<?php
declare(strict_types=1);
namespace App\Core;

final class Response
{
    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public static function notFound(): void
    {
        self::json(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Маршрут не найден.']], 404);
    }
}
