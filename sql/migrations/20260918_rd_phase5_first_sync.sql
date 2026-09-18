-- DNB CRM · Fase 5 — primeira sincronização real RD Station
-- Jobs assíncronos, logs visuais e conflitos auditáveis.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS rd_sync_jobs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  mode ENUM('first_sync','manual') NOT NULL DEFAULT 'first_sync',
  status ENUM('queued','running','paused','completed','error','cancelled') NOT NULL DEFAULT 'queued',
  stage VARCHAR(60) NOT NULL DEFAULT 'init',
  since_at DATETIME DEFAULT NULL,
  page_number INT NOT NULL DEFAULT 1,
  page_size INT NOT NULL DEFAULT 100,
  state_json LONGTEXT,
  counters_json LONGTEXT,
  last_error VARCHAR(500) DEFAULT NULL,
  created_by_user_id INT DEFAULT NULL,
  started_at DATETIME DEFAULT NULL,
  heartbeat_at DATETIME DEFAULT NULL,
  finished_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_rd_sync_jobs_status (status,created_at),
  CONSTRAINT fk_rd_sync_jobs_user FOREIGN KEY (created_by_user_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rd_sync_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  job_id BIGINT NOT NULL,
  level ENUM('info','success','warning','error') NOT NULL DEFAULT 'info',
  scope VARCHAR(60) NOT NULL DEFAULT '',
  message VARCHAR(500) NOT NULL,
  context_json LONGTEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rd_sync_logs_job (job_id,id),
  CONSTRAINT fk_rd_sync_logs_job FOREIGN KEY (job_id) REFERENCES rd_sync_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rd_sync_conflicts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  job_id BIGINT NOT NULL,
  product ENUM('marketing','crm') NOT NULL,
  external_entity VARCHAR(60) NOT NULL,
  external_id VARCHAR(191) NOT NULL,
  conflict_type VARCHAR(80) NOT NULL,
  candidates_json LONGTEXT,
  payload_json LONGTEXT,
  status ENUM('pending','resolved','ignored') NOT NULL DEFAULT 'pending',
  resolved_dnb_id VARCHAR(24) DEFAULT NULL,
  resolution_note VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rd_sync_conflict (job_id,product,external_entity,external_id,conflict_type),
  KEY idx_rd_sync_conflicts_status (status,created_at),
  CONSTRAINT fk_rd_sync_conflicts_job FOREIGN KEY (job_id) REFERENCES rd_sync_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS=1;
