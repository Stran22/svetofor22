<?php
declare(strict_types=1);
namespace App\Security;

use App\Core\HttpException;

final class AdminGuard
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        if (headers_sent()) throw new \RuntimeException('Cannot start admin session after output.');
        session_name('sv22_admin');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true, 'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function matchesCsrfToken(?string $known, ?string $provided): bool
    {
        return is_string($known) && is_string($provided)
            && $known !== '' && $provided !== '' && hash_equals($known, $provided);
    }

    public static function requireCsrf(): void
    {
        self::startSession();
        $provided = '';
        foreach (function_exists('getallheaders') ? (getallheaders() ?: []) : [] as $name => $value) {
            if (strtolower((string)$name) === 'x-csrf-token') {
                $provided = trim((string)$value);
                break;
            }
        }
        if ($provided === '') {
            $provided = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['REDIRECT_HTTP_X_CSRF_TOKEN'] ?? ''));
        }
        if ($provided === '') $provided = trim((string)($_POST['_csrf'] ?? ''));
        if (!self::matchesCsrfToken($_SESSION['csrf_token'] ?? null, $provided)) {
            throw new HttpException(419, 'CSRF_TOKEN_INVALID', 'Сессия формы устарела. Обновите страницу и повторите действие.');
        }
    }

    public static function requireAdmin(): array
    {
        self::startSession();
        $admin = $_SESSION['admin'] ?? null;
        if (!is_array($admin) || empty($admin['id']) || empty($admin['role'])) {
            throw new HttpException(401, 'ADMIN_UNAUTHENTICATED', 'Требуется вход администратора.');
        }
        $lastActivity = (int)($_SESSION['admin_last_activity'] ?? 0);
        if ($lastActivity > 0 && $lastActivity < time() - 1800) {
            $_SESSION = [];
            session_destroy();
            throw new HttpException(401, 'ADMIN_SESSION_EXPIRED', 'Сессия администратора завершена из-за бездействия. Войдите снова.');
        }
        $stmt = Database::connection()->prepare(
            'SELECT id,login,role,is_active FROM admins WHERE id=:id LIMIT 1'
        );
        $stmt->execute(['id' => (int)$admin['id']]);
        $current = $stmt->fetch();
        if (!$current || !(bool)$current['is_active']) {
            $_SESSION = [];
            session_destroy();
            throw new HttpException(401, 'ADMIN_ACCOUNT_DISABLED', 'Учётная запись администратора отключена. Войдите снова после проверки доступа.');
        }

        // Roles are read from MySQL on every protected request. A role change or
        // account deactivation therefore takes effect without waiting for logout.
        $_SESSION['admin'] = [
            'id' => (int)$current['id'],
            'login' => (string)$current['login'],
            'role' => (string)$current['role'],
        ];
        $_SESSION['admin_last_activity'] = time();
        return $_SESSION['admin'];
    }

    public static function requireRole(array $allowedRoles): array
    {
        $admin = self::requireAdmin();
        if (!in_array($admin['role'], $allowedRoles, true)) {
            throw new HttpException(403, 'ADMIN_FORBIDDEN', 'Недостаточно прав для этого действия.');
        }
        return $admin;
    }
}