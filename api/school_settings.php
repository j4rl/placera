<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$user = plc_require_admin(true);
$db = plc_db();
plc_ensure_multischool_schema($db);

if (!plc_is_school_admin($user)) {
    plc_json(['ok' => false, 'error' => 'forbidden'], 403);
}

$schoolId = plc_user_school_id($user);
if ($schoolId <= 0) {
    plc_json(['ok' => false, 'error' => 'invalid_school'], 400);
}

function plc_fetch_school_settings(mysqli $db, int $schoolId): ?array
{
    $stmt = $db->prepare(
        'SELECT id, name, require_2fa
         FROM plc_schools
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $schoolId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

$settings = plc_fetch_school_settings($db, $schoolId);
if (!$settings) {
    plc_json(['ok' => false, 'error' => 'invalid_school'], 400);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    plc_json([
        'ok' => true,
        'schoolId' => (int)$settings['id'],
        'schoolName' => (string)$settings['name'],
        'require2FA' => (int)($settings['require_2fa'] ?? 0) === 1,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    plc_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

plc_verify_csrf_or_403();
$body = plc_read_json_body();
$require2FA = (bool)($body['require2FA'] ?? false);

if ($require2FA && (int)($user['twofa_enabled'] ?? 0) !== 1) {
    plc_json([
        'ok' => false,
        'error' => 'twofa_required_for_admin',
        'message' => 'Aktivera 2FA på ditt eget konto innan du kräver det för hela skolan.',
    ], 403);
}

$requireInt = $require2FA ? 1 : 0;
$stmt = $db->prepare(
    'UPDATE plc_schools
     SET require_2fa = ?, updated_at = NOW()
     WHERE id = ?'
);
$stmt->bind_param('ii', $requireInt, $schoolId);
$stmt->execute();

$settings = plc_fetch_school_settings($db, $schoolId);
if (!$settings) {
    plc_json(['ok' => false, 'error' => 'invalid_school'], 400);
}

plc_json([
    'ok' => true,
    'schoolId' => (int)$settings['id'],
    'schoolName' => (string)$settings['name'],
    'require2FA' => (int)($settings['require_2fa'] ?? 0) === 1,
]);
