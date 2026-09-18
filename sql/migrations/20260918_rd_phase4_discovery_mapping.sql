-- DNB CRM · Fase 4 — Descoberta e mapeamento RD Station
-- Armazena apenas metadados de estrutura e mapas; não importa registros comerciais.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS rd_discovery_runs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  product ENUM('marketing','crm') NOT NULL,
  status ENUM('running','success','partial','error') NOT NULL DEFAULT 'running',
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME DEFAULT NULL,
  total_items INT NOT NULL DEFAULT 0,
  summary_json LONGTEXT,
  error_message VARCHAR(500) DEFAULT NULL,
  executed_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rd_discovery_runs_product (product,started_at),
  CONSTRAINT fk_rd_discovery_runs_user FOREIGN KEY (executed_by_user_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rd_discovery_items (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  product ENUM('marketing','crm') NOT NULL,
  resource_type VARCHAR(40) NOT NULL,
  external_id VARCHAR(191) NOT NULL,
  parent_external_id VARCHAR(191) NOT NULL DEFAULT '',
  entity_type VARCHAR(60) NOT NULL DEFAULT '',
  name VARCHAR(255) NOT NULL DEFAULT '',
  api_identifier VARCHAR(191) NOT NULL DEFAULT '',
  data_type VARCHAR(80) NOT NULL DEFAULT '',
  is_custom TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  selected_for_sync TINYINT(1) NOT NULL DEFAULT 0,
  payload_json LONGTEXT,
  last_run_id BIGINT DEFAULT NULL,
  discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rd_discovery_item (product,resource_type,external_id,parent_external_id),
  KEY idx_rd_discovery_type (product,resource_type,active),
  KEY idx_rd_discovery_entity (product,entity_type,active),
  KEY idx_rd_discovery_run (last_run_id),
  CONSTRAINT fk_rd_discovery_item_run FOREIGN KEY (last_run_id) REFERENCES rd_discovery_runs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rd_field_mappings (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  product ENUM('marketing','crm') NOT NULL,
  source_entity VARCHAR(60) NOT NULL,
  source_key VARCHAR(191) NOT NULL,
  source_label VARCHAR(255) NOT NULL DEFAULT '',
  target_entity ENUM('contact','company','negotiation') NOT NULL,
  target_field VARCHAR(120) NOT NULL,
  source_of_truth ENUM('rd','dnb','manual_review') NOT NULL DEFAULT 'rd',
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rd_field_mapping (product,source_entity,source_key),
  KEY idx_rd_field_target (target_entity,target_field,enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rd_user_mappings (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  rd_user_external_id VARCHAR(191) NOT NULL,
  rd_user_name VARCHAR(255) NOT NULL DEFAULT '',
  rd_user_email VARCHAR(190) NOT NULL DEFAULT '',
  local_user_id INT DEFAULT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  rd_user_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rd_user_mapping_remote (rd_user_external_id),
  KEY idx_rd_user_mapping_local (local_user_id),
  CONSTRAINT fk_rd_user_mapping_local FOREIGN KEY (local_user_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rd_pipeline_mappings (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  rd_pipeline_external_id VARCHAR(191) NOT NULL,
  rd_stage_external_id VARCHAR(191) NOT NULL DEFAULT '',
  pipeline_name VARCHAR(160) NOT NULL DEFAULT '',
  stage_name VARCHAR(160) NOT NULL DEFAULT '',
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rd_pipeline_stage (rd_pipeline_external_id,rd_stage_external_id),
  KEY idx_rd_pipeline_enabled (enabled,pipeline_name,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS=1;
