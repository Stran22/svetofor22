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
            'SELECT s.id, s.product_id, s.branch_id, s.status, s.created_at, s.notified_at,
                    p.name AS product_name
             FROM subscriptions s
             INNER JOIN products p ON p.id = s.product_id
             WHERE s.user_id = ?
             ORDER BY s.created_at DESC, s.id DESC'
        );
        $stmt->execute([$userId]);
        Response::success(['subscriptions' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public function create(Request $request): never
    {
        $userId = $this->userId($request);
        $body = $request->json();
        $productId = filter_var(
            $body['product_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($productId === false || $productId === null) {
            Response::error('VALIDATION_ERROR', 'Укажите корректный product_id.', 422);
        }

        try {
            $this->db->beginTransaction();

            $product = $this->db->prepare(
                'SELECT id FROM products WHERE id = ? AND is_active = 1 FOR UPDATE'
            );
            $product->execute([$productId]);
            if (!$product->fetchColumn()) {
                $this->db->rollBack();
                Response::error('PRODUCT_NOT_FOUND', 'Товар не найден или отключён.', 404);
            }

            // Every active branch must have a known stock row and quantity <= 0.
            // Locking rows serializes subscription creation with stock imports.
            $stock = $this->db->prepare(
                'SELECT b.id, s.quantity
                 FROM branches b
                 LEFT JOIN stock s ON s.branch_id = b.id AND s.product_id = ?
                 WHERE b.is_active = 1
                 ORDER BY b.id
                 FOR UPDATE'
            );
            $stock->execute([$productId]);
            $rows = $stock->fetchAll(PDO::FETCH_ASSOC);
            $notTrackable = $rows === [];
            foreach ($rows as $row) {
                if ($row['quantity'] === null || (float)$row['quantity'] > 0) {
                    $notTrackable = true;
                    break;
                }
            }
            if ($notTrackable) {
                $this->db->rollBack();
                Response::error(
                    'NOT_TRACKABLE',
                    'Товар есть в наличии или данные хотя бы одного филиала ещё не загружены.',
                    409
                );
            }

            $existing = $this->db->prepare(
                'SELECT id, status FROM subscriptions
                 WHERE user_id = ? AND product_id = ? FOR UPDATE'
            );
            $existing->execute([$userId, $productId]);
            $current = $existing->fetch(PDO::FETCH_ASSOC);

            if ($current) {
                $subscriptionId = (int)$current['id'];
                if ($current['status'] !== 'active') {
                    // A new tracking cycle may send a new notification.
                    $deleteEvents = $this->db->prepare(
                        'DELETE FROM push_delivery_log WHERE subscription_id = ?'
                    );
                    $deleteEvents->execute([$subscriptionId]);
                    $reset = $this->db->prepare(
                        "UPDATE subscriptions
                         SET status='active', notified_at=NULL, last_push_error=NULL, push_attempts=0
                         WHERE id=?"
                    );
                    $reset->execute([$subscriptionId]);
                }
            } else {
                $insert = $this->db->prepare(
                    "INSERT INTO subscriptions (user_id, product_id, branch_id, status)
                     VALUES (?, ?, NULL, 'active')"
                );
                $insert->execute([$userId, $productId]);
                $subscriptionId = (int)$this->db->lastInsertId();
            }

            $this->db->commit();

            $stmt = $this->db->prepare(
                'SELECT id, product_id, branch_id, status, created_at, notified_at
                 FROM subscriptions WHERE id = ?'
            );
            $stmt->execute([$subscriptionId]);
            Response::success(['subscription' => $stmt->fetch(PDO::FETCH_ASSOC)], [], 201);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function cancel(Request $request): never
    {
        $userId = $this->userId($request);
        $body = $request->json();
        $subscriptionId = filter_var(
            $body['subscription_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($subscriptionId === false || $subscriptionId === null) {
            Response::error('VALIDATION_ERROR', 'Укажите корректный subscription_id.', 422);
        }

        $stmt = $this->db->prepare(
            "UPDATE subscriptions SET status='cancelled'
             WHERE id=? AND user_id=? AND status='active'"
        );
        $stmt->execute([$subscriptionId, $userId]);
        if ($stmt->rowCount() !== 1) {
            Response::error('SUBSCRIPTION_NOT_FOUND', 'Активная подписка не найдена.', 404);
        }
        Response::success(['subscription_id' => $subscriptionId, 'status' => 'cancelled']);
    }

    private function userId(Request $request): int
    {
        $userId = (new TokenService($this->db))->userId($request->bearerToken());
        if ($userId === null) {
            Response::error('UNAUTHENTICATED', 'Требуется действующий Bearer-токен.', 401);
        }
        return $userId;
    }
}
