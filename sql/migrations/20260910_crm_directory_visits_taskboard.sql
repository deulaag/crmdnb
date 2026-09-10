-- DNB CRM v3.6 - empresas/contatos, OS completa e Kanban de tarefas
-- Execute uma unica vez no phpMyAdmin/cPanel, com backup previo.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS crm_companies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cnpj VARCHAR(18) DEFAULT NULL,
  name VARCHAR(200) NOT NULL,
  trade_name VARCHAR(200) DEFAULT '',
  address VARCHAR(255) DEFAULT '',
  locality VARCHAR(180) DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_crm_companies_cnpj (cnpj),
  KEY idx_crm_companies_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_contacts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  name VARCHAR(180) NOT NULL,
  phone VARCHAR(40) DEFAULT '',
  email VARCHAR(190) DEFAULT '',
  role_title VARCHAR(120) DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_crm_contacts_company (company_id,name),
  KEY idx_crm_contacts_email (email),
  CONSTRAINT fk_crm_contacts_company FOREIGN KEY (company_id) REFERENCES crm_companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP PROCEDURE IF EXISTS dnb_v36_add_column;
DELIMITER $$
CREATE PROCEDURE dnb_v36_add_column(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT)
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

CALL dnb_v36_add_column('orcamentos','company_id','INT DEFAULT NULL AFTER cliente');
CALL dnb_v36_add_column('orcamentos','contact_id','INT DEFAULT NULL AFTER company_id');
CALL dnb_v36_add_column('orcamentos','source_visit_id','INT DEFAULT NULL AFTER contact_id');
CALL dnb_v36_add_column('orcamentos','visit_template_json','LONGTEXT DEFAULT NULL AFTER obs');
CALL dnb_v36_add_column('visitas','company_id','INT DEFAULT NULL AFTER cliente');
CALL dnb_v36_add_column('visitas','contact_id','INT DEFAULT NULL AFTER company_id');
CALL dnb_v36_add_column('visitas','template_data_json','LONGTEXT DEFAULT NULL AFTER oportunidade');
CALL dnb_v36_add_column('crm_tasks','description','VARCHAR(500) DEFAULT NULL AFTER title');
CALL dnb_v36_add_column('crm_tasks','board_stage','VARCHAR(20) NOT NULL DEFAULT ''todo'' AFTER due_date');
DROP PROCEDURE dnb_v36_add_column;

DROP PROCEDURE IF EXISTS dnb_v36_add_index;
DELIMITER $$
CREATE PROCEDURE dnb_v36_add_index(IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_columns VARCHAR(255), IN p_unique TINYINT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_table AND INDEX_NAME=p_index
  ) THEN
    SET @ddl=CONCAT('ALTER TABLE `',p_table,'` ADD ',IF(p_unique=1,'UNIQUE ',''),'INDEX `',p_index,'` (',p_columns,')');
    PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END$$
DELIMITER ;
CALL dnb_v36_add_index('orcamentos','idx_orc_company','`company_id`',0);
CALL dnb_v36_add_index('orcamentos','idx_orc_contact','`contact_id`',0);
CALL dnb_v36_add_index('orcamentos','uq_orc_source_visit','`source_visit_id`',1);
CALL dnb_v36_add_index('visitas','idx_vis_company','`company_id`',0);
CALL dnb_v36_add_index('visitas','idx_vis_contact','`contact_id`',0);
CALL dnb_v36_add_index('crm_tasks','idx_crm_tasks_board','`assigned_to_user_id`,`status`,`board_stage`,`due_date`',0);
DROP PROCEDURE dnb_v36_add_index;

DROP PROCEDURE IF EXISTS dnb_v36_add_fk;
DELIMITER $$
CREATE PROCEDURE dnb_v36_add_fk(IN p_table VARCHAR(64), IN p_constraint VARCHAR(64), IN p_definition TEXT)
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
CALL dnb_v36_add_fk('orcamentos','fk_orc_company','FOREIGN KEY (`company_id`) REFERENCES `crm_companies`(`id`) ON DELETE SET NULL');
CALL dnb_v36_add_fk('orcamentos','fk_orc_contact','FOREIGN KEY (`contact_id`) REFERENCES `crm_contacts`(`id`) ON DELETE SET NULL');
CALL dnb_v36_add_fk('orcamentos','fk_orc_source_visit','FOREIGN KEY (`source_visit_id`) REFERENCES `visitas`(`id`) ON DELETE SET NULL');
CALL dnb_v36_add_fk('visitas','fk_vis_company','FOREIGN KEY (`company_id`) REFERENCES `crm_companies`(`id`) ON DELETE SET NULL');
CALL dnb_v36_add_fk('visitas','fk_vis_contact','FOREIGN KEY (`contact_id`) REFERENCES `crm_contacts`(`id`) ON DELETE SET NULL');
DROP PROCEDURE dnb_v36_add_fk;

-- Backfill simples: clientes ja existentes passam a compor o diretorio de empresas.
INSERT INTO crm_companies (name)
SELECT DISTINCT TRIM(cliente) FROM orcamentos
WHERE TRIM(COALESCE(cliente,''))<>''
  AND NOT EXISTS (SELECT 1 FROM crm_companies c WHERE LOWER(TRIM(c.name))=LOWER(TRIM(orcamentos.cliente)));

INSERT INTO crm_companies (name)
SELECT DISTINCT TRIM(cliente) FROM visitas
WHERE TRIM(COALESCE(cliente,''))<>''
  AND NOT EXISTS (SELECT 1 FROM crm_companies c WHERE LOWER(TRIM(c.name))=LOWER(TRIM(visitas.cliente)));

UPDATE orcamentos o JOIN crm_companies c ON LOWER(TRIM(c.name))=LOWER(TRIM(o.cliente))
SET o.company_id=c.id WHERE o.company_id IS NULL;
UPDATE visitas v JOIN crm_companies c ON LOWER(TRIM(c.name))=LOWER(TRIM(v.cliente))
SET v.company_id=c.id WHERE v.company_id IS NULL;

SET FOREIGN_KEY_CHECKS=1;
