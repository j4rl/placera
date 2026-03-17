<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$user = plc_require_login(true);
$db = plc_db();
$ownerUserId = (int)$user['id'];

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

function plc_fetch_rooms(mysqli $db, int $ownerUserId): array
{
    $stmt = $db->prepare(
        'SELECT r.public_id, r.name, r.desks_json, r.created_at, r.updated_at,
                cu.full_name AS created_by_name, uu.full_name AS updated_by_name
         FROM plc_rooms r
         JOIN plc_users cu ON cu.id = r.created_by_user_id
         LEFT JOIN plc_users uu ON uu.id = r.updated_by_user_id
         WHERE r.owner_user_id = ?
         ORDER BY r.created_at DESC'
    );
    $stmt->bind_param('i', $ownerUserId);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = [
            'id' => $row['public_id'],
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

function plc_fetch_room_by_public_id(mysqli $db, int $ownerUserId, string $publicId): ?array
{
    $stmt = $db->prepare(
        'SELECT r.public_id, r.name, r.desks_json, r.created_at, r.updated_at,
                cu.full_name AS created_by_name, uu.full_name AS updated_by_name
         FROM plc_rooms r
         JOIN plc_users cu ON cu.id = r.created_by_user_id
         LEFT JOIN plc_users uu ON uu.id = r.updated_by_user_id
         WHERE r.owner_user_id = ? AND r.public_id = ?
         LIMIT 1'
    );
    $stmt->bind_param('is', $ownerUserId, $publicId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return null;
    }
    return [
        'id' => $row['public_id'],
        'name' => $row['name'],
        'desks' => plc_decode_json_array($row['desks_json']),
        'createdByName' => $row['created_by_name'],
        'createdAt' => $row['created_at'],
        'updatedByName' => $row['updated_by_name'],
        'updatedAt' => $row['updated_at'],
    ];
}

function plc_fetch_classes(mysqli $db, int $ownerUserId): array
{
    $stmt = $db->prepare(
        'SELECT c.public_id, c.name, c.students_json, c.created_at, c.updated_at,
                cu.full_name AS created_by_name, uu.full_name AS updated_by_name
         FROM plc_classes c
         JOIN plc_users cu ON cu.id = c.created_by_user_id
         LEFT JOIN plc_users uu ON uu.id = c.updated_by_user_id
         WHERE c.owner_user_id = ?
         ORDER BY c.created_at DESC'
    );
    $stmt->bind_param('i', $ownerUserId);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = [
            'id' => $row['public_id'],
            'name' => $row['name'],
            'students' => plc_decode_json_array($row['students_json']),
            'createdByName' => $row['created_by_name'],
            'createdAt' => $row['created_at'],
            'updatedByName' => $row['updated_by_name'],
            'updatedAt' => $row['updated_at'],
        ];
    }
    return $out;
}

function plc_fetch_class_by_public_id(mysqli $db, int $ownerUserId, string $publicId): ?array
{
    $stmt = $db->prepare(
        'SELECT c.public_id, c.name, c.students_json, c.created_at, c.updated_at,
                cu.full_name AS created_by_name, uu.full_name AS updated_by_name
         FROM plc_classes c
         JOIN plc_users cu ON cu.id = c.created_by_user_id
         LEFT JOIN plc_users uu ON uu.id = c.updated_by_user_id
         WHERE c.owner_user_id = ? AND c.public_id = ?
         LIMIT 1'
    );
    $stmt->bind_param('is', $ownerUserId, $publicId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return null;
    }
    return [
        'id' => $row['public_id'],
        'name' => $row['name'],
        'students' => plc_decode_json_array($row['students_json']),
        'createdByName' => $row['created_by_name'],
        'createdAt' => $row['created_at'],
        'updatedByName' => $row['updated_by_name'],
        'updatedAt' => $row['updated_at'],
    ];
}

function plc_fetch_saved(mysqli $db, int $ownerUserId): array
{
    $stmt = $db->prepare(
        'SELECT p.public_id, p.name, p.room_name, p.class_name, p.room_public_id, p.class_public_id,
                p.pairs_json, p.saved_at, p.created_at, p.updated_at,
                cu.full_name AS created_by_name, uu.full_name AS updated_by_name
         FROM plc_placements p
         JOIN plc_users cu ON cu.id = p.created_by_user_id
         LEFT JOIN plc_users uu ON uu.id = p.updated_by_user_id
         WHERE p.owner_user_id = ?
         ORDER BY COALESCE(p.saved_at, p.created_at) DESC'
    );
    $stmt->bind_param('i', $ownerUserId);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $savedAt = $row['saved_at'] ?: $row['created_at'];
        $out[] = [
            'id' => $row['public_id'],
            'name' => $row['name'],
            'roomName' => $row['room_name'],
            'className' => $row['class_name'],
            'roomId' => $row['room_public_id'],
            'classId' => $row['class_public_id'],
            'pairs' => plc_decode_json_array($row['pairs_json']),
            'savedAt' => $savedAt,
            'createdByName' => $row['created_by_name'],
            'createdAt' => $row['created_at'],
            'updatedByName' => $row['updated_by_name'],
            'updatedAt' => $row['updated_at'],
        ];
    }
    return $out;
}

function plc_fetch_saved_by_public_id(mysqli $db, int $ownerUserId, string $publicId): ?array
{
    $stmt = $db->prepare(
        'SELECT p.public_id, p.name, p.room_name, p.class_name, p.room_public_id, p.class_public_id,
                p.pairs_json, p.saved_at, p.created_at, p.updated_at,
                cu.full_name AS created_by_name, uu.full_name AS updated_by_name
         FROM plc_placements p
         JOIN plc_users cu ON cu.id = p.created_by_user_id
         LEFT JOIN plc_users uu ON uu.id = p.updated_by_user_id
         WHERE p.owner_user_id = ? AND p.public_id = ?
         LIMIT 1'
    );
    $stmt->bind_param('is', $ownerUserId, $publicId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return null;
    }
    $savedAt = $row['saved_at'] ?: $row['created_at'];
    return [
        'id' => $row['public_id'],
        'name' => $row['name'],
        'roomName' => $row['room_name'],
        'className' => $row['class_name'],
        'roomId' => $row['room_public_id'],
        'classId' => $row['class_public_id'],
        'pairs' => plc_decode_json_array($row['pairs_json']),
        'savedAt' => $savedAt,
        'createdByName' => $row['created_by_name'],
        'createdAt' => $row['created_at'],
        'updatedByName' => $row['updated_by_name'],
        'updatedAt' => $row['updated_at'],
    ];
}

function plc_upsert_room(mysqli $db, int $ownerUserId, int $actorUserId, array $item): ?array
{
    $name = trim((string)($item['name'] ?? ''));
    if ($name === '') {
        return null;
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

    $stmt = $db->prepare('SELECT id FROM plc_rooms WHERE owner_user_id = ? AND public_id = ? LIMIT 1');
    $stmt->bind_param('is', $ownerUserId, $publicId);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();

    if ($exists) {
        $stmt = $db->prepare(
            'UPDATE plc_rooms
             SET name = ?, desks_json = ?, updated_by_user_id = ?, updated_at = NOW()
             WHERE owner_user_id = ? AND public_id = ?'
        );
        $stmt->bind_param('ssiis', $name, $desksJson, $actorUserId, $ownerUserId, $publicId);
        $stmt->execute();
    } else {
        $stmt = $db->prepare(
            'INSERT INTO plc_rooms (public_id, owner_user_id, name, desks_json, created_by_user_id, updated_by_user_id)
             VALUES (?, ?, ?, ?, ?, NULL)'
        );
        $stmt->bind_param('sissi', $publicId, $ownerUserId, $name, $desksJson, $actorUserId);
        $stmt->execute();
    }

    return plc_fetch_room_by_public_id($db, $ownerUserId, $publicId);
}

function plc_delete_room(mysqli $db, int $ownerUserId, string $publicId): string
{
    $stmt = $db->prepare('DELETE FROM plc_rooms WHERE owner_user_id = ? AND public_id = ?');
    $stmt->bind_param('is', $ownerUserId, $publicId);
    $stmt->execute();
    return $publicId;
}

function plc_upsert_class(mysqli $db, int $ownerUserId, int $actorUserId, array $item): ?array
{
    $name = trim((string)($item['name'] ?? ''));
    if ($name === '') {
        return null;
    }
    $publicId = plc_safe_public_id($item['id'] ?? null, 'cls');
    $students = $item['students'] ?? [];
    if (!is_array($students)) {
        $students = [];
    }
    $studentsJson = json_encode($students, JSON_UNESCAPED_UNICODE);
    if ($studentsJson === false) {
        $studentsJson = '[]';
    }

    $stmt = $db->prepare('SELECT id FROM plc_classes WHERE owner_user_id = ? AND public_id = ? LIMIT 1');
    $stmt->bind_param('is', $ownerUserId, $publicId);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();

    if ($exists) {
        $stmt = $db->prepare(
            'UPDATE plc_classes
             SET name = ?, students_json = ?, updated_by_user_id = ?, updated_at = NOW()
             WHERE owner_user_id = ? AND public_id = ?'
        );
        $stmt->bind_param('ssiis', $name, $studentsJson, $actorUserId, $ownerUserId, $publicId);
        $stmt->execute();
    } else {
        $stmt = $db->prepare(
            'INSERT INTO plc_classes (public_id, owner_user_id, name, students_json, created_by_user_id, updated_by_user_id)
             VALUES (?, ?, ?, ?, ?, NULL)'
        );
        $stmt->bind_param('sissi', $publicId, $ownerUserId, $name, $studentsJson, $actorUserId);
        $stmt->execute();
    }

    return plc_fetch_class_by_public_id($db, $ownerUserId, $publicId);
}

function plc_delete_class(mysqli $db, int $ownerUserId, string $publicId): string
{
    $stmt = $db->prepare('DELETE FROM plc_classes WHERE owner_user_id = ? AND public_id = ?');
    $stmt->bind_param('is', $ownerUserId, $publicId);
    $stmt->execute();
    return $publicId;
}

function plc_upsert_saved(mysqli $db, int $ownerUserId, int $actorUserId, array $item): ?array
{
    $name = trim((string)($item['name'] ?? ''));
    if ($name === '') {
        return null;
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

    return plc_fetch_saved_by_public_id($db, $ownerUserId, $publicId);
}

function plc_delete_saved(mysqli $db, int $ownerUserId, string $publicId): string
{
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
        'INSERT INTO plc_rooms (public_id, owner_user_id, name, desks_json, created_by_user_id, updated_by_user_id)
         VALUES (?, ?, ?, ?, ?, NULL)'
    );
    $upd = $db->prepare(
        'UPDATE plc_rooms
         SET name = ?, desks_json = ?, updated_by_user_id = ?, updated_at = NOW()
         WHERE owner_user_id = ? AND public_id = ?'
    );
    $del = $db->prepare('DELETE FROM plc_rooms WHERE owner_user_id = ? AND public_id = ?');

    foreach ($items as $item) {
        if (!is_array($item)) {
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
        $seen[$publicId] = true;

        if (isset($existing[$publicId])) {
            $upd->bind_param('ssiis', $name, $desksJson, $actorUserId, $ownerUserId, $publicId);
            $upd->execute();
        } else {
            $ins->bind_param('sissi', $publicId, $ownerUserId, $name, $desksJson, $actorUserId);
            $ins->execute();
        }
    }

    foreach (array_keys($existing) as $publicId) {
        if (!isset($seen[$publicId])) {
            $del->bind_param('is', $ownerUserId, $publicId);
            $del->execute();
        }
    }

    return plc_fetch_rooms($db, $ownerUserId);
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
        'INSERT INTO plc_classes (public_id, owner_user_id, name, students_json, created_by_user_id, updated_by_user_id)
         VALUES (?, ?, ?, ?, ?, NULL)'
    );
    $upd = $db->prepare(
        'UPDATE plc_classes
         SET name = ?, students_json = ?, updated_by_user_id = ?, updated_at = NOW()
         WHERE owner_user_id = ? AND public_id = ?'
    );
    $del = $db->prepare('DELETE FROM plc_classes WHERE owner_user_id = ? AND public_id = ?');

    foreach ($items as $item) {
        if (!is_array($item)) {
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
        $studentsJson = json_encode($students, JSON_UNESCAPED_UNICODE);
        if ($studentsJson === false) {
            $studentsJson = '[]';
        }
        $seen[$publicId] = true;

        if (isset($existing[$publicId])) {
            $upd->bind_param('ssiis', $name, $studentsJson, $actorUserId, $ownerUserId, $publicId);
            $upd->execute();
        } else {
            $ins->bind_param('sissi', $publicId, $ownerUserId, $name, $studentsJson, $actorUserId);
            $ins->execute();
        }
    }

    foreach (array_keys($existing) as $publicId) {
        if (!isset($seen[$publicId])) {
            $del->bind_param('is', $ownerUserId, $publicId);
            $del->execute();
        }
    }

    return plc_fetch_classes($db, $ownerUserId);
}

function plc_sync_saved(mysqli $db, int $ownerUserId, int $actorUserId, array $items): array
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
        $pairsJson = json_encode($pairs, JSON_UNESCAPED_UNICODE);
        if ($pairsJson === false) {
            $pairsJson = '[]';
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

    return plc_fetch_saved($db, $ownerUserId);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $payload = [
        'ok' => true,
        'user' => [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'fullName' => $user['full_name'],
            'email' => $user['email'],
            'role' => $user['role'],
        ],
        'rooms' => plc_fetch_rooms($db, $ownerUserId),
        'classes' => plc_fetch_classes($db, $ownerUserId),
        'saved' => plc_fetch_saved($db, $ownerUserId),
    ];
    plc_json($payload);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    plc_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

plc_verify_csrf_or_403();
$body = plc_read_json_body();
$key = (string)($body['key'] ?? '');
$action = (string)($body['action'] ?? 'replace');
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
            $items = plc_sync_saved($db, $ownerUserId, $ownerUserId, $value);
        } else {
            plc_json(['ok' => false, 'error' => 'invalid_key'], 400);
        }
    } elseif ($action === 'upsert') {
        if (!is_array($item)) {
            plc_json(['ok' => false, 'error' => 'invalid_item'], 400);
        }
        if ($key === 'rooms') {
            $updatedItem = plc_upsert_room($db, $ownerUserId, $ownerUserId, $item);
        } elseif ($key === 'classes') {
            $updatedItem = plc_upsert_class($db, $ownerUserId, $ownerUserId, $item);
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
