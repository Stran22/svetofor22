SET NAMES utf8mb4;

ALTER TABLE oauth_attempts
 ADD COLUMN device_id VARCHAR(100) NOT NULL DEFAULT '' AFTER redirect_uri,
 ADD COLUMN link_user_id BIGINT UNSIGNED NULL AFTER device_id,
 ADD INDEX idx_oauth_attempts_expiry_consumed(expires_at,consumed_at),
 ADD CONSTRAINT fk_oauth_attempt_link_user
   FOREIGN KEY(link_user_id) REFERENCES users(id) ON DELETE SET NULL;
