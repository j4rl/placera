<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$admin = plc_require_superadmin(true);
$db = plc_db();
plc_ensure_multischool_schema($db);
$actorId = (int)$admin['id'];

function plc_school_status_normalize(string $status): string
{
    if (in_array($status, ['pending', 'approved', 'rejected', 'disabled'], true)) {
        return $status;
    }
    return 'pending';
}

function plc_school_status_label(string $status): string
{
    if ($status === 'approved') {
        return 'Godkänd';
    }
    if ($status === 'rejected') {
        return 'Avslagen';
    }
    if ($status === 'disabled') {
        return 'Inaktiverad';
    }
    return 'Väntande';
}

function plc_fetch_school_user_counts(mysqli $db): array
{
    $res = $db->query(
        "SELECT school_id,
                COUNT(*) AS total_count,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status = 'disabled' THEN 1 ELSE 0 END) AS disabled_count,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count
         FROM plc_users
         WHERE school_id IS NOT NULL
           AND role <> 'superadmin'
         GROUP BY school_id"
    );
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $sid = (int)$row['school_id'];
        $out[$sid] = [
            'userCount' => (int)($row['total_count'] ?? 0),
            'approvedUserCount' => (int)($row['approved_count'] ?? 0),
            'pendingUserCount' => (int)($row['pending_count'] ?? 0),
            'disabledUserCount' => (int)($row['disabled_count'] ?? 0),
            'rejectedUserCount' => (int)($row['rejected_count'] ?? 0),
        ];
    }
    return $out;
}

function plc_fetch_school_admins_map(mysqli $db): array
{
    $res = $db->query(
        "SELECT id, school_id, full_name, email, status
         FROM plc_users
         WHERE role = 'school_admin'
           AND school_id IS NOT NULL
         ORDER BY full_name ASC, id ASC"
    );
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $sid = (int)$row['school_id'];
        if (!isset($out[$sid])) {
            $out[$sid] = [];
        }
        $out[$sid][] = [
            'id' => (int)$row['id'],
            'fullName' => (string)$row['full_name'],
            'email' => (string)$row['email'],
            'status' => (string)$row['status'],
            'statusLabel' => plc_school_status_label((string)$row['status']),
        ];
    }
    return $out;
}

function plc_fetch_admin_schools(mysqli $db, ?int $onlySchoolId = null): array
{
    $counts = plc_fetch_school_user_counts($db);
    $adminsMap = plc_fetch_school_admins_map($db);

    if ($onlySchoolId !== null) {
        $stmt = $db->prepare(
            'SELECT s.id, s.name, s.status, s.require_2fa, s.approved_at, s.created_at, s.updated_at,
                    a.full_name AS approved_by_name
             FROM plc_schools s
             LEFT JOIN plc_users a ON a.id = s.approved_by_user_id
             WHERE s.id = ?
             LIMIT 1'
        );
        $stmt->bind_param('i', $onlySchoolId);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $db->query(
            "SELECT s.id, s.name, s.status, s.require_2fa, s.approved_at, s.created_at, s.updated_at,
                    a.full_name AS approved_by_name
             FROM plc_schools s
             LEFT JOIN plc_users a ON a.id = s.approved_by_user_id
             ORDER BY FIELD(s.status, 'pending', 'approved', 'disabled', 'rejected'), s.name ASC"
        );
    }

    $out = [];
    while ($row = $res->fetch_assoc()) {
        $sid = (int)$row['id'];
        $countRow = $counts[$sid] ?? [
            'userCount' => 0,
            'approvedUserCount' => 0,
            'pendingUserCount' => 0,
            'disabledUserCount' => 0,
            'rejectedUserCount' => 0,
        ];
        $schoolAdmins = $adminsMap[$sid] ?? [];
        $status = (string)$row['status'];
        $out[] = [
            'id' => $sid,
            'name' => (string)$row['name'],
            'status' => $status,
            'statusLabel' => plc_school_status_label($status),
            'require2FA' => (int)($row['require_2fa'] ?? 0) === 1,
            'approvedAt' => $row['approved_at'] ?? null,
            'approvedByName' => $row['approved_by_name'] ?? null,
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
            'userCount' => (int)$countRow['userCount'],
            'approvedUserCount' => (int)$countRow['approvedUserCount'],
            'pendingUserCount' => (int)$countRow['pendingUserCount'],
            'disabledUserCount' => (int)$countRow['disabledUserCount'],
            'rejectedUserCount' => (int)$countRow['rejectedUserCount'],
            'schoolAdmins' => $schoolAdmins,
            'schoolAdminCount' => count($schoolAdmins),
        ];
    }

    return $out;
}

function plc_fetch_admin_school(mysqli $db, int $schoolId): ?array
{
    $rows = plc_fetch_admin_schools($db, $schoolId);
    if (!$rows) {
        return null;
    }
    return $rows[0];
}

function plc_school_exists(mysqli $db, int $schoolId): bool
{
    $stmt = $db->prepare(
        'SELECT id
         FROM plc_schools
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $schoolId);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}

function plc_school_name_taken_by_other(mysqli $db, string $name, int $schoolId): bool
{
    $stmt = $db->prepare(
        'SELECT id
         FROM plc_schools
         WHERE LOWER(name) = LOWER(?)
           AND id <> ?
         LIMIT 1'
    );
    $stmt->bind_param('si', $name, $schoolId);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}

function plc_update_school_row(mysqli $db, int $schoolId, string $name, string $status, bool $require2FA, int $actorId): void
{
    $status = plc_school_status_normalize($status);
    $requireInt = $require2FA ? 1 : 0;
    if ($status === 'approved') {
        $stmt = $db->prepare(
            'UPDATE plc_schools
             SET name = ?, status = ?, require_2fa = ?, approved_by_user_id = ?, approved_at = COALESCE(approved_at, NOW()), updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->bind_param('ssiii', $name, $status, $requireInt, $actorId, $schoolId);
        $stmt->execute();
        return;
    }

    $stmt = $db->prepare(
        'UPDATE plc_schools
         SET name = ?, status = ?, require_2fa = ?, approved_by_user_id = ?, approved_at = NOW(), updated_at = NOW()
         WHERE id = ?'
    );
    $stmt->bind_param('ssiii', $name, $status, $requireInt, $actorId, $schoolId);
    $stmt->execute();
}

function plc_table_exists(mysqli $db, string $tableName): bool
{
    $safe = $db->real_escape_string($tableName);
    $res = $db->query("SHOW TABLES LIKE '{$safe}'");
    return $res instanceof mysqli_result && $res->num_rows > 0;
}

function plc_delete_school_with_data(mysqli $db, int $schoolId): void
{
    $db->begin_transaction();
    try {
        $stmt = $db->prepare(
            'SELECT status
             FROM plc_schools
             WHERE id = ?
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->bind_param('i', $schoolId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            throw new RuntimeException('invalid_school');
        }
        $status = (string)($row['status'] ?? '');
        if ($status !== 'rejected') {
            throw new RuntimeException('school_not_rejected');
        }

        $deleteJoinSql = [
            [
                'plc_placements',
                'DELETE p
                 FROM plc_placements p
                 JOIN (
                    SELECT id
                    FROM plc_users
                    WHERE school_id = ?
                      AND role <> "superadmin"
                 ) su
                   ON su.id = p.owner_user_id
                   OR su.id = p.created_by_user_id
                   OR su.id = p.updated_by_user_id',
            ],
            [
                'plc_classes',
                'DELETE c
                 FROM plc_classes c
                 JOIN (
                    SELECT id
                    FROM plc_users
                    WHERE school_id = ?
                      AND role <> "superadmin"
                 ) su
                   ON su.id = c.owner_user_id
                   OR su.id = c.created_by_user_id
                   OR su.id = c.updated_by_user_id',
            ],
            [
                'plc_rooms',
                'DELETE r
                 FROM plc_rooms r
                 JOIN (
                    SELECT id
                    FROM plc_users
                    WHERE school_id = ?
                      AND role <> "superadmin"
                 ) su
                   ON su.id = r.owner_user_id
                   OR su.id = r.created_by_user_id
                   OR su.id = r.updated_by_user_id',
            ],
            [
                'plc_teacher_placement_selection',
                'DELETE ts
                 FROM plc_teacher_placement_selection ts
                 JOIN (
                    SELECT id
                    FROM plc_users
                    WHERE school_id = ?
                      AND role <> "superadmin"
                 ) su
                   ON su.id = ts.user_id',
            ],
            [
                'plc_user_backup_codes',
                'DELETE bc
                 FROM plc_user_backup_codes bc
                 JOIN (
                    SELECT id
                    FROM plc_users
                    WHERE school_id = ?
                      AND role <> "superadmin"
                 ) su
                   ON su.id = bc.user_id',
            ],
            [
                'plc_password_resets',
                'DELETE pr
                 FROM plc_password_resets pr
                 JOIN (
                    SELECT id
                    FROM plc_users
                    WHERE school_id = ?
                      AND role <> "superadmin"
                 ) su
                   ON su.id = pr.user_id',
            ],
        ];
        foreach ($deleteJoinSql as $item) {
            $tableName = (string)$item[0];
            $sql = (string)$item[1];
            if (!plc_table_exists($db, $tableName)) {
                continue;
            }
            $stmt = $db->prepare($sql);
            $stmt->bind_param('i', $schoolId);
            $stmt->execute();
        }

        $stmt = $db->prepare(
            'DELETE FROM plc_users
             WHERE school_id = ?
               AND role <> "superadmin"'
        );
        $stmt->bind_param('i', $schoolId);
        $stmt->execute();

        $stmt = $db->prepare(
            'DELETE FROM plc_schools
             WHERE id = ?
             LIMIT 1'
        );
        $stmt->bind_param('i', $schoolId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            throw new RuntimeException('delete_failed');
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    plc_json([
        'ok' => true,
        'schools' => plc_fetch_admin_schools($db),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    plc_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

plc_verify_csrf_or_403();
$body = plc_read_json_body();
$action = (string)($body['action'] ?? '');
$schoolId = (int)($body['schoolId'] ?? 0);

if ($schoolId <= 0 || !plc_school_exists($db, $schoolId)) {
    plc_json(['ok' => false, 'error' => 'invalid_school'], 400);
}

try {
    if ($action === 'set_status') {
        $status = plc_school_status_normalize((string)($body['status'] ?? 'pending'));
        $school = plc_fetch_admin_school($db, $schoolId);
        if (!$school) {
            plc_json(['ok' => false, 'error' => 'invalid_school'], 400);
        }
        plc_update_school_row($db, $schoolId, (string)$school['name'], $status, (bool)$school['require2FA'], $actorId);
    } elseif ($action === 'update_school') {
        $name = trim((string)($body['name'] ?? ''));
        $status = plc_school_status_normalize((string)($body['status'] ?? 'pending'));
        $require2FA = (bool)($body['require2FA'] ?? false);
        $nameLen = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        if ($nameLen < 2 || $nameLen > 190) {
            plc_json([
                'ok' => false,
                'error' => 'invalid_school_name',
                'message' => 'Skolnamn måste vara mellan 2 och 190 tecken.',
            ], 422);
        }
        if (plc_school_name_taken_by_other($db, $name, $schoolId)) {
            plc_json([
                'ok' => false,
                'error' => 'duplicate_school_name',
                'message' => 'Det finns redan en skola med det namnet.',
            ], 409);
        }
        plc_update_school_row($db, $schoolId, $name, $status, $require2FA, $actorId);
    } elseif ($action === 'delete_school') {
        plc_delete_school_with_data($db, $schoolId);
        plc_json([
            'ok' => true,
            'deletedSchoolId' => $schoolId,
        ]);
    } else {
        plc_json(['ok' => false, 'error' => 'invalid_action'], 400);
    }
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'school_not_rejected') {
        plc_json([
            'ok' => false,
            'error' => 'school_not_rejected',
            'message' => 'Endast skolor med status Avslagen kan raderas.',
        ], 409);
    }
    if ($e->getMessage() === 'invalid_school') {
        plc_json(['ok' => false, 'error' => 'invalid_school'], 400);
    }
    plc_json(['ok' => false, 'error' => 'update_failed'], 500);
} catch (Throwable $e) {
    plc_json(['ok' => false, 'error' => 'update_failed'], 500);
}

$updated = plc_fetch_admin_school($db, $schoolId);
if (!$updated) {
    plc_json(['ok' => false, 'error' => 'invalid_school'], 400);
}

plc_json([
    'ok' => true,
    'school' => $updated,
]);
