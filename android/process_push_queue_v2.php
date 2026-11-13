<?php
declare(strict_types=1);

/**
 * Svet22 Firebase per-device queue worker.
 * Default: read-only preview. Sending requires --send.
 * CLI only; set SVET_CONFIG_FILE in the environment.
 * A claimed request is never automatically retried after an ambiguous FCM result.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Svet22\Core\Config;
use Svet22\Core\Database;
use Svet22\Services\FirebasePushService;

$send = in_array('--send', $argv, true);
$limit = 20;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/D', $arg, $m)) {
        $limit = max(1, min(100, (int) $m[1]));
    }
}

/** Finalize parent only when every per-device delivery has a terminal result. */
function finalizeEvent(PDO $db, int $deliveryId, int $subscriptionId): void
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(status = 'sent'), 0) AS sent_count,
                COALESCE(SUM(status IN ('failed','unknown')), 0) AS unconfirmed_count,
                COALESCE(SUM(status IN ('pending','processing')), 0) AS outstanding
         FROM push_device_deliveries WHERE delivery_id = ?"
    );
    $stmt->execute([$deliveryId]);
    $counts = $stmt->fetch(PDO::FETCH_ASSOC);
    if ((int) $counts['total'] === 0 || (int) $counts['outstanding'] !== 0) {
        return;
    }

    $anySent = (int) $counts['sent_count'] > 0;
    $partial = $anySent && (int) $counts['unconfirmed_count'] > 0;
    $summary = $partial ? 'Some device deliveries failed or are unknown'
        : ($anySent ? null : 'No device delivery confirmed');
    $stmt = $db->prepare(
        "UPDATE push_delivery_log
         SET status = ?, last_error = ?, sent_at = CASE WHEN ? = 1 THEN NOW() ELSE sent_at END,
             updated_at = NOW()
         WHERE id = ? AND status = 'processing'"
    );
    $stmt->execute([
        $anySent ? 'sent' : 'failed',
        $summary,
        $anySent ? 1 : 0,
        $deliveryId,
    ]);

    if ($anySent) {
        $stmt = $db->prepare(
            "UPDATE subscriptions
             SET status = 'notified', notified_at = NOW(), last_push_error = ?
             WHERE id = ? AND status = 'active'"
        );
        $stmt->execute([$summary, $subscriptionId]);
    } else {
        $stmt = $db->prepare(
            "UPDATE subscriptions
             SET last_push_error = 'No device delivery confirmed', push_attempts = push_attempts + 1
             WHERE id = ? AND status = 'active'"
        );
        $stmt->execute([$subscriptionId]);
    }
}

try {
    Config::load();
    $db = Database::connection();

    if (!$send) {
        $pending = (int) $db->query("SELECT COUNT(*) FROM push_delivery_log WHERE status = 'pending'")->fetchColumn();
        $devices = (int) $db->query("SELECT COUNT(*) FROM push_device_deliveries WHERE status = 'pending'")->fetchColumn();
        echo "DRY RUN: pending events={$pending}; pending device deliveries={$devices}\n";
        echo "No messages sent. Use --send only after validation.\n";
        exit(0);
    }

    $lock = $db->query("SELECT GET_LOCK('svet22_fcm_queue_worker', 0)")->fetchColumn();
    if ((int) $lock !== 1) {
        fwrite(STDERR, "Another queue worker is running.\n");
        exit(2);
    }

    $processed = 0;
    $sent = 0;
    $failed = 0;
    $push = new FirebasePushService($db);

    try {
        // Recover interrupted attempts only after obtaining the exclusive worker lock.
        // Never retry them: FCM may have accepted the request before PHP stopped.
        $db->beginTransaction();
        $db->exec("UPDATE push_device_deliveries d
                   JOIN push_delivery_log l ON l.id = d.delivery_id
                   SET d.status='unknown',
                       d.last_error='Interrupted attempt; delivery outcome unknown',
                       d.updated_at=NOW()
                   WHERE d.status='processing' AND l.status='processing'");
        $orphans = $db->query(
            "SELECT l.id, l.subscription_id
             FROM push_delivery_log l
             WHERE l.status='processing'
               AND EXISTS (SELECT 1 FROM push_device_deliveries d WHERE d.delivery_id=l.id)
               AND NOT EXISTS (SELECT 1 FROM push_device_deliveries d
                               WHERE d.delivery_id=l.id AND d.status IN ('pending','processing'))
             FOR UPDATE"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($orphans as $orphan) {
            finalizeEvent($db, (int) $orphan['id'], (int) $orphan['subscription_id']);
        }
        $db->commit();

        while ($processed < $limit) {
            // Stage a new parent event, or resume pending device deliveries.
            $db->beginTransaction();
            $parentStmt = $db->query(
                "SELECT l.id, l.subscription_id, s.user_id, s.status AS subscription_status,
                        u.push_enabled
                 FROM push_delivery_log l
                 JOIN subscriptions s ON s.id = l.subscription_id
                 JOIN users u ON u.id = s.user_id
                 WHERE l.status = 'pending'
                 ORDER BY l.created_at, l.id LIMIT 1 FOR UPDATE"
            );
            $parent = $parentStmt->fetch(PDO::FETCH_ASSOC);
            if ($parent) {
                $parentId = (int) $parent['id'];
                $subscriptionId = (int) $parent['subscription_id'];
                if ($parent['subscription_status'] !== 'active' || (int) $parent['push_enabled'] !== 1) {
                    $stmt = $db->prepare(
                        "UPDATE push_delivery_log SET status='failed', last_error=?,
                         attempt_count=attempt_count+1, updated_at=NOW() WHERE id=?"
                    );
                    $stmt->execute(['Subscription inactive or push disabled', $parentId]);
                    $db->commit();
                    $processed++;
                    $failed++;
                    continue;
                }

                // Freeze the list of devices for this event. The unique index prevents duplicates.
                $stmt = $db->prepare(
                    "INSERT IGNORE INTO push_device_deliveries (delivery_id, token_id)
                     SELECT ?, t.id FROM user_push_tokens t WHERE t.user_id = ?"
                );
                $stmt->execute([$parentId, (int) $parent['user_id']]);
                $stmt = $db->prepare('SELECT COUNT(*) FROM push_device_deliveries WHERE delivery_id = ?');
                $stmt->execute([$parentId]);
                if ((int) $stmt->fetchColumn() === 0) {
                    $stmt = $db->prepare(
                        "UPDATE push_delivery_log SET status='failed', last_error='No registered FCM token',
                         attempt_count=attempt_count+1, updated_at=NOW() WHERE id=?"
                    );
                    $stmt->execute([$parentId]);
                    $db->commit();
                    $processed++;
                    $failed++;
                    continue;
                }
                $stmt = $db->prepare(
                    "UPDATE push_delivery_log SET status='processing', attempt_count=attempt_count+1,
                     updated_at=NOW() WHERE id=?"
                );
                $stmt->execute([$parentId]);
            }
            $db->commit();

            // Claim one device delivery before making the external request.
            $db->beginTransaction();
            $stmt = $db->query(
                "SELECT d.id, d.delivery_id, d.token_id, l.subscription_id,
                        s.product_id, s.branch_id, s.status AS subscription_status,
                        u.push_enabled, p.name AS product_name, b.name AS branch_name
                 FROM push_device_deliveries d
                 JOIN push_delivery_log l ON l.id=d.delivery_id
                 JOIN subscriptions s ON s.id=l.subscription_id
                 JOIN users u ON u.id=s.user_id
                 JOIN products p ON p.id=s.product_id
                 JOIN branches b ON b.id=s.branch_id
                 WHERE d.status='pending' AND l.status='processing'
                 ORDER BY d.created_at,d.id LIMIT 1 FOR UPDATE"
            );
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                $db->commit();
                break;
            }

            $id = (int) $item['id'];
            $deliveryId = (int) $item['delivery_id'];
            $subscriptionId = (int) $item['subscription_id'];
            if ($item['subscription_status'] !== 'active' || (int) $item['push_enabled'] !== 1 || $item['token_id'] === null) {
                $stmt = $db->prepare(
                    "UPDATE push_device_deliveries SET status='failed', last_error=?,
                     attempt_count=attempt_count+1, updated_at=NOW() WHERE id=?"
                );
                $stmt->execute(['Subscription inactive, push disabled, or token removed', $id]);
                finalizeEvent($db, $deliveryId, $subscriptionId);
                $db->commit();
                $processed++;
                $failed++;
                continue;
            }

            $stmt = $db->prepare(
                "UPDATE push_device_deliveries SET status='processing', attempt_count=attempt_count+1,
                 updated_at=NOW() WHERE id=? AND status='pending'"
            );
            $stmt->execute([$id]);
            $db->commit();

            $title = 'Товар снова в наличии';
            $body = (string) $item['product_name'] . ' — ' . (string) $item['branch_name'];
            $result = null;
            $error = null;
            try {
                $result = $push->sendToRegisteredTokenId(
                    (int) $item['token_id'],
                    $title,
                    $body,
                    [
                        'product_id' => (string) $item['product_id'],
                        'branch_id' => (string) $item['branch_id'],
                    ]
                );
            } catch (Throwable $e) {
                // The request may have reached Firebase. Do not retry automatically.
                $error = 'FCM exception: ' . get_class($e);
            }

            $ok = is_array($result) && ($result['success'] ?? false) === true;
            if (!$ok && $error === null) {
                $error = 'FCM HTTP ' . (string) ($result['http_status'] ?? 'unknown')
                    . ': ' . (string) ($result['error_status'] ?? 'UNKNOWN');
            }
            $newStatus = $ok ? 'sent' : 'unknown';
            // Explicit HTTP rejection is known not to have been accepted by FCM.
            if (!$ok && is_array($result) && (int) ($result['http_status'] ?? 0) >= 400) {
                $newStatus = 'failed';
            }

            $db->beginTransaction();
            $stmt = $db->prepare(
                "UPDATE push_device_deliveries
                 SET status=?, last_error=?, sent_at=CASE WHEN ? = 'sent' THEN NOW() ELSE sent_at END,
                     provider_message_id=?, updated_at=NOW()
                 WHERE id=? AND status='processing'"
            );
            $providerId = $ok && is_string($result['provider_message_id'] ?? null)
                ? substr($result['provider_message_id'], 0, 255) : null;
            $stmt->execute([$newStatus, $ok ? null : substr((string) $error, 0, 500), $newStatus, $providerId, $id]);
            finalizeEvent($db, $deliveryId, $subscriptionId);
            $db->commit();

            $processed++;
            if ($ok) {
                $sent++;
            } else {
                $failed++;
            }
        }
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $db->query("SELECT RELEASE_LOCK('svet22_fcm_queue_worker')");
    }

    echo "Processed={$processed}; sent={$sent}; failed={$failed}\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Worker error: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}