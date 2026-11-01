<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Security\AdminGuard;
use App\Services\RateLimiter;

final class AdminAuthController
{
    public function csrf(Request $request): void
    {
        Response::json(['success' => true, 'data' => ['csrf_token' => AdminGuard::csrfToken()]]);
    }

    public function login(Request $request): void
    {
        AdminGuard::requireCsrf();
        $login = trim((string)($request->body['login'] ?? ''));
        $password = (string)($request->body['password'] ?? '');
        if ($login === '' || strlen($login) > 100 || $password === '' || strlen($password) > 4096) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Укажите логин и пароль.');
        }
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $pdo = Database::connection();
        $rateIdentity = hash('sha256', $login . chr(0) . $ip);
        RateLimiter::hit(
            'admin_login', $rateIdentity, 10, 900,
            'LOGIN_RATE_LIMITED', 'Слишком много неудачных попыток. Попробуйте позже.'
        );
        $stmt = $pdo->prepare('SELECT id,login,password_hash,role,is_active FROM admins WHERE login=:login LIMIT 1');
        $stmt->execute(['login' => $login]);
        $admin = $stmt->fetch();
        if (!$admin || !(bool)$admin['is_active'] || !password_verify($password, (string)$admin['password_hash'])) {
            $pdo->prepare('INSERT INTO admin_login_attempts (login,ip_address) VALUES (:login,:ip)')
                ->execute(['login' => $login, 'ip' => $ip]);
            throw new HttpException(401, 'ADMIN_LOGIN_FAILED', 'Неверный логин или пароль.');
        }
        AdminGuard::startSession();
        session_regenerate_id(true);
        $_SESSION = [
            'admin' => ['id' => (int)$admin['id'], 'login' => (string)$admin['login'], 'role' => (string)$admin['role']],
            'csrf_token' => bin2hex(random_bytes(32)),
        ];
        $pdo->prepare('UPDATE admins SET last_login_at=NOW() WHERE id=:id')->execute(['id' => $admin['id']]);
        $pdo->prepare('DELETE FROM admin_login_attempts WHERE login=:login AND ip_address=:ip')
            ->execute(['login' => $login, 'ip' => $ip]);
        RateLimiter::clear('admin_login', $rateIdentity);
        $this->audit((int)$admin['id'], 'admin.login', (int)$admin['id']);
        Response::json(['success' => true, 'data' => ['admin' => $_SESSION['admin'], 'csrf_token' => $_SESSION['csrf_token']]]);
    }

    public function me(Request $request): void
    {
        $admin = AdminGuard::requireAdmin();
        Response::json(['success' => true, 'data' => ['admin' => $admin, 'csrf_token' => AdminGuard::csrfToken()]]);
    }

    public function logout(Request $request): void
    {
        $admin = AdminGuard::requireAdmin();
        AdminGuard::requireCsrf();
        $this->audit((int)$admin['id'], 'admin.logout', (int)$admin['id']);
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000, 'path' => $params['path'] ?: '/',
                'domain' => $params['domain'] ?? '', 'secure' => (bool)$params['secure'],
                'httponly' => true, 'samesite' => 'Lax',
            ]);
        }
        session_destroy();
        Response::json(['success' => true, 'data' => ['message' => 'Вы вышли из панели администратора.']]);
    }

    private function audit(int $adminId, string $action, int $entityId): void
    {
        Database::connection()->prepare(
            'INSERT INTO admin_audit_log (admin_id,action,entity_type,entity_id,ip_address) VALUES (:admin,:action,:type,:entity,:ip)'
        )->execute([
            'admin' => $adminId, 'action' => $action, 'type' => 'admin', 'entity' => $entityId,
            'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ]);
    }
}