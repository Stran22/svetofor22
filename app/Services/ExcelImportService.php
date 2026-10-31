<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use PDO;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

final class ExcelImportService
{
    private const MAX_FILE_BYTES = 5 * 1024 * 1024;

    public function importUploadedFile(int $branchId, array $file): array
    {
        if ($branchId < 1) throw new HttpException(422, 'BRANCH_ID_INVALID', 'Не выбран магазин.');
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpException(422, 'UPLOAD_FAILED', 'Файл не был загружен. Проверьте размер и повторите попытку.');
        }
        $tmpPath = (string)($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new HttpException(422, 'UPLOAD_INVALID', 'Не удалось проверить загруженный файл.');
        }
        $size = filesize($tmpPath);
        if ($size === false || $size < 1 || $size > self::MAX_FILE_BYTES) {
            throw new HttpException(422, 'FILE_SIZE_INVALID', 'Размер Excel-файла должен быть от 1 байта до 5 МБ.');
        }

        $originalName = basename(str_replace('\\\\', '/', (string)($file['name'] ?? 'import.xlsx')));
        $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName) ?: 'import.xlsx';
        if (mb_strlen($originalName) > 255 || strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new HttpException(422, 'FILE_FORMAT_INVALID', 'Поддерживаются только файлы Excel формата .xlsx.');
        }

        try {
            $this->validateXlsxPackage($tmpPath);
            if (IOFactory::identify($tmpPath) !== 'Xlsx') {
                throw new HttpException(422, 'FILE_FORMAT_INVALID', 'Содержимое файла не является корректной книгой Excel .xlsx.');
            }
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(false);
            $spreadsheet = $reader->load($tmpPath);
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new HttpException(422, 'XLSX_READ_FAILED', 'Не удалось прочитать Excel-файл. Проверьте, что файл не повреждён.');
        }

        try {
            $sheet = $spreadsheet->getSheet(0);
            $highestRow = $sheet->getHighestRow();
            $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestColumn());
            if ($highestRow > 100000 || $highestColumn > 100) {
                throw new HttpException(422, 'XLSX_DIMENSIONS_INVALID', 'В файле слишком много строк или столбцов для безопасного импорта.');
            }
            $header = $this->detectHeader($sheet, $highestRow, $highestColumn);
            if ($header === null) {
                throw new HttpException(
                    422,
                    'XLSX_HEADERS_NOT_RECOGNIZED',
                    'Не найдены обязательные столбцы: наименование, цена, количество и штрихкод либо артикул.'
                );
            }
            $fileHash = hash_file('sha256', $tmpPath);
            if (!is_string($fileHash)) throw new HttpException(500, 'FILE_HASH_FAILED', 'Не удалось вычислить контрольную сумму файла.');

            $branch = Database::connection()->prepare('SELECT id FROM branches WHERE id=:id AND is_active=1 LIMIT 1');
            $branch->execute(['id' => $branchId]);
            if (!$branch->fetchColumn()) throw new HttpException(404, 'BRANCH_NOT_FOUND', 'Магазин не найден или отключён.');

            $importId = $this->startImport($branchId, $originalName, $fileHash);
            if (is_array($importId)) return $importId;

            return $this->processRows($sheet, $header['row'], $header['map'], $highestRow, $highestColumn, $branchId, $importId);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function validateXlsxPackage(string $path): void
    {
        if (!class_exists(\\ZipArchive::class)) {
            throw new HttpException(503, 'ZIP_EXTENSION_UNAVAILABLE', 'На сервере не включено расширение PHP ZIP.');
        }
        $zip = new \\ZipArchive();
        if ($zip->open($path) !== true) {
            throw new HttpException(422, 'XLSX_PACKAGE_INVALID', 'Файл Excel повреждён или имеет неверный формат.');
        }
        try {
            if ($zip->numFiles > 2000) {
                throw new HttpException(422, 'XLSX_PACKAGE_TOO_LARGE', 'В Excel-файле слишком много внутренних элементов.');
            }
            $totalUncompressed = 0;
            $hasWorkbook = false;
            $hasContentTypes = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) continue;
                $totalUncompressed += (int)($stat['size'] ?? 0);
                if ($totalUncompressed > 50 * 1024 * 1024) {
                    throw new HttpException(422, 'XLSX_UNCOMPRESSED_TOO_LARGE', 'Распакованный объём Excel-файла превышает допустимые 50 МБ.');
                }
                $name = (string)($stat['name'] ?? '');
                if ($name === 'xl/workbook.xml') $hasWorkbook = true;
                if ($name === '[Content_Types].xml') $hasContentTypes = true;
            }
            if (!$hasWorkbook || !$hasContentTypes) {
                throw new HttpException(422, 'XLSX_PACKAGE_INVALID', 'Файл не содержит обязательную структуру книги Excel.');
            }
        } finally {
            $zip->close();
        }
    }

    private function detectHeader(object $sheet, int $highestRow, int $highestColumn): ?array
    {
        $lastRow = min($highestRow, 10);
        for ($row = 1; $row <= $lastRow; $row++) {
            $values = $this->readRow($sheet, $row, $highestColumn);
            $map = ImportHeaderMapper::map($values);
            if ($map !== null) return ['row' => $row, 'map' => $map];
        }
        return null;
    }

    private function readRow(object $sheet, int $rowNumber, int $highestColumn): array
    {
        $values = [];
        for ($column = 1; $column <= $highestColumn; $column++) {
            $coordinate = Coordinate::stringFromColumnIndex($column) . $rowNumber;
            $values[] = $sheet->getCell($coordinate)->getFormattedValue();
        }
        return $values;
    }

    private function startImport(int $branchId, string $filename, string $fileHash): int|array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $find = $pdo->prepare(
                'SELECT id,status,started_at,(started_at IS NULL OR started_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)) AS is_stale,rows_total,rows_created,rows_updated,rows_unchanged,rows_errors
                 FROM import_log WHERE branch_id=:branch_id AND file_sha256=:hash
                 LIMIT 1 FOR UPDATE'
            );
            $find->execute(['branch_id' => $branchId, 'hash' => $fileHash]);
            $existing = $find->fetch();
            if ($existing) {
                if ($existing['status'] === 'completed') {
                    $pdo->commit();
                    return [
                        'duplicate' => true,
                        'import_id' => (int)$existing['id'],
                        'status' => $existing['status'],
                        'rows_total' => (int)$existing['rows_total'],
                        'rows_created' => (int)$existing['rows_created'],
                        'rows_updated' => (int)$existing['rows_updated'],
                        'rows_unchanged' => (int)$existing['rows_unchanged'],
                        'rows_errors' => (int)$existing['rows_errors'],
                    ];
                }
                if ($existing['status'] === 'processing') {
                    if (!(bool)$existing['is_stale']) {
                        throw new HttpException(409, 'IMPORT_ALREADY_PROCESSING', 'Этот файл уже обрабатывается.');
                    }
                }
                $importId = (int)$existing['id'];
                $pdo->prepare('DELETE FROM import_errors WHERE import_id=:id')->execute(['id' => $importId]);
                $pdo->prepare(
                    "UPDATE import_log SET original_filename=:filename,status='processing',
                     rows_total=0,rows_created=0,rows_updated=0,rows_unchanged=0,rows_errors=0,
                     started_at=NOW(),finished_at=NULL WHERE id=:id"
                )->execute(['filename' => $filename, 'id' => $importId]);
            } else {
                $pdo->prepare(
                    "INSERT INTO import_log (branch_id,original_filename,file_sha256,status,started_at)
                     VALUES (:branch_id,:filename,:hash,'processing',NOW())"
                )->execute(['branch_id' => $branchId, 'filename' => $filename, 'hash' => $fileHash]);
                $importId = (int)$pdo->lastInsertId();
            }
            $pdo->commit();
            return $importId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private function processRows(
        object $sheet,
        int $headerRow,
        array $map,
        int $highestRow,
        int $highestColumn,
        int $branchId,
        int $importId
    ): array {
        $pdo = Database::connection();
        $counts = ['rows_total' => 0, 'rows_created' => 0, 'rows_updated' => 0, 'rows_unchanged' => 0, 'rows_errors' => 0];
        $restocks = [];
        $seenIdentifiers = [];

        $pdo->beginTransaction();
        try {
            for ($rowNumber = $headerRow + 1; $rowNumber <= $highestRow; $rowNumber++) {
                $row = $this->readRow($sheet, $rowNumber, $highestColumn);
                if ($this->isEmptyRow($row)) continue;
                $counts['rows_total']++;
                $pdo->exec('SAVEPOINT import_row');
                try {
                    $record = $this->parseRow($row, $map);
                    $identityKeys = [];
                    if ($record['barcode'] !== null) $identityKeys[] = 'barcode:' . $record['barcode'];
                    if ($record['sku'] !== null) $identityKeys[] = 'sku:' . $record['sku'];
                    foreach ($identityKeys as $identityKey) {
                        if (isset($seenIdentifiers[$identityKey])) {
                            throw new \\RuntimeException('Этот штрихкод или артикул повторяется в файле.');
                        }
                    }
                    foreach ($identityKeys as $identityKey) $seenIdentifiers[$identityKey] = true;
                    $outcome = $this->upsertProductAndStock($pdo, $record, $branchId, $importId);
                    if ($outcome['created']) $counts['rows_created']++;
                    elseif ($outcome['changed']) $counts['rows_updated']++;
                    else $counts['rows_unchanged']++;
                    if ($outcome['restocked']) {
                        $restocks[$outcome['product_id']] = true;
                    }
                    $pdo->exec('RELEASE SAVEPOINT import_row');
                } catch (Throwable $rowError) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT import_row');
                    $pdo->exec('RELEASE SAVEPOINT import_row');
                    $counts['rows_errors']++;
                    $errorCode = $rowError instanceof \PDOException ? 'ROW_DATABASE_ERROR' : 'ROW_DATA_INVALID';
                    $pdo->prepare(
                        'INSERT INTO import_errors (import_id,row_number,error_code,message,row_data)
                         VALUES (:import_id,:row_number,:error_code,:message,:row_data)'
                    )->execute([
                        'import_id' => $importId,
                        'row_number' => $rowNumber,
                        'error_code' => $errorCode,
                        'message' => mb_substr($rowError->getMessage(), 0, 500),
                        'row_data' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);
                }
            }

            if ($counts['rows_total'] === 0) {
                throw new HttpException(422, 'XLSX_NO_DATA_ROWS', 'В файле нет строк с товарами после заголовков.');
            }
            $status = $counts['rows_errors'] > 0 ? 'completed_with_errors' : 'completed';
            $pdo->prepare(
                'UPDATE import_log SET status=:status,rows_total=:total,rows_created=:created,
                 rows_updated=:updated,rows_unchanged=:unchanged,rows_errors=:errors,finished_at=NOW()
                 WHERE id=:id'
            )->execute([
                'status' => $status,
                'total' => $counts['rows_total'],
                'created' => $counts['rows_created'],
                'updated' => $counts['rows_updated'],
                'unchanged' => $counts['rows_unchanged'],
                'errors' => $counts['rows_errors'],
                'id' => $importId,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->prepare("UPDATE import_log SET status='failed',finished_at=NOW() WHERE id=:id")
                ->execute(['id' => $importId]);
            throw $e;
        }

        $push = new PushNotificationService();
        foreach (array_keys($restocks) as $productId) {
            $finalStock = $pdo->prepare('SELECT quantity FROM stock WHERE product_id=:product_id AND branch_id=:branch_id LIMIT 1');
            $finalStock->execute(['product_id' => $productId, 'branch_id' => $branchId]);
            if ((float)$finalStock->fetchColumn() <= 0) continue;
            $eventKey = 'restock:import:' . $importId . ':branch:' . $branchId . ':product:' . $productId;
            try {
                $push->notifyRestock((int)$productId, $branchId, $eventKey);
            } catch (Throwable $e) {
                error_log('Restock notification scheduling failed: ' . $e->getMessage());
            }
        }

        return ['duplicate' => false, 'import_id' => $importId, 'status' => $status] + $counts;
    }

    private function parseRow(array $row, array $map): array
    {
        $value = static function (string $field) use ($row, $map): string {
            return isset($map[$field]) ? trim((string)($row[$map[$field]] ?? '')) : '';
        };
        $name = $value('name');
        $barcode = preg_replace('/\s+/u', '', $value('barcode')) ?? '';
        $sku = $value('sku');
        if ($name === '' || ($barcode === '' && $sku === '')) {
            throw new \RuntimeException('Не заполнено наименование или отсутствуют штрихкод и артикул.');
        }
        if ($barcode !== '' && !preg_match('/^\d{1,64}$/', $barcode)) {
            throw new \RuntimeException('Штрихкод должен содержать только цифры. В Excel задавайте штрихкод как текст, чтобы не терялись ведущие нули.');
        }
        if (mb_strlen($name) > 255 || strlen($barcode) > 64 || strlen($sku) > 100) {
            throw new \RuntimeException('Наименование, штрихкод или артикул превышают допустимую длину.');
        }
        $price = ImportHeaderMapper::parseDecimal($value('price'), 2);
        $quantity = ImportHeaderMapper::parseDecimal($value('quantity'), 3);
        $unit = $value('unit');
        if ($unit === '') $unit = 'шт.';
        if (mb_strlen($unit) > 30) throw new \RuntimeException('Слишком длинное обозначение единицы измерения.');
        return ['name' => $name, 'barcode' => $barcode !== '' ? $barcode : null, 'sku' => $sku !== '' ? $sku : null,
            'price' => $price, 'quantity' => $quantity, 'unit' => $unit];
    }

    private function upsertProductAndStock(PDO $pdo, array $record, int $branchId, int $importId): array
    {
        $product = false;
        if ($record['barcode'] !== null) {
            $find = $pdo->prepare('SELECT id,name,sku,barcode,unit FROM products WHERE barcode=:identifier LIMIT 1 FOR UPDATE');
            $find->execute(['identifier' => $record['barcode']]);
            $product = $find->fetch();
        } elseif ($record['sku'] !== null) {
            $find = $pdo->prepare('SELECT id,name,sku,barcode,unit FROM products WHERE sku=:identifier LIMIT 1 FOR UPDATE');
            $find->execute(['identifier' => $record['sku']]);
            $product = $find->fetch();
        }

        $created = false;
        $productChanged = false;
        if (!$product) {
            $identifier = $record['barcode'] ?? $record['sku'];
            $slugBase = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $record['name']);
            $slugBase = strtolower((string)$slugBase);
            $slugBase = trim(preg_replace('/[^a-z0-9]+/', '-', $slugBase) ?? '', '-');
            if ($slugBase === '') $slugBase = 'product';
            $slug = substr($slugBase, 0, 220) . '-' . substr(hash('sha256', (string)$identifier), 0, 8);
            $insert = $pdo->prepare(
                'INSERT INTO products (name,slug,sku,barcode,unit)
                 VALUES (:name,:slug,:sku,:barcode,:unit)'
            );
            $insert->execute([
                'name' => $record['name'], 'slug' => $slug, 'sku' => $record['sku'],
                'barcode' => $record['barcode'], 'unit' => $record['unit'] ?? 'шт.',
            ]);
            $productId = (int)$pdo->lastInsertId();
            $created = true;
        } else {
            $productId = (int)$product['id'];
            if ($product['sku'] !== null && $record['sku'] !== null && $product['sku'] !== $record['sku']) {
                throw new \RuntimeException('Артикул не совпадает с уже сохранённым для этого штрихкода.');
            }
            if ($product['barcode'] !== null && $record['barcode'] !== null && $product['barcode'] !== $record['barcode']) {
                throw new \RuntimeException('Штрихкод не совпадает с уже сохранённым для этого артикула.');
            }
            if ($record['unit'] !== null && $product['unit'] !== $record['unit']) {
                throw new \\RuntimeException('Единица измерения отличается от уже сохранённой для этого товара.');
            }
            $sku = $product['sku'] ?? $record['sku'];
            $barcode = $product['barcode'] ?? $record['barcode'];
            $productChanged = $product['sku'] !== $sku || $product['barcode'] !== $barcode;
            if ($productChanged) {
                $pdo->prepare('UPDATE products SET sku=:sku,barcode=:barcode WHERE id=:id')
                    ->execute(['sku' => $sku, 'barcode' => $barcode, 'id' => $productId]);
            }
        }

        $stock = $pdo->prepare(
            'SELECT id,price,quantity,available_since FROM stock
             WHERE branch_id=:branch_id AND product_id=:product_id LIMIT 1 FOR UPDATE'
        );
        $stock->execute(['branch_id' => $branchId, 'product_id' => $productId]);
        $existingStock = $stock->fetch();
        $newQuantity = $record['quantity'];
        $newPrice = $record['price'];
        $restocked = false;

        if (!$existingStock) {
            $pdo->prepare(
                "INSERT INTO stock (branch_id,product_id,price,quantity,available_since,last_import_id)
                 VALUES (:branch_id,:product_id,:price,:quantity,IF(:is_available=1,NOW(),NULL),:import_id)"
            )->execute([
                'branch_id' => $branchId, 'product_id' => $productId, 'price' => $newPrice,
                'quantity' => $newQuantity, 'is_available' => (float)$newQuantity > 0 ? 1 : 0,
                'import_id' => $importId,
            ]);
            $priceChanged = true;
            $restocked = (float)$newQuantity > 0;
            $stockChanged = true;
        } else {
            $oldQuantity = (string)$existingStock['quantity'];
            $oldPrice = $existingStock['price'] === null ? null : (string)$existingStock['price'];
            $priceChanged = $oldPrice === null || $oldPrice !== $newPrice;
            $quantityChanged = $oldQuantity !== $newQuantity;
            $restocked = (float)$oldQuantity <= 0 && (float)$newQuantity > 0;
            $stockChanged = $priceChanged || $quantityChanged;
            if ($stockChanged || $restocked || ((float)$newQuantity <= 0 && $existingStock['available_since'] !== null)) {
                if ($restocked) {
                    $pdo->prepare(
                        'UPDATE stock SET price=:price,quantity=:quantity,available_since=NOW(),last_import_id=:import_id WHERE id=:id'
                    )->execute(['price' => $newPrice, 'quantity' => $newQuantity, 'import_id' => $importId, 'id' => $existingStock['id']]);
                } elseif ((float)$newQuantity <= 0) {
                    $pdo->prepare(
                        'UPDATE stock SET price=:price,quantity=:quantity,available_since=NULL,last_import_id=:import_id WHERE id=:id'
                    )->execute(['price' => $newPrice, 'quantity' => $newQuantity, 'import_id' => $importId, 'id' => $existingStock['id']]);
                } else {
                    $pdo->prepare(
                        'UPDATE stock SET price=:price,quantity=:quantity,last_import_id=:import_id WHERE id=:id'
                    )->execute(['price' => $newPrice, 'quantity' => $newQuantity, 'import_id' => $importId, 'id' => $existingStock['id']]);
                }
            } else {
                $pdo->prepare('UPDATE stock SET last_import_id=:import_id WHERE id=:id')
                    ->execute(['import_id' => $importId, 'id' => $existingStock['id']]);
            }
        }

        if ($priceChanged) {
            $pdo->prepare(
                "INSERT INTO price_history (branch_id,product_id,price,recorded_at,source)
                 VALUES (:branch_id,:product_id,:price,NOW(),'import')"
            )->execute(['branch_id' => $branchId, 'product_id' => $productId, 'price' => $newPrice]);
        }

        return [
            'product_id' => $productId,
            'created' => $created,
            'changed' => $productChanged || $stockChanged,
            'restocked' => $restocked,
        ];
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string)$value) !== '') return false;
        }
        return true;
    }
}