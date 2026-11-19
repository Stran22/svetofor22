<?php
declare(strict_types=1);

namespace Svet22\Controllers;

use PDO;
use Svet22\Core\Request;
use Svet22\Core\Response;
use Svet22\Services\AdminAuthService;
use Svet22\Views\AdminView;

final class AdminTrafficAnalyticsController
{
    public function __construct(private PDO $db) {}

    public function index(Request $request): never
    {
        $auth = new AdminAuthService($this->db);
        $admin = $auth->requireUser();
        $admin['csrf'] = $auth->csrfToken();
        $period = (string) ($request->query()['period'] ?? 'today');
        $days = ['today' => 1, 'week' => 7, 'month' => 30][$period] ?? 1;
        if (!in_array($period, ['today','week','month'], true)) $period = 'today';
        $start = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
        $end = date('Y-m-d 00:00:00', strtotime('+1 day'));
        $previous = date('Y-m-d 00:00:00', strtotime('-' . ($days * 2 - 1) . ' days'));
        $e = [AdminView::class, 'e'];

        $metrics = [
            ['Посетителей', 'SELECT COUNT(DISTINCT user_id) FROM app_analytics_events WHERE event_type=\'visit\' AND created_at>=? AND created_at<?'],
            ['Поисковых запросов', 'SELECT COUNT(*) FROM search_events WHERE created_at>=? AND created_at<?'],
            ['Просмотры товаров', 'SELECT COUNT(*) FROM app_analytics_events WHERE event_type=\'product_view\' AND created_at>=? AND created_at<?'],
            ['Новых подписок', 'SELECT COUNT(*) FROM subscriptions WHERE created_at>=? AND created_at<?']
        ];
        $css = '<style>
        .ta-periods{display:flex;gap:8px;margin:14px 0 18px;flex-wrap:wrap}.ta-periods a{border-radius:22px;padding:9px 17px;background:#eaf1f9;color:#123d72;text-decoration:none}.ta-periods a.active{background:#123d72;color:white;font-weight:700}
        .ta-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:15px}.ta-stat,.ta-card{background:#fff;border:1px solid #e1e8f0;border-radius:14px;padding:19px;box-shadow:0 3px 14px #123d7208}.ta-stat strong{display:block;font-size:27px;color:#123d72}.ta-stat small{color:#6c7a89}.ta-change{font-size:12px;color:#14579a}.ta-card{margin-top:18px}.ta-card h2{margin:0 0 15px;font-size:18px;color:#123d72}.ta-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(90px,1.3fr) 34px;align-items:center;gap:10px;margin:11px 0;font-size:13px}.ta-name{overflow:hidden;white-space:nowrap;text-overflow:ellipsis}.ta-meter{height:11px;background:#e5edf7;border-radius:20px;overflow:hidden}.ta-meter span{display:block;height:100%;background:#14579a;border-radius:20px}.ta-num{text-align:right;font-weight:bold}.ta-note{font-size:12px;color:#6c7a89;margin-top:16px}@media(max-width:520px){.ta-stat{padding:12px}.ta-stat strong{font-size:23px}.ta-row{gap:6px}}</style>';
        $html = $css . '<div class="ta-periods">';
        foreach (['today'=>'Сегодня','week'=>'Неделя','month'=>'Месяц'] as $key=>$label) {
            $html .= '<a class="' . ($period === $key ? 'active' : '') . '" href="/admin/analytics?period=' . $key . '">' . $label . '</a>';
        }
        $html .= '</div><div class="ta-grid">';
        foreach ($metrics as [$label,$sql]) {
            $current = $this->count($sql, [$start,$end]);
            $before = $this->count($sql, [$previous,$start]);
            $change = $before ? round(($current - $before) * 100 / $before) : null;
            $html .= '<div class="ta-stat"><strong>' . $current . '</strong><small>' . $e($label) . '</small><div class="ta-change">'
                . ($change === null ? 'Нет базы для сравнения' : (($change > 0 ? '+' : '') . $change . '% к прошлому периоду')) . '</div></div>';
        }
        $html .= '</div>';
        $html .= $this->ranking('Что ищут посетители',
            'SELECT query_text AS name,COUNT(*) AS total FROM search_events WHERE created_at>=? AND created_at<? GROUP BY query_text ORDER BY total DESC,name LIMIT 10',
            [$start,$end]);
        $html .= $this->ranking('Что смотрят чаще всего',
            "SELECT p.name AS name,COUNT(*) AS total FROM app_analytics_events a JOIN products p ON p.id=a.product_id WHERE a.event_type='product_view' AND a.created_at>=? AND a.created_at<? GROUP BY p.id,p.name ORDER BY total DESC,name LIMIT 10",
            [$start,$end]);
        $html .= $this->ranking('Клики по рубрикам',
            "SELECT c.name AS name,COUNT(*) AS total FROM app_analytics_events a JOIN categories c ON c.id=a.category_id WHERE a.event_type='category_click' AND a.created_at>=? AND a.created_at<? GROUP BY c.id,c.name ORDER BY total DESC,name LIMIT 20",
            [$start,$end]);
        $html .= '<p class="ta-note">Статистика обновляется при действиях посетителей. Учитываются гости с токеном приложения и авторизованные пользователи. Данные появляются после установки новой версии Android и применения миграции 010.</p>';
        Response::html(AdminView::layout('Аналитика', $html, $admin));
    }

    private function count(string $sql, array $params): int
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function ranking(string $title, string $sql, array $params): string
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $html = '<section class="ta-card"><h2>' . AdminView::e($title) . '</h2>';
        if (!$rows) return $html . '<p class="muted">Пока нет событий за этот период.</p></section>';
        $max = max(1,(int)$rows[0]['total']);
        foreach ($rows as $row) {
            $percent = round((int)$row['total'] * 100 / $max);
            $html .= '<div class="ta-row"><span class="ta-name" title="' . AdminView::e($row['name']) . '">' . AdminView::e($row['name']) . '</span><div class="ta-meter"><span style="width:' . $percent . '%"></span></div><span class="ta-num">' . (int)$row['total'] . '</span></div>';
        }
        return $html . '</section>';
    }
}
