-- DNB CRM · Fase 2 — propriedade de Empresas/Contatos para RBAC da nova UI.
-- Requer 20260918_crm_core_entities_phase1.sql.
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS dnb_p2_add_column;
DELIMITER $$
CREATE PROCEDURE dnb_p2_add_column(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT)
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

DROP PROCEDURE IF EXISTS dnb_p2_add_index;
DELIMITER $$
CREATE PROCEDURE dnb_p2_add_index(IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_columns VARCHAR(255))
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

DROP PROCEDURE IF EXISTS dnb_p2_add_fk;
DELIMITER $$
CREATE PROCEDURE dnb_p2_add_fk(IN p_table VARCHAR(64), IN p_constraint VARCHAR(64), IN p_definition TEXT)
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

CALL dnb_p2_add_column('crm_companies','created_by_user_id','INT DEFAULT NULL AFTER source');
CALL dnb_p2_add_column('crm_contacts','created_by_user_id','INT DEFAULT NULL AFTER source');

CALL dnb_p2_add_index('crm_companies','idx_crm_companies_creator','`created_by_user_id`');
CALL dnb_p2_add_index('crm_contacts','idx_crm_contacts_creator','`created_by_user_id`');

CALL dnb_p2_add_fk('crm_companies','fk_crm_companies_creator','FOREIGN KEY (`created_by_user_id`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL');
CALL dnb_p2_add_fk('crm_contacts','fk_crm_contacts_creator','FOREIGN KEY (`created_by_user_id`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL');

UPDATE crm_companies
SET review_status='needs_review',
    review_reason=COALESCE(NULLIF(review_reason,''),'Empresa sem CNPJ: conferir cadastro')
WHERE (cnpj_digits IS NULL OR cnpj_digits='') AND review_status='ok';

DROP PROCEDURE dnb_p2_add_column;
DROP PROCEDURE dnb_p2_add_index;
DROP PROCEDURE dnb_p2_add_fk;
