-- DNB CRM v3.1.0 — Migração WhatsApp + RBAC + Human Lock
-- Arquivo: 20260809_whatsapp_rbac.sql
-- Execute uma única vez no phpMyAdmin do cPanel, após 20260809_crm_ia_rbac.sql.
-- Faça backup do banco antes da importação.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS whatsapp_instances (
  id INT AUTO_INCREMENT PRIMARY KEY,
  external_instance_id VARCHAR(120) NOT NULL,
  name VARCHAR(160) NOT NULL,
  provider VARCHAR(60) NOT NULL DEFAULT 'generic',
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_whatsapp_instance_external (external_instance_id),
  KEY idx_whatsapp_instance_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS whatsapp_contacts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  instance_id INT NOT NULL,
  external_contact_id VARCHAR(160) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  display_name VARCHAR(180) DEFAULT NULL,
  lead_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_whatsapp_contact_external (instance_id, external_contact_id),
  KEY idx_whatsapp_contact_phone (phone),
  KEY idx_whatsapp_contact_lead (lead_id),
  CONSTRAINT fk_whatsapp_contact_instance FOREIGN KEY (instance_id) REFERENCES whatsapp_instances(id) ON DELETE CASCADE,
  CONSTRAINT fk_whatsapp_contact_lead FOREIGN KEY (lead_id) REFERENCES orcamentos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS whatsapp_conversations (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  instance_id INT NOT NULL,
  contact_id BIGINT NOT NULL,
  lead_id INT DEFAULT NULL,
  assigned_to_user_id INT DEFAULT NULL,
  ai_paused TINYINT(1) NOT NULL DEFAULT 0,
  human_locked_at DATETIME DEFAULT NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  unread_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_message_preview VARCHAR(500) DEFAULT NULL,
  last_message_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_whatsapp_conversation_contact (instance_id, contact_id),
  KEY idx_whatsapp_conversation_lead (lead_id),
  KEY idx_whatsapp_conversation_human (assigned_to_user_id, status),
  KEY idx_whatsapp_conversation_last (status, last_message_at),
  CONSTRAINT fk_whatsapp_conversation_instance FOREIGN KEY (instance_id) REFERENCES whatsapp_instances(id) ON DELETE CASCADE,
  CONSTRAINT fk_whatsapp_conversation_contact FOREIGN KEY (contact_id) REFERENCES whatsapp_contacts(id) ON DELETE CASCADE,
  CONSTRAINT fk_whatsapp_conversation_lead FOREIGN KEY (lead_id) REFERENCES orcamentos(id) ON DELETE SET NULL,
  CONSTRAINT fk_whatsapp_conversation_assignee FOREIGN KEY (assigned_to_user_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS whatsapp_messages (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  provider_message_id VARCHAR(190) NOT NULL,
  instance_id INT NOT NULL,
  conversation_id BIGINT NOT NULL,
  lead_id INT DEFAULT NULL,
  direction ENUM('incoming','outgoing') NOT NULL,
  sender_type ENUM('contact','ai','human','system') NOT NULL DEFAULT 'contact',
  sender_user_id INT DEFAULT NULL,
  message_body TEXT,
  media_url VARCHAR(1000) DEFAULT NULL,
  status ENUM('received','queued','processing','processed','sent','delivered','read','failed','ignored') NOT NULL DEFAULT 'received',
  error_message VARCHAR(1000) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME DEFAULT NULL,
  UNIQUE KEY uq_whatsapp_provider_message (instance_id, provider_message_id),
  KEY idx_whatsapp_message_conversation (conversation_id, id),
  KEY idx_whatsapp_message_buffer (conversation_id, direction, status, created_at),
  KEY idx_whatsapp_message_lead (lead_id, created_at),
  CONSTRAINT fk_whatsapp_message_instance FOREIGN KEY (instance_id) REFERENCES whatsapp_instances(id) ON DELETE CASCADE,
  CONSTRAINT fk_whatsapp_message_conversation FOREIGN KEY (conversation_id) REFERENCES whatsapp_conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_whatsapp_message_lead FOREIGN KEY (lead_id) REFERENCES orcamentos(id) ON DELETE SET NULL,
  CONSTRAINT fk_whatsapp_message_sender FOREIGN KEY (sender_user_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS whatsapp_queue (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  conversation_id BIGINT NOT NULL,
  ai_interaction_id BIGINT DEFAULT NULL,
  first_message_id BIGINT NOT NULL,
  last_message_id BIGINT NOT NULL,
  first_message_at DATETIME NOT NULL,
  last_message_at DATETIME NOT NULL,
  available_at DATETIME NOT NULL,
  status ENUM('pending','processing','completed','skipped','failed') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_at DATETIME DEFAULT NULL,
  processed_at DATETIME DEFAULT NULL,
  error_message VARCHAR(1000) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_whatsapp_queue_conversation (conversation_id),
  KEY idx_whatsapp_queue_ready (status, available_at),
  KEY idx_whatsapp_queue_message_range (conversation_id, first_message_id, last_message_id),
  CONSTRAINT fk_whatsapp_queue_conversation FOREIGN KEY (conversation_id) REFERENCES whatsapp_conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_whatsapp_queue_ai FOREIGN KEY (ai_interaction_id) REFERENCES ai_interactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
