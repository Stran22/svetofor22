<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;
use App\Services\PushNotificationService;

require dirname(__DIR__) . '/vendor/autoload.php';
Config::load(dirname(__DIR__) . '/.env');
$pdo = Database::connection();
$pdo->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(8));
    $pdo->prepare('INSERT INTO branches (name,slug) VALUES (?,?)')
        ->execute(['FCM smoke', 'fcm-smoke-' . $suffix]);
    $branchId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO products (name,slug) VALUES (?,?)')
        ->execute(['FCM smoke', 'fcm-smoke-' . $suffix]);
    $productId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO users (display_name) VALUES (?)')->execute(['FCM smoke']);
    $userId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO subscriptions (user_id,product_id,branch_id) VALUES (?,?,?)')
        ->execute([$userId,$productId,$branchId]);
    $subscriptionId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO stock (branch_id,product_id,quantity) VALUES (?,?,?)')
        ->execute([$branchId,$productId,1]);
    $pdo->prepare('INSERT INTO user_push_tokens (user_id,token_hash,fcm_token) VALUES (?,?,?)')
        ->execute([$userId,hash('sha256',$suffix),'fcm-smoke-' . $suffix]);
    $tokenId = (int)$pdo->lastInsertId();
    $worker = new PushNotificationService();
    $event = 'smoke-' . $suffix;
    if ($worker->enqueueRestock($pdo,$productId,$branchId,$event) !== 1) {
        throw new RuntimeException('Expected one queued subscription.');
    }
    if ($worker->enqueueRestock($pdo,$productId,$branchId,$event) !== 0) {
        throw new RuntimeException('Duplicate event was queued.');
    }
    $stmt = $pdo->prepare('SELECT status FROM push_delivery_log WHERE subscription_id=? AND event_key=?');
    $stmt->execute([$subscriptionId,$event]);
    if ($stmt->fetchColumn() !== 'pending') {
        throw new RuntimeException('Pending outbox record missing.');
    }
    $hash = hash('sha256',$subscriptionId . ':' . $event . ':' . $tokenId);
    $pdo->prepare("INSERT INTO fcm_device_deliveries (event_hash,token_id,status) VALUES (?,?,'processing')")
        ->execute([$hash,$tokenId]);
    $stmt = $pdo->prepare('SELECT status FROM fcm_device_deliveries WHERE event_hash=?');
    $stmt->execute([$hash]);
    if ($stmt->fetchColumn() !== 'processing') {
        throw new RuntimeException('Device deduplication record missing.');
    }
    $pdo->rollBack();
    echo "FCM queue/schema smoke test passed (no network notification sent).\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR,'FCM queue smoke test failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
