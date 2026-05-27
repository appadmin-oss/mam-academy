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
mam_require_admin();

// Stop the JSON content type set by bootstrap and serve HTML for views
header_remove('Content-Type');

$view  = $_GET['v'] ?? 'overview';
$flash = null;

/** Compose a name out of customer first/last (portable across SQLite + MySQL). */
function mam_name(?string $first, ?string $last): string {
    $n = trim((string)$first . ' ' . (string)$last);
    return $n === '' ? '—' : $n;
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

    if ($action === 'app_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if ($id && in_array($status, ['submitted', 'in_review', 'paid', 'enrolled', 'cancelled'], true)) {
            mam_db_update('applications', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
            mam_audit('update_status', 'application', $id, ['status' => $status]);

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
        header('Location: ?v=applications&ok=1');
        exit;
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
            mam_audit('admin_reset_password', 'user', $uid);
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
            mam_db_update('classes', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $cid]);
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
            mam_db_update('enrollments', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $eid]);
            mam_audit('update_status', 'enrollment', $eid, ['status' => $status]);
        }
        header('Location: ?v=enrollments&ok=1');
        exit;
    }

    // Unknown action — bounce back
    header('Location: ?v=' . urlencode($view));
    exit;
}

/* ─── KPIs ─── */
$kpi = [
    'applications' => (int)mam_db()->query('SELECT COUNT(*) FROM applications')->fetchColumn(),
    'paid'         => (int)mam_db()->query("SELECT COUNT(*) FROM applications WHERE status='paid'")->fetchColumn(),
    'revenue'      => (float)mam_db()->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='succeeded'")->fetchColumn(),
    'newsletter'   => (int)mam_db()->query('SELECT COUNT(*) FROM newsletter')->fetchColumn(),
    'messages'     => (int)mam_db()->query("SELECT COUNT(*) FROM messages WHERE status='unread'")->fetchColumn(),
    'webhooks'     => (int)mam_db()->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn(),
    'users'        => (int)mam_db()->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'classes'      => (int)mam_db()->query("SELECT COUNT(*) FROM classes WHERE status='active'")->fetchColumn(),
];

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
.card{background:var(--raised);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:22px}
.card h3{padding:16px 20px;font-size:0.95rem;font-weight:700;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
table{width:100%;border-collapse:collapse;font-size:0.86rem}
th,td{padding:11px 16px;text-align:left;border-bottom:1px solid var(--border);vertical-align:top}
th{color:var(--muted);font-size:0.72rem;letter-spacing:0.08em;text-transform:uppercase;font-weight:700;background:var(--panel)}
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
  /* Let wide data tables scroll horizontally instead of being clipped by the card */
  .card{overflow-x:auto;-webkit-overflow-scrolling:touch}
  .card>table{min-width:620px}
  .top{flex-wrap:wrap;gap:10px}
  .form-grid{grid-template-columns:1fr}
}
@media(max-width:520px){
  .kpi-grid{grid-template-columns:1fr 1fr}
  th,td{padding:9px 12px}
}
</style>
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
      'payments'     => 'Payments',
      'users'        => 'Users',
      'classes'      => 'Classes',
      'enrollments'  => 'Enrollments',
      'messages'     => 'Messages',
      'webhooks'     => 'Webhook Events',
      'newsletter'   => 'Newsletter',
      'audit'        => 'Audit Log',
    ];
    foreach ($links as $k => $label) {
        $cls = $view === $k ? 'active' : '';
        echo "<a href='?v=" . htmlspecialchars($k) . "' class='$cls'>" . htmlspecialchars($label) . "</a>";
    }
    ?>
  </nav>
  <div style="margin-top:24px;padding:12px;background:var(--bg);border:1px solid var(--border);border-radius:10px;font-size:0.78rem;color:var(--muted);line-height:1.5">
    <strong style="color:var(--gold)">Signed in:</strong>
    <?php
    $sessUser = mam_current_user();
    echo htmlspecialchars($sessUser['email'] ?? ($_SERVER['PHP_AUTH_USER'] ?? 'admin'));
    ?><br>
    <a href="/" style="color:var(--gold)" target="_blank">↗ View site</a>
  </div>
</aside>

<main>
<?php if ($flashOk): ?><div class="banner ok">Done.</div><?php endif; ?>
<?php if ($flashErr !== ''): ?><div class="banner err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

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
    if (!$health['key']) echo '<div class="banner warn">⚠️ APP_KEY is missing or too short — CSRF tokens and admin forms will fail. Set <code>APP_KEY</code> in <code>api/.env</code> (hex, ≥32 chars).</div>';
    if (!$health['paystack'] && !$health['flw']) echo '<div class="banner warn">⚠️ No payment provider configured. Set <code>PAYSTACK_SECRET_KEY</code> or <code>FLW_SECRET_KEY</code>.</div>';
    if (!$health['smtp']) echo '<div class="banner">ℹ️ SMTP not configured — outgoing mail falls back to PHP <code>mail()</code>.</div>';
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

  <div class="card">
    <h3>Recent Applications</h3>
    <table>
      <thead><tr><th>Reference</th><th>Student</th><th>Pathway</th><th>Mode</th><th>USD</th><th>Status</th><th>When</th></tr></thead>
      <tbody>
      <?php foreach (mam_db_all('SELECT a.*, c.first_name, c.last_name, c.email FROM applications a LEFT JOIN customers c ON c.id=a.customer_id ORDER BY a.created_at DESC LIMIT 10') as $r): ?>
        <tr>
          <td class="mono"><?= htmlspecialchars($r['reference']) ?></td>
          <td><strong><?= htmlspecialchars(mam_name($r['first_name'] ?? null, $r['last_name'] ?? null)) ?></strong><br><span class="muted"><?= htmlspecialchars((string)($r['email'] ?? '')) ?></span></td>
          <td><?= htmlspecialchars($r['pathway']) ?></td>
          <td class="muted"><?= htmlspecialchars($r['mode']) ?></td>
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
  <div class="card">
    <table>
      <thead><tr><th>Reference</th><th>Student</th><th>Pathway · Mode</th><th>Discipline</th><th>Age</th><th>Total</th><th>Status</th><th>Created</th><th></th></tr></thead>
      <tbody>
      <?php $rows = mam_db_all('SELECT a.*, c.first_name, c.last_name, c.email, c.phone, c.country, c.age_range FROM applications a LEFT JOIN customers c ON c.id=a.customer_id ORDER BY a.created_at DESC LIMIT 200');
      if (!$rows): ?>
        <tr><td colspan="9" class="empty">No applications yet</td></tr>
      <?php endif;
      foreach ($rows as $r): ?>
        <tr>
          <td class="mono"><?= htmlspecialchars($r['reference']) ?></td>
          <td><strong><?= htmlspecialchars(mam_name($r['first_name'] ?? null, $r['last_name'] ?? null)) ?></strong><br><span class="muted"><?= htmlspecialchars((string)($r['email'] ?? '')) ?></span><br><span class="muted"><?= htmlspecialchars((string)($r['phone'] ?? '')) ?> · <?= htmlspecialchars((string)($r['country'] ?? '')) ?></span></td>
          <td><?= htmlspecialchars($r['pathway']) ?> · <span class="muted"><?= htmlspecialchars($r['mode']) ?></span></td>
          <td><?= htmlspecialchars($r['discipline']) ?></td>
          <td class="muted"><?= htmlspecialchars((string)($r['age_range'] ?? '')) ?></td>
          <td>$<?= number_format((float)$r['total_usd'], 2) ?></td>
          <td><span class="pill <?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
          <td class="muted"><?= htmlspecialchars(substr($r['created_at'] ?? '', 0, 16)) ?></td>
          <td>
            <form class="inline" method="post">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="app_status">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <select name="status" class="tiny">
                <?php foreach (['submitted', 'in_review', 'paid', 'enrolled', 'cancelled'] as $s): ?>
                  <option value="<?= $s ?>" <?= $s === $r['status'] ? 'selected' : '' ?>><?= $s ?></option>
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

<?php elseif ($view === 'payments'): ?>
  <header class="top">
    <h1>Payments</h1>
    <a class="btn ghost" href="?v=export&type=payments.csv">⬇ Export CSV</a>
  </header>
  <div class="card">
    <table>
      <thead><tr><th>Reference</th><th>Provider</th><th>Amount</th><th>Status</th><th>Provider Ref</th><th>Verified</th><th>Created</th></tr></thead>
      <tbody>
      <?php $rows = mam_db_all('SELECT * FROM payments ORDER BY created_at DESC LIMIT 200');
      if (!$rows): ?><tr><td colspan="7" class="empty">No payments yet</td></tr><?php endif;
      foreach ($rows as $r): ?>
        <tr>
          <td class="mono"><?= htmlspecialchars($r['reference']) ?></td>
          <td><?= htmlspecialchars($r['provider']) ?></td>
          <td><?= number_format((float)$r['amount']) ?> <?= htmlspecialchars($r['currency']) ?></td>
          <td><span class="pill <?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
          <td class="mono muted"><?= htmlspecialchars((string)$r['provider_reference']) ?></td>
          <td class="muted"><?= htmlspecialchars(substr((string)$r['verified_at'], 0, 16)) ?></td>
          <td class="muted"><?= htmlspecialchars(substr($r['created_at'], 0, 16)) ?></td>
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
  <div class="card">
    <h3>Create user</h3>
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="user_create">
      <label>Email<input type="email" name="email" required></label>
      <label>Full name<input type="text" name="name" required></label>
      <label>Phone<input type="text" name="phone"></label>
      <label>Role
        <select name="role" required>
          <option value="student">Student</option>
          <option value="instructor">Instructor</option>
          <option value="admin">Admin</option>
        </select>
      </label>
      <label>Temporary password<input type="text" name="password" minlength="8" required placeholder="≥ 8 chars"></label>
      <div class="actions"><button class="btn" type="submit">Create user</button></div>
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
      $users = mam_db_all("SELECT id, email, name, phone, role, status, last_login, created_at FROM users $where ORDER BY created_at DESC LIMIT 500", $params);
      if (!$users): ?><tr><td colspan="8" class="empty">No users yet</td></tr><?php endif;
      foreach ($users as $u): ?>
        <tr>
          <td><strong><?= htmlspecialchars((string)($u['name'] ?? '—')) ?></strong><br><span class="muted mono">#<?= (int)$u['id'] ?></span></td>
          <td><?= htmlspecialchars($u['email']) ?><br><span class="muted"><?= htmlspecialchars((string)($u['phone'] ?? '')) ?></span></td>
          <td><span class="pill <?= htmlspecialchars($u['role']) ?>"><?= htmlspecialchars($u['role']) ?></span></td>
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
            <form class="inline" method="post">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="user_status">
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <select name="status" class="tiny">
                <option value="active" <?= $u['status']==='active'?'selected':'' ?>>active</option>
                <option value="suspended" <?= $u['status']==='suspended'?'selected':'' ?>>suspended</option>
              </select>
              <button class="tiny" type="submit">Save</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($view === 'classes'): ?>
  <header class="top"><h1>Classes</h1></header>
  <?php $instructors = mam_db_all("SELECT id, name, email FROM users WHERE role='instructor' AND status='active' ORDER BY name"); ?>
  <div class="card">
    <h3>Create class</h3>
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="class_create">
      <label class="full">Title<input type="text" name="title" required maxlength="120"></label>
      <label>Discipline<input type="text" name="discipline" required maxlength="32" placeholder="piano · voice · …"></label>
      <label>Pathway
        <select name="pathway">
          <option value="">—</option>
          <option value="metamusic">MetaMusic Order</option>
          <option value="powerplay">Power Play</option>
          <option value="auditer">MAM Auditer</option>
        </select>
      </label>
      <label>Instructor
        <select name="instructor_id">
          <option value="">— unassigned —</option>
          <?php foreach ($instructors as $i): ?>
            <option value="<?= (int)$i['id'] ?>"><?= htmlspecialchars($i['name'] ?: $i['email']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Mode
        <select name="mode">
          <option value="">—</option>
          <option value="online">Online</option>
          <option value="onsite">On-site</option>
          <option value="hybrid">Hybrid</option>
        </select>
      </label>
      <label>Capacity<input type="number" name="capacity" value="12" min="1" max="500"></label>
      <label>Starts on<input type="date" name="starts_on"></label>
      <label class="full">Schedule<input type="text" name="schedule" placeholder="Sat 10:00–11:30, Wed 18:00–19:00"></label>
      <div class="actions"><button class="btn" type="submit">Create class</button></div>
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
          <td><?= htmlspecialchars($c['discipline']) ?></td>
          <td class="muted"><?= htmlspecialchars((string)($c['pathway'] ?? '—')) ?></td>
          <td><?= htmlspecialchars((string)($c['instructor_name'] ?? '— unassigned —')) ?></td>
          <td class="muted"><?= htmlspecialchars((string)($c['mode'] ?? '—')) ?></td>
          <td><?= (int)$c['capacity'] ?></td>
          <td><?= (int)$c['enrolled_count'] ?></td>
          <td class="muted"><?= htmlspecialchars((string)($c['starts_on'] ?? '')) ?></td>
          <td>
            <form class="inline" method="post">
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

<?php else: ?>
  <p>Unknown view.</p>
<?php endif; ?>
</main>
</body>
</html>
