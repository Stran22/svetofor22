<?php
declare(strict_types=1);

/**
 * Svet22 FCM queue worker.
 * Default: read-only preview. Explicit --send is required for delivery.
 * Run only from CLI with SVET_CONFIG_FILE configured.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Svet22\Core\Database;
use Svet22\Services\FirebasePushService;

$send = in_array('--send', $argv, true);
$limit = 20;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(100, (int) $m[1]));
    }
}

try {
    $db = Database::connection();
    if (!$send) {
        $stmt = $db->query("SELECT COUNT(*) FROM push_delivery_log WHERE status = 'pending'");
        echo 'DRY RUN: pending rows = ' . (int) $stmt->fetchColumn() . "\n";
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
        while ($processed < $limit) {
            $db->beginTransaction();
            $stmt = $db->query(
    "SELECT l.id, l.subscription_id, s.user_id,
            s.product_id, s.branch_id,
            s.status AS subscription_status,
                        u.push_enabled, p.name AS product_name, b.name AS branch_name
                 FROM push_delivery_log l
                 JOIN subscriptions s ON s.id = l.subscription_id
                 JOIN users u ON u.id = s.user_id
                 JOIN products p ON p.id = s.product_id
                 JOIN branches b ON b.id = s.branch_id
                 WHERE l.status = 'pending'
                 ORDER BY l.created_at, l.id LIMIT 1 FOR UPDATE"
            );
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                $db->commit();
                break;
            }

            $id = (int) $item['id'];
            $subscriptionId = (int) $item['subscription_id'];
            if ($item['subscription_status'] !== 'active' || (int) $item['push_enabled'] !== 1) {
                $stmt = $db->prepare("UPDATE push_delivery_log SET status='failed', last_error=?,
                    attempt_count=attempt_count+1, updated_at=NOW() WHERE id=?");
                $stmt->execute(['Subscription inactive or push disabled', $id]);
                $db->commit();
                $processed++;
                $failed++;
                continue;
            }

            $tokens = $db->prepare('SELECT id FROM user_push_tokens WHERE user_id=? ORDER BY id');
            $tokens->execute([(int) $item['user_id']]);
            $tokenIds = $tokens->fetchAll(PDO::FETCH_COLUMN);
            if (count($tokenIds) !== 1) {
                $stmt = $db->prepare("UPDATE push_delivery_log SET status='failed', last_error=?,
                    attempt_count=attempt_count+1, updated_at=NOW() WHERE id=?");
                $stmt->execute([count($tokenIds) === 0 ? 'No registered FCM token' : 'Multiple tokens: manual review required', $id]);
                $db->commit();
                $processed++;
                $failed++;
                continue;
            }

            // Commit processing state BEFORE contacting Firebase.
            // An interrupted/ambiguous request must not be retried automatically.
            $stmt = $db->prepare("UPDATE push_delivery_log SET status='processing',
                attempt_count=attempt_count+1, updated_at=NOW() WHERE id=?");
            $stmt->execute([$id]);
            $db->commit();

            $title = 'Товар снова в наличии';
            $body = (string) $item['product_name'] . ' — ' . (string) $item['branch_name'];
            try {
                $result = $push->sendToRegisteredTokenId(
    (int) $tokenIds[0],
    $title,
    $body,
    [
        'product_id' => (string) $item['product_id'],
        'branch_id'  => (string) $item['branch_id'],
    ]
);
                $ok = ($result['success'] ?? false) === true;
                $error = $ok ? null : 'FCM HTTP ' . (string) ($result['http_status'] ?? 'unknown')
                    . ': ' . (string) ($result['error_status'] ?? 'UNKNOWN');
            } catch (Throwable $e) {
                $ok = false;
                $error = 'FCM exception: ' . get_class($e);
            }

            $db->beginTransaction();
            if ($ok) {
                $stmt = $db->prepare("UPDATE push_delivery_log SET status='sent',
                    last_error=NULL, sent_at=NOW(), updated_at=NOW() WHERE id=? AND status='processing'");
                $stmt->execute([$id]);
                $stmt = $db->prepare("UPDATE subscriptions SET status='notified', notified_at=NOW(),
                    last_push_error=NULL WHERE id=? AND status='active'");
                $stmt->execute([$subscriptionId]);
                $sent++;
            } else {
                // Never auto-retry: FCM response can be ambiguous.
                $stmt = $db->prepare("UPDATE push_delivery_log SET status='failed',
                    last_error=?, updated_at=NOW() WHERE id=? AND status='processing'");
                $stmt->execute([substr((string) $error, 0, 500), $id]);
                $stmt = $db->prepare('UPDATE subscriptions SET last_push_error=?, push_attempts=push_attempts+1 WHERE id=?');
                $stmt->execute([substr((string) $error, 0, 1000), $subscriptionId]);
                $failed++;
            }
            $db->commit();
            $processed++;
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
