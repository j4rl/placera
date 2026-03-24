<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/crypto.php';

$user = plc_require_login(true);
$db = plc_db();
$ownerUserId = (int)$user['id'];

function plc_is_entity_editable(int $actorUserId, int $entityOwnerUserId): bool
{
    return $actorUserId === $entityOwnerUserId;
}

function plc_public_id_exists_for_other_owner(mysqli $db, string $kind, int $ownerUserId, string $publicId): bool
{
    if ($publicId === '') {
        return false;
    }

    if ($kind === 'room') {
        $sql = 'SELECT 1 FROM plc_rooms WHERE public_id = ? AND owner_user_id <> ? LIMIT 1';
    } elseif ($kind === 'class') {
        $sql = 'SELECT 1 FROM plc_classes WHERE public_id = ? AND owner_user_id <> ? LIMIT 1';
    } elseif ($kind === 'saved') {
        $sql = 'SELECT 1 FROM plc_placements WHERE public_id = ? AND owner_user_id <> ? LIMIT 1';
    } else {
        return false;
    }

    $stmt = $db->prepare($sql);
    $stmt->bind_param('si', $publicId, $ownerUserId);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}

function plc_safe_public_id(mixed $id, string $prefix): string
{
    $val = is_string($id) ? trim($id) : '';
    if ($val !== '' && preg_match('/^[A-Za-z0-9._-]{4,64}$/', $val)) {
        return $val;
    }
    return $prefix . '_' . (string)time() . '_' . bin2hex(random_bytes(3));
}

function plc_decode_json_array(?string $raw): array
{
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $val = json_decode($raw, true);
    return is_array($val) ? $val : [];
}

function plc_ensure_entity_visibility_columns(mysqli $db): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $roomHasVisibility = false;
    $classHasVisibility = false;

    try {
        $res = $db->query("SHOW COLUMNS FROM plc_rooms LIKE 'visibility'");
        $roomHasVisibility = $res instanceof mysqli_result && $res->num_rows > 0;
    } catch (Throwable $e) {
        $roomHasVisibility = false;
    }
    if (!$roomHasVisibility) {
        $db->query(
            "ALTER TABLE plc_rooms
             ADD COLUMN visibility ENUM('shared','private') NOT NULL DEFAULT 'shared' AFTER desks_json"
        );
    }

    try {
        $res = $db->query("SHOW COLUMNS FROM plc_classes LIKE 'visibility'");
        $classHasVisibility = $res instanceof mysqli_result && $res->num_rows > 0;
    } catch (Throwable $e) {
        $classHasVisibility = false;
    }
    if (!$classHasVisibility) {
        $db->query(
            "ALTER TABLE plc_classes
             ADD COLUMN visibility ENUM('shared','private') NOT NULL DEFAULT 'shared' AFTER students_json"
        );
    }

    $done = true;
}

function plc_teacher_selection_table_exists(mysqli $db): bool
{
    static $cached = null;
    if (is_bool($cached)) {
        return $cached;
    }
    try {
        $res = $db->query("SHOW TABLES LIKE 'plc_teacher_placement_selection'");
        $cached = $res instanceof mysqli_result && $res->num_rows > 0;
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

function plc_normalize_public_id_list(array $raw): array
{
    $out = [];
    $seen = [];
    foreach ($raw as $entry) {
        if (!is_scalar($entry)) {
            continue;
        }
        $id = trim((string)$entry);
        if ($id === '' || isset($seen[$id])) {
            continue;
        }
        if (!preg_match('/^[A-Za-z0-9._-]{4,64}$/', $id)) {
            continue;
        }
        $seen[$id] = true;
        $out[] = $id;
    }
    return $out;
}

function plc_fetch_external_usage_counts(mysqli $db, int $actorUserId): array
{
    $counts = ['room' => [], 'class' => []];
    if (!plc_teacher_selection_table_exists($db)) {
        return $counts;
    }

    $res = $db->query('SELECT user_id, room_ids_json, class_ids_json FROM plc_teacher_placement_selection');
    while ($row = $res->fetch_assoc()) {
        $selectionUserId = (int)$row['user_id'];
        if ($selectionUserId === $actorUserId) {
            continue;
        }
        $roomIds = plc_normalize_public_id_list(plc_decode_json_array($row['room_ids_json']));
        foreach ($roomIds as $publicId) {
            $counts['room'][$publicId] = (int)($counts['room'][$publicId] ?? 0) + 1;
        }
        $classIds = plc_normalize_public_id_list(plc_decode_json_array($row['class_ids_json']));
        foreach ($classIds as $publicId) {
            $counts['class'][$publicId] = (int)($counts['class'][$publicId] ?? 0) + 1;
        }
    }

    return $counts;
}

function plc_fetch_teacher_selected_ids_for_saved(mysqli $db, int $teacherUserId): array
{
    if (!plc_teacher_selection_table_exists($db)) {
        return ['roomIds' => [], 'classIds' => []];
    }

    $stmt = $db->prepare(
        'SELECT room_ids_json, class_ids_json
         FROM plc_teacher_placement_selection
         WHERE user_id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $teacherUserId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return ['roomIds' => [], 'classIds' => []];
    }

    $roomIds = plc_normalize_public_id_list(plc_decode_json_array($row['room_ids_json']));
    $classIds = plc_normalize_public_id_list(plc_decode_json_array($row['class_ids_json']));
    return ['roomIds' => $roomIds, 'classIds' => $classIds];
}

function plc_fetch_rooms(mysqli $db, int $actorUserId, array $roomUsageById = []): array
{
    $stmt = $db->prepare(
        'SELECT r.owner_user_id, r.public_id, r.name, r.desks_json, r.visibility, r.created_at, r.updated_at,
                ou.full_name AS owner_name,
                cu.full_name AS created_by_name, uu.full_name AS updated_by_name
         FROM plc_rooms r
         JOIN plc_users ou ON ou.id = r.owner_user_id
         JOIN plc_users cu ON cu.id = r.created_by_user_id
         LEFT JOIN plc_users uu ON uu.id = r.updated_by_user_id
         WHERE ou.status = \'approved\'
           AND (r.owner_user_id = ? OR r.visibility = \'shared\')
         ORDER BY r.created_at DESC'
    );
    $stmt->bind_param('i', $actorUserId);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $entityOwnerUserId = (int)$row['owner_user_id'];
        $visibility = (string)$row['visibility'] === 'private' ? 'private' : 'shared';
        $out[] = [
            'id' => $row['public_id'],
            'ownerUserId' => $entityOwnerUserId,
            'ownerName' => $row['owner_name'],
            'editable' => plc_is_entity_editable($actorUserId, $entityOwnerUserId),
            'usedByOthersCount' => $visibility === 'shared'
                ? (int)($roomUsageById[(string)$row['public_id']] ?? 0)
                : 0,
            'visibility' => $visibility,
            'name' => $row['name'],
            'desks' => plc_decode_json_array($row['desks_json']),
            'createdByName' => $row['created_by_name'],
            'createdAt' => $row['created_at'],
            'updatedByName' => $row['updated_by_name'],
            'updatedAt' => $row['updated_at'],
        ];
    }
    return $out;
}

function plc_fetch_room_by_public_id(mysqli $db, int $actorUserId, int $entityOwnerUserId, string $publicId): ?array
{
    $stmt = $db->prepare(
        'SELECT r.owner_user_id, r.public_id, r.name, r.desks_json, r.visibility, r.created_at, r.updated_at,
                ou.full_name AS owner_name,
                cu.full_name AS created_by_name, uu.full_name AS updated_by_name
         FROM plc_rooms r
         JOIN plc_users ou ON ou.id = r.owner_user_id
         JOIN plc_users cu ON cu.id = r.created_by_user_id
         LEFT JOIN plc_users uu ON uu.id = r.updated_by_user_id
         WHERE r.owner_user_id = ? AND r.public_id = ?
         LIMIT 1'
    );
    $stmt->bind_param('is', $entityOwnerUserId, $publicId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return null;
    }
    $usageCounts = plc_fetch_external_usage_counts($db, $actorUserId);
    $resolvedOwnerUserId = (int)$row['owner_user_id'];
    $visibility = (string)$row['visibility'] === 'private' ? 'private' : 'shared';
    return [
        'id' => $row['public_id'],
        'ownerUserId' => $resolvedOwnerUserId,
        'ownerName' => $row['owner_name'],
        'editable' => plc_is_entity_editable($actorUserId, $resolvedOwnerUserId),
        'usedByOthersCount' => $visibility === 'shared'
            ? (int)($usageCounts['room'][(string)$row['public_id']] ?? 0)
            : 0,
        'visibility' => $visibility,
        'name' => $row['name'],
        'desks' => plc_decode_json_array($row['desks_json']),
        'createdByName' => $row['created_by_name'],
        'createdAt' => $row['created_at'],
        'updatedByName' => $row['updated_by_name'],
        'updatedAt' => $row['updated_at'],
    ];
}

function plc_fetch_classes(mysqli $db, int $actorUserId, array $classUsageById = []): array
{
    $stmt = $db->prepare(
        'SELECT c.owner_user_id, c.public_id, c.name, c.students_json, c.visibility, c.created_at, c.updated_at,
                ou.full_name AS owner_name,
                cu.full_name AS created_by_name, uu.full_name AS updated_by_name
         FROM plc_classes c
         JOIN plc_users ou ON ou.id = c.owner_user_id
         JOIN plc_users cu ON cu.id = c.created_by_user_id
         LEFT JOIN plc_users uu ON uu.id = c.updated_by_user_id
         WHERE ou.status = \'approved\'
           AND (c.owner_user_id = ? OR c.visibility = \'shared\')
         ORDER BY c.created_at DESC'
    );
    $stmt->bind_param('i', $actorUserId);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $entityOwnerUserId = (int)$row['owner_user_id'];
        $visibility = (string)$row['visibility'] === 'private' ? 'private' : 'shared';
        $out[] = [
            'id' => $row['public_id'],
            'ownerUserId' => $entityOwnerUserId,
            'ownerName' => $row['owner_name'],
            'editable' => plc_is_entity_editable($actorUserId, $entityOwnerUserId),
            'usedByOthersCount' => $visibility === 'shared'
                ? (int)($classUsageById[(string)$row['public_id']] ?? 0)
                : 0,
            'visibility' => $visibility,
            'name' => $row['name'],
            'students' => plc_decrypt_student_list(plc_decode_json_array($row['students_json'])),
            'createdByName' => $row['created_by_name'],
            'createdAt' => $row['created_at'],
            'updatedByName' => $row['updated_by_name'],
            'updatedAt' => $row['updated_at'],
        ];
    }
    return $out;
}

function plc_fetch_class_by_public_id(mysqli $db, int $actorUserId, int $entityOwnerUserId, string $publicId): ?array
{
    $stmt = $db->prepare(
        'SELECT c.owner_user_id, c.public_id, c.name, c.students_json, c.visibility, c.created_at, c.updated_at,
                ou.full_name AS owner_name,
                cu.full_name AS created_by_name, uu.full_name AS updated_by_name
         FROM plc_classes c
         JOIN plc_users ou ON ou.id = c.owner_user_id
         JOIN plc_users cu ON cu.id = c.created_by_user_id
         LEFT JOIN plc_users uu ON uu.id = c.updated_by_user_id
         WHERE c.owner_user_id = ? AND c.public_id = ?
         LIMIT 1'
    );
    $stmt->bind_param('is', $entityOwnerUserId, $publicId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return null;
    }
    $usageCounts = plc_fetch_external_usage_counts($db, $actorUserId);
    $resolvedOwnerUserId = (int)$row['owner_user_id'];
    $visibility = (string)$row['visibility'] === 'private' ? 'private' : 'shared';
    return [
        'id' => $row['public_id'],
        'ownerUserId' => $resolvedOwnerUserId,
        'ownerName' => $row['owner_name'],
        'editable' => plc_is_entity_editable($actorUserId, $resolvedOwnerUserId),
        'usedByOthersCount' => $visibility === 'shared'
            ? (int)($usageCounts['class'][(string)$row['public_id']] ?? 0)
            : 0,
        'visibility' => $visibility,
        'name' => $row['name'],
        'students' => plc_decrypt_student_list(plc_decode_json_array($row['students_json'])),
        'createdByName' => $row['created_by_name'],
        'createdAt' => $row['created_at'],
        'updatedByName' => $row['updated_by_name'],
        'updatedAt' => $row['updated_at'],
    ];
}

function plc_fetch_saved(mysqli $db, int $actorUserId, string $actorRole): array
{
    $actorUserIdSql = (string)(int)$actorUserId;
    $whereParts = ["p.owner_user_id = {$actorUserIdSql}"];

    if ($actorRole === 'teacher') {
        $selection = plc_fetch_teacher_selected_ids_for_saved($db, $actorUserId);
        if ($selection['roomIds'] && $selection['classIds']) {
            $roomQuoted = [];
            foreach ($selection['roomIds'] as $roomId) {
                $roomQuoted[] = "'" . $db->real_escape_string($roomId) . "'";
            }
            $classQuoted = [];
            foreach ($selection['classIds'] as $classId) {
                $classQuoted[] = "'" . $db->real_escape_string($classId) . "'";
            }
            if ($roomQuoted && $classQuoted) {
                $roomIn = implode(',', $roomQuoted);
                $classIn = implode(',', $classQuoted);
                $whereParts[] = "(p.owner_user_id <> {$actorUserIdSql}
                    AND p.room_public_id IS NOT NULL AND p.room_public_id <> ''
                    AND p.class_public_id IS NOT NULL AND p.class_public_id <> ''
                    AND p.room_public_id IN ({$roomIn})
                    AND p.class_public_id IN ({$classIn})
                    AND EXISTS (
                        SELECT 1
                        FROM plc_rooms rs
                        WHERE rs.owner_user_id = p.owner_user_id
                          AND rs.public_id = p.room_public_id
                          AND rs.visibility = 'shared'
                    )
                    AND EXISTS (
                        SELECT 1
                        FROM plc_classes cs
                        WHERE cs.owner_user_id = p.owner_user_id
                          AND cs.public_id = p.class_public_id
                          AND cs.visibility = 'shared'
                    ))";
            }
        }
    }

    $whereSql = implode(' OR ', $whereParts);
    $sql = "SELECT p.owner_user_id, p.public_id, p.name, p.room_name, p.class_name, p.room_public_id, p.class_public_id,
                   p.pairs_json, p.saved_at, p.created_at, p.updated_at,
                   ou.full_name AS owner_name, cu.full_name AS created_by_name, uu.full_name AS updated_by_name
            FROM plc_placements p
            JOIN plc_users ou ON ou.id = p.owner_user_id
            JOIN plc_users cu ON cu.id = p.created_by_user_id
            LEFT JOIN plc_users uu ON uu.id = p.updated_by_user_id
            WHERE ou.status = 'approved' AND ({$whereSql})
            ORDER BY COALESCE(p.saved_at, p.created_at) DESC";
    $res = $db->query($sql);
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $savedAt = $row['saved_at'] ?: $row['created_at'];
        $entityOwnerUserId = (int)$row['owner_user_id'];
        $out[] = [
            'id' => $row['public_id'],
            'ownerUserId' => $entityOwnerUserId,
            'ownerName' => $row['owner_name'],
            'editable' => plc_is_entity_editable($actorUserId, $entityOwnerUserId),
            'name' => $row['name'],
            'roomName' => $row['room_name'],
            'className' => $row['class_name'],
            'roomId' => $row['room_public_id'],
            'classId' => $row['class_public_id'],
            'pairs' => plc_decrypt_pairs(plc_decode_json_array($row['pairs_json'])),
            'savedAt' => $savedAt,
            'createdByName' => $row['created_by_name'],
            'createdAt' => $row['created_at'],
            'updatedByName' => $row['updated_by_name'],
            'updatedAt' => $row['updated_at'],
        ];
    }
    return $out;
}

function plc_fetch_saved_by_public_id(mysqli $db, int $actorUserId, int $entityOwnerUserId, string $publicId): ?array
{
    $stmt = $db->prepare(
        'SELECT p.owner_user_id, p.public_id, p.name, p.room_name, p.class_name, p.room_public_id, p.class_public_id,
                p.pairs_json, p.saved_at, p.created_at, p.updated_at,
                ou.full_name AS owner_name, cu.full_name AS created_by_name, uu.full_name AS updated_by_name
         FROM plc_placements p
         JOIN plc_users ou ON ou.id = p.owner_user_id
         JOIN plc_users cu ON cu.id = p.created_by_user_id
         LEFT JOIN plc_users uu ON uu.id = p.updated_by_user_id
         WHERE p.owner_user_id = ? AND p.public_id = ?
         LIMIT 1'
    );
    $stmt->bind_param('is', $entityOwnerUserId, $publicId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return null;
    }
    $savedAt = $row['saved_at'] ?: $row['created_at'];
    $resolvedOwnerUserId = (int)$row['owner_user_id'];
    return [
        'id' => $row['public_id'],
        'ownerUserId' => $resolvedOwnerUserId,
        'ownerName' => $row['owner_name'],
        'editable' => plc_is_entity_editable($actorUserId, $resolvedOwnerUserId),
        'name' => $row['name'],
        'roomName' => $row['room_name'],
        'className' => $row['class_name'],
        'roomId' => $row['room_public_id'],
        'classId' => $row['class_public_id'],
        'pairs' => plc_decrypt_pairs(plc_decode_json_array($row['pairs_json'])),
        'savedAt' => $savedAt,
        'createdByName' => $row['created_by_name'],
        'createdAt' => $row['created_at'],
        'updatedByName' => $row['updated_by_name'],
        'updatedAt' => $row['updated_at'],
    ];
}

function plc_upsert_room(mysqli $db, int $actorUserId, array $item): ?array
{
    $name = trim((string)($item['name'] ?? ''));
    if ($name === '') {
        return null;
    }
    $visibility = (string)($item['visibility'] ?? '') === 'private' ? 'private' : 'shared';
    $rawPublicId = is_string($item['id'] ?? null) ? trim((string)$item['id']) : '';
    $publicId = plc_safe_public_id($rawPublicId !== '' ? $rawPublicId : null, 'room');
    $desks = $item['desks'] ?? [];
    if (!is_array($desks)) {
        $desks = [];
    }
    $desksJson = json_encode($desks, JSON_UNESCAPED_UNICODE);
    if ($desksJson === false) {
        $desksJson = '[]';
    }

    $stmt = $db->prepare('SELECT id FROM plc_rooms WHERE owner_user_id = ? AND public_id = ? LIMIT 1');
    $stmt->bind_param('is', $actorUserId, $publicId);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();

    if ($exists) {
        $stmt = $db->prepare(
            'UPDATE plc_rooms
             SET name = ?, desks_json = ?, visibility = ?, updated_by_user_id = ?, updated_at = NOW()
             WHERE owner_user_id = ? AND public_id = ?'
        );
        $stmt->bind_param('sssiis', $name, $desksJson, $visibility, $actorUserId, $actorUserId, $publicId);
        $stmt->execute();
    } else {
        if (
            $rawPublicId !== '' &&
            plc_public_id_exists_for_other_owner($db, 'room', $actorUserId, $publicId)
        ) {
            throw new RuntimeException('forbidden');
        }
        $stmt = $db->prepare(
            'INSERT INTO plc_rooms (
                public_id, owner_user_id, name, desks_json, visibility, created_by_user_id, updated_by_user_id
             ) VALUES (?, ?, ?, ?, ?, ?, NULL)'
        );
        $stmt->bind_param('sisssi', $publicId, $actorUserId, $name, $desksJson, $visibility, $actorUserId);
        $stmt->execute();
    }

    return plc_fetch_room_by_public_id($db, $actorUserId, $actorUserId, $publicId);
}

function plc_delete_room(mysqli $db, int $actorUserId, string $publicId): string
{
    $stmt = $db->prepare('SELECT id FROM plc_rooms WHERE owner_user_id = ? AND public_id = ? LIMIT 1');
    $stmt->bind_param('is', $actorUserId, $publicId);
    $stmt->execute();
    $ownsEntity = (bool)$stmt->get_result()->fetch_assoc();
    if (!$ownsEntity && plc_public_id_exists_for_other_owner($db, 'room', $actorUserId, $publicId)) {
        throw new RuntimeException('forbidden');
    }

    $stmt = $db->prepare('DELETE FROM plc_rooms WHERE owner_user_id = ? AND public_id = ?');
    $stmt->bind_param('is', $actorUserId, $publicId);
    $stmt->execute();
    return $publicId;
}

function plc_upsert_class(mysqli $db, int $actorUserId, array $item): ?array
{
    $name = trim((string)($item['name'] ?? ''));
    if ($name === '') {
        return null;
    }
    $visibility = (string)($item['visibility'] ?? '') === 'private' ? 'private' : 'shared';
    $rawPublicId = is_string($item['id'] ?? null) ? trim((string)$item['id']) : '';
    $publicId = plc_safe_public_id($rawPublicId !== '' ? $rawPublicId : null, 'cls');
    $students = $item['students'] ?? [];
    if (!is_array($students)) {
        $students = [];
    }
    $students = plc_encrypt_student_list($students);
    $studentsJson = json_encode($students, JSON_UNESCAPED_UNICODE);
    if ($studentsJson === false) {
        $studentsJson = '[]';
    }

    $stmt = $db->prepare('SELECT id FROM plc_classes WHERE owner_user_id = ? AND public_id = ? LIMIT 1');
    $stmt->bind_param('is', $actorUserId, $publicId);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();

    if ($exists) {
        $stmt = $db->prepare(
            'UPDATE plc_classes
             SET name = ?, students_json = ?, visibility = ?, updated_by_user_id = ?, updated_at = NOW()
             WHERE owner_user_id = ? AND public_id = ?'
        );
        $stmt->bind_param('sssiis', $name, $studentsJson, $visibility, $actorUserId, $actorUserId, $publicId);
        $stmt->execute();
    } else {
        if (
            $rawPublicId !== '' &&
            plc_public_id_exists_for_other_owner($db, 'class', $actorUserId, $publicId)
        ) {
            throw new RuntimeException('forbidden');
        }
        $stmt = $db->prepare(
            'INSERT INTO plc_classes (
                public_id, owner_user_id, name, students_json, visibility, created_by_user_id, updated_by_user_id
             ) VALUES (?, ?, ?, ?, ?, ?, NULL)'
        );
        $stmt->bind_param('sisssi', $publicId, $actorUserId, $name, $studentsJson, $visibility, $actorUserId);
        $stmt->execute();
    }

    return plc_fetch_class_by_public_id($db, $actorUserId, $actorUserId, $publicId);
}

function plc_delete_class(mysqli $db, int $actorUserId, string $publicId): string
{
    $stmt = $db->prepare('SELECT id FROM plc_classes WHERE owner_user_id = ? AND public_id = ? LIMIT 1');
    $stmt->bind_param('is', $actorUserId, $publicId);
    $stmt->execute();
    $ownsEntity = (bool)$stmt->get_result()->fetch_assoc();
    if (!$ownsEntity && plc_public_id_exists_for_other_owner($db, 'class', $actorUserId, $publicId)) {
        throw new RuntimeException('forbidden');
    }

    $stmt = $db->prepare('DELETE FROM plc_classes WHERE owner_user_id = ? AND public_id = ?');
    $stmt->bind_param('is', $actorUserId, $publicId);
    $stmt->execute();
    return $publicId;
}

function plc_upsert_saved(mysqli $db, int $ownerUserId, int $actorUserId, array $item): ?array
{
    $name = trim((string)($item['name'] ?? ''));
    if ($name === '') {
        return null;
    }
    $rawPublicId = is_string($item['id'] ?? null) ? trim((string)$item['id']) : '';
    $publicId = plc_safe_public_id($rawPublicId !== '' ? $rawPublicId : null, 'pl');
    $roomName = trim((string)($item['roomName'] ?? ''));
    $className = trim((string)($item['className'] ?? ''));
    $roomPublicId = trim((string)($item['roomId'] ?? ''));
    $classPublicId = trim((string)($item['classId'] ?? ''));
    $savedAtRaw = trim((string)($item['savedAt'] ?? ''));
    $savedAtTs = $savedAtRaw !== '' ? strtotime($savedAtRaw) : false;
    $savedAt = $savedAtTs !== false ? date('Y-m-d H:i:s', $savedAtTs) : date('Y-m-d H:i:s');
    $pairs = $item['pairs'] ?? [];
    if (!is_array($pairs)) {
        $pairs = [];
    }
    $pairs = plc_encrypt_pairs($pairs);
    $pairsJson = json_encode($pairs, JSON_UNESCAPED_UNICODE);
    if ($pairsJson === false) {
        $pairsJson = '[]';
    }

    $stmt = $db->prepare('SELECT id FROM plc_placements WHERE owner_user_id = ? AND public_id = ? LIMIT 1');
    $stmt->bind_param('is', $ownerUserId, $publicId);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();

    if ($exists) {
        $stmt = $db->prepare(
            'UPDATE plc_placements
             SET name = ?, room_name = ?, class_name = ?, room_public_id = ?, class_public_id = ?,
                 pairs_json = ?, saved_at = ?, updated_by_user_id = ?, updated_at = NOW()
             WHERE owner_user_id = ? AND public_id = ?'
        );
        $stmt->bind_param(
            'sssssssiis',
            $name,
            $roomName,
            $className,
            $roomPublicId,
            $classPublicId,
            $pairsJson,
            $savedAt,
            $actorUserId,
            $ownerUserId,
            $publicId
        );
        $stmt->execute();
    } else {
        if (
            $rawPublicId !== '' &&
            plc_public_id_exists_for_other_owner($db, 'saved', $ownerUserId, $publicId)
        ) {
            throw new RuntimeException('forbidden');
        }
        $stmt = $db->prepare(
            'INSERT INTO plc_placements (
                public_id, owner_user_id, name, room_name, class_name, room_public_id, class_public_id,
                pairs_json, saved_at, created_by_user_id, updated_by_user_id
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)'
        );
        $stmt->bind_param(
            'sisssssssi',
            $publicId,
            $ownerUserId,
            $name,
            $roomName,
            $className,
            $roomPublicId,
            $classPublicId,
            $pairsJson,
            $savedAt,
            $actorUserId
        );
        $stmt->execute();
    }

    return plc_fetch_saved_by_public_id($db, $actorUserId, $ownerUserId, $publicId);
}

function plc_delete_saved(mysqli $db, int $ownerUserId, string $publicId): string
{
    $stmt = $db->prepare('SELECT id FROM plc_placements WHERE owner_user_id = ? AND public_id = ? LIMIT 1');
    $stmt->bind_param('is', $ownerUserId, $publicId);
    $stmt->execute();
    $ownsEntity = (bool)$stmt->get_result()->fetch_assoc();
    if (!$ownsEntity && plc_public_id_exists_for_other_owner($db, 'saved', $ownerUserId, $publicId)) {
        throw new RuntimeException('forbidden');
    }

    $stmt = $db->prepare('DELETE FROM plc_placements WHERE owner_user_id = ? AND public_id = ?');
    $stmt->bind_param('is', $ownerUserId, $publicId);
    $stmt->execute();
    return $publicId;
}

function plc_sync_rooms(mysqli $db, int $ownerUserId, int $actorUserId, array $items): array
{
    $existing = [];
    $stmt = $db->prepare('SELECT public_id FROM plc_rooms WHERE owner_user_id = ?');
    $stmt->bind_param('i', $ownerUserId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $existing[(string)$row['public_id']] = true;
    }

    $seen = [];
    $ins = $db->prepare(
        'INSERT INTO plc_rooms (
            public_id, owner_user_id, name, desks_json, visibility, created_by_user_id, updated_by_user_id
         ) VALUES (?, ?, ?, ?, ?, ?, NULL)'
    );
    $upd = $db->prepare(
        'UPDATE plc_rooms
         SET name = ?, desks_json = ?, visibility = ?, updated_by_user_id = ?, updated_at = NOW()
         WHERE owner_user_id = ? AND public_id = ?'
    );
    $del = $db->prepare('DELETE FROM plc_rooms WHERE owner_user_id = ? AND public_id = ?');

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $itemOwnerUserId = (int)($item['ownerUserId'] ?? $ownerUserId);
        if ($itemOwnerUserId !== $ownerUserId) {
            continue;
        }
        $name = trim((string)($item['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $publicId = plc_safe_public_id($item['id'] ?? null, 'room');
        $desks = $item['desks'] ?? [];
        if (!is_array($desks)) {
            $desks = [];
        }
        $desksJson = json_encode($desks, JSON_UNESCAPED_UNICODE);
        if ($desksJson === false) {
            $desksJson = '[]';
        }
        $visibility = (string)($item['visibility'] ?? '') === 'private' ? 'private' : 'shared';
        if (!isset($existing[$publicId]) && plc_public_id_exists_for_other_owner($db, 'room', $ownerUserId, $publicId)) {
            continue;
        }
        $seen[$publicId] = true;

        if (isset($existing[$publicId])) {
            $upd->bind_param('sssiis', $name, $desksJson, $visibility, $actorUserId, $ownerUserId, $publicId);
            $upd->execute();
        } else {
            $ins->bind_param('sisssi', $publicId, $ownerUserId, $name, $desksJson, $visibility, $actorUserId);
            $ins->execute();
        }
    }

    foreach (array_keys($existing) as $publicId) {
        if (!isset($seen[$publicId])) {
            $del->bind_param('is', $ownerUserId, $publicId);
            $del->execute();
        }
    }

    $usageCounts = plc_fetch_external_usage_counts($db, $ownerUserId);
    return plc_fetch_rooms($db, $ownerUserId, $usageCounts['room']);
}

function plc_sync_classes(mysqli $db, int $ownerUserId, int $actorUserId, array $items): array
{
    $existing = [];
    $stmt = $db->prepare('SELECT public_id FROM plc_classes WHERE owner_user_id = ?');
    $stmt->bind_param('i', $ownerUserId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $existing[(string)$row['public_id']] = true;
    }

    $seen = [];
    $ins = $db->prepare(
        'INSERT INTO plc_classes (
            public_id, owner_user_id, name, students_json, visibility, created_by_user_id, updated_by_user_id
         ) VALUES (?, ?, ?, ?, ?, ?, NULL)'
    );
    $upd = $db->prepare(
        'UPDATE plc_classes
         SET name = ?, students_json = ?, visibility = ?, updated_by_user_id = ?, updated_at = NOW()
         WHERE owner_user_id = ? AND public_id = ?'
    );
    $del = $db->prepare('DELETE FROM plc_classes WHERE owner_user_id = ? AND public_id = ?');

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $itemOwnerUserId = (int)($item['ownerUserId'] ?? $ownerUserId);
        if ($itemOwnerUserId !== $ownerUserId) {
            continue;
        }
        $name = trim((string)($item['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $publicId = plc_safe_public_id($item['id'] ?? null, 'cls');
        $students = $item['students'] ?? [];
        if (!is_array($students)) {
            $students = [];
        }
        $students = plc_encrypt_student_list($students);
        $studentsJson = json_encode($students, JSON_UNESCAPED_UNICODE);
        if ($studentsJson === false) {
            $studentsJson = '[]';
        }
        $visibility = (string)($item['visibility'] ?? '') === 'private' ? 'private' : 'shared';
        if (!isset($existing[$publicId]) && plc_public_id_exists_for_other_owner($db, 'class', $ownerUserId, $publicId)) {
            continue;
        }
        $seen[$publicId] = true;

        if (isset($existing[$publicId])) {
            $upd->bind_param('sssiis', $name, $studentsJson, $visibility, $actorUserId, $ownerUserId, $publicId);
            $upd->execute();
        } else {
            $ins->bind_param('sisssi', $publicId, $ownerUserId, $name, $studentsJson, $visibility, $actorUserId);
            $ins->execute();
        }
    }

    foreach (array_keys($existing) as $publicId) {
        if (!isset($seen[$publicId])) {
            $del->bind_param('is', $ownerUserId, $publicId);
            $del->execute();
        }
    }

    $usageCounts = plc_fetch_external_usage_counts($db, $ownerUserId);
    return plc_fetch_classes($db, $ownerUserId, $usageCounts['class']);
}

function plc_sync_saved(mysqli $db, int $ownerUserId, int $actorUserId, string $actorRole, array $items): array
{
    $existing = [];
    $stmt = $db->prepare('SELECT public_id FROM plc_placements WHERE owner_user_id = ?');
    $stmt->bind_param('i', $ownerUserId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $existing[(string)$row['public_id']] = true;
    }

    $seen = [];
    $ins = $db->prepare(
        'INSERT INTO plc_placements (
            public_id, owner_user_id, name, room_name, class_name, room_public_id, class_public_id,
            pairs_json, saved_at, created_by_user_id, updated_by_user_id
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)'
    );
    $upd = $db->prepare(
        'UPDATE plc_placements
         SET name = ?, room_name = ?, class_name = ?, room_public_id = ?, class_public_id = ?,
             pairs_json = ?, saved_at = ?, updated_by_user_id = ?, updated_at = NOW()
         WHERE owner_user_id = ? AND public_id = ?'
    );
    $del = $db->prepare('DELETE FROM plc_placements WHERE owner_user_id = ? AND public_id = ?');

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $itemOwnerUserId = (int)($item['ownerUserId'] ?? $ownerUserId);
        if ($itemOwnerUserId !== $ownerUserId) {
            continue;
        }
        $name = trim((string)($item['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $publicId = plc_safe_public_id($item['id'] ?? null, 'pl');
        $roomName = trim((string)($item['roomName'] ?? ''));
        $className = trim((string)($item['className'] ?? ''));
        $roomPublicId = trim((string)($item['roomId'] ?? ''));
        $classPublicId = trim((string)($item['classId'] ?? ''));
        $savedAtRaw = trim((string)($item['savedAt'] ?? ''));
        $savedAtTs = $savedAtRaw !== '' ? strtotime($savedAtRaw) : false;
        $savedAt = $savedAtTs !== false ? date('Y-m-d H:i:s', $savedAtTs) : date('Y-m-d H:i:s');
        $pairs = $item['pairs'] ?? [];
        if (!is_array($pairs)) {
            $pairs = [];
        }
        $pairs = plc_encrypt_pairs($pairs);
        $pairsJson = json_encode($pairs, JSON_UNESCAPED_UNICODE);
        if ($pairsJson === false) {
            $pairsJson = '[]';
        }
        if (!isset($existing[$publicId]) && plc_public_id_exists_for_other_owner($db, 'saved', $ownerUserId, $publicId)) {
            continue;
        }
        $seen[$publicId] = true;

        if (isset($existing[$publicId])) {
            $upd->bind_param(
                'sssssssiis',
                $name,
                $roomName,
                $className,
                $roomPublicId,
                $classPublicId,
                $pairsJson,
                $savedAt,
                $actorUserId,
                $ownerUserId,
                $publicId
            );
            $upd->execute();
        } else {
            $ins->bind_param(
                'sisssssssi',
                $publicId,
                $ownerUserId,
                $name,
                $roomName,
                $className,
                $roomPublicId,
                $classPublicId,
                $pairsJson,
                $savedAt,
                $actorUserId
            );
            $ins->execute();
        }
    }

    foreach (array_keys($existing) as $publicId) {
        if (!isset($seen[$publicId])) {
            $del->bind_param('is', $ownerUserId, $publicId);
            $del->execute();
        }
    }

    return plc_fetch_saved($db, $ownerUserId, $actorRole);
}

plc_ensure_entity_visibility_columns($db);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $usageCounts = plc_fetch_external_usage_counts($db, $ownerUserId);
        $actorRole = (string)($user['role'] ?? 'teacher');
        $payload = [
            'ok' => true,
            'user' => [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'fullName' => $user['full_name'],
                'email' => $user['email'],
                'role' => $user['role'],
            ],
            'rooms' => plc_fetch_rooms($db, $ownerUserId, $usageCounts['room']),
            'classes' => plc_fetch_classes($db, $ownerUserId, $usageCounts['class']),
            'saved' => plc_fetch_saved($db, $ownerUserId, $actorRole),
        ];
        plc_json($payload);
    } catch (Throwable $e) {
        plc_json(['ok' => false, 'error' => 'state_read_failed'], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    plc_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

plc_verify_csrf_or_403();
$body = plc_read_json_body();
$key = (string)($body['key'] ?? '');
$action = (string)($body['action'] ?? 'replace');
$actorRole = (string)($user['role'] ?? 'teacher');
$value = $body['value'] ?? [];
if (!is_array($value)) {
    $value = [];
}
$item = $body['item'] ?? null;
$publicId = trim((string)($body['id'] ?? ''));

try {
    if ($action === 'replace') {
        if ($key === 'rooms') {
            $items = plc_sync_rooms($db, $ownerUserId, $ownerUserId, $value);
        } elseif ($key === 'classes') {
            $items = plc_sync_classes($db, $ownerUserId, $ownerUserId, $value);
        } elseif ($key === 'saved') {
            $items = plc_sync_saved($db, $ownerUserId, $ownerUserId, $actorRole, $value);
        } else {
            plc_json(['ok' => false, 'error' => 'invalid_key'], 400);
        }
    } elseif ($action === 'upsert') {
        if (!is_array($item)) {
            plc_json(['ok' => false, 'error' => 'invalid_item'], 400);
        }
        if ($key === 'rooms') {
            $updatedItem = plc_upsert_room($db, $ownerUserId, $item);
        } elseif ($key === 'classes') {
            $updatedItem = plc_upsert_class($db, $ownerUserId, $item);
        } elseif ($key === 'saved') {
            $updatedItem = plc_upsert_saved($db, $ownerUserId, $ownerUserId, $item);
        } else {
            plc_json(['ok' => false, 'error' => 'invalid_key'], 400);
        }
        if (!$updatedItem) {
            plc_json(['ok' => false, 'error' => 'invalid_item'], 400);
        }
    } elseif ($action === 'delete') {
        if ($publicId === '') {
            plc_json(['ok' => false, 'error' => 'invalid_id'], 400);
        }
        if ($key === 'rooms') {
            $deletedId = plc_delete_room($db, $ownerUserId, $publicId);
        } elseif ($key === 'classes') {
            $deletedId = plc_delete_class($db, $ownerUserId, $publicId);
        } elseif ($key === 'saved') {
            $deletedId = plc_delete_saved($db, $ownerUserId, $publicId);
        } else {
            plc_json(['ok' => false, 'error' => 'invalid_key'], 400);
        }
    } else {
        plc_json(['ok' => false, 'error' => 'invalid_action'], 400);
    }
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'forbidden') {
        plc_json([
            'ok' => false,
            'error' => 'forbidden',
            'message' => 'Du kan bara ändra dina egna klasser, salar och placeringar.',
        ], 403);
    }
    plc_json(['ok' => false, 'error' => 'save_failed'], 500);
} catch (Throwable $e) {
    plc_json(['ok' => false, 'error' => 'save_failed'], 500);
}

$payload = [
    'ok' => true,
    'key' => $key,
    'action' => $action,
];
if (isset($items)) {
    $payload['items'] = $items;
}
if (isset($updatedItem)) {
    $payload['item'] = $updatedItem;
}
if (isset($deletedId)) {
    $payload['deletedId'] = $deletedId;
}

plc_json($payload);
