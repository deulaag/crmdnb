-- DNB CRM v3.7 - PDF original da OS vinculado a visita e oportunidade.
-- Requer 20260910_crm_directory_visits_taskboard.sql.
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS dnb_v37_add_column;
DELIMITER $$
CREATE PROCEDURE dnb_v37_add_column(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT)
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

CALL dnb_v37_add_column('visitas','pdf_storage_name','VARCHAR(120) DEFAULT NULL AFTER template_data_json');
CALL dnb_v37_add_column('visitas','pdf_original_name','VARCHAR(255) DEFAULT NULL AFTER pdf_storage_name');
CALL dnb_v37_add_column('visitas','pdf_sha256','CHAR(64) DEFAULT NULL AFTER pdf_original_name');
CALL dnb_v37_add_column('orcamentos','visit_pdf_storage_name','VARCHAR(120) DEFAULT NULL AFTER visit_template_json');
CALL dnb_v37_add_column('orcamentos','visit_pdf_original_name','VARCHAR(255) DEFAULT NULL AFTER visit_pdf_storage_name');
CALL dnb_v37_add_column('orcamentos','visit_pdf_sha256','CHAR(64) DEFAULT NULL AFTER visit_pdf_original_name');
DROP PROCEDURE dnb_v37_add_column;

DROP PROCEDURE IF EXISTS dnb_v37_add_index;
DELIMITER $$
CREATE PROCEDURE dnb_v37_add_index(IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_columns VARCHAR(255))
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
CALL dnb_v37_add_index('visitas','idx_vis_pdf_sha','`pdf_sha256`');
CALL dnb_v37_add_index('orcamentos','idx_orc_pdf_sha','`visit_pdf_sha256`');
DROP PROCEDURE dnb_v37_add_index;
