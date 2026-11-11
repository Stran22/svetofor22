<?php
declare(strict_types=1);

namespace Svet22\Controllers;

use PDO;
use Svet22\Core\Request;
use Svet22\Core\Response;

final class ReviewController
{
    public function __construct(private PDO $db) {}

    public function index(Request $request, array $params): never
    {
        $productId = (int) ($params['id'] ?? 0);
        if ($productId < 1) {
            Response::error('VALIDATION_ERROR', 'Некорректный идентификатор товара.', 422);
        }

        $exists = $this->db->prepare('SELECT 1 FROM products WHERE id = ? AND is_active = 1 LIMIT 1');
        $exists->execute([$productId]);
        if ($exists->fetchColumn() === false) {
            Response::error('PRODUCT_NOT_FOUND', 'Товар не найден.', 404);
        }

        $query = $request->query();
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT);
        $perPage = filter_var($query['per_page'] ?? 20, FILTER_VALIDATE_INT);
        if ($page === false || $page < 1 || $perPage === false || $perPage < 1 || $perPage > 100) {
            Response::error('VALIDATION_ERROR', 'page должен быть положительным, per_page — от 1 до 100.', 422);
        }
        if ($page > 1000000) {
            Response::error('VALIDATION_ERROR', 'Слишком большой номер страницы.', 422);
        }

        $summaryStmt = $this->db->prepare(
            "SELECT COUNT(*) AS reviews_count, AVG(rating) AS rating
             FROM reviews WHERE product_id = ? AND status = 'published'"
        );
        $summaryStmt->execute([$productId]);
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['reviews_count' => 0, 'rating' => null];
        $total = (int) $summary['reviews_count'];
        $summary['reviews_count'] = $total;
        $summary['rating'] = $summary['rating'] === null ? null : (float) $summary['rating'];

        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare(
            "SELECT r.id, r.rating, r.review_text,
                    COALESCE(NULLIF(TRIM(u.display_name), ''), 'Покупатель') AS author_name,
                    r.created_at
             FROM reviews r
             JOIN users u ON u.id = r.user_id
             WHERE r.product_id = ? AND r.status = 'published'
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $productId, PDO::PARAM_INT);
        $stmt->bindValue(2, $perPage, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();

        Response::success([
            'reviews' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'summary' => $summary,
        ], [
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /** Reviews belonging to the current authenticated social account, including moderation state. */
    public function mine(Request $request): never
    {
        $userId = (new \Svet22\Services\TokenService($this->db))->userId($request->bearerToken());
        if ($userId === null) {
            Response::error('UNAUTHENTICATED', 'Требуется авторизация.', 401);
        }
        $check = $this->db->prepare('SELECT is_guest FROM users WHERE id = ? LIMIT 1');
        $check->execute([$userId]);
        $guest = $check->fetchColumn();
        if ($guest === false || (int) $guest !== 0) {
            Response::error('SOCIAL_LOGIN_REQUIRED', 'Войдите через VK ID.', 403);
        }
        $stmt = $this->db->prepare(
            'SELECT r.id, r.product_id, p.name AS product_name, r.rating, r.review_text,
                    r.status, r.created_at
             FROM reviews r
             JOIN products p ON p.id = r.product_id
             WHERE r.user_id = ?
             ORDER BY r.created_at DESC, r.id DESC LIMIT 100'
        );
        $stmt->execute([$userId]);
        Response::success(['reviews' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public function create(Request $request, array $params): never
    {
        $token = $request->bearerToken();
        $userId = (new \Svet22\Services\TokenService($this->db))->userId($token);
        if ($userId === null) {
            Response::error('UNAUTHENTICATED', 'Требуется авторизация.', 401);
        }

        $data = $request->json();
        $rating = $data['rating'] ?? null;
        $reviewText = $data['review_text'] ?? null;
        if (!is_int($rating) || $rating < 1 || $rating > 5 ||
            ($reviewText !== null && !is_string($reviewText))) {
            Response::error('VALIDATION_ERROR', 'Оценка должна быть от 1 до 5, текст — строкой.', 422);
        }
        $reviewText = $reviewText === null ? null : trim($reviewText);
        if ($reviewText !== null && mb_strlen($reviewText, 'UTF-8') > 500) {
            Response::error('VALIDATION_ERROR', 'Текст отзыва не должен превышать 500 символов.', 422);
        }
        if ($reviewText === '') {
            $reviewText = null;
        }

        $productId = (int) ($params['id'] ?? 0);
        $product = $this->db->prepare('SELECT 1 FROM products WHERE id = ? AND is_active = 1 LIMIT 1');
        $product->execute([$productId]);
        if ($product->fetchColumn() === false) {
            Response::error('PRODUCT_NOT_FOUND', 'Товар не найден.', 404);
        }

        $this->db->beginTransaction();
        try {
            // Lock the user row to serialize concurrent submissions from the same account.
            $user = $this->db->prepare('SELECT is_guest FROM users WHERE id = ? FOR UPDATE');
            $user->execute([$userId]);
            $guest = $user->fetchColumn();
            if ($guest === false || (int) $guest !== 0) {
                $this->db->rollBack();
                Response::error('SOCIAL_LOGIN_REQUIRED', 'Для отзыва войдите через социальную сеть.', 403);
            }

            $social = $this->db->prepare('SELECT 1 FROM social_accounts WHERE user_id = ? LIMIT 1');
            $social->execute([$userId]);
            if ($social->fetchColumn() === false) {
                $this->db->rollBack();
                Response::error('SOCIAL_LOGIN_REQUIRED', 'Для отзыва войдите через социальную сеть.', 403);
            }

            $existing = $this->db->prepare('SELECT id FROM reviews WHERE user_id = ? AND product_id = ? LIMIT 1');
            $existing->execute([$userId, $productId]);
            if ($existing->fetchColumn() !== false) {
                $this->db->rollBack();
                Response::error('REVIEW_EXISTS', 'Вы уже оставили отзыв на этот товар.', 409);
            }

            $insert = $this->db->prepare(
                "INSERT INTO reviews (user_id, product_id, rating, review_text, status)
                 VALUES (?, ?, ?, ?, 'pending')"
            );
            $insert->execute([$userId, $productId, $rating, $reviewText]);
            $reviewId = (int) $this->db->lastInsertId();
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        Response::success(['id' => $reviewId, 'status' => 'pending'], [], 201);
    }

}