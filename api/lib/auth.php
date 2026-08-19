<?php
/**
 * Authentication & authorization.
 *
 * Three roles: admin, instructor, student.
 * Two ways to be admin:
 *   1. Logged-in user with role='admin' (session cookie)
 *   2. HTTP Basic Auth using ADMIN_USER / ADMIN_PASSWORD_HASH (env)
 *      — kept as a break-glass path before any users exist.
 */
declare(strict_types=1);

/** How many user accounts exist (0 ⇒ fresh install, setup not yet run). */
function mam_user_count(): int {
    try {
        $r = mam_db_row('SELECT COUNT(*) AS c FROM users');
        return (int)($r['c'] ?? 0);
    } catch (Throwable $e) { return 0; }
}

/**
 * Create or update an administrator account (idempotent by email). Used by the
 * first-run installer (/api/setup), the CLI (api/bin/create-admin.php) and the
 * env auto-provisioner. Returns ['id','email','created'].
 * Throws InvalidArgumentException on a bad email or a password under 8 chars.
 */
function mam_create_admin(string $email, string $password, string $name = 'Administrator'): array {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('invalid_email');
    if (strlen($password) < 8)                      throw new InvalidArgumentException('weak_password');
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $now  = date('Y-m-d H:i:s');
    $existing = mam_db_row('SELECT id FROM users WHERE email = ?', [$email]);
    if ($existing) {
        mam_db_update('users', [
            'password_hash' => $hash, 'role' => 'admin', 'status' => 'active',
            'name' => $name, 'must_change_password' => 0, 'updated_at' => $now,
        ], ['id' => (int)$existing['id']]);
        mam_user_ensure_role((int)$existing['id'], 'admin');
        return ['id' => (int)$existing['id'], 'email' => $email, 'created' => false];
    }
    $id = mam_db_insert('users', [
        'email' => $email, 'password_hash' => $hash, 'role' => 'admin',
        'name' => $name, 'status' => 'active', 'must_change_password' => 0,
    ]);
    mam_user_ensure_role($id, 'admin');
    return ['id' => $id, 'email' => $email, 'created' => true];
}

/**
 * Auto-provision the first admin from env on a fresh install, so a correctly
 * configured deploy can log in immediately. No-op once any user exists. Needs
 * ADMIN_EMAIL plus ADMIN_PASSWORD or ADMIN_PASSWORD_HASH.
 */
function mam_maybe_seed_admin_from_env(): void {
    if (mam_user_count() > 0) return;
    $email = (string)mam_env('ADMIN_EMAIL', '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
    $hash  = (string)mam_env('ADMIN_PASSWORD_HASH', '');
    $plain = (string)mam_env('ADMIN_PASSWORD', '');
    try {
        if ($hash !== '') {
            mam_db_insert('users', [
                'email' => strtolower($email), 'password_hash' => $hash, 'role' => 'admin',
                'name' => (string)mam_env('ADMIN_NAME', 'Administrator'), 'status' => 'active',
            ]);
        } elseif (strlen($plain) >= 8) {
            mam_create_admin($email, $plain, (string)mam_env('ADMIN_NAME', 'Administrator'));
        } else { return; }
        mam_log('info', 'admin_seeded_from_env', ['email' => strtolower($email)]);
    } catch (Throwable $e) {
        mam_log('warn', 'admin_seed_failed', ['err' => $e->getMessage()]);
    }
}

function mam_login(string $email, string $password, bool $remember = true): ?array {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
    $u = mam_db_row("SELECT * FROM users WHERE email = ? AND status = 'active'", [$email]);
    if (!$u) return null;
    if (!password_verify($password, $u['password_hash'])) return null;
    // Rehash if algorithm has been upgraded
    if (password_needs_rehash($u['password_hash'], PASSWORD_BCRYPT)) {
        try {
            mam_db_update('users', ['password_hash' => password_hash($password, PASSWORD_BCRYPT)], ['id' => (int)$u['id']]);
        } catch (Throwable $e) {}
    }
    mam_db_update('users', ['last_login' => date('Y-m-d H:i:s')], ['id' => (int)$u['id']]);
    mam_session_start_cookie((int)$u['id'], $remember);
    mam_audit('login', 'user', (int)$u['id'], ['email' => $email, 'remember' => $remember]);
    return $u;
}

function mam_current_user(): ?array {
    return mam_session_user();
}

function mam_require_user(): array {
    $u = mam_current_user();
    if (!$u) mam_fail('authentication_required', 401);
    return $u;
}

/**
 * All roles a user holds. Reads the v16 `user_roles` join (multi-role: a user
 * can be student + instructor + admin at once) and always folds in the legacy
 * primary `users.role`. Degrades gracefully if the table isn't there yet.
 */
function mam_user_roles(array $u): array {
    $roles = [];
    if (!empty($u['role'])) $roles[] = (string)$u['role'];
    try {
        foreach (mam_db_all('SELECT role FROM user_roles WHERE user_id = ?', [(int)($u['id'] ?? 0)]) as $r) {
            $roles[] = (string)$r['role'];
        }
    } catch (Throwable $e) { /* table not migrated yet — primary role still applies */ }
    return array_values(array_unique($roles));
}

/** True if the user holds ANY of $roles (across primary + user_roles). */
function mam_user_has_role(array $u, string ...$roles): bool {
    return (bool)array_intersect($roles, mam_user_roles($u));
}

/** Is this user a scholarship (sponsored) student? NULL/'' student_type = paying. */
function mam_user_is_scholarship(array $u): bool {
    return strtolower(trim((string)($u['student_type'] ?? ''))) === 'scholarship';
}

/** Idempotently grant a role in the multi-role join (portable across SQLite +
 *  MySQL). Keeps user_roles in step with the primary users.role at creation. */
function mam_user_ensure_role(int $userId, string $role): void {
    if ($userId <= 0 || $role === '') return;
    try {
        if (!mam_db_row('SELECT 1 FROM user_roles WHERE user_id = ? AND role = ?', [$userId, $role])) {
            mam_db_insert('user_roles', ['user_id' => $userId, 'role' => $role]);
        }
    } catch (Throwable $e) { /* table not migrated yet — primary role still applies */ }
}

/** Remove a role from the multi-role join. Never touches the primary users.role
 *  (that's edited separately), so this only revokes a *secondary* grant. */
function mam_user_remove_role(int $userId, string $role): void {
    if ($userId <= 0 || $role === '') return;
    try {
        mam_db()->prepare('DELETE FROM user_roles WHERE user_id = ? AND role = ?')->execute([$userId, $role]);
    } catch (Throwable $e) { /* table not migrated yet — nothing to revoke */ }
}

/**
 * The portal destinations a user can reach, one per distinct surface, in
 * priority order. A user holding both student + instructor roles gets two
 * entries → the caller shows a chooser. Admin maps to the admin dashboard.
 * @return array<int,array{role:string,label:string,url:string}>
 */
function mam_portal_targets(array $roles): array {
    $map = [
        'admin'      => ['role' => 'admin',      'label' => 'Admin dashboard',   'url' => '/admin/'],
        'instructor' => ['role' => 'instructor', 'label' => 'Instructor portal', 'url' => '/portal/instructor/'],
        'student'    => ['role' => 'student',    'label' => 'Student portal',     'url' => '/portal/student/'],
        'parent'     => ['role' => 'parent',     'label' => 'Parent portal',      'url' => '/portal/parent/'],
    ];
    $out = [];
    foreach ($map as $role => $t) {
        if (in_array($role, $roles, true)) $out[] = $t;
    }
    return $out;
}

/** Where to send a user after login: their single portal, or the chooser when
 *  they can reach more than one (e.g. someone who is both student + instructor). */
function mam_post_login_redirect(array $roles): string {
    $targets = mam_portal_targets($roles);
    if (count($targets) > 1) return '/portal/choose';
    return $targets[0]['url'] ?? '/portal/student/';
}

function mam_require_role(string ...$roles): array {
    $u = mam_require_user();
    if (!mam_user_has_role($u, ...$roles)) mam_fail('forbidden', 403);
    return $u;
}

/* ───────────────────────── Admin access tiers (RBAC) ─────────────────────
   An admin is 'super' (full control) or 'manager' (operational only). A NULL/
   blank tier on an admin defaults to 'super', and existing admins were
   backfilled to 'super', so nobody loses access; new limited admins are set to
   'manager'. Capabilities map to a minimum tier. */

/** An admin's tier: 'super' | 'manager'. Non-admins → 'none'. NULL → 'super'. */
function mam_admin_tier(array $u): string {
    if (!mam_user_has_role($u, 'admin')) return 'none';
    return ((string)($u['admin_tier'] ?? '') === 'manager') ? 'manager' : 'super';
}

/** Capability → minimum admin tier. Manager gets operational capabilities;
 *  'super' is reserved for sensitive control. Unknown caps default to super. */
function mam_admin_capabilities(): array {
    return [
        'view'          => 'manager',  // dashboards + read-only lists
        'enrollments'   => 'manager',  // approve / assign course registrations
        'media'         => 'manager',  // media & documents library (class resources, photos)
        'admissions'    => 'manager',  // work the applications pipeline
        'messages'      => 'manager',  // contact messages
        'store_orders'  => 'manager',  // fulfil store orders
        'users'         => 'super',    // create / reset / delete users, roles, tiers
        'settings'      => 'super',    // pricing & settings
        'store_catalog' => 'super',    // store item CRUD
        'curriculum'    => 'super',    // author the standard curriculum (modules) + import/export
        'mail'          => 'super',    // mail test / retry
        'migrate'       => 'super',    // run DB migrations
        'rbac'          => 'super',    // assign admin tiers
        'support'       => 'manager',  // feedback, surveys, support tickets
        'content'       => 'super',    // survey-form templates, session-report forms
    ];
}

/** Whether an admin user holds a capability (super → all; manager → operational). */
function mam_admin_can(array $u, string $cap): bool {
    if (!mam_user_has_role($u, 'admin')) return false;
    if (mam_admin_tier($u) === 'super') return true;
    return (mam_admin_capabilities()[$cap] ?? 'super') === 'manager';
}

/**
 * Guard: the request must be from an admin holding $cap, else 403. Preserves the
 * Basic-Auth break-glass (a host-level admin with no session user is treated as
 * super); only restricts SESSION admins who are managers.
 */
function mam_require_admin_cap(string $cap): void {
    mam_require_admin(); // session admin OR Basic-Auth break-glass, else 401/403
    $u = mam_current_user();
    if ($u && mam_user_has_role($u, 'admin') && !mam_admin_can($u, $cap)) mam_fail('forbidden', 403);
}

/**
 * The admin-access decision, as a pure function of the three things that
 * decide it. Returns 'allow' | 'forbidden' | 'authenticate'.
 *
 * Split out from mam_require_admin() so the decision table can be tested
 * directly: mam_session_user() memoises per process, so a test cannot swap
 * identities in-process to exercise the branches through the guard itself.
 *
 *   $isAdmin      — a session user holding the admin role
 *   $isSignedIn   — any session user at all
 *   $basicOk      — valid Basic-Auth break-glass credentials were supplied
 *
 * The ordering matters. `$basicOk` is honoured before the signed-in check so
 * the break-glass still works when a stale non-admin session is present.
 */
function mam_admin_access_outcome(bool $isAdmin, bool $isSignedIn, bool $basicOk): string {
    if ($isAdmin || $basicOk) return 'allow';
    // Signed in but not an admin: a permanent AUTHORISATION failure. Asking
    // them to authenticate again would be a loop, and reporting a server
    // misconfiguration would be a lie.
    if ($isSignedIn) return 'forbidden';
    return 'authenticate';
}

/**
 * The recovery identity. Address is under the RFC 2606 `.invalid` TLD, which is
 * guaranteed never to resolve, so nothing can ever deliver mail to it.
 */
const MAM_RECOVERY_EMAIL = 'break-glass@mam.invalid';

/**
 * Was THIS request authorised by the Basic-Auth break-glass rather than a
 * session? Set once by mam_require_admin(); read by mam_admin_actor().
 * Per-request state, so it cannot leak between callers.
 */
function mam_break_glass_active(?bool $set = null): bool {
    static $active = false;
    if ($set !== null) $active = $set;
    return $active;
}

/**
 * The users row that break-glass actions are attributed to, created on first
 * use. This exists so a recovery-credential action can be WRITTEN down: the
 * ledger columns it lands in (`resolved_by`, `created_by`, a ticket reply's
 * `author_id`) are non-nullable foreign keys into users(id), so without a real
 * row the only options were 0 — which violates the constraint — or NULL, which
 * erases who acted (CLAUDE.md gotcha #3).
 *
 * It is deliberately NOT a usable account. `status = 'system'` is rejected by
 * every path that requires 'active': mam_login(), mam_session_user() and the
 * password-reset lookup. It also carries a bcrypt hash of 32 random bytes that
 * is never stored anywhere, so even if some future code path forgot the status
 * gate there is no password that verifies against it.
 *
 * It is left VISIBLE in the users list on purpose. A hidden admin account is a
 * security smell; an operator should be able to see that this identity exists
 * and what it has done.
 */
function mam_admin_recovery_user(): array {
    $row = mam_db_row('SELECT * FROM users WHERE email = ?', [MAM_RECOVERY_EMAIL]);
    if ($row) return $row;
    try {
        mam_db_insert('users', [
            'email'         => MAM_RECOVERY_EMAIL,
            'name'          => 'Break-glass recovery',
            'role'          => 'admin',
            'status'        => 'system',
            'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT),
        ]);
        mam_audit('break_glass_identity_created', 'user', null, ['email' => MAM_RECOVERY_EMAIL]);
    } catch (\Throwable $e) {
        // Lost the race against a concurrent request — the unique email means
        // the other one won, so re-read rather than failing the request.
    }
    $row = mam_db_row('SELECT * FROM users WHERE email = ?', [MAM_RECOVERY_EMAIL]);
    if (!$row) throw new RuntimeException('recovery_user_unavailable');
    return $row;
}

/**
 * The admin behind this request: the signed-in user, or — when the caller came
 * through the Basic-Auth break-glass — the recovery identity above.
 *
 * Routes gated by mw_admin_cap() are already authorised by the time they run,
 * so they do NOT need to re-check the role; doing so would only add a
 * mam_require_user() that 401s a break-glass operator. What they still need is
 * an identity to attribute the action to, which is what this returns.
 */
function mam_admin_actor(): ?array {
    $u = mam_current_user();
    if ($u && mam_user_has_role($u, 'admin')) return $u;
    if (mam_break_glass_active()) return mam_admin_recovery_user();
    return null;
}

/**
 * The admin actor, REQUIRED — for a write that records who performed it.
 *
 * Always resolves for an authorised caller: a session admin, or the recovery
 * identity when the break-glass was used. The failure below is therefore a
 * belt-and-braces guard for a route that somehow reached a write without
 * passing mw_admin_cap() at all — it should be unreachable in practice, and it
 * fails closed rather than writing an unattributed row.
 */
function mam_admin_require_actor(): array {
    $u = mam_admin_actor();
    if (!$u) {
        mam_fail('admin_session_required', 403, [
            'detail' => 'This action records who performed it, so it needs an admin identity.',
        ]);
    }
    return $u;
}

/**
 * Admin guard accepted by both portals AND the legacy admin dashboard.
 * Prefers a session user holding the admin role — primary users.role OR the
 * multi-role user_roles table, so a secondary-admin isn't locked out — and
 * falls back to env-configured Basic Auth.
 */
function mam_require_admin(): void {
    $u = mam_current_user();
    if ($u && mam_user_has_role($u, 'admin')) return;

    // Fallback: HTTP Basic Auth. On cPanel / LiteSpeed / many shared
    // hosts the `Authorization` header is stripped before PHP sees it
    // (PHP-FPM behind FastCGI strips it for security), so PHP_AUTH_USER
    // / PHP_AUTH_PW arrive empty even when the browser is sending
    // correct credentials. We probe every variant the host may use,
    // and as a last resort decode the raw `Authorization: Basic …` header
    // ourselves (the .htaccess RewriteRule below forwards it as
    // HTTP_AUTHORIZATION). One of these MUST work — without it, the
    // browser re-prompts forever.
    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $pass = $_SERVER['PHP_AUTH_PW']   ?? '';
    if ($user === '') {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION']
                  ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                  ?? $_SERVER['REMOTE_USER']
                  ?? '';
        if (is_string($authHeader) && stripos($authHeader, 'basic ') === 0) {
            $decoded = base64_decode(substr($authHeader, 6), true);
            if (is_string($decoded) && strpos($decoded, ':') !== false) {
                [$user, $pass] = explode(':', $decoded, 2);
            }
        }
    }
    $expectedUser = mam_env('ADMIN_USER', '');
    $expectedHash = mam_env('ADMIN_PASSWORD_HASH', '');

    $basicOk = (bool) ($expectedUser && $expectedHash && $user
        && hash_equals($expectedUser, $user)
        && password_verify($pass, $expectedHash));

    // A caller who IS signed in, just not as an admin, has failed an
    // AUTHORISATION check — not an authentication one, and certainly not a
    // server misconfiguration. Answering 503 'admin_not_configured' here told a
    // logged-in student the server was broken, and told an API client to retry
    // later on what is a permanent refusal.
    switch (mam_admin_access_outcome(false, (bool) $u, $basicOk)) {
        case 'allow':
            // Remember HOW this request was authorised, so a write can attribute
            // itself to the recovery identity rather than to nobody.
            mam_break_glass_active(true);
            return;
        case 'forbidden': mam_fail('forbidden', 403);
    }

    // Nobody is signed in. Ask them to authenticate. Whether the break-glass
    // account happens to be configured is an operator's concern (surfaced by
    // /api/diag and by the admin page itself), not something to disclose to an
    // anonymous caller — so both paths answer 401.
    if ($expectedUser && $expectedHash) {
        header('WWW-Authenticate: Basic realm="MAM Academy Admin"');
    }
    mam_fail('authentication_required', 401);
}

/** Ensure (or create) a student user record linked to a customer. Returns user row + optional plaintext password (only on creation). */
function mam_ensure_student_user(int $customerId): array {
    $cust = mam_db_row('SELECT * FROM customers WHERE id = ?', [$customerId]);
    if (!$cust) throw new RuntimeException('customer_not_found');

    // The applicant's headshot (captured at application) becomes their portal
    // avatar automatically — so a student's photo shows in the portal without any
    // extra step. Editable later via POST /api/student/avatar.
    $shot = mam_db_row(
        "SELECT headshot_url FROM applications WHERE customer_id = ? AND headshot_url IS NOT NULL AND headshot_url <> '' ORDER BY id DESC LIMIT 1",
        [$customerId]
    );
    $avatar = $shot ? (string)$shot['headshot_url'] : null;

    $existing = mam_db_row('SELECT * FROM users WHERE email = ?', [$cust['email']]);
    if ($existing) {
        $patch = [];
        if (!$existing['customer_id']) $patch['customer_id'] = $customerId;
        // Backfill the avatar if the account never had one and a headshot exists.
        if ($avatar && empty($existing['avatar_url'])) $patch['avatar_url'] = $avatar;
        if ($patch) { mam_db_update('users', $patch, ['id' => (int)$existing['id']]); $existing = mam_db_row('SELECT * FROM users WHERE id = ?', [(int)$existing['id']]); }
        return ['user' => $existing, 'password' => null];
    }

    $plain = bin2hex(random_bytes(5)); // 10-char temp password
    $userId = mam_db_insert('users', [
        'email'         => $cust['email'],
        'password_hash' => password_hash($plain, PASSWORD_BCRYPT),
        'role'          => 'student',
        'name'          => trim(($cust['first_name'] ?? '') . ' ' . ($cust['last_name'] ?? '')),
        'phone'         => $cust['phone'],
        'customer_id'   => $customerId,
        'avatar_url'    => $avatar,
        'must_change_password' => 1,
    ]);
    $user = mam_db_row('SELECT * FROM users WHERE id = ?', [$userId]);
    mam_audit('create', 'user', $userId, ['role' => 'student', 'auto' => true]);
    return ['user' => $user, 'password' => $plain];
}

/**
 * ── Account invitations: a link to CREATE a password, not a password ──────────
 *
 * The academy's position, and the better one: on enrolment a family receives a
 * link to set their own password, never a temporary password in an email.
 *
 * Why it matters more here than the change sounds. A temporary password sits in
 * an inbox for years, is typically reused, is readable by anyone with the phone,
 * and cannot be revoked once sent — and for a children's academy the inbox is
 * usually a parent's, shared. A single-use token expires, can be re-issued, and
 * proves control of the address before an account becomes usable. It also removes
 * the worst habit the old flow taught: click a link in unexpected mail and type a
 * password you were sent.
 *
 * `password_resets` is reused deliberately rather than growing an `invites`
 * table. It is already exactly this: a single-use, hashed, expiring token that
 * sets a password and revokes other sessions. A second table would mean two
 * expiry rules, two revocation paths and two places to get the hashing wrong.
 * The one difference is the WINDOW — see below.
 */

/**
 * How long an invitation stays valid.
 *
 * A password RESET is answered in minutes and an hour is right for it. An
 * INVITATION is opened when a parent next looks at their phone, which may be the
 * following evening or after a weekend — and an expired invitation on enrolment
 * day means a family that cannot get in and an admin who has to notice. Seven
 * days, and it can be re-issued.
 */
const MAM_INVITE_TTL_SEC = 604800;   // 7 days

/**
 * Mint a single-use invitation for an account and return the link.
 *
 * Returns null when the user cannot be invited — no such row, or not active.
 * Never throws: an invitation is a courtesy on top of a provisioning step that
 * has already succeeded, and a failure to mint one must not roll back an
 * enrolment that really happened.
 */
function mam_auth_invite(int $userId, int $ttl = MAM_INVITE_TTL_SEC): ?array {
    if ($userId <= 0) return null;
    $u = mam_db_row("SELECT id, email, name, status FROM users WHERE id = ?", [$userId]);
    // Only an active account. Inviting a suspended or closed one would hand out
    // a working credential for an account somebody deliberately shut.
    if (!$u || (string) $u['status'] !== 'active') return null;

    try {
        $token = bin2hex(random_bytes(20));
        mam_db_insert('password_resets', [
            'token_hash' => hash('sha256', $token),
            'user_id'    => (int) $u['id'],
            'expires_at' => date('Y-m-d H:i:s', time() + max(600, $ttl)),
        ]);
    } catch (Throwable $e) {
        mam_log('warn', 'invite_mint_failed', ['user' => $userId, 'err' => $e->getMessage()]);
        return null;
    }

    $base = rtrim((string) mam_env('APP_URL', ''), '/');
    if ($base === '' && function_exists('mam_setting')) {
        $base = rtrim((string) mam_setting('site_url', 'https://academy.mamcentre.org'), '/');
    }
    return [
        'user_id'    => (int) $u['id'],
        'email'      => (string) $u['email'],
        'name'       => (string) ($u['name'] ?? ''),
        'token'      => $token,
        // `welcome=1` only changes what the page SAYS. The token is the whole of
        // the security, so a tampered flag gains nothing.
        'link'       => $base . '/portal/reset?token=' . $token . '&welcome=1',
        'expires_at' => date('Y-m-d H:i:s', time() + max(600, $ttl)),
        'ttl_days'   => (int) round(max(600, $ttl) / 86400),
    ];
}

/**
 * Mint an invitation and email it. Returns the invite, or null if either half
 * failed — and logs which, because "the family never got the link" is the
 * support question this produces and the answer has to be in the log.
 */
function mam_auth_send_invite(int $userId, string $role = 'student', string $context = 'account_invite'): ?array {
    $inv = mam_auth_invite($userId);
    if (!$inv) return null;
    if (!function_exists('mam_send') || !function_exists('mam_email_account_invite')) return $inv;
    try {
        $ok = mam_send($inv['email'], 'Set up your MAM Academy account',
            mam_email_account_invite($inv['name'], $inv['link'], $role, $inv['ttl_days']), $context);
        if (!$ok) mam_log('warn', 'invite_email_failed', ['user' => $userId, 'context' => $context]);
    } catch (Throwable $e) {
        mam_log('warn', 'invite_email_failed', ['user' => $userId, 'err' => $e->getMessage()]);
    }
    mam_audit('account_invite', 'user', $userId, ['context' => $context, 'role' => $role]);
    return $inv;
}

/**
 * A password nobody is told.
 *
 * An account awaiting its invitation still needs `password_hash` to be NOT NULL
 * and, more importantly, must not be guessable. This writes 32 random bytes
 * hashed with bcrypt: there is a valid hash, no plaintext exists anywhere, and
 * the only way in is the invitation. The same construction as the break-glass
 * recovery row, for the same reason.
 */
function mam_auth_unusable_password(): string {
    return password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
}
