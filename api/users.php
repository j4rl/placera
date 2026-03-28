<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$authUser = plc_require_login(true);
$db = plc_db();
plc_ensure_multischool_schema($db);

if (plc_is_superadmin($authUser)) {
    $res = $db->query(
        "SELECT u.id, u.username, u.full_name, u.email, u.role, u.school_id, s.name AS school_name
         FROM plc_users u
         LEFT JOIN plc_schools s ON s.id = u.school_id
         WHERE u.status = 'approved'
         ORDER BY u.full_name ASC"
    );
} else {
    $schoolId = plc_user_school_id($authUser);
    if ($schoolId <= 0) {
        plc_json(['ok' => true, 'users' => []]);
    }
    $stmt = $db->prepare(
        "SELECT u.id, u.username, u.full_name, u.email, u.role, u.school_id, s.name AS school_name
         FROM plc_users u
         LEFT JOIN plc_schools s ON s.id = u.school_id
         WHERE u.status = 'approved'
           AND u.school_id = ?
           AND u.role <> 'superadmin'
         ORDER BY u.full_name ASC"
    );
    $stmt->bind_param('i', $schoolId);
    $stmt->execute();
    $res = $stmt->get_result();
}

$users = [];
while ($row = $res->fetch_assoc()) {
    $users[] = [
        'id' => (int)$row['id'],
        'username' => $row['username'],
        'fullName' => $row['full_name'],
        'email' => $row['email'],
        'role' => $row['role'],
        'schoolId' => (int)($row['school_id'] ?? 0),
        'schoolName' => (string)($row['school_name'] ?? ''),
    ];
}

plc_json(['ok' => true, 'users' => $users]);
