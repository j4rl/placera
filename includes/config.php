<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Stockholm');

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

define('PLC_DB_HOST', getenv('PLC_DB_HOST') ?: '127.0.0.1');
define('PLC_DB_PORT', (int)(getenv('PLC_DB_PORT') ?: 3306));
define('PLC_DB_NAME', getenv('PLC_DB_NAME') ?: 'placera');
define('PLC_DB_USER', getenv('PLC_DB_USER') ?: 'root');
define('PLC_DB_PASS', getenv('PLC_DB_PASS') ?: '');

