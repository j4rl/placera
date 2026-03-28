<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = plc_require_admin(true);
$db = plc_db();
plc_ensure_multischool_schema($db);

$isSuperadmin = plc_is_superadmin($admin);
$actorId = (int)$admin['id'];
$actorSchoolId = plc_user_school_id($admin);

function plc_role_label(string $role): string
{
    if ($role === 'superadmin') {
        return 'Superadmin';
    }
    if ($role === 'school_admin') {
        return 'Skoladmin';
    }
    return 'Lärare';
}

function plc_admin_user_row_to_payload(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'username' => $row['username'],
        'fullName' => $row['full_name'],
        'email' => $row['email'],
        'role' => $row['role'],
        'roleLabel' => plc_role_label((string)$row['role']),
        'status' => $row['status'],
        'createdAt' => $row['created_at'],
        'approvedAt' => $row['approved_at'],
        'approvedByName' => $row['approved_by_name'],
        'schoolId' => (int)($row['school_id'] ?? 0),
        'schoolName' => (string)($row['school_name'] ?? ''),
        'schoolStatus' => (string)($row['school_status'] ?? ''),
        'schoolApprovedAt' => $row['school_approved_at'] ?? null,
        'schoolApprovedByName' => $row['school_approved_by_name'] ?? null,
        'twofaEnabled' => (int)($row['twofa_enabled'] ?? 0) === 1,
    ];
}

function plc_fetch_admin_users(mysqli $db, bool $isSuperadmin, int $actorSchoolId): array
{
    $sql = 'SELECT u.id, u.username, u.full_name, u.email, u.role, u.status, u.created_at, u.approved_at,
                   u.twofa_enabled,
                   u.school_id, s.name AS school_name, s.status AS school_status, s.approved_at AS school_approved_at,
                   a.full_name AS approved_by_name, sa.full_name AS school_approved_by_name
            FROM plc_users u
            LEFT JOIN plc_users a ON a.id = u.approved_by_user_id
            LEFT JOIN plc_schools s ON s.id = u.school_id
            LEFT JOIN plc_users sa ON sa.id = s.approved_by_user_id';
    if ($isSuperadmin) {
        $sql .= ' ORDER BY FIELD(u.status, "pending","approved","disabled","rejected"), u.created_at ASC';
        $res = $db->query($sql);
    } else {
        $sql .= ' WHERE u.school_id = ? AND u.role <> "superadmin"
                  ORDER BY FIELD(u.status, "pending","approved","disabled","rejected"), u.created_at ASC';
        $stmt = $db->prepare($sql);
        $stmt->bind_param('i', $actorSchoolId);
        $stmt->execute();
        $res = $stmt->get_result();
    }

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = plc_admin_user_row_to_payload($row);
    }
    return $rows;
}

function plc_fetch_admin_user(mysqli $db, int $userId): ?array
{
    $stmt = $db->prepare(
        'SELECT u.id, u.username, u.full_name, u.email, u.role, u.status, u.created_at, u.approved_at,
                u.twofa_enabled,
                u.school_id, s.name AS school_name, s.status AS school_status, s.approved_at AS school_approved_at,
                a.full_name AS approved_by_name, sa.full_name AS school_approved_by_name
         FROM plc_users u
         LEFT JOIN plc_users a ON a.id = u.approved_by_user_id
         LEFT JOIN plc_schools s ON s.id = u.school_id
         LEFT JOIN plc_users sa ON sa.id = s.approved_by_user_id
         WHERE u.id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return null;
    }
    return plc_admin_user_row_to_payload($row);
}

function plc_can_actor_manage_user(array $actor, array $target): bool
{
    if (plc_is_superadmin($actor)) {
        return true;
    }
    if (!plc_is_school_admin($actor)) {
        return false;
    }
    $actorSchoolId = plc_user_school_id($actor);
    return $actorSchoolId > 0
        && $actorSchoolId === (int)($target['schoolId'] ?? 0)
        && ($target['role'] ?? '') !== 'superadmin';
}

function plc_normalize_role_input(string $role): string
{
    if (in_array($role, ['teacher', 'school_admin', 'superadmin'], true)) {
        return $role;
    }
    return 'teacher';
}

function plc_normalize_status_input(string $status): string
{
    if (in_array($status, ['pending', 'approved', 'disabled', 'rejected'], true)) {
        return $status;
    }
    return 'approved';
}

function plc_update_school_status(mysqli $db, int $schoolId, string $schoolStatus, int $actorId): void
{
    $schoolStatus = plc_normalize_status_input($schoolStatus);
    if ($schoolStatus === 'approved') {
        $stmt = $db->prepare(
            'UPDATE plc_schools
             SET status = ?, approved_by_user_id = ?, approved_at = COALESCE(approved_at, NOW()), updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->bind_param('sii', $schoolStatus, $actorId, $schoolId);
        $stmt->execute();
        return;
    }

    $stmt = $db->prepare(
        'UPDATE plc_schools
         SET status = ?, approved_by_user_id = ?, approved_at = NOW(), updated_at = NOW()
         WHERE id = ?'
    );
    $stmt->bind_param('sii', $schoolStatus, $actorId, $schoolId);
    $stmt->execute();
}

function plc_find_or_create_school_id(mysqli $db, string $schoolName, string $defaultStatus): int
{
    $name = trim($schoolName);
    if ($name === '') {
        return 0;
    }

    $stmt = $db->prepare(
        'SELECT id
         FROM plc_schools
         WHERE LOWER(name) = LOWER(?)
         LIMIT 1'
    );
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        return (int)$row['id'];
    }

    $status = $defaultStatus;
    $stmt = $db->prepare(
        'INSERT INTO plc_schools (name, status, approved_by_user_id, approved_at)
         VALUES (?, ?, NULL, NULL)'
    );
    $stmt->bind_param('ss', $name, $status);
    $stmt->execute();
    return (int)$db->insert_id;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    plc_json(['ok' => true, 'users' => plc_fetch_admin_users($db, $isSuperadmin, $actorSchoolId)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    plc_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

plc_verify_csrf_or_403();
$body = plc_read_json_body();
$action = (string)($body['action'] ?? '');
$targetId = (int)($body['userId'] ?? 0);
$targetRole = plc_normalize_role_input((string)($body['role'] ?? 'teacher'));
$targetStatus = plc_normalize_status_input((string)($body['status'] ?? 'approved'));
$username = trim((string)($body['username'] ?? ''));
$fullName = trim((string)($body['fullName'] ?? ''));
$emailInput = trim((string)($body['email'] ?? ''));
$email = function_exists('mb_strtolower') ? mb_strtolower($emailInput) : strtolower($emailInput);
$password = (string)($body['password'] ?? '');
$password2 = (string)($body['password2'] ?? '');
$schoolNameInput = trim((string)($body['schoolName'] ?? ''));
$schoolStatusInput = plc_normalize_status_input((string)($body['schoolStatus'] ?? 'pending'));
$schoolIdInput = (int)($body['schoolId'] ?? 0);

if ($action === 'approve_school') {
    if (!$isSuperadmin) {
        plc_json(['ok' => false, 'error' => 'forbidden'], 403);
    }
    $schoolId = $schoolIdInput;
    if ($schoolId <= 0 && $targetId > 0) {
        $targetUser = plc_fetch_admin_user($db, $targetId);
        $schoolId = (int)($targetUser['schoolId'] ?? 0);
    }
    if ($schoolId <= 0) {
        plc_json(['ok' => false, 'error' => 'invalid_school'], 400);
    }
    plc_update_school_status($db, $schoolId, 'approved', $actorId);
    if ($targetId > 0) {
        $updated = plc_fetch_admin_user($db, $targetId);
        if ($updated) {
            plc_json(['ok' => true, 'user' => $updated]);
        }
    }
    plc_json(['ok' => true]);
}

if ($targetId <= 0) {
    plc_json(['ok' => false, 'error' => 'invalid_user'], 400);
}

$targetUser = plc_fetch_admin_user($db, $targetId);
if (!$targetUser) {
    plc_json(['ok' => false, 'error' => 'invalid_user'], 400);
}
if (!plc_can_actor_manage_user($admin, $targetUser)) {
    plc_json(['ok' => false, 'error' => 'forbidden'], 403);
}
if ($targetId === $actorId && in_array($action, ['reject', 'disable', 'set_role', 'reset_2fa'], true)) {
    plc_json(['ok' => false, 'error' => 'forbidden_self_action'], 400);
}

if (!$isSuperadmin) {
    $targetRole = 'teacher';
    if (($targetUser['role'] ?? '') !== 'teacher' && in_array($action, ['approve', 'reject', 'disable', 'enable', 'set_role', 'update_user', 'reset_2fa'], true)) {
        plc_json(['ok' => false, 'error' => 'forbidden'], 403);
    }
    if ($action === 'set_role') {
        plc_json(['ok' => false, 'error' => 'forbidden'], 403);
    }
}

try {
    if ($action === 'approve') {
        if (!$isSuperadmin && ($targetUser['role'] ?? '') !== 'teacher') {
            plc_json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $status = 'approved';
        $stmt = $db->prepare(
            'UPDATE plc_users
             SET status = ?, role = ?, approved_by_user_id = ?, approved_at = NOW(), updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->bind_param('ssii', $status, $targetRole, $actorId, $targetId);
        $stmt->execute();

        if ($isSuperadmin && $targetRole === 'school_admin') {
            $schoolId = (int)($targetUser['schoolId'] ?? 0);
            if ($schoolId > 0) {
                plc_update_school_status($db, $schoolId, 'approved', $actorId);
            }
        }
    } elseif ($action === 'reject') {
        $status = 'rejected';
        $stmt = $db->prepare(
            'UPDATE plc_users
             SET status = ?, approved_by_user_id = ?, approved_at = NOW(), updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->bind_param('sii', $status, $actorId, $targetId);
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
        $stmt->bind_param('sii', $status, $actorId, $targetId);
        $stmt->execute();
    } elseif ($action === 'set_role') {
        if (!$isSuperadmin) {
            plc_json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        if ($targetRole === 'superadmin') {
            $stmt = $db->prepare(
                'UPDATE plc_users
                 SET role = ?, school_id = NULL, updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->bind_param('si', $targetRole, $targetId);
        } else {
            $stmt = $db->prepare(
                'UPDATE plc_users
                 SET role = ?, updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->bind_param('si', $targetRole, $targetId);
        }
        $stmt->execute();
    } elseif ($action === 'reset_2fa') {
        $stmt = $db->prepare(
            'UPDATE plc_users
             SET twofa_enabled = 0, twofa_secret = NULL, twofa_enabled_at = NULL, updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->bind_param('i', $targetId);
        $stmt->execute();

        $stmt = $db->prepare(
            'DELETE FROM plc_user_backup_codes
             WHERE user_id = ?'
        );
        $stmt->bind_param('i', $targetId);
        $stmt->execute();
    } elseif ($action === 'update_user') {
        $fullNameLen = function_exists('mb_strlen') ? mb_strlen($fullName) : strlen($fullName);
        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
            plc_json(['ok' => false, 'error' => 'invalid_username', 'message' => 'Ogiltigt anvandarnamn.'], 422);
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

        if ($targetId === $actorId && ($targetRole !== (string)$targetUser['role'] || $targetStatus !== 'approved')) {
            plc_json(['ok' => false, 'error' => 'forbidden_self_action', 'message' => 'Du kan inte ändra egen roll eller status här.'], 400);
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

        $newRole = $targetRole;
        $newSchoolId = (int)($targetUser['schoolId'] ?? 0);
        if (!$isSuperadmin) {
            $newRole = 'teacher';
            $newSchoolId = $actorSchoolId;
        } elseif ($newRole === 'superadmin') {
            $newSchoolId = 0;
        } else {
            if ($schoolIdInput > 0) {
                $newSchoolId = $schoolIdInput;
            }
            if ($schoolNameInput !== '') {
                $newSchoolId = plc_find_or_create_school_id($db, $schoolNameInput, $schoolStatusInput);
            }
            if ($newSchoolId <= 0) {
                plc_json(['ok' => false, 'error' => 'invalid_school', 'message' => 'Ange en giltig skola.'], 422);
            }
        }

        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if ($newRole === 'superadmin') {
                $stmt = $db->prepare(
                    'UPDATE plc_users
                     SET school_id = NULL, username = ?, full_name = ?, email = ?, role = ?, status = ?, password_hash = ?, updated_at = NOW()
                     WHERE id = ?'
                );
                $stmt->bind_param('ssssssi', $username, $fullName, $email, $newRole, $targetStatus, $hash, $targetId);
            } else {
                $stmt = $db->prepare(
                    'UPDATE plc_users
                     SET school_id = ?, username = ?, full_name = ?, email = ?, role = ?, status = ?, password_hash = ?, updated_at = NOW()
                     WHERE id = ?'
                );
                $stmt->bind_param('issssssi', $newSchoolId, $username, $fullName, $email, $newRole, $targetStatus, $hash, $targetId);
            }
            $stmt->execute();
        } else {
            if ($newRole === 'superadmin') {
                $stmt = $db->prepare(
                    'UPDATE plc_users
                     SET school_id = NULL, username = ?, full_name = ?, email = ?, role = ?, status = ?, updated_at = NOW()
                     WHERE id = ?'
                );
                $stmt->bind_param('sssssi', $username, $fullName, $email, $newRole, $targetStatus, $targetId);
            } else {
                $stmt = $db->prepare(
                    'UPDATE plc_users
                     SET school_id = ?, username = ?, full_name = ?, email = ?, role = ?, status = ?, updated_at = NOW()
                     WHERE id = ?'
                );
                $stmt->bind_param('isssssi', $newSchoolId, $username, $fullName, $email, $newRole, $targetStatus, $targetId);
            }
            $stmt->execute();
        }
        if ($targetStatus === 'approved') {
            $stmt = $db->prepare(
                'UPDATE plc_users
                 SET approved_by_user_id = ?, approved_at = COALESCE(approved_at, NOW()), updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->bind_param('ii', $actorId, $targetId);
            $stmt->execute();
        }

        if ($isSuperadmin && $newSchoolId > 0) {
            plc_update_school_status($db, $newSchoolId, $schoolStatusInput, $actorId);
        }
    } else {
        plc_json(['ok' => false, 'error' => 'invalid_action'], 400);
    }
} catch (Throwable $e) {
    plc_json(['ok' => false, 'error' => 'update_failed'], 500);
}

$updatedUser = plc_fetch_admin_user($db, $targetId);
if (!$updatedUser) {
    plc_json(['ok' => false, 'error' => 'invalid_user'], 400);
}

if (!plc_can_actor_manage_user($admin, $updatedUser)) {
    plc_json(['ok' => false, 'error' => 'forbidden'], 403);
}

plc_json(['ok' => true, 'user' => $updatedUser]);
