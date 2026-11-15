<?php
declare(strict_types=1);

namespace Svet22\Services;

use PDO;

/**
 * Read-only checks against the existing product catalogue.
 * This class never inserts, updates or deletes rows.
 */
final class ExcelImportPreflight
{
    public function __construct(private PDO $db) {}

    /**
     * Category names in Excel must resolve to one existing active category.
     * Import must never create a category implicitly.
     */
    public function checkCategory(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT id FROM categories WHERE name = ? AND is_active = 1 ORDER BY id LIMIT 2'
        );
        $stmt->execute([trim($name)]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) === 0) {
            return 'Категория «' . $name . '» не найдена среди активных категорий. Сначала создайте её в админке.';
        }
        if (count($ids) > 1) {
            return 'Категория «' . $name . '» встречается несколько раз. Уточните категорию в Excel.';
        }
        return null;
    }

    /**
     * Returns a user-facing conflict message or null.
     * Uses the same product identity rules as XlsxImportService::upsertRow().
     *
     * @param array{article:?string,manufacturer:?string,barcode:?string} $row
     */
    public function checkProductIdentity(array $row): ?string
    {
        $article = $row['article'] ?? null;
        $manufacturer = $row['manufacturer'] ?? null;
        $barcode = $row['barcode'] ?? null;

        $byArticle = null;
        if ($article !== null) {
            $stmt = $this->db->prepare(
                'SELECT id, barcode, article FROM products WHERE article = ? AND manufacturer <=> ? LIMIT 1'
            );
            $stmt->execute([$article, $manufacturer]);
            $byArticle = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $byBarcode = null;
        if ($barcode !== null) {
            $stmt = $this->db->prepare(
                'SELECT id, barcode, article FROM products WHERE barcode = ? LIMIT 1'
            );
            $stmt->execute([$barcode]);
            $byBarcode = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($byArticle !== null && $byBarcode !== null
            && (int) $byArticle['id'] !== (int) $byBarcode['id']) {
            return 'Артикул и штрихкод относятся к разным товарам в базе.';
        }

        // A matching article with a new barcode would overwrite the existing
        // barcode. The database may also reject it due to a unique constraint.
        // We do not flag that as a conflict unless the barcode belongs to
        // another existing product (handled above).
        return null;
    }
}
