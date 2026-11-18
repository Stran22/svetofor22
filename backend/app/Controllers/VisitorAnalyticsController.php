<?php
declare(strict_types=1);

namespace Svet22\Controllers;

use PDO;
use Svet22\Core\Request;
use Svet22\Core\Response;
use Svet22\Services\TokenService;

final class VisitorAnalyticsController
{
    public function __construct(private PDO $db) {}

    public function record(Request $request): never
    {
        $userId = (new TokenService($this->db))->userId($request->bearerToken());
        if ($userId === null) {
            Response::error('UNAUTHENTICATED', 'Требуется токен приложения.', 401);
        }
        $data = $request->json();
        $type = $data['event_type'] ?? null;
        if (!in_array($type, ['visit', 'product_view', 'category_click'], true)) {
            Response::error('VALIDATION_ERROR', 'Неизвестный тип события.', 422);
        }
        $productId = null;
        $categoryId = null;
        if ($type === 'product_view') {
            $productId = filter_var($data['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$productId) Response::error('VALIDATION_ERROR', 'Не указан товар.', 422);
            $stmt = $this->db->prepare('SELECT id FROM products WHERE id=? AND is_active=1');
            $stmt->execute([$productId]);
            if (!$stmt->fetchColumn()) Response::error('NOT_FOUND', 'Товар не найден.', 404);
        }
        if ($type === 'category_click') {
            $categoryId = filter_var($data['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$categoryId) Response::error('VALIDATION_ERROR', 'Не указана категория.', 422);
            $stmt = $this->db->prepare('SELECT id FROM categories WHERE id=? AND is_active=1');
            $stmt->execute([$categoryId]);
            if (!$stmt->fetchColumn()) Response::error('NOT_FOUND', 'Категория не найдена.', 404);
        }
        $branchId = null;
        if (isset($data['branch_id'])) {
            $branchId = filter_var($data['branch_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$branchId) Response::error('VALIDATION_ERROR', 'Некорректный филиал.', 422);
            $stmt = $this->db->prepare('SELECT id FROM branches WHERE id=? AND is_active=1');
            $stmt->execute([$branchId]);
            if (!$stmt->fetchColumn()) Response::error('NOT_FOUND', 'Филиал не найден.', 404);
        }
        // At most one visit per user per calendar day. Repeated views remain countable.
        if ($type === 'visit') {
            $stmt = $this->db->prepare("SELECT id FROM app_analytics_events WHERE user_id=? AND event_type='visit' AND created_at>=CURRENT_DATE() LIMIT 1");
            $stmt->execute([$userId]);
            if ($stmt->fetchColumn()) Response::success(['recorded' => false]);
        }
        $stmt = $this->db->prepare('INSERT INTO app_analytics_events (user_id,event_type,product_id,category_id,branch_id) VALUES (?,?,?,?,?)');
        $stmt->execute([$userId, $type, $productId, $categoryId, $branchId]);
        Response::success(['recorded' => true]);
    }
}
