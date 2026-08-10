<?php
/**
 * DNB CRM v3.1.0 — copie para whatsapp.local.php somente no servidor cPanel.
 * O arquivo whatsapp.local.php é ignorado pelo Git e preservado pelo deploy.
 */
define('DNB_WHATSAPP_WEBHOOK_TOKEN', '');
define('DNB_WHATSAPP_CRON_TOKEN', '');
define('DNB_WHATSAPP_SEND_URL', '');
define('DNB_WHATSAPP_API_TOKEN', '');
define('DNB_WHATSAPP_TIMEOUT', '30');

/**
 * Chave mestra que cifra os tokens gravados pela tela de Configurações na tabela
 * system_integrations (AES-256-GCM). Precisa viver fora do banco: quem tem o dump
 * do MySQL não pode ter também a chave.
 *
 * Gere uma vez e nunca mais troque — trocar torna ilegíveis os segredos já gravados:
 *   php -r "echo bin2hex(random_bytes(32));"
 */
define('DNB_CONFIG_KEY', '');
