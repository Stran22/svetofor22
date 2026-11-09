<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\RateLimiter;
use App\Services\RequestUser;

final class PushTokenController
{
    public function register(Request $request): void
    {
        $user = RequestUser::fromBearer($request);
        RateLimiter::hit('push_register_user', (string)$user['id'], 30, 3600);
        $token = $request->body['fcm_token'] ?? null;
        if (!is_string($token) || strlen($token) < 20 || strlen($token) > 2048 ||
            !preg_match('/^[A-Za-z0-9:_-]+$/D', $token)) {
            throw new HttpException(422, 'FCM_TOKEN_INVALID', 'Некорректный FCM-токен.');
        }
        $hash = hash('sha256', $token);
        Database::connection()->prepare(
            "INSERT INTO user_push_tokens (user_id,token_hash,fcm_token,platform)
             VALUES (:user_id,:token_hash,:fcm_token,'android')
             ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),fcm_token=VALUES(fcm_token),updated_at=NOW()"
        )->execute(['user_id' => $user['id'], 'token_hash' => $hash, 'fcm_token' => $token]);
        Response::json(['success' => true, 'data' => ['registered' => true]]);
    }

    public function unregister(Request $request): void
    {
        $user = RequestUser::fromBearer($request);
        RateLimiter::hit('push_unregister_user', (string)$user['id'], 30, 3600);
        $token = $request->body['fcm_token'] ?? null;
        if (!is_string($token) || strlen($token) < 20 || strlen($token) > 2048) {
            throw new HttpException(422, 'FCM_TOKEN_INVALID', 'Некорректный FCM-токен.');
        }
        Database::connection()->prepare(
            'DELETE FROM user_push_tokens WHERE user_id=:user_id AND token_hash=:token_hash'
        )->execute(['user_id' => $user['id'], 'token_hash' => hash('sha256', $token)]);
        Response::json(['success' => true, 'data' => ['unregistered' => true]]);
    }
}
