-- Placera schema (MySQL/MariaDB)
-- All table names use the plc_ prefix.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS plc_users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(50) NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','teacher') NOT NULL DEFAULT 'teacher',
  status ENUM('pending','approved','rejected','disabled') NOT NULL DEFAULT 'pending',
  approved_by_user_id INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_plc_users_username (username),
  UNIQUE KEY ux_plc_users_email (email),
  KEY ix_plc_users_status (status),
  KEY ix_plc_users_role (role),
  KEY ix_plc_users_approved_by (approved_by_user_id),
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
