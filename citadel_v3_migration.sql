USE citadel;

DROP PROCEDURE IF EXISTS citadel_migrate;

DELIMITER $$
CREATE PROCEDURE citadel_migrate()
BEGIN

  -- institutions table (must exist before users FK)
  IF NOT EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA='citadel' AND TABLE_NAME='institutions') THEN
    CREATE TABLE institutions (
      id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name          VARCHAR(200) NOT NULL,
      short_name    VARCHAR(50),
      slug          VARCHAR(20)  UNIQUE,
      logo_url      VARCHAR(512),
      primary_color VARCHAR(7)   DEFAULT '#c9a84c',
      address       TEXT,
      email         VARCHAR(191),
      phone         VARCHAR(30),
      is_active     TINYINT(1)   NOT NULL DEFAULT 1,
      plan          ENUM('free','pro','enterprise') NOT NULL DEFAULT 'free',
      created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;
    INSERT INTO institutions (id,name,short_name,slug,email)
    VALUES (1,'Kumasi Technical University','KTU','ktu','info@ktu.edu.gh');
  END IF;

  -- institutions: add missing columns if table existed already
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='citadel' AND TABLE_NAME='institutions' AND COLUMN_NAME='slug') THEN
    ALTER TABLE institutions ADD COLUMN slug VARCHAR(20) UNIQUE;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='citadel' AND TABLE_NAME='institutions' AND COLUMN_NAME='primary_color') THEN
    ALTER TABLE institutions ADD COLUMN primary_color VARCHAR(7) DEFAULT '#c9a84c';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='citadel' AND TABLE_NAME='institutions' AND COLUMN_NAME='is_active') THEN
    ALTER TABLE institutions ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='citadel' AND TABLE_NAME='institutions' AND COLUMN_NAME='plan') THEN
    ALTER TABLE institutions ADD COLUMN plan ENUM('free','pro','enterprise') NOT NULL DEFAULT 'free';
  END IF;

  UPDATE institutions SET slug='ktu' WHERE id=1 AND (slug IS NULL OR slug='');

  -- users columns
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='citadel' AND TABLE_NAME='users' AND COLUMN_NAME='is_active') THEN
    ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='citadel' AND TABLE_NAME='users' AND COLUMN_NAME='is_locked') THEN
    ALTER TABLE users ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='citadel' AND TABLE_NAME='users' AND COLUMN_NAME='login_attempts') THEN
    ALTER TABLE users ADD COLUMN login_attempts TINYINT NOT NULL DEFAULT 0;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='citadel' AND TABLE_NAME='users' AND COLUMN_NAME='institution_id') THEN
    ALTER TABLE users ADD COLUMN institution_id INT UNSIGNED DEFAULT 1;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='citadel' AND TABLE_NAME='users' AND COLUMN_NAME='department_id') THEN
    ALTER TABLE users ADD COLUMN department_id INT UNSIGNED DEFAULT NULL;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='citadel' AND TABLE_NAME='users' AND COLUMN_NAME='program_id') THEN
    ALTER TABLE users ADD COLUMN program_id INT UNSIGNED DEFAULT NULL;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='citadel' AND TABLE_NAME='users' AND COLUMN_NAME='level') THEN
    ALTER TABLE users ADD COLUMN level TINYINT UNSIGNED DEFAULT NULL;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='citadel' AND TABLE_NAME='users' AND COLUMN_NAME='phone') THEN
    ALTER TABLE users ADD COLUMN phone VARCHAR(20) DEFAULT NULL;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='citadel' AND TABLE_NAME='users' AND COLUMN_NAME='profile_photo') THEN
    ALTER TABLE users ADD COLUMN profile_photo VARCHAR(512) DEFAULT NULL;
  END IF;

  -- sessions columns
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='citadel' AND TABLE_NAME='sessions' AND COLUMN_NAME='course_id') THEN
    ALTER TABLE sessions ADD COLUMN course_id INT UNSIGNED DEFAULT NULL;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='citadel' AND TABLE_NAME='sessions' AND COLUMN_NAME='semester_id') THEN
    ALTER TABLE sessions ADD COLUMN semester_id INT UNSIGNED DEFAULT NULL;
  END IF;

  -- attendance columns
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='citadel' AND TABLE_NAME='attendance' AND COLUMN_NAME='selfie_url') THEN
    ALTER TABLE attendance ADD COLUMN selfie_url VARCHAR(512) DEFAULT NULL;
  END IF;

END$$
DELIMITER ;

CALL citadel_migrate();
DROP PROCEDURE IF EXISTS citadel_migrate;

-- Patch nulls
UPDATE users SET is_active=1, is_locked=0, login_attempts=0 WHERE is_active IS NULL OR is_locked IS NULL;
UPDATE users SET institution_id=1 WHERE institution_id IS NULL;

-- Fix role enum
ALTER TABLE users MODIFY COLUMN role ENUM('student','lecturer','admin','rep','super_admin') NOT NULL DEFAULT 'student';

-- Audit log
CREATE TABLE IF NOT EXISTS audit_log (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id    INT UNSIGNED NOT NULL,
  action      VARCHAR(100) NOT NULL,
  target_type VARCHAR(50)  DEFAULT NULL,
  target_id   INT UNSIGNED DEFAULT NULL,
  detail      TEXT         DEFAULT NULL,
  ip_address  VARCHAR(45)  DEFAULT NULL,
  created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Announcements
CREATE TABLE IF NOT EXISTS announcements (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title       VARCHAR(200) NOT NULL,
  body        TEXT         NOT NULL,
  author_id   INT UNSIGNED NOT NULL,
  course_id   INT UNSIGNED DEFAULT NULL,
  semester_id INT UNSIGNED DEFAULT NULL,
  created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Super admin
INSERT IGNORE INTO users (full_name, email, password_hash, role, institution_id, is_active)
VALUES ('Super Admin','super@citadel.app','\$2y\$12\$LQ8LJrwAFT2Wla5jlnEFouMoEn7vAfZ6XxnIx5iI7wW/XPNz5y5Uy','super_admin',1,1);

SELECT 'Citadel v3 migration complete.' AS status;

-- Face profile and AI attendance columns
SET @db = DATABASE();

SET @q = (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='face_profile'),
  'SELECT 1', 'ALTER TABLE users ADD COLUMN face_profile LONGTEXT DEFAULT NULL'
)); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='face_enrolled_at'),
  'SELECT 1', 'ALTER TABLE users ADD COLUMN face_enrolled_at TIMESTAMP NULL DEFAULT NULL'
)); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='attendance' AND COLUMN_NAME='ai_confidence'),
  'SELECT 1', 'ALTER TABLE attendance ADD COLUMN ai_confidence DECIMAL(5,2) DEFAULT NULL'
)); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='attendance' AND COLUMN_NAME='ai_auto_approved'),
  'SELECT 1', 'ALTER TABLE attendance ADD COLUMN ai_auto_approved TINYINT(1) DEFAULT 0'
)); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='attendance' AND COLUMN_NAME='face_match_score'),
  'SELECT 1', 'ALTER TABLE attendance ADD COLUMN face_match_score DECIMAL(5,2) DEFAULT NULL'
)); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='sessions' AND COLUMN_NAME='is_online'),
  'SELECT 1', 'ALTER TABLE sessions ADD COLUMN is_online TINYINT(1) NOT NULL DEFAULT 0'
)); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='sessions' AND COLUMN_NAME='meeting_link'),
  'SELECT 1', 'ALTER TABLE sessions ADD COLUMN meeting_link VARCHAR(512) DEFAULT NULL'
)); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS ca_scores (
  id             int unsigned NOT NULL AUTO_INCREMENT,
  course_id      int unsigned NOT NULL,
  student_id     int unsigned NOT NULL,
  lecturer_id    int unsigned NOT NULL,
  institution_id int unsigned NOT NULL DEFAULT 1,
  ca_type        varchar(50) NOT NULL DEFAULT 'CA1',
  score          decimal(5,2) NOT NULL DEFAULT 0,
  max_score      decimal(5,2) NOT NULL DEFAULT 100,
  semester_id    int unsigned DEFAULT NULL,
  remarks        varchar(255) DEFAULT NULL,
  uploaded_at    timestamp DEFAULT CURRENT_TIMESTAMP,
  updated_at     timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY unique_ca (course_id, student_id, ca_type, semester_id),
  KEY idx_student (student_id),
  KEY idx_course (course_id),
  KEY idx_lecturer (lecturer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
