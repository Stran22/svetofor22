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
        $status = (string)($query['status'] ?? '');
        if (!in_array($status, ['', 'active', 'notified', 'cancelled'], true)) {
            $status = '';
        }
        $search = mb_substr(trim((string)($query['q'] ?? '')), 0, 100);
        $where = [];
        $params = [];
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
        $base = ' FROM subscriptions s INNER JOIN products p ON p.id = s.product_id';
        $count = $this->db->prepare('SELECT COUNT(*)' . $base . $condition);
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $page = max(1, min(100000, (int)($query['page'] ?? 1)));
        $perPage = 30;
        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare(
            "SELECT s.id, s.user_id, p.name AS product_name, s.status, s.created_at, s.notified_at"
            . $base . $condition . ' ORDER BY s.created_at DESC, s.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stats = $this->db->query(
            "SELECT COUNT(*) AS total, SUM(status='active') AS active_count,
                    SUM(notified_at IS NOT NULL) AS notified_count,
                    COUNT(DISTINCT product_id) AS product_count
             FROM subscriptions"
        )->fetch(PDO::FETCH_ASSOC);

        $e = [AdminView::class, 'e'];
        $content = '<section class="card"><h2>Сводка подписок</h2><p>Всего: <strong>' . (int)$stats['total']
            . '</strong> · Активных: <strong>' . (int)$stats['active_count']
            . '</strong> · Уведомлённых: <strong>' . (int)$stats['notified_count']
            . '</strong> · Товаров: <strong>' . (int)$stats['product_count'] . '</strong></p>'
            . '<p class="muted">Одна подписка относится ко всему товару. Уведомление отправляется при появлении товара в любом активном филиале.</p></section>';

        $content .= '<section class="card"><h2>Фильтры</h2><form method="get" action="/admin/subscribers">'
            . '<label>Статус<select name="status"><option value="">Все</option>';
        foreach (['active' => 'Активные', 'notified' => 'Уведомлённые', 'cancelled' => 'Отменённые'] as $value => $label) {
            $content .= '<option value="' . $value . '"' . ($status === $value ? ' selected' : '') . '>' . $label . '</option>';
        }
        $content .= '</select></label><label>Товар, артикул или штрихкод<input type="search" name="q" maxlength="100" value="' . $e($search) . '"></label>'
            . '<button type="submit">Показать</button></form></section>';

        $demandWhere = ["s.status = 'active'"];
        $demandParams = [];
        if ($search !== '') {
            $demandWhere[] = '(p.name LIKE ? OR p.article LIKE ? OR p.barcode LIKE ?)';
            $like = '%' . $search . '%';
            array_push($demandParams, $like, $like, $like);
        }
        $demandStmt = $this->db->prepare(
            'SELECT p.name AS product_name, COUNT(*) AS waiting_count'
            . $base . ' WHERE ' . implode(' AND ', $demandWhere)
            . ' GROUP BY s.product_id, p.name ORDER BY waiting_count DESC, p.name ASC LIMIT 50'
        );
        $demandStmt->execute($demandParams);
        $demandRows = [];
        foreach ($demandStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $demandRows[] = [$row['product_name'], (int)$row['waiting_count']];
        }
        $content .= '<section class="card"><h2>Самые ожидаемые товары</h2>'
            . '<p class="muted">Количество активных подписок на товар. Все подписчики ожидают поступления в любом филиале.</p>'
            . AdminView::table(['Товар', 'Ожидают'], $demandRows, 'Активных ожиданий нет.')
            . '</section>';

        $tableRows = [];
        foreach ($rows as $row) {
            $tableRows[] = [
                (int)$row['id'],
                (int)$row['user_id'],
                $row['product_name'],
                'Любой филиал',
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
        $pages = (int)ceil($total / $perPage);
        if ($pages > 1) {
            $content .= '<p>Страница ' . $page . ' из ' . $pages . ' · ';
            $baseUrl = '/admin/subscribers?' . http_build_query(['status' => $status, 'q' => $search]);
            if ($page > 1) $content .= '<a href="' . $e($baseUrl . '&page=' . ($page - 1)) . '">← Назад</a> ';
            if ($page < $pages) $content .= '<a href="' . $e($baseUrl . '&page=' . ($page + 1)) . '">Вперёд →</a>';
            $content .= '</p>';
        }
        $content .= '</section>';
        Response::html(AdminView::layout('Подписчики', $content, $admin));
    }
}
