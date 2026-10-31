<?php
declare(strict_types=1);

use App\Core\Config;
use App\Services\PushNotificationService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';
Config::load(dirname(__DIR__) . '/.env');

try {
    $result = (new PushNotificationService())->retryFailedDeliveries(100);
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $e) {
    error_log('Push retry command failed: ' . $e->getMessage());
    fwrite(STDERR, "Push retry failed. Check the server error log.\n");
    exit(1);
}
