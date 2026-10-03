<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/functions.php';

function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $path = dirname(__DIR__) . '/config/database.local.php';
    if (!is_file($path)) {
        return $config = [];
    }
    $loaded = require $path;
    return $config = is_array($loaded) ? $loaded : [];
}

function is_installed(): bool
{
    return is_file(dirname(__DIR__) . '/storage/installed.lock') && !empty(app_config());
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = app_config();
    if (!$config) {
        throw new RuntimeException('CRM não instalado.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['db_host'],
        (int) ($config['db_port'] ?? 3306),
        $config['db_name']
    );

    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

if (basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) !== 'install.php' && !is_installed()) {
    redirect('install.php');
}
