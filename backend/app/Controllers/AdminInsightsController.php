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
            'SELECT COUNT(*) FROM subscriptions WHERE created_at >= ?', [$since]
        );
        $activeSubscriptions = (int) $this->scalar(
            "SELECT COUNT(*) FROM subscriptions WHERE status='active'", []
        );
        $sent = (int) $this->scalar(
            "SELECT COUNT(*) FROM push_delivery_log l WHERE l.status='sent' AND l.sent_at>=?"
            . ($branchId ? ' AND l.branch_id=?' : ''),
            $branchId ? [$since, $branchId] : [$since]
        );
        $stock = $this->db->prepare(
            'SELECT COUNT(*) AS total, COALESCE(SUM(st.quantity<=0),0) AS empty_count'
            . ' FROM stock st JOIN products p ON p.id=st.product_id WHERE p.is_active=1'
            . ($branchId ? ' AND st.branch_id=?' : '')
        );
        $stock->execute($branchId ? [$branchId] : []);
        $stockStats = $stock->fetch(PDO::FETCH_ASSOC);

        // One active subscription represents interest in the product across all branches.
        $topStmt = $this->db->query(
            "SELECT p.id AS product_id, p.name, COUNT(DISTINCT s.user_id) AS interest
             FROM subscriptions s
             INNER JOIN products p ON p.id = s.product_id
             WHERE s.status = 'active' AND p.is_active = 1
             GROUP BY p.id, p.name
             ORDER BY interest DESC, p.name ASC
             LIMIT 10"
        );
        $top = $topStmt->fetchAll(PDO::FETCH_ASSOC);
        $topDetails = [];

        // A subscriber waits for the product until it appears in any active branch.
        $demandStmt = $this->db->query(
            "SELECT p.name, COUNT(DISTINCT s.user_id) AS people
             FROM subscriptions s
             INNER JOIN products p ON p.id = s.product_id
             WHERE s.status = 'active' AND p.is_active = 1
               AND NOT EXISTS (
                   SELECT 1 FROM stock st
                   INNER JOIN branches b ON b.id = st.branch_id AND b.is_active = 1
                   WHERE st.product_id = s.product_id AND st.quantity > 0
               )
             GROUP BY s.product_id, p.name
             ORDER BY people DESC, p.name ASC
             LIMIT 6"
        );
        $missingDemand = $demandStmt->fetchAll(PDO::FETCH_ASSOC);

        $searchStmt = $this->db->prepare(
            'SELECT query_text,COUNT(*) AS searches,'
            . ' SUM(results_count=0) AS no_results'
            . ' FROM search_events WHERE created_at>=?'
            . ($branchId ? ' AND branch_id=?' : '')
            . ' GROUP BY query_text ORDER BY searches DESC,query_text LIMIT 10'
        );
        $searchStmt->execute($branchId ? [$since, $branchId] : [$since]);
        $popularSearches = $searchStmt->fetchAll(PDO::FETCH_ASSOC);
        $searchTotalsStmt = $this->db->prepare(
            'SELECT COUNT(*) AS total,COALESCE(SUM(results_count=0),0) AS empty_searches'
            . ' FROM search_events WHERE created_at>=?'
            . ($branchId ? ' AND branch_id=?' : '')
        );
        $searchTotalsStmt->execute($branchId ? [$since, $branchId] : [$since]);
        $searchTotals = $searchTotalsStmt->fetch(PDO::FETCH_ASSOC);

        $dailyStmt = $this->db->prepare(
            'SELECT DATE(created_at) AS day, COUNT(*) AS amount FROM subscriptions'
            . ' WHERE created_at >= ? GROUP BY DATE(created_at) ORDER BY day'
        );
        $dailyStmt->execute([$since]);
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

        $style = '<style>.insight-hero{background:linear-gradient(115deg,#123d72,#176f83);color:#fff;border-radius:16px;padding:25px;margin-bottom:18px}.insight-hero h2{font-size:25px;margin:4px 0}.insight-hero p{margin:5px 0;color:#dceef5}.insight-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin:16px 0}.insight-kpi{border:1px solid #dce6f1;background:#fff;border-radius:13px;padding:18px}.insight-kpi strong{display:block;font-size:29px;color:#123d72}.insight-kpi span{font-size:13px;color:#64748b}.insight-chart{display:flex;align-items:end;gap:3px;height:175px;border-bottom:1px solid #d5dfec;padding-top:10px}.insight-bar{background:#2877b8;flex:1;min-width:2px;border-radius:4px 4px 0 0}.insight-bar:hover{background:#13a5a1}.insight-filters{display:flex;gap:12px;align-items:end;flex-wrap:wrap}.insight-filters label{min-width:160px;margin:0}.insight-filters select{margin:3px 0 0}.insight-progress{background:#e7eef5;border-radius:8px;height:9px;overflow:hidden}.insight-progress span{display:block;background:#2084b9;height:100%}.insight-note{font-size:13px;color:#687789}.insight-two{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px}@media(max-width:600px){.insight-two{grid-template-columns:1fr}}.insight-branches{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:15px;margin-top:15px}.insight-branch{border:1px solid #dce6f1;border-radius:14px;padding:19px;background:linear-gradient(180deg,#fff,#f7fbff)}.insight-branch h3{font-size:17px;margin:0 0 14px;color:#123d72}.insight-branch-counts{display:grid;grid-template-columns:1fr 1fr;gap:10px}.insight-branch-number{font-size:25px;font-weight:700;color:#173f70;display:block}.insight-branch-label{font-size:12px;color:#64748b}.insight-branch-wait{margin-top:14px;border-top:1px solid #e4ebf4;padding-top:12px}.insight-branch-wait strong{font-size:21px;color:#0f7280}.insight-branch-meter{display:flex;height:10px;border-radius:8px;background:#e9eef5;overflow:hidden;margin:12px 0 7px}.insight-branch-meter .available{background:#19a381}.insight-branch-meter .unavailable{background:#e6a048}.insight-branch-caption{font-size:12px;color:#64748b}.insight-rank{padding:10px 0 12px;border-bottom:1px solid #edf1f7}.insight-rank:last-of-type{border-bottom:0}.insight-rank-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:9px}.insight-rank-name{display:flex;align-items:center;gap:9px;min-width:0;overflow-wrap:anywhere}.insight-rank-index{display:inline-flex;align-items:center;justify-content:center;flex:none;width:25px;height:25px;background:#eaf3fc;color:#164d7c;border-radius:7px;font-size:12px}.insight-rank-head strong{white-space:nowrap;color:#123d72}.insight-rank-detail{font-size:12px;color:#687789;margin-top:7px;line-height:1.5}</style>';
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
            $rank = 0;
            foreach ($top as $r) {
                ++$rank;
                $productId = (int) $r['product_id'];
                $people = (int) $r['interest'];
                $percent = round(100 * $people / $max, 1);
                $content .= '<div class="insight-rank">'
                    . '<div class="insight-rank-head"><span class="insight-rank-name"><b class="insight-rank-index">' . $rank . '</b>'
                    . $e($r['name']) . '</span><strong>' . $people . ' чел.</strong></div>'
                    . '<div class="insight-progress"><span style="width:' . $percent . '%"></span></div>';
                if (!$branchId && isset($topDetails[$productId])) {
                    $labels = [];
                    foreach ($topDetails[$productId] as $detail) {
                        $labels[] = $e($detail['branch_name']) . ': ' . (int) $detail['people'];
                    }
                    $content .= '<div class="insight-rank-detail">' . implode(' <span aria-hidden="true">·</span> ', $labels) . '</div>';
                }
                $content .= '</div>';
            }
            $content .= '<p class="insight-note">Рейтинг по уникальным покупателям с активной подпиской на товар.'
                . ($branchId ? ' Показан выбранный филиал.' : ' Разбивка по филиалам может суммарно превышать общий показатель: один человек может ждать товар в нескольких магазинах.')
                . '</p>';
        }
        $content .= '</section></div><section class="card"><h2>Сигналы спроса: товары без остатка</h2>'
            . '<p class="insight-note">Товары, которые сейчас отсутствуют, но на их поступление подписаны покупатели. Это не прогноз продаж.</p>';
        if (!$missingDemand) {
            $content .= '<p class="muted">Сейчас нет активных подписок на отсутствующие товары.</p>';
        } else {
            $alertRows = [];
            foreach ($missingDemand as $r) {
                $alertRows[] = [$e($r['name']), (int) $r['people'] . ' чел.'];
            }
            $content .= AdminView::table(['Товар', 'Ожидают'], $alertRows);
        }
        $content .= '</section><section class="card"><h2>Что ищут покупатели</h2>'
            . '<p class="insight-note">Поисковые запросы приложения за выбранный период. '
            . 'Запросы без результатов помогают заметить интерес к отсутствующему в каталоге ассортименту.</p>'
            . '<div class="insight-kpis">'
            . $this->kpi('Всего поисков', (int) $searchTotals['total'], 'За выбранный период')
            . $this->kpi('Без результатов', (int) $searchTotals['empty_searches'], 'Запросы с нулевой выдачей')
            . '</div>';
        if (!$popularSearches) {
            $content .= '<p class="muted">Поисковых событий пока нет. Статистика появится после подключения отправки событий из Android-приложения.</p>';
        } else {
            $searchRows = [];
            foreach ($popularSearches as $r) {
                $searchRows[] = [$e($r['query_text']), (int) $r['searches'], (int) $r['no_results']];
            }
            $content .= AdminView::table(['Запрос', 'Поисков', 'Без результатов'], $searchRows);
        }
        $content .= '</section><section class="card"><h2>Сравнение филиалов</h2>'
            . '<p class="insight-note">Срез по текущим остаткам в каждом филиале. Подписки на товары учитываются отдельно и не привязаны к магазину.</p>'
            . '<div class="insight-branches">';
        $shownBranches = 0;
        foreach ($branchRows as $b) {
            if ($branchId && (int) $b['id'] !== $branchId) {
                continue;
            }
            ++$shownBranches;
            $available = (int) $b['available'];
            $unavailable = (int) $b['unavailable'];
            $waiting = (int) $b['waiting'];
            $total = $available + $unavailable;
            $availablePercent = $total > 0 ? round($available * 100 / $total, 1) : 0;
            $unavailablePercent = $total > 0 ? 100 - $availablePercent : 0;
            $content .= '<article class="insight-branch"><h3>' . $e($b['name']) . '</h3>'
                . '<div class="insight-branch-counts"><div><span class="insight-branch-number">' . number_format($available, 0, ',', ' ') . '</span>'
                . '<span class="insight-branch-label">Позиций в наличии</span></div>'
                . '<div><span class="insight-branch-number">' . number_format($unavailable, 0, ',', ' ') . '</span>'
                . '<span class="insight-branch-label">Позиций без остатка</span></div></div>'
                . '<div class="insight-branch-meter" role="img" aria-label="Доля позиций в наличии: ' . $availablePercent . '%">'
                . '<span class="available" style="width:' . $availablePercent . '%"></span>'
                . '<span class="unavailable" style="width:' . $unavailablePercent . '%"></span></div>'
                . '<div class="insight-branch-caption">' . ($total > 0
                    ? 'В наличии ' . $availablePercent . '% из ' . number_format($total, 0, ',', ' ') . ' учтённых позиций'
                    : 'Нет загруженных остатков') . '</div>'
</article>'
        }
        if ($shownBranches === 0) {
            $content .= '<p class="muted">Филиалы пока не добавлены.</p>';
        }
        $content .= '</div><p class="insight-note">Доли рассчитаны по позициям с загруженными остатками, а не по всему ассортименту. '
            . 'Подписки — сигнал интереса, а не подтверждённые продажи. '
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
