<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use PDO;

final class TokenService
{
    public function issueGuestToken(string $deviceUuid): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $find = $pdo->prepare('SELECT id FROM users WHERE device_uuid=:uuid LIMIT 1');
            $find->execute(['uuid' => $deviceUuid]);
            $userId = $find->fetchColumn();
            if ($userId === false) {
                $insert = $pdo->prepare('INSERT INTO users (device_uuid,display_name) VALUES (:uuid,:name)');
                $insert->execute(['uuid' => $deviceUuid, 'name' => 'Гость']);
                $userId = (int)$pdo->lastInsertId();
            } else {
                $userId = (int)$userId;
            }

            $plainToken = bin2hex(random_bytes(32));
            $hash = hash('sha256', $plainToken);
            $expiresAt = (new \DateTimeImmutable('+365 days'))->format('Y-m-d H:i:s');
            $token = $pdo->prepare('INSERT INTO api_tokens (user_id,token_hash,expires_at) VALUES (:user_id,:hash,:expires)');
            $token->execute(['user_id' => $userId, 'hash' => $hash, 'expires' => $expiresAt]);
            $pdo->commit();
            return ['token' => $plainToken, 'token_type' => 'Bearer', 'expires_at' => $expiresAt, 'user_id' => $userId];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function authenticate(string $plainToken): array
    {
        if ($plainToken === '' || strlen($plainToken) > 200) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Требуется авторизация.');
        }
        $stmt = Database::connection()->prepare(
            'SELECT u.id,u.device_uuid,u.display_name,u.email,t.id AS token_id
             FROM api_tokens t JOIN users u ON u.id=t.user_id
             WHERE t.token_hash=:hash AND (t.expires_at IS NULL OR t.expires_at>NOW()) LIMIT 1'
        );
        $stmt->execute(['hash' => hash('sha256', $plainToken)]);
        $user = $stmt->fetch();
        if (!$user) throw new HttpException(401, 'UNAUTHENTICATED', 'Токен недействителен или истёк.');
        Database::connection()->prepare('UPDATE api_tokens SET last_used_at=NOW() WHERE id=:id')
            ->execute(['id' => $user['token_id']]);
        unset($user['token_id']);
        return $user;
    }
}
