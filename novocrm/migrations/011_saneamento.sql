-- NOVOCRM v4 — Saneamento: apelidos de empresa, chave de cliente na revisão, fila de CNPJ e registro das mesclagens. Somente aditivo.
CREATE TABLE IF NOT EXISTS company_aliases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  alias_key VARCHAR(190) NOT NULL,
  alias_name VARCHAR(190) NOT NULL,
  batch_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_alias_key (alias_key),
  KEY idx_alias_company (company_id),
  KEY idx_alias_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE import_rows ADD COLUMN client_key VARCHAR(190) NULL;
CREATE INDEX idx_batch_client ON import_rows (batch_id, client_key);
CREATE TABLE IF NOT EXISTS company_enrich (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  step VARCHAR(20) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pendente',
  source VARCHAR(20) NULL,
  suggested_cnpj VARCHAR(14) NULL,
  confidence INT NULL,
  evidence_json LONGTEXT NULL,
  error TEXT NULL,
  attempts INT NOT NULL DEFAULT 0,
  checked_at DATETIME NULL,
  decided_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_enrich_company_step (company_id, step),
  KEY idx_enrich_status (step, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS company_merge_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  principal_id BIGINT UNSIGNED NOT NULL,
  dup_id BIGINT UNSIGNED NOT NULL,
  dup_json LONGTEXT NOT NULL,
  moved_contacts_json LONGTEXT NULL,
  moved_opps_json LONGTEXT NULL,
  merged_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_merge_principal (principal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
