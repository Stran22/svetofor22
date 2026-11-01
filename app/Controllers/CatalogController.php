<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use PDO;

final class CatalogController
{
    private const ACTIVE_CATEGORY_CTE = 'WITH RECURSIVE active_categories AS (
        SELECT id,parent_id,name,slug,image_url,sort_order FROM categories
        WHERE parent_id IS NULL AND is_active=1
        UNION ALL
        SELECT c.id,c.parent_id,c.name,c.slug,c.image_url,c.sort_order
        FROM categories c JOIN active_categories ac ON c.parent_id=ac.id
        WHERE c.is_active=1
    )';

    public function branches(Request $request): void
    {
        $rows = Database::connection()->query('SELECT id,name,slug,address,is_active FROM branches WHERE is_active=1 ORDER BY sort_order,name')->fetchAll();
        Response::json(['success' => true, 'data' => $rows]);
    }

    public function categories(Request $request): void
    {
        $rows = Database::connection()->query(
            self::ACTIVE_CATEGORY_CTE .
            ' SELECT id,parent_id,name,slug,image_url,sort_order FROM active_categories ORDER BY sort_order,name'
        )->fetchAll();
        Response::json(['success' => true, 'data' => $rows]);
    }

    public function products(Request $request): void
    {
        $page = max(1, min(100000, (int)($request->query['page'] ?? 1)));
        $limit = min(100, max(1, (int)($request->query['per_page'] ?? 24)));
        $branchId = $this->positiveInt($request->query['branch_id'] ?? null);
        $categoryId = $this->positiveInt($request->query['category_id'] ?? null);
        $search = trim((string)($request->query['q'] ?? ''));
        $pdo = Database::connection();
        if ($branchId !== null) {
            $branchCheck = $pdo->prepare('SELECT id FROM branches WHERE id=:id AND is_active=1 LIMIT 1');
            $branchCheck->execute(['id' => $branchId]);
            if (!$branchCheck->fetchColumn()) {
                throw new HttpException(404, 'BRANCH_NOT_FOUND', 'Магазин не найден или отключён.');
            }
        }
        if ($categoryId !== null) {
            $categoryCheck = $pdo->prepare(
                self::ACTIVE_CATEGORY_CTE . ' SELECT id FROM active_categories WHERE id=:id LIMIT 1'
            );
            $categoryCheck->execute(['id' => $categoryId]);
            if (!$categoryCheck->fetchColumn()) {
                throw new HttpException(404, 'CATEGORY_NOT_FOUND', 'Категория не найдена или отключена.');
            }
        }
        $where = ['p.is_active=1', '(p.category_id IS NULL OR EXISTS (SELECT 1 FROM active_categories visible_category WHERE visible_category.id=p.category_id))'];
        $params = [];
        if ($categoryId !== null) { $where[] = 'p.category_id=:category_id'; $params['category_id'] = [$categoryId, PDO::PARAM_INT]; }
        if ($search !== '') {
            $where[] = '(p.name LIKE :q OR p.barcode=:barcode OR p.sku=:sku)';
            $params['q'] = ['%' . $search . '%', PDO::PARAM_STR];
            $params['barcode'] = [$search, PDO::PARAM_STR];
            $params['sku'] = [$search, PDO::PARAM_STR];
        }
        $join = '';
        $stock = 'NULL AS price,NULL AS quantity,NULL AS is_available';
        if ($branchId !== null) {
            $join = ' LEFT JOIN stock s ON s.product_id=p.id AND s.branch_id=:branch_id';
            $stock = 's.price,COALESCE(s.quantity,0.000) AS quantity,(COALESCE(s.quantity,0)>0) AS is_available';
            $params['branch_id'] = [$branchId, PDO::PARAM_INT];
        }
        $sql = self::ACTIVE_CATEGORY_CTE . ' SELECT p.id,p.category_id,p.name,p.slug,p.sku,p.barcode,p.description,p.image_url,' . $stock .
            ' FROM products p' . $join . ' WHERE ' . implode(' AND ', $where) .
            ' ORDER BY p.name LIMIT :limit OFFSET :offset';
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => [$value, $type]) $stmt->bindValue(':' . $key, $value, $type);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $stmt->execute();
        Response::json(['success' => true, 'data' => $stmt->fetchAll(),
            'meta' => ['page' => $page, 'per_page' => $limit, 'branch_id' => $branchId]]);
    }

    public function productDetail(Request $request): void
    {
        $id = $this->positiveInt($request->query['id'] ?? null);
        if ($id === null) throw new HttpException(400, 'VALIDATION_ERROR', 'Параметр id обязателен.');
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            self::ACTIVE_CATEGORY_CTE .
            ' SELECT id,category_id,name,slug,sku,barcode,description,image_url FROM products
              WHERE id=:id AND is_active=1
                AND (category_id IS NULL OR EXISTS (SELECT 1 FROM active_categories ac WHERE ac.id=products.category_id))'
        );
        $stmt->execute(['id' => $id]);
        $product = $stmt->fetch();
        if (!$product) {
            Response::json(['success' => false, 'error' => ['code' => 'PRODUCT_NOT_FOUND', 'message' => 'Товар не найден.']], 404);
            return;
        }
        $stock = $pdo->prepare('SELECT b.id AS branch_id,b.name AS branch_name,b.address,s.price,
                   COALESCE(s.quantity,0.000) AS quantity,(COALESCE(s.quantity,0)>0) AS is_available
            FROM branches b LEFT JOIN stock s ON s.branch_id=b.id AND s.product_id=:product_id
            WHERE b.is_active=1 ORDER BY b.sort_order,b.name');
        $stock->execute(['product_id' => $id]);
        $product['branches'] = $stock->fetchAll();
        Response::json(['success' => true, 'data' => $product]);
    }

    private function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) throw new HttpException(400, 'VALIDATION_ERROR', 'Некорректный числовой параметр.');
        return $id;
    }
}
