<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/twofa.php';

$authUser = plc_require_login(true);
$db = plc_db();
plc_ensure_multischool_schema($db);
$userId = (int)$authUser['id'];

function plc_twofa_setup_session_key(int $userId): string
{
    return 'plc_2fa_setup_secret_' . $userId;
}

function plc_fetch_twofa_user(mysqli $db, int $userId): ?array
{
    $stmt = $db->prepare(
        'SELECT u.id, u.username, u.email, u.twofa_enabled, u.twofa_secret,
                s.require_2fa AS school_require_2fa
         FROM plc_users u
         LEFT JOIN plc_schools s ON s.id = u.school_id
         WHERE u.id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function plc_twofa_payload(mysqli $db, array $row, ?string $setupSecret): array
{
    $enabled = (int)($row['twofa_enabled'] ?? 0) === 1;
    $schoolRequire2FA = (int)($row['school_require_2fa'] ?? 0) === 1;
    $setupInProgress = is_string($setupSecret) && $setupSecret !== '';
    $account = trim((string)($row['email'] ?? '')) !== ''
        ? (string)$row['email']
        : (string)($row['username'] ?? 'user');

    return [
        'ok' => true,
        'twofaEnabled' => $enabled,
        'schoolRequire2FA' => $schoolRequire2FA,
        'backupCodesRemaining' => $enabled ? plc_twofa_backup_code_remaining($db, (int)$row['id']) : 0,
        'setupInProgress' => $setupInProgress,
        'setupSecret' => $setupInProgress ? $setupSecret : '',
        'otpauthUri' => $setupInProgress ? plc_twofa_otpauth_uri('Placera', $account, $setupSecret) : '',
    ];
}

$sessionKey = plc_twofa_setup_session_key($userId);
$setupSecret = isset($_SESSION[$sessionKey]) ? (string)$_SESSION[$sessionKey] : '';
$userRow = plc_fetch_twofa_user($db, $userId);
if (!$userRow) {
    plc_json(['ok' => false, 'error' => 'not_found'], 404);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    plc_json(plc_twofa_payload($db, $userRow, $setupSecret));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    plc_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

plc_verify_csrf_or_403();
$body = plc_read_json_body();
$action = (string)($body['action'] ?? '');
$code = (string)($body['code'] ?? '');

if ($action === 'start_setup') {
    if ((int)($userRow['twofa_enabled'] ?? 0) === 1) {
        plc_json(plc_twofa_payload($db, $userRow, ''));
    }
    $setupSecret = plc_twofa_generate_secret();
    $_SESSION[$sessionKey] = $setupSecret;
    plc_json(plc_twofa_payload($db, $userRow, $setupSecret));
}

if ($action === 'cancel_setup') {
    unset($_SESSION[$sessionKey]);
    plc_json(plc_twofa_payload($db, $userRow, ''));
}

if ($action === 'confirm_setup') {
    if (!is_string($setupSecret) || $setupSecret === '') {
        plc_json(['ok' => false, 'error' => 'setup_not_started', 'message' => 'Starta 2FA-setup först.'], 400);
    }
    if (!plc_twofa_verify_code($setupSecret, $code)) {
        plc_json(['ok' => false, 'error' => 'invalid_code', 'message' => 'Felaktig verifieringskod.'], 422);
    }

    $encryptedSecret = plc_encrypt_text($setupSecret);
    $enabled = 1;
    $stmt = $db->prepare(
        'UPDATE plc_users
         SET twofa_enabled = ?, twofa_secret = ?, twofa_enabled_at = NOW(), updated_at = NOW()
         WHERE id = ?'
    );
    $stmt->bind_param('isi', $enabled, $encryptedSecret, $userId);
    $stmt->execute();
    unset($_SESSION[$sessionKey]);

    $userRow = plc_fetch_twofa_user($db, $userId);
    if (!$userRow) {
        plc_json(['ok' => false, 'error' => 'not_found'], 404);
    }
    $payload = plc_twofa_payload($db, $userRow, '');
    $payload['backupCodes'] = plc_twofa_generate_backup_codes($db, $userId);
    $payload['backupCodesRemaining'] = plc_twofa_backup_code_remaining($db, $userId);
    plc_json($payload);
}

if ($action === 'generate_backup_codes') {
    if ((int)($userRow['twofa_enabled'] ?? 0) !== 1) {
        plc_json(['ok' => false, 'error' => 'twofa_not_enabled', 'message' => 'Aktivera 2FA först.'], 400);
    }
    $codes = plc_twofa_generate_backup_codes($db, $userId);
    $userRow = plc_fetch_twofa_user($db, $userId);
    if (!$userRow) {
        plc_json(['ok' => false, 'error' => 'not_found'], 404);
    }
    $payload = plc_twofa_payload($db, $userRow, $setupSecret);
    $payload['backupCodes'] = $codes;
    $payload['backupCodesRemaining'] = plc_twofa_backup_code_remaining($db, $userId);
    plc_json($payload);
}

if ($action === 'disable') {
    $schoolRequire2FA = (int)($userRow['school_require_2fa'] ?? 0) === 1;
    if ($schoolRequire2FA) {
        plc_json([
            'ok' => false,
            'error' => 'forbidden',
            'message' => 'Din skola kräver 2FA. Inaktivering är inte tillåten.',
        ], 403);
    }
    $disabled = 0;
    $stmt = $db->prepare(
        'UPDATE plc_users
         SET twofa_enabled = ?, twofa_secret = NULL, twofa_enabled_at = NULL, updated_at = NOW()
         WHERE id = ?'
    );
    $stmt->bind_param('ii', $disabled, $userId);
    $stmt->execute();
    $stmt = $db->prepare('DELETE FROM plc_user_backup_codes WHERE user_id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    unset($_SESSION[$sessionKey]);

    $userRow = plc_fetch_twofa_user($db, $userId);
    if (!$userRow) {
        plc_json(['ok' => false, 'error' => 'not_found'], 404);
    }
    plc_json(plc_twofa_payload($db, $userRow, ''));
}

plc_json(['ok' => false, 'error' => 'invalid_action'], 400);
