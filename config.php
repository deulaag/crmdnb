<?php
/**
 * DNB · CRM Comercial — configuração de conexão com o banco.
 *
 * Preencha com os dados criados em cPanel > Bancos de Dados MySQL.
 * O nome do banco e do usuário no cPanel normalmente vêm prefixados
 * com o usuário da hospedagem, ex.: "dnbauto_crm", "dnbauto_crmuser".
 */

$configLocal = __DIR__ . '/config.local.php';
if (is_file($configLocal)) require $configLocal;

if (!defined('DB_HOST')) define('DB_HOST', getenv('DNB_DB_HOST') ?: 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', getenv('DNB_DB_NAME') ?: '');
if (!defined('DB_USER')) define('DB_USER', getenv('DNB_DB_USER') ?: '');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DNB_DB_PASS') ?: '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', getenv('DNB_DB_CHARSET') ?: 'utf8mb4');

/**
 * Fuso horário usado nas datas do sistema.
 */
date_default_timezone_set('America/Bahia');

/**
 * Nome exibido no topo do sistema.
 */
define('APP_NOME', 'DNB · CRM Comercial');

/**
 * Integração de IA hospedada na VPS.
 * No cPanel, prefira definir DNB_AI_VPS_ENDPOINT e DNB_AI_VPS_TOKEN no ambiente.
 * Se a hospedagem não oferecer variáveis de ambiente, substitua os valores vazios
 * abaixo no config.php do servidor e nunca publique a credencial no GitHub.
 */
if (!defined('AI_VPS_ENDPOINT')) define('AI_VPS_ENDPOINT', getenv('DNB_AI_VPS_ENDPOINT') ?: '');
if (!defined('AI_VPS_TOKEN')) define('AI_VPS_TOKEN', getenv('DNB_AI_VPS_TOKEN') ?: '');
if (!defined('AI_REQUEST_TIMEOUT')) define('AI_REQUEST_TIMEOUT', max(5, (int) (getenv('DNB_AI_TIMEOUT') ?: 45)));
if (!defined('AI_MAX_PROMPT_LENGTH')) define('AI_MAX_PROMPT_LENGTH', 8000);
