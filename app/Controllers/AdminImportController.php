<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Security\AdminGuard;
use App\Services\ExcelImportService;

final class AdminImportController
{
    public function upload(Request $request): void
    {
        $admin = AdminGuard::requireRole(['owner', 'manager']);
        AdminGuard::requireCsrf();
        $branchId = filter_var($_POST['branch_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$branchId || $branchId < 1) {
            throw new HttpException(422, 'BRANCH_ID_INVALID', 'Выберите магазин для импорта.');
        }
        $file = $_FILES['file'] ?? [];
        $result = (new ExcelImportService())->importUploadedFile($branchId, is_array($file) ? $file : []);
        $this->audit((int)$admin['id'], (int)$result['import_id'], 'import.upload');
        Response::json(['success' => true, 'data' => $result], !empty($result['duplicate']) ? 200 : 201);
    }

    public function index(Request $request): void
    {
        AdminGuard::requireRole(['owner', 'manager']);
        $branchId = isset($request->query['branch_id']) ? filter_var($request->query['branch_id'], FILTER_VALIDATE_INT) : null;
        if ($branchId !== null && (!$branchId || $branchId < 1)) {
            throw new HttpException(422, 'BRANCH_ID_INVALID', 'Некорректный branch_id.');
        }
        $page = min(100000, max(1, (int)($request->query['page'] ?? 1)));
        $perPage = min(100, max(1, (int)($request->query['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;
        $pdo = Database::connection();
        if ($branchId !== null) {
            $count = $pdo->prepare('SELECT COUNT(*) FROM import_log WHERE branch_id=:branch_id');
            $count->execute(['branch_id' => $branchId]);
            $stmt = $pdo->prepare(
                'SELECT i.id,i.branch_id,b.name AS branch_name,i.original_filename,i.file_sha256,i.status,
                        i.rows_total,i.rows_created,i.rows_updated,i.rows_unchanged,i.rows_errors,
                        i.started_at,i.finished_at,i.created_at
                 FROM import_log i JOIN branches b ON b.id=i.branch_id
                 WHERE i.branch_id=:branch_id ORDER BY i.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
            );
            $stmt->execute(['branch_id' => $branchId]);
        } else {
            $count = $pdo->query('SELECT COUNT(*) FROM import_log');
            $stmt = $pdo->query(
                'SELECT i.id,i.branch_id,b.name AS branch_name,i.original_filename,i.file_sha256,i.status,
                        i.rows_total,i.rows_created,i.rows_updated,i.rows_unchanged,i.rows_errors,
                        i.started_at,i.finished_at,i.created_at
                 FROM import_log i JOIN branches b ON b.id=i.branch_id
                 ORDER BY i.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
            );
        }
        Response::json(['success' => true, 'data' => [
            'items' => $stmt->fetchAll(),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => (int)$count->fetchColumn()],
        ]]);
    }

    public function errors(Request $request): void
    {
        AdminGuard::requireRole(['owner', 'manager']);
        $importId = filter_var($request->query['import_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$importId || $importId < 1) {
            throw new HttpException(422, 'IMPORT_ID_INVALID', 'Укажите корректный import_id.');
        }
        $pdo = Database::connection();
        $check = $pdo->prepare('SELECT id FROM import_log WHERE id=:id LIMIT 1');
        $check->execute(['id' => $importId]);
        if (!$check->fetchColumn()) throw new HttpException(404, 'IMPORT_NOT_FOUND', 'Импорт не найден.');
        $stmt = $pdo->prepare(
            'SELECT row_number,error_code,message,row_data,created_at
             FROM import_errors WHERE import_id=:import_id ORDER BY row_number,id LIMIT 500'
        );
        $stmt->execute(['import_id' => $importId]);
        Response::json(['success' => true, 'data' => ['import_id' => $importId, 'errors' => $stmt->fetchAll()]]);
    }

    private function audit(int $adminId, int $importId, string $action): void
    {
        Database::connection()->prepare(
            'INSERT INTO admin_audit_log (admin_id,action,entity_type,entity_id,ip_address)
             VALUES (:admin_id,:action,:entity_type,:entity_id,:ip_address)'
        )->execute([
            'admin_id' => $adminId,
            'action' => $action,
            'entity_type' => 'import',
            'entity_id' => $importId,
            'ip_address' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ]);
    }
}