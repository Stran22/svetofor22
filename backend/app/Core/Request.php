<?php
declare(strict_types=1);

namespace Svet22\Core;

final class Request
{
    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = is_string($uri) ? $uri : '/';
        $path = preg_replace('#/+#', '/', $path) ?: '/';
        return rtrim($path, '/') ?: '/';
    }

    public function query(): array
    {
        return $_GET;
    }

    public function post(): array
    {
        return $_POST;
    }

    public function file(string $name): ?array
    {
        $file = $_FILES[$name] ?? null;
        return is_array($file) ? $file : null;
    }

    public function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return is_string($ip) ? $ip : '';
    }

    public function json(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('Authorization');
        if ($header && preg_match('/^Bearer\s+([A-Za-z0-9._-]+)$/i', $header, $m)) {
            return $m[1];
        }
        return null;
    }
}
