-- DNB CRM v3.0.0 — Migração IA + RBAC hierárquico
-- Execute uma única vez no phpMyAdmin do cPanel, com backup prévio do banco.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP PROCEDURE IF EXISTS dnb_add_column_if_missing;
DELIMITER $$
CREATE PROCEDURE dnb_add_column_if_missing(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_table AND COLUMN_NAME=p_column
  ) THEN
    SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
    PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END$$
DELIMITER ;

CALL dnb_add_column_if_missing('usuarios','role','ENUM(''ADMINISTRADOR'',''SUPERVISOR'',''VENDEDOR'',''SUPORTE'') NOT NULL DEFAULT ''VENDEDOR''');
CALL dnb_add_column_if_missing('usuarios','supervisor_id','INT DEFAULT NULL');
CALL dnb_add_column_if_missing('usuarios','vendedor_id','VARCHAR(20) DEFAULT NULL');
CALL dnb_add_column_if_missing('usuarios','status','ENUM(''active'',''inactive'') NOT NULL DEFAULT ''active''');
CALL dnb_add_column_if_missing('usuarios','atualizado_em','DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
CALL dnb_add_column_if_missing('orcamentos','assigned_to_user_id','INT DEFAULT NULL');
CALL dnb_add_column_if_missing('pedidos','assigned_to_user_id','INT DEFAULT NULL');
CALL dnb_add_column_if_missing('visitas','assigned_to_user_id','INT DEFAULT NULL');
DROP PROCEDURE dnb_add_column_if_missing;

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
CALL dnb_add_index_if_missing('usuarios','idx_usuarios_role_status','`role`,`status`',0);
CALL dnb_add_index_if_missing('usuarios','idx_usuarios_supervisor','`supervisor_id`',0);
CALL dnb_add_index_if_missing('usuarios','uq_usuarios_vendedor','`vendedor_id`',1);
CALL dnb_add_index_if_missing('orcamentos','idx_orc_assigned_user','`assigned_to_user_id`',0);
CALL dnb_add_index_if_missing('pedidos','idx_ped_assigned_user','`assigned_to_user_id`',0);
CALL dnb_add_index_if_missing('visitas','idx_vis_assigned_user','`assigned_to_user_id`',0);
DROP PROCEDURE dnb_add_index_if_missing;

UPDATE usuarios SET status=IF(ativo=1,'active','inactive');
UPDATE usuarios u JOIN vendedores v ON LOWER(TRIM(u.email))=LOWER(TRIM(v.email)) AND v.email<>''
SET u.vendedor_id=v.id WHERE u.vendedor_id IS NULL;
SET @dnb_first_admin = (SELECT MIN(id) FROM usuarios);
UPDATE usuarios SET role='ADMINISTRADOR' WHERE id=@dnb_first_admin;
UPDATE orcamentos o JOIN usuarios u ON u.vendedor_id=o.vendedor_id
SET o.assigned_to_user_id=u.id WHERE o.assigned_to_user_id IS NULL;
UPDATE pedidos p JOIN usuarios u ON u.vendedor_id=p.vendedor_id
SET p.assigned_to_user_id=u.id WHERE p.assigned_to_user_id IS NULL;
UPDATE visitas v JOIN usuarios u ON u.vendedor_id=v.vendedor_id
SET v.assigned_to_user_id=u.id WHERE v.assigned_to_user_id IS NULL;

CREATE TABLE IF NOT EXISTS lead_scores (
  id INT AUTO_INCREMENT PRIMARY KEY, lead_id INT NOT NULL, score TINYINT UNSIGNED NOT NULL DEFAULT 0,
  justification TEXT, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lead_scores_lead (lead_id),
  CONSTRAINT fk_lead_scores_lead FOREIGN KEY (lead_id) REFERENCES orcamentos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lead_timeline (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, lead_id INT NOT NULL, event_type VARCHAR(60) NOT NULL,
  description TEXT NOT NULL, actor VARCHAR(180) NOT NULL DEFAULT 'Sistema', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lead_timeline (lead_id, created_at),
  CONSTRAINT fk_lead_timeline_lead FOREIGN KEY (lead_id) REFERENCES orcamentos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_interactions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, lead_id INT NOT NULL, requested_by_user_id INT NOT NULL,
  customer_query TEXT NOT NULL, route_name VARCHAR(80), response_text MEDIUMTEXT, lead_score TINYINT UNSIGNED,
  score_justification TEXT, guardrail_status VARCHAR(30), guardrail_reason TEXT, escalated TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('processing','completed','failed') NOT NULL DEFAULT 'processing', error_message TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, processed_at DATETIME,
  KEY idx_ai_lead_created (lead_id, created_at),
  KEY idx_ai_user_created (requested_by_user_id, created_at),
  CONSTRAINT fk_ai_lead FOREIGN KEY (lead_id) REFERENCES orcamentos(id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_requested_by FOREIGN KEY (requested_by_user_id) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS support_cases (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, lead_id INT NOT NULL, agent_id INT, ai_interaction_id BIGINT,
  reason_blocked TEXT, customer_query TEXT NOT NULL,
  status ENUM('open','in_progress','resolved') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_support_cases_status (status, created_at), KEY idx_support_cases_lead (lead_id),
  CONSTRAINT fk_support_case_lead FOREIGN KEY (lead_id) REFERENCES orcamentos(id) ON DELETE CASCADE,
  CONSTRAINT fk_support_case_agent FOREIGN KEY (agent_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_support_case_interaction FOREIGN KEY (ai_interaction_id) REFERENCES ai_interactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, type VARCHAR(50) NOT NULL,
  title VARCHAR(160) NOT NULL, message TEXT NOT NULL, link VARCHAR(255), read_at DATETIME,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notifications_user (user_id, read_at, created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
