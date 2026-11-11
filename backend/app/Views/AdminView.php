<?php
declare(strict_types=1);

namespace Svet22\Views;

final class AdminView
{
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function layout(string $title, string $content, array $admin): string
    {
        $username = self::e($admin['username'] ?? '');
        $role = ($admin['role'] ?? '') === 'admin' ? 'Администратор' : 'Менеджер';
        return '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . self::e($title) . ' — Svet22</title><style>' . self::css() . '</style></head><body>'
            . '<header class="top"><div class="brand">Svet22 <span>Панель управления</span></div><div class="user">'
            . $username . ' · ' . $role
            . '<form method="post" action="/admin/logout" class="inline"><input type="hidden" name="csrf_token" value="' . self::e($admin['csrf'] ?? '') . '"><button class="link" type="submit">Выйти</button></form>'
            . '</div></header><div class="shell"><aside class="nav">'
            . '<a href="/admin">Обзор</a><a href="/admin/import">Импорт Excel</a><a href="/admin/imports">История загрузок</a>'
            . '<a href="/admin/products">Товары</a><a href="/admin/categories">Категории</a><a href="/admin/branches">Филиалы</a>'
            . (($admin['role'] ?? '') === 'admin' ? '<a href="/admin/reviews">Отзывы</a>' : '')
            . (($admin['role'] ?? '') === 'admin' ? '<a href="/admin/users">Пользователи</a>' : '')
            . '</aside><main><h1>' . self::e($title) . '</h1>' . $content . '</main></div></body></html>';
    }

    public static function login(string $csrf, string $error = ''): string
    {
        return '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Вход — Svet22</title><style>' . self::css() . '</style></head><body class="login-page"><main class="login-card">'
            . '<div class="brand dark">Svet22 <span>Панель управления</span></div><h1>Вход в систему</h1>'
            . ($error !== '' ? '<div class="alert error">' . self::e($error) . '</div>' : '')
            . '<form method="post" action="/admin/login"><input type="hidden" name="csrf_token" value="' . self::e($csrf) . '">'
            . '<label>Логин<input name="username" maxlength="100" autocomplete="username" required autofocus></label>'
            . '<label>Пароль<input type="password" name="password" maxlength="4096" autocomplete="current-password" required></label>'
            . '<button type="submit">Войти</button></form><p class="muted">Доступ только для уполномоченных сотрудников.</p></main></body></html>';
    }

    public static function alert(string $message, bool $error = false): string
    {
        return '<div class="alert ' . ($error ? 'error' : 'success') . '">' . self::e($message) . '</div>';
    }

    public static function table(array $headers, array $rows, string $empty = 'Нет данных.'): string
    {
        if (!$rows) {
            return '<p class="muted">' . self::e($empty) . '</p>';
        }
        $html = '<div class="table-wrap"><table><thead><tr>';
        foreach ($headers as $header) {
            $html .= '<th>' . self::e($header) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . $cell . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div>';
    }

    private static function css(): string
    {
        return ' *{box-sizing:border-box}body{margin:0;background:#f3f6fa;color:#182333;font:15px/1.5 Arial,sans-serif}.top{height:64px;background:#123d72;color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 28px}.brand{font-size:21px;font-weight:700}.brand span{font-size:13px;font-weight:400;opacity:.8;margin-left:10px}.user{display:flex;gap:16px;align-items:center}.shell{display:grid;grid-template-columns:220px minmax(0,1fr);min-height:calc(100vh - 64px)}.nav{background:#fff;padding:22px 14px;border-right:1px solid #e2e8f0}.nav a{display:block;padding:10px 12px;margin-bottom:5px;border-radius:8px;color:#23415f;text-decoration:none}.nav a:hover{background:#edf3fa}main{padding:28px;max-width:1400px;width:100%}h1{font-size:26px;margin:0 0 22px}h2{font-size:19px;margin:22px 0 12px}.card{background:#fff;border:1px solid #e1e8f0;border-radius:12px;padding:20px;margin-bottom:18px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px}.metric{font-size:28px;font-weight:700;color:#123d72}.muted{color:#6c7a89}.alert{padding:12px 15px;border-radius:8px;margin:0 0 16px}.success{background:#e8f6ee;color:#17633a}.error{background:#fff0ef;color:#9b2821}label{display:block;font-weight:600;margin:0 0 14px}input,select{display:block;width:100%;max-width:560px;padding:11px 12px;margin-top:6px;border:1px solid #cbd5e1;border-radius:7px;background:#fff;font:inherit}button,.button{display:inline-block;background:#123d72;color:#fff;border:0;border-radius:7px;padding:11px 17px;font:inherit;text-decoration:none;cursor:pointer}button:hover,.button:hover{background:#082c59}.link{background:none;padding:0;color:#fff;text-decoration:underline}.inline{display:inline}.table-wrap{overflow-x:auto;background:#fff;border:1px solid #e1e8f0;border-radius:10px}table{border-collapse:collapse;width:100%;min-width:600px}th,td{text-align:left;padding:11px 13px;border-bottom:1px solid #edf0f4;vertical-align:top}th{background:#f7f9fc;font-size:13px;color:#526274}td a{color:#14579a}.login-page{min-height:100vh;display:grid;place-items:center;padding:20px}.login-card{width:min(420px,100%);background:#fff;border:1px solid #e1e8f0;border-radius:14px;padding:30px;box-shadow:0 12px 36px #122b4514}.login-card h1{font-size:23px;margin:26px 0 20px}.brand.dark{color:#123d72}.login-card input{max-width:none}.login-card button{width:100%;margin-top:5px}.login-card .muted{font-size:13px;margin:18px 0 0}.pagination{display:flex;gap:10px;margin:16px 0}.pill{display:inline-block;border-radius:20px;background:#edf3fa;padding:3px 9px;font-size:12px}@media(max-width:760px){.top{padding:0 14px}.brand span{display:none}.shell{display:block}.nav{display:flex;overflow-x:auto;padding:8px;border-right:0;border-bottom:1px solid #e2e8f0}.nav a{white-space:nowrap;margin:0 3px}.shell main{padding:18px}.user{font-size:12px;gap:8px}}';
    }
}