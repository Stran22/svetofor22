<?php
declare(strict_types=1);

use Svet22\Core\Config;
use Svet22\Core\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This utility can only run from CLI.\n");
    exit(1);
}

try {
    Config::load();
    $db = Database::connection();
    $username = trim($argv[1] ?? (getenv('SVET_ADMIN_USERNAME') ?: ''));
    if ($username === '') {
        fwrite(STDOUT, "Логин нового администратора: ");
        $username = trim((string) fgets(STDIN));
    }
    if (!preg_match('/^[a-zA-Z0-9._-]{3,100}$/', $username)) {
        throw new RuntimeException('Логин: 3–100 символов, латиница, цифры, точка, дефис или подчёркивание.');
    }

    $existing = $db->prepare('SELECT id FROM admins WHERE username = ? LIMIT 1');
    $existing->execute([$username]);
    if ($existing->fetchColumn()) {
        throw new RuntimeException('Такой логин уже существует. Пароль существующей записи не менялся.');
    }

    $hideInput = function (bool $hide): void {
        if (function_exists('stream_isatty') && function_exists('system') && stream_isatty(STDIN) && DIRECTORY_SEPARATOR === '/') {
            @system($hide ? 'stty -echo' : 'stty echo');
        }
    };

    $hideInput(true);
    try {
        fwrite(STDOUT, "Пароль (не менее 12 символов): ");
        $password = rtrim((string) fgets(STDIN), "\r\n");
        fwrite(STDOUT, "\nПовторите пароль: ");
        $confirm = rtrim((string) fgets(STDIN), "\r\n");
    } finally {
        $hideInput(false);
        fwrite(STDOUT, "\n");
    }

    if (strlen($password) < 12 || strlen($password) > 4096) {
        throw new RuntimeException('Пароль должен содержать от 12 до 4096 байт.');
    }
    if (!hash_equals($password, $confirm)) {
        throw new RuntimeException('Пароли не совпадают.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($hash)) {
        throw new RuntimeException('Не удалось создать хэш пароля.');
    }
    $insert = $db->prepare("INSERT INTO admins (username, password_hash, role, is_active) VALUES (?, ?, 'admin', 1)");
    $insert->execute([$username, $hash]);

    fwrite(STDOUT, "Администратор создан. Войдите на /admin/login.\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Ошибка: " . $e->getMessage() . "\n");
    exit(1);
}
