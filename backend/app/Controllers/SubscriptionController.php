<?php
declare(strict_types=1);

namespace Svet22\Controllers;

use PDO;
use Svet22\Core\Request;
use Svet22\Core\Response;
use Svet22\Services\TokenService;

final class SubscriptionController
{
    public function __construct(private PDO $db) {}

    public function index(Request $request): never
    {
        $userId = $this->userId($request);
        $stmt = $this->db->prepare(
            'SELECT s.id,s.product_id,s.branch_id,s.status,s.created_at,s.notified_at,
                    p.name AS product_name,b.name AS branch_name
             FROM subscriptions s
             JOIN products p ON p.id=s.product_id
             JOIN branches b ON b.id=s.branch_id
             WHERE s.user_id=? ORDER BY s.created_at DESC,s.id DESC'
        );
        $stmt->execute([$userId]);
        Response::success(['subscriptions' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public function create(Request $request): never
    {
        $userId = $this->userId($request);
        $body = $request->json();
        $productId = filter_var($body['product_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$productId || $productId < 1) {
            Response::error('VALIDATION_ERROR', 'Укажите корректный product_id.', 422);
        }
            // Lock stock rows while validating global availability.
            $stmt = $this->db->prepare(
                'SELECT b.id, s.quantity FROM branches b
                 LEFT JOIN stock s ON s.branch_id=b.id AND s.product_id=?
                 WHERE b.is_active=1 ORDER BY b.id FOR UPDATE'
            );
            $stmt->execute([$productId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows || array_filter($rows, static fn(array $row): bool =>
                $row['quantity'] === null || (float)$row['quantity'] > 0)) {
                $this->db->rollBack();
                Response::error('NOT_TRACKABLE', 'Товар есть в наличии или данные об остатках неполные.', 409);
            }
            $stmt = $this->db->prepare(
                "INSERT INTO subscriptions (user_id,product_id,branch_id,status,notified_at)
                 VALUES (?,?,NULL,'active',NULL)
                 ON DUPLICATE KEY UPDATE status='active',notified_at=NULL,last_push_error=NULL,push_attempts=0"
            );
            $stmt->execute([$userId, $productId]);
            $stmt = $this->db->prepare(
                'SELECT id,product_id,status,created_at FROM subscriptions WHERE user_id=? AND product_id=?'
            );
            $stmt->execute([$userId, $productId]);
            $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
}