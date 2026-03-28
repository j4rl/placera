<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$authUser = plc_require_login(true);
$db = plc_db();
plc_ensure_multischool_schema($db);
$userId = (int)$authUser['id'];

function plc_profile_payload(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'username' => $row['username'],
        'fullName' => $row['full_name'],
        'email' => $row['email'],
        'role' => $row['role'],
        'roleLabel' => plc_is_superadmin($row)
            ? 'Superadmin'
            : (plc_is_school_admin($row) ? 'Skoladmin' : 'Lärare'),
        'schoolId' => (int)($row['school_id'] ?? 0),
        'schoolName' => (string)($row['school_name'] ?? ''),
        'schoolStatus' => (string)($row['school_status'] ?? ''),
        'schoolRequire2FA' => (int)($row['school_require_2fa'] ?? 0) === 1,
        'twofaEnabled' => (int)($row['twofa_enabled'] ?? 0) === 1,
    ];
}

function plc_fetch_profile(mysqli $db, int $userId): ?array
{
    $stmt = $db->prepare(
        'SELECT u.id, u.username, u.full_name, u.email, u.role, u.school_id, u.twofa_enabled,
                s.name AS school_name, s.status AS school_status, s.require_2fa AS school_require_2fa
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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $row = plc_fetch_profile($db, $userId);
    if (!$row) {
        plc_json(['ok' => false, 'error' => 'not_found', 'message' => 'Användaren hittades inte.'], 404);
    }
    plc_json(['ok' => true, 'user' => plc_profile_payload($row)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    plc_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

plc_verify_csrf_or_403();
$body = plc_read_json_body();

$fullName = trim((string)($body['fullName'] ?? ''));
$emailInput = trim((string)($body['email'] ?? ''));
$email = function_exists('mb_strtolower') ? mb_strtolower($emailInput) : strtolower($emailInput);
$password = (string)($body['password'] ?? '');
$password2 = (string)($body['password2'] ?? '');

$fullNameLen = function_exists('mb_strlen') ? mb_strlen($fullName) : strlen($fullName);

if ($fullNameLen < 2) {
    plc_json(['ok' => false, 'error' => 'invalid_name', 'message' => 'Ange ditt riktiga namn.'], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    plc_json(['ok' => false, 'error' => 'invalid_email', 'message' => 'Ogiltig e-postadress.'], 422);
}
if ($password !== '' && strlen($password) < 8) {
    plc_json(['ok' => false, 'error' => 'invalid_password', 'message' => 'Lösenord måste vara minst 8 tecken.'], 422);
}
if ($password !== $password2) {
    plc_json(['ok' => false, 'error' => 'password_mismatch', 'message' => 'Lösenorden matchar inte.'], 422);
}

$stmt = $db->prepare(
    'SELECT id
     FROM plc_users
     WHERE email = ? AND id <> ?
     LIMIT 1'
);
$stmt->bind_param('si', $email, $userId);
$stmt->execute();
$exists = $stmt->get_result()->fetch_assoc();
if ($exists) {
    plc_json(['ok' => false, 'error' => 'duplicate_user', 'message' => 'E-post finns redan.'], 409);
}

if ($password !== '') {
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare(
        'UPDATE plc_users
         SET full_name = ?, email = ?, password_hash = ?, updated_at = NOW()
         WHERE id = ?'
    );
    $stmt->bind_param('sssi', $fullName, $email, $passwordHash, $userId);
    $stmt->execute();
} else {
    $stmt = $db->prepare(
        'UPDATE plc_users
         SET full_name = ?, email = ?, updated_at = NOW()
         WHERE id = ?'
    );
    $stmt->bind_param('ssi', $fullName, $email, $userId);
    $stmt->execute();
}

$row = plc_fetch_profile($db, $userId);
if (!$row) {
    plc_json(['ok' => false, 'error' => 'not_found', 'message' => 'Användaren hittades inte.'], 404);
}

plc_json(['ok' => true, 'user' => plc_profile_payload($row)]);
