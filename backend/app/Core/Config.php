<?php
declare(strict_types=1);

namespace Svet22\Core;

use RuntimeException;

final class Config
{
    private static ?array $values = null;

    public static function load(): void
    {
        if (self::$values !== null) {
            return;
        }

        $path = getenv('SVET_CONFIG_FILE');
        if (!$path || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Не найден внешний конфигурационный файл SVET_CONFIG_FILE.');
        }

        $values = require $path;
        if (!is_array($values)) {
            throw new RuntimeException('Конфигурационный файл должен возвращать массив.');
        }

        self::$values = $values;
        date_default_timezone_set((string) self::get('app.timezone', 'Asia/Barnaul'));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        $value = self::$values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }
}
