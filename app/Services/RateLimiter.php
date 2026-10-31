<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;

final class RateLimiter
{
    public static function hit(string $scope, string $identity, int $limit, int $windowSeconds): void
    {
        if ($scope === '' || $identity === '' || $limit < 1 || $windowSeconds < 1 || $windowSeconds > 86400) {
            throw new \InvalidArgumentException('Invalid rate limit parameters.');
        }
        $bucketHash = hash('sha256', $scope . ':' . $identity);
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO api_rate_limits (bucket_hash,attempts,window_started_at,updated_at)
             VALUES (:bucket_hash,1,NOW(),NOW())
             ON DUPLICATE KEY UPDATE
               attempts=IF(window_started_at < DATE_SUB(NOW(), INTERVAL ' . $windowSeconds . ' SECOND),1,attempts+1),
               window_started_at=IF(window_started_at < DATE_SUB(NOW(), INTERVAL ' . $windowSeconds . ' SECOND),NOW(),window_started_at),
               updated_at=NOW()'
        )->execute(['bucket_hash' => $bucketHash]);

        $stmt = $pdo->prepare('SELECT attempts FROM api_rate_limits WHERE bucket_hash=:bucket_hash LIMIT 1');
        $stmt->execute(['bucket_hash' => $bucketHash]);
        if ((int)$stmt->fetchColumn() > $limit) {
            throw new HttpException(429, 'RATE_LIMIT_EXCEEDED', 'Слишком много запросов. Подождите и повторите попытку.');
        }
    }
}
