-- ============================================================
-- CITADEL MULTI-SCHOOL MIGRATION
-- Run on railway database
-- ============================================================

USE railway;

-- 1. Add super_admin role
ALTER TABLE users MODIFY COLUMN role ENUM('student','lecturer','admin','rep','super_admin') NOT NULL DEFAULT 'student';

-- 2. Upgrade institutions table
ALTER TABLE institutions
  ADD COLUMN IF NOT EXISTS slug         VARCHAR(20)  UNIQUE,
  ADD COLUMN IF NOT EXISTS logo_url     VARCHAR(512) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS primary_color VARCHAR(7)  DEFAULT '#c9a84c',
  ADD COLUMN IF NOT EXISTS email        VARCHAR(191) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS phone        VARCHAR(30)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS address      TEXT         DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS plan         ENUM('free','pro','enterprise') NOT NULL DEFAULT 'free',
  ADD COLUMN IF NOT EXISTS created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP;

-- 3. Set KTU slug
UPDATE institutions SET slug='ktu', is_active=1 WHERE id=1;

-- 4. Create super admin user (password: citadel_super)
INSERT IGNORE INTO users (full_name, email, password_hash, role, institution_id)
VALUES ('Super Admin', 'super@citadel.app', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin', 1);

-- 5. Add institution_id to sessions if not scoped
-- (already exists from v2 migration)

SELECT 'Multi-school migration complete.' AS status;
