<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

plc_require_login(true);
$db = plc_db();

$res = $db->query(
    "SELECT id, username, full_name, email, role
     FROM plc_users
     WHERE status = 'approved'
     ORDER BY full_name ASC"
);

$users = [];
while ($row = $res->fetch_assoc()) {
    $users[] = [
        'id' => (int)$row['id'],
        'username' => $row['username'],
        'fullName' => $row['full_name'],
        'email' => $row['email'],
        'role' => $row['role'],
    ];
}

plc_json(['ok' => true, 'users' => $users]);

