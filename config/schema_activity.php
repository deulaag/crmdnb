<?php
declare(strict_types=1);

return [
<<<'SQL'
CREATE TABLE IF NOT EXISTS visits (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 os_number VARCHAR(80) NULL,
 visit_date DATE NOT NULL,
 client_name VARCHAR(190) NOT NULL,
 seller_user_id INT UNSIGNED NULL,
 visit_type ENUM('visita','prospeccao') NOT NULL DEFAULT 'visita',
 contact_name VARCHAR(160) NULL,
 phone VARCHAR(60) NULL,
 sector VARCHAR(120) NULL,
 equipment VARCHAR(255) NULL,
 summary TEXT NULL,
 opportunity_text TEXT NULL,
 lead_status ENUM('pendente','promovida','descartada') NOT NULL DEFAULT 'pendente',
 source VARCHAR(50) NOT NULL DEFAULT 'manual',
 created_by INT UNSIGNED NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_visit_date (visit_date),
 CONSTRAINT fk_visit_seller FOREIGN KEY (seller_user_id) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT fk_visit_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS agenda (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 event_date DATE NOT NULL,
 event_time TIME NULL,
 client_name VARCHAR(190) NOT NULL,
 user_id INT UNSIGNED NULL,
 contact_name VARCHAR(160) NULL,
 objective TEXT NOT NULL,
 status ENUM('sugerido','agendado','confirmado','concluido','cancelado') NOT NULL DEFAULT 'agendado',
 source VARCHAR(50) NOT NULL DEFAULT 'manual',
 visit_id BIGINT UNSIGNED NULL,
 created_by INT UNSIGNED NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_agenda_date (event_date),
 CONSTRAINT fk_agenda_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT fk_agenda_visit FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE SET NULL,
 CONSTRAINT fk_agenda_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS sync_runs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 provider VARCHAR(50) NOT NULL,
 direction VARCHAR(30) NOT NULL DEFAULT 'import',
 status VARCHAR(30) NOT NULL,
 summary TEXT NULL,
 started_at DATETIME NOT NULL,
 finished_at DATETIME NULL,
 created_by INT UNSIGNED NULL,
 CONSTRAINT fk_sync_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS ai_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 feature VARCHAR(80) NOT NULL,
 entity_type VARCHAR(80) NULL,
 entity_id BIGINT UNSIGNED NULL,
 model_name VARCHAR(100) NULL,
 status VARCHAR(30) NOT NULL,
 prompt_hash CHAR(64) NULL,
 response_excerpt TEXT NULL,
 created_by INT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_ai_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
];
