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
        $branchId = filter_var($body['branch_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$productId || $productId < 1 || !$branchId || $branchId < 1) {
            Response::error('VALIDATION_ERROR', 'Укажите корректные product_id и branch_id.', 422);
        }
        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare('SELECT id FROM branches WHERE id=? AND is_active=1 FOR UPDATE');
            $stmt->execute([$branchId]);
            if (!$stmt->fetchColumn()) {
                $this->db->rollBack();
                Response::error('NOT_FOUND', 'Магазин не найден.', 404);
            }
            $stmt = $this->db->prepare('SELECT id FROM products WHERE id=? AND is_active=1');
            $stmt->execute([$productId]);
            if (!$stmt->fetchColumn()) {
                $this->db->rollBack();
                Response::error('NOT_FOUND', 'Товар не найден.', 404);
            }
            $stmt = $this->db->prepare('SELECT quantity FROM stock WHERE product_id=? AND branch_id=? LIMIT 1');
            $stmt->execute([$productId, $branchId]);
            $quantity = $stmt->fetchColumn();
            if ($quantity !== false && (float)$quantity > 0) {
                $this->db->rollBack();
                Response::error('ALREADY_AVAILABLE', 'Товар уже есть в наличии.', 409);
            }
            $stmt = $this->db->prepare(
                "INSERT INTO subscriptions (user_id,product_id,branch_id,status,notified_at)
                 VALUES (?,?,?,'active',NULL)
                 ON DUPLICATE KEY UPDATE status='active',notified_at=NULL,last_push_error=NULL,push_attempts=0"
            );
            $stmt->execute([$userId, $productId, $branchId]);
            $stmt = $this->db->prepare('SELECT id,product_id,branch_id,status,created_at FROM subscriptions WHERE user_id=? AND product_id=? AND branch_id=?');
            $stmt->execute([$userId, $productId, $branchId]);
            $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->db->commit();
            Response::success(['subscription' => $subscription]);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function cancel(Request $request): never
    {
        $userId = $this->userId($request);
        $body = $request->json();
        $id = filter_var($body['subscription_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            Response::error('VALIDATION_ERROR', 'Укажите subscription_id.', 422);
        }
        $stmt = $this->db->prepare("UPDATE subscriptions SET status='cancelled' WHERE id=? AND user_id=?");
        $stmt->execute([$id, $userId]);
        if ($stmt->rowCount() === 0) {
            $check = $this->db->prepare('SELECT id FROM subscriptions WHERE id=? AND user_id=?');
            $check->execute([$id, $userId]);
            if (!$check->fetchColumn()) Response::error('NOT_FOUND', 'Подписка не найдена.', 404);
        }
        Response::success(['subscription_id' => $id, 'status' => 'cancelled']);
    }

    private function userId(Request $request): int
    {
        $userId = (new TokenService($this->db))->userId($request->bearerToken());
        if ($userId === null) Response::error('UNAUTHENTICATED', 'Требуется Bearer-токен.', 401);
        return $userId;
    }
}
