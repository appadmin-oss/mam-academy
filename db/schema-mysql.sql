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
  available_locations TEXT          DEFAULT '*',
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
ALTER TABLE applications ADD COLUMN IF NOT EXISTS level         VARCHAR(16) NULL;
ALTER TABLE applications ADD COLUMN IF NOT EXISTS expression    VARCHAR(16) NULL;
ALTER TABLE applications ADD COLUMN IF NOT EXISTS extra_courses INT NOT NULL DEFAULT 0;
ALTER TABLE applications ADD COLUMN IF NOT EXISTS courses_label TEXT NULL;
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
  ADD COLUMN IF NOT EXISTS status_changed_at DATETIME NULL AFTER approved_at;

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

ALTER TABLE course_enrollments ADD COLUMN IF NOT EXISTS cohort_id INT NULL;
ALTER TABLE course_enrollments ADD KEY IF NOT EXISTS idx_cenroll_cohort (cohort_id);

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
ALTER TABLE users ADD COLUMN IF NOT EXISTS admin_tier VARCHAR(16) NULL;
UPDATE users SET admin_tier = 'super' WHERE role = 'admin' AND (admin_tier IS NULL OR admin_tier = '');

INSERT INTO schema_version (version) VALUES (26) ON DUPLICATE KEY UPDATE version = version;

-- v27 — Admissions pipeline (Application A2): enforced status state machine +
-- audit (application_events) + internal notes + an assignee.
ALTER TABLE applications ADD COLUMN IF NOT EXISTS assignee_id INT NULL;
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
