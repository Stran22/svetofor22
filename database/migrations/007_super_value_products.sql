-- Рекламная подборка «Супер выгода». Отметка не меняет цену и остатки.
CREATE TABLE IF NOT EXISTS featured_products (
    product_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    sort_order INT NOT NULL DEFAULT 0,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_featured_products_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    KEY idx_featured_products_display (is_enabled, sort_order, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
