<?php
declare(strict_types=1);

namespace Svet22\Controllers;

use PDO;
use PDOException;
use Svet22\Core\Request;
use Svet22\Core\Response;
use Svet22\Core\Config;
use Svet22\Services\TokenService;
use Throwable;

final class ApiController
{
    public function __construct(private PDO $db) {}

    public function health(Request $request): never
    {
        Response::success(['status' => 'ok', 'service' => 'svet22-api']);
    }

    public function healthDb(Request $request): never
    {
        $expected = (string) Config::get('security.health_token', '');
        $provided = $request->header('X-Health-Token') ?? '';

        if ($expected === '' || !hash_equals($expected, $provided)) {
            Response::error('FORBIDDEN', 'Доступ запрещён.', 403);
        }

        try {
            $this->db->query('SELECT 1');
            Response::success(['status' => 'ok', 'database' => 'connected']);
        } catch (Throwable) {
            Response::error(
                'DATABASE_UNAVAILABLE',
                'База данных временно недоступна.',
                503
            );
        }
    }

    public function branches(Request $request): never
    {
        $rows = $this->db->query(
            'SELECT id, code, name, address
             FROM branches
             WHERE is_active = 1
             ORDER BY id'
        )->fetchAll();

        Response::success($rows);
    }

    public function categories(Request $request): never
    {
        $rows = $this->db->query(
            'SELECT id, parent_id, name, slug, image_url, sort_order
             FROM categories
             WHERE is_active = 1
             ORDER BY sort_order, name'
        )->fetchAll();

        Response::success($rows);
    }

    public function products(Request $request): never
    {
        $q = trim((string) ($request->query()['q'] ?? ''));
        $branchRaw = $request->query()['branch_id'] ?? null;
        $categoryRaw = $request->query()['category_id'] ?? null;

        $branchId = $branchRaw === null
            ? null
            : filter_var($branchRaw, FILTER_VALIDATE_INT);

        $categoryId = $categoryRaw === null
            ? null
            : filter_var($categoryRaw, FILTER_VALIDATE_INT);

        if (
            ($branchRaw !== null && ($branchId === false || $branchId < 1)) ||
            ($categoryRaw !== null && ($categoryId === false || $categoryId < 1))
        ) {
            Response::error(
                'VALIDATION_ERROR',
                'branch_id и category_id должны быть положительными целыми числами.',
                422
            );
        }

        $inStock = filter_var(
            $request->query()['in_stock'] ?? null,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );

        $page = max(1, (int) ($request->query()['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($request->query()['per_page'] ?? 20)));
        $sort = (string) ($request->query()['sort'] ?? 'name');

        if (!in_array($sort, ['name', 'price_asc', 'price_desc'], true)) {
            Response::error(
                'VALIDATION_ERROR',
                'Недопустимый параметр sort.',
                422
            );
        }

        if (
            in_array($sort, ['price_asc', 'price_desc'], true) &&
            (!$branchId || $branchId === false)
        ) {
            Response::error(
                'VALIDATION_ERROR',
                'Для сортировки по цене укажите branch_id.',
                422
            );
        }

        $order = match ($sort) {
            'price_asc' => 's.price ASC, p.name ASC',
            'price_desc' => 's.price DESC, p.name ASC',
            default => 'p.name ASC',
        };

        $where = ['p.is_active = 1'];
        $params = [];

        if ($q !== '') {
            $where[] = 'p.name LIKE ?';
            $params[] = '%' . mb_substr($q, 0, 100) . '%';
        }

        if ($categoryId !== false && $categoryId !== null) {
            $where[] = 'p.category_id = ?';
            $params[] = $categoryId;
        }

        if ($branchId !== false && $branchId !== null) {
            $where[] = 's.branch_id = ?';
            $params[] = $branchId;
        }

        if ($inStock === true) {
            $where[] = 's.quantity > 0';
        }

        $whereSql = implode(' AND ', $where);

        $countSql =
            'SELECT COUNT(DISTINCT p.id)
             FROM products p
             LEFT JOIN stock s ON s.product_id = p.id
             WHERE ' . $whereSql;

        $count = $this->db->prepare($countSql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $sql =
            'SELECT p.id, p.barcode, p.article, p.name,
                    p.category_id, p.manufacturer, p.unit,
                    p.image_url, s.branch_id, s.price,
                    s.quantity, s.imported_on
             FROM products p
             LEFT JOIN stock s ON s.product_id = p.id
             WHERE ' . $whereSql .
            ' ORDER BY ' . $order .
            ' LIMIT ' . $perPage .
            ' OFFSET ' . (($page - 1) * $perPage);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        Response::success($rows, [
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    public function product(Request $request, array $params): never
    {
        $stmt = $this->db->prepare(
            'SELECT id, barcode, article, name, category_id,
                    manufacturer, unit, image_url, description
             FROM products
             WHERE id = ? AND is_active = 1
             LIMIT 1'
        );

        $stmt->execute([$params['id']]);
        $product = $stmt->fetch();

        if (!$product) {
            Response::error(
                'PRODUCT_NOT_FOUND',
                'Товар не найден.',
                404
            );
        }

        $stock = $this->db->prepare(
            'SELECT branch_id, price, quantity, imported_on, updated_at
             FROM stock
             WHERE product_id = ?
             ORDER BY branch_id'
        );

        $stock->execute([$params['id']]);
        $product['branches'] = $stock->fetchAll();

        $rating = $this->db->prepare(
            "SELECT COUNT(*) AS reviews_count,
                    AVG(rating) AS rating
             FROM reviews
             WHERE product_id = ?
               AND status = 'published'"
        );

        $rating->execute([$params['id']]);
        $product['reviews'] = $rating->fetch();

        Response::success($product);
    }

    // ===== НАЧАЛО БЛОКА: ПОЛУЧЕНИЕ ОТЗЫВОВ =====

    public function productReviews(Request $request, array $params): never
    {
        $productId = (int) ($params['id'] ?? 0);

        $this->ensureProductExists($productId);

        $query = $request->query();

        $pageRaw = $query['page'] ?? '1';
        $perPageRaw = $query['per_page'] ?? '20';

        $page = filter_var(
            $pageRaw,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 100000]]
        );

        $perPage = filter_var(
            $perPageRaw,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 100]]
        );

        if ($page === false || $perPage === false) {
            Response::error(
                'VALIDATION_ERROR',
                'page должен быть от 1 до 100000, per_page — от 1 до 100.',
                422
            );
        }

        $stats = $this->db->prepare(
            "SELECT COUNT(*) AS reviews_count,
                    AVG(rating) AS rating
             FROM reviews
             WHERE product_id = ?
               AND status = 'published'"
        );

        $stats->execute([$productId]);
        $summary = $stats->fetch(PDO::FETCH_ASSOC);

        $total = (int) ($summary['reviews_count'] ?? 0);
        $average = $summary['rating'] === null
            ? null
            : round((float) $summary['rating'], 2);

        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare(
            "SELECT r.id,
                    r.rating,
                    r.review_text,
                    r.created_at,
                    COALESCE(
                        NULLIF(u.display_name, ''),
                        'Покупатель'
                    ) AS author_name
             FROM reviews r
             INNER JOIN users u ON u.id = r.user_id
             WHERE r.product_id = :product_id
               AND r.status = 'published'
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT :limit OFFSET :offset"
        );

        $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        Response::success(
            [
                'reviews' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                'summary' => [
                    'reviews_count' => $total,
                    'rating' => $average,
                ],
            ],
            [
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => (int) ceil($total / $perPage),
                ],
            ]
        );
    }

    // ===== КОНЕЦ БЛОКА: ПОЛУЧЕНИЕ ОТЗЫВОВ =====

    // ===== НАЧАЛО БЛОКА: СОЗДАНИЕ ОТЗЫВА =====

    public function createProductReview(
        Request $request,
        array $params
    ): never {
        $productId = (int) ($params['id'] ?? 0);

        $this->ensureProductExists($productId);

        // Пользователь определяется только по Bearer-токену.
        $userId = $this->authenticatedUserId($request);

        // Гостям запрещено отправлять отзывы.
        $user = $this->db->prepare(
            'SELECT id, is_guest
             FROM users
             WHERE id = ?
             LIMIT 1'
        );

        $user->execute([$userId]);
        $account = $user->fetch(PDO::FETCH_ASSOC);

        if (!$account || (int) $account['is_guest'] !== 0) {
            Response::error(
                'AUTH_REQUIRED',
                'Чтобы оставить отзыв, войдите в аккаунт.',
                403
            );
        }

        $data = $request->json();

        $rating = filter_var(
            $data['rating'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 5]]
        );

        if ($rating === false) {
            Response::error(
                'VALIDATION_ERROR',
                'Оценка должна быть целым числом от 1 до 5.',
                422
            );
        }

        $reviewText = $data['review_text'] ?? null;

        if ($reviewText !== null && !is_string($reviewText)) {
            Response::error(
                'VALIDATION_ERROR',
                'Текст отзыва должен быть строкой.',
                422
            );
        }

        if ($reviewText !== null) {
            $reviewText = trim($reviewText);

            if (mb_strlen($reviewText, 'UTF-8') > 500) {
                Response::error(
                    'VALIDATION_ERROR',
                    'Текст отзыва не должен превышать 500 символов.',
                    422
                );
            }

            if ($reviewText === '') {
                $reviewText = null;
            }
        }

        // Уникальный индекс в MySQL дополнительно защищает
        // от двух отзывов одного пользователя на один товар.
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO reviews
                    (user_id, product_id, rating, review_text, status)
                 VALUES
                    (?, ?, ?, ?, 'pending')"
            );

            $stmt->execute([
                $userId,
                $productId,
                $rating,
                $reviewText,
            ]);

            $reviewId = (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            // MySQL 1062 — нарушение уникального индекса.
            if (
                ($e->errorInfo[1] ?? null) === 1062 &&
                str_contains(
                    $e->getMessage(),
                    'uq_reviews_user_product'
                )
            ) {
                Response::error(
                    'REVIEW_ALREADY_EXISTS',
                    'Вы уже оставляли отзыв на этот товар.',
                    409
                );
            }

            throw $e;
        }

        Response::success(
            [
                'id' => $reviewId,
                'product_id' => $productId,
                'rating' => $rating,
                'review_text' => $reviewText,
                'status' => 'pending',
                'message' => 'Отзыв отправлен на модерацию.',
            ],
            [],
            201
        );
    }

    // ===== КОНЕЦ БЛОКА: СОЗДАНИЕ ОТЗЫВА =====

    // ===== НАЧАЛО БЛОКА: ПРОВЕРКА ТОВАРА =====

    private function ensureProductExists(int $productId): void
    {
        if ($productId < 1) {
            Response::error(
                'PRODUCT_NOT_FOUND',
                'Товар не найден.',
                404
            );
        }

        $stmt = $this->db->prepare(
            'SELECT id
             FROM products
             WHERE id = ? AND is_active = 1
             LIMIT 1'
        );

        $stmt->execute([$productId]);

        if ($stmt->fetchColumn() === false) {
            Response::error(
                'PRODUCT_NOT_FOUND',
                'Товар не найден.',
                404
            );
        }
    }

    // ===== КОНЕЦ БЛОКА: ПРОВЕРКА ТОВАРА =====

    public function registerDevice(Request $request): never
    {
        $data = $request->json();
        $deviceId = strtolower(trim((string) ($data['device_id'] ?? '')));

        if (!preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $deviceId
        )) {
            Response::error(
                'VALIDATION_ERROR',
                'device_id должен быть корректным UUID.',
                422
            );
        }

        $this->db->beginTransaction();

        try {
            $find = $this->db->prepare(
                'SELECT id FROM users WHERE device_id = ? LIMIT 1'
            );

            $find->execute([$deviceId]);
            $userId = $find->fetchColumn();

            if ($userId === false) {
                $insert = $this->db->prepare(
                    'INSERT INTO users (device_id, is_guest) VALUES (?, 1)'
                );

                $insert->execute([$deviceId]);
                $userId = (int) $this->db->lastInsertId();
            } else {
                $userId = (int) $userId;
            }

            $token = (new TokenService($this->db))->issue($userId);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        Response::success(
            [
                'user_id' => $userId,
                'token_type' => 'Bearer',
                'access_token' => $token,
            ],
            [],
            201
        );
    }

    public function me(Request $request): never
    {
        $userId = $this->authenticatedUserId($request);

        $stmt = $this->db->prepare(
            'SELECT id, display_name, is_guest, push_enabled, created_at
             FROM users
             WHERE id = ?
             LIMIT 1'
        );

        $stmt->execute([$userId]);

        Response::success($stmt->fetch() ?: []);
    }

    public function logout(Request $request): never
    {
        $userId = $this->authenticatedUserId($request);

        (new TokenService($this->db))->revoke(
            $request->bearerToken()
        );

        Response::success([
            'logged_out' => true,
            'user_id' => $userId,
        ]);
    }

    private function authenticatedUserId(Request $request): int
    {
        $userId = (new TokenService($this->db))->userId(
            $request->bearerToken()
        );

        if ($userId === null) {
            Response::error(
                'UNAUTHENTICATED',
                'Требуется действующий Bearer-токен.',
                401
            );
        }

        return $userId;
    }
}
