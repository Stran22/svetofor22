<?php
declare(strict_types=1);

namespace Svet22\Controllers;

use PDO;
use Throwable;
use Svet22\Core\Request;
use Svet22\Core\Response;
use Svet22\Services\TokenService;
use Svet22\Services\VkIdService;

final class VkAuthController
{
    public function __construct(private PDO $db) {}

    public function login(Request $request): never
    {
        $userId = (new TokenService($this->db))->userId($request->bearerToken());
        if ($userId === null) {
            Response::error('UNAUTHENTICATED', 'Сначала зарегистрируйте устройство.', 401);
        }

        $data = $request->json();
        $code = $data['code'] ?? null;
        $deviceId = $data['device_id'] ?? null;
        $verifier = $data['code_verifier'] ?? null;
        $state = $data['state'] ?? null;
        if (!is_string($code) || strlen($code) < 5 || strlen($code) > 4096 ||
            !is_string($deviceId) || strlen($deviceId) < 5 || strlen($deviceId) > 256 ||
            !is_string($verifier) || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) ||
            !is_string($state) || !preg_match('/^[A-Za-z0-9_-]{20,256}$/D', $state)) {
            Response::error('VALIDATION_ERROR', 'Некорректные параметры VK ID.', 422);
        }

        try {
            $identity = (new VkIdService())->exchange($code, $deviceId, $verifier, $state);
        } catch (Throwable $e) {
            error_log('[svet22-vk] OAuth exchange failed: ' . $e::class);
            Response::error('VK_AUTH_FAILED', 'VK ID не подтвердил вход. Попробуйте ещё раз.', 401);
        }

        // A verified VK identity is authoritative: restore its existing account on a new installation.
        // Never merge or transfer unrelated guest data implicitly.
        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare('SELECT id, is_guest FROM users WHERE id = ? FOR UPDATE');
            $lock->execute([$userId]);
            $currentUser = $lock->fetch(PDO::FETCH_ASSOC);
            if ($currentUser === false) {
                $this->db->rollBack();
                Response::error('UNAUTHENTICATED', 'Пользователь не найден.', 401);
            }

            $lookup = $this->db->prepare(
                "SELECT user_id FROM social_accounts WHERE provider = 'vk' AND provider_user_id = ? LIMIT 1 FOR UPDATE"
            );
            $lookup->execute([$identity['subject']]);
            $owner = $lookup->fetchColumn();
            if ($owner !== false && (int) $owner !== $userId) {
                // Prevent switching an already social-authenticated account to another identity.
                if ((int) $currentUser['is_guest'] !== 1) {
                    $this->db->rollBack();
                    Response::error('ACCOUNT_CONFLICT', 'Выйдите из текущего аккаунта перед входом в другой VK ID.', 409);
                }
                $userId = (int) $owner;
                $ownerLock = $this->db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
                $ownerLock->execute([$userId]);
                if ($ownerLock->fetchColumn() === false) {
                    $this->db->rollBack();
                    Response::error('ACCOUNT_NOT_FOUND', 'Аккаунт VK ID не найден.', 404);
                }
            }

            if ($owner === false) {
                $insert = $this->db->prepare(
                    "INSERT INTO social_accounts (user_id, provider, provider_user_id) VALUES (?, 'vk', ?)"
                );
                $insert->execute([$userId, $identity['subject']]);
            }
            $name = mb_substr(trim($identity['name']), 0, 150);
            $update = $this->db->prepare(
                "UPDATE users SET is_guest = 0, display_name = CASE WHEN (display_name IS NULL OR display_name = '') AND ? <> '' THEN ? ELSE display_name END WHERE id = ?"
            );
            $update->execute([$name, $name, $userId]);
            // Issue a fresh bearer for the restored user, without altering old reviews/subscriptions.
            $accessToken = (new TokenService($this->db))->issue($userId);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        Response::success(['user_id' => $userId, 'is_guest' => false, 'provider' => 'vk', 'access_token' => $accessToken, 'token_type' => 'Bearer']);
    }
}