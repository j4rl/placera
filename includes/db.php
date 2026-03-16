<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function plc_db(): mysqli
{
    static $db = null;
    if ($db instanceof mysqli) {
        return $db;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli(PLC_DB_HOST, PLC_DB_USER, PLC_DB_PASS, PLC_DB_NAME, PLC_DB_PORT);
    $db->set_charset('utf8mb4');
    return $db;
}

