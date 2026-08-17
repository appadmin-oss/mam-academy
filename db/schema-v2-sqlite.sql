-- =============================================================================
-- MAM Platform — schema-v2, SQLite mirror
-- =============================================================================
-- GENERATED FILE — do not edit.
--   source:     db/schema-v2.sql   (canonical, MySQL 8)
--   regenerate: php api/tools/schema-v2-sqlite.php
--   verify:     php api/tools/schema-v2-sqlite.php --check
--
-- Differences from the canonical file are mechanical only: MySQL types map
-- to SQLite affinities, inline KEYs become CREATE INDEX statements, and
-- ON UPDATE CURRENT_TIMESTAMP is dropped (SQLite has no equivalent, so
-- updated_at is maintained by the application on this engine).
--
-- Constraints — CHECKs, foreign keys, uniqueness — are IDENTICAL by design.
-- They are what makes dev a rehearsal for production rather than a
-- looser environment that lets bad rows through.
-- =============================================================================

PRAGMA foreign_keys = ON;
-- =============================================================================
-- MAM Platform — schema-v2 (canonical, MySQL 8)
-- =============================================================================
-- Design + rationale: docs/schema-v2-design.md
--
-- This is a FRESH schema, not a migration target. A new install creates it
-- directly; existing data arrives through the ETL (export → transform → load →
-- verify), never by replaying the 81 historical v1 migrations.
--
-- Conventions (see §2 of the design doc):
--   • tenant_id is the LEADING column of composite keys — a cross-tenant
--     collision is a constraint violation, not a leak somebody must remember
--     to prevent with a WHERE clause.
--   • public_id TEXT is a ULID for anything externally addressable, so
--     URLs and exports leak neither row counts nor growth rate.
--   • Soft delete everywhere; uniqueness is scoped so a deleted row does not
--     block reuse of its natural key. The scoping column is `deleted_seq`, NOT
--     `deleted_at`: both engines treat NULLs as DISTINCT in a unique index, so
--     a key ending in a nullable `deleted_at` silently permits two LIVE
--     duplicates — which is how a learner could hold two enrolments in one
--     cohort. `deleted_seq` is NOT NULL, 0 while live, set to the row id on
--     soft delete; live rows therefore collide, and a natural key can be
--     recycled any number of times.
--   • row_version for optimistic locking — two admins editing one cohort is
--     routine, and last-write-wins silently discards an edit.
--   • Money is integer minor units + ISO currency, with the base-currency
--     amount and the frozen rate REQUIRED whenever they differ (CHECK).
--   • TEXT UTC. Wall-clock times that must survive a timezone rule
--     change are stored local + IANA zone, never as an instant.
--
-- Engine/charset match the v1 baseline for host compatibility.
-- =============================================================================

-- =============================================================================
-- LAYER A · PLATFORM & TENANCY
-- =============================================================================

-- A tenant is an academy/organisation. MAM Academy is tenant #1 rather than the
-- hardcoded universe; this is the single decision that makes the rest a
-- platform, and the one that cannot be retrofitted cheaply.
CREATE TABLE tenants (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id         TEXT     NOT NULL,
  slug              TEXT  NOT NULL,           -- URL/subdomain key
  legal_name        TEXT NOT NULL,
  display_name      TEXT NOT NULL,
  -- Per tenant, and MAM's is NGN. Every payment in the v1 data is naira and
  -- v59 predates any USD column, so a USD base would force the ETL to invent a
  -- rate per historical transaction and store it as frozen fact. Reporting in
  -- another currency stays available through fx_rates, going forward, where a
  -- real observed rate exists.
  base_currency     TEXT      NOT NULL DEFAULT 'NGN',
  default_locale    TEXT  NOT NULL DEFAULT 'en',
  default_timezone  TEXT  NOT NULL DEFAULT 'Africa/Lagos',
  status            TEXT  NOT NULL DEFAULT 'active',  -- active|suspended|closed
  -- Contractual/branding facts kept as TEXT: they vary per tenant and are read
  -- whole, never filtered on, so a column per field would be churn for nothing.
  branding          TEXT         NULL,
  contact           TEXT         NULL,
  created_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  updated_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  deleted_at        TEXT  NULL,
  row_version       INTEGER NOT NULL DEFAULT 1,
  UNIQUE (public_id),
  UNIQUE (slug)

);

-- ISO 4217 with the exponent, because currencies disagree about how many minor
-- units exist (JPY 0, USD/NGN 2, KWD 3). Storing the exponent is what lets a
-- generic formatter render any tenant's money correctly.
CREATE TABLE currencies (
  code        TEXT     NOT NULL PRIMARY KEY,
  name        TEXT NOT NULL,
  exponent    TINYINTEGER NOT NULL DEFAULT 2,
  symbol      TEXT  NULL,
  is_active   INTEGER  NOT NULL DEFAULT 1
);

-- A centre is a place teaching happens — a campus, a partner site, or the
-- virtual one. Flat per tenant for now; §7.4 of the design doc flags the
-- hierarchy question, which is a parent_id away but should be decided before
-- data lands.
CREATE TABLE centres (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id     TEXT     NOT NULL,
  tenant_id     INTEGER NOT NULL,
  code          TEXT  NOT NULL,
  name          TEXT NOT NULL,
  kind          TEXT  NOT NULL DEFAULT 'physical',  -- physical|virtual
  timezone      TEXT  NULL,        -- overrides the tenant default
  address       TEXT         NULL,
  geo_lat       REAL NULL,
  geo_lng       REAL NULL,
  is_active     INTEGER   NOT NULL DEFAULT 1,
  created_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  updated_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  deleted_at    TEXT  NULL,
  -- 0 while live, the row id once soft-deleted (see the header note).
  deleted_seq   INTEGER NOT NULL DEFAULT 0,
  created_by    INTEGER NULL,
  updated_by    INTEGER NULL,
  row_version   INTEGER NOT NULL DEFAULT 1,
  UNIQUE (public_id),
  UNIQUE (tenant_id, code, deleted_seq),

  CONSTRAINT fk_centres_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);

-- Typed settings. v1 stored everything as a string and cast at every read site,
-- so a typo in a numeric setting became a silent 0. `value_type` lets the
-- accessor validate once, centrally.
CREATE TABLE tenant_settings (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER NOT NULL,
  skey          TEXT NOT NULL,
  value_type    TEXT  NOT NULL DEFAULT 'string',  -- string|int|decimal|bool|json|secret
  svalue        TEXT         NULL,
  -- `secret` values are stored encrypted at rest by the application; the type
  -- is recorded here so an export or an admin screen knows never to display it.
  is_secret     INTEGER   NOT NULL DEFAULT 0,
  updated_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  updated_by    INTEGER NULL,
  UNIQUE (tenant_id, skey),
  CONSTRAINT fk_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

-- Feature flags per tenant, so a capability can be piloted at one academy
-- without a branch or a deploy. `rollout_percent` supports gradual enablement
-- within a tenant (hashed on a stable key, decided in code).
CREATE TABLE tenant_features (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id       INTEGER NOT NULL,
  feature_key     TEXT  NOT NULL,
  is_enabled      INTEGER   NOT NULL DEFAULT 0,
  rollout_percent TINYINTEGER NOT NULL DEFAULT 100,
  config          TEXT         NULL,
  updated_at      TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  updated_by      INTEGER NULL,
  UNIQUE (tenant_id, feature_key),
  CONSTRAINT fk_features_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

-- =============================================================================
-- LAYER B · IDENTITY & ACCESS
-- =============================================================================

-- One human, one row, however many academies they belong to. The alternative —
-- a users row per tenant — is simpler until the first instructor teaches at two
-- centres and has two passwords and no combined timetable.
--
-- Email is globally unique, deliberately: the address identifies a person, not
-- a relationship with one tenant.
CREATE TABLE users (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id         TEXT     NOT NULL,
  email             TEXT NOT NULL,
  email_verified_at TEXT  NULL,
  phone             TEXT  NULL,
  phone_verified_at TEXT  NULL,
  full_name         TEXT NOT NULL,
  preferred_name    TEXT  NULL,
  -- Stored, never inferred from the name: guessing pronouns from a name
  -- misgenders real people, and the cost of a NULL here is nothing.
  pronouns          TEXT  NULL,
  date_of_birth     TEXT         NULL,
  avatar_url        TEXT NULL,
  locale            TEXT  NULL,
  timezone          TEXT  NULL,
  -- Platform-level state. Tenant-level state lives on the membership, so
  -- suspending someone at one academy does not lock them out of another.
  status            TEXT  NOT NULL DEFAULT 'active',  -- active|locked|closed|system
  last_seen_at      TEXT  NULL,
  created_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  updated_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  deleted_at        TEXT  NULL,
  row_version       INTEGER NOT NULL DEFAULT 1,
  UNIQUE (public_id),
  UNIQUE (email)

);

-- Credentials live apart from the person. A user may have none (SSO-only), and
-- rotating a password must not touch the identity row that everything else
-- foreign-keys to.
CREATE TABLE user_credentials (
  id                    INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id               INTEGER NOT NULL,
  password_hash         TEXT NOT NULL,
  algo                  TEXT  NOT NULL DEFAULT 'bcrypt',
  must_change           INTEGER   NOT NULL DEFAULT 0,
  password_changed_at   TEXT  NULL,
  -- Lockout is per credential, so a brute-force attempt cannot be used to
  -- deny service to the human's other authentication routes.
  failed_attempts       INTEGER NOT NULL DEFAULT 0,
  locked_until          TEXT  NULL,
  mfa_secret            BLOB NULL,        -- encrypted at rest
  mfa_enrolled_at       TEXT  NULL,
  mfa_recovery_codes    TEXT         NULL,          -- hashed, single-use
  created_at            TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  updated_at            TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  UNIQUE (user_id),
  CONSTRAINT fk_cred_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- External identities (Google Workspace, Microsoft, an LMS SSO). Separate rows
-- so a person can link several and lose none by changing email.
CREATE TABLE user_identities (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id       INTEGER NOT NULL,
  provider      TEXT  NOT NULL,          -- google|microsoft|saml:<tenant>
  subject       TEXT NOT NULL,          -- the provider's stable id
  email_at_link TEXT NULL,
  linked_at     TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  last_login_at TEXT  NULL,
  UNIQUE (provider, subject),

  CONSTRAINT fk_identity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- A membership is the relationship between a person and a tenant. Roles,
-- lifecycle stage and tenant-level status all hang here rather than on the
-- user, which is what lets one person be a student at A and an admin at B.
CREATE TABLE memberships (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id         TEXT     NOT NULL,
  tenant_id         INTEGER NOT NULL,
  user_id           INTEGER NOT NULL,
  centre_id         INTEGER NULL,           -- home centre, if any
  reference         TEXT  NULL,              -- human-facing student/staff ref
  -- Cached lifecycle stage. The event log is the truth; this is materialised so
  -- "everyone at stage X, sorted by age in stage" is affordable on the pipeline
  -- board. A disagreement between the two is a writer bug a job can detect.
  stage             TEXT  NOT NULL DEFAULT 'prospect',
     -- prospect|applicant|admitted|enrolled|active|paused|alumnus|withdrawn|rejected
  stage_entered_at  TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  status            TEXT  NOT NULL DEFAULT 'active',   -- active|suspended|closed
  joined_at         TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  created_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  updated_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  deleted_at        TEXT  NULL,
  -- 0 while live, the row id once soft-deleted (see the header note).
  deleted_seq   INTEGER NOT NULL DEFAULT 0,
  created_by        INTEGER NULL,
  updated_by        INTEGER NULL,
  row_version       INTEGER NOT NULL DEFAULT 1,
  UNIQUE (public_id),
  UNIQUE (tenant_id, user_id, deleted_seq),
  UNIQUE (tenant_id, reference, deleted_seq),
  -- Vocabulary and wording come from ONE place: mam_v2_status_catalogue()
  -- in api/lib/v2/status-catalogue.php. test_v2_clarity.php asserts this CHECK
  -- and that catalogue list the same values, so a status can never exist that
  -- nobody has written a sentence for.
  CONSTRAINT chk_membership_stage CHECK (stage IN
    ('prospect','applicant','admitted','enrolled','active','paused','alumnus','withdrawn','rejected')),

  CONSTRAINT fk_memberships_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_memberships_user   FOREIGN KEY (user_id)   REFERENCES users(id),
  CONSTRAINT fk_memberships_centre FOREIGN KEY (centre_id) REFERENCES centres(id)
);

-- Permissions are rows, not constants, so a tenant can define a role the
-- platform authors never imagined without a deployment.
CREATE TABLE permissions (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  code        TEXT  NOT NULL,      -- e.g. 'enrolment.write'
  domain      TEXT  NOT NULL,      -- grouping for the admin UI
  description TEXT NULL,
  UNIQUE (code)
);

-- System roles (tenant_id NULL) ship with the platform and cannot be edited;
-- tenant roles are theirs to define. The nullable tenant_id is what allows both
-- in one table without a discriminator column that every query must remember.
CREATE TABLE roles (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER NULL,        -- NULL = platform role (carries the FK)
  -- 0 = platform, otherwise the tenant id. NOT NULL so the unique key below
  -- actually holds: with a nullable tenant_id, five platform roles could all be
  -- called 'admin' and authorisation would depend on which one was fetched.
  tenant_scope  INTEGER NOT NULL DEFAULT 0,
  code          TEXT  NOT NULL,
  name          TEXT NOT NULL,
  description   TEXT NULL,
  is_system     INTEGER   NOT NULL DEFAULT 0,
  created_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  updated_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  UNIQUE (tenant_scope, code),
  CONSTRAINT fk_roles_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE role_permissions (
  role_id       INTEGER NOT NULL,
  permission_id INTEGER NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id)       REFERENCES roles(id)       ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
);

-- Roles attach to the MEMBERSHIP, not the user. `centre_id` optionally narrows
-- a grant, so "administrator, Lagos only" is expressible without a second role.
CREATE TABLE membership_roles (
  membership_id INTEGER NOT NULL,
  role_id       INTEGER NOT NULL,
  centre_id     INTEGER NULL,
  granted_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  granted_by    INTEGER NULL,
  expires_at    TEXT  NULL,       -- time-boxed elevation
  PRIMARY KEY (membership_id, role_id),

  CONSTRAINT fk_mr_membership FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
  CONSTRAINT fk_mr_role       FOREIGN KEY (role_id)       REFERENCES roles(id),
  CONSTRAINT fk_mr_centre     FOREIGN KEY (centre_id)     REFERENCES centres(id)
);

-- Sessions store a HASH of the token, never the token: a database disclosure
-- must not hand over live sessions.
CREATE TABLE auth_sessions (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id       INTEGER NOT NULL,
  tenant_id     INTEGER NULL,        -- the tenant context in use
  token_hash    TEXT     NOT NULL,
  ip            BLOB NULL,
  user_agent    TEXT NULL,
  created_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  last_used_at  TEXT  NULL,
  expires_at    TEXT  NOT NULL,
  revoked_at    TEXT  NULL,
  UNIQUE (token_hash),

  CONSTRAINT fk_auth_sessions_user   FOREIGN KEY (user_id)   REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_auth_sessions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);

-- Machine access, separate from human sessions: different lifetime, different
-- revocation story, and scopes rather than roles.
CREATE TABLE api_clients (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id     TEXT     NOT NULL,
  tenant_id     INTEGER NOT NULL,
  name          TEXT NOT NULL,
  key_hash      TEXT     NOT NULL,
  scopes        TEXT         NOT NULL,
  last_used_at  TEXT  NULL,
  expires_at    TEXT  NULL,
  revoked_at    TEXT  NULL,
  created_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  created_by    INTEGER NULL,
  UNIQUE (public_id),
  UNIQUE (key_hash),

  CONSTRAINT fk_api_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

-- =============================================================================
-- LAYER C · LIFECYCLE
-- =============================================================================

-- Append-only. memberships.stage is the cache; this is the truth, and it
-- records ATTEMPTED transitions too — a rejected move is evidence about a
-- process, and discarding it hides exactly the cases worth reviewing.
CREATE TABLE lifecycle_events (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER NOT NULL,
  membership_id INTEGER NOT NULL,
  from_stage    TEXT  NULL,
  to_stage      TEXT  NOT NULL,
  outcome       TEXT  NOT NULL DEFAULT 'applied',  -- applied|rejected
  reason_code   TEXT  NULL,
  note          TEXT NULL,
  occurred_at   TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  -- Nullable because the system acts too (an automation, a scheduled sweep).
  -- NOT ON DELETE SET NULL: erasing who acted is the one thing this table
  -- exists to prevent.
  actor_user_id INTEGER NULL,
  source        TEXT  NOT NULL DEFAULT 'app',      -- app|automation|import|api

  CONSTRAINT fk_lifecycle_tenant     FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_lifecycle_membership FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
  CONSTRAINT fk_lifecycle_actor      FOREIGN KEY (actor_user_id) REFERENCES users(id)
);

-- One intake table. v1 had three (applications, summer_applications,
-- scholarship_applications) with three status vocabularies and no single funnel
-- view; `type` + a typed payload replaces them.
CREATE TABLE applications (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id         TEXT     NOT NULL,
  tenant_id         INTEGER NOT NULL,
  membership_id     INTEGER NULL,        -- linked once a person exists
  type              TEXT  NOT NULL,       -- programme|summer|scholarship|audition
  status            TEXT  NOT NULL DEFAULT 'draft',
     -- draft|submitted|under_review|interview_scheduled|offered|accepted|
     -- waitlisted|rejected|withdrawn
  submitted_at      TEXT  NULL,
  decided_at        TEXT  NULL,
  decided_by        INTEGER NULL,
  decision_reason   TEXT NULL,
  programme_id      INTEGER NULL,
  level_id          INTEGER NULL,
  centre_id         INTEGER NULL,
  intake_term       TEXT  NULL,
  -- Type-specific answers. The shape is validated in code against the type, so
  -- a summer application's guardian block does not become 30 nullable columns
  -- that every other type ignores.
  payload           TEXT         NULL,
  readiness_signal  TEXT  NULL,           -- advisory, never a gate
  -- The reference the applicant is given and will quote back on the phone.
  -- v1 had one for scholarships only, so every other applicant had nothing to
  -- say when they called except their name.
  reference         TEXT  NULL,
  source_channel    TEXT  NULL,           -- attribution
  created_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  updated_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  deleted_at        TEXT  NULL,
  -- 0 while live, the row id once soft-deleted (see the header note).
  deleted_seq       INTEGER NOT NULL DEFAULT 0,
  row_version       INTEGER NOT NULL DEFAULT 1,
  UNIQUE (public_id),
  UNIQUE (tenant_id, reference, deleted_seq),

  -- No rejection without a reason. "Unsuccessful" and nothing else is the most
  -- confusing message an academy can send, and v1 permitted exactly that.
  -- Vocabulary and wording come from ONE place: mam_v2_status_catalogue()
  -- in api/lib/v2/status-catalogue.php. test_v2_clarity.php asserts this CHECK
  -- and that catalogue list the same values, so a status can never exist that
  -- nobody has written a sentence for.
  CONSTRAINT chk_app_status CHECK (status IN
    ('draft','submitted','under_review','interview_scheduled','offered','accepted',
     'waitlisted','rejected','withdrawn')),
  CONSTRAINT chk_app_rejection_reason CHECK (status <> 'rejected' OR decision_reason IS NOT NULL),
  CONSTRAINT fk_app_tenant     FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_app_membership FOREIGN KEY (membership_id) REFERENCES memberships(id),
  CONSTRAINT fk_app_centre     FOREIGN KEY (centre_id)     REFERENCES centres(id)
);

-- Guardianship is a relationship between two memberships in the SAME tenant, so
-- a guardian of children at two academies has a row in each and no cross-tenant
-- visibility. `permissions` scopes what they may see.
CREATE TABLE guardianships (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id         INTEGER NOT NULL,
  guardian_membership_id INTEGER NOT NULL,
  learner_membership_id  INTEGER NOT NULL,
  relationship      TEXT  NOT NULL DEFAULT 'guardian',
  is_primary        INTEGER   NOT NULL DEFAULT 0,
  -- A named scope both parties can be shown in one phrase. The TEXT below
  -- refines it; the named level is what a learner is told ("your guardian can
  -- see your progress and your fees") and what a guardian is shown on sign-up.
  -- Free-form permissions alone left neither side able to state the answer.
  scope             TEXT  NOT NULL DEFAULT 'standard',
     -- billing_only|standard|full
  permissions       TEXT         NULL,       -- view_progress|view_invoices|approve_media
  verified_at       TEXT  NULL,
  created_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  deleted_at        TEXT  NULL,
  -- 0 while live, the row id once soft-deleted (see the header note).
  deleted_seq   INTEGER NOT NULL DEFAULT 0,
  created_by        INTEGER NULL,
  UNIQUE (tenant_id, guardian_membership_id, learner_membership_id, deleted_seq),

  CONSTRAINT chk_guard_scope CHECK (scope IN ('billing_only','standard','full')),
  CONSTRAINT fk_guard_tenant   FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_guard_guardian FOREIGN KEY (guardian_membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
  CONSTRAINT fk_guard_learner  FOREIGN KEY (learner_membership_id)  REFERENCES memberships(id) ON DELETE CASCADE
);

-- =============================================================================
-- LAYER D · CATALOGUE (what can be taught)
-- =============================================================================
-- Reference data. Re-seeded from canonical definitions by the ETL rather than
-- copied, so accumulated inconsistency is shed rather than migrated.

CREATE TABLE programmes (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id       TEXT     NOT NULL,
  tenant_id       INTEGER NOT NULL,
  code            TEXT  NOT NULL,
  name            TEXT NOT NULL,
  summary         TEXT NULL,
  duration_weeks  SMALLINTEGER NOT NULL DEFAULT 12,
  certification   TEXT  NULL,          -- participation|certificate|diploma
  sort_order      INTEGER          NOT NULL DEFAULT 0,
  is_active       INTEGER   NOT NULL DEFAULT 1,
  created_at      TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  updated_at      TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  deleted_at      TEXT  NULL,
  -- 0 while live, the row id once soft-deleted (see the header note).
  deleted_seq   INTEGER NOT NULL DEFAULT 0,
  row_version     INTEGER NOT NULL DEFAULT 1,
  UNIQUE (public_id),
  UNIQUE (tenant_id, code, deleted_seq),
  CONSTRAINT fk_programmes_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);

CREATE TABLE levels (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id       INTEGER NOT NULL,
  code            TEXT  NOT NULL,
  name            TEXT NOT NULL,
  external_grades TEXT  NULL,          -- e.g. 'Trinity Grade 1-2'
  rank_order      INTEGER     NOT NULL DEFAULT 0,   -- progression order
  is_active       INTEGER   NOT NULL DEFAULT 1,
  deleted_at      TEXT  NULL,
  -- 0 while live, the row id once soft-deleted (see the header note).
  deleted_seq   INTEGER NOT NULL DEFAULT 0,
  UNIQUE (tenant_id, code, deleted_seq),
  CONSTRAINT fk_levels_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);

-- A course is a subject (Piano, Drums, Music Theory). "Instrument" in the
-- reporting drill-down is a course.
CREATE TABLE courses (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id     TEXT     NOT NULL,
  tenant_id     INTEGER NOT NULL,
  code          TEXT  NOT NULL,
  name          TEXT NOT NULL,
  kind          TEXT  NOT NULL DEFAULT 'elective',  -- core|elective|compulsory
  discipline    TEXT  NULL,            -- instrument family, for grouping
  description   TEXT         NULL,
  sort_order    INTEGER          NOT NULL DEFAULT 0,
  is_active     INTEGER   NOT NULL DEFAULT 1,
  created_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  updated_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  deleted_at    TEXT  NULL,
  -- 0 while live, the row id once soft-deleted (see the header note).
  deleted_seq   INTEGER NOT NULL DEFAULT 0,
  row_version   INTEGER NOT NULL DEFAULT 1,
  UNIQUE (public_id),
  UNIQUE (tenant_id, code, deleted_seq),

  CONSTRAINT fk_courses_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);

CREATE TABLE programme_courses (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  programme_id  INTEGER NOT NULL,
  course_id     INTEGER NOT NULL,
  level_id      INTEGER NULL,        -- NULL = the course at no set level
  -- 0 = no level, otherwise the level id. The natural key was the PRIMARY KEY
  -- and included the nullable level_id: MySQL coerces PK columns to NOT NULL
  -- and would have REJECTED every level-less row, while SQLite accepted them
  -- and allowed duplicates. A surrogate id plus this companion makes the two
  -- engines agree.
  level_scope   INTEGER NOT NULL DEFAULT 0,
  is_required   INTEGER   NOT NULL DEFAULT 0,
  sort_order    INTEGER          NOT NULL DEFAULT 0,
  UNIQUE (programme_id, course_id, level_scope),

  CONSTRAINT fk_pc_programme FOREIGN KEY (programme_id) REFERENCES programmes(id) ON DELETE CASCADE,
  CONSTRAINT fk_pc_course    FOREIGN KEY (course_id)    REFERENCES courses(id),
  CONSTRAINT fk_pc_level     FOREIGN KEY (level_id)     REFERENCES levels(id)
);

-- A module is a unit of curriculum within a course at a level — the "week N"
-- content. week_no is the PLANNED position; when a learner actually reaches it
-- is recorded per learner, never here (design doc §0: curriculum ≠ schedule ≠
-- mastery).
CREATE TABLE modules (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id       TEXT     NOT NULL,
  tenant_id       INTEGER NOT NULL,
  course_id       INTEGER NOT NULL,
  level_id        INTEGER NULL,
  week_no         SMALLINTEGER NULL,
  title           TEXT NOT NULL,
  objectives      TEXT         NULL,
  prep_instructions TEXT       NULL,
  resources       TEXT         NULL,
  requires_teachback INTEGER NOT NULL DEFAULT 0,
  is_published    INTEGER   NOT NULL DEFAULT 1,
  created_at      TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  updated_at      TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  deleted_at      TEXT  NULL,
  row_version     INTEGER NOT NULL DEFAULT 1,
  UNIQUE (public_id),

  CONSTRAINT fk_modules_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_modules_course FOREIGN KEY (course_id) REFERENCES courses(id),
  CONSTRAINT fk_modules_level  FOREIGN KEY (level_id)  REFERENCES levels(id)
);

-- Competencies are the named abilities a rubric criterion maps to. v1 matched
-- rubric criteria to score components by string keyword, which is fragile and
-- silently reclassifies a renamed criterion; here the mapping is a foreign key.
CREATE TABLE competencies (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER NOT NULL,
  code          TEXT  NOT NULL,     -- rhythm_timing|musical_accuracy|...
  name          TEXT NOT NULL,
  description   TEXT NULL,
  weight_pct    REAL NOT NULL DEFAULT 0,   -- share of the mastery score
  sort_order    INTEGER          NOT NULL DEFAULT 0,
  is_active     INTEGER   NOT NULL DEFAULT 1,
  UNIQUE (tenant_id, code),
  CONSTRAINT fk_comp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);

CREATE TABLE rubrics (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id     TEXT     NOT NULL,
  tenant_id     INTEGER NOT NULL,
  course_id     INTEGER NULL,      -- instrument-specific when set
  level_id      INTEGER NULL,
  title         TEXT NOT NULL,
  version       INTEGER NOT NULL DEFAULT 1,
  -- A scored rubric must never change under a grade that was already awarded.
  -- Editing a published rubric creates a new VERSION; submissions reference the
  -- version they were marked against.
  is_published  INTEGER   NOT NULL DEFAULT 0,
  published_at  TEXT  NULL,
  created_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  created_by    INTEGER NULL,
  deleted_at    TEXT  NULL,
  -- 0 while live, the row id once soft-deleted (see the header note).
  deleted_seq   INTEGER NOT NULL DEFAULT 0,
  UNIQUE (public_id),
  UNIQUE (tenant_id, title, version, deleted_seq),

  CONSTRAINT fk_rubrics_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_rubrics_course FOREIGN KEY (course_id) REFERENCES courses(id)
);

CREATE TABLE rubric_criteria (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  rubric_id     INTEGER NOT NULL,
  competency_id INTEGER NULL,       -- the FK that replaces keyword matching
  label         TEXT NOT NULL,
  descriptor    TEXT         NULL,
  max_points    SMALLINTEGER NOT NULL DEFAULT 10,
  weight        REAL NOT NULL DEFAULT 1.000,
  sort_order    INTEGER          NOT NULL DEFAULT 0,

  CONSTRAINT fk_criteria_rubric     FOREIGN KEY (rubric_id)     REFERENCES rubrics(id) ON DELETE CASCADE,
  CONSTRAINT fk_criteria_competency FOREIGN KEY (competency_id) REFERENCES competencies(id)
);

-- Translations for anything learner-facing. One table rather than a *_i18n
-- sibling per entity, so adding a language is data and adding a translatable
-- entity is one row of configuration.
CREATE TABLE translations (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER NOT NULL,
  entity_type   TEXT  NOT NULL,      -- programmes|courses|modules|...
  entity_id     INTEGER NOT NULL,
  field         TEXT  NOT NULL,      -- name|summary|description
  locale        TEXT  NOT NULL,
  value         TEXT         NOT NULL,
  updated_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  UNIQUE (tenant_id, entity_type, entity_id, field, locale),
  CONSTRAINT fk_translations_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

-- =============================================================================
-- LAYER E · DELIVERY (what is actually taught, to whom, when)
-- =============================================================================
-- ONE spine. v1 carried `classes`+`enrollments`+`attendance` alongside
-- `cohorts`+`course_enrollments`+`session_attendance`, so every read path had
-- to dual-read and a learner could exist in one model and not the other.

CREATE TABLE cohorts (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id       TEXT     NOT NULL,
  tenant_id       INTEGER NOT NULL,
  centre_id       INTEGER NULL,
  course_id       INTEGER NOT NULL,
  level_id        INTEGER NULL,
  programme_id    INTEGER NULL,
  title           TEXT NOT NULL,
  -- Short human reference ("PNO-L1-A26"). A title is for reading; a reference
  -- is for saying out loud and typing into a search box.
  reference       TEXT  NULL,
  term            TEXT  NULL,
  delivery_mode   TEXT  NOT NULL DEFAULT 'onsite',   -- onsite|online|hybrid
  -- Wall-clock, not an instant: a class at "16:00 Lagos" must stay at 16:00
  -- when offset rules change. The instant is derived at render/schedule time.
  meeting_days    TEXT  NULL,          -- 'mon,wed'
  meeting_time    TIME         NULL,
  timezone        TEXT  NULL,
  session_minutes SMALLINTEGER NOT NULL DEFAULT 60,
  starts_on       TEXT         NULL,
  ends_on         TEXT         NULL,
  -- NULL capacity = uncapped; 0 would read as permanently full, which is why
  -- "unlimited" must not be expressed as zero.
  capacity        SMALLINTEGER NULL,
  status          TEXT  NOT NULL DEFAULT 'planned',  -- planned|active|completed|cancelled
  lead_instructor_membership_id INTEGER NULL,
  created_at      TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  updated_at      TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  deleted_at      TEXT  NULL,
  -- 0 while live, the row id once soft-deleted (see the header note).
  deleted_seq     INTEGER NOT NULL DEFAULT 0,
  created_by      INTEGER NULL,
  updated_by      INTEGER NULL,
  row_version     INTEGER NOT NULL DEFAULT 1,
  UNIQUE (public_id),
  UNIQUE (tenant_id, reference, deleted_seq),

  CONSTRAINT fk_cohorts_tenant     FOREIGN KEY (tenant_id)    REFERENCES tenants(id),
  CONSTRAINT fk_cohorts_centre     FOREIGN KEY (centre_id)    REFERENCES centres(id),
  CONSTRAINT fk_cohorts_course     FOREIGN KEY (course_id)    REFERENCES courses(id),
  CONSTRAINT fk_cohorts_level      FOREIGN KEY (level_id)     REFERENCES levels(id),
  CONSTRAINT fk_cohorts_programme  FOREIGN KEY (programme_id) REFERENCES programmes(id),
  CONSTRAINT fk_cohorts_instructor FOREIGN KEY (lead_instructor_membership_id) REFERENCES memberships(id)
);

-- Staffing is many-to-many: a cohort can have an assistant or a substitute, and
-- an instructor's pay/among-instructor reporting needs to know which.
CREATE TABLE cohort_staff (
  cohort_id     INTEGER NOT NULL,
  membership_id INTEGER NOT NULL,
  role          TEXT  NOT NULL DEFAULT 'instructor',  -- instructor|assistant|observer
  starts_on     TEXT         NULL,
  ends_on       TEXT         NULL,
  PRIMARY KEY (cohort_id, membership_id, role),

  CONSTRAINT fk_cs_cohort FOREIGN KEY (cohort_id)     REFERENCES cohorts(id) ON DELETE CASCADE,
  CONSTRAINT fk_cs_member FOREIGN KEY (membership_id) REFERENCES memberships(id)
);

-- THE enrolment. One row per learner per cohort — no second model.
CREATE TABLE enrolments (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id         TEXT     NOT NULL,
  tenant_id         INTEGER NOT NULL,
  membership_id     INTEGER NOT NULL,
  cohort_id         INTEGER NOT NULL,
  status            TEXT  NOT NULL DEFAULT 'pending',
     -- pending|active|completed|withdrawn|transferred|waitlisted
  waitlist_position SMALLINTEGER NULL,
  enrolled_at       TEXT  NULL,
  completed_at      TEXT  NULL,
  withdrawn_at      TEXT  NULL,
  withdrawal_reason TEXT NULL,
  final_score       REAL NULL,
  final_grade       TEXT   NULL,
  outcome           TEXT  NULL,        -- pass|fail|incomplete
  created_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  updated_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  deleted_at        TEXT  NULL,
  -- 0 while live, the row id once soft-deleted (see the header note).
  deleted_seq   INTEGER NOT NULL DEFAULT 0,
  created_by        INTEGER NULL,
  updated_by        INTEGER NULL,
  row_version       INTEGER NOT NULL DEFAULT 1,
  UNIQUE (public_id),
  -- One live enrolment per learner per cohort. Scoped by deleted_at so a
  -- withdrawn-and-deleted row does not block re-enrolment.
  UNIQUE (cohort_id, membership_id, deleted_seq),
  CONSTRAINT chk_enrol_status CHECK (status IN
    ('pending','active','completed','withdrawn','transferred','waitlisted')),
  CONSTRAINT chk_enrol_withdrawal_reason CHECK (status <> 'withdrawn' OR withdrawal_reason IS NOT NULL),

  CONSTRAINT fk_enrol_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_enrol_member FOREIGN KEY (membership_id) REFERENCES memberships(id),
  CONSTRAINT fk_enrol_cohort FOREIGN KEY (cohort_id)     REFERENCES cohorts(id)
);

-- Rooms are declared before class_sessions so the session's room_id can carry
-- a real foreign key rather than being an unenforced integer.
CREATE TABLE rooms (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER NOT NULL,
  centre_id     INTEGER NOT NULL,
  name          TEXT NOT NULL,
  capacity      SMALLINTEGER NULL,
  resources     TEXT         NULL,        -- piano, drum kit, PA…
  is_active     INTEGER   NOT NULL DEFAULT 1,
  deleted_at    TEXT  NULL,
  -- 0 while live, the row id once soft-deleted (see the header note).
  deleted_seq   INTEGER NOT NULL DEFAULT 0,
  UNIQUE (tenant_id, centre_id, name, deleted_seq),
  CONSTRAINT fk_rooms_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_rooms_centre FOREIGN KEY (centre_id) REFERENCES centres(id)
);

-- A delivered session. `scheduled_start_local` + `timezone` is the wall clock;
-- `scheduled_start_utc` is the derived instant, stored so ordering and range
-- queries are index-friendly, and recomputed if the zone database changes.
CREATE TABLE class_sessions (
  id                    INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id             TEXT     NOT NULL,
  tenant_id             INTEGER NOT NULL,
  cohort_id             INTEGER NOT NULL,
  module_id             INTEGER NULL,
  room_id               INTEGER NULL,
  title                 TEXT NULL,
  sequence_no           SMALLINTEGER NULL,
  scheduled_start_local TEXT     NOT NULL,
  timezone              TEXT  NOT NULL,
  scheduled_start_utc   TEXT  NOT NULL,
  duration_minutes      SMALLINTEGER NOT NULL DEFAULT 60,
  delivery_mode         TEXT  NOT NULL DEFAULT 'onsite',
  status                TEXT  NOT NULL DEFAULT 'scheduled',
     -- scheduled|held|cancelled|rescheduled
  meeting_provider      TEXT  NULL,
  meeting_url           TEXT NULL,
  meeting_code          TEXT  NULL,
  rescheduled_from_id   INTEGER NULL,
  cancellation_reason   TEXT NULL,
  notes                 TEXT         NULL,
  created_at            TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  updated_at            TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  deleted_at            TEXT  NULL,
  row_version           INTEGER NOT NULL DEFAULT 1,
  UNIQUE (public_id),

  -- A class that vanishes from the timetable with no reason is the single
  -- complaint every academy gets. The reason is required at the source.
  CONSTRAINT chk_session_status CHECK (status IN ('scheduled','held','cancelled','rescheduled')),
  CONSTRAINT chk_session_cancel_reason CHECK (status <> 'cancelled' OR cancellation_reason IS NOT NULL),
  CONSTRAINT fk_sessions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_sessions_cohort FOREIGN KEY (cohort_id) REFERENCES cohorts(id) ON DELETE CASCADE,
  CONSTRAINT fk_sessions_module FOREIGN KEY (module_id) REFERENCES modules(id),
  CONSTRAINT fk_sessions_room   FOREIGN KEY (room_id)   REFERENCES rooms(id),
  CONSTRAINT fk_sessions_prev   FOREIGN KEY (rescheduled_from_id) REFERENCES class_sessions(id)
);

-- Attendance carries the full seven-status vocabulary from the start. v1's
-- CHECK admitted four, so `left_early`, `technical_failure` and `rescheduled`
-- had to be recorded as an `absent` that misrepresented what happened.
--
-- Durations are STORED, never recomputed on read: a session's scheduled time
-- can be edited afterwards and the record of what actually happened must not
-- move with it.
CREATE TABLE attendance (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id         INTEGER NOT NULL,
  session_id        INTEGER NOT NULL,
  enrolment_id      INTEGER NOT NULL,
  status            TEXT  NOT NULL,
  delivery_mode     TEXT  NOT NULL DEFAULT 'onsite',
  clocked_in_at     TEXT  NULL,
  clocked_out_at    TEXT  NULL,
  minutes_present   SMALLINTEGER NULL,
  minutes_late      SMALLINTEGER NOT NULL DEFAULT 0,
  method            TEXT  NOT NULL DEFAULT 'instructor',  -- instructor|self|qr|card|api
  geo_lat           REAL NULL,
  geo_lng           REAL NULL,
  notes             TEXT NULL,
  marked_by         INTEGER NULL,
  created_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  updated_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  row_version       INTEGER NOT NULL DEFAULT 1,
  UNIQUE (session_id, enrolment_id),

  CONSTRAINT chk_attendance_status CHECK (status IN
    ('present','late','absent','excused','left_early','technical_failure','rescheduled')),
  CONSTRAINT chk_attendance_mode CHECK (delivery_mode IN ('onsite','online','hybrid')),
  CONSTRAINT fk_att_tenant   FOREIGN KEY (tenant_id)    REFERENCES tenants(id),
  CONSTRAINT fk_att_session  FOREIGN KEY (session_id)   REFERENCES class_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_att_enrolment FOREIGN KEY (enrolment_id) REFERENCES enrolments(id) ON DELETE CASCADE
);

-- Per-learner progress through a module. This is where "curriculum vs schedule
-- vs mastery" becomes three separate facts: week_planned, week_started,
-- week_completed, and a mastery percentage that is null until assessed.
CREATE TABLE module_progress (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id         INTEGER NOT NULL,
  enrolment_id      INTEGER NOT NULL,
  module_id         INTEGER NOT NULL,
  status            TEXT  NOT NULL DEFAULT 'not_started',
  percent_complete  TINYINTEGER NOT NULL DEFAULT 0,
  -- NULL means "not assessed", which is a different statement from zero and
  -- must stay distinguishable: scoring an unassessed beginner 0 reports them
  -- as failing.
  mastery_pct       REAL NULL,
  week_planned      SMALLINTEGER NULL,
  week_started      SMALLINTEGER NULL,
  week_completed    SMALLINTEGER NULL,
  attempts          SMALLINTEGER NOT NULL DEFAULT 0,
  due_at            TEXT  NULL,
  completed_at      TEXT  NULL,
  updated_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  row_version       INTEGER NOT NULL DEFAULT 1,
  UNIQUE (enrolment_id, module_id),

  CONSTRAINT chk_progress_status CHECK (status IN
    ('not_started','available','preparation_pending','in_progress','submitted',
     'under_assessment','developing','proficient','advanced','mastered',
     'carried_forward','remedial','completed','exempted')),
  CONSTRAINT fk_prog_tenant   FOREIGN KEY (tenant_id)    REFERENCES tenants(id),
  CONSTRAINT fk_prog_enrolment FOREIGN KEY (enrolment_id) REFERENCES enrolments(id) ON DELETE CASCADE,
  CONSTRAINT fk_prog_module   FOREIGN KEY (module_id)    REFERENCES modules(id)
);

-- Carry-forward is APPENDED, never an overwrite: no academic history is lost.
CREATE TABLE module_carryforwards (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id         INTEGER NOT NULL,
  enrolment_id      INTEGER NOT NULL,
  module_id         INTEGER NOT NULL,
  from_week         SMALLINTEGER NULL,
  to_week           SMALLINTEGER NULL,
  to_date           TEXT         NULL,
  previous_status   TEXT  NOT NULL,
  previous_mastery  REAL NULL,
  reason            TEXT NULL,
  occurred_at       TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  created_by        INTEGER NULL,

  CONSTRAINT fk_carry_tenant    FOREIGN KEY (tenant_id)    REFERENCES tenants(id),
  CONSTRAINT fk_carry_enrolment FOREIGN KEY (enrolment_id) REFERENCES enrolments(id) ON DELETE CASCADE,
  CONSTRAINT fk_carry_module    FOREIGN KEY (module_id)    REFERENCES modules(id)
);

-- =============================================================================
-- LAYER F · MONEY
-- =============================================================================
-- v1 stored a bare `amount` that meant whole naira on one row and dollars on
-- the next, so SUM(amount) added the two and the Revenue KPI reported nonsense.
-- Here the invariant is structural: if the transaction currency differs from
-- the tenant's base, the base amount and the frozen rate are BOTH required.

CREATE TABLE fx_rates (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  base_currency TEXT      NOT NULL,
  quote_currency TEXT     NOT NULL,
  rate          REAL NOT NULL,
  source        TEXT  NOT NULL,       -- which feed, so a dispute is answerable
  observed_at   TEXT  NOT NULL,
  created_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  UNIQUE (base_currency, quote_currency, source, observed_at),

  CONSTRAINT chk_fx_positive CHECK (rate > 0)
);

-- Effective-dated pricing. A price change must never alter what an existing
-- invoice charged, so prices are versioned by validity window rather than
-- updated in place.
CREATE TABLE price_books (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER NOT NULL,
  code          TEXT  NOT NULL,
  name          TEXT NOT NULL,
  currency      TEXT      NOT NULL,
  valid_from    TEXT         NOT NULL,
  valid_to      TEXT         NULL,
  is_active     INTEGER   NOT NULL DEFAULT 1,
  created_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  UNIQUE (tenant_id, code, valid_from),

  CONSTRAINT fk_pb_tenant   FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_pb_currency FOREIGN KEY (currency)  REFERENCES currencies(code)
);

CREATE TABLE price_items (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  price_book_id   INTEGER NOT NULL,
  item_type       TEXT  NOT NULL,     -- tuition|registration|exam|merch|fee
  programme_id    INTEGER NULL,
  course_id       INTEGER NULL,
  level_id        INTEGER NULL,
  sku             TEXT  NULL,
  label           TEXT NOT NULL,
  amount_minor    INTEGER       NOT NULL,
  tax_rate_pct    REAL NOT NULL DEFAULT 0,

  CONSTRAINT fk_pi_book FOREIGN KEY (price_book_id) REFERENCES price_books(id) ON DELETE CASCADE,
  CONSTRAINT chk_pi_amount CHECK (amount_minor >= 0)
);

CREATE TABLE invoices (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id         TEXT     NOT NULL,
  tenant_id         INTEGER NOT NULL,
  membership_id     INTEGER NOT NULL,
  enrolment_id      INTEGER NULL,
  number            TEXT  NOT NULL,      -- human-facing, per tenant
  status            TEXT  NOT NULL DEFAULT 'draft',
     -- draft|issued|part_paid|paid|void|written_off
  currency          TEXT      NOT NULL,
  subtotal_minor    INTEGER       NOT NULL DEFAULT 0,
  tax_minor         INTEGER       NOT NULL DEFAULT 0,
  discount_minor    INTEGER       NOT NULL DEFAULT 0,
  total_minor       INTEGER       NOT NULL DEFAULT 0,
  -- Base-currency mirror, frozen at issue. Reporting sums THIS, never the
  -- transaction amount, which is what makes cross-currency totals meaningful.
  base_currency     TEXT      NOT NULL,
  base_total_minor  INTEGER       NULL,
  fx_rate           REAL NULL,
  fx_source         TEXT  NULL,
  issued_at         TEXT  NULL,
  due_at            TEXT  NULL,
  paid_at           TEXT  NULL,
  voided_at         TEXT  NULL,
  void_reason       TEXT NULL,
  created_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  updated_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  row_version       INTEGER NOT NULL DEFAULT 1,
  UNIQUE (public_id),
  UNIQUE (tenant_id, number),
  CONSTRAINT chk_inv_status CHECK (status IN
    ('draft','issued','part_paid','paid','void','written_off')),
  CONSTRAINT chk_inv_void_reason CHECK (voided_at IS NULL OR void_reason IS NOT NULL),

  -- THE invariant v1 lacked.
  CONSTRAINT chk_inv_fx CHECK (
    currency = base_currency OR (base_total_minor IS NOT NULL AND fx_rate IS NOT NULL)
  ),
  CONSTRAINT fk_inv_tenant   FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_inv_member   FOREIGN KEY (membership_id) REFERENCES memberships(id),
  CONSTRAINT fk_inv_enrolment FOREIGN KEY (enrolment_id) REFERENCES enrolments(id),
  CONSTRAINT fk_inv_currency FOREIGN KEY (currency)      REFERENCES currencies(code)
);

CREATE TABLE invoice_lines (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  invoice_id        INTEGER NOT NULL,
  item_type         TEXT  NOT NULL,
  description       TEXT NOT NULL,
  quantity          REAL NOT NULL DEFAULT 1,
  unit_amount_minor INTEGER       NOT NULL,
  tax_rate_pct      REAL NOT NULL DEFAULT 0,
  line_total_minor  INTEGER       NOT NULL,
  price_item_id     INTEGER NULL,     -- provenance: which price applied
  metadata          TEXT         NULL,

  CONSTRAINT fk_line_invoice FOREIGN KEY (invoice_id)    REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_line_price   FOREIGN KEY (price_item_id) REFERENCES price_items(id)
);

CREATE TABLE payments (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id         TEXT     NOT NULL,
  tenant_id         INTEGER NOT NULL,
  membership_id     INTEGER NULL,
  provider          TEXT  NOT NULL,      -- paystack|flutterwave|bank|cash|credit
  provider_ref      TEXT NULL,
  -- 24, not 16: the CHECK below permits 'awaiting_transfer' (17 chars) and
  -- 'partially_refunded' (18), so a 16-wide column contradicts its own
  -- constraint. SQLite ignores widths, so this only fails on MySQL — and it
  -- would have failed on the dominant status in the real data.
  status            TEXT  NOT NULL DEFAULT 'pending',
     -- pending|awaiting_transfer|succeeded|failed|refunded|partially_refunded|disputed
  currency          TEXT      NOT NULL,
  amount_minor      INTEGER       NOT NULL,
  fee_minor         INTEGER       NOT NULL DEFAULT 0,
  base_currency     TEXT      NOT NULL,
  base_amount_minor INTEGER       NULL,
  fx_rate           REAL NULL,
  fx_source         TEXT  NULL,
  received_at       TEXT  NULL,
  reconciled_at     TEXT  NULL,
  -- The provider's code, kept verbatim, plus our plain-language rendering.
  -- v1 stored neither, so "your payment did not go through" was the whole of
  -- what a learner could be told — and they would simply try the same card.
  failure_code      TEXT  NULL,
  failure_reason    TEXT NULL,
  metadata          TEXT         NULL,
  created_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  updated_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  row_version       INTEGER NOT NULL DEFAULT 1,
  UNIQUE (public_id),
  -- A provider reference is unique per provider: the guard against a webhook
  -- replay creating a second payment for one charge.
  UNIQUE (tenant_id, provider, provider_ref),
  CONSTRAINT chk_pay_status CHECK (status IN
    ('pending','awaiting_transfer','succeeded','failed','refunded','partially_refunded','disputed')),
  CONSTRAINT chk_pay_failure_reason CHECK (status <> 'failed' OR failure_reason IS NOT NULL),

  CONSTRAINT chk_pay_fx CHECK (
    currency = base_currency OR (base_amount_minor IS NOT NULL AND fx_rate IS NOT NULL)
  ),
  CONSTRAINT fk_pay_tenant   FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_pay_member   FOREIGN KEY (membership_id) REFERENCES memberships(id),
  CONSTRAINT fk_pay_currency FOREIGN KEY (currency)      REFERENCES currencies(code)
);

-- A payment may settle several invoices and an invoice may take several
-- payments, so allocation is its own table rather than a foreign key on either.
CREATE TABLE payment_allocations (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  payment_id    INTEGER NOT NULL,
  invoice_id    INTEGER NOT NULL,
  amount_minor  INTEGER       NOT NULL,
  allocated_at  TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  allocated_by  INTEGER NULL,
  UNIQUE (payment_id, invoice_id),

  CONSTRAINT fk_alloc_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
  CONSTRAINT fk_alloc_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id),
  CONSTRAINT chk_alloc_amount CHECK (amount_minor > 0)
);

CREATE TABLE refunds (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id         TEXT     NOT NULL,
  tenant_id         INTEGER NOT NULL,
  payment_id        INTEGER NOT NULL,
  amount_minor      INTEGER       NOT NULL,
  currency          TEXT      NOT NULL,
  reason            TEXT NULL,
  status            TEXT  NOT NULL DEFAULT 'pending',
  provider_ref      TEXT NULL,
  processed_at      TEXT  NULL,
  requested_by      INTEGER NULL,
  approved_by       INTEGER NULL,
  created_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  UNIQUE (public_id),

  CONSTRAINT fk_refund_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants(id),
  CONSTRAINT fk_refund_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
  CONSTRAINT chk_refund_amount CHECK (amount_minor > 0)
);

-- The ledger is the truth; invoices and payments are documents ABOUT the truth.
-- Every movement is a balanced pair (or set) sharing a transaction_id, which is
-- what makes "why does this learner owe ₦8,000" answerable without archaeology.
CREATE TABLE ledger_accounts (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER NOT NULL,
  code          TEXT  NOT NULL,     -- ar|cash|revenue|discount|refunds|fees
  name          TEXT NOT NULL,
  type          TEXT  NOT NULL,     -- asset|liability|income|expense|equity
  currency      TEXT      NOT NULL,
  is_active     INTEGER   NOT NULL DEFAULT 1,
  UNIQUE (tenant_id, code),
  CONSTRAINT fk_la_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);

CREATE TABLE ledger_entries (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id         INTEGER NOT NULL,
  transaction_id    TEXT     NOT NULL,      -- groups the balanced set
  account_id        INTEGER NOT NULL,
  membership_id     INTEGER NULL,
  -- Signed minor units: positive debit, negative credit. Sum per
  -- transaction_id MUST be zero; asserted by the load's verify step and by a
  -- periodic job, since MySQL cannot express a cross-row CHECK.
  amount_minor      INTEGER       NOT NULL,
  currency          TEXT      NOT NULL,
  base_amount_minor INTEGER       NULL,
  fx_rate           REAL NULL,
  source_type       TEXT  NOT NULL,      -- invoice|payment|refund|adjustment|fee
  source_id         INTEGER NULL,
  description       TEXT NULL,
  occurred_at       TEXT  NOT NULL,
  created_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  created_by        INTEGER NULL,

  CONSTRAINT fk_le_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants(id),
  CONSTRAINT fk_le_account FOREIGN KEY (account_id) REFERENCES ledger_accounts(id),
  CONSTRAINT fk_le_member  FOREIGN KEY (membership_id) REFERENCES memberships(id)
);

-- Punctuality / accountability charges, with the six-status vocabulary and the
-- request→decision flow the v1 ledger lacked entirely.
CREATE TABLE accountability_charges (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id         TEXT     NOT NULL,
  tenant_id         INTEGER NOT NULL,
  subject_membership_id INTEGER NOT NULL,
  session_id        INTEGER NULL,
  reason            TEXT  NOT NULL,
  minutes           SMALLINTEGER NOT NULL DEFAULT 0,
  currency          TEXT      NOT NULL,
  amount_minor      INTEGER       NOT NULL,
  base_amount_minor INTEGER       NULL,
  fx_rate           REAL NULL,
  status            TEXT  NOT NULL DEFAULT 'outstanding',
  resolved_by       INTEGER NULL,
  resolved_at       TEXT  NULL,
  resolution_note   TEXT NULL,
  created_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  UNIQUE (public_id),
  UNIQUE (subject_membership_id, session_id, reason),

  -- §15: "Rejected — Charge Maintained" is a decision the student must be able
  -- to read. A waiver or rejection with no note is a charge nobody can explain.
  CONSTRAINT chk_charge_resolution_note CHECK (
    status NOT IN ('waived','rejected') OR resolution_note IS NOT NULL),
  CONSTRAINT chk_charge_status CHECK (status IN
    ('pending','outstanding','paid','waived','disputed','reversed')),
  CONSTRAINT fk_charge_tenant  FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_charge_subject FOREIGN KEY (subject_membership_id) REFERENCES memberships(id),
  CONSTRAINT fk_charge_session FOREIGN KEY (session_id) REFERENCES class_sessions(id)
);

CREATE TABLE accountability_exceptions (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id         INTEGER NOT NULL,
  charge_id         INTEGER NOT NULL,
  requested_by      INTEGER NULL,
  reason_code       TEXT  NOT NULL,
  detail            TEXT NULL,
  status            TEXT  NOT NULL DEFAULT 'pending',   -- pending|approved|rejected
  decided_by        INTEGER NULL,
  decided_at        TEXT  NULL,
  decision_note     TEXT NULL,
  created_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),

  CONSTRAINT fk_exc_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_exc_charge FOREIGN KEY (charge_id) REFERENCES accountability_charges(id) ON DELETE CASCADE
);

-- =============================================================================
-- LAYER G · PLATFORM SERVICES
-- =============================================================================

-- Tamper-evident audit. row_hash = sha256(prev_hash || canonical(row)), so any
-- retro-edit or deletion breaks the chain from that point and a verifier can
-- prove the log intact. For an academy issuing certificates and charging fees,
-- that is the difference between "our records say" and "our records can be
-- shown not to have been altered".
CREATE TABLE audit_log (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER NULL,          -- NULL = platform-level action
  actor_user_id INTEGER NULL,
  actor_label   TEXT NULL,             -- credential used (e.g. break-glass)
  action        TEXT  NOT NULL,
  entity_type   TEXT  NOT NULL,
  entity_id     INTEGER NULL,
  before_state  TEXT         NULL,
  after_state   TEXT         NULL,
  metadata      TEXT         NULL,
  ip            BLOB NULL,
  user_agent    TEXT NULL,
  request_id    TEXT     NULL,             -- ties a burst to one request
  occurred_at   TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  prev_hash     TEXT     NULL,
  row_hash      TEXT     NOT NULL,

  CONSTRAINT fk_audit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);

-- The outbox. Anything that must reach the outside world is written HERE in the
-- same transaction as the state change that caused it, then delivered by a
-- worker. This removes v1's failure mode where a payment settled, the receipt
-- email threw, and the two facts disagreed with nobody able to say which won.
CREATE TABLE outbox_messages (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id       INTEGER NULL,
  topic           TEXT  NOT NULL,       -- payment.succeeded|enrolment.activated
  payload         TEXT         NOT NULL,
  status          TEXT  NOT NULL DEFAULT 'pending',
     -- pending|delivering|delivered|failed|dead
  attempts        SMALLINTEGER NOT NULL DEFAULT 0,
  max_attempts    SMALLINTEGER NOT NULL DEFAULT 8,
  available_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),  -- backoff
  last_error      TEXT NULL,
  delivered_at    TEXT  NULL,
  -- A dead message is retained, not discarded: "we never sent it" must be
  -- discoverable, and a silent drop is how integrations rot unnoticed.
  created_at      TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),

  CONSTRAINT fk_outbox_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);

-- Inbound webhooks, deduplicated by provider event id. The unique key is the
-- replay guard; `signature_verified` records that HMAC passed, so a later
-- dispute can distinguish "we accepted it" from "we verified it".
CREATE TABLE webhook_events (
  id                  INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id           INTEGER NULL,
  provider            TEXT  NOT NULL,
  event_id            TEXT NOT NULL,
  event_type          TEXT  NULL,
  signature_verified  INTEGER   NOT NULL DEFAULT 0,
  payload             TEXT         NOT NULL,
  processed_at        TEXT  NULL,
  process_result      TEXT NULL,
  received_at         TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  UNIQUE (provider, event_id),

  CONSTRAINT fk_webhook_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);

-- Idempotency for mutating API calls: the same key returns the first response
-- rather than performing the action twice.
CREATE TABLE idempotency_keys (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id       INTEGER NULL,      -- NULL = platform-scoped request
  -- 0 = platform, otherwise the tenant id. This one is not a tidiness point:
  -- with a nullable tenant_id the unique key below does not constrain
  -- platform-scoped requests at all, so a retried payment webhook inserts a
  -- second row and is processed twice. Idempotency that only works for some
  -- callers is not idempotency.
  tenant_scope    INTEGER NOT NULL DEFAULT 0,
  idem_key        TEXT NOT NULL,
  endpoint        TEXT NOT NULL,
  request_hash    TEXT     NOT NULL,   -- same key + different body = conflict
  response_status SMALLINTEGER NULL,
  response_body   TEXT         NULL,
  locked_at       TEXT  NULL,
  completed_at    TEXT  NULL,
  expires_at      TEXT  NOT NULL,
  created_at      TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  UNIQUE (tenant_scope, idem_key, endpoint)

);

-- Consent, per purpose, with the evidence of how it was given. Withdrawal is a
-- new row, never an update: "when did they consent, and when did they stop"
-- must both be answerable.
CREATE TABLE consents (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id         INTEGER NOT NULL,
  membership_id     INTEGER NOT NULL,
  purpose           TEXT  NOT NULL,     -- media|photo|data|marketing|recording
  is_granted        INTEGER   NOT NULL,
  granted_by_membership_id INTEGER NULL,  -- a guardian, for a minor
  evidence          TEXT         NULL,         -- form version, IP, wording shown
  effective_from    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  effective_to      TEXT  NULL,
  created_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),

  CONSTRAINT fk_consent_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_consent_member FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE
);

-- Subject access / erasure requests, with the workflow rather than an ad-hoc
-- email thread. Retention periods are configured per tenant, not hardcoded.
CREATE TABLE data_subject_requests (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id         TEXT     NOT NULL,
  tenant_id         INTEGER NOT NULL,
  membership_id     INTEGER NULL,
  subject_email     TEXT NOT NULL,
  kind              TEXT  NOT NULL,     -- access|erasure|rectify|export|restrict
  status            TEXT  NOT NULL DEFAULT 'received',
  received_at       TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  due_at            TEXT  NULL,         -- statutory clock
  completed_at      TEXT  NULL,
  handled_by        INTEGER NULL,
  outcome_note      TEXT NULL,
  -- What was retained despite an erasure, and on what legal basis. An erasure
  -- that silently keeps financial records is a compliance problem; one that
  -- records WHY it kept them is a defensible position.
  retained_basis    TEXT         NULL,
  UNIQUE (public_id),

  CONSTRAINT fk_dsr_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);

-- Bounded EAV: the definition constrains type and validation, so this is an
-- extension mechanism rather than an untyped bag. It exists so onboarding a
-- second academy that tracks "Suzuki book number" needs no migration.
CREATE TABLE custom_field_defs (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER NOT NULL,
  entity_type   TEXT  NOT NULL,     -- memberships|enrolments|applications
  field_key     TEXT  NOT NULL,
  label         TEXT NOT NULL,
  data_type     TEXT  NOT NULL,     -- string|int|decimal|bool|date|enum|json
  options       TEXT         NULL,         -- enum choices
  validation    TEXT         NULL,         -- min/max/regex/required
  is_required   INTEGER   NOT NULL DEFAULT 0,
  is_pii        INTEGER   NOT NULL DEFAULT 0,   -- drives export/erasure handling
  sort_order    INTEGER          NOT NULL DEFAULT 0,
  is_active     INTEGER   NOT NULL DEFAULT 1,
  created_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  UNIQUE (tenant_id, entity_type, field_key),
  CONSTRAINT fk_cfd_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE custom_field_values (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER NOT NULL,
  field_def_id  INTEGER NOT NULL,
  entity_id     INTEGER NOT NULL,
  value_text    TEXT         NULL,
  value_number  REAL NULL,
  value_date    TEXT  NULL,
  value_bool    INTEGER   NULL,
  updated_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  UNIQUE (field_def_id, entity_id),

  -- Typed columns rather than one text blob, so filtering and sorting on a
  -- custom numeric field uses an index instead of a cast.

  CONSTRAINT fk_cfv_def    FOREIGN KEY (field_def_id) REFERENCES custom_field_defs(id) ON DELETE CASCADE,
  CONSTRAINT fk_cfv_tenant FOREIGN KEY (tenant_id)    REFERENCES tenants(id)
);

-- Files, with the access policy on the row rather than in the URL. v1 relied on
-- an unguessable key for recordings of children; `visibility` + the owning
-- entity is what lets a proxy answer "may THIS caller see it".
CREATE TABLE attachments (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id     TEXT     NOT NULL,
  tenant_id     INTEGER NOT NULL,
  entity_type   TEXT  NOT NULL,
  entity_id     INTEGER NULL,
  kind          TEXT  NOT NULL,     -- headshot|take|document|score|recording
  storage       TEXT  NOT NULL DEFAULT 'local',   -- local|drive|s3|cloudinary
  storage_key   TEXT NOT NULL,
  mime_type     TEXT NULL,
  byte_size     INTEGER NULL,
  checksum      TEXT     NULL,
  visibility    TEXT  NOT NULL DEFAULT 'private', -- private|tenant|public
  uploaded_by   INTEGER NULL,
  created_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  deleted_at    TEXT  NULL,
  UNIQUE (public_id),

  CONSTRAINT fk_att_tenant2 FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);

-- Notifications and their delivery attempts, split so one notification can be
-- delivered over several channels with independent outcomes.
CREATE TABLE notifications (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER NOT NULL,
  membership_id INTEGER NOT NULL,
  type          TEXT  NOT NULL,
  title         TEXT NOT NULL,
  body          TEXT         NULL,
  url           TEXT NULL,
  -- The button's words. "Open" tells the reader nothing; "Pay ₦120,000" does.
  action_label  TEXT  NULL,
  severity      TEXT  NOT NULL DEFAULT 'info',
  -- Suppresses a repeat of the same message. Without it every nightly sweep
  -- re-notifies, the inbox fills with duplicates, and the reader stops looking
  -- — which costs them the ONE message that mattered.
  dedupe_key    TEXT NULL,
  -- Groups a burst into one line ("3 new results"), so a busy day does not read
  -- as an incident.
  group_key     TEXT NULL,
  -- After this the message is stale and must not be shown. A reminder about a
  -- class that already happened actively misleads.
  expires_at    TEXT  NULL,
  read_at       TEXT  NULL,
  -- Read is not understood. Acknowledged is an explicit "I have seen this",
  -- which is what a safeguarding or policy notice actually needs.
  acknowledged_at TEXT NULL,
  created_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  UNIQUE (membership_id, dedupe_key),

  CONSTRAINT chk_notif_severity CHECK (severity IN ('info','success','attention','warning','critical')),
  CONSTRAINT fk_notif_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_notif_member FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE
);

CREATE TABLE message_deliveries (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id       INTEGER NOT NULL,
  notification_id INTEGER NULL,
  channel         TEXT  NOT NULL,     -- email|sms|whatsapp|push
  template_key    TEXT  NULL,
  recipient       TEXT NOT NULL,
  subject         TEXT NULL,
  status          TEXT  NOT NULL DEFAULT 'queued',
  provider        TEXT  NULL,
  provider_ref    TEXT NULL,
  attempts        SMALLINTEGER NOT NULL DEFAULT 0,
  last_error      TEXT NULL,
  sent_at         TEXT  NULL,
  opened_at       TEXT  NULL,
  created_at      TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),

  CONSTRAINT fk_delivery_tenant FOREIGN KEY (tenant_id)       REFERENCES tenants(id),
  CONSTRAINT fk_delivery_notif  FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE SET NULL
);

-- Background work, with a claim column so several workers can run without two
-- of them taking the same job.
CREATE TABLE jobs (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER NULL,
  queue         TEXT  NOT NULL DEFAULT 'default',
  job_type      TEXT  NOT NULL,
  payload       TEXT         NULL,
  status        TEXT  NOT NULL DEFAULT 'pending',
  attempts      SMALLINTEGER NOT NULL DEFAULT 0,
  max_attempts  SMALLINTEGER NOT NULL DEFAULT 5,
  available_at  TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  claimed_by    TEXT  NULL,
  claimed_at    TEXT  NULL,
  completed_at  TEXT  NULL,
  last_error    TEXT NULL,
  created_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now'))

);

-- =============================================================================
-- LAYER H · CLARITY
-- =============================================================================
-- Every table above records what is TRUE. This layer records what a person can
-- be TOLD, and it exists because v1 could not answer three questions that every
-- user of the system asks:
--
--   "What does this word mean?"   — a learner shown `carried_forward` or
--     `developing` has no idea whether that is good news. v1 leaked its internal
--     status vocabulary straight into the portal, and each screen invented its
--     own gloss, so the same status read differently in three places.
--   "What do I need to do?"       — the portal could list classes, invoices and
--     announcements, but nothing could say "you have two things to do", because
--     no table held the union.
--   "Why did that happen?"        — a rejected application, a withdrawn
--     enrolment, a cancelled session and a failed payment could all be recorded
--     with no reason at all, which meant the notification had nothing to say
--     and the learner had to phone somebody.
--
-- None of this is presentation polish. A status word with no agreed meaning, a
-- to-do list nobody can compute, and a decision with no reason are data
-- problems that no amount of front-end work can fix.
-- =============================================================================

-- The single glossary. One row per (entity, status, audience, locale), so the
-- student portal, the guardian portal, the admin screen and the email template
-- all describe a status with the SAME words — and describe it differently to
-- different people, which is the part a single `label` column cannot do.
--
-- `tenant_id` NULL is the platform default; a row with a tenant_id overrides it
-- for that academy, so an academy can rename "cohort" to "class" without a
-- deployment and without forking the vocabulary.
CREATE TABLE status_definitions (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER NULL,        -- NULL = platform default (carries the FK)
  -- Uniqueness companion, same reason as deleted_seq: a unique key ending in a
  -- NULLABLE tenant_id would treat every platform-default row as distinct from
  -- every other, so the glossary could hold five conflicting definitions of one
  -- status. 0 = platform default, otherwise the tenant id. Kept in step with
  -- tenant_id by the application; the pair is written together or not at all.
  tenant_scope  INTEGER NOT NULL DEFAULT 0,
  entity_type   TEXT  NOT NULL,       -- application|enrolment|invoice|module_progress|…
  status        TEXT  NOT NULL,
  audience      TEXT  NOT NULL DEFAULT 'learner',  -- learner|guardian|staff|admin
  locale        TEXT  NOT NULL DEFAULT 'en',
  -- What it is called. Short enough for a chip.
  label         TEXT  NOT NULL,
  -- What it MEANS, in a sentence, in the second person. This is the field that
  -- stops a learner guessing.
  meaning       TEXT NOT NULL,
  -- What the reader should do about it, or NULL when the honest answer is
  -- "nothing, we will contact you" — which is itself worth saying.
  next_step     TEXT NULL,
  -- Tone drives colour and icon. Named rather than left to each screen, because
  -- the same status rendered green here and red there is worse than no colour.
  tone          TEXT  NOT NULL DEFAULT 'neutral',
     -- neutral|positive|progress|attention|warning|critical
  -- Whether this state is one the reader can act their way out of. A learner
  -- shown an unactionable state alongside actionable ones will try to act.
  is_actionable INTEGER   NOT NULL DEFAULT 0,
  is_terminal   INTEGER   NOT NULL DEFAULT 0,
  sort_order    SMALLINTEGER NOT NULL DEFAULT 0,   -- pipeline/progress order
  updated_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  UNIQUE (tenant_scope, entity_type, status, audience, locale),

  CONSTRAINT chk_statusdef_tone CHECK (tone IN
    ('neutral','positive','progress','attention','warning','critical')),
  CONSTRAINT chk_statusdef_audience CHECK (audience IN ('learner','guardian','staff','admin')),
  CONSTRAINT fk_statusdef_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

-- "What do I need to do?" — one table, every role. A learner's outstanding
-- invoice, an instructor's overdue progress report, a guardian's unsigned
-- consent form and an admin's unassigned application are the same shape of
-- fact, and until they live in one place no screen can say "you have three
-- things to do" without four queries and a guess at the ordering.
--
-- Rows are DERIVED — written by the same code that creates the underlying
-- obligation, cleared when it is met. `dedupe_key` is what stops a nightly
-- sweep from stacking five copies of "pay your invoice", which is the failure
-- that turns a to-do list into noise the user learns to ignore.
CREATE TABLE action_items (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id     TEXT     NOT NULL,
  tenant_id     INTEGER NOT NULL,
  membership_id INTEGER NOT NULL,
  kind          TEXT  NOT NULL,       -- pay_invoice|sign_consent|submit_work|…
  -- Written for the reader, not the developer: "Pay your October tuition",
  -- never "invoice.outstanding".
  title         TEXT NOT NULL,
  -- Why it matters to THEM. An instruction without a consequence gets deferred.
  detail        TEXT NULL,
  action_url    TEXT NULL,
  action_label  TEXT  NULL,           -- the button's words
  -- What it is about, so the item can be cleared by the code that resolves it.
  source_type   TEXT  NULL,
  source_id     INTEGER NULL,
  priority      SMALLINTEGER NOT NULL DEFAULT 50,   -- lower sorts first
  -- Blocking items stop something the learner is trying to do. They are
  -- surfaced differently, and MUST be rare — a list where everything blocks is
  -- a list with no priority at all.
  is_blocking   INTEGER   NOT NULL DEFAULT 0,
  due_at        TEXT  NULL,
  -- Set when the obligation is met. Kept rather than deleted, so "you did this"
  -- is answerable and a resolved item never silently reappears.
  completed_at  TEXT  NULL,
  -- A reader may put an optional item aside. Distinct from completed: the thing
  -- was not done, and an admin looking at why still needs to see it.
  dismissed_at  TEXT  NULL,
  -- 0 while the item is live, the row id once completed or dismissed — so the
  -- same obligation can recur next term without the old row blocking it.
  resolved_seq  INTEGER NOT NULL DEFAULT 0,
  dedupe_key    TEXT NOT NULL,
  created_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  updated_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  UNIQUE (public_id),
  UNIQUE (tenant_id, membership_id, dedupe_key, resolved_seq),

  CONSTRAINT fk_action_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_action_member FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE
);

-- How this person needs to be spoken to. v1 scattered these across ad-hoc
-- columns and localStorage, which meant a preference set on a phone did not
-- apply to the email the system sent an hour later — so a learner who asked for
-- large text and Yoruba got neither anywhere it mattered.
--
-- Keyed to the USER, not the membership: a person who needs a dyslexia-friendly
-- face needs it at every academy they belong to.
CREATE TABLE user_preferences (
  user_id           INTEGER NOT NULL PRIMARY KEY,
  locale            TEXT  NULL,       -- overrides tenant default
  timezone          TEXT  NULL,
  -- What money should be SHOWN in. Accounting stays in the tenant's base
  -- currency; a guardian in Lagos should still read naira. v1 showed some
  -- screens in USD and some in NGN with no rule, which reads as a pricing error.
  display_currency  TEXT      NULL,
  date_format       TEXT  NULL,       -- a bare 03/04 means two dates
  -- Channels. An empty set is a valid answer and must be honoured; a system
  -- that keeps emailing someone who opted out is not a preference system.
  channel_email     INTEGER   NOT NULL DEFAULT 1,
  channel_sms       INTEGER   NOT NULL DEFAULT 0,
  channel_whatsapp  INTEGER   NOT NULL DEFAULT 0,
  channel_push      INTEGER   NOT NULL DEFAULT 1,
  -- Batch non-urgent notifications instead of sending each one. Urgency is a
  -- property of the notification, so a digest never delays a critical message.
  digest_frequency  TEXT  NOT NULL DEFAULT 'immediate',  -- immediate|daily|weekly
  quiet_hours_start TIME         NULL,
  quiet_hours_end   TIME         NULL,
  -- Accessibility. These are stored server-side precisely so they survive a new
  -- device and reach server-rendered pages and emails, not just the SPA.
  reduce_motion     INTEGER   NOT NULL DEFAULT 0,
  high_contrast     INTEGER   NOT NULL DEFAULT 0,
  dyslexia_font     INTEGER   NOT NULL DEFAULT 0,
  text_scale_pct    SMALLINTEGER NOT NULL DEFAULT 100,
  low_bandwidth     INTEGER   NOT NULL DEFAULT 0,
  captions_default  INTEGER   NOT NULL DEFAULT 0,
  -- Plain-language mode: shorter sentences and no jargon. Useful for younger
  -- learners and for guardians reading in a second language.
  plain_language    INTEGER   NOT NULL DEFAULT 0,
  onboarding_done_at TEXT NULL,
  updated_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  CONSTRAINT chk_pref_digest CHECK (digest_frequency IN ('immediate','daily','weekly')),
  -- 200% zoom is the WCAG 1.4.4 requirement; the floor stops a mis-set value
  -- from rendering the interface unreadable and unrecoverable.
  CONSTRAINT chk_pref_scale  CHECK (text_scale_pct BETWEEN 80 AND 250),
  CONSTRAINT fk_pref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Maps every v1 identifier to its v2 row. Written by the ETL, kept forever:
-- old references live in emails, receipts and people's memories, so
-- "application MAM-2026-0412" must remain resolvable after cutover.
CREATE TABLE legacy_reference_map (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id       INTEGER NOT NULL,
  legacy_table    TEXT  NOT NULL,
  legacy_id       TEXT  NOT NULL,
  legacy_ref      TEXT  NULL,        -- human-facing reference, if any
  v2_table        TEXT  NOT NULL,
  v2_id           INTEGER NOT NULL,
  migrated_at     TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  note            TEXT NULL,
  UNIQUE (tenant_id, legacy_table, legacy_id),

  CONSTRAINT fk_legacy_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);

-- Schema baseline. A fresh v2 install starts AT the target rather than
-- replaying v1's 81 migrations.
CREATE TABLE schema_version (
  version     INTEGER NOT NULL PRIMARY KEY,
  applied_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  note        TEXT NULL
);

INSERT INTO schema_version (version, note) VALUES (200, 'schema-v2 baseline');

-- Seed the currencies the platform ships knowing about. Everything else is
-- tenant data and arrives via the ETL or the admin.
INSERT INTO currencies (code, name, exponent, symbol) VALUES
  ('USD','US Dollar',2,'$'),
  ('NGN','Nigerian Naira',2,'₦'),
  ('GBP','Pound Sterling',2,'£'),
  ('EUR','Euro',2,'€'),
  ('JPY','Japanese Yen',0,'¥');

-- Secondary indexes (inline KEY clauses in the canonical file).
CREATE INDEX ix_tenants_status ON tenants (status, deleted_at);
CREATE INDEX ix_centres_tenant ON centres (tenant_id, is_active);
CREATE INDEX ix_users_status ON users (status, deleted_at);
CREATE INDEX ix_users_name ON users (full_name);
CREATE INDEX ix_identity_user ON user_identities (user_id);
CREATE INDEX ix_memberships_stage ON memberships (tenant_id, stage, stage_entered_at);
CREATE INDEX ix_memberships_centre ON memberships (tenant_id, centre_id, status);
CREATE INDEX ix_mr_role ON membership_roles (role_id);
CREATE INDEX ix_sessions_user ON auth_sessions (user_id, expires_at);
CREATE INDEX ix_api_tenant ON api_clients (tenant_id, revoked_at);
CREATE INDEX ix_lifecycle_membership ON lifecycle_events (membership_id, occurred_at);
CREATE INDEX ix_lifecycle_tenant ON lifecycle_events (tenant_id, to_stage, occurred_at);
CREATE INDEX ix_applications_pipeline ON applications (tenant_id, status, submitted_at);
CREATE INDEX ix_applications_member ON applications (membership_id);
CREATE INDEX ix_guardianship_learner ON guardianships (learner_membership_id);
CREATE INDEX ix_courses_discipline ON courses (tenant_id, discipline, is_active);
CREATE INDEX ix_pc_course ON programme_courses (course_id);
CREATE INDEX ix_modules_course ON modules (tenant_id, course_id, level_id, week_no);
CREATE INDEX ix_rubrics_course ON rubrics (tenant_id, course_id, level_id, is_published);
CREATE INDEX ix_criteria_rubric ON rubric_criteria (rubric_id, sort_order);
CREATE INDEX ix_cohorts_lookup ON cohorts (tenant_id, course_id, level_id, status);
CREATE INDEX ix_cohorts_centre ON cohorts (tenant_id, centre_id, starts_on);
CREATE INDEX ix_cohorts_instructor ON cohorts (lead_instructor_membership_id, status);
CREATE INDEX ix_cohort_staff_member ON cohort_staff (membership_id);
CREATE INDEX ix_enrolments_member ON enrolments (tenant_id, membership_id, status);
CREATE INDEX ix_enrolments_cohort ON enrolments (cohort_id, status);
CREATE INDEX ix_sessions_cohort ON class_sessions (cohort_id, scheduled_start_utc);
CREATE INDEX ix_sessions_when ON class_sessions (tenant_id, scheduled_start_utc, status);
CREATE INDEX ix_attendance_enrolment ON attendance (enrolment_id, status);
CREATE INDEX ix_attendance_tenant ON attendance (tenant_id, status, created_at);
CREATE INDEX ix_progress_status ON module_progress (tenant_id, status);
CREATE INDEX ix_carry_enrolment ON module_carryforwards (enrolment_id, occurred_at);
CREATE INDEX ix_fx_lookup ON fx_rates (base_currency, quote_currency, observed_at);
CREATE INDEX ix_price_books_window ON price_books (tenant_id, valid_from, valid_to);
CREATE INDEX ix_price_items_book ON price_items (price_book_id, item_type);
CREATE INDEX ix_invoices_member ON invoices (tenant_id, membership_id, status);
CREATE INDEX ix_invoices_due ON invoices (tenant_id, status, due_at);
CREATE INDEX ix_lines_invoice ON invoice_lines (invoice_id);
CREATE INDEX ix_payments_member ON payments (tenant_id, membership_id, status);
CREATE INDEX ix_payments_reporting ON payments (tenant_id, status, received_at);
CREATE INDEX ix_alloc_invoice ON payment_allocations (invoice_id);
CREATE INDEX ix_refunds_payment ON refunds (payment_id);
CREATE INDEX ix_ledger_txn ON ledger_entries (transaction_id);
CREATE INDEX ix_ledger_account ON ledger_entries (tenant_id, account_id, occurred_at);
CREATE INDEX ix_ledger_member ON ledger_entries (tenant_id, membership_id, occurred_at);
CREATE INDEX ix_charges_status ON accountability_charges (tenant_id, status, created_at);
CREATE INDEX ix_exceptions_charge ON accountability_exceptions (charge_id);
CREATE INDEX ix_exceptions_queue ON accountability_exceptions (tenant_id, status, id);
CREATE INDEX ix_audit_entity ON audit_log (tenant_id, entity_type, entity_id, occurred_at);
CREATE INDEX ix_audit_actor ON audit_log (actor_user_id, occurred_at);
CREATE INDEX ix_audit_action ON audit_log (tenant_id, action, occurred_at);
CREATE INDEX ix_outbox_claim ON outbox_messages (status, available_at, id);
CREATE INDEX ix_outbox_topic ON outbox_messages (tenant_id, topic, created_at);
CREATE INDEX ix_webhook_unprocessed ON webhook_events (processed_at, received_at);
CREATE INDEX ix_idem_expiry ON idempotency_keys (expires_at);
CREATE INDEX ix_consents_current ON consents (tenant_id, membership_id, purpose, effective_to);
CREATE INDEX ix_dsr_queue ON data_subject_requests (tenant_id, status, due_at);
CREATE INDEX ix_cfv_entity ON custom_field_values (tenant_id, entity_id);
CREATE INDEX ix_cfv_number ON custom_field_values (field_def_id, value_number);
CREATE INDEX ix_attachments_entity ON attachments (tenant_id, entity_type, entity_id);
CREATE INDEX ix_notifications_inbox ON notifications (membership_id, read_at, id);
CREATE INDEX ix_notifications_live ON notifications (membership_id, expires_at, read_at);
CREATE INDEX ix_delivery_status ON message_deliveries (tenant_id, status, created_at);
CREATE INDEX ix_delivery_recipient ON message_deliveries (recipient, created_at);
CREATE INDEX ix_jobs_claim ON jobs (queue, status, available_at, id);
CREATE INDEX ix_statusdef_lookup ON status_definitions (entity_type, audience, locale);
CREATE INDEX ix_action_open ON action_items (membership_id, completed_at, dismissed_at, priority, due_at);
CREATE INDEX ix_action_due ON action_items (tenant_id, due_at, completed_at);
CREATE INDEX ix_legacy_ref ON legacy_reference_map (tenant_id, legacy_ref);
CREATE INDEX ix_legacy_target ON legacy_reference_map (v2_table, v2_id);
