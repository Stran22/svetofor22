SET NAMES utf8mb4;
CREATE TABLE admin_login_attempts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 login VARCHAR(100) NOT NULL,
 ip_address VARCHAR(45) NOT NULL,
 attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_admin_login_attempts_lookup(login,ip_address,attempted_at),
 INDEX idx_admin_login_attempts_time(attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE oauth_attempts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 provider ENUM('vk','ok','yandex') NOT NULL,
 state_hash CHAR(64) NOT NULL UNIQUE,
 code_verifier VARCHAR(255) NULL,
 redirect_uri VARCHAR(500) NOT NULL,
 expires_at DATETIME NOT NULL,
 consumed_at DATETIME NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_oauth_attempts_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE oauth_handoff_codes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 code_hash CHAR(64) NOT NULL UNIQUE,
 expires_at DATETIME NOT NULL,
 consumed_at DATETIME NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_oauth_handoff_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 INDEX idx_oauth_handoff_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;