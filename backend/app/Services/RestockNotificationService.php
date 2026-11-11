<?php
declare(strict_types=1);

namespace Svet22\Services;

use PDO;

/**
 * Adds restock events to the durable push queue.
 * Call from the SAME transaction as the stock update, only on 0 -> positive.
 * Does not send FCM: a separate worker processes push_delivery_log.
 */
final class RestockNotificationService
{
    public function __construct(private PDO $db)
    {
    }

    public function queueTransition(int $productId, int $branchId, string $oldQuantity, string $newQuantity): int
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Restock events must be queued inside the stock transaction.');
        }

        if ($productId < 1 || $branchId < 1 ||
            !is_numeric($oldQuantity) || !is_numeric($newQuantity) ||
            (float) $oldQuantity > 0 || (float) $newQuantity <= 0) {
            return 0;
        }

        // One key per actual stock transition; each subscribed user gets one queue row.
        // A future 0 -> positive transition gets a different key.
        $eventKey = 'restock:' . $productId . ':' . $branchId . ':' . bin2hex(random_bytes(16));

        $subscriptions = $this->db->prepare(
            "SELECT id FROM subscriptions
             WHERE product_id = ? AND branch_id = ? AND status = 'active'
             ORDER BY id"
        );
        $subscriptions->execute([$productId, $branchId]);

        $enqueue = $this->db->prepare(
            "INSERT INTO push_delivery_log (subscription_id, event_key, status, attempt_count)
             VALUES (?, ?, 'pending', 0)
             ON DUPLICATE KEY UPDATE id = id"
        );

        $queued = 0;
        while (($subscriptionId = $subscriptions->fetchColumn()) !== false) {
            $enqueue->execute([(int) $subscriptionId, $eventKey]);
            $queued += $enqueue->rowCount() === 1 ? 1 : 0;
        }

        return $queued;
    }
}