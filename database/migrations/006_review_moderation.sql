SET NAMES utf8mb4;

ALTER TABLE reviews
 ADD COLUMN moderated_by BIGINT UNSIGNED NULL AFTER moderated_at,
 ADD CONSTRAINT fk_reviews_moderated_by
   FOREIGN KEY(moderated_by) REFERENCES admins(id) ON DELETE SET NULL,
 ADD INDEX idx_reviews_moderation(status,created_at);
