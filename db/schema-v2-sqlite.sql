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
  -- ── The four kinds of relief, kept SEPARATE ──────────────────────────────
  -- These were one flat `discount_minor`, and collapsing them changes what the
  -- academy's books say happened. They are four MECHANISMS, not four discounts:
  --
  --   waiver_minor   the academy chose not to BILL it. Non-revenue, never a cash
  --                  entry, and it lives on the LINE (see invoice_lines) so the
  --                  invoice can show what was charged AND what was waived.
  --   aid_minor      GIFT AID actually paid from the aid pool — a bursary or a
  --                  scholarship. The line stands at full value because the money
  --                  is real, and the credit is a real transfer (invoice_credits).
  --   sibling_minor  a DISCOUNT on the POST-AID balance, capped at the published
  --                  maximum. After aid, because a percentage of the gross would
  --                  discount money the aid pool paid.
  --
  -- The order is fixed and every stage is stored, so a family can be shown the
  -- arithmetic and an auditor can take it apart, rather than meeting one net
  -- figure nobody can explain:
  --   subtotal → less waivers → less aid → less sibling → total
  --
  -- `discount_minor` remains for a plain commercial discount that is none of the
  -- three (an early-payment reduction, say), so the aid figures stay meaningful.
  waiver_minor      INTEGER       NOT NULL DEFAULT 0,
  aid_minor         INTEGER       NOT NULL DEFAULT 0,
  sibling_minor     INTEGER       NOT NULL DEFAULT 0,
  discount_minor    INTEGER       NOT NULL DEFAULT 0,
  total_minor       INTEGER       NOT NULL DEFAULT 0,
  amount_paid_minor INTEGER       NOT NULL DEFAULT 0,
  -- Dunning, not disconnection: reminders at day 0, 3 and 7, then a grace window,
  -- then a conversation. There is deliberately NO column here that suspends
  -- anything — an access hold must never touch a learner's academic record, a
  -- safeguarding file, or a minor's report, and the way to keep a refusal is to
  -- leave the mechanism unbuilt.
  grace_until       TEXT         NULL,
  dunning_stage     TINYINTEGER NOT NULL DEFAULT 0,
  dunning_last_at   TEXT  NULL,
  term_id           INTEGER NULL,
  term_label        TEXT NULL,
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
  -- Relief is never negative, never exceeds what was billed, and the stages add
  -- up. A negative minor amount is not a refund — a refund is its own row.
  CONSTRAINT chk_inv_relief CHECK (waiver_minor >= 0 AND aid_minor >= 0
        AND sibling_minor >= 0 AND discount_minor >= 0 AND amount_paid_minor >= 0
        AND waiver_minor + aid_minor + sibling_minor + discount_minor <= subtotal_minor + tax_minor),
  CONSTRAINT chk_inv_total CHECK (total_minor
        = subtotal_minor + tax_minor - waiver_minor - aid_minor - sibling_minor - discount_minor),
  -- An ISSUED invoice has a date and something to pay by. Draft and VOID are both
  -- exempt: a draft raised against the wrong learner is voided rather than
  -- deleted, and never had an issue date to state.
  CONSTRAINT chk_inv_issued CHECK (status IN ('draft','void')
        OR (issued_at IS NOT NULL AND due_at IS NOT NULL)),
  CONSTRAINT fk_inv_term     FOREIGN KEY (term_id)       REFERENCES academic_terms(id) ON DELETE SET NULL,
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
  -- A WAIVER reduces THIS LINE and is never a cash entry. Kept beside the amount
  -- rather than netted into it, so the invoice can show both what was charged and
  -- what was waived — which is what a family needs to understand the bill and
  -- what an auditor needs to see that no money moved.
  waiver_minor      INTEGER       NOT NULL DEFAULT 0,
  waiver_reason     TEXT NULL,
  price_item_id     INTEGER NULL,     -- provenance: which price applied
  metadata          TEXT         NULL,

  -- A waiver cannot exceed the line it reduces, and it states its reason: it is
  -- the academy choosing not to bill, which is a decision and not a rounding.
  CONSTRAINT chk_line_waiver CHECK (waiver_minor >= 0 AND waiver_minor <= line_total_minor
        AND (waiver_minor = 0 OR waiver_reason IS NOT NULL)),
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

-- =============================================================================
-- LAYER I · ACADEMIC CALENDAR
-- =============================================================================
-- v2 had no term table: `cohorts.term` was a free string, which is enough for a
-- label on a page and not enough for the one thing a calendar is actually needed
-- for. **This is the only authority on a refund.** Before v1 grew these tables
-- there was no refund function anywhere in the codebase and every refund was a
-- manual decision with nothing to check it against.
--
-- Four invariants, all of which are easy to "simplify" into being wrong:
--   • A refund percentage is COMPUTED from a date, never stored per learner. Two
--     families withdrawing the same day must get the same answer, and the answer
--     must be reproducible a year later in an audit.
--   • `term_refund_schedule` rows are the truth once seeded. A family is entitled
--     to the table that was published when they enrolled, so re-saving a term's
--     dates must never silently revert an edited table.
--   • `term_census` is a SNAPSHOT and append-only (UNIQUE on term). "Enrolment as
--     of the census date" must not move when somebody withdraws in week nine —
--     recompute-on-read is the commonest way an official figure rewrites history.
--   • Deadlines are adjudicated in the term's OWN timezone and displayed in the
--     reader's. A diaspora guardian in New York must not get a different refund
--     from a Lagos guardian because their clocks disagree about the date.

CREATE TABLE academic_terms (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id         TEXT     NOT NULL,
  tenant_id         INTEGER NOT NULL,
  -- The academy year this term sits in ("2026/2027"), and the term's own code.
  -- MAM runs TONI / MEDI / SUMMER / DOMI, renameable per tenant.
  year_label        TEXT  NOT NULL,
  code              TEXT  NOT NULL,
  term_no           TINYINTEGER NOT NULL DEFAULT 1,
  -- What a family sees on a receipt. Snapshotted onto reports and invoices,
  -- because a tenant may rename a term and a published document must keep the
  -- name it was published under.
  label             TEXT NOT NULL,
  starts_on         TEXT         NOT NULL,
  ends_on           TEXT         NOT NULL,
  add_drop_deadline TEXT         NULL,
  withdrawal_deadline TEXT       NULL,
  census_date       TEXT         NULL,
  teaching_weeks    TINYINTEGER NOT NULL DEFAULT 12,
  -- IANA zone, because a TEXT deadline is a wall-clock fact about a place.
  timezone          TEXT  NOT NULL DEFAULT 'Africa/Lagos',
  status            TEXT  NOT NULL DEFAULT 'planned',   -- planned|active|closed
  notes             TEXT NULL,
  created_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  updated_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  deleted_at        TEXT  NULL,
  deleted_seq       INTEGER NOT NULL DEFAULT 0,
  created_by        INTEGER NULL,
  row_version       INTEGER NOT NULL DEFAULT 1,
  UNIQUE (public_id),
  UNIQUE (tenant_id, year_label, code, deleted_seq),

  CONSTRAINT chk_terms_status CHECK (status IN ('planned','active','closed')),
  -- A term must not end before it starts, and every deadline must fall inside it
  -- and in the right order. Enforced here rather than in PHP because a bad row
  -- corrupts every refund computed against it.
  CONSTRAINT chk_terms_window CHECK (ends_on >= starts_on),
  CONSTRAINT chk_terms_addrop CHECK (add_drop_deadline IS NULL
        OR (add_drop_deadline >= starts_on AND add_drop_deadline <= ends_on)),
  CONSTRAINT chk_terms_wdr CHECK (withdrawal_deadline IS NULL
        OR (withdrawal_deadline >= starts_on AND withdrawal_deadline <= ends_on)),
  CONSTRAINT chk_terms_census CHECK (census_date IS NULL
        OR (census_date >= starts_on AND census_date <= ends_on)),
  CONSTRAINT chk_terms_order CHECK (add_drop_deadline IS NULL OR withdrawal_deadline IS NULL
        OR withdrawal_deadline >= add_drop_deadline),
  CONSTRAINT fk_terms_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);

-- The published week-by-week refund table. Rows are the truth once seeded; a
-- constant only ever SEEDS them.
CREATE TABLE term_refund_schedule (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER NOT NULL,
  term_id       INTEGER NOT NULL,
  -- 0 means "on or before the add/drop date".
  week_no       TINYINTEGER NOT NULL,
  refund_pct    REAL NOT NULL,
  -- What the transcript says: an add/drop leaves no mark, a withdrawal leaves W.
  transcript_mark TEXT  NULL,
  note          TEXT NULL,
  created_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  UNIQUE (tenant_id, term_id, week_no),
  CONSTRAINT chk_refund_pct CHECK (refund_pct >= 0 AND refund_pct <= 100),
  CONSTRAINT fk_refundsched_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_refundsched_term FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE CASCADE
);

-- Enrolment as of the census date. A SNAPSHOT: one row per term, append-only.
CREATE TABLE term_census (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id         INTEGER NOT NULL,
  term_id           INTEGER NOT NULL,
  taken_on          TEXT         NOT NULL,
  enrolled_count    INTEGER NOT NULL,
  active_count      INTEGER NOT NULL,
  -- NULLABLE, unlike the other two: a census taken by a system that did not
  -- count withdrawals has no figure, and 0 would state that nobody withdrew.
  withdrawn_count   INTEGER NULL,
  breakdown         TEXT         NULL,      -- by programme/level/centre
  taken_by          INTEGER NULL,
  created_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  -- One census per term. Retaking it would rewrite an official figure.
  UNIQUE (tenant_id, term_id),
  CONSTRAINT fk_census_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_census_term FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE CASCADE
);

-- Days the academy is shut. A closure is in NEITHER half of an attendance rate:
-- a class that did not happen must not count against the learner who would have
-- attended it.
CREATE TABLE term_closures (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER NOT NULL,
  term_id       INTEGER NOT NULL,
  centre_id     INTEGER NULL,        -- NULL = every centre
  kind          TEXT  NOT NULL,       -- holiday|public|closure|exam_week
  label         TEXT NOT NULL,
  from_on       TEXT         NOT NULL,
  to_on         TEXT         NOT NULL,
  created_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),

  CONSTRAINT chk_closure_span CHECK (to_on >= from_on),
  CONSTRAINT fk_closure_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_closure_term FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE CASCADE,
  CONSTRAINT fk_closure_centre FOREIGN KEY (centre_id) REFERENCES centres(id) ON DELETE CASCADE
);

-- =============================================================================
-- LAYER J · THE RATIFIED TERMLY REPORT
-- =============================================================================
-- The published academic record a family is shown. NOT `progress_reports`, which
-- is the instructor's post-session note, due within two hours, with no status and
-- no board.
--
-- The whole point is that a family sees it only AFTER a board has ratified it: a
-- provisional mark a family can see and then have overturned is worse than one
-- that arrives a week later.
--
-- ⚠️ v2 improves on v1 here in one specific way worth keeping. The design asks
-- for the competency to be a FOREIGN KEY — "v1 matched criteria by string and
-- silently reclassified anything an instructor renamed" — and v1 had no
-- competency table to point at, so it stored a code. v2 HAS `competencies`, so
-- `termly_report_criteria.competency_id` is a real reference and a rename is
-- just a rename.

CREATE TABLE termly_reports (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id         TEXT     NOT NULL,
  tenant_id         INTEGER NOT NULL,
  -- The LEARNER, as a membership: a person's relationship to this academy is
  -- what a report is about, not their platform account.
  membership_id     INTEGER NOT NULL,
  enrolment_id      INTEGER NULL,
  cohort_id         INTEGER NULL,
  course_id         INTEGER NULL,
  level_id          INTEGER NULL,
  term_id           INTEGER NULL,
  -- Snapshotted: terms are renameable and a family has this on their receipt.
  term_label        TEXT NOT NULL,
  status            TEXT  NOT NULL DEFAULT 'draft',
     -- draft|moderating|ratified|withdrawn
  -- The BAND is the stored value. There is no raw_score column anywhere in this
  -- layer: a guardian-facing table with a column for the precise figure is an
  -- invitation to print it, and the mastery coverage floor exists precisely so a
  -- thin composite is never published as a comparable number.
  band_low          TINYINTEGER NULL,
  band_high         TINYINTEGER NULL,
  -- Coverage prints ON the document ("8 of 11 parts — 72% of the weight"), so it
  -- is stored beside the band and NOT NULL with no default. `NOT NULL DEFAULT 0`
  -- constrains nothing, because the default satisfies it — and a report that
  -- cannot state its coverage is not a report.
  coverage_evidenced  TINYINTEGER NOT NULL,
  coverage_total      TINYINTEGER NOT NULL,
  coverage_weight_pct TINYINTEGER NOT NULL,
  instructor_membership_id INTEGER NULL,
  instructor_comment  TEXT     NULL,
  attendance_summary  TEXT NULL,
  -- Ratification is a named person on a stated time. Both or neither.
  ratified_by       INTEGER NULL,
  ratified_at       TEXT  NULL,
  ratified_note     TEXT NULL,
  -- Withdrawing a published mark is a real event and must not be spelled as a
  -- delete. It also FREES the slot, so the corrected report an upheld appeal
  -- produces has somewhere to go.
  withdrawn_at      TEXT  NULL,
  withdrawn_reason  TEXT NULL,
  created_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  updated_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  deleted_at        TEXT  NULL,
  deleted_seq       INTEGER NOT NULL DEFAULT 0,
  created_by        INTEGER NULL,
  row_version       INTEGER NOT NULL DEFAULT 1,
  UNIQUE (public_id),

  -- Lookup only. The one-LIVE-report-per-slot rule is the application's, on both
  -- engines: with no status term a withdrawn report holds its slot forever, and
  -- keying on `term_label` keys the rule on a renameable string. See
  -- mam_report_slot_taken().

  CONSTRAINT chk_treport_status CHECK (status IN ('draft','moderating','ratified','withdrawn')),
  -- A band is ten points wide, or clamped against the top of the scale — which is
  -- the ONLY reason band_high may equal band_low. `band_high > band_low` refused
  -- the (100,100) a perfect score produces.
  CONSTRAINT chk_treport_band CHECK ((band_low IS NULL AND band_high IS NULL)
        OR (band_low <= band_high AND band_high <= 100
            AND band_high - band_low <= 9
            AND (band_high - band_low = 9 OR band_high = 100))),
  CONSTRAINT chk_treport_cov CHECK (coverage_evidenced <= coverage_total
        AND coverage_weight_pct <= 100),
  CONSTRAINT chk_treport_rat CHECK (status <> 'ratified'
        OR (ratified_by IS NOT NULL AND ratified_at IS NOT NULL)),
  CONSTRAINT chk_treport_wdr CHECK (status <> 'withdrawn' OR withdrawn_at IS NOT NULL),
  CONSTRAINT fk_treport_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_treport_member FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
  CONSTRAINT fk_treport_enrol FOREIGN KEY (enrolment_id) REFERENCES enrolments(id) ON DELETE SET NULL,
  CONSTRAINT fk_treport_cohort FOREIGN KEY (cohort_id) REFERENCES cohorts(id) ON DELETE SET NULL,
  CONSTRAINT fk_treport_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
  CONSTRAINT fk_treport_level FOREIGN KEY (level_id) REFERENCES levels(id) ON DELETE SET NULL,
  CONSTRAINT fk_treport_term FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE SET NULL,
  CONSTRAINT fk_treport_instr FOREIGN KEY (instructor_membership_id) REFERENCES memberships(id) ON DELETE SET NULL,
  CONSTRAINT fk_treport_ratby FOREIGN KEY (ratified_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE termly_report_criteria (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id         INTEGER NOT NULL,
  report_id         INTEGER NOT NULL,
  -- A real FOREIGN KEY, which is the thing v1 could not do. A renamed competency
  -- stays the same competency.
  competency_id     INTEGER NOT NULL,
  -- The label AS PRINTED, snapshotted, so a later rename does not silently
  -- restate a document a family already holds.
  label             TEXT NOT NULL,
  weight_pct        TINYINTEGER NOT NULL DEFAULT 0,
  level             TEXT  NOT NULL,     -- strong|secure|working|insufficient
  -- NULL for 'insufficient' and only for it. A zero there reports an unassessed
  -- beginner as failing, which is a different statement from "no evidence".
  pct               TINYINTEGER NULL,
  -- The printed word. FIVE grade words map onto FOUR levels, because the design's
  -- own mock labels both 68% and 46% "Pass" while colouring their bars
  -- differently — the level is the colour axis, the grade is the word.
  grade_label       TEXT  NULL,
  first_assessed_week TINYINTEGER NULL,
  note              TEXT NULL,
  sort_order        INTEGER          NOT NULL DEFAULT 0,
  UNIQUE (report_id, competency_id),

  CONSTRAINT chk_tcrit_level CHECK (level IN ('strong','secure','working','insufficient')),
  CONSTRAINT chk_tcrit_pct CHECK (weight_pct <= 100 AND (pct IS NULL OR pct <= 100)),
  CONSTRAINT chk_tcrit_evid CHECK ((level = 'insufficient' AND pct IS NULL)
        OR (level <> 'insufficient' AND pct IS NOT NULL)),
  CONSTRAINT fk_tcrit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_tcrit_report FOREIGN KEY (report_id) REFERENCES termly_reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_tcrit_comp FOREIGN KEY (competency_id) REFERENCES competencies(id)
);

-- The moderation sample. Per-CRITERION, because "a short sample" is a count of
-- second-marked criteria and a per-report row could not express it.
CREATE TABLE report_moderations (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id         INTEGER NOT NULL,
  report_id         INTEGER NOT NULL,
  competency_id     INTEGER NOT NULL,
  moderator_membership_id INTEGER NULL,
  agreed            INTEGER   NOT NULL DEFAULT 1,
  moderator_level   TEXT  NULL,
  note              TEXT NULL,
  moderated_at      TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  -- Plain UNIQUE with a nullable column: SQL's NULLs-are-distinct rule is the
  -- correct semantics, so two unattributed second-markers are two rows. Under a
  -- COALESCE(...,0) they collapsed onto one key and UNDERCOUNTED the sample that
  -- ratification refuses on — making the refusal fire on a properly moderated
  -- report.
  UNIQUE (report_id, competency_id, moderator_membership_id),

  CONSTRAINT chk_rmod_agreed CHECK (agreed IN (0,1)),
  CONSTRAINT chk_rmod_level CHECK (moderator_level IS NULL
        OR moderator_level IN ('strong','secure','working','insufficient')),
  CONSTRAINT fk_rmod_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_rmod_report FOREIGN KEY (report_id) REFERENCES termly_reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_rmod_comp FOREIGN KEY (competency_id) REFERENCES competencies(id),
  CONSTRAINT fk_rmod_mod FOREIGN KEY (moderator_membership_id) REFERENCES memberships(id) ON DELETE SET NULL
);

-- Its own table rather than a status on the report, because an appeal OUTLIVES
-- the report's status: a family appeals a ratified mark too, and the design gives
-- them three months.
CREATE TABLE mark_appeals (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id         TEXT     NOT NULL,
  tenant_id         INTEGER NOT NULL,
  report_id         INTEGER NULL,
  membership_id     INTEGER NOT NULL,       -- the learner
  raised_by_membership_id INTEGER NULL,
  raised_role       TEXT  NOT NULL DEFAULT 'guardian',  -- guardian|learner|staff
  competency_id     INTEGER NULL,
  reason            TEXT NOT NULL,
  status            TEXT  NOT NULL DEFAULT 'open',      -- open|upheld|declined|withdrawn
  outcome_note      TEXT NULL,
  decided_by        INTEGER NULL,
  -- No DB default, deliberately. `chk_appeal_order` compares these two, and a
  -- defaulted (strftime('%Y-%m-%d %H:%M:%f','now')) carries milliseconds while a PHP caller
  -- stamps `closed_at` from gmdate('Y-m-d H:i:s') with none — so an appeal
  -- closed minutes after it was raised compared BELOW its own opened_at and
  -- the write failed outright. (Same family as the v1 CURRENT_TIMESTAMP vs.
  -- PHP-UTC bug in `mark_appeals`.) Both timestamps must come from one
  -- writer at one precision.
  opened_at         TEXT  NOT NULL,
  closed_at         TEXT  NULL,
  UNIQUE (public_id),

  CONSTRAINT chk_appeal_role CHECK (raised_role IN ('guardian','learner','staff')),
  CONSTRAINT chk_appeal_status CHECK (status IN ('open','upheld','declined','withdrawn')),
  -- ANY closed appeal states its outcome. Written as `status <> 'declined'` it
  -- left an UPHELD appeal free to close with no note — a mark overturned and
  -- nothing saying why.
  CONSTRAINT chk_appeal_closed CHECK (status = 'open'
        OR (closed_at IS NOT NULL AND outcome_note IS NOT NULL)),
  CONSTRAINT chk_appeal_order CHECK (closed_at IS NULL OR closed_at >= opened_at),
  CONSTRAINT fk_appeal_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_appeal_report FOREIGN KEY (report_id) REFERENCES termly_reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_appeal_member FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
  CONSTRAINT fk_appeal_raiser FOREIGN KEY (raised_by_membership_id) REFERENCES memberships(id) ON DELETE SET NULL,
  CONSTRAINT fk_appeal_comp FOREIGN KEY (competency_id) REFERENCES competencies(id) ON DELETE SET NULL,
  CONSTRAINT fk_appeal_decider FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
);

-- =============================================================================
-- LAYER K · FEE RELIEF AND PAYMENT PLANS
-- =============================================================================
-- The AWARD is the academy's decision that a learner is entitled to something.
-- It is separate from its application to any one invoice, because an award spans a
-- term and an invoice is a month.

CREATE TABLE invoice_aid_awards (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id         TEXT     NOT NULL,
  tenant_id         INTEGER NOT NULL,
  membership_id     INTEGER NOT NULL,
  kind              TEXT  NOT NULL,     -- bursary|scholarship|waiver|sibling
  -- Either a percentage or a fixed amount, never both and never neither.
  pct               REAL NULL,
  amount_minor      INTEGER       NULL,
  currency          TEXT      NULL,
  term_id           INTEGER NULL,
  term_label        TEXT NULL,
  -- What the decision rested on. For a bursary this is the income BAND, never a
  -- written account of a family's circumstances.
  basis             TEXT NULL,
  status            TEXT  NOT NULL DEFAULT 'active',   -- active|spent|expired|revoked
  starts_on         TEXT         NULL,
  expires_on        TEXT         NULL,
  awarded_by        INTEGER NULL,
  awarded_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  revoked_at        TEXT  NULL,
  revoked_reason    TEXT NULL,
  note              TEXT NULL,
  UNIQUE (public_id),

  CONSTRAINT chk_aid_kind CHECK (kind IN ('bursary','scholarship','waiver','sibling')),
  CONSTRAINT chk_aid_status CHECK (status IN ('active','spent','expired','revoked')),
  CONSTRAINT chk_aid_shape CHECK ((pct IS NOT NULL AND amount_minor IS NULL)
        OR (pct IS NULL AND amount_minor IS NOT NULL)),
  CONSTRAINT chk_aid_pct CHECK (pct IS NULL OR (pct > 0 AND pct <= 100)),
  CONSTRAINT chk_aid_amount CHECK (amount_minor IS NULL
        OR (amount_minor > 0 AND currency IS NOT NULL)),
  -- An award given and taken back is an event a family may ask about.
  CONSTRAINT chk_aid_revoke CHECK (status <> 'revoked'
        OR (revoked_at IS NOT NULL AND revoked_reason IS NOT NULL)),
  CONSTRAINT fk_aid_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_aid_member FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
  CONSTRAINT fk_aid_term FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE SET NULL,
  CONSTRAINT fk_aid_awarder FOREIGN KEY (awarded_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_aid_currency FOREIGN KEY (currency) REFERENCES currencies(code)
);

-- GIFT AID applied to one invoice — bursary and scholarship ONLY. A waiver never
-- appears here (it is on the line, and is not money); a sibling discount never
-- appears here (it is a discount on the post-aid balance, stored on the invoice).
-- Keeping the three apart is what makes "how much aid did the academy actually pay
-- out this term" a question with an answer.
CREATE TABLE invoice_credits (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id         INTEGER NOT NULL,
  invoice_id        INTEGER NOT NULL,
  award_id          INTEGER NULL,
  kind              TEXT  NOT NULL,     -- bursary|scholarship
  amount_minor      INTEGER       NOT NULL,
  currency          TEXT      NOT NULL,
  base_amount_minor INTEGER       NULL,
  fx_rate           REAL NULL,
  note              TEXT NULL,
  applied_by        INTEGER NULL,
  applied_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  -- One application of an award per invoice: applying one award twice is the
  -- commonest way a double-submitted form gives away the aid pool.
  UNIQUE (invoice_id, award_id),

  CONSTRAINT chk_credit_kind CHECK (kind IN ('bursary','scholarship')),
  CONSTRAINT chk_credit_amount CHECK (amount_minor > 0),
  CONSTRAINT fk_credit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_credit_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_credit_award FOREIGN KEY (award_id) REFERENCES invoice_aid_awards(id) ON DELETE SET NULL,
  CONSTRAINT fk_credit_actor FOREIGN KEY (applied_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_credit_currency FOREIGN KEY (currency) REFERENCES currencies(code)
);

-- "Pay in 3 instalments instead." The plan does not change the TOTAL; it changes
-- when each part falls due. So the instalments must SUM to the balance exactly and
-- the rounding remainder lands on the FIRST one — front-loaded, so no family meets
-- a larger final payment than the plan showed them, and none is left owing a
-- rounding shortfall after paying every instalment.
CREATE TABLE invoice_payment_plans (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id         TEXT     NOT NULL,
  tenant_id         INTEGER NOT NULL,
  invoice_id        INTEGER NOT NULL,
  instalments       TINYINTEGER NOT NULL,
  status            TEXT  NOT NULL DEFAULT 'active',   -- active|completed|cancelled
  -- A family REQUESTS; the office approves. A plan a family can grant itself is a
  -- payment term, not a plan.
  requested_by      INTEGER NULL,
  approved_by       INTEGER NULL,
  created_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  cancelled_at      TEXT  NULL,
  cancel_reason     TEXT NULL,
  UNIQUE (public_id),

  CONSTRAINT chk_plan_status CHECK (status IN ('active','completed','cancelled')),
  CONSTRAINT chk_plan_n CHECK (instalments >= 2 AND instalments <= 12),
  CONSTRAINT chk_plan_cancel CHECK (status <> 'cancelled'
        OR (cancelled_at IS NOT NULL AND cancel_reason IS NOT NULL)),
  CONSTRAINT fk_plan_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_plan_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_plan_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_plan_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE invoice_plan_instalments (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id         INTEGER NOT NULL,
  plan_id           INTEGER NOT NULL,
  seq               TINYINTEGER NOT NULL,
  due_on            TEXT         NOT NULL,
  amount_minor      INTEGER       NOT NULL,
  status            TEXT  NOT NULL DEFAULT 'pending',  -- pending|paid|waived
  paid_at           TEXT  NULL,
  payment_id        INTEGER NULL,
  UNIQUE (plan_id, seq),

  CONSTRAINT chk_inst_status CHECK (status IN ('pending','paid','waived')),
  CONSTRAINT chk_inst_amount CHECK (seq >= 1 AND amount_minor > 0),
  CONSTRAINT chk_inst_paid CHECK (status <> 'paid' OR paid_at IS NOT NULL),
  CONSTRAINT fk_inst_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_inst_plan FOREIGN KEY (plan_id) REFERENCES invoice_payment_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_inst_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL
);

-- A bursary is assessed on a declared income BAND, never a written case for
-- hardship. A written case rewards the family best able to write one, asks a
-- parent to describe their circumstances to their child's school, and leaves an
-- assessor free to be inconsistent between two households in the same position.
--
-- ⚠️ There is deliberately NO hardship / circumstances / case_text column, and
-- `note` is the ASSESSOR's rather than the applicant's — it is never returned to
-- the family. There is also no column linking this to an admission decision,
-- because applying must never affect one and the way that stays true is that
-- admissions cannot see it.
CREATE TABLE bursary_applications (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id         TEXT     NOT NULL,
  tenant_id         INTEGER NOT NULL,
  membership_id     INTEGER NOT NULL,        -- the learner
  guardian_membership_id INTEGER NULL,
  term_id           INTEGER NULL,
  term_label        TEXT NULL,
  income_band       TEXT  NOT NULL,
  household_size    TINYINTEGER NULL,
  dependants_in_academy TINYINTEGER NOT NULL DEFAULT 1,
  status            TEXT  NOT NULL DEFAULT 'submitted',
     -- submitted|assessing|awarded|declined|withdrawn
  -- May be BELOW the band's published figure — a committee weighing a finite pool
  -- — but never above it, because the table is published and a family that read
  -- it is entitled to the answer it gives.
  awarded_pct       REAL NULL,
  award_id          INTEGER NULL,
  decided_by        INTEGER NULL,
  decided_at        TEXT  NULL,
  outcome_note      TEXT NULL,
  note              TEXT NULL,
  created_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  UNIQUE (public_id),

  CONSTRAINT chk_bursary_status CHECK (status IN
    ('submitted','assessing','awarded','declined','withdrawn')),
  CONSTRAINT chk_bursary_house CHECK ((household_size IS NULL OR household_size >= 1)
        AND dependants_in_academy >= 1),
  CONSTRAINT chk_bursary_pct CHECK (awarded_pct IS NULL
        OR (awarded_pct > 0 AND awarded_pct <= 100)),
  -- A decision states its outcome and names who made it, whichever way it went.
  CONSTRAINT chk_bursary_decide CHECK (status IN ('submitted','assessing')
        OR (decided_at IS NOT NULL AND outcome_note IS NOT NULL)),
  CONSTRAINT chk_bursary_award CHECK (status <> 'awarded' OR awarded_pct IS NOT NULL),
  CONSTRAINT fk_bursary_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_bursary_member FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
  CONSTRAINT fk_bursary_guardian FOREIGN KEY (guardian_membership_id) REFERENCES memberships(id) ON DELETE SET NULL,
  CONSTRAINT fk_bursary_term FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE SET NULL,
  CONSTRAINT fk_bursary_award FOREIGN KEY (award_id) REFERENCES invoice_aid_awards(id) ON DELETE SET NULL,
  CONSTRAINT fk_bursary_decider FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
);

-- =============================================================================
-- LAYER L · GUARDIAN COMMUNICATION AND MAKE-UP CREDITS
-- =============================================================================
-- NOT `outbox_messages`, which is transactional send, and NOT the public contact
-- form. A thread is a conversation between the adults responsible for a learner.
--
-- ⚠️ **A learner is never a participant in a guardian thread.** No guardian scope
-- reaches a teenager's messages with their instructor, and the cheapest way to
-- breach that would be to add a guardian to the learner's own conversation. So the
-- participant role CHECK admits only adult roles, and the application derives the
-- role from the MEMBERSHIP rather than taking it as an argument — there is no
-- signature through which "add this learner as an office contact" is expressible.
--
-- The reason is not squeamishness: a young person who knows their questions are
-- read at home stops asking the embarrassing ones, and those are where the
-- teaching happens. What a family is owed instead is everything that affects them,
-- reliably — which is what `guardian_channel_prefs` cannot be used to switch off.

CREATE TABLE guardian_threads (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id         TEXT     NOT NULL,
  tenant_id         INTEGER NOT NULL,
  learner_membership_id INTEGER NOT NULL,
  subject           TEXT NOT NULL,
  -- Decides whether the channel preferences may suppress a notice, so it is on
  -- the thread and constrained rather than inferred from the words.
  kind              TEXT  NOT NULL DEFAULT 'general',
     -- general|academic|money|safeguarding|timetable
  status            TEXT  NOT NULL DEFAULT 'open',
  opened_by         INTEGER NULL,
  created_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  last_message_at   TEXT  NULL,
  closed_at         TEXT  NULL,
  UNIQUE (public_id),

  CONSTRAINT chk_gthread_kind CHECK (kind IN
    ('general','academic','money','safeguarding','timetable')),
  CONSTRAINT chk_gthread_status CHECK (status IN ('open','closed')),
  CONSTRAINT chk_gthread_closed CHECK (status = 'open' OR closed_at IS NOT NULL),
  CONSTRAINT fk_gthread_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_gthread_learner FOREIGN KEY (learner_membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
  CONSTRAINT fk_gthread_opener FOREIGN KEY (opened_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE guardian_thread_participants (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id         INTEGER NOT NULL,
  thread_id         INTEGER NOT NULL,
  membership_id     INTEGER NOT NULL,
  -- No 'learner'. This is a refusal, not an omission — see the layer note.
  role              TEXT  NOT NULL,     -- guardian|instructor|office|admin
  last_read_at      TEXT  NULL,
  added_at          TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  UNIQUE (thread_id, membership_id),

  CONSTRAINT chk_gpart_role CHECK (role IN ('guardian','instructor','office','admin')),
  CONSTRAINT fk_gpart_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_gpart_thread FOREIGN KEY (thread_id) REFERENCES guardian_threads(id) ON DELETE CASCADE,
  CONSTRAINT fk_gpart_member FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE
);

CREATE TABLE guardian_thread_messages (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id         INTEGER NOT NULL,
  thread_id         INTEGER NOT NULL,
  author_membership_id INTEGER NULL,
  -- Denormalised, because author_membership_id goes NULL when a staff membership
  -- is closed and a family's thread has to still make sense afterwards. "A message
  -- from nobody" is worse than a name.
  author_name       TEXT NOT NULL,
  author_role       TEXT  NOT NULL,
  body              TEXT         NOT NULL,
  created_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),

  CONSTRAINT chk_gmsg_role CHECK (author_role IN ('guardian','instructor','office','admin')),
  CONSTRAINT fk_gmsg_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_gmsg_thread FOREIGN KEY (thread_id) REFERENCES guardian_threads(id) ON DELETE CASCADE,
  CONSTRAINT fk_gmsg_author FOREIGN KEY (author_membership_id) REFERENCES memberships(id) ON DELETE SET NULL
);

-- One row per guardian membership. ⚠️ There is NO per-topic column, deliberately:
-- anything about safeguarding or money reaches a guardian on every channel the
-- academy can use regardless of these toggles, so "mute safeguarding" is not a
-- state this table can hold. A family that has muted notifications has muted
-- reminders about practice, not a concern about their child or a fee falling due.
CREATE TABLE guardian_channel_prefs (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id         INTEGER NOT NULL,
  membership_id     INTEGER NOT NULL,
  whatsapp          INTEGER   NOT NULL DEFAULT 1,
  email             INTEGER   NOT NULL DEFAULT 1,
  -- Opt-IN: it costs the academy per message and reaches the same phone WhatsApp
  -- already does.
  sms               INTEGER   NOT NULL DEFAULT 0,
  updated_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  UNIQUE (tenant_id, membership_id),
  CONSTRAINT chk_gpref_flags CHECK (whatsapp IN (0,1) AND email IN (0,1) AND sms IN (0,1)),
  CONSTRAINT fk_gpref_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_gpref_member FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE
);

-- 24 hours' notice earns a credit · five a year · non-transferable · they expire ·
-- no cash in lieu · and a teacher cancellation ALWAYS credits and NEVER counts
-- against the five.
--
-- Five of those six are one column each and the sixth is a refusal:
--   • non-transferable → membership_id is on the row and there is no transfer
--     column. A credit is not a token.
--   • they expire      → expires_on NOT NULL. A credit with no expiry is a
--     liability the academy can never close a year on.
--   • no cash in lieu  → there is NO money column, no currency and no payments
--     link. A credit buys a lesson or it lapses; making it refundable turns every
--     cancellation into a pricing negotiation.
--   • the allowance    → counts_against_allowance, and the CHECK forces it to 0
--     for anything the academy itself initiated. Charging a family an allowance
--     slot because the academy cancelled is the most resented thing a make-up
--     policy can do, so it is not left to a caller passing the default.
CREATE TABLE makeup_credits (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id         TEXT     NOT NULL,
  tenant_id         INTEGER NOT NULL,
  membership_id     INTEGER NOT NULL,
  term_id           INTEGER NULL,
  -- The academy year the allowance is counted over. Stored, because "five a year"
  -- has to mean the same five in December as in March.
  year_label        TEXT  NOT NULL,
  reason            TEXT  NOT NULL,
     -- guardian_notice|teacher_cancellation|academy_closure|goodwill
  source_session_id INTEGER NULL,
  notice_hours      REAL NULL,
  counts_against_allowance INTEGER NOT NULL DEFAULT 1,
  status            TEXT  NOT NULL DEFAULT 'available',
     -- available|spent|expired|revoked
  expires_on        TEXT         NOT NULL,
  spent_session_id  INTEGER NULL,
  spent_at          TEXT  NULL,
  granted_by        INTEGER NULL,
  granted_at        TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  revoked_reason    TEXT NULL,
  note              TEXT NULL,
  UNIQUE (public_id),

  CONSTRAINT chk_makeup_reason CHECK (reason IN
    ('guardian_notice','teacher_cancellation','academy_closure','goodwill')),
  CONSTRAINT chk_makeup_status CHECK (status IN ('available','spent','expired','revoked')),
  CONSTRAINT chk_makeup_counts CHECK (counts_against_allowance IN (0,1)),
  CONSTRAINT chk_makeup_notice CHECK (notice_hours IS NULL OR notice_hours >= 0),
  CONSTRAINT chk_makeup_spent CHECK (status <> 'spent' OR spent_at IS NOT NULL),
  CONSTRAINT chk_makeup_revoke CHECK (status <> 'revoked' OR revoked_reason IS NOT NULL),
  -- Only a GUARDIAN cancellation may cost an allowance slot: the allowance limits
  -- how often a FAMILY cancels, so anything the academy initiates is free by
  -- construction rather than by a caller remembering.
  CONSTRAINT chk_makeup_free CHECK (reason = 'guardian_notice'
        OR counts_against_allowance = 0),
  CONSTRAINT fk_makeup_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_makeup_member FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
  CONSTRAINT fk_makeup_term FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE SET NULL,
  CONSTRAINT fk_makeup_src FOREIGN KEY (source_session_id) REFERENCES class_sessions(id) ON DELETE SET NULL,
  CONSTRAINT fk_makeup_spent FOREIGN KEY (spent_session_id) REFERENCES class_sessions(id) ON DELETE SET NULL,
  CONSTRAINT fk_makeup_grantor FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
);

-- =============================================================================
-- LAYER M · ASSESSMENT AND PROGRESS
-- =============================================================================
-- v1 kept the act of marking in `submission_reviews.scores_json`: a blob of
-- criterion → points that no query could join to a competency, which is why the
-- mastery score had to map rubric criteria to components BY LABEL. Here a mark
-- is a row, the criterion is a foreign key, and the competency comes with it.
--
-- Everything in this layer is keyed on `enrolment_id`, not `student_id`. A mark
-- belongs to a person's enrolment on a course, so the same person taking piano
-- and voice has two independent records instead of one interleaved one — and
-- withdrawing from one course cannot orphan the other's marks.

-- What the academy assesses, and whether it counts toward the mastery score.
--
-- NOT called `assessment_types`, deliberately. v1 has a table of that name and
-- it means something else entirely — its rows are the mastery COMPONENTS, and
-- the ETL maps them to `competencies`. A v2 table sharing the name would invite
-- the next person extending the ETL to map v1's rows straight into it, which
-- would put the mastery weighting in the wrong table with nothing complaining.
-- Configuration, not code: an academy adding a "studio recording" assessment
-- must not need a deploy, and the mastery weighting must not be a constant
-- somebody has to remember to change alongside it.
CREATE TABLE assessment_kinds (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER NOT NULL,
  code          TEXT  NOT NULL,     -- recital|exam|coursework|teachback|practical
  name          TEXT NOT NULL,
  description   TEXT NULL,
  -- Share of the enrolment result. A type that does NOT count to mastery
  -- (a formative check) carries 0 and `counts_to_mastery = 0` — the two say
  -- different things and both are read.
  weight_pct    REAL NOT NULL DEFAULT 0,
  counts_to_mastery INTEGER NOT NULL DEFAULT 1,
  sort_order    INTEGER          NOT NULL DEFAULT 0,
  is_active     INTEGER   NOT NULL DEFAULT 1,
  UNIQUE (tenant_id, code),
  CONSTRAINT chk_asskind_weight CHECK (weight_pct >= 0 AND weight_pct <= 100),
  CONSTRAINT chk_asskind_mastery CHECK (counts_to_mastery IN (0,1)),
  CONSTRAINT chk_asskind_active CHECK (is_active IN (0,1)),
  CONSTRAINT fk_asskind_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);

-- A piece of work handed in. `attempt_no` is part of the natural key because a
-- resubmission is a NEW submission, not an edit of the old one: the mark that
-- was awarded against the first attempt has to stay readable.
CREATE TABLE submissions (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id     TEXT     NOT NULL,
  tenant_id     INTEGER NOT NULL,
  enrolment_id  INTEGER NOT NULL,
  assignment_id INTEGER NULL,
  module_id     INTEGER NULL,
  session_id    INTEGER NULL,
  attempt_no    SMALLINTEGER NOT NULL DEFAULT 1,
  kind          TEXT  NOT NULL DEFAULT 'link',   -- link|text|recording|take|document
  body          TEXT         NULL,
  -- Files live in `attachments` (entity_type='submission'), so a recording, a
  -- photographed score and a PDF are one mechanism instead of v1's
  -- `submission_files` beside a bare `artifact_url`.
  status        TEXT  NOT NULL DEFAULT 'submitted',
     -- draft|submitted|returned|marked|withdrawn
  submitted_at  TEXT  NULL,
  is_late       INTEGER   NOT NULL DEFAULT 0,
  created_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  updated_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  deleted_at    TEXT  NULL,
  deleted_seq   INTEGER NOT NULL DEFAULT 0,
  row_version   INTEGER NOT NULL DEFAULT 1,
  UNIQUE (public_id),
  UNIQUE (tenant_id, assignment_id, enrolment_id, attempt_no, deleted_seq),

  CONSTRAINT chk_subm_status CHECK (status IN ('draft','submitted','returned','marked','withdrawn')),
  CONSTRAINT chk_subm_late CHECK (is_late IN (0,1)),
  -- A submitted piece of work states WHEN it arrived: lateness, the two-hour
  -- marking clock and the assignment deadline are all measured from it.
  CONSTRAINT chk_subm_when CHECK (status = 'draft' OR submitted_at IS NOT NULL),
  CONSTRAINT chk_subm_attempt CHECK (attempt_no >= 1),
  CONSTRAINT fk_subm_tenant  FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_subm_enrol   FOREIGN KEY (enrolment_id)  REFERENCES enrolments(id) ON DELETE CASCADE,
  CONSTRAINT fk_subm_module  FOREIGN KEY (module_id)     REFERENCES modules(id) ON DELETE SET NULL,
  CONSTRAINT fk_subm_session FOREIGN KEY (session_id)    REFERENCES class_sessions(id) ON DELETE SET NULL
);

-- WHO is to mark a submission, which is a different fact from what they marked.
-- The moderation share the report layer requires (MAM_REPORT_MODERATION_SHARE)
-- is a count of allocations with role='moderator' — derivable only if the
-- allocation is recorded before the mark exists.
CREATE TABLE submission_markers (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER NOT NULL,
  submission_id INTEGER NOT NULL,
  marker_membership_id INTEGER NOT NULL,
  role          TEXT  NOT NULL DEFAULT 'primary',   -- primary|moderator|second
  allocated_at  TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  allocated_by  INTEGER NULL,
  completed_at  TEXT  NULL,
  UNIQUE (tenant_id, submission_id, marker_membership_id),

  CONSTRAINT chk_submarker_role CHECK (role IN ('primary','moderator','second')),
  CONSTRAINT fk_submarker_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_submarker_subm   FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_submarker_who    FOREIGN KEY (marker_membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
  CONSTRAINT fk_submarker_by     FOREIGN KEY (allocated_by)  REFERENCES users(id) ON DELETE SET NULL
);

-- The marking EVENT. v1's `submission_reviews` could only exist against a
-- submission; a recital marked in the room and an exam sat on paper had nowhere
-- to go, so they were entered as a submission that nobody submitted.
CREATE TABLE assessments (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id     TEXT     NOT NULL,
  tenant_id     INTEGER NOT NULL,
  enrolment_id  INTEGER NOT NULL,
  assessment_kind_id INTEGER NULL,
  submission_id INTEGER NULL,
  session_id    INTEGER NULL,
  module_id     INTEGER NULL,
  title         TEXT NULL,
  rubric_id     INTEGER NULL,
  -- The rubric VERSION is frozen here, because rubrics/uq_rubrics_version keeps
  -- editing a published rubric to a new version and a mark must state which one
  -- it was awarded against.
  rubric_version INTEGER NULL,
  points_awarded REAL NULL,
  points_max     REAL NULL,
  -- Derived and STORED at mark time, like every other percentage in this schema:
  -- the marker's rubric can be re-versioned afterwards and the awarded figure
  -- must not move with it.
  pct           REAL NULL,
  feedback      TEXT         NULL,
  status        TEXT  NOT NULL DEFAULT 'draft',
     -- draft|marked|moderated|released|withdrawn
  marker_membership_id INTEGER NULL,
  marked_at     TEXT  NULL,
  moderated_by  INTEGER NULL,
  moderated_at  TEXT  NULL,
  -- RELEASED is the only status a learner or guardian may be shown. A mark
  -- exists for a while before anybody is entitled to read it.
  released_at   TEXT  NULL,
  created_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  updated_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  deleted_at    TEXT  NULL,
  deleted_seq   INTEGER NOT NULL DEFAULT 0,
  created_by    INTEGER NULL,
  row_version   INTEGER NOT NULL DEFAULT 1,
  UNIQUE (public_id),

  CONSTRAINT chk_assess_status CHECK (status IN ('draft','marked','moderated','released','withdrawn')),
  CONSTRAINT chk_assess_points CHECK ((points_awarded IS NULL AND points_max IS NULL)
        OR (points_awarded >= 0 AND points_max > 0 AND points_awarded <= points_max)),
  CONSTRAINT chk_assess_pct CHECK (pct IS NULL OR (pct >= 0 AND pct <= 100)),
  -- A mark states who awarded it and when. Without this a released figure has no
  -- author, which is exactly what an appeal has to be answered from.
  CONSTRAINT chk_assess_marked CHECK (status IN ('draft','withdrawn')
        OR (marker_membership_id IS NOT NULL AND marked_at IS NOT NULL AND points_awarded IS NOT NULL)),
  CONSTRAINT chk_assess_released CHECK (status <> 'released' OR released_at IS NOT NULL),
  CONSTRAINT chk_assess_moderated CHECK ((moderated_by IS NULL AND moderated_at IS NULL)
        OR (moderated_by IS NOT NULL AND moderated_at IS NOT NULL)),
  CONSTRAINT fk_assess_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_assess_enrol  FOREIGN KEY (enrolment_id)  REFERENCES enrolments(id) ON DELETE CASCADE,
  CONSTRAINT fk_assess_kind   FOREIGN KEY (assessment_kind_id) REFERENCES assessment_kinds(id) ON DELETE SET NULL,
  CONSTRAINT fk_assess_subm   FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE SET NULL,
  CONSTRAINT fk_assess_sess   FOREIGN KEY (session_id)    REFERENCES class_sessions(id) ON DELETE SET NULL,
  CONSTRAINT fk_assess_module FOREIGN KEY (module_id)     REFERENCES modules(id) ON DELETE SET NULL,
  CONSTRAINT fk_assess_rubric FOREIGN KEY (rubric_id)     REFERENCES rubrics(id) ON DELETE SET NULL,
  CONSTRAINT fk_assess_marker FOREIGN KEY (marker_membership_id) REFERENCES memberships(id) ON DELETE SET NULL,
  CONSTRAINT fk_assess_modby  FOREIGN KEY (moderated_by)  REFERENCES users(id) ON DELETE SET NULL
);

-- One row per criterion marked. This is the table `scores_json` should always
-- have been: `competency_id` is carried BESIDE the criterion (denormalised on
-- purpose) so a mastery query joins nothing and a re-versioned rubric cannot
-- change which competency an awarded mark counted toward.
CREATE TABLE assessment_marks (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER NOT NULL,
  assessment_id INTEGER NOT NULL,
  criterion_id  INTEGER NULL,
  competency_id INTEGER NULL,
  label         TEXT NOT NULL,
  points_awarded REAL NULL,
  points_max     REAL NOT NULL,
  weight        REAL NOT NULL DEFAULT 1.000,
  -- An unevidenced criterion is a ROW with a NULL award, never a zero and never
  -- an absent row: scoring an unassessed beginner 0 reports them as failing, and
  -- omitting the row hides that the criterion was in scope at all.
  is_insufficient INTEGER NOT NULL DEFAULT 0,
  comment       TEXT NULL,
  sort_order    INTEGER          NOT NULL DEFAULT 0,
  UNIQUE (tenant_id, assessment_id, label),

  CONSTRAINT chk_assmark_points CHECK (points_max > 0
        AND (points_awarded IS NULL OR (points_awarded >= 0 AND points_awarded <= points_max))),
  CONSTRAINT chk_assmark_insuff CHECK (is_insufficient IN (0,1)
        AND (is_insufficient = 0 OR points_awarded IS NULL)),
  CONSTRAINT fk_assmark_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_assmark_assess FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
  CONSTRAINT fk_assmark_crit   FOREIGN KEY (criterion_id)  REFERENCES rubric_criteria(id) ON DELETE SET NULL,
  CONSTRAINT fk_assmark_comp   FOREIGN KEY (competency_id) REFERENCES competencies(id) ON DELETE SET NULL
);

-- The learner explains the material back. Five named dimensions rather than
-- v1's single `understanding` plus five columns bolted on afterwards.
CREATE TABLE teachbacks (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id     TEXT     NOT NULL,
  tenant_id     INTEGER NOT NULL,
  enrolment_id  INTEGER NOT NULL,
  module_id     INTEGER NULL,
  session_id    INTEGER NULL,
  kind          TEXT  NOT NULL DEFAULT 'written',   -- written|audio|video|live
  body          TEXT         NULL,
  status        TEXT  NOT NULL DEFAULT 'submitted',
     -- submitted|reviewed|returned|withdrawn
  reviewer_membership_id INTEGER NULL,
  decision      TEXT  NULL,          -- secure|developing|revisit
  accuracy      TINYINTEGER NULL,
  vocabulary    TINYINTEGER NULL,
  explanation   TINYINTEGER NULL,
  application   TINYINTEGER NULL,
  confidence    TINYINTEGER NULL,
  feedback      TEXT         NULL,
  created_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  reviewed_at   TEXT  NULL,
  deleted_at    TEXT  NULL,
  deleted_seq   INTEGER NOT NULL DEFAULT 0,
  UNIQUE (public_id),
  -- One LIVE teach-back per module per enrolment; a withdrawn one frees the slot
  -- (deleted_seq), which is how a re-record replaces rather than duplicates.
  UNIQUE (tenant_id, enrolment_id, module_id, deleted_seq),

  CONSTRAINT chk_teachback_kind CHECK (kind IN ('written','audio','video','live')),
  CONSTRAINT chk_teachback_status CHECK (status IN ('submitted','reviewed','returned','withdrawn')),
  CONSTRAINT chk_teachback_decision CHECK (decision IS NULL
        OR decision IN ('secure','developing','revisit')),
  -- The five dimensions share ONE scale (0–5). v1 left them unbounded, so a
  -- 5-point column and a 100-point column were averaged together.
  CONSTRAINT chk_teachback_scale CHECK (
        (accuracy    IS NULL OR accuracy    <= 5)
    AND (vocabulary  IS NULL OR vocabulary  <= 5)
    AND (explanation IS NULL OR explanation <= 5)
    AND (application IS NULL OR application <= 5)
    AND (confidence  IS NULL OR confidence  <= 5)),
  CONSTRAINT chk_teachback_reviewed CHECK (status <> 'reviewed'
        OR (reviewer_membership_id IS NOT NULL AND reviewed_at IS NOT NULL AND decision IS NOT NULL)),
  CONSTRAINT fk_teachback_tenant FOREIGN KEY (tenant_id)    REFERENCES tenants(id),
  CONSTRAINT fk_teachback_enrol  FOREIGN KEY (enrolment_id) REFERENCES enrolments(id) ON DELETE CASCADE,
  CONSTRAINT fk_teachback_module FOREIGN KEY (module_id)    REFERENCES modules(id) ON DELETE SET NULL,
  CONSTRAINT fk_teachback_sess   FOREIGN KEY (session_id)   REFERENCES class_sessions(id) ON DELETE SET NULL,
  CONSTRAINT fk_teachback_rev    FOREIGN KEY (reviewer_membership_id) REFERENCES memberships(id) ON DELETE SET NULL
);

-- A named set of assignments (a unit of work, a practice block).
CREATE TABLE assignment_groups (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id     TEXT     NOT NULL,
  tenant_id     INTEGER NOT NULL,
  cohort_id     INTEGER NULL,
  course_id     INTEGER NULL,
  title         TEXT NOT NULL,
  description   TEXT         NULL,
  sort_order    INTEGER          NOT NULL DEFAULT 0,
  created_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  created_by    INTEGER NULL,
  deleted_at    TEXT  NULL,
  deleted_seq   INTEGER NOT NULL DEFAULT 0,
  UNIQUE (public_id),

  CONSTRAINT fk_asgroup_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_asgroup_cohort FOREIGN KEY (cohort_id) REFERENCES cohorts(id) ON DELETE CASCADE,
  CONSTRAINT fk_asgroup_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
  CONSTRAINT fk_asgroup_by     FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Set work. v1's `assignments` was per-STUDENT with an optional class, so
-- setting one piece of work for a cohort of nine wrote nine rows that then
-- drifted apart when the instructor edited the instructions. Here the
-- assignment is the work and the target is either a cohort or one enrolment;
-- what each learner did about it is a `submissions` row.
CREATE TABLE assignments (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id     TEXT     NOT NULL,
  tenant_id     INTEGER NOT NULL,
  group_id      INTEGER NULL,
  cohort_id     INTEGER NULL,
  enrolment_id  INTEGER NULL,
  module_id     INTEGER NULL,
  assessment_kind_id INTEGER NULL,
  rubric_id     INTEGER NULL,
  title         TEXT NOT NULL,
  instructions  TEXT         NULL,
  reference_url TEXT NULL,
  reference_kind TEXT  NULL,
  points_max    REAL NULL,
  due_at        TEXT  NULL,
  due_timezone  TEXT  NULL,
  status        TEXT  NOT NULL DEFAULT 'open',
     -- draft|open|closed|archived
  allows_resubmission INTEGER NOT NULL DEFAULT 1,
  created_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  updated_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  created_by    INTEGER NULL,
  deleted_at    TEXT  NULL,
  deleted_seq   INTEGER NOT NULL DEFAULT 0,
  row_version   INTEGER NOT NULL DEFAULT 1,
  UNIQUE (public_id),

  CONSTRAINT chk_assign_status CHECK (status IN ('draft','open','closed','archived')),
  CONSTRAINT chk_assign_resub CHECK (allows_resubmission IN (0,1)),
  CONSTRAINT chk_assign_points CHECK (points_max IS NULL OR points_max > 0),
  -- Work set at nobody is work nobody can hand in. Exactly one target, so a
  -- cohort assignment cannot also claim to be one learner's.
  CONSTRAINT chk_assign_target CHECK ((cohort_id IS NULL) <> (enrolment_id IS NULL)),
  -- A deadline is a wall-clock time in a stated zone, per the header note: a
  -- diaspora learner and a Lagos learner must read the same Friday.
  CONSTRAINT chk_assign_due_tz CHECK (due_at IS NULL OR due_timezone IS NOT NULL),
  CONSTRAINT fk_assign_tenant FOREIGN KEY (tenant_id)    REFERENCES tenants(id),
  CONSTRAINT fk_assign_group  FOREIGN KEY (group_id)     REFERENCES assignment_groups(id) ON DELETE SET NULL,
  CONSTRAINT fk_assign_cohort FOREIGN KEY (cohort_id)    REFERENCES cohorts(id) ON DELETE CASCADE,
  CONSTRAINT fk_assign_enrol  FOREIGN KEY (enrolment_id) REFERENCES enrolments(id) ON DELETE CASCADE,
  CONSTRAINT fk_assign_module FOREIGN KEY (module_id)    REFERENCES modules(id) ON DELETE SET NULL,
  CONSTRAINT fk_assign_kind   FOREIGN KEY (assessment_kind_id) REFERENCES assessment_kinds(id) ON DELETE SET NULL,
  CONSTRAINT fk_assign_rubric FOREIGN KEY (rubric_id)    REFERENCES rubrics(id) ON DELETE SET NULL,
  CONSTRAINT fk_assign_by     FOREIGN KEY (created_by)   REFERENCES users(id) ON DELETE SET NULL
);

-- The finalised outcome of an enrolment. v1's `results` carried a bare
-- `total_score REAL` with nothing saying what scale it was on or who signed it.
CREATE TABLE enrolment_results (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id     TEXT     NOT NULL,
  tenant_id     INTEGER NOT NULL,
  enrolment_id  INTEGER NOT NULL,
  term_id       INTEGER NULL,
  -- INTERNAL. This is the figure the mastery coverage floor governs the
  -- PUBLICATION of, not the storage of: the academy needs it for progression and
  -- an audit needs it reproducible. What a learner or guardian is shown is the
  -- band on `termly_reports`. See mam_mastery_publishable().
  total_pct     REAL NULL,
  grade         TEXT  NULL,
  outcome       TEXT  NULL,      -- passed|referred|failed|withdrawn|incomplete
  -- Coverage travels WITH the figure. A percentage computed over three of eleven
  -- competencies is not the same number as one computed over all eleven, and
  -- without this nothing downstream can tell them apart.
  coverage_evidenced TINYINTEGER NULL,
  coverage_total     TINYINTEGER NULL,
  status        TEXT  NOT NULL DEFAULT 'provisional',
     -- provisional|finalised|withdrawn
  finalised_by  INTEGER NULL,
  finalised_at  TEXT  NULL,
  note          TEXT NULL,
  created_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  updated_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  deleted_at    TEXT  NULL,
  deleted_seq   INTEGER NOT NULL DEFAULT 0,
  row_version   INTEGER NOT NULL DEFAULT 1,
  UNIQUE (public_id),
  -- One LIVE result per enrolment per term. v1's UNIQUE(enrollment_id) could not
  -- express a year-long enrolment reported twice.
  UNIQUE (tenant_id, enrolment_id, term_id, deleted_seq),

  CONSTRAINT chk_enresult_status CHECK (status IN ('provisional','finalised','withdrawn')),
  CONSTRAINT chk_enresult_pct CHECK (total_pct IS NULL OR (total_pct >= 0 AND total_pct <= 100)),
  CONSTRAINT chk_enresult_outcome CHECK (outcome IS NULL
        OR outcome IN ('passed','referred','failed','withdrawn','incomplete')),
  CONSTRAINT chk_enresult_cov CHECK (coverage_evidenced IS NULL
        OR coverage_total IS NULL OR coverage_evidenced <= coverage_total),
  -- A finalised result is signed. An unsigned final grade is the thing an appeal
  -- cannot be answered from.
  CONSTRAINT chk_enresult_final CHECK (status <> 'finalised'
        OR (finalised_by IS NOT NULL AND finalised_at IS NOT NULL AND outcome IS NOT NULL)),
  CONSTRAINT fk_enresult_tenant FOREIGN KEY (tenant_id)    REFERENCES tenants(id),
  CONSTRAINT fk_enresult_enrol  FOREIGN KEY (enrolment_id) REFERENCES enrolments(id) ON DELETE CASCADE,
  CONSTRAINT fk_enresult_term   FOREIGN KEY (term_id)      REFERENCES academic_terms(id) ON DELETE SET NULL,
  CONSTRAINT fk_enresult_by     FOREIGN KEY (finalised_by) REFERENCES users(id) ON DELETE SET NULL
);

-- An awarded certificate, and the code a third party verifies it by.
CREATE TABLE certificates (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id     TEXT     NOT NULL,
  tenant_id     INTEGER NOT NULL,
  membership_id INTEGER NOT NULL,
  enrolment_id  INTEGER NULL,
  programme_id  INTEGER NULL,
  course_id     INTEGER NULL,
  level_id      INTEGER NULL,
  term_id       INTEGER NULL,
  kind          TEXT  NOT NULL,     -- completion|level|participation|merit
  title         TEXT NULL,
  -- The reference a holder quotes and the code a verifier types are DIFFERENT
  -- strings on purpose: the reference is printed on the certificate and readable
  -- over the phone, the verification code is not derivable from it.
  reference        TEXT NOT NULL,
  verification_code TEXT   NOT NULL,
  status        TEXT  NOT NULL DEFAULT 'issued',   -- issued|revoked|reissued
  issued_by     INTEGER NULL,
  issued_at     TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  revoked_at    TEXT  NULL,
  revoked_reason TEXT NULL,
  UNIQUE (public_id),
  UNIQUE (tenant_id, reference),
  -- Verification is looked up by code ALONE (a verifier has no tenant), so the
  -- code is unique across the platform, not within a tenant.
  UNIQUE (verification_code),

  CONSTRAINT chk_cert_kind CHECK (kind IN ('completion','level','participation','merit')),
  CONSTRAINT chk_cert_status CHECK (status IN ('issued','revoked','reissued')),
  -- A revoked certificate says why. A certificate that stops verifying with no
  -- stated reason is indistinguishable from a database fault to the holder.
  CONSTRAINT chk_cert_revoked CHECK (status <> 'revoked'
        OR (revoked_at IS NOT NULL AND revoked_reason IS NOT NULL)),
  CONSTRAINT fk_cert_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_cert_member FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
  CONSTRAINT fk_cert_enrol  FOREIGN KEY (enrolment_id)  REFERENCES enrolments(id) ON DELETE SET NULL,
  CONSTRAINT fk_cert_prog   FOREIGN KEY (programme_id)  REFERENCES programmes(id) ON DELETE SET NULL,
  CONSTRAINT fk_cert_course FOREIGN KEY (course_id)     REFERENCES courses(id) ON DELETE SET NULL,
  CONSTRAINT fk_cert_level  FOREIGN KEY (level_id)      REFERENCES levels(id) ON DELETE SET NULL,
  CONSTRAINT fk_cert_term   FOREIGN KEY (term_id)       REFERENCES academic_terms(id) ON DELETE SET NULL,
  CONSTRAINT fk_cert_by     FOREIGN KEY (issued_by)     REFERENCES users(id) ON DELETE SET NULL
);

-- An instructor's proposed curriculum week, awaiting academic review. Kept
-- separate from `modules` so an unreviewed proposal can never be taught.
CREATE TABLE module_proposals (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id     TEXT     NOT NULL,
  tenant_id     INTEGER NOT NULL,
  course_id     INTEGER NOT NULL,
  level_id      INTEGER NULL,
  week_no       SMALLINTEGER NOT NULL,
  title         TEXT NOT NULL,
  kind          TEXT  NOT NULL DEFAULT 'module',
  content       TEXT         NULL,
  proposed_by_membership_id INTEGER NULL,
  status        TEXT  NOT NULL DEFAULT 'pending',
     -- pending|approved|rejected|withdrawn
  review_note   TEXT NULL,
  reviewed_by   INTEGER NULL,
  reviewed_at   TEXT  NULL,
  -- Set when an approval created the real module, so the proposal and what it
  -- became are joinable rather than merely adjacent.
  module_id     INTEGER NULL,
  created_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  deleted_at    TEXT  NULL,
  deleted_seq   INTEGER NOT NULL DEFAULT 0,
  UNIQUE (public_id),

  CONSTRAINT chk_mprop_status CHECK (status IN ('pending','approved','rejected','withdrawn')),
  CONSTRAINT chk_mprop_week CHECK (week_no >= 1),
  -- A decision has a decider and a time. A rejection also states why: the
  -- proposer is a colleague who has to be able to revise it.
  CONSTRAINT chk_mprop_reviewed CHECK (status = 'pending'
        OR status = 'withdrawn'
        OR (reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL)),
  CONSTRAINT chk_mprop_rejected CHECK (status <> 'rejected' OR review_note IS NOT NULL),
  CONSTRAINT fk_mprop_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_mprop_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  CONSTRAINT fk_mprop_level  FOREIGN KEY (level_id)  REFERENCES levels(id) ON DELETE SET NULL,
  CONSTRAINT fk_mprop_by     FOREIGN KEY (proposed_by_membership_id) REFERENCES memberships(id) ON DELETE SET NULL,
  CONSTRAINT fk_mprop_revby  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_mprop_module FOREIGN KEY (module_id)  REFERENCES modules(id) ON DELETE SET NULL
);

-- Studio practice-tool results. A personal best and a play count, per person per
-- tool. Deliberately NOT a ranking: there is no table in v2 that orders learners
-- against each other, and the 2026-08 redesign refuses one.
CREATE TABLE tool_scores (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER NOT NULL,
  membership_id INTEGER NOT NULL,
  tool          TEXT  NOT NULL,
  best_score    INTEGER NOT NULL DEFAULT 0,
  best_at       TEXT  NULL,
  plays         INTEGER NOT NULL DEFAULT 0,
  -- The score is CLIENT-ATTESTED (it is measured by a microphone in a browser
  -- and the server cannot recompute it), so it is a practice record and never an
  -- assessment. It has no route into `assessments` on purpose.
  created_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  updated_at    TEXT  NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  UNIQUE (tenant_id, membership_id, tool),
  CONSTRAINT fk_toolscore_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_toolscore_member FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE
);

-- Schema baseline. A fresh v2 install starts AT the target rather than
-- replaying v1's 81 migrations.
CREATE TABLE schema_version (
  version     INTEGER NOT NULL PRIMARY KEY,
  applied_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now')),
  note        TEXT NULL
);

INSERT INTO schema_version (version, note) VALUES (202,
  'schema-v2 baseline + guardian layer + assessment and progress');

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
CREATE INDEX ix_terms_window ON academic_terms (tenant_id, starts_on, ends_on);
CREATE INDEX ix_terms_status ON academic_terms (tenant_id, status);
CREATE INDEX ix_closure_span ON term_closures (tenant_id, term_id, from_on, to_on);
CREATE INDEX ix_treport_member ON termly_reports (tenant_id, membership_id, status);
CREATE INDEX ix_treport_cohort ON termly_reports (tenant_id, cohort_id, status);
CREATE INDEX ix_treport_slot ON termly_reports (tenant_id, membership_id, term_id, course_id, level_id, status);
CREATE INDEX ix_tcrit_report ON termly_report_criteria (report_id, sort_order);
CREATE INDEX ix_rmod_report ON report_moderations (report_id);
CREATE INDEX ix_appeal_report ON mark_appeals (tenant_id, report_id, status);
CREATE INDEX ix_appeal_open ON mark_appeals (tenant_id, status, opened_at);
CREATE INDEX ix_aid_member ON invoice_aid_awards (tenant_id, membership_id, status, kind);
CREATE INDEX ix_credit_invoice ON invoice_credits (tenant_id, invoice_id);
CREATE INDEX ix_plan_invoice ON invoice_payment_plans (tenant_id, invoice_id, status);
CREATE INDEX ix_inst_due ON invoice_plan_instalments (tenant_id, status, due_on);
CREATE INDEX ix_bursary_member ON bursary_applications (tenant_id, membership_id, status);
CREATE INDEX ix_bursary_queue ON bursary_applications (tenant_id, status, created_at);
CREATE INDEX ix_gthread_learner ON guardian_threads (tenant_id, learner_membership_id, status, last_message_at);
CREATE INDEX ix_gpart_member ON guardian_thread_participants (tenant_id, membership_id);
CREATE INDEX ix_gmsg_thread ON guardian_thread_messages (thread_id, created_at);
CREATE INDEX ix_makeup_member ON makeup_credits (tenant_id, membership_id, status, expires_on);
CREATE INDEX ix_makeup_year ON makeup_credits (tenant_id, membership_id, year_label, counts_against_allowance);
CREATE INDEX ix_subm_enrol ON submissions (tenant_id, enrolment_id, status);
CREATE INDEX ix_subm_module ON submissions (tenant_id, module_id, status);
CREATE INDEX ix_submarker_who ON submission_markers (tenant_id, marker_membership_id, completed_at);
CREATE INDEX ix_assess_enrol ON assessments (tenant_id, enrolment_id, status);
CREATE INDEX ix_assess_subm ON assessments (tenant_id, submission_id);
CREATE INDEX ix_assess_marker ON assessments (tenant_id, marker_membership_id, marked_at);
CREATE INDEX ix_assmark_comp ON assessment_marks (tenant_id, competency_id);
CREATE INDEX ix_teachback_review ON teachbacks (tenant_id, status, created_at);
CREATE INDEX ix_asgroup_cohort ON assignment_groups (tenant_id, cohort_id, sort_order);
CREATE INDEX ix_assign_cohort ON assignments (tenant_id, cohort_id, status, due_at);
CREATE INDEX ix_assign_enrol ON assignments (tenant_id, enrolment_id, status, due_at);
CREATE INDEX ix_enresult_status ON enrolment_results (tenant_id, status, finalised_at);
CREATE INDEX ix_cert_member ON certificates (tenant_id, membership_id, status);
CREATE INDEX ix_mprop_review ON module_proposals (tenant_id, status, created_at);
CREATE INDEX ix_mprop_course ON module_proposals (tenant_id, course_id, level_id, week_no);
