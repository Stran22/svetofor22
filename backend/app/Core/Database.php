<?php
declare(strict_types=1);

namespace Svet22\Core;

use PDO;
use RuntimeException;
use Throwable;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = (string) Config::get('database.host');
        $port = (int) Config::get('database.port', 3306);
        $name = (string) Config::get('database.name');
        $charset = (string) Config::get('database.charset', 'utf8mb4');
        $username = (string) Config::get('database.username');
        $password = (string) Config::get('database.password');

        if ($name === '' || $username === '') {
            throw new RuntimeException('Не заполнены параметры подключения к MySQL.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);
        self::$connection = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        return self::$connection;
    }

    public static function ping(): bool
    {
        try {
            self::connection()->query('SELECT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
