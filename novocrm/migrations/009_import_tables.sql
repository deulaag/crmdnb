-- Central de Importação (v3): lotes, linhas de staging e modelos de mapeamento.
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
