<?php
declare(strict_types=1);
namespace App\Services;

use PDO;

final class CatalogVisibility
{
    public static function isProductVisible(PDO $pdo, int $productId): bool
    {
        $stmt = $pdo->prepare(
            'WITH RECURSIVE active_categories AS (
                SELECT id,parent_id FROM categories WHERE parent_id IS NULL AND is_active=1
                UNION ALL
                SELECT c.id,c.parent_id FROM categories c
                JOIN active_categories ac ON c.parent_id=ac.id WHERE c.is_active=1
             )
             SELECT p.id FROM products p
             WHERE p.id=:product_id AND p.is_active=1
               AND (p.category_id IS NULL OR EXISTS (
                   SELECT 1 FROM active_categories ac WHERE ac.id=p.category_id
               ))
             LIMIT 1'
        );
        $stmt->execute(['product_id' => $productId]);
        return (bool)$stmt->fetchColumn();
    }
}
