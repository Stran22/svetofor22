<?php
declare(strict_types=1);
namespace App\Core;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly array $body
    ) {}

    public static function fromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        if ($base !== '' && $base !== '/' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }

        $headers = function_exists('getallheaders') ? getallheaders() : [];
        if (!is_array($headers)) $headers = [];
        $serverHeaderMap = [
            'HTTP_AUTHORIZATION' => 'Authorization',
            'REDIRECT_HTTP_AUTHORIZATION' => 'Authorization',
            'HTTP_X_CSRF_TOKEN' => 'X-CSRF-Token',
            'CONTENT_TYPE' => 'Content-Type',
            'CONTENT_LENGTH' => 'Content-Length',
        ];
        foreach ($serverHeaderMap as $serverKey => $headerName) {
            if (isset($_SERVER[$serverKey]) && !self::hasHeader($headers, $headerName)) {
                $headers[$headerName] = (string)$_SERVER[$serverKey];
            }
        }

        $raw = file_get_contents('php://input');
        $raw = is_string($raw) ? $raw : '';
        $contentType = '';
        foreach ($headers as $name => $value) {
            if (strtolower((string)$name) === 'content-type') {
                $contentType = strtolower(trim(explode(';', (string)$value, 2)[0]));
                break;
            }
        }

        if ($contentType === 'application/json' || str_ends_with($contentType, '+json')) {
            if ($raw === '') {
                $body = [];
            } else {
                $decoded = json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                    throw new HttpException(400, 'JSON_INVALID', 'Тело запроса содержит некорректный JSON.');
                }
                $body = $decoded;
            }
        } elseif ($contentType === 'application/x-www-form-urlencoded') {
            $body = $_POST;
            if ($body === [] && $raw !== '') parse_str($raw, $body);
        } else {
            // PHP fills $_POST and $_FILES for multipart/form-data. The uploaded
            // file itself remains in $_FILES and is deliberately not copied here.
            $body = is_array($_POST ?? null) ? $_POST : [];
        }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            '/' . trim($path, '/'),
            is_array($_GET ?? null) ? $_GET : [],
            $headers,
            $body
        );
    }

    private static function hasHeader(array $headers, string $needle): bool
    {
        foreach ($headers as $name => $_value) {
            if (strtolower((string)$name) === strtolower($needle)) return true;
        }
        return false;
    }
}
