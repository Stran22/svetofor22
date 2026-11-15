-- Агрегируемые поисковые события приложения; запросы не связываются с ФИО или устройством.
CREATE TABLE IF NOT EXISTS search_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    query_text VARCHAR(100) NOT NULL,
    results_count INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_search_events_date (created_at),
    KEY idx_search_events_branch_date (branch_id, created_at),
    KEY idx_search_events_query_date (query_text, created_at),
    KEY idx_search_events_user_date (user_id, created_at),
    CONSTRAINT fk_search_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_search_events_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
