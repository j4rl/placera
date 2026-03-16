<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = plc_require_admin(true);
$db = plc_db();

function plc_fetch_admin_users(mysqli $db): array
{
    $res = $db->query(
        'SELECT u.id, u.username, u.full_name, u.email, u.role, u.status, u.created_at, u.approved_at,
                a.full_name AS approved_by_name
         FROM plc_users u
         LEFT JOIN plc_users a ON a.id = u.approved_by_user_id
         ORDER BY FIELD(u.status, "pending","approved","disabled","rejected"), u.created_at ASC'
    );
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'id' => (int)$row['id'],
            'username' => $row['username'],
            'fullName' => $row['full_name'],
            'email' => $row['email'],
            'role' => $row['role'],
            'status' => $row['status'],
            'createdAt' => $row['created_at'],
            'approvedAt' => $row['approved_at'],
            'approvedByName' => $row['approved_by_name'],
        ];
    }
    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    plc_json(['ok' => true, 'users' => plc_fetch_admin_users($db)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    plc_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

plc_verify_csrf_or_403();
$body = plc_read_json_body();
$action = (string)($body['action'] ?? '');
$targetId = (int)($body['userId'] ?? 0);
$targetRole = (string)($body['role'] ?? 'teacher');
$targetStatus = (string)($body['status'] ?? 'approved');
$username = trim((string)($body['username'] ?? ''));
$fullName = trim((string)($body['fullName'] ?? ''));
$emailInput = trim((string)($body['email'] ?? ''));
$email = function_exists('mb_strtolower') ? mb_strtolower($emailInput) : strtolower($emailInput);
$password = (string)($body['password'] ?? '');
$password2 = (string)($body['password2'] ?? '');

if ($targetId <= 0) {
    plc_json(['ok' => false, 'error' => 'invalid_user'], 400);
}
if ($targetId === (int)$admin['id'] && in_array($action, ['reject', 'disable', 'set_role'], true)) {
    plc_json(['ok' => false, 'error' => 'forbidden_self_action'], 400);
}

if (!in_array($targetRole, ['teacher', 'admin'], true)) {
    $targetRole = 'teacher';
}
if (!in_array($targetStatus, ['pending', 'approved', 'disabled', 'rejected'], true)) {
    $targetStatus = 'approved';
}

$stmt = $db->prepare('SELECT id, role, status FROM plc_users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $targetId);
$stmt->execute();
$targetUser = $stmt->get_result()->fetch_assoc();
if (!$targetUser) {
    plc_json(['ok' => false, 'error' => 'invalid_user'], 400);
}

try {
    if ($action === 'approve') {
        $status = 'approved';
        $stmt = $db->prepare(
            'UPDATE plc_users
             SET status = ?, role = ?, approved_by_user_id = ?, approved_at = NOW(), updated_at = NOW()
             WHERE id = ?'
        );
        $adminId = (int)$admin['id'];
        $stmt->bind_param('ssii', $status, $targetRole, $adminId, $targetId);
        $stmt->execute();
    } elseif ($action === 'reject') {
        $status = 'rejected';
        $stmt = $db->prepare(
            'UPDATE plc_users
             SET status = ?, approved_by_user_id = ?, approved_at = NOW(), updated_at = NOW()
             WHERE id = ?'
        );
        $adminId = (int)$admin['id'];
        $stmt->bind_param('sii', $status, $adminId, $targetId);
        $stmt->execute();
    } elseif ($action === 'disable') {
        $status = 'disabled';
        $stmt = $db->prepare(
            'UPDATE plc_users
             SET status = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->bind_param('si', $status, $targetId);
        $stmt->execute();
    } elseif ($action === 'enable') {
        $status = 'approved';
        $stmt = $db->prepare(
            'UPDATE plc_users
             SET status = ?, approved_by_user_id = ?, approved_at = COALESCE(approved_at, NOW()), updated_at = NOW()
             WHERE id = ?'
        );
        $adminId = (int)$admin['id'];
        $stmt->bind_param('sii', $status, $adminId, $targetId);
        $stmt->execute();
    } elseif ($action === 'set_role') {
        $stmt = $db->prepare(
            'UPDATE plc_users
             SET role = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->bind_param('si', $targetRole, $targetId);
        $stmt->execute();
    } elseif ($action === 'update_user') {
        $fullNameLen = function_exists('mb_strlen') ? mb_strlen($fullName) : strlen($fullName);
        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
            plc_json(['ok' => false, 'error' => 'invalid_username', 'message' => 'Ogiltigt användarnamn.'], 422);
        }
        if ($fullNameLen < 2) {
            plc_json(['ok' => false, 'error' => 'invalid_name', 'message' => 'Ange riktigt namn.'], 422);
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

        if ($targetId === (int)$admin['id']) {
            if ($targetRole !== (string)$targetUser['role'] || $targetStatus !== 'approved') {
                plc_json(['ok' => false, 'error' => 'forbidden_self_action', 'message' => 'Du kan inte ändra egen roll eller status här.'], 400);
            }
        }

        $stmt = $db->prepare(
            'SELECT id
             FROM plc_users
             WHERE (username = ? OR email = ?) AND id <> ?
             LIMIT 1'
        );
        $stmt->bind_param('ssi', $username, $email, $targetId);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        if ($exists) {
            plc_json(['ok' => false, 'error' => 'duplicate_user', 'message' => 'Användarnamn eller e-post används redan.'], 409);
        }

        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare(
                'UPDATE plc_users
                 SET username = ?, full_name = ?, email = ?, role = ?, status = ?, password_hash = ?, updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->bind_param('ssssssi', $username, $fullName, $email, $targetRole, $targetStatus, $hash, $targetId);
            $stmt->execute();
        } else {
            $stmt = $db->prepare(
                'UPDATE plc_users
                 SET username = ?, full_name = ?, email = ?, role = ?, status = ?, updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->bind_param('sssssi', $username, $fullName, $email, $targetRole, $targetStatus, $targetId);
            $stmt->execute();
        }
        if ($targetStatus === 'approved') {
            $stmt = $db->prepare(
                'UPDATE plc_users
                 SET approved_by_user_id = ?, approved_at = COALESCE(approved_at, NOW()), updated_at = NOW()
                 WHERE id = ?'
            );
            $adminId = (int)$admin['id'];
            $stmt->bind_param('ii', $adminId, $targetId);
            $stmt->execute();
        }
    } else {
        plc_json(['ok' => false, 'error' => 'invalid_action'], 400);
    }
} catch (Throwable $e) {
    plc_json(['ok' => false, 'error' => 'update_failed'], 500);
}

plc_json(['ok' => true, 'users' => plc_fetch_admin_users($db)]);
