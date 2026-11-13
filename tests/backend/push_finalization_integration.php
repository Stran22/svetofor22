<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Svet22\Services\PushDeliveryFinalizer;

$dsn = getenv('SVET_TEST_DSN') ?: '';
if (!preg_match('/(?:^|;)dbname=[a-zA-Z0-9_]*_test(?:;|$)/', $dsn)) {
    fwrite(STDERR, "Only a *_test database is allowed.\n");
    exit(2);
}
$db = new PDO($dsn, getenv('SVET_TEST_USER') ?: '', getenv('SVET_TEST_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
if (!preg_match('/_test$/', (string) $db->query('SELECT DATABASE()')->fetchColumn())) {
    throw new RuntimeException('Not a test database.');
}
$branchId = (int) $db->query('SELECT id FROM branches WHERE is_active = 1 LIMIT 1')->fetchColumn();
if (!$branchId) {
    throw new RuntimeException('Missing test branch.');
}
$db->beginTransaction();
try {
    $db->exec("INSERT INTO users (display_name, is_guest) VALUES ('Finalizer fixture', 0)");
    $userId = (int) $db->lastInsertId();
    $barcode = 'F' . bin2hex(random_bytes(12));
    $stmt = $db->prepare("INSERT INTO products (barcode, name) VALUES (?, 'Finalizer fixture')");
    $stmt->execute([$barcode]);
    $productId = (int) $db->lastInsertId();
    $stmt = $db->prepare("INSERT INTO subscriptions (user_id, product_id, branch_id, status) VALUES (?, ?, ?, 'active')");
    $stmt->execute([$userId, $productId, $branchId]);
    $subscriptionId = (int) $db->lastInsertId();
    $finalizer = new PushDeliveryFinalizer($db);

    foreach ([
        ['empty', [], 'processing', 'active'],
        ['sent', ['sent'], 'sent', 'notified'],
        ['partial', ['sent', 'failed'], 'sent', 'notified'],
        ['failed', ['failed', 'unknown'], 'failed', 'active'],
        ['pending', ['sent', 'pending'], 'processing', 'active'],
    ] as [$case, $statuses, $expectedEvent, $expectedSubscription]) {
        $db->prepare("UPDATE subscriptions SET status='active', notified_at=NULL, push_attempts=0 WHERE id=?")
            ->execute([$subscriptionId]);
        $stmt = $db->prepare(
            "INSERT INTO push_delivery_log (subscription_id, event_key, status) VALUES (?, ?, 'processing')"
        );
        $stmt->execute([$subscriptionId, 'test-' . $case . '-' . bin2hex(random_bytes(4))]);
        $deliveryId = (int) $db->lastInsertId();
        $insertDevice = $db->prepare(
            "INSERT INTO push_device_deliveries (delivery_id, token_id, status) VALUES (?, NULL, ?)"
        );
        foreach ($statuses as $status) {
            $insertDevice->execute([$deliveryId, $status]);
        }
        $finalizer->finalize($deliveryId, $subscriptionId);
        $event = $db->prepare('SELECT status FROM push_delivery_log WHERE id = ?');
        $event->execute([$deliveryId]);
        $subscription = $db->prepare('SELECT status FROM subscriptions WHERE id = ?');
        $subscription->execute([$subscriptionId]);
        if ($event->fetchColumn() !== $expectedEvent || $subscription->fetchColumn() !== $expectedSubscription) {
            throw new RuntimeException('FAIL finalization scenario: ' . $case);
        }
        echo "PASS finalization: {$case}\n";
        if ($case === 'partial') {
            $errorStmt = $db->prepare('SELECT last_error FROM push_delivery_log WHERE id = ?');
            $errorStmt->execute([$deliveryId]);
            if (!str_contains((string) $errorStmt->fetchColumn(), 'Some device deliveries failed')) {
                throw new RuntimeException('FAIL: partial delivery warning missing.');
            }
            echo "PASS finalization: partial failure is recorded\n";
        }
        if ($case === 'sent') {
            $finalizer->finalize($deliveryId, $subscriptionId);
            $subscription->execute([$subscriptionId]);
            if ($subscription->fetchColumn() !== 'notified') {
                throw new RuntimeException('FAIL: repeated success changed subscription.');
            }
            echo "PASS finalization: repeated success is safe\n";
        }
        if ($case === 'failed') {
            $attempts = $db->prepare('SELECT push_attempts FROM subscriptions WHERE id=?');
            $attempts->execute([$subscriptionId]);
            if ((int) $attempts->fetchColumn() !== 1) {
                throw new RuntimeException('FAIL: failed event must increment attempts exactly once.');
            }
            $finalizer->finalize($deliveryId, $subscriptionId);
            $attempts->execute([$subscriptionId]);
            if ((int) $attempts->fetchColumn() !== 1) {
                throw new RuntimeException('FAIL: finalizer is not idempotent.');
            }
            echo "PASS finalization: idempotent failed event\n";
        }
    }
} finally {
    $db->rollBack();
}
