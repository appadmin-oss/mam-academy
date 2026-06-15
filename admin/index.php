<?php
/**
 * MAM Academy — Admin Dashboard
 * Single-file PHP admin protected by HTTP Basic Auth (env credentials)
 * or by a logged-in admin user (session cookie).
 *
 * Views:
 *   /admin/                — Overview KPIs + recent applications
 *   /admin/?v=applications — Applications: status transitions + auto-provision
 *   /admin/?v=payments     — Payments + verification status
 *   /admin/?v=users        — Users (admin / instructor / student) + create + reset
 *   /admin/?v=classes      — Classes + create
 *   /admin/?v=enrollments  — Enrollments + enroll-student form
 *   /admin/?v=messages     — Contact messages
 *   /admin/?v=webhooks     — Webhook event log
 *   /admin/?v=newsletter   — Newsletter subscribers
 *   /admin/?v=audit        — Audit log
 *   /admin/?v=export&type=applications.csv
 */
declare(strict_types=1);

require_once __DIR__ . '/../api/lib/bootstrap.php';

// Auth for the HTML dashboard. A logged-in admin (session) passes straight
// through. Otherwise fall back to env Basic Auth (break-glass) when configured;
// if neither is available, send the visitor to the portal login rather than
// dumping a raw JSON error — a far friendlier first-run experience.
$adminUser = mam_current_user();
// Multi-role aware: admin may be the primary users.role OR granted via the
// user_roles join table — both must open the dashboard.
if (!($adminUser && mam_user_has_role($adminUser, 'admin'))) {
    if (mam_env('ADMIN_USER', '') !== '' && mam_env('ADMIN_PASSWORD_HASH', '') !== '') {
        mam_require_admin(); // HTTP Basic Auth challenge/verify
    } else {
        $next = rawurlencode('/admin/');
        header('Location: /portal/login?next=' . $next);
        if (!defined('MAM_RESPONSE_SENT')) define('MAM_RESPONSE_SENT', true);
        exit;
    }
}

// Stop the JSON content type set by bootstrap and serve HTML for views
header_remove('Content-Type');
header('Content-Type: text/html; charset=utf-8');
// This is a server-rendered HTML page, not a JSON route — tell the bootstrap
// shutdown handler so it doesn't append a {"error":"no_response"} envelope
// after our markup (which also truncated some views under output buffering).
if (!defined('MAM_RESPONSE_SENT')) define('MAM_RESPONSE_SENT', true);

$view  = $_GET['v'] ?? 'overview';
$flash = null;

// A session admin on a temporary password must set a new one before using the
// dashboard. Sends them to My Account (GET only, so the change POST still runs).
if (isset($adminUser) && $adminUser && (int)($adminUser['must_change_password'] ?? 0) === 1
    && $view !== 'account' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Location: ?v=account'); exit;
}

/** Compose a name out of customer first/last (portable across SQLite + MySQL). */
function mam_name(?string $first, ?string $last): string {
    $n = trim((string)$first . ' ' . (string)$last);
    return $n === '' ? '—' : $n;
}

/**
 * Never-throw helpers for the overview's read-only aggregates. A deployment
 * whose DB is behind on migrations is missing some tables; without these, the
 * unconditional KPI queries below would 500 the ENTIRE admin dashboard — and
 * with it the "Run migrations now" button that fixes the problem. Degrading to
 * zero/empty keeps the dashboard (and its schema-behind banner) reachable.
 */
function mam_admin_scalar(string $sql, $fallback = 0) {
    try { return mam_db()->query($sql)->fetchColumn(); }
    catch (Throwable $e) { return $fallback; }
}
function mam_admin_rows(string $sql, array $args = []): array {
    try { return mam_db_all($sql, $args); }
    catch (Throwable $e) { return []; }
}

/* ─── CSV export (no state change) ─── */
if ($view === 'export') {
    $type = $_GET['type'] ?? 'applications.csv';
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '', basename($type));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    if (str_starts_with($filename, 'applications')) {
        fputcsv($out, ['reference', 'name', 'email', 'phone', 'country', 'pathway', 'mode', 'discipline', 'age', 'total_usd', 'status', 'created_at']);
        foreach (mam_db_all('SELECT a.reference, c.first_name, c.last_name, c.email, c.phone, c.country, a.pathway, a.mode, a.discipline, c.age_range AS age, a.total_usd, a.status, a.created_at FROM applications a LEFT JOIN customers c ON c.id = a.customer_id ORDER BY a.created_at DESC') as $r) {
            fputcsv($out, [$r['reference'], mam_name($r['first_name'] ?? null, $r['last_name'] ?? null), $r['email'], $r['phone'], $r['country'], $r['pathway'], $r['mode'], $r['discipline'], $r['age'], $r['total_usd'], $r['status'], $r['created_at']]);
        }
    } elseif (str_starts_with($filename, 'payments')) {
        fputcsv($out, ['reference', 'provider', 'status', 'amount', 'currency', 'verified_at', 'created_at']);
        foreach (mam_db_all('SELECT reference, provider, status, amount, currency, verified_at, created_at FROM payments ORDER BY created_at DESC') as $r) fputcsv($out, $r);
    } elseif (str_starts_with($filename, 'newsletter')) {
        fputcsv($out, ['email', 'source', 'status', 'created_at']);
        foreach (mam_db_all('SELECT email, source, status, created_at FROM newsletter ORDER BY created_at DESC') as $r) fputcsv($out, $r);
    } elseif (str_starts_with($filename, 'messages')) {
        fputcsv($out, ['id', 'name', 'email', 'phone', 'topic', 'message', 'status', 'created_at']);
        foreach (mam_db_all('SELECT id, name, email, phone, topic, message, status, created_at FROM messages ORDER BY created_at DESC') as $r) fputcsv($out, $r);
    } elseif (str_starts_with($filename, 'users')) {
        fputcsv($out, ['id', 'email', 'name', 'role', 'status', 'last_login', 'created_at']);
        foreach (mam_db_all('SELECT id, email, name, role, status, last_login, created_at FROM users ORDER BY created_at DESC') as $r) fputcsv($out, $r);
    }
    fclose($out);
    exit;
}

/* ─── State-changing actions: enforce CSRF + same-origin ─── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // Same-origin defence (in case the session cookie is the auth path
    // — Basic Auth wouldn't normally be sent cross-site, but be safe).
    $origin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    $host   = $_SERVER['HTTP_HOST'] ?? '';
    if ($origin !== '' && $host !== '' && parse_url($origin, PHP_URL_HOST) !== $host) {
        mam_fail('cross_origin_blocked', 403);
    }
    $csrf = $_POST['csrf_token'] ?? '';
    if (!mam_csrf_verify($csrf)) {
        mam_fail('csrf_invalid', 403);
    }

    $action = $_POST['action'] ?? '';

    // RBAC: gate sensitive actions to the right admin tier in one place.
    // Operational actions (course/enrolment/messages/orders/own-account) are
    // omitted → allowed for managers; everything here is super-only. Basic-Auth
    // admins (no $adminUser row) are the break-glass super and skip the check.
    $actionCaps = [
        'user_create' => 'users', 'user_reset' => 'users', 'user_status' => 'users',
        'user_delete' => 'users', 'user_edit' => 'users', 'set_admin_tier' => 'rbac',
        'save_settings' => 'settings',
        'store_item_save' => 'store_catalog', 'store_item_delete' => 'store_catalog',
        'payment_refund' => 'settings', 'payment_confirm_bank' => 'settings',
    ];
    if (isset($actionCaps[$action]) && $adminUser && !mam_admin_can($adminUser, $actionCaps[$action])) {
        header('Location: ?err=' . urlencode('Your admin level does not permit that action.')); exit;
    }

    // Set an admin's access tier (super-only — gated above via set_admin_tier).
    if ($action === 'set_admin_tier') {
        $uid  = (int)($_POST['user_id'] ?? 0);
        $tier = strtolower(trim((string)($_POST['tier'] ?? '')));
        $me   = mam_current_user();
        if ($uid <= 0 || !in_array($tier, ['super', 'manager'], true)) { header('Location: ?v=users&err=' . urlencode('Pick a valid tier.')); exit; }
        $target = mam_db_row('SELECT id, role FROM users WHERE id = ?', [$uid]);
        if (!$target || !mam_user_has_role($target, 'admin')) { header('Location: ?v=users&err=' . urlencode('That user is not an admin.')); exit; }
        if ($me && (int)$me['id'] === $uid && $tier === 'manager') { header('Location: ?v=users&err=' . urlencode('You can\'t demote your own access.')); exit; }
        mam_db_update('users', ['admin_tier' => $tier], ['id' => $uid]);
        mam_audit('set_admin_tier', 'user', $uid, ['tier' => $tier]);
        header('Location: ?v=users&ok=1'); exit;
    }

    // Self-service account: a session admin updates their own name/phone and,
    // optionally, their password. (Basic-Auth admins have no DB row — guarded.)
    if ($action === 'change_own_account') {
        $me = mam_current_user();
        if (!$me) { header('Location: ?v=account&err=' . urlencode('Sign in with a user account to manage it.')); exit; }
        $name  = trim((string)($_POST['name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $cur   = (string)($_POST['current'] ?? '');
        $next  = (string)($_POST['next'] ?? '');
        $upd = ['updated_at' => date('Y-m-d H:i:s'), 'phone' => $phone ?: null];
        if ($name !== '') $upd['name'] = $name;
        if ($next !== '' || $cur !== '') {
            if (strlen($next) < 8) { header('Location: ?v=account&err=' . urlencode('New password must be at least 8 characters.')); exit; }
            if (!password_verify($cur, (string)$me['password_hash'])) { header('Location: ?v=account&err=' . urlencode('Current password is incorrect.')); exit; }
            $upd['password_hash'] = password_hash($next, PASSWORD_BCRYPT);
            $upd['must_change_password'] = 0;
        }
        mam_db_update('users', $upd, ['id' => (int)$me['id']]);
        mam_audit('update', 'user', (int)$me['id'], ['self_account' => true, 'password_changed' => isset($upd['password_hash'])]);
        header('Location: ?v=account&ok=1'); exit;
    }

    if ($action === 'app_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        // Preserve the detail-card context when the change came from it.
        $back = ((int)($_POST['app'] ?? 0)) ? '&app=' . (int)$_POST['app'] : '';
        if ($id && in_array($status, ['submitted', 'in_review', 'accepted', 'waitlisted', 'paid', 'enrolled', 'cancelled'], true)) {
            // Route through the enforced, audited admissions state machine (A2).
            try {
                mam_admissions_transition($id, $status, ((int)($adminUser['id'] ?? 0)) ?: null, trim((string)($_POST['reason'] ?? '')) ?: null);
            } catch (RuntimeException $e) {
                header('Location: ?v=applications' . $back . '&err=' . urlencode($e->getMessage() === 'illegal_transition' ? "That status change isn't allowed from the current stage." : 'Application not found.'));
                exit;
            }

            // When an admin marks a bank-transfer (or any) application as paid
            // from the dashboard, mirror the verify-payment flow: provision a
            // student account and email them their credentials.
            if (in_array($status, ['paid', 'enrolled'], true)) {
                $app = mam_db_row('SELECT * FROM applications WHERE id = ?', [$id]);
                if ($app && $app['customer_id']) {
                    try {
                        $prov = mam_ensure_student_user((int)$app['customer_id']);
                        if ($prov['password']) {
                            $customer = mam_db_row('SELECT * FROM customers WHERE id = ?', [(int)$app['customer_id']]);
                            $loginUrl = rtrim((string)mam_env('APP_URL', ''), '/') . '/portal/login.html';
                            $name = htmlspecialchars(trim($customer['first_name'] . ' ' . $customer['last_name']), ENT_QUOTES);
                            $body = "<!doctype html><html><body style='background:#120D08;color:#fff;font-family:Arial,sans-serif;padding:24px'>"
                                  . "<table style='max-width:560px;margin:0 auto;background:#1A120B;border:1px solid #251A10;border-radius:18px;overflow:hidden'>"
                                  . "<tr><td style='background:linear-gradient(135deg,#E08A1A,#FBBF24);padding:24px;text-align:center'>"
                                  . "<div style='font-family:Impact,sans-serif;font-size:24px;color:#1A120B;font-weight:900;letter-spacing:.08em'>MAM ACADEMY</div></td></tr>"
                                  . "<tr><td style='padding:32px 28px;color:#FBF7F3;line-height:1.7'>"
                                  . "<h2 style='font-family:Georgia,serif;margin:0 0 12px;color:#fff'>Welcome, $name</h2>"
                                  . "<p>Your application has been confirmed. Sign in to your portal to track your classes and MAM Auditer placement.</p>"
                                  . "<div style='background:#251A10;border:1px dashed #FBBF24;border-radius:10px;padding:14px 18px;font-family:monospace;font-size:14px;color:#FBBF24;margin:16px 0'>"
                                  . "<div>Email: <strong>" . htmlspecialchars($customer['email']) . "</strong></div>"
                                  . "<div>Temporary password: <strong>" . htmlspecialchars($prov['password']) . "</strong></div></div>"
                                  . "<p>Please change this password on first sign-in.</p>"
                                  . "<p><a href='$loginUrl' style='display:inline-block;padding:12px 22px;background:linear-gradient(135deg,#E08A1A,#FBBF24);color:#1A120B;border-radius:8px;font-weight:800;text-decoration:none'>Open student portal →</a></p>"
                                  . "</td></tr></table></body></html>";
                            @mam_send_mail($customer['email'], 'Your MAM Academy student portal is ready', $body, true);
                        }
                    } catch (Throwable $e) {
                        mam_log('warn', 'admin_provision_failed', ['app_id' => $id, 'err' => $e->getMessage()]);
                    }
                }
            }
            $flash = ['ok', 'Application updated.'];
        }
        header('Location: ?v=applications' . $back . '&ok=1');
        exit;
    }

    // Admissions pipeline (A2): internal notes + assignee. Both are operational
    // (omitted from the super-only $actionCaps map) so admissions managers can
    // triage. actor/author ids fall back to null for break-glass Basic-Auth.
    if ($action === 'app_note') {
        $aid  = (int)($_POST['app_id'] ?? 0);
        $note = trim((string)($_POST['note'] ?? ''));
        if ($note === '') {
            header('Location: ?v=applications&app=' . $aid . '&err=' . urlencode('Note cannot be empty.')); exit;
        }
        try { mam_admissions_note($aid, (int)($adminUser['id'] ?? 0) ?: null, $note); }
        catch (Throwable $e) { mam_log('warn', 'app_note_failed', ['app_id' => $aid, 'err' => $e->getMessage()]); }
        header('Location: ?v=applications&app=' . $aid . '&ok=1'); exit;
    }
    if ($action === 'app_assign') {
        $aid = (int)($_POST['app_id'] ?? 0);
        $uid = (int)($_POST['assignee_id'] ?? 0);
        try { mam_admissions_assign($aid, $uid ?: null, (int)($adminUser['id'] ?? 0) ?: null); }
        catch (Throwable $e) { mam_log('warn', 'app_assign_failed', ['app_id' => $aid, 'err' => $e->getMessage()]); }
        header('Location: ?v=applications&app=' . $aid . '&ok=1'); exit;
    }

    // Clear the signed-in admin's notification inbox (operational; session only).
    if ($action === 'notif_read_all') {
        if ($adminUser && function_exists('mam_notifications_mark_all_read')) {
            try { mam_notifications_mark_all_read((int)$adminUser['id']); } catch (Throwable $e) {}
        }
        $to = preg_replace('/[^a-z]/', '', (string)($_POST['back'] ?? 'overview')) ?: 'overview';
        header('Location: ?v=' . $to); exit;
    }

    if ($action === 'msg_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'read';
        if ($id) mam_db_update('messages', ['status' => $status], ['id' => $id]);
        header('Location: ?v=messages&ok=1');
        exit;
    }

    if ($action === 'user_create') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $name  = trim((string)($_POST['name'] ?? ''));
        $role  = (string)($_POST['role'] ?? '');
        $phone = trim((string)($_POST['phone'] ?? '')) ?: null;
        $pass  = (string)($_POST['password'] ?? '');
        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'email';
        if ($name === '') $errors[] = 'name';
        if (!in_array($role, ['admin', 'instructor', 'student'], true)) $errors[] = 'role';
        if (strlen($pass) < 8) $errors[] = 'password';
        if ($errors) {
            $flash = ['err', 'Invalid: ' . implode(', ', $errors)];
        } elseif (mam_db_row('SELECT id FROM users WHERE email = ?', [$email])) {
            $flash = ['err', 'A user with that email already exists.'];
        } else {
            $uid = mam_db_insert('users', [
                'email' => $email,
                'name'  => $name,
                'phone' => $phone,
                'role'  => $role,
                'password_hash' => password_hash($pass, PASSWORD_BCRYPT),
                'must_change_password' => 1,
            ]);
            if ($role === 'instructor') {
                try { mam_db_insert('instructor_profiles', ['user_id' => $uid]); } catch (Throwable $e) {}
            }
            mam_audit('create', 'user', $uid, ['role' => $role, 'email' => $email]);
            $flash = ['ok', 'User created · #' . $uid];
        }
        $_SESSION_FLASH = $flash;
        header('Location: ?v=users' . ($flash[0] === 'ok' ? '&ok=1' : '&err=' . urlencode($flash[1])));
        exit;
    }

    if ($action === 'user_reset') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $pass = (string)($_POST['password'] ?? '');
        if ($uid && strlen($pass) >= 8) {
            mam_db_update('users', [
                'password_hash' => password_hash($pass, PASSWORD_BCRYPT),
                'must_change_password' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $uid]);
            mam_db()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$uid]);
            // Email the new credentials so the reset actually reaches the user.
            $urow = mam_db_row('SELECT email, name, role FROM users WHERE id = ?', [$uid]);
            if ($urow && !empty($urow['email'])) {
                $loginUrl = rtrim((string)mam_env('APP_URL', ''), '/') . '/portal/login';
                try {
                    mam_send((string)$urow['email'], 'Your MAM Academy password was reset',
                        mam_email_account_created((string)($urow['name'] ?? ''), (string)$urow['email'], $pass, (string)$urow['role'], $loginUrl),
                        'account_reset', true);
                } catch (Throwable $e) { mam_log('warn', 'reset_email_failed', ['err' => $e->getMessage()]); }
            }
            mam_audit('admin_reset_password', 'user', $uid, ['emailed' => !empty($urow['email'])]);
            header('Location: ?v=users&ok=1');
        } else {
            header('Location: ?v=users&err=' . urlencode('Password must be at least 8 characters.'));
        }
        exit;
    }

    if ($action === 'user_status') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        if ($uid && in_array($status, ['active', 'suspended'], true)) {
            mam_db_update('users', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $uid]);
            if ($status === 'suspended') {
                mam_db()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$uid]);
            }
            mam_audit('update_status', 'user', $uid, ['status' => $status]);
        }
        header('Location: ?v=users&ok=1');
        exit;
    }

    if ($action === 'class_create') {
        $title       = trim((string)($_POST['title'] ?? ''));
        $discipline  = trim((string)($_POST['discipline'] ?? ''));
        $pathway     = trim((string)($_POST['pathway'] ?? '')) ?: null;
        $instructor  = (int)($_POST['instructor_id'] ?? 0) ?: null;
        $mode        = trim((string)($_POST['mode'] ?? '')) ?: null;
        $schedule    = trim((string)($_POST['schedule'] ?? '')) ?: null;
        $capacity    = max(1, (int)($_POST['capacity'] ?? 12));
        $startsOn    = trim((string)($_POST['starts_on'] ?? '')) ?: null;
        if ($title === '' || $discipline === '') {
            header('Location: ?v=classes&err=' . urlencode('Title and discipline are required.'));
            exit;
        }
        $cid = mam_db_insert('classes', [
            'title' => $title, 'discipline' => $discipline, 'pathway' => $pathway,
            'instructor_id' => $instructor, 'mode' => $mode, 'schedule' => $schedule,
            'capacity' => $capacity, 'starts_on' => $startsOn,
        ]);
        mam_audit('create', 'class', $cid, ['title' => $title]);
        header('Location: ?v=classes&ok=1');
        exit;
    }

    if ($action === 'class_status') {
        $cid = (int)($_POST['class_id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        if ($cid && in_array($status, ['active', 'paused', 'archived'], true)) {
            mam_db_update('classes', ['status' => $status], ['id' => $cid]);
            mam_audit('update_status', 'class', $cid, ['status' => $status]);
        }
        header('Location: ?v=classes&ok=1');
        exit;
    }

    if ($action === 'enroll') {
        $sid = (int)($_POST['student_id'] ?? 0);
        $cid = (int)($_POST['class_id'] ?? 0);
        if (!$sid || !$cid) {
            header('Location: ?v=enrollments&err=' . urlencode('Pick a student and a class.'));
            exit;
        }
        $student = mam_db_row("SELECT id FROM users WHERE id = ? AND role = 'student'", [$sid]);
        $class   = mam_db_row('SELECT id FROM classes WHERE id = ?', [$cid]);
        if (!$student || !$class) {
            header('Location: ?v=enrollments&err=' . urlencode('Student or class not found.'));
            exit;
        }
        try {
            $eid = mam_db_insert('enrollments', [
                'student_id' => $sid, 'class_id' => $cid, 'status' => 'active',
            ]);
            mam_audit('enroll', 'student', $sid, ['class_id' => $cid]);
            header('Location: ?v=enrollments&ok=1');
        } catch (PDOException $e) {
            $m = $e->getMessage();
            $dup = str_contains($m, 'UNIQUE') || str_contains($m, 'Duplicate') || $e->getCode() === '23000';
            header('Location: ?v=enrollments&err=' . urlencode($dup ? 'Student already enrolled in this class.' : 'Enroll failed.'));
        }
        exit;
    }

    if ($action === 'enrollment_status') {
        $eid = (int)($_POST['enrollment_id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        if ($eid && in_array($status, ['active', 'completed', 'withdrawn', 'paused'], true)) {
            mam_db_update('enrollments', ['status' => $status], ['id' => $eid]);
            mam_audit('update_status', 'enrollment', $eid, ['status' => $status]);
        }
        header('Location: ?v=enrollments&ok=1');
        exit;
    }

    // ─── LMS course registrations: approve + assign instructor ───────
    if ($action === 'course_approve') {
        $ceid   = (int)($_POST['enrollment_id'] ?? 0);
        $iid    = (int)($_POST['instructor_id'] ?? 0);
        $status = (string)($_POST['status'] ?? 'active');
        if (!in_array($status, ['active', 'pending', 'completed', 'withdrawn'], true)) $status = 'active';
        if (!$ceid || !mam_db_row('SELECT id FROM course_enrollments WHERE id = ?', [$ceid])) {
            header('Location: ?v=courseenroll&err=' . urlencode('Course registration not found.')); exit;
        }
        if ($status === 'active') {
            mam_lms_approve($ceid, $iid ?: null, (int)($adminUser['id'] ?? 0));
        } else {
            $upd = ['status' => $status]; if ($iid) $upd['instructor_id'] = $iid;
            mam_db_update('course_enrollments', $upd, ['id' => $ceid]);
            mam_audit('lms_status', 'course_enrollment', $ceid, ['status' => $status]);
            // Completing a passed course auto-issues its programme certificate
            // and (when policy allows) advances it to the next level.
            if ($status === 'completed') {
                $cert = mam_lms_issue_for_enrollment($ceid, (int)($adminUser['id'] ?? 0));
                if (mam_lms_auto_promote()) mam_lms_promote_enrollment($ceid, (int)($adminUser['id'] ?? 0));
                if ($cert && !empty($cert['issued'])) {
                    header('Location: ?v=courseenroll&cert=' . urlencode($cert['reference'])); exit;
                }
            }
        }
        header('Location: ?v=courseenroll&ok=1'); exit;
    }

    // Manually advance a passed enrolment to the same course at the next level.
    if ($action === 'course_promote') {
        $ceid = (int)($_POST['enrollment_id'] ?? 0);
        if (!$ceid) { header('Location: ?v=courseenroll&err=' . urlencode('Missing enrolment.')); exit; }
        $res = mam_lms_promote_enrollment($ceid, (int)($adminUser['id'] ?? 0));
        if ($res === null) { header('Location: ?v=courseenroll&err=' . urlencode('Not eligible — needs a passing result and a higher level.')); exit; }
        header('Location: ?v=courseenroll&promoted=' . urlencode($res['level']) . ($res['created'] ? '' : '&dup=1')); exit;
    }

    // ─── LMS course modules (curriculum authoring) ───────────────────
    if (in_array($action, ['module_save', 'module_delete', 'module_scaffold'], true)) {
        $courseCode  = (string)($_POST['course_code'] ?? '');
        $levelCode   = (string)($_POST['level_code'] ?? '');
        $programCode = (string)($_POST['program_code'] ?? '');
        $back = '?v=modules' . ($courseCode !== '' ? '&course=' . urlencode($courseCode) : '')
              . ($levelCode !== '' ? '&level=' . urlencode($levelCode) : '')
              . ($programCode !== '' ? '&program=' . urlencode($programCode) : '');
        $course   = $courseCode !== '' ? mam_db_row('SELECT id FROM courses WHERE code = ?', [$courseCode]) : null;
        $courseId = $course ? (int)$course['id'] : 0;
        $levelRow = $levelCode !== '' ? mam_db_row('SELECT id FROM levels WHERE code = ?', [$levelCode]) : null;
        $levelId  = $levelRow ? (int)$levelRow['id'] : null;

        if ($action === 'module_delete') {
            $mid = (int)($_POST['module_id'] ?? 0);
            if ($mid) mam_lms_delete_module($mid);
            header('Location: ' . $back . '&ok=1'); exit;
        }
        if (!$courseId) { header('Location: ?v=modules&err=' . urlencode('Pick a course first.')); exit; }

        if ($action === 'module_scaffold') {
            $programRow = $programCode !== '' ? mam_db_row('SELECT id FROM programs WHERE code = ?', [$programCode]) : null;
            $made = mam_lms_scaffold_modules($courseId, $levelId, $programRow ? (int)$programRow['id'] : null);
            header('Location: ' . $back . '&made=' . $made); exit;
        }
        // module_save
        $title = trim((string)($_POST['title'] ?? ''));
        if ($title === '') { header('Location: ' . $back . '&err=' . urlencode('A title is required.')); exit; }
        mam_lms_save_module(
            (int)($_POST['module_id'] ?? 0) ?: null,
            $courseId, $levelId,
            (int)($_POST['week'] ?? 1),
            (string)($_POST['kind'] ?? 'module'),
            $title,
            trim((string)($_POST['content'] ?? '')) ?: null
        );
        header('Location: ' . $back . '&ok=1'); exit;
    }

    // ─── Store / Library CRUD ────────────────────────────────────────
    // Single dispatch covers create (id=0), update (id>0), soft-delete (delete=1).
    // We slug-ify the name client-side, but normalise + de-dupe here so two
    // items with the same name don't collide. Images / available_locations are
    // free-text in the form for ease — comma-separated, normalised to JSON.
    if ($action === 'store_item_save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $sku = strtoupper(trim((string)($_POST['sku'] ?? '')));
        $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
        if ($slug === '') $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
        $slug = trim((string)$slug, '-');

        $images_csv = trim((string)($_POST['images'] ?? ''));
        $images = $images_csv === '' ? [] : array_values(array_filter(array_map('trim', explode("\n", str_replace([',',';'], "\n", $images_csv)))));
        $locs_csv = trim((string)($_POST['available_locations'] ?? '*'));
        if ($locs_csv === '' || $locs_csv === '*') $locations = '*';
        else {
            $arr = array_values(array_filter(array_map(function($x){ return strtoupper(trim($x)); }, explode(',', $locs_csv))));
            $locations = json_encode($arr);
        }

        $row = [
            'sku'                 => $sku,
            'slug'                => $slug,
            'name'                => $name,
            'description'         => trim((string)($_POST['description'] ?? '')),
            'goods_type'          => (string)($_POST['goods_type'] ?? 'digital'),
            'delivery_type'       => (string)($_POST['delivery_type'] ?? 'instant'),
            'availability'        => (string)($_POST['availability'] ?? 'in_stock'),
            'stock'               => $_POST['stock'] === '' ? -1 : (int)($_POST['stock'] ?? -1),
            'available_locations' => $locations,
            'price_usd'           => (float)($_POST['price_usd'] ?? 0),
            'currency'            => (string)($_POST['currency'] ?? 'USD'),
            'images'              => $images ? json_encode($images) : null,
            'category'            => trim((string)($_POST['category'] ?? '')),
            'file_url'            => trim((string)($_POST['file_url'] ?? '')) ?: null,
            'weight_grams'        => $_POST['weight_grams'] === '' ? null : (int)($_POST['weight_grams'] ?? 0),
            'tax_rate'            => (float)($_POST['tax_rate'] ?? 0),
            'sort_order'          => (int)($_POST['sort_order'] ?? 0),
            'active'              => isset($_POST['active']) ? 1 : 0,
        ];

        if ($id === 0) {
            if ($name === '' || $sku === '' || $row['price_usd'] <= 0) {
                header('Location: ?v=storeitems&err=' . urlencode('Name, SKU and a positive price are required.')); exit;
            }
            if (mam_db_row('SELECT id FROM store_items WHERE sku = ? OR slug = ?', [$sku, $slug])) {
                header('Location: ?v=storeitems&err=' . urlencode('That SKU or slug is already taken.')); exit;
            }
            $newId = mam_db_insert('store_items', $row);
            mam_audit('store_item_create', 'store_item', (int)$newId, ['sku' => $sku]);
            header('Location: ?v=storeitems&ok=1'); exit;
        }
        $row['updated_at'] = date('Y-m-d H:i:s');
        mam_db_update('store_items', $row, ['id' => $id]);
        mam_audit('store_item_update', 'store_item', $id, ['sku' => $sku]);
        header('Location: ?v=storeitems&ok=1'); exit;
    }

    if ($action === 'store_item_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            mam_db_update('store_items', ['active' => 0, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
            mam_audit('store_item_delete', 'store_item', $id);
        }
        header('Location: ?v=storeitems&ok=1'); exit;
    }

    if ($action === 'save_settings') {
        // Persist every key declared in the settings schema. Non-negative
        // numbers only; blanks fall back to the shipped default so a cleared
        // field can never zero out a price by accident.
        $saved = [];
        foreach (mam_settings_schema() as $row) {
            [$group, $key, $label, $default, $suffix] = $row;
            $raw = $_POST[$key] ?? null;
            if (is_numeric($default)) {
                // Numeric setting — non-negative; blank falls back to the
                // shipped default so a cleared field can't zero out a price.
                if ($raw === null || $raw === '') { $val = (float)$default; }
                else { $val = max(0, (float)$raw); }
                // Store whole numbers without a trailing .0 for clean display.
                $val = ($val == (int)$val) ? (string)(int)$val : (string)$val;
            } else {
                // Text setting (e.g. a URL) — store trimmed; blank → default.
                $val = trim((string)($raw ?? ''));
                if ($val === '') $val = (string)$default;
                $val = mb_substr($val, 0, 300);
            }
            mam_setting_set($key, $val);
            $saved[$key] = $val;
        }
        mam_audit('update_settings', 'settings', null, $saved);
        header('Location: ?v=settings&ok=1'); exit;
    }

    if ($action === 'store_order_status') {
        $oid = (int)($_POST['order_id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        if ($oid && in_array($status, ['pending', 'paid', 'shipped', 'delivered', 'refunded', 'cancelled'], true)) {
            $upd = ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')];
            if (in_array($status, ['delivered', 'shipped'], true)) $upd['delivered_at'] = date('Y-m-d H:i:s');
            mam_db_update('store_orders', $upd, ['id' => $oid]);
            mam_audit('update_status', 'store_order', $oid, ['status' => $status]);
        }
        header('Location: ?v=storeorders&ok=1'); exit;
    }

    // ─── Soft-delete / deactivation across core entities ─────────────
    // We never hard-delete user-visible records (audit + finance integrity);
    // these flip a status column instead so the row stays for history.
    if ($action === 'user_delete') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid) {
            mam_db_update('users', ['status' => 'suspended', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $uid]);
            mam_audit('user_suspend', 'user', $uid);
        }
        header('Location: ?v=users&ok=1'); exit;
    }
    if ($action === 'class_delete') {
        $cid = (int)($_POST['class_id'] ?? 0);
        if ($cid) {
            mam_db_update('classes', ['status' => 'archived'], ['id' => $cid]);
            mam_audit('class_archive', 'class', $cid);
        }
        header('Location: ?v=classes&ok=1'); exit;
    }
    if ($action === 'enrollment_delete') {
        $eid = (int)($_POST['enrollment_id'] ?? 0);
        if ($eid) {
            mam_db_update('enrollments', ['status' => 'withdrawn'], ['id' => $eid]);
            mam_audit('enrollment_withdraw', 'enrollment', $eid);
        }
        header('Location: ?v=enrollments&ok=1'); exit;
    }
    if ($action === 'app_delete') {
        $aid = (int)($_POST['app_id'] ?? 0);
        if ($aid) {
            mam_db_update('applications', ['status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $aid]);
            mam_audit('application_cancel', 'application', $aid);
        }
        header('Location: ?v=applications&ok=1'); exit;
    }

    // ─── User editing (beyond suspend + reset-password) ──────────────
    // We update users + the linked customers row in lockstep so the data the
    // student sees in their portal matches what admin sees here. Email changes
    // are guarded against collisions; role changes only allow valid values.
    if ($action === 'user_edit') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if (!$uid) { header('Location: ?v=users&err=' . urlencode('Missing user_id')); exit; }
        $email = trim((string)($_POST['email'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $role = (string)($_POST['role'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { header('Location: ?v=users&edit=' . $uid . '&err=' . urlencode('Valid email required')); exit; }
        if (!in_array($role, ['student','instructor','admin'], true)) { header('Location: ?v=users&edit=' . $uid . '&err=' . urlencode('Invalid role')); exit; }
        if (mam_db_row('SELECT id FROM users WHERE email = ? AND id != ?', [$email, $uid])) {
            header('Location: ?v=users&edit=' . $uid . '&err=' . urlencode('That email is already taken by another user.')); exit;
        }
        mam_db_update('users', ['email' => $email, 'name' => $name, 'phone' => $phone, 'role' => $role, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $uid]);
        // Sync the linked customer row so applications + emails stay consistent.
        $u = mam_db_row('SELECT customer_id FROM users WHERE id = ?', [$uid]);
        if (!empty($u['customer_id'])) {
            $names = explode(' ', $name, 2);
            mam_db_update('customers', [
                'email' => $email,
                'first_name' => $names[0] ?? '',
                'last_name'  => $names[1] ?? '',
                'phone' => $phone,
            ], ['id' => (int)$u['customer_id']]);
        }
        mam_audit('user_edit', 'user', $uid, ['email' => $email, 'role' => $role]);
        header('Location: ?v=users&ok=1'); exit;
    }

    // ─── Class editing (beyond status toggle) ────────────────────────
    if ($action === 'class_edit') {
        $cid = (int)($_POST['class_id'] ?? 0);
        if (!$cid) { header('Location: ?v=classes&err=' . urlencode('Missing class_id')); exit; }
        $upd = [
            'title'         => trim((string)($_POST['title'] ?? '')),
            'discipline'    => trim((string)($_POST['discipline'] ?? '')),
            'pathway'       => trim((string)($_POST['pathway'] ?? '')) ?: null,
            'instructor_id' => $_POST['instructor_id'] === '' ? null : (int)($_POST['instructor_id'] ?? 0),
            'mode'          => trim((string)($_POST['mode'] ?? '')) ?: null,
            'capacity'      => (int)($_POST['capacity'] ?? 0),
            'starts_on'     => trim((string)($_POST['starts_on'] ?? '')) ?: null,
            'schedule'      => trim((string)($_POST['schedule'] ?? '')) ?: null,
        ];
        if ($upd['title'] === '' || $upd['discipline'] === '') {
            header('Location: ?v=classes&edit=' . $cid . '&err=' . urlencode('Title and discipline are required.')); exit;
        }
        mam_db_update('classes', $upd, ['id' => $cid]);
        mam_audit('class_edit', 'class', $cid);
        header('Location: ?v=classes&ok=1'); exit;
    }

    // ─── Payment refund (operational marker) ─────────────────────────
    // We don't push the refund through the provider API — most refunds at MAM
    // are handled manually with finance, then logged here. The reversal flips
    // the payment row to 'refunded' AND the linked application/order back to
    // a non-terminal state so it doesn't show as paid on the customer portal.
    if ($action === 'payment_refund') {
        $pid = (int)($_POST['payment_id'] ?? 0);
        $note = trim((string)($_POST['note'] ?? ''));
        if (!$pid) { header('Location: ?v=payments&err=' . urlencode('Missing payment_id')); exit; }
        $p = mam_db_row('SELECT * FROM payments WHERE id = ?', [$pid]);
        if (!$p) { header('Location: ?v=payments&err=' . urlencode('Payment not found')); exit; }
        mam_db_update('payments', [
            'status' => 'refunded',
            'provider_response' => trim(((string)$p['provider_response']) . "\nREFUNDED: " . $note),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $pid]);
        // Reverse the related subject so the customer portal reflects reality.
        $purpose = (string)($p['purpose'] ?? 'tuition');
        if ($purpose === 'store') {
            $o = mam_db_row('SELECT id FROM store_orders WHERE reference = ?', [$p['reference']]);
            if ($o) mam_db_update('store_orders', ['status' => 'refunded', 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int)$o['id']]);
        } elseif ($purpose === 'booking_fee' && !empty($p['application_id'])) {
            mam_db_update('applications', ['booking_fee_paid_at' => null], ['id' => (int)$p['application_id']]);
        } elseif (!empty($p['application_id'])) {
            mam_db_update('applications', ['status' => 'in_review'], ['id' => (int)$p['application_id']]);
        }
        mam_audit('payment_refund', 'payment', $pid, ['note' => $note, 'purpose' => $purpose]);
        header('Location: ?v=payments&ok=1'); exit;
    }

    // ─── Confirm bank transfer ───────────────────────────────────────
    // The bank-transfer flow leaves payments stuck at 'pending_bank_transfer'
    // until finance verifies the deposit. This action is what flips that row
    // to 'succeeded' and propagates the success to the linked subject
    // (booking_fee_paid_at, application 'paid', or store_orders 'delivered')
    // exactly as the auto-verify code path would have.
    if ($action === 'payment_confirm_bank') {
        $pid = (int)($_POST['payment_id'] ?? 0);
        $note = trim((string)($_POST['note'] ?? ''));
        if (!$pid) { header('Location: ?v=payments&err=' . urlencode('Missing payment_id')); exit; }
        $p = mam_db_row('SELECT * FROM payments WHERE id = ?', [$pid]);
        if (!$p) { header('Location: ?v=payments&err=' . urlencode('Payment not found')); exit; }
        if ($p['status'] === 'succeeded') { header('Location: ?v=payments&ok=1'); exit; }

        mam_db_update('payments', [
            'status'            => 'succeeded',
            'provider_response' => trim(((string)$p['provider_response']) . "\nCONFIRMED BY ADMIN: " . $note . ' on ' . date('c')),
            'verified_at'       => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ], ['id' => $pid]);

        // Mirror the success effects of an automatic provider verify.
        $purpose = (string)($p['purpose'] ?? 'tuition');
        if ($purpose === 'store') {
            $o = mam_db_row('SELECT id, items_json, customer_email, reference FROM store_orders WHERE reference = ?', [$p['reference']]);
            if ($o) {
                $items = json_decode((string)$o['items_json'], true) ?: [];
                $hasPhysical = false;
                foreach ($items as $i) if (($i['goods_type'] ?? '') === 'physical') { $hasPhysical = true; break; }
                mam_db_update('store_orders', [
                    'status' => $hasPhysical ? 'paid' : 'delivered',
                    'delivered_at' => $hasPhysical ? null : date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['id' => (int)$o['id']]);
            }
        } elseif ($purpose === 'booking_fee' && !empty($p['application_id'])) {
            mam_db_update('applications', ['booking_fee_paid_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int)$p['application_id']]);
        } elseif (!empty($p['application_id'])) {
            mam_db_update('applications', ['status' => 'paid', 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int)$p['application_id']]);
        }
        mam_audit('payment_confirm_bank', 'payment', $pid, ['note' => $note, 'purpose' => $purpose]);
        header('Location: ?v=payments&ok=1'); exit;
    }

    // ─── Resend application confirmation + invoice ───────────────────
    // Useful when an applicant says the email never arrived. We rebuild the
    // confirmation using the current tier (so the regional price is fresh)
    // and re-attach a freshly-rendered invoice.
    if ($action === 'resend_confirmation') {
        $aid = (int)($_POST['app_id'] ?? 0);
        if (!$aid) { header('Location: ?v=applications&err=' . urlencode('Missing app_id')); exit; }
        $app = mam_db_row('SELECT * FROM applications WHERE id = ?', [$aid]);
        $cust = $app && $app['customer_id'] ? mam_db_row('SELECT * FROM customers WHERE id = ?', [(int)$app['customer_id']]) : null;
        if (!$app || !$cust || empty($cust['email'])) {
            header('Location: ?v=applications&err=' . urlencode('Cannot find application or customer email')); exit;
        }
        $tier = mam_booking_tier();
        $invNo = 'INV-MAM-' . date('y', strtotime((string)$app['created_at'])) . '-' . str_pad((string)$aid, 4, '0', STR_PAD_LEFT);
        // No .html attachment — the email body links to /api/invoice/{ref}
        // (renders properly in any browser, prints clean as PDF).
        mam_send(
            (string)$cust['email'],
            'MAM Academy — Application copy (' . $app['reference'] . ')',
            mam_email_application_confirmation($app, $cust, $tier, $invNo),
            'application_confirmation_resend',
            true
        );
        mam_audit('resend_confirmation', 'application', $aid, ['to' => $cust['email']]);
        header('Location: ?v=applications&ok=1'); exit;
    }

    // Unknown action — bounce back
    header('Location: ?v=' . urlencode($view));
    exit;
}

/* ─── KPIs (degrade to 0 if a table is missing — see mam_admin_scalar) ─── */
$kpi = [
    'applications' => (int)mam_admin_scalar('SELECT COUNT(*) FROM applications'),
    'paid'         => (int)mam_admin_scalar("SELECT COUNT(*) FROM applications WHERE status='paid'"),
    'revenue'      => (float)mam_admin_scalar("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='succeeded'"),
    'newsletter'   => (int)mam_admin_scalar('SELECT COUNT(*) FROM newsletter'),
    'messages'     => (int)mam_admin_scalar("SELECT COUNT(*) FROM messages WHERE status='unread'"),
    'webhooks'     => (int)mam_admin_scalar('SELECT COUNT(*) FROM webhook_events'),
    'users'        => (int)mam_admin_scalar('SELECT COUNT(*) FROM users'),
    'classes'      => (int)mam_admin_scalar("SELECT COUNT(*) FROM classes WHERE status='active'"),
];

/* ─── 14-day trend series (bucketed in PHP so it works identically on
   SQLite + MySQL without date-function differences) ─── */
$trendDays = 14;
$buckets = [];                                  // 'YYYY-MM-DD' => ['apps'=>0,'rev'=>0]
for ($i = $trendDays - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $buckets[$d] = ['apps' => 0, 'rev' => 0.0];
}
$since = date('Y-m-d 00:00:00', strtotime('-' . ($trendDays - 1) . ' day'));
foreach (mam_admin_rows('SELECT created_at FROM applications WHERE created_at >= ?', [$since]) as $r) {
    $d = substr((string)$r['created_at'], 0, 10);
    if (isset($buckets[$d])) $buckets[$d]['apps']++;
}
foreach (mam_admin_rows("SELECT amount, COALESCE(verified_at, created_at) AS paid_at FROM payments WHERE status='succeeded' AND COALESCE(verified_at, created_at) >= ?", [$since]) as $r) {
    $d = substr((string)$r['paid_at'], 0, 10);
    if (isset($buckets[$d])) $buckets[$d]['rev'] += (float)$r['amount'];
}
$trendLabels = array_map(function ($d) { return date('M j', strtotime($d)); }, array_keys($buckets));
$trendApps   = array_map(function ($b) { return $b['apps']; }, array_values($buckets));
$trendRev    = array_map(function ($b) { return round($b['rev']); }, array_values($buckets));
// Payment status mix (all-time) for the doughnut.
$payMix = [];
foreach (mam_admin_rows('SELECT status, COUNT(*) AS n FROM payments GROUP BY status') as $r) {
    $payMix[(string)$r['status']] = (int)$r['n'];
}

$csrf = mam_csrf_token();
$flashOk  = isset($_GET['ok']);
$flashErr = $_GET['err'] ?? '';
?><!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>MAM Admin — <?= htmlspecialchars($view) ?></title>
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{
  --bg:#0F0A06;--panel:#1A120B;--raised:#241710;--card:#2E2015;
  --text:#FBF7F3;--muted:rgba(251,247,243,0.7);--faint:rgba(251,247,243,0.5);
  --border:rgba(210,170,138,0.22);--gold:#FBBF24;--gold-2:#E08A1A;
  --emerald:#10B981;--red:#F43F5E;--blue:#6366F1;
}
html,body{height:100%}
body{font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:grid;grid-template-columns:240px 1fr}
a{color:inherit;text-decoration:none}
aside{background:var(--panel);border-right:1px solid var(--border);padding:18px 14px;position:sticky;top:0;height:100vh;overflow:auto}
.brand{display:flex;align-items:center;gap:10px;padding:8px 10px 18px;border-bottom:1px solid var(--border);margin-bottom:14px}
.brand .logo{width:36px;height:36px;border-radius:8px;background:linear-gradient(135deg,var(--gold),var(--gold-2));display:flex;align-items:center;justify-content:center;color:#1A120B;font-weight:900}
.brand b{font-size:1rem;letter-spacing:0.04em}
.brand small{display:block;color:var(--muted);font-size:0.74rem;letter-spacing:0.06em;text-transform:uppercase}
nav a{display:flex;align-items:center;gap:10px;padding:9px 12px;color:var(--muted);border-radius:8px;margin-bottom:2px;font-size:0.92rem}
nav a:hover{background:rgba(255,255,255,0.04);color:var(--text)}
nav a.active{background:linear-gradient(135deg,rgba(224,138,26,0.18),rgba(251,191,36,0.08));color:var(--gold);border:1px solid var(--border)}
main{padding:28px 32px;overflow:auto}
header.top{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;gap:18px;flex-wrap:wrap}
header.top h1{font-size:1.4rem;font-weight:700;letter-spacing:-0.01em}
.btn{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;background:linear-gradient(135deg,var(--gold),var(--gold-2));color:#1A120B;border-radius:8px;font-weight:700;font-size:0.85rem;border:none;cursor:pointer}
.btn.ghost{background:transparent;color:var(--text);border:1px solid var(--border)}
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-bottom:32px}
.kpi{padding:18px;background:var(--raised);border:1px solid var(--border);border-radius:12px}
.kpi-label{font-size:0.72rem;color:var(--muted);letter-spacing:0.12em;text-transform:uppercase;margin-bottom:6px;font-weight:600}
.kpi-value{font-size:1.7rem;font-weight:800;letter-spacing:-0.01em}
.kpi-value.gold{background:linear-gradient(135deg,var(--gold),var(--gold-2));-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
/* Tables can be wide (9+ columns). The card is the horizontal scroll
   container; the h3 header is pinned via sticky so it doesn't pan with
   the table. Edge-fade backgrounds appear only when there's more to scroll. */
.card{background:var(--raised);border:1px solid var(--border);border-radius:14px;margin-bottom:22px;overflow:hidden;overflow-x:auto;-webkit-overflow-scrolling:touch;
  background-image:
    linear-gradient(to right,var(--raised) 30%,rgba(0,0,0,0)),
    linear-gradient(to right,rgba(0,0,0,0),var(--raised) 70%),
    linear-gradient(to right,rgba(0,0,0,0.4),rgba(0,0,0,0)),
    linear-gradient(to left,rgba(0,0,0,0.4),rgba(0,0,0,0));
  background-position:0 0,100% 0,0 0,100% 0;
  background-repeat:no-repeat;
  background-size:42px 100%,42px 100%,14px 100%,14px 100%;
  background-attachment:local,local,scroll,scroll;
}
.card h3{padding:16px 20px;font-size:0.95rem;font-weight:700;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;position:sticky;left:0;width:max-content;min-width:100%;background:var(--raised);z-index:2}
.card > table{min-width:720px}
table{width:100%;border-collapse:collapse;font-size:0.86rem}
th,td{padding:11px 16px;text-align:left;border-bottom:1px solid var(--border);vertical-align:top}
th{color:var(--muted);font-size:0.72rem;letter-spacing:0.08em;text-transform:uppercase;font-weight:700;background:var(--panel);white-space:nowrap}
tr:hover td{background:rgba(255,255,255,0.02)}
.pill{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:100px;font-size:0.72rem;font-weight:700;letter-spacing:0.03em;text-transform:lowercase}
.pill.submitted,.pill.received{background:rgba(99,102,241,0.18);color:#A5B4FC}
.pill.paid,.pill.processed,.pill.succeeded,.pill.active,.pill.subscribed{background:rgba(16,185,129,0.18);color:#34D399}
.pill.enrolled{background:linear-gradient(135deg,var(--gold),var(--gold-2));color:#1A120B}
.pill.cancelled,.pill.failed,.pill.invalid,.pill.suspended,.pill.withdrawn{background:rgba(244,63,94,0.18);color:#FB7185}
.pill.in_review,.pill.pending,.pill.unread,.pill.paused{background:rgba(251,191,36,0.18);color:#FBBF24}
.pill.read,.pill.archived,.pill.completed{background:rgba(255,255,255,0.08);color:var(--muted)}
.pill.admin{background:linear-gradient(135deg,var(--red),#FB7185);color:#fff}
.pill.instructor{background:rgba(99,102,241,0.18);color:#A5B4FC}
.pill.student{background:rgba(16,185,129,0.18);color:#34D399}
.muted{color:var(--muted)}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:0.82rem}
form.inline{display:inline-flex;gap:6px;align-items:center}
select.tiny,input.tiny{background:var(--panel);color:var(--text);border:1px solid var(--border);border-radius:6px;font-size:0.78rem;padding:4px 6px}
button.tiny{padding:4px 8px;font-size:0.74rem;background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:6px;cursor:pointer}
button.tiny:hover{border-color:var(--gold)}
.empty{padding:48px;text-align:center;color:var(--muted)}
.banner{padding:14px 18px;border-radius:10px;background:rgba(99,102,241,0.08);border:1px solid rgba(99,102,241,0.3);margin-bottom:18px;font-size:0.88rem;color:#C7D2FE}
.banner.warn{background:rgba(251,191,36,0.08);border-color:rgba(251,191,36,0.4);color:#FDE68A}
.banner.ok{background:rgba(16,185,129,0.10);border-color:rgba(16,185,129,0.4);color:#A7F3D0}
.banner.err{background:rgba(244,63,94,0.10);border-color:rgba(244,63,94,0.4);color:#FECDD3}
.json-pop{max-width:380px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--muted)}
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;padding:18px 20px}
.form-grid label{display:flex;flex-direction:column;gap:4px;font-size:0.78rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.06em;font-weight:600}
.form-grid input,.form-grid select{background:var(--panel);color:var(--text);border:1px solid var(--border);border-radius:8px;font-size:0.9rem;padding:9px 12px}
.form-grid input:focus,.form-grid select:focus{outline:none;border-color:var(--gold)}
.form-grid .full{grid-column:1/-1}
.form-grid .actions{grid-column:1/-1;display:flex;justify-content:flex-end;gap:8px;padding-top:6px}
@media(max-width:920px){
  body{grid-template-columns:1fr}
  aside{position:static;height:auto}
  main{padding:20px 16px}
  .top{flex-wrap:wrap;gap:10px}
  .form-grid{grid-template-columns:1fr}
}
@media(max-width:520px){
  .kpi-grid{grid-template-columns:1fr 1fr}
  th,td{padding:9px 12px;font-size:0.82rem}
  .card > table > tbody,.card > table > thead{min-width:560px}
}
.chart-row{display:grid;grid-template-columns:2fr 1fr;gap:18px;margin-bottom:22px}
.chart-row .card{margin:0}
.chart-row canvas{max-height:260px}
@media(max-width:860px){.chart-row{grid-template-columns:1fr}}
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
</head>
<body>
<aside>
  <div class="brand">
    <div class="logo">M</div>
    <div><b>MAM Admin</b><small><?= htmlspecialchars(mam_env('APP_ENV', 'production')) ?></small></div>
  </div>
  <nav>
    <?php
    $links = [
      'overview'     => 'Overview',
      'applications' => 'Applications',
      'funnel'       => 'Apply Funnel',
      'payments'     => 'Payments',
      'users'        => 'Users',
      'classes'      => 'Classes',
      'enrollments'  => 'Enrollments',
      'courseenroll' => 'Course Registrations',
      'modules'      => 'Course Modules',
      'storeitems'   => 'Store / Library',
      'storeorders'  => 'Store Orders',
      'messages'     => 'Messages',
      'webhooks'     => 'Webhook Events',
      'newsletter'   => 'Newsletter',
      'settings'     => 'Pricing & Settings',
      'audit'        => 'Audit Log',
      'account'      => 'My Account',
    ];
    // RBAC: managers only see sections they can use; sensitive views are
    // super-only. A direct URL to a restricted view falls back to Overview.
    $viewCaps = ['users' => 'users', 'settings' => 'settings', 'storeitems' => 'store_catalog', 'webhooks' => 'settings', 'audit' => 'users'];
    $canView = function ($k) use ($adminUser, $viewCaps) {
        return !$adminUser || mam_admin_can($adminUser, $viewCaps[$k] ?? 'view'); // Basic-Auth (null) = super
    };
    if (!$canView($view)) { $view = 'overview'; }
    foreach ($links as $k => $label) {
        if (!$canView($k)) continue;
        $cls = $view === $k ? 'active' : '';
        echo "<a href='?v=" . htmlspecialchars($k) . "' class='$cls'>" . htmlspecialchars($label) . "</a>";
    }
    if ($adminUser && mam_admin_tier($adminUser) === 'manager') {
        echo "<div style='margin-top:10px;padding:7px 10px;font-size:0.72rem;color:var(--muted);border:1px dashed var(--border);border-radius:8px'>Manager access — pricing, users &amp; settings are restricted.</div>";
    }
    ?>
  </nav>
  <form method="get" action="" style="margin-top:16px;display:flex;gap:6px">
    <input type="hidden" name="v" value="search">
    <input type="search" name="q" value="<?= htmlspecialchars((string)($_GET['q'] ?? '')) ?>" placeholder="Search ref, email, name…" style="flex:1;min-width:0;padding:9px 11px;border-radius:8px;border:1px solid var(--border);background:var(--bg);color:var(--ink);font-size:0.82rem">
    <button class="btn" type="submit" style="padding:9px 12px" aria-label="Search">🔍</button>
  </form>
  <div style="margin-top:24px;padding:12px;background:var(--bg);border:1px solid var(--border);border-radius:10px;font-size:0.78rem;color:var(--muted);line-height:1.5">
    <strong style="color:var(--gold)">Signed in:</strong>
    <?php
    $sessUser = mam_current_user();
    echo htmlspecialchars($sessUser['email'] ?? ($_SERVER['PHP_AUTH_USER'] ?? 'admin'));
    ?><br>
    <a href="/" style="color:var(--gold)" target="_blank">↗ View site</a>
  </div>
  <?php
    // Notification centre (L3) — session admins only (Basic-Auth has no user row,
    // so no inbox). Surfaces assignments and other staff alerts where admins work.
    if ($adminUser && function_exists('mam_notifications')):
      $myUid  = (int)$adminUser['id'];
      $unread = mam_notifications_unread_count($myUid);
      $notes  = mam_notifications($myUid, false, 6);
  ?>
  <div style="margin-top:16px;padding:12px;background:var(--bg);border:1px solid var(--border);border-radius:10px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
      <strong style="color:var(--gold);font-size:.8rem"><i class="fa-solid fa-bell" aria-hidden="true"></i> Notifications<?php if ($unread): ?> <span style="background:#E11D48;color:#fff;border-radius:9px;padding:0 6px;font-size:.64rem"><?= (int)$unread ?></span><?php endif; ?></strong>
      <?php if ($unread): ?>
        <form method="post" style="margin:0">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="notif_read_all">
          <input type="hidden" name="back" value="<?= htmlspecialchars((string)$view) ?>">
          <button type="submit" style="background:none;border:0;color:var(--gold);cursor:pointer;font-size:.68rem;padding:0">Mark all read</button>
        </form>
      <?php endif; ?>
    </div>
    <?php if (!$notes): ?>
      <div class="muted" style="font-size:.74rem">You're all caught up.</div>
    <?php else: foreach ($notes as $n): $nurl = (string)($n['url'] ?? ''); ?>
      <a href="<?= htmlspecialchars($nurl !== '' ? $nurl : '#') ?>" style="display:block;padding:6px 0;border-top:1px solid var(--border);text-decoration:none;color:var(--text)<?= $n['read_at'] ? ';opacity:.5' : '' ?>">
        <div style="font-size:.75rem;font-weight:600"><?= htmlspecialchars((string)$n['title']) ?></div>
        <?php if (!empty($n['body'])): ?><div class="muted" style="font-size:.7rem"><?= htmlspecialchars((string)$n['body']) ?></div><?php endif; ?>
      </a>
    <?php endforeach; endif; ?>
  </div>
  <?php endif; ?>
</aside>

<main>
<?php if ($flashOk): ?><div class="banner ok">Done.</div><?php endif; ?>
<?php if ($flashErr !== ''): ?><div class="banner err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>
<?php $mailRdy = mam_mail_readiness(); if (!$mailRdy['ready']): ?>
  <div class="banner err" style="line-height:1.6">
    <strong>⚠ Email is not configured</strong> — applications, password resets, portal logins, store receipts and certificates won't be delivered.
    <?= htmlspecialchars(implode(' ', $mailRdy['issues'])) ?>
    Set <code>MAIL_HOST</code>, <code>MAIL_USERNAME</code>, <code>MAIL_PASSWORD</code> (and <code>MAIL_PORT</code>/<code>MAIL_ENCRYPTION</code>) in <code>api/.env</code>, then verify with <code>php api/tools/mail-test.php you@example.com</code> or <code>GET /api/diag</code>.
  </div>
<?php endif; ?>

<?php if ($view === 'overview'): ?>
  <header class="top">
    <h1>Overview</h1>
    <div>
      <a class="btn ghost" href="?v=export&type=applications.csv">⬇ Export applications</a>
      <a class="btn ghost" href="?v=export&type=payments.csv">⬇ Export payments</a>
    </div>
  </header>
  <?php
    $health = [
      'paystack' => !!mam_env('PAYSTACK_SECRET_KEY'),
      'flw'      => !!mam_env('FLW_SECRET_KEY'),
      'smtp'     => !!mam_env('MAIL_HOST'),
      'key'      => strlen((string)mam_env('APP_KEY')) >= 32,
    ];
    // Schema-version check — surfaces the #1 production gotcha: a MySQL DB
    // that's behind on migrations because schema-mysql.sql wasn't run after
    // a deploy. The button POSTs /admin/migrate which runs the idempotent
    // schema file. Safe to click repeatedly.
    $schemaVersion = 0; $schemaTarget = defined('MAM_SCHEMA_TARGET') ? MAM_SCHEMA_TARGET : 16;
    try {
      $schemaVersion = (int)mam_db()->query('SELECT IFNULL(MAX(version),0) FROM schema_version')->fetchColumn();
    } catch (Throwable $e) {}
    if ($schemaVersion < $schemaTarget) {
      echo '<div class="banner warn">⚠️ Database is at <strong>schema version ' . $schemaVersion . '</strong>, target is <strong>' . $schemaTarget . '</strong>. Submissions that touch the missing columns will 500 until you migrate.';
      echo ' <form method="post" action="/api/admin/migrate" style="display:inline;margin-left:12px" id="migrateForm">'
         . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf) . '">'
         . '<button type="button" class="btn" onclick="mamRunMigrate()">Run migrations now</button>'
         . '</form>';
      echo '<script>function mamRunMigrate(){fetch("/api/admin/migrate",{method:"POST",headers:{"X-CSRF-Token":"'.htmlspecialchars($csrf,ENT_QUOTES).'","Content-Type":"application/json"},credentials:"same-origin"}).then(r=>r.json()).then(j=>{alert("Migration "+(j.ok?"succeeded":"failed")+": schema "+j.before+" → "+j.after);location.reload();}).catch(e=>alert("Failed: "+e.message));}</script>';
      echo '</div>';
    }
    if (!$health['key']) echo '<div class="banner warn">⚠️ APP_KEY is missing or too short — CSRF tokens and admin forms will fail. Set <code>APP_KEY</code> in <code>api/.env</code> (hex, ≥32 chars).</div>';
    if (!$health['paystack'] && !$health['flw']) echo '<div class="banner warn">⚠️ No payment provider configured. Set <code>PAYSTACK_SECRET_KEY</code> or <code>FLW_SECRET_KEY</code>.</div>';
    if (!$health['smtp']) echo '<div class="banner">ℹ️ SMTP not configured — outgoing mail falls back to PHP <code>mail()</code>.</div>';
    // Operational readiness: config that silently degrades a feature when left
    // empty. Server-side values come from .env; the client-side ones live in
    // assets/js/config.js, so we string-scan that file for its known defaults.
    $ops = [];
    if (!mam_env('BOOKING_EMBED_URL')) $ops[] = '<code>BOOKING_EMBED_URL</code> (api/.env) — paid bookers see “calendar is being set up” instead of the scheduler';
    $cfgJs = @file_get_contents(__DIR__ . '/../assets/js/config.js') ?: '';
    if (preg_match('/meetUrl:\s*\'\'/', $cfgJs))      $ops[] = '<code>powerPlay.meetUrl</code> (assets/js/config.js) — Power Play page has no join link';
    if (preg_match('/cloudName:\s*\'\'/', $cfgJs))    $ops[] = '<code>cdn.cloudName</code> (assets/js/config.js) — image CDN is a no-op';
    if (preg_match('/uploadPreset:\s*\'\'/', $cfgJs)) $ops[] = '<code>cdn.uploadPreset</code> (assets/js/config.js) — applicant headshot upload is disabled';
    if (function_exists('mam_mail_readiness')) { $mr = mam_mail_readiness(); if (is_array($mr) && empty($mr['ready']) && !empty($mr['issues'])) $ops[] = 'Mail delivery: ' . htmlspecialchars(implode('; ', (array)$mr['issues'])); }
    if ($ops) echo '<div class="banner"><strong>ℹ️ Operational readiness</strong> — features waiting on configuration:<ul style="margin:8px 0 0 18px;padding:0">' . implode('', array_map(fn ($o) => '<li style="margin:3px 0">' . $o . '</li>', $ops)) . '</ul></div>';
  ?>
  <div class="kpi-grid">
    <div class="kpi"><div class="kpi-label">Applications</div><div class="kpi-value"><?= number_format($kpi['applications']) ?></div></div>
    <div class="kpi"><div class="kpi-label">Paid</div><div class="kpi-value gold"><?= number_format($kpi['paid']) ?></div></div>
    <div class="kpi"><div class="kpi-label">Revenue</div><div class="kpi-value gold">₦<?= number_format($kpi['revenue']) ?></div></div>
    <div class="kpi"><div class="kpi-label">Users</div><div class="kpi-value"><?= number_format($kpi['users']) ?></div></div>
    <div class="kpi"><div class="kpi-label">Active Classes</div><div class="kpi-value"><?= number_format($kpi['classes']) ?></div></div>
    <div class="kpi"><div class="kpi-label">Unread Messages</div><div class="kpi-value"><?= number_format($kpi['messages']) ?></div></div>
    <div class="kpi"><div class="kpi-label">Newsletter</div><div class="kpi-value"><?= number_format($kpi['newsletter']) ?></div></div>
    <div class="kpi"><div class="kpi-label">Webhook Events</div><div class="kpi-value"><?= number_format($kpi['webhooks']) ?></div></div>
  </div>

  <div class="chart-row">
    <div class="card">
      <h3>Last 14 days — applications &amp; revenue</h3>
      <canvas id="trendChart" height="120" aria-label="Applications and revenue over the last 14 days"></canvas>
    </div>
    <div class="card">
      <h3>Payment status</h3>
      <canvas id="payChart" height="120" aria-label="Payment status breakdown"></canvas>
    </div>
  </div>
  <script>
  (function () {
    var tries = 0;
    function draw() {
      // Wait for the deferred Chart.js CDN, but give up after ~3s so a blocked
      // CDN just leaves the (already-rendered) KPI cards rather than looping.
      if (typeof Chart === 'undefined') { if (tries++ < 25) setTimeout(draw, 120); return; }
      var gold = '#E08A1A', green = '#34D399';
      var tc = document.getElementById('trendChart');
      if (tc) new Chart(tc.getContext('2d'), {
        data: {
          labels: <?= json_encode($trendLabels) ?>,
          datasets: [
            { type: 'bar',  label: 'Applications', data: <?= json_encode($trendApps) ?>, backgroundColor: 'rgba(224,138,26,0.55)', borderRadius: 5, yAxisID: 'y' },
            { type: 'line', label: 'Revenue (₦)', data: <?= json_encode($trendRev) ?>, borderColor: green, backgroundColor: 'rgba(52,211,153,0.15)', tension: 0.35, fill: true, yAxisID: 'y1' }
          ]
        },
        options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
          plugins: { legend: { labels: { color: '#9A8A78' } } },
          scales: {
            x:  { grid: { display: false }, ticks: { color: '#9A8A78', maxRotation: 0, autoSkip: true } },
            y:  { position: 'left',  beginAtZero: true, grid: { color: 'rgba(255,255,255,0.06)' }, ticks: { color: '#9A8A78', precision: 0 }, title: { display: true, text: 'Applications', color: '#9A8A78' } },
            y1: { position: 'right', beginAtZero: true, grid: { display: false }, ticks: { color: '#9A8A78' }, title: { display: true, text: '₦', color: '#9A8A78' } }
          } }
      });
      var pc = document.getElementById('payChart');
      var mix = <?= json_encode($payMix) ?>;
      var labels = Object.keys(mix), vals = labels.map(function (k) { return mix[k]; });
      var palette = { succeeded: green, pending: '#FBBF24', failed: '#FB7185' };
      if (pc) {
        if (!labels.length) { pc.parentNode.innerHTML += '<p class="muted" style="text-align:center">No payments yet.</p>'; return; }
        new Chart(pc.getContext('2d'), {
          type: 'doughnut',
          data: { labels: labels, datasets: [{ data: vals, backgroundColor: labels.map(function (k) { return palette[k] || '#A5B4FC'; }), borderColor: 'rgba(0,0,0,0.25)', borderWidth: 2 }] },
          options: { responsive: true, maintainAspectRatio: false, cutout: '60%', plugins: { legend: { position: 'bottom', labels: { color: '#9A8A78', padding: 12 } } } }
        });
      }
    }
    draw();
  })();
  </script>

  <div class="card">
    <h3>Recent Applications</h3>
    <table>
      <thead><tr><th>Reference</th><th>Student</th><th>Pathway</th><th>Mode</th><th>USD</th><th>Status</th><th>When</th></tr></thead>
      <tbody>
      <?php foreach (mam_db_all('SELECT a.*, c.first_name, c.last_name, c.email FROM applications a LEFT JOIN customers c ON c.id=a.customer_id ORDER BY a.created_at DESC LIMIT 10') as $r): ?>
        <tr>
          <td class="mono"><?= htmlspecialchars($r['reference']) ?></td>
          <td><strong><?= htmlspecialchars(mam_name($r['first_name'] ?? null, $r['last_name'] ?? null)) ?></strong><br><span class="muted"><?= htmlspecialchars((string)($r['email'] ?? '')) ?></span></td>
          <td><?= htmlspecialchars((string)($r['pathway'] ?? '')) ?></td>
          <td class="muted"><?= htmlspecialchars((string)($r['mode'] ?? '')) ?></td>
          <td>$<?= number_format((float)$r['total_usd'], 0) ?></td>
          <td><span class="pill <?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
          <td class="muted"><?= htmlspecialchars(substr($r['created_at'] ?? '', 0, 16)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($view === 'applications'): ?>
  <header class="top">
    <h1>Applications</h1>
    <a class="btn ghost" href="?v=export&type=applications.csv">⬇ Export CSV</a>
  </header>
  <?php if (isset($_GET['ok'])): ?><div class="card" style="border-color:#34D399"><strong style="color:#34D399">Saved.</strong></div><?php endif; ?>
  <?php if (isset($_GET['err'])): ?><div class="card" style="border-color:#FCA5A5"><strong style="color:#FCA5A5"><?= htmlspecialchars((string)$_GET['err']) ?></strong></div><?php endif; ?>
  <?php
    /* ── A2 admissions pipeline: per-application detail card (shown when ?app=N) ──
       The enforced state machine, notes and assignee live in api/lib/admissions.php;
       this card is the staff cockpit over them. Managers reach it too (the
       app_note/app_assign actions are operational, not super-only). */
    $manageId  = (int)($_GET['app'] ?? 0);
    $manageApp = $manageId ? mam_db_row('SELECT a.*, c.first_name, c.last_name, c.email, c.phone FROM applications a LEFT JOIN customers c ON c.id = a.customer_id WHERE a.id = ?', [$manageId]) : null;
    if ($manageApp):
      $mPay    = (string)(mam_db_row('SELECT status FROM payments WHERE application_id = ? ORDER BY id DESC LIMIT 1', [$manageId])['status'] ?? '');
      $mStage  = mam_application_stage((string)$manageApp['status'], $mPay);
      $mNext   = mam_admissions_transitions()[(string)$manageApp['status']] ?? [];
      $mNotes  = mam_admissions_notes($manageId);
      $mEvents = mam_admissions_events($manageId);
      $mStaff  = mam_lms_instructors();
  ?>
  <div class="card" style="border-color:var(--gold)">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
      <div>
        <h2 style="margin:0 0 4px">Manage application
          <span class="mono muted" style="font-size:.8rem">#<?= (int)$manageApp['id'] ?> · <?= htmlspecialchars((string)$manageApp['reference']) ?></span>
        </h2>
        <div><strong><?= htmlspecialchars(mam_name($manageApp['first_name'] ?? null, $manageApp['last_name'] ?? null)) ?></strong>
          <span class="muted">· <?= htmlspecialchars((string)($manageApp['email'] ?? '')) ?></span></div>
        <div style="margin-top:6px">
          <span class="pill <?= htmlspecialchars((string)$manageApp['status']) ?>"><?= htmlspecialchars((string)$manageApp['status']) ?></span>
          <span class="pill" style="background:var(--panel)"><?= htmlspecialchars($mStage['label']) ?></span>
        </div>
      </div>
      <a class="btn ghost" href="?v=applications">✕ Close</a>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px;margin-top:18px">
      <div>
        <h3 style="margin:0 0 8px">Decision</h3>
        <?php if ($mNext): ?>
          <form method="post" style="display:flex;flex-direction:column;gap:8px">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="app_status">
            <input type="hidden" name="id" value="<?= (int)$manageApp['id'] ?>">
            <input type="hidden" name="app" value="<?= (int)$manageApp['id'] ?>">
            <label>Move to
              <select name="status">
                <?php foreach ($mNext as $s): ?><option value="<?= $s ?>"><?= htmlspecialchars($s) ?></option><?php endforeach; ?>
              </select>
            </label>
            <label>Reason <span class="muted">(optional, recorded in the audit trail)</span>
              <input type="text" name="reason" maxlength="255" placeholder="e.g. strong audition sample">
            </label>
            <button class="btn" type="submit">Apply decision</button>
          </form>
        <?php else: ?>
          <p class="muted">This application is in a terminal state (<?= htmlspecialchars((string)$manageApp['status']) ?>) — no further moves.</p>
        <?php endif; ?>

        <h3 style="margin:18px 0 8px">Assignee</h3>
        <form method="post" style="display:flex;gap:8px;align-items:flex-end">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="app_assign">
          <input type="hidden" name="app_id" value="<?= (int)$manageApp['id'] ?>">
          <label style="flex:1">Handled by
            <select name="assignee_id">
              <option value="0">— Unassigned —</option>
              <?php foreach ($mStaff as $st): ?>
                <option value="<?= (int)$st['id'] ?>" <?= (int)($manageApp['assignee_id'] ?? 0) === (int)$st['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$st['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button class="btn ghost" type="submit">Save</button>
        </form>

        <?php if (function_exists('mam_ai_enabled') && mam_ai_enabled('placement')): ?>
          <h3 style="margin:18px 0 8px">AI placement assist <span class="muted" style="font-size:.7rem">(advisory)</span></h3>
          <button type="button" class="btn ghost" id="aiPlaceBtn" data-app="<?= (int)$manageApp['id'] ?>">✨ Suggest pathway &amp; level</button>
          <div id="aiPlaceOut" style="margin-top:10px;font-size:.82rem"></div>
          <script>
          (function () {
            var btn = document.getElementById('aiPlaceBtn'), out = document.getElementById('aiPlaceOut');
            if (!btn) return;
            function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c]; }); }
            btn.addEventListener('click', function () {
              btn.disabled = true; var old = btn.textContent; btn.textContent = 'Thinking…';
              fetch('/api/admin/placement?app_id=' + encodeURIComponent(btn.getAttribute('data-app')), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                  btn.disabled = false; btn.textContent = old;
                  if (!j || !j.ok || !j.recommendation || !j.recommendation.json) { out.innerHTML = '<span style="color:var(--muted)">No suggestion available right now.</span>'; return; }
                  var rec = j.recommendation.json;
                  out.innerHTML = '<div style="padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg)">'
                    + '<div><strong>Pathway:</strong> ' + esc(rec.recommended_pathway || '—') + ' &nbsp; <strong>Level:</strong> ' + esc(rec.recommended_level || '—') + ' &nbsp; <span class="pill">' + esc(rec.confidence || '') + '</span></div>'
                    + '<div style="margin-top:6px;color:var(--muted)">' + esc(rec.rationale || '') + '</div>'
                    + '<div style="margin-top:6px;font-size:.68rem;color:var(--muted)">Advisory only · ' + esc(j.recommendation.provider || '') + ' · confirm at the entry assessment</div></div>';
                })
                .catch(function () { btn.disabled = false; btn.textContent = old; out.innerHTML = '<span style="color:#FCA5A5">Could not reach the AI service.</span>'; });
            });
          })();
          </script>
        <?php endif; ?>
      </div>

      <div>
        <h3 style="margin:0 0 8px">Internal notes <span class="muted" style="font-size:.72rem">(staff-only)</span></h3>
        <form method="post" style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="app_note">
          <input type="hidden" name="app_id" value="<?= (int)$manageApp['id'] ?>">
          <textarea name="note" rows="2" maxlength="4000" placeholder="Add a note — not visible to the applicant…" required></textarea>
          <button class="btn" type="submit" style="align-self:flex-start">Add note</button>
        </form>
        <?php if (!$mNotes): ?>
          <p class="muted">No notes yet.</p>
        <?php else: foreach ($mNotes as $n): ?>
          <div style="border-left:2px solid var(--border);padding:2px 0 2px 10px;margin-bottom:8px">
            <div><?= nl2br(htmlspecialchars((string)$n['note'])) ?></div>
            <div class="muted" style="font-size:.72rem"><?= htmlspecialchars((string)($n['author_name'] ?? 'system')) ?> · <?= htmlspecialchars(substr((string)$n['created_at'], 0, 16)) ?></div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <h3 style="margin:18px 0 8px">Status history</h3>
    <?php if (!$mEvents): ?>
      <p class="muted">No status changes recorded yet.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>When</th><th>From</th><th>To</th><th>By</th><th>Reason</th></tr></thead>
        <tbody>
        <?php foreach ($mEvents as $e): ?>
          <tr>
            <td class="muted"><?= htmlspecialchars(substr((string)$e['created_at'], 0, 16)) ?></td>
            <td><span class="muted"><?= htmlspecialchars((string)($e['from_status'] ?? '—')) ?></span></td>
            <td><span class="pill <?= htmlspecialchars((string)$e['to_status']) ?>"><?= htmlspecialchars((string)$e['to_status']) ?></span></td>
            <td class="muted"><?= htmlspecialchars((string)($e['actor_name'] ?? 'system')) ?></td>
            <td class="muted"><?= htmlspecialchars((string)($e['reason'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <div class="card">
    <table>
      <thead><tr><th>Photo</th><th>Reference</th><th>Student</th><th>Programme</th><th>Pathway · Mode</th><th>Course</th><th>Session</th><th>Age</th><th>Total</th><th>Status</th><th>Created</th><th></th></tr></thead>
      <tbody>
      <?php $rows = mam_db_all('SELECT a.*, c.first_name, c.last_name, c.email, c.phone, c.sex, c.address, c.lga, c.state, c.country, c.age_range FROM applications a LEFT JOIN customers c ON c.id=a.customer_id ORDER BY a.created_at DESC LIMIT 200');
      if (!$rows): ?>
        <tr><td colspan="12" class="empty">No applications yet</td></tr>
      <?php endif;
      $sessionLabels = ['toni' => 'TONI', 'medi' => 'MEDI', 'summer' => 'SUMMER', 'domi' => 'DOMI'];
      foreach ($rows as $r):
        $loc = implode(', ', array_filter([$r['lga'] ?? '', $r['state'] ?? '', $r['country'] ?? ''])); ?>
        <tr>
          <td>
            <?php if (!empty($r['headshot_url'])): ?>
              <a href="<?= htmlspecialchars($r['headshot_url']) ?>" target="_blank" rel="noopener"><img src="<?= htmlspecialchars($r['headshot_url']) ?>" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:1px solid var(--border-gold,#5a4327)"></a>
            <?php else: ?>
              <span class="muted" title="No headshot uploaded" style="display:inline-flex;width:40px;height:40px;border-radius:50%;align-items:center;justify-content:center;background:var(--panel);border:1px dashed var(--border)"><i class="fa-solid fa-user" aria-hidden="true"></i></span>
            <?php endif; ?>
          </td>
          <td class="mono"><?= htmlspecialchars($r['reference']) ?></td>
          <td><strong><?= htmlspecialchars(mam_name($r['first_name'] ?? null, $r['last_name'] ?? null)) ?></strong>
            <?php if (!empty($r['sex'])): ?><span class="muted">· <?= htmlspecialchars(ucfirst((string)$r['sex'])) ?></span><?php endif; ?>
            <br><span class="muted"><?= htmlspecialchars((string)($r['email'] ?? '')) ?></span>
            <br><span class="muted"><?= htmlspecialchars((string)($r['phone'] ?? '')) ?></span>
            <?php if ($loc): ?><br><span class="muted"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <?= htmlspecialchars($loc) ?></span><?php endif; ?>
          </td>
          <td class="muted"><?= htmlspecialchars((string)($r['program'] ?? '—')) ?></td>
          <td><?= htmlspecialchars((string)($r['pathway'] ?? '')) ?> · <span class="muted"><?= htmlspecialchars((string)($r['mode'] ?? '')) ?></span></td>
          <td><?= htmlspecialchars((string)($r['discipline'] ?? '')) ?></td>
          <td><?php $s = strtolower((string)($r['session'] ?? '')); echo $s ? '<span class="pill">' . htmlspecialchars($sessionLabels[$s] ?? strtoupper($s)) . '</span>' : '<span class="muted">—</span>'; ?></td>
          <td class="muted"><?= htmlspecialchars((string)($r['age_range'] ?? '')) ?></td>
          <td>$<?= number_format((float)$r['total_usd'], 2) ?></td>
          <td><span class="pill <?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span>
            <?php if (!empty($r['recovery_sent_at'])): ?><br><span class="muted" title="Recovery email sent" style="font-size:0.66rem"><i class="fa-solid fa-envelope-circle-check"></i> nudged</span><?php endif; ?>
          </td>
          <td class="muted"><?= htmlspecialchars(substr($r['created_at'] ?? '', 0, 16)) ?></td>
          <td style="white-space:nowrap">
            <a class="tiny btn ghost" href="?v=applications&app=<?= (int)$r['id'] ?>" title="Open the admissions cockpit (decision, notes, assignee, history)">⚙ Manage</a>
            <form class="inline" method="post" style="display:inline-flex;gap:6px;align-items:center">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="app_status">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <select name="status" class="tiny">
                <?php foreach (['submitted', 'in_review', 'accepted', 'waitlisted', 'paid', 'enrolled', 'cancelled'] as $s): ?>
                  <option value="<?= $s ?>" <?= $s === $r['status'] ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
              </select>
              <button class="tiny" type="submit">Save</button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Re-send the confirmation email and invoice to <?= htmlspecialchars((string)($r['email'] ?? '')) ?>?');">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="resend_confirmation">
              <input type="hidden" name="app_id" value="<?= (int)$r['id'] ?>">
              <button class="tiny btn ghost" type="submit" title="Resend confirmation email + invoice">✉ Resend</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($view === 'funnel'): ?>
  <?php
    $fDays = (int)($_GET['days'] ?? 30); if (!in_array($fDays, [7, 30, 90, 365], true)) $fDays = 30;
    $rep = mam_funnel_report($fDays);
    $pct = function ($n, $d) { return $d > 0 ? round(100 * $n / $d) : 0; };
    $stepLabels = [0 => 'Programme & pathway', 1 => 'Your details', 2 => 'Experience & goals', 3 => 'Review', 4 => 'Payment'];
  ?>
  <header class="top">
    <h1>Apply Funnel</h1>
    <div>
      <?php foreach ([7 => '7d', 30 => '30d', 90 => '90d', 365 => '1y'] as $d => $lbl): ?>
        <a class="btn <?= $fDays === $d ? '' : 'ghost' ?>" href="?v=funnel&days=<?= $d ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
  </header>
  <div class="card" style="font-size:.82rem;color:var(--muted)">
    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i> First-party only — no third-party pixels, Do-Not-Track is honoured, and we count
    <strong>anonymous</strong> funnel sessions (never identities). Showing the last <strong><?= (int)$rep['days'] ?> days</strong>.
  </div>

  <?php $anyData = $rep['started'] || $rep['submitted'] || $rep['paid'] || $rep['steps'] || $rep['totals']; ?>
  <?php if (!$anyData): ?>
    <div class="card"><p class="empty">No funnel events recorded in this window yet. Events start flowing as applicants use the apply flow (with Do-Not-Track off).</p></div>
  <?php else: ?>

  <div class="kpis" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin:6px 0 4px">
    <div class="kpi"><div class="kpi-label">Started</div><div class="kpi-value"><?= number_format($rep['started']) ?></div></div>
    <div class="kpi"><div class="kpi-label">Submitted</div><div class="kpi-value"><?= number_format($rep['submitted']) ?></div>
      <div class="muted" style="font-size:.72rem"><?= $pct($rep['submitted'], $rep['started']) ?>% of started</div></div>
    <div class="kpi"><div class="kpi-label">Paid (booking fee)</div><div class="kpi-value"><?= number_format($rep['paid']) ?></div>
      <div class="muted" style="font-size:.72rem"><?= $pct($rep['paid'], $rep['submitted']) ?>% of submitted · <?= $pct($rep['paid'], $rep['started']) ?>% overall</div></div>
  </div>

  <div class="card">
    <h3 style="margin:0 0 10px">Step drop-off</h3>
    <?php if (!$rep['steps']): ?>
      <p class="muted">No per-step events yet.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>Step</th><th>Reached</th><th>Completed</th><th>Dropped</th><th>Drop-off</th></tr></thead>
        <tbody>
        <?php foreach ($rep['steps'] as $s): ?>
          <tr>
            <td><strong>Step <?= (int)$s['step'] + 1 ?></strong> <span class="muted">· <?= htmlspecialchars($stepLabels[$s['step']] ?? '') ?></span></td>
            <td><?= number_format($s['viewed']) ?></td>
            <td><?= number_format($s['completed']) ?></td>
            <td><?= number_format($s['dropped']) ?></td>
            <td>
              <?php $d = $pct($s['dropped'], $s['viewed']); ?>
              <span class="pill <?= $d >= 50 ? 'cancelled' : ($d >= 25 ? 'submitted' : 'enrolled') ?>"><?= $d ?>%</span>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3 style="margin:0 0 10px">Conversion by pathway</h3>
    <?php if (!$rep['by_pathway']): ?>
      <p class="muted">No pathway-tagged submissions yet.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>Pathway</th><th>Submitted</th><th>Paid</th><th>Pay rate</th></tr></thead>
        <tbody>
        <?php foreach ($rep['by_pathway'] as $p): ?>
          <tr>
            <td><?= htmlspecialchars($p['pathway']) ?></td>
            <td><?= number_format($p['submitted']) ?></td>
            <td><?= number_format($p['paid']) ?></td>
            <td><span class="pill"><?= $pct($p['paid'], $p['submitted']) ?>%</span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <?php if ($rep['totals']): ?>
  <div class="card" style="font-size:.78rem;color:var(--muted)">
    <strong>Raw event totals</strong> (independent of session stitching):
    <?php $bits = []; foreach ($rep['totals'] as $ev => $n) $bits[] = htmlspecialchars($ev) . ' = ' . number_format($n); echo implode(' · ', $bits); ?>
  </div>
  <?php endif; ?>

  <?php endif; ?>

<?php elseif ($view === 'payments'): ?>
  <header class="top">
    <h1>Payments</h1>
    <a class="btn ghost" href="?v=export&type=payments.csv">⬇ Export CSV</a>
  </header>
  <?php if (isset($_GET['ok'])): ?><div class="card" style="border-color:#34D399"><strong style="color:#34D399">Done.</strong></div><?php endif; ?>
  <?php if (isset($_GET['err'])): ?><div class="card" style="border-color:#FCA5A5"><strong style="color:#FCA5A5"><?= htmlspecialchars($_GET['err']) ?></strong></div><?php endif; ?>
  <div class="card">
    <table>
      <thead><tr><th>Reference</th><th>Purpose</th><th>Provider</th><th>Amount</th><th>Status</th><th>Provider Ref</th><th>Verified</th><th>Created</th><th>Actions</th></tr></thead>
      <tbody>
      <?php $rows = mam_db_all('SELECT * FROM payments ORDER BY created_at DESC LIMIT 200');
      if (!$rows): ?><tr><td colspan="9" class="empty">No payments yet</td></tr><?php endif;
      foreach ($rows as $r): ?>
        <tr>
          <td class="mono"><?= htmlspecialchars($r['reference']) ?></td>
          <td><span class="pill"><?= htmlspecialchars((string)($r['purpose'] ?? 'tuition')) ?></span></td>
          <td><?= htmlspecialchars($r['provider']) ?></td>
          <td><?= number_format((float)$r['amount']) ?> <?= htmlspecialchars($r['currency']) ?></td>
          <td><span class="pill <?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
          <td class="mono muted"><?= htmlspecialchars((string)$r['provider_reference']) ?></td>
          <td class="muted"><?= htmlspecialchars(substr((string)$r['verified_at'], 0, 16)) ?></td>
          <td class="muted"><?= htmlspecialchars(substr($r['created_at'], 0, 16)) ?></td>
          <td>
            <?php if ($r['status'] === 'succeeded'): ?>
              <form method="post" onsubmit="return confirm('Mark this payment as REFUNDED? The customer\'s record (application or order) will be reversed accordingly. Process the actual refund with the provider separately.');" style="display:inline-flex;gap:6px;align-items:center">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="payment_refund">
                <input type="hidden" name="payment_id" value="<?= (int)$r['id'] ?>">
                <input type="text" name="note" class="tiny" placeholder="reason / ticket id" maxlength="120">
                <button class="tiny btn ghost" style="border-color:#FCA5A5;color:#FCA5A5" type="submit">Refund</button>
              </form>
            <?php elseif ($r['status'] === 'pending_bank_transfer'): ?>
              <form method="post" onsubmit="return confirm('Confirm you have received this bank transfer? The customer will be marked as paid and (for store orders) delivered.');" style="display:inline-flex;gap:6px;align-items:center">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="payment_confirm_bank">
                <input type="hidden" name="payment_id" value="<?= (int)$r['id'] ?>">
                <input type="text" name="note" class="tiny" placeholder="bank ref / depositor" maxlength="120">
                <button class="tiny btn" style="background:linear-gradient(135deg,#34D399,#10B981);color:#0a3022" type="submit">✓ Confirm</button>
              </form>
            <?php else: ?><span class="muted">—</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($view === 'users'): ?>
  <header class="top">
    <h1>Users</h1>
    <a class="btn ghost" href="?v=export&type=users.csv">⬇ Export CSV</a>
  </header>
  <?php
    // Edit user — if ?edit=N is set we render this card hydrated with the row,
    // otherwise we render the blank "Create user" card. Keeps the markup tidy.
    $editUid = (int)($_GET['edit'] ?? 0);
    $editUser = $editUid ? mam_db_row('SELECT id, email, name, phone, role FROM users WHERE id = ?', [$editUid]) : null;
  ?>
  <?php if (isset($_GET['ok'])): ?><div class="card" style="border-color:#34D399"><strong style="color:#34D399">Saved.</strong></div><?php endif; ?>
  <?php if (isset($_GET['err'])): ?><div class="card" style="border-color:#FCA5A5"><strong style="color:#FCA5A5"><?= htmlspecialchars($_GET['err']) ?></strong></div><?php endif; ?>

  <div class="card">
    <h3><?= $editUser ? 'Edit user #' . (int)$editUser['id'] : 'Create user' ?></h3>
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="<?= $editUser ? 'user_edit' : 'user_create' ?>">
      <?php if ($editUser): ?><input type="hidden" name="user_id" value="<?= (int)$editUser['id'] ?>"><?php endif; ?>
      <label>Email<input type="email" name="email" required value="<?= htmlspecialchars((string)($editUser['email'] ?? '')) ?>"></label>
      <label>Full name<input type="text" name="name" required value="<?= htmlspecialchars((string)($editUser['name'] ?? '')) ?>"></label>
      <label>Phone<input type="text" name="phone" value="<?= htmlspecialchars((string)($editUser['phone'] ?? '')) ?>"></label>
      <label>Role
        <select name="role" required>
          <?php foreach (['student','instructor','admin'] as $r): ?>
            <option value="<?= $r ?>" <?= ($editUser['role'] ?? 'student') === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php if (!$editUser): ?>
        <label>Temporary password<input type="text" name="password" minlength="8" required placeholder="≥ 8 chars"></label>
      <?php endif; ?>
      <div class="actions">
        <button class="btn" type="submit"><?= $editUser ? 'Save changes' : 'Create user' ?></button>
        <?php if ($editUser): ?><a class="btn ghost" href="?v=users">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>
  <div class="card">
    <h3>All users</h3>
    <table>
      <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last login</th><th>Created</th><th>Reset password</th><th></th></tr></thead>
      <tbody>
      <?php
      $roleFilter = $_GET['role'] ?? '';
      $where = ''; $params = [];
      if (in_array($roleFilter, ['admin','instructor','student'], true)) { $where = 'WHERE role = ?'; $params = [$roleFilter]; }
      $users = mam_db_all("SELECT id, email, name, phone, role, status, last_login, created_at, admin_tier FROM users $where ORDER BY created_at DESC LIMIT 500", $params);
      if (!$users): ?><tr><td colspan="8" class="empty">No users yet</td></tr><?php endif;
      foreach ($users as $u): ?>
        <tr>
          <td><strong><?= htmlspecialchars((string)($u['name'] ?? '—')) ?></strong><br><span class="muted mono">#<?= (int)$u['id'] ?></span></td>
          <td><?= htmlspecialchars($u['email']) ?><br><span class="muted"><?= htmlspecialchars((string)($u['phone'] ?? '')) ?></span></td>
          <td><span class="pill <?= htmlspecialchars($u['role']) ?>"><?= htmlspecialchars($u['role']) ?></span>
            <?php if ($u['role'] === 'admin'): ?>
              <form class="inline" method="post" style="margin-top:6px;display:flex;gap:4px;align-items:center" title="Admin access tier">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="set_admin_tier">
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <select name="tier" class="tiny">
                  <option value="super" <?= (($u['admin_tier'] ?? 'super') !== 'manager') ? 'selected' : '' ?>>super</option>
                  <option value="manager" <?= (($u['admin_tier'] ?? '') === 'manager') ? 'selected' : '' ?>>manager</option>
                </select>
                <button class="tiny" type="submit">Tier</button>
              </form>
            <?php endif; ?>
          </td>
          <td><span class="pill <?= htmlspecialchars($u['status']) ?>"><?= htmlspecialchars($u['status']) ?></span></td>
          <td class="muted"><?= htmlspecialchars(substr((string)($u['last_login'] ?? ''), 0, 16)) ?></td>
          <td class="muted"><?= htmlspecialchars(substr((string)$u['created_at'], 0, 16)) ?></td>
          <td>
            <form class="inline" method="post">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="user_reset">
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <input type="text" name="password" class="tiny" placeholder="new password" minlength="8" required>
              <button class="tiny" type="submit">Reset</button>
            </form>
          </td>
          <td>
            <form class="inline" method="post" style="display:inline-flex;gap:6px;align-items:center">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="user_status">
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <select name="status" class="tiny">
                <option value="active" <?= $u['status']==='active'?'selected':'' ?>>active</option>
                <option value="suspended" <?= $u['status']==='suspended'?'selected':'' ?>>suspended</option>
              </select>
              <button class="tiny" type="submit">Save</button>
            </form>
            <a class="tiny btn ghost" style="margin-left:6px;padding:6px 10px" href="?v=users&edit=<?= (int)$u['id'] ?>">Edit</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($view === 'classes'): ?>
  <header class="top"><h1>Classes</h1></header>
  <?php
    $instructors = mam_db_all("SELECT id, name, email FROM users WHERE role='instructor' AND status='active' ORDER BY name");
    $editCid = (int)($_GET['edit'] ?? 0);
    $editClass = $editCid ? mam_db_row('SELECT * FROM classes WHERE id = ?', [$editCid]) : null;
  ?>
  <?php if (isset($_GET['ok'])): ?><div class="card" style="border-color:#34D399"><strong style="color:#34D399">Saved.</strong></div><?php endif; ?>
  <?php if (isset($_GET['err'])): ?><div class="card" style="border-color:#FCA5A5"><strong style="color:#FCA5A5"><?= htmlspecialchars($_GET['err']) ?></strong></div><?php endif; ?>

  <div class="card">
    <h3><?= $editClass ? 'Edit class #' . (int)$editClass['id'] : 'Create class' ?></h3>
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="<?= $editClass ? 'class_edit' : 'class_create' ?>">
      <?php if ($editClass): ?><input type="hidden" name="class_id" value="<?= (int)$editClass['id'] ?>"><?php endif; ?>
      <label class="full">Title<input type="text" name="title" required maxlength="120" value="<?= htmlspecialchars((string)($editClass['title'] ?? '')) ?>"></label>
      <label>Discipline<input type="text" name="discipline" required maxlength="32" placeholder="piano · voice · …" value="<?= htmlspecialchars((string)($editClass['discipline'] ?? '')) ?>"></label>
      <label>Pathway
        <select name="pathway">
          <?php $pw = (string)($editClass['pathway'] ?? ''); foreach (['' => '—', 'metamusic' => 'MetaMusic Order', 'powerplay' => 'Power Play', 'auditer' => 'MAM Auditer'] as $v => $lbl): ?>
            <option value="<?= htmlspecialchars($v) ?>" <?= $pw === $v ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Instructor
        <select name="instructor_id">
          <option value="">— unassigned —</option>
          <?php $in = (int)($editClass['instructor_id'] ?? 0); foreach ($instructors as $i): ?>
            <option value="<?= (int)$i['id'] ?>" <?= $in === (int)$i['id'] ? 'selected' : '' ?>><?= htmlspecialchars($i['name'] ?: $i['email']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Mode
        <select name="mode">
          <?php $md = (string)($editClass['mode'] ?? ''); foreach (['' => '—', 'online' => 'Online', 'onsite' => 'On-site', 'hybrid' => 'Hybrid'] as $v => $lbl): ?>
            <option value="<?= htmlspecialchars($v) ?>" <?= $md === $v ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Capacity<input type="number" name="capacity" value="<?= htmlspecialchars((string)($editClass['capacity'] ?? 12)) ?>" min="1" max="500"></label>
      <label>Starts on<input type="date" name="starts_on" value="<?= htmlspecialchars(substr((string)($editClass['starts_on'] ?? ''), 0, 10)) ?>"></label>
      <label class="full">Schedule<input type="text" name="schedule" placeholder="Sat 10:00–11:30, Wed 18:00–19:00" value="<?= htmlspecialchars((string)($editClass['schedule'] ?? '')) ?>"></label>
      <div class="actions">
        <button class="btn" type="submit"><?= $editClass ? 'Save changes' : 'Create class' ?></button>
        <?php if ($editClass): ?><a class="btn ghost" href="?v=classes">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>
  <div class="card">
    <h3>All classes</h3>
    <table>
      <thead><tr><th>Title</th><th>Discipline</th><th>Pathway</th><th>Instructor</th><th>Mode</th><th>Capacity</th><th>Enrolled</th><th>Starts</th><th>Status</th></tr></thead>
      <tbody>
      <?php
      $classes = mam_db_all(
        "SELECT c.*, u.name AS instructor_name, (SELECT COUNT(*) FROM enrollments e WHERE e.class_id = c.id AND e.status='active') AS enrolled_count
         FROM classes c LEFT JOIN users u ON u.id = c.instructor_id ORDER BY c.created_at DESC LIMIT 200"
      );
      if (!$classes): ?><tr><td colspan="9" class="empty">No classes yet</td></tr><?php endif;
      foreach ($classes as $c): ?>
        <tr>
          <td><strong><?= htmlspecialchars($c['title']) ?></strong><br><span class="muted mono">#<?= (int)$c['id'] ?></span></td>
          <td><?= htmlspecialchars((string)($c['discipline'] ?? '—')) ?></td>
          <td class="muted"><?= htmlspecialchars((string)($c['pathway'] ?? '—')) ?></td>
          <td><?= htmlspecialchars((string)($c['instructor_name'] ?? '— unassigned —')) ?></td>
          <td class="muted"><?= htmlspecialchars((string)($c['mode'] ?? '—')) ?></td>
          <td><?= (int)$c['capacity'] ?></td>
          <td><?= (int)$c['enrolled_count'] ?></td>
          <td class="muted"><?= htmlspecialchars((string)($c['starts_on'] ?? '')) ?></td>
          <td style="white-space:nowrap">
            <form class="inline" method="post" style="display:inline-flex;gap:6px;align-items:center">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="class_status">
              <input type="hidden" name="class_id" value="<?= (int)$c['id'] ?>">
              <select name="status" class="tiny">
                <?php foreach (['active','paused','archived'] as $s): ?>
                  <option value="<?= $s ?>" <?= $s === $c['status'] ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
              </select>
              <button class="tiny" type="submit">Save</button>
            </form>
            <a class="tiny btn ghost" style="margin-left:6px;padding:6px 10px" href="?v=classes&edit=<?= (int)$c['id'] ?>">Edit</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($view === 'enrollments'): ?>
  <header class="top"><h1>Enrollments</h1></header>
  <?php
    $students = mam_db_all("SELECT id, name, email FROM users WHERE role='student' AND status='active' ORDER BY name");
    $classList = mam_db_all("SELECT id, title, discipline FROM classes WHERE status='active' ORDER BY title");
  ?>
  <div class="card">
    <h3>Enroll a student</h3>
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="enroll">
      <label>Student
        <select name="student_id" required>
          <option value="">— pick a student —</option>
          <?php foreach ($students as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars(($s['name'] ?: $s['email'])) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Class
        <select name="class_id" required>
          <option value="">— pick a class —</option>
          <?php foreach ($classList as $cl): ?>
            <option value="<?= (int)$cl['id'] ?>"><?= htmlspecialchars($cl['title']) ?> · <span><?= htmlspecialchars($cl['discipline']) ?></span></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="actions"><button class="btn" type="submit">Enroll</button></div>
    </form>
    <?php if (!$students || !$classList): ?>
      <div style="padding:0 20px 20px"><span class="muted">
        <?php if (!$students): ?>Add a student user first (Users tab).<?php endif; ?>
        <?php if (!$classList): ?>Create an active class first (Classes tab).<?php endif; ?>
      </span></div>
    <?php endif; ?>
  </div>
  <div class="card">
    <h3>All enrollments</h3>
    <table>
      <thead><tr><th>Student</th><th>Class</th><th>Status</th><th>Enrolled</th><th></th></tr></thead>
      <tbody>
      <?php
      $enrolls = mam_db_all(
        "SELECT e.*, u.name AS student_name, u.email AS student_email, c.title AS class_title
         FROM enrollments e JOIN users u ON u.id = e.student_id JOIN classes c ON c.id = e.class_id
         ORDER BY e.created_at DESC LIMIT 300"
      );
      if (!$enrolls): ?><tr><td colspan="5" class="empty">No enrollments yet</td></tr><?php endif;
      foreach ($enrolls as $e): ?>
        <tr>
          <td><strong><?= htmlspecialchars((string)($e['student_name'] ?? '—')) ?></strong><br><span class="muted"><?= htmlspecialchars((string)$e['student_email']) ?></span></td>
          <td><?= htmlspecialchars($e['class_title']) ?></td>
          <td><span class="pill <?= htmlspecialchars($e['status']) ?>"><?= htmlspecialchars($e['status']) ?></span></td>
          <td class="muted"><?= htmlspecialchars(substr((string)$e['created_at'], 0, 16)) ?></td>
          <td>
            <form class="inline" method="post">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="enrollment_status">
              <input type="hidden" name="enrollment_id" value="<?= (int)$e['id'] ?>">
              <select name="status" class="tiny">
                <?php foreach (['active','paused','completed','withdrawn'] as $s): ?>
                  <option value="<?= $s ?>" <?= $s === $e['status'] ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
              </select>
              <button class="tiny" type="submit">Save</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($view === 'courseenroll'): ?>
  <header class="top"><h1>Course Registrations</h1></header>
  <?php if (!empty($_GET['cert'])): ?>
    <div class="banner ok">🎓 Certificate issued — <a href="/api/certificate/<?= urlencode((string)$_GET['cert']) ?>" target="_blank" rel="noopener" style="color:inherit;text-decoration:underline">view it</a>.</div>
  <?php endif; ?>
  <?php
    $lvlName   = ['precert' => 'Pre-Certification', 'cert' => 'Certificate', 'prediploma' => 'Pre-Diploma', 'diploma' => 'Diploma'];
    $nextLabel = ['precert' => 'Certificate', 'cert' => 'Pre-Diploma', 'prediploma' => 'Diploma'];
  ?>
  <?php if (!empty($_GET['promoted'])): ?>
    <div class="banner ok">↑ Advanced to <?= htmlspecialchars($lvlName[(string)$_GET['promoted']] ?? (string)$_GET['promoted']) ?><?= !empty($_GET['dup']) ? ' (already enrolled there)' : '' ?>.</div>
  <?php endif; ?>
  <?php $cohortList = function_exists('mam_lms_cohorts') ? mam_lms_cohorts() : []; ?>
  <?php if ($cohortList): ?>
  <div class="card">
    <h3>Cohorts <span class="muted" style="font-weight:400">· the unified &ldquo;scheduled run&rdquo; grouping (course &times; level &times; programme)</span></h3>
    <table>
      <thead><tr><th>Cohort</th><th>Course / Level</th><th>Instructor</th><th>Students</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($cohortList as $co): ?>
        <tr>
          <td><strong><?= htmlspecialchars((string)($co['title'] ?: ('Cohort #' . $co['id']))) ?></strong><?= !empty($co['legacy_class_id']) ? ' <span class="muted">(from class #' . (int)$co['legacy_class_id'] . ')</span>' : '' ?></td>
          <td><?= htmlspecialchars((string)($co['course_name'] ?? $co['discipline'] ?? '—')) ?><?= !empty($co['level_name']) ? ' &middot; ' . htmlspecialchars((string)$co['level_name']) : '' ?></td>
          <td><?= htmlspecialchars((string)($co['instructor_name'] ?? '—')) ?></td>
          <td><?= (int)($co['enrollment_count'] ?? 0) ?></td>
          <td><span class="pill <?= htmlspecialchars((string)$co['status']) ?>"><?= htmlspecialchars((string)$co['status']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php
    $autoApprove = (int)mam_setting_num('lms_auto_approve', 0) === 1;
    $instructors = mam_lms_instructors();
    $ces = mam_db_all(
      "SELECT ce.id, ce.status, ce.created_at, ce.instructor_id,
              c.name AS course_name, c.kind AS course_kind,
              l.code AS level_code, l.name AS level_name, p.name AS program_name,
              su.name AS student_name, su.email AS student_email,
              r.grade AS result_grade, r.outcome AS result_outcome,
              (SELECT cr.reference FROM certificates cr
                 WHERE cr.student_id = ce.student_id AND cr.program_id = ce.program_id
                   AND (cr.level_id = ce.level_id OR (cr.level_id IS NULL AND ce.level_id IS NULL))
                 LIMIT 1) AS cert_ref
         FROM course_enrollments ce
         JOIN courses c ON c.id = ce.course_id
         LEFT JOIN levels l ON l.id = ce.level_id
         LEFT JOIN programs p ON p.id = ce.program_id
         LEFT JOIN results r ON r.enrollment_id = ce.id
         JOIN users su ON su.id = ce.student_id
         ORDER BY (ce.status = 'pending') DESC, ce.created_at DESC LIMIT 400"
    );
    $pendingCount = 0; foreach ($ces as $r) { if ($r['status'] === 'pending') $pendingCount++; }
  ?>
  <div class="card">
    <h3>Approval policy</h3>
    <p style="padding:0 20px 16px;color:var(--muted);font-size:0.86rem;line-height:1.6">
      Course additions are currently <strong style="color:var(--gold)"><?= $autoApprove ? 'auto-approved' : 'manually reviewed' ?></strong>.
      Toggle <code>lms_auto_approve</code> in <a href="?v=settings" style="color:var(--gold)">Pricing &amp; Settings</a>.
      <?php if ($pendingCount): ?><br><strong style="color:var(--gold)"><?= $pendingCount ?></strong> registration<?= $pendingCount === 1 ? '' : 's' ?> awaiting review.<?php endif; ?>
    </p>
  </div>
  <div class="card">
    <h3>All course registrations</h3>
    <table>
      <thead><tr><th>Student</th><th>Course</th><th>Level · Programme</th><th>Status</th><th>Approve / assign</th></tr></thead>
      <tbody>
      <?php if (!$ces): ?><tr><td colspan="5" class="empty">No course registrations yet</td></tr><?php endif;
      foreach ($ces as $r): ?>
        <tr>
          <td><strong><?= htmlspecialchars((string)($r['student_name'] ?: '—')) ?></strong><br><span class="muted"><?= htmlspecialchars((string)$r['student_email']) ?></span></td>
          <td><?= htmlspecialchars((string)$r['course_name']) ?><?= $r['course_kind'] === 'core' ? ' <span class="pill">core</span>' : '' ?></td>
          <td class="muted"><?= htmlspecialchars((string)($r['level_name'] ?: '—')) ?><?= $r['program_name'] ? ' · ' . htmlspecialchars((string)$r['program_name']) : '' ?></td>
          <td><span class="pill <?= htmlspecialchars((string)$r['status']) ?>"><?= htmlspecialchars((string)$r['status']) ?></span>
            <?php if (!empty($r['result_grade'])): ?><br><span class="muted" style="font-size:0.78rem"><?= htmlspecialchars((string)$r['result_grade']) ?> · <?= htmlspecialchars((string)$r['result_outcome']) ?></span><?php endif; ?>
            <?php if (!empty($r['cert_ref'])): ?><br><a href="/api/certificate/<?= urlencode((string)$r['cert_ref']) ?>" target="_blank" rel="noopener" style="color:var(--gold);font-size:0.78rem">🎓 Certificate</a><?php endif; ?>
          </td>
          <td>
            <form class="inline" method="post">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="course_approve">
              <input type="hidden" name="enrollment_id" value="<?= (int)$r['id'] ?>">
              <select name="instructor_id" class="tiny">
                <option value="">— instructor —</option>
                <?php foreach ($instructors as $ins): ?>
                  <option value="<?= (int)$ins['id'] ?>" <?= (int)$ins['id'] === (int)$r['instructor_id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)($ins['name'] ?: $ins['email'])) ?></option>
                <?php endforeach; ?>
              </select>
              <select name="status" class="tiny">
                <?php foreach (['active','pending','completed','withdrawn'] as $s): ?>
                  <option value="<?= $s ?>" <?= $s === $r['status'] ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
              </select>
              <button class="tiny" type="submit"><?= $r['status'] === 'pending' ? 'Approve' : 'Save' ?></button>
            </form>
            <?php if (($r['result_outcome'] ?? '') === 'pass' && !empty($r['level_code']) && isset($nextLabel[$r['level_code']])): ?>
              <form class="inline" method="post" style="margin-top:6px">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="course_promote">
                <input type="hidden" name="enrollment_id" value="<?= (int)$r['id'] ?>">
                <button class="tiny" type="submit" title="Enrol this student in the same course at the next level">↑ Advance to <?= htmlspecialchars($nextLabel[$r['level_code']]) ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($view === 'modules'): ?>
  <header class="top"><h1>Course Modules</h1></header>
  <?php
    $cat        = mam_lms_catalog();
    $selCourse  = (string)($_GET['course'] ?? '');
    $selLevel   = (string)($_GET['level'] ?? '');
    $selProgram = (string)($_GET['program'] ?? '');
    if (isset($_GET['made'])) echo '<div class="banner ok">Scaffolded ' . (int)$_GET['made'] . ' week(s).</div>';
  ?>
  <div class="card">
    <h3>Choose a course to author</h3>
    <form method="get" class="form-grid">
      <input type="hidden" name="v" value="modules">
      <label>Course
        <select name="course" required onchange="this.form.submit()">
          <option value="">— choose a course —</option>
          <?php foreach ($cat['courses'] as $c): ?>
            <option value="<?= htmlspecialchars($c['code']) ?>" <?= $c['code'] === $selCourse ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?> · <?= htmlspecialchars($c['kind']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Level
        <select name="level" onchange="this.form.submit()">
          <option value="">— level-agnostic —</option>
          <?php foreach ($cat['levels'] as $l): ?>
            <option value="<?= htmlspecialchars($l['code']) ?>" <?= $l['code'] === $selLevel ? 'selected' : '' ?>><?= htmlspecialchars($l['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Programme frame
        <select name="program" onchange="this.form.submit()">
          <option value="">12-week (default)</option>
          <?php foreach ($cat['programs'] as $p): ?>
            <option value="<?= htmlspecialchars($p['code']) ?>" <?= $p['code'] === $selProgram ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?> · <?= (int)$p['weeks'] ?>wk</option>
          <?php endforeach; ?>
        </select>
      </label>
    </form>
  </div>
  <?php
  if ($selCourse !== ''):
    $outline = mam_lms_course_outline($selCourse, $selLevel ?: null, $selProgram ?: null);
    if (!$outline): ?>
      <div class="card"><div class="empty" style="padding:20px">Course not found.</div></div>
    <?php else:
      $hid = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf) . '">'
           . '<input type="hidden" name="course_code" value="' . htmlspecialchars($selCourse) . '">'
           . '<input type="hidden" name="level_code" value="' . htmlspecialchars($selLevel) . '">'
           . '<input type="hidden" name="program_code" value="' . htmlspecialchars($selProgram) . '">';
    ?>
      <div class="card">
        <h3><?= htmlspecialchars($outline['course']['name']) ?><?= $outline['level'] ? ' · ' . htmlspecialchars($outline['level']['name']) : '' ?>
          <span class="muted" style="font-weight:400;font-size:0.8rem">— <?= count($outline['weeks']) ?>-week plan<?= $outline['program'] ? ' (' . htmlspecialchars($outline['program']['name']) . ')' : '' ?></span></h3>
        <div style="padding:0 20px 16px">
          <form method="post" class="inline"><?= $hid ?><input type="hidden" name="action" value="module_scaffold"><button class="btn" type="submit">⚙ Scaffold missing weeks</button></form>
          <span class="muted" style="margin-left:12px;font-size:0.82rem">Creates a placeholder row for every week (modules · revision · exam).</span>
        </div>
        <div style="display:flex;flex-direction:column;gap:10px;padding:0 20px 20px">
        <?php foreach ($outline['weeks'] as $wk): ?>
          <form method="post" style="display:flex;gap:10px;align-items:flex-start;border:1px solid var(--border);border-radius:10px;padding:12px;background:var(--bg)">
            <?= $hid ?>
            <input type="hidden" name="action" value="module_save">
            <input type="hidden" name="module_id" value="<?= (int)($wk['id'] ?? 0) ?>">
            <div style="text-align:center;flex:0 0 46px">
              <div class="muted" style="font-size:0.66rem;letter-spacing:0.1em;text-transform:uppercase">Week</div>
              <input type="number" name="week" value="<?= (int)$wk['week'] ?>" min="1" class="tiny" style="width:46px;text-align:center">
            </div>
            <select name="kind" class="tiny" style="flex:0 0 100px;align-self:center">
              <?php foreach (['module', 'revision', 'exam'] as $k): ?><option value="<?= $k ?>" <?= $k === $wk['kind'] ? 'selected' : '' ?>><?= $k ?></option><?php endforeach; ?>
            </select>
            <div style="flex:1;min-width:0">
              <input name="title" value="<?= htmlspecialchars((string)($wk['title'] ?? '')) ?>" placeholder="<?= $wk['kind'] === 'exam' ? 'Final Examination' : ($wk['kind'] === 'revision' ? 'Revision week' : 'Week ' . $wk['week'] . ' — module title') ?>" style="width:100%;margin-bottom:6px">
              <textarea name="content" rows="2" placeholder="What this week covers (optional)" style="width:100%;resize:vertical"><?= htmlspecialchars((string)($wk['content'] ?? '')) ?></textarea>
            </div>
            <div style="display:flex;flex-direction:column;gap:6px;flex:0 0 auto;align-self:center">
              <button class="tiny" type="submit">Save</button>
            </div>
        </form>
        <?php if (!empty($wk['id'])): ?>
          <form method="post" style="margin-top:-4px;text-align:right;padding-right:4px"><?= $hid ?><input type="hidden" name="action" value="module_delete"><input type="hidden" name="module_id" value="<?= (int)$wk['id'] ?>"><button class="tiny" type="submit" style="background:transparent;color:var(--muted);border:0;cursor:pointer;font-size:0.74rem">✕ remove week <?= (int)$wk['week'] ?></button></form>
        <?php endif; ?>
        <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

<?php elseif ($view === 'messages'): ?>
  <header class="top"><h1>Contact Messages</h1><a class="btn ghost" href="?v=export&type=messages.csv">⬇ Export</a></header>
  <div class="card">
    <table>
      <thead><tr><th>When</th><th>From</th><th>Topic</th><th>Message</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php $rows = mam_db_all('SELECT * FROM messages ORDER BY created_at DESC LIMIT 200');
      if (!$rows): ?><tr><td colspan="6" class="empty">No messages yet</td></tr><?php endif;
      foreach ($rows as $r): ?>
        <tr>
          <td class="muted"><?= htmlspecialchars(substr($r['created_at'], 0, 16)) ?></td>
          <td><strong><?= htmlspecialchars((string)$r['name']) ?></strong><br><span class="muted"><?= htmlspecialchars((string)$r['email']) ?></span></td>
          <td><?= htmlspecialchars((string)$r['topic']) ?></td>
          <td style="max-width:340px"><?= nl2br(htmlspecialchars(substr((string)$r['message'], 0, 280))) ?></td>
          <td><span class="pill <?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
          <td>
            <form class="inline" method="post">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="msg_status">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <select name="status" class="tiny"><option value="unread" <?= $r['status']==='unread'?'selected':'' ?>>unread</option><option value="read" <?= $r['status']==='read'?'selected':'' ?>>read</option></select>
              <button class="tiny" type="submit">Save</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($view === 'webhooks'): ?>
  <header class="top"><h1>Webhook Events</h1></header>
  <div class="card">
    <table>
      <thead><tr><th>When</th><th>Provider</th><th>Event</th><th>Event ID</th><th>Sig</th><th>Status</th></tr></thead>
      <tbody>
      <?php $rows = mam_db_all('SELECT * FROM webhook_events ORDER BY received_at DESC LIMIT 100');
      if (!$rows): ?><tr><td colspan="6" class="empty">No webhooks received yet</td></tr><?php endif;
      foreach ($rows as $r): ?>
        <tr>
          <td class="muted"><?= htmlspecialchars(substr($r['received_at'], 0, 19)) ?></td>
          <td><?= htmlspecialchars($r['provider']) ?></td>
          <td><?= htmlspecialchars((string)$r['event_type']) ?></td>
          <td class="mono muted"><?= htmlspecialchars((string)$r['event_id']) ?></td>
          <td><?= $r['signature_valid'] ? '<span class="pill processed">valid</span>' : '<span class="pill invalid">invalid</span>' ?></td>
          <td><span class="pill <?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($view === 'newsletter'): ?>
  <header class="top"><h1>Newsletter</h1><a class="btn ghost" href="?v=export&type=newsletter.csv">⬇ Export</a></header>
  <div class="card">
    <table>
      <thead><tr><th>Email</th><th>Source</th><th>Status</th><th>When</th></tr></thead>
      <tbody>
      <?php $rows = mam_db_all('SELECT * FROM newsletter ORDER BY created_at DESC LIMIT 500');
      if (!$rows): ?><tr><td colspan="4" class="empty">No subscribers yet</td></tr><?php endif;
      foreach ($rows as $r): ?>
        <tr><td><?= htmlspecialchars($r['email']) ?></td><td class="muted"><?= htmlspecialchars((string)$r['source']) ?></td><td><span class="pill <?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td><td class="muted"><?= htmlspecialchars(substr($r['created_at'], 0, 16)) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($view === 'audit'): ?>
  <header class="top"><h1>Audit Log</h1></header>
  <div class="card">
    <table>
      <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>Meta</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach (mam_db_all('SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 200') as $r): ?>
        <tr>
          <td class="muted"><?= htmlspecialchars(substr($r['created_at'], 0, 19)) ?></td>
          <td><?= htmlspecialchars((string)$r['actor']) ?></td>
          <td><strong><?= htmlspecialchars($r['action']) ?></strong></td>
          <td><?= htmlspecialchars($r['entity']) ?> <span class="muted">#<?= (int)$r['entity_id'] ?></span></td>
          <td class="json-pop"><?= htmlspecialchars((string)$r['meta']) ?></td>
          <td class="mono muted"><?= htmlspecialchars((string)$r['ip']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($view === 'storeitems'):
    // List + create/edit form for store items. The form is the same one for
    // BOTH create and update — when ?edit=N is present we hydrate with that
    // row, otherwise the form starts blank for a new item.
    $editId = (int)($_GET['edit'] ?? 0);
    $edit = $editId ? mam_db_row('SELECT * FROM store_items WHERE id = ?', [$editId]) : null;
    $items = mam_db_all('SELECT id, sku, slug, name, goods_type, price_usd, currency, category, availability, stock, active, sort_order FROM store_items ORDER BY sort_order ASC, id DESC');
?>
  <h1>Store / Library</h1>
  <?php if (isset($_GET['ok'])): ?><div class="card" style="border-color:#34D399"><strong style="color:#34D399">Saved.</strong></div><?php endif; ?>
  <?php if (isset($_GET['err'])): ?><div class="card" style="border-color:#FCA5A5"><strong style="color:#FCA5A5"><?= htmlspecialchars($_GET['err']) ?></strong></div><?php endif; ?>

  <div class="card">
    <h2 style="font-family:Georgia,serif;color:var(--gold);font-size:1.2rem;margin-bottom:14px"><?= $edit ? 'Edit item · #'.(int)$edit['id'] : 'Add a new item' ?></h2>
    <form method="POST" action="" style="display:grid;grid-template-columns:repeat(2,1fr);gap:14px">
      <?= mam_csrf_field() ?>
      <input type="hidden" name="action" value="store_item_save">
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

      <div><label>Name *<br><input type="text" name="name" required value="<?= htmlspecialchars((string)($edit['name'] ?? '')) ?>" placeholder="Theory Handout — Grade 4"></label></div>
      <div><label>SKU *<br><input type="text" name="sku" required value="<?= htmlspecialchars((string)($edit['sku'] ?? '')) ?>" placeholder="MAM-THEORY-G4" style="text-transform:uppercase"></label></div>
      <div><label>Slug<br><input type="text" name="slug" value="<?= htmlspecialchars((string)($edit['slug'] ?? '')) ?>" placeholder="auto-generated from name"></label></div>
      <div><label>Category<br><input type="text" name="category" value="<?= htmlspecialchars((string)($edit['category'] ?? '')) ?>" placeholder="Theory · Sheet Music · Coaching"></label></div>

      <div style="grid-column:1/-1"><label>Description<br><textarea name="description" rows="3" style="width:100%;font-family:inherit"><?= htmlspecialchars((string)($edit['description'] ?? '')) ?></textarea></label></div>

      <div><label>Goods type<br>
        <select name="goods_type">
          <?php foreach (['digital','physical','service'] as $g): ?>
            <option value="<?= $g ?>" <?= ($edit['goods_type'] ?? 'digital') === $g ? 'selected' : '' ?>><?= ucfirst($g) ?></option>
          <?php endforeach; ?>
        </select></label></div>
      <div><label>Delivery type<br>
        <select name="delivery_type">
          <?php foreach (['instant','courier','in_person','scheduled'] as $d): ?>
            <option value="<?= $d ?>" <?= ($edit['delivery_type'] ?? 'instant') === $d ? 'selected' : '' ?>><?= str_replace('_',' ', ucfirst($d)) ?></option>
          <?php endforeach; ?>
        </select></label></div>
      <div><label>Availability<br>
        <select name="availability">
          <?php foreach (['in_stock','preorder','sold_out'] as $a): ?>
            <option value="<?= $a ?>" <?= ($edit['availability'] ?? 'in_stock') === $a ? 'selected' : '' ?>><?= str_replace('_',' ', ucfirst($a)) ?></option>
          <?php endforeach; ?>
        </select></label></div>
      <div><label>Stock (-1 = unlimited)<br><input type="number" name="stock" value="<?= htmlspecialchars((string)($edit['stock'] ?? -1)) ?>"></label></div>

      <div><label>Price (USD) *<br><input type="number" step="0.01" min="0" name="price_usd" required value="<?= htmlspecialchars((string)($edit['price_usd'] ?? '')) ?>"></label></div>
      <div><label>Currency<br><input type="text" name="currency" value="<?= htmlspecialchars((string)($edit['currency'] ?? 'USD')) ?>"></label></div>
      <div><label>Weight (g, physical only)<br><input type="number" name="weight_grams" value="<?= htmlspecialchars((string)($edit['weight_grams'] ?? '')) ?>"></label></div>
      <div><label>Tax rate (e.g. 0.075)<br><input type="number" step="0.001" min="0" name="tax_rate" value="<?= htmlspecialchars((string)($edit['tax_rate'] ?? 0)) ?>"></label></div>

      <div style="grid-column:1/-1"><label>Image URLs <small style="color:var(--muted)">(one per line, or comma-separated)</small><br><textarea name="images" rows="2" style="width:100%;font-family:inherit"><?php
        $imgs = $edit && !empty($edit['images']) ? (json_decode((string)$edit['images'], true) ?: []) : [];
        echo htmlspecialchars(implode("\n", $imgs));
      ?></textarea></label></div>

      <div style="grid-column:1/-1"><label>Available locations <small style="color:var(--muted)">(ISO-2 codes, comma-separated. <code>*</code> = anywhere.)</small><br><input type="text" name="available_locations" value="<?php
        $loc = $edit['available_locations'] ?? '*';
        if ($loc !== '*' && $loc) { $arr = json_decode((string)$loc, true); echo htmlspecialchars(is_array($arr) ? implode(', ', $arr) : '*'); }
        else echo '*';
      ?>"></label></div>

      <div style="grid-column:1/-1"><label>Digital file URL <small style="color:var(--muted)">(private — only paid customers receive it by email)</small><br><input type="url" name="file_url" value="<?= htmlspecialchars((string)($edit['file_url'] ?? '')) ?>" placeholder="https://cdn.example.com/handout.pdf"></label></div>

      <div><label>Sort order<br><input type="number" name="sort_order" value="<?= htmlspecialchars((string)($edit['sort_order'] ?? 0)) ?>"></label></div>
      <div><label style="display:flex;align-items:center;gap:8px;margin-top:24px"><input type="checkbox" name="active" value="1" <?= ($edit['active'] ?? 1) ? 'checked' : '' ?>>Active (visible in store)</label></div>

      <div style="grid-column:1/-1;display:flex;gap:10px"><button class="btn" type="submit"><?= $edit ? 'Save changes' : 'Create item' ?></button>
      <?php if ($edit): ?><a class="btn" style="background:transparent;border:1px solid var(--border);color:var(--muted)" href="?v=storeitems">Cancel edit</a><?php endif; ?></div>
    </form>
  </div>

  <table>
    <thead><tr><th>SKU</th><th>Name</th><th>Type</th><th>Price</th><th>Category</th><th>Stock</th><th>Availability</th><th>Active</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($items as $i): ?>
      <tr>
        <td class="mono"><?= htmlspecialchars($i['sku']) ?></td>
        <td><?= htmlspecialchars($i['name']) ?></td>
        <td><?= htmlspecialchars($i['goods_type']) ?></td>
        <td>$<?= number_format((float)$i['price_usd'], 2) ?> <?= htmlspecialchars($i['currency'] ?? '') ?></td>
        <td><?= htmlspecialchars($i['category'] ?? '') ?></td>
        <td><?= (int)$i['stock'] === -1 ? '∞' : (int)$i['stock'] ?></td>
        <td><?= htmlspecialchars(str_replace('_',' ', $i['availability'])) ?></td>
        <td><?= (int)$i['active'] ? '<span style="color:#34D399">●</span>' : '<span style="color:#FCA5A5">●</span>' ?></td>
        <td style="display:flex;gap:6px">
          <a class="btn" style="padding:6px 10px;font-size:0.78rem" href="?v=storeitems&edit=<?= (int)$i['id'] ?>">Edit</a>
          <form method="POST" action="" onsubmit="return confirm('Deactivate this item? It will hide from the store immediately.');" style="display:inline">
            <?= mam_csrf_field() ?>
            <input type="hidden" name="action" value="store_item_delete">
            <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
            <button class="btn" style="padding:6px 10px;font-size:0.78rem;background:transparent;border:1px solid #FCA5A5;color:#FCA5A5">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; if (!$items): ?><tr><td colspan="9" class="empty">No items yet — create your first one above.</td></tr><?php endif; ?>
    </tbody>
  </table>

<?php elseif ($view === 'storeorders'):
    $orders = mam_db_all('SELECT * FROM store_orders ORDER BY id DESC LIMIT 200');
?>
  <h1>Store Orders</h1>
  <?php if (isset($_GET['ok'])): ?><div class="card" style="border-color:#34D399"><strong style="color:#34D399">Updated.</strong></div><?php endif; ?>
  <table>
    <thead><tr><th>Reference</th><th>Customer</th><th>Items</th><th>Total</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($orders as $o):
      $its = json_decode((string)$o['items_json'], true) ?: [];
      $summary = implode(', ', array_map(function($i){ return $i['name'] . '×' . $i['qty']; }, array_slice($its, 0, 3)));
    ?>
      <tr>
        <td class="mono"><?= htmlspecialchars($o['reference']) ?></td>
        <td>
          <strong><?= htmlspecialchars($o['customer_name']) ?></strong><br>
          <small style="color:var(--muted)"><?= htmlspecialchars($o['customer_email']) ?> <?= $o['customer_country'] ? '· ' . htmlspecialchars($o['customer_country']) : '' ?></small>
        </td>
        <td><small><?= htmlspecialchars($summary) ?><?= count($its) > 3 ? '…' : '' ?></small></td>
        <td>$<?= number_format((float)$o['total_usd'], 2) ?></td>
        <td>
          <form method="POST" action="" style="display:flex;gap:6px">
            <?= mam_csrf_field() ?>
            <input type="hidden" name="action" value="store_order_status">
            <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
            <select name="status" onchange="this.form.submit()">
              <?php foreach (['pending','paid','shipped','delivered','refunded','cancelled'] as $s): ?>
                <option value="<?= $s ?>" <?= $o['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </td>
        <td><small><?= htmlspecialchars((string)$o['created_at']) ?></small></td>
        <td><a class="btn" style="padding:6px 10px;font-size:0.78rem" href="#" onclick="alert(<?= htmlspecialchars(json_encode(json_encode($its, JSON_PRETTY_PRINT))) ?>);return false">View items</a></td>
      </tr>
    <?php endforeach; if (!$orders): ?><tr><td colspan="7" class="empty">No store orders yet.</td></tr><?php endif; ?>
    </tbody>
  </table>

<?php elseif ($view === 'settings'):
    $cur = mam_settings_all();
    $groups = [];
    foreach (mam_settings_schema() as $row) {
        [$group, $key, $label, $default, $suffix] = $row;
        $groups[$group][] = ['key' => $key, 'label' => $label, 'default' => $default, 'suffix' => $suffix];
    }
?>
  <header class="top"><h1>Pricing &amp; Settings</h1></header>
  <?php if (isset($_GET['ok'])): ?><div class="banner ok">Saved. New prices apply to every new application, booking and store order immediately.</div><?php endif; ?>

  <?php $mr = mam_mail_readiness(); ?>
  <div class="card" style="max-width:760px">
    <h3>Email delivery</h3>
    <div style="padding:0 20px 18px">
      <p class="muted" style="margin:0 0 12px;line-height:1.6">
        Transport: <strong style="color:var(--gold)"><?= htmlspecialchars($mr['transport']) ?></strong>
        <?= $mr['host'] ? '· ' . htmlspecialchars($mr['host']) . ':' . (int)$mr['port'] . ' (' . htmlspecialchars($mr['encryption']) . ')' : '· no SMTP host configured' ?>
        · status: <?= $mr['ready'] ? '<strong style="color:#34D399">ready</strong>' : '<strong style="color:#FB7185">not configured</strong>' ?>.
        Send a live test to see the exact SMTP step that fails (port probe → connect → TLS → auth → send).
      </p>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input type="email" id="mtTo" placeholder="you@example.com" style="flex:1;min-width:200px;padding:9px 11px;border-radius:8px;border:1px solid var(--border);background:var(--bg);color:var(--ink)">
        <button class="btn" type="button" id="mtSend">Send test email</button>
      </div>
      <pre id="mtOut" style="display:none;margin:14px 0 0;padding:12px;background:var(--bg);border:1px solid var(--border);border-radius:10px;font-size:0.78rem;line-height:1.55;white-space:pre-wrap;word-break:break-word;max-height:300px;overflow:auto"></pre>
    </div>
  </div>
  <script>
  (function () {
    var btn = document.getElementById('mtSend'), out = document.getElementById('mtOut'), to = document.getElementById('mtTo');
    if (!btn) return;
    btn.addEventListener('click', function () {
      var addr = (to.value || '').trim();
      if (!addr) { to.focus(); return; }
      btn.disabled = true; btn.textContent = 'Sending…';
      out.style.display = 'block'; out.textContent = 'Running probe + send…';
      fetch('/api/admin/mail-test', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': <?= json_encode(mam_csrf_token()) ?> },
        body: JSON.stringify({ to: addr })
      }).then(function (r) { return r.json(); }).then(function (j) {
        btn.disabled = false; btn.textContent = 'Send test email';
        var lines = [];
        if (j.probe && j.probe.attempted) lines.push('PORT PROBE  ' + j.probe.target + '  →  ' + (j.probe.ok ? 'reachable ✓' : 'FAILED: ' + (j.probe.error || '?')));
        lines.push('TRANSPORT   ' + (j.transport || '?') + (j.host ? ' via ' + j.host : ''));
        lines.push('RESULT      ' + (j.sent ? 'SENT ✓ — check the inbox (and spam folder)' : 'FAILED: ' + (j.error || j.message || 'unknown')));
        (j.steps || []).forEach(function (s) { lines.push('  step ' + s.step + '  ' + (s.ok ? 'ok' : 'FAIL') + '  ' + (s.reply || '')); });
        if (!j.sent && j.readiness && j.readiness.issues && j.readiness.issues.length) lines.push('\nHINTS:\n- ' + j.readiness.issues.join('\n- '));
        out.textContent = lines.join('\n');
      }).catch(function (e) {
        btn.disabled = false; btn.textContent = 'Send test email';
        out.textContent = 'Request failed: ' + e;
      });
    });
  })();
  </script>

  <div class="card" style="max-width:760px">
    <h3>Recent email activity</h3>
    <div style="padding:0 20px 18px">
      <p class="muted" style="margin:0 0 12px;line-height:1.6">
        Every transactional email is recorded in the outbox. A failed send is <strong>kept</strong> (not lost) and can be
        re-sent once SMTP is fixed — here with <em>Retry failed</em>, or automatically on a schedule via
        <code>php api/tools/mail-retry.php</code> (cron, every 15&nbsp;min).
      </p>
      <div id="mlChips" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px"></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
        <button class="btn" type="button" id="mlRetry" disabled>Retry failed</button>
        <button class="btn" type="button" id="mlRefresh">Refresh</button>
        <span id="mlMsg" class="muted" style="font-size:0.82rem"></span>
      </div>
      <div id="mlList" style="max-height:340px;overflow:auto;border:1px solid var(--border);border-radius:10px"></div>
    </div>
  </div>
  <script>
  (function () {
    var CSRF = <?= json_encode(mam_csrf_token()) ?>;
    var chips = document.getElementById('mlChips'), list = document.getElementById('mlList'),
        retry = document.getElementById('mlRetry'), refresh = document.getElementById('mlRefresh'), msg = document.getElementById('mlMsg');
    if (!list) return;
    function esc(s) { return (s == null ? '' : String(s)).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function chip(label, n, color) { return '<span style="display:inline-flex;gap:6px;align-items:center;padding:4px 10px;border-radius:999px;background:var(--bg);border:1px solid var(--border);font-size:0.8rem"><strong style="color:' + color + '">' + (n || 0) + '</strong> ' + label + '</span>'; }
    function load() {
      list.innerHTML = '<div class="muted" style="padding:14px">Loading…</div>';
      fetch('/api/admin/mail-log?limit=100', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
        var c = j.counts || {}, entries = j.entries || [], retryable = j.retryable || 0;
        chips.innerHTML = chip('sent', c.sent, '#34D399') + chip('failed', c.failed, '#FB7185') + chip('queued', c.queued, '#FBBF24');
        retry.textContent = retryable > 0 ? ('Retry failed (' + retryable + ')') : 'Retry failed';
        retry.disabled = retryable <= 0;
        if (!entries.length) { list.innerHTML = '<div class="muted" style="padding:14px">No email recorded yet — submit a form or send a test above.</div>'; return; }
        list.innerHTML = entries.map(function (e) {
          var col = e.status === 'sent' ? '#34D399' : (e.status === 'failed' ? '#FB7185' : '#FBBF24');
          return '<div style="padding:10px 12px;border-bottom:1px solid var(--border);font-size:0.82rem">'
            + '<div style="display:flex;justify-content:space-between;gap:10px">'
            + '<strong style="color:' + col + ';text-transform:uppercase;font-size:0.7rem;letter-spacing:0.05em">' + esc(e.status) + '</strong>'
            + '<span class="muted">' + esc(e.created_at) + '</span></div>'
            + '<div style="margin-top:2px">' + esc(e.to_email) + ' · <span class="muted">' + esc(e.context || '') + '</span></div>'
            + '<div class="muted" style="margin-top:1px">' + esc(e.subject || '') + '</div>'
            + (e.error ? '<div style="margin-top:3px;color:#FB7185">' + esc(e.error) + (e.attempts > 1 ? ' · ' + e.attempts + ' attempts' : '') + '</div>' : '')
            + '</div>';
        }).join('');
      }).catch(function (e) { list.innerHTML = '<div class="muted" style="padding:14px">Failed to load: ' + esc(e) + '</div>'; });
    }
    retry.addEventListener('click', function () {
      retry.disabled = true; msg.textContent = 'Re-sending the failed backlog…';
      fetch('/api/admin/mail-retry', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: '{}' })
        .then(function (r) { return r.json(); }).then(function (j) {
          var s = j.retry || {};
          msg.textContent = 'Tried ' + (s.tried || 0) + ' · sent ' + (s.sent || 0) + ' · still failed ' + (s.failed || 0) + '.';
          load();
        }).catch(function (e) { msg.textContent = 'Retry request failed: ' + e; retry.disabled = false; });
    });
    refresh.addEventListener('click', load);
    load();
  })();
  </script>

  <div class="card" style="max-width:760px">
    <p class="muted" style="margin:0 0 18px;line-height:1.6">
      These values are the live source of truth. The server charges from them at checkout, and the
      public site reads them via <code>/api/config/public</code> — so a change here takes effect everywhere
      without a redeploy. Store product prices are managed per-item under <a href="?v=storeitems" style="color:var(--gold)">Store / Library</a>.
    </p>
    <form method="POST" action="">
      <?= mam_csrf_field() ?>
      <input type="hidden" name="action" value="save_settings">
      <?php foreach ($groups as $groupName => $fields): ?>
        <h3 style="margin:22px 0 12px;color:var(--gold);font-size:0.92rem;letter-spacing:0.04em;text-transform:uppercase"><?= htmlspecialchars($groupName) ?></h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">
          <?php foreach ($fields as $f):
            $val = $cur[$f['key']] ?? $f['default'];
            $isNum = is_numeric($f['default']);
          ?>
            <label style="display:block<?= $isNum ? '' : ';grid-column:1/-1' ?>">
              <span style="display:block;font-size:0.82rem;color:var(--muted);margin-bottom:5px"><?= htmlspecialchars($f['label']) ?></span>
              <span style="display:flex;align-items:center;gap:6px">
                <?php if ($f['suffix'] !== ''): ?><span class="mono" style="color:var(--gold);min-width:14px"><?= htmlspecialchars($f['suffix']) ?></span><?php endif; ?>
                <?php if ($isNum): ?>
                <input type="number" step="0.01" min="0" name="<?= htmlspecialchars($f['key']) ?>" value="<?= htmlspecialchars((string)$val) ?>" style="flex:1" required>
                <?php else: ?>
                <input type="text" name="<?= htmlspecialchars($f['key']) ?>" value="<?= htmlspecialchars((string)$val) ?>" style="flex:1" placeholder="<?= htmlspecialchars((string)$f['default']) ?>">
                <?php endif; ?>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
      <div style="margin-top:24px;display:flex;gap:10px;align-items:center">
        <button class="btn" type="submit">Save settings</button>
        <span class="muted" style="font-size:0.8rem">Blank fields revert to the shipped default.</span>
      </div>
    </form>
  </div>

<?php elseif ($view === 'account'):
  $me = mam_current_user();
?>
  <header class="top"><h1>My Account</h1></header>
  <?php if (isset($_GET['ok'])): ?><div class="banner ok">Saved.</div><?php endif; ?>
  <?php if (isset($_GET['err'])): ?><div class="banner err"><?= htmlspecialchars($_GET['err']) ?></div><?php endif; ?>
  <?php if (!$me): ?>
    <div class="card">You're signed in via HTTP Basic Auth (environment credentials), which has no editable account. To get a personal admin login, create a user account at <code>/api/setup</code> or via <code>php api/bin/create-admin.php</code>.</div>
  <?php else: ?>
    <?php if ((int)($me['must_change_password'] ?? 0) === 1): ?>
      <div class="banner err" style="background:rgba(251,191,36,.12);border-color:#FBBF24;color:#FBBF24">You're using a temporary password — please set a new one below.</div>
    <?php endif; ?>
    <div class="card" style="max-width:620px">
      <form method="POST" action="">
        <?= mam_csrf_field() ?>
        <input type="hidden" name="action" value="change_own_account">
        <h3 style="margin:0 0 14px;color:var(--gold);font-size:0.92rem;letter-spacing:.04em;text-transform:uppercase">Profile</h3>
        <label style="display:block;margin-bottom:14px">Full name
          <input type="text" name="name" value="<?= htmlspecialchars((string)($me['name'] ?? '')) ?>" required></label>
        <label style="display:block;margin-bottom:14px">Phone
          <input type="text" name="phone" value="<?= htmlspecialchars((string)($me['phone'] ?? '')) ?>"></label>
        <label style="display:block;margin-bottom:6px">Email
          <input type="email" value="<?= htmlspecialchars((string)($me['email'] ?? '')) ?>" disabled></label>
        <h3 style="margin:24px 0 14px;color:var(--gold);font-size:0.92rem;letter-spacing:.04em;text-transform:uppercase">Change password <span style="text-transform:none;color:var(--muted);font-weight:400">(leave blank to keep current)</span></h3>
        <label style="display:block;margin-bottom:14px">Current password
          <input type="password" name="current" autocomplete="current-password"></label>
        <label style="display:block;margin-bottom:18px">New password (min 8)
          <input type="password" name="next" minlength="8" autocomplete="new-password"></label>
        <button class="btn" type="submit">Save changes</button>
      </form>
    </div>
  <?php endif; ?>

<?php elseif ($view === 'search'):
  $q = trim((string)($_GET['q'] ?? ''));
  $like = '%' . $q . '%';
  $apps = $users = $pays = $orders = [];
  if ($q !== '') {
    try { $apps = mam_db_all("SELECT a.reference, a.pathway, a.status, a.total_usd, a.created_at, c.email, c.first_name, c.last_name
        FROM applications a JOIN customers c ON c.id = a.customer_id
        WHERE a.reference LIKE ? OR c.email LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ?
        ORDER BY a.created_at DESC LIMIT 25", [$like, $like, $like, $like]); } catch (Throwable $e) {}
    try { $users = mam_db_all("SELECT id, email, name, role, status FROM users WHERE email LIKE ? OR name LIKE ? ORDER BY created_at DESC LIMIT 25", [$like, $like]); } catch (Throwable $e) {}
    try { $pays = mam_db_all("SELECT reference, provider, status, amount, currency, created_at FROM payments WHERE reference LIKE ? ORDER BY created_at DESC LIMIT 25", [$like]); } catch (Throwable $e) {}
    try { $orders = mam_db_all("SELECT reference, customer_email, status, total_usd, created_at FROM store_orders WHERE reference LIKE ? OR customer_email LIKE ? ORDER BY id DESC LIMIT 25", [$like, $like]); } catch (Throwable $e) {}
  }
  $hits = count($apps) + count($users) + count($pays) + count($orders);
?>
  <header class="top"><h1>Search</h1></header>
  <form method="get" action="" style="margin-bottom:18px;display:flex;gap:8px;max-width:520px">
    <input type="hidden" name="v" value="search">
    <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Reference, email or name…" autofocus style="flex:1;padding:11px 14px;border-radius:9px;border:1px solid var(--border);background:var(--bg);color:var(--ink)">
    <button class="btn" type="submit">Search</button>
  </form>
  <?php if ($q === ''): ?>
    <div class="card">Type a reference (e.g. <code>MAM-…</code>), an email, or a name to search applications, users, payments and store orders.</div>
  <?php else: ?>
    <p class="muted" style="margin:0 0 16px"><?= $hits ?> result<?= $hits === 1 ? '' : 's' ?> for “<strong><?= htmlspecialchars($q) ?></strong>”.</p>

    <div class="card"><h3>Applications (<?= count($apps) ?>)</h3>
      <?php if ($apps): ?><table><thead><tr><th>Reference</th><th>Applicant</th><th>Pathway</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($apps as $a): ?><tr>
          <td class="mono"><?= htmlspecialchars($a['reference']) ?></td>
          <td><?= htmlspecialchars(mam_name($a['first_name'], $a['last_name'])) ?><br><small class="muted"><?= htmlspecialchars((string)$a['email']) ?></small></td>
          <td><?= htmlspecialchars((string)$a['pathway']) ?></td>
          <td><span class="pill"><?= htmlspecialchars((string)$a['status']) ?></span></td>
          <td><a class="btn ghost" style="padding:5px 10px" href="?v=applications">Open</a> <a class="btn ghost" style="padding:5px 10px" href="/api/invoice/<?= rawurlencode($a['reference']) ?>" target="_blank">Invoice</a></td>
        </tr><?php endforeach; ?></tbody></table>
      <?php else: ?><p class="muted">No matching applications.</p><?php endif; ?>
    </div>

    <div class="card"><h3>Users (<?= count($users) ?>)</h3>
      <?php if ($users): ?><table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($users as $usr): ?><tr>
          <td><?= htmlspecialchars((string)($usr['name'] ?? '—')) ?></td>
          <td class="mono"><?= htmlspecialchars((string)$usr['email']) ?></td>
          <td><?= htmlspecialchars((string)$usr['role']) ?></td>
          <td><span class="pill"><?= htmlspecialchars((string)$usr['status']) ?></span></td>
          <td><a class="btn ghost" style="padding:5px 10px" href="?v=users&edit=<?= (int)$usr['id'] ?>">Edit</a></td>
        </tr><?php endforeach; ?></tbody></table>
      <?php else: ?><p class="muted">No matching users.</p><?php endif; ?>
    </div>

    <div class="card"><h3>Payments (<?= count($pays) ?>)</h3>
      <?php if ($pays): ?><table><thead><tr><th>Reference</th><th>Provider</th><th>Amount</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($pays as $p): ?><tr>
          <td class="mono"><?= htmlspecialchars((string)$p['reference']) ?></td>
          <td><?= htmlspecialchars((string)$p['provider']) ?></td>
          <td><?= htmlspecialchars((string)$p['currency']) ?> <?= number_format((float)$p['amount'], 0) ?></td>
          <td><span class="pill"><?= htmlspecialchars((string)$p['status']) ?></span></td>
        </tr><?php endforeach; ?></tbody></table>
      <?php else: ?><p class="muted">No matching payments.</p><?php endif; ?>
    </div>

    <div class="card"><h3>Store orders (<?= count($orders) ?>)</h3>
      <?php if ($orders): ?><table><thead><tr><th>Reference</th><th>Customer</th><th>Total</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($orders as $o): ?><tr>
          <td class="mono"><?= htmlspecialchars((string)$o['reference']) ?></td>
          <td class="mono"><?= htmlspecialchars((string)$o['customer_email']) ?></td>
          <td>$<?= number_format((float)$o['total_usd'], 0) ?></td>
          <td><span class="pill"><?= htmlspecialchars((string)$o['status']) ?></span></td>
        </tr><?php endforeach; ?></tbody></table>
      <?php else: ?><p class="muted">No matching store orders.</p><?php endif; ?>
    </div>
  <?php endif; ?>

<?php else: ?>
  <p>Unknown view.</p>
<?php endif; ?>
</main>
</body>
</html>
