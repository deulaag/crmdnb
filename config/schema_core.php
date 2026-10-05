<?php
declare(strict_types=1);

return [
<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(150) NOT NULL,
 email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 role ENUM('admin','gestor','comercial','suporte','leitura') NOT NULL DEFAULT 'comercial',
 active TINYINT(1) NOT NULL DEFAULT 1,
 last_login_at DATETIME NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS login_attempts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 identifier_hash CHAR(64) NOT NULL,
 ip_address VARCHAR(45) NULL,
 success TINYINT(1) NOT NULL DEFAULT 0,
 attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_login_attempt_identifier_time (identifier_hash, attempted_at),
 INDEX idx_login_attempt_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS user_commission_policies (
 user_id INT UNSIGNED PRIMARY KEY,
 operation_role ENUM('vendedor','suporte') NOT NULL DEFAULT 'vendedor',
 policy_key VARCHAR(50) NOT NULL DEFAULT 'sem_politica',
 params_json LONGTEXT NULL,
 linked_user_id INT UNSIGNED NULL,
 linked_user_id2 INT UNSIGNED NULL,
 mentor_user_id INT UNSIGNED NULL,
 hired_month CHAR(7) NULL,
 active_operation TINYINT(1) NOT NULL DEFAULT 1,
 updated_by INT UNSIGNED NULL,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_policy_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_policy_linked_user FOREIGN KEY (linked_user_id) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT fk_policy_linked_user2 FOREIGN KEY (linked_user_id2) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT fk_policy_mentor FOREIGN KEY (mentor_user_id) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT fk_policy_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS funnels (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 funnel_key VARCHAR(50) NOT NULL UNIQUE,
 label VARCHAR(120) NOT NULL,
 description VARCHAR(255) NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS funnel_stages (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 funnel_id INT UNSIGNED NOT NULL,
 name VARCHAR(120) NOT NULL,
 sort_order INT NOT NULL DEFAULT 0,
 UNIQUE KEY uq_funnel_stage (funnel_id,name),
 CONSTRAINT fk_stage_funnel FOREIGN KEY (funnel_id) REFERENCES funnels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS product_lines (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(160) NOT NULL UNIQUE,
 funnel_key VARCHAR(50) NOT NULL,
 item_type VARCHAR(60) NOT NULL,
 is_agency TINYINT(1) NOT NULL DEFAULT 0,
 active TINYINT(1) NOT NULL DEFAULT 1,
 sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS integration_settings (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 provider VARCHAR(50) NOT NULL UNIQUE,
 client_id TEXT NULL,
 client_secret LONGTEXT NULL,
 access_token LONGTEXT NULL,
 refresh_token LONGTEXT NULL,
 extra_json LONGTEXT NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 0,
 updated_by INT UNSIGNED NULL,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_integration_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS audit_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NULL,
 action VARCHAR(80) NOT NULL,
 entity_type VARCHAR(80) NOT NULL,
 entity_id BIGINT UNSIGNED NULL,
 context_json LONGTEXT NULL,
 ip_address VARCHAR(45) NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_audit_created (created_at),
 CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
];
