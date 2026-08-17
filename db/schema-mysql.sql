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
  role                   ENUM('admin', 'instructor', 'student') NOT NULL,
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
  role    ENUM('admin','instructor','student') NOT NULL,
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
  CONSTRAINT fk_res_user   FOREIGN KEY (finalized_by)  REFERENCES users(id)              ON DELETE SET NULL
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

-- NOTE: program_courses is ALSO created in the consolidated section above, so
-- on a fresh install this CREATE is inert (IF NOT EXISTS) and the earlier
-- definition is the one that takes effect. It is kept here so an install that
-- predates the consolidated section still gets the table when it reaches v41.
-- The two definitions must stay IDENTICAL: they disagreed for a long time
-- (VARCHAR(32) NULL above vs VARCHAR(24) here), which meant editing this copy
-- changed nothing on a fresh install, silently and with no error.
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
-- The progress_reports CREATE below is therefore a DELIBERATE second copy, not
-- stray duplication — do not "tidy" it away. It is inert on any install where
-- the earlier definition succeeded, and it is the whole repair on one where
-- that definition failed. Keep it byte-identical to the earlier one.
CREATE TABLE IF NOT EXISTS progress_reports (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  session_id    INT NOT NULL,
  student_id    INT NOT NULL,
  instructor_id INT NOT NULL,
  form_id       INT NULL,
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
ALTER TABLE class_sessions ADD COLUMN IF NOT EXISTS meeting_link VARCHAR(500) NULL;
INSERT INTO schema_version (version) VALUES (57) ON DUPLICATE KEY UPDATE version = version;

-- v58: public-site promo mode (banner + time-limited discount) on promo_banners.
ALTER TABLE promo_banners ADD COLUMN IF NOT EXISTS public TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE promo_banners ADD COLUMN IF NOT EXISTS discount_percent INT NOT NULL DEFAULT 0;
ALTER TABLE promo_banners ADD COLUMN IF NOT EXISTS applies_to VARCHAR(16) NULL;
INSERT INTO schema_version (version) VALUES (58) ON DUPLICATE KEY UPDATE version = version;

-- v59: ID cards get a REAL expiry. The printed EXP was derived from the issue year
-- and enforced nowhere, so an expired card kept scanning into attendance. Summer
-- cards are meant to lapse a couple of months after the camp. Backfill derives a
-- date from each card's own issue date so no row is left NULL (= never expires).
ALTER TABLE id_cards ADD COLUMN IF NOT EXISTS expires_at DATETIME NULL;
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

INSERT INTO schema_version (version) VALUES (82) ON DUPLICATE KEY UPDATE version = version;
