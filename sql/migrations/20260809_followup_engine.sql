-- DNB CRM v3.2.0 — Motor automático de follow-up com IA
-- Arquivo: 20260809_followup_engine.sql
-- Execute uma única vez no phpMyAdmin do cPanel, após 20260809_whatsapp_rbac.sql.
-- Faça backup do banco antes da importação.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS pipeline_stages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pipeline_key VARCHAR(20) NOT NULL,
  stage_name VARCHAR(80) NOT NULL,
  stage_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pipeline_stage (pipeline_key, stage_name),
  KEY idx_pipeline_stage_active (pipeline_key, is_active, stage_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO pipeline_stages (pipeline_key,stage_name,stage_order,is_active) VALUES
('equipamentos','Prospecção',1,1),
('equipamentos','Levantamento técnico / Visita',2,1),
('equipamentos','Proposta emitida',3,1),
('equipamentos','Negociação',4,1),
('equipamentos','Fechamento',5,1),
('servicos','Solicitação',1,1),
('servicos','Levantamento / Vistoria',2,1),
('servicos','Proposta emitida',3,1),
('servicos','Negociação',4,1),
('servicos','Fechamento',5,1),
('pecas','Solicitação',1,1),
('pecas','Cotação enviada',2,1),
('pecas','Follow-up',3,1),
('pecas','Fechamento',4,1)
ON DUPLICATE KEY UPDATE stage_order=VALUES(stage_order),is_active=VALUES(is_active);

CREATE TABLE IF NOT EXISTS followup_rules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pipeline_stage_id INT NOT NULL,
  inactivity_hours INT UNSIGNED NOT NULL DEFAULT 24,
  min_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_followup_rule_stage (pipeline_stage_id),
  KEY idx_followup_rule_active (is_active, pipeline_stage_id),
  CONSTRAINT fk_followup_rule_stage FOREIGN KEY (pipeline_stage_id) REFERENCES pipeline_stages(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- As regras começam desativadas para impedir envios inesperados após a migração.
-- Revise os parâmetros em Cadência e ative somente as etapas desejadas.
INSERT INTO followup_rules (pipeline_stage_id,inactivity_hours,min_score,max_attempts,is_active)
SELECT ps.id,
  CASE ps.pipeline_key WHEN 'equipamentos' THEN 72 WHEN 'servicos' THEN 48 ELSE 24 END,
  0,3,0
FROM pipeline_stages ps
ON DUPLICATE KEY UPDATE pipeline_stage_id=VALUES(pipeline_stage_id);

CREATE TABLE IF NOT EXISTS followup_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  lead_id INT NOT NULL,
  rule_id INT NOT NULL,
  conversation_id BIGINT DEFAULT NULL,
  attempt_number TINYINT UNSIGNED NOT NULL,
  status ENUM('processing','ready','sending','sent','failed','paused','blocked','delivery_unknown') NOT NULL DEFAULT 'processing',
  message_sent MEDIUMTEXT DEFAULT NULL,
  provider_message_id VARCHAR(190) DEFAULT NULL,
  attempt_key CHAR(64) DEFAULT NULL,
  idempotency_key CHAR(64) NOT NULL,
  correlation_id CHAR(32) DEFAULT NULL,
  retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  error_message VARCHAR(1000) DEFAULT NULL,
  locked_at DATETIME DEFAULT NULL,
  sent_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_followup_attempt_key (attempt_key),
  UNIQUE KEY uq_followup_idempotency (idempotency_key),
  KEY idx_followup_logs_lead (lead_id, created_at),
  KEY idx_followup_logs_operational (status, updated_at),
  KEY idx_followup_logs_rule_attempt (rule_id, attempt_number, status),
  CONSTRAINT fk_followup_log_lead FOREIGN KEY (lead_id) REFERENCES orcamentos(id) ON DELETE CASCADE,
  CONSTRAINT fk_followup_log_rule FOREIGN KEY (rule_id) REFERENCES followup_rules(id) ON DELETE RESTRICT,
  CONSTRAINT fk_followup_log_conversation FOREIGN KEY (conversation_id) REFERENCES whatsapp_conversations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
