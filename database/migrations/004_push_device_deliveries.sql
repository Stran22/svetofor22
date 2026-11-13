-- Per-device Firebase delivery tracking.
-- MySQL 8.0 / InnoDB.

CREATE TABLE IF NOT EXISTS push_device_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    delivery_id BIGINT UNSIGNED NOT NULL,
    token_id BIGINT UNSIGNED NULL,
    status ENUM(
        'pending',
        'processing',
        'sent',
        'failed',
        'unknown'
    ) NOT NULL DEFAULT 'pending',
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    provider_message_id VARCHAR(255) NULL,
    last_error VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_device_delivery (delivery_id, token_id),
    KEY idx_device_delivery_status (status, created_at),
    KEY idx_device_delivery_token (token_id),
    CONSTRAINT fk_device_delivery_event
        FOREIGN KEY (delivery_id)
        REFERENCES push_delivery_log(id) ON DELETE CASCADE,
    CONSTRAINT fk_device_delivery_token
        FOREIGN KEY (token_id)
        REFERENCES user_push_tokens(id)
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
