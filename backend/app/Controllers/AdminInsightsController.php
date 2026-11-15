<?php
declare(strict_types=1);

namespace Svet22\Controllers;

use PDO;
use Svet22\Core\Request;
use Svet22\Core\Response;
use Svet22\Services\AdminAuthService;
use Svet22\Views\AdminView;

final class AdminInsightsController
{
    public function __construct(private PDO $db) {}

    public function index(Request $request): never
    {
        $auth = new AdminAuthService($this->db);
        $admin = $auth->requireUser();
        $admin['csrf'] = $auth->csrfToken();
        $query = $request->query();
        $days = (int) ($query['days'] ?? 30);
        if (!in_array($days, [7, 30, 90], true)) {
            $days = 30;
        }
        $branchId = filter_var($query['branch_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $branches = $this->db->query('SELECT id, name FROM branches ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        if ($branchId && !in_array($branchId, array_map(static fn($b) => (int) $b['id'], $branches), true)) {
            $branchId = 0;
        }
        $since = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
        $e = [AdminView::class, 'e'];

        $newUsers = (int) $this->scalar('SELECT COUNT(*) FROM users WHERE created_at >= ?', [$since]);
        $newSubscriptions = (int) $this->scalar(
            'SELECT COUNT(*) FROM subscriptions WHERE created_at >= ?' . ($branchId ? ' AND branch_id=?' : ''),
            $branchId ? [$since, $branchId] : [$since]
        );
        $activeSubscriptions = (int) $this->scalar(
            "SELECT COUNT(*) FROM subscriptions WHERE status='active'" . ($branchId ? ' AND branch_id=?' : ''),
            $branchId ? [$branchId] : []
        );
        $sent = (int) $this->scalar(
            "SELECT COUNT(*) FROM push_delivery_log l JOIN subscriptions s ON s.id=l.subscription_id WHERE l.status='sent' AND l.sent_at>=?"
            . ($branchId ? ' AND s.branch_id=?' : ''),
            $branchId ? [$since, $branchId] : [$since]
        );
        $stock = $this->db->prepare(
            'SELECT COUNT(*) AS total, COALESCE(SUM(st.quantity<=0),0) AS empty_count'
            . ' FROM stock st JOIN products p ON p.id=st.product_id WHERE p.is_active=1'
            . ($branchId ? ' AND st.branch_id=?' : '')
        );
        $stock->execute($branchId ? [$branchId] : []);
        $stockStats = $stock->fetch(PDO::FETCH_ASSOC);

        $topStmt = $this->db->prepare(
            'SELECT p.name, b.name AS branch_name, COUNT(*) AS interest,'
            . ' COALESCE(st.quantity,0) AS quantity'
            . ' FROM subscriptions s JOIN products p ON p.id=s.product_id'
            . ' JOIN branches b ON b.id=s.branch_id'
            . ' LEFT JOIN stock st ON st.product_id=s.product_id AND st.branch_id=s.branch_id'
            . " WHERE s.status='active'" . ($branchId ? ' AND s.branch_id=?' : '')
            . ' GROUP BY s.product_id,s.branch_id,p.name,b.name,st.quantity'
            . ' ORDER BY interest DESC,p.name ASC LIMIT 8'
        );
        $topStmt->execute($branchId ? [$branchId] : []);
        $top = $topStmt->fetchAll(PDO::FETCH_ASSOC);

        // Спрос на отсутствующие товары: только действующие подписки и фактический нулевой остаток.
        $demandStmt = $this->db->prepare(
            'SELECT p.name,b.name AS branch_name,COUNT(DISTINCT s.user_id) AS people'
            . ' FROM subscriptions s JOIN products p ON p.id=s.product_id'
            . ' JOIN branches b ON b.id=s.branch_id'
            . ' LEFT JOIN stock st ON st.product_id=s.product_id AND st.branch_id=s.branch_id'
            . " WHERE s.status='active' AND p.is_active=1 AND COALESCE(st.quantity,0)<=0"
            . ($branchId ? ' AND s.branch_id=?' : '')
            . ' GROUP BY s.product_id,s.branch_id,p.name,b.name ORDER BY people DESC,p.name LIMIT 6'
        );
        $demandStmt->execute($branchId ? [$branchId] : []);
        $missingDemand = $demandStmt->fetchAll(PDO::FETCH_ASSOC);

        $dailyStmt = $this->db->prepare(
            'SELECT DATE(created_at) AS day, COUNT(*) AS amount FROM subscriptions'
            . ' WHERE created_at >= ?' . ($branchId ? ' AND branch_id=?' : '')
            . ' GROUP BY DATE(created_at) ORDER BY day'
        );
        $dailyStmt->execute($branchId ? [$since, $branchId] : [$since]);
        $byDay = [];
        foreach ($dailyStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byDay[$r['day']] = (int) $r['amount'];
        }
        $daily = [];
        $maxDaily = 1;
        for ($i = $days - 1; $i >= 0; --$i) {
            $day = date('Y-m-d', strtotime('-' . $i . ' days'));
            $n = $byDay[$day] ?? 0;
            $daily[] = ['day' => $day, 'value' => $n];
            $maxDaily = max($maxDaily, $n);
        }

        $branchStmt = $this->db->query(
            "SELECT b.id,b.name,"
            . " (SELECT COUNT(*) FROM subscriptions s WHERE s.branch_id=b.id AND s.status='active') AS waiting,"
            . ' (SELECT COUNT(*) FROM stock st JOIN products p ON p.id=st.product_id WHERE st.branch_id=b.id AND p.is_active=1 AND st.quantity>0) AS available,'
            . ' (SELECT COUNT(*) FROM stock st JOIN products p ON p.id=st.product_id WHERE st.branch_id=b.id AND p.is_active=1 AND st.quantity<=0) AS unavailable'
            . ' FROM branches b ORDER BY b.name'
        );
        $branchRows = $branchStmt->fetchAll(PDO::FETCH_ASSOC);

        $style = '<style>.insight-hero{background:linear-gradient(115deg,#123d72,#176f83);color:#fff;border-radius:16px;padding:25px;margin-bottom:18px}.insight-hero h2{font-size:25px;margin:4px 0}.insight-hero p{margin:5px 0;color:#dceef5}.insight-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin:16px 0}.insight-kpi{border:1px solid #dce6f1;background:#fff;border-radius:13px;padding:18px}.insight-kpi strong{display:block;font-size:29px;color:#123d72}.insight-kpi span{font-size:13px;color:#64748b}.insight-chart{display:flex;align-items:end;gap:3px;height:175px;border-bottom:1px solid #d5dfec;padding-top:10px}.insight-bar{background:#2877b8;flex:1;min-width:2px;border-radius:4px 4px 0 0}.insight-bar:hover{background:#13a5a1}.insight-filters{display:flex;gap:12px;align-items:end;flex-wrap:wrap}.insight-filters label{min-width:160px;margin:0}.insight-filters select{margin:3px 0 0}.insight-progress{background:#e7eef5;border-radius:8px;height:9px;overflow:hidden}.insight-progress span{display:block;background:#2084b9;height:100%}.insight-note{font-size:13px;color:#687789}.insight-two{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px}@media(max-width:600px){.insight-two{grid-template-columns:1fr}}</style>';
        $content = $style
            . '<div class="insight-hero"><div style="font-size:12px;letter-spacing:1px">СВЕТОФОР · АНАЛИТИКА</div>'
            . '<h2>Панель руководителя</h2><p>Интерес покупателей, подписки и доступность товаров — по реальным данным системы.</p></div>'
            . '<section class="card"><form class="insight-filters" method="get" action="/admin">'
            . '<label>Период<select name="days">';
        foreach ([7, 30, 90] as $n) {
            $content .= '<option value="' . $n . '"' . ($days === $n ? ' selected' : '') . '>Последние ' . $n . ' дней</option>';
        }
        $content .= '</select></label><label>Филиал<select name="branch_id"><option value="0">Все филиалы</option>';
        foreach ($branches as $b) {
            $content .= '<option value="' . (int) $b['id'] . '"' . ($branchId === (int) $b['id'] ? ' selected' : '') . '>' . $e($b['name']) . '</option>';
        }
        $content .= '</select></label><button type="submit">Применить</button></form></section>'
            . '<div class="insight-kpis">'
            . $this->kpi('Новые пользователи', $newUsers, 'За выбранный период · все филиалы')
            . $this->kpi('Новые подписки', $newSubscriptions, 'За выбранный период')
            . $this->kpi('Ожидают поступления', $activeSubscriptions, 'Активные подписки сейчас')
            . $this->kpi('Принято Firebase', $sent, 'Уведомления за период')
            . $this->kpi('Нет в наличии', (int) $stockStats['empty_count'], 'Позиций с нулевым остатком')
            . '</div>'
            . '<div class="insight-two"><section class="card"><h2>Новые подписки по дням</h2>'
            . '<div class="insight-chart" role="img" aria-label="График новых подписок по дням">';
        foreach ($daily as $d) {
            $height = $d['value'] ? max(3, round($d['value'] * 100 / $maxDaily)) : 0;
            $content .= '<div class="insight-bar" title="' . $e($d['day'] . ': ' . $d['value']) . '" style="height:' . $height . '%"></div>';
        }
        $content .= '</div><p class="insight-note">Слева — ' . $e($daily[0]['day']) . ', справа — ' . $e($daily[count($daily)-1]['day'])
            . '. Наведите курсор на столбец, чтобы увидеть число подписок.</p></section>'
            . '<section class="card"><h2>Что ждут покупатели</h2>';
        if (!$top) {
            $content .= '<p class="muted">Пока нет активных подписок на товары.</p>';
        } else {
            $max = max(1, (int) $top[0]['interest']);
            foreach ($top as $r) {
                $percent = round(100 * (int) $r['interest'] / $max);
                $content .= '<div style="margin-bottom:15px"><div style="display:flex;justify-content:space-between;gap:12px"><span>'
                    . $e($r['name']) . '<small class="muted"> · ' . $e($r['branch_name']) . '</small></span>'
                    . '<strong>' . (int) $r['interest'] . '</strong></div>'
                    . '<div class="insight-progress"><span style="width:' . $percent . '%"></span></div>'
                    . '<small class="insight-note">Остаток: ' . $e($r['quantity']) . '</small></div>';
            }
        }
        $content .= '</section></div><section class="card"><h2>Сигналы спроса: товары без остатка</h2>'
            . '<p class="insight-note">Товары, которые сейчас отсутствуют, но на их поступление подписаны покупатели. Это не прогноз продаж.</p>';
        if (!$missingDemand) {
            $content .= '<p class="muted">Сейчас нет активных подписок на отсутствующие товары.</p>';
        } else {
            $alertRows = [];
            foreach ($missingDemand as $r) {
                $alertRows[] = [$e($r['name']), $e($r['branch_name']), (int) $r['people'] . ' чел.'];
            }
            $content .= AdminView::table(['Товар', 'Филиал', 'Ожидают'], $alertRows);
        }
        $content .= '</section><section class="card"><h2>Сравнение филиалов</h2>';
        $rows = [];
        foreach ($branchRows as $b) {
            if ($branchId && (int) $b['id'] !== $branchId) {
                continue;
            }
            $rows[] = [$e($b['name']), (int) $b['available'], (int) $b['unavailable'], (int) $b['waiting']];
        }
        $content .= AdminView::table(['Филиал', 'Позиций в наличии', 'Позиций без остатка', 'Активных подписок'], $rows)
            . '<p class="insight-note">Подписки — сигнал интереса, а не подтверждённые продажи. '
            . '«Принято Firebase» не означает, что уведомление прочитано. '
            . 'Просмотры, поисковые запросы и покупки пока не отслеживаются.</p></section>'
            . '<section class="card"><h2>Рабочие инструменты</h2><p>'
            . '<a class="button" href="/admin/import">Импорт Excel</a> '
            . '<a class="button" href="/admin/subscribers">Подписчики</a> '
            . '<a class="button" href="/admin/push">Push-уведомления</a></p></section>';
        Response::html(AdminView::layout('Панель руководителя', $content, $admin));
    }

    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    private function kpi(string $label, int $value, string $hint): string
    {
        return '<div class="insight-kpi"><span>' . AdminView::e($label) . '</span><strong>'
            . number_format($value, 0, ',', ' ') . '</strong><span>' . AdminView::e($hint) . '</span></div>';
    }
}
