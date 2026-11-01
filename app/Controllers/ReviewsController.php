<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\RateLimiter;
use App\Services\CatalogVisibility;
use App\Services\RequestUser;

final class ReviewsController
{
    public function index(Request $request): void
    {
        $productId = filter_var($request->query['product_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$productId || $productId < 1) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Укажите корректный product_id.');
        }
        $pdo = Database::connection();
        if (!CatalogVisibility::isProductVisible($pdo, $productId)) {
            throw new HttpException(404, 'PRODUCT_NOT_FOUND', 'Товар не найден.');
        }
        $summary = $pdo->prepare(
            "SELECT COUNT(*) AS reviews_count, ROUND(AVG(rating),2) AS average_rating
             FROM reviews WHERE product_id=:id AND status='published'"
        );
        $summary->execute(['id' => $productId]);
        $reviews = $pdo->prepare(
            "SELECT r.id,r.rating,r.body,r.created_at,r.updated_at,u.display_name
             FROM reviews r JOIN users u ON u.id=r.user_id
             WHERE r.product_id=:id AND r.status='published'
             ORDER BY r.created_at DESC,r.id DESC LIMIT 100"
        );
        $reviews->execute(['id' => $productId]);
        Response::json(['success' => true, 'data' => [
            'product_id' => $productId,
            'summary' => $summary->fetch(),
            'reviews' => $reviews->fetchAll(),
        ]]);
    }

    public function save(Request $request): void
    {
        $user = RequestUser::fromBearer($request);
        RateLimiter::hit('review_user', (string)$user['id'], 5, 3600);
        $social = Database::connection()->prepare('SELECT id FROM social_accounts WHERE user_id=:id LIMIT 1');
        $social->execute(['id' => $user['id']]);
        if (!$social->fetchColumn()) {
            throw new HttpException(403, 'SOCIAL_AUTH_REQUIRED', 'Оставлять отзывы могут только пользователи, вошедшие через социальную сеть.');
        }
        $productId = filter_var($request->body['product_id'] ?? null, FILTER_VALIDATE_INT);
        $ratingRaw = $request->body['rating'] ?? null;
        $rating = filter_var($ratingRaw, FILTER_VALIDATE_INT);
        $body = trim((string)($request->body['body'] ?? ''));
        if (!$productId || $productId < 1 || $rating === false || $rating < 1 || $rating > 5 || $body === '' || mb_strlen($body) > 500) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Проверьте товар, оценку от 1 до 5 и текст от 1 до 500 символов.');
        }
        $pdo = Database::connection();
        if (!CatalogVisibility::isProductVisible($pdo, $productId)) {
            throw new HttpException(404, 'PRODUCT_NOT_FOUND', 'Товар не найден.');
        }
        $save = $pdo->prepare(
            "INSERT INTO reviews (user_id,product_id,rating,body,status)
             VALUES (:user_id,:product_id,:rating,:body,'pending')
             ON DUPLICATE KEY UPDATE rating=VALUES(rating),body=VALUES(body),status='pending',moderated_at=NULL,moderated_by=NULL,updated_at=NOW()"
        );
        $save->execute(['user_id' => $user['id'], 'product_id' => $productId, 'rating' => $rating, 'body' => $body]);
        $stmt = $pdo->prepare('SELECT id,product_id,rating,body,status,created_at,updated_at FROM reviews WHERE user_id=:user_id AND product_id=:product_id');
        $stmt->execute(['user_id' => $user['id'], 'product_id' => $productId]);
        Response::json(['success' => true, 'data' => [
            'review' => $stmt->fetch(),
            'message' => 'Отзыв отправлен на модерацию.',
        ]], 201);
    }
}