<?php
declare(strict_types=1);

namespace Svet22\Core;

final class Router
{
    private array $routes = [];

    public function get(string $path, callable $handler): void { $this->add('GET', $path, $handler); }
    public function post(string $path, callable $handler): void { $this->add('POST', $path, $handler); }
    public function delete(string $path, callable $handler): void { $this->add('DELETE', $path, $handler); }

    private function add(string $method, string $path, callable $handler): void
    {
        $names = [];
        $pattern = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', static function (array $m) use (&$names): string {
            $names[] = $m[1];
            return '([0-9]+)';
        }, $path);
        $this->routes[] = [$method, '#^' . $pattern . '$#', $names, $handler];
    }

    public function dispatch(Request $request): void
    {
        foreach ($this->routes as [$method, $pattern, $names, $handler]) {
            if ($method !== $request->method() || !preg_match($pattern, $request->path(), $matches)) {
                continue;
            }
            array_shift($matches);
            $params = [];
            foreach ($names as $index => $name) {
                $params[$name] = (int) $matches[$index];
            }
            $handler($request, $params);
            return;
        }
        Response::error('NOT_FOUND', 'Маршрут не найден.', 404);
    }
}
