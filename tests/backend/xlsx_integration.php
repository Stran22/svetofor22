<?php
declare(strict_types=1);

/**
 * End-to-end XLSX import against an isolated *_test MySQL database.
 * Requires composer install, migrations 001-006 and one active branch.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Svet22\Services\XlsxImportService;

$dsn = getenv('SVET_TEST_DSN') ?: '';
if (!preg_match('/(?:^|;)dbname=[a-zA-Z0-9_]*_test(?:;|$)/', $dsn)) {
    fwrite(STDERR, "Refusing to run outside an explicitly named *_test database.\n");
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
$branchId = $db->query('SELECT id FROM branches WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
if (!$branchId) {
    throw new RuntimeException('Seed one active test branch.');
}

$barcode = 'T' . bin2hex(random_bytes(12));
$article = 'TEST-' . bin2hex(random_bytes(8));
$files = [];
$uploadIds = [];
$productId = null;
$mapping = ['barcode' => 0, 'article' => 1, 'name' => 2, 'price' => 3, 'quantity' => 4];
$service = new XlsxImportService($db);
$date = new DateTimeImmutable('2099-01-02 12:00:00', new DateTimeZone('Asia/Barnaul'));

function assertImport(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "PASS: {$message}\n";
}
function makeXlsx(array $row): string
{
    $sheet = new Spreadsheet();
    $tab = $sheet->getActiveSheet();
    $tab->fromArray([['Barcode', 'Article', 'Name', 'Price', 'Quantity'], $row]);
    $path = tempnam(sys_get_temp_dir(), 'svet22_xlsx_');
    if ($path === false) {
        throw new RuntimeException('Cannot create temporary XLSX path.');
    }
    (new Xlsx($sheet))->save($path);
    $sheet->disconnectWorksheets();
    return $path;
}
try {
    $firstFile = makeXlsx([$barcode, $article, 'Integration product', '15.50', '0']);
    $files[] = $firstFile;
    $first = $service->import($firstFile, 'fixture.xlsx', (int) $branchId, null, $date, $mapping);
    $uploadIds[] = (int) $first['upload_id'];
    assertImport(($first['rows_created'] ?? 0) === 1 && ($first['rows_failed'] ?? 0) === 0, 'first import creates stock');
    $stmt = $db->prepare('SELECT id FROM products WHERE barcode = ?');
    $stmt->execute([$barcode]);
    $productId = (int) $stmt->fetchColumn();
    assertImport($productId > 0, 'product was created');

    $again = $service->import($firstFile, 'renamed.xlsx', (int) $branchId, null, $date, $mapping);
    assertImport(($again['duplicate'] ?? false) === true, 'identical file and mapping is skipped');

    $secondFile = makeXlsx([$barcode, $article, 'Integration product', '17.25', '4']);
    $files[] = $secondFile;
    $second = $service->import($secondFile, 'updated.xlsx', (int) $branchId, null, $date, $mapping);
    $uploadIds[] = (int) $second['upload_id'];
    assertImport(($second['rows_updated'] ?? 0) === 1, 'new price and quantity update stock');
    $stock = $db->prepare('SELECT price, quantity FROM stock WHERE product_id = ? AND branch_id = ?');
    $stock->execute([$productId, $branchId]);
    $values = $stock->fetch();
    assertImport((float) $values['price'] === 17.25 && (float) $values['quantity'] === 4.0, 'stored stock values match XLSX');
    $history = $db->prepare('SELECT price FROM price_history WHERE product_id = ? AND branch_id = ? AND recorded_date = ?');
    $history->execute([$productId, $branchId, $date->format('Y-m-d')]);
    assertImport((float) $history->fetchColumn() === 17.25, 'daily price history is updated');

    // Same file bytes but a different valid mapping must not be treated as duplicate.
    $changedMapping = ['barcode' => 1, 'article' => 0, 'name' => 2, 'price' => 3, 'quantity' => 4];
    $remapped = $service->import($firstFile, 'remapped.xlsx', (int) $branchId, null, $date, $changedMapping);
    $uploadIds[] = (int) $remapped['upload_id'];
    assertImport(($remapped['duplicate'] ?? true) === false, 'changed mapping bypasses duplicate detection');
    assertImport(($remapped['rows_failed'] ?? 0) === 0, 'remapped file processes without validation failures');
} finally {
    // Imports commit internally; remove only uniquely generated test records.
    if ($productId !== null && $productId > 0) {
        $db->prepare('DELETE FROM products WHERE id = ? AND barcode = ?')->execute([$productId, $barcode]);
    }
    foreach ($uploadIds as $id) {
        $db->prepare('DELETE FROM upload_log WHERE id = ? AND branch_id = ?')->execute([$id, $branchId]);
    }
    foreach ($files as $path) {
        @unlink($path);
    }
}
echo "XLSX integration checks finished.\n";
