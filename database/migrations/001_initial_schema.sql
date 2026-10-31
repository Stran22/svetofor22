SET NAMES utf8mb4;
CREATE TABLE branches (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, slug VARCHAR(160) NOT NULL UNIQUE,
 address VARCHAR(255) NULL, phone VARCHAR(40) NULL, sort_order INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, parent_id BIGINT UNSIGNED NULL, name VARCHAR(180) NOT NULL,
 slug VARCHAR(190) NOT NULL UNIQUE, image_url VARCHAR(500) NULL, sort_order INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_categories_parent FOREIGN KEY(parent_id) REFERENCES categories(id) ON DELETE SET NULL,
 INDEX idx_categories_parent(parent_id,is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, category_id BIGINT UNSIGNED NULL, name VARCHAR(255) NOT NULL,
 slug VARCHAR(280) NOT NULL UNIQUE, sku VARCHAR(100) NULL UNIQUE, barcode VARCHAR(64) NULL UNIQUE,
 description TEXT NULL, image_url VARCHAR(500) NULL, unit VARCHAR(30) NOT NULL DEFAULT 'шт.', is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_products_category FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE SET NULL,
 INDEX idx_products_name(name), INDEX idx_products_category_active(category_id,is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, branch_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NOT NULL,
 price DECIMAL(12,2) NULL, quantity DECIMAL(12,3) NOT NULL DEFAULT 0, available_since DATETIME NULL,
 last_import_id BIGINT UNSIGNED NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_stock_branch_product(branch_id,product_id),
 CONSTRAINT fk_stock_branch FOREIGN KEY(branch_id) REFERENCES branches(id) ON DELETE CASCADE,
 CONSTRAINT fk_stock_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE,
 INDEX idx_stock_product_available(product_id,quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE price_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, branch_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NOT NULL,
 price DECIMAL(12,2) NOT NULL, recorded_at DATETIME NOT NULL, source ENUM('import','admin') NOT NULL DEFAULT 'import',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_price_branch FOREIGN KEY(branch_id) REFERENCES branches(id) ON DELETE CASCADE,
 CONSTRAINT fk_price_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE,
 INDEX idx_price_history_lookup(branch_id,product_id,recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, device_uuid CHAR(36) NULL UNIQUE, display_name VARCHAR(150) NULL,
 email VARCHAR(190) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_tokens (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE,
 expires_at DATETIME NULL, last_used_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_api_tokens_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE, INDEX idx_api_tokens_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE social_accounts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, provider ENUM('vk','ok','yandex') NOT NULL,
 provider_user_id VARCHAR(190) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_social_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 UNIQUE KEY uq_social_provider_user(provider,provider_user_id), INDEX idx_social_user(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subscriptions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NOT NULL,
 branch_id BIGINT UNSIGNED NOT NULL, status ENUM('active','cancelled','triggered') NOT NULL DEFAULT 'active',
 notified_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_sub_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_sub_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE,
 CONSTRAINT fk_sub_branch FOREIGN KEY(branch_id) REFERENCES branches(id) ON DELETE CASCADE,
 UNIQUE KEY uq_subscription_target(user_id,product_id,branch_id), INDEX idx_subscription_active(branch_id,product_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reviews (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NOT NULL,
 rating TINYINT UNSIGNED NOT NULL, body VARCHAR(500) NOT NULL, status ENUM('pending','published','rejected') NOT NULL DEFAULT 'pending',
 moderated_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_reviews_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_reviews_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE,
 UNIQUE KEY uq_review_user_product(user_id,product_id), CHECK(rating BETWEEN 1 AND 5), INDEX idx_reviews_product_status(product_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admins (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, login VARCHAR(100) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
 role ENUM('owner','manager','moderator') NOT NULL DEFAULT 'manager', is_active TINYINT(1) NOT NULL DEFAULT 1,
 last_login_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE import_log (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, branch_id BIGINT UNSIGNED NOT NULL, original_filename VARCHAR(255) NOT NULL,
 file_sha256 CHAR(64) NOT NULL, status ENUM('queued','processing','completed','failed','completed_with_errors') NOT NULL DEFAULT 'queued',
 rows_total INT UNSIGNED NOT NULL DEFAULT 0, rows_created INT UNSIGNED NOT NULL DEFAULT 0, rows_updated INT UNSIGNED NOT NULL DEFAULT 0,
 rows_unchanged INT UNSIGNED NOT NULL DEFAULT 0, rows_errors INT UNSIGNED NOT NULL DEFAULT 0,
 started_at DATETIME NULL, finished_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_import_branch FOREIGN KEY(branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
 UNIQUE KEY uq_import_branch_file(branch_id,file_sha256), INDEX idx_import_branch_created(branch_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE import_errors (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, import_id BIGINT UNSIGNED NOT NULL, row_number INT UNSIGNED NOT NULL,
 error_code VARCHAR(80) NOT NULL, message VARCHAR(500) NOT NULL, row_data JSON NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_import_errors_import FOREIGN KEY(import_id) REFERENCES import_log(id) ON DELETE CASCADE,
 INDEX idx_import_errors_row(import_id,row_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE push_delivery_log (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, subscription_id BIGINT UNSIGNED NOT NULL, event_key VARCHAR(190) NOT NULL,
 provider_message_id VARCHAR(190) NULL, status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending', response JSON NULL,
 sent_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_push_subscription FOREIGN KEY(subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE,
 UNIQUE KEY uq_push_subscription_event(subscription_id,event_key), INDEX idx_push_status(status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_audit_log (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, admin_id BIGINT UNSIGNED NULL, action VARCHAR(120) NOT NULL,
 entity_type VARCHAR(80) NOT NULL, entity_id BIGINT UNSIGNED NULL, ip_address VARCHAR(45) NULL, details JSON NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_admin_audit_admin FOREIGN KEY(admin_id) REFERENCES admins(id) ON DELETE SET NULL,
 INDEX idx_admin_audit_created(created_at), INDEX idx_admin_audit_entity(entity_type,entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE schema_migrations (
 migration VARCHAR(190) PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
