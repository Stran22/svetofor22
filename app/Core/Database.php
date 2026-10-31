<?php
declare(strict_types=1);
namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo) return self::$pdo;
        $db = Config::get('db');
        if (!$db['database'] || !$db['username']) throw new RuntimeException('Database credentials are not configured.');
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $db['host'], $db['port'], $db['database'], $db['charset']);
        try {
            self::$pdo = new PDO($dsn, $db['username'], $db['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException('Database connection failed.', 0, $e);
        }
        return self::$pdo;
    }
}
