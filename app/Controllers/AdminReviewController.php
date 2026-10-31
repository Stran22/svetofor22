<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Security\AdminGuard;

final class AdminReviewController
{
    public function index(Request $request): void
    {
        AdminGuard::requireRole(['owner', 'manager', 'moderator']);
        $status = strtolower(trim((string)($request->query['status'] ?? 'pending')));
        if (!in_array($status, ['pending', 'published', 'rejected'], true)) {
            throw new HttpException(422, 'REVIEW_STATUS_INVALID', 'Некорректный статус отзывов.');
        }
        $page = min(100000, max(1, (int)($request->query['page'] ?? 1)));
        $perPage = min(100, max(1, (int)($request->query['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;
        $pdo = Database::connection();
        $count = $pdo->prepare('SELECT COUNT(*) FROM reviews WHERE status=:status');
        $count->execute(['status' => $status]);
        $stmt = $pdo->prepare(
            'SELECT r.id,r.product_id,p.name AS product_name,r.user_id,u.display_name AS user_name,
                    r.rating,r.body,r.status,r.created_at,r.updated_at,r.moderated_at,
                    a.login AS moderated_by_login
             FROM reviews r
             JOIN products p ON p.id=r.product_id
             JOIN users u ON u.id=r.user_id
             LEFT JOIN admins a ON a.id=r.moderated_by
             WHERE r.status=:status
             ORDER BY r.created_at DESC,r.id DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $stmt->execute(['status' => $status]);
        Response::json(['success' => true, 'data' => [
            'items' => $stmt->fetchAll(),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => (int)$count->fetchColumn()],
        ]]);
    }

    public function moderate(Request $request): void
    {
        $admin = AdminGuard::requireRole(['owner', 'manager', 'moderator']);
        AdminGuard::requireCsrf();
        $reviewId = filter_var($request->body['review_id'] ?? null, FILTER_VALIDATE_INT);
        $status = strtolower(trim((string)($request->body['status'] ?? '')));
        if (!$reviewId || $reviewId < 1 || !in_array($status, ['published', 'rejected'], true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Укажите review_id и статус published либо rejected.');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $find = $pdo->prepare('SELECT id,status FROM reviews WHERE id=:id LIMIT 1 FOR UPDATE');
            $find->execute(['id' => $reviewId]);
            $review = $find->fetch();
            if (!$review) throw new HttpException(404, 'REVIEW_NOT_FOUND', 'Отзыв не найден.');
            if ($review['status'] !== 'pending') {
                throw new HttpException(409, 'REVIEW_ALREADY_MODERATED', 'Этот отзыв уже прошёл модерацию.');
            }
            $pdo->prepare(
                'UPDATE reviews SET status=:status,moderated_at=NOW(),moderated_by=:admin_id WHERE id=:id'
            )->execute(['status' => $status, 'admin_id' => $admin['id'], 'id' => $reviewId]);
            $pdo->prepare(
                'INSERT INTO admin_audit_log (admin_id,action,entity_type,entity_id,ip_address,details)
                 VALUES (:admin_id,:action,:entity_type,:entity_id,:ip_address,:details)'
            )->execute([
                'admin_id' => $admin['id'],
                'action' => 'review.' . $status,
                'entity_type' => 'review',
                'entity_id' => $reviewId,
                'ip_address' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                'details' => json_encode(['status' => $status], JSON_UNESCAPED_UNICODE),
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        Response::json(['success' => true, 'data' => ['review_id' => $reviewId, 'status' => $status]]);
    }
}