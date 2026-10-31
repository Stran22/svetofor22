<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command can only run from CLI.\n");
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';
Config::load(dirname(__DIR__) . '/.env');

$login = trim((string)($argv[1] ?? ''));
if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $login)) {
    fwrite(STDERR, "Usage: php bin/create-admin.php <login> [owner|manager|moderator]\n");
    exit(1);
}

$pdo = Database::connection();
$adminCount = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
$role = strtolower((string)($argv[2] ?? ($adminCount === 0 ? 'owner' : '')));
if (!in_array($role, ['owner', 'manager', 'moderator'], true)) {
    fwrite(STDERR, "Укажите роль: owner, manager или moderator.\n");
    exit(1);
}
if ($adminCount === 0 && $role !== 'owner') {
    fwrite(STDERR, "Первая учётная запись должна иметь роль owner.\n");
    exit(1);
}

$password = getenv('ADMIN_PASSWORD') ?: '';
if ($password === '') {
    fwrite(STDOUT, 'Пароль (минимум 12 символов): ');
    $sttyState = null;
    if (function_exists('shell_exec')) {
        $sttyState = @shell_exec('stty -g 2>/dev/null');
        if (is_string($sttyState) && trim($sttyState) !== '') @shell_exec('stty -echo 2>/dev/null');
    }
    $password = trim((string)fgets(STDIN));
    if (is_string($sttyState) && trim($sttyState) !== '') {
        @shell_exec('stty ' . escapeshellarg(trim($sttyState)) . ' 2>/dev/null');
        fwrite(STDOUT, PHP_EOL);
    }
}
if (mb_strlen($password) < 12 || mb_strlen($password) > 200) {
    fwrite(STDERR, "Пароль должен содержать от 12 до 200 символов.\n");
    exit(1);
}

try {
    $stmt = $pdo->prepare(
        'INSERT INTO admins (login,password_hash,role,is_active)
         VALUES (:login,:password_hash,:role,1)'
    );
    $stmt->execute([
        'login' => $login,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
    ]);
    fwrite(STDOUT, 'Создан администратор #' . $pdo->lastInsertId() . ' с ролью ' . $role . '.' . PHP_EOL);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        fwrite(STDERR, "Такой логин уже существует.\n");
        exit(1);
    }
    throw $e;
} finally {
    unset($password);
}
