<?php
declare(strict_types=1);

namespace Svet22\Services;

use PDO;

/**
 * Resolves display restrictions across the category ancestry.
 * A parent restriction always applies to descendants.
 */
final class CategoryVisibilityService
{
    public function __construct(private PDO $db) {}

    public function all(): array
    {
        $rows = $this->db->query(
            'SELECT id, parent_id, hide_store_stock FROM categories'
        )->fetchAll(PDO::FETCH_ASSOC);
        $byId = [];
        foreach ($rows as $row) $byId[(int)$row['id']] = $row;
        $resolved = [];
        $resolve = function (int $id, array $visited = []) use (&$resolve, &$resolved, $byId): array {
            if (isset($resolved[$id])) return $resolved[$id];
            if (!isset($byId[$id]) || isset($visited[$id])) {
                return ['hide_store_stock' => false];
            }
            $visited[$id] = true;
            $row = $byId[$id];
            $parent = $row['parent_id'] !== null
                ? $resolve((int)$row['parent_id'], $visited)
                : ['hide_store_stock' => false];
            return $resolved[$id] = [
                'hide_store_stock' => (bool)$row['hide_store_stock'] || $parent['hide_store_stock']
            ];
        };
        foreach (array_keys($byId) as $id) $resolve($id);
        return $resolved;
    }

    public function forCategory(?int $categoryId): array
    {
        return $categoryId === null
            ? ['hide_store_stock' => false]
            : ($this->all()[$categoryId] ?? ['hide_store_stock' => false]);
    }
}
