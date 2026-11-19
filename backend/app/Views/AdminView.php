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
            . '</div></header><div class="shell"><aside class="nav" aria-label="Разделы управления"><div class="nav-caption">НАВИГАЦИЯ</div>'
            . '<div class="nav-group">Главное</div><a href="/admin" data-nav="overview">Обзор</a><a href="/admin/analytics" data-nav="analytics">Аналитика</a>'
            . '<div class="nav-group">Каталог и остатки</div><a href="/admin/import">Импорт Excel</a><a href="/admin/imports">История загрузок</a>'
            . '<a href="/admin/products">Товары</a><a href="/admin/categories">Категории</a>'
            . (($admin['role'] ?? '') === 'admin' ? '<a href="/admin/categories/visibility">Видимость цен и остатков</a>' : '')
            . '<div class="nav-group">Маркетинг</div><a href="/admin/featured">Супер выгода</a><a href="/admin/contests">Конкурсы</a>'
            . (($admin['role'] ?? '') === 'admin' ? '<a href="/admin/sales-banner">Баннер SALES</a>' : '')
            . '<a href="/admin/branches">Филиалы</a>'
            . '<div class="nav-group">Клиенты</div><a href="/admin/subscribers">Подписчики</a><a href="/admin/push">Push-уведомления</a>'
            . (($admin['role'] ?? '') === 'admin' ? '<a href="/admin/reviews">Отзывы</a>' : '')
            . (($admin['role'] ?? '') === 'admin' ? '<div class="nav-group">Управление</div><a href="/admin/users">Пользователи</a>' : '')
            . '</aside><main id="main-content"><div class="page-heading"><span class="page-eyebrow">Панель управления / ' . self::e($title) . '</span>'
            . ($title === 'Панель руководителя' ? '' : '<h1>' . self::e($title) . '</h1>') . '</div>' . $content . '</main></div>'
            . '<script>(function(){var path=location.pathname;document.querySelectorAll(".nav a").forEach(function(a){var href=a.getAttribute("href");if(path===href||(href!=="/admin"&&path.indexOf(href+"/")===0)){a.classList.add("active");a.setAttribute("aria-current","page")}})})();</script></body></html>';
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
        return '*{box-sizing:border-box}body{margin:0;background:#f3f6fa;color:#182b40;font:15px/1.55 system-ui,-apple-system,"Segoe UI",Arial,sans-serif}a{color:#14579a}.top{min-height:72px;background:#123d72;color:#fff;display:flex;align-items:center;justify-content:space-between;padding:12px 30px;gap:16px;box-shadow:0 2px 16px #123d7218}.brand{font-size:21px;font-weight:800;letter-spacing:-.5px}.brand span{font-size:13px;font-weight:400;opacity:.85;margin-left:12px;letter-spacing:0}.user{display:flex;gap:16px;align-items:center;font-size:13px}.shell{display:grid;grid-template-columns:254px minmax(0,1fr);min-height:calc(100vh - 72px)}.nav{background:#fff;padding:23px 14px 30px;border-right:1px solid #e1e8f0}.nav-caption{font-size:11px;font-weight:800;letter-spacing:1.6px;color:#8c9aad;padding:0 13px 12px}.nav-group{font-size:11px;text-transform:uppercase;letter-spacing:1px;font-weight:800;color:#8797a8;margin:21px 12px 8px}.nav a{display:block;padding:10px 13px;margin:3px 0;border-radius:9px;color:#344e6b;text-decoration:none;font-size:14px;font-weight:550;transition:background .15s,color .15s}.nav a:hover{background:#edf3fa;color:#123d72}.nav a.active{background:#e6f0fc;color:#123d72;font-weight:750;box-shadow:inset 3px 0 #14579a}main{padding:30px 34px 48px;max-width:1500px;width:100%;min-width:0}.page-heading{margin:0 0 20px}.page-eyebrow{display:block;color:#7b8da1;font-size:12px;font-weight:600;margin-bottom:6px}h1{font-size:27px;line-height:1.2;letter-spacing:-.6px;margin:0 0 4px;color:#123d72}h2{font-size:19px;letter-spacing:-.2px;margin:22px 0 12px;color:#173c64}.card{background:#fff;border:1px solid #e1e8f0;border-radius:14px;padding:22px;margin-bottom:18px;box-shadow:0 3px 14px #123d7208}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:15px}.metric{font-size:30px;font-weight:800;color:#123d72;letter-spacing:-.8px}.muted{color:#6c7a89}.alert{padding:13px 16px;border-radius:9px;margin:0 0 16px;border:1px solid transparent}.success{background:#e8f6ee;color:#17633a;border-color:#c9ebd5}.error{background:#fff0ef;color:#9b2821;border-color:#f4d2d0}label{display:block;font-weight:650;margin:0 0 14px}input,select,textarea{display:block;width:100%;max-width:560px;padding:11px 12px;margin-top:6px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;font:inherit;color:#182b40}input:focus,select:focus,textarea:focus{outline:3px solid #b9d6f7;outline-offset:1px;border-color:#14579a}button,.button{display:inline-block;background:#123d72;color:#fff;border:0;border-radius:9px;padding:11px 17px;font:inherit;font-weight:650;text-decoration:none;cursor:pointer;transition:background .15s,transform .15s}button:hover,.button:hover{background:#0b2c54}button:active,.button:active{transform:translateY(1px)}.link{background:none;padding:0;color:#fff;text-decoration:underline}.table-wrap button.link{color:#14579a;background:transparent;border:0;padding:3px 6px;font-weight:600;text-decoration:underline}.table-wrap button.link:hover{color:#a72b2b;background:#fff1f1}.inline{display:inline}.table-wrap{overflow-x:auto;background:#fff;border:1px solid #e1e8f0;border-radius:12px;box-shadow:0 3px 14px #123d7208}table{border-collapse:collapse;width:100%;min-width:600px}th,td{text-align:left;padding:12px 15px;border-bottom:1px solid #edf0f4;vertical-align:top}th{background:#f5f8fc;font-size:12px;text-transform:uppercase;letter-spacing:.3px;color:#526b83}tbody tr:hover{background:#f8fbff}td a{color:#14579a}.login-page{min-height:100vh;display:grid;place-items:center;padding:20px}.login-card{width:min(420px,100%);background:#fff;border:1px solid #e1e8f0;border-radius:16px;padding:30px;box-shadow:0 12px 36px #122b4514}.login-card h1{font-size:23px;margin:26px 0 20px}.brand.dark{color:#123d72}.login-card input{max-width:none}.login-card button{width:100%;margin-top:5px}.login-card .muted{font-size:13px;margin:18px 0 0}.pagination{display:flex;gap:10px;margin:16px 0;flex-wrap:wrap}.pill{display:inline-block;border-radius:20px;background:#edf3fa;padding:3px 9px;font-size:12px}@media(max-width:900px){.shell{grid-template-columns:205px minmax(0,1fr)}main{padding:24px}.nav{padding:16px 9px}}@media(max-width:760px){.top{padding:12px 15px;min-height:64px}.brand span{display:none}.shell{display:block}.nav{display:flex;overflow-x:auto;align-items:center;gap:4px;padding:9px;border-right:0;border-bottom:1px solid #e2e8f0}.nav-caption,.nav-group{display:none}.nav a{white-space:nowrap;margin:0;padding:9px 12px}.nav a.active{box-shadow:inset 0 -3px #14579a}.shell main{padding:18px}.user{font-size:12px;gap:8px}.card{padding:16px}h1{font-size:23px}}';
    }
}