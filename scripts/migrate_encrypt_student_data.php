<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/crypto.php';

function decode_json_array(string $raw): array
{
    $val = json_decode($raw, true);
    return is_array($val) ? $val : [];
}

function encode_json_array(array $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('JSON encode failed.');
    }
    return $json;
}

$db = plc_db();

$classesRead = $db->query('SELECT id, students_json FROM plc_classes');
$classesUpd = $db->prepare('UPDATE plc_classes SET students_json = ?, updated_at = NOW() WHERE id = ?');
$classesScanned = 0;
$classesUpdated = 0;
while ($row = $classesRead->fetch_assoc()) {
    $classesScanned++;
    $id = (int)$row['id'];
    $current = decode_json_array((string)$row['students_json']);
    $encrypted = plc_encrypt_student_list($current);
    if ($encrypted === $current) {
        continue;
    }
    $json = encode_json_array($encrypted);
    $classesUpd->bind_param('si', $json, $id);
    $classesUpd->execute();
    $classesUpdated++;
}

$placementsRead = $db->query('SELECT id, pairs_json FROM plc_placements');
$placementsUpd = $db->prepare('UPDATE plc_placements SET pairs_json = ?, updated_at = NOW() WHERE id = ?');
$placementsScanned = 0;
$placementsUpdated = 0;
while ($row = $placementsRead->fetch_assoc()) {
    $placementsScanned++;
    $id = (int)$row['id'];
    $current = decode_json_array((string)$row['pairs_json']);
    $encrypted = plc_encrypt_pairs($current);
    if ($encrypted === $current) {
        continue;
    }
    $json = encode_json_array($encrypted);
    $placementsUpd->bind_param('si', $json, $id);
    $placementsUpd->execute();
    $placementsUpdated++;
}

echo "Klar.\n";
echo "Grupper: {$classesUpdated} uppdaterade av {$classesScanned}.\n";
echo "Placeringar: {$placementsUpdated} uppdaterade av {$placementsScanned}.\n";
