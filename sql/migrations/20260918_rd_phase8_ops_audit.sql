-- DNB CRM · Fase 8 — operação, auditoria e reprocessamento RD
-- Requer as Fases 5 e 6 instaladas.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

DROP PROCEDURE IF EXISTS dnb_phase8_add_column;
DELIMITER $$
CREATE PROCEDURE dnb_phase8_add_column(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_table AND COLUMN_NAME=p_column
  ) THEN
    SET @ddl=CONCAT('ALTER TABLE `',p_table,'` ADD COLUMN `',p_column,'` ',p_definition);
    PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END$$
DELIMITER ;

DROP PROCEDURE IF EXISTS dnb_phase8_add_index;
DELIMITER $$
CREATE PROCEDURE dnb_phase8_add_index(IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_columns VARCHAR(255))
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_table AND INDEX_NAME=p_index
  ) THEN
    SET @ddl=CONCAT('ALTER TABLE `',p_table,'` ADD INDEX `',p_index,'` (',p_columns,')');
    PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END$$
DELIMITER ;

DROP PROCEDURE IF EXISTS dnb_phase8_add_fk;
DELIMITER $$
CREATE PROCEDURE dnb_phase8_add_fk(IN p_table VARCHAR(64), IN p_constraint VARCHAR(64), IN p_definition TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=p_table AND CONSTRAINT_NAME=p_constraint
  ) THEN
    SET @ddl=CONCAT('ALTER TABLE `',p_table,'` ADD CONSTRAINT `',p_constraint,'` ',p_definition);
    PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END$$
DELIMITER ;

CALL dnb_phase8_add_column('rd_webhook_events','requeue_count','INT NOT NULL DEFAULT 0 AFTER attempts');
CALL dnb_phase8_add_column('rd_webhook_events','last_requeued_at','DATETIME DEFAULT NULL AFTER requeue_count');
CALL dnb_phase8_add_column('rd_webhook_events','last_requeued_by_user_id','INT DEFAULT NULL AFTER last_requeued_at');
CALL dnb_phase8_add_index('rd_webhook_events','idx_rd_webhook_events_requeued','`status`,`requeue_count`,`last_requeued_at`');
CALL dnb_phase8_add_fk('rd_webhook_events','fk_rd_webhook_events_requeued_by','FOREIGN KEY (`last_requeued_by_user_id`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL');

CREATE TABLE IF NOT EXISTS rd_reprocess_actions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  action_type ENUM('event_retry','error_retry_all','job_resume','queue_process','audit_export') NOT NULL,
  target_type ENUM('webhook_event','sync_job','queue','audit') NOT NULL,
  target_id BIGINT DEFAULT NULL,
  affected_count INT NOT NULL DEFAULT 0,
  requested_by_user_id INT DEFAULT NULL,
  note VARCHAR(500) DEFAULT NULL,
  details_json LONGTEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rd_reprocess_actions_created (created_at),
  KEY idx_rd_reprocess_actions_target (target_type,target_id),
  KEY idx_rd_reprocess_actions_user (requested_by_user_id),
  CONSTRAINT fk_rd_reprocess_actions_user FOREIGN KEY (requested_by_user_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP PROCEDURE IF EXISTS dnb_phase8_add_fk;
DROP PROCEDURE IF EXISTS dnb_phase8_add_index;
DROP PROCEDURE IF EXISTS dnb_phase8_add_column;

SET FOREIGN_KEY_CHECKS=1;
