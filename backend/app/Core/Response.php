<?php
declare(strict_types=1);

namespace Svet22\Core;

final class Response
{
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function html(string $html, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'; base-uri 'self'; frame-ancestors 'none'; object-src 'none'");
        if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? null) === '443')) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        echo $html;
        exit;
    }

    public static function redirect(string $location, int $status = 303): never
    {
        header('Location: ' . $location, true, $status);
        exit;
    }

    public static function success(mixed $data, array $meta = [], int $status = 200): never
    {
        self::json(['success' => true, 'data' => $data, 'meta' => $meta], $status);
    }

    public static function error(string $code, string $message, int $status = 400, array $details = []): never
    {
        self::json([
            'success' => false,
            'error' => ['code' => $code, 'message' => $message, 'details' => $details],
        ], $status);
    }
}
