<?php
declare(strict_types=1);

namespace Svet22\Services;

use PDO;

final class PushTokenService
{
    public function __construct(private PDO $db) {}

    public function register(int $userId, string $fcmToken): void
    {
        $hash = hash('sha256', $fcmToken);
        $stmt = $this->db->prepare(
            "INSERT INTO user_push_tokens (user_id, token_hash, fcm_token, platform)
             VALUES (?, ?, ?, 'android')
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                fcm_token = VALUES(fcm_token),
                platform = VALUES(platform),
                updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([$userId, $hash, $fcmToken]);
    }

    public function unregister(int $userId, string $fcmToken): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM user_push_tokens WHERE user_id = ? AND token_hash = ?'
        );
        $stmt->execute([$userId, hash('sha256', $fcmToken)]);
        return $stmt->rowCount() > 0;
    }
}