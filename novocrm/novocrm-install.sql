-- ============================================================================
-- NOVOCRM — alterações de banco (TODAS ADITIVAS e IDEMPOTENTES)
--
-- Como usar:
--   1. Rode o install.php do CRM normalmente (cria as tabelas-base).
--   2. Importe ESTE arquivo no banco do CRM (phpMyAdmin > Importar, ou: mysql -u USUARIO -p BANCO < novocrm-install.sql).
--   3. Pode importar de novo sem problema: cada comando só age se a coluna/tabela ainda não existir.
--
-- Alternativa sem importar: logado como admin, abra /novocrm/migrations/run.php e clique em "Aplicar pendentes".
-- Os dois caminhos fazem exatamente o mesmo e registram em nc_migrations.
--
-- Nada aqui apaga, renomeia ou altera tipo de coluna existente: o CRM clássico continua funcionando.
-- ============================================================================

-- Controle de migrações do NOVOCRM
CREATE TABLE IF NOT EXISTS nc_migrations (
  name VARCHAR(190) NOT NULL PRIMARY KEY,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 001 — ordem manual dos cards no kanban (opportunities.position)
SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `opportunities` ADD COLUMN `position` INT NULL',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'opportunities' AND COLUMN_NAME = 'position');
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;

-- 002 — status manual da carteira de clientes (companies.portfolio_status)
SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `companies` ADD COLUMN `portfolio_status` VARCHAR(20) NULL',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'portfolio_status');
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;

-- 003 — itens opcionais do pedido (orders.amount continua sendo a fonte do valor)
CREATE TABLE IF NOT EXISTS order_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  description VARCHAR(255) NOT NULL,
  sku VARCHAR(80) NULL,
  quantity DECIMAL(12,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
  subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 004 — meta mensal por pessoa (users.monthly_target)
SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `users` ADD COLUMN `monthly_target` DECIMAL(15,2) NULL',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'monthly_target');
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;

-- 004 (v2) — metas de faturamento por mês/ano (vendedor ou equipe). Substitui users.monthly_target na leitura.
-- UNIQUE com NULL não impede duplicidade no MySQL: o upsert é feito em código.
CREATE TABLE IF NOT EXISTS sales_targets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  year SMALLINT UNSIGNED NOT NULL,
  month TINYINT UNSIGNED NULL,
  amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_target (user_id, year, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 004b — copia users.monthly_target para sales_targets (mês corrente), sem repetir e sem remover a coluna antiga
INSERT INTO sales_targets (user_id, year, month, amount, updated_by)
SELECT u.id, YEAR(CURDATE()), MONTH(CURDATE()), u.monthly_target, NULL
FROM users u
WHERE u.monthly_target IS NOT NULL
AND NOT EXISTS (SELECT 1 FROM sales_targets t WHERE t.user_id = u.id AND t.year = YEAR(CURDATE()) AND t.month = MONTH(CURDATE()));

-- 006 — fechamento mensal de comissões (snapshot por pessoa e mês)
CREATE TABLE IF NOT EXISTS commission_closings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  reference_month CHAR(7) NOT NULL,
  invoiced_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  commission_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  details_json LONGTEXT NULL,
  status ENUM('fechada','paga') NOT NULL DEFAULT 'fechada',
  closed_by INT UNSIGNED NULL,
  closed_at DATETIME NOT NULL,
  paid_at DATE NULL,
  paid_by INT UNSIGNED NULL,
  UNIQUE KEY uq_closing (user_id, reference_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 007 — probabilidade da etapa exibida no card do kanban (funnel_stages.probability)
SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `funnel_stages` ADD COLUMN `probability` TINYINT UNSIGNED NULL',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funnel_stages' AND COLUMN_NAME = 'probability');
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;

-- 008 (v3) — rastreio de importação, IDs externos e índices de busca (empresas, contatos, oportunidades)
SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `companies` ADD COLUMN `external_id` VARCHAR(120) NULL',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'external_id');
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;

SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `companies` ADD COLUMN `person_document` VARCHAR(14) NULL',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'person_document');
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;

SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `companies` ADD COLUMN `merged_ids_json` LONGTEXT NULL',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'merged_ids_json');
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;

SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `companies` ADD COLUMN `import_batch_id` INT UNSIGNED NULL',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'import_batch_id');
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;

SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `companies` ADD COLUMN `import_synced_at` DATETIME NULL',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'import_synced_at');
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;

SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `contacts` ADD COLUMN `external_id` VARCHAR(120) NULL',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'external_id');
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;

SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `contacts` ADD COLUMN `import_batch_id` INT UNSIGNED NULL',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'import_batch_id');
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;

SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `contacts` ADD COLUMN `import_synced_at` DATETIME NULL',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'import_synced_at');
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;

SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `contacts` ADD COLUMN `created_by` INT UNSIGNED NULL',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'created_by');
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;

SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `opportunities` ADD COLUMN `external_source` VARCHAR(50) NULL',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'opportunities' AND COLUMN_NAME = 'external_source');
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;

SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `opportunities` ADD COLUMN `external_ref` VARCHAR(120) NULL',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'opportunities' AND COLUMN_NAME = 'external_ref');
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;

SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `opportunities` ADD COLUMN `import_batch_id` INT UNSIGNED NULL',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'opportunities' AND COLUMN_NAME = 'import_batch_id');
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;

SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `opportunities` ADD COLUMN `import_synced_at` DATETIME NULL',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'opportunities' AND COLUMN_NAME = 'import_synced_at');
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;

-- Índices: só cria se ainda não existir índice cuja 1ª coluna seja a mesma (a raiz já tem idx_company_cnpj e idx_contact_email).
SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'CREATE INDEX `idx_companies_cnpj` ON `companies` (`cnpj`)',
  'SELECT 1')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'cnpj' AND SEQ_IN_INDEX = 1);
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;

SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'CREATE INDEX `idx_companies_external` ON `companies` (`external_id`)',
  'SELECT 1')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'external_id' AND SEQ_IN_INDEX = 1);
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;

SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'CREATE INDEX `idx_contacts_email` ON `contacts` (`email`)',
  'SELECT 1')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'email' AND SEQ_IN_INDEX = 1);
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;

SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'CREATE INDEX `idx_contacts_external` ON `contacts` (`external_id`)',
  'SELECT 1')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'external_id' AND SEQ_IN_INDEX = 1);
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;

SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'CREATE INDEX `idx_opp_external` ON `opportunities` (`external_source`, `external_ref`)',
  'SELECT 1')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'opportunities' AND INDEX_NAME = 'idx_opp_external');
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;


-- 010 (v3) — campos extras da planilha de contatos
SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `contacts` ADD COLUMN `first_name` VARCHAR(100) NULL',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'first_name');
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;

SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `companies` ADD COLUMN `interest_lines` VARCHAR(500) NULL',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'interest_lines');
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;

SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `companies` ADD COLUMN `source_quote_count` INT UNSIGNED NULL',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'source_quote_count');
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;

SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `companies` ADD COLUMN `source_last_quote_at` DATE NULL',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'source_last_quote_at');
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;

SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `companies` ADD COLUMN `source_origin` VARCHAR(190) NULL',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'source_origin');
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;

SET @nc_sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `companies` ADD COLUMN `import_note` VARCHAR(500) NULL',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'import_note');
PREPARE nc_st FROM @nc_sql; EXECUTE nc_st; DEALLOCATE PREPARE nc_st;

-- 009_import_tables: Central de Importação
CREATE TABLE IF NOT EXISTS import_batches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  import_type ENUM('contatos','orcamentos') NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  file_hash CHAR(64) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  sheet_name VARCHAR(120) NULL,
  template_id INT UNSIGNED NULL,
  status ENUM('enviado','mapeado','em_revisao','confirmado','falhou','desfeito') NOT NULL DEFAULT 'enviado',
  default_product_line_id INT UNSIGNED NULL,
  counters_json LONGTEXT NULL,
  error_message TEXT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmed_by INT UNSIGNED NULL,
  confirmed_at DATETIME NULL,
  undone_by INT UNSIGNED NULL,
  undone_at DATETIME NULL,
  KEY idx_hash (file_hash),
  KEY idx_type_status (import_type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS import_rows (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id INT UNSIGNED NOT NULL,
  sheet_row INT UNSIGNED NOT NULL,
  raw_json LONGTEXT NOT NULL,
  normalized_json LONGTEXT NULL,
  classification ENUM('novo','atualizar','sem_alteracao','revisar','erro','ignorar') NOT NULL,
  reason TEXT NULL,
  match_rule VARCHAR(40) NULL,
  company_id BIGINT UNSIGNED NULL,
  candidates_json LONGTEXT NULL,
  resolved_company_id BIGINT UNSIGNED NULL,
  resolution ENUM('escolhida','criada','ignorada') NULL,
  resolved_by INT UNSIGNED NULL,
  resolved_at DATETIME NULL,
  keep_crm_fields_json LONGTEXT NULL,
  result_json LONGTEXT NULL,
  previous_json LONGTEXT NULL,
  KEY idx_batch_class (batch_id, classification)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS import_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  import_type ENUM('contatos','orcamentos') NOT NULL,
  name VARCHAR(120) NOT NULL,
  header_signature CHAR(64) NOT NULL,
  mapping_json LONGTEXT NOT NULL,
  value_maps_json LONGTEXT NULL,
  external_source VARCHAR(50) NOT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tpl (import_type, header_signature)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Registro das migrações aplicadas (mesmos nomes dos arquivos em novocrm/migrations/)
INSERT IGNORE INTO nc_migrations (name) VALUES
  ('001_opportunity_position.sql'),
  ('002_company_portfolio_status.sql'),
  ('003_order_items.sql'),
  ('004_sales_targets.sql'),
  ('004_user_monthly_target.sql'),
  ('004b_migrate_monthly_target.sql'),
  ('006_commission_closings.sql'),
  ('007_stage_probability.sql'),
  ('008_import_tracking_columns.sql'),
  ('009_import_tables.sql'),
  ('010_import_extra_columns.sql');
