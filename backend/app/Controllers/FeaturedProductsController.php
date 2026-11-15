<?php
declare(strict_types=1);

namespace Svet22\Controllers;

use PDO;
use Svet22\Core\Request;
use Svet22\Core\Response;
use Svet22\Services\AdminAuthService;
use Svet22\Views\AdminView;

final class FeaturedProductsController
{
    private AdminAuthService $auth;

    public function __construct(private PDO $db)
    {
        $this->auth = new AdminAuthService($db);
    }

    public function api(Request $request): never
    {
        $raw = $request->query()['branch_id'] ?? null;
        $branchId = filter_var($raw, FILTER_VALIDATE_INT);
        if ($branchId === false || $branchId === null || $branchId < 1) {
            Response::error('VALIDATION_ERROR', 'Укажите корректный branch_id.', 422);
        }
        $stmt = $this->db->prepare(
            'SELECT p.id, p.name, p.image_url, p.category_id, p.barcode, p.article,
                    s.branch_id, s.price, s.quantity
             FROM featured_products f
             JOIN products p ON p.id = f.product_id AND p.is_active = 1
             JOIN stock s ON s.product_id = p.id AND s.branch_id = ?
             JOIN branches b ON b.id = s.branch_id AND b.is_active = 1
             WHERE f.is_enabled = 1 AND s.quantity > 0
             ORDER BY f.sort_order, p.id LIMIT 60'
        );
        $stmt->execute([(int)$branchId]);
        Response::success($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function index(Request $request): never
    {
        $admin = $this->auth->requireUser();
        $q = trim((string)($request->query()['q'] ?? ''));
        $where = $q !== '' ? 'WHERE p.name LIKE ? OR p.barcode LIKE ? OR p.article LIKE ?' : '';
        $sql = 'SELECT p.id, p.name, p.barcode, p.image_url, f.is_enabled, f.sort_order,
                       (SELECT COUNT(*) FROM stock s WHERE s.product_id = p.id AND s.quantity > 0) AS branches_in_stock
                FROM products p LEFT JOIN featured_products f ON f.product_id = p.id '
            . $where . ' ORDER BY (f.is_enabled = 1) DESC, f.sort_order, p.name LIMIT 100';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($q !== '' ? array_fill(0, 3, '%' . mb_substr($q, 0, 100) . '%') : []);
        $rows = [];
        $csrf = AdminView::e($this->auth->csrfToken());
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $product) {
            $id = (int)$product['id'];
            $enabled = (int)($product['is_enabled'] ?? 0) === 1;
            $action = $admin['role'] === 'admin'
                ? '<form method="post" action="/admin/featured/' . $id . '">'
                    . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                    . '<label style="display:inline"><input style="display:inline;width:auto" type="checkbox" name="enabled" value="1"' . ($enabled ? ' checked' : '') . '> В подборке</label> '
                    . '<input style="display:inline;width:75px" type="number" name="sort_order" value="' . (int)($product['sort_order'] ?? 0) . '" aria-label="Порядок">'
                    . ' <button type="submit">Сохранить</button></form>'
                : ($enabled ? 'Да' : 'Нет');
            $rows[] = [$id, AdminView::e($product['name']), (int)$product['branches_in_stock'], $action];
        }
        $content = '<section class="card"><p>Отмечайте товары для рекламного блока «Супер выгода». '
            . 'В приложении показываются только отмеченные товары, имеющиеся в выбранном филиале. '
            . 'Отметка не меняет цену, остатки и не создаёт скидку.</p>'
            . '<form method="get" action="/admin/featured"><label>Найти товар по названию, штрихкоду или артикулу'
            . '<input name="q" value="' . AdminView::e($q) . '" maxlength="100"></label><button>Найти</button></form></section>'
            . AdminView::table(['ID', 'Товар', 'Филиалов с остатком', 'Управление'], $rows, 'Товары не найдены.');
        Response::html(AdminView::layout('Супер выгода', $content, [
            'username' => $admin['username'], 'role' => $admin['role'], 'csrf' => $this->auth->csrfToken()
        ]));
    }

    public function save(Request $request, array $params): never
    {
        $admin = $this->auth->requireRole('admin');
        $data = $request->post();
        if (!$this->auth->verifyCsrf($data['csrf_token'] ?? null)) {
            Response::html('Сессия формы устарела.', 419);
        }
        $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT);
        $sort = filter_var($data['sort_order'] ?? 0, FILTER_VALIDATE_INT);
        if (!$id || $id < 1 || $sort === false || abs($sort) > 1000000) {
            Response::html('Некорректный товар или порядок.', 422);
        }
        $exists = $this->db->prepare('SELECT id FROM products WHERE id=? AND is_active=1');
        $exists->execute([(int)$id]);
        if (!$exists->fetchColumn()) Response::html('Товар не найден.', 404);
        $enabled = ($data['enabled'] ?? '') === '1' ? 1 : 0;
        $stmt = $this->db->prepare(
            'INSERT INTO featured_products (product_id, sort_order, is_enabled) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE sort_order=VALUES(sort_order), is_enabled=VALUES(is_enabled)'
        );
        $stmt->execute([(int)$id, (int)$sort, $enabled]);
        $this->auth->audit((int)$admin['id'], 'featured.update', 'product', (int)$id, ['enabled'=>$enabled,'sort_order'=>(int)$sort], $request->clientIp());
        Response::redirect('/admin/featured');
    }
}
