<?php
declare(strict_types=1);

namespace Svet22\Services;

use PDO;

final class TokenService
{
    public function __construct(private PDO $db) {}

    public function issue(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $stmt = $this->db->prepare('INSERT INTO api_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 365 DAY))');
        $stmt->execute([$userId, $hash]);
        return $token;
    }

    public function userId(?string $token): ?int
    {
        if ($token === null || $token === '') {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT user_id FROM api_tokens WHERE token_hash = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1'
        );
        $stmt->execute([hash('sha256', $token)]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function revoke(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }
        $stmt = $this->db->prepare('UPDATE api_tokens SET revoked_at = NOW() WHERE token_hash = ? AND revoked_at IS NULL');
        $stmt->execute([hash('sha256', $token)]);
        return $stmt->rowCount() > 0;
    }
}
