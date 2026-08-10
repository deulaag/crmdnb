<?php
/**
 * Conexão PDO única (singleton) com o MySQL.
 */
require_once __DIR__ . '/../config.php';

function db(): PDO
{
    $pdo = dbOpcional();
    if ($pdo === null) {
        http_response_code(500);
        die('Não foi possível conectar ao banco de dados. Verifique a configuração do servidor.');
    }
    return $pdo;
}

/**
 * Mesma conexão singleton, porém devolvendo null em vez de encerrar a requisição.
 * Usado por leituras acessórias — configurações de integração, por exemplo — que
 * precisam poder cair em um fallback local quando o banco não responde.
 */
function dbOpcional(): ?PDO
{
    static $pdo = null;
    static $tentado = false;
    if ($pdo !== null) return $pdo;
    if ($tentado) return null;
    $tentado = true;

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        error_log('DNB CRM database connection failed: ' . $e->getMessage());
        return null;
    }
    return $pdo;
}
