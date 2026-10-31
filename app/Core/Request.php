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
        if ($base !== '' && $base !== '/' && str_starts_with($path, $base)) $path = substr($path, strlen($base)) ?: '/';
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $raw = file_get_contents('php://input') ?: '';
        $json = $raw !== '' ? json_decode($raw, true) : [];
        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), '/' . trim($path, '/'), $_GET,
            is_array($headers) ? $headers : [], is_array($json) ? $json : []);
    }
}
