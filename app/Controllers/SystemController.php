<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Database;
use App\Core\Response;
use Throwable;

final class SystemController
{
    public function health(): void
    {
        try {
            Database::connection()->query('SELECT 1');
            Response::json(['success' => true, 'data' => [
                'service' => 'Svetofor22 API', 'status' => 'ok',
                'database' => 'ok', 'timestamp' => date(DATE_ATOM),
            ]]);
        } catch (Throwable) {
            Response::json(['success' => false, 'data' => [
                'service' => 'Svetofor22 API', 'status' => 'degraded',
                'database' => 'unavailable', 'timestamp' => date(DATE_ATOM),
            ]], 503);
        }
    }
}
