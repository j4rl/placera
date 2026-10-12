<?php
declare(strict_types=1);

// Uses only connection-local temporary tables; existing school data is untouched.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/crypto.php';
$source = file_get_contents(__DIR__ . '/../api/state.php');
$start = strpos($source, 'function plc_is_entity_editable(');
$end = strpos($source, "\nplc_ensure_entity_visibility_columns(\$db);", $start);
eval(substr($source, $start, $end - $start));
$db = plc_db();
$db->query("CREATE TEMPORARY TABLE plc_users (id INT PRIMARY KEY, school_id INT, status VARCHAR(20), full_name VARCHAR(100))");
$db->query("INSERT INTO plc_users VALUES (1, 10, 'approved', 'Owner'), (2, 10, 'approved', 'Colleague'), (3, 20, 'approved', 'Other school')");
$db->query("CREATE TEMPORARY TABLE plc_rooms (id INT, desks_json LONGTEXT, visibility VARCHAR(10))");
$db->query("CREATE TEMPORARY TABLE plc_classes (id INT, students_json LONGTEXT, visibility VARCHAR(10))");
$db->query("CREATE TEMPORARY TABLE plc_placements (
    id INT AUTO_INCREMENT PRIMARY KEY, public_id VARCHAR(64), owner_user_id INT, name VARCHAR(190),
    room_name VARCHAR(160), class_name VARCHAR(160), room_public_id VARCHAR(64), class_public_id VARCHAR(64),
    pairs_json LONGTEXT, saved_at DATETIME, created_by_user_id INT, updated_by_user_id INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (owner_user_id, public_id))");

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

// Existing placements become private when the visibility column is added.
$db->query("INSERT INTO plc_placements (public_id, owner_user_id, name, pairs_json, created_by_user_id) VALUES ('legacy_test', 1, 'Legacy', '[]', 1)");
plc_ensure_entity_visibility_columns($db);
check(plc_fetch_saved($db, 2, 10, 'teacher') === [], 'Legacy placements must remain private');
$db->query("DELETE FROM plc_placements WHERE public_id = 'legacy_test'");
$entry = ['id' => 'placement_test', 'name' => 'Test', 'roomName' => 'Room', 'className' => 'Class',
    'pairs' => [['student' => 'Test Student', 'desk' => ['x' => 20, 'y' => 60, 'scale' => 0.7]]]];
$saved = plc_upsert_saved($db, 1, 1, 10, $entry);
check($saved['visibility'] === 'private', 'New placements default to private');
check(plc_fetch_saved($db, 2, 10, 'teacher') === [], 'Private placement leaked to colleague');
check(plc_fetch_saved_by_public_id($db, 2, 10, 1, $entry['id']) === null, 'Private direct lookup leaked');
$entry['visibility'] = 'shared';
$saved = plc_upsert_saved($db, 1, 1, 10, $entry);
$shared = plc_fetch_saved($db, 2, 10, 'teacher');
check(count($shared) === 1 && !$shared[0]['editable'], 'Shared placement must be visible and read-only');
check($shared[0]['pairs'][0]['student'] === 'Test Student', 'Shared encrypted names must round-trip');
check($shared[0]['pairs'][0]['desk']['scale'] === 0.7, 'Desk scale must round-trip');
check(plc_fetch_saved($db, 3, 20, 'teacher') === [], 'Shared placement leaked across schools');
check(plc_fetch_saved_by_public_id($db, 3, 20, 1, $entry['id']) === null, 'Direct lookup leaked across schools');
check(plc_fetch_saved_by_public_id($db, 2, 10, 1, $entry['id'])['editable'] === false, 'Shared lookup must be read-only');
foreach (['update', 'delete'] as $operation) {
    $denied = false;
    try {
        if ($operation === 'update') plc_upsert_saved($db, 2, 2, 10, $entry);
        else plc_delete_saved($db, 2, $entry['id']);
    } catch (RuntimeException $e) { $denied = $e->getMessage() === 'forbidden'; }
    check($denied, 'Colleague must not ' . $operation . ' original');
}
unset($entry['visibility']);
check(plc_upsert_saved($db, 1, 1, 10, $entry)['visibility'] === 'shared', 'Omitted visibility must preserve sharing');
plc_sync_saved($db, 1, 1, 10, 'teacher', [$entry]);
check(count(plc_fetch_saved($db, 2, 10, 'teacher')) === 1, 'Bulk update must preserve sharing');
$entry['visibility'] = 'private';
plc_sync_saved($db, 1, 1, 10, 'teacher', [$entry]);
check(plc_fetch_saved($db, 2, 10, 'teacher') === [], 'Revoked placement still visible');
$entry['id'] = 'bulk_shared_test';
$entry['visibility'] = 'shared';
plc_sync_saved($db, 1, 1, 10, 'teacher', [$entry]);
check(count(plc_fetch_saved($db, 2, 10, 'teacher')) === 1, 'Bulk insert must persist sharing');
echo "Placement sharing checks passed: migration, private defaults, sharing, revocation, ownership, school isolation, encrypted names, desk scale and bulk persistence.\n";
