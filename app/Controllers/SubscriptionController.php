<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\RateLimiter;
use App\Services\RequestUser;

final class SubscriptionController
{
    public function index(Request $request): void
    {
        $user = RequestUser::fromBearer($request);
        $stmt = Database::connection()->prepare(
            'SELECT s.id,s.product_id,s.branch_id,s.status,s.created_at,p.name AS product_name,p.image_url,
                    b.name AS branch_name,b.address AS branch_address
             FROM subscriptions s
             JOIN products p ON p.id=s.product_id
             JOIN branches b ON b.id=s.branch_id
             WHERE s.user_id=:user_id ORDER BY s.created_at DESC,s.id DESC'
        );
        $stmt->execute(['user_id' => $user['id']]);
        Response::json(['success' => true, 'data' => ['subscriptions' => $stmt->fetchAll()]]);
    }

    public function create(Request $request): void
    {
        $user = RequestUser::fromBearer($request);
        RateLimiter::hit('subscription_create_user', (string)$user['id'], 20, 3600);
        $productId = filter_var($request->body['product_id'] ?? null, FILTER_VALIDATE_INT);
        $branchId = filter_var($request->body['branch_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$productId || $productId < 1 || !$branchId || $branchId < 1) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Укажите корректные product_id и branch_id.');
        }
        $pdo = Database::connection();
        $valid = $pdo->prepare(
            'SELECT p.id FROM products p CROSS JOIN branches b
             WHERE p.id=:product_id AND p.is_active=1 AND b.id=:branch_id AND b.is_active=1 LIMIT 1'
        );
        $valid->execute(['product_id' => $productId, 'branch_id' => $branchId]);
        if (!$valid->fetchColumn()) throw new HttpException(404, 'SUBSCRIPTION_TARGET_NOT_FOUND', 'Товар или магазин не найден.');
        $pdo->prepare(
            "INSERT INTO subscriptions (user_id,product_id,branch_id,status,notified_at)
             VALUES (:user_id,:product_id,:branch_id,'active',NULL)
             ON DUPLICATE KEY UPDATE status='active',notified_at=NULL,updated_at=NOW()"
        )->execute(['user_id' => $user['id'], 'product_id' => $productId, 'branch_id' => $branchId]);
        $stmt = $pdo->prepare(
            'SELECT id,product_id,branch_id,status,created_at FROM subscriptions
             WHERE user_id=:user_id AND product_id=:product_id AND branch_id=:branch_id'
        );
        $stmt->execute(['user_id' => $user['id'], 'product_id' => $productId, 'branch_id' => $branchId]);
        Response::json(['success' => true, 'data' => ['subscription' => $stmt->fetch()]]);
    }

    public function cancel(Request $request): void
    {
        $user = RequestUser::fromBearer($request);
        RateLimiter::hit('subscription_cancel_user', (string)$user['id'], 30, 3600);
        $subscriptionId = filter_var($request->body['subscription_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$subscriptionId || $subscriptionId < 1) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Укажите корректный subscription_id.');
        }
        $pdo = Database::connection();
        $find = $pdo->prepare('SELECT id,status FROM subscriptions WHERE id=:id AND user_id=:user_id LIMIT 1');
        $find->execute(['id' => $subscriptionId, 'user_id' => $user['id']]);
        $subscription = $find->fetch();
        if (!$subscription) throw new HttpException(404, 'SUBSCRIPTION_NOT_FOUND', 'Подписка не найдена.');
        if ($subscription['status'] !== 'cancelled') {
            $pdo->prepare("UPDATE subscriptions SET status='cancelled',updated_at=NOW() WHERE id=:id AND user_id=:user_id")
                ->execute(['id' => $subscriptionId, 'user_id' => $user['id']]);
        }
        Response::json(['success' => true, 'data' => ['subscription_id' => $subscriptionId, 'status' => 'cancelled']]);
    }
}