<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$user = plc_require_login(true);
if (($user['role'] ?? '') !== 'teacher') {
    plc_json(['ok' => false, 'error' => 'forbidden', 'message' => 'Funktionen gäller endast lärare.'], 403);
}

$db = plc_db();
plc_ensure_multischool_schema($db);
$userId = (int)$user['id'];
$userSchoolId = plc_user_school_id($user);
if ($userSchoolId <= 0) {
    plc_json(['ok' => false, 'error' => 'forbidden', 'message' => 'Användaren saknar godkänd skola.'], 403);
}

function plc_ensure_teacher_selection_table(mysqli $db): void
{
    $db->query(
        "CREATE TABLE IF NOT EXISTS plc_teacher_placement_selection (
            user_id INT UNSIGNED NOT NULL,
            room_ids_json LONGTEXT NOT NULL,
            class_ids_json LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id),
            CONSTRAINT fk_plc_teacher_selection_user
              FOREIGN KEY (user_id) REFERENCES plc_users(id)
              ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
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

function plc_normalize_public_id_list(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }
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
        if (count($out) >= 1000) {
            break;
        }
    }
    return $out;
}

function plc_filter_existing_entity_ids(mysqli $db, int $userId, int $schoolId, string $kind, array $ids): array
{
    if (!$ids) {
        return [];
    }

    if ($kind === 'room') {
        $table = 'plc_rooms';
        $alias = 'r';
    } elseif ($kind === 'class') {
        $table = 'plc_classes';
        $alias = 'c';
    } else {
        return [];
    }

    $quoted = [];
    foreach ($ids as $id) {
        $quoted[] = "'" . $db->real_escape_string($id) . "'";
    }
    $inList = implode(',', $quoted);
    $userIdSql = (string)(int)$userId;
    $schoolIdSql = (string)(int)$schoolId;
    $sql = "SELECT {$alias}.public_id AS public_id
            FROM {$table} {$alias}
            JOIN plc_users ou ON ou.id = {$alias}.owner_user_id
            WHERE ou.status = 'approved'
              AND ou.school_id = {$schoolIdSql}
              AND ({$alias}.visibility = 'shared' OR {$alias}.owner_user_id = {$userIdSql})
              AND {$alias}.public_id IN ({$inList})";

    $res = $db->query($sql);
    $exists = [];
    while ($row = $res->fetch_assoc()) {
        $exists[(string)$row['public_id']] = true;
    }

    $out = [];
    foreach ($ids as $id) {
        if (isset($exists[$id])) {
            $out[] = $id;
        }
    }
    return $out;
}

function plc_decode_json_list(?string $raw): array
{
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function plc_fetch_teacher_selection(mysqli $db, int $userId, int $schoolId): array
{
    $stmt = $db->prepare(
        'SELECT room_ids_json, class_ids_json
         FROM plc_teacher_placement_selection
         WHERE user_id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return ['roomIds' => [], 'classIds' => []];
    }

    $roomIds = plc_normalize_public_id_list(plc_decode_json_list($row['room_ids_json']));
    $classIds = plc_normalize_public_id_list(plc_decode_json_list($row['class_ids_json']));
    $roomIds = plc_filter_existing_entity_ids($db, $userId, $schoolId, 'room', $roomIds);
    $classIds = plc_filter_existing_entity_ids($db, $userId, $schoolId, 'class', $classIds);
    return ['roomIds' => $roomIds, 'classIds' => $classIds];
}

plc_ensure_teacher_selection_table($db);
plc_ensure_entity_visibility_columns($db);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $selection = plc_fetch_teacher_selection($db, $userId, $userSchoolId);
    plc_json([
        'ok' => true,
        'roomIds' => $selection['roomIds'],
        'classIds' => $selection['classIds'],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    plc_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

plc_verify_csrf_or_403();
$body = plc_read_json_body();
$roomIds = plc_normalize_public_id_list($body['roomIds'] ?? []);
$classIds = plc_normalize_public_id_list($body['classIds'] ?? []);
$roomIds = plc_filter_existing_entity_ids($db, $userId, $userSchoolId, 'room', $roomIds);
$classIds = plc_filter_existing_entity_ids($db, $userId, $userSchoolId, 'class', $classIds);

$roomIdsJson = json_encode($roomIds, JSON_UNESCAPED_UNICODE);
$classIdsJson = json_encode($classIds, JSON_UNESCAPED_UNICODE);
if ($roomIdsJson === false) {
    $roomIdsJson = '[]';
}
if ($classIdsJson === false) {
    $classIdsJson = '[]';
}

$stmt = $db->prepare(
    'INSERT INTO plc_teacher_placement_selection (user_id, room_ids_json, class_ids_json)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE room_ids_json = VALUES(room_ids_json), class_ids_json = VALUES(class_ids_json), updated_at = NOW()'
);
$stmt->bind_param('iss', $userId, $roomIdsJson, $classIdsJson);
$stmt->execute();

plc_json(['ok' => true, 'roomIds' => $roomIds, 'classIds' => $classIds]);
