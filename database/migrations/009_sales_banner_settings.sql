-- Configurable home SALES banner. Separate from legacy reviews_login banner.
CREATE TABLE IF NOT EXISTS sales_banner_settings (
 id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
 is_enabled TINYINT(1) NOT NULL DEFAULT 1,
 mode ENUM('text','product') NOT NULL DEFAULT 'text',
 heading VARCHAR(60) NOT NULL DEFAULT 'SALES',
 cta_text VARCHAR(60) NOT NULL DEFAULT 'SHOP NOW',
 right_title VARCHAR(100) NOT NULL DEFAULT 'BIGGEST SALES',
 right_subtitle VARCHAR(100) NOT NULL DEFAULT 'UP TO 70% OFF',
 product_id BIGINT UNSIGNED NULL,
 image_override_url VARCHAR(1000) NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_sales_banner_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sales_banner_settings (id) VALUES (1)
ON DUPLICATE KEY UPDATE id=id;
