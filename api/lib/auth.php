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

    if ($expectedUser && $expectedHash && $user
        && hash_equals($expectedUser, $user)
        && password_verify($pass, $expectedHash)) {
        return;
    }

    if (!$expectedUser || !$expectedHash) {
        mam_fail('admin_not_configured', 503);
    }

    header('WWW-Authenticate: Basic realm="MAM Academy Admin"');
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
