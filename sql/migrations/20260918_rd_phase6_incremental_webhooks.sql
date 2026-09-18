-- DNB CRM · Fase 6 — sincronização contínua, webhooks e resolução de conflitos
-- Requer as Fases 1–5 já instaladas.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

ALTER TABLE rd_sync_jobs
  MODIFY COLUMN mode ENUM('first_sync','manual','incremental','webhook') NOT NULL DEFAULT 'first_sync';

ALTER TABLE rd_sync_conflicts
  ADD COLUMN resolution_action VARCHAR(40) DEFAULT NULL AFTER resolution_note,
  ADD COLUMN resolved_by_user_id INT DEFAULT NULL AFTER resolution_action,
  ADD COLUMN resolved_at DATETIME DEFAULT NULL AFTER resolved_by_user_id,
  ADD KEY idx_rd_sync_conflicts_resolved_by (resolved_by_user_id),
  ADD CONSTRAINT fk_rd_sync_conflicts_resolved_by FOREIGN KEY (resolved_by_user_id) REFERENCES usuarios(id) ON DELETE SET NULL;

ALTER TABLE crm_external_links
  ADD COLUMN remote_status ENUM('active','deleted') NOT NULL DEFAULT 'active' AFTER last_sync_at,
  ADD COLUMN remote_deleted_at DATETIME DEFAULT NULL AFTER remote_status,
  ADD COLUMN last_event_at DATETIME DEFAULT NULL AFTER remote_deleted_at,
  ADD KEY idx_crm_external_remote_status (provider,remote_status,last_event_at);

CREATE TABLE IF NOT EXISTS rd_sync_state (
  state_key VARCHAR(100) PRIMARY KEY,
  state_value LONGTEXT,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rd_webhook_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  product ENUM('marketing','crm') NOT NULL,
  event_key CHAR(64) NOT NULL,
  transaction_uuid VARCHAR(80) DEFAULT NULL,
  event_name VARCHAR(100) NOT NULL,
  external_entity VARCHAR(60) DEFAULT '',
  external_id VARCHAR(191) DEFAULT '',
  payload_json LONGTEXT NOT NULL,
  status ENUM('pending','processing','processed','ignored','error') NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  job_id BIGINT DEFAULT NULL,
  last_error VARCHAR(500) DEFAULT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME DEFAULT NULL,
  UNIQUE KEY uq_rd_webhook_event_key (event_key),
  KEY idx_rd_webhook_events_queue (status,received_at),
  KEY idx_rd_webhook_events_entity (product,external_entity,external_id),
  KEY idx_rd_webhook_events_job (job_id),
  CONSTRAINT fk_rd_webhook_events_job FOREIGN KEY (job_id) REFERENCES rd_sync_jobs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rd_webhook_subscriptions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  product ENUM('marketing','crm') NOT NULL,
  event_type VARCHAR(100) NOT NULL,
  remote_id VARCHAR(191) DEFAULT NULL,
  callback_url VARCHAR(500) NOT NULL,
  status ENUM('pending','active','error','disabled') NOT NULL DEFAULT 'pending',
  last_http_status INT DEFAULT NULL,
  last_error VARCHAR(500) DEFAULT NULL,
  registered_at DATETIME DEFAULT NULL,
  checked_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rd_webhook_subscription (product,event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS=1;
