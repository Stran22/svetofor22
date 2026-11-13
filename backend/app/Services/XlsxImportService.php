<?php
declare(strict_types=1);

namespace Svet22\Services;

use DateTimeImmutable;
use PDO;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

final class XlsxImportService
{
    private const MAX_FILE_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private PDO $db,
        private ImportRowValidator $validator = new ImportRowValidator()
    ) {}

    public function import(
        string $filePath,
        string $originalName,
        int $branchId,
        ?int $adminId = null,
        ?DateTimeImmutable $date = null,
        ?array $mapping = null
    ): array {
        $date ??= new DateTimeImmutable('now', new \DateTimeZone('Asia/Barnaul'));
        $recordedDate = $date->format('Y-m-d');
        $this->validateUpload($filePath, $originalName, $branchId);

        $branch = $this->db->prepare('SELECT id FROM branches WHERE id = ? AND is_active = 1');
        $branch->execute([$branchId]);
        if (!$branch->fetchColumn()) {
            throw new \InvalidArgumentException('Выбранный филиал не найден или отключён.');
        }

        $hash = hash_file('sha256', $filePath);
        if (!is_string($hash)) {
            throw new \RuntimeException('Не удалось вычислить контрольную сумму файла.');
        }

        // The same bytes can represent different data after the column mapping changes.
        // Resolve and validate the mapping before checking whether this import is a duplicate.
        $spreadsheet = IOFactory::load($filePath);
        try {
            $sheet = $spreadsheet->getActiveSheet();
            $highestColumn = $sheet->getHighestDataColumn();
            $columnCount = Coordinate::columnIndexFromString($highestColumn);
            $savedMapping = $mapping === null ? $this->loadMapping($branchId) : null;
            $columns = $mapping !== null
                ? $this->validator->validateMapping($mapping, $columnCount)
                : ($savedMapping !== null
                    ? $this->validator->validateMapping($savedMapping, $columnCount)
                    : $this->validator->normalizeHeaders($this->readRow($sheet, 1, $highestColumn)));
        } catch (Throwable $e) {
            $spreadsheet->disconnectWorksheets();
            throw $e;
        }

        // Store a SHA-256 fingerprint of the file together with its effective mapping.
        // Canonical field ordering keeps the fingerprint stable across equivalent mappings.
        ksort($columns);
        $importFingerprint = hash('sha256', $hash . ':' . json_encode($columns, JSON_THROW_ON_ERROR));
        $duplicate = $this->db->prepare(
            "SELECT id FROM upload_log
             WHERE branch_id = ? AND recorded_date = ? AND file_sha256 = ? AND status = 'completed' AND rows_failed = 0
             ORDER BY id DESC LIMIT 1"
        );
        try {
            $duplicate->execute([$branchId, $recordedDate, $importFingerprint]);
            $previous = $duplicate->fetchColumn();
            if ($previous !== false) {
                $spreadsheet->disconnectWorksheets();
                return ['upload_id' => (int) $previous, 'status' => 'already_imported', 'duplicate' => true];
            }

            $log = $this->db->prepare(
                "INSERT INTO upload_log
                 (branch_id, admin_id, original_filename, file_sha256, recorded_date, status)
                 VALUES (?, ?, ?, ?, ?, 'processing')"
            );
            $log->execute([$branchId, $adminId, mb_substr(basename($originalName), 0, 255), $importFingerprint, $recordedDate]);
            $uploadId = (int) $this->db->lastInsertId();
        } catch (Throwable $e) {
            $spreadsheet->disconnectWorksheets();
            throw $e;
        }

        $stats = [
            'rows_total' => 0,
            'rows_created' => 0,
            'rows_updated' => 0,
            'rows_skipped' => 0,
            'rows_failed' => 0,
        ];

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestDataRow();
            $seen = [];

            $this->db->beginTransaction();
            for ($line = 2; $line <= $highestRow; $line++) {
                $cells = $this->readRow($sheet, $line, $highestColumn);
                foreach (['barcode', 'article'] as $identifierField) {
                    if (isset($columns[$identifierField])) {
                        $cells[$columns[$identifierField]] = (string) $sheet
                            ->getCell(Coordinate::stringFromColumnIndex($columns[$identifierField] + 1) . $line)
                            ->getFormattedValue();
                    }
                }
                if ($this->isEmpty($cells)) {
                    continue;
                }

                $stats['rows_total']++;
                try {
                    $row = $this->validator->validate($cells, $columns);
                    $keys = $this->identityKeys($row);
                    foreach ($keys as $key) {
                        if (isset($seen[$key])) {
                            throw new \InvalidArgumentException('Повтор идентификатора товара в этом файле.');
                        }
                    }
                    // A failed row must not leave partially written products or stock.
                    $this->db->exec('SAVEPOINT import_row');
                    try {
                        $outcome = $this->upsertRow($row, $branchId, $recordedDate);
                        $this->db->exec('RELEASE SAVEPOINT import_row');
                    } catch (Throwable $e) {
                        $this->db->exec('ROLLBACK TO SAVEPOINT import_row');
                        $this->db->exec('RELEASE SAVEPOINT import_row');
                        throw $e;
                    }
                    // Reserve identifiers only after the row has been saved.
                    foreach ($keys as $key) {
                        $seen[$key] = true;
                    }
                    if ($outcome === 'created') {
                        $stats['rows_created']++;
                    } elseif ($outcome === 'updated') {
                        $stats['rows_updated']++;
                    } else {
                        $stats['rows_skipped']++;
                    }
                } catch (\InvalidArgumentException $e) {
                    $stats['rows_failed']++;
                    $this->saveRowError($uploadId, $line, 'ROW_VALIDATION', $e->getMessage(), $cells);
                }
            }

            $this->db->commit();
            $spreadsheet->disconnectWorksheets();

            $finish = $this->db->prepare(
                "UPDATE upload_log SET status = 'completed', rows_total = ?, rows_created = ?,
                 rows_updated = ?, rows_skipped = ?, rows_failed = ?, finished_at = NOW() WHERE id = ?"
            );
            $finish->execute([...array_values($stats), $uploadId]);

            return ['upload_id' => $uploadId, 'status' => 'completed', 'duplicate' => false] + $stats;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $fail = $this->db->prepare(
                "UPDATE upload_log SET status = 'failed', error_text = ?, finished_at = NOW() WHERE id = ?"
            );
            $fail->execute([mb_substr($e->getMessage(), 0, 60000), $uploadId]);
            throw $e;
        }
    }

    /**
     * Read the first row and a few sample rows without changing the database.
     */
    public function previewColumns(string $filePath, string $originalName, int $branchId): array
    {
        $this->validateUpload($filePath, $originalName, $branchId);
        $branch = $this->db->prepare('SELECT id FROM branches WHERE id = ? AND is_active = 1');
        $branch->execute([$branchId]);
        if (!$branch->fetchColumn()) {
            throw new \InvalidArgumentException('Выбранный филиал не найден или отключён.');
        }

        $spreadsheet = IOFactory::load($filePath);
        try {
            $sheet = $spreadsheet->getActiveSheet();
            $highestColumn = $sheet->getHighestDataColumn();
            $header = $this->readRow($sheet, 1, $highestColumn);
            $samples = [];
            $lastRow = min($sheet->getHighestDataRow(), 6);
            for ($row = 2; $row <= $lastRow; $row++) {
                $samples[] = $this->readRow($sheet, $row, $highestColumn);
            }
            return [
                'headers' => array_map(static fn($value): string => trim((string) $value), $header),
                'samples' => $samples,
                'column_count' => count($header),
            ];
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function saveMapping(int $branchId, array $mapping, int $columnCount): array
    {
        $branch = $this->db->prepare('SELECT id FROM branches WHERE id = ? AND is_active = 1 LIMIT 1');
        $branch->execute([$branchId]);
        if (!$branch->fetchColumn()) {
            throw new \InvalidArgumentException('Нельзя настроить колонки для отсутствующего или отключённого филиала.');
        }

        $validated = $this->validator->validateMapping($mapping, $columnCount);
        $json = json_encode($validated, JSON_THROW_ON_ERROR);
        $stmt = $this->db->prepare(
            'INSERT INTO excel_column_mappings (branch_id, column_mapping)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE column_mapping = VALUES(column_mapping)'
        );
        $stmt->execute([$branchId, $json]);
        return $validated;
    }

    public function loadMapping(int $branchId): ?array
    {
        $stmt = $this->db->prepare('SELECT column_mapping FROM excel_column_mappings WHERE branch_id = ? LIMIT 1');
        $stmt->execute([$branchId]);
        $json = $stmt->fetchColumn();
        if (!is_string($json) || $json === '') {
            return null;
        }
        $mapping = json_decode($json, true);
        return is_array($mapping) ? $mapping : null;
    }

    private function validateUpload(string $path, string $name, int $branchId): void
    {
        if ($branchId < 1) {
            throw new \InvalidArgumentException('Не выбран филиал.');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('Временный файл загрузки недоступен.');
        }
        $size = filesize($path);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            throw new \InvalidArgumentException('Размер XLSX-файла не должен превышать 5 МБ.');
        }
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new \InvalidArgumentException('Поддерживаются только файлы .xlsx.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/x-zip',
            'application/octet-stream',
        ], true)) {
            throw new \InvalidArgumentException('Файл не распознан как XLSX.');
        }
    }

    private function upsertRow(array $row, int $branchId, string $date): string
    {
        $byArticle = null;
        if ($row['article'] !== null) {
            $stmt = $this->db->prepare(
                'SELECT * FROM products WHERE article = ? AND manufacturer <=> ? LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$row['article'], $row['manufacturer']]);
            $byArticle = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $byBarcode = null;
        if ($row['barcode'] !== null) {
            $stmt = $this->db->prepare('SELECT * FROM products WHERE barcode = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$row['barcode']]);
            $byBarcode = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($byArticle && $byBarcode && (int) $byArticle['id'] !== (int) $byBarcode['id']) {
            throw new \InvalidArgumentException('Артикул и штрихкод относятся к разным товарам.');
        }

        $product = $byArticle ?? $byBarcode;
        $categoryId = $row['category'] === null ? null : $this->categoryId($row['category']);
        $productChanged = false;

        if (!$product) {
            $insert = $this->db->prepare(
                'INSERT INTO products (barcode, article, name, category_id, manufacturer, unit)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $row['barcode'], $row['article'], $row['name'],
                $categoryId, $row['manufacturer'], $row['unit'],
            ]);
            $productId = (int) $this->db->lastInsertId();
        } else {
            $productId = (int) $product['id'];
            $changes = [];
            foreach (['barcode', 'article', 'name', 'category_id', 'manufacturer', 'unit'] as $field) {
                $new = $field === 'category_id' ? $categoryId : $row[$field];
                // Пустые необязательные значения не стирают ранее заполненные сведения.
                if ($field !== 'name' && $new === null) {
                    continue;
                }
                $old = $product[$field] ?? null;
                if ((string) ($old ?? '') !== (string) ($new ?? '')) {
                    $changes[$field] = $new;
                }
            }
            if ($changes) {
                $sets = implode(', ', array_map(
                    static fn(string $field): string => $field . ' = ?',
                    array_keys($changes)
                ));
                $update = $this->db->prepare('UPDATE products SET ' . $sets . ' WHERE id = ?');
                $update->execute([...array_values($changes), $productId]);
                $productChanged = true;
            }
        }

        $stockStmt = $this->db->prepare(
            'SELECT price, quantity, imported_on FROM stock
             WHERE product_id = ? AND branch_id = ? FOR UPDATE'
        );
        $stockStmt->execute([$productId, $branchId]);
        $stock = $stockStmt->fetch(PDO::FETCH_ASSOC);
        if (!$stock) {
            $insertStock = $this->db->prepare(
                'INSERT INTO stock (product_id, branch_id, price, quantity, imported_on) VALUES (?, ?, ?, ?, ?)'
            );
            $insertStock->execute([$productId, $branchId, $row['price'], $row['quantity'], $date]);

            // Новый филиальный остаток считается добавлением в филиал,
            // даже если общая карточка товара уже существовала в другом филиале.
            $history = $this->db->prepare(
                'INSERT INTO price_history (product_id, branch_id, price, recorded_date)
                 VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE price = VALUES(price)'
            );
            $history->execute([$productId, $branchId, $row['price'], $date]);

            return 'created';
        }

        $priceChanged = number_format((float) $stock['price'], 2, '.', '') !== $row['price'];
        $quantityChanged = number_format((float) $stock['quantity'], 3, '.', '') !== $row['quantity'];
        $dateChanged = (string) ($stock['imported_on'] ?? '') !== $date;

        // Дата импорта обновляется для контроля свежести остатков, но сама по себе
        // не означает, что товар изменился, и не увеличивает счётчик «Изменено».
        if ($priceChanged || $quantityChanged || $dateChanged) {
            $update = $this->db->prepare(
                'UPDATE stock SET price = ?, quantity = ?, imported_on = ?
                 WHERE product_id = ? AND branch_id = ?'
            );
            $update->execute([$row['price'], $row['quantity'], $date, $productId, $branchId]);
        }

        // Ставим уведомления в очередь в той же транзакции, что и изменение остатка.
        // Отправка Firebase выполняется отдельно, после успешного COMMIT.
        if ((float) $stock['quantity'] <= 0 && (float) $row['quantity'] > 0) {
            (new RestockNotificationService($this->db))->queueTransition(
                $productId,
                $branchId,
                (string) $stock['quantity'],
                (string) $row['quantity']
            );
        }

        // Изменение только остатка не создаёт запись истории цены.
        if ($priceChanged) {
            $history = $this->db->prepare(
                'INSERT INTO price_history (product_id, branch_id, price, recorded_date)
                 VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE price = VALUES(price)'
            );
            $history->execute([$productId, $branchId, $row['price'], $date]);
        }

        return ($productChanged || $priceChanged || $quantityChanged) ? 'updated' : 'unchanged';
    }

    private function categoryId(string $name): int
    {
        $find = $this->db->prepare(
            'SELECT id FROM categories WHERE parent_id IS NULL AND name = ? LIMIT 1'
        );
        $find->execute([$name]);
        $id = $find->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $slug = $this->slug($name);
        $check = $this->db->prepare(
            'SELECT id FROM categories WHERE parent_id IS NULL AND slug = ? LIMIT 1'
        );
        $check->execute([$slug]);
        if ($check->fetchColumn()) {
            $slug .= '-' . substr(hash('sha256', $name), 0, 8);
        }

        $insert = $this->db->prepare(
            'INSERT INTO categories (parent_id, name, slug) VALUES (NULL, ?, ?)'
        );
        $insert->execute([$name, $slug]);
        return (int) $this->db->lastInsertId();
    }

    private function slug(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', (string) $transliterated) ?? '');
        return trim($slug, '-') ?: 'category-' . substr(hash('sha256', $value), 0, 10);
    }

    private function saveRowError(
        int $uploadId,
        int $line,
        string $code,
        string $message,
        array $cells
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO import_errors (upload_id, source_row_number, error_code, error_message, row_data)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $uploadId,
            $line,
            $code,
            $message,
            json_encode($cells, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
    }

    private function identityKeys(array $row): array
    {
        $keys = [];
        if ($row['article'] !== null) {
            $keys[] = 'a:' . mb_strtolower((string) $row['manufacturer']) . ':' . mb_strtolower($row['article']);
        }
        if ($row['barcode'] !== null) {
            $keys[] = 'b:' . $row['barcode'];
        }
        return $keys;
    }

    private function readRow(Worksheet $sheet, int $row, string $highestColumn): array
    {
        $lastColumn = Coordinate::columnIndexFromString($highestColumn);
        $values = [];
        for ($column = 1; $column <= $lastColumn; $column++) {
            $values[] = $sheet->getCell(Coordinate::stringFromColumnIndex($column) . $row)->getCalculatedValue();
        }
        return $values;
    }

    private function isEmpty(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }
        return true;
    }
}
