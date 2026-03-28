<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';

function plc_csrf_token(): string
{
    if (empty($_SESSION['plc_csrf'])) {
        $_SESSION['plc_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['plc_csrf'];
}

function plc_verify_csrf_or_403(): void
{
    $sessionToken = $_SESSION['plc_csrf'] ?? '';
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
    if (
        !is_string($sessionToken) ||
        $sessionToken === '' ||
        !is_string($token) ||
        $token === '' ||
        !hash_equals($sessionToken, $token)
    ) {
        plc_json(['ok' => false, 'error' => 'invalid_csrf'], 403);
    }
}

function plc_ensure_multischool_schema(mysqli $db): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $db->query(
        "CREATE TABLE IF NOT EXISTS plc_schools (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            status ENUM('pending','approved','rejected','disabled') NOT NULL DEFAULT 'pending',
            require_2fa TINYINT(1) NOT NULL DEFAULT 0,
            approved_by_user_id INT UNSIGNED NULL,
            approved_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_plc_schools_name (name),
            KEY ix_plc_schools_status (status)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $db->query(
        "CREATE TABLE IF NOT EXISTS plc_user_backup_codes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            code_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            used_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY ux_plc_user_backup_code_hash (user_id, code_hash),
            KEY ix_plc_user_backup_codes_user_used (user_id, used_at),
            CONSTRAINT fk_plc_user_backup_codes_user
              FOREIGN KEY (user_id) REFERENCES plc_users(id)
              ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $db->query(
        "CREATE TABLE IF NOT EXISTS plc_password_resets (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_plc_password_resets_token_hash (token_hash),
            KEY ix_plc_password_resets_user (user_id),
            KEY ix_plc_password_resets_expires (expires_at),
            CONSTRAINT fk_plc_password_resets_user
              FOREIGN KEY (user_id) REFERENCES plc_users(id)
              ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $hasSchoolRequire2fa = false;
    try {
        $res = $db->query("SHOW COLUMNS FROM plc_schools LIKE 'require_2fa'");
        $hasSchoolRequire2fa = $res instanceof mysqli_result && $res->num_rows > 0;
    } catch (Throwable $e) {
        $hasSchoolRequire2fa = false;
    }
    if (!$hasSchoolRequire2fa) {
        $db->query("ALTER TABLE plc_schools ADD COLUMN require_2fa TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    }

    $hasSchoolId = false;
    try {
        $res = $db->query("SHOW COLUMNS FROM plc_users LIKE 'school_id'");
        $hasSchoolId = $res instanceof mysqli_result && $res->num_rows > 0;
    } catch (Throwable $e) {
        $hasSchoolId = false;
    }
    if (!$hasSchoolId) {
        $db->query("ALTER TABLE plc_users ADD COLUMN school_id INT UNSIGNED NULL AFTER id");
    }

    $hasTwofaEnabled = false;
    $hasTwofaSecret = false;
    $hasTwofaEnabledAt = false;
    try {
        $res = $db->query("SHOW COLUMNS FROM plc_users LIKE 'twofa_enabled'");
        $hasTwofaEnabled = $res instanceof mysqli_result && $res->num_rows > 0;
    } catch (Throwable $e) {
        $hasTwofaEnabled = false;
    }
    if (!$hasTwofaEnabled) {
        $db->query("ALTER TABLE plc_users ADD COLUMN twofa_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    }
    try {
        $res = $db->query("SHOW COLUMNS FROM plc_users LIKE 'twofa_secret'");
        $hasTwofaSecret = $res instanceof mysqli_result && $res->num_rows > 0;
    } catch (Throwable $e) {
        $hasTwofaSecret = false;
    }
    if (!$hasTwofaSecret) {
        $db->query("ALTER TABLE plc_users ADD COLUMN twofa_secret VARCHAR(255) NULL AFTER twofa_enabled");
    }
    try {
        $res = $db->query("SHOW COLUMNS FROM plc_users LIKE 'twofa_enabled_at'");
        $hasTwofaEnabledAt = $res instanceof mysqli_result && $res->num_rows > 0;
    } catch (Throwable $e) {
        $hasTwofaEnabledAt = false;
    }
    if (!$hasTwofaEnabledAt) {
        $db->query("ALTER TABLE plc_users ADD COLUMN twofa_enabled_at DATETIME NULL AFTER twofa_secret");
    }

    $db->query(
        "ALTER TABLE plc_users
         MODIFY COLUMN role ENUM('superadmin','school_admin','teacher') NOT NULL DEFAULT 'teacher'"
    );
    $db->query("UPDATE plc_users SET role = 'school_admin' WHERE role = 'admin'");

    try {
        $db->query("ALTER TABLE plc_users ADD INDEX ix_plc_users_school (school_id)");
    } catch (Throwable $e) {
        // Index exists.
    }
    try {
        $db->query(
            "ALTER TABLE plc_users
             ADD CONSTRAINT fk_plc_users_school
             FOREIGN KEY (school_id) REFERENCES plc_schools(id)
             ON DELETE SET NULL"
        );
    } catch (Throwable $e) {
        // FK exists.
    }

    $superadminId = 0;
    $res = $db->query("SELECT id FROM plc_users WHERE role = 'superadmin' ORDER BY id ASC LIMIT 1");
    $row = $res->fetch_assoc();
    if ($row) {
        $superadminId = (int)$row['id'];
    } else {
        $res = $db->query("SELECT id FROM plc_users ORDER BY created_at ASC, id ASC LIMIT 1");
        $first = $res->fetch_assoc();
        if ($first) {
            $superadminId = (int)$first['id'];
            $stmt = $db->prepare(
                "UPDATE plc_users
                 SET role = 'superadmin', status = 'approved', approved_at = COALESCE(approved_at, NOW()), school_id = NULL
                 WHERE id = ?"
            );
            $stmt->bind_param('i', $superadminId);
            $stmt->execute();
        }
    }

    if ($superadminId > 0) {
        $stmt = $db->prepare("UPDATE plc_users SET school_id = NULL WHERE role = 'superadmin'");
        $stmt->execute();
    }

    $needsSchoolBackfill = false;
    $res = $db->query("SELECT id FROM plc_users WHERE role <> 'superadmin' AND school_id IS NULL LIMIT 1");
    if ($res->fetch_assoc()) {
        $needsSchoolBackfill = true;
    }
    if ($needsSchoolBackfill) {
        $schoolId = 0;
        $migrationSchoolName = 'Migrerad skola';
        $stmt = $db->prepare("SELECT id FROM plc_schools WHERE name = ? LIMIT 1");
        $stmt->bind_param('s', $migrationSchoolName);
        $stmt->execute();
        $school = $stmt->get_result()->fetch_assoc();
        if ($school) {
            $schoolId = (int)$school['id'];
        } else {
            $status = 'approved';
            if ($superadminId > 0) {
                $approvedAt = date('Y-m-d H:i:s');
                $stmt = $db->prepare(
                    'INSERT INTO plc_schools (name, status, approved_by_user_id, approved_at)
                     VALUES (?, ?, ?, ?)'
                );
                $stmt->bind_param('ssis', $migrationSchoolName, $status, $superadminId, $approvedAt);
                $stmt->execute();
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO plc_schools (name, status, approved_by_user_id, approved_at)
                     VALUES (?, ?, NULL, NULL)'
                );
                $stmt->bind_param('ss', $migrationSchoolName, $status);
                $stmt->execute();
            }
            $schoolId = (int)$db->insert_id;
        }
        if ($schoolId > 0) {
            $stmt = $db->prepare(
                "UPDATE plc_users
                 SET school_id = ?
                 WHERE role <> 'superadmin' AND school_id IS NULL"
            );
            $stmt->bind_param('i', $schoolId);
            $stmt->execute();
        }
    }

    $done = true;
}

function plc_user_school_id(array $user): int
{
    return (int)($user['school_id'] ?? 0);
}

function plc_is_superadmin(array $user): bool
{
    return (string)($user['role'] ?? '') === 'superadmin';
}

function plc_is_school_admin(array $user): bool
{
    return (string)($user['role'] ?? '') === 'school_admin';
}

function plc_is_school_approved(array $user): bool
{
    $schoolId = (int)($user['school_id'] ?? 0);
    $schoolStatus = (string)($user['school_status'] ?? '');
    return $schoolId > 0 && $schoolStatus === 'approved';
}

function plc_is_school_admin_readonly_mode(array $user): bool
{
    return plc_is_school_admin($user) && !plc_is_school_approved($user);
}

function plc_can_manage_users(array $user): bool
{
    return plc_is_superadmin($user) || plc_is_school_admin($user);
}

function plc_current_user(): ?array
{
    static $cached = null;
    static $loaded = false;
    if ($loaded) {
        return $cached;
    }
    $loaded = true;

    $uid = isset($_SESSION['plc_user_id']) ? (int)$_SESSION['plc_user_id'] : 0;
    if ($uid <= 0) {
        return null;
    }

    $db = plc_db();
    plc_ensure_multischool_schema($db);
    $stmt = $db->prepare(
        'SELECT u.id, u.school_id, u.username, u.full_name, u.email, u.role, u.status,
                u.twofa_enabled, s.name AS school_name, s.status AS school_status, s.require_2fa AS school_require_2fa
         FROM plc_users u
         LEFT JOIN plc_schools s ON s.id = u.school_id
         WHERE u.id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc() ?: null;

    if (!$user || $user['status'] !== 'approved') {
        unset($_SESSION['plc_user_id']);
        return null;
    }
    $isSuperadmin = (string)($user['role'] ?? '') === 'superadmin';
    if (!$isSuperadmin) {
        $isSchoolAdmin = (string)($user['role'] ?? '') === 'school_admin';
        $schoolId = (int)($user['school_id'] ?? 0);
        $schoolStatus = (string)($user['school_status'] ?? '');
        $schoolRequire2fa = (int)($user['school_require_2fa'] ?? 0) === 1;
        $twofaEnabled = (int)($user['twofa_enabled'] ?? 0) === 1;
        $schoolApproved = $schoolId > 0 && $schoolStatus === 'approved';
        if ($schoolId <= 0) {
            unset($_SESSION['plc_user_id']);
            return null;
        }
        if (!$schoolApproved && !$isSchoolAdmin) {
            unset($_SESSION['plc_user_id']);
            return null;
        }
        $enforceSchool2FA = $schoolRequire2fa && !($isSchoolAdmin && !$schoolApproved);
        if ($enforceSchool2FA && !$twofaEnabled) {
            unset($_SESSION['plc_user_id']);
            return null;
        }
    }

    $cached = $user;
    return $cached;
}

function plc_login_user(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['plc_user_id'] = $userId;
}

function plc_logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
}

function plc_require_login(bool $asJson = false): array
{
    $user = plc_current_user();
    if ($user) {
        return $user;
    }
    if ($asJson) {
        plc_json(['ok' => false, 'error' => 'unauthorized'], 401);
    }
    plc_redirect('index.php');
}

function plc_require_admin(bool $asJson = false): array
{
    $user = plc_require_login($asJson);
    if (plc_can_manage_users($user)) {
        return $user;
    }
    if ($asJson) {
        plc_json(['ok' => false, 'error' => 'forbidden'], 403);
    }
    plc_redirect('app.php');
}

function plc_require_superadmin(bool $asJson = false): array
{
    $user = plc_require_login($asJson);
    if (plc_is_superadmin($user)) {
        return $user;
    }
    if ($asJson) {
        plc_json(['ok' => false, 'error' => 'forbidden'], 403);
    }
    plc_redirect('app.php');
}
