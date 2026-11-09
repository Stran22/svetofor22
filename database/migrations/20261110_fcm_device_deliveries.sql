-- Apply after 20261109_user_push_tokens.sql.
-- A device/event can be submitted to FCM only once automatically.
-- A 'processing' result may be ambiguous and requires manual investigation.
CREATE TABLE IF NOT EXISTS fcm_device_deliveries (
    event_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    token_id BIGINT UNSIGNED NOT NULL,
    status ENUM('processing','sent','invalid') NOT NULL DEFAULT 'processing',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_fcm_device_token (token_id),
    CONSTRAINT fk_fcm_device_token FOREIGN KEY (token_id)
        REFERENCES user_push_tokens(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
