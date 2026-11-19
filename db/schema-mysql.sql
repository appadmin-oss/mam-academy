-- ============================================================
-- MAM Academy — MySQL / MariaDB production schema
-- ============================================================
-- Apply once before the first deploy. The SQLite migrator in
-- api/lib/db.php does not run when DB_DSN is set in api/.env.
--
-- Usage:
--   mysql -u USER -p DBNAME < db/schema-mysql.sql
--
-- Requires **MySQL 8.0.16+** or **MariaDB 10.2.1+**.
--
-- Not 5.7, which this header used to claim. MySQL 5.7 PARSES a CREATE TABLE
-- CHECK clause and then SILENTLY IGNORES it — enforcement landed in 8.0.16 —
-- and this file now carries 20-odd CHECK constraints that whole subsystems rely
-- on rather than trusting callers with (the termly report's band and coverage
-- rules, the appeal state machine, the money invariants). On 5.7 every one of
-- them is decoration, the load reports success, and the first sign of trouble is
-- a guardian looking at a report that says 0 of 0 parts. MariaDB has enforced
-- CHECK since 10.2.1.
--
-- mam_db_migrate_mysql() logs an ERROR naming the server version when it detects
-- an engine that ignores CHECK, because a schema whose guarantees are inert is
-- worse than one that failed to load.
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
  level                  VARCHAR(16),
  expression             VARCHAR(16),
  program                VARCHAR(40),
  discipline             VARCHAR(32),
  extra_courses          INT DEFAULT 0,
  courses_label          VARCHAR(400),
  session                VARCHAR(12),
  headshot_url           VARCHAR(500),
  experience             VARCHAR(32),
  goals                  TEXT,
  referrer               VARCHAR(40),
  readiness_signal       VARCHAR(16),
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
  -- VARCHAR, not ENUM. The application writes 'parent' for every guardian, and
  -- an ENUM of three values made that a 1265 "Data truncated" under the
  -- STRICT_ALL_TABLES sql_mode mam_db() sets — so a guardian account could not
  -- be created on MySQL at all, while SQLite's TEXT accepted it silently. The
  -- vocabulary lives in code and grows with the product; an ENUM turns every new
  -- role into a schema migration on a live host.
  role                   VARCHAR(20) NOT NULL,
  name                   VARCHAR(120),
  phone                  VARCHAR(32),
  avatar_url             VARCHAR(255),
  status                 VARCHAR(24) DEFAULT 'active',
  customer_id            INT NULL,
  last_login             DATETIME NULL,
  must_change_password   TINYINT(1) DEFAULT 0,
  leaderboard_opt_in     TINYINT(1) DEFAULT 0,
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
  UNIQUE KEY uq_attendance (enrollment_id, session_date),
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
  ADD COLUMN program_track      VARCHAR(8)   DEFAULT 'MAM' AFTER include_certification,
  ADD COLUMN policy_mode        VARCHAR(16)                AFTER program_track,
  ADD COLUMN policy_agreed      TINYINT(1)   DEFAULT 0     AFTER policy_mode,
  ADD COLUMN policy_signed_name VARCHAR(120)               AFTER policy_agreed,
  ADD COLUMN policy_signed_at   DATETIME     NULL          AFTER policy_signed_name;
INSERT INTO schema_version (version) VALUES (3) ON DUPLICATE KEY UPDATE version = version;

-- ─────────────────────────────────────────────────────────────
-- Migration v4–v7 — additive applicant fields, intake session,
-- abandonment-recovery timestamp, idempotency key.
-- ─────────────────────────────────────────────────────────────
ALTER TABLE customers
  ADD COLUMN sex     VARCHAR(16)  NULL,
  ADD COLUMN address VARCHAR(255) NULL,
  ADD COLUMN lga     VARCHAR(64)  NULL,
  ADD COLUMN state   VARCHAR(64)  NULL;
ALTER TABLE applications
  ADD COLUMN program          VARCHAR(64)  NULL,
  ADD COLUMN headshot_url     VARCHAR(500) NULL,
  ADD COLUMN session          VARCHAR(16)  NULL,
  ADD COLUMN recovery_sent_at DATETIME     NULL,
  ADD COLUMN idempotency_key  VARCHAR(64)  NULL,
  ADD INDEX idx_apps_idem (idempotency_key);
INSERT INTO schema_version (version) VALUES (4), (5), (6), (7) ON DUPLICATE KEY UPDATE version = version;

-- ─────────────────────────────────────────────────────────────
-- Migration v8 — Booking-fee flow. The application is now free; a flat
-- $5 booking fee unlocks the Book-a-Session page (registration + ID card
-- + student file + 30-min MAM Auditer assessment). `payments.purpose`
-- tags each charge so booking-fee, future tuition and store orders can
-- safely share the same payments table.
-- ─────────────────────────────────────────────────────────────
ALTER TABLE applications
  ADD COLUMN booking_fee_paid_at DATETIME NULL;
ALTER TABLE payments
  ADD COLUMN purpose VARCHAR(24) DEFAULT 'tuition';
INSERT INTO schema_version (version) VALUES (8) ON DUPLICATE KEY UPDATE version = version;

-- ─────────────────────────────────────────────────────────────
-- Migration v9 — Store / Library. Public catalogue of digital,
-- physical and service items sold alongside the academy. Orders
-- settle via the existing payments table with purpose='store'.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS store_items (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku                 VARCHAR(64)   NOT NULL UNIQUE,
  slug                VARCHAR(140)  NOT NULL UNIQUE,
  name                VARCHAR(200)  NOT NULL,
  description         TEXT,
  goods_type          VARCHAR(16)   NOT NULL DEFAULT 'digital',
  delivery_type       VARCHAR(24)   NOT NULL DEFAULT 'instant',
  availability        VARCHAR(16)   NOT NULL DEFAULT 'in_stock',
  stock               INT           DEFAULT -1,
  -- MySQL rejects a literal DEFAULT on TEXT/BLOB (error 1101) — see the
  -- documented gotcha. Nullable, no default; the store code treats NULL/empty
  -- as '*' (everywhere). SQLite keeps its own DEFAULT in the db.php migration.
  available_locations TEXT,
  price_usd           DECIMAL(10,2) NOT NULL,
  currency            VARCHAR(8)    DEFAULT 'USD',
  images              TEXT,
  category            VARCHAR(80),
  file_url            VARCHAR(500),
  weight_grams        INT,
  tax_rate            DECIMAL(5,4)  DEFAULT 0,
  sort_order          INT           DEFAULT 0,
  active              TINYINT(1)    DEFAULT 1,
  created_at          DATETIME      DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_active   (active),
  INDEX idx_category (category),
  INDEX idx_sort     (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_orders (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference        VARCHAR(64)    NOT NULL UNIQUE,
  customer_id      BIGINT UNSIGNED NULL,
  customer_email   VARCHAR(190),
  customer_name    VARCHAR(200),
  customer_country VARCHAR(8),
  customer_phone   VARCHAR(32),
  shipping_address TEXT,
  items_json       LONGTEXT       NOT NULL,
  subtotal_usd     DECIMAL(10,2)  DEFAULT 0,
  tax_usd          DECIMAL(10,2)  DEFAULT 0,
  shipping_usd     DECIMAL(10,2)  DEFAULT 0,
  total_usd        DECIMAL(10,2)  DEFAULT 0,
  status           VARCHAR(24)    NOT NULL DEFAULT 'pending',
  notes            TEXT,
  delivered_at     DATETIME       NULL,
  created_at       DATETIME       DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status (status),
  INDEX idx_email  (customer_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_version (version) VALUES (9) ON DUPLICATE KEY UPDATE version = version;

-- ─────────────────────────────────────────────────────────────
-- Migration v10 — store-flow fix. Store orders are NOT tied to an
-- application, so a store payment row needs application_id = NULL.
-- The original schema declared application_id NOT NULL with a CASCADE
-- foreign key, which made every store INSERT 500. Drop the FK, relax
-- to NULL, re-add an indexed reference that allows NULL.
-- ─────────────────────────────────────────────────────────────
SET @fk := (SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = 'payments' AND COLUMN_NAME = 'application_id'
              AND CONSTRAINT_NAME <> 'PRIMARY' AND REFERENCED_TABLE_NAME IS NOT NULL
            LIMIT 1);
SET @sql := IF(@fk IS NOT NULL, CONCAT('ALTER TABLE payments DROP FOREIGN KEY ', @fk), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE payments MODIFY application_id INT NULL;
ALTER TABLE payments
  ADD CONSTRAINT fk_pay_app_v10
  FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE SET NULL;

INSERT INTO schema_version (version) VALUES (10) ON DUPLICATE KEY UPDATE version = version;

-- ─────────────────────────────────────────────────────────────
-- Migration v11 — admin-editable settings (key/value). Programme
-- prices, the online surcharge, the registration fee, the store
-- shipping flat-rate and the USD→NGN charge rate move out of code
-- into this table so an admin can change them at /admin/?v=settings.
-- Columns are skey/svalue because `key`/`value` are MySQL reserved
-- words. Seeded with the shipped defaults; ON DUPLICATE KEY keeps an
-- admin's edited value on re-run.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS settings (
  skey        VARCHAR(120) PRIMARY KEY,
  svalue      TEXT,
  updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO settings (skey, svalue) VALUES
  ('price_certified_musician','230'),
  ('price_grand_musician','310'),
  ('price_crash','70'),
  ('price_meta_order','60'),
  ('online_surcharge_pct','20'),
  ('registration_fee_usd','5'),
  ('store_shipping_flat_usd','5'),
  ('usd_to_ngn','1500')
ON DUPLICATE KEY UPDATE skey = skey;

INSERT INTO schema_version (version) VALUES (11) ON DUPLICATE KEY UPDATE version = version;

-- v12: Crash is a flat $70 with no levels (spec/review). Lift the old $40 seed
-- for existing installs; conditional so a deliberately-changed price is kept.
UPDATE settings SET svalue='70' WHERE skey='price_crash' AND svalue='40';
INSERT INTO schema_version (version) VALUES (12) ON DUPLICATE KEY UPDATE version = version;

-- v13: level-based application model (spec §2). IF NOT EXISTS (MariaDB) makes
-- this idempotent; 1060 (duplicate column) is also in the migrator's
-- expected-error list as a belt-and-braces fallback on stock MySQL.
ALTER TABLE applications ADD COLUMN level         VARCHAR(16) NULL;
ALTER TABLE applications ADD COLUMN expression    VARCHAR(16) NULL;
ALTER TABLE applications ADD COLUMN extra_courses INT NOT NULL DEFAULT 0;
ALTER TABLE applications ADD COLUMN courses_label TEXT NULL;
INSERT INTO schema_version (version) VALUES (13) ON DUPLICATE KEY UPDATE version = version;

-- v14: class announcements (instructor → enrolled students) + server-persisted
-- student practice logs (previously localStorage-only). CREATE … IF NOT EXISTS
-- keeps this idempotent across re-runs.
CREATE TABLE IF NOT EXISTS announcements (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  class_id    INT NULL,
  author_id   INT NULL,
  title       VARCHAR(160) NULL,
  body        TEXT NOT NULL,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_announcements_class (class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS practice_logs (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  student_id  INT NOT NULL,
  minutes     INT NOT NULL DEFAULT 0,
  focus       VARCHAR(120) NULL,
  notes       TEXT NULL,
  logged_on   DATE NULL,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_practice_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_version (version) VALUES (14) ON DUPLICATE KEY UPDATE version = version;

-- v15: instructor → student assignments (practice tasks, pieces to prepare,
-- theory worksheets). One row per student; class-wide assignments fan out to a
-- row per enrolled pupil so completion is tracked individually.
CREATE TABLE IF NOT EXISTS assignments (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  student_id    INT NOT NULL,
  author_id     INT NULL,
  class_id      INT NULL,
  title         VARCHAR(160) NOT NULL,
  instructions  TEXT NULL,
  due_on        DATE NULL,
  status        VARCHAR(12) NOT NULL DEFAULT 'open',
  completed_at  DATETIME NULL,
  reference_url  VARCHAR(500) NULL,
  reference_kind VARCHAR(12)  NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_assignments_student (student_id),
  KEY idx_assignments_author (author_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_version (version) VALUES (15) ON DUPLICATE KEY UPDATE version = version;

-- ─────────────────────────────────────────────────────────────
-- Migration v16 — Academic LMS backbone (normalized, code-free).
-- Programs → Levels → Courses → Modules → Enrollments → Instructor
-- Assignments → Assessments → Results → Certificates, plus multi-role
-- users (user_roles). Fully additive; durations are data (programs.weeks /
-- exam_week) so 6-week vs 12-week is admin-configurable without code.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_roles (
  user_id INT NOT NULL,
  role    VARCHAR(20) NOT NULL,   -- see users.role: 'parent' is a real value

  PRIMARY KEY (user_id, role),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO user_roles (user_id, role) SELECT id, role FROM users;

CREATE TABLE IF NOT EXISTS programs (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(32) UNIQUE NOT NULL,
  name          VARCHAR(120) NOT NULL,
  weeks         INT NOT NULL DEFAULT 12,
  revision_week INT NULL,
  exam_week     INT NULL,
  requires_core TINYINT(1) NOT NULL DEFAULT 0,
  certification VARCHAR(32),
  sort_order    INT DEFAULT 0,
  active        TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO programs (code,name,weeks,revision_week,exam_week,requires_core,certification,sort_order) VALUES
  ('ktams','KTAMS Young Musicians',12,11,12,0,'participation',0),
  ('crash','Crash Program',6,NULL,6,0,'participation',1),
  ('standard','Standard Program',12,11,12,0,'completion',2),
  ('certified','Certified Program',12,11,12,1,'internal',3),
  ('grand','Grand Certified Program',12,11,12,1,'internal_diploma',4)
ON DUPLICATE KEY UPDATE code = code;

CREATE TABLE IF NOT EXISTS levels (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(32) UNIQUE NOT NULL,
  name           VARCHAR(80) NOT NULL,
  trinity_grades VARCHAR(40),
  sort_order     INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO levels (code,name,trinity_grades,sort_order) VALUES
  ('precert','Pre-Certification','Grade 1-2',1),
  ('cert','Certificate','Grade 3-4',2),
  ('prediploma','Pre-Diploma','Grade 5-6',3),
  ('diploma','Diploma','Grade 7-8',4)
ON DUPLICATE KEY UPDATE code = code;

CREATE TABLE IF NOT EXISTS courses (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(48) UNIQUE NOT NULL,
  name        VARCHAR(120) NOT NULL,
  kind        VARCHAR(16) NOT NULL DEFAULT 'elective',
  description TEXT,
  sort_order  INT DEFAULT 0,
  active      TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO courses (code,name,kind,sort_order) VALUES
  ('rhythm_pitch','Rhythm & Pitch Intelligence','core',1),
  ('performance_etiquette','Performance Etiquette','core',2),
  ('meta_music','Meta Music','core',3),
  ('theory','Theory of Music','core',4),
  ('piano','Piano','elective',10),
  ('voice','Voice','elective',11),
  ('guitar','Guitar','elective',12),
  ('drums','Drums','elective',13),
  ('saxophone','Saxophone','elective',14),
  ('music_production','Music Production','elective',15),
  ('songwriting','Songwriting','elective',16),
  ('conducting','Conducting','elective',17)
ON DUPLICATE KEY UPDATE code = code;

CREATE TABLE IF NOT EXISTS program_courses (
  program_id INT NOT NULL,
  course_id  INT NOT NULL,
  level_code VARCHAR(32) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (program_id, course_id),
  CONSTRAINT fk_pc_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
  CONSTRAINT fk_pc_course  FOREIGN KEY (course_id)  REFERENCES courses(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO program_courses (program_id, course_id, level_code, sort_order)
  SELECT p.id, c.id, 'precert', c.sort_order
  FROM programs p JOIN courses c ON c.code IN ('drums','guitar','piano')
  WHERE p.code = 'ktams';
INSERT IGNORE INTO program_courses (program_id, course_id, sort_order)
  SELECT p.id, c.id, c.sort_order
  FROM programs p CROSS JOIN courses c
  WHERE p.code IN ('crash','standard','certified','grand') AND c.active = 1;
INSERT INTO schema_version (version) VALUES (41) ON DUPLICATE KEY UPDATE version = version;

CREATE TABLE IF NOT EXISTS modules (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  course_id INT NOT NULL,
  level_id  INT NULL,
  week      INT NOT NULL,
  title     VARCHAR(200) NOT NULL,
  kind      VARCHAR(16) NOT NULL DEFAULT 'module',
  content   TEXT,
  KEY idx_modules_course (course_id, level_id),
  CONSTRAINT fk_modules_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  CONSTRAINT fk_modules_level  FOREIGN KEY (level_id)  REFERENCES levels(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS instructor_assignments (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  instructor_id INT NOT NULL,
  course_id     INT NOT NULL,
  level_id      INT NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY idx_instr_assign (instructor_id, course_id, level_id),
  CONSTRAINT fk_ia_instr  FOREIGN KEY (instructor_id) REFERENCES users(id)   ON DELETE CASCADE,
  CONSTRAINT fk_ia_course FOREIGN KEY (course_id)     REFERENCES courses(id) ON DELETE CASCADE,
  CONSTRAINT fk_ia_level  FOREIGN KEY (level_id)      REFERENCES levels(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS course_enrollments (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  student_id              INT NOT NULL,
  course_id               INT NOT NULL,
  level_id                INT NULL,
  program_id              INT NULL,
  instructor_id           INT NULL,
  requested_instructor_id INT NULL,
  status                  VARCHAR(16) NOT NULL DEFAULT 'pending',
  approved_by             INT NULL,
  approved_at             DATETIME NULL,
  status_changed_at       DATETIME NULL,
  created_at              DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cenroll_student (student_id),
  KEY idx_cenroll_course (course_id),
  KEY idx_cenroll_status (status),
  CONSTRAINT fk_ce_student FOREIGN KEY (student_id) REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_ce_course  FOREIGN KEY (course_id)  REFERENCES courses(id)  ON DELETE CASCADE,
  CONSTRAINT fk_ce_level   FOREIGN KEY (level_id)   REFERENCES levels(id)   ON DELETE SET NULL,
  CONSTRAINT fk_ce_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE SET NULL,
  CONSTRAINT fk_ce_instr   FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS assessment_types (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(32) UNIQUE NOT NULL,
  name       VARCHAR(80) NOT NULL,
  weight     INT NOT NULL DEFAULT 0,
  sort_order INT DEFAULT 0,
  active     TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO assessment_types (code,name,weight,sort_order) VALUES
  ('attendance','Attendance',10,1),
  ('assignment','Assignment',20,2),
  ('practical','Practical',30,3),
  ('continuous','Continuous Assessment',10,4),
  ('final','Final Examination',30,5)
ON DUPLICATE KEY UPDATE code = code;

CREATE TABLE IF NOT EXISTS assessments (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  enrollment_id INT NOT NULL,
  type_id       INT NULL,
  instructor_id INT NULL,
  week          INT NULL,
  score         DECIMAL(6,2) NULL,
  max_score     DECIMAL(6,2) NOT NULL DEFAULT 100,
  notes         TEXT,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_assess_enroll (enrollment_id),
  CONSTRAINT fk_as_enroll FOREIGN KEY (enrollment_id) REFERENCES course_enrollments(id) ON DELETE CASCADE,
  CONSTRAINT fk_as_type   FOREIGN KEY (type_id)       REFERENCES assessment_types(id)   ON DELETE SET NULL,
  CONSTRAINT fk_as_instr  FOREIGN KEY (instructor_id) REFERENCES users(id)              ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS results (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  enrollment_id INT NOT NULL UNIQUE,
  total_score   DECIMAL(6,2) NULL,
  grade         VARCHAR(8),
  outcome       VARCHAR(8),
  finalized_by  INT NULL,
  finalized_at  DATETIME NULL,
  CONSTRAINT fk_res_enroll FOREIGN KEY (enrollment_id) REFERENCES course_enrollments(id) ON DELETE CASCADE,
  -- `fk_result_user`, not `fk_res_user`: that name is already taken by
  -- class_resources.posted_by earlier in this file, and MySQL scopes foreign-key
  -- constraint names to the DATABASE, not the table. The collision made this
  -- CREATE TABLE fail with error 1826 — so a fresh MySQL install had no `results`
  -- table at all, while SQLite (which scopes them per table) was fine.
  CONSTRAINT fk_result_user FOREIGN KEY (finalized_by)  REFERENCES users(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS certificates (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  reference  VARCHAR(64) UNIQUE NOT NULL,
  student_id INT NOT NULL,
  program_id INT NULL,
  level_id   INT NULL,
  kind       VARCHAR(32) NOT NULL,
  title      VARCHAR(200),
  issued_by  INT NULL,
  issued_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_certs_student (student_id),
  CONSTRAINT fk_cert_student FOREIGN KEY (student_id) REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_cert_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE SET NULL,
  CONSTRAINT fk_cert_level   FOREIGN KEY (level_id)   REFERENCES levels(id)   ON DELETE SET NULL,
  CONSTRAINT fk_cert_issuer  FOREIGN KEY (issued_by)  REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- v17 — Outbound email outbox. Every transactional send becomes a durable,
-- queryable row so failures are visible + recoverable (admin "Retry failed" +
-- api/tools/mail-retry.php cron) instead of vanishing into a log file. Body +
-- opts retained only while a row is retryable; cleared on success.
CREATE TABLE IF NOT EXISTS mail_log (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  to_email   VARCHAR(320) NOT NULL,
  subject    VARCHAR(512),
  context    VARCHAR(120),
  is_html    TINYINT NOT NULL DEFAULT 1,
  status     VARCHAR(16) NOT NULL DEFAULT 'queued',
  transport  VARCHAR(32),
  error      TEXT,
  attempts   INT NOT NULL DEFAULT 0,
  body       LONGTEXT,
  opts       TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  sent_at    DATETIME NULL,
  KEY idx_mail_log_status (status),
  KEY idx_mail_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_version (version) VALUES (16) ON DUPLICATE KEY UPDATE version = version;
INSERT INTO schema_version (version) VALUES (17) ON DUPLICATE KEY UPDATE version = version;

-- v18 — Enrolment lifecycle (LMS L1): append-only transition audit + a
-- status_changed_at stamp. Legal moves are enforced in code (mam_lms_transition).
-- The ALTER is for installs already past v16 (fresh installs get the column from
-- the CREATE above). "IF NOT EXISTS" is MariaDB-friendly; on MySQL 8 run once.
ALTER TABLE course_enrollments
  ADD COLUMN status_changed_at DATETIME NULL AFTER approved_at;

CREATE TABLE IF NOT EXISTS enrolment_events (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  enrollment_id INT NOT NULL,
  from_status   VARCHAR(16) NULL,
  to_status     VARCHAR(16) NOT NULL,
  actor_id      INT NULL,
  reason        VARCHAR(255) NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_enrolment_events_enrollment (enrollment_id, id),
  CONSTRAINT fk_ee_enroll FOREIGN KEY (enrollment_id) REFERENCES course_enrollments(id) ON DELETE CASCADE,
  CONSTRAINT fk_ee_actor  FOREIGN KEY (actor_id)      REFERENCES users(id)              ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_version (version) VALUES (18) ON DUPLICATE KEY UPDATE version = version;

-- v19 — Cohorts (LMS L1: unify the two enrolment systems). Additive: a `cohorts`
-- table (the single scheduled-run concept), course_enrollments.cohort_id link,
-- and an idempotent backfill of one cohort per legacy class (keyed by
-- legacy_class_id via INSERT IGNORE). No read-path cutover here. "IF NOT EXISTS"
-- on the ALTERs is MariaDB-friendly; on MySQL 8 run once.
CREATE TABLE IF NOT EXISTS cohorts (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  course_id       INT NULL,
  level_id        INT NULL,
  program_id      INT NULL,
  instructor_id   INT NULL,
  title           VARCHAR(160),
  discipline      VARCHAR(32),
  pathway         VARCHAR(32),
  mode            VARCHAR(16),
  term            VARCHAR(64),
  capacity        INT DEFAULT 12,
  meeting_pattern VARCHAR(200),
  starts_on       DATE NULL,
  ends_on         DATE NULL,
  status          VARCHAR(24) NOT NULL DEFAULT 'active',
  legacy_class_id INT NULL,
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cohorts_legacy (legacy_class_id),
  KEY idx_cohorts_course (course_id, level_id),
  KEY idx_cohorts_instructor (instructor_id),
  CONSTRAINT fk_cohort_course  FOREIGN KEY (course_id)     REFERENCES courses(id)  ON DELETE SET NULL,
  CONSTRAINT fk_cohort_level   FOREIGN KEY (level_id)      REFERENCES levels(id)   ON DELETE SET NULL,
  CONSTRAINT fk_cohort_program FOREIGN KEY (program_id)    REFERENCES programs(id) ON DELETE SET NULL,
  CONSTRAINT fk_cohort_instr   FOREIGN KEY (instructor_id) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE course_enrollments ADD COLUMN cohort_id INT NULL;
ALTER TABLE course_enrollments ADD KEY idx_cenroll_cohort (cohort_id);

INSERT IGNORE INTO cohorts (instructor_id, title, discipline, pathway, mode, capacity, meeting_pattern, starts_on, status, legacy_class_id, created_at)
  SELECT instructor_id, title, discipline, pathway, mode, COALESCE(capacity,12), schedule, starts_on, COALESCE(status,'active'), id, created_at FROM classes;

INSERT INTO schema_version (version) VALUES (19) ON DUPLICATE KEY UPDATE version = version;

-- v20 — AI run audit (provider-agnostic AI gateway). Records who/what/provider
-- for reproducibility + quota; stores a prompt HASH, not the prompt (no PII).
CREATE TABLE IF NOT EXISTS ai_runs (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  use_case      VARCHAR(64) NOT NULL,
  provider      VARCHAR(32),
  model         VARCHAR(64),
  ok            TINYINT NOT NULL DEFAULT 0,
  prompt_hash   VARCHAR(64),
  input_tokens  INT DEFAULT 0,
  output_tokens INT DEFAULT 0,
  error         VARCHAR(255),
  reviewed_by   INT NULL,
  reviewed_at   DATETIME NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ai_runs_usecase (use_case, id),
  CONSTRAINT fk_airuns_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_version (version) VALUES (20) ON DUPLICATE KEY UPDATE version = version;

-- v21 — Class sessions (LMS L2: scheduling on cohorts). Named class_sessions
-- (not sessions) because the auth-session table already owns `sessions`.
CREATE TABLE IF NOT EXISTS class_sessions (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  cohort_id    INT NOT NULL,
  title        VARCHAR(160),
  scheduled_at DATETIME NULL,
  duration_min INT DEFAULT 60,
  kind         VARCHAR(16) NOT NULL DEFAULT 'class',
  location     VARCHAR(255),
  status       VARCHAR(16) NOT NULL DEFAULT 'scheduled',
  notes        TEXT,
  created_by   INT NULL,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_class_sessions_cohort (cohort_id, scheduled_at),
  CONSTRAINT fk_cs_cohort  FOREIGN KEY (cohort_id)  REFERENCES cohorts(id) ON DELETE CASCADE,
  CONSTRAINT fk_cs_creator FOREIGN KEY (created_by) REFERENCES users(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_version (version) VALUES (21) ON DUPLICATE KEY UPDATE version = version;

-- v22 — Session attendance (LMS L2). One record per (session, student),
-- alongside the legacy `attendance` table (dual-read; no cutover).
CREATE TABLE IF NOT EXISTS session_attendance (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  session_id INT NOT NULL,
  student_id INT NOT NULL,
  status     VARCHAR(16) NOT NULL,
  notes      TEXT,
  marked_by  INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sess_att (session_id, student_id),
  KEY idx_sess_att_student (student_id),
  CONSTRAINT fk_sa_session FOREIGN KEY (session_id) REFERENCES class_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_sa_student FOREIGN KEY (student_id) REFERENCES users(id)          ON DELETE CASCADE,
  CONSTRAINT fk_sa_marker  FOREIGN KEY (marked_by)  REFERENCES users(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_version (version) VALUES (22) ON DUPLICATE KEY UPDATE version = version;

-- v23 — Submissions & feedback (LMS L2). Student artifact submissions against
-- assignments + instructor feedback (1:1 with a submission, editable).
CREATE TABLE IF NOT EXISTS submissions (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  assignment_id INT NOT NULL,
  student_id    INT NOT NULL,
  artifact_url  VARCHAR(1024),
  artifact_kind VARCHAR(16) NOT NULL DEFAULT 'link',
  note          TEXT,
  status        VARCHAR(24) NOT NULL DEFAULT 'submitted',
  submitted_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_submissions_assignment (assignment_id, id),
  KEY idx_submissions_student (student_id, id),
  CONSTRAINT fk_sub_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
  CONSTRAINT fk_sub_student    FOREIGN KEY (student_id)    REFERENCES users(id)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS feedback (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  submission_id INT NOT NULL UNIQUE,
  instructor_id INT NULL,
  rubric_json   TEXT,
  comments      TEXT,
  outcome       VARCHAR(24),
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fb_submission FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_fb_instructor FOREIGN KEY (instructor_id) REFERENCES users(id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_version (version) VALUES (23) ON DUPLICATE KEY UPDATE version = version;

-- v24 — Server-side application drafts (Application A1): cross-device resume via
-- a signed magic link, keyed by the client's idempotency key.
CREATE TABLE IF NOT EXISTS application_drafts (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  draft_key  VARCHAR(64) NOT NULL UNIQUE,
  email      VARCHAR(320),
  payload    LONGTEXT,
  status     VARCHAR(16) NOT NULL DEFAULT 'draft',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_app_drafts_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_version (version) VALUES (24) ON DUPLICATE KEY UPDATE version = version;

-- v25 — Automation spine (LMS L3): append-only events + a due-able jobs queue.
CREATE TABLE IF NOT EXISTS events (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  type       VARCHAR(64) NOT NULL,
  entity     VARCHAR(64),
  entity_id  INT,
  payload    TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_events_type (type, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS jobs (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  kind       VARCHAR(64) NOT NULL,
  payload    TEXT,
  run_after  DATETIME NULL,
  attempts   INT NOT NULL DEFAULT 0,
  status     VARCHAR(16) NOT NULL DEFAULT 'pending',
  last_error VARCHAR(255),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_jobs_due (status, run_after)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_version (version) VALUES (25) ON DUPLICATE KEY UPDATE version = version;

-- v26 — Admin access tiers (RBAC): 'super' (full) vs 'manager' (operational).
-- NULL = treated as 'super' in code; existing admins backfilled so none lose access.
ALTER TABLE users ADD COLUMN admin_tier VARCHAR(16) NULL;
UPDATE users SET admin_tier = 'super' WHERE role = 'admin' AND (admin_tier IS NULL OR admin_tier = '');

INSERT INTO schema_version (version) VALUES (26) ON DUPLICATE KEY UPDATE version = version;

-- v27 — Admissions pipeline (Application A2): enforced status state machine +
-- audit (application_events) + internal notes + an assignee.
ALTER TABLE applications ADD COLUMN assignee_id INT NULL;
CREATE TABLE IF NOT EXISTS application_events (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  application_id INT NOT NULL,
  from_status    VARCHAR(24),
  to_status      VARCHAR(24) NOT NULL,
  actor_id       INT NULL,
  reason         VARCHAR(255),
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_app_events_app (application_id, id),
  CONSTRAINT fk_ae_app   FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_ae_actor FOREIGN KEY (actor_id)       REFERENCES users(id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS application_notes (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  application_id INT NOT NULL,
  author_id      INT NULL,
  note           TEXT NOT NULL,
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_app_notes_app (application_id, id),
  CONSTRAINT fk_an_app    FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_an_author FOREIGN KEY (author_id)      REFERENCES users(id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_version (version) VALUES (27) ON DUPLICATE KEY UPDATE version = version;

-- v28 — Funnel analytics (Application A3): first-party, privacy-respecting
-- instrumentation. sid is an anonymous funnel-session id (NOT identity); event
-- is allowlisted; meta is a small no-PII blob. Do-Not-Track honoured at the edge.
CREATE TABLE IF NOT EXISTS funnel_events (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  sid        VARCHAR(64),
  event      VARCHAR(32) NOT NULL,
  step       INT NULL,
  pathway    VARCHAR(32),
  meta       TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_funnel_event (event, created_at),
  KEY idx_funnel_sid (sid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_version (version) VALUES (28) ON DUPLICATE KEY UPDATE version = version;

-- v29 — Notification centre (LMS L3): a per-user in-app inbox over the
-- automation spine. Typed/titled notifications with an optional body + deep
-- link and a read_at marker; recipients are real users (CASCADE on delete).
CREATE TABLE IF NOT EXISTS notifications (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  type       VARCHAR(64) NOT NULL,
  title      VARCHAR(200) NOT NULL,
  body       TEXT,
  url        VARCHAR(500),
  read_at    DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notif_user (user_id, read_at, id),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_version (version) VALUES (29) ON DUPLICATE KEY UPDATE version = version;

-- v30 — Studio leaderboard (S2): best score + play count per (user, tool) for
-- an opt-in cohort leaderboard. PII minimised at read time (first name only).
CREATE TABLE IF NOT EXISTS tool_scores (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  tool       VARCHAR(32) NOT NULL,
  best_score INT NOT NULL DEFAULT 0,
  plays      INT NOT NULL DEFAULT 0,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY idx_tool_scores_user_tool (user_id, tool),
  KEY idx_tool_scores_board (tool, best_score),
  CONSTRAINT fk_tool_scores_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_version (version) VALUES (30) ON DUPLICATE KEY UPDATE version = version;

-- v31 — Scholarship tier (#4): separate scholarship recipients from fee-paying
-- students. NULL/'' = paying (default for all existing + new accounts);
-- 'scholarship' = sponsored. Drives invoice exemption + reporting.
ALTER TABLE users ADD COLUMN student_type VARCHAR(16) NULL;

INSERT INTO schema_version (version) VALUES (31) ON DUPLICATE KEY UPDATE version = version;

-- v32 — Internal wallet (#12): append-only credit ledger. Balance = SUM(amount_cents).
-- Admin-issued credits, store-only spend (no real-money rails). Every debit is
-- server-validated against the running balance.
CREATE TABLE IF NOT EXISTS wallet_ledger (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  user_id             INT NOT NULL,
  amount_cents        INT NOT NULL,
  reason              VARCHAR(32) NOT NULL,
  reference           VARCHAR(64) NULL,
  note                VARCHAR(255) NULL,
  balance_after_cents INT NOT NULL,
  created_by          INT NULL,
  created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_wallet_user (user_id, id),
  CONSTRAINT fk_wallet_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_version (version) VALUES (32) ON DUPLICATE KEY UPDATE version = version;

-- v33 — Learning Readiness (#1): advisory signal column on applications, derived
-- server-side from age + experience (no new form fields). The column is declared
-- inline in the applications table above; this marks the version so a fresh
-- MySQL install skips the equivalent ALTER migration.
INSERT INTO schema_version (version) VALUES (33) ON DUPLICATE KEY UPDATE version = version;

-- v34 — MAM Scholars Initiative (scholarship flow, flow-guide §2c): a parallel,
-- opt-in essay assessment with its own status track. Award still routes through
-- admin set-student-type; this only captures the application.
CREATE TABLE IF NOT EXISTS scholarship_applications (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  reference             VARCHAR(64) UNIQUE NOT NULL,
  application_reference VARCHAR(64),
  full_name             VARCHAR(160) NOT NULL,
  email                 VARCHAR(190) NOT NULL,
  age_band              VARCHAR(16),
  experience            VARCHAR(32),
  financial_need        TEXT,
  musical_journey       TEXT,
  values_alignment      TEXT,
  community_impact      TEXT,
  commitment_ack        TINYINT(1) NOT NULL DEFAULT 0,
  readiness_signal      VARCHAR(16),
  status                VARCHAR(24) NOT NULL DEFAULT 'submitted',
  ip                    VARCHAR(64),
  created_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_scholarship_email (email),
  KEY idx_scholarship_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO schema_version (version) VALUES (34) ON DUPLICATE KEY UPDATE version = version;

-- v35 — instructor curriculum proposals (admin-approved authoring)
CREATE TABLE IF NOT EXISTS module_proposals (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  course_id    INT NOT NULL,
  level_id     INT,
  week         INT NOT NULL,
  title        VARCHAR(200) NOT NULL,
  kind         VARCHAR(16) NOT NULL DEFAULT 'module',
  content      TEXT,
  proposed_by  INT,
  status       VARCHAR(16) NOT NULL DEFAULT 'pending',
  review_note  TEXT,
  reviewed_by  INT,
  reviewed_at  DATETIME,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_modprop_status (status, id),
  KEY idx_modprop_by (proposed_by, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO schema_version (version) VALUES (35) ON DUPLICATE KEY UPDATE version = version;

-- v36 — instructor (faculty) applications (details + headshot form)
CREATE TABLE IF NOT EXISTS instructor_applications (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  email            VARCHAR(190) NOT NULL,
  full_name        VARCHAR(160),
  phone            VARCHAR(40),
  disciplines      VARCHAR(300),
  experience_years INT,
  bio              TEXT,
  links            TEXT,
  cv_url           VARCHAR(500),
  headshot_url     VARCHAR(500),
  status           VARCHAR(24) NOT NULL DEFAULT 'submitted',
  ip               VARCHAR(64),
  created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_instrapp_email (email),
  KEY idx_instrapp_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO schema_version (version) VALUES (36) ON DUPLICATE KEY UPDATE version = version;

-- v37 — stateful faculty invites (approval-gated, single-use, 7-day expiry)
CREATE TABLE IF NOT EXISTS faculty_invites (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  email      VARCHAR(190) NOT NULL,
  name       VARCHAR(160),
  source     VARCHAR(24) NOT NULL DEFAULT 'contact',
  status     VARCHAR(24) NOT NULL DEFAULT 'requested',
  nonce      VARCHAR(64),
  expires_at DATETIME,
  invited_by INT,
  invited_at DATETIME,
  used_at    DATETIME,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_facinv_email (email),
  KEY idx_facinv_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO schema_version (version) VALUES (37) ON DUPLICATE KEY UPDATE version = version;

-- v38 — lifecycle automations: admin-toggleable enable flag per rule
-- (see api/lib/automations.php). Additive; unknown rules fall back to defaults.
CREATE TABLE IF NOT EXISTS automations (
  akey        VARCHAR(64) PRIMARY KEY,
  enabled     TINYINT(1) NOT NULL DEFAULT 1,
  params      TEXT,
  last_run_at DATETIME NULL,
  run_count   INT NOT NULL DEFAULT 0,
  updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO schema_version (version) VALUES (38) ON DUPLICATE KEY UPDATE version = version;

-- v39 — announcement convergence (P2): an announcement may target a COHORT as
-- well as a legacy class, so a course/cohort-only student sees their updates.
ALTER TABLE announcements ADD COLUMN cohort_id INT NULL;
ALTER TABLE announcements ADD KEY idx_announcements_cohort (cohort_id);
ALTER TABLE announcements ADD CONSTRAINT fk_ann_cohort FOREIGN KEY (cohort_id) REFERENCES cohorts(id) ON DELETE CASCADE;
INSERT INTO schema_version (version) VALUES (39) ON DUPLICATE KEY UPDATE version = version;

-- v40 — resource convergence (P2): a learning resource may attach to a COHORT as
-- well as a legacy class. class_id becomes nullable (a cohort resource has none).
ALTER TABLE class_resources MODIFY class_id INT NULL;
ALTER TABLE class_resources ADD COLUMN cohort_id INT NULL;
ALTER TABLE class_resources ADD KEY idx_class_resources_cohort (cohort_id);
ALTER TABLE class_resources ADD CONSTRAINT fk_res_cohort FOREIGN KEY (cohort_id) REFERENCES cohorts(id) ON DELETE CASCADE;
INSERT INTO schema_version (version) VALUES (40) ON DUPLICATE KEY UPDATE version = version;

-- v41 — KTAMS as first-class programme + program_courses join table linking each
-- course to the programme(s) it belongs to, with preferred level and display order.
INSERT IGNORE INTO programs (code, name, weeks, revision_week, exam_week, requires_core, certification, sort_order, active)
VALUES ('ktams', 'KTAMS Young Musicians', 12, 11, 12, 0, 'participation', 0, 1);

-- program_courses is created once, in the consolidated section above (line ~574,
-- after `programs` and `courses`, which it references). A second copy stood here
-- and has been REMOVED, along with the note that argued for keeping it.
--
-- The argument was that the second copy repaired an install where the first had
-- failed. It does not: both are CREATE TABLE IF NOT EXISTS, so the second never
-- runs when the first succeeded, and when the first FAILED the whole file is
-- re-executed on the next apply (the migrator re-runs it whenever schema_version
-- is behind MAM_SCHEMA_TARGET) and the now-portable first definition creates the
-- table then. What the duplicate did achieve was drift: the two disagreed for a
-- long time (VARCHAR(32) NULL above vs VARCHAR(24) here), so editing this copy
-- changed nothing on a fresh install, silently and with no error.
--
-- The seed INSERT below is kept — it is idempotent and does real work.

INSERT IGNORE INTO program_courses (program_id, course_id, level_code, sort_order)
  SELECT p.id, c.id, 'precert', c.sort_order
  FROM programs p, courses c
  WHERE p.code = 'ktams' AND c.code IN ('drums','guitar','piano');

INSERT IGNORE INTO program_courses (program_id, course_id, sort_order)
  SELECT p.id, c.id, c.sort_order
  FROM programs p, courses c
  WHERE p.code IN ('crash','standard','certified','grand') AND c.active = 1;

INSERT INTO schema_version (version) VALUES (41) ON DUPLICATE KEY UPDATE version = version;

-- v42 — Support hub: student feedback, surveys + responses, support tickets + messages.
CREATE TABLE IF NOT EXISTS student_feedback (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  student_id           INT NOT NULL,
  rating               TINYINT NOT NULL,
  category             VARCHAR(64) NOT NULL DEFAULT 'general',
  subject              VARCHAR(255),
  body                 TEXT NOT NULL,
  enrollment_id        INT,
  course_enrollment_id INT,
  is_anonymous         TINYINT(1) NOT NULL DEFAULT 0,
  created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_sfeedback_rating CHECK (rating BETWEEN 1 AND 5),
  KEY idx_sfeedback_student (student_id),
  KEY idx_sfeedback_created (created_at),
  CONSTRAINT fk_sfeedback_student  FOREIGN KEY (student_id)           REFERENCES users(id)              ON DELETE CASCADE,
  CONSTRAINT fk_sfeedback_enroll   FOREIGN KEY (enrollment_id)        REFERENCES enrollments(id)        ON DELETE SET NULL,
  CONSTRAINT fk_sfeedback_cenroll  FOREIGN KEY (course_enrollment_id) REFERENCES course_enrollments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS surveys (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  title          VARCHAR(255) NOT NULL,
  description    TEXT,
  questions_json LONGTEXT NOT NULL,
  status         VARCHAR(24) NOT NULL DEFAULT 'draft',
  target_track   VARCHAR(64),
  created_by     INT,
  closes_at      DATETIME,
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_surveys_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS survey_responses (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  survey_id    INT NOT NULL,
  student_id   INT NOT NULL,
  answers_json LONGTEXT NOT NULL,
  submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_survey_response (survey_id, student_id),
  CONSTRAINT fk_sresp_survey  FOREIGN KEY (survey_id)  REFERENCES surveys(id) ON DELETE CASCADE,
  CONSTRAINT fk_sresp_student FOREIGN KEY (student_id) REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS support_tickets (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  student_id  INT NOT NULL,
  reference   VARCHAR(24) NOT NULL UNIQUE,
  category    VARCHAR(64) NOT NULL DEFAULT 'general',
  subject     VARCHAR(255) NOT NULL,
  body        TEXT NOT NULL,
  status      VARCHAR(24) NOT NULL DEFAULT 'open',
  priority    VARCHAR(24) NOT NULL DEFAULT 'normal',
  assigned_to INT,
  resolved_at DATETIME,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tickets_student (student_id),
  KEY idx_tickets_status  (status),
  CONSTRAINT fk_tickets_student  FOREIGN KEY (student_id)  REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_tickets_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ticket_messages (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id   INT NOT NULL,
  author_id   INT NOT NULL,
  body        TEXT NOT NULL,
  is_internal TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ticket_msgs_ticket (ticket_id),
  CONSTRAINT fk_tmsg_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_tmsg_author FOREIGN KEY (author_id)  REFERENCES users(id)           ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_version (version) VALUES (42) ON DUPLICATE KEY UPDATE version = version;

-- v43 — Lesson records on class_sessions + shareable parent progress report tokens.
ALTER TABLE class_sessions ADD COLUMN lesson_notes TEXT;
ALTER TABLE class_sessions ADD COLUMN topics TEXT;
ALTER TABLE class_sessions ADD COLUMN learning_outcomes TEXT;

CREATE TABLE IF NOT EXISTS progress_report_tokens (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  student_id       INT NOT NULL,
  token            VARCHAR(64) NOT NULL UNIQUE,
  label            VARCHAR(255),
  scope            VARCHAR(32) NOT NULL DEFAULT 'all',
  expires_at       DATETIME,
  created_by       INT,
  accessed_count   INT NOT NULL DEFAULT 0,
  last_accessed_at DATETIME,
  created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_prt_student (student_id),
  CONSTRAINT fk_prt_student    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_prt_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_version (version) VALUES (43) ON DUPLICATE KEY UPDATE version = version;


-- v44 — Student enrollment references
ALTER TABLE users ADD COLUMN enrollment_ref VARCHAR(32) NULL;
CREATE UNIQUE INDEX idx_users_enrollment_ref ON users(enrollment_ref);

INSERT INTO schema_version (version) VALUES (44) ON DUPLICATE KEY UPDATE version = version;


-- v45 — Enterprise scheduling: waitlist, session audit trail, substitutes, structured schedules.
CREATE TABLE IF NOT EXISTS class_waitlist (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  cohort_id  INT NOT NULL,
  position   INT NOT NULL,
  status     VARCHAR(16) NOT NULL DEFAULT 'waiting',
  notified_at DATETIME,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_waitlist (student_id, cohort_id),
  KEY idx_waitlist_cohort  (cohort_id, position),
  KEY idx_waitlist_student (student_id),
  CONSTRAINT fk_wl_student FOREIGN KEY (student_id) REFERENCES users(id)   ON DELETE CASCADE,
  CONSTRAINT fk_wl_cohort  FOREIGN KEY (cohort_id)  REFERENCES cohorts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS session_history (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  session_id    INT NOT NULL,
  field_changed VARCHAR(64) NOT NULL,
  old_value     TEXT,
  new_value     TEXT,
  reason        TEXT,
  changed_by    INT,
  changed_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sh_session (session_id),
  CONSTRAINT fk_sh_session    FOREIGN KEY (session_id) REFERENCES class_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_sh_changed_by FOREIGN KEY (changed_by) REFERENCES users(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE class_sessions
  ADD COLUMN substitute_id      INT          NULL AFTER created_by,
  ADD COLUMN rescheduled_from   INT          NULL AFTER substitute_id,
  ADD COLUMN cancellation_reason TEXT        NULL AFTER rescheduled_from,
  ADD COLUMN room               VARCHAR(120) NULL AFTER cancellation_reason,
  ADD CONSTRAINT fk_cs_substitute    FOREIGN KEY (substitute_id)    REFERENCES users(id)          ON DELETE SET NULL,
  ADD CONSTRAINT fk_cs_reschd_from   FOREIGN KEY (rescheduled_from) REFERENCES class_sessions(id) ON DELETE SET NULL;

ALTER TABLE cohorts
  ADD COLUMN schedule_days     VARCHAR(20) NULL AFTER meeting_pattern,
  ADD COLUMN schedule_time     VARCHAR(8)  NULL AFTER schedule_days,
  ADD COLUMN session_duration  INT         NULL DEFAULT 60 AFTER schedule_time;

INSERT INTO schema_version (version) VALUES (45) ON DUPLICATE KEY UPDATE version = version;


-- v46 — Post-session progress reports, instructor fines, form builder AI.
-- NOTE: `ADD COLUMN IF NOT EXISTS` is MariaDB-only — MySQL 8 raises a syntax
-- error (1064), which the migration runner does NOT treat as expected, so the
-- column silently never landed while schema_version still advanced. Use a plain
-- ADD COLUMN: on first apply it creates the column; on re-run it errors 1060
-- (duplicate column), which the runner tolerates like every other ALTER here.
ALTER TABLE surveys ADD COLUMN kind VARCHAR(32) NOT NULL DEFAULT 'survey';

CREATE TABLE IF NOT EXISTS progress_reports (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  session_id    INT NOT NULL,
  student_id    INT NOT NULL,
  instructor_id INT NOT NULL,
  form_id       INT NULL,
  -- MySQL rejects a literal DEFAULT on TEXT (error 1101), which would fail this
  -- whole CREATE and leave progress_reports missing. Drop the default — the
  -- writer always supplies answers_json, and readers coalesce NULL to '{}'.
  answers_json  TEXT NOT NULL,
  narrative     TEXT NULL,
  email_sent    TINYINT(1) NOT NULL DEFAULT 0,
  email_sent_at DATETIME NULL,
  submitted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pr (session_id, student_id),
  KEY idx_pr_session    (session_id),
  KEY idx_pr_student    (student_id),
  KEY idx_pr_instructor (instructor_id),
  CONSTRAINT fk_pr_session    FOREIGN KEY (session_id)    REFERENCES class_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_pr_student    FOREIGN KEY (student_id)    REFERENCES users(id)          ON DELETE CASCADE,
  CONSTRAINT fk_pr_instructor FOREIGN KEY (instructor_id) REFERENCES users(id)          ON DELETE CASCADE,
  CONSTRAINT fk_pr_form       FOREIGN KEY (form_id)       REFERENCES surveys(id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS instructor_fines (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  instructor_id INT NOT NULL,
  session_id    INT NULL,
  reason        VARCHAR(100) NOT NULL DEFAULT 'late_progress_report',
  amount_kobo   INT NOT NULL DEFAULT 0,
  status        VARCHAR(16) NOT NULL DEFAULT 'pending',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at   DATETIME NULL,
  resolved_by   INT NULL,
  KEY idx_fines_instructor (instructor_id),
  CONSTRAINT fk_fine_instructor FOREIGN KEY (instructor_id) REFERENCES users(id)          ON DELETE CASCADE,
  CONSTRAINT fk_fine_session    FOREIGN KEY (session_id)    REFERENCES class_sessions(id) ON DELETE SET NULL,
  CONSTRAINT fk_fine_resolved   FOREIGN KEY (resolved_by)   REFERENCES users(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_version (version) VALUES (46) ON DUPLICATE KEY UPDATE version = version;

-- v47: fines resolution note
ALTER TABLE instructor_fines ADD COLUMN resolution_note TEXT NULL;
INSERT INTO schema_version (version) VALUES (47) ON DUPLICATE KEY UPDATE version = version;

-- ─────────────────────────────────────────────────────────────
-- v48 — Repair pass for MySQL-8 DDL that silently failed on earlier applies.
-- The whole file is re-exec'd (idempotently) whenever schema_version is behind
-- MAM_SCHEMA_TARGET, so bumping to 48 re-runs the corrected v9/v46 statements:
--   • store_items.available_locations was `TEXT DEFAULT '*'` and
--   • progress_reports.answers_json was `TEXT NOT NULL DEFAULT '{}'`
--     — MySQL rejects a literal DEFAULT on TEXT (error 1101), so those CREATEs
--     failed and the tables never existed on MySQL.
--   • surveys.kind used `ADD COLUMN IF NOT EXISTS` (MariaDB-only; MySQL 8 → 1064).
-- Those definitions are now portable above; the CREATE TABLE IF NOT EXISTS /
-- tolerated-duplicate ALTER re-runs recreate whatever was missing. These extra
-- guards make the repair explicit and order-independent.
--
-- A second `progress_reports` CREATE stood here and has been REMOVED, along with
-- the note that told the next maintainer not to remove it. Same reasoning as
-- program_courses above: the repair is the RE-RUN of the corrected first
-- definition (line ~1353, after users, class_sessions and surveys, all of which
-- it references), not a second copy of it. `CREATE TABLE IF NOT EXISTS` makes a
-- duplicate a silent no-op whenever the original worked, so all the second copy
-- could ever contribute is a divergence nothing reports.
ALTER TABLE surveys      ADD COLUMN kind VARCHAR(32) NOT NULL DEFAULT 'survey';
ALTER TABLE store_items  ADD COLUMN available_locations TEXT;
-- Normalize collation on tables created without explicit COLLATE so they all
-- use utf8mb4_unicode_ci. Mismatched collations cause error 1271 when these
-- tables appear together in a UNION query on MariaDB.
ALTER TABLE users              CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE classes            CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE cohorts            CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE course_enrollments CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE announcements      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE class_resources    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
INSERT INTO schema_version (version) VALUES (48) ON DUPLICATE KEY UPDATE version = version;

-- v49: add application columns that exist in SQLite but were never in the MySQL schema,
-- causing POST /api/application to 500 on every submission in production.
-- Plain ADD COLUMN (no IF NOT EXISTS) — MySQL 8 rejects the IF NOT EXISTS syntax
-- (error 1064, which is NOT in the tolerated list and caused v49 to fail silently on
-- MySQL 8 while still bumping schema_version). 1060 (duplicate column) is tolerated,
-- so re-runs are safe. The CREATE TABLE above already includes these columns for
-- fresh installs; these ALTERs handle upgrades from pre-v13 installs.
ALTER TABLE applications ADD COLUMN level         VARCHAR(16) NULL;
ALTER TABLE applications ADD COLUMN expression    VARCHAR(16) NULL;
ALTER TABLE applications ADD COLUMN extra_courses INT DEFAULT 0;
ALTER TABLE applications ADD COLUMN courses_label VARCHAR(400) NULL;
INSERT INTO schema_version (version) VALUES (49) ON DUPLICATE KEY UPDATE version = version;

-- v50: repair pass for MySQL 8 installs where v49's IF NOT EXISTS raised error 1064
-- and left the four application columns absent even though schema_version advanced
-- to 49. Plain ADD COLUMN tolerates 1060 on re-runs and adds columns where missing.
-- Also adds cohort/session/announcement/resource columns that criticalRepairs now
-- covers but that could have been missed on partial or manual deploys.
ALTER TABLE applications    ADD COLUMN level              VARCHAR(16)  NULL;
ALTER TABLE applications    ADD COLUMN expression         VARCHAR(16)  NULL;
ALTER TABLE applications    ADD COLUMN extra_courses      INT DEFAULT 0;
ALTER TABLE applications    ADD COLUMN courses_label      VARCHAR(400) NULL;
ALTER TABLE class_sessions  ADD COLUMN lesson_notes       TEXT         NULL;
ALTER TABLE class_sessions  ADD COLUMN substitute_id      INT          NULL;
ALTER TABLE class_sessions  ADD COLUMN rescheduled_from   INT          NULL;
ALTER TABLE class_sessions  ADD COLUMN cancellation_reason TEXT        NULL;
ALTER TABLE class_sessions  ADD COLUMN room               VARCHAR(120) NULL;
ALTER TABLE cohorts         ADD COLUMN schedule_days      VARCHAR(20)  NULL;
ALTER TABLE cohorts         ADD COLUMN schedule_time      VARCHAR(8)   NULL;
ALTER TABLE cohorts         ADD COLUMN session_duration   INT DEFAULT 60;
ALTER TABLE announcements   ADD COLUMN cohort_id          INT          NULL;
ALTER TABLE class_resources ADD COLUMN cohort_id          INT          NULL;
ALTER TABLE instructor_fines ADD COLUMN resolution_note   TEXT         NULL;
INSERT INTO schema_version (version) VALUES (50) ON DUPLICATE KEY UPDATE version = version;

-- v51: idempotency_cache table for the shared idempotency layer (api/lib/idempotency.php).
-- Keyed by (scope, idem_key) so application and payment keys never collide.
-- Entries expire after 24 h; the cron purges them via mam_idempotency_purge().
CREATE TABLE IF NOT EXISTS idempotency_cache (
  idem_key      VARCHAR(80)  NOT NULL,
  scope         VARCHAR(64)  NOT NULL,
  response_json TEXT         NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (idem_key, scope),
  KEY idx_idem_created (created_at)
);
INSERT INTO schema_version (version) VALUES (51) ON DUPLICATE KEY UPDATE version = version;

-- v52: summer_applications — separate table for the Summer Programme.
-- References use the SUM- prefix to distinguish from regular MAM- applications.
-- No customer_id FK: summer applicants are not necessarily enrolled students.
CREATE TABLE IF NOT EXISTS summer_applications (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  reference           VARCHAR(40)   NOT NULL UNIQUE,
  first_name          VARCHAR(100)  NOT NULL,
  last_name           VARCHAR(100)  NOT NULL,
  email               VARCHAR(200)  NOT NULL,
  phone               VARCHAR(30),
  dob                 DATE          NOT NULL,
  age_at_submission   TINYINT UNSIGNED,
  sex                 VARCHAR(16),
  country             VARCHAR(60),
  city                VARCHAR(80),
  guardian_name       VARCHAR(160),
  guardian_phone      VARCHAR(30),
  guardian_email      VARCHAR(200),
  discipline          VARCHAR(100),
  experience          TEXT,
  goals               TEXT,
  referrer            VARCHAR(200),
  policy_agreed       TINYINT(1)    NOT NULL DEFAULT 0,
  policy_signed_name  VARCHAR(160),
  policy_signed_at    DATETIME,
  session_year        YEAR,
  fee_snapshot_usd    DECIMAL(10,2),
  status              VARCHAR(20)   NOT NULL DEFAULT 'submitted',
  notes               TEXT,
  source              VARCHAR(200),
  ip                  VARCHAR(45),
  user_agent          VARCHAR(240),
  created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_summer_apps_email   (email),
  KEY idx_summer_apps_status  (status, id),
  KEY idx_summer_apps_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO schema_version (version) VALUES (52) ON DUPLICATE KEY UPDATE version = version;

ALTER TABLE summer_applications ADD COLUMN headshot_url VARCHAR(500) NULL;
INSERT INTO schema_version (version) VALUES (53) ON DUPLICATE KEY UPDATE version = version;

-- v54: meeting_link on classes — Zoom/Meet/Teams URL for online/hybrid classes.
ALTER TABLE classes ADD COLUMN meeting_link VARCHAR(500) NULL;
INSERT INTO schema_version (version) VALUES (54) ON DUPLICATE KEY UPDATE version = version;

-- v55: promo_banners — admin-configurable promotional email banner with date-range gating.
CREATE TABLE IF NOT EXISTS promo_banners (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  title      VARCHAR(200) NOT NULL,
  body       TEXT         NOT NULL,
  cta_label  VARCHAR(100) NOT NULL DEFAULT '',
  cta_url    VARCHAR(500) NOT NULL DEFAULT '',
  bg_color   VARCHAR(20)  NOT NULL DEFAULT '#FEF3C7',
  active     TINYINT(1)   NOT NULL DEFAULT 1,
  public           TINYINT(1) NOT NULL DEFAULT 0,
  discount_percent INT        NOT NULL DEFAULT 0,
  applies_to       VARCHAR(16) NULL,
  starts_at  DATE         NULL,
  ends_at    DATE         NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_promo_active (active, starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO schema_version (version) VALUES (55) ON DUPLICATE KEY UPDATE version = version;

-- v56: id_cards + card_scans — student/instructor ID cards with HMAC-signed QR codes.
CREATE TABLE IF NOT EXISTS id_cards (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT          NULL,
  summer_app_id  INT          NULL,
  role           VARCHAR(20)  NOT NULL DEFAULT 'student',
  serial         VARCHAR(20)  NOT NULL UNIQUE,
  version        TINYINT      NOT NULL DEFAULT 1,
  status         VARCHAR(16)  NOT NULL DEFAULT 'active',
  session_code   VARCHAR(120) NOT NULL DEFAULT '',
  photo_url      VARCHAR(500) NULL,
  issued_at      DATETIME     NOT NULL,
  expires_at     DATETIME     NULL,
  printed_at     DATETIME     NULL,
  revoked_at     DATETIME     NULL,
  revoke_reason  TEXT         NULL,
  created_by     INT          NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_id_cards_user   (user_id, status),
  KEY idx_id_cards_summer (summer_app_id, status),
  KEY idx_id_cards_serial (serial),
  KEY idx_id_cards_expires (status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS card_scans (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  card_id     INT          NULL,
  raw_serial  VARCHAR(20)  NOT NULL,
  session_id  INT          NULL,
  class_id    INT          NULL,
  scanned_by  INT          NOT NULL,
  result      VARCHAR(32)  NOT NULL,
  scanned_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_card_scans_card    (card_id),
  KEY idx_card_scans_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO schema_version (version) VALUES (56) ON DUPLICATE KEY UPDATE version = version;
-- Plain `ADD COLUMN`, not `ADD COLUMN IF NOT EXISTS`: the latter is MariaDB-only
-- and MySQL 8 raises 1064, which the runner does NOT tolerate — so the column
-- silently never landed while schema_version advanced anyway. Same trap the v46
-- note above describes; these five were added after that fix and missed it.
--
-- ⚠️ Only ONE of the five actually creates anything: `class_sessions.meeting_link`.
-- The other four columns (promo_banners.public / .discount_percent / .applies_to
-- and id_cards.expires_at) are ALREADY declared inside their own CREATE TABLE
-- earlier in this file, so these ALTERs raise 1060 on EVERY apply including the
-- first — not "on a re-run", as this note used to say. That is harmless (the
-- runner tolerates 1050/1060/1061/1091 exactly so an idempotent file can re-run)
-- and they are kept as the legacy repair for a host whose original CREATE
-- predated those columns. But do not read them as the statements that put the
-- columns there on a fresh install; the CREATE TABLE does.
ALTER TABLE class_sessions ADD COLUMN meeting_link VARCHAR(500) NULL;
INSERT INTO schema_version (version) VALUES (57) ON DUPLICATE KEY UPDATE version = version;

-- v58: public-site promo mode (banner + time-limited discount) on promo_banners.
ALTER TABLE promo_banners ADD COLUMN public TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE promo_banners ADD COLUMN discount_percent INT NOT NULL DEFAULT 0;
ALTER TABLE promo_banners ADD COLUMN applies_to VARCHAR(16) NULL;
INSERT INTO schema_version (version) VALUES (58) ON DUPLICATE KEY UPDATE version = version;

-- v59: ID cards get a REAL expiry. The printed EXP was derived from the issue year
-- and enforced nowhere, so an expired card kept scanning into attendance. Summer
-- cards are meant to lapse a couple of months after the camp. Backfill derives a
-- date from each card's own issue date so no row is left NULL (= never expires).
ALTER TABLE id_cards ADD COLUMN expires_at DATETIME NULL;
UPDATE id_cards SET expires_at = DATE_ADD(issued_at, INTERVAL 2 MONTH)
 WHERE expires_at IS NULL AND summer_app_id IS NOT NULL;
UPDATE id_cards SET expires_at = DATE_ADD(issued_at, INTERVAL 12 MONTH)
 WHERE expires_at IS NULL;
INSERT INTO schema_version (version) VALUES (59) ON DUPLICATE KEY UPDATE version = version;

-- v60: attendance had no uniqueness, so the check-then-insert in card-scan /
-- mark-attendance / self-checkin could race into TWO 'present' rows for one
-- (enrolment, day), inflating every attendance rate that counts them. Dedup
-- (keep earliest per key) then enforce the uniqueness the code always assumed.
-- (This block was previously SQLite-only; MySQL installs never reached v60 and
-- re-ran the whole schema on every cold start. Mirrored here to close that gap.)
DELETE FROM attendance WHERE id NOT IN (
  SELECT id FROM (SELECT MIN(id) AS id FROM attendance GROUP BY enrollment_id, session_date) t
);
CREATE UNIQUE INDEX idx_attendance_unique ON attendance(enrollment_id, session_date);
INSERT INTO schema_version (version) VALUES (60) ON DUPLICATE KEY UPDATE version = version;

-- v61: money normalization (LMS 2.0 Part A). `payments.amount` meant whole
-- naira for NGN rows but dollars for USD rows, so SUM(amount) across rows added
-- naira to dollars and the Revenue KPI/trend reported nonsense. Add an explicit
-- integer, currency-safe representation next to the legacy column (kept intact
-- and readable — no drop here; deferred to admin sign-off). amount_usd_cents is
-- the canonical, cross-row-summable USD value, frozen at charge time alongside
-- the fx rate used. fx_backfilled: 0 = captured live, 1 = derived by
-- tools/backfill-money.php. Plain ADD COLUMN (not the MariaDB-only IF NOT
-- EXISTS) so these apply on MySQL 8 too; a re-exec hits errno 1060/1061 which
-- the migrator swallows.
ALTER TABLE payments ADD COLUMN amount_minor     BIGINT       NULL;
ALTER TABLE payments ADD COLUMN amount_usd_cents BIGINT       NULL;
ALTER TABLE payments ADD COLUMN fx_rate_usd_ngn  DECIMAL(14,4) NULL;
ALTER TABLE payments ADD COLUMN fx_backfilled    TINYINT(1)   NOT NULL DEFAULT 0;
CREATE INDEX idx_pay_usd ON payments(status, amount_usd_cents);
ALTER TABLE instructor_fines ADD COLUMN currency         CHAR(3)      NOT NULL DEFAULT 'NGN';
ALTER TABLE instructor_fines ADD COLUMN amount_usd_cents BIGINT       NULL;
ALTER TABLE instructor_fines ADD COLUMN fx_rate_usd_ngn  DECIMAL(14,4) NULL;
INSERT INTO schema_version (version) VALUES (61) ON DUPLICATE KEY UPDATE version = version;

-- v62: paid class rescheduling (LMS 2.0 Part C). Student requests to move one
-- or more cohort sessions; server prices it (free allowance → fee), settles via
-- wallet/provider, an instructor approves, the move is applied through the
-- existing session_history-audited writer. Money uses the Part A model
-- (canonical USD cents + frozen rate). Gated behind reschedule_enabled (off by
-- default), so these tables are inert until an admin opts in.
CREATE TABLE IF NOT EXISTS reschedule_requests (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  requester_id     INT           NOT NULL,
  cohort_id        INT           NOT NULL,
  status           VARCHAR(20)   NOT NULL DEFAULT 'pending_payment',
  reason           TEXT          NULL,
  session_count    INT           NOT NULL DEFAULT 0,
  fee_usd_cents    BIGINT        NOT NULL DEFAULT 0,
  fee_currency     CHAR(3)       NOT NULL DEFAULT 'USD',
  fx_rate_usd_ngn  DECIMAL(14,4) NULL,
  waived           TINYINT(1)    NOT NULL DEFAULT 0,
  waived_reason    VARCHAR(190)  NULL,
  payment_ref      VARCHAR(64)   NULL,
  payment_provider VARCHAR(24)   NULL,
  paid_at          DATETIME      NULL,
  decided_by       INT           NULL,
  decided_at       DATETIME      NULL,
  decision_note    TEXT          NULL,
  applied_at       DATETIME      NULL,
  expires_at       DATETIME      NULL,
  created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_resched_cohort (cohort_id, status),
  KEY idx_resched_req    (requester_id, status),
  KEY idx_resched_expiry (status, expires_at),
  CONSTRAINT fk_resched_requester FOREIGN KEY (requester_id) REFERENCES users(id)   ON DELETE CASCADE,
  CONSTRAINT fk_resched_cohort    FOREIGN KEY (cohort_id)    REFERENCES cohorts(id) ON DELETE CASCADE,
  CONSTRAINT fk_resched_decided   FOREIGN KEY (decided_by)   REFERENCES users(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reschedule_items (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  request_id       INT       NOT NULL,
  session_id       INT       NOT NULL,
  old_scheduled_at DATETIME  NULL,
  new_scheduled_at DATETIME  NOT NULL,
  applied          TINYINT(1) NOT NULL DEFAULT 0,
  created_at       DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_resched_item_req     (request_id),
  KEY idx_resched_item_session (session_id),
  CONSTRAINT fk_resched_item_req     FOREIGN KEY (request_id) REFERENCES reschedule_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_resched_item_session FOREIGN KEY (session_id) REFERENCES class_sessions(id)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reschedule_events (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  request_id  INT          NOT NULL,
  event       VARCHAR(32)  NOT NULL,
  actor_id    INT          NULL,
  detail      TEXT         NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_resched_event_req (request_id),
  CONSTRAINT fk_resched_event_req   FOREIGN KEY (request_id) REFERENCES reschedule_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_resched_event_actor FOREIGN KEY (actor_id)   REFERENCES users(id)               ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (skey, svalue) VALUES
  ('reschedule_enabled','0'),
  ('reschedule_fee_usd','10'),
  ('reschedule_fee_mode','per_session'),
  ('reschedule_free_per_term','1'),
  ('reschedule_max_per_term','3'),
  ('reschedule_window_hours','24'),
  ('reschedule_payment_expiry_hours','24'),
  ('reschedule_approval_required','1')
ON DUPLICATE KEY UPDATE skey = skey;
INSERT INTO schema_version (version) VALUES (62) ON DUPLICATE KEY UPDATE version = version;

-- v63: LMS 2.0 teaching tables (Part B) — all additive/inert until used.
CREATE TABLE IF NOT EXISTS assignment_groups (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  class_id     INT          NULL,
  cohort_id    INT          NULL,
  author_id    INT          NULL,
  rubric_id    INT          NULL,
  title        VARCHAR(190) NOT NULL,
  instructions TEXT         NULL,
  due_on       DATETIME     NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_agroups_class  (class_id),
  KEY idx_agroups_cohort (cohort_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE assignments ADD COLUMN group_id INT NULL;
CREATE INDEX idx_assignments_group ON assignments(group_id);

CREATE TABLE IF NOT EXISTS submission_files (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  submission_id INT          NOT NULL,
  url           TEXT         NOT NULL,
  kind          VARCHAR(24)  NOT NULL DEFAULT 'link',
  filename      VARCHAR(255) NULL,
  size_bytes    BIGINT       NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_subfiles_submission (submission_id),
  CONSTRAINT fk_subfiles_sub FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS resource_versions (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  resource_id INT          NOT NULL,
  version     INT          NOT NULL DEFAULT 1,
  title       VARCHAR(255) NULL,
  url         TEXT         NOT NULL,
  note        TEXT         NULL,
  created_by  INT          NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_resver_resource (resource_id, version),
  CONSTRAINT fk_resver_res FOREIGN KEY (resource_id) REFERENCES class_resources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lessons (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  cohort_id   INT          NULL,
  module_id   INT          NULL,
  title       VARCHAR(255) NOT NULL,
  body        TEXT         NULL,
  url         TEXT         NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  published   TINYINT(1)   NOT NULL DEFAULT 1,
  created_by  INT          NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lessons_cohort (cohort_id, sort_order),
  CONSTRAINT fk_lessons_cohort FOREIGN KEY (cohort_id) REFERENCES cohorts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lesson_progress (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  lesson_id    INT        NOT NULL,
  student_id   INT        NOT NULL,
  status       VARCHAR(16) NOT NULL DEFAULT 'in_progress',
  percent      INT        NOT NULL DEFAULT 0,
  completed_at DATETIME   NULL,
  updated_at   DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY idx_lesson_progress_unique (lesson_id, student_id),
  CONSTRAINT fk_lprog_lesson  FOREIGN KEY (lesson_id)  REFERENCES lessons(id) ON DELETE CASCADE,
  CONSTRAINT fk_lprog_student FOREIGN KEY (student_id) REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO schema_version (version) VALUES (63) ON DUPLICATE KEY UPDATE version = version;

-- v64: gradebook v2 (Part B) — reusable rubrics scored per submission.
CREATE TABLE IF NOT EXISTS rubrics (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  title      VARCHAR(190) NOT NULL,
  cohort_id  INT          NULL,
  created_by INT          NULL,
  active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rubrics_cohort (cohort_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rubric_criteria (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  rubric_id  INT          NOT NULL,
  label      VARCHAR(190) NOT NULL,
  max_points INT          NOT NULL DEFAULT 10,
  weight     DECIMAL(6,3) NOT NULL DEFAULT 1.0,
  sort_order INT          NOT NULL DEFAULT 0,
  KEY idx_rcriteria_rubric (rubric_id, sort_order),
  CONSTRAINT fk_rcriteria_rubric FOREIGN KEY (rubric_id) REFERENCES rubrics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS submission_reviews (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  submission_id INT          NOT NULL,
  rubric_id     INT          NULL,
  reviewer_id   INT          NULL,
  scores_json   TEXT         NULL,
  total_points  DECIMAL(8,2) NULL,
  max_points    DECIMAL(8,2) NULL,
  feedback      TEXT         NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sreviews_submission (submission_id),
  CONSTRAINT fk_sreviews_sub    FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_sreviews_rubric FOREIGN KEY (rubric_id)     REFERENCES rubrics(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO schema_version (version) VALUES (64) ON DUPLICATE KEY UPDATE version = version;

-- v65: scheduling core (Part D) — availability windows, a normalised rooms
-- table (class_sessions keeps free-text `room`; room_id is the additive FK),
-- and calendar_tokens for a signed read-only ICS feed.
CREATE TABLE IF NOT EXISTS instructor_availability (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  instructor_id  INT         NOT NULL,
  day_of_week    TINYINT     NOT NULL,
  start_time     VARCHAR(8)  NOT NULL,
  end_time       VARCHAR(8)  NOT NULL,
  effective_from DATE        NULL,
  effective_to   DATE        NULL,
  active         TINYINT(1)  NOT NULL DEFAULT 1,
  created_at     DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_availability_instr (instructor_id, day_of_week, active),
  CONSTRAINT fk_avail_instr FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rooms (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL,
  capacity   INT          NULL,
  location   VARCHAR(190) NULL,
  active      TINYINT(1)  NOT NULL DEFAULT 1,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE class_sessions ADD COLUMN room_id INT NULL;
CREATE INDEX idx_class_sessions_room ON class_sessions(room_id);

CREATE TABLE IF NOT EXISTS calendar_tokens (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT          NOT NULL,
  token        VARCHAR(64)  NOT NULL UNIQUE,
  scope        VARCHAR(24)  NOT NULL DEFAULT 'sessions',
  active       TINYINT(1)   NOT NULL DEFAULT 1,
  last_used_at DATETIME     NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_caltokens_user (user_id, active),
  CONSTRAINT fk_caltokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO schema_version (version) VALUES (65) ON DUPLICATE KEY UPDATE version = version;

-- v66: NGG-style student check-in. session_attendance gains check-in
-- provenance (when/how/where) so self check-ins and late arrivals are
-- distinguishable from instructor marks. Additive; existing flows unchanged.
ALTER TABLE session_attendance ADD COLUMN checked_in_at DATETIME NULL;
ALTER TABLE session_attendance ADD COLUMN method VARCHAR(16) NOT NULL DEFAULT 'instructor';
ALTER TABLE session_attendance ADD COLUMN checkin_lat DECIMAL(10,6) NULL;
ALTER TABLE session_attendance ADD COLUMN checkin_lng DECIMAL(10,6) NULL;
INSERT INTO settings (skey, svalue) VALUES
  ('checkin_self_enabled','1'),
  ('checkin_open_before_min','30'),
  ('checkin_late_grace_min','10'),
  ('checkin_geofence_enabled','0'),
  ('checkin_geofence_lat',''),
  ('checkin_geofence_lng',''),
  ('checkin_geofence_radius_m','200'),
  ('program_total_weeks','12')
ON DUPLICATE KEY UPDATE skey = skey;
INSERT INTO schema_version (version) VALUES (66) ON DUPLICATE KEY UPDATE version = version;

-- v67: accountability ledger (PDF §13-15, §37) — punctuality charges on the
-- Part A money model with a waiver/exception path. Record only (no wallet
-- auto-debit); auto-charge on late check-in is gated behind late_charge_enabled.
CREATE TABLE IF NOT EXISTS accountability_charges (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  subject_type     VARCHAR(16)  NOT NULL DEFAULT 'student',
  subject_id       INT          NOT NULL,
  session_id       INT          NULL,
  reason           VARCHAR(32)  NOT NULL DEFAULT 'late_arrival',
  minutes          INT          NOT NULL DEFAULT 0,
  amount_usd_cents BIGINT       NOT NULL DEFAULT 0,
  currency         CHAR(3)      NOT NULL DEFAULT 'USD',
  fx_rate_usd_ngn  DECIMAL(14,4) NULL,
  status           VARCHAR(16)  NOT NULL DEFAULT 'outstanding',
  note             VARCHAR(255) NULL,
  resolved_by      INT          NULL,
  resolved_at      DATETIME     NULL,
  resolution_note  VARCHAR(255) NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_acct_subject (subject_type, subject_id, status),
  KEY idx_acct_session (session_id),
  CONSTRAINT fk_acct_subject  FOREIGN KEY (subject_id)  REFERENCES users(id)          ON DELETE CASCADE,
  CONSTRAINT fk_acct_session  FOREIGN KEY (session_id)  REFERENCES class_sessions(id) ON DELETE SET NULL,
  CONSTRAINT fk_acct_resolved FOREIGN KEY (resolved_by) REFERENCES users(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO settings (skey, svalue) VALUES
  ('late_charge_enabled','0'),
  ('late_charge_student_usd','1'),
  ('late_charge_instructor_usd','2')
ON DUPLICATE KEY UPDATE skey = skey;
INSERT INTO schema_version (version) VALUES (67) ON DUPLICATE KEY UPDATE version = version;

-- v68: pre-class learning + teach-back (PDF §8-9, 18-19). lessons becomes the
-- week-module content unit; a class_session can reference the module it
-- delivers; teachbacks records the student's DECLARE step + instructor decision.
ALTER TABLE lessons ADD COLUMN week_no INT NULL;
ALTER TABLE lessons ADD COLUMN objectives TEXT NULL;
ALTER TABLE lessons ADD COLUMN prep_instructions TEXT NULL;
ALTER TABLE lessons ADD COLUMN resources_json TEXT NULL;
ALTER TABLE lessons ADD COLUMN teachback_required TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE class_sessions ADD COLUMN lesson_id INT NULL;
CREATE INDEX idx_class_sessions_lesson ON class_sessions(lesson_id);

CREATE TABLE IF NOT EXISTS teachbacks (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  lesson_id     INT          NOT NULL,
  student_id    INT          NOT NULL,
  cohort_id     INT          NULL,
  kind          VARCHAR(16)  NOT NULL DEFAULT 'written',
  artifact_url  TEXT         NULL,
  body          TEXT         NULL,
  status        VARCHAR(16)  NOT NULL DEFAULT 'submitted',
  reviewer_id   INT          NULL,
  decision      VARCHAR(16)  NULL,
  understanding INT          NULL,
  accuracy      INT          NULL,
  vocabulary    INT          NULL,
  explanation   INT          NULL,
  application   INT          NULL,
  confidence    INT          NULL,
  feedback      TEXT         NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at   DATETIME     NULL,
  UNIQUE KEY idx_teachback_unique (lesson_id, student_id),
  KEY idx_teachback_queue (status),
  CONSTRAINT fk_tb_lesson   FOREIGN KEY (lesson_id)   REFERENCES lessons(id) ON DELETE CASCADE,
  CONSTRAINT fk_tb_student  FOREIGN KEY (student_id)  REFERENCES users(id)   ON DELETE CASCADE,
  CONSTRAINT fk_tb_cohort   FOREIGN KEY (cohort_id)   REFERENCES cohorts(id) ON DELETE SET NULL,
  CONSTRAINT fk_tb_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO schema_version (version) VALUES (68) ON DUPLICATE KEY UPDATE version = version;

-- v69: module status + carry-forward (PDF §6-7, 29-30). lesson_progress gains
-- mastery/week/attempt/deadline fields (status uses the §7 vocabulary, free
-- text); a carry-forward is an appended event so no history is deleted.
ALTER TABLE lesson_progress ADD COLUMN mastery_pct DECIMAL(5,1) NULL;
ALTER TABLE lesson_progress ADD COLUMN week_scheduled INT NULL;
ALTER TABLE lesson_progress ADD COLUMN week_started INT NULL;
ALTER TABLE lesson_progress ADD COLUMN week_completed INT NULL;
ALTER TABLE lesson_progress ADD COLUMN attempts INT NOT NULL DEFAULT 0;
ALTER TABLE lesson_progress ADD COLUMN due_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS module_carryforward (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  lesson_id        INT          NOT NULL,
  student_id       INT          NOT NULL,
  from_week        INT          NULL,
  to_week          INT          NULL,
  to_date          DATETIME     NULL,
  reason           VARCHAR(255) NULL,
  previous_status  VARCHAR(32)  NULL,
  previous_mastery DECIMAL(5,1) NULL,
  created_by       INT          NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_carryfwd_student (student_id, lesson_id),
  CONSTRAINT fk_cf_lesson  FOREIGN KEY (lesson_id)  REFERENCES lessons(id) ON DELETE CASCADE,
  CONSTRAINT fk_cf_student FOREIGN KEY (student_id) REFERENCES users(id)   ON DELETE CASCADE,
  CONSTRAINT fk_cf_by      FOREIGN KEY (created_by) REFERENCES users(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO schema_version (version) VALUES (69) ON DUPLICATE KEY UPDATE version = version;

-- v70: instructor clock-in + online reconciliation (PDF §10-11, 14). A late
-- clock-in feeds the accountability ledger; meeting_reconciliation reconciles
-- the portal clock-in against Meet (CSV import) or Jitsi; MAM clock-in stays
-- the official record where provider data isn't available.
CREATE TABLE IF NOT EXISTS instructor_clockins (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  session_id    INT         NOT NULL,
  instructor_id INT         NOT NULL,
  clock_in_at   DATETIME    NULL,
  clock_out_at  DATETIME    NULL,
  status        VARCHAR(16) NOT NULL DEFAULT 'present',
  minutes_late  INT         NOT NULL DEFAULT 0,
  source        VARCHAR(16) NOT NULL DEFAULT 'portal',
  created_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY idx_clockin_unique (session_id, instructor_id),
  CONSTRAINT fk_ci_session FOREIGN KEY (session_id)    REFERENCES class_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_ci_instr   FOREIGN KEY (instructor_id) REFERENCES users(id)          ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meeting_reconciliation (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  session_id   INT          NOT NULL,
  subject_type VARCHAR(16)  NOT NULL DEFAULT 'student',
  subject_id   INT          NULL,
  display_name VARCHAR(190) NULL,
  join_at      DATETIME     NULL,
  leave_at     DATETIME     NULL,
  minutes      INT          NULL,
  provider     VARCHAR(16)  NOT NULL DEFAULT 'meet',
  source       VARCHAR(16)  NOT NULL DEFAULT 'import',
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_meetrecon_session (session_id),
  CONSTRAINT fk_mr_session FOREIGN KEY (session_id) REFERENCES class_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_mr_subject FOREIGN KEY (subject_id) REFERENCES users(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE class_sessions ADD COLUMN meeting_provider VARCHAR(16) NULL;
INSERT INTO settings (skey, svalue) VALUES
  ('instructor_clockin_grace_min','5'),
  ('jitsi_domain','meet.jit.si'),
  ('meeting_default_provider','none')
ON DUPLICATE KEY UPDATE skey = skey;
INSERT INTO schema_version (version) VALUES (70) ON DUPLICATE KEY UPDATE version = version;

-- v71: real parent accounts (PDF §26). A parent is a user with the 'parent'
-- role; parent_links is the guardian↔student many-to-many. Additive.
CREATE TABLE IF NOT EXISTS parent_links (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  parent_id    INT          NOT NULL,
  student_id   INT          NOT NULL,
  relationship VARCHAR(32)  NOT NULL DEFAULT 'guardian',
  created_by   INT          NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY idx_parent_link_unique (parent_id, student_id),
  KEY idx_parent_link_parent (parent_id),
  KEY idx_parent_link_student (student_id),
  CONSTRAINT fk_pl_parent  FOREIGN KEY (parent_id)  REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_pl_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_pl_by      FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO schema_version (version) VALUES (71) ON DUPLICATE KEY UPDATE version = version;

-- v73: timestamped audio/video feedback (Advanced Modernization §1.1) — markers
-- pin an instructor comment to a moment in a submitted recording.
CREATE TABLE IF NOT EXISTS submission_markers (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  submission_id INT          NOT NULL,
  author_id     INT          NULL,
  at_seconds    DECIMAL(9,3) NOT NULL DEFAULT 0,
  comment       TEXT         NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_submarkers_sub (submission_id, at_seconds),
  CONSTRAINT fk_submarker_sub    FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_submarker_author FOREIGN KEY (author_id)     REFERENCES users(id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO schema_version (version) VALUES (73) ON DUPLICATE KEY UPDATE version = version;

-- v74: achievements (Advanced Modernization §3.1) — warm badges tied to real
-- progress, awarded once per (student, code).
CREATE TABLE IF NOT EXISTS achievements (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT          NOT NULL,
  code       VARCHAR(48)  NOT NULL,
  title      VARCHAR(160) NOT NULL,
  awarded_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY idx_ach_unique (student_id, code),
  CONSTRAINT fk_ach_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO schema_version (version) VALUES (74) ON DUPLICATE KEY UPDATE version = version;

-- v75: consent & safeguarding (Advanced Modernization §8.4) — per-student
-- media/photo/data consent + safeguarding contact, default-deny.
CREATE TABLE IF NOT EXISTS student_consents (
  student_id           INT PRIMARY KEY,
  media_consent        TINYINT(1)   NOT NULL DEFAULT 0,
  photo_consent        TINYINT(1)   NOT NULL DEFAULT 0,
  data_consent         TINYINT(1)   NOT NULL DEFAULT 0,
  safeguarding_contact VARCHAR(500) NULL,
  updated_by           INT          NULL,
  updated_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_consent_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_consent_by      FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO schema_version (version) VALUES (75) ON DUPLICATE KEY UPDATE version = version;

-- v76: repertoire → recital (Advanced Modernization §1.7) + assignment
-- reference recording (§1.3). Existing installs get the two assignment columns
-- via the ALTER below (fresh installs already have them in the CREATE above).
CREATE TABLE IF NOT EXISTS repertoire (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT          NOT NULL,
  title      VARCHAR(200) NOT NULL,
  composer   VARCHAR(160) NULL,
  status     VARCHAR(16)  NOT NULL DEFAULT 'learning',
  notes      VARCHAR(1000) NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rep_student (student_id, status),
  CONSTRAINT fk_rep_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE assignments
  ADD COLUMN reference_url  VARCHAR(500) NULL,
  ADD COLUMN reference_kind VARCHAR(12)  NULL;
INSERT INTO schema_version (version) VALUES (76) ON DUPLICATE KEY UPDATE version = version;

-- v77: practice challenges (Advanced Modernization §3.3) — a time-boxed personal
-- goal; progress is derived from practice_logs.
CREATE TABLE IF NOT EXISTS practice_challenges (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT          NOT NULL,
  title      VARCHAR(160) NOT NULL,
  metric     VARCHAR(16)  NOT NULL DEFAULT 'sessions',
  target     INT          NOT NULL DEFAULT 1,
  starts_on  DATE         NOT NULL,
  ends_on    DATE         NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_challenge_student (student_id, ends_on),
  CONSTRAINT fk_challenge_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO schema_version (version) VALUES (77) ON DUPLICATE KEY UPDATE version = version;

-- v78: referral program (Advanced Modernization §6.3) — a trackable code per
-- user + referral records that reward the referrer's wallet.
CREATE TABLE IF NOT EXISTS referral_codes (
  user_id    INT PRIMARY KEY,
  code       VARCHAR(32) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_refcode_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS referrals (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  referrer_id      INT NOT NULL,
  code             VARCHAR(32) NOT NULL,
  referred_name    VARCHAR(120) NULL,
  referred_email   VARCHAR(190) NULL,
  referred_user_id INT NULL,
  status           VARCHAR(16) NOT NULL DEFAULT 'pending',
  reward_cents     INT NOT NULL DEFAULT 0,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  rewarded_at      DATETIME NULL,
  KEY idx_referrals_referrer (referrer_id),
  KEY idx_referrals_status (status),
  CONSTRAINT fk_ref_referrer FOREIGN KEY (referrer_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ref_referred FOREIGN KEY (referred_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO schema_version (version) VALUES (78) ON DUPLICATE KEY UPDATE version = version;

-- v79: opt-in effort leaderboards (Advanced Modernization §3.4) — existing
-- installs get the users column via this ALTER.
ALTER TABLE users ADD COLUMN leaderboard_opt_in TINYINT(1) DEFAULT 0;
INSERT INTO schema_version (version) VALUES (79) ON DUPLICATE KEY UPDATE version = version;

-- ─────────────────────────────────────────────────────────────
-- v80 — The Recital Hall.
-- A recital is a real event with a curated running order, not a list of names.
-- Items carry a section, a duration and a sort order so the programme can
-- compute clock times, and a status of their own so a piece can be proposed,
-- accepted, declined or withdrawn without destroying the record that it was
-- offered at all. `interval_after` is the sort_order the interval follows.
CREATE TABLE IF NOT EXISTS recitals (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  slug                 VARCHAR(160) NOT NULL UNIQUE,
  title                VARCHAR(200) NOT NULL,
  subtitle             VARCHAR(200) NULL,
  venue                VARCHAR(200) NULL,
  starts_at            DATETIME NULL,
  doors_open_at        DATETIME NULL,
  interval_after       INT NULL,
  interval_min         INT NOT NULL DEFAULT 15,
  status               VARCHAR(24) NOT NULL DEFAULT 'draft',
  submissions_close_at DATETIME NULL,
  -- MySQL rejects a literal DEFAULT on TEXT (error 1101) — nullable, no default.
  description          TEXT NULL,
  programme_note       TEXT NULL,
  created_by           INT NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_recitals_status (status, starts_at),
  CONSTRAINT fk_recital_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recital_items (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  recital_id    INT NOT NULL,
  student_id    INT NOT NULL,
  repertoire_id INT NULL,
  submission_id INT NULL,
  title         VARCHAR(200) NOT NULL,
  composer      VARCHAR(160) NULL,
  arranger      VARCHAR(160) NULL,
  movement      VARCHAR(160) NULL,
  instrument    VARCHAR(80)  NULL,
  duration_min  INT NOT NULL DEFAULT 4,
  section       VARCHAR(24) NOT NULL DEFAULT 'main',
  sort_order    INT NOT NULL DEFAULT 0,
  status        VARCHAR(24) NOT NULL DEFAULT 'proposed',
  note          VARCHAR(1000) NULL,
  decided_by    INT NULL,
  decided_at    DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ritems_recital (recital_id, section, sort_order),
  KEY idx_ritems_student (student_id, status),
  CONSTRAINT fk_ritem_recital    FOREIGN KEY (recital_id)    REFERENCES recitals(id)    ON DELETE CASCADE,
  CONSTRAINT fk_ritem_student    FOREIGN KEY (student_id)    REFERENCES users(id)       ON DELETE CASCADE,
  CONSTRAINT fk_ritem_repertoire FOREIGN KEY (repertoire_id) REFERENCES repertoire(id)  ON DELETE SET NULL,
  CONSTRAINT fk_ritem_submission FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE SET NULL,
  CONSTRAINT fk_ritem_decider    FOREIGN KEY (decided_by)    REFERENCES users(id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO schema_version (version) VALUES (80) ON DUPLICATE KEY UPDATE version = version;

-- v81 — the attendance clock (PDF §10, §12, §16, §39-41). §12 defines seven
-- attendance statuses where the column admitted four, and a clock needs an OUT
-- as well as an IN. Durations are STORED, never recomputed on read: a session's
-- scheduled time can be edited afterwards and the record of what actually
-- happened must not move with it. `mode` carries §16 — onsite, online and
-- hybrid all live in the same unified academic record.
ALTER TABLE session_attendance MODIFY COLUMN status VARCHAR(24) NOT NULL;
ALTER TABLE session_attendance ADD COLUMN mode VARCHAR(16) NOT NULL DEFAULT 'onsite';
ALTER TABLE session_attendance ADD COLUMN clocked_out_at DATETIME NULL;
ALTER TABLE session_attendance ADD COLUMN duration_min INT NULL;
ALTER TABLE session_attendance ADD COLUMN minutes_late INT NOT NULL DEFAULT 0;
ALTER TABLE class_sessions ADD COLUMN mode VARCHAR(16) NOT NULL DEFAULT 'onsite';

-- §15 exception management: the ledger could waive a charge but nobody could
-- ASK for one. A request carries a reason from a fixed vocabulary, goes to an
-- authorised administrator, and every decision is logged.
CREATE TABLE IF NOT EXISTS accountability_exceptions (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  charge_id     INT NOT NULL,
  requested_by  INT NULL,
  reason_code   VARCHAR(40) NOT NULL,
  detail        VARCHAR(1000) NULL,
  status        VARCHAR(16) NOT NULL DEFAULT 'pending',
  decided_by    INT NULL,
  decided_at    DATETIME NULL,
  decision_note VARCHAR(1000) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_acct_exc_charge (charge_id),
  KEY idx_acct_exc_status (status, id),
  CONSTRAINT fk_acctexc_charge    FOREIGN KEY (charge_id)    REFERENCES accountability_charges(id) ON DELETE CASCADE,
  CONSTRAINT fk_acctexc_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_acctexc_decider   FOREIGN KEY (decided_by)   REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (skey, svalue) VALUES
  ('readiness_before_min','15'),
  ('mastery_score_enabled','1');
-- ── v82: instructor vetting records + the safeguarding gate ──────────────────
-- Stores status/type/issuing body/reference/dates and a pointer to where the
-- evidence is filed OFFLINE. Never the content of a check and never offence
-- data: under the NDPA this is sensitive personal data about the instructor.
-- UNIQUE(instructor_id, clearance_type) — re-running a check replaces the record;
-- the gate asks "is there a current one" and the audit log keeps the trail.
-- No status column: validity is derived from expires_on at read time.
CREATE TABLE IF NOT EXISTS vetting_records (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  instructor_id  INT          NOT NULL,
  clearance_type VARCHAR(32)  NOT NULL,
  issuing_body   VARCHAR(120) NULL,
  reference_no   VARCHAR(80)  NULL,
  issued_on      DATE         NOT NULL,
  expires_on     DATE         NULL,
  verified_by    INT          NULL,
  verified_at    DATETIME     NULL,
  evidence_held  VARCHAR(200) NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vetting_instr_type (instructor_id, clearance_type),
  KEY idx_vetting_expiry (expires_on),
  CONSTRAINT fk_vetting_instr    FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_vetting_verifier FOREIGN KEY (verified_by)   REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── v83: the academic calendar as DATA ───────────────────────────────────────
-- academic-session.php computes term windows from constants + a JSON setting.
-- Fine for a label, useless for a refund percentage, the withdrawal outcome or an
-- official enrolment count — those need dated rows somebody can point at.
-- CHECKs keep the deadlines in a sane order: a calendar allowing withdrawal
-- before add/drop produces refund percentages nobody can defend.
-- NB deliberately NOT named `sessions` — that is the login session store.
CREATE TABLE IF NOT EXISTS academic_terms (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  year_label          VARCHAR(16)  NOT NULL,
  code                VARCHAR(24)  NOT NULL,
  term_no             INT          NOT NULL DEFAULT 1,
  label               VARCHAR(80)  NOT NULL,
  starts_on           DATE         NOT NULL,
  ends_on             DATE         NOT NULL,
  add_drop_deadline   DATE         NULL,
  withdrawal_deadline DATE         NULL,
  census_date         DATE         NULL,
  teaching_weeks      INT          NOT NULL DEFAULT 12,
  timezone            VARCHAR(48)  NOT NULL DEFAULT 'Africa/Lagos',
  status              VARCHAR(16)  NOT NULL DEFAULT 'planned',
  notes               VARCHAR(255) NULL,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_term_year_code (year_label, code),
  KEY idx_term_dates (starts_on, ends_on),
  CONSTRAINT chk_term_status  CHECK (status IN ('planned','active','closed')),
  CONSTRAINT chk_term_span    CHECK (ends_on >= starts_on),
  CONSTRAINT chk_term_adddrop CHECK (add_drop_deadline   IS NULL OR (add_drop_deadline   >= starts_on AND add_drop_deadline   <= ends_on)),
  CONSTRAINT chk_term_withdraw CHECK (withdrawal_deadline IS NULL OR (withdrawal_deadline >= starts_on AND withdrawal_deadline <= ends_on)),
  CONSTRAINT chk_term_census  CHECK (census_date         IS NULL OR (census_date         >= starts_on AND census_date         <= ends_on)),
  CONSTRAINT chk_term_order   CHECK (add_drop_deadline IS NULL OR withdrawal_deadline IS NULL
                                     OR withdrawal_deadline >= add_drop_deadline)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A PUBLISHED table, not a formula in code: a family is entitled to read the
-- percentage before committing, and an auditor to see the one that applied.
CREATE TABLE IF NOT EXISTS term_refund_schedule (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  term_id   INT NOT NULL,
  week_from INT NOT NULL,
  week_to   INT NOT NULL,
  percent   INT NOT NULL,
  UNIQUE KEY uq_refund_term_week (term_id, week_from),
  CONSTRAINT chk_refund_weeks   CHECK (week_to >= week_from),
  CONSTRAINT chk_refund_percent CHECK (percent BETWEEN 0 AND 100),
  CONSTRAINT fk_refund_term FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS term_closures (
  id      INT AUTO_INCREMENT PRIMARY KEY,
  term_id INT NOT NULL,
  kind    VARCHAR(16)  NOT NULL DEFAULT 'holiday',
  label   VARCHAR(120) NOT NULL,
  from_on DATE NOT NULL,
  to_on   DATE NOT NULL,
  KEY idx_closure_term (term_id, from_on),
  CONSTRAINT chk_closure_kind CHECK (kind IN ('holiday','break','exam','makeup')),
  CONSTRAINT chk_closure_span CHECK (to_on >= from_on),
  CONSTRAINT fk_closure_term FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only SNAPSHOT. "Enrolment as of the census date" must not change when
-- somebody withdraws in week nine; the UNIQUE key is what enforces that.
CREATE TABLE IF NOT EXISTS term_census (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  term_id        INT NOT NULL,
  taken_on       DATE NOT NULL,
  enrolled       INT NOT NULL DEFAULT 0,
  active_cohorts INT NOT NULL DEFAULT 0,
  detail_json    TEXT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_census_term (term_id),
  CONSTRAINT fk_census_term FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_version (version) VALUES (83) ON DUPLICATE KEY UPDATE version = version;

-- ─────────────────────────────────────────────────────────────────────────────
-- v84 — NDPA 2023 / GAID 2025 compliance layer.
--
-- Five distinct legal artefacts, deliberately not one 'compliance' table. What
-- is NOT here: the record of processing activities and the retention schedule
-- themselves. Those are CODE registries in `api/lib/dataprotection.php`, because
-- a register describing what the software does must be derived from the
-- software — free text in a table drifts the day a feature ships, and a drifted
-- register is a false assurance rather than a missing one.
-- ─────────────────────────────────────────────────────────────────────────────

-- The request log. Having an export button is not the compliance artefact; the
-- record of requests and whether they were answered in time is.
CREATE TABLE IF NOT EXISTS dp_requests (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  kind            VARCHAR(24)  NOT NULL,
  subject_email   VARCHAR(190) NOT NULL,
  subject_user_id INT          NULL,
  channel         VARCHAR(24)  NOT NULL DEFAULT 'email',
  -- The clock runs from RECEIPT, not from when somebody logged it. Backdating
  -- a late-logged request is the point, not an abuse.
  received_at     DATETIME     NOT NULL,
  acknowledged_at DATETIME     NULL,
  closed_at       DATETIME     NULL,
  outcome         VARCHAR(24)  NULL,
  refusal_reason  TEXT         NULL,
  detail          TEXT         NULL,
  handled_by      INT          NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dpreq_open  (closed_at, received_at),
  KEY idx_dpreq_email (subject_email),
  CONSTRAINT chk_dpreq_kind CHECK (kind IN ('access','erasure','rectification','portability','objection','restriction','withdraw_consent','snag')),
  CONSTRAINT chk_dpreq_outcome CHECK (outcome IS NULL OR outcome IN ('fulfilled','partially_fulfilled','refused','withdrawn')),
  CONSTRAINT chk_dpreq_ack   CHECK (acknowledged_at IS NULL OR acknowledged_at >= received_at),
  CONSTRAINT chk_dpreq_close CHECK (closed_at       IS NULL OR closed_at       >= received_at),
  -- A closed request must say how it ended; a refusal must say why.
  CONSTRAINT chk_dpreq_done   CHECK (closed_at IS NULL OR outcome IS NOT NULL),
  CONSTRAINT chk_dpreq_refuse CHECK (outcome IS NULL OR outcome <> 'refused' OR refusal_reason IS NOT NULL),
  CONSTRAINT fk_dpreq_user  FOREIGN KEY (subject_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_dpreq_actor FOREIGN KEY (handled_by)      REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The breach register. NDPA s.40(2) starts the 72 hours at AWARENESS, not at
-- occurrence — both are stored because the gap between them is itself a finding.
CREATE TABLE IF NOT EXISTS dp_breaches (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  reference            VARCHAR(48)  NOT NULL,
  occurred_at          DATETIME     NULL,
  discovered_at        DATETIME     NOT NULL,
  nature               TEXT         NOT NULL,
  data_categories      TEXT         NOT NULL,
  subject_categories   VARCHAR(255) NULL,
  subjects_affected    INT          NULL,
  risk_level           VARCHAR(16)  NOT NULL DEFAULT 'unknown',
  containment          TEXT         NULL,
  ndpc_notified_at     DATETIME     NULL,
  ndpc_reference       VARCHAR(120) NULL,
  subjects_notified_at DATETIME     NULL,
  no_notify_reason     TEXT         NULL,
  lessons              TEXT         NULL,
  status               VARCHAR(16)  NOT NULL DEFAULT 'open',
  recorded_by          INT          NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dpbreach_ref (reference),
  KEY idx_dpbreach_clock (discovered_at, ndpc_notified_at),
  CONSTRAINT chk_dpbreach_risk   CHECK (risk_level IN ('unknown','low','medium','high')),
  CONSTRAINT chk_dpbreach_status CHECK (status IN ('open','contained','closed')),
  CONSTRAINT chk_dpbreach_order  CHECK (occurred_at IS NULL OR occurred_at <= discovered_at),
  -- A closed high-risk breach must either have told the subjects (s.40(3)) or
  -- say why not. "We decided not to" is defensible; silence is not.
  CONSTRAINT chk_dpbreach_notify CHECK (risk_level <> 'high' OR subjects_notified_at IS NOT NULL
                                        OR no_notify_reason IS NOT NULL OR status <> 'closed'),
  CONSTRAINT fk_dpbreach_actor FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The processor / DPA register. Seeded from the integrations the code actually
-- has, and the admin screen shows which of them are CONFIGURED right now — an
-- aspirational register is the commonest kind.
CREATE TABLE IF NOT EXISTS dp_processors (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  key_name       VARCHAR(48)  NOT NULL,
  name           VARCHAR(120) NOT NULL,
  role           VARCHAR(24)  NOT NULL DEFAULT 'processor',
  purpose        VARCHAR(255) NULL,
  country        VARCHAR(64)  NULL,
  dpa_signed_on  DATE         NULL,
  dpa_reference  VARCHAR(120) NULL,
  sub_processors TEXT         NULL,
  transfer_basis VARCHAR(255) NULL,
  review_due_on  DATE         NULL,
  status         VARCHAR(16)  NOT NULL DEFAULT 'active',
  notes          TEXT         NULL,
  updated_at     DATETIME     NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dpproc_key (key_name),
  CONSTRAINT chk_dpproc_role   CHECK (role IN ('processor','joint_controller','controller')),
  CONSTRAINT chk_dpproc_status CHECK (status IN ('active','suspended','retired'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DPIA records.
CREATE TABLE IF NOT EXISTS dp_assessments (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  title          VARCHAR(190) NOT NULL,
  scope          TEXT         NOT NULL,
  trigger_reason TEXT         NULL,
  necessity      TEXT         NULL,
  risks          TEXT         NULL,
  mitigations    TEXT         NULL,
  residual_risk  VARCHAR(16)  NOT NULL DEFAULT 'unknown',
  decision       VARCHAR(32)  NOT NULL DEFAULT 'draft',
  assessed_on    DATE         NULL,
  assessed_by    INT          NULL,
  review_due_on  DATE         NULL,
  consulted_ndpc TINYINT(1)   NOT NULL DEFAULT 0,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_dpdpia_risk     CHECK (residual_risk IN ('unknown','low','medium','high')),
  CONSTRAINT chk_dpdpia_decision CHECK (decision IN ('draft','proceed','proceed_with_controls','do_not_proceed')),
  CONSTRAINT fk_dpdpia_actor FOREIGN KEY (assessed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Annotations over the CODE registries. `ref_key` names an entry in
-- mam_dp_purposes() or mam_dp_retention(); `keep_days` applies only to
-- kind='retention' and may only LENGTHEN the shipped period (enforced in PHP —
-- a shortened one would delete lawfully-held records on somebody's guess).
CREATE TABLE IF NOT EXISTS dp_register_notes (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  kind        VARCHAR(16)  NOT NULL,
  ref_key     VARCHAR(64)  NOT NULL,
  keep_days   INT          NULL,
  notes       TEXT         NULL,
  reviewed_on DATE         NULL,
  reviewed_by INT          NULL,
  updated_at  DATETIME     NULL,
  UNIQUE KEY uq_dpnote_ref (kind, ref_key),
  CONSTRAINT chk_dpnote_kind CHECK (kind IN ('purpose','retention')),
  CONSTRAINT chk_dpnote_keep CHECK (keep_days IS NULL OR keep_days >= 0),
  CONSTRAINT fk_dpnote_actor FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- v85 — the ratified termly report (guardian redesign §3.3)
--
-- A published academic record a family sees only after a board has ratified it.
-- NOT `progress_reports`, which is the instructor's two-hour post-session note.
--
-- `raw_score` deliberately has no column anywhere here. The band IS the stored
-- value, with a CHECK that it is a real band rather than a decimal wearing a
-- range's clothes. Coverage is NOT NULL because it prints on the face of the
-- report, and an unevidenced criterion is a row with a NULL pct — never a zero.
--
-- MySQL note: the SQLite mirror uses expression indexes (COALESCE) for the
-- one-report-per-slot rule. MySQL 8 needs generated columns for that, and a
-- functional UNIQUE would have to be spelled differently on each engine — so
-- the slot rule is enforced in PHP (mam_report_slot_taken) and the index here
-- is a plain non-unique one over the same columns. The other UNIQUEs are over
-- NOT NULL columns and translate directly.
CREATE TABLE IF NOT EXISTS termly_reports (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  student_id          INT          NOT NULL,
  cohort_id           INT          NULL,
  course_id           INT          NULL,
  level_id            INT          NULL,
  term_id             INT          NULL,
  -- 190, not 64: the natural source is academic_terms.label (VARCHAR(80)),
  -- often composed with a year label, and under STRICT_ALL_TABLES MySQL raises
  -- 1406 where SQLite's TEXT silently accepts. 190 also stays inside the
  -- utf8mb4 index-prefix limit.
  term_label          VARCHAR(190) NOT NULL,
  status              VARCHAR(16)  NOT NULL DEFAULT 'draft',
  band_low            INT          NULL,
  band_high           INT          NULL,
  -- NOT NULL and NO DEFAULT: `NOT NULL DEFAULT 0` constrains nothing, because
  -- the default satisfies it. Coverage prints on the face of the report.
  coverage_evidenced  INT          NOT NULL,
  coverage_total      INT          NOT NULL,
  coverage_weight_pct INT          NOT NULL,
  instructor_id       INT          NULL,
  instructor_comment  TEXT         NULL,
  attendance_summary  TEXT         NULL,
  ratified_by         INT          NULL,
  ratified_at         DATETIME     NULL,
  ratified_note       TEXT         NULL,
  withdrawn_at        DATETIME     NULL,
  withdrawn_reason    TEXT         NULL,
  created_by          INT          NULL,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NULL,
  KEY idx_treport_student (student_id, status),
  KEY idx_treport_cohort  (cohort_id, status),
  -- Lookup only, on BOTH engines: the one-live-report-per-slot rule is
  -- mam_report_slot_taken()'s. See the SQLite migration for the three ways a
  -- UNIQUE index states it wrongly (a withdrawn report keeps the slot forever, a
  -- renamed session opens a second one, and COALESCE turns SET NULL into an abort).
  KEY idx_treport_slot    (student_id, term_id, term_label, course_id, level_id, status),
  CONSTRAINT chk_treport_status CHECK (status IN ('draft','moderating','ratified','withdrawn')),
  -- Ten points wide, or clamped at the top of the scale — which is the only
  -- reason band_high may EQUAL band_low. `band_high > band_low` refused the
  -- correct (100, 100) a perfect score produces, and admitted (70, 71) and
  -- (0, 100), neither of which is a band. INT already refuses 74.3 here under
  -- the STRICT_ALL_TABLES sql_mode mam_db() sets, but the CAST is kept so the
  -- constraint reads identically to the SQLite one, where it is load-bearing.
  CONSTRAINT chk_treport_band CHECK ((band_low IS NULL AND band_high IS NULL)
        OR (band_low  = CAST(band_low  AS SIGNED)
        AND band_high = CAST(band_high AS SIGNED)
        AND band_low >= 0 AND band_high <= 100
        AND band_high >= band_low
        AND band_high - band_low <= 9
        AND (band_high - band_low = 9 OR band_high = 100))),
  CONSTRAINT chk_treport_cov  CHECK (coverage_evidenced >= 0 AND coverage_total >= 0
        AND coverage_evidenced <= coverage_total),
  CONSTRAINT chk_treport_covw CHECK (coverage_weight_pct = CAST(coverage_weight_pct AS SIGNED)
        AND coverage_weight_pct >= 0 AND coverage_weight_pct <= 100),
  CONSTRAINT chk_treport_rat  CHECK (status <> 'ratified'
        OR (ratified_by IS NOT NULL AND ratified_at IS NOT NULL)),
  CONSTRAINT chk_treport_wdr  CHECK (status <> 'withdrawn' OR withdrawn_at IS NOT NULL),
  CONSTRAINT fk_treport_student FOREIGN KEY (student_id)    REFERENCES users(id)          ON DELETE CASCADE,
  CONSTRAINT fk_treport_cohort  FOREIGN KEY (cohort_id)     REFERENCES cohorts(id)        ON DELETE SET NULL,
  CONSTRAINT fk_treport_course  FOREIGN KEY (course_id)     REFERENCES courses(id)        ON DELETE SET NULL,
  CONSTRAINT fk_treport_level   FOREIGN KEY (level_id)      REFERENCES levels(id)         ON DELETE SET NULL,
  CONSTRAINT fk_treport_term    FOREIGN KEY (term_id)       REFERENCES academic_terms(id) ON DELETE SET NULL,
  CONSTRAINT fk_treport_instr   FOREIGN KEY (instructor_id) REFERENCES users(id)          ON DELETE SET NULL,
  -- NOT `ON DELETE SET NULL`, and MariaDB is what forced the issue: it refuses
  -- (error 1901) a CHECK that references a column a referential action can
  -- null, because the cascade would silently break the constraint. Here the
  -- CHECK is `chk_treport_rat` — a ratified report names its ratifier — and the
  -- domain answer is the same as the engine's: an academic mark must stay
  -- attributable, so a user who has ratified a report cannot be DELETED. This
  -- codebase closes accounts (`status='closed'`) and keeps a tombstone row
  -- rather than deleting people, so RESTRICT costs nothing operationally.
  -- ⚠️ SQLite and MySQL 8 both ACCEPT the SET NULL form, so this failure is
  -- invisible until a MariaDB host applies the file — where the whole CREATE
  -- TABLE fails and all four v85 tables are simply absent while schema_version
  -- still reads 88.
  CONSTRAINT fk_treport_ratby   FOREIGN KEY (ratified_by)   REFERENCES users(id),
  CONSTRAINT fk_treport_creator FOREIGN KEY (created_by)    REFERENCES users(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The competency is a CODE, not a keyword match on the label. v1 matched by
-- string and silently reclassified anything an instructor renamed.
CREATE TABLE IF NOT EXISTS termly_report_criteria (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  report_id           INT          NOT NULL,
  competency_code     VARCHAR(64)  NOT NULL,
  label               VARCHAR(190) NOT NULL,
  weight_pct          INT          NOT NULL DEFAULT 0,
  level               VARCHAR(16)  NOT NULL,
  pct                 INT          NULL,
  grade_label         VARCHAR(48)  NULL,
  first_assessed_week INT          NULL,
  note                TEXT         NULL,
  sort_order          INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_tcrit_slot (report_id, competency_code),
  KEY idx_tcrit_report (report_id, sort_order),
  CONSTRAINT chk_tcrit_level  CHECK (level IN ('strong','secure','working','insufficient')),
  CONSTRAINT chk_tcrit_weight CHECK (weight_pct = CAST(weight_pct AS SIGNED)
        AND weight_pct >= 0 AND weight_pct <= 100),
  CONSTRAINT chk_tcrit_pct    CHECK (pct IS NULL
        OR (pct = CAST(pct AS SIGNED) AND pct >= 0 AND pct <= 100)),
  CONSTRAINT chk_tcrit_evid   CHECK ((level =  'insufficient' AND pct IS NULL)
        OR (level <> 'insufficient' AND pct IS NOT NULL)),
  CONSTRAINT fk_tcrit_report FOREIGN KEY (report_id) REFERENCES termly_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The moderation sample. Per-CRITERION, because "a short sample" is a count of
-- second-marked criteria and a per-report row could not express it.
CREATE TABLE IF NOT EXISTS report_moderations (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  report_id       INT          NOT NULL,
  competency_code VARCHAR(64)  NOT NULL,
  moderator_id    INT          NULL,
  agreed          TINYINT(1)   NOT NULL DEFAULT 1,
  moderator_level VARCHAR(16)  NULL,
  note            TEXT         NULL,
  moderated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rmod_report (report_id),
  -- UNIQUE with a nullable column, matching the SQLite index: NULLs are
  -- distinct, so two unattributed second-markers are two rows and the sample
  -- count mam_report_ratify() gates on is the real one.
  UNIQUE KEY uq_rmod_slot (report_id, competency_code, moderator_id),
  CONSTRAINT chk_rmod_agreed CHECK (agreed IN (0,1)),
  CONSTRAINT chk_rmod_level  CHECK (moderator_level IS NULL
        OR moderator_level IN ('strong','secure','working','insufficient')),
  CONSTRAINT fk_rmod_report FOREIGN KEY (report_id)    REFERENCES termly_reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_rmod_actor  FOREIGN KEY (moderator_id) REFERENCES users(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Its own table rather than a status on the report, because an appeal outlives
-- the report's status: a family appeals a RATIFIED mark too, and the design
-- gives them three months to do it. An open appeal blocks ratification.
CREATE TABLE IF NOT EXISTS mark_appeals (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  report_id       INT          NULL,
  student_id      INT          NOT NULL,
  raised_by       INT          NULL,
  raised_role     VARCHAR(16)  NOT NULL DEFAULT 'parent',
  competency_code VARCHAR(64)  NULL,
  reason          TEXT         NOT NULL,
  status          VARCHAR(16)  NOT NULL DEFAULT 'open',
  outcome_note    TEXT         NULL,
  decided_by      INT          NULL,
  opened_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at       DATETIME     NULL,
  KEY idx_appeal_report (report_id, status),
  KEY idx_appeal_open   (status, opened_at),
  CONSTRAINT chk_appeal_role   CHECK (raised_role IN ('parent','student','staff')),
  CONSTRAINT chk_appeal_status CHECK (status IN ('open','upheld','declined','withdrawn')),
  -- ANY closed appeal states its outcome. `status <> 'declined'` left an UPHELD
  -- appeal free to close with no note — a mark overturned and nothing saying why.
  CONSTRAINT chk_appeal_closed CHECK (status = 'open'
        OR (closed_at IS NOT NULL AND outcome_note IS NOT NULL)),
  CONSTRAINT chk_appeal_order  CHECK (closed_at IS NULL OR closed_at >= opened_at),
  CONSTRAINT fk_appeal_report  FOREIGN KEY (report_id)  REFERENCES termly_reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_appeal_student FOREIGN KEY (student_id) REFERENCES users(id)          ON DELETE CASCADE,
  CONSTRAINT fk_appeal_raiser  FOREIGN KEY (raised_by)  REFERENCES users(id)          ON DELETE SET NULL,
  CONSTRAINT fk_appeal_decider FOREIGN KEY (decided_by) REFERENCES users(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_version (version) VALUES (85) ON DUPLICATE KEY UPDATE version = version;

-- ─────────────────────────────────────────────────────────────────────────────
-- v86 — the guardian ACCESS SCOPE (guardian redesign §3.2), enforced server-side.
--   billing  — invoices and receipts. No marks, no attendance.
--   standard — the above plus ratified reports, attendance, timetable. DEFAULT.
--   full     — the above plus consents, and can act for the child.
-- No scope reaches practice logs, teach-back recordings, or a teenager's messages
-- with their instructor: that is a refusal, not a gap. See mam_parent_scope_allows().
-- `standard` is the default so the migration cannot narrow access families
-- already have. Plain ADD COLUMN — 1060 on a re-run, which the runner tolerates.
ALTER TABLE parent_links ADD COLUMN access_scope VARCHAR(16) NOT NULL DEFAULT 'standard';
ALTER TABLE parent_links ADD COLUMN scope_set_by INT      NULL;
ALTER TABLE parent_links ADD COLUMN scope_set_at DATETIME NULL;
ALTER TABLE parent_links ADD CONSTRAINT fk_plink_scopeby FOREIGN KEY (scope_set_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE parent_links ADD CONSTRAINT chk_plink_scope CHECK (access_scope IN ('billing','standard','full'));
CREATE INDEX idx_plink_scope ON parent_links (parent_id, access_scope);
-- One link per guardian↔child pair: the scope hangs off this row, so a second row
-- would mean two different answers to "what may this guardian see".
CREATE UNIQUE INDEX uq_plink_pair ON parent_links (parent_id, student_id);
INSERT INTO schema_version (version) VALUES (86) ON DUPLICATE KEY UPDATE version = version;

-- ─────────────────────────────────────────────────────────────────────────────
-- v87 — the FEE INVOICE, and the four things that are not one discount
-- (guardian redesign §3.4). See the SQLite migration in api/lib/db.php for the
-- reasoning; the invariants below are the same ones, spelled for MySQL 8.
--
-- Money is integer minor units + an ISO currency + a rate frozen at issue.
-- Totals are STORED, computed once from the lines in the invoice's own currency,
-- never summed from per-line conversions — rounding eleven lines and adding them
-- differs from converting the sum, and a one-naira gap reads as a pricing error.
--
-- The four kinds of aid are four MECHANISMS: a waiver reduces the LINE and is
-- never a cash entry; bursary and scholarship are GIFT AID credits against the
-- invoice; a sibling discount applies AFTER aid and is capped. The order is
-- fixed: gross → waivers → aid → sibling → total, with each stage stored so the
-- arithmetic can be shown to a family and checked by an auditor.
--
-- There is deliberately no column that suspends anything over money: an access
-- hold must never touch a child's academic record, a safeguarding file, or a
-- minor's report, and the way to keep a refusal is to leave the mechanism unbuilt.
CREATE TABLE IF NOT EXISTS fee_invoices (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  public_ref      VARCHAR(48)  NOT NULL,
  student_id      INT          NOT NULL,
  guardian_id     INT          NULL,
  term_id         INT          NULL,
  term_label      VARCHAR(190) NOT NULL,
  currency        CHAR(3)      NOT NULL DEFAULT 'USD',
  status          VARCHAR(16)  NOT NULL DEFAULT 'draft',
  issued_on       DATE         NULL,
  due_on          DATE         NULL,
  grace_until     DATE         NULL,
  dunning_stage   INT          NOT NULL DEFAULT 0,
  dunning_last    DATETIME     NULL,
  fx_rate_usd_ngn DECIMAL(14,4) NULL,
  gross_minor     BIGINT       NOT NULL DEFAULT 0,
  waiver_minor    BIGINT       NOT NULL DEFAULT 0,
  aid_minor       BIGINT       NOT NULL DEFAULT 0,
  sibling_minor   BIGINT       NOT NULL DEFAULT 0,
  total_minor     BIGINT       NOT NULL DEFAULT 0,
  paid_minor      BIGINT       NOT NULL DEFAULT 0,
  total_usd_cents BIGINT       NULL,
  note            TEXT         NULL,
  void_reason     TEXT         NULL,
  created_by      INT          NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL,
  UNIQUE KEY uq_feeinv_ref (public_ref),
  KEY idx_feeinv_student  (student_id, status),
  KEY idx_feeinv_guardian (guardian_id, status),
  KEY idx_feeinv_due      (status, due_on),
  CONSTRAINT chk_feeinv_status CHECK (status IN ('draft','issued','part_paid','paid','void')),
  -- A negative minor amount is not a refund, it is a data error; a refund is its
  -- own payment row.
  CONSTRAINT chk_feeinv_signs CHECK (gross_minor >= 0 AND waiver_minor >= 0
        AND aid_minor >= 0 AND sibling_minor >= 0 AND total_minor >= 0 AND paid_minor >= 0),
  -- Nothing may reduce the bill below zero, and the stages must add up.
  CONSTRAINT chk_feeinv_relief CHECK (waiver_minor + aid_minor + sibling_minor <= gross_minor),
  CONSTRAINT chk_feeinv_total  CHECK (total_minor = gross_minor - waiver_minor - aid_minor - sibling_minor),
  -- Draft and VOID are both exempt: a draft raised against the wrong child is
  -- voided rather than deleted, and never had an issue date to state.
  CONSTRAINT chk_feeinv_issued CHECK (status IN ('draft','void') OR (issued_on IS NOT NULL AND due_on IS NOT NULL)),
  CONSTRAINT chk_feeinv_void   CHECK (status <> 'void' OR void_reason IS NOT NULL),
  CONSTRAINT fk_feeinv_student  FOREIGN KEY (student_id)  REFERENCES users(id)           ON DELETE CASCADE,
  CONSTRAINT fk_feeinv_guardian FOREIGN KEY (guardian_id) REFERENCES users(id)           ON DELETE SET NULL,
  CONSTRAINT fk_feeinv_term     FOREIGN KEY (term_id)     REFERENCES academic_terms(id)  ON DELETE SET NULL,
  CONSTRAINT fk_feeinv_creator  FOREIGN KEY (created_by)  REFERENCES users(id)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fee_invoice_lines (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id    INT          NOT NULL,
  kind          VARCHAR(16)  NOT NULL DEFAULT 'tuition',
  label         VARCHAR(190) NOT NULL,
  enrollment_id INT          NULL,
  cohort_id     INT          NULL,
  qty           INT          NOT NULL DEFAULT 1,
  unit_minor    BIGINT       NOT NULL DEFAULT 0,
  amount_minor  BIGINT       NOT NULL DEFAULT 0,
  -- A WAIVER reduces the line and is never a cash entry. Kept beside the amount
  -- rather than netted into it, so the invoice can show what was charged AND what
  -- was waived — which is what a family and an auditor each need.
  waiver_minor  BIGINT       NOT NULL DEFAULT 0,
  waiver_reason TEXT         NULL,
  sort_order    INT          NOT NULL DEFAULT 0,
  KEY idx_feeline_invoice (invoice_id, sort_order),
  CONSTRAINT chk_feeline_kind   CHECK (kind IN ('tuition','materials','exam','registration','recital','late','other')),
  CONSTRAINT chk_feeline_qty    CHECK (qty > 0),
  CONSTRAINT chk_feeline_signs  CHECK (unit_minor >= 0 AND amount_minor >= 0 AND waiver_minor >= 0),
  CONSTRAINT chk_feeline_waiver CHECK (waiver_minor <= amount_minor),
  CONSTRAINT chk_feeline_amount CHECK (amount_minor = unit_minor * qty),
  CONSTRAINT chk_feeline_why    CHECK (waiver_minor = 0 OR waiver_reason IS NOT NULL),
  CONSTRAINT fk_feeline_invoice FOREIGN KEY (invoice_id)    REFERENCES fee_invoices(id)        ON DELETE CASCADE,
  CONSTRAINT fk_feeline_enrol   FOREIGN KEY (enrollment_id) REFERENCES course_enrollments(id)  ON DELETE SET NULL,
  CONSTRAINT fk_feeline_cohort  FOREIGN KEY (cohort_id)     REFERENCES cohorts(id)             ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The AWARD — the academy's decision that a student is entitled to something.
-- Separate from its application to any one invoice: an award spans a term.
CREATE TABLE IF NOT EXISTS fee_aid_awards (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  student_id     INT          NOT NULL,
  kind           VARCHAR(16)  NOT NULL,
  pct            DECIMAL(6,3) NULL,
  amount_minor   BIGINT       NULL,
  currency       CHAR(3)      NULL,
  term_id        INT          NULL,
  term_label     VARCHAR(190) NULL,
  basis          VARCHAR(190) NULL,
  status         VARCHAR(16)  NOT NULL DEFAULT 'active',
  starts_on      DATE         NULL,
  expires_on     DATE         NULL,
  awarded_by     INT          NULL,
  awarded_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at     DATETIME     NULL,
  revoked_reason TEXT         NULL,
  note           TEXT         NULL,
  KEY idx_aid_student (student_id, status, kind),
  CONSTRAINT chk_aid_kind   CHECK (kind IN ('bursary','scholarship','waiver','sibling')),
  CONSTRAINT chk_aid_status CHECK (status IN ('active','spent','expired','revoked')),
  -- Either a percentage or a fixed amount, never both and never neither.
  CONSTRAINT chk_aid_shape  CHECK ((pct IS NOT NULL AND amount_minor IS NULL)
        OR (pct IS NULL AND amount_minor IS NOT NULL)),
  CONSTRAINT chk_aid_pct    CHECK (pct IS NULL OR (pct > 0 AND pct <= 100)),
  CONSTRAINT chk_aid_amount CHECK (amount_minor IS NULL OR amount_minor > 0),
  CONSTRAINT chk_aid_cur    CHECK (amount_minor IS NULL OR currency IS NOT NULL),
  CONSTRAINT chk_aid_revoke CHECK (status <> 'revoked' OR (revoked_at IS NOT NULL AND revoked_reason IS NOT NULL)),
  CONSTRAINT fk_aid_student FOREIGN KEY (student_id) REFERENCES users(id)          ON DELETE CASCADE,
  CONSTRAINT fk_aid_term    FOREIGN KEY (term_id)    REFERENCES academic_terms(id) ON DELETE SET NULL,
  CONSTRAINT fk_aid_awarder FOREIGN KEY (awarded_by) REFERENCES users(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GIFT AID applied to one invoice — bursary and scholarship only. A waiver never
-- appears here (it is on the line, and is not money); a sibling discount never
-- appears here (it is a discount on the post-aid balance). Keeping the three
-- apart is what makes "how much aid did the academy actually pay out" answerable.
CREATE TABLE IF NOT EXISTS fee_invoice_credits (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id   INT          NOT NULL,
  award_id     INT          NULL,
  kind         VARCHAR(16)  NOT NULL,
  amount_minor BIGINT       NOT NULL,
  currency     CHAR(3)      NOT NULL,
  note         TEXT         NULL,
  applied_by   INT          NULL,
  applied_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_feecredit_invoice (invoice_id),
  -- One application of an award per invoice: applying one award twice is the
  -- commonest way a double-submitted form gives away the aid pool.
  UNIQUE KEY uq_feecredit_award (invoice_id, award_id),
  CONSTRAINT chk_feecredit_kind   CHECK (kind IN ('bursary','scholarship')),
  CONSTRAINT chk_feecredit_amount CHECK (amount_minor > 0),
  CONSTRAINT fk_feecredit_invoice FOREIGN KEY (invoice_id) REFERENCES fee_invoices(id)   ON DELETE CASCADE,
  CONSTRAINT fk_feecredit_award   FOREIGN KEY (award_id)   REFERENCES fee_aid_awards(id) ON DELETE SET NULL,
  CONSTRAINT fk_feecredit_actor   FOREIGN KEY (applied_by) REFERENCES users(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Pay in 3 instalments instead". The plan does not change the total; it changes
-- when each part falls due, so the instalments must SUM to the invoice total
-- exactly and the remainder lands on the FIRST one rather than being rounded away.
CREATE TABLE IF NOT EXISTS fee_payment_plans (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id    INT          NOT NULL,
  instalments   INT          NOT NULL,
  status        VARCHAR(16)  NOT NULL DEFAULT 'active',
  requested_by  INT          NULL,
  approved_by   INT          NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cancelled_at  DATETIME     NULL,
  cancel_reason TEXT         NULL,
  KEY idx_feeplan_invoice (invoice_id, status),
  CONSTRAINT chk_feeplan_status CHECK (status IN ('active','completed','cancelled')),
  CONSTRAINT chk_feeplan_n      CHECK (instalments >= 2 AND instalments <= 12),
  CONSTRAINT chk_feeplan_cancel CHECK (status <> 'cancelled' OR (cancelled_at IS NOT NULL AND cancel_reason IS NOT NULL)),
  CONSTRAINT fk_feeplan_invoice   FOREIGN KEY (invoice_id)   REFERENCES fee_invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_feeplan_requester FOREIGN KEY (requested_by) REFERENCES users(id)        ON DELETE SET NULL,
  CONSTRAINT fk_feeplan_approver  FOREIGN KEY (approved_by)  REFERENCES users(id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fee_plan_instalments (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  plan_id      INT          NOT NULL,
  seq          INT          NOT NULL,
  due_on       DATE         NOT NULL,
  amount_minor BIGINT       NOT NULL,
  status       VARCHAR(16)  NOT NULL DEFAULT 'pending',
  paid_at      DATETIME     NULL,
  payment_id   INT          NULL,
  UNIQUE KEY uq_feeinst_seq (plan_id, seq),
  KEY idx_feeinst_due (status, due_on),
  CONSTRAINT chk_feeinst_status CHECK (status IN ('pending','paid','waived')),
  CONSTRAINT chk_feeinst_seq    CHECK (seq >= 1),
  CONSTRAINT chk_feeinst_amount CHECK (amount_minor > 0),
  CONSTRAINT chk_feeinst_paid   CHECK (status <> 'paid' OR paid_at IS NOT NULL),
  CONSTRAINT fk_feeinst_plan    FOREIGN KEY (plan_id)    REFERENCES fee_payment_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_feeinst_payment FOREIGN KEY (payment_id) REFERENCES payments(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A bursary is assessed on a DECLARED INCOME BAND, never a written case for
-- hardship: a written case rewards the family best able to write one, asks a
-- parent to describe their circumstances to their child's school, and gives an
-- assessor room to be inconsistent. There is deliberately no free-text hardship
-- column — `note` is the ASSESSOR's and is not shown to the family — and no
-- column linking this to an admission decision, because applying must never
-- affect one and the way to keep that true is to leave it unrepresentable.
CREATE TABLE IF NOT EXISTS bursary_applications (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  student_id            INT          NOT NULL,
  guardian_id           INT          NULL,
  term_id               INT          NULL,
  term_label            VARCHAR(190) NULL,
  income_band           VARCHAR(32)  NOT NULL,
  household_size        INT          NULL,
  dependants_in_academy INT          NOT NULL DEFAULT 1,
  status                VARCHAR(16)  NOT NULL DEFAULT 'submitted',
  awarded_pct           DECIMAL(6,3) NULL,
  award_id              INT          NULL,
  decided_by            INT          NULL,
  decided_at            DATETIME     NULL,
  outcome_note          TEXT         NULL,
  note                  TEXT         NULL,
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_bursary_student (student_id, status),
  KEY idx_bursary_queue   (status, created_at),
  CONSTRAINT chk_bursary_status CHECK (status IN ('submitted','assessing','awarded','declined','withdrawn')),
  CONSTRAINT chk_bursary_house  CHECK (household_size IS NULL OR household_size >= 1),
  CONSTRAINT chk_bursary_deps   CHECK (dependants_in_academy >= 1),
  CONSTRAINT chk_bursary_pct    CHECK (awarded_pct IS NULL OR (awarded_pct > 0 AND awarded_pct <= 100)),
  -- A decision states its outcome and names who made it, whichever way it went.
  CONSTRAINT chk_bursary_decide CHECK (status IN ('submitted','assessing')
        OR (decided_at IS NOT NULL AND outcome_note IS NOT NULL)),
  CONSTRAINT chk_bursary_award  CHECK (status <> 'awarded' OR awarded_pct IS NOT NULL),
  CONSTRAINT fk_bursary_student  FOREIGN KEY (student_id)  REFERENCES users(id)           ON DELETE CASCADE,
  CONSTRAINT fk_bursary_guardian FOREIGN KEY (guardian_id) REFERENCES users(id)           ON DELETE SET NULL,
  CONSTRAINT fk_bursary_term     FOREIGN KEY (term_id)     REFERENCES academic_terms(id)  ON DELETE SET NULL,
  CONSTRAINT fk_bursary_award    FOREIGN KEY (award_id)    REFERENCES fee_aid_awards(id)  ON DELETE SET NULL,
  CONSTRAINT fk_bursary_decider  FOREIGN KEY (decided_by)  REFERENCES users(id)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_version (version) VALUES (87) ON DUPLICATE KEY UPDATE version = version;

-- ─────────────────────────────────────────────────────────────────────────────
-- v88 — guardian↔staff THREADS, how the academy reaches a family, and the
-- make-up credit ledger. See the SQLite migration for the reasoning.
--
-- The existing `messages` table is the PUBLIC CONTACT FORM and cannot be a
-- thread. A STUDENT is never a participant here: no guardian scope reaches a
-- teenager's messages with their instructor, and the cheapest breach of that
-- would be adding a guardian to the student's own thread — so the participant
-- role CHECK admits only adult roles.
CREATE TABLE IF NOT EXISTS guardian_threads (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  student_id      INT          NOT NULL,
  subject         VARCHAR(190) NOT NULL,
  kind            VARCHAR(16)  NOT NULL DEFAULT 'general',
  status          VARCHAR(16)  NOT NULL DEFAULT 'open',
  opened_by       INT          NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_message_at DATETIME     NULL,
  closed_at       DATETIME     NULL,
  KEY idx_gthread_student (student_id, status, last_message_at),
  CONSTRAINT chk_gthread_kind   CHECK (kind IN ('general','academic','money','safeguarding','timetable')),
  CONSTRAINT chk_gthread_status CHECK (status IN ('open','closed')),
  CONSTRAINT chk_gthread_closed CHECK (status = 'open' OR closed_at IS NOT NULL),
  CONSTRAINT fk_gthread_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_gthread_opener  FOREIGN KEY (opened_by)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guardian_thread_participants (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  thread_id    INT          NOT NULL,
  user_id      INT          NOT NULL,
  role         VARCHAR(16)  NOT NULL,
  last_read_at DATETIME     NULL,
  added_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_gpart_pair (thread_id, user_id),
  KEY idx_gpart_user (user_id),
  -- No 'student'. See the note above; this is a refusal, not an omission.
  CONSTRAINT chk_gpart_role CHECK (role IN ('guardian','instructor','office','admin')),
  CONSTRAINT fk_gpart_thread FOREIGN KEY (thread_id) REFERENCES guardian_threads(id) ON DELETE CASCADE,
  CONSTRAINT fk_gpart_user   FOREIGN KEY (user_id)   REFERENCES users(id)            ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guardian_thread_messages (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  thread_id   INT          NOT NULL,
  author_id   INT          NULL,
  -- Kept because author_id goes NULL when a staff account is deleted and a
  -- family's thread must still make sense afterwards.
  author_name VARCHAR(190) NOT NULL,
  author_role VARCHAR(16)  NOT NULL,
  body        TEXT         NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_gmsg_thread (thread_id, created_at),
  CONSTRAINT chk_gmsg_role   CHECK (author_role IN ('guardian','instructor','office','admin')),
  CONSTRAINT fk_gmsg_thread  FOREIGN KEY (thread_id) REFERENCES guardian_threads(id) ON DELETE CASCADE,
  CONSTRAINT fk_gmsg_author  FOREIGN KEY (author_id) REFERENCES users(id)            ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per guardian. No per-topic column, deliberately: there is nowhere to
-- record "mute safeguarding", because anything about safeguarding or money
-- reaches a guardian on every channel the academy can use regardless of these
-- toggles. That rule lives in mam_guardian_channels().
CREATE TABLE IF NOT EXISTS guardian_channel_prefs (
  user_id    INT        PRIMARY KEY,
  whatsapp   TINYINT(1) NOT NULL DEFAULT 1,
  email      TINYINT(1) NOT NULL DEFAULT 1,
  sms        TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_gpref_flags CHECK (whatsapp IN (0,1) AND email IN (0,1) AND sms IN (0,1)),
  CONSTRAINT fk_gpref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 24 hours' notice earns a credit · five a year · non-transferable (student_id is
-- on the row and there is no transfer column) · they expire (expires_on NOT NULL,
-- because a credit with no expiry is a liability the academy can never close a
-- year on) · no cash in lieu (there is deliberately no money column, no currency
-- and no payments link) · and a teacher cancellation ALWAYS credits and NEVER
-- counts against the five, which the last CHECK enforces rather than trusting a
-- caller not to pass the default.
CREATE TABLE IF NOT EXISTS makeup_credits (
  id                       INT AUTO_INCREMENT PRIMARY KEY,
  student_id               INT          NOT NULL,
  term_id                  INT          NULL,
  year_label               VARCHAR(32)  NOT NULL,
  reason                   VARCHAR(24)  NOT NULL,
  source_session_id        INT          NULL,
  notice_hours             DECIMAL(8,2) NULL,
  counts_against_allowance TINYINT(1)   NOT NULL DEFAULT 1,
  status                   VARCHAR(16)  NOT NULL DEFAULT 'available',
  expires_on               DATE         NOT NULL,
  spent_session_id         INT          NULL,
  spent_at                 DATETIME     NULL,
  granted_by               INT          NULL,
  granted_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_reason           TEXT         NULL,
  note                     TEXT         NULL,
  KEY idx_makeup_student (student_id, status, expires_on),
  KEY idx_makeup_year    (student_id, year_label, counts_against_allowance),
  CONSTRAINT chk_makeup_reason  CHECK (reason IN ('guardian_notice','teacher_cancellation','academy_closure','goodwill')),
  CONSTRAINT chk_makeup_status  CHECK (status IN ('available','spent','expired','revoked')),
  CONSTRAINT chk_makeup_counts  CHECK (counts_against_allowance IN (0,1)),
  CONSTRAINT chk_makeup_notice  CHECK (notice_hours IS NULL OR notice_hours >= 0),
  CONSTRAINT chk_makeup_spent   CHECK (status <> 'spent' OR spent_at IS NOT NULL),
  CONSTRAINT chk_makeup_revoke  CHECK (status <> 'revoked' OR revoked_reason IS NOT NULL),
  -- Only a GUARDIAN cancellation may cost an allowance slot: the allowance limits
  -- how often a FAMILY cancels, so anything the academy initiates is free by
  -- construction rather than by a caller remembering.
  CONSTRAINT chk_makeup_free    CHECK (reason = 'guardian_notice' OR counts_against_allowance = 0),
  CONSTRAINT fk_makeup_student FOREIGN KEY (student_id)        REFERENCES users(id)           ON DELETE CASCADE,
  CONSTRAINT fk_makeup_term    FOREIGN KEY (term_id)           REFERENCES academic_terms(id)  ON DELETE SET NULL,
  CONSTRAINT fk_makeup_src     FOREIGN KEY (source_session_id) REFERENCES class_sessions(id)  ON DELETE SET NULL,
  CONSTRAINT fk_makeup_spent   FOREIGN KEY (spent_session_id)  REFERENCES class_sessions(id)  ON DELETE SET NULL,
  CONSTRAINT fk_makeup_grantor FOREIGN KEY (granted_by)        REFERENCES users(id)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_version (version) VALUES (88) ON DUPLICATE KEY UPDATE version = version;

-- ─────────────────────────────────────────────────────────────
-- v89 — role columns from ENUM to VARCHAR.
--
-- Found by applying this file to MariaDB 10.11 and running the suite against
-- it: `users.role` was ENUM('admin','instructor','student') and the application
-- writes 'parent' for every guardian, so under STRICT_ALL_TABLES the insert
-- raised 1265 and the guardian portal was unusable on MySQL. SQLite's TEXT
-- accepted it, which is why the whole layer shipped without anybody noticing.
--
-- MODIFY is idempotent in effect: re-running it on a VARCHAR column is a no-op
-- rewrite, and on the ENUM it converts every existing row's label to the same
-- string. Existing data is preserved either way.
-- ─────────────────────────────────────────────────────────────
ALTER TABLE users      MODIFY role VARCHAR(20) NOT NULL;
ALTER TABLE user_roles MODIFY role VARCHAR(20) NOT NULL;
INSERT INTO schema_version (version) VALUES (89) ON DUPLICATE KEY UPDATE version = version;

-- ─────────────────────────────────────────────────────────────
-- v90 — the instructor application becomes an ASSESSMENT.
--
-- v36 held a candidate's subjects in one free-text column
-- (`disciplines`), which nothing can join on. The teaching model is
-- `instructor_assignments(instructor_id, course_id, level_id)`, so
-- approving an application meant re-keying it, and "who can teach
-- Diploma piano" was unanswerable.
--
-- The referees table carries CONTACTS ONLY. `vetting_records`
-- deliberately stores no check content, and an applicant's own claim is
-- never a clearance: a verifier takes the reference up and records the
-- OUTCOME there. There is nowhere here to type a certificate number or
-- offence data, and that absence is the control.
--
-- Every added TEXT column is nullable with no DEFAULT — MySQL TEXT
-- cannot carry one, so NULL is read as the default in code.
-- ─────────────────────────────────────────────────────────────
ALTER TABLE instructor_applications ADD COLUMN programmes          TEXT;
ALTER TABLE instructor_applications ADD COLUMN teaching_modes      TEXT;
ALTER TABLE instructor_applications ADD COLUMN location            VARCHAR(190);
ALTER TABLE instructor_applications ADD COLUMN qualifications      TEXT;
ALTER TABLE instructor_applications ADD COLUMN teaching_philosophy TEXT;
ALTER TABLE instructor_applications ADD COLUMN availability        TEXT;
ALTER TABLE instructor_applications ADD COLUMN weekly_hours        INT;
ALTER TABLE instructor_applications ADD COLUMN earliest_start      VARCHAR(32);
ALTER TABLE instructor_applications ADD COLUMN safeguarding_ack    TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE instructor_applications ADD COLUMN policy_ack          TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE instructor_applications ADD COLUMN readiness_signal    VARCHAR(24);
ALTER TABLE instructor_applications ADD COLUMN submitted_at        DATETIME;

CREATE TABLE IF NOT EXISTS instructor_application_subjects (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  application_id INT NOT NULL,
  course_id      INT NOT NULL,
  level_id       INT NULL,
  years          INT,
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_iasubj_app (application_id),
  KEY idx_iasubj_course (course_id, level_id),
  CONSTRAINT fk_iasubj_app    FOREIGN KEY (application_id) REFERENCES instructor_applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_iasubj_course FOREIGN KEY (course_id)      REFERENCES courses(id)                 ON DELETE CASCADE,
  CONSTRAINT fk_iasubj_level  FOREIGN KEY (level_id)       REFERENCES levels(id)                  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS instructor_application_referees (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  application_id INT NOT NULL,
  name           VARCHAR(160) NOT NULL,
  relationship   VARCHAR(120),
  organisation   VARCHAR(190),
  email          VARCHAR(190),
  phone          VARCHAR(40),
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_iaref_app (application_id),
  CONSTRAINT fk_iaref_app FOREIGN KEY (application_id) REFERENCES instructor_applications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO schema_version (version) VALUES (90) ON DUPLICATE KEY UPDATE version = version;

-- v91 — money the office receives against a fee invoice (fees.php mam_fee_payment_record).
CREATE TABLE IF NOT EXISTS fee_payments (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id    INT NOT NULL,
  amount_minor  BIGINT NOT NULL,
  currency      CHAR(3) NOT NULL,
  method        VARCHAR(12) NOT NULL DEFAULT 'transfer',
  reference     VARCHAR(120),
  received_on   DATE NOT NULL,
  note          VARCHAR(500),
  status        VARCHAR(12) NOT NULL DEFAULT 'recorded',
  void_reason   VARCHAR(500),
  voided_at     DATETIME,
  recorded_by   INT NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_feepay_invoice (invoice_id, status),
  CONSTRAINT fk_feepay_invoice FOREIGN KEY (invoice_id)  REFERENCES fee_invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_feepay_by      FOREIGN KEY (recorded_by) REFERENCES users(id)        ON DELETE SET NULL,
  CONSTRAINT chk_feepay_amount CHECK (amount_minor > 0),
  CONSTRAINT chk_feepay_method CHECK (method IN ('transfer','cash','pos','card','other')),
  CONSTRAINT chk_feepay_status CHECK (status IN ('recorded','void')),
  CONSTRAINT chk_feepay_void   CHECK (status <> 'void' OR void_reason IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO schema_version (version) VALUES (91) ON DUPLICATE KEY UPDATE version = version;

-- v92 — a guardian pays a fee invoice online: the gateway charge says which
-- invoice, how much of it in the invoice's own currency, and who paid.
ALTER TABLE payments ADD COLUMN fee_invoice_id INT NULL;
ALTER TABLE payments ADD COLUMN fee_minor BIGINT NULL;
ALTER TABLE payments ADD COLUMN fee_payer_id INT NULL;
CREATE INDEX idx_pay_fee_invoice ON payments(fee_invoice_id);
INSERT INTO schema_version (version) VALUES (92) ON DUPLICATE KEY UPDATE version = version;

-- v93 — a guardian reports a bank transfer with proof; online money taken beyond
-- the balance is resolved by a person (fees.php, HANDOFF §4v).
CREATE TABLE IF NOT EXISTS fee_transfer_claims (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id      INT NOT NULL,
  parent_id       INT NULL,
  amount_minor    BIGINT NOT NULL,
  currency        CHAR(3) NOT NULL,
  payer_name      VARCHAR(160) NOT NULL,
  bank_name       VARCHAR(120) NOT NULL,
  reference       VARCHAR(120) NOT NULL,
  reference_key   VARCHAR(120) NOT NULL,
  paid_on         DATE NOT NULL,
  evidence_sha256 CHAR(64) NOT NULL,
  evidence_file   VARCHAR(160) NOT NULL,
  evidence_type   VARCHAR(40) NOT NULL,
  evidence_bytes  INT NOT NULL,
  note            VARCHAR(500),
  status          VARCHAR(12) NOT NULL DEFAULT 'pending',
  reject_reason   VARCHAR(500),
  decided_by      INT NULL,
  decided_at      DATETIME NULL,
  fee_payment_id  INT NULL,
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_feeclaim_evidence (evidence_sha256),
  KEY idx_feeclaim_invoice (invoice_id, status),
  KEY idx_feeclaim_refkey (reference_key),
  CONSTRAINT fk_feeclaim_invoice FOREIGN KEY (invoice_id)     REFERENCES fee_invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_feeclaim_parent  FOREIGN KEY (parent_id)      REFERENCES users(id)        ON DELETE SET NULL,
  CONSTRAINT fk_feeclaim_by      FOREIGN KEY (decided_by)     REFERENCES users(id)        ON DELETE SET NULL,
  CONSTRAINT fk_feeclaim_payment FOREIGN KEY (fee_payment_id) REFERENCES fee_payments(id) ON DELETE SET NULL,
  CONSTRAINT chk_feeclaim_amount CHECK (amount_minor > 0),
  CONSTRAINT chk_feeclaim_status CHECK (status IN ('pending','verified','rejected')),
  CONSTRAINT chk_feeclaim_reject CHECK (status <> 'rejected' OR reject_reason IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fee_online_unapplied (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  reference   VARCHAR(64) NOT NULL,
  invoice_id  INT NULL,
  over_minor  BIGINT NOT NULL,
  currency    CHAR(3) NOT NULL,
  status      VARCHAR(12) NOT NULL DEFAULT 'open',
  note        VARCHAR(500),
  applied_invoice_id INT NULL,
  resolved_by INT NULL,
  resolved_at DATETIME NULL,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_feeunap_ref (reference),
  CONSTRAINT fk_feeunap_invoice FOREIGN KEY (invoice_id)         REFERENCES fee_invoices(id) ON DELETE SET NULL,
  CONSTRAINT fk_feeunap_applied FOREIGN KEY (applied_invoice_id) REFERENCES fee_invoices(id) ON DELETE SET NULL,
  CONSTRAINT fk_feeunap_by      FOREIGN KEY (resolved_by)        REFERENCES users(id)        ON DELETE SET NULL,
  CONSTRAINT chk_feeunap_amount CHECK (over_minor > 0),
  CONSTRAINT chk_feeunap_status CHECK (status IN ('open','refunded','applied','dismissed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO schema_version (version) VALUES (93) ON DUPLICATE KEY UPDATE version = version;

-- v94 — member status history (with reasons) and staff notes on a person (members.php).
CREATE TABLE IF NOT EXISTS member_status_log (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NOT NULL,
  from_status VARCHAR(20) NULL,
  to_status   VARCHAR(20) NOT NULL,
  reason      VARCHAR(500) NULL,
  changed_by  INT NULL,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mstatus_user (user_id, id),
  CONSTRAINT fk_mstatus_user FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_mstatus_by   FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS member_notes (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  body       VARCHAR(2000) NOT NULL,
  author_id  INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mnotes_user (user_id, id),
  CONSTRAINT fk_mnotes_user   FOREIGN KEY (user_id)   REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_mnotes_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_mnotes_body  CHECK (CHAR_LENGTH(body) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO schema_version (version) VALUES (94) ON DUPLICATE KEY UPDATE version = version;

-- v95 — one row per members-roster bulk run, with its per-row verdicts (members.php).
CREATE TABLE IF NOT EXISTS member_bulk_runs (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  action       VARCHAR(40) NOT NULL,
  actor_id     INT NULL,
  requested    INT NOT NULL DEFAULT 0,
  applied      INT NOT NULL DEFAULT 0,
  unchanged    INT NOT NULL DEFAULT 0,
  refused      INT NOT NULL DEFAULT 0,
  results_json MEDIUMTEXT NOT NULL,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mbulk_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO schema_version (version) VALUES (95) ON DUPLICATE KEY UPDATE version = version;

-- v96 — the guardian's email on a year-round application, so the guardian can be given their own account.
ALTER TABLE applications ADD COLUMN parent_email VARCHAR(190) NULL;
INSERT INTO schema_version (version) VALUES (96) ON DUPLICATE KEY UPDATE version = version;

-- v97 — lessons (ported from NGG's curriculum): a module becomes a lesson with a
-- draft/published/retired life, questions, materials and per-student progress.
ALTER TABLE modules ADD COLUMN status VARCHAR(12) NOT NULL DEFAULT 'published';
ALTER TABLE modules ADD COLUMN summary VARCHAR(600) NULL;
ALTER TABLE modules ADD COLUMN objectives TEXT NULL;
ALTER TABLE modules ADD COLUMN duration_mins INT NULL;
ALTER TABLE modules ADD COLUMN pass_mark INT NOT NULL DEFAULT 60;
ALTER TABLE modules ADD COLUMN version INT NOT NULL DEFAULT 1;
ALTER TABLE modules ADD COLUMN published_at DATETIME NULL;
ALTER TABLE modules ADD COLUMN updated_at DATETIME NULL;
ALTER TABLE modules ADD COLUMN updated_by INT NULL;
CREATE TABLE IF NOT EXISTS module_checks (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  module_id  INT NOT NULL,
  kind       VARCHAR(12) NOT NULL,
  prompt     TEXT NOT NULL,
  options    TEXT NULL,
  answer     TEXT NULL,
  whys       TEXT NULL,
  rubric     TEXT NULL,
  points     INT NOT NULL DEFAULT 1,
  position   INT NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mchecks_module (module_id, position),
  CONSTRAINT fk_mchecks_module FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE,
  CONSTRAINT chk_mchecks_kind   CHECK (kind IN ('mcq','order','short','task','reflection','evidence')),
  CONSTRAINT chk_mchecks_points CHECK (points BETWEEN 0 AND 100),
  CONSTRAINT chk_mchecks_prompt CHECK (CHAR_LENGTH(prompt) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS module_materials (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  module_id  INT NOT NULL,
  kind       VARCHAR(16) NOT NULL DEFAULT 'link',
  title      VARCHAR(200) NOT NULL,
  url        VARCHAR(1000) NOT NULL,
  guide      VARCHAR(300) NULL,
  position   INT NOT NULL DEFAULT 1,
  status     VARCHAR(10) NOT NULL DEFAULT 'approved',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mmat_module (module_id, position),
  CONSTRAINT fk_mmat_module FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE,
  CONSTRAINT chk_mmat_status CHECK (status IN ('approved','pending','rejected'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS module_progress (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  module_id      INT NOT NULL,
  student_id     INT NOT NULL,
  status         VARCHAR(10) NOT NULL DEFAULT 'started',
  module_version INT NOT NULL DEFAULT 1,
  score          DOUBLE NULL,
  max_score      DOUBLE NULL,
  best_pct       DOUBLE NULL,
  attempts       INT NOT NULL DEFAULT 0,
  answers        MEDIUMTEXT NULL,
  marks          MEDIUMTEXT NULL,
  feedback       TEXT NULL,
  pending        TINYINT NOT NULL DEFAULT 0,
  marked_by      INT NULL,
  confirmed_by   INT NULL,
  started_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  completed_at   DATETIME NULL,
  reviewed_at    DATETIME NULL,
  updated_at     DATETIME NULL,
  UNIQUE KEY uq_mprog (module_id, student_id),
  KEY idx_mprog_student (student_id),
  KEY idx_mprog_pending (pending, module_id),
  CONSTRAINT fk_mprog_module  FOREIGN KEY (module_id)    REFERENCES modules(id) ON DELETE CASCADE,
  CONSTRAINT fk_mprog_student FOREIGN KEY (student_id)   REFERENCES users(id)   ON DELETE CASCADE,
  CONSTRAINT fk_mprog_marker  FOREIGN KEY (marked_by)    REFERENCES users(id)   ON DELETE SET NULL,
  CONSTRAINT fk_mprog_confirm FOREIGN KEY (confirmed_by) REFERENCES users(id)   ON DELETE SET NULL,
  CONSTRAINT chk_mprog_status  CHECK (status IN ('started','attempted','submitted','completed')),
  CONSTRAINT chk_mprog_pending CHECK (pending IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO schema_version (version) VALUES (97) ON DUPLICATE KEY UPDATE version = version;

-- v98 — lessons phase 2: notes, feedback, placement, exams, the deadline log; material review columns.
ALTER TABLE module_materials ADD COLUMN submitted_by INT NULL;
ALTER TABLE module_materials ADD COLUMN reviewed_by INT NULL;
ALTER TABLE module_materials ADD COLUMN reviewed_at DATETIME NULL;
ALTER TABLE module_materials ADD COLUMN review_note VARCHAR(500) NULL;
CREATE TABLE IF NOT EXISTS lesson_notes (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  module_id  INT NOT NULL,
  student_id INT NOT NULL,
  note       TEXT NULL,
  note_at    DATETIME NULL,
  saved      TINYINT NOT NULL DEFAULT 0,
  saved_at   DATETIME NULL,
  opened_at  DATETIME NULL,
  opens      INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_lnotes (module_id, student_id),
  CONSTRAINT fk_lnotes_module  FOREIGN KEY (module_id)  REFERENCES modules(id) ON DELETE CASCADE,
  CONSTRAINT fk_lnotes_student FOREIGN KEY (student_id) REFERENCES users(id)   ON DELETE CASCADE,
  CONSTRAINT chk_lnotes_saved CHECK (saved IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS lesson_feedback (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  module_id  INT NOT NULL,
  student_id INT NOT NULL,
  feel       VARCHAR(8) NOT NULL,
  body       VARCHAR(1000) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_lfb (module_id, student_id),
  CONSTRAINT fk_lfb_module  FOREIGN KEY (module_id)  REFERENCES modules(id) ON DELETE CASCADE,
  CONSTRAINT fk_lfb_student FOREIGN KEY (student_id) REFERENCES users(id)   ON DELETE CASCADE,
  CONSTRAINT chk_lfb_feel CHECK (feel IN ('got','mostly','lost'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS lesson_placements (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  course_id  INT NOT NULL,
  level_id   INT NOT NULL,
  placed_by  INT NULL,
  reason     VARCHAR(300) NULL,
  placed_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lplace (student_id, course_id),
  CONSTRAINT fk_lplace_student FOREIGN KEY (student_id) REFERENCES users(id)   ON DELETE CASCADE,
  CONSTRAINT fk_lplace_course  FOREIGN KEY (course_id)  REFERENCES courses(id) ON DELETE CASCADE,
  CONSTRAINT fk_lplace_level   FOREIGN KEY (level_id)   REFERENCES levels(id)  ON DELETE CASCADE,
  CONSTRAINT fk_lplace_by      FOREIGN KEY (placed_by)  REFERENCES users(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS lesson_sittings (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  student_id     INT NOT NULL,
  course_id      INT NOT NULL,
  module_id      INT NULL,
  kind           VARCHAR(8) NOT NULL DEFAULT 'session',
  items          MEDIUMTEXT NOT NULL,
  answers        MEDIUMTEXT NULL,
  marks          MEDIUMTEXT NULL,
  score          DOUBLE NULL,
  max_score      DOUBLE NULL,
  pct            DOUBLE NULL,
  band           VARCHAR(16) NULL,
  status         VARCHAR(10) NOT NULL DEFAULT 'open',
  pending        TINYINT NOT NULL DEFAULT 0,
  time_limit_sec INT NOT NULL,
  pledged_at     DATETIME NULL,
  started_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  deadline       DATETIME NOT NULL,
  submitted_at   DATETIME NULL,
  marked_by      INT NULL,
  appeal_text    VARCHAR(2000) NULL,
  appealed_at    DATETIME NULL,
  appeal_outcome VARCHAR(2000) NULL,
  appeal_by      INT NULL,
  updated_at     DATETIME NULL,
  KEY idx_sit_student (student_id, kind, course_id),
  KEY idx_sit_pending (pending, status),
  CONSTRAINT fk_sit_student FOREIGN KEY (student_id) REFERENCES users(id)   ON DELETE CASCADE,
  CONSTRAINT fk_sit_course  FOREIGN KEY (course_id)  REFERENCES courses(id) ON DELETE CASCADE,
  CONSTRAINT fk_sit_module  FOREIGN KEY (module_id)  REFERENCES modules(id) ON DELETE SET NULL,
  CONSTRAINT fk_sit_marker  FOREIGN KEY (marked_by)  REFERENCES users(id)   ON DELETE SET NULL,
  CONSTRAINT fk_sit_appeal  FOREIGN KEY (appeal_by)  REFERENCES users(id)   ON DELETE SET NULL,
  CONSTRAINT chk_sit_kind    CHECK (kind IN ('session','trial')),
  CONSTRAINT chk_sit_status  CHECK (status IN ('open','submitted','marked')),
  CONSTRAINT chk_sit_pending CHECK (pending IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS lesson_deadline_log (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  module_id  INT NOT NULL,
  student_id INT NOT NULL,
  kind       VARCHAR(10) NOT NULL,
  period     VARCHAR(20) NOT NULL,
  charge_id  INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ldl (module_id, student_id, kind, period),
  CONSTRAINT fk_ldl_module  FOREIGN KEY (module_id)  REFERENCES modules(id) ON DELETE CASCADE,
  CONSTRAINT fk_ldl_student FOREIGN KEY (student_id) REFERENCES users(id)   ON DELETE CASCADE,
  CONSTRAINT chk_ldl_kind CHECK (kind IN ('reminder','fine'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- A lesson saved without a kind was stored as '' (fixed in lessons.php): it is a module.
UPDATE modules SET kind = 'module' WHERE kind = '' OR kind IS NULL;
INSERT INTO schema_version (version) VALUES (98) ON DUPLICATE KEY UPDATE version = version;

-- v99 — stored files (private bucket or this host), class spaces, safeguarding concerns.
CREATE TABLE IF NOT EXISTS media_objects (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  object_key    VARCHAR(200) NOT NULL,
  owner_id      INT NULL,
  purpose       VARCHAR(20) NOT NULL,
  kind          VARCHAR(10) NOT NULL,
  content_type  VARCHAR(100) NOT NULL,
  bytes         BIGINT NOT NULL,
  provider      VARCHAR(10) NOT NULL,
  status        VARCHAR(10) NOT NULL DEFAULT 'pending',
  scope_type    VARCHAR(20) NULL,
  scope_id      INT NULL,
  original_name VARCHAR(160) NULL,
  meta          TEXT NULL,
  created_at    DATETIME NOT NULL,
  uploaded_at   DATETIME NULL,
  deleted_at    DATETIME NULL,
  UNIQUE KEY uq_media_key (object_key),
  KEY idx_media_owner (owner_id, status),
  KEY idx_media_scope (scope_type, scope_id),
  CONSTRAINT fk_media_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_media_provider CHECK (provider IN ('bucket','local')),
  CONSTRAINT chk_media_status CHECK (status IN ('pending','uploaded','deleted')),
  CONSTRAINT chk_media_kind CHECK (kind IN ('video','audio','image','pdf','doc'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS class_spaces (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  cohort_id    INT NULL,
  title        VARCHAR(160) NOT NULL,
  about        TEXT NULL,
  visibility   VARCHAR(10) NOT NULL DEFAULT 'class',
  review_media TINYINT NOT NULL DEFAULT 1,
  students_comment TINYINT NOT NULL DEFAULT 1,
  status       VARCHAR(10) NOT NULL DEFAULT 'open',
  created_by   INT NULL,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NULL,
  UNIQUE KEY uq_space_cohort (cohort_id),
  CONSTRAINT fk_space_cohort FOREIGN KEY (cohort_id) REFERENCES cohorts(id) ON DELETE SET NULL,
  CONSTRAINT fk_space_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_space_vis CHECK (visibility IN ('class','private')),
  CONSTRAINT chk_space_rev CHECK (review_media IN (0,1)),
  CONSTRAINT chk_space_com CHECK (students_comment IN (0,1)),
  CONSTRAINT chk_space_status CHECK (status IN ('open','archived'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS space_members (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  space_id   INT NOT NULL,
  user_id    INT NOT NULL,
  role       VARCHAR(10) NOT NULL DEFAULT 'student',
  status     VARCHAR(10) NOT NULL DEFAULT 'active',
  added_by   INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_spm (space_id, user_id),
  KEY idx_spm_user (user_id, status),
  CONSTRAINT fk_spm_space FOREIGN KEY (space_id) REFERENCES class_spaces(id) ON DELETE CASCADE,
  CONSTRAINT fk_spm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_spm_by FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_spm_role CHECK (role IN ('student','teacher')),
  CONSTRAINT chk_spm_status CHECK (status IN ('active','removed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS space_tasks (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  space_id    INT NOT NULL,
  title       VARCHAR(160) NOT NULL,
  brief       TEXT NULL,
  wants       VARCHAR(10) NOT NULL DEFAULT 'video',
  share       VARCHAR(10) NOT NULL DEFAULT 'class',
  points      INT NOT NULL DEFAULT 10,
  due_at      DATETIME NULL,
  status      VARCHAR(10) NOT NULL DEFAULT 'open',
  created_by  INT NULL,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_stask_space FOREIGN KEY (space_id) REFERENCES class_spaces(id) ON DELETE CASCADE,
  CONSTRAINT fk_stask_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_stask_wants CHECK (wants IN ('video','audio','any')),
  CONSTRAINT chk_stask_share CHECK (share IN ('class','teacher')),
  CONSTRAINT chk_stask_points CHECK (points BETWEEN 1 AND 100),
  CONSTRAINT chk_stask_status CHECK (status IN ('open','closed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS space_posts (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  space_id    INT NOT NULL,
  author_id   INT NULL,
  kind        VARCHAR(12) NOT NULL DEFAULT 'post',
  task_id     INT NULL,
  body        TEXT NULL,
  media_id    INT NULL,
  audience    VARCHAR(10) NOT NULL DEFAULT 'class',
  status      VARCHAR(10) NOT NULL DEFAULT 'visible',
  hold_reason VARCHAR(200) NULL,
  score       INT NULL,
  max_score   INT NULL,
  feedback    TEXT NULL,
  reviewed_by INT NULL,
  reviewed_at DATETIME NULL,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  edited_at   DATETIME NULL,
  KEY idx_sp_space (space_id, status, id),
  KEY idx_sp_task (task_id, author_id),
  CONSTRAINT fk_sp_space FOREIGN KEY (space_id) REFERENCES class_spaces(id) ON DELETE CASCADE,
  CONSTRAINT fk_sp_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_sp_task FOREIGN KEY (task_id) REFERENCES space_tasks(id) ON DELETE SET NULL,
  CONSTRAINT fk_sp_media FOREIGN KEY (media_id) REFERENCES media_objects(id) ON DELETE SET NULL,
  CONSTRAINT fk_sp_rev FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_sp_kind CHECK (kind IN ('post','submission','notice')),
  CONSTRAINT chk_sp_aud CHECK (audience IN ('class','teacher')),
  CONSTRAINT chk_sp_status CHECK (status IN ('visible','held','removed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS space_comments (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  post_id     INT NOT NULL,
  author_id   INT NULL,
  body        TEXT NOT NULL,
  at_sec      DECIMAL(9,2) NULL,
  status      VARCHAR(10) NOT NULL DEFAULT 'visible',
  hold_reason VARCHAR(200) NULL,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sc_post (post_id, status),
  CONSTRAINT fk_sc_post FOREIGN KEY (post_id) REFERENCES space_posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_sc_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_sc_status CHECK (status IN ('visible','held','removed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS space_reactions (
  post_id    INT NOT NULL,
  user_id    INT NOT NULL,
  kind       VARCHAR(10) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (post_id, user_id, kind),
  CONSTRAINT fk_sr_post FOREIGN KEY (post_id) REFERENCES space_posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_sr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_sr_kind CHECK (kind IN ('clap','heart','star'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS space_reports (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  post_id     INT NULL,
  comment_id  INT NULL,
  reporter_id INT NULL,
  reason      VARCHAR(20) NOT NULL,
  note        TEXT NULL,
  status      VARCHAR(10) NOT NULL DEFAULT 'open',
  outcome     VARCHAR(200) NULL,
  handled_by  INT NULL,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  handled_at  DATETIME NULL,
  CONSTRAINT fk_srep_post FOREIGN KEY (post_id) REFERENCES space_posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_srep_comment FOREIGN KEY (comment_id) REFERENCES space_comments(id) ON DELETE CASCADE,
  CONSTRAINT fk_srep_by FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_srep_handled FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_srep_reason CHECK (reason IN ('unkind','unsafe','private_info','spam','other')),
  CONSTRAINT chk_srep_status CHECK (status IN ('open','closed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS safeguarding_concerns (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  raised_by   INT NULL,
  about_id    INT NULL,
  source      VARCHAR(10) NOT NULL DEFAULT 'person',
  ref_type    VARCHAR(20) NULL,
  ref_id      INT NULL,
  category    VARCHAR(20) NOT NULL,
  urgency     VARCHAR(10) NOT NULL DEFAULT 'normal',
  body        TEXT NOT NULL,
  status      VARCHAR(10) NOT NULL DEFAULT 'open',
  assigned_to INT NULL,
  outcome     TEXT NULL,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  acknowledged_at DATETIME NULL,
  closed_at   DATETIME NULL,
  KEY idx_sgc_status (status, urgency),
  CONSTRAINT fk_sgc_by FOREIGN KEY (raised_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_sgc_about FOREIGN KEY (about_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_sgc_assigned FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_sgc_source CHECK (source IN ('person','automatic')),
  CONSTRAINT chk_sgc_cat CHECK (category IN ('wellbeing','conduct','contact','content','online_class','other')),
  CONSTRAINT chk_sgc_urg CHECK (urgency IN ('normal','urgent')),
  CONSTRAINT chk_sgc_status CHECK (status IN ('open','assessing','referred','closed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS safeguarding_notes (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  concern_id INT NOT NULL,
  author_id  INT NULL,
  body       TEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sgn_concern FOREIGN KEY (concern_id) REFERENCES safeguarding_concerns(id) ON DELETE CASCADE,
  CONSTRAINT fk_sgn_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO schema_version (version) VALUES (99) ON DUPLICATE KEY UPDATE version = version;

-- v100 — invoice review, family queries, discount codes (api/lib/fee-review.php).
ALTER TABLE fee_invoices ADD COLUMN reviewed_by INT NULL;
ALTER TABLE fee_invoices ADD COLUMN reviewed_at DATETIME NULL;
ALTER TABLE fee_invoices ADD COLUMN review_note VARCHAR(500) NULL;
ALTER TABLE fee_invoices ADD COLUMN review_sig CHAR(64) NULL;
CREATE TABLE IF NOT EXISTS fee_invoice_queries (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id           INT NOT NULL,
  raised_by            INT NULL,
  kind                 VARCHAR(10) NOT NULL,
  message              TEXT NOT NULL,
  proposed_minor       BIGINT NULL,
  proposed_instalments INT NULL,
  status               VARCHAR(12) NOT NULL DEFAULT 'open',
  office_reply         TEXT NULL,
  counter_minor        BIGINT NULL,
  counter_instalments  INT NULL,
  decided_by           INT NULL,
  decided_at           DATETIME NULL,
  family_response_at   DATETIME NULL,
  created_at           DATETIME NOT NULL,
  KEY idx_fiq_invoice (invoice_id, status),
  CONSTRAINT fk_fiq_invoice FOREIGN KEY (invoice_id) REFERENCES fee_invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_fiq_raised FOREIGN KEY (raised_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_fiq_decided FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_fiq_kind CHECK (kind IN ('question','amount','plan')),
  CONSTRAINT chk_fiq_status CHECK (status IN ('open','answered','accepted','countered','declined','withdrawn'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fee_discount_codes (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(24) NOT NULL,
  label           VARCHAR(120) NOT NULL,
  pct             DECIMAL(5,2) NULL,
  amount_minor    BIGINT NULL,
  currency        CHAR(3) NULL,
  max_uses        INT NULL,
  used            INT NOT NULL DEFAULT 0,
  once_per_family TINYINT NOT NULL DEFAULT 1,
  starts_on       DATE NULL,
  ends_on         DATE NULL,
  active          TINYINT NOT NULL DEFAULT 1,
  created_by      INT NULL,
  created_at      DATETIME NOT NULL,
  UNIQUE KEY uq_fdc_code (code),
  CONSTRAINT fk_fdc_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_fdc_one CHECK ((pct IS NULL) <> (amount_minor IS NULL)),
  CONSTRAINT chk_fdc_active CHECK (active IN (0,1)),
  CONSTRAINT chk_fdc_once CHECK (once_per_family IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fee_discount_redemptions (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  code_id      INT NOT NULL,
  invoice_id   INT NOT NULL,
  amount_minor BIGINT NOT NULL,
  applied_by   INT NULL,
  created_at   DATETIME NOT NULL,
  UNIQUE KEY uq_fdr (code_id, invoice_id),
  CONSTRAINT fk_fdr_code FOREIGN KEY (code_id) REFERENCES fee_discount_codes(id) ON DELETE CASCADE,
  CONSTRAINT fk_fdr_invoice FOREIGN KEY (invoice_id) REFERENCES fee_invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_fdr_by FOREIGN KEY (applied_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO schema_version (version) VALUES (100) ON DUPLICATE KEY UPDATE version = version;

-- v101 — live online classes: join/leave/heartbeat events (api/lib/live-classes.php).
CREATE TABLE IF NOT EXISTS live_events (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  session_id INT NOT NULL,
  user_id    INT NULL,
  kind       VARCHAR(10) NOT NULL,
  at         DATETIME NOT NULL,
  source     VARCHAR(10) NOT NULL,
  idem       VARCHAR(160) NOT NULL,
  raw        TEXT NULL,
  UNIQUE KEY uq_live_idem (idem),
  KEY idx_live_session (session_id, user_id, at),
  CONSTRAINT fk_live_session FOREIGN KEY (session_id) REFERENCES class_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_live_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_live_kind CHECK (kind IN ('opened','joined','left','beat')),
  CONSTRAINT chk_live_source CHECK (source IN ('page','beat','webhook'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO schema_version (version) VALUES (101) ON DUPLICATE KEY UPDATE version = version;

-- v102 — adaptive assessment, weekly mastery snapshots, goals (adaptive.php, growth.php).
ALTER TABLE module_checks ADD COLUMN difficulty INT NOT NULL DEFAULT 3;
CREATE TABLE IF NOT EXISTS check_calibration (
  check_id INT PRIMARY KEY,
  b        DOUBLE NOT NULL,
  answered INT NOT NULL DEFAULT 0,
  correct  INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_cal_check FOREIGN KEY (check_id) REFERENCES module_checks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS student_ability (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  course_id  INT NOT NULL,
  theta      DOUBLE NOT NULL DEFAULT 0,
  answered   INT NOT NULL DEFAULT 0,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_ability (student_id, course_id),
  CONSTRAINT fk_ability_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ability_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS mastery_snapshots (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  student_id   INT NOT NULL,
  week_of      DATE NOT NULL,
  coverage_pct DECIMAL(5,1) NOT NULL DEFAULT 0,
  band_low     INT NULL,
  band_high    INT NULL,
  level_label  VARCHAR(40) NULL,
  components   TEXT NULL,
  taken_at     DATETIME NOT NULL,
  UNIQUE KEY uq_snap (student_id, week_of),
  CONSTRAINT fk_snap_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS student_goals (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  student_id  INT NOT NULL,
  kind        VARCHAR(16) NOT NULL,
  title       VARCHAR(160) NOT NULL,
  target      INT NULL,
  due_on      DATE NULL,
  status      VARCHAR(10) NOT NULL DEFAULT 'active',
  set_by      INT NULL,
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NULL,
  achieved_at DATETIME NULL,
  KEY idx_goals_student (student_id, status),
  CONSTRAINT fk_goal_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_goal_by FOREIGN KEY (set_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_goal_kind CHECK (kind IN ('practice_week','lessons','handins','custom')),
  CONSTRAINT chk_goal_status CHECK (status IN ('active','achieved','dropped'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO schema_version (version) VALUES (102) ON DUPLICATE KEY UPDATE version = version;
