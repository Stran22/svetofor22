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
$tasks = [
    'api_rate_limits' => "DELETE FROM api_rate_limits WHERE updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)",
    'admin_login_attempts' => "DELETE FROM admin_login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
    'oauth_attempts' => "DELETE FROM oauth_attempts WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)",
    'oauth_handoff_codes' => "DELETE FROM oauth_handoff_codes WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)",
    'api_tokens' => "DELETE FROM api_tokens WHERE expires_at IS NOT NULL AND expires_at < NOW()",
];

foreach ($tasks as $table => $sql) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    fwrite(STDOUT, $table . ': ' . $stmt->rowCount() . PHP_EOL);
}
