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
--   • public_id CHAR(26) is a ULID for anything externally addressable, so
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
--   • DATETIME(3) UTC. Wall-clock times that must survive a timezone rule
--     change are stored local + IANA zone, never as an instant.
--
-- Engine/charset match the v1 baseline for host compatibility.
-- =============================================================================

SET NAMES utf8mb4;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';

-- =============================================================================
-- LAYER A · PLATFORM & TENANCY
-- =============================================================================

-- A tenant is an academy/organisation. MAM Academy is tenant #1 rather than the
-- hardcoded universe; this is the single decision that makes the rest a
-- platform, and the one that cannot be retrofitted cheaply.
CREATE TABLE tenants (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id         CHAR(26)     NOT NULL,
  slug              VARCHAR(63)  NOT NULL,           -- URL/subdomain key
  legal_name        VARCHAR(200) NOT NULL,
  display_name      VARCHAR(200) NOT NULL,
  base_currency     CHAR(3)      NOT NULL DEFAULT 'USD',
  default_locale    VARCHAR(12)  NOT NULL DEFAULT 'en',
  default_timezone  VARCHAR(64)  NOT NULL DEFAULT 'Africa/Lagos',
  status            VARCHAR(16)  NOT NULL DEFAULT 'active',  -- active|suspended|closed
  -- Contractual/branding facts kept as JSON: they vary per tenant and are read
  -- whole, never filtered on, so a column per field would be churn for nothing.
  branding          JSON         NULL,
  contact           JSON         NULL,
  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at        DATETIME(3)  NULL,
  row_version       INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_tenants_public (public_id),
  UNIQUE KEY uq_tenants_slug (slug),
  KEY ix_tenants_status (status, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ISO 4217 with the exponent, because currencies disagree about how many minor
-- units exist (JPY 0, USD/NGN 2, KWD 3). Storing the exponent is what lets a
-- generic formatter render any tenant's money correctly.
CREATE TABLE currencies (
  code        CHAR(3)     NOT NULL PRIMARY KEY,
  name        VARCHAR(64) NOT NULL,
  exponent    TINYINT UNSIGNED NOT NULL DEFAULT 2,
  symbol      VARCHAR(8)  NULL,
  is_active   TINYINT(1)  NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A centre is a place teaching happens — a campus, a partner site, or the
-- virtual one. Flat per tenant for now; §7.4 of the design doc flags the
-- hierarchy question, which is a parent_id away but should be decided before
-- data lands.
CREATE TABLE centres (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  code          VARCHAR(32)  NOT NULL,
  name          VARCHAR(160) NOT NULL,
  kind          VARCHAR(16)  NOT NULL DEFAULT 'physical',  -- physical|virtual
  timezone      VARCHAR(64)  NULL,        -- overrides the tenant default
  address       JSON         NULL,
  geo_lat       DECIMAL(10,7) NULL,
  geo_lng       DECIMAL(10,7) NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at    DATETIME(3)  NULL,
  -- 0 while live, the row id once soft-deleted (see the header note).
  deleted_seq   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_by    BIGINT UNSIGNED NULL,
  updated_by    BIGINT UNSIGNED NULL,
  row_version   INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_centres_public (public_id),
  UNIQUE KEY uq_centres_code (tenant_id, code, deleted_seq),
  KEY ix_centres_tenant (tenant_id, is_active),
  CONSTRAINT fk_centres_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Typed settings. v1 stored everything as a string and cast at every read site,
-- so a typo in a numeric setting became a silent 0. `value_type` lets the
-- accessor validate once, centrally.
CREATE TABLE tenant_settings (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  skey          VARCHAR(120) NOT NULL,
  value_type    VARCHAR(16)  NOT NULL DEFAULT 'string',  -- string|int|decimal|bool|json|secret
  svalue        TEXT         NULL,
  -- `secret` values are stored encrypted at rest by the application; the type
  -- is recorded here so an export or an admin screen knows never to display it.
  is_secret     TINYINT(1)   NOT NULL DEFAULT 0,
  updated_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  updated_by    BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_settings_key (tenant_id, skey),
  CONSTRAINT fk_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Feature flags per tenant, so a capability can be piloted at one academy
-- without a branch or a deploy. `rollout_percent` supports gradual enablement
-- within a tenant (hashed on a stable key, decided in code).
CREATE TABLE tenant_features (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id       BIGINT UNSIGNED NOT NULL,
  feature_key     VARCHAR(80)  NOT NULL,
  is_enabled      TINYINT(1)   NOT NULL DEFAULT 0,
  rollout_percent TINYINT UNSIGNED NOT NULL DEFAULT 100,
  config          JSON         NULL,
  updated_at      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  updated_by      BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_features (tenant_id, feature_key),
  CONSTRAINT fk_features_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id         CHAR(26)     NOT NULL,
  email             VARCHAR(190) NOT NULL,
  email_verified_at DATETIME(3)  NULL,
  phone             VARCHAR(32)  NULL,
  phone_verified_at DATETIME(3)  NULL,
  full_name         VARCHAR(160) NOT NULL,
  preferred_name    VARCHAR(80)  NULL,
  -- Stored, never inferred from the name: guessing pronouns from a name
  -- misgenders real people, and the cost of a NULL here is nothing.
  pronouns          VARCHAR(32)  NULL,
  date_of_birth     DATE         NULL,
  avatar_url        VARCHAR(500) NULL,
  locale            VARCHAR(12)  NULL,
  timezone          VARCHAR(64)  NULL,
  -- Platform-level state. Tenant-level state lives on the membership, so
  -- suspending someone at one academy does not lock them out of another.
  status            VARCHAR(16)  NOT NULL DEFAULT 'active',  -- active|locked|closed|system
  last_seen_at      DATETIME(3)  NULL,
  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at        DATETIME(3)  NULL,
  row_version       INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_users_public (public_id),
  UNIQUE KEY uq_users_email (email),
  KEY ix_users_status (status, deleted_at),
  KEY ix_users_name (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Credentials live apart from the person. A user may have none (SSO-only), and
-- rotating a password must not touch the identity row that everything else
-- foreign-keys to.
CREATE TABLE user_credentials (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id               BIGINT UNSIGNED NOT NULL,
  password_hash         VARCHAR(255) NOT NULL,
  algo                  VARCHAR(24)  NOT NULL DEFAULT 'bcrypt',
  must_change           TINYINT(1)   NOT NULL DEFAULT 0,
  password_changed_at   DATETIME(3)  NULL,
  -- Lockout is per credential, so a brute-force attempt cannot be used to
  -- deny service to the human's other authentication routes.
  failed_attempts       INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until          DATETIME(3)  NULL,
  mfa_secret            VARBINARY(255) NULL,        -- encrypted at rest
  mfa_enrolled_at       DATETIME(3)  NULL,
  mfa_recovery_codes    JSON         NULL,          -- hashed, single-use
  created_at            DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at            DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_cred_user (user_id),
  CONSTRAINT fk_cred_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- External identities (Google Workspace, Microsoft, an LMS SSO). Separate rows
-- so a person can link several and lose none by changing email.
CREATE TABLE user_identities (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       BIGINT UNSIGNED NOT NULL,
  provider      VARCHAR(32)  NOT NULL,          -- google|microsoft|saml:<tenant>
  subject       VARCHAR(190) NOT NULL,          -- the provider's stable id
  email_at_link VARCHAR(190) NULL,
  linked_at     DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  last_login_at DATETIME(3)  NULL,
  UNIQUE KEY uq_identity (provider, subject),
  KEY ix_identity_user (user_id),
  CONSTRAINT fk_identity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A membership is the relationship between a person and a tenant. Roles,
-- lifecycle stage and tenant-level status all hang here rather than on the
-- user, which is what lets one person be a student at A and an admin at B.
CREATE TABLE memberships (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id         CHAR(26)     NOT NULL,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  user_id           BIGINT UNSIGNED NOT NULL,
  centre_id         BIGINT UNSIGNED NULL,           -- home centre, if any
  reference         VARCHAR(32)  NULL,              -- human-facing student/staff ref
  -- Cached lifecycle stage. The event log is the truth; this is materialised so
  -- "everyone at stage X, sorted by age in stage" is affordable on the pipeline
  -- board. A disagreement between the two is a writer bug a job can detect.
  stage             VARCHAR(24)  NOT NULL DEFAULT 'prospect',
  stage_entered_at  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  status            VARCHAR(16)  NOT NULL DEFAULT 'active',   -- active|suspended|closed
  joined_at         DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at        DATETIME(3)  NULL,
  -- 0 while live, the row id once soft-deleted (see the header note).
  deleted_seq   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_by        BIGINT UNSIGNED NULL,
  updated_by        BIGINT UNSIGNED NULL,
  row_version       INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_memberships_public (public_id),
  UNIQUE KEY uq_memberships_user (tenant_id, user_id, deleted_seq),
  UNIQUE KEY uq_memberships_ref (tenant_id, reference, deleted_seq),
  KEY ix_memberships_stage (tenant_id, stage, stage_entered_at),
  KEY ix_memberships_centre (tenant_id, centre_id, status),
  CONSTRAINT fk_memberships_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_memberships_user   FOREIGN KEY (user_id)   REFERENCES users(id),
  CONSTRAINT fk_memberships_centre FOREIGN KEY (centre_id) REFERENCES centres(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissions are rows, not constants, so a tenant can define a role the
-- platform authors never imagined without a deployment.
CREATE TABLE permissions (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(80)  NOT NULL,      -- e.g. 'enrolment.write'
  domain      VARCHAR(40)  NOT NULL,      -- grouping for the admin UI
  description VARCHAR(255) NULL,
  UNIQUE KEY uq_permissions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- System roles (tenant_id NULL) ship with the platform and cannot be edited;
-- tenant roles are theirs to define. The nullable tenant_id is what allows both
-- in one table without a discriminator column that every query must remember.
CREATE TABLE roles (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NULL,
  code          VARCHAR(64)  NOT NULL,
  name          VARCHAR(120) NOT NULL,
  description   VARCHAR(255) NULL,
  is_system     TINYINT(1)   NOT NULL DEFAULT 0,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_roles_code (tenant_id, code),
  CONSTRAINT fk_roles_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
  role_id       BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id)       REFERENCES roles(id)       ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Roles attach to the MEMBERSHIP, not the user. `centre_id` optionally narrows
-- a grant, so "administrator, Lagos only" is expressible without a second role.
CREATE TABLE membership_roles (
  membership_id BIGINT UNSIGNED NOT NULL,
  role_id       BIGINT UNSIGNED NOT NULL,
  centre_id     BIGINT UNSIGNED NULL,
  granted_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  granted_by    BIGINT UNSIGNED NULL,
  expires_at    DATETIME(3)  NULL,       -- time-boxed elevation
  PRIMARY KEY (membership_id, role_id),
  KEY ix_mr_role (role_id),
  CONSTRAINT fk_mr_membership FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
  CONSTRAINT fk_mr_role       FOREIGN KEY (role_id)       REFERENCES roles(id),
  CONSTRAINT fk_mr_centre     FOREIGN KEY (centre_id)     REFERENCES centres(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessions store a HASH of the token, never the token: a database disclosure
-- must not hand over live sessions.
CREATE TABLE auth_sessions (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       BIGINT UNSIGNED NOT NULL,
  tenant_id     BIGINT UNSIGNED NULL,        -- the tenant context in use
  token_hash    CHAR(64)     NOT NULL,
  ip            VARBINARY(16) NULL,
  user_agent    VARCHAR(255) NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  last_used_at  DATETIME(3)  NULL,
  expires_at    DATETIME(3)  NOT NULL,
  revoked_at    DATETIME(3)  NULL,
  UNIQUE KEY uq_sessions_token (token_hash),
  KEY ix_sessions_user (user_id, expires_at),
  CONSTRAINT fk_auth_sessions_user   FOREIGN KEY (user_id)   REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_auth_sessions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Machine access, separate from human sessions: different lifetime, different
-- revocation story, and scopes rather than roles.
CREATE TABLE api_clients (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  name          VARCHAR(120) NOT NULL,
  key_hash      CHAR(64)     NOT NULL,
  scopes        JSON         NOT NULL,
  last_used_at  DATETIME(3)  NULL,
  expires_at    DATETIME(3)  NULL,
  revoked_at    DATETIME(3)  NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by    BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_api_public (public_id),
  UNIQUE KEY uq_api_key (key_hash),
  KEY ix_api_tenant (tenant_id, revoked_at),
  CONSTRAINT fk_api_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- LAYER C · LIFECYCLE
-- =============================================================================

-- Append-only. memberships.stage is the cache; this is the truth, and it
-- records ATTEMPTED transitions too — a rejected move is evidence about a
-- process, and discarding it hides exactly the cases worth reviewing.
CREATE TABLE lifecycle_events (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  membership_id BIGINT UNSIGNED NOT NULL,
  from_stage    VARCHAR(24)  NULL,
  to_stage      VARCHAR(24)  NOT NULL,
  outcome       VARCHAR(16)  NOT NULL DEFAULT 'applied',  -- applied|rejected
  reason_code   VARCHAR(48)  NULL,
  note          VARCHAR(500) NULL,
  occurred_at   DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  -- Nullable because the system acts too (an automation, a scheduled sweep).
  -- NOT ON DELETE SET NULL: erasing who acted is the one thing this table
  -- exists to prevent.
  actor_user_id BIGINT UNSIGNED NULL,
  source        VARCHAR(32)  NOT NULL DEFAULT 'app',      -- app|automation|import|api
  KEY ix_lifecycle_membership (membership_id, occurred_at),
  KEY ix_lifecycle_tenant (tenant_id, to_stage, occurred_at),
  CONSTRAINT fk_lifecycle_tenant     FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_lifecycle_membership FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
  CONSTRAINT fk_lifecycle_actor      FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One intake table. v1 had three (applications, summer_applications,
-- scholarship_applications) with three status vocabularies and no single funnel
-- view; `type` + a typed payload replaces them.
CREATE TABLE applications (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id         CHAR(26)     NOT NULL,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  membership_id     BIGINT UNSIGNED NULL,        -- linked once a person exists
  type              VARCHAR(24)  NOT NULL,       -- programme|summer|scholarship|audition
  status            VARCHAR(24)  NOT NULL DEFAULT 'draft',
  submitted_at      DATETIME(3)  NULL,
  decided_at        DATETIME(3)  NULL,
  decided_by        BIGINT UNSIGNED NULL,
  decision_reason   VARCHAR(255) NULL,
  programme_id      BIGINT UNSIGNED NULL,
  level_id          BIGINT UNSIGNED NULL,
  centre_id         BIGINT UNSIGNED NULL,
  intake_term       VARCHAR(32)  NULL,
  -- Type-specific answers. The shape is validated in code against the type, so
  -- a summer application's guardian block does not become 30 nullable columns
  -- that every other type ignores.
  payload           JSON         NULL,
  readiness_signal  VARCHAR(16)  NULL,           -- advisory, never a gate
  source_channel    VARCHAR(48)  NULL,           -- attribution
  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at        DATETIME(3)  NULL,
  row_version       INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_applications_public (public_id),
  KEY ix_applications_pipeline (tenant_id, status, submitted_at),
  KEY ix_applications_member (membership_id),
  CONSTRAINT fk_app_tenant     FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_app_membership FOREIGN KEY (membership_id) REFERENCES memberships(id),
  CONSTRAINT fk_app_centre     FOREIGN KEY (centre_id)     REFERENCES centres(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Guardianship is a relationship between two memberships in the SAME tenant, so
-- a guardian of children at two academies has a row in each and no cross-tenant
-- visibility. `permissions` scopes what they may see.
CREATE TABLE guardianships (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  guardian_membership_id BIGINT UNSIGNED NOT NULL,
  learner_membership_id  BIGINT UNSIGNED NOT NULL,
  relationship      VARCHAR(32)  NOT NULL DEFAULT 'guardian',
  is_primary        TINYINT(1)   NOT NULL DEFAULT 0,
  permissions       JSON         NULL,       -- view_progress|view_invoices|approve_media
  verified_at       DATETIME(3)  NULL,
  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  deleted_at        DATETIME(3)  NULL,
  -- 0 while live, the row id once soft-deleted (see the header note).
  deleted_seq   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_by        BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_guardianship (tenant_id, guardian_membership_id, learner_membership_id, deleted_seq),
  KEY ix_guardianship_learner (learner_membership_id),
  CONSTRAINT fk_guard_tenant   FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_guard_guardian FOREIGN KEY (guardian_membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
  CONSTRAINT fk_guard_learner  FOREIGN KEY (learner_membership_id)  REFERENCES memberships(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- LAYER D · CATALOGUE (what can be taught)
-- =============================================================================
-- Reference data. Re-seeded from canonical definitions by the ETL rather than
-- copied, so accumulated inconsistency is shed rather than migrated.

CREATE TABLE programmes (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id       CHAR(26)     NOT NULL,
  tenant_id       BIGINT UNSIGNED NOT NULL,
  code            VARCHAR(48)  NOT NULL,
  name            VARCHAR(160) NOT NULL,
  summary         VARCHAR(500) NULL,
  duration_weeks  SMALLINT UNSIGNED NOT NULL DEFAULT 12,
  certification   VARCHAR(32)  NULL,          -- participation|certificate|diploma
  sort_order      INT          NOT NULL DEFAULT 0,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at      DATETIME(3)  NULL,
  -- 0 while live, the row id once soft-deleted (see the header note).
  deleted_seq   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  row_version     INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_programmes_public (public_id),
  UNIQUE KEY uq_programmes_code (tenant_id, code, deleted_seq),
  CONSTRAINT fk_programmes_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE levels (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id       BIGINT UNSIGNED NOT NULL,
  code            VARCHAR(32)  NOT NULL,
  name            VARCHAR(120) NOT NULL,
  external_grades VARCHAR(64)  NULL,          -- e.g. 'Trinity Grade 1-2'
  rank_order      SMALLINT     NOT NULL DEFAULT 0,   -- progression order
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  deleted_at      DATETIME(3)  NULL,
  -- 0 while live, the row id once soft-deleted (see the header note).
  deleted_seq   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_levels_code (tenant_id, code, deleted_seq),
  CONSTRAINT fk_levels_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A course is a subject (Piano, Drums, Music Theory). "Instrument" in the
-- reporting drill-down is a course.
CREATE TABLE courses (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  code          VARCHAR(48)  NOT NULL,
  name          VARCHAR(160) NOT NULL,
  kind          VARCHAR(24)  NOT NULL DEFAULT 'elective',  -- core|elective|compulsory
  discipline    VARCHAR(48)  NULL,            -- instrument family, for grouping
  description   TEXT         NULL,
  sort_order    INT          NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at    DATETIME(3)  NULL,
  -- 0 while live, the row id once soft-deleted (see the header note).
  deleted_seq   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  row_version   INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_courses_public (public_id),
  UNIQUE KEY uq_courses_code (tenant_id, code, deleted_seq),
  KEY ix_courses_discipline (tenant_id, discipline, is_active),
  CONSTRAINT fk_courses_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE programme_courses (
  programme_id  BIGINT UNSIGNED NOT NULL,
  course_id     BIGINT UNSIGNED NOT NULL,
  level_id      BIGINT UNSIGNED NULL,
  is_required   TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order    INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (programme_id, course_id, level_id),
  KEY ix_pc_course (course_id),
  CONSTRAINT fk_pc_programme FOREIGN KEY (programme_id) REFERENCES programmes(id) ON DELETE CASCADE,
  CONSTRAINT fk_pc_course    FOREIGN KEY (course_id)    REFERENCES courses(id),
  CONSTRAINT fk_pc_level     FOREIGN KEY (level_id)     REFERENCES levels(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A module is a unit of curriculum within a course at a level — the "week N"
-- content. week_no is the PLANNED position; when a learner actually reaches it
-- is recorded per learner, never here (design doc §0: curriculum ≠ schedule ≠
-- mastery).
CREATE TABLE modules (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id       CHAR(26)     NOT NULL,
  tenant_id       BIGINT UNSIGNED NOT NULL,
  course_id       BIGINT UNSIGNED NOT NULL,
  level_id        BIGINT UNSIGNED NULL,
  week_no         SMALLINT UNSIGNED NULL,
  title           VARCHAR(200) NOT NULL,
  objectives      TEXT         NULL,
  prep_instructions TEXT       NULL,
  resources       JSON         NULL,
  requires_teachback TINYINT(1) NOT NULL DEFAULT 0,
  is_published    TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at      DATETIME(3)  NULL,
  row_version     INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_modules_public (public_id),
  KEY ix_modules_course (tenant_id, course_id, level_id, week_no),
  CONSTRAINT fk_modules_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_modules_course FOREIGN KEY (course_id) REFERENCES courses(id),
  CONSTRAINT fk_modules_level  FOREIGN KEY (level_id)  REFERENCES levels(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Competencies are the named abilities a rubric criterion maps to. v1 matched
-- rubric criteria to score components by string keyword, which is fragile and
-- silently reclassifies a renamed criterion; here the mapping is a foreign key.
CREATE TABLE competencies (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  code          VARCHAR(48)  NOT NULL,     -- rhythm_timing|musical_accuracy|...
  name          VARCHAR(120) NOT NULL,
  description   VARCHAR(500) NULL,
  weight_pct    DECIMAL(5,2) NOT NULL DEFAULT 0,   -- share of the mastery score
  sort_order    INT          NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_competencies (tenant_id, code),
  CONSTRAINT fk_comp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rubrics (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  course_id     BIGINT UNSIGNED NULL,      -- instrument-specific when set
  level_id      BIGINT UNSIGNED NULL,
  title         VARCHAR(160) NOT NULL,
  version       INT UNSIGNED NOT NULL DEFAULT 1,
  -- A scored rubric must never change under a grade that was already awarded.
  -- Editing a published rubric creates a new VERSION; submissions reference the
  -- version they were marked against.
  is_published  TINYINT(1)   NOT NULL DEFAULT 0,
  published_at  DATETIME(3)  NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by    BIGINT UNSIGNED NULL,
  deleted_at    DATETIME(3)  NULL,
  -- 0 while live, the row id once soft-deleted (see the header note).
  deleted_seq   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_rubrics_public (public_id),
  UNIQUE KEY uq_rubrics_version (tenant_id, title, version, deleted_seq),
  KEY ix_rubrics_course (tenant_id, course_id, level_id, is_published),
  CONSTRAINT fk_rubrics_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_rubrics_course FOREIGN KEY (course_id) REFERENCES courses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rubric_criteria (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rubric_id     BIGINT UNSIGNED NOT NULL,
  competency_id BIGINT UNSIGNED NULL,       -- the FK that replaces keyword matching
  label         VARCHAR(190) NOT NULL,
  descriptor    TEXT         NULL,
  max_points    SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  weight        DECIMAL(6,3) NOT NULL DEFAULT 1.000,
  sort_order    INT          NOT NULL DEFAULT 0,
  KEY ix_criteria_rubric (rubric_id, sort_order),
  CONSTRAINT fk_criteria_rubric     FOREIGN KEY (rubric_id)     REFERENCES rubrics(id) ON DELETE CASCADE,
  CONSTRAINT fk_criteria_competency FOREIGN KEY (competency_id) REFERENCES competencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Translations for anything learner-facing. One table rather than a *_i18n
-- sibling per entity, so adding a language is data and adding a translatable
-- entity is one row of configuration.
CREATE TABLE translations (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  entity_type   VARCHAR(48)  NOT NULL,      -- programmes|courses|modules|...
  entity_id     BIGINT UNSIGNED NOT NULL,
  field         VARCHAR(48)  NOT NULL,      -- name|summary|description
  locale        VARCHAR(12)  NOT NULL,
  value         TEXT         NOT NULL,
  updated_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_translations (tenant_id, entity_type, entity_id, field, locale),
  CONSTRAINT fk_translations_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- LAYER E · DELIVERY (what is actually taught, to whom, when)
-- =============================================================================
-- ONE spine. v1 carried `classes`+`enrollments`+`attendance` alongside
-- `cohorts`+`course_enrollments`+`session_attendance`, so every read path had
-- to dual-read and a learner could exist in one model and not the other.

CREATE TABLE cohorts (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id       CHAR(26)     NOT NULL,
  tenant_id       BIGINT UNSIGNED NOT NULL,
  centre_id       BIGINT UNSIGNED NULL,
  course_id       BIGINT UNSIGNED NOT NULL,
  level_id        BIGINT UNSIGNED NULL,
  programme_id    BIGINT UNSIGNED NULL,
  title           VARCHAR(160) NOT NULL,
  term            VARCHAR(48)  NULL,
  delivery_mode   VARCHAR(16)  NOT NULL DEFAULT 'onsite',   -- onsite|online|hybrid
  -- Wall-clock, not an instant: a class at "16:00 Lagos" must stay at 16:00
  -- when offset rules change. The instant is derived at render/schedule time.
  meeting_days    VARCHAR(20)  NULL,          -- 'mon,wed'
  meeting_time    TIME         NULL,
  timezone        VARCHAR(64)  NULL,
  session_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  starts_on       DATE         NULL,
  ends_on         DATE         NULL,
  -- NULL capacity = uncapped; 0 would read as permanently full, which is why
  -- "unlimited" must not be expressed as zero.
  capacity        SMALLINT UNSIGNED NULL,
  status          VARCHAR(16)  NOT NULL DEFAULT 'planned',  -- planned|active|completed|cancelled
  lead_instructor_membership_id BIGINT UNSIGNED NULL,
  created_at      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at      DATETIME(3)  NULL,
  created_by      BIGINT UNSIGNED NULL,
  updated_by      BIGINT UNSIGNED NULL,
  row_version     INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_cohorts_public (public_id),
  KEY ix_cohorts_lookup (tenant_id, course_id, level_id, status),
  KEY ix_cohorts_centre (tenant_id, centre_id, starts_on),
  KEY ix_cohorts_instructor (lead_instructor_membership_id, status),
  CONSTRAINT fk_cohorts_tenant     FOREIGN KEY (tenant_id)    REFERENCES tenants(id),
  CONSTRAINT fk_cohorts_centre     FOREIGN KEY (centre_id)    REFERENCES centres(id),
  CONSTRAINT fk_cohorts_course     FOREIGN KEY (course_id)    REFERENCES courses(id),
  CONSTRAINT fk_cohorts_level      FOREIGN KEY (level_id)     REFERENCES levels(id),
  CONSTRAINT fk_cohorts_programme  FOREIGN KEY (programme_id) REFERENCES programmes(id),
  CONSTRAINT fk_cohorts_instructor FOREIGN KEY (lead_instructor_membership_id) REFERENCES memberships(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Staffing is many-to-many: a cohort can have an assistant or a substitute, and
-- an instructor's pay/among-instructor reporting needs to know which.
CREATE TABLE cohort_staff (
  cohort_id     BIGINT UNSIGNED NOT NULL,
  membership_id BIGINT UNSIGNED NOT NULL,
  role          VARCHAR(24)  NOT NULL DEFAULT 'instructor',  -- instructor|assistant|observer
  starts_on     DATE         NULL,
  ends_on       DATE         NULL,
  PRIMARY KEY (cohort_id, membership_id, role),
  KEY ix_cohort_staff_member (membership_id),
  CONSTRAINT fk_cs_cohort FOREIGN KEY (cohort_id)     REFERENCES cohorts(id) ON DELETE CASCADE,
  CONSTRAINT fk_cs_member FOREIGN KEY (membership_id) REFERENCES memberships(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- THE enrolment. One row per learner per cohort — no second model.
CREATE TABLE enrolments (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id         CHAR(26)     NOT NULL,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  membership_id     BIGINT UNSIGNED NOT NULL,
  cohort_id         BIGINT UNSIGNED NOT NULL,
  status            VARCHAR(24)  NOT NULL DEFAULT 'pending',
     -- pending|active|completed|withdrawn|transferred|waitlisted
  waitlist_position SMALLINT UNSIGNED NULL,
  enrolled_at       DATETIME(3)  NULL,
  completed_at      DATETIME(3)  NULL,
  withdrawn_at      DATETIME(3)  NULL,
  withdrawal_reason VARCHAR(255) NULL,
  final_score       DECIMAL(6,2) NULL,
  final_grade       VARCHAR(8)   NULL,
  outcome           VARCHAR(16)  NULL,        -- pass|fail|incomplete
  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at        DATETIME(3)  NULL,
  -- 0 while live, the row id once soft-deleted (see the header note).
  deleted_seq   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_by        BIGINT UNSIGNED NULL,
  updated_by        BIGINT UNSIGNED NULL,
  row_version       INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_enrolments_public (public_id),
  -- One live enrolment per learner per cohort. Scoped by deleted_at so a
  -- withdrawn-and-deleted row does not block re-enrolment.
  UNIQUE KEY uq_enrolments_unique (cohort_id, membership_id, deleted_seq),
  KEY ix_enrolments_member (tenant_id, membership_id, status),
  KEY ix_enrolments_cohort (cohort_id, status),
  CONSTRAINT fk_enrol_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_enrol_member FOREIGN KEY (membership_id) REFERENCES memberships(id),
  CONSTRAINT fk_enrol_cohort FOREIGN KEY (cohort_id)     REFERENCES cohorts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rooms are declared before class_sessions so the session's room_id can carry
-- a real foreign key rather than being an unenforced integer.
CREATE TABLE rooms (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  centre_id     BIGINT UNSIGNED NOT NULL,
  name          VARCHAR(120) NOT NULL,
  capacity      SMALLINT UNSIGNED NULL,
  resources     JSON         NULL,        -- piano, drum kit, PA…
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  deleted_at    DATETIME(3)  NULL,
  -- 0 while live, the row id once soft-deleted (see the header note).
  deleted_seq   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_rooms (tenant_id, centre_id, name, deleted_seq),
  CONSTRAINT fk_rooms_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_rooms_centre FOREIGN KEY (centre_id) REFERENCES centres(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A delivered session. `scheduled_start_local` + `timezone` is the wall clock;
-- `scheduled_start_utc` is the derived instant, stored so ordering and range
-- queries are index-friendly, and recomputed if the zone database changes.
CREATE TABLE class_sessions (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id             CHAR(26)     NOT NULL,
  tenant_id             BIGINT UNSIGNED NOT NULL,
  cohort_id             BIGINT UNSIGNED NOT NULL,
  module_id             BIGINT UNSIGNED NULL,
  room_id               BIGINT UNSIGNED NULL,
  title                 VARCHAR(200) NULL,
  sequence_no           SMALLINT UNSIGNED NULL,
  scheduled_start_local DATETIME     NOT NULL,
  timezone              VARCHAR(64)  NOT NULL,
  scheduled_start_utc   DATETIME(3)  NOT NULL,
  duration_minutes      SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  delivery_mode         VARCHAR(16)  NOT NULL DEFAULT 'onsite',
  status                VARCHAR(16)  NOT NULL DEFAULT 'scheduled',
     -- scheduled|held|cancelled|rescheduled
  meeting_provider      VARCHAR(24)  NULL,
  meeting_url           VARCHAR(500) NULL,
  meeting_code          VARCHAR(64)  NULL,
  rescheduled_from_id   BIGINT UNSIGNED NULL,
  cancellation_reason   VARCHAR(255) NULL,
  notes                 TEXT         NULL,
  created_at            DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at            DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at            DATETIME(3)  NULL,
  row_version           INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_sessions_public (public_id),
  KEY ix_sessions_cohort (cohort_id, scheduled_start_utc),
  KEY ix_sessions_when (tenant_id, scheduled_start_utc, status),
  CONSTRAINT fk_sessions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_sessions_cohort FOREIGN KEY (cohort_id) REFERENCES cohorts(id) ON DELETE CASCADE,
  CONSTRAINT fk_sessions_module FOREIGN KEY (module_id) REFERENCES modules(id),
  CONSTRAINT fk_sessions_room   FOREIGN KEY (room_id)   REFERENCES rooms(id),
  CONSTRAINT fk_sessions_prev   FOREIGN KEY (rescheduled_from_id) REFERENCES class_sessions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attendance carries the full seven-status vocabulary from the start. v1's
-- CHECK admitted four, so `left_early`, `technical_failure` and `rescheduled`
-- had to be recorded as an `absent` that misrepresented what happened.
--
-- Durations are STORED, never recomputed on read: a session's scheduled time
-- can be edited afterwards and the record of what actually happened must not
-- move with it.
CREATE TABLE attendance (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  session_id        BIGINT UNSIGNED NOT NULL,
  enrolment_id      BIGINT UNSIGNED NOT NULL,
  status            VARCHAR(24)  NOT NULL,
  delivery_mode     VARCHAR(16)  NOT NULL DEFAULT 'onsite',
  clocked_in_at     DATETIME(3)  NULL,
  clocked_out_at    DATETIME(3)  NULL,
  minutes_present   SMALLINT UNSIGNED NULL,
  minutes_late      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  method            VARCHAR(24)  NOT NULL DEFAULT 'instructor',  -- instructor|self|qr|card|api
  geo_lat           DECIMAL(10,7) NULL,
  geo_lng           DECIMAL(10,7) NULL,
  notes             VARCHAR(500) NULL,
  marked_by         BIGINT UNSIGNED NULL,
  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  row_version       INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_attendance (session_id, enrolment_id),
  KEY ix_attendance_enrolment (enrolment_id, status),
  KEY ix_attendance_tenant (tenant_id, status, created_at),
  CONSTRAINT chk_attendance_status CHECK (status IN
    ('present','late','absent','excused','left_early','technical_failure','rescheduled')),
  CONSTRAINT chk_attendance_mode CHECK (delivery_mode IN ('onsite','online','hybrid')),
  CONSTRAINT fk_att_tenant   FOREIGN KEY (tenant_id)    REFERENCES tenants(id),
  CONSTRAINT fk_att_session  FOREIGN KEY (session_id)   REFERENCES class_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_att_enrolment FOREIGN KEY (enrolment_id) REFERENCES enrolments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-learner progress through a module. This is where "curriculum vs schedule
-- vs mastery" becomes three separate facts: week_planned, week_started,
-- week_completed, and a mastery percentage that is null until assessed.
CREATE TABLE module_progress (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  enrolment_id      BIGINT UNSIGNED NOT NULL,
  module_id         BIGINT UNSIGNED NOT NULL,
  status            VARCHAR(32)  NOT NULL DEFAULT 'not_started',
  percent_complete  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  -- NULL means "not assessed", which is a different statement from zero and
  -- must stay distinguishable: scoring an unassessed beginner 0 reports them
  -- as failing.
  mastery_pct       DECIMAL(5,2) NULL,
  week_planned      SMALLINT UNSIGNED NULL,
  week_started      SMALLINT UNSIGNED NULL,
  week_completed    SMALLINT UNSIGNED NULL,
  attempts          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  due_at            DATETIME(3)  NULL,
  completed_at      DATETIME(3)  NULL,
  updated_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  row_version       INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_progress (enrolment_id, module_id),
  KEY ix_progress_status (tenant_id, status),
  CONSTRAINT chk_progress_status CHECK (status IN
    ('not_started','available','preparation_pending','in_progress','submitted',
     'under_assessment','developing','proficient','advanced','mastered',
     'carried_forward','remedial','completed','exempted')),
  CONSTRAINT fk_prog_tenant   FOREIGN KEY (tenant_id)    REFERENCES tenants(id),
  CONSTRAINT fk_prog_enrolment FOREIGN KEY (enrolment_id) REFERENCES enrolments(id) ON DELETE CASCADE,
  CONSTRAINT fk_prog_module   FOREIGN KEY (module_id)    REFERENCES modules(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Carry-forward is APPENDED, never an overwrite: no academic history is lost.
CREATE TABLE module_carryforwards (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  enrolment_id      BIGINT UNSIGNED NOT NULL,
  module_id         BIGINT UNSIGNED NOT NULL,
  from_week         SMALLINT UNSIGNED NULL,
  to_week           SMALLINT UNSIGNED NULL,
  to_date           DATE         NULL,
  previous_status   VARCHAR(32)  NOT NULL,
  previous_mastery  DECIMAL(5,2) NULL,
  reason            VARCHAR(500) NULL,
  occurred_at       DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by        BIGINT UNSIGNED NULL,
  KEY ix_carry_enrolment (enrolment_id, occurred_at),
  CONSTRAINT fk_carry_tenant    FOREIGN KEY (tenant_id)    REFERENCES tenants(id),
  CONSTRAINT fk_carry_enrolment FOREIGN KEY (enrolment_id) REFERENCES enrolments(id) ON DELETE CASCADE,
  CONSTRAINT fk_carry_module    FOREIGN KEY (module_id)    REFERENCES modules(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- LAYER F · MONEY
-- =============================================================================
-- v1 stored a bare `amount` that meant whole naira on one row and dollars on
-- the next, so SUM(amount) added the two and the Revenue KPI reported nonsense.
-- Here the invariant is structural: if the transaction currency differs from
-- the tenant's base, the base amount and the frozen rate are BOTH required.

CREATE TABLE fx_rates (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  base_currency CHAR(3)      NOT NULL,
  quote_currency CHAR(3)     NOT NULL,
  rate          DECIMAL(18,8) NOT NULL,
  source        VARCHAR(32)  NOT NULL,       -- which feed, so a dispute is answerable
  observed_at   DATETIME(3)  NOT NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_fx (base_currency, quote_currency, source, observed_at),
  KEY ix_fx_lookup (base_currency, quote_currency, observed_at),
  CONSTRAINT chk_fx_positive CHECK (rate > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Effective-dated pricing. A price change must never alter what an existing
-- invoice charged, so prices are versioned by validity window rather than
-- updated in place.
CREATE TABLE price_books (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  code          VARCHAR(48)  NOT NULL,
  name          VARCHAR(160) NOT NULL,
  currency      CHAR(3)      NOT NULL,
  valid_from    DATE         NOT NULL,
  valid_to      DATE         NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_price_books (tenant_id, code, valid_from),
  KEY ix_price_books_window (tenant_id, valid_from, valid_to),
  CONSTRAINT fk_pb_tenant   FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_pb_currency FOREIGN KEY (currency)  REFERENCES currencies(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE price_items (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  price_book_id   BIGINT UNSIGNED NOT NULL,
  item_type       VARCHAR(32)  NOT NULL,     -- tuition|registration|exam|merch|fee
  programme_id    BIGINT UNSIGNED NULL,
  course_id       BIGINT UNSIGNED NULL,
  level_id        BIGINT UNSIGNED NULL,
  sku             VARCHAR(64)  NULL,
  label           VARCHAR(160) NOT NULL,
  amount_minor    BIGINT       NOT NULL,
  tax_rate_pct    DECIMAL(5,2) NOT NULL DEFAULT 0,
  KEY ix_price_items_book (price_book_id, item_type),
  CONSTRAINT fk_pi_book FOREIGN KEY (price_book_id) REFERENCES price_books(id) ON DELETE CASCADE,
  CONSTRAINT chk_pi_amount CHECK (amount_minor >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoices (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id         CHAR(26)     NOT NULL,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  membership_id     BIGINT UNSIGNED NOT NULL,
  enrolment_id      BIGINT UNSIGNED NULL,
  number            VARCHAR(32)  NOT NULL,      -- human-facing, per tenant
  status            VARCHAR(16)  NOT NULL DEFAULT 'draft',
     -- draft|issued|part_paid|paid|void|written_off
  currency          CHAR(3)      NOT NULL,
  subtotal_minor    BIGINT       NOT NULL DEFAULT 0,
  tax_minor         BIGINT       NOT NULL DEFAULT 0,
  discount_minor    BIGINT       NOT NULL DEFAULT 0,
  total_minor       BIGINT       NOT NULL DEFAULT 0,
  -- Base-currency mirror, frozen at issue. Reporting sums THIS, never the
  -- transaction amount, which is what makes cross-currency totals meaningful.
  base_currency     CHAR(3)      NOT NULL,
  base_total_minor  BIGINT       NULL,
  fx_rate           DECIMAL(18,8) NULL,
  fx_source         VARCHAR(32)  NULL,
  issued_at         DATETIME(3)  NULL,
  due_at            DATETIME(3)  NULL,
  paid_at           DATETIME(3)  NULL,
  voided_at         DATETIME(3)  NULL,
  void_reason       VARCHAR(255) NULL,
  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  row_version       INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_invoices_public (public_id),
  UNIQUE KEY uq_invoices_number (tenant_id, number),
  KEY ix_invoices_member (tenant_id, membership_id, status),
  KEY ix_invoices_due (tenant_id, status, due_at),
  -- THE invariant v1 lacked.
  CONSTRAINT chk_inv_fx CHECK (
    currency = base_currency OR (base_total_minor IS NOT NULL AND fx_rate IS NOT NULL)
  ),
  CONSTRAINT fk_inv_tenant   FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_inv_member   FOREIGN KEY (membership_id) REFERENCES memberships(id),
  CONSTRAINT fk_inv_enrolment FOREIGN KEY (enrolment_id) REFERENCES enrolments(id),
  CONSTRAINT fk_inv_currency FOREIGN KEY (currency)      REFERENCES currencies(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoice_lines (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id        BIGINT UNSIGNED NOT NULL,
  item_type         VARCHAR(32)  NOT NULL,
  description       VARCHAR(255) NOT NULL,
  quantity          DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_amount_minor BIGINT       NOT NULL,
  tax_rate_pct      DECIMAL(5,2) NOT NULL DEFAULT 0,
  line_total_minor  BIGINT       NOT NULL,
  price_item_id     BIGINT UNSIGNED NULL,     -- provenance: which price applied
  metadata          JSON         NULL,
  KEY ix_lines_invoice (invoice_id),
  CONSTRAINT fk_line_invoice FOREIGN KEY (invoice_id)    REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_line_price   FOREIGN KEY (price_item_id) REFERENCES price_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id         CHAR(26)     NOT NULL,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  membership_id     BIGINT UNSIGNED NULL,
  provider          VARCHAR(32)  NOT NULL,      -- paystack|flutterwave|bank|cash|credit
  provider_ref      VARCHAR(128) NULL,
  status            VARCHAR(16)  NOT NULL DEFAULT 'pending',
     -- pending|succeeded|failed|refunded|partially_refunded|disputed
  currency          CHAR(3)      NOT NULL,
  amount_minor      BIGINT       NOT NULL,
  fee_minor         BIGINT       NOT NULL DEFAULT 0,
  base_currency     CHAR(3)      NOT NULL,
  base_amount_minor BIGINT       NULL,
  fx_rate           DECIMAL(18,8) NULL,
  fx_source         VARCHAR(32)  NULL,
  received_at       DATETIME(3)  NULL,
  reconciled_at     DATETIME(3)  NULL,
  metadata          JSON         NULL,
  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  row_version       INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_payments_public (public_id),
  -- A provider reference is unique per provider: the guard against a webhook
  -- replay creating a second payment for one charge.
  UNIQUE KEY uq_payments_provider (tenant_id, provider, provider_ref),
  KEY ix_payments_member (tenant_id, membership_id, status),
  KEY ix_payments_reporting (tenant_id, status, received_at),
  CONSTRAINT chk_pay_fx CHECK (
    currency = base_currency OR (base_amount_minor IS NOT NULL AND fx_rate IS NOT NULL)
  ),
  CONSTRAINT fk_pay_tenant   FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_pay_member   FOREIGN KEY (membership_id) REFERENCES memberships(id),
  CONSTRAINT fk_pay_currency FOREIGN KEY (currency)      REFERENCES currencies(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A payment may settle several invoices and an invoice may take several
-- payments, so allocation is its own table rather than a foreign key on either.
CREATE TABLE payment_allocations (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id    BIGINT UNSIGNED NOT NULL,
  invoice_id    BIGINT UNSIGNED NOT NULL,
  amount_minor  BIGINT       NOT NULL,
  allocated_at  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  allocated_by  BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_alloc (payment_id, invoice_id),
  KEY ix_alloc_invoice (invoice_id),
  CONSTRAINT fk_alloc_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
  CONSTRAINT fk_alloc_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id),
  CONSTRAINT chk_alloc_amount CHECK (amount_minor > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE refunds (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id         CHAR(26)     NOT NULL,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  payment_id        BIGINT UNSIGNED NOT NULL,
  amount_minor      BIGINT       NOT NULL,
  currency          CHAR(3)      NOT NULL,
  reason            VARCHAR(255) NULL,
  status            VARCHAR(16)  NOT NULL DEFAULT 'pending',
  provider_ref      VARCHAR(128) NULL,
  processed_at      DATETIME(3)  NULL,
  requested_by      BIGINT UNSIGNED NULL,
  approved_by       BIGINT UNSIGNED NULL,
  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_refunds_public (public_id),
  KEY ix_refunds_payment (payment_id),
  CONSTRAINT fk_refund_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants(id),
  CONSTRAINT fk_refund_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
  CONSTRAINT chk_refund_amount CHECK (amount_minor > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The ledger is the truth; invoices and payments are documents ABOUT the truth.
-- Every movement is a balanced pair (or set) sharing a transaction_id, which is
-- what makes "why does this learner owe ₦8,000" answerable without archaeology.
CREATE TABLE ledger_accounts (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  code          VARCHAR(48)  NOT NULL,     -- ar|cash|revenue|discount|refunds|fees
  name          VARCHAR(120) NOT NULL,
  type          VARCHAR(16)  NOT NULL,     -- asset|liability|income|expense|equity
  currency      CHAR(3)      NOT NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_ledger_accounts (tenant_id, code),
  CONSTRAINT fk_la_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ledger_entries (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  transaction_id    CHAR(26)     NOT NULL,      -- groups the balanced set
  account_id        BIGINT UNSIGNED NOT NULL,
  membership_id     BIGINT UNSIGNED NULL,
  -- Signed minor units: positive debit, negative credit. Sum per
  -- transaction_id MUST be zero; asserted by the load's verify step and by a
  -- periodic job, since MySQL cannot express a cross-row CHECK.
  amount_minor      BIGINT       NOT NULL,
  currency          CHAR(3)      NOT NULL,
  base_amount_minor BIGINT       NULL,
  fx_rate           DECIMAL(18,8) NULL,
  source_type       VARCHAR(32)  NOT NULL,      -- invoice|payment|refund|adjustment|fee
  source_id         BIGINT UNSIGNED NULL,
  description       VARCHAR(255) NULL,
  occurred_at       DATETIME(3)  NOT NULL,
  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by        BIGINT UNSIGNED NULL,
  KEY ix_ledger_txn (transaction_id),
  KEY ix_ledger_account (tenant_id, account_id, occurred_at),
  KEY ix_ledger_member (tenant_id, membership_id, occurred_at),
  CONSTRAINT fk_le_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants(id),
  CONSTRAINT fk_le_account FOREIGN KEY (account_id) REFERENCES ledger_accounts(id),
  CONSTRAINT fk_le_member  FOREIGN KEY (membership_id) REFERENCES memberships(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Punctuality / accountability charges, with the six-status vocabulary and the
-- request→decision flow the v1 ledger lacked entirely.
CREATE TABLE accountability_charges (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id         CHAR(26)     NOT NULL,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  subject_membership_id BIGINT UNSIGNED NOT NULL,
  session_id        BIGINT UNSIGNED NULL,
  reason            VARCHAR(48)  NOT NULL,
  minutes           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  currency          CHAR(3)      NOT NULL,
  amount_minor      BIGINT       NOT NULL,
  base_amount_minor BIGINT       NULL,
  fx_rate           DECIMAL(18,8) NULL,
  status            VARCHAR(16)  NOT NULL DEFAULT 'outstanding',
  resolved_by       BIGINT UNSIGNED NULL,
  resolved_at       DATETIME(3)  NULL,
  resolution_note   VARCHAR(500) NULL,
  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_charges_public (public_id),
  UNIQUE KEY uq_charges_dedupe (subject_membership_id, session_id, reason),
  KEY ix_charges_status (tenant_id, status, created_at),
  CONSTRAINT chk_charge_status CHECK (status IN
    ('pending','outstanding','paid','waived','disputed','reversed')),
  CONSTRAINT fk_charge_tenant  FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_charge_subject FOREIGN KEY (subject_membership_id) REFERENCES memberships(id),
  CONSTRAINT fk_charge_session FOREIGN KEY (session_id) REFERENCES class_sessions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE accountability_exceptions (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  charge_id         BIGINT UNSIGNED NOT NULL,
  requested_by      BIGINT UNSIGNED NULL,
  reason_code       VARCHAR(48)  NOT NULL,
  detail            VARCHAR(1000) NULL,
  status            VARCHAR(16)  NOT NULL DEFAULT 'pending',   -- pending|approved|rejected
  decided_by        BIGINT UNSIGNED NULL,
  decided_at        DATETIME(3)  NULL,
  decision_note     VARCHAR(1000) NULL,
  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_exceptions_charge (charge_id),
  KEY ix_exceptions_queue (tenant_id, status, id),
  CONSTRAINT fk_exc_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_exc_charge FOREIGN KEY (charge_id) REFERENCES accountability_charges(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- LAYER G · PLATFORM SERVICES
-- =============================================================================

-- Tamper-evident audit. row_hash = sha256(prev_hash || canonical(row)), so any
-- retro-edit or deletion breaks the chain from that point and a verifier can
-- prove the log intact. For an academy issuing certificates and charging fees,
-- that is the difference between "our records say" and "our records can be
-- shown not to have been altered".
CREATE TABLE audit_log (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NULL,          -- NULL = platform-level action
  actor_user_id BIGINT UNSIGNED NULL,
  actor_label   VARCHAR(120) NULL,             -- credential used (e.g. break-glass)
  action        VARCHAR(80)  NOT NULL,
  entity_type   VARCHAR(48)  NOT NULL,
  entity_id     BIGINT UNSIGNED NULL,
  before_state  JSON         NULL,
  after_state   JSON         NULL,
  metadata      JSON         NULL,
  ip            VARBINARY(16) NULL,
  user_agent    VARCHAR(255) NULL,
  request_id    CHAR(26)     NULL,             -- ties a burst to one request
  occurred_at   DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  prev_hash     CHAR(64)     NULL,
  row_hash      CHAR(64)     NOT NULL,
  KEY ix_audit_entity (tenant_id, entity_type, entity_id, occurred_at),
  KEY ix_audit_actor (actor_user_id, occurred_at),
  KEY ix_audit_action (tenant_id, action, occurred_at),
  CONSTRAINT fk_audit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The outbox. Anything that must reach the outside world is written HERE in the
-- same transaction as the state change that caused it, then delivered by a
-- worker. This removes v1's failure mode where a payment settled, the receipt
-- email threw, and the two facts disagreed with nobody able to say which won.
CREATE TABLE outbox_messages (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id       BIGINT UNSIGNED NULL,
  topic           VARCHAR(80)  NOT NULL,       -- payment.succeeded|enrolment.activated
  payload         JSON         NOT NULL,
  status          VARCHAR(16)  NOT NULL DEFAULT 'pending',
     -- pending|delivering|delivered|failed|dead
  attempts        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts    SMALLINT UNSIGNED NOT NULL DEFAULT 8,
  available_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),  -- backoff
  last_error      VARCHAR(500) NULL,
  delivered_at    DATETIME(3)  NULL,
  -- A dead message is retained, not discarded: "we never sent it" must be
  -- discoverable, and a silent drop is how integrations rot unnoticed.
  created_at      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_outbox_claim (status, available_at, id),
  KEY ix_outbox_topic (tenant_id, topic, created_at),
  CONSTRAINT fk_outbox_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inbound webhooks, deduplicated by provider event id. The unique key is the
-- replay guard; `signature_verified` records that HMAC passed, so a later
-- dispute can distinguish "we accepted it" from "we verified it".
CREATE TABLE webhook_events (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id           BIGINT UNSIGNED NULL,
  provider            VARCHAR(32)  NOT NULL,
  event_id            VARCHAR(190) NOT NULL,
  event_type          VARCHAR(80)  NULL,
  signature_verified  TINYINT(1)   NOT NULL DEFAULT 0,
  payload             JSON         NOT NULL,
  processed_at        DATETIME(3)  NULL,
  process_result      VARCHAR(255) NULL,
  received_at         DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_webhook (provider, event_id),
  KEY ix_webhook_unprocessed (processed_at, received_at),
  CONSTRAINT fk_webhook_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotency for mutating API calls: the same key returns the first response
-- rather than performing the action twice.
CREATE TABLE idempotency_keys (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id       BIGINT UNSIGNED NULL,
  idem_key        VARCHAR(190) NOT NULL,
  endpoint        VARCHAR(190) NOT NULL,
  request_hash    CHAR(64)     NOT NULL,   -- same key + different body = conflict
  response_status SMALLINT UNSIGNED NULL,
  response_body   JSON         NULL,
  locked_at       DATETIME(3)  NULL,
  completed_at    DATETIME(3)  NULL,
  expires_at      DATETIME(3)  NOT NULL,
  created_at      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_idem (tenant_id, idem_key, endpoint),
  KEY ix_idem_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Consent, per purpose, with the evidence of how it was given. Withdrawal is a
-- new row, never an update: "when did they consent, and when did they stop"
-- must both be answerable.
CREATE TABLE consents (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  membership_id     BIGINT UNSIGNED NOT NULL,
  purpose           VARCHAR(48)  NOT NULL,     -- media|photo|data|marketing|recording
  is_granted        TINYINT(1)   NOT NULL,
  granted_by_membership_id BIGINT UNSIGNED NULL,  -- a guardian, for a minor
  evidence          JSON         NULL,         -- form version, IP, wording shown
  effective_from    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  effective_to      DATETIME(3)  NULL,
  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_consents_current (tenant_id, membership_id, purpose, effective_to),
  CONSTRAINT fk_consent_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_consent_member FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Subject access / erasure requests, with the workflow rather than an ad-hoc
-- email thread. Retention periods are configured per tenant, not hardcoded.
CREATE TABLE data_subject_requests (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id         CHAR(26)     NOT NULL,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  membership_id     BIGINT UNSIGNED NULL,
  subject_email     VARCHAR(190) NOT NULL,
  kind              VARCHAR(16)  NOT NULL,     -- access|erasure|rectify|export|restrict
  status            VARCHAR(16)  NOT NULL DEFAULT 'received',
  received_at       DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  due_at            DATETIME(3)  NULL,         -- statutory clock
  completed_at      DATETIME(3)  NULL,
  handled_by        BIGINT UNSIGNED NULL,
  outcome_note      VARCHAR(1000) NULL,
  -- What was retained despite an erasure, and on what legal basis. An erasure
  -- that silently keeps financial records is a compliance problem; one that
  -- records WHY it kept them is a defensible position.
  retained_basis    JSON         NULL,
  UNIQUE KEY uq_dsr_public (public_id),
  KEY ix_dsr_queue (tenant_id, status, due_at),
  CONSTRAINT fk_dsr_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bounded EAV: the definition constrains type and validation, so this is an
-- extension mechanism rather than an untyped bag. It exists so onboarding a
-- second academy that tracks "Suzuki book number" needs no migration.
CREATE TABLE custom_field_defs (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  entity_type   VARCHAR(48)  NOT NULL,     -- memberships|enrolments|applications
  field_key     VARCHAR(64)  NOT NULL,
  label         VARCHAR(160) NOT NULL,
  data_type     VARCHAR(16)  NOT NULL,     -- string|int|decimal|bool|date|enum|json
  options       JSON         NULL,         -- enum choices
  validation    JSON         NULL,         -- min/max/regex/required
  is_required   TINYINT(1)   NOT NULL DEFAULT 0,
  is_pii        TINYINT(1)   NOT NULL DEFAULT 0,   -- drives export/erasure handling
  sort_order    INT          NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_cfd (tenant_id, entity_type, field_key),
  CONSTRAINT fk_cfd_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE custom_field_values (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  field_def_id  BIGINT UNSIGNED NOT NULL,
  entity_id     BIGINT UNSIGNED NOT NULL,
  value_text    TEXT         NULL,
  value_number  DECIMAL(20,6) NULL,
  value_date    DATETIME(3)  NULL,
  value_bool    TINYINT(1)   NULL,
  updated_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_cfv (field_def_id, entity_id),
  KEY ix_cfv_entity (tenant_id, entity_id),
  -- Typed columns rather than one text blob, so filtering and sorting on a
  -- custom numeric field uses an index instead of a cast.
  KEY ix_cfv_number (field_def_id, value_number),
  CONSTRAINT fk_cfv_def    FOREIGN KEY (field_def_id) REFERENCES custom_field_defs(id) ON DELETE CASCADE,
  CONSTRAINT fk_cfv_tenant FOREIGN KEY (tenant_id)    REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Files, with the access policy on the row rather than in the URL. v1 relied on
-- an unguessable key for recordings of children; `visibility` + the owning
-- entity is what lets a proxy answer "may THIS caller see it".
CREATE TABLE attachments (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  entity_type   VARCHAR(48)  NOT NULL,
  entity_id     BIGINT UNSIGNED NULL,
  kind          VARCHAR(24)  NOT NULL,     -- headshot|take|document|score|recording
  storage       VARCHAR(24)  NOT NULL DEFAULT 'local',   -- local|drive|s3|cloudinary
  storage_key   VARCHAR(500) NOT NULL,
  mime_type     VARCHAR(100) NULL,
  byte_size     BIGINT UNSIGNED NULL,
  checksum      CHAR(64)     NULL,
  visibility    VARCHAR(16)  NOT NULL DEFAULT 'private', -- private|tenant|public
  uploaded_by   BIGINT UNSIGNED NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  deleted_at    DATETIME(3)  NULL,
  UNIQUE KEY uq_attachments_public (public_id),
  KEY ix_attachments_entity (tenant_id, entity_type, entity_id),
  CONSTRAINT fk_att_tenant2 FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notifications and their delivery attempts, split so one notification can be
-- delivered over several channels with independent outcomes.
CREATE TABLE notifications (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  membership_id BIGINT UNSIGNED NOT NULL,
  type          VARCHAR(64)  NOT NULL,
  title         VARCHAR(200) NOT NULL,
  body          TEXT         NULL,
  url           VARCHAR(500) NULL,
  severity      VARCHAR(16)  NOT NULL DEFAULT 'info',
  read_at       DATETIME(3)  NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_notifications_inbox (membership_id, read_at, id),
  CONSTRAINT fk_notif_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_notif_member FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_deliveries (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id       BIGINT UNSIGNED NOT NULL,
  notification_id BIGINT UNSIGNED NULL,
  channel         VARCHAR(16)  NOT NULL,     -- email|sms|whatsapp|push
  template_key    VARCHAR(80)  NULL,
  recipient       VARCHAR(320) NOT NULL,
  subject         VARCHAR(512) NULL,
  status          VARCHAR(16)  NOT NULL DEFAULT 'queued',
  provider        VARCHAR(32)  NULL,
  provider_ref    VARCHAR(190) NULL,
  attempts        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_error      VARCHAR(500) NULL,
  sent_at         DATETIME(3)  NULL,
  opened_at       DATETIME(3)  NULL,
  created_at      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_delivery_status (tenant_id, status, created_at),
  KEY ix_delivery_recipient (recipient, created_at),
  CONSTRAINT fk_delivery_tenant FOREIGN KEY (tenant_id)       REFERENCES tenants(id),
  CONSTRAINT fk_delivery_notif  FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Background work, with a claim column so several workers can run without two
-- of them taking the same job.
CREATE TABLE jobs (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NULL,
  queue         VARCHAR(48)  NOT NULL DEFAULT 'default',
  job_type      VARCHAR(80)  NOT NULL,
  payload       JSON         NULL,
  status        VARCHAR(16)  NOT NULL DEFAULT 'pending',
  attempts      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts  SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  available_at  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  claimed_by    VARCHAR(64)  NULL,
  claimed_at    DATETIME(3)  NULL,
  completed_at  DATETIME(3)  NULL,
  last_error    VARCHAR(500) NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_jobs_claim (queue, status, available_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Maps every v1 identifier to its v2 row. Written by the ETL, kept forever:
-- old references live in emails, receipts and people's memories, so
-- "application MAM-2026-0412" must remain resolvable after cutover.
CREATE TABLE legacy_reference_map (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id       BIGINT UNSIGNED NOT NULL,
  legacy_table    VARCHAR(64)  NOT NULL,
  legacy_id       VARCHAR(64)  NOT NULL,
  legacy_ref      VARCHAR(64)  NULL,        -- human-facing reference, if any
  v2_table        VARCHAR(64)  NOT NULL,
  v2_id           BIGINT UNSIGNED NOT NULL,
  migrated_at     DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  note            VARCHAR(255) NULL,
  UNIQUE KEY uq_legacy (tenant_id, legacy_table, legacy_id),
  KEY ix_legacy_ref (tenant_id, legacy_ref),
  KEY ix_legacy_target (v2_table, v2_id),
  CONSTRAINT fk_legacy_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schema baseline. A fresh v2 install starts AT the target rather than
-- replaying v1's 81 migrations.
CREATE TABLE schema_version (
  version     INT NOT NULL PRIMARY KEY,
  applied_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  note        VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_version (version, note) VALUES (200, 'schema-v2 baseline');

-- Seed the currencies the platform ships knowing about. Everything else is
-- tenant data and arrives via the ETL or the admin.
INSERT INTO currencies (code, name, exponent, symbol) VALUES
  ('USD','US Dollar',2,'$'),
  ('NGN','Nigerian Naira',2,'₦'),
  ('GBP','Pound Sterling',2,'£'),
  ('EUR','Euro',2,'€'),
  ('JPY','Japanese Yen',0,'¥');
