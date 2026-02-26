CREATE DATABASE IF NOT EXISTS citadel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE citadel;

CREATE TABLE IF NOT EXISTS users (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name         VARCHAR(150)         NOT NULL,
  index_no          VARCHAR(50)          UNIQUE,
  email             VARCHAR(191)         NOT NULL UNIQUE,
  password_hash     VARCHAR(255)         NOT NULL,
  role              ENUM('student','lecturer','admin','rep') NOT NULL DEFAULT 'student',
  device_fingerprint VARCHAR(64),
  created_at        TIMESTAMP            DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP            DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sessions (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_code   VARCHAR(20)  NOT NULL,
  course_name   VARCHAR(150),
  lecturer_id   INT UNSIGNED NOT NULL,
  secret_key    VARCHAR(64)  NOT NULL,
  start_time    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  end_time      TIMESTAMP    NULL,
  active_status TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (lecturer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id  INT UNSIGNED NOT NULL,
  student_id  INT UNSIGNED NOT NULL,
  status      ENUM('present','absent','late') NOT NULL DEFAULT 'present',
  selfie_url  VARCHAR(512),
  timestamp   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_session_student (session_id, student_id),
  FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS timetable (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_code VARCHAR(20)  NOT NULL,
  course_name VARCHAR(150) NOT NULL,
  day_of_week ENUM('Monday','Tuesday','Wednesday','Thursday','Friday') NOT NULL,
  start_time  TIME         NOT NULL,
  end_time    TIME         NOT NULL,
  room        VARCHAR(50),
  lecturer_id INT UNSIGNED,
  FOREIGN KEY (lecturer_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_sessions_course    ON sessions(course_code);
CREATE INDEX idx_sessions_lecturer  ON sessions(lecturer_id);
CREATE INDEX idx_attendance_session ON attendance(session_id);
CREATE INDEX idx_attendance_student ON attendance(student_id);
