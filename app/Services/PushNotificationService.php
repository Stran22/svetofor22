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
    private const ONESIGNAL_URL = 'https://api.onesignal.com/notifications';

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
                    self::idempotencyKey((int)$subscription['subscription_id'], $eventKey)
                );
                $messageId = trim((string)($response['id'] ?? ''));
                if ($messageId === '') {
                    $this->finishDelivery($pdo, $claim, false, null, $response, 'NO_SUBSCRIBED_DEVICES');
                    $result['failed']++;
                    continue;
                }
                $this->finishDelivery($pdo, $claim, true, $messageId, $response, null);
                $result['sent']++;
            } catch (Throwable $e) {
                $this->finishDelivery($pdo, $claim, false, null, ['error' => 'ONESIGNAL_REQUEST_FAILED'], $e->getMessage());
                $result['failed']++;
                error_log('OneSignal delivery failed for subscription ' . (int)$subscription['subscription_id'] . ': ' . $e->getMessage());
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
             LEFT JOIN stock st ON st.product_id=s.product_id AND st.branch_id=s.branch_id
             SET d.status='failed',d.locked_at=NULL,
                 d.last_error=CASE WHEN s.status<>'active' THEN 'SUBSCRIPTION_INACTIVE'
                                   ELSE 'STOCK_NO_LONGER_AVAILABLE' END
             WHERE d.status IN ('pending','failed','processing')
               AND (d.status IN ('pending','failed') OR d.locked_at<DATE_SUB(NOW(), INTERVAL 5 MINUTE))
               AND (s.status<>'active' OR st.id IS NULL OR st.quantity<=0)"
        );
        $stmt = $pdo->query(
            "SELECT d.subscription_id,d.event_key,s.user_id,s.product_id,s.branch_id,
                    p.name AS product_name,b.name AS branch_name
             FROM push_delivery_log d
             JOIN subscriptions s ON s.id=d.subscription_id
             JOIN products p ON p.id=s.product_id
             JOIN branches b ON b.id=s.branch_id
             JOIN stock st ON st.product_id=s.product_id AND st.branch_id=s.branch_id AND st.quantity>0
             WHERE s.status='active' AND d.attempt_count<10
               AND COALESCE(d.last_error,'') NOT IN ('NO_SUBSCRIBED_DEVICES','STOCK_NO_LONGER_AVAILABLE','SUBSCRIPTION_INACTIVE')
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
                    self::idempotencyKey((int)$item['subscription_id'], (string)$item['event_key'])
                );
                $messageId = trim((string)($response['id'] ?? ''));
                if ($messageId === '') {
                    $this->finishDelivery($pdo, $deliveryId, false, null, $response, 'NO_SUBSCRIBED_DEVICES');
                    $result['failed']++;
                    continue;
                }
                $this->finishDelivery($pdo, $deliveryId, true, $messageId, $response, null);
                $result['sent']++;
            } catch (Throwable $e) {
                $this->finishDelivery($pdo, $deliveryId, false, null, ['error' => 'ONESIGNAL_REQUEST_FAILED'], $e->getMessage());
                $result['failed']++;
                error_log('OneSignal retry failed for subscription ' . (int)$item['subscription_id'] . ': ' . $e->getMessage());
            }
        }
        return $result;
    }

    private function claimDelivery(PDO $pdo, int $subscriptionId, string $eventKey): ?int
    {
        $pdo->beginTransaction();
        try {
            $active = $pdo->prepare(
                'SELECT s.status,st.quantity
                 FROM subscriptions s
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

    private function send(int $userId, string $productName, string $branchName, int $productId, int $branchId, string $idempotencyKey): array
    {
        $appId = (string)Config::get('onesignal.app_id', '');
        $apiKey = (string)Config::get('onesignal.rest_api_key', '');
        if ($appId === '' || $apiKey === '') {
            throw new RuntimeException('OneSignal credentials are not configured.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is not available.');
        }

        $body = [
            'app_id' => $appId,
            'target_channel' => 'push',
            'include_aliases' => ['external_id' => [(string)$userId]],
            'headings' => ['en' => 'Product is back in stock', 'ru' => 'Товар снова в наличии'],
            'contents' => [
                'en' => $productName . ' is available at ' . $branchName . '.',
                'ru' => $productName . ' снова в наличии в магазине «' . $branchName . '».',
            ],
            'data' => [
                'type' => 'product_available',
                'product_id' => $productId,
                'branch_id' => $branchId,
            ],
            'idempotency_key' => $idempotencyKey,
        ];

        $ch = curl_init(self::ONESIGNAL_URL);
        if ($ch === false) throw new RuntimeException('Could not initialize OneSignal request.');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Authorization: Key ' . $apiKey,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!is_string($raw) || $status < 200 || $status >= 300) {
            throw new RuntimeException('OneSignal HTTP request failed (' . $status . '). ' . $curlError);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) throw new RuntimeException('OneSignal returned invalid JSON.');
        return $decoded;
    }
}