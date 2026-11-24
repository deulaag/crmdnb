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

-- Registro das migrações aplicadas (mesmos nomes dos arquivos em novocrm/migrations/)
INSERT IGNORE INTO nc_migrations (name) VALUES
  ('001_opportunity_position.sql'),
  ('002_company_portfolio_status.sql'),
  ('003_order_items.sql'),
  ('004_user_monthly_target.sql');
