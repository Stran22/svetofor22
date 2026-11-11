<?php
declare(strict_types=1);

namespace Svet22\Controllers;

use PDO;
use Svet22\Core\Request;
use Svet22\Core\Response;
use Svet22\Services\PushTokenService;
use Svet22\Services\TokenService;

final class PushController
{
    public function __construct(private PDO $db) {}

    public function register(Request $request): never
    {
        $userId = $this->authenticatedUserId($request);
        $token = $this->validatedFcmToken($request);
        (new PushTokenService($this->db))->register($userId, $token);
        Response::success(['registered' => true]);
    }

    public function unregister(Request $request): never
    {
        $userId = $this->authenticatedUserId($request);
        $token = $this->validatedFcmToken($request);
        $deleted = (new PushTokenService($this->db))->unregister($userId, $token);
        Response::success(['unregistered' => $deleted]);
    }

    private function authenticatedUserId(Request $request): int
    {
        $userId = (new TokenService($this->db))->userId($request->bearerToken());
        if ($userId === null) {
            Response::error('UNAUTHENTICATED', 'Требуется действующий Bearer-токен.', 401);
        }
        return $userId;
    }

    private function validatedFcmToken(Request $request): string
    {
        $data = $request->json();
        $token = $data['fcm_token'] ?? null;
        $platform = $data['platform'] ?? 'android';
        if (!is_string($token) || $token === '' || strlen($token) > 2048
            || preg_match('/[\\x00-\\x20\\x7F]/', $token)
            || $platform !== 'android') {
            Response::error('VALIDATION_ERROR', 'Некорректный FCM-токен или платформа.', 422);
        }
        return $token;
    }
}