-- DNB CRM · Fase 6 — sincronização contínua, webhooks e resolução de conflitos
-- Requer as Fases 1–5 já instaladas.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

ALTER TABLE rd_sync_jobs
  MODIFY COLUMN mode ENUM('first_sync','manual','incremental','webhook') NOT NULL DEFAULT 'first_sync';

DROP PROCEDURE IF EXISTS dnb_phase6_add_column;
DELIMITER $
CREATE PROCEDURE dnb_phase6_add_column(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_table AND COLUMN_NAME=p_column
  ) THEN
    SET @ddl=CONCAT('ALTER TABLE `',p_table,'` ADD COLUMN `',p_column,'` ',p_definition);
    PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END$
DELIMITER ;

DROP PROCEDURE IF EXISTS dnb_phase6_add_index;
DELIMITER $
CREATE PROCEDURE dnb_phase6_add_index(IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_columns VARCHAR(255))
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_table AND INDEX_NAME=p_index
  ) THEN
    SET @ddl=CONCAT('ALTER TABLE `',p_table,'` ADD INDEX `',p_index,'` (',p_columns,')');
    PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END$
DELIMITER ;

DROP PROCEDURE IF EXISTS dnb_phase6_add_fk;
DELIMITER $
CREATE PROCEDURE dnb_phase6_add_fk(IN p_table VARCHAR(64), IN p_constraint VARCHAR(64), IN p_definition TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=p_table AND CONSTRAINT_NAME=p_constraint
  ) THEN
    SET @ddl=CONCAT('ALTER TABLE `',p_table,'` ADD CONSTRAINT `',p_constraint,'` ',p_definition);
    PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END$
DELIMITER ;

CALL dnb_phase6_add_column('rd_sync_conflicts','resolution_action','VARCHAR(40) DEFAULT NULL AFTER resolution_note');
CALL dnb_phase6_add_column('rd_sync_conflicts','resolved_by_user_id','INT DEFAULT NULL AFTER resolution_action');
CALL dnb_phase6_add_column('rd_sync_conflicts','resolved_at','DATETIME DEFAULT NULL AFTER resolved_by_user_id');
CALL dnb_phase6_add_index('rd_sync_conflicts','idx_rd_sync_conflicts_resolved_by','`resolved_by_user_id`');
CALL dnb_phase6_add_fk('rd_sync_conflicts','fk_rd_sync_conflicts_resolved_by','FOREIGN KEY (`resolved_by_user_id`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL');

CALL dnb_phase6_add_column('crm_external_links','remote_status','ENUM(''active'',''deleted'') NOT NULL DEFAULT ''active'' AFTER last_sync_at');
CALL dnb_phase6_add_column('crm_external_links','remote_deleted_at','DATETIME DEFAULT NULL AFTER remote_status');
CALL dnb_phase6_add_column('crm_external_links','last_event_at','DATETIME DEFAULT NULL AFTER remote_deleted_at');
CALL dnb_phase6_add_index('crm_external_links','idx_crm_external_remote_status','`provider`,`remote_status`,`last_event_at`');

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

DROP PROCEDURE IF EXISTS dnb_phase6_add_fk;
DROP PROCEDURE IF EXISTS dnb_phase6_add_index;
DROP PROCEDURE IF EXISTS dnb_phase6_add_column;

SET FOREIGN_KEY_CHECKS=1;
