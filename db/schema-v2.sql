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
  -- Per tenant, and MAM's is NGN. Every payment in the v1 data is naira and
  -- v59 predates any USD column, so a USD base would force the ETL to invent a
  -- rate per historical transaction and store it as frozen fact. Reporting in
  -- another currency stays available through fx_rates, going forward, where a
  -- real observed rate exists.
  base_currency     CHAR(3)      NOT NULL DEFAULT 'NGN',
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
     -- prospect|applicant|admitted|enrolled|active|paused|alumnus|withdrawn|rejected
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
  -- Vocabulary and wording come from ONE place: mam_v2_status_catalogue()
  -- in api/lib/v2/status-catalogue.php. test_v2_clarity.php asserts this CHECK
  -- and that catalogue list the same values, so a status can never exist that
  -- nobody has written a sentence for.
  CONSTRAINT chk_membership_stage CHECK (stage IN
    ('prospect','applicant','admitted','enrolled','active','paused','alumnus','withdrawn','rejected')),
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
  tenant_id     BIGINT UNSIGNED NULL,        -- NULL = platform role (carries the FK)
  -- 0 = platform, otherwise the tenant id. NOT NULL so the unique key below
  -- actually holds: with a nullable tenant_id, five platform roles could all be
  -- called 'admin' and authorisation would depend on which one was fetched.
  tenant_scope  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  code          VARCHAR(64)  NOT NULL,
  name          VARCHAR(120) NOT NULL,
  description   VARCHAR(255) NULL,
  is_system     TINYINT(1)   NOT NULL DEFAULT 0,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_roles_code (tenant_scope, code),
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
     -- draft|submitted|under_review|interview_scheduled|offered|accepted|
     -- waitlisted|rejected|withdrawn
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
  -- The reference the applicant is given and will quote back on the phone.
  -- v1 had one for scholarships only, so every other applicant had nothing to
  -- say when they called except their name.
  reference         VARCHAR(32)  NULL,
  source_channel    VARCHAR(48)  NULL,           -- attribution
  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at        DATETIME(3)  NULL,
  -- 0 while live, the row id once soft-deleted (see the header note).
  deleted_seq       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  row_version       INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_applications_public (public_id),
  UNIQUE KEY uq_applications_ref (tenant_id, reference, deleted_seq),
  KEY ix_applications_pipeline (tenant_id, status, submitted_at),
  KEY ix_applications_member (membership_id),
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
  -- A named scope both parties can be shown in one phrase. The JSON below
  -- refines it; the named level is what a learner is told ("your guardian can
  -- see your progress and your fees") and what a guardian is shown on sign-up.
  -- Free-form permissions alone left neither side able to state the answer.
  scope             VARCHAR(24)  NOT NULL DEFAULT 'standard',
     -- billing_only|standard|full
  permissions       JSON         NULL,       -- view_progress|view_invoices|approve_media
  verified_at       DATETIME(3)  NULL,
  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  deleted_at        DATETIME(3)  NULL,
  -- 0 while live, the row id once soft-deleted (see the header note).
  deleted_seq   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_by        BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_guardianship (tenant_id, guardian_membership_id, learner_membership_id, deleted_seq),
  KEY ix_guardianship_learner (learner_membership_id),
  CONSTRAINT chk_guard_scope CHECK (scope IN ('billing_only','standard','full')),
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
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  programme_id  BIGINT UNSIGNED NOT NULL,
  course_id     BIGINT UNSIGNED NOT NULL,
  level_id      BIGINT UNSIGNED NULL,        -- NULL = the course at no set level
  -- 0 = no level, otherwise the level id. The natural key was the PRIMARY KEY
  -- and included the nullable level_id: MySQL coerces PK columns to NOT NULL
  -- and would have REJECTED every level-less row, while SQLite accepted them
  -- and allowed duplicates. A surrogate id plus this companion makes the two
  -- engines agree.
  level_scope   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  is_required   TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order    INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_pc (programme_id, course_id, level_scope),
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
  -- Short human reference ("PNO-L1-A26"). A title is for reading; a reference
  -- is for saying out loud and typing into a search box.
  reference       VARCHAR(32)  NULL,
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
  -- 0 while live, the row id once soft-deleted (see the header note).
  deleted_seq     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_by      BIGINT UNSIGNED NULL,
  updated_by      BIGINT UNSIGNED NULL,
  row_version     INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_cohorts_public (public_id),
  UNIQUE KEY uq_cohorts_ref (tenant_id, reference, deleted_seq),
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
  CONSTRAINT chk_enrol_status CHECK (status IN
    ('pending','active','completed','withdrawn','transferred','waitlisted')),
  CONSTRAINT chk_enrol_withdrawal_reason CHECK (status <> 'withdrawn' OR withdrawal_reason IS NOT NULL),
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
  -- A class that vanishes from the timetable with no reason is the single
  -- complaint every academy gets. The reason is required at the source.
  CONSTRAINT chk_session_status CHECK (status IN ('scheduled','held','cancelled','rescheduled')),
  CONSTRAINT chk_session_cancel_reason CHECK (status <> 'cancelled' OR cancellation_reason IS NOT NULL),
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
  waiver_minor      BIGINT       NOT NULL DEFAULT 0,
  aid_minor         BIGINT       NOT NULL DEFAULT 0,
  sibling_minor     BIGINT       NOT NULL DEFAULT 0,
  discount_minor    BIGINT       NOT NULL DEFAULT 0,
  total_minor       BIGINT       NOT NULL DEFAULT 0,
  amount_paid_minor BIGINT       NOT NULL DEFAULT 0,
  -- Dunning, not disconnection: reminders at day 0, 3 and 7, then a grace window,
  -- then a conversation. There is deliberately NO column here that suspends
  -- anything — an access hold must never touch a learner's academic record, a
  -- safeguarding file, or a minor's report, and the way to keep a refusal is to
  -- leave the mechanism unbuilt.
  grace_until       DATE         NULL,
  dunning_stage     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  dunning_last_at   DATETIME(3)  NULL,
  term_id           BIGINT UNSIGNED NULL,
  term_label        VARCHAR(190) NULL,
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
  CONSTRAINT chk_inv_status CHECK (status IN
    ('draft','issued','part_paid','paid','void','written_off')),
  CONSTRAINT chk_inv_void_reason CHECK (voided_at IS NULL OR void_reason IS NOT NULL),
  KEY ix_invoices_member (tenant_id, membership_id, status),
  KEY ix_invoices_due (tenant_id, status, due_at),
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
  -- A WAIVER reduces THIS LINE and is never a cash entry. Kept beside the amount
  -- rather than netted into it, so the invoice can show both what was charged and
  -- what was waived — which is what a family needs to understand the bill and
  -- what an auditor needs to see that no money moved.
  waiver_minor      BIGINT       NOT NULL DEFAULT 0,
  waiver_reason     VARCHAR(255) NULL,
  price_item_id     BIGINT UNSIGNED NULL,     -- provenance: which price applied
  metadata          JSON         NULL,
  KEY ix_lines_invoice (invoice_id),
  -- A waiver cannot exceed the line it reduces, and it states its reason: it is
  -- the academy choosing not to bill, which is a decision and not a rounding.
  CONSTRAINT chk_line_waiver CHECK (waiver_minor >= 0 AND waiver_minor <= line_total_minor
        AND (waiver_minor = 0 OR waiver_reason IS NOT NULL)),
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
  -- 24, not 16: the CHECK below permits 'awaiting_transfer' (17 chars) and
  -- 'partially_refunded' (18), so a 16-wide column contradicts its own
  -- constraint. SQLite ignores widths, so this only fails on MySQL — and it
  -- would have failed on the dominant status in the real data.
  status            VARCHAR(24)  NOT NULL DEFAULT 'pending',
     -- pending|awaiting_transfer|succeeded|failed|refunded|partially_refunded|disputed
  currency          CHAR(3)      NOT NULL,
  amount_minor      BIGINT       NOT NULL,
  fee_minor         BIGINT       NOT NULL DEFAULT 0,
  base_currency     CHAR(3)      NOT NULL,
  base_amount_minor BIGINT       NULL,
  fx_rate           DECIMAL(18,8) NULL,
  fx_source         VARCHAR(32)  NULL,
  received_at       DATETIME(3)  NULL,
  reconciled_at     DATETIME(3)  NULL,
  -- The provider's code, kept verbatim, plus our plain-language rendering.
  -- v1 stored neither, so "your payment did not go through" was the whole of
  -- what a learner could be told — and they would simply try the same card.
  failure_code      VARCHAR(64)  NULL,
  failure_reason    VARCHAR(255) NULL,
  metadata          JSON         NULL,
  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  row_version       INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_payments_public (public_id),
  -- A provider reference is unique per provider: the guard against a webhook
  -- replay creating a second payment for one charge.
  UNIQUE KEY uq_payments_provider (tenant_id, provider, provider_ref),
  CONSTRAINT chk_pay_status CHECK (status IN
    ('pending','awaiting_transfer','succeeded','failed','refunded','partially_refunded','disputed')),
  CONSTRAINT chk_pay_failure_reason CHECK (status <> 'failed' OR failure_reason IS NOT NULL),
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
  -- §15: "Rejected — Charge Maintained" is a decision the student must be able
  -- to read. A waiver or rejection with no note is a charge nobody can explain.
  CONSTRAINT chk_charge_resolution_note CHECK (
    status NOT IN ('waived','rejected') OR resolution_note IS NOT NULL),
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
  tenant_id       BIGINT UNSIGNED NULL,      -- NULL = platform-scoped request
  -- 0 = platform, otherwise the tenant id. This one is not a tidiness point:
  -- with a nullable tenant_id the unique key below does not constrain
  -- platform-scoped requests at all, so a retried payment webhook inserts a
  -- second row and is processed twice. Idempotency that only works for some
  -- callers is not idempotency.
  tenant_scope    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  idem_key        VARCHAR(190) NOT NULL,
  endpoint        VARCHAR(190) NOT NULL,
  request_hash    CHAR(64)     NOT NULL,   -- same key + different body = conflict
  response_status SMALLINT UNSIGNED NULL,
  response_body   JSON         NULL,
  locked_at       DATETIME(3)  NULL,
  completed_at    DATETIME(3)  NULL,
  expires_at      DATETIME(3)  NOT NULL,
  created_at      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_idem (tenant_scope, idem_key, endpoint),
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
  -- The button's words. "Open" tells the reader nothing; "Pay ₦120,000" does.
  action_label  VARCHAR(80)  NULL,
  severity      VARCHAR(16)  NOT NULL DEFAULT 'info',
  -- Suppresses a repeat of the same message. Without it every nightly sweep
  -- re-notifies, the inbox fills with duplicates, and the reader stops looking
  -- — which costs them the ONE message that mattered.
  dedupe_key    VARCHAR(190) NULL,
  -- Groups a burst into one line ("3 new results"), so a busy day does not read
  -- as an incident.
  group_key     VARCHAR(120) NULL,
  -- After this the message is stale and must not be shown. A reminder about a
  -- class that already happened actively misleads.
  expires_at    DATETIME(3)  NULL,
  read_at       DATETIME(3)  NULL,
  -- Read is not understood. Acknowledged is an explicit "I have seen this",
  -- which is what a safeguarding or policy notice actually needs.
  acknowledged_at DATETIME(3) NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_notif_dedupe (membership_id, dedupe_key),
  KEY ix_notifications_inbox (membership_id, read_at, id),
  KEY ix_notifications_live (membership_id, expires_at, read_at),
  CONSTRAINT chk_notif_severity CHECK (severity IN ('info','success','attention','warning','critical')),
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
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NULL,        -- NULL = platform default (carries the FK)
  -- Uniqueness companion, same reason as deleted_seq: a unique key ending in a
  -- NULLABLE tenant_id would treat every platform-default row as distinct from
  -- every other, so the glossary could hold five conflicting definitions of one
  -- status. 0 = platform default, otherwise the tenant id. Kept in step with
  -- tenant_id by the application; the pair is written together or not at all.
  tenant_scope  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  entity_type   VARCHAR(48)  NOT NULL,       -- application|enrolment|invoice|module_progress|…
  status        VARCHAR(48)  NOT NULL,
  audience      VARCHAR(16)  NOT NULL DEFAULT 'learner',  -- learner|guardian|staff|admin
  locale        VARCHAR(12)  NOT NULL DEFAULT 'en',
  -- What it is called. Short enough for a chip.
  label         VARCHAR(80)  NOT NULL,
  -- What it MEANS, in a sentence, in the second person. This is the field that
  -- stops a learner guessing.
  meaning       VARCHAR(400) NOT NULL,
  -- What the reader should do about it, or NULL when the honest answer is
  -- "nothing, we will contact you" — which is itself worth saying.
  next_step     VARCHAR(400) NULL,
  -- Tone drives colour and icon. Named rather than left to each screen, because
  -- the same status rendered green here and red there is worse than no colour.
  tone          VARCHAR(16)  NOT NULL DEFAULT 'neutral',
     -- neutral|positive|progress|attention|warning|critical
  -- Whether this state is one the reader can act their way out of. A learner
  -- shown an unactionable state alongside actionable ones will try to act.
  is_actionable TINYINT(1)   NOT NULL DEFAULT 0,
  is_terminal   TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,   -- pipeline/progress order
  updated_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_statusdef (tenant_scope, entity_type, status, audience, locale),
  KEY ix_statusdef_lookup (entity_type, audience, locale),
  CONSTRAINT chk_statusdef_tone CHECK (tone IN
    ('neutral','positive','progress','attention','warning','critical')),
  CONSTRAINT chk_statusdef_audience CHECK (audience IN ('learner','guardian','staff','admin')),
  CONSTRAINT fk_statusdef_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  membership_id BIGINT UNSIGNED NOT NULL,
  kind          VARCHAR(64)  NOT NULL,       -- pay_invoice|sign_consent|submit_work|…
  -- Written for the reader, not the developer: "Pay your October tuition",
  -- never "invoice.outstanding".
  title         VARCHAR(200) NOT NULL,
  -- Why it matters to THEM. An instruction without a consequence gets deferred.
  detail        VARCHAR(500) NULL,
  action_url    VARCHAR(500) NULL,
  action_label  VARCHAR(80)  NULL,           -- the button's words
  -- What it is about, so the item can be cleared by the code that resolves it.
  source_type   VARCHAR(48)  NULL,
  source_id     BIGINT UNSIGNED NULL,
  priority      SMALLINT UNSIGNED NOT NULL DEFAULT 50,   -- lower sorts first
  -- Blocking items stop something the learner is trying to do. They are
  -- surfaced differently, and MUST be rare — a list where everything blocks is
  -- a list with no priority at all.
  is_blocking   TINYINT(1)   NOT NULL DEFAULT 0,
  due_at        DATETIME(3)  NULL,
  -- Set when the obligation is met. Kept rather than deleted, so "you did this"
  -- is answerable and a resolved item never silently reappears.
  completed_at  DATETIME(3)  NULL,
  -- A reader may put an optional item aside. Distinct from completed: the thing
  -- was not done, and an admin looking at why still needs to see it.
  dismissed_at  DATETIME(3)  NULL,
  -- 0 while the item is live, the row id once completed or dismissed — so the
  -- same obligation can recur next term without the old row blocking it.
  resolved_seq  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  dedupe_key    VARCHAR(190) NOT NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_action_public (public_id),
  UNIQUE KEY uq_action_dedupe (tenant_id, membership_id, dedupe_key, resolved_seq),
  KEY ix_action_open (membership_id, completed_at, dismissed_at, priority, due_at),
  KEY ix_action_due (tenant_id, due_at, completed_at),
  CONSTRAINT fk_action_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_action_member FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- How this person needs to be spoken to. v1 scattered these across ad-hoc
-- columns and localStorage, which meant a preference set on a phone did not
-- apply to the email the system sent an hour later — so a learner who asked for
-- large text and Yoruba got neither anywhere it mattered.
--
-- Keyed to the USER, not the membership: a person who needs a dyslexia-friendly
-- face needs it at every academy they belong to.
CREATE TABLE user_preferences (
  user_id           BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  locale            VARCHAR(12)  NULL,       -- overrides tenant default
  timezone          VARCHAR(64)  NULL,
  -- What money should be SHOWN in. Accounting stays in the tenant's base
  -- currency; a guardian in Lagos should still read naira. v1 showed some
  -- screens in USD and some in NGN with no rule, which reads as a pricing error.
  display_currency  CHAR(3)      NULL,
  date_format       VARCHAR(24)  NULL,       -- a bare 03/04 means two dates
  -- Channels. An empty set is a valid answer and must be honoured; a system
  -- that keeps emailing someone who opted out is not a preference system.
  channel_email     TINYINT(1)   NOT NULL DEFAULT 1,
  channel_sms       TINYINT(1)   NOT NULL DEFAULT 0,
  channel_whatsapp  TINYINT(1)   NOT NULL DEFAULT 0,
  channel_push      TINYINT(1)   NOT NULL DEFAULT 1,
  -- Batch non-urgent notifications instead of sending each one. Urgency is a
  -- property of the notification, so a digest never delays a critical message.
  digest_frequency  VARCHAR(16)  NOT NULL DEFAULT 'immediate',  -- immediate|daily|weekly
  quiet_hours_start TIME         NULL,
  quiet_hours_end   TIME         NULL,
  -- Accessibility. These are stored server-side precisely so they survive a new
  -- device and reach server-rendered pages and emails, not just the SPA.
  reduce_motion     TINYINT(1)   NOT NULL DEFAULT 0,
  high_contrast     TINYINT(1)   NOT NULL DEFAULT 0,
  dyslexia_font     TINYINT(1)   NOT NULL DEFAULT 0,
  text_scale_pct    SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  low_bandwidth     TINYINT(1)   NOT NULL DEFAULT 0,
  captions_default  TINYINT(1)   NOT NULL DEFAULT 0,
  -- Plain-language mode: shorter sentences and no jargon. Useful for younger
  -- learners and for guardians reading in a second language.
  plain_language    TINYINT(1)   NOT NULL DEFAULT 0,
  onboarding_done_at DATETIME(3) NULL,
  updated_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  CONSTRAINT chk_pref_digest CHECK (digest_frequency IN ('immediate','daily','weekly')),
  -- 200% zoom is the WCAG 1.4.4 requirement; the floor stops a mis-set value
  -- from rendering the interface unreadable and unrecoverable.
  CONSTRAINT chk_pref_scale  CHECK (text_scale_pct BETWEEN 80 AND 250),
  CONSTRAINT fk_pref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id         CHAR(26)     NOT NULL,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  -- The academy year this term sits in ("2026/2027"), and the term's own code.
  -- MAM runs TONI / MEDI / SUMMER / DOMI, renameable per tenant.
  year_label        VARCHAR(32)  NOT NULL,
  code              VARCHAR(32)  NOT NULL,
  term_no           TINYINT UNSIGNED NOT NULL DEFAULT 1,
  -- What a family sees on a receipt. Snapshotted onto reports and invoices,
  -- because a tenant may rename a term and a published document must keep the
  -- name it was published under.
  label             VARCHAR(190) NOT NULL,
  starts_on         DATE         NOT NULL,
  ends_on           DATE         NOT NULL,
  add_drop_deadline DATE         NULL,
  withdrawal_deadline DATE       NULL,
  census_date       DATE         NULL,
  teaching_weeks    TINYINT UNSIGNED NOT NULL DEFAULT 12,
  -- IANA zone, because a DATE deadline is a wall-clock fact about a place.
  timezone          VARCHAR(64)  NOT NULL DEFAULT 'Africa/Lagos',
  status            VARCHAR(16)  NOT NULL DEFAULT 'planned',   -- planned|active|closed
  notes             VARCHAR(1000) NULL,
  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at        DATETIME(3)  NULL,
  deleted_seq       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_by        BIGINT UNSIGNED NULL,
  row_version       INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_terms_public (public_id),
  UNIQUE KEY uq_terms_code (tenant_id, year_label, code, deleted_seq),
  KEY ix_terms_window (tenant_id, starts_on, ends_on),
  KEY ix_terms_status (tenant_id, status),
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The published week-by-week refund table. Rows are the truth once seeded; a
-- constant only ever SEEDS them.
CREATE TABLE term_refund_schedule (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  term_id       BIGINT UNSIGNED NOT NULL,
  -- 0 means "on or before the add/drop date".
  week_no       TINYINT UNSIGNED NOT NULL,
  refund_pct    DECIMAL(5,2) NOT NULL,
  -- What the transcript says: an add/drop leaves no mark, a withdrawal leaves W.
  transcript_mark VARCHAR(8)  NULL,
  note          VARCHAR(255) NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_refund_week (tenant_id, term_id, week_no),
  CONSTRAINT chk_refund_pct CHECK (refund_pct >= 0 AND refund_pct <= 100),
  CONSTRAINT fk_refundsched_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_refundsched_term FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enrolment as of the census date. A SNAPSHOT: one row per term, append-only.
CREATE TABLE term_census (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  term_id           BIGINT UNSIGNED NOT NULL,
  taken_on          DATE         NOT NULL,
  enrolled_count    INT UNSIGNED NOT NULL,
  active_count      INT UNSIGNED NOT NULL,
  -- NULLABLE, unlike the other two: a census taken by a system that did not
  -- count withdrawals has no figure, and 0 would state that nobody withdrew.
  withdrawn_count   INT UNSIGNED NULL,
  breakdown         JSON         NULL,      -- by programme/level/centre
  taken_by          BIGINT UNSIGNED NULL,
  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  -- One census per term. Retaking it would rewrite an official figure.
  UNIQUE KEY uq_census_term (tenant_id, term_id),
  CONSTRAINT fk_census_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_census_term FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Days the academy is shut. A closure is in NEITHER half of an attendance rate:
-- a class that did not happen must not count against the learner who would have
-- attended it.
CREATE TABLE term_closures (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  term_id       BIGINT UNSIGNED NOT NULL,
  centre_id     BIGINT UNSIGNED NULL,        -- NULL = every centre
  kind          VARCHAR(24)  NOT NULL,       -- holiday|public|closure|exam_week
  label         VARCHAR(190) NOT NULL,
  from_on       DATE         NOT NULL,
  to_on         DATE         NOT NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_closure_span (tenant_id, term_id, from_on, to_on),
  CONSTRAINT chk_closure_span CHECK (to_on >= from_on),
  CONSTRAINT fk_closure_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_closure_term FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE CASCADE,
  CONSTRAINT fk_closure_centre FOREIGN KEY (centre_id) REFERENCES centres(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id         CHAR(26)     NOT NULL,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  -- The LEARNER, as a membership: a person's relationship to this academy is
  -- what a report is about, not their platform account.
  membership_id     BIGINT UNSIGNED NOT NULL,
  enrolment_id      BIGINT UNSIGNED NULL,
  cohort_id         BIGINT UNSIGNED NULL,
  course_id         BIGINT UNSIGNED NULL,
  level_id          BIGINT UNSIGNED NULL,
  term_id           BIGINT UNSIGNED NULL,
  -- Snapshotted: terms are renameable and a family has this on their receipt.
  term_label        VARCHAR(190) NOT NULL,
  status            VARCHAR(16)  NOT NULL DEFAULT 'draft',
     -- draft|moderating|ratified|withdrawn
  -- The BAND is the stored value. There is no raw_score column anywhere in this
  -- layer: a guardian-facing table with a column for the precise figure is an
  -- invitation to print it, and the mastery coverage floor exists precisely so a
  -- thin composite is never published as a comparable number.
  band_low          TINYINT UNSIGNED NULL,
  band_high         TINYINT UNSIGNED NULL,
  -- Coverage prints ON the document ("8 of 11 parts — 72% of the weight"), so it
  -- is stored beside the band and NOT NULL with no default. `NOT NULL DEFAULT 0`
  -- constrains nothing, because the default satisfies it — and a report that
  -- cannot state its coverage is not a report.
  coverage_evidenced  TINYINT UNSIGNED NOT NULL,
  coverage_total      TINYINT UNSIGNED NOT NULL,
  coverage_weight_pct TINYINT UNSIGNED NOT NULL,
  instructor_membership_id BIGINT UNSIGNED NULL,
  instructor_comment  TEXT     NULL,
  attendance_summary  VARCHAR(255) NULL,
  -- Ratification is a named person on a stated time. Both or neither.
  ratified_by       BIGINT UNSIGNED NULL,
  ratified_at       DATETIME(3)  NULL,
  ratified_note     VARCHAR(500) NULL,
  -- Withdrawing a published mark is a real event and must not be spelled as a
  -- delete. It also FREES the slot, so the corrected report an upheld appeal
  -- produces has somewhere to go.
  withdrawn_at      DATETIME(3)  NULL,
  withdrawn_reason  VARCHAR(500) NULL,
  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at        DATETIME(3)  NULL,
  deleted_seq       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_by        BIGINT UNSIGNED NULL,
  row_version       INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_treport_public (public_id),
  KEY ix_treport_member (tenant_id, membership_id, status),
  KEY ix_treport_cohort (tenant_id, cohort_id, status),
  -- Lookup only. The one-LIVE-report-per-slot rule is the application's, on both
  -- engines: with no status term a withdrawn report holds its slot forever, and
  -- keying on `term_label` keys the rule on a renameable string. See
  -- mam_report_slot_taken().
  KEY ix_treport_slot (tenant_id, membership_id, term_id, course_id, level_id, status),
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE termly_report_criteria (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  report_id         BIGINT UNSIGNED NOT NULL,
  -- A real FOREIGN KEY, which is the thing v1 could not do. A renamed competency
  -- stays the same competency.
  competency_id     BIGINT UNSIGNED NOT NULL,
  -- The label AS PRINTED, snapshotted, so a later rename does not silently
  -- restate a document a family already holds.
  label             VARCHAR(190) NOT NULL,
  weight_pct        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  level             VARCHAR(16)  NOT NULL,     -- strong|secure|working|insufficient
  -- NULL for 'insufficient' and only for it. A zero there reports an unassessed
  -- beginner as failing, which is a different statement from "no evidence".
  pct               TINYINT UNSIGNED NULL,
  -- The printed word. FIVE grade words map onto FOUR levels, because the design's
  -- own mock labels both 68% and 46% "Pass" while colouring their bars
  -- differently — the level is the colour axis, the grade is the word.
  grade_label       VARCHAR(48)  NULL,
  first_assessed_week TINYINT UNSIGNED NULL,
  note              VARCHAR(500) NULL,
  sort_order        INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_tcrit_slot (report_id, competency_id),
  KEY ix_tcrit_report (report_id, sort_order),
  CONSTRAINT chk_tcrit_level CHECK (level IN ('strong','secure','working','insufficient')),
  CONSTRAINT chk_tcrit_pct CHECK (weight_pct <= 100 AND (pct IS NULL OR pct <= 100)),
  CONSTRAINT chk_tcrit_evid CHECK ((level = 'insufficient' AND pct IS NULL)
        OR (level <> 'insufficient' AND pct IS NOT NULL)),
  CONSTRAINT fk_tcrit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_tcrit_report FOREIGN KEY (report_id) REFERENCES termly_reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_tcrit_comp FOREIGN KEY (competency_id) REFERENCES competencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The moderation sample. Per-CRITERION, because "a short sample" is a count of
-- second-marked criteria and a per-report row could not express it.
CREATE TABLE report_moderations (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  report_id         BIGINT UNSIGNED NOT NULL,
  competency_id     BIGINT UNSIGNED NOT NULL,
  moderator_membership_id BIGINT UNSIGNED NULL,
  agreed            TINYINT(1)   NOT NULL DEFAULT 1,
  moderator_level   VARCHAR(16)  NULL,
  note              VARCHAR(500) NULL,
  moderated_at      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  -- Plain UNIQUE with a nullable column: SQL's NULLs-are-distinct rule is the
  -- correct semantics, so two unattributed second-markers are two rows. Under a
  -- COALESCE(...,0) they collapsed onto one key and UNDERCOUNTED the sample that
  -- ratification refuses on — making the refusal fire on a properly moderated
  -- report.
  UNIQUE KEY uq_rmod_slot (report_id, competency_id, moderator_membership_id),
  KEY ix_rmod_report (report_id),
  CONSTRAINT chk_rmod_agreed CHECK (agreed IN (0,1)),
  CONSTRAINT chk_rmod_level CHECK (moderator_level IS NULL
        OR moderator_level IN ('strong','secure','working','insufficient')),
  CONSTRAINT fk_rmod_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_rmod_report FOREIGN KEY (report_id) REFERENCES termly_reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_rmod_comp FOREIGN KEY (competency_id) REFERENCES competencies(id),
  CONSTRAINT fk_rmod_mod FOREIGN KEY (moderator_membership_id) REFERENCES memberships(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Its own table rather than a status on the report, because an appeal OUTLIVES
-- the report's status: a family appeals a ratified mark too, and the design gives
-- them three months.
CREATE TABLE mark_appeals (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id         CHAR(26)     NOT NULL,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  report_id         BIGINT UNSIGNED NULL,
  membership_id     BIGINT UNSIGNED NOT NULL,       -- the learner
  raised_by_membership_id BIGINT UNSIGNED NULL,
  raised_role       VARCHAR(16)  NOT NULL DEFAULT 'guardian',  -- guardian|learner|staff
  competency_id     BIGINT UNSIGNED NULL,
  reason            VARCHAR(2000) NOT NULL,
  status            VARCHAR(16)  NOT NULL DEFAULT 'open',      -- open|upheld|declined|withdrawn
  outcome_note      VARCHAR(2000) NULL,
  decided_by        BIGINT UNSIGNED NULL,
  -- No DB default, deliberately. `chk_appeal_order` compares these two, and a
  -- defaulted CURRENT_TIMESTAMP(3) carries milliseconds while a PHP caller
  -- stamps `closed_at` from gmdate('Y-m-d H:i:s') with none — so an appeal
  -- closed minutes after it was raised compared BELOW its own opened_at and
  -- the write failed outright. (Same family as the v1 CURRENT_TIMESTAMP vs.
  -- PHP-UTC bug in `mark_appeals`.) Both timestamps must come from one
  -- writer at one precision.
  opened_at         DATETIME(3)  NOT NULL,
  closed_at         DATETIME(3)  NULL,
  UNIQUE KEY uq_appeal_public (public_id),
  KEY ix_appeal_report (tenant_id, report_id, status),
  KEY ix_appeal_open (tenant_id, status, opened_at),
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- LAYER K · FEE RELIEF AND PAYMENT PLANS
-- =============================================================================
-- The AWARD is the academy's decision that a learner is entitled to something.
-- It is separate from its application to any one invoice, because an award spans a
-- term and an invoice is a month.

CREATE TABLE invoice_aid_awards (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id         CHAR(26)     NOT NULL,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  membership_id     BIGINT UNSIGNED NOT NULL,
  kind              VARCHAR(16)  NOT NULL,     -- bursary|scholarship|waiver|sibling
  -- Either a percentage or a fixed amount, never both and never neither.
  pct               DECIMAL(6,3) NULL,
  amount_minor      BIGINT       NULL,
  currency          CHAR(3)      NULL,
  term_id           BIGINT UNSIGNED NULL,
  term_label        VARCHAR(190) NULL,
  -- What the decision rested on. For a bursary this is the income BAND, never a
  -- written account of a family's circumstances.
  basis             VARCHAR(190) NULL,
  status            VARCHAR(16)  NOT NULL DEFAULT 'active',   -- active|spent|expired|revoked
  starts_on         DATE         NULL,
  expires_on        DATE         NULL,
  awarded_by        BIGINT UNSIGNED NULL,
  awarded_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  revoked_at        DATETIME(3)  NULL,
  revoked_reason    VARCHAR(500) NULL,
  note              VARCHAR(500) NULL,
  UNIQUE KEY uq_aid_public (public_id),
  KEY ix_aid_member (tenant_id, membership_id, status, kind),
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GIFT AID applied to one invoice — bursary and scholarship ONLY. A waiver never
-- appears here (it is on the line, and is not money); a sibling discount never
-- appears here (it is a discount on the post-aid balance, stored on the invoice).
-- Keeping the three apart is what makes "how much aid did the academy actually pay
-- out this term" a question with an answer.
CREATE TABLE invoice_credits (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  invoice_id        BIGINT UNSIGNED NOT NULL,
  award_id          BIGINT UNSIGNED NULL,
  kind              VARCHAR(16)  NOT NULL,     -- bursary|scholarship
  amount_minor      BIGINT       NOT NULL,
  currency          CHAR(3)      NOT NULL,
  base_amount_minor BIGINT       NULL,
  fx_rate           DECIMAL(18,8) NULL,
  note              VARCHAR(500) NULL,
  applied_by        BIGINT UNSIGNED NULL,
  applied_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  -- One application of an award per invoice: applying one award twice is the
  -- commonest way a double-submitted form gives away the aid pool.
  UNIQUE KEY uq_credit_award (invoice_id, award_id),
  KEY ix_credit_invoice (tenant_id, invoice_id),
  CONSTRAINT chk_credit_kind CHECK (kind IN ('bursary','scholarship')),
  CONSTRAINT chk_credit_amount CHECK (amount_minor > 0),
  CONSTRAINT fk_credit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_credit_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_credit_award FOREIGN KEY (award_id) REFERENCES invoice_aid_awards(id) ON DELETE SET NULL,
  CONSTRAINT fk_credit_actor FOREIGN KEY (applied_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_credit_currency FOREIGN KEY (currency) REFERENCES currencies(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Pay in 3 instalments instead." The plan does not change the TOTAL; it changes
-- when each part falls due. So the instalments must SUM to the balance exactly and
-- the rounding remainder lands on the FIRST one — front-loaded, so no family meets
-- a larger final payment than the plan showed them, and none is left owing a
-- rounding shortfall after paying every instalment.
CREATE TABLE invoice_payment_plans (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id         CHAR(26)     NOT NULL,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  invoice_id        BIGINT UNSIGNED NOT NULL,
  instalments       TINYINT UNSIGNED NOT NULL,
  status            VARCHAR(16)  NOT NULL DEFAULT 'active',   -- active|completed|cancelled
  -- A family REQUESTS; the office approves. A plan a family can grant itself is a
  -- payment term, not a plan.
  requested_by      BIGINT UNSIGNED NULL,
  approved_by       BIGINT UNSIGNED NULL,
  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  cancelled_at      DATETIME(3)  NULL,
  cancel_reason     VARCHAR(500) NULL,
  UNIQUE KEY uq_plan_public (public_id),
  KEY ix_plan_invoice (tenant_id, invoice_id, status),
  CONSTRAINT chk_plan_status CHECK (status IN ('active','completed','cancelled')),
  CONSTRAINT chk_plan_n CHECK (instalments >= 2 AND instalments <= 12),
  CONSTRAINT chk_plan_cancel CHECK (status <> 'cancelled'
        OR (cancelled_at IS NOT NULL AND cancel_reason IS NOT NULL)),
  CONSTRAINT fk_plan_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_plan_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_plan_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_plan_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoice_plan_instalments (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  plan_id           BIGINT UNSIGNED NOT NULL,
  seq               TINYINT UNSIGNED NOT NULL,
  due_on            DATE         NOT NULL,
  amount_minor      BIGINT       NOT NULL,
  status            VARCHAR(16)  NOT NULL DEFAULT 'pending',  -- pending|paid|waived
  paid_at           DATETIME(3)  NULL,
  payment_id        BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_inst_seq (plan_id, seq),
  KEY ix_inst_due (tenant_id, status, due_on),
  CONSTRAINT chk_inst_status CHECK (status IN ('pending','paid','waived')),
  CONSTRAINT chk_inst_amount CHECK (seq >= 1 AND amount_minor > 0),
  CONSTRAINT chk_inst_paid CHECK (status <> 'paid' OR paid_at IS NOT NULL),
  CONSTRAINT fk_inst_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_inst_plan FOREIGN KEY (plan_id) REFERENCES invoice_payment_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_inst_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id         CHAR(26)     NOT NULL,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  membership_id     BIGINT UNSIGNED NOT NULL,        -- the learner
  guardian_membership_id BIGINT UNSIGNED NULL,
  term_id           BIGINT UNSIGNED NULL,
  term_label        VARCHAR(190) NULL,
  income_band       VARCHAR(32)  NOT NULL,
  household_size    TINYINT UNSIGNED NULL,
  dependants_in_academy TINYINT UNSIGNED NOT NULL DEFAULT 1,
  status            VARCHAR(16)  NOT NULL DEFAULT 'submitted',
     -- submitted|assessing|awarded|declined|withdrawn
  -- May be BELOW the band's published figure — a committee weighing a finite pool
  -- — but never above it, because the table is published and a family that read
  -- it is entitled to the answer it gives.
  awarded_pct       DECIMAL(6,3) NULL,
  award_id          BIGINT UNSIGNED NULL,
  decided_by        BIGINT UNSIGNED NULL,
  decided_at        DATETIME(3)  NULL,
  outcome_note      VARCHAR(2000) NULL,
  note              VARCHAR(2000) NULL,
  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_bursary_public (public_id),
  KEY ix_bursary_member (tenant_id, membership_id, status),
  KEY ix_bursary_queue (tenant_id, status, created_at),
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id         CHAR(26)     NOT NULL,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  learner_membership_id BIGINT UNSIGNED NOT NULL,
  subject           VARCHAR(190) NOT NULL,
  -- Decides whether the channel preferences may suppress a notice, so it is on
  -- the thread and constrained rather than inferred from the words.
  kind              VARCHAR(16)  NOT NULL DEFAULT 'general',
     -- general|academic|money|safeguarding|timetable
  status            VARCHAR(16)  NOT NULL DEFAULT 'open',
  opened_by         BIGINT UNSIGNED NULL,
  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  last_message_at   DATETIME(3)  NULL,
  closed_at         DATETIME(3)  NULL,
  UNIQUE KEY uq_gthread_public (public_id),
  KEY ix_gthread_learner (tenant_id, learner_membership_id, status, last_message_at),
  CONSTRAINT chk_gthread_kind CHECK (kind IN
    ('general','academic','money','safeguarding','timetable')),
  CONSTRAINT chk_gthread_status CHECK (status IN ('open','closed')),
  CONSTRAINT chk_gthread_closed CHECK (status = 'open' OR closed_at IS NOT NULL),
  CONSTRAINT fk_gthread_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_gthread_learner FOREIGN KEY (learner_membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
  CONSTRAINT fk_gthread_opener FOREIGN KEY (opened_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE guardian_thread_participants (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  thread_id         BIGINT UNSIGNED NOT NULL,
  membership_id     BIGINT UNSIGNED NOT NULL,
  -- No 'learner'. This is a refusal, not an omission — see the layer note.
  role              VARCHAR(16)  NOT NULL,     -- guardian|instructor|office|admin
  last_read_at      DATETIME(3)  NULL,
  added_at          DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_gpart_pair (thread_id, membership_id),
  KEY ix_gpart_member (tenant_id, membership_id),
  CONSTRAINT chk_gpart_role CHECK (role IN ('guardian','instructor','office','admin')),
  CONSTRAINT fk_gpart_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_gpart_thread FOREIGN KEY (thread_id) REFERENCES guardian_threads(id) ON DELETE CASCADE,
  CONSTRAINT fk_gpart_member FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE guardian_thread_messages (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  thread_id         BIGINT UNSIGNED NOT NULL,
  author_membership_id BIGINT UNSIGNED NULL,
  -- Denormalised, because author_membership_id goes NULL when a staff membership
  -- is closed and a family's thread has to still make sense afterwards. "A message
  -- from nobody" is worse than a name.
  author_name       VARCHAR(190) NOT NULL,
  author_role       VARCHAR(16)  NOT NULL,
  body              TEXT         NOT NULL,
  created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_gmsg_thread (thread_id, created_at),
  CONSTRAINT chk_gmsg_role CHECK (author_role IN ('guardian','instructor','office','admin')),
  CONSTRAINT fk_gmsg_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_gmsg_thread FOREIGN KEY (thread_id) REFERENCES guardian_threads(id) ON DELETE CASCADE,
  CONSTRAINT fk_gmsg_author FOREIGN KEY (author_membership_id) REFERENCES memberships(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per guardian membership. ⚠️ There is NO per-topic column, deliberately:
-- anything about safeguarding or money reaches a guardian on every channel the
-- academy can use regardless of these toggles, so "mute safeguarding" is not a
-- state this table can hold. A family that has muted notifications has muted
-- reminders about practice, not a concern about their child or a fee falling due.
CREATE TABLE guardian_channel_prefs (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  membership_id     BIGINT UNSIGNED NOT NULL,
  whatsapp          TINYINT(1)   NOT NULL DEFAULT 1,
  email             TINYINT(1)   NOT NULL DEFAULT 1,
  -- Opt-IN: it costs the academy per message and reaches the same phone WhatsApp
  -- already does.
  sms               TINYINT(1)   NOT NULL DEFAULT 0,
  updated_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_gpref_member (tenant_id, membership_id),
  CONSTRAINT chk_gpref_flags CHECK (whatsapp IN (0,1) AND email IN (0,1) AND sms IN (0,1)),
  CONSTRAINT fk_gpref_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_gpref_member FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id         CHAR(26)     NOT NULL,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  membership_id     BIGINT UNSIGNED NOT NULL,
  term_id           BIGINT UNSIGNED NULL,
  -- The academy year the allowance is counted over. Stored, because "five a year"
  -- has to mean the same five in December as in March.
  year_label        VARCHAR(32)  NOT NULL,
  reason            VARCHAR(24)  NOT NULL,
     -- guardian_notice|teacher_cancellation|academy_closure|goodwill
  source_session_id BIGINT UNSIGNED NULL,
  notice_hours      DECIMAL(8,2) NULL,
  counts_against_allowance TINYINT(1) NOT NULL DEFAULT 1,
  status            VARCHAR(16)  NOT NULL DEFAULT 'available',
     -- available|spent|expired|revoked
  expires_on        DATE         NOT NULL,
  spent_session_id  BIGINT UNSIGNED NULL,
  spent_at          DATETIME(3)  NULL,
  granted_by        BIGINT UNSIGNED NULL,
  granted_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  revoked_reason    VARCHAR(500) NULL,
  note              VARCHAR(500) NULL,
  UNIQUE KEY uq_makeup_public (public_id),
  KEY ix_makeup_member (tenant_id, membership_id, status, expires_on),
  KEY ix_makeup_year (tenant_id, membership_id, year_label, counts_against_allowance),
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  code          VARCHAR(48)  NOT NULL,     -- recital|exam|coursework|teachback|practical
  name          VARCHAR(120) NOT NULL,
  description   VARCHAR(500) NULL,
  -- Share of the enrolment result. A type that does NOT count to mastery
  -- (a formative check) carries 0 and `counts_to_mastery = 0` — the two say
  -- different things and both are read.
  weight_pct    DECIMAL(5,2) NOT NULL DEFAULT 0,
  counts_to_mastery TINYINT(1) NOT NULL DEFAULT 1,
  sort_order    INT          NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_asskind (tenant_id, code),
  CONSTRAINT chk_asskind_weight CHECK (weight_pct >= 0 AND weight_pct <= 100),
  CONSTRAINT chk_asskind_mastery CHECK (counts_to_mastery IN (0,1)),
  CONSTRAINT chk_asskind_active CHECK (is_active IN (0,1)),
  CONSTRAINT fk_asskind_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A piece of work handed in. `attempt_no` is part of the natural key because a
-- resubmission is a NEW submission, not an edit of the old one: the mark that
-- was awarded against the first attempt has to stay readable.
CREATE TABLE submissions (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  enrolment_id  BIGINT UNSIGNED NOT NULL,
  assignment_id BIGINT UNSIGNED NULL,
  module_id     BIGINT UNSIGNED NULL,
  session_id    BIGINT UNSIGNED NULL,
  attempt_no    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  kind          VARCHAR(24)  NOT NULL DEFAULT 'link',   -- link|text|recording|take|document
  body          TEXT         NULL,
  -- Files live in `attachments` (entity_type='submission'), so a recording, a
  -- photographed score and a PDF are one mechanism instead of v1's
  -- `submission_files` beside a bare `artifact_url`.
  status        VARCHAR(16)  NOT NULL DEFAULT 'submitted',
     -- draft|submitted|returned|marked|withdrawn
  submitted_at  DATETIME(3)  NULL,
  is_late       TINYINT(1)   NOT NULL DEFAULT 0,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at    DATETIME(3)  NULL,
  deleted_seq   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  row_version   INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_subm_public (public_id),
  UNIQUE KEY uq_subm_attempt (tenant_id, assignment_id, enrolment_id, attempt_no, deleted_seq),
  KEY ix_subm_enrol (tenant_id, enrolment_id, status),
  KEY ix_subm_module (tenant_id, module_id, status),
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- WHO is to mark a submission, which is a different fact from what they marked.
-- The moderation share the report layer requires (MAM_REPORT_MODERATION_SHARE)
-- is a count of allocations with role='moderator' — derivable only if the
-- allocation is recorded before the mark exists.
CREATE TABLE submission_markers (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  submission_id BIGINT UNSIGNED NOT NULL,
  marker_membership_id BIGINT UNSIGNED NOT NULL,
  role          VARCHAR(16)  NOT NULL DEFAULT 'primary',   -- primary|moderator|second
  allocated_at  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  allocated_by  BIGINT UNSIGNED NULL,
  completed_at  DATETIME(3)  NULL,
  UNIQUE KEY uq_submarker (tenant_id, submission_id, marker_membership_id),
  KEY ix_submarker_who (tenant_id, marker_membership_id, completed_at),
  CONSTRAINT chk_submarker_role CHECK (role IN ('primary','moderator','second')),
  CONSTRAINT fk_submarker_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_submarker_subm   FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_submarker_who    FOREIGN KEY (marker_membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
  CONSTRAINT fk_submarker_by     FOREIGN KEY (allocated_by)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The marking EVENT. v1's `submission_reviews` could only exist against a
-- submission; a recital marked in the room and an exam sat on paper had nowhere
-- to go, so they were entered as a submission that nobody submitted.
CREATE TABLE assessments (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  enrolment_id  BIGINT UNSIGNED NOT NULL,
  assessment_kind_id BIGINT UNSIGNED NULL,
  submission_id BIGINT UNSIGNED NULL,
  session_id    BIGINT UNSIGNED NULL,
  module_id     BIGINT UNSIGNED NULL,
  title         VARCHAR(190) NULL,
  rubric_id     BIGINT UNSIGNED NULL,
  -- The rubric VERSION is frozen here, because rubrics/uq_rubrics_version keeps
  -- editing a published rubric to a new version and a mark must state which one
  -- it was awarded against.
  rubric_version INT UNSIGNED NULL,
  points_awarded DECIMAL(8,2) NULL,
  points_max     DECIMAL(8,2) NULL,
  -- Derived and STORED at mark time, like every other percentage in this schema:
  -- the marker's rubric can be re-versioned afterwards and the awarded figure
  -- must not move with it.
  pct           DECIMAL(5,2) NULL,
  feedback      TEXT         NULL,
  status        VARCHAR(16)  NOT NULL DEFAULT 'draft',
     -- draft|marked|moderated|released|withdrawn
  marker_membership_id BIGINT UNSIGNED NULL,
  marked_at     DATETIME(3)  NULL,
  moderated_by  BIGINT UNSIGNED NULL,
  moderated_at  DATETIME(3)  NULL,
  -- RELEASED is the only status a learner or guardian may be shown. A mark
  -- exists for a while before anybody is entitled to read it.
  released_at   DATETIME(3)  NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at    DATETIME(3)  NULL,
  deleted_seq   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_by    BIGINT UNSIGNED NULL,
  row_version   INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_assess_public (public_id),
  KEY ix_assess_enrol (tenant_id, enrolment_id, status),
  KEY ix_assess_subm (tenant_id, submission_id),
  KEY ix_assess_marker (tenant_id, marker_membership_id, marked_at),
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per criterion marked. This is the table `scores_json` should always
-- have been: `competency_id` is carried BESIDE the criterion (denormalised on
-- purpose) so a mastery query joins nothing and a re-versioned rubric cannot
-- change which competency an awarded mark counted toward.
CREATE TABLE assessment_marks (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  assessment_id BIGINT UNSIGNED NOT NULL,
  criterion_id  BIGINT UNSIGNED NULL,
  competency_id BIGINT UNSIGNED NULL,
  label         VARCHAR(190) NOT NULL,
  points_awarded DECIMAL(8,2) NULL,
  points_max     DECIMAL(8,2) NOT NULL,
  weight        DECIMAL(6,3) NOT NULL DEFAULT 1.000,
  -- An unevidenced criterion is a ROW with a NULL award, never a zero and never
  -- an absent row: scoring an unassessed beginner 0 reports them as failing, and
  -- omitting the row hides that the criterion was in scope at all.
  is_insufficient TINYINT(1) NOT NULL DEFAULT 0,
  comment       VARCHAR(1000) NULL,
  sort_order    INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_assmark (tenant_id, assessment_id, label),
  KEY ix_assmark_comp (tenant_id, competency_id),
  CONSTRAINT chk_assmark_points CHECK (points_max > 0
        AND (points_awarded IS NULL OR (points_awarded >= 0 AND points_awarded <= points_max))),
  CONSTRAINT chk_assmark_insuff CHECK (is_insufficient IN (0,1)
        AND (is_insufficient = 0 OR points_awarded IS NULL)),
  CONSTRAINT fk_assmark_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_assmark_assess FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
  CONSTRAINT fk_assmark_crit   FOREIGN KEY (criterion_id)  REFERENCES rubric_criteria(id) ON DELETE SET NULL,
  CONSTRAINT fk_assmark_comp   FOREIGN KEY (competency_id) REFERENCES competencies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The learner explains the material back. Five named dimensions rather than
-- v1's single `understanding` plus five columns bolted on afterwards.
CREATE TABLE teachbacks (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  enrolment_id  BIGINT UNSIGNED NOT NULL,
  module_id     BIGINT UNSIGNED NULL,
  session_id    BIGINT UNSIGNED NULL,
  kind          VARCHAR(16)  NOT NULL DEFAULT 'written',   -- written|audio|video|live
  body          TEXT         NULL,
  status        VARCHAR(16)  NOT NULL DEFAULT 'submitted',
     -- submitted|reviewed|returned|withdrawn
  reviewer_membership_id BIGINT UNSIGNED NULL,
  decision      VARCHAR(16)  NULL,          -- secure|developing|revisit
  accuracy      TINYINT UNSIGNED NULL,
  vocabulary    TINYINT UNSIGNED NULL,
  explanation   TINYINT UNSIGNED NULL,
  application   TINYINT UNSIGNED NULL,
  confidence    TINYINT UNSIGNED NULL,
  feedback      TEXT         NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  reviewed_at   DATETIME(3)  NULL,
  deleted_at    DATETIME(3)  NULL,
  deleted_seq   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_teachback_public (public_id),
  -- One LIVE teach-back per module per enrolment; a withdrawn one frees the slot
  -- (deleted_seq), which is how a re-record replaces rather than duplicates.
  UNIQUE KEY uq_teachback_module (tenant_id, enrolment_id, module_id, deleted_seq),
  KEY ix_teachback_review (tenant_id, status, created_at),
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A named set of assignments (a unit of work, a practice block).
CREATE TABLE assignment_groups (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  cohort_id     BIGINT UNSIGNED NULL,
  course_id     BIGINT UNSIGNED NULL,
  title         VARCHAR(190) NOT NULL,
  description   TEXT         NULL,
  sort_order    INT          NOT NULL DEFAULT 0,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by    BIGINT UNSIGNED NULL,
  deleted_at    DATETIME(3)  NULL,
  deleted_seq   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_asgroup_public (public_id),
  KEY ix_asgroup_cohort (tenant_id, cohort_id, sort_order),
  CONSTRAINT fk_asgroup_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_asgroup_cohort FOREIGN KEY (cohort_id) REFERENCES cohorts(id) ON DELETE CASCADE,
  CONSTRAINT fk_asgroup_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
  CONSTRAINT fk_asgroup_by     FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Set work. v1's `assignments` was per-STUDENT with an optional class, so
-- setting one piece of work for a cohort of nine wrote nine rows that then
-- drifted apart when the instructor edited the instructions. Here the
-- assignment is the work and the target is either a cohort or one enrolment;
-- what each learner did about it is a `submissions` row.
CREATE TABLE assignments (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  group_id      BIGINT UNSIGNED NULL,
  cohort_id     BIGINT UNSIGNED NULL,
  enrolment_id  BIGINT UNSIGNED NULL,
  module_id     BIGINT UNSIGNED NULL,
  assessment_kind_id BIGINT UNSIGNED NULL,
  rubric_id     BIGINT UNSIGNED NULL,
  title         VARCHAR(190) NOT NULL,
  instructions  TEXT         NULL,
  reference_url VARCHAR(1000) NULL,
  reference_kind VARCHAR(24)  NULL,
  points_max    DECIMAL(8,2) NULL,
  due_at        DATETIME(3)  NULL,
  due_timezone  VARCHAR(64)  NULL,
  status        VARCHAR(16)  NOT NULL DEFAULT 'open',
     -- draft|open|closed|archived
  allows_resubmission TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  created_by    BIGINT UNSIGNED NULL,
  deleted_at    DATETIME(3)  NULL,
  deleted_seq   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  row_version   INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_assign_public (public_id),
  KEY ix_assign_cohort (tenant_id, cohort_id, status, due_at),
  KEY ix_assign_enrol (tenant_id, enrolment_id, status, due_at),
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The finalised outcome of an enrolment. v1's `results` carried a bare
-- `total_score REAL` with nothing saying what scale it was on or who signed it.
CREATE TABLE enrolment_results (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  enrolment_id  BIGINT UNSIGNED NOT NULL,
  term_id       BIGINT UNSIGNED NULL,
  -- INTERNAL. This is the figure the mastery coverage floor governs the
  -- PUBLICATION of, not the storage of: the academy needs it for progression and
  -- an audit needs it reproducible. What a learner or guardian is shown is the
  -- band on `termly_reports`. See mam_mastery_publishable().
  total_pct     DECIMAL(5,2) NULL,
  grade         VARCHAR(16)  NULL,
  outcome       VARCHAR(24)  NULL,      -- passed|referred|failed|withdrawn|incomplete
  -- Coverage travels WITH the figure. A percentage computed over three of eleven
  -- competencies is not the same number as one computed over all eleven, and
  -- without this nothing downstream can tell them apart.
  coverage_evidenced TINYINT UNSIGNED NULL,
  coverage_total     TINYINT UNSIGNED NULL,
  status        VARCHAR(16)  NOT NULL DEFAULT 'provisional',
     -- provisional|finalised|withdrawn
  finalised_by  BIGINT UNSIGNED NULL,
  finalised_at  DATETIME(3)  NULL,
  note          VARCHAR(500) NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at    DATETIME(3)  NULL,
  deleted_seq   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  row_version   INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_enresult_public (public_id),
  -- One LIVE result per enrolment per term. v1's UNIQUE(enrollment_id) could not
  -- express a year-long enrolment reported twice.
  UNIQUE KEY uq_enresult_slot (tenant_id, enrolment_id, term_id, deleted_seq),
  KEY ix_enresult_status (tenant_id, status, finalised_at),
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- An awarded certificate, and the code a third party verifies it by.
CREATE TABLE certificates (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  membership_id BIGINT UNSIGNED NOT NULL,
  enrolment_id  BIGINT UNSIGNED NULL,
  programme_id  BIGINT UNSIGNED NULL,
  course_id     BIGINT UNSIGNED NULL,
  level_id      BIGINT UNSIGNED NULL,
  term_id       BIGINT UNSIGNED NULL,
  kind          VARCHAR(24)  NOT NULL,     -- completion|level|participation|merit
  title         VARCHAR(190) NULL,
  -- The reference a holder quotes and the code a verifier types are DIFFERENT
  -- strings on purpose: the reference is printed on the certificate and readable
  -- over the phone, the verification code is not derivable from it.
  reference        VARCHAR(64) NOT NULL,
  verification_code CHAR(32)   NOT NULL,
  status        VARCHAR(16)  NOT NULL DEFAULT 'issued',   -- issued|revoked|reissued
  issued_by     BIGINT UNSIGNED NULL,
  issued_at     DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  revoked_at    DATETIME(3)  NULL,
  revoked_reason VARCHAR(500) NULL,
  UNIQUE KEY uq_cert_public (public_id),
  UNIQUE KEY uq_cert_reference (tenant_id, reference),
  -- Verification is looked up by code ALONE (a verifier has no tenant), so the
  -- code is unique across the platform, not within a tenant.
  UNIQUE KEY uq_cert_verify (verification_code),
  KEY ix_cert_member (tenant_id, membership_id, status),
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- An instructor's proposed curriculum week, awaiting academic review. Kept
-- separate from `modules` so an unreviewed proposal can never be taught.
CREATE TABLE module_proposals (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  course_id     BIGINT UNSIGNED NOT NULL,
  level_id      BIGINT UNSIGNED NULL,
  week_no       SMALLINT UNSIGNED NOT NULL,
  title         VARCHAR(190) NOT NULL,
  kind          VARCHAR(24)  NOT NULL DEFAULT 'module',
  content       TEXT         NULL,
  proposed_by_membership_id BIGINT UNSIGNED NULL,
  status        VARCHAR(16)  NOT NULL DEFAULT 'pending',
     -- pending|approved|rejected|withdrawn
  review_note   VARCHAR(1000) NULL,
  reviewed_by   BIGINT UNSIGNED NULL,
  reviewed_at   DATETIME(3)  NULL,
  -- Set when an approval created the real module, so the proposal and what it
  -- became are joinable rather than merely adjacent.
  module_id     BIGINT UNSIGNED NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  deleted_at    DATETIME(3)  NULL,
  deleted_seq   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_mprop_public (public_id),
  KEY ix_mprop_review (tenant_id, status, created_at),
  KEY ix_mprop_course (tenant_id, course_id, level_id, week_no),
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Studio practice-tool results. A personal best and a play count, per person per
-- tool. Deliberately NOT a ranking: there is no table in v2 that orders learners
-- against each other, and the 2026-08 redesign refuses one.
CREATE TABLE tool_scores (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  membership_id BIGINT UNSIGNED NOT NULL,
  tool          VARCHAR(48)  NOT NULL,
  best_score    INT UNSIGNED NOT NULL DEFAULT 0,
  best_at       DATETIME(3)  NULL,
  plays         INT UNSIGNED NOT NULL DEFAULT 0,
  -- The score is CLIENT-ATTESTED (it is measured by a microphone in a browser
  -- and the server cannot recompute it), so it is a practice record and never an
  -- assessment. It has no route into `assessments` on purpose.
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_toolscore (tenant_id, membership_id, tool),
  CONSTRAINT fk_toolscore_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_toolscore_member FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- LAYER N · TEACHING OPERATIONS
-- =============================================================================
-- Who may teach what, when they were in the room, what they wrote afterwards,
-- and what it costs a family to move a lesson. v1 spread this across eleven
-- tables keyed on `users.id`; here it is keyed on the MEMBERSHIP, so a person
-- who teaches at one centre and guardians a child at another is one account with
-- two relationships rather than one row with an ambiguous role.
--
-- Two v1 tables deliberately have NO table here:
--   • `instructor_profiles` (bio, specialties, years, photo) is absorbed into
--     `custom_field_values` on the membership — four optional strings do not
--     need a table, and the ETL already carries them there. Two homes for one
--     fact is how they drift.
--   • `reschedule_events` is absorbed into `lifecycle_events`, which is v2's one
--     stage machine. A reschedule request moving from pending to approved is the
--     same shape of fact as an application moving from submitted to enrolled.

-- When an instructor is available to teach. A wall-clock time plus a zone, per
-- the header note: 16:00 in Lagos must stay 16:00 if an offset rule changes.
CREATE TABLE instructor_availability (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  membership_id BIGINT UNSIGNED NOT NULL,
  centre_id     BIGINT UNSIGNED NULL,
  day_of_week   TINYINT UNSIGNED NOT NULL,      -- 0 = Sunday … 6 = Saturday
  start_time    TIME         NOT NULL,
  end_time      TIME         NOT NULL,
  timezone      VARCHAR(64)  NOT NULL DEFAULT 'Africa/Lagos',
  -- An availability window is a PERIOD, not a standing fact: a teacher who can
  -- take Friday afternoons this term may not next term, and last term's
  -- timetable has to stay explicable.
  effective_from DATE        NULL,
  effective_to   DATE        NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  note          VARCHAR(255) NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_avail_member (tenant_id, membership_id, day_of_week, is_active),
  CONSTRAINT chk_avail_dow CHECK (day_of_week <= 6),
  CONSTRAINT chk_avail_time CHECK (end_time > start_time),
  CONSTRAINT chk_avail_period CHECK (effective_to IS NULL
        OR effective_from IS NULL OR effective_to >= effective_from),
  CONSTRAINT chk_avail_active CHECK (is_active IN (0,1)),
  CONSTRAINT fk_avail_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_avail_member FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
  CONSTRAINT fk_avail_centre FOREIGN KEY (centre_id)     REFERENCES centres(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The courses an instructor may be assigned to teach. NOT `cohort_staff`, which
-- records who IS teaching a class: this is eligibility, read when an admin is
-- choosing somebody, and the two answer different questions. Named
-- `instructor_courses` rather than v1's `instructor_assignments` because
-- "assignment" in v2 is a piece of set work.
CREATE TABLE instructor_courses (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  membership_id BIGINT UNSIGNED NOT NULL,
  course_id     BIGINT UNSIGNED NOT NULL,
  level_id      BIGINT UNSIGNED NULL,           -- NULL = every level of the course
  -- 0 = every level, otherwise the level id — the same companion column
  -- `programme_courses` uses, and for the same reason: both engines treat NULLs
  -- as distinct in a unique index, so a key ending in a nullable level_id would
  -- let two identical "may teach every level of piano" rows coexist.
  level_scope   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  is_lead       TINYINT(1)   NOT NULL DEFAULT 0,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by    BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_icourse (tenant_id, membership_id, course_id, level_scope),
  KEY ix_icourse_course (tenant_id, course_id, level_id),
  CONSTRAINT chk_icourse_lead CHECK (is_lead IN (0,1)),
  CONSTRAINT fk_icourse_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_icourse_member FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
  CONSTRAINT fk_icourse_course FOREIGN KEY (course_id)     REFERENCES courses(id) ON DELETE CASCADE,
  CONSTRAINT fk_icourse_level  FOREIGN KEY (level_id)      REFERENCES levels(id) ON DELETE CASCADE,
  CONSTRAINT fk_icourse_by     FOREIGN KEY (created_by)    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The instructor's own attendance clock. §11: this is the OFFICIAL record of
-- whether a lesson was taught, and a video-conference report is only evidence
-- beside it — so nothing here is derived from a provider.
CREATE TABLE instructor_clockins (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  session_id    BIGINT UNSIGNED NOT NULL,
  membership_id BIGINT UNSIGNED NOT NULL,
  role          VARCHAR(16)  NOT NULL DEFAULT 'instructor',   -- instructor|substitute|assistant
  clocked_in_at  DATETIME(3) NULL,
  clocked_out_at DATETIME(3) NULL,
  -- STORED, never recomputed on read. A session's scheduled_at can be edited
  -- afterwards and the record of what actually happened must not move with it.
  minutes_late  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  minutes_present SMALLINT UNSIGNED NULL,
  status        VARCHAR(16)  NOT NULL DEFAULT 'present',
     -- present|late|absent|substituted|cancelled
  source        VARCHAR(16)  NOT NULL DEFAULT 'portal',       -- portal|admin|api
  note          VARCHAR(500) NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  -- One clock per person per session per role. A second clock-in is an EDIT of
  -- the first, not another attendance.
  UNIQUE KEY uq_clockin (tenant_id, session_id, membership_id, role),
  KEY ix_clockin_member (tenant_id, membership_id, clocked_in_at),
  CONSTRAINT chk_clockin_role CHECK (role IN ('instructor','substitute','assistant')),
  CONSTRAINT chk_clockin_status CHECK (status IN ('present','late','absent','substituted','cancelled')),
  -- Both timestamps come from one writer, so this compares like with like (the
  -- lesson `mark_appeals.opened_at` records above).
  CONSTRAINT chk_clockin_order CHECK (clocked_out_at IS NULL
        OR clocked_in_at IS NULL OR clocked_out_at >= clocked_in_at),
  -- An instructor recorded as present was in the room at a stated time. Absent
  -- and cancelled are the statuses with nothing to state.
  CONSTRAINT chk_clockin_when CHECK (status IN ('absent','cancelled')
        OR clocked_in_at IS NOT NULL),
  CONSTRAINT fk_clockin_tenant  FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_clockin_session FOREIGN KEY (session_id)    REFERENCES class_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_clockin_member  FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A charge raised against an instructor — in practice a late progress report.
--
-- ⚠️ This table RECORDS a charge. It does not deduct anything, and nothing here
-- links to a payroll run or to `ledger_entries`: Labour Act s.5(1) bars
-- deductions from wages for fines without an authorised labour officer's written
-- consent, so the deduction is a decision somebody takes outside this system
-- with that consent in hand. There is deliberately no `deducted_at`, no
-- `payroll_run_id` and no route from here into the money layer.
CREATE TABLE instructor_fines (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  membership_id BIGINT UNSIGNED NOT NULL,
  session_id    BIGINT UNSIGNED NULL,
  reason        VARCHAR(48)  NOT NULL DEFAULT 'late_progress_report',
  -- Minor units + ISO currency + the base amount and frozen rate whenever they
  -- differ, exactly as for money owed BY a family. v1 kept kobo in one column
  -- and USD cents in another, so a sum over the table depended on which column
  -- the reader happened to pick.
  amount_minor  BIGINT       NOT NULL DEFAULT 0,
  currency      CHAR(3)      NOT NULL,
  base_currency CHAR(3)      NOT NULL,
  base_amount_minor BIGINT   NULL,
  fx_rate       DECIMAL(20,8) NULL,
  fx_source     VARCHAR(32)  NULL,
  status        VARCHAR(16)  NOT NULL DEFAULT 'pending',
     -- pending|upheld|waived|cancelled|disputed
  note          VARCHAR(500) NULL,
  resolved_by   BIGINT UNSIGNED NULL,
  resolved_at   DATETIME(3)  NULL,
  resolution_note VARCHAR(1000) NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by    BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_ifine_public (public_id),
  KEY ix_ifine_member (tenant_id, membership_id, status, created_at),
  CONSTRAINT chk_ifine_status CHECK (status IN ('pending','upheld','waived','cancelled','disputed')),
  CONSTRAINT chk_ifine_amount CHECK (amount_minor >= 0),
  CONSTRAINT chk_ifine_fx CHECK (currency = base_currency
        OR (base_amount_minor IS NOT NULL AND fx_rate IS NOT NULL)),
  -- A resolution is a named person on a stated date, and anything other than
  -- upholding it says why. "Waived" with no reason is the record of a decision
  -- nobody can account for.
  CONSTRAINT chk_ifine_resolved CHECK (status IN ('pending','disputed')
        OR (resolved_by IS NOT NULL AND resolved_at IS NOT NULL)),
  CONSTRAINT chk_ifine_waived CHECK (status NOT IN ('waived','cancelled')
        OR resolution_note IS NOT NULL),
  CONSTRAINT fk_ifine_tenant  FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_ifine_member  FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
  CONSTRAINT fk_ifine_session FOREIGN KEY (session_id)    REFERENCES class_sessions(id) ON DELETE SET NULL,
  CONSTRAINT fk_ifine_res     FOREIGN KEY (resolved_by)   REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ifine_by      FOREIGN KEY (created_by)    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Somebody the academy has invited to apply for a teaching post, and the
-- application they send back.
--
-- The invitation TOKEN is not stored here in usable form: v1 kept the nonce, and
-- a leaked table of live nonces is a set of credentials. Only its hash is kept,
-- so an arriving link can be verified and nothing in the table can be replayed.
CREATE TABLE faculty_invites (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  email         VARCHAR(320) NOT NULL,
  name          VARCHAR(190) NULL,
  source        VARCHAR(24)  NOT NULL DEFAULT 'contact',   -- contact|referral|admin|event
  status        VARCHAR(16)  NOT NULL DEFAULT 'requested',
     -- requested|approved|sent|used|expired|declined
  nonce_hash    CHAR(64)     NULL,
  expires_at    DATETIME(3)  NULL,
  invited_by    BIGINT UNSIGNED NULL,
  invited_at    DATETIME(3)  NULL,
  used_at       DATETIME(3)  NULL,
  note          VARCHAR(500) NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_finvite_public (public_id),
  KEY ix_finvite_email (tenant_id, email, status),
  CONSTRAINT chk_finvite_status CHECK (status IN
    ('requested','approved','sent','used','expired','declined')),
  CONSTRAINT chk_finvite_source CHECK (source IN ('contact','referral','admin','event')),
  -- A sent invitation has something to verify and a moment it stops working. An
  -- invitation that never expires is a permanent credential.
  CONSTRAINT chk_finvite_sent CHECK (status NOT IN ('sent','used')
        OR (nonce_hash IS NOT NULL AND expires_at IS NOT NULL AND invited_at IS NOT NULL)),
  CONSTRAINT chk_finvite_used CHECK (status <> 'used' OR used_at IS NOT NULL),
  CONSTRAINT fk_finvite_tenant FOREIGN KEY (tenant_id)  REFERENCES tenants(id),
  CONSTRAINT fk_finvite_by     FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE instructor_applications (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  invite_id     BIGINT UNSIGNED NULL,
  -- The candidate may have no account at all, so this is an ADDRESS and not a
  -- membership. It becomes a membership if they are appointed.
  email         VARCHAR(320) NOT NULL,
  full_name     VARCHAR(190) NULL,
  phone         VARCHAR(32)  NULL,
  disciplines   VARCHAR(500) NULL,
  experience_years SMALLINT UNSIGNED NULL,
  bio           TEXT         NULL,
  links         VARCHAR(1000) NULL,
  status        VARCHAR(16)  NOT NULL DEFAULT 'submitted',
     -- submitted|screening|interview|offered|appointed|declined|withdrawn
  membership_id BIGINT UNSIGNED NULL,     -- set when they are appointed
  decided_by    BIGINT UNSIGNED NULL,
  decided_at    DATETIME(3)  NULL,
  decision_note VARCHAR(1000) NULL,
  -- The CV and the headshot are `attachments` (entity_type='instructor_application'):
  -- a candidate's documents are files and belong where every other file is.
  source_ip     VARBINARY(16) NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  deleted_at    DATETIME(3)  NULL,
  deleted_seq   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_iapp_public (public_id),
  KEY ix_iapp_status (tenant_id, status, created_at),
  KEY ix_iapp_email (tenant_id, email),
  CONSTRAINT chk_iapp_status CHECK (status IN
    ('submitted','screening','interview','offered','appointed','declined','withdrawn')),
  -- An appointment is the one status that must point at the account it created,
  -- otherwise the hire and the teacher are two unconnected records.
  CONSTRAINT chk_iapp_appointed CHECK (status <> 'appointed' OR membership_id IS NOT NULL),
  -- A decided application states WHEN and says something about the decision.
  -- Deliberately NOT "and who": v1 recorded no decider, and refusing those rows
  -- would either lose the decisions (putting declined candidates back in the
  -- queue) or force a fabricated name into the record. Contrast
  -- `chk_treport_rat`, which does demand a named ratifier — a mark shown to a
  -- family has to be attributable because it can be appealed, while a
  -- recruitment decision affects no-one's rights that way.
  CONSTRAINT chk_iapp_decided CHECK (status IN ('submitted','screening','interview')
        OR (decided_at IS NOT NULL AND decision_note IS NOT NULL)),
  CONSTRAINT fk_iapp_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_iapp_invite FOREIGN KEY (invite_id)     REFERENCES faculty_invites(id) ON DELETE SET NULL,
  CONSTRAINT fk_iapp_member FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE SET NULL,
  CONSTRAINT fk_iapp_by     FOREIGN KEY (decided_by)    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What a video-conference provider says happened, beside what the portal
-- recorded. §11 is explicit that the portal clock-in is the official record and
-- this is corroboration — so it is a separate table and never writes to
-- `attendance`.
--
-- ⚠️ Google's Reports API emits the moment a participant LEFT plus a duration;
-- there is no join timestamp. `joined_at` is therefore DERIVED by subtraction
-- and `joined_at_is_derived` says so, because a derived instant presented as an
-- observation is the kind of evidence that loses a dispute.
CREATE TABLE meeting_participants (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  session_id    BIGINT UNSIGNED NOT NULL,
  subject_type  VARCHAR(16)  NOT NULL DEFAULT 'learner',   -- learner|staff|guest|unknown
  membership_id BIGINT UNSIGNED NULL,
  -- The name the provider reported. Kept even when it matched nobody: an
  -- unmatched participant is the interesting row, not a discardable one.
  display_name  VARCHAR(190) NULL,
  joined_at     DATETIME(3)  NULL,
  left_at       DATETIME(3)  NULL,
  minutes       SMALLINT UNSIGNED NULL,
  joined_at_is_derived TINYINT(1) NOT NULL DEFAULT 0,
  provider      VARCHAR(24)  NOT NULL DEFAULT 'meet',      -- meet|jitsi|zoom|manual
  source        VARCHAR(16)  NOT NULL DEFAULT 'import',    -- import|api|manual
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_mpart_session (tenant_id, session_id, subject_type),
  KEY ix_mpart_member (tenant_id, membership_id),
  CONSTRAINT chk_mpart_subject CHECK (subject_type IN ('learner','staff','guest','unknown')),
  CONSTRAINT chk_mpart_source CHECK (source IN ('import','api','manual')),
  CONSTRAINT chk_mpart_derived CHECK (joined_at_is_derived IN (0,1)),
  CONSTRAINT chk_mpart_order CHECK (left_at IS NULL OR joined_at IS NULL OR left_at >= joined_at),
  CONSTRAINT fk_mpart_tenant  FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_mpart_session FOREIGN KEY (session_id)    REFERENCES class_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_mpart_member  FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The instructor's note after a single lesson, due within two hours of it.
--
-- ⚠️ Named `session_progress_reports`, NOT `progress_reports`. v1's
-- `progress_reports` and its `termly_reports` are different documents with
-- different audiences and different rules — one is a two-hour note about one
-- lesson, the other is a board-ratified termly record — and the bare name has
-- already caused them to be confused in code. The name states which is which.
CREATE TABLE session_progress_reports (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  session_id    BIGINT UNSIGNED NOT NULL,
  enrolment_id  BIGINT UNSIGNED NOT NULL,
  instructor_membership_id BIGINT UNSIGNED NOT NULL,
  answers       JSON         NULL,
  narrative     TEXT         NULL,
  -- The deadline is STORED, so lateness is a fact about this report rather than
  -- something recomputed from a policy that may since have changed.
  due_at        DATETIME(3)  NULL,
  submitted_at  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  minutes_late  INT UNSIGNED NULL,
  -- Whether the FAMILY has been sent it, which is a different fact from whether
  -- it was written.
  sent_at       DATETIME(3)  NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  deleted_at    DATETIME(3)  NULL,
  deleted_seq   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_sprep_public (public_id),
  -- One LIVE report per learner per session. v1's UNIQUE(session, student) could
  -- not express a report withdrawn and rewritten.
  UNIQUE KEY uq_sprep_slot (tenant_id, session_id, enrolment_id, deleted_seq),
  KEY ix_sprep_instructor (tenant_id, instructor_membership_id, submitted_at),
  CONSTRAINT fk_sprep_tenant  FOREIGN KEY (tenant_id)    REFERENCES tenants(id),
  CONSTRAINT fk_sprep_session FOREIGN KEY (session_id)   REFERENCES class_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_sprep_enrol   FOREIGN KEY (enrolment_id) REFERENCES enrolments(id) ON DELETE CASCADE,
  CONSTRAINT fk_sprep_instr   FOREIGN KEY (instructor_membership_id) REFERENCES memberships(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A family asking to move lessons, priced by the academy and decided by a human.
CREATE TABLE reschedule_requests (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  requester_membership_id BIGINT UNSIGNED NOT NULL,
  enrolment_id  BIGINT UNSIGNED NULL,
  cohort_id     BIGINT UNSIGNED NOT NULL,
  status        VARCHAR(24)  NOT NULL DEFAULT 'pending_payment',
     -- pending_payment|pending_approval|approved|declined|applied|expired|cancelled
  reason        VARCHAR(1000) NULL,
  session_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  -- SERVER-PRICED, in minor units, with the base amount and frozen rate whenever
  -- they differ. The client sends intent; what it costs is never the client's to
  -- state.
  fee_minor     BIGINT       NOT NULL DEFAULT 0,
  currency      CHAR(3)      NOT NULL,
  base_currency CHAR(3)      NOT NULL,
  base_fee_minor BIGINT      NULL,
  fx_rate       DECIMAL(20,8) NULL,
  is_waived     TINYINT(1)   NOT NULL DEFAULT 0,
  waived_reason VARCHAR(500) NULL,
  payment_id    BIGINT UNSIGNED NULL,
  paid_at       DATETIME(3)  NULL,
  decided_by    BIGINT UNSIGNED NULL,
  decided_at    DATETIME(3)  NULL,
  decision_note VARCHAR(1000) NULL,
  applied_at    DATETIME(3)  NULL,
  expires_at    DATETIME(3)  NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  row_version   INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_resch_public (public_id),
  KEY ix_resch_status (tenant_id, status, created_at),
  KEY ix_resch_member (tenant_id, requester_membership_id, status),
  CONSTRAINT chk_resch_status CHECK (status IN ('pending_payment','pending_approval',
    'approved','declined','applied','expired','cancelled')),
  CONSTRAINT chk_resch_fee CHECK (fee_minor >= 0),
  CONSTRAINT chk_resch_fx CHECK (currency = base_currency
        OR (base_fee_minor IS NOT NULL AND fx_rate IS NOT NULL)),
  CONSTRAINT chk_resch_waived CHECK (is_waived IN (0,1)
        AND (is_waived = 0 OR waived_reason IS NOT NULL)),
  -- A decision is a named person on a stated date, and a refusal says why: the
  -- family paid a fee to ask.
  CONSTRAINT chk_resch_decided CHECK (status IN ('pending_payment','pending_approval','expired','cancelled')
        OR (decided_by IS NOT NULL AND decided_at IS NOT NULL)),
  CONSTRAINT chk_resch_declined CHECK (status <> 'declined' OR decision_note IS NOT NULL),
  CONSTRAINT chk_resch_applied CHECK (status <> 'applied' OR applied_at IS NOT NULL),
  -- A fee that has been PAID names the payment. Reconciling "did this family pay
  -- to move their lessons" by matching amounts and dates is how money goes
  -- missing.
  CONSTRAINT chk_resch_paid CHECK (paid_at IS NULL OR is_waived = 1 OR payment_id IS NOT NULL),
  CONSTRAINT fk_resch_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_resch_member FOREIGN KEY (requester_membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
  CONSTRAINT fk_resch_enrol  FOREIGN KEY (enrolment_id)  REFERENCES enrolments(id) ON DELETE SET NULL,
  CONSTRAINT fk_resch_cohort FOREIGN KEY (cohort_id)     REFERENCES cohorts(id) ON DELETE CASCADE,
  CONSTRAINT fk_resch_pay    FOREIGN KEY (payment_id)    REFERENCES payments(id) ON DELETE SET NULL,
  CONSTRAINT fk_resch_by     FOREIGN KEY (decided_by)    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One session inside a reschedule request. The OLD time is kept because "what
-- was it before" is the first question asked when a family says a lesson moved
-- and nobody told them.
CREATE TABLE reschedule_items (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  request_id    BIGINT UNSIGNED NOT NULL,
  session_id    BIGINT UNSIGNED NOT NULL,
  old_scheduled_at DATETIME(3) NULL,
  new_scheduled_at DATETIME(3) NOT NULL,
  timezone      VARCHAR(64)  NOT NULL DEFAULT 'Africa/Lagos',
  is_applied    TINYINT(1)   NOT NULL DEFAULT 0,
  applied_at    DATETIME(3)  NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_reschitem (request_id, session_id),
  KEY ix_reschitem_session (tenant_id, session_id),
  CONSTRAINT chk_reschitem_applied CHECK (is_applied IN (0,1)
        AND (is_applied = 0 OR applied_at IS NOT NULL)),
  CONSTRAINT fk_reschitem_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants(id),
  CONSTRAINT fk_reschitem_request FOREIGN KEY (request_id) REFERENCES reschedule_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_reschitem_session FOREIGN KEY (session_id) REFERENCES class_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The queue for a full cohort.
CREATE TABLE cohort_waitlist (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  cohort_id     BIGINT UNSIGNED NOT NULL,
  membership_id BIGINT UNSIGNED NOT NULL,
  position      INT UNSIGNED NOT NULL,
  status        VARCHAR(16)  NOT NULL DEFAULT 'waiting',
     -- waiting|offered|promoted|declined|expired|withdrawn
  -- Set when the family was TOLD a seat had come free. The column existed in v1
  -- and nothing ever wrote it, so promotions were silent — hence the CHECK.
  notified_at   DATETIME(3)  NULL,
  offer_expires_at DATETIME(3) NULL,
  promoted_enrolment_id BIGINT UNSIGNED NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_seq   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  -- One LIVE place in one queue per person; leaving and rejoining is legal and
  -- takes a new position.
  UNIQUE KEY uq_waitlist (tenant_id, cohort_id, membership_id, deleted_seq),
  KEY ix_waitlist_queue (tenant_id, cohort_id, status, position),
  CONSTRAINT chk_waitlist_status CHECK (status IN
    ('waiting','offered','promoted','declined','expired','withdrawn')),
  CONSTRAINT chk_waitlist_position CHECK (position >= 1),
  CONSTRAINT chk_waitlist_offered CHECK (status <> 'offered' OR notified_at IS NOT NULL),
  -- A promotion points at the enrolment it produced. Without it, "promoted" is a
  -- claim with nothing behind it — which is exactly the v1 bug where entries
  -- were marked promoted while nobody was enrolled.
  CONSTRAINT chk_waitlist_promoted CHECK (status <> 'promoted'
        OR (promoted_enrolment_id IS NOT NULL AND notified_at IS NOT NULL)),
  CONSTRAINT fk_waitlist_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_waitlist_cohort FOREIGN KEY (cohort_id)     REFERENCES cohorts(id) ON DELETE CASCADE,
  CONSTRAINT fk_waitlist_member FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
  CONSTRAINT fk_waitlist_enrol  FOREIGN KEY (promoted_enrolment_id) REFERENCES enrolments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Material shared with learners. v1 had `class_resources` (dual-read across both
-- enrolment models) plus `resource_versions` beside it; here a new version is a
-- NEW ROW that supersedes the old one, which is the same idiom `rubrics` uses.
-- A learner who was given version 1 can still be shown what they were given.
CREATE TABLE learning_resources (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  cohort_id     BIGINT UNSIGNED NULL,
  course_id     BIGINT UNSIGNED NULL,
  module_id     BIGINT UNSIGNED NULL,
  title         VARCHAR(190) NOT NULL,
  kind          VARCHAR(24)  NOT NULL DEFAULT 'link',   -- link|document|score|audio|video|drive
  url           VARCHAR(1000) NULL,
  attachment_id BIGINT UNSIGNED NULL,
  visibility    VARCHAR(16)  NOT NULL DEFAULT 'cohort', -- cohort|course|tenant|staff
  version       INT UNSIGNED NOT NULL DEFAULT 1,
  supersedes_id BIGINT UNSIGNED NULL,
  note          VARCHAR(500) NULL,
  posted_by     BIGINT UNSIGNED NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  deleted_at    DATETIME(3)  NULL,
  deleted_seq   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_lres_public (public_id),
  KEY ix_lres_cohort (tenant_id, cohort_id, deleted_seq),
  KEY ix_lres_module (tenant_id, module_id, deleted_seq),
  CONSTRAINT chk_lres_visibility CHECK (visibility IN ('cohort','course','tenant','staff')),
  CONSTRAINT chk_lres_version CHECK (version >= 1),
  -- Something to open: either a URL or a stored file. A resource with neither is
  -- a title in a list that disappoints whoever clicks it.
  CONSTRAINT chk_lres_target CHECK (url IS NOT NULL OR attachment_id IS NOT NULL),
  -- And somewhere to belong. v1 allowed both class_id and cohort_id to be NULL,
  -- which made a resource nobody could reach.
  CONSTRAINT chk_lres_scope CHECK (cohort_id IS NOT NULL
        OR course_id IS NOT NULL OR module_id IS NOT NULL OR visibility IN ('tenant','staff')),
  CONSTRAINT fk_lres_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_lres_cohort FOREIGN KEY (cohort_id)     REFERENCES cohorts(id) ON DELETE CASCADE,
  CONSTRAINT fk_lres_course FOREIGN KEY (course_id)     REFERENCES courses(id) ON DELETE SET NULL,
  CONSTRAINT fk_lres_module FOREIGN KEY (module_id)     REFERENCES modules(id) ON DELETE SET NULL,
  CONSTRAINT fk_lres_att    FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE SET NULL,
  CONSTRAINT fk_lres_super  FOREIGN KEY (supersedes_id) REFERENCES learning_resources(id) ON DELETE SET NULL,
  CONSTRAINT fk_lres_by     FOREIGN KEY (posted_by)     REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- LAYER O · COMMUNICATIONS AND ADMISSIONS
-- =============================================================================
-- Everything the academy says to people, and everything people say back before
-- they are members. Three v1 tables deliberately have no table here:
--   • `feedback` (per-submission rubric + comments) is `assessments` +
--     `assessment_marks` — one marking mechanism, not two.
--   • `summer_applications` is `applications` with `type='programme'` against
--     the CRASH programme. A parallel intake table is the thing the migration
--     exists to end.
--   • `application_drafts` is abandoned wizard state keyed by a browser token.
--     A draft nobody submitted is not an application, and after cutover it is
--     personal data held for no purpose anybody can name.
--
-- Two rules run through the whole layer and are enforced by CHECK rather than
-- left to a caller:
--   1. ANONYMOUS MEANS ANONYMOUS. v1 stored `is_anonymous` beside the author's
--      id, so "anonymous" feedback named its author to anyone with database
--      access — which is everyone who can read a backup. Here an anonymous row
--      has NOWHERE to put an identity, and one-per-person is enforced by a
--      salted hash instead.
--   2. A STAFF-ONLY NOTE SAYS SO. Visibility is a column, never an inference
--      from who wrote it.

-- What the academy tells a cohort. v1 had class_id OR cohort_id (the two
-- enrolment models); v2 has one spine, plus a wider scope for a whole course or
-- the whole tenant.
CREATE TABLE announcements (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  cohort_id     BIGINT UNSIGNED NULL,
  course_id     BIGINT UNSIGNED NULL,
  centre_id     BIGINT UNSIGNED NULL,
  scope         VARCHAR(16)  NOT NULL DEFAULT 'cohort',   -- cohort|course|centre|tenant
  -- WHO is meant to read it. A timetable change is for guardians, a practice
  -- reminder is for learners, and sending both to both is how people stop
  -- reading either.
  audience      VARCHAR(16)  NOT NULL DEFAULT 'both',     -- learners|guardians|both|staff
  title         VARCHAR(190) NULL,
  body          TEXT         NOT NULL,
  author_membership_id BIGINT UNSIGNED NULL,
  publish_at    DATETIME(3)  NULL,
  expires_at    DATETIME(3)  NULL,
  is_pinned     TINYINT(1)   NOT NULL DEFAULT 0,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  deleted_at    DATETIME(3)  NULL,
  deleted_seq   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_ann_public (public_id),
  KEY ix_ann_cohort (tenant_id, cohort_id, deleted_seq),
  KEY ix_ann_scope (tenant_id, scope, publish_at),
  CONSTRAINT chk_ann_scope CHECK (scope IN ('cohort','course','centre','tenant')),
  CONSTRAINT chk_ann_audience CHECK (audience IN ('learners','guardians','both','staff')),
  CONSTRAINT chk_ann_pinned CHECK (is_pinned IN (0,1)),
  -- The scope has to name what it is scoped TO, or nobody can be resolved as a
  -- recipient. v1 allowed both ids to be NULL, which made an announcement with
  -- no readers.
  CONSTRAINT chk_ann_target CHECK (
        (scope = 'cohort' AND cohort_id IS NOT NULL)
     OR (scope = 'course' AND course_id IS NOT NULL)
     OR (scope = 'centre' AND centre_id IS NOT NULL)
     OR scope = 'tenant'),
  CONSTRAINT chk_ann_window CHECK (expires_at IS NULL OR publish_at IS NULL OR expires_at >= publish_at),
  CONSTRAINT fk_ann_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_ann_cohort FOREIGN KEY (cohort_id) REFERENCES cohorts(id) ON DELETE CASCADE,
  CONSTRAINT fk_ann_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  CONSTRAINT fk_ann_centre FOREIGN KEY (centre_id) REFERENCES centres(id) ON DELETE CASCADE,
  CONSTRAINT fk_ann_author FOREIGN KEY (author_membership_id) REFERENCES memberships(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The public contact form. NOT `messages`, and NOT the guardian threads: this is
-- a stranger's enquiry, held under legitimate interest, with no account behind
-- it — a different lawful basis, a different retention period and a different
-- audience from anything in LAYER L. The bare name `messages` is what let the
-- two be confused in v1.
CREATE TABLE contact_messages (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  name          VARCHAR(190) NULL,
  email         VARCHAR(320) NULL,
  phone         VARCHAR(32)  NULL,
  topic         VARCHAR(48)  NULL,
  body          TEXT         NOT NULL,
  status        VARCHAR(16)  NOT NULL DEFAULT 'unread',   -- unread|read|answered|spam|closed
  -- The enquirer may become a member later. Set when somebody makes that link
  -- BY HAND; never matched on an address alone, because two people share a
  -- family inbox and one of them is a child.
  membership_id BIGINT UNSIGNED NULL,
  answered_by   BIGINT UNSIGNED NULL,
  answered_at   DATETIME(3)  NULL,
  source_ip     VARBINARY(16) NULL,
  user_agent    VARCHAR(255) NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  -- The retention anchor. `dp_retention()` names a real table and a real date
  -- column, so an enquiry has to carry one the sweep can measure from.
  deleted_at    DATETIME(3)  NULL,
  UNIQUE KEY uq_cmsg_public (public_id),
  KEY ix_cmsg_status (tenant_id, status, created_at),
  KEY ix_cmsg_email (tenant_id, email),
  CONSTRAINT chk_cmsg_status CHECK (status IN ('unread','read','answered','spam','closed')),
  -- Something to answer, and somewhere to send it. v1 accepted a message with
  -- neither an address nor a phone number, which is a complaint nobody can reply
  -- to sitting in a queue looking actionable.
  CONSTRAINT chk_cmsg_reachable CHECK (email IS NOT NULL OR phone IS NOT NULL),
  CONSTRAINT chk_cmsg_answered CHECK (status <> 'answered' OR answered_at IS NOT NULL),
  CONSTRAINT fk_cmsg_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_cmsg_member FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE SET NULL,
  CONSTRAINT fk_cmsg_by     FOREIGN KEY (answered_by)   REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE support_tickets (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  -- The person who raised it, which may be a guardian on a learner's behalf —
  -- so both are recorded and neither is inferred from the other.
  raised_by_membership_id BIGINT UNSIGNED NOT NULL,
  subject_membership_id   BIGINT UNSIGNED NULL,
  reference     VARCHAR(64)  NOT NULL,
  category      VARCHAR(32)  NOT NULL DEFAULT 'general',
  subject       VARCHAR(190) NOT NULL,
  status        VARCHAR(16)  NOT NULL DEFAULT 'open',
     -- open|waiting_on_us|waiting_on_them|resolved|closed
  priority      VARCHAR(16)  NOT NULL DEFAULT 'normal',   -- low|normal|high|urgent
  assigned_to_membership_id BIGINT UNSIGNED NULL,
  -- Stored, not recomputed: a support target that changes must not restate
  -- whether last term's tickets were answered in time.
  first_response_due_at DATETIME(3) NULL,
  first_response_at     DATETIME(3) NULL,
  resolved_at   DATETIME(3)  NULL,
  closed_at     DATETIME(3)  NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  row_version   INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_ticket_public (public_id),
  UNIQUE KEY uq_ticket_ref (tenant_id, reference),
  KEY ix_ticket_queue (tenant_id, status, priority, created_at),
  KEY ix_ticket_member (tenant_id, raised_by_membership_id, status),
  CONSTRAINT chk_ticket_status CHECK (status IN
    ('open','waiting_on_us','waiting_on_them','resolved','closed')),
  CONSTRAINT chk_ticket_priority CHECK (priority IN ('low','normal','high','urgent')),
  CONSTRAINT chk_ticket_resolved CHECK (status NOT IN ('resolved','closed') OR resolved_at IS NOT NULL),
  CONSTRAINT fk_ticket_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_ticket_raiser FOREIGN KEY (raised_by_membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
  CONSTRAINT fk_ticket_subject FOREIGN KEY (subject_membership_id) REFERENCES memberships(id) ON DELETE SET NULL,
  CONSTRAINT fk_ticket_assignee FOREIGN KEY (assigned_to_membership_id) REFERENCES memberships(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_messages (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  ticket_id     BIGINT UNSIGNED NOT NULL,
  author_membership_id BIGINT UNSIGNED NULL,
  author_name   VARCHAR(190) NOT NULL,
  body          TEXT         NOT NULL,
  -- An internal note is invisible to the family. It is a COLUMN, never an
  -- inference from the author's role: a staff member also writes replies the
  -- family is meant to read, and getting that inference wrong discloses a
  -- private note about a child.
  visibility    VARCHAR(16)  NOT NULL DEFAULT 'family',   -- family|internal
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_tmsg_ticket (tenant_id, ticket_id, created_at),
  CONSTRAINT chk_tmsg_visibility CHECK (visibility IN ('family','internal')),
  CONSTRAINT fk_tmsg_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_tmsg_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_tmsg_author FOREIGN KEY (author_membership_id) REFERENCES memberships(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE surveys (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  kind          VARCHAR(24)  NOT NULL DEFAULT 'survey',   -- survey|form|progress_form|poll
  title         VARCHAR(190) NOT NULL,
  description   TEXT         NULL,
  questions     JSON         NOT NULL,
  status        VARCHAR(16)  NOT NULL DEFAULT 'draft',    -- draft|open|closed|archived
  audience      VARCHAR(16)  NOT NULL DEFAULT 'learners', -- learners|guardians|staff
  target_course_id BIGINT UNSIGNED NULL,
  target_cohort_id BIGINT UNSIGNED NULL,
  -- Declared ONCE, on the survey, before anybody answers. An anonymity promise
  -- that can be switched off after the fact is not a promise, so
  -- `mam_survey_*` must refuse to change it once a response exists.
  is_anonymous  TINYINT(1)   NOT NULL DEFAULT 0,
  opens_at      DATETIME(3)  NULL,
  closes_at     DATETIME(3)  NULL,
  created_by    BIGINT UNSIGNED NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  deleted_at    DATETIME(3)  NULL,
  deleted_seq   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_survey_public (public_id),
  KEY ix_survey_status (tenant_id, status, closes_at),
  CONSTRAINT chk_survey_kind CHECK (kind IN ('survey','form','progress_form','poll')),
  CONSTRAINT chk_survey_status CHECK (status IN ('draft','open','closed','archived')),
  CONSTRAINT chk_survey_audience CHECK (audience IN ('learners','guardians','staff')),
  CONSTRAINT chk_survey_anon CHECK (is_anonymous IN (0,1)),
  CONSTRAINT chk_survey_window CHECK (closes_at IS NULL OR opens_at IS NULL OR closes_at >= opens_at),
  CONSTRAINT fk_survey_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_survey_course FOREIGN KEY (target_course_id) REFERENCES courses(id) ON DELETE SET NULL,
  CONSTRAINT fk_survey_cohort FOREIGN KEY (target_cohort_id) REFERENCES cohorts(id) ON DELETE SET NULL,
  CONSTRAINT fk_survey_by     FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One answered survey.
--
-- ⚠️ v1's `survey_responses` had UNIQUE(survey_id, student_id) and NOT NULL on
-- the student — so every response was identifiable whatever the survey claimed.
-- Here an anonymous response carries NO membership and one-per-person is kept by
-- `respondent_hash`: a salted hash of the identity, with the salt held per
-- survey outside the row. It answers "has this person already replied" without
-- answering "who said this".
CREATE TABLE survey_responses (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  survey_id     BIGINT UNSIGNED NOT NULL,
  membership_id BIGINT UNSIGNED NULL,
  respondent_hash CHAR(64)   NULL,
  is_anonymous  TINYINT(1)   NOT NULL DEFAULT 0,
  answers       JSON         NOT NULL,
  submitted_at  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  -- One reply per person per survey, whichever way the survey is run. Both keys
  -- exist because exactly one of the two columns is populated.
  UNIQUE KEY uq_sresp_member (survey_id, membership_id),
  UNIQUE KEY uq_sresp_hash (survey_id, respondent_hash),
  KEY ix_sresp_survey (tenant_id, survey_id, submitted_at),
  CONSTRAINT chk_sresp_anon CHECK (is_anonymous IN (0,1)),
  -- The whole point of the table. An anonymous response has nowhere to put an
  -- identity, and an attributed one must state whose it is.
  CONSTRAINT chk_sresp_identity CHECK (
        (is_anonymous = 1 AND membership_id IS NULL AND respondent_hash IS NOT NULL)
     OR (is_anonymous = 0 AND membership_id IS NOT NULL)),
  CONSTRAINT fk_sresp_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_sresp_survey FOREIGN KEY (survey_id)     REFERENCES surveys(id) ON DELETE CASCADE,
  CONSTRAINT fk_sresp_member FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What a learner or family says about the teaching. Same anonymity rule as
-- survey_responses, and for the same reason: v1 kept `is_anonymous` next to
-- `student_id`, so the promise was worth nothing to anybody who could read the
-- table — which includes anyone with a backup.
CREATE TABLE learner_feedback (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  membership_id BIGINT UNSIGNED NULL,
  is_anonymous  TINYINT(1)   NOT NULL DEFAULT 0,
  -- Context WITHOUT identity. A cohort of nine is small enough that naming the
  -- cohort can identify the author, so an anonymous row keeps the COURSE and
  -- drops the cohort rather than pretending the distinction does not matter.
  course_id     BIGINT UNSIGNED NULL,
  cohort_id     BIGINT UNSIGNED NULL,
  rating        TINYINT UNSIGNED NULL,
  category      VARCHAR(32)  NOT NULL DEFAULT 'general',
  subject       VARCHAR(190) NULL,
  body          TEXT         NOT NULL,
  status        VARCHAR(16)  NOT NULL DEFAULT 'new',      -- new|reviewed|actioned|closed
  reviewed_by   BIGINT UNSIGNED NULL,
  reviewed_at   DATETIME(3)  NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_lfb_public (public_id),
  KEY ix_lfb_status (tenant_id, status, created_at),
  CONSTRAINT chk_lfb_anon CHECK (is_anonymous IN (0,1)),
  CONSTRAINT chk_lfb_rating CHECK (rating IS NULL OR (rating >= 1 AND rating <= 5)),
  CONSTRAINT chk_lfb_status CHECK (status IN ('new','reviewed','actioned','closed')),
  CONSTRAINT chk_lfb_identity CHECK (
        (is_anonymous = 1 AND membership_id IS NULL AND cohort_id IS NULL)
     OR is_anonymous = 0),
  CONSTRAINT fk_lfb_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_lfb_member FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
  CONSTRAINT fk_lfb_course FOREIGN KEY (course_id)     REFERENCES courses(id) ON DELETE SET NULL,
  CONSTRAINT fk_lfb_cohort FOREIGN KEY (cohort_id)     REFERENCES cohorts(id) ON DELETE SET NULL,
  CONSTRAINT fk_lfb_rev    FOREIGN KEY (reviewed_by)   REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- An admissions officer's note on an application. Internal by default: this is
-- where a judgement about a child gets written down, and a family reading it in
-- a subject-access response should find a professional record, not a surprise.
CREATE TABLE application_notes (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  application_id BIGINT UNSIGNED NOT NULL,
  author_membership_id BIGINT UNSIGNED NULL,
  author_name   VARCHAR(190) NOT NULL,
  note          TEXT         NOT NULL,
  visibility    VARCHAR(16)  NOT NULL DEFAULT 'internal', -- internal|family
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_anote_app (tenant_id, application_id, created_at),
  CONSTRAINT chk_anote_visibility CHECK (visibility IN ('internal','family')),
  CONSTRAINT fk_anote_tenant FOREIGN KEY (tenant_id)      REFERENCES tenants(id),
  CONSTRAINT fk_anote_app    FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_anote_author FOREIGN KEY (author_membership_id) REFERENCES memberships(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The MAM Scholars Initiative: its own track, assessed on essays.
--
-- Like `bursary_applications`, there is NO column linking this to an admission
-- decision, and there is not one by accident. A scholarship is decided by a
-- committee on the essays; an admission is decided on readiness. A foreign key
-- between them would make "did we admit this child because they asked for money"
-- a question the schema cannot answer no.
CREATE TABLE scholarship_applications (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  reference     VARCHAR(64)  NOT NULL,
  application_id BIGINT UNSIGNED NULL,
  membership_id BIGINT UNSIGNED NULL,
  full_name     VARCHAR(190) NOT NULL,
  email         VARCHAR(320) NOT NULL,
  age_band      VARCHAR(24)  NULL,
  experience    TEXT         NULL,
  -- The five assessed sections. Free text about a family's finances is the most
  -- sensitive thing in this layer, and `dp_retention()` measures its period from
  -- `created_at`: it is kept for the award cycle, not for ever.
  financial_need     TEXT    NULL,
  musical_journey    TEXT    NULL,
  values_alignment   TEXT    NULL,
  community_impact   TEXT    NULL,
  commitment_ack TINYINT(1)  NOT NULL DEFAULT 0,
  readiness_signal VARCHAR(24) NULL,
  status        VARCHAR(16)  NOT NULL DEFAULT 'submitted',
     -- submitted|screening|shortlisted|awarded|declined|withdrawn
  decided_by    BIGINT UNSIGNED NULL,
  decided_at    DATETIME(3)  NULL,
  outcome_note  VARCHAR(1000) NULL,
  source_ip     VARBINARY(16) NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  deleted_at    DATETIME(3)  NULL,
  deleted_seq   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_schol_public (public_id),
  UNIQUE KEY uq_schol_ref (tenant_id, reference),
  KEY ix_schol_status (tenant_id, status, created_at),
  CONSTRAINT chk_schol_status CHECK (status IN
    ('submitted','screening','shortlisted','awarded','declined','withdrawn')),
  CONSTRAINT chk_schol_ack CHECK (commitment_ack IN (0,1)),
  -- A decision states when and why. An award or a refusal a family cannot be
  -- given a reason for is the thing that makes a scholarship scheme look
  -- arbitrary.
  CONSTRAINT chk_schol_decided CHECK (status IN ('submitted','screening','shortlisted')
        OR (decided_at IS NOT NULL AND outcome_note IS NOT NULL)),
  CONSTRAINT fk_schol_tenant FOREIGN KEY (tenant_id)      REFERENCES tenants(id),
  CONSTRAINT fk_schol_app    FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE SET NULL,
  CONSTRAINT fk_schol_member FOREIGN KEY (membership_id)  REFERENCES memberships(id) ON DELETE SET NULL,
  CONSTRAINT fk_schol_by     FOREIGN KEY (decided_by)     REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A recorded Auditer placement: the academy's judgement of where a learner
-- should start. It is ADVICE with an author and a date, not a score — and it
-- expires, because a placement taken two years ago says nothing about a
-- teenager now.
CREATE TABLE auditer_results (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  membership_id BIGINT UNSIGNED NOT NULL,
  application_id BIGINT UNSIGNED NULL,
  dimensions    JSON         NULL,
  recommended_programme_id BIGINT UNSIGNED NULL,
  recommended_level_id     BIGINT UNSIGNED NULL,
  recommended_pathway      VARCHAR(48) NULL,
  notes         TEXT         NULL,
  audited_by_membership_id BIGINT UNSIGNED NULL,
  audited_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  valid_until   DATE         NULL,
  UNIQUE KEY uq_auditer_public (public_id),
  KEY ix_auditer_member (tenant_id, membership_id, audited_at),
  CONSTRAINT fk_auditer_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_auditer_member FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE CASCADE,
  CONSTRAINT fk_auditer_app    FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE SET NULL,
  CONSTRAINT fk_auditer_prog   FOREIGN KEY (recommended_programme_id) REFERENCES programmes(id) ON DELETE SET NULL,
  CONSTRAINT fk_auditer_level  FOREIGN KEY (recommended_level_id) REFERENCES levels(id) ON DELETE SET NULL,
  CONSTRAINT fk_auditer_by     FOREIGN KEY (audited_by_membership_id) REFERENCES memberships(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Newsletter subscribers. A subscriber is a DATA SUBJECT, not a member, which is
-- why this is its own table and not a `consents` row: `consents` requires a
-- membership, and creating one would put marketing contacts into the academy's
-- roster and into every report that counts learners.
--
-- Unsubscribing is a FACT, recorded — not a delete. Proving the academy stopped
-- contacting somebody requires the row that says when it stopped, and deleting
-- it also loses the suppression list, so the next import mails them again.
CREATE TABLE marketing_contacts (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  email         VARCHAR(320) NOT NULL,
  name          VARCHAR(190) NULL,
  status        VARCHAR(16)  NOT NULL DEFAULT 'subscribed',
     -- subscribed|unsubscribed|bounced|suppressed
  -- The provenance of the consent, which is the whole reason this table can be
  -- carried across a migration at all: where they signed up, when, and what
  -- they were shown.
  source        VARCHAR(48)  NULL,
  consent_at    DATETIME(3)  NULL,
  consent_evidence JSON      NULL,
  unsubscribed_at DATETIME(3) NULL,
  bounced_at    DATETIME(3)  NULL,
  membership_id BIGINT UNSIGNED NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_mkt_public (public_id),
  UNIQUE KEY uq_mkt_email (tenant_id, email),
  KEY ix_mkt_status (tenant_id, status),
  CONSTRAINT chk_mkt_status CHECK (status IN ('subscribed','unsubscribed','bounced','suppressed')),
  -- An unsubscribe states WHEN. Without the date the row cannot prove the
  -- academy stopped, which is the only thing it is for.
  CONSTRAINT chk_mkt_unsub CHECK (status <> 'unsubscribed' OR unsubscribed_at IS NOT NULL),
  CONSTRAINT fk_mkt_tenant FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  CONSTRAINT fk_mkt_member FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A time-limited discount.
--
-- ⚠️ This is SPLIT OUT of v1's `promo_banners` deliberately. There, one row held
-- the banner's title, body and background colour AND a `discount_percent` that
-- `validation.php` and `store.php` read when pricing tuition and orders — so
-- editing a banner's colour and editing what every family pays were the same
-- operation on the same row, and "why was this invoice cheaper" was answered by
-- a marketing record somebody had since overwritten. The price lives here, with
-- a window and an audit trail; the copy lives in `announcement_banners`.
--
-- An ISSUED invoice stores its own totals (LAYER F), so changing or ending a
-- promotion can never restate what a family was already asked for.
CREATE TABLE promotions (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  code          VARCHAR(48)  NULL,          -- NULL = automatic, no code to type
  name          VARCHAR(190) NOT NULL,
  applies_to    VARCHAR(24)  NOT NULL DEFAULT 'tuition',   -- tuition|store|registration|all
  discount_pct  DECIMAL(5,2) NOT NULL DEFAULT 0,
  max_discount_minor BIGINT  NULL,
  currency      CHAR(3)      NULL,
  starts_at     DATETIME(3)  NULL,
  ends_at       DATETIME(3)  NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by    BIGINT UNSIGNED NULL,
  updated_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  row_version   INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_promo_public (public_id),
  UNIQUE KEY uq_promo_code (tenant_id, code),
  KEY ix_promo_window (tenant_id, is_active, starts_at, ends_at),
  CONSTRAINT chk_promo_applies CHECK (applies_to IN ('tuition','store','registration','all')),
  -- A discount is a percentage of something, between nothing and everything. A
  -- negative one is a surcharge nobody agreed to; one above 100 is a refund.
  CONSTRAINT chk_promo_pct CHECK (discount_pct >= 0 AND discount_pct <= 100),
  CONSTRAINT chk_promo_cap CHECK (max_discount_minor IS NULL
        OR (max_discount_minor >= 0 AND currency IS NOT NULL)),
  CONSTRAINT chk_promo_active CHECK (is_active IN (0,1)),
  CONSTRAINT chk_promo_window CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at >= starts_at),
  CONSTRAINT fk_promo_tenant FOREIGN KEY (tenant_id)  REFERENCES tenants(id),
  CONSTRAINT fk_promo_by     FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The banner itself: copy, colour and a link. It may POINT AT a promotion so the
-- headline and the price cannot drift apart, but it cannot set one.
CREATE TABLE announcement_banners (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id     CHAR(26)     NOT NULL,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  promotion_id  BIGINT UNSIGNED NULL,
  title         VARCHAR(190) NOT NULL,
  body          VARCHAR(1000) NOT NULL DEFAULT '',
  cta_label     VARCHAR(64)  NULL,
  cta_url       VARCHAR(1000) NULL,
  -- A token name, not a hex value: a colour typed into a marketing form is how a
  -- banner ends up with 2:1 contrast against its own text. The palette is the
  -- design system's, and the admin picks from it.
  tone          VARCHAR(24)  NOT NULL DEFAULT 'notice',   -- notice|warning|success|brand
  audience      VARCHAR(16)  NOT NULL DEFAULT 'public',   -- public|learners|guardians|staff
  starts_at     DATETIME(3)  NULL,
  ends_at       DATETIME(3)  NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_banner_public (public_id),
  KEY ix_banner_window (tenant_id, is_active, starts_at, ends_at),
  CONSTRAINT chk_banner_tone CHECK (tone IN ('notice','warning','success','brand')),
  CONSTRAINT chk_banner_audience CHECK (audience IN ('public','learners','guardians','staff')),
  CONSTRAINT chk_banner_active CHECK (is_active IN (0,1)),
  CONSTRAINT chk_banner_window CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at >= starts_at),
  -- A call to action needs both halves. A labelled button with no destination is
  -- a dead end on the academy's front page.
  CONSTRAINT chk_banner_cta CHECK ((cta_label IS NULL) = (cta_url IS NULL)),
  CONSTRAINT fk_banner_tenant FOREIGN KEY (tenant_id)    REFERENCES tenants(id),
  CONSTRAINT fk_banner_promo  FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schema baseline. A fresh v2 install starts AT the target rather than
-- replaying v1's 81 migrations.
CREATE TABLE schema_version (
  version     INT NOT NULL PRIMARY KEY,
  applied_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  note        VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_version (version, note) VALUES (204,
  'schema-v2 baseline + guardian layer + assessment + teaching operations + communications');

-- Seed the currencies the platform ships knowing about. Everything else is
-- tenant data and arrives via the ETL or the admin.
INSERT INTO currencies (code, name, exponent, symbol) VALUES
  ('USD','US Dollar',2,'$'),
  ('NGN','Nigerian Naira',2,'₦'),
  ('GBP','Pound Sterling',2,'£'),
  ('EUR','Euro',2,'€'),
  ('JPY','Japanese Yen',0,'¥');
