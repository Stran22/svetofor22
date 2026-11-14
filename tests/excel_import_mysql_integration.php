<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Svet22\Services\XlsxImportService;

function ensure(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function scalar(PDO $db, string $sql): string {
    return (string) $db->query($sql)->fetchColumn();
}
function xlsx(array $rows): string {
    $path = tempnam(sys_get_temp_dir(), 'svet_excel_');
    if ($path === false) { throw new RuntimeException('Cannot create temp file'); }
    $file = $path . '.xlsx';
    rename($path, $file);
    $book = new Spreadsheet();
    $book->getActiveSheet()->fromArray($rows, null, 'A1');
    (new Xlsx($book))->save($file);
    $book->disconnectWorksheets();
    return $file;
}

$db = new PDO(
    getenv('TEST_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;dbname=svet_import_test;charset=utf8mb4',
    getenv('TEST_MYSQL_USER') ?: 'root',
    getenv('TEST_MYSQL_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$db->exec("INSERT INTO branches(code,name) VALUES('test-a','Тестовый филиал А'),('test-b','Тестовый филиал Б')");
$db->exec("INSERT INTO products(barcode,article,name) VALUES('460000000001','TEST-1','Тестовый товар')");
$db->exec("INSERT INTO stock(product_id,branch_id,price,quantity) VALUES(1,1,100,0)");
$db->exec("INSERT INTO users(device_id) VALUES('12345678-1234-4234-8234-123456789abc')");
$db->exec("INSERT INTO subscriptions(user_id,product_id,branch_id,status) VALUES(1,1,1,'active')");

$service = new XlsxImportService($db);
$files = [];
try {
    $files[] = xlsx([
        ['Штрихкод','Артикул','Название','Цена','Остаток'],
        ['460000000001','TEST-1','Тестовый товар','110,50','3'],
    ]);
    $first = $files[0];
    $preview = $service->previewImport($first, 'stock.xlsx', 1);
    ensure($preview['rows_valid'] === 1 && $preview['rows_failed'] === 0, 'Preview must accept valid row');
    ensure(scalar($db, 'SELECT quantity FROM stock WHERE product_id=1 AND branch_id=1') === '0.000', 'Preview changed stock');
    ensure(scalar($db, 'SELECT COUNT(*) FROM push_delivery_log') === '0', 'Preview queued push');
    ensure(scalar($db, 'SELECT COUNT(*) FROM upload_log') === '0', 'Preview wrote upload log');

    $import = $service->import($first, 'stock.xlsx', 1);
    ensure($import['status'] === 'completed' && $import['rows_updated'] === 1, 'Import did not update stock');
    ensure(scalar($db, 'SELECT quantity FROM stock WHERE product_id=1 AND branch_id=1') === '3.000', 'Stock mismatch');
    ensure(scalar($db, 'SELECT price FROM stock WHERE product_id=1 AND branch_id=1') === '110.50', 'Price mismatch');
    ensure(scalar($db, 'SELECT COUNT(*) FROM price_history') === '1', 'Price history missing');
    ensure(scalar($db, 'SELECT COUNT(*) FROM push_delivery_log') === '1', 'Restock notification not queued');

    $again = $service->import($first, 'stock.xlsx', 1);
    ensure(($again['duplicate'] ?? false) === true, 'Duplicate import not blocked');
    ensure(scalar($db, 'SELECT COUNT(*) FROM push_delivery_log') === '1', 'Duplicate queued another push');

    $files[] = xlsx([
        ['Штрихкод','Артикул','Название','Цена','Остаток'],
        ['460000000002','TEST-2','Новый товар','75','2,5'],
    ]);
    $other = $service->import($files[1], 'other.xlsx', 2);
    ensure($other['status'] === 'completed', 'Other branch import failed');
    ensure(scalar($db, 'SELECT COUNT(*) FROM stock WHERE branch_id=2') === '1', 'Other branch stock missing');
    ensure(scalar($db, 'SELECT quantity FROM stock WHERE product_id=1 AND branch_id=1') === '3.000', 'Other branch changed first branch');

    $db->exec("INSERT INTO products(barcode,article,name) VALUES('460000000003','TEST-3','Другой товар')");
    $files[] = xlsx([
        ['Штрихкод','Артикул','Название','Цена','Остаток'],
        ['460000000003','TEST-1','Конфликт','99','4'],
    ]);
    $conflict = $service->previewImport($files[2], 'conflict.xlsx', 1);
    ensure($conflict['rows_failed'] === 1 && $conflict['rows_valid'] === 0, 'Identity conflict not detected');
    echo "MySQL Excel import integration tests passed\n";
} finally {
    foreach ($files as $file) { @unlink($file); }
}
