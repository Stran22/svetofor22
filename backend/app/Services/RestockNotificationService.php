<?php
declare(strict_types=1);

namespace Svet22\Services;

use PDO;

/**
 * Queues one notification per subscriber and product-availability cycle.
 * Must be called inside the stock-import transaction.
 */
final class RestockNotificationService
{
    public function __construct(private PDO $db) {}

    public function queueTransition(int $productId, int $branchId, string $oldQuantity, string $newQuantity): int
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Restock events must be queued inside the stock transaction.');
        }
        if ($productId < 1 || $branchId < 1 ||
            !is_numeric($oldQuantity) || !is_numeric($newQuantity) ||
            (float)$oldQuantity > 0 || (float)$newQuantity <= 0) {
            return 0;
        }

        // A stable key plus uq_delivery_event prevents duplicate notifications
        // when multiple branches restock before the queue worker runs.
        $eventKey = 'restock:' . $productId;
        $subscriptions = $this->db->prepare(
            "SELECT id FROM subscriptions
             WHERE product_id = ? AND status = 'active'
             ORDER BY id"
        );
        $subscriptions->execute([$productId]);

        $enqueue = $this->db->prepare(
            "INSERT INTO push_delivery_log
                (subscription_id, branch_id, event_key, status, attempt_count)
             VALUES (?, ?, ?, 'pending', 0)
             ON DUPLICATE KEY UPDATE id = id"
        );

        $queued = 0;
        while (($subscriptionId = $subscriptions->fetchColumn()) !== false) {
            $enqueue->execute([(int)$subscriptionId, $branchId, $eventKey]);
            if ($enqueue->rowCount() === 1) {
                $queued++;
            }
        }
        return $queued;
    }
}
