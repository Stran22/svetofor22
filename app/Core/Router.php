<?php
declare(strict_types=1);
namespace App\Core;

final class Router
{
    private array $routes = [];
    public function get(string $path, callable $handler): void { $this->routes['GET'][$this->norm($path)] = $handler; }
    public function post(string $path, callable $handler): void { $this->routes['POST'][$this->norm($path)] = $handler; }
    public function dispatch(Request $request): void
    {
        $handler = $this->routes[$request->method][$this->norm($request->path)] ?? null;
        if (!$handler) { Response::notFound(); return; }
        $handler($request);
    }
    private function norm(string $path): string { return $path === '/' ? '/' : '/' . trim($path, '/'); }
}
