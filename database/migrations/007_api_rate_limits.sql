SET NAMES utf8mb4;

CREATE TABLE api_rate_limits (
 bucket_hash CHAR(64) NOT NULL PRIMARY KEY,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 window_started_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 INDEX idx_api_rate_limits_updated(updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
