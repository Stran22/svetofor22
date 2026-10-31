<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\RateLimiter;
use App\Services\RequestUser;
use App\Services\TokenService;

final class AuthController
{
    public function guest(Request $request): void
    {
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
        RateLimiter::hit('guest_ip', $ip, 30, 60);
        $uuid = strtolower(trim((string)($request->body['device_uuid'] ?? '')));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Передан некорректный device_uuid.');
        }
        RateLimiter::hit('guest_device', $uuid, 5, 3600);
        $issued = (new TokenService())->issueGuestToken($uuid);
        Response::json(['success' => true, 'data' => $issued], 201);
    }

    public function me(Request $request): void
    {
        $user = RequestUser::fromBearer($request);
        $stmt = Database::connection()->prepare('SELECT 1 FROM social_accounts WHERE user_id=:user_id LIMIT 1');
        $stmt->execute(['user_id' => $user['id']]);
        $accountType = $stmt->fetchColumn() ? 'social' : 'guest';
        Response::json(['success' => true, 'data' => [
            'user' => $user,
            'account_type' => $accountType,
        ]]);
    }

    public function logout(Request $request): void
    {
        $plainToken = RequestUser::tokenFromBearer($request);
        $service = new TokenService();
        $user = $service->authenticate($plainToken);
        RateLimiter::hit('logout_user', (string)$user['id'], 30, 3600);
        $service->revokeToken($plainToken, (int)$user['id']);
        Response::json(['success' => true, 'data' => ['message' => 'Текущий токен отозван.']]);
    }
}
