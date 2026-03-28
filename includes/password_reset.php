<?php
declare(strict_types=1);

function plc_password_reset_generate_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function plc_password_reset_hash_token(string $token): string
{
    return hash('sha256', $token);
}

function plc_password_reset_create(mysqli $db, int $userId, int $validMinutes = 60): string
{
    $token = plc_password_reset_generate_token();
    $tokenHash = plc_password_reset_hash_token($token);
    $expiresAt = date('Y-m-d H:i:s', time() + (max(5, $validMinutes) * 60));

    $stmt = $db->prepare(
        'UPDATE plc_password_resets
         SET used_at = NOW()
         WHERE user_id = ? AND used_at IS NULL'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $stmt = $db->prepare(
        'INSERT INTO plc_password_resets (user_id, token_hash, expires_at, used_at, created_at)
         VALUES (?, ?, ?, NULL, NOW())'
    );
    $stmt->bind_param('iss', $userId, $tokenHash, $expiresAt);
    $stmt->execute();

    return $token;
}

function plc_password_reset_find_active_by_token(mysqli $db, string $token): ?array
{
    $tokenHash = plc_password_reset_hash_token($token);
    $stmt = $db->prepare(
        'SELECT pr.id, pr.user_id, pr.expires_at, pr.used_at,
                u.username, u.email, u.status, u.role, u.school_id,
                s.status AS school_status
         FROM plc_password_resets pr
         JOIN plc_users u ON u.id = pr.user_id
         LEFT JOIN plc_schools s ON s.id = u.school_id
         WHERE pr.token_hash = ?
         LIMIT 1'
    );
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return null;
    }

    $usedAt = (string)($row['used_at'] ?? '');
    $expiresAt = (string)($row['expires_at'] ?? '');
    if ($usedAt !== '') {
        return null;
    }
    $expiresTs = strtotime($expiresAt);
    if ($expiresTs === false || $expiresTs < time()) {
        return null;
    }
    if ((string)($row['status'] ?? '') !== 'approved') {
        return null;
    }
    $role = (string)($row['role'] ?? '');
    if ($role !== 'superadmin') {
        $schoolId = (int)($row['school_id'] ?? 0);
        $schoolStatus = (string)($row['school_status'] ?? '');
        $isSchoolAdmin = $role === 'school_admin';
        if ($schoolId <= 0) {
            return null;
        }
        if (!$isSchoolAdmin && $schoolStatus !== 'approved') {
            return null;
        }
    }

    return $row;
}

function plc_password_reset_use(mysqli $db, int $resetId, int $userId, string $passwordHash): bool
{
    $db->begin_transaction();
    try {
        $stmt = $db->prepare(
            'UPDATE plc_users
             SET password_hash = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->bind_param('si', $passwordHash, $userId);
        $stmt->execute();
        if ($stmt->affected_rows <= 0) {
            $db->rollback();
            return false;
        }

        $stmt = $db->prepare(
            'UPDATE plc_password_resets
             SET used_at = NOW()
             WHERE id = ? AND used_at IS NULL'
        );
        $stmt->bind_param('i', $resetId);
        $stmt->execute();
        if ($stmt->affected_rows <= 0) {
            $db->rollback();
            return false;
        }

        $stmt = $db->prepare(
            'UPDATE plc_password_resets
             SET used_at = NOW()
             WHERE user_id = ? AND used_at IS NULL'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();

        $db->commit();
        return true;
    } catch (Throwable $e) {
        $db->rollback();
        return false;
    }
}
