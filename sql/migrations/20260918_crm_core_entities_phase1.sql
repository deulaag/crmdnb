-- DNB CRM · Fase 1 do novo núcleo comercial
-- Banco + dnb_id + Empresas + Contatos + Negociações
-- Compatível com MySQL 5.7+/8.0 e MariaDB 10.2+.
-- Não remove dados comerciais existentes.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

-- ---------------------------------------------------------------------------
-- Diretório principal. Mantemos os nomes técnicos existentes para não quebrar
-- OS/Visitas/Oportunidades já em produção.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS crm_companies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dnb_id VARCHAR(24) DEFAULT NULL,
  cnpj VARCHAR(18) DEFAULT NULL,
  cnpj_digits VARCHAR(14) DEFAULT NULL,
  name VARCHAR(200) NOT NULL,
  trade_name VARCHAR(200) DEFAULT '',
  primary_email VARCHAR(190) DEFAULT '',
  phone VARCHAR(40) DEFAULT '',
  website VARCHAR(255) DEFAULT '',
  address VARCHAR(255) DEFAULT '',
  locality VARCHAR(180) DEFAULT '',
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  review_status ENUM('ok','needs_review') NOT NULL DEFAULT 'ok',
  review_reason VARCHAR(255) DEFAULT NULL,
  source VARCHAR(30) NOT NULL DEFAULT 'manual',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_crm_companies_dnb_id (dnb_id),
  KEY idx_crm_companies_cnpj_digits (cnpj_digits),
  KEY idx_crm_companies_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_contacts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dnb_id VARCHAR(24) DEFAULT NULL,
  company_id INT DEFAULT NULL,
  name VARCHAR(180) NOT NULL,
  phone VARCHAR(40) DEFAULT '',
  email VARCHAR(190) DEFAULT '',
  role_title VARCHAR(120) DEFAULT '',
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  review_status ENUM('ok','needs_review') NOT NULL DEFAULT 'ok',
  review_reason VARCHAR(255) DEFAULT NULL,
  source VARCHAR(30) NOT NULL DEFAULT 'manual',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_crm_contacts_dnb_id (dnb_id),
  KEY idx_crm_contacts_company (company_id,name),
  KEY idx_crm_contacts_email (email),
  CONSTRAINT fk_crm_contacts_company FOREIGN KEY (company_id) REFERENCES crm_companies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Helpers idempotentes para instalações que já possuem o diretório v3.6.
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS dnb_core_add_column;
DELIMITER $$
CREATE PROCEDURE dnb_core_add_column(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT)
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

DROP PROCEDURE IF EXISTS dnb_core_add_index;
DELIMITER $$
CREATE PROCEDURE dnb_core_add_index(IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_columns VARCHAR(255), IN p_unique TINYINT)
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

DROP PROCEDURE IF EXISTS dnb_core_drop_index;
DELIMITER $$
CREATE PROCEDURE dnb_core_drop_index(IN p_table VARCHAR(64), IN p_index VARCHAR(64))
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_table AND INDEX_NAME=p_index
  ) THEN
    SET @ddl=CONCAT('ALTER TABLE `',p_table,'` DROP INDEX `',p_index,'`');
    PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END$$
DELIMITER ;

DROP PROCEDURE IF EXISTS dnb_core_drop_fk;
DELIMITER $$
CREATE PROCEDURE dnb_core_drop_fk(IN p_table VARCHAR(64), IN p_constraint VARCHAR(64))
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=p_table
      AND CONSTRAINT_NAME=p_constraint AND CONSTRAINT_TYPE='FOREIGN KEY'
  ) THEN
    SET @ddl=CONCAT('ALTER TABLE `',p_table,'` DROP FOREIGN KEY `',p_constraint,'`');
    PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END$$
DELIMITER ;

DROP PROCEDURE IF EXISTS dnb_core_add_fk;
DELIMITER $$
CREATE PROCEDURE dnb_core_add_fk(IN p_table VARCHAR(64), IN p_constraint VARCHAR(64), IN p_definition TEXT)
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

-- Empresas: CNPJ deixa de ser UNIQUE. Duplicidade será sinalizada, nunca fundida
-- silenciosamente por restrição de banco.
CALL dnb_core_add_column('crm_companies','dnb_id','VARCHAR(24) DEFAULT NULL AFTER id');
CALL dnb_core_add_column('crm_companies','cnpj_digits','VARCHAR(14) DEFAULT NULL AFTER cnpj');
CALL dnb_core_add_column('crm_companies','primary_email','VARCHAR(190) DEFAULT '''' AFTER trade_name');
CALL dnb_core_add_column('crm_companies','phone','VARCHAR(40) DEFAULT '''' AFTER primary_email');
CALL dnb_core_add_column('crm_companies','website','VARCHAR(255) DEFAULT '''' AFTER phone');
CALL dnb_core_add_column('crm_companies','status','ENUM(''active'',''inactive'') NOT NULL DEFAULT ''active'' AFTER locality');
CALL dnb_core_add_column('crm_companies','review_status','ENUM(''ok'',''needs_review'') NOT NULL DEFAULT ''ok'' AFTER status');
CALL dnb_core_add_column('crm_companies','review_reason','VARCHAR(255) DEFAULT NULL AFTER review_status');
CALL dnb_core_add_column('crm_companies','source','VARCHAR(30) NOT NULL DEFAULT ''manual'' AFTER review_reason');
CALL dnb_core_drop_index('crm_companies','uq_crm_companies_cnpj');
CALL dnb_core_add_index('crm_companies','uq_crm_companies_dnb_id','`dnb_id`',1);
CALL dnb_core_add_index('crm_companies','idx_crm_companies_cnpj_digits','`cnpj_digits`',0);

-- Contatos: e-mail é o identificador comercial principal, porém duplicidades ficam
-- auditáveis. company_id pode ser NULL para "Contatos sem empresa".
CALL dnb_core_add_column('crm_contacts','dnb_id','VARCHAR(24) DEFAULT NULL AFTER id');
CALL dnb_core_add_column('crm_contacts','status','ENUM(''active'',''inactive'') NOT NULL DEFAULT ''active'' AFTER role_title');
CALL dnb_core_add_column('crm_contacts','review_status','ENUM(''ok'',''needs_review'') NOT NULL DEFAULT ''ok'' AFTER status');
CALL dnb_core_add_column('crm_contacts','review_reason','VARCHAR(255) DEFAULT NULL AFTER review_status');
CALL dnb_core_add_column('crm_contacts','source','VARCHAR(30) NOT NULL DEFAULT ''manual'' AFTER review_reason');
CALL dnb_core_add_index('crm_contacts','uq_crm_contacts_dnb_id','`dnb_id`',1);
CALL dnb_core_add_index('crm_contacts','idx_crm_contacts_email','`email`',0);
CALL dnb_core_drop_fk('crm_contacts','fk_crm_contacts_company');
ALTER TABLE crm_contacts MODIFY company_id INT DEFAULT NULL;
CALL dnb_core_add_fk('crm_contacts','fk_crm_contacts_company','FOREIGN KEY (`company_id`) REFERENCES `crm_companies`(`id`) ON DELETE SET NULL');

-- ---------------------------------------------------------------------------
-- Negociações: entidade acima de Orçamento/Proposta.
-- Uma negociação pode existir sem orçamento e possuir vários contatos.
-- Categoria é única por negociação conforme levantamento.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS crm_negotiations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dnb_id VARCHAR(24) DEFAULT NULL,
  title VARCHAR(220) NOT NULL,
  company_id INT DEFAULT NULL,
  category VARCHAR(120) DEFAULT '',
  pipeline_name VARCHAR(160) DEFAULT '',
  stage_name VARCHAR(160) DEFAULT '',
  estimated_value DECIMAL(14,2) NOT NULL DEFAULT 0,
  status ENUM('open','won','lost') NOT NULL DEFAULT 'open',
  lost_reason VARCHAR(255) DEFAULT '',
  order_status ENUM('none','pending','generated') NOT NULL DEFAULT 'none',
  vendedor_id VARCHAR(20) DEFAULT NULL,
  assigned_to_user_id INT DEFAULT NULL,
  origin VARCHAR(40) NOT NULL DEFAULT 'manual',
  source_orcamento_id INT DEFAULT NULL,
  business_created_at DATETIME DEFAULT NULL,
  business_updated_at DATETIME DEFAULT NULL,
  closed_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_crm_negotiations_dnb_id (dnb_id),
  UNIQUE KEY uq_crm_negotiations_source_orc (source_orcamento_id),
  KEY idx_crm_negotiations_company (company_id,status),
  KEY idx_crm_negotiations_owner (assigned_to_user_id,status),
  KEY idx_crm_negotiations_pipeline (pipeline_name,stage_name,status),
  KEY idx_crm_negotiations_dates (business_updated_at,closed_at),
  CONSTRAINT fk_crm_negotiations_company FOREIGN KEY (company_id) REFERENCES crm_companies(id) ON DELETE SET NULL,
  CONSTRAINT fk_crm_negotiations_user FOREIGN KEY (assigned_to_user_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_negotiation_contacts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  negotiation_id INT NOT NULL,
  contact_id INT NOT NULL,
  role ENUM('principal','decisor','comprador','tecnico','financeiro','outro') NOT NULL DEFAULT 'outro',
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_crm_neg_contact (negotiation_id,contact_id),
  KEY idx_crm_neg_contact_contact (contact_id),
  CONSTRAINT fk_crm_neg_contact_negotiation FOREIGN KEY (negotiation_id) REFERENCES crm_negotiations(id) ON DELETE CASCADE,
  CONSTRAINT fk_crm_neg_contact_contact FOREIGN KEY (contact_id) REFERENCES crm_contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- IDs externos ficam isolados aqui. As entidades comerciais usam somente dnb_id.
CREATE TABLE IF NOT EXISTS crm_external_links (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(40) NOT NULL,
  external_entity VARCHAR(60) NOT NULL,
  external_id VARCHAR(191) NOT NULL,
  local_entity VARCHAR(60) NOT NULL,
  dnb_id VARCHAR(24) NOT NULL,
  last_sync_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_crm_external_remote (provider,external_entity,external_id),
  UNIQUE KEY uq_crm_external_local (provider,local_entity,dnb_id),
  KEY idx_crm_external_dnb (dnb_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Ligações com módulos existentes. Todos os campos são opcionais para manter
-- compatibilidade durante a transição.
-- ---------------------------------------------------------------------------
CALL dnb_core_add_column('orcamentos','dnb_id','VARCHAR(24) DEFAULT NULL AFTER id');
CALL dnb_core_add_column('orcamentos','company_id','INT DEFAULT NULL AFTER cliente');
CALL dnb_core_add_column('orcamentos','contact_id','INT DEFAULT NULL AFTER company_id');
CALL dnb_core_add_column('orcamentos','negotiation_id','INT DEFAULT NULL AFTER contact_id');
CALL dnb_core_add_index('orcamentos','uq_orc_dnb_id','`dnb_id`',1);
CALL dnb_core_add_index('orcamentos','idx_orc_negotiation','`negotiation_id`',0);

CALL dnb_core_add_column('pedidos','dnb_id','VARCHAR(24) DEFAULT NULL AFTER id');
CALL dnb_core_add_column('pedidos','company_id','INT DEFAULT NULL AFTER cliente');
CALL dnb_core_add_column('pedidos','negotiation_id','INT DEFAULT NULL AFTER company_id');
CALL dnb_core_add_index('pedidos','uq_ped_dnb_id','`dnb_id`',1);
CALL dnb_core_add_index('pedidos','idx_ped_negotiation','`negotiation_id`',0);
CALL dnb_core_add_index('pedidos','idx_ped_company','`company_id`',0);

CALL dnb_core_add_column('visitas','dnb_id','VARCHAR(24) DEFAULT NULL AFTER id');
CALL dnb_core_add_column('visitas','company_id','INT DEFAULT NULL AFTER cliente');
CALL dnb_core_add_column('visitas','contact_id','INT DEFAULT NULL AFTER company_id');
CALL dnb_core_add_column('visitas','negotiation_id','INT DEFAULT NULL AFTER contact_id');
CALL dnb_core_add_index('visitas','uq_vis_dnb_id','`dnb_id`',1);
CALL dnb_core_add_index('visitas','idx_vis_negotiation','`negotiation_id`',0);

CALL dnb_core_add_fk('orcamentos','fk_orc_company','FOREIGN KEY (`company_id`) REFERENCES `crm_companies`(`id`) ON DELETE SET NULL');
CALL dnb_core_add_fk('orcamentos','fk_orc_contact','FOREIGN KEY (`contact_id`) REFERENCES `crm_contacts`(`id`) ON DELETE SET NULL');
CALL dnb_core_add_fk('orcamentos','fk_orc_negotiation','FOREIGN KEY (`negotiation_id`) REFERENCES `crm_negotiations`(`id`) ON DELETE SET NULL');
CALL dnb_core_add_fk('pedidos','fk_ped_core_company','FOREIGN KEY (`company_id`) REFERENCES `crm_companies`(`id`) ON DELETE SET NULL');
CALL dnb_core_add_fk('pedidos','fk_ped_negotiation','FOREIGN KEY (`negotiation_id`) REFERENCES `crm_negotiations`(`id`) ON DELETE SET NULL');
CALL dnb_core_add_fk('visitas','fk_vis_company','FOREIGN KEY (`company_id`) REFERENCES `crm_companies`(`id`) ON DELETE SET NULL');
CALL dnb_core_add_fk('visitas','fk_vis_contact','FOREIGN KEY (`contact_id`) REFERENCES `crm_contacts`(`id`) ON DELETE SET NULL');
CALL dnb_core_add_fk('visitas','fk_vis_negotiation','FOREIGN KEY (`negotiation_id`) REFERENCES `crm_negotiations`(`id`) ON DELETE SET NULL');

-- ---------------------------------------------------------------------------
-- Backfill determinístico dos identificadores DNB.
-- ---------------------------------------------------------------------------
UPDATE crm_companies
SET cnpj_digits=NULLIF(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(cnpj,''),'.',''),'/',''),'-',''),' ',''),'')
WHERE cnpj_digits IS NULL OR cnpj_digits='';

UPDATE crm_companies SET dnb_id=CONCAT('EMP-',LPAD(id,6,'0')) WHERE dnb_id IS NULL OR dnb_id='';
UPDATE crm_contacts SET dnb_id=CONCAT('CON-',LPAD(id,6,'0')) WHERE dnb_id IS NULL OR dnb_id='';
UPDATE orcamentos SET dnb_id=CONCAT('ORC-',LPAD(id,6,'0')) WHERE dnb_id IS NULL OR dnb_id='';
UPDATE pedidos SET dnb_id=CONCAT('PED-',LPAD(id,6,'0')) WHERE dnb_id IS NULL OR dnb_id='';
UPDATE visitas SET dnb_id=CONCAT('VIS-',LPAD(id,6,'0')) WHERE dnb_id IS NULL OR dnb_id='';

-- Sinaliza CNPJ/e-mail duplicados para revisão em vez de fundir registros.
UPDATE crm_companies c
JOIN (
  SELECT cnpj_digits FROM crm_companies
  WHERE cnpj_digits IS NOT NULL AND cnpj_digits<>''
  GROUP BY cnpj_digits HAVING COUNT(*)>1
) d ON d.cnpj_digits=c.cnpj_digits
SET c.review_status='needs_review',
    c.review_reason='CNPJ duplicado: conferir antes de mesclar';

UPDATE crm_contacts c
JOIN (
  SELECT LOWER(TRIM(email)) email_key FROM crm_contacts
  WHERE TRIM(COALESCE(email,''))<>''
  GROUP BY LOWER(TRIM(email)) HAVING COUNT(*)>1
) d ON d.email_key=LOWER(TRIM(c.email))
SET c.review_status='needs_review',
    c.review_reason='E-mail duplicado: conferir antes de mesclar';

-- ---------------------------------------------------------------------------
-- Transição segura: cada Orçamento atual ganha inicialmente uma Negociação.
-- Isso preserva 100% do CRM atual e permite, na Fase 2, reunir propostas quando
-- a operação identificar que pertencem ao mesmo negócio.
-- ---------------------------------------------------------------------------
INSERT INTO crm_negotiations
  (dnb_id,title,company_id,category,pipeline_name,stage_name,estimated_value,status,
   vendedor_id,assigned_to_user_id,origin,source_orcamento_id,business_created_at,created_at,updated_at)
SELECT
  CONCAT('NEG-LEG-',LPAD(o.id,6,'0')),
  CONCAT(CASE WHEN TRIM(COALESCE(o.numero,''))<>'' THEN CONCAT(o.numero,' · ') ELSE '' END,o.cliente),
  o.company_id,
  COALESCE(NULLIF(o.tipo,''),NULLIF(o.linha,''),''),
  COALESCE(o.funil,''),
  COALESCE(o.etapa,''),
  COALESCE(o.valor,0),
  CASE WHEN o.status='Ganha' THEN 'won' WHEN o.status='Perdida' THEN 'lost' ELSE 'open' END,
  o.vendedor_id,
  o.assigned_to_user_id,
  'legacy_orcamento',
  o.id,
  CONCAT(o.data,' 00:00:00'),
  COALESCE(o.criado_em,NOW()),
  COALESCE(o.criado_em,NOW())
FROM orcamentos o
LEFT JOIN crm_negotiations n ON n.source_orcamento_id=o.id
WHERE n.id IS NULL;

UPDATE crm_negotiations SET dnb_id=CONCAT('NEG-',LPAD(id,6,'0'))
WHERE dnb_id IS NULL OR dnb_id='' OR dnb_id LIKE 'NEG-LEG-%';

UPDATE orcamentos o
JOIN crm_negotiations n ON n.source_orcamento_id=o.id
SET o.negotiation_id=n.id
WHERE o.negotiation_id IS NULL;

INSERT IGNORE INTO crm_negotiation_contacts (negotiation_id,contact_id,role,is_primary)
SELECT o.negotiation_id,o.contact_id,'principal',1
FROM orcamentos o
WHERE o.negotiation_id IS NOT NULL AND o.contact_id IS NOT NULL;

UPDATE pedidos p
JOIN orcamentos o ON o.id=p.orcamento_id
SET p.negotiation_id=COALESCE(p.negotiation_id,o.negotiation_id),
    p.company_id=COALESCE(p.company_id,o.company_id)
WHERE p.orcamento_id IS NOT NULL;

UPDATE visitas v
JOIN orcamentos o ON o.source_visit_id=v.id
SET v.negotiation_id=COALESCE(v.negotiation_id,o.negotiation_id),
    v.company_id=COALESCE(v.company_id,o.company_id),
    v.contact_id=COALESCE(v.contact_id,o.contact_id)
WHERE o.source_visit_id IS NOT NULL;

DROP PROCEDURE dnb_core_add_column;
DROP PROCEDURE dnb_core_add_index;
DROP PROCEDURE dnb_core_drop_index;
DROP PROCEDURE dnb_core_drop_fk;
DROP PROCEDURE dnb_core_add_fk;

SET FOREIGN_KEY_CHECKS=1;
