<?php
/**
 * MAM Academy — Admin Dashboard
 * Single-file PHP admin protected by HTTP Basic Auth.
 *
 * Views:
 *   /admin/                — Overview KPIs
 *   /admin/?v=applications — Applications table + filters + export
 *   /admin/?v=payments     — Payments + verification status
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

$view = $_GET['v'] ?? 'overview';

if ($view === 'export') {
    $type = $_GET['type'] ?? 'applications.csv';
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '', basename($type));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    if (str_starts_with($filename, 'applications')) {
        fputcsv($out, ['reference', 'name', 'email', 'phone', 'country', 'pathway', 'mode', 'discipline', 'age', 'total_usd', 'status', 'created_at']);
        foreach (mam_db_all('SELECT a.reference, c.first_name||" "||c.last_name AS name, c.email, c.phone, c.country, a.pathway, a.mode, a.discipline, c.age_range AS age, a.total_usd, a.status, a.created_at FROM applications a LEFT JOIN customers c ON c.id = a.customer_id ORDER BY a.created_at DESC') as $r) {
            fputcsv($out, $r);
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
    }
    fclose($out);
    exit;
}

// Update statuses
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
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
        }
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '?v=applications'));
        exit;
    }
    if ($action === 'msg_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'read';
        if ($id) mam_db_update('messages', ['status' => $status], ['id' => $id]);
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '?v=messages'));
        exit;
    }
}

// Pull KPIs
$kpi = [
    'applications' => (int)mam_db()->query('SELECT COUNT(*) FROM applications')->fetchColumn(),
    'paid'         => (int)mam_db()->query("SELECT COUNT(*) FROM applications WHERE status='paid'")->fetchColumn(),
    'revenue'      => (float)mam_db()->query("SELECT IFNULL(SUM(amount),0) FROM payments WHERE status='succeeded'")->fetchColumn(),
    'newsletter'   => (int)mam_db()->query('SELECT COUNT(*) FROM newsletter')->fetchColumn(),
    'messages'     => (int)mam_db()->query("SELECT COUNT(*) FROM messages WHERE status='unread'")->fetchColumn(),
    'webhooks'     => (int)mam_db()->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn(),
];
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
nav .dot{width:6px;height:6px;border-radius:50%;background:currentColor;opacity:0.6}
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
.pill{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:100px;font-size:0.72rem;font-weight:700;letter-spacing:0.03em}
.pill.submitted{background:rgba(99,102,241,0.18);color:#A5B4FC}
.pill.paid{background:rgba(16,185,129,0.18);color:#34D399}
.pill.enrolled{background:linear-gradient(135deg,var(--gold),var(--gold-2));color:#1A120B}
.pill.cancelled{background:rgba(244,63,94,0.18);color:#FB7185}
.pill.in_review{background:rgba(251,191,36,0.18);color:#FBBF24}
.pill.pending{background:rgba(251,191,36,0.18);color:#FBBF24}
.pill.succeeded{background:rgba(16,185,129,0.18);color:#34D399}
.pill.failed{background:rgba(244,63,94,0.18);color:#FB7185}
.pill.received{background:rgba(99,102,241,0.18);color:#A5B4FC}
.pill.processed{background:rgba(16,185,129,0.18);color:#34D399}
.pill.invalid{background:rgba(244,63,94,0.18);color:#FB7185}
.pill.unread{background:rgba(251,191,36,0.18);color:#FBBF24}
.pill.read{background:rgba(255,255,255,0.08);color:var(--muted)}
.muted{color:var(--muted)}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:0.82rem}
form.inline{display:inline-flex;gap:6px;align-items:center}
select.tiny{background:var(--panel);color:var(--text);border:1px solid var(--border);border-radius:6px;font-size:0.78rem;padding:4px 6px}
button.tiny{padding:4px 8px;font-size:0.74rem;background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:6px;cursor:pointer}
button.tiny:hover{border-color:var(--gold)}
.empty{padding:48px;text-align:center;color:var(--muted)}
.empty i{font-size:2rem;margin-bottom:12px;display:block}
.banner{padding:14px 18px;border-radius:10px;background:rgba(99,102,241,0.08);border:1px solid rgba(99,102,241,0.3);margin-bottom:18px;font-size:0.88rem;color:#C7D2FE}
.banner.warn{background:rgba(251,191,36,0.08);border-color:rgba(251,191,36,0.4);color:#FDE68A}
.json-pop{max-width:380px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--muted)}
@media(max-width:920px){body{grid-template-columns:1fr}aside{position:static;height:auto}}
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
      'overview' => ['Overview', '📊'],
      'applications' => ['Applications', '📝'],
      'payments' => ['Payments', '💳'],
      'messages' => ['Messages', '💬'],
      'webhooks' => ['Webhook Events', '🔔'],
      'newsletter' => ['Newsletter', '✉️'],
      'audit' => ['Audit Log', '🛡️'],
    ];
    foreach ($links as $k => $info) {
        $cls = $view === $k ? 'active' : '';
        echo "<a href='?v=$k' class='$cls'><span>{$info[1]}</span> {$info[0]}</a>";
    }
    ?>
  </nav>
  <div style="margin-top:24px;padding:12px;background:var(--bg);border:1px solid var(--border);border-radius:10px;font-size:0.78rem;color:var(--muted);line-height:1.5">
    <strong style="color:var(--gold)">Logged in:</strong> <?= htmlspecialchars($_SERVER['PHP_AUTH_USER'] ?? '') ?><br>
    <a href="/" style="color:var(--gold)" target="_blank">↗ View site</a>
  </div>
</aside>

<main>
<?php if ($view === 'overview'): ?>
  <header class="top">
    <h1>Overview</h1>
    <div>
      <a class="btn ghost" href="?v=export&type=applications.csv">⬇ Export applications</a>
      <a class="btn ghost" href="?v=export&type=payments.csv">⬇ Export payments</a>
    </div>
  </header>
  <?php
    $health = ['paystack' => !!mam_env('PAYSTACK_SECRET_KEY'), 'flw' => !!mam_env('FLW_SECRET_KEY'), 'smtp' => !!mam_env('MAIL_HOST'), 'key' => strlen((string)mam_env('APP_KEY')) >= 32];
    if (!$health['key']) echo '<div class="banner warn">⚠️ APP_KEY is missing or too short — CSRF tokens will fail. Set <code>APP_KEY</code> in <code>api/.env</code>.</div>';
    if (!$health['paystack'] && !$health['flw']) echo '<div class="banner warn">⚠️ No payment provider configured. Set <code>PAYSTACK_SECRET_KEY</code> or <code>FLW_SECRET_KEY</code>.</div>';
  ?>
  <div class="kpi-grid">
    <div class="kpi"><div class="kpi-label">Total Applications</div><div class="kpi-value"><?= number_format($kpi['applications']) ?></div></div>
    <div class="kpi"><div class="kpi-label">Paid</div><div class="kpi-value gold"><?= number_format($kpi['paid']) ?></div></div>
    <div class="kpi"><div class="kpi-label">Revenue (NGN)</div><div class="kpi-value gold">₦<?= number_format($kpi['revenue']) ?></div></div>
    <div class="kpi"><div class="kpi-label">Newsletter</div><div class="kpi-value"><?= number_format($kpi['newsletter']) ?></div></div>
    <div class="kpi"><div class="kpi-label">Unread Messages</div><div class="kpi-value"><?= number_format($kpi['messages']) ?></div></div>
    <div class="kpi"><div class="kpi-label">Webhook Events</div><div class="kpi-value"><?= number_format($kpi['webhooks']) ?></div></div>
  </div>

  <div class="card">
    <h3>Recent Applications</h3>
    <table>
      <thead><tr><th>Reference</th><th>Student</th><th>Pathway</th><th>Mode</th><th>USD</th><th>Status</th><th>When</th></tr></thead>
      <tbody>
      <?php foreach (mam_db_all('SELECT a.*, c.first_name||" "||c.last_name AS name, c.email FROM applications a LEFT JOIN customers c ON c.id=a.customer_id ORDER BY a.created_at DESC LIMIT 10') as $r): ?>
        <tr>
          <td class="mono"><?= htmlspecialchars($r['reference']) ?></td>
          <td><strong><?= htmlspecialchars($r['name'] ?? '—') ?></strong><br><span class="muted"><?= htmlspecialchars($r['email'] ?? '') ?></span></td>
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
      <?php $rows = mam_db_all('SELECT a.*, c.first_name||" "||c.last_name AS name, c.email, c.phone, c.country, c.age_range FROM applications a LEFT JOIN customers c ON c.id=a.customer_id ORDER BY a.created_at DESC LIMIT 200');
      if (!$rows): ?>
        <tr><td colspan="9" class="empty"><i>📝</i>No applications yet</td></tr>
      <?php endif;
      foreach ($rows as $r): ?>
        <tr>
          <td class="mono"><?= htmlspecialchars($r['reference']) ?></td>
          <td><strong><?= htmlspecialchars($r['name'] ?? '—') ?></strong><br><span class="muted"><?= htmlspecialchars($r['email'] ?? '') ?></span><br><span class="muted"><?= htmlspecialchars($r['phone'] ?? '') ?> · <?= htmlspecialchars($r['country'] ?? '') ?></span></td>
          <td><?= htmlspecialchars($r['pathway']) ?> · <span class="muted"><?= htmlspecialchars($r['mode']) ?></span></td>
          <td><?= htmlspecialchars($r['discipline']) ?></td>
          <td class="muted"><?= htmlspecialchars($r['age_range'] ?? '') ?></td>
          <td>$<?= number_format((float)$r['total_usd'], 2) ?></td>
          <td><span class="pill <?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
          <td class="muted"><?= htmlspecialchars(substr($r['created_at'] ?? '', 0, 16)) ?></td>
          <td>
            <form class="inline" method="post">
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
      if (!$rows): ?><tr><td colspan="7" class="empty"><i>💳</i>No payments yet</td></tr><?php endif;
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

<?php elseif ($view === 'messages'): ?>
  <header class="top"><h1>Contact Messages</h1><a class="btn ghost" href="?v=export&type=messages.csv">⬇ Export</a></header>
  <div class="card">
    <table>
      <thead><tr><th>When</th><th>From</th><th>Topic</th><th>Message</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php $rows = mam_db_all('SELECT * FROM messages ORDER BY created_at DESC LIMIT 200');
      if (!$rows): ?><tr><td colspan="6" class="empty"><i>💬</i>No messages yet</td></tr><?php endif;
      foreach ($rows as $r): ?>
        <tr>
          <td class="muted"><?= htmlspecialchars(substr($r['created_at'], 0, 16)) ?></td>
          <td><strong><?= htmlspecialchars((string)$r['name']) ?></strong><br><span class="muted"><?= htmlspecialchars((string)$r['email']) ?></span></td>
          <td><?= htmlspecialchars((string)$r['topic']) ?></td>
          <td style="max-width:340px"><?= nl2br(htmlspecialchars(substr((string)$r['message'], 0, 280))) ?></td>
          <td><span class="pill <?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
          <td>
            <form class="inline" method="post"><input type="hidden" name="action" value="msg_status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
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
      if (!$rows): ?><tr><td colspan="6" class="empty"><i>🔔</i>No webhooks received yet</td></tr><?php endif;
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
      if (!$rows): ?><tr><td colspan="4" class="empty"><i>✉️</i>No subscribers yet</td></tr><?php endif;
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
