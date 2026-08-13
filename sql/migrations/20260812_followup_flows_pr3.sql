-- DNB CRM v3.3.0 — Fluxos versionados, ações operacionais e auditoria visual
-- Arquivo: 20260812_followup_flows_pr3.sql
-- Execute uma única vez no phpMyAdmin do cPanel, após 20260809_followup_engine.sql.
-- A migração é aditiva: não remove nem altera dados ou regras existentes.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS followup_flows (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  rule_id INT NOT NULL,
  name VARCHAR(180) NOT NULL,
  status ENUM('draft','active','paused') NOT NULL DEFAULT 'draft',
  draft_graph JSON NOT NULL,
  active_version_id BIGINT DEFAULT NULL,
  created_by_user_id INT DEFAULT NULL,
  updated_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_followup_flow_rule (rule_id),
  KEY idx_followup_flow_status (status, updated_at),
  CONSTRAINT fk_followup_flow_rule FOREIGN KEY (rule_id) REFERENCES followup_rules(id) ON DELETE RESTRICT,
  CONSTRAINT fk_followup_flow_created_by FOREIGN KEY (created_by_user_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_followup_flow_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS followup_flow_versions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  flow_id BIGINT NOT NULL,
  version_number INT UNSIGNED NOT NULL,
  graph_json JSON NOT NULL,
  published_by_user_id INT DEFAULT NULL,
  published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_followup_flow_version (flow_id, version_number),
  KEY idx_followup_flow_version_date (flow_id, published_at),
  CONSTRAINT fk_followup_flow_version_flow FOREIGN KEY (flow_id) REFERENCES followup_flows(id) ON DELETE RESTRICT,
  CONSTRAINT fk_followup_flow_version_user FOREIGN KEY (published_by_user_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS followup_rule_actions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  rule_id INT NOT NULL,
  node_key VARCHAR(80) NOT NULL,
  action_type ENUM('move_stage','assign_owner','human_handoff','notify_internal') NOT NULL,
  action_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
  config_json JSON NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_followup_rule_action_node (rule_id, node_key),
  KEY idx_followup_rule_action_active (rule_id, is_active, action_order),
  CONSTRAINT fk_followup_rule_action_rule FOREIGN KEY (rule_id) REFERENCES followup_rules(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS followup_flow_runs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  flow_id BIGINT NOT NULL,
  flow_version_id BIGINT NOT NULL,
  legacy_log_id BIGINT NOT NULL,
  lead_id INT NOT NULL,
  rule_id INT NOT NULL,
  status ENUM('running','success','failed','paused','blocked','delivery_unknown') NOT NULL DEFAULT 'running',
  correlation_id CHAR(32) DEFAULT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_followup_flow_run_legacy_log (legacy_log_id),
  KEY idx_followup_flow_run_status (status, started_at),
  KEY idx_followup_flow_run_lead (lead_id, started_at),
  CONSTRAINT fk_followup_flow_run_flow FOREIGN KEY (flow_id) REFERENCES followup_flows(id) ON DELETE RESTRICT,
  CONSTRAINT fk_followup_flow_run_version FOREIGN KEY (flow_version_id) REFERENCES followup_flow_versions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_followup_flow_run_rule FOREIGN KEY (rule_id) REFERENCES followup_rules(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS followup_flow_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  run_id BIGINT NOT NULL,
  node_key VARCHAR(80) NOT NULL,
  node_type VARCHAR(40) NOT NULL,
  status ENUM('running','success','failed','skipped','blocked','paused') NOT NULL,
  input_summary VARCHAR(1000) DEFAULT NULL,
  output_summary VARCHAR(1000) DEFAULT NULL,
  error_code VARCHAR(80) DEFAULT NULL,
  duration_ms INT UNSIGNED DEFAULT NULL,
  idempotency_key CHAR(64) NOT NULL,
  actor_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_followup_flow_event_idempotency (idempotency_key),
  KEY idx_followup_flow_event_run (run_id, id),
  KEY idx_followup_flow_event_status (status, created_at),
  CONSTRAINT fk_followup_flow_event_run FOREIGN KEY (run_id) REFERENCES followup_flow_runs(id) ON DELETE RESTRICT,
  CONSTRAINT fk_followup_flow_event_actor FOREIGN KEY (actor_user_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS followup_flow_audit_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  flow_id BIGINT NOT NULL,
  flow_version_id BIGINT DEFAULT NULL,
  event_type ENUM('draft_saved','published','paused','resumed','rollback') NOT NULL,
  summary VARCHAR(1000) DEFAULT NULL,
  actor_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_followup_flow_audit_flow (flow_id, id),
  KEY idx_followup_flow_audit_date (event_type, created_at),
  CONSTRAINT fk_followup_flow_audit_flow FOREIGN KEY (flow_id) REFERENCES followup_flows(id) ON DELETE RESTRICT,
  CONSTRAINT fk_followup_flow_audit_version FOREIGN KEY (flow_version_id) REFERENCES followup_flow_versions(id) ON DELETE SET NULL,
  CONSTRAINT fk_followup_flow_audit_actor FOREIGN KEY (actor_user_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
