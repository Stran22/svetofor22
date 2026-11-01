<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;

final class TokenService
{
    public function issueGuestToken(string $deviceUuid): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            // A single device can send two first-login requests concurrently.
            // LAST_INSERT_ID(id) returns the existing user's id on duplicate UUID
            // instead of leaking a duplicate-key error to one of those requests.
            $insert = $pdo->prepare(
                'INSERT INTO users (device_uuid,display_name) VALUES (:uuid,:name)
                 ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)'
            );
            $insert->execute(['uuid' => $deviceUuid, 'name' => 'Гость']);
            $userId = (int)$pdo->lastInsertId();
            if ($userId < 1) throw new \RuntimeException('Could not resolve guest account id.');

            $plainToken = bin2hex(random_bytes(32));
            $token = $pdo->prepare(
                'INSERT INTO api_tokens (user_id,token_hash,expires_at)
                 VALUES (:user_id,:hash,DATE_ADD(NOW(), INTERVAL 365 DAY))'
            );
            $token->execute(['user_id' => $userId, 'hash' => hash('sha256', $plainToken)]);
            $expiry = $pdo->prepare('SELECT expires_at FROM api_tokens WHERE id=:id');
            $expiry->execute(['id' => $pdo->lastInsertId()]);
            $expiresAt = (string)$expiry->fetchColumn();
            $pdo->commit();
            return ['token' => $plainToken, 'token_type' => 'Bearer', 'expires_at' => $expiresAt, 'user_id' => $userId];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function issueTokenForUser(int $userId): array
    {
        $pdo = Database::connection();
        $check = $pdo->prepare('SELECT id FROM users WHERE id=:id LIMIT 1');
        $check->execute(['id' => $userId]);
        if (!$check->fetchColumn()) {
            throw new HttpException(404, 'USER_NOT_FOUND', 'Учётная запись не найдена.');
        }
        $plainToken = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare(
            'INSERT INTO api_tokens (user_id,token_hash,expires_at)
             VALUES (:user_id,:hash,DATE_ADD(NOW(), INTERVAL 365 DAY))'
        );
        $stmt->execute([
            'user_id' => $userId,
            'hash' => hash('sha256', $plainToken),
        ]);
        $expiry = $pdo->prepare('SELECT expires_at FROM api_tokens WHERE id=:id');
        $expiry->execute(['id' => $pdo->lastInsertId()]);
        $expiresAt = (string)$expiry->fetchColumn();
        return [
            'token' => $plainToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt,
            'user_id' => $userId,
        ];
    }

    public function revokeToken(string $plainToken, int $userId): void
    {
        Database::connection()->prepare(
            'DELETE FROM api_tokens WHERE user_id=:user_id AND token_hash=:hash'
        )->execute([
            'user_id' => $userId,
            'hash' => hash('sha256', $plainToken),
        ]);
    }

    public function authenticate(string $plainToken): array
    {
        if ($plainToken === '' || strlen($plainToken) > 200) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Требуется авторизация.');
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT u.id,u.device_uuid,u.display_name,u.email,t.id AS token_id
             FROM api_tokens t JOIN users u ON u.id=t.user_id
             WHERE t.token_hash=:hash AND (t.expires_at IS NULL OR t.expires_at>NOW()) LIMIT 1'
        );
        $stmt->execute(['hash' => hash('sha256', $plainToken)]);
        $user = $stmt->fetch();
        if (!$user) throw new HttpException(401, 'UNAUTHENTICATED', 'Токен недействителен или истёк.');
        $pdo->prepare('UPDATE api_tokens SET last_used_at=NOW() WHERE id=:id')
            ->execute(['id' => $user['token_id']]);
        unset($user['token_id']);
        return $user;
    }
}
