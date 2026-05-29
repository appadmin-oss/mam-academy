-- ============================================================
-- MAM Academy — MySQL / MariaDB production schema
-- ============================================================
-- Apply once before the first deploy. The SQLite migrator in
-- api/lib/db.php does not run when DB_DSN is set in api/.env.
--
-- Usage:
--   mysql -u USER -p DBNAME < db/schema-mysql.sql
--
-- Requires MySQL 5.7+ or MariaDB 10.2+ (JSON column type).
-- ============================================================

SET NAMES utf8mb4;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';

CREATE TABLE IF NOT EXISTS schema_version (
  version INT PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  email        VARCHAR(190) UNIQUE NOT NULL,
  first_name   VARCHAR(120),
  last_name    VARCHAR(120),
  phone        VARCHAR(32),
  sex          VARCHAR(12),
  address      VARCHAR(255),
  lga          VARCHAR(80),
  state        VARCHAR(80),
  country      VARCHAR(80),
  city         VARCHAR(80),
  age_range    VARCHAR(16),
  dob          DATE NULL,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_customers_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS applications (
  id                     INT AUTO_INCREMENT PRIMARY KEY,
  reference              VARCHAR(64) UNIQUE NOT NULL,
  customer_id            INT NULL,
  pathway                VARCHAR(32) NOT NULL,
  mode                   VARCHAR(16),
  program                VARCHAR(40),
  discipline             VARCHAR(32),
  session                VARCHAR(12),
  headshot_url           VARCHAR(500),
  experience             VARCHAR(32),
  goals                  TEXT,
  referrer               VARCHAR(40),
  parent_name            VARCHAR(120),
  parent_phone           VARCHAR(32),
  emergency_contact      VARCHAR(200),
  auditer_optin          TINYINT(1) DEFAULT 0,
  include_registration   TINYINT(1) DEFAULT 1,
  include_certification  TINYINT(1) DEFAULT 0,
  program_track          VARCHAR(8) DEFAULT 'MAM',
  policy_mode            VARCHAR(16),
  policy_agreed          TINYINT(1) DEFAULT 0,
  policy_signed_name     VARCHAR(120),
  policy_signed_at       DATETIME NULL,
  total_usd              DECIMAL(10,2),
  status                 VARCHAR(24) DEFAULT 'submitted',
  recovery_sent_at       DATETIME NULL,
  idempotency_key        VARCHAR(80) NULL,
  source                 VARCHAR(200),
  ip                     VARCHAR(64),
  user_agent             VARCHAR(255),
  created_at             DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_apps_status  (status),
  KEY idx_apps_created (created_at),
  KEY idx_apps_idem    (idempotency_key),
  CONSTRAINT fk_apps_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  application_id      INT NOT NULL,
  reference           VARCHAR(64) UNIQUE NOT NULL,
  idempotency_key     VARCHAR(80),
  provider            VARCHAR(24) NOT NULL,
  status              VARCHAR(24) DEFAULT 'pending',
  amount              DECIMAL(12,2) NOT NULL,
  currency            CHAR(3) NOT NULL,
  provider_reference  VARCHAR(190),
  provider_response   JSON,
  verified_at         DATETIME NULL,
  created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_pay_status (status),
  KEY idx_pay_provider_ref (provider, provider_reference),
  CONSTRAINT fk_pay_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_events (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  provider        VARCHAR(24) NOT NULL,
  event_id        VARCHAR(120),
  event_type      VARCHAR(80),
  payload         JSON,
  signature_valid TINYINT(1) DEFAULT 0,
  status          VARCHAR(24) DEFAULT 'received',
  error           VARCHAR(500),
  received_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY idx_webhook_unique (provider, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS newsletter (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  email       VARCHAR(190) UNIQUE NOT NULL,
  source      VARCHAR(64),
  status      VARCHAR(24) DEFAULT 'subscribed',
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120),
  email       VARCHAR(190),
  phone       VARCHAR(32),
  topic       VARCHAR(80),
  message     TEXT,
  ip          VARCHAR(64),
  status      VARCHAR(24) DEFAULT 'unread',
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  actor       VARCHAR(120),
  action      VARCHAR(64),
  entity      VARCHAR(40),
  entity_id   INT,
  meta        JSON,
  ip          VARCHAR(64),
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_entity (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  bucket    VARCHAR(80) PRIMARY KEY,
  count     INT DEFAULT 0,
  reset_at  INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id                     INT AUTO_INCREMENT PRIMARY KEY,
  email                  VARCHAR(190) UNIQUE NOT NULL,
  password_hash          VARCHAR(255) NOT NULL,
  role                   ENUM('admin', 'instructor', 'student') NOT NULL,
  name                   VARCHAR(120),
  phone                  VARCHAR(32),
  avatar_url             VARCHAR(255),
  status                 VARCHAR(24) DEFAULT 'active',
  customer_id            INT NULL,
  last_login             DATETIME NULL,
  must_change_password   TINYINT(1) DEFAULT 0,
  created_at             DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_users_role (role),
  KEY idx_users_customer (customer_id),
  CONSTRAINT fk_users_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  token_hash  CHAR(64) PRIMARY KEY,
  user_id     INT NOT NULL,
  ip          VARCHAR(64),
  ua          VARCHAR(255),
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  expires_at  DATETIME NOT NULL,
  KEY idx_sessions_user (user_id),
  KEY idx_sessions_expires (expires_at),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS instructor_profiles (
  user_id           INT PRIMARY KEY,
  bio               TEXT,
  specialties       VARCHAR(255),
  photo_url         VARCHAR(255),
  years_experience  INT,
  updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_inst_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classes (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  title          VARCHAR(120) NOT NULL,
  discipline     VARCHAR(32),
  pathway        VARCHAR(32),
  instructor_id  INT NULL,
  mode           VARCHAR(16),
  schedule       VARCHAR(200),
  capacity       INT DEFAULT 12,
  status         VARCHAR(24) DEFAULT 'active',
  starts_on      DATE NULL,
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_classes_instr FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS enrollments (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  student_id      INT NOT NULL,
  class_id        INT NOT NULL,
  application_id  INT NULL,
  status          VARCHAR(24) DEFAULT 'active',
  enrolled_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY idx_enrollments_unique (student_id, class_id),
  CONSTRAINT fk_enr_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_enr_class   FOREIGN KEY (class_id)   REFERENCES classes(id) ON DELETE CASCADE,
  CONSTRAINT fk_enr_app     FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  enrollment_id  INT NOT NULL,
  session_date   DATE NOT NULL,
  status         ENUM('present', 'absent', 'late', 'excused') NOT NULL,
  notes          VARCHAR(500),
  marked_by      INT NULL,
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_attendance_enrollment (enrollment_id),
  CONSTRAINT fk_att_enr   FOREIGN KEY (enrollment_id) REFERENCES enrollments(id) ON DELETE CASCADE,
  CONSTRAINT fk_att_marker FOREIGN KEY (marked_by)    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_resources (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  class_id    INT NOT NULL,
  title       VARCHAR(190) NOT NULL,
  url         VARCHAR(500) NOT NULL,
  type        VARCHAR(32),
  posted_by   INT NULL,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_res_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  CONSTRAINT fk_res_user  FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auditer_results (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  student_id           INT NOT NULL,
  dimensions           JSON,
  recommended_pathway  VARCHAR(32),
  notes                TEXT,
  audited_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
  audited_by           INT NULL,
  CONSTRAINT fk_aud_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_aud_marker  FOREIGN KEY (audited_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  token_hash  CHAR(64) PRIMARY KEY,
  user_id     INT NOT NULL,
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pwreset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_version (version) VALUES (1), (2) ON DUPLICATE KEY UPDATE version = version;

-- ─────────────────────────────────────────────────────────────
-- Migration v3 — KTAMS/MAM track + class-policy agreement.
-- For EXISTING installs (the CREATE TABLE above is skipped once the
-- table exists). Safe on MariaDB (IF NOT EXISTS). On MySQL 8, drop the
-- "IF NOT EXISTS" and run once, or guard via your migration tool.
-- ─────────────────────────────────────────────────────────────
ALTER TABLE applications
  ADD COLUMN IF NOT EXISTS program_track      VARCHAR(8)   DEFAULT 'MAM' AFTER include_certification,
  ADD COLUMN IF NOT EXISTS policy_mode        VARCHAR(16)                AFTER program_track,
  ADD COLUMN IF NOT EXISTS policy_agreed      TINYINT(1)   DEFAULT 0     AFTER policy_mode,
  ADD COLUMN IF NOT EXISTS policy_signed_name VARCHAR(120)               AFTER policy_agreed,
  ADD COLUMN IF NOT EXISTS policy_signed_at   DATETIME     NULL          AFTER policy_signed_name;
INSERT INTO schema_version (version) VALUES (3) ON DUPLICATE KEY UPDATE version = version;

-- ─────────────────────────────────────────────────────────────
-- Migration v4–v7 — additive applicant fields, intake session,
-- abandonment-recovery timestamp, idempotency key.
-- ─────────────────────────────────────────────────────────────
ALTER TABLE customers
  ADD COLUMN IF NOT EXISTS sex     VARCHAR(16)  NULL,
  ADD COLUMN IF NOT EXISTS address VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS lga     VARCHAR(64)  NULL,
  ADD COLUMN IF NOT EXISTS state   VARCHAR(64)  NULL;
ALTER TABLE applications
  ADD COLUMN IF NOT EXISTS program          VARCHAR(64)  NULL,
  ADD COLUMN IF NOT EXISTS headshot_url     VARCHAR(500) NULL,
  ADD COLUMN IF NOT EXISTS session          VARCHAR(16)  NULL,
  ADD COLUMN IF NOT EXISTS recovery_sent_at DATETIME     NULL,
  ADD COLUMN IF NOT EXISTS idempotency_key  VARCHAR(64)  NULL,
  ADD INDEX  IF NOT EXISTS idx_apps_idem (idempotency_key);
INSERT INTO schema_version (version) VALUES (4), (5), (6), (7) ON DUPLICATE KEY UPDATE version = version;

-- ─────────────────────────────────────────────────────────────
-- Migration v8 — Booking-fee flow. The application is now free; a flat
-- $5 booking fee unlocks the Book-a-Session page (registration + ID card
-- + student file + 30-min MAM Auditer assessment). `payments.purpose`
-- tags each charge so booking-fee, future tuition and store orders can
-- safely share the same payments table.
-- ─────────────────────────────────────────────────────────────
ALTER TABLE applications
  ADD COLUMN IF NOT EXISTS booking_fee_paid_at DATETIME NULL;
ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS purpose VARCHAR(24) DEFAULT 'tuition';
INSERT INTO schema_version (version) VALUES (8) ON DUPLICATE KEY UPDATE version = version;
