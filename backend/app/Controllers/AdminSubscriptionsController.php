<?php
declare(strict_types=1);

namespace Svet22\Controllers;

use PDO;
use Svet22\Core\Request;
use Svet22\Core\Response;
use Svet22\Services\AdminAuthService;
use Svet22\Views\AdminView;

final class AdminSubscriptionsController
{
    private AdminAuthService $auth;

    public function __construct(private PDO $db)
    {
        $this->auth = new AdminAuthService($db);
    }

    public function index(Request $request): never
    {
        $admin = $this->auth->requireUser();
        $admin['csrf'] = $this->auth->csrfToken();
        $query = $request->query();
        $branchId = filter_var($query['branch_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $status = (string) ($query['status'] ?? '');
        if (!in_array($status, ['', 'active', 'notified', 'cancelled'], true)) {
            $status = '';
        }
        $search = trim((string) ($query['q'] ?? ''));
        $search = mb_substr($search, 0, 100);
        $where = [];
        $params = [];
        if ($branchId > 0) {
            $where[] = 's.branch_id = ?';
            $params[] = $branchId;
        }
        if ($status !== '') {
            $where[] = 's.status = ?';
            $params[] = $status;
        }
        if ($search !== '') {
            $where[] = '(p.name LIKE ? OR p.article LIKE ? OR p.barcode LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }
        $condition = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $base = ' FROM subscriptions s JOIN products p ON p.id = s.product_id JOIN branches b ON b.id = s.branch_id';
        $count = $this->db->prepare('SELECT COUNT(*)' . $base . $condition);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $page = max(1, min(100000, (int) ($query['page'] ?? 1)));
        $perPage = 30;
        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare(
            'SELECT s.id, s.user_id, p.name AS product_name, b.name AS branch_name,
                    s.status, s.created_at, s.notified_at'
            . $base . $condition . ' ORDER BY s.created_at DESC, s.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stats = $this->db->query(
            "SELECT COUNT(*) AS total, SUM(status = 'active') AS active_count,
                    SUM(notified_at IS NOT NULL) AS notified_count,
                    COUNT(DISTINCT product_id) AS product_count FROM subscriptions"
        )->fetch(PDO::FETCH_ASSOC);
        $branches = $this->db->query('SELECT id, name FROM branches ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        $e = [AdminView::class, 'e'];
        $content = '<section class="card"><h2>Сводка подписок</h2><p>Всего: <strong>' . (int) $stats['total']
            . '</strong> · Активных: <strong>' . (int) $stats['active_count']
            . '</strong> · С отметкой уведомления: <strong>' . (int) $stats['notified_count']
            . '</strong> · Товаров: <strong>' . (int) $stats['product_count'] . '</strong></p>'
            . '<p class="muted">Подписка означает интерес к товару в определённом филиале, а не подтверждённый заказ.</p></section>';
        $content .= '<section class="card"><h2>Фильтры</h2><form method="get" action="/admin/subscribers">'
            . '<label>Магазин<select name="branch_id"><option value="0">Все магазины</option>';
        foreach ($branches as $branch) {
            $selected = (int) $branch['id'] === $branchId ? ' selected' : '';
            $content .= '<option value="' . (int) $branch['id'] . '"' . $selected . '>' . $e($branch['name']) . '</option>';
        }
        $content .= '</select></label><label>Статус<select name="status"><option value="">Все</option>';
        foreach (['active' => 'Активные', 'notified' => 'Уведомлённые', 'cancelled' => 'Отменённые'] as $value => $label) {
            $content .= '<option value="' . $value . '"' . ($status === $value ? ' selected' : '') . '>' . $label . '</option>';
        }
        $content .= '</select></label><label>Товар, артикул или штрихкод<input type="search" name="q" maxlength="100" value="' . $e($search) . '"></label>'
            . '<button type="submit">Показать</button></form></section>';
        $tableRows = [];
        foreach ($rows as $row) {
            $tableRows[] = [
                (int) $row['id'],
                (int) $row['user_id'],
                $row['product_name'],
                $row['branch_name'],
                match ($row['status']) {
                    'active' => 'Активна',
                    'notified' => 'Уведомлён',
                    'cancelled' => 'Отменена',
                    default => 'Неизвестный статус',
                },
                $row['created_at'],
                $row['notified_at'] ?? '—',
            ];
        }
        $content .= '<section class="card"><h2>Подписки (' . $total . ')</h2>'
            . AdminView::table(['ID', 'Пользователь ID', 'Товар', 'Магазин', 'Статус', 'Подписался', 'Уведомлён'], $tableRows, 'Подписок не найдено.');
        $pages = (int) ceil($total / $perPage);
        if ($pages > 1) {
            $content .= '<p>Страница ' . $page . ' из ' . $pages . ' · ';
            $baseUrl = '/admin/subscribers?' . http_build_query(['branch_id' => $branchId, 'status' => $status, 'q' => $search]);
            if ($page > 1) {
                $content .= '<a href="' . $e($baseUrl . '&page=' . ($page - 1)) . '">← Назад</a> ';
            }
            if ($page < $pages) {
                $content .= '<a href="' . $e($baseUrl . '&page=' . ($page + 1)) . '">Вперёд →</a>';
            }
            $content .= '</p>';
        }
        $content .= '</section>';
        Response::html(AdminView::layout('Подписчики', $content, $admin));
    }
}
