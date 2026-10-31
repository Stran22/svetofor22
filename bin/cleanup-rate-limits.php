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

$stmt = Database::connection()->prepare(
    'DELETE FROM api_rate_limits WHERE updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)'
);
$stmt->execute();
fwrite(STDOUT, 'Удалено старых лимитов: ' . $stmt->rowCount() . PHP_EOL);
