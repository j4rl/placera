<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const PLC_ENC_PREFIX = 'enc:v1:';

function plc_is_encrypted_text(mixed $value): bool
{
    return is_string($value) && str_starts_with($value, PLC_ENC_PREFIX);
}

function plc_data_encryption_key(): string
{
    static $key = null;
    if (is_string($key)) {
        return $key;
    }

    if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
        throw new RuntimeException('OpenSSL extension is required for data encryption.');
    }

    $raw = trim((string)PLC_DATA_KEY);
    if ($raw === '') {
        throw new RuntimeException('PLC_DATA_KEY is missing. Configure a strong encryption key.');
    }

    $decoded = base64_decode($raw, true);
    $material = ($decoded !== false && strlen($decoded) >= 32) ? $decoded : $raw;
    $key = hash('sha256', $material, true);
    return $key;
}

function plc_encrypt_text(string $plaintext): string
{
    $value = trim($plaintext);
    if ($value === '' || plc_is_encrypted_text($value)) {
        return $value;
    }

    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt(
        $value,
        'aes-256-gcm',
        plc_data_encryption_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        16
    );
    if (!is_string($cipher)) {
        throw new RuntimeException('Failed to encrypt text value.');
    }

    return PLC_ENC_PREFIX . base64_encode($iv . $tag . $cipher);
}

function plc_decrypt_text(mixed $ciphertext): string
{
    if (!is_string($ciphertext)) {
        return '';
    }
    if (!plc_is_encrypted_text($ciphertext)) {
        return trim($ciphertext);
    }

    $encoded = substr($ciphertext, strlen(PLC_ENC_PREFIX));
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 28) {
        return '';
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt(
        $cipher,
        'aes-256-gcm',
        plc_data_encryption_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        ''
    );
    return is_string($plain) ? trim($plain) : '';
}

function plc_encrypt_student_list(array $students): array
{
    $out = [];
    foreach ($students as $student) {
        $name = trim((string)$student);
        if ($name === '') {
            continue;
        }
        $out[] = plc_encrypt_text($name);
    }
    return $out;
}

function plc_decrypt_student_list(array $students): array
{
    $out = [];
    foreach ($students as $student) {
        $name = plc_decrypt_text($student);
        if ($name === '') {
            continue;
        }
        $out[] = $name;
    }
    return $out;
}

function plc_encrypt_pairs(array $pairs): array
{
    $out = [];
    foreach ($pairs as $pair) {
        if (!is_array($pair)) {
            continue;
        }
        $next = $pair;
        if (array_key_exists('student', $next) && $next['student'] !== null) {
            $next['student'] = plc_encrypt_text((string)$next['student']);
        }
        $out[] = $next;
    }
    return $out;
}

function plc_decrypt_pairs(array $pairs): array
{
    $out = [];
    foreach ($pairs as $pair) {
        if (!is_array($pair)) {
            continue;
        }
        $next = $pair;
        if (array_key_exists('student', $next) && $next['student'] !== null) {
            $name = plc_decrypt_text($next['student']);
            $next['student'] = ($name !== '') ? $name : null;
        }
        $out[] = $next;
    }
    return $out;
}
