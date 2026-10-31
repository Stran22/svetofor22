SET NAMES utf8mb4;

ALTER TABLE push_delivery_log
 MODIFY COLUMN status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
 ADD COLUMN attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER status,
 ADD COLUMN locked_at DATETIME NULL AFTER sent_at,
 ADD COLUMN last_error VARCHAR(500) NULL AFTER locked_at,
 ADD INDEX idx_push_delivery_processing(status,locked_at);
