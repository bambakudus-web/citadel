-- ============================================================
-- CITADEL v2 MIGRATION
-- Run this ONCE on your existing database.
-- Safe: additive only — existing data is preserved.
-- ============================================================

USE railway;

-- ============================================================
-- 1. INSTITUTIONS
-- Allows Citadel to serve multiple schools/orgs
-- ============================================================
CREATE TABLE IF NOT EXISTS institutions (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(200)  NOT NULL,
  short_name   VARCHAR(50),
  logo_url     VARCHAR(512),
  address      TEXT,
  email        VARCHAR(191),
  phone        VARCHAR(30),
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Seed: insert KTU as the default institution
INSERT IGNORE INTO institutions (id, name, short_name, email)
VALUES (1, 'Kumasi Technical University', 'KTU', 'info@ktu.edu.gh');


-- ============================================================
-- 2. DEPARTMENTS
-- ============================================================
CREATE TABLE IF NOT EXISTS departments (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id INT UNSIGNED NOT NULL,
  name           VARCHAR(200) NOT NULL,
  code           VARCHAR(20),
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Seed: Computer Technology dept
INSERT IGNORE INTO departments (id, institution_id, name, code)
VALUES (1, 1, 'Computer Technology', 'CT');


-- ============================================================
-- 3. PROGRAMS
-- e.g. HND Computer Technology, BSc IT, etc.
-- ============================================================
CREATE TABLE IF NOT EXISTS programs (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  department_id INT UNSIGNED NOT NULL,
  name          VARCHAR(200) NOT NULL,
  code          VARCHAR(20),
  duration_yrs  TINYINT UNSIGNED DEFAULT 2,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Seed: HND Computer Technology
INSERT IGNORE INTO programs (id, department_id, name, code, duration_yrs)
VALUES (1, 1, 'HND Computer Technology', 'HND-CT', 2);


-- ============================================================
-- 4. SEMESTERS
-- Admin creates a new semester each term instead of manual SQL
-- ============================================================
CREATE TABLE IF NOT EXISTS semesters (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id INT UNSIGNED NOT NULL,
  name          VARCHAR(100) NOT NULL,   -- e.g. "2025/2026 Semester 1"
  academic_year VARCHAR(20)  NOT NULL,   -- e.g. "2025/2026"
  semester_no   TINYINT UNSIGNED NOT NULL, -- 1 or 2
  start_date    DATE NOT NULL,
  end_date      DATE NOT NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 0, -- only one active at a time
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Seed: current semester
INSERT IGNORE INTO semesters (id, institution_id, name, academic_year, semester_no, start_date, end_date, is_active)
VALUES (1, 1, '2024/2025 Semester 2', '2024/2025', 2, '2025-01-13', '2025-05-30', 1);


-- ============================================================
-- 5. COURSES (proper table, replaces hardcoded strings)
-- Each course belongs to a program + semester
-- ============================================================
CREATE TABLE IF NOT EXISTS courses (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  program_id  INT UNSIGNED NOT NULL,
  semester_id INT UNSIGNED NOT NULL,
  code        VARCHAR(20)  NOT NULL,
  name        VARCHAR(200) NOT NULL,
  credit_hrs  TINYINT UNSIGNED DEFAULT 3,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_course_semester (code, semester_id),
  FOREIGN KEY (program_id)  REFERENCES programs(id)  ON DELETE CASCADE,
  FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Seed: migrate existing timetable courses into courses table
INSERT IGNORE INTO courses (program_id, semester_id, code, name) VALUES
(1, 1, 'CSH221', 'Systems Analysis and Design'),
(1, 1, 'CSH201', 'Human-Computer Interaction'),
(1, 1, 'CSH245', 'Probability and Statistics'),
(1, 1, 'CSH237', 'OOP with Java'),
(1, 1, 'CSH261', 'Financial Accounting'),
(1, 1, 'CSH231', 'Database Systems'),
(1, 1, 'CSH251', 'Web Development Technology');


-- ============================================================
-- 6. UPGRADE users TABLE
-- Add institution, department, program, level context
-- ============================================================
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS institution_id INT UNSIGNED DEFAULT 1       AFTER role,
  ADD COLUMN IF NOT EXISTS department_id  INT UNSIGNED DEFAULT NULL    AFTER institution_id,
  ADD COLUMN IF NOT EXISTS program_id     INT UNSIGNED DEFAULT NULL    AFTER department_id,
  ADD COLUMN IF NOT EXISTS level          TINYINT UNSIGNED DEFAULT NULL AFTER program_id,  -- 1, 2, 3...
  ADD COLUMN IF NOT EXISTS phone          VARCHAR(20) DEFAULT NULL     AFTER level,
  ADD COLUMN IF NOT EXISTS is_active      TINYINT(1) NOT NULL DEFAULT 1 AFTER phone,
  ADD COLUMN IF NOT EXISTS profile_photo  VARCHAR(512) DEFAULT NULL    AFTER is_active;

-- Set existing users to KTU
UPDATE users SET institution_id = 1 WHERE institution_id IS NULL;

-- Set students to HND-CT program, level 2 (they are 2nd year)
UPDATE users SET program_id = 1, department_id = 1, level = 2
WHERE role IN ('student', 'rep');

-- Set lecturers to CT department
UPDATE users SET department_id = 1
WHERE role = 'lecturer';

-- Add foreign keys for users (after columns exist)
ALTER TABLE users
  ADD CONSTRAINT fk_users_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_users_department  FOREIGN KEY (department_id)  REFERENCES departments(id)  ON DELETE SET NULL,
  ADD CONSTRAINT fk_users_program     FOREIGN KEY (program_id)     REFERENCES programs(id)     ON DELETE SET NULL;


-- ============================================================
-- 7. COURSE ASSIGNMENTS (lecturer ↔ course per semester)
-- Replaces the hardcoded lecturer_id in timetable
-- ============================================================
CREATE TABLE IF NOT EXISTS course_assignments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_id   INT UNSIGNED NOT NULL,
  lecturer_id INT UNSIGNED NOT NULL,
  semester_id INT UNSIGNED NOT NULL,
  assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_assignment (course_id, lecturer_id, semester_id),
  FOREIGN KEY (course_id)   REFERENCES courses(id)   ON DELETE CASCADE,
  FOREIGN KEY (lecturer_id) REFERENCES users(id)     ON DELETE CASCADE,
  FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Seed: migrate existing lecturer assignments
INSERT IGNORE INTO course_assignments (course_id, lecturer_id, semester_id)
SELECT c.id, u.id, 1
FROM courses c
JOIN timetable t ON t.course_code = c.code
JOIN users u ON u.id = t.lecturer_id
WHERE c.semester_id = 1
GROUP BY c.id, u.id;


-- ============================================================
-- 8. COURSE ENROLLMENTS (student ↔ course per semester)
-- Currently all students take all courses — enroll them all
-- ============================================================
CREATE TABLE IF NOT EXISTS course_enrollments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_id   INT UNSIGNED NOT NULL,
  student_id  INT UNSIGNED NOT NULL,
  semester_id INT UNSIGNED NOT NULL,
  enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  status      ENUM('active','dropped','completed') NOT NULL DEFAULT 'active',
  UNIQUE KEY uq_enrollment (course_id, student_id, semester_id),
  FOREIGN KEY (course_id)   REFERENCES courses(id)   ON DELETE CASCADE,
  FOREIGN KEY (student_id)  REFERENCES users(id)     ON DELETE CASCADE,
  FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Seed: enroll all existing students in all current courses
INSERT IGNORE INTO course_enrollments (course_id, student_id, semester_id)
SELECT c.id, u.id, 1
FROM courses c
CROSS JOIN users u
WHERE u.role IN ('student', 'rep')
  AND c.semester_id = 1;


-- ============================================================
-- 9. CLASS REPS (rep ↔ course, per semester)
-- A rep can represent a group for specific courses
-- ============================================================
CREATE TABLE IF NOT EXISTS class_reps (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rep_id      INT UNSIGNED NOT NULL,
  course_id   INT UNSIGNED NOT NULL,
  semester_id INT UNSIGNED NOT NULL,
  assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rep_course (rep_id, course_id, semester_id),
  FOREIGN KEY (rep_id)      REFERENCES users(id)     ON DELETE CASCADE,
  FOREIGN KEY (course_id)   REFERENCES courses(id)   ON DELETE CASCADE,
  FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Seed: assign Ali Richard as rep for all current courses
INSERT IGNORE INTO class_reps (rep_id, course_id, semester_id)
SELECT u.id, c.id, 1
FROM users u
CROSS JOIN courses c
WHERE u.index_no = '52430540017'
  AND c.semester_id = 1;


-- ============================================================
-- 10. UPGRADE timetable TABLE
-- Add semester_id and course_id (proper FK), keep old columns
-- for backwards compat during transition
-- ============================================================
ALTER TABLE timetable
  ADD COLUMN IF NOT EXISTS semester_id INT UNSIGNED DEFAULT NULL AFTER id,
  ADD COLUMN IF NOT EXISTS course_id   INT UNSIGNED DEFAULT NULL AFTER semester_id;

-- Link existing timetable rows to proper course records
UPDATE timetable t
JOIN courses c ON c.code = t.course_code AND c.semester_id = 1
SET t.course_id = c.id, t.semester_id = 1;

ALTER TABLE timetable
  ADD CONSTRAINT fk_timetable_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_timetable_course   FOREIGN KEY (course_id)   REFERENCES courses(id)   ON DELETE SET NULL;


-- ============================================================
-- 11. UPGRADE sessions TABLE
-- Add course_id FK alongside existing course_code string
-- ============================================================
ALTER TABLE sessions
  ADD COLUMN IF NOT EXISTS course_id   INT UNSIGNED DEFAULT NULL AFTER course_code,
  ADD COLUMN IF NOT EXISTS semester_id INT UNSIGNED DEFAULT NULL AFTER course_id;

-- Link existing sessions to proper course records
UPDATE sessions s
JOIN courses c ON c.code = s.course_code AND c.semester_id = 1
SET s.course_id = c.id, s.semester_id = 1;

ALTER TABLE sessions
  ADD CONSTRAINT fk_sessions_course   FOREIGN KEY (course_id)   REFERENCES courses(id)   ON DELETE SET NULL,
  ADD CONSTRAINT fk_sessions_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE SET NULL;


-- ============================================================
-- 12. ANNOUNCEMENTS (admin/lecturer → students)
-- ============================================================
CREATE TABLE IF NOT EXISTS announcements (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title       VARCHAR(200) NOT NULL,
  body        TEXT         NOT NULL,
  author_id   INT UNSIGNED NOT NULL,
  course_id   INT UNSIGNED DEFAULT NULL,  -- NULL = institution-wide
  semester_id INT UNSIGNED DEFAULT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (author_id)   REFERENCES users(id)     ON DELETE CASCADE,
  FOREIGN KEY (course_id)   REFERENCES courses(id)   ON DELETE SET NULL,
  FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE SET NULL
) ENGINE=InnoDB;


-- ============================================================
-- 13. AUDIT LOG
-- Track all admin actions for accountability
-- ============================================================
CREATE TABLE IF NOT EXISTS audit_log (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id    INT UNSIGNED NOT NULL,
  action      VARCHAR(100) NOT NULL,  -- e.g. 'ADD_STUDENT', 'DELETE_COURSE'
  target_type VARCHAR(50),            -- e.g. 'user', 'course', 'semester'
  target_id   INT UNSIGNED,
  detail      TEXT,                   -- JSON or description
  ip_address  VARCHAR(45),
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- ============================================================
-- INDEXES for performance
-- ============================================================
CREATE INDEX IF NOT EXISTS idx_enrollments_student  ON course_enrollments(student_id);
CREATE INDEX IF NOT EXISTS idx_enrollments_course   ON course_enrollments(course_id);
CREATE INDEX IF NOT EXISTS idx_assignments_lecturer ON course_assignments(lecturer_id);
CREATE INDEX IF NOT EXISTS idx_assignments_course   ON course_assignments(course_id);
CREATE INDEX IF NOT EXISTS idx_courses_semester     ON courses(semester_id);
CREATE INDEX IF NOT EXISTS idx_sessions_semester    ON sessions(semester_id);
CREATE INDEX IF NOT EXISTS idx_audit_actor          ON audit_log(actor_id);
CREATE INDEX IF NOT EXISTS idx_audit_created        ON audit_log(created_at);


-- ============================================================
-- DONE
-- ============================================================
SELECT 'Citadel v2 migration complete.' AS status;
