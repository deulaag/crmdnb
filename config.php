<?php
/**
 * DNB · CRM Comercial — configuração de conexão com o banco.
 *
 * Preencha com os dados criados em cPanel > Bancos de Dados MySQL.
 * O nome do banco e do usuário no cPanel normalmente vêm prefixados
 * com o usuário da hospedagem, ex.: "dnbauto_crm", "dnbauto_crmuser".
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'dnbai6685943_crmdnb');
define('DB_USER', 'dnbai6685943_dnbcrm');
define('DB_PASS', 'h*nz04!x~.tfw~lb');
define('DB_CHARSET', 'utf8mb4');

/**
 * Fuso horário usado nas datas do sistema.
 */
date_default_timezone_set('America/Bahia');

/**
 * Nome exibido no topo do sistema.
 */
define('APP_NOME', 'DNB · CRM Comercial');
