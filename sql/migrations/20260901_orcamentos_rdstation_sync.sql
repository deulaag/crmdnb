-- DNB CRM v3.4.0 — Sincronização RD Station CRM (substitui Salesforce)
-- Execute uma única vez no phpMyAdmin do cPanel, com backup prévio do banco.
-- Aditiva/idempotente: não altera nem remove dados existentes (registros com origem='salesforce' permanecem intocados).
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP PROCEDURE IF EXISTS dnb_add_index_if_missing;
DELIMITER $$
CREATE PROCEDURE dnb_add_index_if_missing(IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_columns VARCHAR(255), IN p_unique TINYINT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_table AND INDEX_NAME=p_index
  ) THEN
    SET @ddl = CONCAT('ALTER TABLE `',p_table,'` ADD ',IF(p_unique=1,'UNIQUE ',''),'INDEX `',p_index,'` (',p_columns,')');
    PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END$$
DELIMITER ;

-- sf_id é reaproveitada como identificador genérico de origem externa (agora RD Station em vez de Salesforce).
CALL dnb_add_index_if_missing('orcamentos','idx_orc_sf_id','`sf_id`',0);
DROP PROCEDURE dnb_add_index_if_missing;
