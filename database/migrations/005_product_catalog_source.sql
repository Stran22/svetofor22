SET NAMES utf8mb4;

ALTER TABLE products
 ADD COLUMN catalog_source_branch_id BIGINT UNSIGNED NULL AFTER category_id,
 ADD INDEX idx_products_catalog_source_branch(catalog_source_branch_id),
 ADD CONSTRAINT fk_products_catalog_source_branch
   FOREIGN KEY(catalog_source_branch_id) REFERENCES branches(id) ON DELETE SET NULL;
