<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Security\AdminGuard;
use App\Services\ImportHeaderMapper;
use App\Services\PushNotificationService;
use PDO;
use PDOException;
use Throwable;

final class AdminCatalogController
{
    public function products(Request $request): void
    {
        AdminGuard::requireRole(['owner', 'manager']);
        $page = min(100000, max(1, (int)($request->query['page'] ?? 1)));
        $perPage = min(100, max(1, (int)($request->query['per_page'] ?? 30)));
        $offset = ($page - 1) * $perPage;
        $query = trim((string)($request->query['q'] ?? ''));
        if (mb_strlen($query) > 100) throw new HttpException(422, 'SEARCH_QUERY_TOO_LONG', 'Поисковый запрос слишком длинный.');
        $categoryId = isset($request->query['category_id']) ? filter_var($request->query['category_id'], FILTER_VALIDATE_INT) : null;
        $where = [];
        $params = [];
        if ($query !== '') {
            $where[] = '(p.name LIKE :q_name OR p.sku LIKE :q_sku OR p.barcode LIKE :q_barcode)';
            $params['q_name'] = '%' . $query . '%';
            $params['q_sku'] = '%' . $query . '%';
            $params['q_barcode'] = '%' . $query . '%';
        }
        if ($categoryId !== null) {
            if (!$categoryId || $categoryId < 1) throw new HttpException(422, 'CATEGORY_ID_INVALID', 'Некорректный category_id.');
            $where[] = 'p.category_id=:category_id';
            $params['category_id'] = $categoryId;
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pdo = Database::connection();
        $count = $pdo->prepare('SELECT COUNT(*) FROM products p' . $whereSql);
        $count->execute($params);
        $stmt = $pdo->prepare(
            'SELECT p.id,p.category_id,c.name AS category_name,p.name,p.slug,p.sku,p.barcode,
                    p.description,p.image_url,p.unit,p.is_active,p.catalog_source_branch_id,
                    p.created_at,p.updated_at
             FROM products p LEFT JOIN categories c ON c.id=p.category_id' . $whereSql .
            ' ORDER BY p.name,p.id LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $stmt->execute($params);
        Response::json(['success' => true, 'data' => [
            'items' => $stmt->fetchAll(),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => (int)$count->fetchColumn()],
        ]]);
    }

    public function saveProduct(Request $request): void
    {
        $admin = AdminGuard::requireRole(['owner', 'manager']);
        AdminGuard::requireCsrf();
        $id = isset($request->body['id']) ? filter_var($request->body['id'], FILTER_VALIDATE_INT) : null;
        if ($id !== null && (!$id || $id < 1)) throw new HttpException(422, 'PRODUCT_ID_INVALID', 'Некорректный id товара.');
        $name = trim((string)($request->body['name'] ?? ''));
        $sku = trim((string)($request->body['sku'] ?? ''));
        $barcode = preg_replace('/\s+/u', '', trim((string)($request->body['barcode'] ?? ''))) ?? '';
        $unit = trim((string)($request->body['unit'] ?? 'шт.'));
        $description = trim((string)($request->body['description'] ?? ''));
        $imageUrl = trim((string)($request->body['image_url'] ?? ''));
        $categoryId = isset($request->body['category_id']) && $request->body['category_id'] !== ''
            ? filter_var($request->body['category_id'], FILTER_VALIDATE_INT)
            : null;
        if ($name === '' || mb_strlen($name) > 255 || mb_strlen($unit) > 30 || strlen($sku) > 100 || strlen($barcode) > 64 || strlen($description) > 60000) {
            throw new HttpException(422, 'PRODUCT_VALIDATION_ERROR', 'Проверьте наименование, единицу измерения, артикул, штрихкод и описание.');
        }
        if ($barcode !== '' && !preg_match('/^\d{1,64}$/', $barcode)) {
            throw new HttpException(422, 'BARCODE_INVALID', 'Штрихкод должен содержать только цифры.');
        }
        if ($imageUrl !== '' && !$this->isHttpsUrl($imageUrl)) {
            throw new HttpException(422, 'IMAGE_URL_INVALID', 'Ссылка на изображение должна быть корректным HTTPS-адресом.');
        }
        if ($categoryId !== null && (!$categoryId || $categoryId < 1)) {
            throw new HttpException(422, 'CATEGORY_ID_INVALID', 'Некорректный category_id.');
        }

        $pdo = Database::connection();
        if ($categoryId !== null) {
            $category = $pdo->prepare('SELECT id FROM categories WHERE id=:id LIMIT 1');
            $category->execute(['id' => $categoryId]);
            if (!$category->fetchColumn()) throw new HttpException(404, 'CATEGORY_NOT_FOUND', 'Категория не найдена.');
        }
        $pdo->beginTransaction();
        try {
            if ($id === null) {
                $slug = $this->makeSlug(trim((string)($request->body['slug'] ?? '')) ?: $name, 'product', 270);
                $stmt = $pdo->prepare(
                    'INSERT INTO products (category_id,name,slug,sku,barcode,description,image_url,unit,is_active)
                     VALUES (:category_id,:name,:slug,:sku,:barcode,:description,:image_url,:unit,:is_active)'
                );
                $stmt->execute([
                    'category_id' => $categoryId,
                    'name' => $name,
                    'slug' => $slug,
                    'sku' => $sku !== '' ? $sku : null,
                    'barcode' => $barcode !== '' ? $barcode : null,
                    'description' => $description !== '' ? $description : null,
                    'image_url' => $imageUrl !== '' ? $imageUrl : null,
                    'unit' => $unit !== '' ? $unit : 'шт.',
                    'is_active' => filter_var($request->body['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                ]);
                $id = (int)$pdo->lastInsertId();
                $this->audit($pdo, (int)$admin['id'], 'product.create', 'product', $id, ['name' => $name]);
                $pdo->commit();
                Response::json(['success' => true, 'data' => ['id' => $id]], 201);
                return;
            }

            $find = $pdo->prepare('SELECT id,slug,is_active FROM products WHERE id=:id LIMIT 1');
            $find->execute(['id' => $id]);
            $existing = $find->fetch();
            if (!$existing) throw new HttpException(404, 'PRODUCT_NOT_FOUND', 'Товар не найден.');
            $slugInput = trim((string)($request->body['slug'] ?? ''));
            $slug = $slugInput !== '' ? $this->makeSlug($slugInput, 'product', 270) : (string)$existing['slug'];
            $isActive = array_key_exists('is_active', $request->body)
                ? filter_var($request->body['is_active'], FILTER_VALIDATE_BOOLEAN)
                : (bool)$existing['is_active'];
            $pdo->prepare(
                'UPDATE products SET category_id=:category_id,name=:name,slug=:slug,sku=:sku,barcode=:barcode,
                 description=:description,image_url=:image_url,unit=:unit,is_active=:is_active WHERE id=:id'
            )->execute([
                'category_id' => $categoryId,
                'name' => $name,
                'slug' => $slug,
                'sku' => $sku !== '' ? $sku : null,
                'barcode' => $barcode !== '' ? $barcode : null,
                'description' => $description !== '' ? $description : null,
                'image_url' => $imageUrl !== '' ? $imageUrl : null,
                'unit' => $unit !== '' ? $unit : 'шт.',
                'is_active' => $isActive ? 1 : 0,
                'id' => $id,
            ]);
            $this->audit($pdo, (int)$admin['id'], 'product.update', 'product', (int)$id, ['name' => $name]);
            $pdo->commit();
            Response::json(['success' => true, 'data' => ['id' => (int)$id]]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e->getCode() === '23000') throw new HttpException(409, 'PRODUCT_IDENTIFIER_CONFLICT', 'Такой slug, артикул или штрихкод уже используется.');
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function categories(Request $request): void
    {
        AdminGuard::requireRole(['owner', 'manager']);
        $stmt = Database::connection()->query(
            'SELECT c.id,c.parent_id,p.name AS parent_name,c.name,c.slug,c.image_url,c.sort_order,c.is_active,c.created_at,c.updated_at
             FROM categories c LEFT JOIN categories p ON p.id=c.parent_id
             ORDER BY c.sort_order,c.name,c.id'
        );
        Response::json(['success' => true, 'data' => ['items' => $stmt->fetchAll()]]);
    }

    public function saveCategory(Request $request): void
    {
        $admin = AdminGuard::requireRole(['owner', 'manager']);
        AdminGuard::requireCsrf();
        $id = isset($request->body['id']) ? filter_var($request->body['id'], FILTER_VALIDATE_INT) : null;
        if ($id !== null && (!$id || $id < 1)) throw new HttpException(422, 'CATEGORY_ID_INVALID', 'Некорректный id категории.');
        $name = trim((string)($request->body['name'] ?? ''));
        $parentId = isset($request->body['parent_id']) && $request->body['parent_id'] !== ''
            ? filter_var($request->body['parent_id'], FILTER_VALIDATE_INT)
            : null;
        $imageUrl = trim((string)($request->body['image_url'] ?? ''));
        $sortOrder = (int)($request->body['sort_order'] ?? 0);
        $isActive = filter_var($request->body['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);
        if ($name === '' || mb_strlen($name) > 180 || ($parentId !== null && (!$parentId || $parentId < 1))) {
            throw new HttpException(422, 'CATEGORY_VALIDATION_ERROR', 'Проверьте название и родительскую категорию.');
        }
        if ($imageUrl !== '' && !$this->isHttpsUrl($imageUrl)) throw new HttpException(422, 'IMAGE_URL_INVALID', 'Ссылка на изображение должна быть HTTPS.');
        $pdo = Database::connection();
        $existingCategory = null;
        if ($id !== null) {
            $exists = $pdo->prepare('SELECT id,slug,is_active FROM categories WHERE id=:id LIMIT 1');
            $exists->execute(['id' => $id]);
            $existingCategory = $exists->fetch();
            if (!$existingCategory) throw new HttpException(404, 'CATEGORY_NOT_FOUND', 'Категория не найдена.');
            if (!array_key_exists('is_active', $request->body)) $isActive = (bool)$existingCategory['is_active'];
        }
        if ($parentId !== null) {
            if ($id !== null && $parentId === $id) throw new HttpException(422, 'CATEGORY_PARENT_CYCLE', 'Категория не может быть родителем самой себе.');
            $parent = $pdo->prepare('SELECT id FROM categories WHERE id=:id LIMIT 1');
            $parent->execute(['id' => $parentId]);
            if (!$parent->fetchColumn()) throw new HttpException(404, 'PARENT_CATEGORY_NOT_FOUND', 'Родительская категория не найдена.');
            if ($id !== null) {
                $cycle = $pdo->prepare(
                    'WITH RECURSIVE ancestors AS (
                        SELECT id,parent_id FROM categories WHERE id=:parent_id
                        UNION DISTINCT
                        SELECT c.id,c.parent_id FROM categories c JOIN ancestors a ON c.id=a.parent_id
                     )
                     SELECT id FROM ancestors WHERE id=:category_id LIMIT 1'
                );
                $cycle->execute(['parent_id' => $parentId, 'category_id' => $id]);
                if ($cycle->fetchColumn()) throw new HttpException(422, 'CATEGORY_PARENT_CYCLE', 'Выбранная родительская категория создаёт цикл.');
            }
        }
        $slugInput = trim((string)($request->body['slug'] ?? ''));
        $slug = $slugInput !== ''
            ? $this->makeSlug($slugInput, 'category', 180)
            : ($existingCategory === null ? $this->makeSlug($name, 'category', 180) : (string)$existingCategory['slug']);
        $pdo->beginTransaction();
        try {
            if ($id === null) {
                $stmt = $pdo->prepare(
                    'INSERT INTO categories (parent_id,name,slug,image_url,sort_order,is_active)
                     VALUES (:parent_id,:name,:slug,:image_url,:sort_order,:is_active)'
                );
                $stmt->execute([
                    'parent_id' => $parentId, 'name' => $name, 'slug' => $slug,
                    'image_url' => $imageUrl !== '' ? $imageUrl : null,
                    'sort_order' => $sortOrder, 'is_active' => $isActive ? 1 : 0,
                ]);
                $id = (int)$pdo->lastInsertId();
                $this->audit($pdo, (int)$admin['id'], 'category.create', 'category', $id, ['name' => $name]);
                $pdo->commit();
                Response::json(['success' => true, 'data' => ['id' => $id]], 201);
                return;
            }
            $pdo->prepare(
                'UPDATE categories SET parent_id=:parent_id,name=:name,slug=:slug,image_url=:image_url,
                 sort_order=:sort_order,is_active=:is_active WHERE id=:id'
            )->execute([
                'parent_id' => $parentId, 'name' => $name, 'slug' => $slug,
                'image_url' => $imageUrl !== '' ? $imageUrl : null,
                'sort_order' => $sortOrder, 'is_active' => $isActive ? 1 : 0, 'id' => $id,
            ]);
            $this->audit($pdo, (int)$admin['id'], 'category.update', 'category', (int)$id, ['name' => $name]);
            $pdo->commit();
            Response::json(['success' => true, 'data' => ['id' => (int)$id]]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e->getCode() === '23000') throw new HttpException(409, 'CATEGORY_SLUG_CONFLICT', 'Такая ссылка категории уже существует.');
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function branches(Request $request): void
    {
        AdminGuard::requireRole(['owner', 'manager']);
        $stmt = Database::connection()->query(
            'SELECT id,name,slug,address,phone,sort_order,is_active,created_at,updated_at
             FROM branches ORDER BY sort_order,name,id'
        );
        Response::json(['success' => true, 'data' => ['items' => $stmt->fetchAll()]]);
    }

    public function saveBranch(Request $request): void
    {
        $admin = AdminGuard::requireRole(['owner', 'manager']);
        AdminGuard::requireCsrf();
        $id = isset($request->body['id']) ? filter_var($request->body['id'], FILTER_VALIDATE_INT) : null;
        if ($id !== null && (!$id || $id < 1)) throw new HttpException(422, 'BRANCH_ID_INVALID', 'Некорректный id магазина.');
        $name = trim((string)($request->body['name'] ?? ''));
        $address = trim((string)($request->body['address'] ?? ''));
        $phone = trim((string)($request->body['phone'] ?? ''));
        $sortOrder = (int)($request->body['sort_order'] ?? 0);
        $isActive = filter_var($request->body['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);
        if ($name === '' || mb_strlen($name) > 150 || mb_strlen($address) > 255 || strlen($phone) > 40) {
            throw new HttpException(422, 'BRANCH_VALIDATION_ERROR', 'Проверьте название, адрес и телефон магазина.');
        }
        $pdo = Database::connection();
        if ($id !== null) {
            $find = $pdo->prepare('SELECT id,slug,is_active FROM branches WHERE id=:id LIMIT 1');
            $find->execute(['id' => $id]);
            $existing = $find->fetch();
            if (!$existing) throw new HttpException(404, 'BRANCH_NOT_FOUND', 'Магазин не найден.');
            if (!array_key_exists('is_active', $request->body)) $isActive = (bool)$existing['is_active'];
        }
        $slugInput = trim((string)($request->body['slug'] ?? ''));
        $slug = $slugInput !== ''
            ? $this->makeSlug($slugInput, 'branch', 150)
            : ($id === null ? $this->makeSlug($name, 'branch', 150) : $existing['slug']);
        $pdo->beginTransaction();
        try {
            if ($id === null) {
                $pdo->prepare(
                    'INSERT INTO branches (name,slug,address,phone,sort_order,is_active)
                     VALUES (:name,:slug,:address,:phone,:sort_order,:is_active)'
                )->execute([
                    'name' => $name, 'slug' => $slug, 'address' => $address !== '' ? $address : null,
                    'phone' => $phone !== '' ? $phone : null, 'sort_order' => $sortOrder, 'is_active' => $isActive ? 1 : 0,
                ]);
                $id = (int)$pdo->lastInsertId();
                $this->audit($pdo, (int)$admin['id'], 'branch.create', 'branch', $id, ['name' => $name]);
                $pdo->commit();
                Response::json(['success' => true, 'data' => ['id' => $id]], 201);
                return;
            }
            $pdo->prepare(
                'UPDATE branches SET name=:name,slug=:slug,address=:address,phone=:phone,sort_order=:sort_order,is_active=:is_active
                 WHERE id=:id'
            )->execute([
                'name' => $name, 'slug' => $slug, 'address' => $address !== '' ? $address : null,
                'phone' => $phone !== '' ? $phone : null, 'sort_order' => $sortOrder, 'is_active' => $isActive ? 1 : 0, 'id' => $id,
            ]);
            $this->audit($pdo, (int)$admin['id'], 'branch.update', 'branch', (int)$id, ['name' => $name]);
            $pdo->commit();
            Response::json(['success' => true, 'data' => ['id' => (int)$id]]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e->getCode() === '23000') throw new HttpException(409, 'BRANCH_SLUG_CONFLICT', 'Такая ссылка магазина уже существует.');
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function stock(Request $request): void
    {
        AdminGuard::requireRole(['owner', 'manager']);
        $branchId = filter_var($request->query['branch_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$branchId || $branchId < 1) throw new HttpException(422, 'BRANCH_ID_INVALID', 'Укажите branch_id.');
        $productId = isset($request->query['product_id']) ? filter_var($request->query['product_id'], FILTER_VALIDATE_INT) : null;
        $params = ['branch_id' => $branchId];
        $where = 's.branch_id=:branch_id';
        if ($productId !== null) {
            if (!$productId || $productId < 1) throw new HttpException(422, 'PRODUCT_ID_INVALID', 'Некорректный product_id.');
            $where .= ' AND s.product_id=:product_id';
            $params['product_id'] = $productId;
        }
        $stmt = Database::connection()->prepare(
            'SELECT s.id,s.product_id,p.name AS product_name,p.sku,p.barcode,s.price,s.quantity,s.available_since,s.updated_at
             FROM stock s JOIN products p ON p.id=s.product_id WHERE ' . $where . ' ORDER BY p.name LIMIT 1000'
        );
        $stmt->execute($params);
        Response::json(['success' => true, 'data' => ['items' => $stmt->fetchAll()]]);
    }

    public function saveStock(Request $request): void
    {
        $admin = AdminGuard::requireRole(['owner', 'manager']);
        AdminGuard::requireCsrf();
        $branchId = filter_var($request->body['branch_id'] ?? null, FILTER_VALIDATE_INT);
        $productId = filter_var($request->body['product_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$branchId || $branchId < 1 || !$productId || $productId < 1) {
            throw new HttpException(422, 'STOCK_TARGET_INVALID', 'Укажите корректные branch_id и product_id.');
        }
        try {
            $price = ImportHeaderMapper::parseDecimal($request->body['price'] ?? null, 2);
            $quantity = ImportHeaderMapper::parseDecimal($request->body['quantity'] ?? null, 3);
        } catch (\RuntimeException $e) {
            throw new HttpException(422, 'STOCK_VALUE_INVALID', $e->getMessage());
        }

        $pdo = Database::connection();
        $restocked = false;
        $stockId = 0;
        $auditId = 0;
        $eventKey = null;
        $pdo->beginTransaction();
        try {
            $target = $pdo->prepare(
                'SELECT b.id AS branch_id,b.is_active AS branch_active,
                        p.id AS product_id,p.is_active AS product_active
                 FROM branches b CROSS JOIN products p
                 WHERE b.id=:branch_id AND p.id=:product_id LIMIT 1 FOR UPDATE'
            );
            $target->execute(['branch_id' => $branchId, 'product_id' => $productId]);
            $targetRow = $target->fetch();
            if (!$targetRow) throw new HttpException(404, 'STOCK_TARGET_NOT_FOUND', 'Магазин или товар не найден.');
            if (!(bool)$targetRow['branch_active'] || !(bool)$targetRow['product_active']) {
                throw new HttpException(409, 'STOCK_TARGET_INACTIVE', 'Нельзя изменять остаток отключённого магазина или товара.');
            }

            $find = $pdo->prepare(
                'SELECT id,price,quantity FROM stock WHERE branch_id=:branch_id AND product_id=:product_id LIMIT 1 FOR UPDATE'
            );
            $find->execute(['branch_id' => $branchId, 'product_id' => $productId]);
            $existing = $find->fetch();
            if (!$existing) {
                $pdo->prepare(
                    'INSERT INTO stock (branch_id,product_id,price,quantity,available_since)
                     VALUES (:branch_id,:product_id,:price,:quantity,IF(:available=1,NOW(),NULL))'
                )->execute([
                    'branch_id' => $branchId, 'product_id' => $productId, 'price' => $price,
                    'quantity' => $quantity, 'available' => (float)$quantity > 0 ? 1 : 0,
                ]);
                $stockId = (int)$pdo->lastInsertId();
                $priceChanged = true;
                $restocked = (float)$quantity > 0;
                $oldPrice = null;
                $oldQuantity = '0.000';
            } else {
                $stockId = (int)$existing['id'];
                $oldPrice = $existing['price'] === null ? null : (string)$existing['price'];
                $oldQuantity = (string)$existing['quantity'];
                $priceChanged = $oldPrice === null || $oldPrice !== $price;
                $restocked = (float)$oldQuantity <= 0 && (float)$quantity > 0;
                if ($restocked) {
                    $pdo->prepare('UPDATE stock SET price=:price,quantity=:quantity,available_since=NOW() WHERE id=:id')
                        ->execute(['price' => $price, 'quantity' => $quantity, 'id' => $stockId]);
                } elseif ((float)$quantity <= 0) {
                    $pdo->prepare('UPDATE stock SET price=:price,quantity=:quantity,available_since=NULL WHERE id=:id')
                        ->execute(['price' => $price, 'quantity' => $quantity, 'id' => $stockId]);
                } else {
                    $pdo->prepare('UPDATE stock SET price=:price,quantity=:quantity WHERE id=:id')
                        ->execute(['price' => $price, 'quantity' => $quantity, 'id' => $stockId]);
                }
            }
            if ($priceChanged) {
                $pdo->prepare(
                    "INSERT INTO price_history (branch_id,product_id,price,recorded_at,source)
                     VALUES (:branch_id,:product_id,:price,NOW(),'admin')"
                )->execute(['branch_id' => $branchId, 'product_id' => $productId, 'price' => $price]);
            }
            $this->audit($pdo, (int)$admin['id'], 'stock.update', 'stock', $stockId, [
                'old_price' => $oldPrice, 'new_price' => $price,
                'old_quantity' => $oldQuantity, 'new_quantity' => $quantity,
            ]);
            $auditId = (int)$pdo->lastInsertId();
            if ($restocked) {
                $eventKey = 'restock:admin_audit:' . $auditId;
                (new PushNotificationService())->enqueueRestock($pdo, $productId, $branchId, $eventKey);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        if ($restocked && $eventKey !== null) {
            try {
                (new PushNotificationService())->notifyRestock($productId, $branchId, $eventKey);
            } catch (Throwable $e) {
                error_log('Admin stock restock notification failed: ' . $e->getMessage());
            }
        }
        Response::json(['success' => true, 'data' => ['stock_id' => $stockId, 'restocked' => $restocked]]);
    }

    private function makeSlug(string $value, string $fallback, int $maxLength = 250): string
    {
        $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $slug = strtolower((string)$slug);
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '', '-');
        if ($slug === '') $slug = $fallback;
        return substr($slug, 0, max(1, $maxLength));
    }

    private function isHttpsUrl(string $url): bool
    {
        if (strlen($url) > 500 || !filter_var($url, FILTER_VALIDATE_URL)) return false;
        return strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'https';
    }

    private function audit(PDO $pdo, int $adminId, string $action, string $entityType, int $entityId, array $details): void
    {
        $pdo->prepare(
            'INSERT INTO admin_audit_log (admin_id,action,entity_type,entity_id,ip_address,details)
             VALUES (:admin_id,:action,:entity_type,:entity_id,:ip_address,:details)'
        )->execute([
            'admin_id' => $adminId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
    }
}