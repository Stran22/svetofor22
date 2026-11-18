-- Anonymous and authenticated app sessions are associated with the existing users.id.
-- No IP, advertising ID, phone number or location is stored.
CREATE TABLE IF NOT EXISTS app_analytics_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 event_type ENUM('visit','product_view','category_click') NOT NULL,
 product_id BIGINT UNSIGNED NULL,
 category_id BIGINT UNSIGNED NULL,
 branch_id BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_analytics_type_date (event_type, created_at),
 KEY idx_analytics_user_date (user_id, created_at),
 KEY idx_analytics_product_date (product_id, created_at),
 KEY idx_analytics_category_date (category_id, created_at),
 CONSTRAINT fk_analytics_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
