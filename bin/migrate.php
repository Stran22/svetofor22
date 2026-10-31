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

$pdo = Database::connection();
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(190) NOT NULL PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$files = glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [];
sort($files, SORT_STRING);
if ($files === []) {
    fwrite(STDOUT, "Миграции не найдены.\n");
    exit(0);
}

$applied = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$appliedSet = array_fill_keys($applied, true);

foreach ($files as $file) {
    $name = basename($file);
    if (isset($appliedSet[$name])) {
        fwrite(STDOUT, "Пропуск: {$name}\n");
        continue;
    }

    $sql = file_get_contents($file);
    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException("Миграция {$name} пуста или недоступна.");
    }

    // Migration files contain one SQL statement per semicolon-terminated line.
    // Execute statements individually because MySQL DDL implicitly commits.
    $statements = preg_split('/;\\s*(?:\\r?\\n|$)/', $sql) ?: [];
    try {
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement !== '') $pdo->exec($statement);
        }
        $record = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');
        $record->execute(['migration' => $name]);
        $appliedSet[$name] = true;
        fwrite(STDOUT, "Применена: {$name}\n");
    } catch (Throwable $e) {
        fwrite(STDERR, "Ошибка в миграции {$name}: " . $e->getMessage() . PHP_EOL);
        fwrite(STDERR, "MySQL DDL может частично сохраниться; проверьте схему перед повторным запуском.\n");
        exit(1);
    }
}

fwrite(STDOUT, "Все миграции применены.\n");
