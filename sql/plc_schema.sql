-- Placera schema (MySQL/MariaDB)
-- All table names use the plc_ prefix.

SET NAMES utf8mb4;

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
  KEY ix_plc_schools_status (status)
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
  KEY ix_plc_users_approved_by (approved_by_user_id),
  CONSTRAINT fk_plc_users_school
    FOREIGN KEY (school_id) REFERENCES plc_schools(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_plc_users_approved_by
    FOREIGN KEY (approved_by_user_id) REFERENCES plc_users(id)
    ON DELETE SET NULL
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
  KEY ix_plc_rooms_updated_by (updated_by_user_id),
  CONSTRAINT fk_plc_rooms_owner
    FOREIGN KEY (owner_user_id) REFERENCES plc_users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_plc_rooms_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES plc_users(id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_plc_rooms_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES plc_users(id)
    ON DELETE SET NULL
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
  KEY ix_plc_classes_updated_by (updated_by_user_id),
  CONSTRAINT fk_plc_classes_owner
    FOREIGN KEY (owner_user_id) REFERENCES plc_users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_plc_classes_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES plc_users(id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_plc_classes_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES plc_users(id)
    ON DELETE SET NULL
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
  KEY ix_plc_placements_updated_by (updated_by_user_id),
  CONSTRAINT fk_plc_placements_owner
    FOREIGN KEY (owner_user_id) REFERENCES plc_users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_plc_placements_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES plc_users(id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_plc_placements_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES plc_users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plc_teacher_placement_selection (
  user_id INT UNSIGNED NOT NULL,
  room_ids_json LONGTEXT NOT NULL,
  class_ids_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_plc_teacher_selection_user
    FOREIGN KEY (user_id) REFERENCES plc_users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plc_user_backup_codes (
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
  KEY ix_plc_password_resets_expires (expires_at),
  CONSTRAINT fk_plc_password_resets_user
    FOREIGN KEY (user_id) REFERENCES plc_users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
