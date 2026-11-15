-- Home banners and VK-authenticated contests. MySQL 8, utf8mb4.
CREATE TABLE IF NOT EXISTS home_banners (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 banner_key VARCHAR(60) NOT NULL,
 title VARCHAR(190) NOT NULL,
 description VARCHAR(600) NULL,
 button_text VARCHAR(80) NOT NULL DEFAULT 'Авторизоваться',
 image_url VARCHAR(1000) NULL,
 is_enabled TINYINT(1) NOT NULL DEFAULT 0,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_home_banner_key (banner_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contests (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(190) NOT NULL,
 description TEXT NOT NULL,
 rules TEXT NOT NULL,
 prize_description TEXT NULL,
 banner_url VARCHAR(1000) NULL,
 detail_image_url VARCHAR(1000) NULL,
 starts_at DATETIME NOT NULL,
 ends_at DATETIME NOT NULL,
 is_published TINYINT(1) NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_contests_visible (is_published, starts_at, ends_at),
 CONSTRAINT chk_contests_dates CHECK (ends_at > starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contest_participants (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 contest_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_contest_user (contest_id, user_id),
 KEY idx_contest_participants_user (user_id),
 CONSTRAINT fk_contest_participants_contest FOREIGN KEY (contest_id) REFERENCES contests(id) ON DELETE CASCADE,
 CONSTRAINT fk_contest_participants_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO home_banners (banner_key,title,description,button_text,is_enabled)
VALUES ('reviews_login','Оставляйте отзывы о товарах!','Авторизуйтесь через VK, делитесь впечатлениями и помогайте другим покупателям.','Авторизоваться',0)
ON DUPLICATE KEY UPDATE banner_key=banner_key;
