-- DNB CRM · Fase 9 — saída controlada CRM DNB → RD CRM
-- Requer Fases 1–8. IDs externos continuam isolados em crm_external_links.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS rd_outbound_field_rules (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  entity ENUM('company','contact','negotiation') NOT NULL,
  local_field VARCHAR(120) NOT NULL,
  rd_field VARCHAR(120) NOT NULL,
  source_of_truth ENUM('rd','dnb','manual_review') NOT NULL DEFAULT 'rd',
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rd_outbound_rule (entity,local_field),
  KEY idx_rd_outbound_rule_truth (entity,source_of_truth,enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rd_outbound_queue (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  entity ENUM('company','contact','negotiation') NOT NULL,
  dnb_id VARCHAR(24) NOT NULL,
  operation ENUM('upsert') NOT NULL DEFAULT 'upsert',
  status ENUM('pending','processing','success','error','blocked') NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  reason VARCHAR(160) NOT NULL DEFAULT '',
  changed_fields_json LONGTEXT,
  external_id VARCHAR(191) DEFAULT NULL,
  last_http_status INT DEFAULT NULL,
  last_error VARCHAR(500) DEFAULT NULL,
  requested_by_user_id INT DEFAULT NULL,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME DEFAULT NULL,
  processed_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_rd_outbound_queue_status (status,available_at,id),
  KEY idx_rd_outbound_queue_entity (entity,dnb_id,status,id),
  KEY idx_rd_outbound_queue_user (requested_by_user_id),
  CONSTRAINT fk_rd_outbound_queue_user FOREIGN KEY (requested_by_user_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO rd_outbound_field_rules (entity,local_field,rd_field,source_of_truth,enabled,sort_order) VALUES
('company','name','name','dnb',1,10),
('company','website','url','dnb',1,20),
('company','address','address','dnb',1,30),
('contact','name','name','dnb',1,10),
('contact','role_title','job_title','dnb',1,20),
('contact','email','emails','dnb',1,30),
('contact','phone','phones','dnb',1,40),
('contact','company_id','organization_id','dnb',1,50),
('negotiation','title','name','dnb',1,10),
('negotiation','estimated_value','total_price','dnb',1,20),
('negotiation','company_id','organization_id','dnb',1,30),
('negotiation','assigned_to_user_id','owner_id','dnb',1,40),
('negotiation','stage_name','stage_id','dnb',1,50),
('negotiation','primary_contact','contact_id','dnb',1,60),
('negotiation','status','status','rd',1,70),
('negotiation','lost_reason','lost_reason_id','rd',1,80)
ON DUPLICATE KEY UPDATE rd_field=VALUES(rd_field),sort_order=VALUES(sort_order);

SET FOREIGN_KEY_CHECKS=1;
