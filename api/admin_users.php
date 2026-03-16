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

if ($targetId <= 0) {
    plc_json(['ok' => false, 'error' => 'invalid_user'], 400);
}
if ($targetId === (int)$admin['id'] && in_array($action, ['reject', 'disable'], true)) {
    plc_json(['ok' => false, 'error' => 'cannot_disable_self'], 400);
}

if (!in_array($targetRole, ['teacher', 'admin'], true)) {
    $targetRole = 'teacher';
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
             SET status = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->bind_param('si', $status, $targetId);
        $stmt->execute();
    } else {
        plc_json(['ok' => false, 'error' => 'invalid_action'], 400);
    }
} catch (Throwable $e) {
    plc_json(['ok' => false, 'error' => 'update_failed'], 500);
}

plc_json(['ok' => true, 'users' => plc_fetch_admin_users($db)]);

