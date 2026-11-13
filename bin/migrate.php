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
    $lockName = 'svet22:schema-migrations';
    $lock = $db->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute([$lockName]);
    if ((int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('Не удалось получить блокировку миграций. Возможно, другая копия уже выполняется.');
    }

    try {
        $directory = dirname(__DIR__) . '/database/migrations';
        $files = glob($directory . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        if (!$files) {
            throw new RuntimeException('Файлы миграций не найдены.');
        }

        $hasLedger = (bool) $db->query("SHOW TABLES LIKE 'schema_migrations'")->fetchColumn();
        $batch = $hasLedger
            ? ((int) $db->query('SELECT COALESCE(MAX(batch), 0) FROM schema_migrations')->fetchColumn() + 1)
            : 1;

        foreach ($files as $file) {
            $migration = basename($file);
            if (!preg_match('/^\d{3}_[a-z0-9_-]+\.sql$/i', $migration)) {
                throw new RuntimeException('Недопустимое имя файла миграции: ' . $migration);
            }

            if ($hasLedger) {
                $check = $db->prepare('SELECT id FROM schema_migrations WHERE migration = ? LIMIT 1');
                $check->execute([$migration]);
                if ($check->fetchColumn()) {
                    fwrite(STDOUT, '[пропуск] ' . $migration . " уже применена.\n");
                    continue;
                }
            }

            $sql = file_get_contents($file);
            if (!is_string($sql)) {
                throw new RuntimeException('Не удалось прочитать миграцию: ' . $migration);
            }
            $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
            $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
            foreach ($statements as $statement) {
                if (trim($statement) !== '') {
                    $db->exec($statement);
                }
            }

            $hasLedger = (bool) $db->query("SHOW TABLES LIKE 'schema_migrations'")->fetchColumn();
            if (!$hasLedger) {
                throw new RuntimeException('Миграция не создала таблицу schema_migrations.');
            }
            $record = $db->prepare('INSERT INTO schema_migrations (migration, batch) VALUES (?, ?)');
            $record->execute([$migration, $batch]);
            fwrite(STDOUT, '[применена] ' . $migration . "\n");
        }

        fwrite(STDOUT, "Миграции проверены.\n");
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Ошибка миграции: " . $e->getMessage() . "\n");
    exit(1);
}
