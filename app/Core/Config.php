<?php
declare(strict_types=1);
namespace App\Core;

final class Config
{
    private static array $values = [];

    public static function load(string $file): void
    {
        if (is_file($file) && is_readable($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                if ($key !== '' && getenv($key) === false) putenv($key . '=' . trim(trim($value), "'\""));
            }
        }
        self::$values = [
            'app' => [
                'env' => getenv('APP_ENV') ?: 'production',
                'debug' => filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOL),
                'timezone' => getenv('APP_TIMEZONE') ?: 'Asia/Barnaul',
                'url' => getenv('APP_URL') ?: '',
                'auth_return_uri' => getenv('APP_AUTH_RETURN_URI') ?: '',
            ],
            'db' => [
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '3306',
                'database' => getenv('DB_DATABASE') ?: '',
                'username' => getenv('DB_USERNAME') ?: '',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',\n                'timezone' => getenv('DB_TIMEZONE') ?: '+07:00',
            ],
            'onesignal' => [
                'app_id' => getenv('ONESIGNAL_APP_ID') ?: '',
                'rest_api_key' => getenv('ONESIGNAL_REST_API_KEY') ?: '',
            ],
            'oauth' => [
                'vk_id' => [
                    'client_id' => getenv('VK_ID_CLIENT_ID') ?: '',
                    'service_token' => getenv('VK_ID_SERVICE_TOKEN') ?: '',
                    'scope' => getenv('VK_ID_SCOPE') ?: 'vkid.personal_info email',
                ],
                'yandex' => [
                    'client_id' => getenv('YANDEX_CLIENT_ID') ?: '',
                    'scope' => getenv('YANDEX_SCOPE') ?: 'login:info login:email login:avatar',
                ],
            ],
        ];
        date_default_timezone_set(self::$values['app']['timezone']);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) return $default;
            $value = $value[$part];
        }
        return $value;
    }
}
