<?php
declare(strict_types=1);

namespace Svet22\Services;

use PDO;
use Svet22\Core\Response;

final class AdminAuthService
{
    private const IDLE_TIMEOUT = 1800;
    private const ABSOLUTE_TIMEOUT = 28800;
    private const MAX_ATTEMPTS = 5;
    private const LOCK_SECONDS = 900;

    public function __construct(private PDO $db) {}

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Strict');
        session_name('SVET_ADMIN');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/admin',
            'secure' => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    public function csrfToken(): string
    {
        $this->start();
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public function verifyCsrf(?string $token): bool
    {
        $this->start();
        return is_string($token)
            && isset($_SESSION['csrf_token'])
            && is_string($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    public function login(string $username, string $password, string $ip): bool
    {
        $this->start();
        $username = trim(mb_substr($username, 0, 100));
        if ($username === '' || $password === '' || strlen($password) > 4096) {
            return false;
        }

        $key = hash('sha256', mb_strtolower($username) . '|' . $ip);
        $attempt = $this->db->prepare('SELECT attempts, window_started_at, locked_until FROM admin_login_attempts WHERE attempt_key = ? LIMIT 1');
        $attempt->execute([$key]);
        $state = $attempt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($state && $state['locked_until'] !== null && strtotime((string) $state['locked_until']) > time()) {
            return false;
        }
        if ($state && strtotime((string) $state['window_started_at']) < time() - self::LOCK_SECONDS) {
            $this->clearAttempts($key);
            $state = null;
        }

        $stmt = $this->db->prepare('SELECT id, username, password_hash, role, is_active FROM admins WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin || (int) $admin['is_active'] !== 1 || !password_verify($password, (string) $admin['password_hash'])) {
            $this->recordFailure($key);
            return false;
        }

        $this->clearAttempts($key);
        session_regenerate_id(true);
        $_SESSION = [
            'admin' => [
                'id' => (int) $admin['id'],
                'username' => (string) $admin['username'],
                'role' => (string) $admin['role'],
            ],
            'login_at' => time(),
            'last_activity' => time(),
            'csrf_token' => bin2hex(random_bytes(32)),
        ];

        $update = $this->db->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = ?');
        $update->execute([(int) $admin['id']]);
        $this->audit((int) $admin['id'], 'admin.login', 'admin', (int) $admin['id']);
        return true;
    }

    public function user(): ?array
    {
        $this->start();
        $admin = $_SESSION['admin'] ?? null;
        if (!is_array($admin) || !isset($admin['id'], $admin['role'], $admin['username'])) {
            return null;
        }

        $now = time();
        $last = (int) ($_SESSION['last_activity'] ?? 0);
        $login = (int) ($_SESSION['login_at'] ?? 0);
        if ($last < 1 || $login < 1 || $now - $last > self::IDLE_TIMEOUT || $now - $login > self::ABSOLUTE_TIMEOUT) {
            $this->logout();
            return null;
        }

        $check = $this->db->prepare('SELECT is_active, role, username FROM admins WHERE id = ? LIMIT 1');
        $check->execute([(int) $admin['id']]);
        $fresh = $check->fetch(PDO::FETCH_ASSOC);
        if (!$fresh || (int) $fresh['is_active'] !== 1) {
            $this->logout();
            return null;
        }

        $_SESSION['admin']['role'] = (string) $fresh['role'];
        $_SESSION['admin']['username'] = (string) $fresh['username'];
        $_SESSION['last_activity'] = $now;
        return $_SESSION['admin'];
    }

    public function requireUser(): array
    {
        $user = $this->user();
        if ($user === null) {
            Response::redirect('/admin/login');
        }
        return $user;
    }

    public function requireRole(string $role): array
    {
        $user = $this->requireUser();
        if ($role === 'admin' && $user['role'] !== 'admin') {
            Response::html('<!doctype html><meta charset="utf-8"><h1>403 — Недостаточно прав</h1><p><a href="/admin">Вернуться в панель</a></p>', 403);
        }
        return $user;
    }

    public function logout(): void
    {
        $this->start();
        if (isset($_SESSION['admin']['id'])) {
            $this->audit((int) $_SESSION['admin']['id'], 'admin.logout', 'admin', (int) $_SESSION['admin']['id']);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?: '/admin',
                'secure' => (bool) $params['secure'],
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }
        session_destroy();
    }

    public function audit(?int $adminId, string $action, ?string $targetType = null, ?int $targetId = null, array $details = [], string $ip = ''): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO admin_audit_log (admin_id, action, target_type, target_id, details, ip_hash)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $adminId,
            $action,
            $targetType,
            $targetId,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null,
            $ip !== '' ? hash('sha256', $ip) : null,
        ]);
    }

    private function recordFailure(string $key): void
    {
        // Атомарный UPSERT не позволяет параллельным запросам потерять счётчик попыток.
        $stmt = $this->db->prepare(
            'INSERT INTO admin_login_attempts (attempt_key, attempts, window_started_at, locked_until)
             VALUES (?, 1, NOW(), NULL)
             ON DUPLICATE KEY UPDATE
                attempts = IF(window_started_at < DATE_SUB(NOW(), INTERVAL ' . self::LOCK_SECONDS . ' SECOND), 1, attempts + 1),
                locked_until = IF(attempts >= ' . self::MAX_ATTEMPTS . ', DATE_ADD(NOW(), INTERVAL ' . self::LOCK_SECONDS . ' SECOND), NULL),
                window_started_at = IF(window_started_at < DATE_SUB(NOW(), INTERVAL ' . self::LOCK_SECONDS . ' SECOND), NOW(), window_started_at)'
        );
        $stmt->execute([$key]);
    }

    private function clearAttempts(string $key): void
    {
        $stmt = $this->db->prepare('DELETE FROM admin_login_attempts WHERE attempt_key = ?');
        $stmt->execute([$key]);
    }

    private function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) === '443');
    }
}
