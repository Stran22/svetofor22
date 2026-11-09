<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class PushNotificationService
{

    public static function idempotencyKey(int $subscriptionId, string $eventKey): string
    {
        $hex = substr(hash('sha256', $subscriptionId . ':' . $eventKey), 0, 32);
        $hex[12] = '4';
        $hex[16] = dechex((hexdec($hex[16]) & 0x03) | 0x08);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' .
            substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /**
     * Persist a delivery intent inside the same transaction as the stock
     * transition. The retry worker can deliver it if the web request crashes
     * after the stock transaction commits.
     */
    public function enqueueRestock(PDO $pdo, int $productId, int $branchId, string $eventKey): int
    {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('Restock delivery must be enqueued inside the stock transaction.');
        }
        if ($productId < 1 || $branchId < 1 || $eventKey === '' || strlen($eventKey) > 190) {
            throw new RuntimeException('Invalid restock event parameters.');
        }
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO push_delivery_log (subscription_id,event_key,status)
             SELECT s.id,:event_key,'pending'
             FROM subscriptions s
             WHERE s.product_id=:product_id AND s.branch_id=:branch_id AND s.status='active'"
        );
        $stmt->execute(['event_key' => $eventKey, 'product_id' => $productId, 'branch_id' => $branchId]);
        return $stmt->rowCount();
    }

    /**
     * Call only after the stock transaction has committed and a 0 -> positive
     * quantity transition has been confirmed.
     */
    public function notifyRestock(int $productId, int $branchId, string $eventKey): array
    {
        if ($productId < 1 || $branchId < 1 || $eventKey === '' || strlen($eventKey) > 190) {
            throw new RuntimeException('Invalid restock event parameters.');
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT s.id AS subscription_id,s.user_id,p.name AS product_name,b.name AS branch_name
             FROM subscriptions s
             JOIN products p ON p.id=s.product_id
             JOIN branches b ON b.id=s.branch_id
             JOIN stock st ON st.product_id=s.product_id AND st.branch_id=s.branch_id AND st.quantity>0
             WHERE s.product_id=:product_id AND s.branch_id=:branch_id AND s.status='active'
               AND p.is_active=1 AND b.is_active=1
             ORDER BY s.id"
        );
        $stmt->execute(['product_id' => $productId, 'branch_id' => $branchId]);
        $subscriptions = $stmt->fetchAll();

        $result = ['matched' => count($subscriptions), 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($subscriptions as $subscription) {
            $claim = $this->claimDelivery(
                $pdo,
                (int)$subscription['subscription_id'],
                $eventKey
            );
            if ($claim === null) {
                $result['skipped']++;
                continue;
            }

            try {
                $response = $this->send(
                    (int)$subscription['user_id'],
                    (string)$subscription['product_name'],
                    (string)$subscription['branch_name'],
                    $productId,
                    $branchId,
                    $eventKey
                );
                $messageId = trim((string)($response['id'] ?? ''));
                if ($messageId === '') {
                    $this->finishDelivery($pdo, $claim, false, null, $response, (string)($response['error'] ?? 'FCM_FAILED'));
                    $result['failed']++;
                    continue;
                }
                $this->finishDelivery($pdo, $claim, true, $messageId, $response, null);
                $result['sent']++;
            } catch (Throwable $e) {
                $this->finishDelivery($pdo, $claim, false, null, ['error' => 'FCM_REQUEST_FAILED'], $e->getMessage());
                $result['failed']++;
                error_log('FCM delivery failed for subscription ' . (int)$subscription['subscription_id'] . ': ' . $e->getMessage());
            }
        }
        return $result;
    }

    public function retryFailedDeliveries(int $limit = 100): array
    {
        $limit = min(500, max(1, $limit));
        $pdo = Database::connection();
        // Do not send a delayed outbox event if the item is no longer available.
        $pdo->exec(
            "UPDATE push_delivery_log d
             JOIN subscriptions s ON s.id=d.subscription_id
             JOIN products p ON p.id=s.product_id
             JOIN branches b ON b.id=s.branch_id
             LEFT JOIN stock st ON st.product_id=s.product_id AND st.branch_id=s.branch_id
             SET d.status='failed',d.locked_at=NULL,
                 d.last_error=CASE WHEN s.status<>'active' THEN 'SUBSCRIPTION_INACTIVE'
                                   WHEN p.is_active<>1 OR b.is_active<>1 THEN 'TARGET_INACTIVE'
                                   ELSE 'STOCK_NO_LONGER_AVAILABLE' END
             WHERE d.status IN ('pending','failed','processing')
               AND (d.status IN ('pending','failed') OR d.locked_at<DATE_SUB(NOW(), INTERVAL 5 MINUTE))
               AND (s.status<>'active' OR st.id IS NULL OR st.quantity<=0 OR p.is_active<>1 OR b.is_active<>1)"
        );
        $stmt = $pdo->query(
            "SELECT d.subscription_id,d.event_key,s.user_id,s.product_id,s.branch_id,
                    p.name AS product_name,b.name AS branch_name
             FROM push_delivery_log d
             JOIN subscriptions s ON s.id=d.subscription_id
             JOIN products p ON p.id=s.product_id
             JOIN branches b ON b.id=s.branch_id
             JOIN stock st ON st.product_id=s.product_id AND st.branch_id=s.branch_id AND st.quantity>0
             WHERE s.status='active' AND p.is_active=1 AND b.is_active=1 AND d.attempt_count<10
               AND COALESCE(d.last_error,'') NOT IN ('NO_SUBSCRIBED_DEVICES','STOCK_NO_LONGER_AVAILABLE','SUBSCRIPTION_INACTIVE','TARGET_INACTIVE')
               AND (
                    d.status='pending'
                    OR (d.status='failed' AND (d.attempted_at IS NULL OR d.attempted_at<DATE_SUB(NOW(), INTERVAL 15 MINUTE)))
                    OR (d.status='processing' AND d.locked_at<DATE_SUB(NOW(), INTERVAL 5 MINUTE))
               )
             ORDER BY d.id ASC LIMIT " . $limit
        );
        $items = $stmt->fetchAll();
        $result = ['matched' => count($items), 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($items as $item) {
            $deliveryId = $this->claimDelivery($pdo, (int)$item['subscription_id'], (string)$item['event_key']);
            if ($deliveryId === null) {
                $result['skipped']++;
                continue;
            }
            try {
                $response = $this->send(
                    (int)$item['user_id'],
                    (string)$item['product_name'],
                    (string)$item['branch_name'],
                    (int)$item['product_id'],
                    (int)$item['branch_id'],
                    (string)$item['event_key']
                );
                $messageId = trim((string)($response['id'] ?? ''));
                if ($messageId === '') {
                    $this->finishDelivery($pdo, $deliveryId, false, null, $response, (string)($response['error'] ?? 'FCM_FAILED'));
                    $result['failed']++;
                    continue;
                }
                $this->finishDelivery($pdo, $deliveryId, true, $messageId, $response, null);
                $result['sent']++;
            } catch (Throwable $e) {
                $this->finishDelivery($pdo, $deliveryId, false, null, ['error' => 'FCM_REQUEST_FAILED'], $e->getMessage());
                $result['failed']++;
                error_log('FCM retry failed for subscription ' . (int)$item['subscription_id'] . ': ' . $e->getMessage());
            }
        }
        return $result;
    }

    private function claimDelivery(PDO $pdo, int $subscriptionId, string $eventKey): ?int
    {
        $pdo->beginTransaction();
        try {
            $active = $pdo->prepare(
                'SELECT s.status,st.quantity,p.is_active AS product_active,b.is_active AS branch_active
                 FROM subscriptions s
                 JOIN products p ON p.id=s.product_id
                 JOIN branches b ON b.id=s.branch_id
                 LEFT JOIN stock st ON st.product_id=s.product_id AND st.branch_id=s.branch_id
                 WHERE s.id=:id LIMIT 1 FOR UPDATE'
            );
            $active->execute(['id' => $subscriptionId]);
            $subscription = $active->fetch();
            if (!$subscription || $subscription['status'] !== 'active') {
                $pdo->commit();
                return null;
            }
            $pdo->prepare(
                "INSERT IGNORE INTO push_delivery_log (subscription_id,event_key,status)
                 VALUES (:subscription_id,:event_key,'pending')"
            )->execute(['subscription_id' => $subscriptionId, 'event_key' => $eventKey]);

            $stmt = $pdo->prepare(
                'SELECT id,status,locked_at FROM push_delivery_log
                 WHERE subscription_id=:subscription_id AND event_key=:event_key
                 LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['subscription_id' => $subscriptionId, 'event_key' => $eventKey]);
            $delivery = $stmt->fetch();
            if (!$delivery || $delivery['status'] === 'sent') {
                $pdo->commit();
                return null;
            }
            if (!(bool)$subscription['product_active'] || !(bool)$subscription['branch_active']) {
                $pdo->prepare(
                    "UPDATE push_delivery_log SET status='failed',locked_at=NULL,
                     last_error='TARGET_INACTIVE' WHERE id=:id"
                )->execute(['id' => $delivery['id']]);
                $pdo->commit();
                return null;
            }
            if ($subscription['quantity'] === null || (float)$subscription['quantity'] <= 0) {
                $pdo->prepare(
                    "UPDATE push_delivery_log SET status='failed',locked_at=NULL,
                     last_error='STOCK_NO_LONGER_AVAILABLE' WHERE id=:id"
                )->execute(['id' => $delivery['id']]);
                $pdo->commit();
                return null;
            }
            if ($delivery['status'] === 'processing' && $delivery['locked_at'] !== null) {
                $lockedAt = strtotime((string)$delivery['locked_at']);
                if ($lockedAt !== false && $lockedAt > time() - 300) {
                    $pdo->commit();
                    return null;
                }
            }
            $pdo->prepare(
                "UPDATE push_delivery_log
                 SET status='processing',locked_at=NOW(),attempted_at=NOW(),attempt_count=attempt_count+1,last_error=NULL
                 WHERE id=:id"
            )->execute(['id' => $delivery['id']]);
            $pdo->commit();
            return (int)$delivery['id'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private function finishDelivery(PDO $pdo, int $deliveryId, bool $success, ?string $messageId, array $response, ?string $error): void
    {
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE push_delivery_log
                 SET status=:status,provider_message_id=:message_id,response=:response,
                     sent_at=IF(:is_sent=1,NOW(),NULL),locked_at=NULL,last_error=:last_error
                 WHERE id=:id'
            )->execute([
                'status' => $success ? 'sent' : 'failed',
                'message_id' => $messageId,
                'response' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'is_sent' => $success ? 1 : 0,
                'last_error' => $error === null ? null : mb_substr($error, 0, 500),
                'id' => $deliveryId,
            ]);
            if ($success) {
                $pdo->prepare(
                    "UPDATE subscriptions s
                     JOIN push_delivery_log d ON d.subscription_id=s.id
                     SET s.status='triggered',s.notified_at=NOW(),s.updated_at=NOW()
                     WHERE d.id=:delivery_id AND s.status='active'"
                )->execute(['delivery_id' => $deliveryId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Sends to registered FCM devices. Successful device sends are remembered
     * across queue retries so an intermittent failure does not resend to them.
     */
    private function send(int $userId, string $productName, string $branchName, int $productId, int $branchId, string $eventKey): array
    {
        $pdo = Database::connection();
        $tokens = $pdo->prepare('SELECT id,fcm_token FROM user_push_tokens WHERE user_id=:user_id ORDER BY id');
        $tokens->execute(['user_id' => $userId]);
        $devices = $tokens->fetchAll(PDO::FETCH_ASSOC);
        if (!$devices) return ['error' => 'NO_SUBSCRIBED_DEVICES'];

        $sender = new FcmSender();
        $firstMessage = null;
        $failed = false;
        $title = 'Товар снова в наличии';
        $body = $productName . ' снова в наличии в магазине «' . $branchName . '».';
        foreach ($devices as $device) {
            $tokenId = (int)$device['id'];
            $hash = hash('sha256', $eventKey . ':' . $tokenId);
            $previous = $pdo->prepare('SELECT status FROM fcm_device_deliveries WHERE event_hash=:hash LIMIT 1');
            $previous->execute(['hash' => $hash]);
            if ($previous->fetchColumn() === 'sent') {
                $firstMessage ??= 'already-sent';
                continue;
            }
            // Reserve the device/event combination before the external request.
            // A stale processing record is deliberately not auto-retried: FCM has
            // no idempotency key, so a timed-out request may already have arrived.
            $insert = $pdo->prepare(
                "INSERT IGNORE INTO fcm_device_deliveries (event_hash,token_id,status)
                 VALUES (:hash,:token_id,'processing')"
            );
            $insert->execute(['hash' => $hash, 'token_id' => $tokenId]);
            if ($insert->rowCount() === 0) {
                $failed = true;
                continue;
            }
            try {
                $result = $sender->send((string)$device['fcm_token'], $title, $body, $productId, $branchId);
                if ($result['success'] ?? false) {
                    $pdo->prepare("UPDATE fcm_device_deliveries SET status='sent' WHERE event_hash=:hash")
                        ->execute(['hash' => $hash]);
                    $firstMessage ??= (string)$result['message_id'];
                } else {
                    $reason = (string)($result['reason'] ?? 'UNKNOWN');
                    $status = (int)($result['http_status'] ?? 0);
                    if ($status === 404 || ($status === 400 && $reason === 'INVALID_ARGUMENT')) {
                        // A malformed/expired device registration is not retried.
                        $pdo->prepare("UPDATE fcm_device_deliveries SET status='invalid' WHERE event_hash=:hash")
                            ->execute(['hash' => $hash]);
                    } else {
                        // Ambiguous failures stay processing; no automatic duplicate.
                        $failed = true;
                    }
                }
            } catch (Throwable $e) {
                $failed = true;
                error_log('FCM delivery attempt failed for user ' . $userId);
            }
        }
        if ($failed) return ['error' => 'FCM_DEVICE_DELIVERY_INCOMPLETE'];
        return $firstMessage === null ? ['error' => 'NO_SUBSCRIBED_DEVICES'] : ['id' => $firstMessage];
    }
}
