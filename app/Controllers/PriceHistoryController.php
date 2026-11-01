<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

final class PriceHistoryController
{
    public function index(Request $request): void
    {
        $productId = filter_var($request->query['product_id'] ?? null, FILTER_VALIDATE_INT);
        $branchId = filter_var($request->query['branch_id'] ?? null, FILTER_VALIDATE_INT);
        $limit = filter_var($request->query['limit'] ?? 30, FILTER_VALIDATE_INT);
        if (!$productId || !$branchId || !$limit || $limit < 1) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Укажите корректные product_id, branch_id и limit.');
        }
        $limit = min($limit, 100);
        $pdo = Database::connection();
        $check = $pdo->prepare(
            'SELECT p.id FROM products p
             JOIN stock s ON s.product_id=p.id
             JOIN branches b ON b.id=s.branch_id
             WHERE p.id=:product_id AND p.is_active=1
               AND s.branch_id=:branch_id AND b.is_active=1
             LIMIT 1'
        );
        $check->execute(['product_id' => $productId, 'branch_id' => $branchId]);
        if (!$check->fetchColumn()) {
            throw new HttpException(404, 'PRICE_HISTORY_NOT_FOUND', 'История цен для этого товара и магазина не найдена.');
        }
        $stmt = $pdo->prepare(
            'SELECT price,recorded_at,source FROM price_history
             WHERE product_id=:product_id AND branch_id=:branch_id
             ORDER BY recorded_at DESC,id DESC LIMIT ' . $limit
        );
        $stmt->execute(['product_id' => $productId, 'branch_id' => $branchId]);
        Response::json(['success' => true, 'data' => [
            'product_id' => $productId,
            'branch_id' => $branchId,
            'history' => $stmt->fetchAll(),
        ]]);
    }
}