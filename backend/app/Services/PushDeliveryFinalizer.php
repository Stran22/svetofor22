<?php
declare(strict_types=1);

namespace Svet22\Services;

use PDO;

/**
 * Finalizes one FCM event after every device has reached a terminal state.
 * Call inside the worker's database transaction.
 */
final class PushDeliveryFinalizer
{
    public function __construct(private PDO $db) {}

    public function finalize(int $deliveryId, int $subscriptionId): void
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Push delivery finalization requires a transaction.');
        }
        $stmt = $this->db->prepare(
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

        $stmt = $this->db->prepare(
            "UPDATE push_delivery_log
             SET status = ?, last_error = ?, sent_at = CASE WHEN ? = 1 THEN NOW() ELSE sent_at END,
                 updated_at = NOW()
             WHERE id = ? AND subscription_id = ? AND status = 'processing'"
        );
        $stmt->execute([
            $anySent ? 'sent' : 'failed',
            $summary,
            $anySent ? 1 : 0,
            $deliveryId,
            $subscriptionId,
        ]);
        if ($stmt->rowCount() !== 1) {
            return;
        }

        if ($anySent) {
            $stmt = $this->db->prepare(
                "UPDATE subscriptions
                 SET status = 'notified', notified_at = NOW(), last_push_error = ?
                 WHERE id = ? AND status = 'active'"
            );
            $stmt->execute([$summary, $subscriptionId]);
        } else {
            $stmt = $this->db->prepare(
                "UPDATE subscriptions
                 SET last_push_error = 'No device delivery confirmed',
                     push_attempts = push_attempts + 1
                 WHERE id = ? AND status = 'active'"
            );
            $stmt->execute([$subscriptionId]);
        }
    }
}
