-- Placera: enkel schema-migrering till nuvarande struktur.
-- Ingen stored procedure, inga SQL-funktioner, bara CREATE TABLE IF NOT EXISTS,
-- UPDATE/DELETE och ALTER TABLE.
-- Filen är idempotent och avsedd för MariaDB/MySQL i XAMPP.

SET NAMES utf8mb4;
SET @plc_old_fk_checks := @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS plc_schools (
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
  KEY ix_plc_schools_status (status),
  KEY ix_plc_schools_approved_by (approved_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plc_users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  school_id INT UNSIGNED NULL,
  username VARCHAR(50) NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('superadmin','school_admin','teacher') NOT NULL DEFAULT 'teacher',
  status ENUM('pending','approved','rejected','disabled') NOT NULL DEFAULT 'pending',
  twofa_enabled TINYINT(1) NOT NULL DEFAULT 0,
  twofa_secret VARCHAR(255) NULL,
  twofa_enabled_at DATETIME NULL,
  approved_by_user_id INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_plc_users_username (username),
  UNIQUE KEY ux_plc_users_email (email),
  KEY ix_plc_users_school (school_id),
  KEY ix_plc_users_status (status),
  KEY ix_plc_users_role (role),
  KEY ix_plc_users_approved_by (approved_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plc_rooms (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id VARCHAR(64) NOT NULL,
  owner_user_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  desks_json LONGTEXT NOT NULL,
  visibility ENUM('shared','private') NOT NULL DEFAULT 'shared',
  created_by_user_id INT UNSIGNED NOT NULL,
  updated_by_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_plc_rooms_owner_public (owner_user_id, public_id),
  KEY ix_plc_rooms_owner (owner_user_id),
  KEY ix_plc_rooms_created_by (created_by_user_id),
  KEY ix_plc_rooms_updated_by (updated_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plc_classes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id VARCHAR(64) NOT NULL,
  owner_user_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  students_json LONGTEXT NOT NULL,
  visibility ENUM('shared','private') NOT NULL DEFAULT 'shared',
  created_by_user_id INT UNSIGNED NOT NULL,
  updated_by_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_plc_classes_owner_public (owner_user_id, public_id),
  KEY ix_plc_classes_owner (owner_user_id),
  KEY ix_plc_classes_created_by (created_by_user_id),
  KEY ix_plc_classes_updated_by (updated_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plc_placements (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id VARCHAR(64) NOT NULL,
  owner_user_id INT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  room_name VARCHAR(160) NOT NULL,
  class_name VARCHAR(160) NOT NULL,
  room_public_id VARCHAR(64) NULL,
  class_public_id VARCHAR(64) NULL,
  pairs_json LONGTEXT NOT NULL,
  saved_at DATETIME NULL,
  created_by_user_id INT UNSIGNED NOT NULL,
  updated_by_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_plc_placements_owner_public (owner_user_id, public_id),
  KEY ix_plc_placements_owner (owner_user_id),
  KEY ix_plc_placements_created_by (created_by_user_id),
  KEY ix_plc_placements_updated_by (updated_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plc_teacher_placement_selection (
  user_id INT UNSIGNED NOT NULL,
  room_ids_json LONGTEXT NOT NULL,
  class_ids_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plc_user_backup_codes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  used_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_plc_user_backup_code_hash (user_id, code_hash),
  KEY ix_plc_user_backup_codes_user_used (user_id, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plc_password_resets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_plc_password_resets_token_hash (token_hash),
  KEY ix_plc_password_resets_user (user_id),
  KEY ix_plc_password_resets_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE plc_schools ADD COLUMN IF NOT EXISTS require_2fa TINYINT(1) NULL;
ALTER TABLE plc_schools ADD COLUMN IF NOT EXISTS approved_by_user_id INT UNSIGNED NULL;
ALTER TABLE plc_schools ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL;
ALTER TABLE plc_schools ADD COLUMN IF NOT EXISTS created_at DATETIME NULL;
ALTER TABLE plc_schools ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL;

ALTER TABLE plc_users ADD COLUMN IF NOT EXISTS school_id INT UNSIGNED NULL;
ALTER TABLE plc_users ADD COLUMN IF NOT EXISTS full_name VARCHAR(120) NULL;
ALTER TABLE plc_users ADD COLUMN IF NOT EXISTS email VARCHAR(190) NULL;
ALTER TABLE plc_users ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255) NULL;
ALTER TABLE plc_users ADD COLUMN IF NOT EXISTS role VARCHAR(32) NULL;
ALTER TABLE plc_users ADD COLUMN IF NOT EXISTS status VARCHAR(32) NULL;
ALTER TABLE plc_users ADD COLUMN IF NOT EXISTS twofa_enabled TINYINT(1) NULL;
ALTER TABLE plc_users ADD COLUMN IF NOT EXISTS twofa_secret VARCHAR(255) NULL;
ALTER TABLE plc_users ADD COLUMN IF NOT EXISTS twofa_enabled_at DATETIME NULL;
ALTER TABLE plc_users ADD COLUMN IF NOT EXISTS approved_by_user_id INT UNSIGNED NULL;
ALTER TABLE plc_users ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL;
ALTER TABLE plc_users ADD COLUMN IF NOT EXISTS created_at DATETIME NULL;
ALTER TABLE plc_users ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL;

ALTER TABLE plc_rooms ADD COLUMN IF NOT EXISTS public_id VARCHAR(64) NULL;
ALTER TABLE plc_rooms ADD COLUMN IF NOT EXISTS owner_user_id INT UNSIGNED NULL;
ALTER TABLE plc_rooms ADD COLUMN IF NOT EXISTS name VARCHAR(160) NULL;
ALTER TABLE plc_rooms ADD COLUMN IF NOT EXISTS desks_json LONGTEXT NULL;
ALTER TABLE plc_rooms ADD COLUMN IF NOT EXISTS visibility VARCHAR(32) NULL;
ALTER TABLE plc_rooms ADD COLUMN IF NOT EXISTS created_by_user_id INT UNSIGNED NULL;
ALTER TABLE plc_rooms ADD COLUMN IF NOT EXISTS updated_by_user_id INT UNSIGNED NULL;
ALTER TABLE plc_rooms ADD COLUMN IF NOT EXISTS created_at DATETIME NULL;
ALTER TABLE plc_rooms ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL;

ALTER TABLE plc_classes ADD COLUMN IF NOT EXISTS public_id VARCHAR(64) NULL;
ALTER TABLE plc_classes ADD COLUMN IF NOT EXISTS owner_user_id INT UNSIGNED NULL;
ALTER TABLE plc_classes ADD COLUMN IF NOT EXISTS name VARCHAR(160) NULL;
ALTER TABLE plc_classes ADD COLUMN IF NOT EXISTS students_json LONGTEXT NULL;
ALTER TABLE plc_classes ADD COLUMN IF NOT EXISTS visibility VARCHAR(32) NULL;
ALTER TABLE plc_classes ADD COLUMN IF NOT EXISTS created_by_user_id INT UNSIGNED NULL;
ALTER TABLE plc_classes ADD COLUMN IF NOT EXISTS updated_by_user_id INT UNSIGNED NULL;
ALTER TABLE plc_classes ADD COLUMN IF NOT EXISTS created_at DATETIME NULL;
ALTER TABLE plc_classes ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL;

ALTER TABLE plc_placements ADD COLUMN IF NOT EXISTS public_id VARCHAR(64) NULL;
ALTER TABLE plc_placements ADD COLUMN IF NOT EXISTS owner_user_id INT UNSIGNED NULL;
ALTER TABLE plc_placements ADD COLUMN IF NOT EXISTS name VARCHAR(190) NULL;
ALTER TABLE plc_placements ADD COLUMN IF NOT EXISTS room_name VARCHAR(160) NULL;
ALTER TABLE plc_placements ADD COLUMN IF NOT EXISTS class_name VARCHAR(160) NULL;
ALTER TABLE plc_placements ADD COLUMN IF NOT EXISTS room_public_id VARCHAR(64) NULL;
ALTER TABLE plc_placements ADD COLUMN IF NOT EXISTS class_public_id VARCHAR(64) NULL;
ALTER TABLE plc_placements ADD COLUMN IF NOT EXISTS pairs_json LONGTEXT NULL;
ALTER TABLE plc_placements ADD COLUMN IF NOT EXISTS saved_at DATETIME NULL;
ALTER TABLE plc_placements ADD COLUMN IF NOT EXISTS created_by_user_id INT UNSIGNED NULL;
ALTER TABLE plc_placements ADD COLUMN IF NOT EXISTS updated_by_user_id INT UNSIGNED NULL;
ALTER TABLE plc_placements ADD COLUMN IF NOT EXISTS created_at DATETIME NULL;
ALTER TABLE plc_placements ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL;

ALTER TABLE plc_teacher_placement_selection ADD COLUMN IF NOT EXISTS room_ids_json LONGTEXT NULL;
ALTER TABLE plc_teacher_placement_selection ADD COLUMN IF NOT EXISTS class_ids_json LONGTEXT NULL;
ALTER TABLE plc_teacher_placement_selection ADD COLUMN IF NOT EXISTS created_at DATETIME NULL;
ALTER TABLE plc_teacher_placement_selection ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL;

ALTER TABLE plc_user_backup_codes ADD COLUMN IF NOT EXISTS created_at DATETIME NULL;
ALTER TABLE plc_user_backup_codes ADD COLUMN IF NOT EXISTS used_at DATETIME NULL;

ALTER TABLE plc_password_resets ADD COLUMN IF NOT EXISTS expires_at DATETIME NULL;
ALTER TABLE plc_password_resets ADD COLUMN IF NOT EXISTS used_at DATETIME NULL;
ALTER TABLE plc_password_resets ADD COLUMN IF NOT EXISTS created_at DATETIME NULL;

UPDATE plc_schools
SET name = CONCAT('Migrerad skola ', id)
WHERE name IS NULL OR TRIM(name) = '';

UPDATE plc_schools
SET status = 'approved'
WHERE status IS NULL OR status NOT IN ('pending','approved','rejected','disabled');

UPDATE plc_schools
SET require_2fa = CASE WHEN require_2fa IN (1, '1', 'true', 'TRUE', 'yes', 'YES') THEN 1 ELSE 0 END;

UPDATE plc_schools
SET created_at = COALESCE(created_at, NOW()),
    updated_at = COALESCE(updated_at, created_at, NOW()),
    approved_at = CASE WHEN status = 'approved' THEN COALESCE(approved_at, created_at, NOW()) ELSE approved_at END;

UPDATE plc_schools s
JOIN plc_schools prev
  ON LOWER(TRIM(s.name)) = LOWER(TRIM(prev.name))
 AND s.id > prev.id
SET s.name = CONCAT(LEFT(TRIM(s.name), 180), ' #', s.id);

UPDATE plc_users
SET username = CONCAT('user_', id)
WHERE username IS NULL OR TRIM(username) = '';

UPDATE plc_users
SET full_name = CONCAT('Migrerad anvandare ', id)
WHERE full_name IS NULL OR TRIM(full_name) = '';

UPDATE plc_users
SET email = CONCAT('user_', id, '@local.invalid')
WHERE email IS NULL OR TRIM(email) = '';

UPDATE plc_users
SET password_hash = 'migrated-password-hash-missing'
WHERE password_hash IS NULL OR password_hash = '';

UPDATE plc_users
SET role = CASE
             WHEN role = 'admin' THEN 'school_admin'
             WHEN role IN ('superadmin','school_admin','teacher') THEN role
             ELSE 'teacher'
           END;

UPDATE plc_users
SET status = CASE
               WHEN status IN ('pending','approved','rejected','disabled') THEN status
               ELSE 'approved'
             END;

UPDATE plc_users
SET twofa_enabled = CASE WHEN twofa_enabled IN (1, '1', 'true', 'TRUE', 'yes', 'YES') THEN 1 ELSE 0 END,
    created_at = COALESCE(created_at, NOW()),
    updated_at = COALESCE(updated_at, created_at, NOW()),
    approved_at = CASE WHEN status = 'approved' THEN COALESCE(approved_at, created_at, NOW()) ELSE approved_at END;

UPDATE plc_users u
JOIN plc_users prev
  ON LOWER(TRIM(u.username)) = LOWER(TRIM(prev.username))
 AND u.id > prev.id
SET u.username = CONCAT(LEFT(TRIM(u.username), 40), '_', u.id);

UPDATE plc_users u
JOIN plc_users prev
  ON LOWER(TRIM(u.email)) = LOWER(TRIM(prev.email))
 AND u.id > prev.id
SET u.email = CONCAT('user_', u.id, '@local.invalid');

ALTER TABLE plc_schools ADD UNIQUE INDEX IF NOT EXISTS ux_plc_schools_name (name);
ALTER TABLE plc_schools ADD INDEX IF NOT EXISTS ix_plc_schools_status (status);
ALTER TABLE plc_schools ADD INDEX IF NOT EXISTS ix_plc_schools_approved_by (approved_by_user_id);

ALTER TABLE plc_users ADD UNIQUE INDEX IF NOT EXISTS ux_plc_users_username (username);
ALTER TABLE plc_users ADD UNIQUE INDEX IF NOT EXISTS ux_plc_users_email (email);
ALTER TABLE plc_users ADD INDEX IF NOT EXISTS ix_plc_users_school (school_id);
ALTER TABLE plc_users ADD INDEX IF NOT EXISTS ix_plc_users_status (status);
ALTER TABLE plc_users ADD INDEX IF NOT EXISTS ix_plc_users_role (role);
ALTER TABLE plc_users ADD INDEX IF NOT EXISTS ix_plc_users_approved_by (approved_by_user_id);

SET @plc_first_user_id := (SELECT id FROM plc_users ORDER BY created_at ASC, id ASC LIMIT 1);
SET @plc_has_superadmin := (SELECT COUNT(*) FROM plc_users WHERE role = 'superadmin');

UPDATE plc_users
SET role = 'superadmin',
    status = 'approved',
    school_id = NULL,
    approved_at = COALESCE(approved_at, NOW())
WHERE id = @plc_first_user_id
  AND @plc_first_user_id IS NOT NULL
  AND @plc_has_superadmin = 0;

UPDATE plc_users
SET school_id = NULL
WHERE role = 'superadmin';

INSERT IGNORE INTO plc_schools (name, status, require_2fa, approved_at, created_at, updated_at)
VALUES ('Migrerad skola', 'approved', 0, NOW(), NOW(), NOW());

SET @plc_migration_school_id := (
  SELECT id FROM plc_schools WHERE name = 'Migrerad skola' ORDER BY id ASC LIMIT 1
);

UPDATE plc_users u
LEFT JOIN plc_schools s
  ON s.id = u.school_id
SET u.school_id = @plc_migration_school_id
WHERE u.role <> 'superadmin'
  AND (u.school_id IS NULL OR s.id IS NULL);

UPDATE plc_schools s
LEFT JOIN plc_users u
  ON u.id = s.approved_by_user_id
SET s.approved_by_user_id = NULL
WHERE s.approved_by_user_id IS NOT NULL
  AND u.id IS NULL;

UPDATE plc_users u
LEFT JOIN plc_users a
  ON a.id = u.approved_by_user_id
SET u.approved_by_user_id = NULL
WHERE u.approved_by_user_id IS NOT NULL
  AND a.id IS NULL;

SET @plc_default_owner_user_id := COALESCE(
  (SELECT id FROM plc_users WHERE role <> 'superadmin' ORDER BY id ASC LIMIT 1),
  (SELECT id FROM plc_users ORDER BY id ASC LIMIT 1),
  0
);

DELETE FROM plc_placements WHERE @plc_default_owner_user_id = 0;
DELETE FROM plc_classes WHERE @plc_default_owner_user_id = 0;
DELETE FROM plc_rooms WHERE @plc_default_owner_user_id = 0;

UPDATE plc_rooms r
LEFT JOIN plc_users u ON u.id = r.owner_user_id
SET r.owner_user_id = @plc_default_owner_user_id
WHERE @plc_default_owner_user_id > 0
  AND (r.owner_user_id IS NULL OR r.owner_user_id = 0 OR u.id IS NULL);

UPDATE plc_rooms
SET public_id = CONCAT('room_', id)
WHERE public_id IS NULL OR TRIM(public_id) = '';

UPDATE plc_rooms
SET name = CONCAT('Sal ', id)
WHERE name IS NULL OR TRIM(name) = '';

UPDATE plc_rooms
SET desks_json = '[]'
WHERE desks_json IS NULL OR desks_json = '';

UPDATE plc_rooms
SET visibility = CASE WHEN visibility = 'private' THEN 'private' ELSE 'shared' END,
    created_at = COALESCE(created_at, NOW()),
    updated_at = COALESCE(updated_at, created_at, NOW());

UPDATE plc_rooms r
LEFT JOIN plc_users u ON u.id = r.created_by_user_id
SET r.created_by_user_id = r.owner_user_id
WHERE r.created_by_user_id IS NULL OR r.created_by_user_id = 0 OR u.id IS NULL;

UPDATE plc_rooms r
LEFT JOIN plc_users u ON u.id = r.updated_by_user_id
SET r.updated_by_user_id = NULL
WHERE r.updated_by_user_id IS NOT NULL
  AND (r.updated_by_user_id = 0 OR u.id IS NULL);

UPDATE plc_rooms r
JOIN plc_rooms prev
  ON prev.owner_user_id = r.owner_user_id
 AND prev.public_id = r.public_id
 AND r.id > prev.id
SET r.public_id = CONCAT(LEFT(r.public_id, 52), '_', r.id);

UPDATE plc_classes c
LEFT JOIN plc_users u ON u.id = c.owner_user_id
SET c.owner_user_id = @plc_default_owner_user_id
WHERE @plc_default_owner_user_id > 0
  AND (c.owner_user_id IS NULL OR c.owner_user_id = 0 OR u.id IS NULL);

UPDATE plc_classes
SET public_id = CONCAT('cls_', id)
WHERE public_id IS NULL OR TRIM(public_id) = '';

UPDATE plc_classes
SET name = CONCAT('Grupp ', id)
WHERE name IS NULL OR TRIM(name) = '';

UPDATE plc_classes
SET students_json = '[]'
WHERE students_json IS NULL OR students_json = '';

UPDATE plc_classes
SET visibility = CASE WHEN visibility = 'private' THEN 'private' ELSE 'shared' END,
    created_at = COALESCE(created_at, NOW()),
    updated_at = COALESCE(updated_at, created_at, NOW());

UPDATE plc_classes c
LEFT JOIN plc_users u ON u.id = c.created_by_user_id
SET c.created_by_user_id = c.owner_user_id
WHERE c.created_by_user_id IS NULL OR c.created_by_user_id = 0 OR u.id IS NULL;

UPDATE plc_classes c
LEFT JOIN plc_users u ON u.id = c.updated_by_user_id
SET c.updated_by_user_id = NULL
WHERE c.updated_by_user_id IS NOT NULL
  AND (c.updated_by_user_id = 0 OR u.id IS NULL);

UPDATE plc_classes c
JOIN plc_classes prev
  ON prev.owner_user_id = c.owner_user_id
 AND prev.public_id = c.public_id
 AND c.id > prev.id
SET c.public_id = CONCAT(LEFT(c.public_id, 52), '_', c.id);

UPDATE plc_placements p
LEFT JOIN plc_users u ON u.id = p.owner_user_id
SET p.owner_user_id = @plc_default_owner_user_id
WHERE @plc_default_owner_user_id > 0
  AND (p.owner_user_id IS NULL OR p.owner_user_id = 0 OR u.id IS NULL);

UPDATE plc_placements
SET public_id = CONCAT('pl_', id)
WHERE public_id IS NULL OR TRIM(public_id) = '';

UPDATE plc_placements
SET name = CONCAT('Placering ', id)
WHERE name IS NULL OR TRIM(name) = '';

UPDATE plc_placements
SET room_name = 'Okand sal'
WHERE room_name IS NULL OR TRIM(room_name) = '';

UPDATE plc_placements
SET class_name = 'Okand grupp'
WHERE class_name IS NULL OR TRIM(class_name) = '';

UPDATE plc_placements
SET pairs_json = '[]'
WHERE pairs_json IS NULL OR pairs_json = '';

UPDATE plc_placements
SET room_public_id = NULLIF(TRIM(room_public_id), ''),
    class_public_id = NULLIF(TRIM(class_public_id), ''),
    saved_at = COALESCE(saved_at, created_at, NOW()),
    created_at = COALESCE(created_at, NOW()),
    updated_at = COALESCE(updated_at, created_at, NOW());

UPDATE plc_placements p
LEFT JOIN plc_users u ON u.id = p.created_by_user_id
SET p.created_by_user_id = p.owner_user_id
WHERE p.created_by_user_id IS NULL OR p.created_by_user_id = 0 OR u.id IS NULL;

UPDATE plc_placements p
LEFT JOIN plc_users u ON u.id = p.updated_by_user_id
SET p.updated_by_user_id = NULL
WHERE p.updated_by_user_id IS NOT NULL
  AND (p.updated_by_user_id = 0 OR u.id IS NULL);

UPDATE plc_placements p
JOIN plc_placements prev
  ON prev.owner_user_id = p.owner_user_id
 AND prev.public_id = p.public_id
 AND p.id > prev.id
SET p.public_id = CONCAT(LEFT(p.public_id, 52), '_', p.id);

DELETE ts
FROM plc_teacher_placement_selection ts
LEFT JOIN plc_users u ON u.id = ts.user_id
WHERE ts.user_id IS NULL OR u.id IS NULL;

UPDATE plc_teacher_placement_selection
SET room_ids_json = COALESCE(NULLIF(room_ids_json, ''), '[]'),
    class_ids_json = COALESCE(NULLIF(class_ids_json, ''), '[]'),
    created_at = COALESCE(created_at, NOW()),
    updated_at = COALESCE(updated_at, created_at, NOW());

DELETE bc
FROM plc_user_backup_codes bc
LEFT JOIN plc_users u ON u.id = bc.user_id
WHERE bc.user_id IS NULL OR u.id IS NULL;

UPDATE plc_user_backup_codes
SET code_hash = LEFT(COALESCE(NULLIF(code_hash, ''), SHA2(CONCAT('backup-code-', id), 256)), 64),
    created_at = COALESCE(created_at, NOW());

DELETE pr
FROM plc_password_resets pr
LEFT JOIN plc_users u ON u.id = pr.user_id
WHERE pr.user_id IS NULL OR u.id IS NULL;

UPDATE plc_password_resets
SET token_hash = LEFT(COALESCE(NULLIF(token_hash, ''), SHA2(CONCAT('password-reset-', id), 256)), 64),
    expires_at = COALESCE(expires_at, DATE_ADD(NOW(), INTERVAL 1 HOUR)),
    created_at = COALESCE(created_at, NOW());

ALTER TABLE plc_schools
  MODIFY COLUMN IF EXISTS name VARCHAR(190) NOT NULL,
  MODIFY COLUMN IF EXISTS status ENUM('pending','approved','rejected','disabled') NOT NULL DEFAULT 'pending',
  MODIFY COLUMN IF EXISTS require_2fa TINYINT(1) NOT NULL DEFAULT 0,
  MODIFY COLUMN IF EXISTS approved_by_user_id INT UNSIGNED NULL,
  MODIFY COLUMN IF EXISTS approved_at DATETIME NULL,
  MODIFY COLUMN IF EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  MODIFY COLUMN IF EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE plc_users
  MODIFY COLUMN IF EXISTS school_id INT UNSIGNED NULL,
  MODIFY COLUMN IF EXISTS username VARCHAR(50) NOT NULL,
  MODIFY COLUMN IF EXISTS full_name VARCHAR(120) NOT NULL,
  MODIFY COLUMN IF EXISTS email VARCHAR(190) NOT NULL,
  MODIFY COLUMN IF EXISTS password_hash VARCHAR(255) NOT NULL,
  MODIFY COLUMN IF EXISTS role ENUM('superadmin','school_admin','teacher') NOT NULL DEFAULT 'teacher',
  MODIFY COLUMN IF EXISTS status ENUM('pending','approved','rejected','disabled') NOT NULL DEFAULT 'pending',
  MODIFY COLUMN IF EXISTS twofa_enabled TINYINT(1) NOT NULL DEFAULT 0,
  MODIFY COLUMN IF EXISTS twofa_secret VARCHAR(255) NULL,
  MODIFY COLUMN IF EXISTS twofa_enabled_at DATETIME NULL,
  MODIFY COLUMN IF EXISTS approved_by_user_id INT UNSIGNED NULL,
  MODIFY COLUMN IF EXISTS approved_at DATETIME NULL,
  MODIFY COLUMN IF EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  MODIFY COLUMN IF EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE plc_rooms
  MODIFY COLUMN IF EXISTS public_id VARCHAR(64) NOT NULL,
  MODIFY COLUMN IF EXISTS owner_user_id INT UNSIGNED NOT NULL,
  MODIFY COLUMN IF EXISTS name VARCHAR(160) NOT NULL,
  MODIFY COLUMN IF EXISTS desks_json LONGTEXT NOT NULL,
  MODIFY COLUMN IF EXISTS visibility ENUM('shared','private') NOT NULL DEFAULT 'shared',
  MODIFY COLUMN IF EXISTS created_by_user_id INT UNSIGNED NOT NULL,
  MODIFY COLUMN IF EXISTS updated_by_user_id INT UNSIGNED NULL,
  MODIFY COLUMN IF EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  MODIFY COLUMN IF EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE plc_classes
  MODIFY COLUMN IF EXISTS public_id VARCHAR(64) NOT NULL,
  MODIFY COLUMN IF EXISTS owner_user_id INT UNSIGNED NOT NULL,
  MODIFY COLUMN IF EXISTS name VARCHAR(160) NOT NULL,
  MODIFY COLUMN IF EXISTS students_json LONGTEXT NOT NULL,
  MODIFY COLUMN IF EXISTS visibility ENUM('shared','private') NOT NULL DEFAULT 'shared',
  MODIFY COLUMN IF EXISTS created_by_user_id INT UNSIGNED NOT NULL,
  MODIFY COLUMN IF EXISTS updated_by_user_id INT UNSIGNED NULL,
  MODIFY COLUMN IF EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  MODIFY COLUMN IF EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE plc_placements
  MODIFY COLUMN IF EXISTS public_id VARCHAR(64) NOT NULL,
  MODIFY COLUMN IF EXISTS owner_user_id INT UNSIGNED NOT NULL,
  MODIFY COLUMN IF EXISTS name VARCHAR(190) NOT NULL,
  MODIFY COLUMN IF EXISTS room_name VARCHAR(160) NOT NULL,
  MODIFY COLUMN IF EXISTS class_name VARCHAR(160) NOT NULL,
  MODIFY COLUMN IF EXISTS room_public_id VARCHAR(64) NULL,
  MODIFY COLUMN IF EXISTS class_public_id VARCHAR(64) NULL,
  MODIFY COLUMN IF EXISTS pairs_json LONGTEXT NOT NULL,
  MODIFY COLUMN IF EXISTS saved_at DATETIME NULL,
  MODIFY COLUMN IF EXISTS created_by_user_id INT UNSIGNED NOT NULL,
  MODIFY COLUMN IF EXISTS updated_by_user_id INT UNSIGNED NULL,
  MODIFY COLUMN IF EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  MODIFY COLUMN IF EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE plc_teacher_placement_selection
  ADD PRIMARY KEY IF NOT EXISTS (user_id),
  MODIFY COLUMN IF EXISTS user_id INT UNSIGNED NOT NULL,
  MODIFY COLUMN IF EXISTS room_ids_json LONGTEXT NOT NULL,
  MODIFY COLUMN IF EXISTS class_ids_json LONGTEXT NOT NULL,
  MODIFY COLUMN IF EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  MODIFY COLUMN IF EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE plc_user_backup_codes
  MODIFY COLUMN IF EXISTS user_id INT UNSIGNED NOT NULL,
  MODIFY COLUMN IF EXISTS code_hash CHAR(64) NOT NULL,
  MODIFY COLUMN IF EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  MODIFY COLUMN IF EXISTS used_at DATETIME NULL;

ALTER TABLE plc_password_resets
  MODIFY COLUMN IF EXISTS user_id INT UNSIGNED NOT NULL,
  MODIFY COLUMN IF EXISTS token_hash CHAR(64) NOT NULL,
  MODIFY COLUMN IF EXISTS expires_at DATETIME NOT NULL,
  MODIFY COLUMN IF EXISTS used_at DATETIME NULL,
  MODIFY COLUMN IF EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE plc_rooms ADD UNIQUE INDEX IF NOT EXISTS ux_plc_rooms_owner_public (owner_user_id, public_id);
ALTER TABLE plc_rooms ADD INDEX IF NOT EXISTS ix_plc_rooms_owner (owner_user_id);
ALTER TABLE plc_rooms ADD INDEX IF NOT EXISTS ix_plc_rooms_created_by (created_by_user_id);
ALTER TABLE plc_rooms ADD INDEX IF NOT EXISTS ix_plc_rooms_updated_by (updated_by_user_id);

ALTER TABLE plc_classes ADD UNIQUE INDEX IF NOT EXISTS ux_plc_classes_owner_public (owner_user_id, public_id);
ALTER TABLE plc_classes ADD INDEX IF NOT EXISTS ix_plc_classes_owner (owner_user_id);
ALTER TABLE plc_classes ADD INDEX IF NOT EXISTS ix_plc_classes_created_by (created_by_user_id);
ALTER TABLE plc_classes ADD INDEX IF NOT EXISTS ix_plc_classes_updated_by (updated_by_user_id);

ALTER TABLE plc_placements ADD UNIQUE INDEX IF NOT EXISTS ux_plc_placements_owner_public (owner_user_id, public_id);
ALTER TABLE plc_placements ADD INDEX IF NOT EXISTS ix_plc_placements_owner (owner_user_id);
ALTER TABLE plc_placements ADD INDEX IF NOT EXISTS ix_plc_placements_created_by (created_by_user_id);
ALTER TABLE plc_placements ADD INDEX IF NOT EXISTS ix_plc_placements_updated_by (updated_by_user_id);

ALTER TABLE plc_teacher_placement_selection ADD INDEX IF NOT EXISTS ix_plc_teacher_selection_user (user_id);
ALTER TABLE plc_user_backup_codes ADD INDEX IF NOT EXISTS ix_plc_user_backup_codes_user_used (user_id, used_at);
ALTER TABLE plc_password_resets ADD INDEX IF NOT EXISTS ix_plc_password_resets_user (user_id);
ALTER TABLE plc_password_resets ADD INDEX IF NOT EXISTS ix_plc_password_resets_expires (expires_at);

ALTER TABLE plc_users DROP FOREIGN KEY IF EXISTS fk_plc_users_school;
ALTER TABLE plc_users DROP FOREIGN KEY IF EXISTS fk_plc_users_approved_by;
ALTER TABLE plc_schools DROP FOREIGN KEY IF EXISTS fk_plc_schools_approved_by;
ALTER TABLE plc_rooms DROP FOREIGN KEY IF EXISTS fk_plc_rooms_owner;
ALTER TABLE plc_rooms DROP FOREIGN KEY IF EXISTS fk_plc_rooms_created_by;
ALTER TABLE plc_rooms DROP FOREIGN KEY IF EXISTS fk_plc_rooms_updated_by;
ALTER TABLE plc_classes DROP FOREIGN KEY IF EXISTS fk_plc_classes_owner;
ALTER TABLE plc_classes DROP FOREIGN KEY IF EXISTS fk_plc_classes_created_by;
ALTER TABLE plc_classes DROP FOREIGN KEY IF EXISTS fk_plc_classes_updated_by;
ALTER TABLE plc_placements DROP FOREIGN KEY IF EXISTS fk_plc_placements_owner;
ALTER TABLE plc_placements DROP FOREIGN KEY IF EXISTS fk_plc_placements_created_by;
ALTER TABLE plc_placements DROP FOREIGN KEY IF EXISTS fk_plc_placements_updated_by;
ALTER TABLE plc_teacher_placement_selection DROP FOREIGN KEY IF EXISTS fk_plc_teacher_selection_user;
ALTER TABLE plc_user_backup_codes DROP FOREIGN KEY IF EXISTS fk_plc_user_backup_codes_user;
ALTER TABLE plc_password_resets DROP FOREIGN KEY IF EXISTS fk_plc_password_resets_user;

ALTER TABLE plc_users ADD FOREIGN KEY IF NOT EXISTS fk_plc_users_school (school_id) REFERENCES plc_schools(id) ON DELETE SET NULL;
ALTER TABLE plc_users ADD FOREIGN KEY IF NOT EXISTS fk_plc_users_approved_by (approved_by_user_id) REFERENCES plc_users(id) ON DELETE SET NULL;
ALTER TABLE plc_schools ADD FOREIGN KEY IF NOT EXISTS fk_plc_schools_approved_by (approved_by_user_id) REFERENCES plc_users(id) ON DELETE SET NULL;
ALTER TABLE plc_rooms ADD FOREIGN KEY IF NOT EXISTS fk_plc_rooms_owner (owner_user_id) REFERENCES plc_users(id) ON DELETE CASCADE;
ALTER TABLE plc_rooms ADD FOREIGN KEY IF NOT EXISTS fk_plc_rooms_created_by (created_by_user_id) REFERENCES plc_users(id) ON DELETE RESTRICT;
ALTER TABLE plc_rooms ADD FOREIGN KEY IF NOT EXISTS fk_plc_rooms_updated_by (updated_by_user_id) REFERENCES plc_users(id) ON DELETE SET NULL;
ALTER TABLE plc_classes ADD FOREIGN KEY IF NOT EXISTS fk_plc_classes_owner (owner_user_id) REFERENCES plc_users(id) ON DELETE CASCADE;
ALTER TABLE plc_classes ADD FOREIGN KEY IF NOT EXISTS fk_plc_classes_created_by (created_by_user_id) REFERENCES plc_users(id) ON DELETE RESTRICT;
ALTER TABLE plc_classes ADD FOREIGN KEY IF NOT EXISTS fk_plc_classes_updated_by (updated_by_user_id) REFERENCES plc_users(id) ON DELETE SET NULL;
ALTER TABLE plc_placements ADD FOREIGN KEY IF NOT EXISTS fk_plc_placements_owner (owner_user_id) REFERENCES plc_users(id) ON DELETE CASCADE;
ALTER TABLE plc_placements ADD FOREIGN KEY IF NOT EXISTS fk_plc_placements_created_by (created_by_user_id) REFERENCES plc_users(id) ON DELETE RESTRICT;
ALTER TABLE plc_placements ADD FOREIGN KEY IF NOT EXISTS fk_plc_placements_updated_by (updated_by_user_id) REFERENCES plc_users(id) ON DELETE SET NULL;
ALTER TABLE plc_teacher_placement_selection ADD FOREIGN KEY IF NOT EXISTS fk_plc_teacher_selection_user (user_id) REFERENCES plc_users(id) ON DELETE CASCADE;
ALTER TABLE plc_user_backup_codes ADD FOREIGN KEY IF NOT EXISTS fk_plc_user_backup_codes_user (user_id) REFERENCES plc_users(id) ON DELETE CASCADE;
ALTER TABLE plc_password_resets ADD FOREIGN KEY IF NOT EXISTS fk_plc_password_resets_user (user_id) REFERENCES plc_users(id) ON DELETE CASCADE;

SET FOREIGN_KEY_CHECKS = @plc_old_fk_checks;
