<?php
declare(strict_types=1);

namespace Svet22\Controllers;

use PDO;
use Svet22\Core\Request;
use Svet22\Core\Response;
use Svet22\Services\AdminAuthService;
use Svet22\Views\AdminView;

final class AdminPushController
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
        if (!in_array($status, ['', 'pending', 'processing', 'sent', 'failed'], true)) {
            $status = '';
        }
        $search = mb_substr(trim((string) ($query['q'] ?? '')), 0, 100);
        $page = max(1, min(100000, (int) ($query['page'] ?? 1)));
        $perPage = 30;
        $where = [];
        $params = [];
        if ($branchId > 0) {
            $where[] = 'l.branch_id = ?';
            $params[] = $branchId;
        }
        if ($status !== '') {
            $where[] = 'l.status = ?';
            $params[] = $status;
        }
        if ($search !== '') {
            $where[] = '(p.name LIKE ? OR p.article LIKE ? OR p.barcode LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }
        $base = ' FROM push_delivery_log l'
            . ' JOIN subscriptions s ON s.id = l.subscription_id'
            . ' JOIN products p ON p.id = s.product_id'
            . ' JOIN branches b ON b.id = l.branch_id';
        $condition = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $countStmt = $this->db->prepare('SELECT COUNT(*)' . $base . $condition);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare(
            'SELECT l.id, l.subscription_id, l.status, l.attempt_count, l.last_error,'
            . ' l.created_at, l.sent_at, p.name AS product_name, b.name AS branch_name,'
            . ' (SELECT COUNT(*) FROM push_device_deliveries d WHERE d.delivery_id=l.id) AS devices_total,'
            . " (SELECT COUNT(*) FROM push_device_deliveries d WHERE d.delivery_id=l.id AND d.status='sent') AS devices_sent,"
            . " (SELECT COUNT(*) FROM push_device_deliveries d WHERE d.delivery_id=l.id AND d.status='failed') AS devices_failed,"
            . " (SELECT COUNT(*) FROM push_device_deliveries d WHERE d.delivery_id=l.id AND d.status='unknown') AS devices_unknown"
            . $base . $condition . ' ORDER BY l.created_at DESC, l.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stats = $this->db->query(
            "SELECT COUNT(*) AS total,"
            . " COALESCE(SUM(status='pending'),0) AS pending_count,"
            . " COALESCE(SUM(status='processing'),0) AS processing_count,"
            . " COALESCE(SUM(status='sent'),0) AS sent_count,"
            . " COALESCE(SUM(status='failed'),0) AS failed_count"
            . ' FROM push_delivery_log'
        )->fetch(PDO::FETCH_ASSOC);
        $branches = $this->db->query('SELECT id, name FROM branches ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        $e = [AdminView::class, 'e'];
        $content = '<section class="card"><h2>Очередь Firebase</h2><p>'
            . 'Всего: <strong>' . (int) $stats['total'] . '</strong> · '
            . 'Ожидают: <strong>' . (int) $stats['pending_count'] . '</strong> · '
            . 'Обрабатываются: <strong>' . (int) $stats['processing_count'] . '</strong> · '
            . 'Приняты Firebase: <strong>' . (int) $stats['sent_count'] . '</strong> · '
            . 'Ошибки: <strong>' . (int) $stats['failed_count'] . '</strong></p>'
            . '<p class="muted">Статус «Отправлено» означает принятие запроса Firebase, а не подтверждение доставки на телефон. '
            . 'Результаты «Неизвестно» на отдельных устройствах требуют проверки, но не автоматической повторной отправки.</p></section>';

        $content .= '<section class="card"><h2>Фильтры</h2><form method="get" action="/admin/push">'
            . '<label>Магазин<select name="branch_id"><option value="0">Все магазины</option>';
        foreach ($branches as $branch) {
            $content .= '<option value="' . (int) $branch['id'] . '"'
                . ((int) $branch['id'] === $branchId ? ' selected' : '') . '>' . $e($branch['name']) . '</option>';
        }
        $content .= '</select></label><label>Статус<select name="status"><option value="">Все</option>';
        foreach (['pending' => 'Ожидает', 'processing' => 'Обрабатывается', 'sent' => 'Отправлено', 'failed' => 'Ошибка'] as $value => $label) {
            $content .= '<option value="' . $value . '"' . ($status === $value ? ' selected' : '') . '>' . $label . '</option>';
        }
        $content .= '</select></label><label>Товар, артикул или штрихкод'
            . '<input type="search" name="q" maxlength="100" value="' . $e($search) . '"></label>'
            . '<button type="submit">Показать</button></form></section>';

        $labels = ['pending' => 'Ожидает', 'processing' => 'Обрабатывается', 'sent' => 'Отправлено', 'failed' => 'Ошибка'];
        $tableRows = [];
        foreach ($rows as $row) {
            $devices = (int) $row['devices_total'];
            $deviceText = $devices === 0 ? '—' :
                'Всего ' . $devices . '; отправлено ' . (int) $row['devices_sent']
                . '; ошибок ' . (int) $row['devices_failed']
                . '; неизвестно ' . (int) $row['devices_unknown'];
            $tableRows[] = [
                '<a href="/admin/push/' . (int) $row['id'] . '">#' . (int) $row['id'] . '</a>',
                (int) $row['subscription_id'],
                $row['product_name'],
                $row['branch_name'],
                $labels[$row['status']] ?? 'Неизвестный статус',
                $deviceText,
                (int) $row['attempt_count'],
                $row['created_at'],
                $row['sent_at'] ?? '—',
                $row['last_error'] ?? '—',
            ];
        }
        $content .= '<section class="card"><h2>История уведомлений (' . $total . ')</h2>'
            . AdminView::table(
                ['ID', 'Подписка', 'Товар', 'Магазин', 'Статус', 'Устройства', 'Попытки', 'Создано', 'Отправлено', 'Ошибка'],
                $tableRows,
                'Уведомлений по выбранным фильтрам нет.'
            );
        if ($pages > 1) {
            $baseUrl = '/admin/push?' . http_build_query(['branch_id' => $branchId, 'status' => $status, 'q' => $search]);
            $content .= '<p>Страница ' . $page . ' из ' . $pages . ' · ';
            if ($page > 1) {
                $content .= '<a href="' . $e($baseUrl . '&page=' . ($page - 1)) . '">← Назад</a> ';
            }
            if ($page < $pages) {
                $content .= '<a href="' . $e($baseUrl . '&page=' . ($page + 1)) . '">Вперёд →</a>';
            }
            $content .= '</p>';
        }
        $content .= '</section>';
        Response::html(AdminView::layout('Push-уведомления', $content, $admin));
    }

    public function detail(Request $request, array $params): never
    {
        $id = (int) ($params['id'] ?? 0);
        $admin = $this->auth->requireUser();
        $admin['csrf'] = $this->auth->csrfToken();
        if ($id < 1) {
            Response::html(AdminView::layout('Уведомление не найдено', '<section class="card"><p>Некорректный ID уведомления.</p><p><a href="/admin/push">← К истории</a></p></section>', $admin), 404);
        }

        $stmt = $this->db->prepare(
            'SELECT l.id, l.subscription_id, l.status, l.attempt_count, l.created_at, l.updated_at, l.sent_at, l.last_error,'
            . ' s.status AS subscription_status, p.name AS product_name, b.name AS branch_name'
            . ' FROM push_delivery_log l'
            . ' JOIN subscriptions s ON s.id=l.subscription_id'
            . ' JOIN products p ON p.id=s.product_id'
            . ' JOIN branches b ON b.id=l.branch_id WHERE l.id=? LIMIT 1'
        );
        $stmt->execute([(int) $id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            Response::html(AdminView::layout('Уведомление не найдено', '<section class="card"><p>Уведомление не найдено.</p><p><a href="/admin/push">← К истории</a></p></section>', $admin), 404);
        }

        $stmt = $this->db->prepare(
            'SELECT d.id, d.token_id, d.status, d.attempt_count, d.created_at, d.updated_at,'
            . ' d.sent_at, d.last_error, d.provider_message_id'
            . ' FROM push_device_deliveries d WHERE d.delivery_id=? ORDER BY d.id'
        );
        $stmt->execute([(int) $id]);
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $labels = [
            'pending' => 'Ожидает',
            'processing' => 'Обрабатывается',
            'sent' => 'Отправлено',
            'failed' => 'Ошибка',
            'unknown' => 'Неизвестно',
            'active' => 'Активна',
            'notified' => 'Уведомлён',
            'cancelled' => 'Отменена',
        ];
        $e = [AdminView::class, 'e'];
        $content = '<p><a href="/admin/push">← К истории уведомлений</a></p>'
            . '<section class="card"><h2>Уведомление № ' . (int) $item['id'] . '</h2>'
            . '<p><strong>Товар:</strong> ' . $e($item['product_name'])
            . ' · <strong>Филиал:</strong> ' . $e($item['branch_name']) . '</p>'
            . '<p><strong>Подписка:</strong> № ' . (int) $item['subscription_id']
            . ' (' . $e($labels[$item['subscription_status']] ?? $item['subscription_status']) . ')</p>'
            . '<p><strong>Статус:</strong> ' . $e($labels[$item['status']] ?? $item['status'])
            . ' · <strong>Попытки:</strong> ' . (int) $item['attempt_count'] . '</p>'
            . '<p><strong>Создано:</strong> ' . $e($item['created_at'])
            . ' · <strong>Обновлено:</strong> ' . $e($item['updated_at'])
            . ' · <strong>Отправлено:</strong> ' . $e($item['sent_at'] ?? '—') . '</p>'
            . '<p><strong>Ошибка:</strong> ' . $e($item['last_error'] ?? '—') . '</p></section>';

        $rows = [];
        foreach ($devices as $device) {
            $rows[] = [
                (int) $device['id'],
                $device['token_id'] === null ? '—' : (int) $device['token_id'],
                $e($labels[$device['status']] ?? $device['status']),
                (int) $device['attempt_count'],
                $e($device['created_at']),
                $e($device['updated_at']),
                $e($device['sent_at'] ?? '—'),
                $e($device['last_error'] ?? '—'),
                $e($device['provider_message_id'] ?? '—'),
            ];
        }
        $content .= '<section class="card"><h2>Отправки по устройствам (' . count($rows) . ')</h2>'
            . '<p class="muted">ID устройства — внутренний номер записи токена, не сам FCM-токен. '
            . '«Отправлено» означает принятие Firebase, а не подтверждение показа на телефоне.</p>'
            . AdminView::table(
                ['ID доставки', 'ID устройства', 'Статус', 'Попытки', 'Создано', 'Обновлено', 'Отправлено', 'Ошибка', 'ID сообщения Firebase'],
                $rows,
                '—'
            ) . '</section>';
        Response::html(AdminView::layout('Push № ' . (int) $item['id'], $content, $admin));
    }

}
