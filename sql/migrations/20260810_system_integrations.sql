-- DNB CRM v3.5.0 — Migração de integrações editáveis (WhatsApp, VPS de IA e RD Station)
-- Arquivo: 20260810_system_integrations.sql
-- Execute uma única vez no phpMyAdmin do cPanel. Faça backup do banco antes da importação.
--
-- Antes de usar a tela, defina DNB_CONFIG_KEY em whatsapp.local.php (ou nas
-- variáveis de ambiente do cPanel). Sem essa chave o CRM se recusa a gravar
-- segredos, para nunca deixá-los em texto puro nesta tabela.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS system_integrations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  service_name VARCHAR(40) NOT NULL,
  config_key VARCHAR(60) NOT NULL,
  config_value TEXT DEFAULT NULL,
  is_secret TINYINT(1) NOT NULL DEFAULT 0,
  updated_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_system_integration (service_name, config_key),
  KEY idx_system_integration_service (service_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
