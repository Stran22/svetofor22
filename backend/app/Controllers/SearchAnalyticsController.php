<?php
declare(strict_types=1);

namespace Svet22\Controllers;

use PDO;
use Svet22\Core\Request;
use Svet22\Core\Response;
use Svet22\Services\TokenService;

final class SearchAnalyticsController
{
    public function __construct(private PDO $db) {}

    public function record(Request $request): never
    {
        $userId = (new TokenService($this->db))->userId($request->bearerToken());
        if ($userId === null) {
            Response::error('UNAUTHENTICATED', 'Требуется Bearer-токен.', 401);
        }
        $body = $request->json();
        $query = $body['query'] ?? null;
        if (!is_string($query)) {
            Response::error('VALIDATION_ERROR', 'Укажите поисковый запрос.', 422);
        }
        $query = preg_replace('/\s+/u', ' ', trim($query));
        if (!is_string($query) || mb_strlen($query) < 2 || mb_strlen($query) > 100) {
            Response::error('VALIDATION_ERROR', 'Длина запроса: от 2 до 100 символов.', 422);
        }
        $branchId = null;
        if (array_key_exists('branch_id', $body) && $body['branch_id'] !== null) {
            $branchId = filter_var($body['branch_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($branchId === false) {
                Response::error('VALIDATION_ERROR', 'Некорректный филиал.', 422);
            }
            $branch = $this->db->prepare('SELECT id FROM branches WHERE id=? AND is_active=1');
            $branch->execute([$branchId]);
            if ($branch->fetchColumn() === false) {
                Response::error('NOT_FOUND', 'Филиал не найден.', 404);
            }
        }
        // Не сохраняем запросы слишком часто, например при вводе каждого символа.
        $recent = $this->db->prepare(
            'SELECT query_text,branch_id FROM search_events WHERE user_id=? AND created_at>=DATE_SUB(NOW(), INTERVAL 10 SECOND) ORDER BY id DESC LIMIT 1'
        );
        $recent->execute([$userId]);
        $last = $recent->fetch(PDO::FETCH_ASSOC);
        if ($last !== false) {
            Response::success(['recorded' => false, 'reason' => 'rate_limited']);
        }
        // Результаты вычисляются сервером, клиент не может подделать их количество.
        $sql = 'SELECT COUNT(DISTINCT p.id) FROM products p'
            . ($branchId !== null ? ' LEFT JOIN stock s ON s.product_id=p.id' : '')
            . ' WHERE p.is_active=1 AND p.name LIKE ?'
            . ($branchId !== null ? ' AND s.branch_id=?' : '');
        $count = $this->db->prepare($sql);
        $count->execute($branchId !== null ? ['%' . $query . '%', $branchId] : ['%' . $query . '%']);
        $results = (int) $count->fetchColumn();
        $insert = $this->db->prepare(
            'INSERT INTO search_events (user_id,branch_id,query_text,results_count) VALUES (?,?,?,?)'
        );
        $insert->execute([$userId, $branchId, $query, $results]);
        Response::success(['recorded' => true, 'results_count' => $results]);
    }
}
