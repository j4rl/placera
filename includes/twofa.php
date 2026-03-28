<?php
declare(strict_types=1);

const PLC_2FA_PERIOD = 30;
const PLC_2FA_DIGITS = 6;
const PLC_2FA_BACKUP_CODE_COUNT = 10;

function plc_twofa_base32_encode(string $raw): string
{
    if ($raw === '') {
        return '';
    }
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    $len = strlen($raw);
    for ($i = 0; $i < $len; $i++) {
        $bits .= str_pad(decbin(ord($raw[$i])), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    $chunks = str_split($bits, 5);
    foreach ($chunks as $chunk) {
        if (strlen($chunk) < 5) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        }
        $out .= $alphabet[bindec($chunk)];
    }
    return $out;
}

function plc_twofa_base32_decode(string $encoded): string
{
    $clean = strtoupper(preg_replace('/[^A-Z2-7]/', '', $encoded) ?? '');
    if ($clean === '') {
        return '';
    }
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $map = [];
    for ($i = 0; $i < strlen($alphabet); $i++) {
        $map[$alphabet[$i]] = $i;
    }

    $bits = '';
    $len = strlen($clean);
    for ($i = 0; $i < $len; $i++) {
        $ch = $clean[$i];
        if (!isset($map[$ch])) {
            return '';
        }
        $bits .= str_pad(decbin($map[$ch]), 5, '0', STR_PAD_LEFT);
    }

    $out = '';
    $octets = str_split($bits, 8);
    foreach ($octets as $octet) {
        if (strlen($octet) !== 8) {
            continue;
        }
        $out .= chr(bindec($octet));
    }
    return $out;
}

function plc_twofa_generate_secret(int $bytes = 20): string
{
    return plc_twofa_base32_encode(random_bytes(max(10, $bytes)));
}

function plc_twofa_normalize_code(string $code): string
{
    return preg_replace('/\D+/', '', $code) ?? '';
}

function plc_twofa_totp_code(string $base32Secret, ?int $timestamp = null): string
{
    $secret = plc_twofa_base32_decode($base32Secret);
    if ($secret === '') {
        return '';
    }
    $ts = $timestamp ?? time();
    $counter = (int)floor($ts / PLC_2FA_PERIOD);
    $binaryCounter = pack('N*', 0) . pack('N*', $counter);
    $hash = hash_hmac('sha1', $binaryCounter, $secret, true);
    $offset = ord(substr($hash, -1)) & 0x0F;
    $chunk = substr($hash, $offset, 4);
    $value = unpack('N', $chunk);
    $codeInt = ($value[1] & 0x7FFFFFFF) % (10 ** PLC_2FA_DIGITS);
    return str_pad((string)$codeInt, PLC_2FA_DIGITS, '0', STR_PAD_LEFT);
}

function plc_twofa_verify_code(string $base32Secret, string $code, int $window = 1): bool
{
    $normalized = plc_twofa_normalize_code($code);
    if (strlen($normalized) !== PLC_2FA_DIGITS) {
        return false;
    }
    $now = time();
    for ($offset = -$window; $offset <= $window; $offset++) {
        $candidate = plc_twofa_totp_code($base32Secret, $now + ($offset * PLC_2FA_PERIOD));
        if ($candidate !== '' && hash_equals($candidate, $normalized)) {
            return true;
        }
    }
    return false;
}

function plc_twofa_otpauth_uri(string $issuer, string $accountLabel, string $secret): string
{
    $iss = trim($issuer) !== '' ? trim($issuer) : 'Placera';
    $label = rawurlencode($iss . ':' . $accountLabel);
    $params = http_build_query([
        'secret' => $secret,
        'issuer' => $iss,
        'algorithm' => 'SHA1',
        'digits' => PLC_2FA_DIGITS,
        'period' => PLC_2FA_PERIOD,
    ]);
    return 'otpauth://totp/' . $label . '?' . $params;
}

function plc_twofa_generate_backup_code(): string
{
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $len = strlen($alphabet);
    $raw = '';
    for ($i = 0; $i < 8; $i++) {
        $raw .= $alphabet[random_int(0, $len - 1)];
    }
    return substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
}

function plc_twofa_normalize_backup_code(string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
}

function plc_twofa_backup_code_hash(string $code): string
{
    return hash('sha256', plc_twofa_normalize_backup_code($code));
}

function plc_twofa_generate_backup_codes(mysqli $db, int $userId, int $count = PLC_2FA_BACKUP_CODE_COUNT): array
{
    $stmt = $db->prepare('DELETE FROM plc_user_backup_codes WHERE user_id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $codes = [];
    $insert = $db->prepare(
        'INSERT INTO plc_user_backup_codes (user_id, code_hash, created_at, used_at)
         VALUES (?, ?, NOW(), NULL)'
    );
    for ($i = 0; $i < max(1, $count); $i++) {
        $code = plc_twofa_generate_backup_code();
        $hash = plc_twofa_backup_code_hash($code);
        $insert->bind_param('is', $userId, $hash);
        $insert->execute();
        $codes[] = $code;
    }
    return $codes;
}

function plc_twofa_backup_code_remaining(mysqli $db, int $userId): int
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS c
         FROM plc_user_backup_codes
         WHERE user_id = ? AND used_at IS NULL'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int)($row['c'] ?? 0);
}

function plc_twofa_consume_backup_code(mysqli $db, int $userId, string $inputCode): bool
{
    $hash = plc_twofa_backup_code_hash($inputCode);
    $stmt = $db->prepare(
        'SELECT id
         FROM plc_user_backup_codes
         WHERE user_id = ? AND code_hash = ? AND used_at IS NULL
         LIMIT 1'
    );
    $stmt->bind_param('is', $userId, $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return false;
    }
    $id = (int)$row['id'];
    $stmt = $db->prepare(
        'UPDATE plc_user_backup_codes
         SET used_at = NOW()
         WHERE id = ? AND used_at IS NULL'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    return $stmt->affected_rows > 0;
}
