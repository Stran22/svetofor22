<?php
declare(strict_types=1);

/**
 * Integration smoke test. Requires an isolated MySQL database with all migrations
 * (including 006) and at least one active branch.
 *
 * SVET_TEST_DSN='mysql:host=127.0.0.1;dbname=svet22_test;charset=utf8mb4'
 * SVET_TEST_USER='...' SVET_TEST_PASSWORD='...' php tests/backend/mysql_integration.php
 */
$dsn = getenv('SVET_TEST_DSN') ?: '';
$user = getenv('SVET_TEST_USER') ?: '';
$password = getenv('SVET_TEST_PASSWORD') ?: '';
if ($dsn === '' || !preg_match('/(?:^|;)dbname=([a-zA-Z0-9_]*_test)(?:;|$)/', $dsn)) {
    fwrite(STDERR, "Refusing to run: SVET_TEST_DSN must explicitly select a database ending in _test.\n");
    exit(2);
}

$db = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$database = (string) $db->query('SELECT DATABASE()')->fetchColumn();
if (!preg_match('/_test$/', $database)) {
    throw new RuntimeException('Integration tests may run only against a *_test database.');
}

$checks = 0;
function check(bool $condition, string $description): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $description);
    }
    $checks++;
    echo "PASS: {$description}\n";
}

$required = [
    'branches' => ['id', 'is_active'],
    'products' => ['id', 'barcode', 'article', 'name', 'manufacturer', 'category_id', 'unit'],
    'categories' => ['id', 'parent_id', 'name', 'slug'],
    'stock' => ['product_id', 'branch_id', 'price', 'quantity', 'imported_on'],
    'price_history' => ['product_id', 'branch_id', 'price', 'recorded_date'],
    'upload_log' => [
        'id', 'branch_id', 'admin_id', 'original_filename', 'file_sha256',
        'mapping_sha256', 'recorded_date', 'status', 'rows_failed',
    ],
    'import_errors' => ['upload_id', 'source_row_number', 'error_code', 'error_message', 'row_data'],
    'excel_column_mappings' => ['branch_id', 'column_mapping'],
];
$columnStmt = $db->prepare(
    'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
);
foreach ($required as $table => $columns) {
    $columnStmt->execute([$table]);
    $actual = $columnStmt->fetchAll(PDO::FETCH_COLUMN);
    check(count(array_diff($columns, $actual)) === 0, "required columns: {$table}");
}

$engine = $db->prepare(
    'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
);
foreach (['products', 'stock', 'upload_log', 'import_errors'] as $table) {
    $engine->execute([$table]);
    check(strcasecmp((string) $engine->fetchColumn(), 'InnoDB') === 0, "transactional table: {$table}");
}

$branchId = $db->query('SELECT id FROM branches WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
if ($branchId === false) {
    throw new RuntimeException('Seed at least one active branch in the isolated test database.');
}

$fileHash = hash('sha256', 'integration-file-' . random_bytes(16));
$mappingA = hash('sha256', '{"barcode":0,"name":1,"price":2,"quantity":3}');
$mappingB = hash('sha256', '{"barcode":1,"name":0,"price":2,"quantity":3}');
$date = '2099-01-01';
$lookup = $db->prepare(
    "SELECT id FROM upload_log
     WHERE branch_id = ? AND recorded_date = ? AND file_sha256 = ? AND mapping_sha256 = ?
       AND status = 'completed' AND rows_failed = 0 ORDER BY id DESC LIMIT 1"
);
$db->beginTransaction();
try {
    $insert = $db->prepare(
        "INSERT INTO upload_log
         (branch_id, admin_id, original_filename, file_sha256, mapping_sha256, recorded_date, status)
         VALUES (?, NULL, 'integration.xlsx', ?, ?, ?, 'processing')"
    );
    $insert->execute([$branchId, $fileHash, $mappingA, $date]);
    $id = (int) $db->lastInsertId();
    $lookup->execute([$branchId, $date, $fileHash, $mappingA]);
    check($lookup->fetchColumn() === false, 'processing import is not a duplicate');

    $db->prepare("UPDATE upload_log SET status = 'completed', rows_failed = 0 WHERE id = ?")
        ->execute([$id]);
    $lookup->execute([$branchId, $date, $fileHash, $mappingA]);
    check((int) $lookup->fetchColumn() === $id, 'same file and mapping is a duplicate');
    $lookup->execute([$branchId, $date, $fileHash, $mappingB]);
    check($lookup->fetchColumn() === false, 'changed mapping allows reimport');

    $db->exec('SAVEPOINT integration_row');
    $db->prepare("UPDATE upload_log SET status = 'failed' WHERE id = ?")->execute([$id]);
    $db->exec('ROLLBACK TO SAVEPOINT integration_row');
    $db->exec('RELEASE SAVEPOINT integration_row');
    $lookup->execute([$branchId, $date, $fileHash, $mappingA]);
    check((int) $lookup->fetchColumn() === $id, 'savepoint restores prior state');
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}
$lookup->execute([$branchId, $date, $fileHash, $mappingA]);
check($lookup->fetchColumn() === false, 'test transaction leaves no upload record');
echo "OK: {$checks} integration checks; database {$database}\n";
