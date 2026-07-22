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

/* ─── Pagination: keep every list bounded + navigable as data grows ─── */
function mam_admin_page(): int { return max(1, (int)($_GET['page'] ?? 1)); }
/** Prev/next controls that preserve the current view + query params. */
function mam_admin_pager(int $page, int $total, int $perPage): string {
    $pages = max(1, (int)ceil($total / max(1, $perPage)));
    $qs = $_GET; unset($qs['ok'], $qs['err']); // don't carry flash banners across pages
    $mk = function ($p) use ($qs) { $qs['page'] = $p; return '?' . http_build_query($qs); };
    $out = '<div class="pager" style="display:flex;gap:8px;align-items:center;justify-content:flex-end;margin-top:12px;font-size:.82rem">'
         . '<span class="muted">Page ' . $page . ' of ' . $pages . ' · ' . number_format($total) . ' total</span>';
    if ($page > 1)      $out .= '<a class="btn ghost tiny" href="' . htmlspecialchars($mk($page - 1)) . '">← Prev</a>';
    if ($page < $pages) $out .= '<a class="btn ghost tiny" href="' . htmlspecialchars($mk($page + 1)) . '">Next →</a>';
    return $out . '</div>';
}

/* ─── CSV export (no state change) ─── */
if ($view === 'export') {
    $type = $_GET['type'] ?? 'applications.csv';

    // Data-subject access export (NDPA/GDPR): a full JSON bundle for ONE person.
    // Contains full PII → super-only (Basic-Auth break-glass = super, $adminUser null).
    if ($type === 'datarights') {
        if ($adminUser && !mam_admin_can($adminUser, 'users')) { http_response_code(403); echo 'Forbidden — super-admin only.'; exit; }
        $email = strtolower(trim((string)($_GET['email'] ?? '')));
        $bundle = mam_datarights_export($email);
        if (!$bundle) { http_response_code(404); echo 'No data found for that email.'; exit; }
        mam_audit('datarights_export', 'customer', (int)($bundle['customer']['id'] ?? 0), ['email' => $email]);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="mam-data-' . preg_replace('/[^a-z0-9]/', '_', $email) . '.json"');
        echo json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Standard-curriculum export: the authored weekly modules for one course
    // (+level), as a re-importable JSON bundle or a spreadsheet CSV. Super-only
    // (structural content). The JSON shape {course,level,modules} is exactly
    // what the importer re-ingests, so a syllabus is portable between installs.
    if ($type === 'curriculum' || $type === 'curriculum.csv') {
        if ($adminUser && !mam_admin_can($adminUser, 'curriculum')) { http_response_code(403); echo 'Forbidden — super-admin only.'; exit; }
        $cc = trim((string)($_GET['course'] ?? ''));
        $course = $cc !== '' ? mam_db_row('SELECT id, code, name FROM courses WHERE code = ?', [$cc]) : null;
        if (!$course) { http_response_code(404); echo 'Course not found.'; exit; }
        $lc = trim((string)($_GET['level'] ?? ''));
        $level = $lc !== '' ? mam_db_row('SELECT id FROM levels WHERE code = ?', [$lc]) : null;
        $modules = mam_lms_export_modules((int)$course['id'], $level ? (int)$level['id'] : null);
        $base = trim('curriculum-' . preg_replace('/[^a-z0-9]+/i', '-', $cc . ($lc !== '' ? '-' . $lc : '')), '-');
        mam_audit('curriculum_export', 'course', (int)$course['id'], ['level' => $lc ?: null, 'count' => count($modules), 'format' => $type === 'curriculum.csv' ? 'csv' : 'json']);
        if ($type === 'curriculum.csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $base . '.csv"');
            echo mam_lms_modules_to_csv($modules);
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $base . '.json"');
        echo json_encode(['course' => $course['code'], 'level' => $lc !== '' ? $lc : null, 'modules' => $modules], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Programme curriculum PDF — print-ready HTML; browser Print → Save as PDF.
    if ($type === 'curriculum.pdf') {
        if ($adminUser && !mam_admin_can($adminUser, 'curriculum')) { http_response_code(403); echo 'Forbidden.'; exit; }
        $pc   = trim((string)($_GET['program'] ?? ''));
        $lc   = trim((string)($_GET['level'] ?? ''));
        $prog = $pc !== '' ? mam_db_row('SELECT * FROM programs WHERE code = ?', [$pc]) : null;
        if (!$prog) { http_response_code(404); echo 'Programme not found.'; exit; }
        $curriculum  = mam_lms_full_program_curriculum($pc);
        $progName    = (string)$prog['name'];
        $progWeeks   = max(1, (int)$prog['weeks']);
        $certLabels  = ['participation' => 'Certificate of Participation', 'completion' => 'Certificate of Completion', 'internal' => 'Internal Certification', 'internal_diploma' => 'Internal Certification & Diploma Transcript'];
        $certLabel   = $certLabels[$prog['certification'] ?? ''] ?? 'Certificate';
        $totalAuthored = 0; $totalPossible = 0;
        $courses = $curriculum['courses'] ?? [];
        foreach ($courses as $c) { $authoredW = count(array_filter($c['weeks'] ?? [], fn($w) => !empty($w['title']))); $totalAuthored += $authoredW; $totalPossible += $progWeeks; }
        mam_audit('curriculum_pdf_export', 'program', (int)$prog['id'], ['level' => $lc ?: null]);
        header_remove('Content-Type');
        header('Content-Type: text/html; charset=utf-8');
        $edition = 'Edition ' . date('Y') . '.' . date('n');
        $genDate = date('j F Y');
        ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($progName) ?> Curriculum — MAM Academy</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;0,700;1,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ── Reset ── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
/* ── Tokens ── */
:root{
  --gold:#E8961F;--gold2:#F7A823;--ink:#1B140D;--warm:#3B2E1D;--smoke:#5C4A3A;
  --rule:#DFD0C0;--bg:#FDFAF6;--tag-bg:#F5EDE2;
  --core-bg:#FEF3C7;--core-fg:#92400E;
  --elec-bg:#E0F2FE;--elec-fg:#075985;
  --rev-bg:#D1FAE5;--rev-fg:#065F46;
  --exam-bg:#FEE2E2;--exam-fg:#991B1B;
  --f-display:'Cormorant Garamond',Georgia,serif;
  --f-body:'Inter',-apple-system,sans-serif;
}
/* ── Base ── */
html{font-size:10.5pt}
body{font-family:var(--f-body);color:var(--ink);background:var(--bg);line-height:1.55}
a{color:inherit;text-decoration:none}

/* ── Print page setup ── */
@page{
  size:A4;margin:18mm 16mm 22mm;
  @bottom-left{content:'MAM Academy — ' attr(data-prog);font-size:7.5pt;color:#888;font-family:var(--f-body)}
  @bottom-center{content:counter(page) ' / ' counter(pages);font-size:7.5pt;color:#888;font-family:var(--f-body)}
  @bottom-right{content:attr(data-edition);font-size:7.5pt;color:#888;font-family:var(--f-body)}
}
@page cover{margin:0;@bottom-left{content:none}@bottom-center{content:none}@bottom-right{content:none}}
@page toc{margin:18mm 16mm 22mm}

/* ── No-print control bar ── */
.no-print{position:sticky;top:0;z-index:100;display:flex;align-items:center;gap:10px;
  padding:12px 24px;background:var(--ink);box-shadow:0 2px 16px rgba(0,0,0,.25)}
.no-print h2{font-family:var(--f-display);font-size:1.1rem;font-weight:700;color:#fff;flex:1;letter-spacing:.03em}
.no-print button{padding:8px 18px;border-radius:8px;border:none;font-family:var(--f-body);font-size:.88rem;font-weight:700;cursor:pointer;transition:opacity .15s}
.btn-pdf{background:var(--gold);color:var(--ink)}
.btn-pdf:hover{opacity:.9}
.btn-close{background:transparent;color:rgba(255,255,255,.7);border:1px solid rgba(255,255,255,.2)}
.btn-close:hover{color:#fff;border-color:rgba(255,255,255,.5)}
@media print{.no-print{display:none!important}}

/* ── Cover page ── */
.cover{
  page:cover;page-break-after:always;
  position:relative;min-height:100vh;display:flex;flex-direction:column;
  background:#fff;overflow:hidden
}
.cover-stripe{
  position:absolute;top:0;left:0;right:0;height:8px;
  background:linear-gradient(90deg,var(--gold),var(--gold2),var(--gold))
}
.cover-body{flex:1;display:flex;flex-direction:column;justify-content:center;padding:64px 56px}
.cover-eyebrow{font-family:var(--f-body);font-size:8pt;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:var(--gold);margin-bottom:20px}
.cover h1{font-family:var(--f-display);font-size:44pt;font-weight:700;color:var(--ink);line-height:1.0;letter-spacing:-.5pt;margin-bottom:12px}
.cover-sub{font-family:var(--f-display);font-size:17pt;font-weight:600;font-style:italic;color:var(--smoke);margin-bottom:36px}
.cover-rule{width:56px;height:3px;background:var(--gold);margin-bottom:36px}
.cover-meta{display:flex;flex-wrap:wrap;gap:10px}
.cover-meta span{display:inline-flex;align-items:center;gap:6px;background:var(--tag-bg);color:var(--smoke);font-size:8.5pt;font-weight:600;padding:5px 12px;border-radius:20px;border:1px solid var(--rule)}
.cover-stats{margin-top:40px;padding-top:32px;border-top:1px solid var(--rule);display:flex;gap:40px}
.cover-stat-n{font-family:var(--f-display);font-size:32pt;font-weight:700;color:var(--ink);line-height:1}
.cover-stat-l{font-size:8pt;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--smoke);margin-top:4px}
.cover-footer{padding:28px 56px;display:flex;align-items:center;gap:12px;border-top:1px solid var(--rule)}
.cover-monogram{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,var(--gold),var(--gold2));display:flex;align-items:center;justify-content:center;color:var(--ink);font-family:var(--f-display);font-size:1.2rem;font-weight:700;flex:0 0 auto}
.cover-brand{font-size:8pt;color:var(--smoke)}
.cover-brand strong{display:block;font-weight:700;color:var(--ink);letter-spacing:.04em}

/* ── Table of contents ── */
.toc{page:toc;page-break-after:always;padding:48px 56px}
.toc h2{font-family:var(--f-display);font-size:22pt;font-weight:700;color:var(--ink);margin-bottom:6px}
.toc-rule{width:40px;height:2px;background:var(--gold);margin:14px 0 28px}
.toc-row{display:flex;align-items:baseline;gap:8px;padding:10px 0;border-bottom:1px dashed var(--rule)}
.toc-row:last-child{border-bottom:none}
.toc-idx{font-family:var(--f-display);font-size:11pt;font-weight:700;color:var(--gold);min-width:28px}
.toc-name{font-size:10.5pt;font-weight:600;flex:1}
.toc-dots{flex:1;border-bottom:1px dotted var(--rule);min-width:20px;align-self:center;margin:0 10px}
.toc-badge{font-size:7.5pt;padding:2px 8px;border-radius:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
.toc-core{background:var(--core-bg);color:var(--core-fg)}
.toc-elec{background:var(--elec-bg);color:var(--elec-fg)}
.toc-progress{font-size:8pt;color:var(--smoke)}

/* ── Course sections ── */
.course-section{padding:40px 56px;page-break-inside:avoid}
.course-section+.course-section{border-top:3px solid var(--rule);page-break-before:always}
.course-header{display:flex;align-items:flex-start;gap:14px;margin-bottom:6px}
.course-num{font-family:var(--f-display);font-size:10pt;font-weight:700;color:var(--gold);min-width:28px;line-height:1.8}
.course-title{font-family:var(--f-display);font-size:22pt;font-weight:700;color:var(--ink);flex:1;line-height:1.1}
.course-badge{font-size:7.5pt;padding:3px 9px;border-radius:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;align-self:flex-start;margin-top:6px}
.course-desc{font-size:9pt;color:var(--smoke);line-height:1.6;margin:10px 0 16px 42px}
.course-level{display:inline-flex;align-items:center;gap:6px;background:var(--tag-bg);color:var(--smoke);font-size:8pt;font-weight:600;padding:3px 10px;border-radius:14px;margin:0 0 16px 42px}
.course-progress{display:flex;align-items:center;gap:12px;margin:0 0 20px 42px}
.progress-bar-wrap{flex:1;height:5px;background:var(--rule);border-radius:3px;max-width:200px}
.progress-bar-fill{height:100%;background:linear-gradient(90deg,var(--gold),var(--gold2));border-radius:3px}
.progress-label{font-size:8pt;color:var(--smoke);white-space:nowrap}

/* ── Week table ── */
table.weeks{width:100%;border-collapse:collapse;font-size:9pt;margin-left:42px;width:calc(100% - 42px)}
table.weeks th{
  text-align:left;padding:7px 10px;
  background:var(--ink);color:#fff;
  font-size:7.5pt;font-weight:700;letter-spacing:.08em;text-transform:uppercase
}
table.weeks thead th:first-child{border-radius:0}
table.weeks td{padding:8px 10px;border-bottom:1px solid var(--rule);vertical-align:top}
table.weeks tr.authored td{background:#fff}
table.weeks tr.unwritten td{background:#FAF7F3;color:#aaa}
table.weeks tr:last-child td{border-bottom:none}
.wk-num{font-family:var(--f-display);font-size:12pt;font-weight:700;color:var(--gold);white-space:nowrap;width:44px;line-height:1.4}
.wk-num.unwritten{color:var(--rule)}
.wk-badge{display:inline-block;font-size:7pt;padding:2px 7px;border-radius:8px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap}
.kind-module{background:var(--elec-bg);color:var(--elec-fg)}
.kind-revision{background:var(--rev-bg);color:var(--rev-fg)}
.kind-exam{background:var(--exam-bg);color:var(--exam-fg)}
.kind-empty{background:var(--tag-bg);color:#bbb}
.wk-title{font-weight:600;line-height:1.35}
.wk-content{font-size:8.5pt;color:var(--smoke);line-height:1.45;margin-top:4px}
.no-modules-note{font-size:9pt;color:#bbb;font-style:italic;margin:16px 0 0 42px}

/* ── Empty curriculum state ── */
.no-courses{padding:48px 56px;color:#bbb;font-style:italic}

/* ── Print-only running footer (fallback for @page) ── */
.print-footer{display:none}
@media print{
  .print-footer{display:flex;justify-content:space-between;font-size:7.5pt;color:#aaa;padding:0;margin-top:40px;border-top:1px solid var(--rule)}
  body{background:#fff}
  .cover-body{padding:64px 56px}
}
</style>
</head>
<body data-prog="<?= htmlspecialchars($progName) ?>" data-edition="<?= htmlspecialchars($edition) ?>">

<!-- ── Screen toolbar (hidden on print) ── -->
<div class="no-print">
  <h2><?= htmlspecialchars($progName) ?> — Curriculum</h2>
  <button class="btn-pdf" onclick="window.print()">↓ Save as PDF</button>
  <button class="btn-close" onclick="window.close()">✕ Close</button>
</div>

<!-- ── Cover page ── -->
<div class="cover">
  <div class="cover-stripe"></div>
  <div class="cover-body">
    <div class="cover-eyebrow">MAM Academy · Official Curriculum Document</div>
    <h1><?= htmlspecialchars($progName) ?></h1>
    <div class="cover-sub"><?= $lc !== '' ? htmlspecialchars(ucwords($lc)) . ' Level · ' : '' ?>Standard Programme Curriculum</div>
    <div class="cover-rule"></div>
    <div class="cover-meta">
      <span><?= (int)$prog['weeks'] ?> weeks</span>
      <span><?= htmlspecialchars($certLabel) ?></span>
      <?php if ($prog['requires_core']): ?><span>Core track required</span><?php endif; ?>
      <?php if ($courses): ?><span><?= count($courses) ?> course<?= count($courses) === 1 ? '' : 's' ?></span><?php endif; ?>
      <span>Generated <?= $genDate ?></span>
    </div>
    <?php if ($totalPossible > 0): ?>
    <div class="cover-stats">
      <div><div class="cover-stat-n"><?= $totalAuthored ?></div><div class="cover-stat-l">Weeks authored</div></div>
      <div><div class="cover-stat-n"><?= $totalPossible ?></div><div class="cover-stat-l">Total weeks</div></div>
      <div><div class="cover-stat-n"><?= $totalPossible > 0 ? round($totalAuthored / $totalPossible * 100) : 0 ?>%</div><div class="cover-stat-l">Complete</div></div>
    </div>
    <?php endif; ?>
  </div>
  <div class="cover-footer">
    <div class="cover-monogram">M</div>
    <div class="cover-brand"><strong>MAM ACADEMY</strong>Music, Arts &amp; Mystery · academy.mamcentre.org</div>
    <div style="margin-left:auto;font-size:8pt;color:var(--smoke)"><?= htmlspecialchars($edition) ?></div>
  </div>
</div>

<!-- ── Table of Contents ── -->
<?php if ($courses): ?>
<div class="toc">
  <h2>Contents</h2>
  <div class="toc-rule"></div>
  <?php foreach ($courses as $i => $c):
    $cName     = (string)($c['course']['name'] ?? '');
    $cKind     = (string)($c['course']['kind'] ?? 'elective');
    $cAuthored = count(array_filter($c['weeks'] ?? [], fn($w) => !empty($w['title'])));
  ?>
  <div class="toc-row">
    <span class="toc-idx"><?= $i + 1 ?></span>
    <span class="toc-name"><?= htmlspecialchars($cName) ?></span>
    <span class="toc-badge <?= $cKind === 'core' ? 'toc-core' : 'toc-elec' ?>"><?= htmlspecialchars($cKind) ?></span>
    <span class="toc-dots"></span>
    <span class="toc-progress"><?= $cAuthored ?> / <?= $progWeeks ?> wks</span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Course sections ── -->
<?php if (!$courses): ?>
  <div class="no-courses">No courses are linked to this programme yet. Visit the Programme Catalogue to add courses.</div>
<?php else: foreach ($courses as $i => $c):
  $cName     = (string)($c['course']['name'] ?? '');
  $cKind     = (string)($c['course']['kind'] ?? 'elective');
  $cDesc     = (string)($c['course']['description'] ?? '');
  $cLevel    = $c['level']['name'] ?? '';
  $weeksData = $c['weeks'] ?? [];
  $authored  = array_filter($weeksData, fn($w) => !empty($w['title']));
  $authCount = count($authored);
  $pct       = $progWeeks > 0 ? min(100, round($authCount / $progWeeks * 100)) : 0;
  $weeksByN  = [];
  foreach ($weeksData as $w) { $weeksByN[(int)$w['week']] = $w; }
?>
<div class="course-section">
  <div class="course-header">
    <span class="course-num"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
    <span class="course-title"><?= htmlspecialchars($cName) ?></span>
    <span class="course-badge <?= $cKind === 'core' ? '' : '' ?>" style="background:<?= $cKind === 'core' ? 'var(--core-bg);color:var(--core-fg)' : 'var(--elec-bg);color:var(--elec-fg)' ?>"><?= htmlspecialchars($cKind) ?></span>
  </div>
  <?php if ($cDesc): ?><p class="course-desc"><?= htmlspecialchars($cDesc) ?></p><?php endif; ?>
  <?php if ($cLevel): ?><div class="course-level">Level: <?= htmlspecialchars($cLevel) ?></div><?php endif; ?>
  <div class="course-progress">
    <div class="progress-bar-wrap"><div class="progress-bar-fill" style="width:<?= $pct ?>%"></div></div>
    <span class="progress-label"><?= $authCount ?> of <?= $progWeeks ?> weeks authored (<?= $pct ?>%)</span>
  </div>
  <?php if (!$weeksData && !$authCount): ?>
    <p class="no-modules-note">No weeks authored for this course yet.</p>
  <?php else: ?>
  <table class="weeks">
    <thead><tr><th>Wk</th><th>Type</th><th>Title &amp; Content</th></tr></thead>
    <tbody>
    <?php for ($wn = 1; $wn <= $progWeeks; $wn++):
      $w = $weeksByN[$wn] ?? null;
      $hasContent = $w && !empty($w['title']);
      $kind = $hasContent ? (string)($w['kind'] ?? 'module') : '';
    ?>
      <tr class="<?= $hasContent ? 'authored' : 'unwritten' ?>">
        <td class="wk-num <?= $hasContent ? '' : 'unwritten' ?>"><?= $wn ?></td>
        <td><?php if ($hasContent): ?><span class="wk-badge kind-<?= htmlspecialchars($kind) ?>"><?= htmlspecialchars($kind) ?></span><?php else: ?><span class="wk-badge kind-empty">—</span><?php endif; ?></td>
        <td>
          <?php if ($hasContent): ?>
            <div class="wk-title"><?= htmlspecialchars((string)$w['title']) ?></div>
            <?php if (!empty($w['content'])): ?><div class="wk-content"><?= nl2br(htmlspecialchars(substr((string)$w['content'], 0, 700))) ?></div><?php endif; ?>
          <?php else: ?>
            <span style="color:#ccc;font-size:8.5pt">Not yet authored</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endfor; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php endforeach; endif; ?>

<script>
window.onload = function() {
  if (window.opener || window.history.length <= 1) {
    setTimeout(function(){ window.print(); }, 500);
  }
};
</script>
</body></html><?php exit; }

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
        'wallet_credit' => 'users',
        'faculty_invite' => 'users', 'faculty_app_status' => 'users', 'faculty_request_review' => 'users',
        'module_proposal_review' => 'curriculum',
        'curriculum_clone' => 'curriculum',
        'course_create' => 'curriculum', 'course_update' => 'curriculum', 'course_toggle' => 'curriculum',
        'program_create' => 'curriculum', 'program_update' => 'curriculum',
        'link_course' => 'curriculum', 'unlink_course' => 'curriculum',
        'cohort_converge' => 'settings', 'cohort_create' => 'curriculum', 'cohort_edit' => 'curriculum',
        'bridge_legacy_enrollments' => 'settings',
        'module_save' => 'curriculum', 'module_delete' => 'curriculum',
        'module_scaffold' => 'curriculum', 'module_import' => 'curriculum',
        'set_student_type' => 'users',
        'resource_add' => 'media', 'resource_delete' => 'media', 'avatar_clear' => 'media',
        'datarights_erase' => 'users',
        'save_settings' => 'settings',
        'save_email_branding' => 'settings',
        'save_promo_banner' => 'settings',
        'delete_promo_banner' => 'settings',
        'automation_toggle' => 'settings',
        'run_automations' => 'settings',
        'record_placement' => 'admissions',
        'store_item_save' => 'store_catalog', 'store_item_delete' => 'store_catalog',
        'payment_refund' => 'settings', 'payment_confirm_bank' => 'settings',
        // Destructive record deletions + bulk seeding are super-only — a manager
        // must not be able to permanently remove applications/classes/enrolments.
        'app_delete' => 'settings', 'class_delete' => 'settings',
        'enrollment_delete' => 'settings', 'seed_starter' => 'settings',
        'survey_create' => 'support', 'survey_status' => 'support',
        'ticket_reply' => 'support', 'ticket_assign' => 'support', 'ticket_resolve' => 'support',
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

    // Issue (or adjust) a student's internal store credit — admin-issued only,
    // the sole way credits enter the wallet (#12). Positive adds, negative
    // deducts (never below zero). mam_wallet_post audits the movement itself.
    if ($action === 'wallet_credit') {
        $uid       = (int)($_POST['user_id'] ?? 0);
        $amountUsd = (float)($_POST['amount_usd'] ?? 0);
        $note      = substr(trim((string)($_POST['note'] ?? '')), 0, 255) ?: null;
        $me        = mam_current_user();
        if ($uid <= 0 || !is_finite($amountUsd) || $amountUsd == 0.0) { header('Location: ?v=users&err=' . urlencode('Enter a non-zero dollar amount.')); exit; }
        if (abs($amountUsd) > 100000) { header('Location: ?v=users&err=' . urlencode('That amount is too large.')); exit; }
        $target = mam_db_row('SELECT id, role FROM users WHERE id = ?', [$uid]);
        // The wallet now tracks INSTRUCTOR EARNINGS (students no longer have a wallet).
        if (!$target || !mam_user_has_role($target, 'instructor')) { header('Location: ?v=users&err=' . urlencode('Earnings apply to instructors.')); exit; }
        $cents = (int) round($amountUsd * 100);
        try {
            mam_wallet_post($uid, $cents, $cents >= 0 ? 'earnings' : 'earnings_adjust', $me ? (int)$me['id'] : null, null, $note);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage() === 'insufficient_credit' ? 'That deduction would take earnings below $0.' : 'Could not record those earnings.';
            header('Location: ?v=users&err=' . urlencode($msg)); exit;
        }
        header('Location: ?v=users&ok=1'); exit;
    }

    // ── Scholarship / student-type toggle ────────────────────────────────
    if ($action === 'set_student_type') {
        $uid  = (int)($_POST['user_id'] ?? 0);
        $type = strtolower(trim((string)($_POST['student_type'] ?? '')));
        if (!in_array($type, ['scholarship', 'paying'], true)) {
            header('Location: ?v=users&edit=' . $uid . '&err=' . urlencode('Invalid type value.')); exit;
        }
        $target = mam_db_row('SELECT id, role FROM users WHERE id = ?', [$uid]);
        if (!$target || !mam_user_has_role($target, 'student')) {
            header('Location: ?v=users&edit=' . $uid . '&err=' . urlencode('Scholarship tier applies to students only.')); exit;
        }
        mam_db_update('users', ['student_type' => $type === 'scholarship' ? 'scholarship' : null], ['id' => $uid]);
        mam_audit('set_student_type', 'user', $uid, ['type' => $type]);
        header('Location: ?v=users&edit=' . $uid . '&ok=1'); exit;
    }

    // ── Media & Documents library (#media) ──────────────────────────────
    // Add a class resource (document/media link) from admin, delete any
    // resource, or clear a student's portal photo. All gated by the 'media' cap.
    if ($action === 'resource_add') {
        $classId = (int)($_POST['class_id'] ?? 0);
        $title = mam_str($_POST, 'title', 200);
        $url   = mam_str($_POST, 'url', 500);
        $type  = mam_str($_POST, 'type', 16);
        if (!in_array($type, ['link', 'sheet', 'video', 'doc'], true)) $type = 'link';
        if ($classId <= 0 || $title === '' || !preg_match('#^https?://#i', $url)) { header('Location: ?v=media&err=' . urlencode('Pick a class and enter a title + a valid http(s) URL.')); exit; }
        if (!mam_db_row('SELECT id FROM classes WHERE id = ?', [$classId])) { header('Location: ?v=media&err=' . urlencode('That class no longer exists.')); exit; }
        $me = mam_current_user();
        $newId = mam_db_insert('class_resources', ['class_id' => $classId, 'title' => $title, 'url' => $url, 'type' => $type, 'posted_by' => $me ? (int)$me['id'] : null]);
        mam_audit('resource_add', 'class_resource', (int)$newId, ['class_id' => $classId, 'type' => $type]);
        header('Location: ?v=media&ok=1'); exit;
    }
    if ($action === 'resource_delete') {
        $rid = (int)($_POST['resource_id'] ?? 0);
        if ($rid > 0 && mam_db_row('SELECT id FROM class_resources WHERE id = ?', [$rid])) {
            mam_db()->prepare('DELETE FROM class_resources WHERE id = ?')->execute([$rid]);
            mam_audit('resource_delete', 'class_resource', $rid);
        }
        header('Location: ?v=media&ok=1'); exit;
    }
    if ($action === 'avatar_clear') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $target = $uid > 0 ? mam_db_row('SELECT id, role FROM users WHERE id = ?', [$uid]) : null;
        if ($target) {
            mam_db_update('users', ['avatar_url' => null], ['id' => $uid]);
            mam_audit('avatar_clear', 'user', $uid, ['by' => 'admin']);
        }
        header('Location: ?v=media&ok=1'); exit;
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

    // Bulk status change across selected applications — routes each through the
    // audited admissions state machine; illegal transitions are skipped, not
    // fatal. Provisioning stays a per-application step (drawer / single save).
    if ($action === 'app_bulk_status') {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))))));
        $status = (string)($_POST['status'] ?? '');
        $valid = ['submitted', 'in_review', 'accepted', 'waitlisted', 'paid', 'enrolled', 'cancelled'];
        if (!$ids || !in_array($status, $valid, true)) { header('Location: ?v=applications&err=' . urlencode('Select at least one application and a status.')); exit; }
        $ok = 0; $skip = 0;
        foreach (array_slice($ids, 0, 200) as $id) {
            try { mam_admissions_transition($id, $status, ((int)($adminUser['id'] ?? 0)) ?: null, 'bulk update'); $ok++; }
            catch (\Throwable $e) { $skip++; }
        }
        mam_audit('app_bulk_status', 'application', null, ['status' => $status, 'ok' => $ok, 'skipped' => $skip]);
        header('Location: ?v=applications&ok=' . urlencode("Updated {$ok} application(s) to “{$status}”" . ($skip ? " · {$skip} skipped (not allowed from their stage)" : '') . '.')); exit;
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

    // Data-subject erasure (NDPA/GDPR). Super-only (gated above). Requires a
    // typed confirmation to prevent accidents. Anonymises PII + deletes free-form
    // PII, preserving financial + audit records (lawful basis).
    if ($action === 'datarights_erase') {
        $email   = strtolower(trim((string)($_POST['email'] ?? '')));
        $confirm = (string)($_POST['confirm'] ?? '');
        if ($confirm !== 'ERASE') { header('Location: ?v=datarights&email=' . urlencode($email) . '&err=' . urlencode('Type ERASE to confirm.')); exit; }
        $res = mam_datarights_erase($email, (int)($adminUser['id'] ?? 0) ?: null);
        if (empty($res['ok'])) { header('Location: ?v=datarights&email=' . urlencode($email) . '&err=' . urlencode($res['error'] === 'not_found' ? 'No data found for that email.' : 'Could not erase (' . ($res['error'] ?? 'error') . ').')); exit; }
        header('Location: ?v=datarights&erased=1'); exit;
    }

    // Active-session management (P5; operational — a session admin manages their
    // OWN devices). Basic-Auth admins have no session row → guarded no-op.
    if ($action === 'session_revoke' || $action === 'session_revoke_others') {
        if ($adminUser && function_exists('mam_sessions_revoke')) {
            if ($action === 'session_revoke') { mam_sessions_revoke((int)$adminUser['id'], (string)($_POST['sid'] ?? '')); }
            else { mam_sessions_revoke_others((int)$adminUser['id']); }
        }
        header('Location: ?v=account&ok=1'); exit;
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
            // Welcome the new user with their sign-in details (same template the
            // password-reset flow uses) — an account isn't useful if nobody
            // tells its owner it exists.
            $mailed = false;
            try {
                $loginUrl = rtrim((string)mam_env('APP_URL', ''), '/') . ($role === 'admin' ? '/admin/' : '/portal/login');
                $mailed = mam_send($email, 'Your MAM Academy ' . $role . ' account is ready',
                    mam_email_account_created($name, $email, $pass, $role, $loginUrl),
                    'account_created', true);
            } catch (Throwable $e) { /* account still created; surface below */ }
            $flash = ['ok', 'User created · #' . $uid . ($mailed ? ' · welcome email sent' : ' · ⚠ welcome email failed — share the password manually')];
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
        $rawLink     = trim((string)($_POST['meeting_link'] ?? ''));
        $meetingLink = null;
        if ($rawLink !== '') {
            $validated = filter_var($rawLink, FILTER_VALIDATE_URL);
            if ($validated && preg_match('#^https://#i', $validated)) {
                $meetingLink = substr($validated, 0, 500);
            }
        }
        if ($title === '' || $discipline === '') {
            header('Location: ?v=classes&err=' . urlencode('Title and discipline are required.'));
            exit;
        }
        $cid = mam_db_insert('classes', [
            'title' => $title, 'discipline' => $discipline, 'pathway' => $pathway,
            'instructor_id' => $instructor, 'mode' => $mode, 'schedule' => $schedule,
            'capacity' => $capacity, 'starts_on' => $startsOn, 'meeting_link' => $meetingLink,
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
        $ceid    = (int)($_POST['enrollment_id'] ?? 0);
        $iid     = (int)($_POST['instructor_id'] ?? 0);
        $status  = (string)($_POST['status'] ?? 'active');
        $actorId = (int)($adminUser['id'] ?? 0);
        if (!$ceid || !mam_db_row('SELECT id FROM course_enrollments WHERE id = ?', [$ceid])) {
            header('Location: ?v=courseenroll&err=' . urlencode('Course registration not found.')); exit;
        }
        // Instructor assignment can accompany any status change — do it first so
        // subsequent lifecycle functions see the updated instructor.
        if ($iid) mam_db_update('course_enrollments', ['instructor_id' => $iid], ['id' => $ceid]);

        if ($status === 'active') {
            mam_lms_approve($ceid, $iid ?: null, $actorId);
        } elseif ($status === 'withdrawn') {
            // Route through the state machine so the transition is logged to
            // enrolment_events and illegal moves are rejected rather than silently
            // written (a withdrawn→active reversal belongs in the audit trail).
            try { mam_lms_withdraw($ceid, $actorId); }
            catch (RuntimeException $e) {
                header('Location: ?v=courseenroll&err=' . urlencode('Cannot withdraw: ' . $e->getMessage())); exit;
            }
        } elseif (in_array($status, ['completed', 'failed'], true)) {
            // mam_lms_finalize() computes the gradebook result, rejects ungraded
            // enrollments (not_graded → 422-friendly message), fires the
            // enrolment.finalized event, notifies the student, and enqueues
            // auto-promotion — none of those ran through the old raw-UPDATE path.
            try {
                $fin  = mam_lms_finalize($ceid, $actorId);
                $cert = mam_lms_issue_for_enrollment($ceid, $actorId);
                if ($cert && !empty($cert['issued'])) {
                    header('Location: ?v=courseenroll&cert=' . urlencode($cert['reference'])); exit;
                }
            } catch (RuntimeException $e) {
                $msg = $e->getMessage() === 'not_graded'
                    ? 'No grades on record yet — score the student in the Gradebook before finalizing.'
                    : 'Cannot finalize: ' . $e->getMessage();
                header('Location: ?v=courseenroll&err=' . urlencode($msg)); exit;
            }
        } else {
            header('Location: ?v=courseenroll&err=' . urlencode('Invalid status — finalize from the Gradebook or use Withdraw.')); exit;
        }
        header('Location: ?v=courseenroll&ok=1'); exit;
    }

    // ─── Bridge all active legacy class enrollments into course_enrollments ─
    if ($action === 'bridge_legacy_enrollments') {
        $bridged = 0; $skipped = 0; $errors = [];
        $rows = mam_db_all(
            "SELECT e.student_id, cl.discipline, cl.instructor_id
               FROM enrollments e
               JOIN classes cl ON cl.id = e.class_id
              WHERE e.status = 'active' AND cl.discipline IS NOT NULL AND cl.discipline <> ''
              ORDER BY e.student_id"
        );
        $adminId = (int)($adminUser['id'] ?? 0);
        foreach ($rows as $row) {
            $sid   = (int)$row['student_id'];
            $disc  = (string)$row['discipline'];
            $iid   = (int)($row['instructor_id'] ?? 0);
            $ceId  = 0;
            try {
                $ceRow = mam_lms_add_course($sid, $disc);
                $ceId  = (int)$ceRow['id'];
                $bridged++;
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'already_enrolled') {
                    $ex = mam_db_row("SELECT ce.id FROM course_enrollments ce JOIN courses c ON c.id = ce.course_id WHERE ce.student_id = ? AND c.code = ? AND ce.status IN ('pending','active') LIMIT 1", [$sid, $disc]);
                    $ceId = $ex ? (int)$ex['id'] : 0;
                    $skipped++;
                } else {
                    $errors[] = "student $sid / $disc: " . $e->getMessage();
                }
            } catch (\Throwable $e) {
                $errors[] = "student $sid / $disc: " . $e->getMessage();
            }
            if ($ceId > 0) {
                try { mam_lms_approve($ceId, $iid ?: null, $adminId); }
                catch (\Throwable $e) { /* already active */ }
            }
        }
        $msg = "Bridged $bridged" . ($skipped ? ", $skipped already linked" : '') . ($errors ? ', ' . count($errors) . ' failed (see logs)' : '') . ' legacy class enrollment' . ($bridged + $skipped + count($errors) !== 1 ? 's' : '') . '.';
        foreach ($errors as $err) mam_log('warn', 'bridge_legacy_error', ['detail' => $err]);
        mam_audit('bridge_legacy_enrollments', 'course_enrollments', 0, ['bridged' => $bridged, 'skipped' => $skipped, 'errors' => count($errors)]);
        header('Location: ?v=courseenroll&ok=' . urlencode($msg)); exit;
    }

    // ─── Assign / add a course to a student from the user-edit view ────
    if ($action === 'student_course_assign') {
        $uid  = (int)($_POST['user_id'] ?? 0);
        $code = trim((string)($_POST['course_code'] ?? ''));
        $iid  = (int)($_POST['instructor_id'] ?? 0);
        if (!$uid || !$code) { header('Location: ?v=users&edit=' . $uid . '&err=' . urlencode('Select a course.')); exit; }
        $student = mam_db_row("SELECT id FROM users WHERE id = ?", [$uid]);
        if (!$student) { header('Location: ?v=users&err=' . urlencode('Student not found.')); exit; }
        $ceId = 0;
        try {
            $ceRow = mam_lms_add_course($uid, $code);
            $ceId  = (int)$ceRow['id'];
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'already_enrolled') {
                $existing = mam_db_row(
                    "SELECT ce.id FROM course_enrollments ce JOIN courses c ON c.id = ce.course_id
                      WHERE ce.student_id = ? AND c.code = ? AND ce.status IN ('pending','active') LIMIT 1",
                    [$uid, $code]
                );
                $ceId = $existing ? (int)$existing['id'] : 0;
            } else {
                header('Location: ?v=users&edit=' . $uid . '&err=' . urlencode($e->getMessage() === 'course_not_found' ? 'Course not found — check the course catalogue.' : $e->getMessage())); exit;
            }
        } catch (\Throwable $e) {
            header('Location: ?v=users&edit=' . $uid . '&err=' . urlencode('Could not add course: ' . $e->getMessage())); exit;
        }
        if ($ceId > 0) {
            try { mam_lms_approve($ceId, $iid ?: null, (int)($adminUser['id'] ?? 0)); }
            catch (\Throwable $e) { /* already active — instructor_id updated regardless */ }
            mam_audit('admin_course_assign', 'course_enrollment', $ceId, ['student' => $uid, 'course' => $code, 'instructor' => $iid]);
        }
        header('Location: ?v=users&edit=' . $uid . '&ok=1'); exit;
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
    if (in_array($action, ['module_save', 'module_delete', 'module_scaffold', 'module_import'], true)) {
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

        // Bulk import — paste/upload JSON or CSV; upserts by (course,level,week)
        // into the selected course (rows may carry their own course_code/level_code).
        if ($action === 'module_import') {
            $text = (string)($_POST['import_text'] ?? '');
            // A remote data-source URL (published CSV/JSON) wins over pasted text;
            // an uploaded file wins over both.
            $impUrl = trim((string)($_POST['import_url'] ?? ''));
            if ($impUrl !== '') {
                $fetched = mam_lms_fetch_url($impUrl);
                if ($fetched === null) { header('Location: ' . $back . '&err=' . urlencode('Could not fetch that data source — use a public http(s) CSV/JSON URL.')); exit; }
                $text = $fetched;
            }
            if (!empty($_FILES['import_file']['tmp_name']) && is_uploaded_file($_FILES['import_file']['tmp_name'])) {
                $sz = (int)($_FILES['import_file']['size'] ?? 0);
                if ($sz <= 0 || $sz > 2 * 1024 * 1024) { header('Location: ' . $back . '&err=' . urlencode('That file is empty or larger than 2 MB.')); exit; }
                $text = (string)file_get_contents($_FILES['import_file']['tmp_name']);
            }
            $rows = mam_lms_rows_from_text($text, (string)($_POST['import_format'] ?? ''));
            if (!$rows) { header('Location: ' . $back . '&err=' . urlencode('Nothing to import — paste JSON/CSV or choose a file with week & title columns.')); exit; }
            $res = mam_lms_import_modules($courseId, $levelId, $rows);
            $q = '&imp_c=' . (int)$res['created'] . '&imp_u=' . (int)$res['updated'] . '&imp_s=' . (int)$res['skipped'];
            if (!empty($res['errors'])) {
                $first = $res['errors'][0];
                $more  = count($res['errors']) - 1;
                $q .= '&imp_e=' . urlencode('Row ' . ($first['row'] ?? '?') . ': ' . ($first['reason'] ?? 'error') . ($more > 0 ? ' (+' . $more . ' more)' : ''));
            }
            header('Location: ' . $back . $q); exit;
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
        // Land back on the week that was just saved, not the top of the page.
        header('Location: ' . $back . '&ok=1#wk' . max(1, (int)($_POST['week'] ?? 1))); exit;
    }

    // ─── Faculty invites: approve/dismiss requests, direct invites, app status ──
    if ($action === 'faculty_invite') {
        // Direct invite: create (or reuse) the request, approve it and send the
        // single-use, 7-day link in one step.
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { header('Location: ?v=users&err=' . urlencode('Enter a valid email.') . '#faculty'); exit; }
        $me = mam_current_user();
        $ok = false;
        try {
            $inv = mam_faculty_approve(mam_faculty_request($email, null, 'admin'), $me ? (int)$me['id'] : null);
            if ($inv) $ok = mam_faculty_send_invite($inv);
        } catch (Throwable $e) { /* surfaced below */ }
        header('Location: ?v=users' . ($ok ? '&ok=' . urlencode('Invite sent — single-use link, valid ' . MAM_FACULTY_INVITE_DAYS . ' days.') : '&err=' . urlencode('Invite email failed to send — check mail settings.')) . '#faculty'); exit;
    }
    if ($action === 'faculty_request_review') {
        $rid = (int)($_POST['invite_id'] ?? 0);
        $decision = (string)($_POST['decision'] ?? '');
        $me = mam_current_user();
        if ($rid > 0 && $decision === 'approve') {
            $ok = false;
            try { $inv = mam_faculty_approve($rid, $me ? (int)$me['id'] : null); if ($inv) $ok = mam_faculty_send_invite($inv); } catch (Throwable $e) {}
            header('Location: ?v=users' . ($ok ? '&ok=' . urlencode('Approved — application form emailed (single-use, ' . MAM_FACULTY_INVITE_DAYS . ' days).') : '&err=' . urlencode('Approved, but the email failed to send — use "Send form" to retry.')) . '#faculty'); exit;
        }
        if ($rid > 0 && $decision === 'dismiss') { mam_faculty_dismiss($rid, $me ? (int)$me['id'] : null); }
        header('Location: ?v=users&ok=1#faculty'); exit;
    }
    if ($action === 'faculty_app_status') {
        $fid = (int)($_POST['app_id'] ?? 0);
        $st  = (string)($_POST['status'] ?? '');
        if ($fid > 0 && in_array($st, ['submitted', 'reviewing', 'approved', 'declined'], true)) {
            mam_db_update('instructor_applications', ['status' => $st], ['id' => $fid]);
            mam_audit('faculty_app_status', 'instructor_application', $fid, ['status' => $st]);
        }
        header('Location: ?v=users&ok=1#faculty'); exit;
    }

    // ─── Review a faculty curriculum proposal (approve → applied to modules) ──
    if ($action === 'curriculum_clone') {
        $courseCode = (string)($_POST['course_code'] ?? '');
        $fromLevel  = (string)($_POST['from_level'] ?? '');
        $toLevel    = (string)($_POST['to_level'] ?? '');
        $back = '?v=modules&course=' . urlencode($courseCode) . '&level=' . urlencode($toLevel);
        if ($courseCode === '' || $toLevel === '' || $fromLevel === $toLevel) {
            header('Location: ' . $back . '&err=' . urlencode('Pick a different target level to clone into.')); exit;
        }
        try {
            $res = mam_lms_clone_syllabus($courseCode, $fromLevel, $toLevel);
            if ($res['total'] === 0) { header('Location: ?v=modules&course=' . urlencode($courseCode) . '&level=' . urlencode($fromLevel) . '&err=' . urlencode('Nothing to clone — author the source level first.')); exit; }
            header('Location: ' . $back . '&ok=' . urlencode('Cloned ' . $res['total'] . ' week' . ($res['total'] === 1 ? '' : 's') . ' — ' . $res['created'] . ' new, ' . $res['updated'] . ' updated.')); exit;
        } catch (\Throwable $e) {
            header('Location: ' . $back . '&err=' . urlencode('Could not clone that syllabus.')); exit;
        }
    }

    // Cohort convergence (P2 write-path): reconcile a backfilled cohort's legacy
    // class roster into course_enrollments so those students become gradable +
    // markable in the cohort tools. Additive + idempotent (mam_lms_*).
    if ($action === 'waitlist_promote') {
        $cohortId = (int)($_POST['cohort_id'] ?? 0);
        $force    = !empty($_POST['force']);
        if ($cohortId <= 0) { header('Location: ?v=courseenroll&err=' . urlencode('Missing cohort.')); exit; }
        try {
            $promoted = mam_lms_waitlist_promote($cohortId, ((int)($adminUser['id'] ?? 0)) ?: null, $force);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'cohort_full') {
                header('Location: ?v=courseenroll&err=' . urlencode('Cohort is full. Use "Force promote" to override the capacity limit.')); exit;
            }
            header('Location: ?v=courseenroll&err=' . urlencode('Could not promote waitlist student.')); exit;
        }
        header('Location: ?v=courseenroll&ok=' . urlencode($promoted ? 'Promoted student #' . $promoted . ' from the waitlist.' : 'Waitlist is empty.')); exit;
    }

    if ($action === 'cohort_converge') {
        $cohortId = (int)($_POST['cohort_id'] ?? 0);
        if ($cohortId <= 0) { header('Location: ?v=courseenroll&err=' . urlencode('Pick a cohort to converge.')); exit; }
        try {
            $r = mam_lms_converge_cohort_enrollments($cohortId, ((int)($adminUser['id'] ?? 0)) ?: null);
        } catch (\Throwable $e) {
            header('Location: ?v=courseenroll&err=' . urlencode('Could not converge that cohort.')); exit;
        }
        if ($r['reason'] === 'no_course_mapping') { header('Location: ?v=courseenroll&err=' . urlencode('Map this cohort to a course first — then converge its class roster.')); exit; }
        if ($r['reason'] === 'not_backfilled')   { header('Location: ?v=courseenroll&err=' . urlencode('That cohort has no legacy class to converge.')); exit; }
        $made = (int)$r['converged'] + (int)$r['linked'];
        header('Location: ?v=courseenroll&ok=' . urlencode($made
            ? ('Converged the legacy roster — ' . (int)$r['converged'] . ' enrolled, ' . (int)$r['linked'] . ' linked' . ($r['skipped'] ? ', ' . (int)$r['skipped'] . ' already enrolled' : '') . '.')
            : 'Nothing to converge — the class roster is already enrolled.')); exit;
    }

    if ($action === 'cohort_create') {
        $title   = substr(trim((string)($_POST['title'] ?? '')), 0, 200);
        $cid     = (int)($_POST['course_id'] ?? 0) ?: null;
        $iid     = (int)($_POST['instructor_id'] ?? 0) ?: null;
        $cap     = max(1, min(500, (int)($_POST['capacity'] ?? 12)));
        $starts  = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['starts_on'] ?? '')) ? $_POST['starts_on'] : null;
        $ends    = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['ends_on']   ?? '')) ? $_POST['ends_on']   : null;
        $term    = substr(trim((string)($_POST['term'] ?? '')), 0, 60) ?: null;
        if ($title === '') { header('Location: ?v=courseenroll&err=' . urlencode('Cohort title is required.')); exit; }
        $newId = mam_lms_create_cohort([
            'title'         => $title,
            'course_id'     => $cid,
            'instructor_id' => $iid,
            'capacity'      => $cap,
            'starts_on'     => $starts,
            'ends_on'       => $ends,
            'term'          => $term,
            'status'        => 'active',
        ]);
        header('Location: ?v=courseenroll&ok=' . urlencode('Cohort #' . $newId . ' created.')); exit;
    }

    if ($action === 'cohort_edit') {
        $cohortId = (int)($_POST['cohort_id'] ?? 0);
        if ($cohortId <= 0) { header('Location: ?v=courseenroll&err=' . urlencode('Invalid cohort.')); exit; }
        if (!mam_db_row('SELECT id FROM cohorts WHERE id = ?', [$cohortId])) { header('Location: ?v=courseenroll&err=' . urlencode('Cohort not found.')); exit; }
        $updates = [];
        $title = substr(trim((string)($_POST['title'] ?? '')), 0, 200);
        if ($title !== '') $updates['title'] = $title;
        $cid = (int)($_POST['course_id'] ?? 0);
        if ($cid > 0) $updates['course_id'] = $cid;
        $iid = (int)($_POST['instructor_id'] ?? 0);
        $updates['instructor_id'] = $iid > 0 ? $iid : null;
        $cap = (int)($_POST['capacity'] ?? 0);
        if ($cap > 0) $updates['capacity'] = max(1, min(500, $cap));
        $starts = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['starts_on'] ?? '')) ? $_POST['starts_on'] : null;
        $ends   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['ends_on']   ?? '')) ? $_POST['ends_on']   : null;
        if ($starts !== null) $updates['starts_on'] = $starts;
        if ($ends   !== null) $updates['ends_on']   = $ends;
        $term = substr(trim((string)($_POST['term'] ?? '')), 0, 60);
        if ($term !== '') $updates['term'] = $term;
        $status = $_POST['status'] ?? '';
        if (in_array($status, ['active','inactive','archived'], true)) $updates['status'] = $status;
        if ($updates) {
            mam_db_update('cohorts', $updates, ['id' => $cohortId]);
            mam_audit('cohort_edit', 'cohort', $cohortId, $updates);
        }
        header('Location: ?v=courseenroll&ok=' . urlencode('Cohort #' . $cohortId . ' updated.')); exit;
    }

    if ($action === 'module_proposal_review') {
        $pid = (int)($_POST['proposal_id'] ?? 0);
        $approve = ($_POST['decision'] ?? '') === 'approve';
        $note = substr(trim((string)($_POST['review_note'] ?? '')), 0, 500) ?: null;
        $me = mam_current_user();
        if ($pid > 0) {
            try { mam_lms_review_proposal($pid, $approve, $me ? (int)$me['id'] : null, $note); }
            catch (\Throwable $e) { header('Location: ?v=modules&err=' . urlencode('Could not review that proposal.')); exit; }
        }
        header('Location: ?v=modules&ok=1'); exit;
    }

    // ─── Load the bundled starter curriculum (from MAM's documents) ──
    if ($action === 'seed_starter') {
        $force = (int)($_POST['force'] ?? 0) === 1;
        $res = mam_lms_seed_starter_curriculum($force);
        $c = 0; $u = 0;
        foreach ($res as $r) { $c += (int)($r['created'] ?? 0); $u += (int)($r['updated'] ?? 0); }
        header('Location: ?v=modules&seed_c=' . $c . '&seed_u=' . $u . '&seed_n=' . count($res)); exit;
    }

    // ─── Enterprise curriculum management (v41) ──────────────────────
    if ($action === 'course_create') {
        $code = trim((string)($_POST['code'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        if ($code === '' || $name === '') { header('Location: ?v=programs&err=' . urlencode('Code and name are required.')); exit; }
        try {
            mam_lms_create_course($code, $name, (string)($_POST['kind'] ?? 'elective'), trim((string)($_POST['description'] ?? '')) ?: null, (int)($_POST['sort_order'] ?? 99));
            header('Location: ?v=programs&ok=' . urlencode('Course "' . $name . '" created.')); exit;
        } catch (RuntimeException $e) {
            header('Location: ?v=programs&err=' . urlencode('A course with that code already exists.')); exit;
        }
    }

    if ($action === 'course_update') {
        $cid = (int)($_POST['course_id'] ?? 0);
        if ($cid < 1) { header('Location: ?v=programs&err=' . urlencode('Invalid course.')); exit; }
        mam_lms_update_course($cid, [
            'name'        => trim((string)($_POST['name'] ?? '')),
            'kind'        => (string)($_POST['kind'] ?? 'elective'),
            'description' => trim((string)($_POST['description'] ?? '')) ?: null,
            'sort_order'  => (int)($_POST['sort_order'] ?? 99),
        ]);
        header('Location: ?v=programs&ok=' . urlencode('Course updated.')); exit;
    }

    if ($action === 'course_toggle') {
        $cid = (int)($_POST['course_id'] ?? 0);
        if ($cid < 1) { header('Location: ?v=programs&err=' . urlencode('Invalid course.')); exit; }
        $row = mam_db_row('SELECT id, active FROM courses WHERE id = ?', [$cid]);
        if ($row) mam_lms_update_course($cid, ['active' => (int)$row['active'] ? 0 : 1]);
        header('Location: ?v=programs&ok=1'); exit;
    }

    if ($action === 'program_create') {
        $code = trim((string)($_POST['code'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        if ($code === '' || $name === '') { header('Location: ?v=programs&err=' . urlencode('Code and name are required.')); exit; }
        try {
            mam_lms_create_program(
                $code, $name, (int)($_POST['weeks'] ?? 12),
                (int)($_POST['revision_week'] ?? 0) ?: null,
                (int)($_POST['exam_week'] ?? 0) ?: null,
                !empty($_POST['requires_core']),
                (string)($_POST['certification'] ?? 'completion'),
                (int)($_POST['sort_order'] ?? 99)
            );
            header('Location: ?v=programs&ok=' . urlencode('Programme "' . $name . '" created.')); exit;
        } catch (RuntimeException $e) {
            header('Location: ?v=programs&err=' . urlencode('A programme with that code already exists.')); exit;
        }
    }

    if ($action === 'program_update') {
        $pid = (int)($_POST['program_id'] ?? 0);
        if ($pid < 1) { header('Location: ?v=programs&err=' . urlencode('Invalid programme.')); exit; }
        mam_lms_update_program($pid, [
            'name'          => trim((string)($_POST['name'] ?? '')),
            'weeks'         => (int)($_POST['weeks'] ?? 12),
            'revision_week' => (int)($_POST['revision_week'] ?? 0) ?: null,
            'exam_week'     => (int)($_POST['exam_week'] ?? 0) ?: null,
            'requires_core' => !empty($_POST['requires_core']),
            'certification' => (string)($_POST['certification'] ?? 'completion'),
            'sort_order'    => (int)($_POST['sort_order'] ?? 99),
        ]);
        header('Location: ?v=programs&ok=' . urlencode('Programme updated.')); exit;
    }

    if ($action === 'link_course') {
        $cid = (int)($_POST['course_id'] ?? 0);
        $pid = (int)($_POST['program_id'] ?? 0);
        if ($cid < 1 || $pid < 1) { header('Location: ?v=programs&err=' . urlencode('Select a course to add.')); exit; }
        try {
            mam_lms_link_course_to_program($cid, $pid, trim((string)($_POST['level_code'] ?? '')) ?: null, (int)($_POST['sort_order'] ?? 99));
        } catch (InvalidArgumentException $e) { /* not found — silently ignore */ }
        header('Location: ?v=programs&prog=' . $pid . '&ok=' . urlencode('Course linked.')); exit;
    }

    if ($action === 'unlink_course') {
        $cid = (int)($_POST['course_id'] ?? 0);
        $pid = (int)($_POST['program_id'] ?? 0);
        if ($cid > 0 && $pid > 0) mam_lms_unlink_course_from_program($cid, $pid);
        header('Location: ?v=programs&prog=' . $pid . '&ok=' . urlencode('Course removed.')); exit;
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

    if ($action === 'save_email_branding') {
        $url = trim((string)($_POST['email_logo_url'] ?? ''));
        if ($url !== '') {
            $s = strtolower(substr($url, 0, 8));
            if ($s !== 'https://' && substr($s, 0, 7) !== 'http://') { $url = ''; }
            else { $url = mb_substr($url, 0, 500); }
        }
        mam_setting_set('email_logo_url', $url);
        mam_audit('save_email_branding', 'settings', null, ['email_logo_url' => $url]);
        header('Location: ?v=email-branding&ok=1'); exit;
    }

    if ($action === 'save_promo_banner') {
        $id       = (int)($_POST['banner_id'] ?? 0);
        $title    = mb_substr(trim((string)($_POST['banner_title']    ?? '')), 0, 200);
        $body     = mb_substr(trim((string)($_POST['banner_body']     ?? '')), 0, 1000);
        $ctaLabel = mb_substr(trim((string)($_POST['banner_cta_label'] ?? '')), 0, 100);
        $ctaUrl   = mb_substr(trim((string)($_POST['banner_cta_url']   ?? '')), 0, 500);
        $bgColor  = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($_POST['banner_bg_color'] ?? ''))
            ? (string)$_POST['banner_bg_color'] : '#FEF3C7';
        $active   = !empty($_POST['banner_active']) ? 1 : 0;
        $startsAt = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['banner_starts_at'] ?? ''))
            ? (string)$_POST['banner_starts_at'] : null;
        $endsAt   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['banner_ends_at'] ?? ''))
            ? (string)$_POST['banner_ends_at'] : null;
        if ($title === '' || $body === '') {
            header('Location: ?v=email-branding&err=' . urlencode('Title and banner text are required.')); exit;
        }
        $now = date('Y-m-d H:i:s');
        if ($id > 0 && mam_db_row('SELECT id FROM promo_banners WHERE id = ?', [$id])) {
            mam_db_update('promo_banners', [
                'title' => $title, 'body' => $body, 'cta_label' => $ctaLabel,
                'cta_url' => $ctaUrl, 'bg_color' => $bgColor, 'active' => $active,
                'starts_at' => $startsAt, 'ends_at' => $endsAt, 'updated_at' => $now,
            ], ['id' => $id]);
            mam_audit('update_promo_banner', 'promo_banner', $id, ['title' => $title]);
        } else {
            mam_db()->prepare(
                'INSERT INTO promo_banners (title,body,cta_label,cta_url,bg_color,active,starts_at,ends_at,created_at,updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            )->execute([$title, $body, $ctaLabel, $ctaUrl, $bgColor, $active, $startsAt, $endsAt, $now, $now]);
            mam_audit('create_promo_banner', 'promo_banner', null, ['title' => $title]);
        }
        header('Location: ?v=email-branding&ok=1'); exit;
    }

    if ($action === 'delete_promo_banner') {
        $id = (int)($_POST['banner_id'] ?? 0);
        if ($id > 0) {
            mam_db()->prepare('DELETE FROM promo_banners WHERE id = ?')->execute([$id]);
            mam_audit('delete_promo_banner', 'promo_banner', $id, []);
        }
        header('Location: ?v=email-branding&ok=1'); exit;
    }

    // Record a MAM Auditer placement for an application's student — writes the
    // auditer_results row and (when the 'auditer_placement' automation is on)
    // ensures the account + enrols them at the recommended level (see
    // api/lib/auditer.php). Gated to the 'admissions' cap above.
    if ($action === 'record_placement') {
        $appId = (int)($_POST['app_id'] ?? 0);
        $app   = $appId ? mam_db_row('SELECT id, customer_id, pathway FROM applications WHERE id = ?', [$appId]) : null;
        if (!$app || !$app['customer_id']) { header('Location: ?v=applications&err=' . urlencode('Application or customer not found.')); exit; }
        $courseCode = trim((string)($_POST['course_code'] ?? ''));
        $levelCode  = trim((string)($_POST['level_code'] ?? ''));
        if ($courseCode === '' || $levelCode === '') { header('Location: ?v=applications&app=' . $appId . '&err=' . urlencode('Pick a course and level.')); exit; }
        try {
            $res = mam_auditer_record((int)$app['customer_id'], (string)($app['pathway'] ?? ''), [
                'course_code' => $courseCode,
                'level_code'  => $levelCode,
                'notes'       => (string)($_POST['notes'] ?? ''),
                'audited_by'  => (int)($adminUser['id'] ?? 0),
            ]);
            $msg = $res['enrolled']
                ? 'Placement recorded — student enrolled.'
                : ($res['note'] === 'automation_off' ? 'Placement recorded (auto-enrol is paused).' : 'Placement recorded (' . $res['note'] . ').');
            header('Location: ?v=applications&app=' . $appId . '&ok=' . urlencode($msg)); exit;
        } catch (\Throwable $e) {
            header('Location: ?v=applications&app=' . $appId . '&err=' . urlencode('Could not record placement.')); exit;
        }
    }

    // Run the scheduled automation sweeps on demand (the same ones the cron
    // drains) — lets an admin trigger + see counts without server access.
    if ($action === 'run_automations') {
        $r = ['reminders' => 0, 'birthdays' => 0, 'drip' => 0];
        if (function_exists('mam_automation_session_reminder_sweep')) $r['reminders'] = mam_automation_session_reminder_sweep();
        if (function_exists('mam_automation_ktams_birthday_sweep'))   $r['birthdays'] = mam_automation_ktams_birthday_sweep();
        if (function_exists('mam_automation_week_drip_sweep'))         $r['drip']      = mam_automation_week_drip_sweep();
        mam_audit('run_automations', 'automation', null, $r);
        header('Location: ?v=automations&ok=' . urlencode('Ran scheduled sweeps — ' . (int)$r['reminders'] . ' session reminder(s), ' . (int)$r['birthdays'] . ' birthday note(s), ' . (int)$r['drip'] . ' module drip(s).')); exit;
    }

    // Toggle a lifecycle automation on/off (super/settings only — gated above).
    // The flag is honoured at each rule's trigger point (see api/lib/automations.php).
    if ($action === 'automation_toggle') {
        $key = (string)($_POST['key'] ?? '');
        if (isset(mam_automations_registry()[$key])) {
            $on = (string)($_POST['enabled'] ?? '') === '1';
            mam_automation_set($key, $on);
            mam_audit('automation_toggle', 'automation', null, ['key' => $key, 'enabled' => $on]);
            header('Location: ?v=automations&ok=' . urlencode(($on ? 'Enabled' : 'Paused') . ' — ' . $key)); exit;
        }
        header('Location: ?v=automations&err=' . urlencode('Unknown automation.')); exit;
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
        // Secondary portal access (user_roles): a user can be both student +
        // instructor. The primary role lives in users.role (folded in by
        // mam_user_roles), so we only grant/revoke the OTHER portal role here.
        $extra = array_map('strval', (array)($_POST['extra_roles'] ?? []));
        foreach (['student', 'instructor'] as $rc) {
            if ($rc === $role) continue; // primary role is authoritative, not a secondary grant
            if (in_array($rc, $extra, true)) mam_user_ensure_role($uid, $rc);
            else mam_user_remove_role($uid, $rc);
        }
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
        $rawLink2 = trim((string)($_POST['meeting_link'] ?? ''));
        $meetingLink2 = null;
        if ($rawLink2 !== '') {
            $validated2 = filter_var($rawLink2, FILTER_VALIDATE_URL);
            if ($validated2 && preg_match('#^https://#i', $validated2)) {
                $meetingLink2 = substr($validated2, 0, 500);
            }
        }
        $upd = [
            'title'         => trim((string)($_POST['title'] ?? '')),
            'discipline'    => trim((string)($_POST['discipline'] ?? '')),
            'pathway'       => trim((string)($_POST['pathway'] ?? '')) ?: null,
            'instructor_id' => $_POST['instructor_id'] === '' ? null : (int)($_POST['instructor_id'] ?? 0),
            'mode'          => trim((string)($_POST['mode'] ?? '')) ?: null,
            'capacity'      => (int)($_POST['capacity'] ?? 0),
            'starts_on'     => trim((string)($_POST['starts_on'] ?? '')) ?: null,
            'schedule'      => trim((string)($_POST['schedule'] ?? '')) ?: null,
            'meeting_link'  => $meetingLink2,
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
        // provider_response is a JSON column on MySQL/MariaDB — appending plain
        // text produces invalid JSON and the write is rejected (error 4025). Merge
        // the admin marker into a valid JSON object instead.
        $prObj = mam_payment_response_merge($p['provider_response'] ?? null, ['admin_refund' => ['note' => $note, 'at' => date('c')]]);
        mam_db_update('payments', [
            'status' => 'refunded',
            'provider_response' => $prObj,
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
            'provider_response' => mam_payment_response_merge($p['provider_response'] ?? null, ['admin_confirm' => ['note' => $note, 'at' => date('c')]]),
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
            // Provision student portal + auto-enroll + generate enrollment ref,
            // exactly as the automatic webhook path does via mam_payment_verify.
            $settleTag = mam_payment_settle_tuition((int)$p['application_id']);
            mam_log('info', 'bank_confirm_settle', ['payment_id' => $pid, 'app_id' => $p['application_id'], 'result' => $settleTag]);
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
<meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
<title>MAM Admin — <?= htmlspecialchars($view) ?></title>
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<!-- Font Awesome — the dashboard uses fa-* icons throughout (nav, tables, the
     notifications panel, the top bar); without this they render blank. -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" referrerpolicy="no-referrer">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Cormorant+Garamond:wght@600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
/* Redesign design system — the candlelit "MAM Redesign" language from the
   prototype: warmer neutrals, calmer gold, Cormorant titles + Bebas numerals. */
:root{
  --bg:#1B140D;--panel:#251C13;--raised:#302517;--card:#3B2E1D;
  --text:#FCF8F3;--muted:rgba(252,248,243,0.72);--faint:rgba(252,248,243,0.5);
  --border:rgba(240,214,170,0.14);--border-strong:rgba(240,214,170,0.26);
  --gold:#E8961F;--gold-2:#F7A823;--goldhi:#FCC647;
  --emerald:#2FD39E;--red:#F0567E;--blue:#7FA9F0;--violet:#9A8BF2;
  --r:14px;--r-l:20px;
  --font-display:'Bebas Neue',Impact,sans-serif;--font-serif:'Cormorant Garamond',Georgia,serif;
}
html,body{height:100%}
/* Accessibility: visible keyboard focus everywhere + a skip link. */
a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible,[tabindex]:focus-visible,[role="switch"]:focus-visible{outline:2px solid var(--goldhi);outline-offset:2px;border-radius:8px}
main:focus{outline:none}
.skip-link{position:absolute;left:16px;top:-60px;z-index:10001;background:var(--goldhi);color:#1A120B;font-weight:800;padding:10px 16px;border-radius:10px;transition:top .18s}
.skip-link:focus{top:12px}
@media(prefers-reduced-motion:reduce){.skip-link{transition:none}}
body{font:14px/1.55 'Inter',-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:radial-gradient(1200px 700px at 82% -12%,rgba(232,150,31,0.06),transparent 55%),var(--bg);color:var(--text);height:100vh;overflow:hidden;display:grid;grid-template-columns:244px 1fr}
a{color:inherit;text-decoration:none}
aside{background:var(--panel);border-right:1px solid var(--border);padding:18px 14px;height:100vh;overflow:auto}
.brand{display:flex;align-items:center;gap:10px;padding:8px 10px 18px;border-bottom:1px solid var(--border);margin-bottom:14px}
.brand .logo{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,var(--gold),var(--goldhi));display:flex;align-items:center;justify-content:center;color:#1A120B;font-weight:800;font-family:var(--font-display);font-size:1.3rem}
.brand b{font-family:var(--font-display);font-size:1.2rem;letter-spacing:0.06em;font-weight:400}
.brand small{display:block;color:var(--muted);font-size:0.72rem;letter-spacing:0.06em;text-transform:uppercase}
nav a{display:flex;align-items:center;gap:11px;padding:10px 13px;color:var(--muted);border-radius:11px;margin-bottom:3px;font-size:0.9rem;font-weight:500;border:1px solid transparent}
nav a:hover{background:var(--raised);color:var(--text)}
nav a.active{background:linear-gradient(135deg,rgba(232,150,31,0.2),rgba(252,198,71,0.06));color:var(--goldhi);border:1px solid var(--border-strong);font-weight:600}
nav a i{width:18px;text-align:center;font-size:0.88rem;opacity:0.8;flex:0 0 auto}
nav a.active i{opacity:1;color:var(--gold)}
/* Grouped sidebar: a quiet section label introduces each domain of the admin. */
.nav-group{margin:15px 12px 5px;font-size:0.62rem;font-weight:800;letter-spacing:0.15em;text-transform:uppercase;color:var(--muted);opacity:0.62}
.nav-group:first-of-type{margin-top:2px}
.nav-note{margin-top:12px;padding:8px 11px;font-size:0.72rem;line-height:1.5;color:var(--muted);border:1px dashed var(--border);border-radius:8px}
/* Access-level (RBAC tier) badges — make the admin's level explicit. */
.tier-badge{display:inline-block;margin-top:6px;padding:2px 9px;border-radius:100px;font-size:0.6rem;font-weight:800;letter-spacing:0.08em;text-transform:uppercase}
.tier-super{background:linear-gradient(135deg,rgba(232,150,31,0.22),rgba(252,198,71,0.1));color:var(--goldhi);border:1px solid var(--border-strong)}
.tier-manager{background:rgba(99,102,241,0.2);color:#A5B4FC;border:1px solid rgba(165,180,252,0.4)}
.atb-tier{padding:3px 11px;border-radius:100px;font-size:0.64rem;font-weight:800;letter-spacing:0.06em;text-transform:uppercase}
.admin-topbar .atb-search{flex:1;min-width:0;max-width:440px;margin:0 auto;display:flex;align-items:center;gap:10px;height:42px;padding:0 14px;background:var(--panel);border:1px solid var(--border-strong);border-radius:11px;color:var(--faint);cursor:pointer;font-size:0.88rem;font-family:inherit;transition:border-color .15s,color .15s}
.admin-topbar .atb-search span{flex:1;text-align:left}
.admin-topbar .atb-search kbd{font-size:0.66rem;padding:2px 7px;border:1px solid var(--border-strong);border-radius:6px;color:var(--faint);font-family:inherit}
.admin-topbar .atb-search:hover{border-color:var(--gold);color:var(--muted)}
@media(max-width:760px){.admin-topbar .atb-search span,.admin-topbar .atb-search kbd{display:none}.admin-topbar .atb-search{flex:0 0 auto;max-width:none}}
/* Mobile top bar: wrap instead of overflowing; trim the identity chips. */
@media(max-width:640px){.admin-topbar{flex-wrap:wrap;gap:8px}.admin-topbar .atb-user{max-width:150px}.admin-topbar .atb-chip span,.admin-topbar .atb-chip{font-size:.78rem}}
@media(max-width:460px){.admin-topbar .atb-user{display:none}}
/* Curriculum completeness matrix (Standard Curriculum overview). */
.curric-matrix{width:100%;border-collapse:collapse;font-size:0.84rem;min-width:560px}
.curric-matrix th{font-size:0.64rem;letter-spacing:0.06em;text-transform:uppercase;color:var(--muted);font-weight:700;text-align:center;padding:8px 6px;border-bottom:1px solid var(--border);white-space:nowrap}
.curric-matrix th:first-child{text-align:left}
.curric-matrix td{padding:5px 6px;border-bottom:1px solid var(--border);text-align:center}
.cm-course{text-align:left;white-space:nowrap;color:var(--text);font-weight:600}
.cm-core{font-size:0.58rem;font-weight:800;letter-spacing:0.06em;text-transform:uppercase;color:var(--gold);border:1px solid var(--border);border-radius:100px;padding:1px 6px;margin-left:5px}
.cm-cell{display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:26px;border-radius:7px;font-weight:700;font-variant-numeric:tabular-nums;text-decoration:none;transition:all .15s var(--ease)}
.cm-cell.on{background:linear-gradient(135deg,rgba(224,138,26,0.22),rgba(251,191,36,0.10));color:var(--gold);border:1px solid rgba(245,168,35,0.45)}
.cm-cell.off{color:var(--muted);border:1px solid var(--border)}
.cm-cell:hover{border-color:var(--gold);color:var(--gold);transform:translateY(-1px)}
main{padding:0 36px 40px;overflow:auto;height:100vh}
/* Sticky app-shell top bar — page title left, account + view-site right. Bleeds
   to main's horizontal padding and frosts on scroll. */
.admin-topbar{position:sticky;top:0;z-index:50;display:flex;align-items:center;justify-content:space-between;gap:16px;
  margin:0 -36px 28px;padding:15px 36px;background:rgba(20,14,9,0.85);-webkit-backdrop-filter:blur(12px);backdrop-filter:blur(12px);border-bottom:1px solid var(--border)}
.admin-topbar .atb-title{font-size:0.98rem;font-weight:700;letter-spacing:0.02em;color:var(--text);display:flex;align-items:center;gap:10px}
.admin-topbar .atb-title .dot{width:7px;height:7px;border-radius:50%;background:linear-gradient(135deg,var(--gold),var(--gold-2));box-shadow:0 0 10px rgba(251,191,36,0.6)}
.admin-topbar .atb-right{display:flex;align-items:center;gap:10px}
.admin-topbar .atb-chip{display:inline-flex;align-items:center;gap:7px;padding:8px 13px;border:1px solid var(--border);border-radius:10px;color:var(--muted);font-size:0.82rem;transition:color .15s,border-color .15s}
.admin-topbar .atb-chip:hover{color:var(--gold);border-color:var(--gold)}
.admin-topbar .atb-user{display:inline-flex;align-items:center;gap:8px;color:var(--muted);font-size:0.82rem;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding-left:4px}
.admin-topbar .atb-user .av{width:28px;height:28px;border-radius:50%;flex:0 0 auto;background:linear-gradient(135deg,var(--gold),var(--gold-2));color:#1A120B;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:0.78rem}
header.top{display:flex;align-items:center;justify-content:space-between;margin:0 0 26px;gap:18px;flex-wrap:wrap}
header.top h1{font-family:var(--font-serif);font-size:2.1rem;font-weight:700;letter-spacing:0;line-height:1.05}
.btn{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;background:linear-gradient(135deg,var(--gold),var(--gold-2));color:#1A120B;border-radius:11px;font-weight:700;font-size:0.85rem;border:none;cursor:pointer;transition:filter 0.15s,transform 0.12s,box-shadow 0.15s}
.btn:hover{filter:brightness(1.06);transform:translateY(-1px);box-shadow:0 4px 14px rgba(224,138,26,0.35)}
.btn:active{filter:brightness(0.94);transform:translateY(1px);box-shadow:none}
.btn.ghost{background:transparent;color:var(--text);border:1px solid var(--border)}
.btn.ghost:hover{border-color:var(--gold);color:var(--gold);background:rgba(224,138,26,0.06);box-shadow:none;filter:none}
.btn.ghost:active{transform:translateY(1px)}
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:16px;margin-bottom:36px}
.kpi{padding:18px 18px 16px;background:var(--panel);border:1px solid var(--border);border-radius:var(--r)}
.kpi-label{font-size:0.68rem;color:var(--faint);letter-spacing:0.1em;text-transform:uppercase;margin-bottom:8px;font-weight:700}
.kpi-value{font-family:var(--font-display);font-size:2.3rem;font-weight:400;letter-spacing:0.01em;line-height:0.95;font-variant-numeric:tabular-nums}
.kpi-value.gold{background:linear-gradient(135deg,var(--gold),var(--gold-2));-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
/* Tables can be wide (9+ columns). The card is the horizontal scroll
   container; the h3 header is pinned via sticky so it doesn't pan with
   the table. Edge-fade backgrounds appear only when there's more to scroll. */
.card{background:var(--panel);border:1px solid var(--border);border-radius:var(--r-l);margin-bottom:26px;overflow:hidden;overflow-x:auto;-webkit-overflow-scrolling:touch;
  background-image:
    linear-gradient(to right,var(--panel) 30%,rgba(0,0,0,0)),
    linear-gradient(to right,rgba(0,0,0,0),var(--panel) 70%),
    linear-gradient(to right,rgba(0,0,0,0.4),rgba(0,0,0,0)),
    linear-gradient(to left,rgba(0,0,0,0.4),rgba(0,0,0,0));
  background-position:0 0,100% 0,0 0,100% 0;
  background-repeat:no-repeat;
  background-size:42px 100%,42px 100%,14px 100%,14px 100%;
  background-attachment:local,local,scroll,scroll;
}
.card h3{padding:16px 20px;font-size:0.95rem;font-weight:700;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;position:sticky;left:0;width:max-content;min-width:100%;background:var(--panel);z-index:2}
.card > table{min-width:720px}
table{width:100%;border-collapse:collapse;font-size:0.86rem}
th,td{padding:11px 16px;text-align:left;border-bottom:1px solid var(--border);vertical-align:top}
th{color:var(--muted);font-size:0.72rem;letter-spacing:0.08em;text-transform:uppercase;font-weight:700;background:var(--panel);white-space:nowrap}
tr:hover td{background:rgba(255,255,255,0.04)}
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
/* ── Base form controls: theme EVERY bare input/select/textarea in the content
   area so inline forms (app decision, placement, assignee, notes, …) match the
   dark UI instead of rendering as default white widgets. .form-grid / .tiny
   variants keep their own tighter styling via higher specificity. ── */
main input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]),
main select, main textarea{
  background:var(--panel);color:var(--text);border:1px solid var(--border);border-radius:8px;
  font:inherit;font-size:0.9rem;padding:9px 11px;min-height:40px;max-width:100%;outline:none;
  color-scheme:dark;transition:border-color .15s,box-shadow .15s}
main textarea{min-height:64px;line-height:1.5;padding:10px 12px;resize:vertical}
main input:not([type=hidden]):focus, main select:focus, main textarea:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(251,191,36,0.18)}
main input::placeholder, main textarea::placeholder{color:var(--faint,rgba(251,247,243,0.4))}
main input[type=checkbox], main input[type=radio]{accent-color:var(--gold);min-height:0}
main select option{background:var(--panel);color:var(--text)}
select.tiny,input.tiny{background:var(--panel);color:var(--text);border:1px solid var(--border);border-radius:6px;font-size:0.78rem;padding:4px 6px}
/* Keep compact inline controls compact even under the base rule above. */
main select.tiny,main input.tiny{min-height:0;padding:4px 6px;font-size:0.78rem;border-radius:6px}
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
/* Mobile: the rail becomes an off-canvas drawer behind a hamburger (redesign
   §10 — "sidebar collapses to a drawer"). */
.nav-toggle{display:none;flex:0 0 auto;width:40px;height:40px;border-radius:10px;background:var(--panel);border:1px solid var(--border-strong);color:var(--text);cursor:pointer;align-items:center;justify-content:center;font-size:1rem}
.nav-scrim{display:none;position:fixed;inset:0;z-index:9400;background:rgba(10,7,4,0.6)}
@media(max-width:920px){
  body{grid-template-columns:1fr}
  aside{position:fixed;left:0;top:0;height:100vh;width:264px;z-index:9500;transform:translateX(-100%);transition:transform .22s ease;box-shadow:12px 0 44px rgba(0,0,0,0.5)}
  body.nav-open aside{transform:none}
  body.nav-open .nav-scrim{display:block}
  .nav-toggle{display:inline-flex}
  main{padding:20px 16px;height:100vh}
  .top{flex-wrap:wrap;gap:10px}
  .form-grid{grid-template-columns:1fr}
}
@media(prefers-reduced-motion:reduce){aside{transition:none}}
@media(max-width:520px){
  .kpi-grid{grid-template-columns:1fr 1fr}
  th,td{padding:9px 12px;font-size:0.82rem}
  .card > table > tbody,.card > table > thead{min-width:560px}
}
.chart-row{display:grid;grid-template-columns:2fr 1fr;gap:18px;margin-bottom:22px}
.chart-row .card{margin:0}
.chart-row canvas{max-height:260px}
@media(max-width:860px){.chart-row{grid-template-columns:1fr}}
/* ── UX layer: pending-work badges, needs-attention queue, toast feedback ── */
.nav-count{margin-left:auto;background:linear-gradient(135deg,var(--gold),var(--gold-2));color:#1A120B;border-radius:100px;min-width:22px;height:20px;padding:0 7px;font-size:.68rem;font-weight:800;font-style:normal;display:inline-flex;align-items:center;justify-content:center;line-height:1}
nav a{display:flex;align-items:center}
.needs{border-color:var(--gold)}
.needs-list{display:flex;flex-direction:column;gap:0;padding:0 20px 16px}
.needs-item{display:flex;align-items:center;gap:12px;padding:11px 0;border-top:1px solid var(--border);text-decoration:none;color:var(--text)}
.needs-item:first-child{border-top:none}
.needs-item:hover .needs-verb{background:var(--gold);color:#1A120B}
.needs-n{min-width:40px;height:40px;border-radius:11px;background:var(--raised);border:1px solid var(--border-strong);color:var(--goldhi);font-family:var(--font-display);font-weight:400;display:flex;align-items:center;justify-content:center;font-size:1.35rem;flex-shrink:0}
.needs-label{flex:1;font-size:.9rem}
.needs-verb{font-size:.72rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase;border:1px solid var(--gold);color:var(--gold);border-radius:100px;padding:4px 12px;transition:all .15s}
.mam-toast-stack{position:fixed;top:16px;right:16px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.mam-toast{pointer-events:auto;display:flex;align-items:center;gap:10px;min-width:240px;max-width:380px;padding:12px 16px;border-radius:12px;font-size:.86rem;font-weight:600;box-shadow:0 12px 40px rgba(0,0,0,.45);transform:translateX(24px);opacity:0;transition:transform .3s cubic-bezier(.2,.9,.3,1.2),opacity .3s}
.mam-toast.in{transform:none;opacity:1}
.mam-toast.ok{background:#123B2C;border:1px solid #34D399;color:#A7F3D0}
.mam-toast.err{background:#3B1219;border:1px solid #FB7185;color:#FECDD3}
/* ── Automations: trigger→action rules with a no-JS toggle switch ── */
.auto-rule{display:flex;align-items:center;gap:16px;padding:15px 20px;border-top:1px solid var(--border)}
.auto-rule:first-child{border-top:none}
.auto-ico{flex-shrink:0;width:40px;height:40px;border-radius:11px;background:var(--bg);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--gold);font-size:1rem}
.auto-body{flex:1;min-width:0}
.auto-trig{font-weight:700;font-size:.92rem;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.auto-act{color:var(--muted);font-size:.84rem;margin-top:2px}
.auto-act i{color:var(--faint);margin:0 5px;font-size:.72rem}
.auto-tag{font-size:.6rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;padding:2px 8px;border-radius:100px;border:1px solid}
.auto-tag.live{color:#34D399;border-color:rgba(52,211,153,.5);background:rgba(52,211,153,.1)}
.auto-tag.planned{color:var(--faint);border-color:var(--border);background:var(--bg)}
.auto-meta{font-size:.72rem;color:var(--faint);margin-top:3px}
.auto-switch{flex-shrink:0;border:0;background:transparent;cursor:pointer;padding:0}
.auto-switch .track{display:block;width:46px;height:26px;border-radius:100px;background:var(--card);border:1px solid var(--border);position:relative;transition:background .2s,border-color .2s}
.auto-switch .knob{position:absolute;top:2px;left:2px;width:20px;height:20px;border-radius:50%;background:var(--faint);transition:transform .2s,background .2s}
.auto-switch.on .track{background:linear-gradient(135deg,var(--gold),var(--gold-2));border-color:transparent}
.auto-switch.on .knob{transform:translateX(20px);background:#1A120B}
/* ── Command palette (⌘K) ── */
.cmdk-scrim{position:fixed;inset:0;z-index:10000;background:rgba(10,7,4,.62);display:none;align-items:flex-start;justify-content:center;padding:12vh 16px 16px}
.cmdk-scrim.open{display:flex}
.cmdk{width:100%;max-width:560px;background:var(--panel);border:1px solid var(--border);border-radius:16px;box-shadow:0 26px 80px rgba(0,0,0,.6);overflow:hidden;animation:cmdkIn .16s ease-out}
@keyframes cmdkIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:none}}
.cmdk-in{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid var(--border)}
.cmdk-in i{color:var(--faint)}
.cmdk-in input{flex:1;background:transparent;border:0;outline:none;color:var(--ink);font-size:1rem}
.cmdk-list{max-height:52vh;overflow:auto;padding:6px}
.cmdk-sec{font-size:.62rem;letter-spacing:.1em;text-transform:uppercase;color:var(--faint);font-weight:800;padding:10px 12px 4px}
.cmdk-item{display:flex;align-items:center;gap:11px;padding:10px 12px;border-radius:9px;cursor:pointer;color:var(--text);font-size:.9rem}
.cmdk-item i{display:inline-block;width:18px;text-align:center;color:var(--gold)}
.cmdk-item .tag{margin-left:auto;font-size:.64rem;color:var(--faint);text-transform:uppercase;letter-spacing:.05em}
.cmdk-item.sel,.cmdk-item:hover{background:rgba(232,150,31,.14)}
.cmdk-empty{padding:22px 14px;text-align:center;color:var(--faint);font-size:.86rem}
/* Keyboard-shortcuts help (press ?) */
.kbd-scrim{position:fixed;inset:0;z-index:10000;background:rgba(10,7,4,.62);display:none;align-items:center;justify-content:center;padding:16px}
.kbd-scrim.open{display:flex}
.kbd-modal{width:100%;max-width:440px;background:var(--panel);border:1px solid var(--border-strong);border-radius:16px;box-shadow:0 26px 80px rgba(0,0,0,.6);padding:20px 22px;animation:cmdkIn .16s ease-out}
.kbd-modal h2{font-family:var(--font-serif);font-size:1.4rem;margin:0 0 4px}
.kbd-modal .sub{color:var(--faint);font-size:.82rem;margin:0 0 14px}
.kbd-row{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:10px 0;border-top:1px solid var(--border)}
.kbd-row span{color:var(--muted);font-size:.9rem}
.kbd-row kbd{font-family:inherit;font-size:.74rem;background:var(--raised);border:1px solid var(--border-strong);border-radius:6px;padding:3px 9px;color:var(--goldhi);white-space:nowrap;font-weight:700}
@media(prefers-reduced-motion:reduce){.kbd-modal{animation:none}}
@media(prefers-reduced-motion:reduce){.cmdk{animation:none}.auto-switch .knob,.auto-switch .track{transition:none}}
/* ── Drawer: right slide-in panel (Applications "Manage"), per the redesign ── */
.mam-drawer-scrim{position:fixed;inset:0;z-index:9000;background:rgba(10,7,4,0.6);display:flex;justify-content:flex-end;animation:fadeIn .18s ease-out}
.mam-drawer{width:min(480px,100%);height:100%;background:var(--panel);border-left:1px solid var(--border-strong);box-shadow:-26px 0 64px rgba(6,4,1,0.6);overflow:auto;padding:0 22px 40px;animation:drawerIn .22s cubic-bezier(.2,.8,.25,1)}
.mam-drawer > div:first-child{position:sticky;top:0;background:var(--panel);margin:0 -22px 4px;padding:18px 22px;border-bottom:1px solid var(--border);z-index:2}
.mam-drawer table{font-size:.8rem}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes drawerIn{from{transform:translateX(34px);opacity:.4}to{transform:none;opacity:1}}
@media(max-width:640px){.mam-drawer{width:100%;border-left:0}}
@media(prefers-reduced-motion:reduce){.mam-drawer,.mam-drawer-scrim{animation:none}}
/* Mobile: opt-in tables (class="stack") become stacked cards — each row a
   card, each cell labelled from data-label (redesign §10). */
@media(max-width:640px){
  table.stack{min-width:0!important;width:100%}
  table.stack thead{display:none}
  table.stack tbody{display:block}
  table.stack tr{display:block;border:1px solid var(--border);border-radius:var(--r);padding:8px 2px;margin:0 4px 12px;background:var(--panel)}
  table.stack td{display:block;border:0;padding:5px 14px;text-align:left}
  table.stack td::before{content:attr(data-label);display:block;color:var(--faint);font-weight:700;font-size:.64rem;letter-spacing:.06em;text-transform:uppercase;margin-bottom:2px}
  table.stack td[data-label=""]::before{display:none}
  table.stack td[data-label=""]{display:flex;flex-wrap:wrap;gap:8px;padding-top:12px}
  table.stack tr:hover td{background:transparent}
  table.stack td.empty{text-align:center}
}
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
</head>
<body>
<a href="#main" class="skip-link">Skip to content</a>
<aside>
  <div class="brand">
    <div class="logo">M</div>
    <div>
      <b>MAM Admin</b>
      <small><?= htmlspecialchars(mam_env('APP_ENV', 'production')) ?></small>
      <?php $__tier = $adminUser ? mam_admin_tier($adminUser) : 'super'; ?>
      <span class="tier-badge tier-<?= htmlspecialchars($__tier) ?>"><?= $__tier === 'super' ? 'Super admin' : 'Manager' ?></span>
    </div>
  </div>
  <nav id="adminNav" aria-label="Admin sections">
    <?php
    // "What needs me" counts — the difference between a nav you wander and a
    // nav that points. Each is a cheap COUNT, individually fault-tolerant so a
    // missing table (older DB) never breaks the shell.
    $navBadges = [];
    $needs = [];   // [label, url, count, verb]
    $cnt = function (string $sql): int { try { return (int)mam_db()->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; } };
    if ($n = $cnt("SELECT COUNT(*) FROM applications WHERE status IN ('submitted','reviewing')")) { $navBadges['applications'] = $n; $needs[] = ['application' . ($n === 1 ? '' : 's') . ' awaiting a decision', '?v=applications', $n, 'Review']; }
    if ($n = $cnt("SELECT COUNT(*) FROM course_enrollments WHERE status = 'pending'"))            { $navBadges['courseenroll'] = $n; $needs[] = ['course registration' . ($n === 1 ? '' : 's') . ' awaiting approval', '?v=courseenroll&ce=pending', $n, 'Approve']; }
    if ($n = $cnt("SELECT COUNT(*) FROM course_enrollments WHERE instructor_id IS NULL AND status IN ('active','pending')")) { $needs[] = ['course student' . ($n === 1 ? '' : 's') . ' with no instructor', '?v=courseenroll&ce=unassigned', $n, 'Assign']; }
    if ($n = $cnt("SELECT COUNT(*) FROM module_proposals WHERE status = 'pending'"))              { $navBadges['modules'] = $n; $needs[] = ['curriculum proposal' . ($n === 1 ? '' : 's') . ' from faculty', '?v=modules', $n, 'Review']; }
    if ($n = $cnt("SELECT COUNT(*) FROM messages WHERE status = 'new'"))                          { $navBadges['messages'] = $n; $needs[] = ['unread message' . ($n === 1 ? '' : 's'), '?v=messages', $n, 'Read']; }
    $fac = $cnt("SELECT COUNT(*) FROM faculty_invites WHERE status = 'requested'") + $cnt("SELECT COUNT(*) FROM instructor_applications WHERE status = 'submitted'");
    if ($fac) { $navBadges['users'] = $fac; $needs[] = ['teaching request' . ($fac === 1 ? '' : 's') . ' / instructor application' . ($fac === 1 ? '' : 's'), '?v=users#faculty', $fac, 'Review']; }
    if ($n = $cnt("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','in_progress')")) { $navBadges['support'] = $n; $needs[] = ['open support ticket' . ($n === 1 ? '' : 's'), '?v=support', $n, 'Respond']; }

    // Sidebar nav grouped by domain — [view => [label, FontAwesome icon]].
    // Grouping turns a flat 19-item list into a scannable structure.
    $navGroups = [
      'Overview'   => ['overview' => ['Overview', 'gauge-high']],
      'Admissions' => ['applications' => ['Applications', 'file-lines'], 'summer' => ['Summer Programme', 'sun'], 'funnel' => ['Apply Funnel', 'filter']],
      'Academics'  => ['courseenroll' => ['Course Registrations', 'user-check'], 'schedule' => ['Class Schedule', 'calendar-week'], 'programs' => ['Programme Catalogue', 'layer-group'], 'modules' => ['Standard Curriculum', 'book-open'], 'classes' => ['Classes', 'chalkboard-user'], 'enrollments' => ['Enrollments', 'user-group'], 'id-cards' => ['ID Cards', 'id-card'], 'media' => ['Media & Docs', 'photo-film'], 'analytics' => ['Learning Analytics', 'chart-line'], 'fines' => ['Instructor Fines', 'triangle-exclamation']],
      'Commerce'   => ['payments' => ['Payments', 'credit-card'], 'storeitems' => ['Store / Library', 'store'], 'storeorders' => ['Store Orders', 'box-open']],
      'Engagement' => ['messages' => ['Messages', 'envelope'], 'newsletter' => ['Newsletter', 'paper-plane'], 'support' => ['Support Hub', 'headset']],
      'System'     => ['users' => ['Users', 'user-gear'], 'settings' => ['Pricing & Settings', 'sliders'], 'email-branding' => ['Email Branding', 'paintbrush'], 'automations' => ['Automations', 'robot'], 'forms' => ['Form Builder', 'wpforms'], 'webhooks' => ['Webhook Events', 'bolt'], 'audit' => ['Audit Log', 'clipboard-list'], 'logs' => ['App Logs', 'scroll'], 'datarights' => ['Data Rights', 'shield-halved']],
      'Account'    => ['account' => ['My Account', 'circle-user']],
    ];
    // Flatten to a label map so the topbar title lookup keeps working.
    $links = [];
    foreach ($navGroups as $__items) foreach ($__items as $__k => $__m) $links[$__k] = $__m[0];
    // RBAC: managers only see sections they can use; sensitive views are
    // super-only. A direct URL to a restricted view falls back to Overview.
    $viewCaps = ['users' => 'users', 'settings' => 'settings', 'email-branding' => 'settings', 'automations' => 'settings', 'forms' => 'curriculum', 'storeitems' => 'store_catalog', 'modules' => 'curriculum', 'webhooks' => 'settings', 'audit' => 'users', 'logs' => 'settings', 'datarights' => 'users', 'media' => 'media', 'support' => 'support', 'id-cards' => 'enrollments'];
    $canView = function ($k) use ($adminUser, $viewCaps) {
        return !$adminUser || mam_admin_can($adminUser, $viewCaps[$k] ?? 'view'); // Basic-Auth (null) = super
    };
    if (!$canView($view)) { $view = 'overview'; }
    foreach ($navGroups as $groupName => $items) {
        if (!array_filter(array_keys($items), $canView)) continue; // hide an empty group
        echo "<div class='nav-group'>" . htmlspecialchars($groupName) . "</div>";
        foreach ($items as $k => $meta) {
            if (!$canView($k)) continue;
            $cls = $view === $k ? 'active' : '';
            $badge = !empty($navBadges[$k]) ? "<em class='nav-count'>" . (int)$navBadges[$k] . "</em>" : '';
            echo "<a href='?v=" . htmlspecialchars($k) . "' class='$cls'" . ($cls ? " aria-current='page'" : '') . ">"
               . "<i class='fa-solid fa-" . htmlspecialchars($meta[1]) . "' aria-hidden='true'></i><span>" . htmlspecialchars($meta[0]) . "</span>$badge</a>";
        }
    }
    if ($adminUser && mam_admin_tier($adminUser) === 'manager') {
        echo "<div class='nav-note'>Manager access — pricing, users &amp; settings are restricted.</div>";
    }
    ?>
  </nav>
  <form method="get" action="" style="margin-top:16px;display:flex;gap:6px">
    <input type="hidden" name="v" value="search">
    <input type="search" name="q" value="<?= htmlspecialchars((string)($_GET['q'] ?? '')) ?>" placeholder="Search ref, email, name…" style="flex:1;min-width:0;padding:9px 11px;border-radius:8px;border:1px solid var(--border);background:var(--bg);color:var(--ink);font-size:0.82rem">
    <button class="btn" type="submit" style="padding:9px 12px" aria-label="Search">🔍</button>
  </form>
  <?php
  // (Signed-in identity + "View site" now live in the sticky top bar.)
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
  <?php
    // Version stamp — one glance answers "what build am I looking at?" when
    // debugging or reporting an issue. UI version comes from config.js (the
    // single source the front-end also exposes as MAM_CONFIG.uiVersion).
    $uiVer = 'dev';
    if (preg_match("/uiVersion:\s*'([^']+)'/", @file_get_contents(__DIR__ . '/../assets/js/config.js') ?: '', $vm)) $uiVer = $vm[1];
  ?>
  <div class="muted" style="margin-top:14px;padding-top:10px;border-top:1px solid var(--border);font-size:.68rem;letter-spacing:.04em">
    UI <?= htmlspecialchars($uiVer) ?> · API v<?= htmlspecialchars(defined('MAM_API_VERSION') ? MAM_API_VERSION : '1.0') ?> · schema v<?= (int)MAM_SCHEMA_TARGET ?>
  </div>
</aside>
<div class="nav-scrim" id="navScrim" aria-hidden="true"></div>

<main id="main" tabindex="-1">
<?php
  // App-shell top bar — current section + account, sticky on scroll.
  $atbLabel = $links[$view] ?? ucfirst((string)$view);
  $atbEmail = (string)($adminUser['email'] ?? ($_SERVER['PHP_AUTH_USER'] ?? 'admin'));
  $atbInit  = strtoupper(substr(trim((string)($adminUser['name'] ?? $atbEmail)), 0, 1)) ?: 'M';
?>
<div class="admin-topbar">
  <button type="button" class="nav-toggle" id="navToggle" aria-label="Open menu" aria-controls="adminNav"><i class="fa-solid fa-bars" aria-hidden="true"></i></button>
  <div class="atb-title"><span class="dot" aria-hidden="true"></span><?= htmlspecialchars($atbLabel) ?></div>
  <button type="button" class="atb-search" id="atbSearch" aria-label="Search or jump to"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><span>Search or jump to…</span><kbd>⌘K</kbd></button>
  <div class="atb-right">
    <?php $__atbTier = $adminUser ? mam_admin_tier($adminUser) : 'super'; ?>
    <span class="atb-tier tier-<?= htmlspecialchars($__atbTier) ?>" title="Your admin access level"><?= $__atbTier === 'super' ? 'Super admin' : 'Manager' ?></span>
    <a class="atb-chip" href="/" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i> View site</a>
    <span class="atb-user" title="<?= htmlspecialchars($atbEmail) ?>"><span class="av"><?= htmlspecialchars($atbInit) ?></span><?= htmlspecialchars($atbEmail) ?></span>
  </div>
</div>
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
    // empty. Server-side values come from .env or the admin settings table.
    $ops = [];
    if (!mam_env('BOOKING_EMBED_URL')) $ops[] = '<code>BOOKING_EMBED_URL</code> (api/.env) — paid bookers see “calendar is being set up” instead of the scheduler';
    $cfgJs = @file_get_contents(__DIR__ . '/../assets/js/config.js') ?: '';
    if (preg_match('/meetUrl:\s*\'\'/', $cfgJs)) $ops[] = '<code>powerPlay.meetUrl</code> (assets/js/config.js) — Power Play page has no join link';
    // CDN readiness: these are now DB settings (admin-editable in Pricing & Settings),
    // not static config.js values — check the DB, not the file.
    if (!mam_setting('cloudinary_cloud_name', ''))
        $ops[] = '<code>cloudinary_cloud_name</code> (<a href=”?v=settings” style=”color:var(--gold)”>Pricing &amp; Settings → CDN</a>) — Cloudinary image CDN is disabled; enter your cloud name (found at the top of your Cloudinary dashboard)';
    if (!mam_setting('cloudinary_upload_preset', ''))
        $ops[] = '<code>cloudinary_upload_preset</code> (<a href=”?v=settings” style=”color:var(--gold)”>Pricing &amp; Settings → CDN</a>) — headshot photos won\'t upload without this; in Cloudinary go to <strong>Settings → Upload → Upload presets</strong>, create an <strong>Unsigned</strong> preset, and paste its name here';
    if (function_exists('mam_mail_readiness')) { $mr = mam_mail_readiness(); if (is_array($mr) && empty($mr['ready']) && !empty($mr['issues'])) $ops[] = 'Mail delivery: ' . htmlspecialchars(implode('; ', (array)$mr['issues'])); }
    if ($ops) echo '<div class="banner"><strong>ℹ️ Operational readiness</strong> — features waiting on configuration:<ul style="margin:8px 0 0 18px;padding:0">' . implode('', array_map(fn ($o) => '<li style="margin:3px 0">' . $o . '</li>', $ops)) . '</ul></div>';

    // ── Needs attention — the work queue, front and centre ──
    if (!empty($needs)) {
        echo '<div class="card needs"><h3>⚡ Needs your attention <span class="muted" style="font-weight:400;font-size:.78rem">— everything pending across the academy, one list</span></h3><div class="needs-list">';
        foreach ($needs as [$label, $url, $n, $verb]) {
            echo '<a class="needs-item" href="' . htmlspecialchars($url) . '"><span class="needs-n">' . (int)$n . '</span><span class="needs-label">' . (int)$n . ' ' . htmlspecialchars($label) . '</span><span class="needs-verb">' . htmlspecialchars($verb) . '</span></a>';
        }
        echo '</div></div>';
    } else {
        echo '<div class="card"><div style="padding:16px 20px;color:var(--muted);font-size:.9rem">✅ Nothing is waiting on you — applications, registrations, proposals, messages and faculty requests are all clear.</div></div>';
    }

    // ── AI assist status — what the gateway powers and whether it's live ──
    if (function_exists('mam_ai_readiness')) {
        $ai = mam_ai_readiness();
        $aiSurfaces = [
            'Placement suggestions + reviewer triage on applications (Admissions)',
            'Grading feedback drafts in the instructor gradebook',
            'Student progress notes + practice coach in the portal',
            'The "Find Your Path" advisor on the apply page',
        ];
        echo '<div class="card"><h3>🤖 AI assist '
           . ($ai['enabled']
               ? '<span class="pill present">on · ' . htmlspecialchars((string)$ai['provider']) . ($ai['model'] ? ' · ' . htmlspecialchars((string)$ai['model']) : '') . '</span>'
               : '<span class="pill pending">off</span>')
           . ' <span class="muted" style="font-weight:400;font-size:.78rem">— all surfaces are advisory; a human always decides</span></h3>'
           . '<div style="padding:0 20px 16px;font-size:.86rem;color:var(--muted);line-height:1.7">'
           . '<ul style="margin:0 0 10px 18px;padding:0">' . implode('', array_map(fn ($s) => '<li>' . htmlspecialchars($s) . '</li>', $aiSurfaces)) . '</ul>'
           . ($ai['enabled']
               ? 'Provider adapter: <code>' . htmlspecialchars((string)$ai['adapter']) . '</code>' . ($ai['local'] ? ' (self-hosted — data stays on your server)' : '') . ($ai['key_set'] ? '' : ' · <strong style="color:var(--gold)">no API key set</strong>')
               : 'Enable by setting <code>ai_provider</code> in <a href="?v=settings" style="color:var(--gold)">Pricing &amp; Settings</a> (e.g. <code>ollama</code> for self-hosted/$0, or <code>groq</code>). Every run is logged in <code>ai_runs</code>.')
           . (!empty($ai['issues']) ? '<div style="margin-top:8px;color:var(--gold)">' . htmlspecialchars(implode(' ', (array)$ai['issues'])) . '</div>' : '')
           . '</div></div>';
    }
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
      var gold = '#E8961F', green = '#34D399';
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
      $mCourses = mam_db_all("SELECT code, name FROM courses WHERE active = 1 ORDER BY sort_order, name");
      $mLevels  = mam_db_all("SELECT code, name FROM levels ORDER BY sort_order");
      $mAutoPlacement = function_exists('mam_automation_on') ? mam_automation_on('auditer_placement') : true;
  ?>
  <div class="mam-drawer-scrim" id="appDrawerScrim" data-param="app">
  <aside class="mam-drawer" role="dialog" aria-modal="true" aria-label="Manage application">
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
          <h3 style="margin:18px 0 8px">AI assist <span class="muted" style="font-size:.7rem">(advisory — a human decides)</span></h3>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button type="button" class="btn ghost" id="aiPlaceBtn" data-app="<?= (int)$manageApp['id'] ?>">✨ Suggest pathway &amp; level</button>
            <button type="button" class="btn ghost" id="aiTriageBtn" data-app="<?= (int)$manageApp['id'] ?>">📋 Draft reviewer summary</button>
          </div>
          <div id="aiPlaceOut" style="margin-top:10px;font-size:.82rem"></div>
          <div id="aiTriageOut" style="margin-top:10px;font-size:.82rem"></div>
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
          // Reviewer triage summary — strengths, flags to verify, next action.
          (function () {
            var btn = document.getElementById('aiTriageBtn'), out = document.getElementById('aiTriageOut');
            if (!btn) return;
            function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c]; }); }
            function list(items, color) {
              if (!items || !items.length) return '';
              return '<ul style="margin:4px 0 0 18px;padding:0;color:' + color + '">' + items.slice(0, 4).map(function (i) { return '<li>' + esc(i) + '</li>'; }).join('') + '</ul>';
            }
            btn.addEventListener('click', function () {
              btn.disabled = true; var old = btn.textContent; btn.textContent = 'Reviewing…';
              fetch('/api/admin/ai-triage?app_id=' + encodeURIComponent(btn.getAttribute('data-app')), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                  btn.disabled = false; btn.textContent = old;
                  if (!j || !j.ok || !j.triage || !j.triage.json) { out.innerHTML = '<span style="color:var(--muted)">No summary available right now.</span>'; return; }
                  var t = j.triage.json;
                  out.innerHTML = '<div style="padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg)">'
                    + '<div>' + esc(t.summary || '') + '</div>'
                    + (t.strengths && t.strengths.length ? '<div style="margin-top:8px"><strong style="color:#34D399">Strengths</strong>' + list(t.strengths, 'var(--muted)') + '</div>' : '')
                    + (t.flags && t.flags.length ? '<div style="margin-top:8px"><strong style="color:#FBBF24">Verify</strong>' + list(t.flags, 'var(--muted)') + '</div>' : '')
                    + '<div style="margin-top:8px"><strong>Suggested:</strong> ' + esc(t.suggested_action || '—') + ' <span class="pill">' + esc(t.confidence || '') + '</span></div>'
                    + '<div style="margin-top:6px;font-size:.68rem;color:var(--muted)">Advisory only · drafted by ' + esc(j.triage.provider || 'AI') + ' · never shown to the applicant</div></div>';
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

    <h3 style="margin:18px 0 8px">MAM Auditer placement
      <span class="muted" style="font-size:.72rem">— record the outcome<?= $mAutoPlacement ? ' · auto-enrols at the chosen level' : ' · auto-enrol paused (capture only)' ?></span>
    </h3>
    <?php if (!$mCourses): ?>
      <p class="muted">Load the curriculum first (<a href="?v=modules" style="color:var(--gold)">Standard Curriculum → Seed starter</a>) so a placement can enrol the student into a course.</p>
    <?php else: ?>
      <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;align-items:end;max-width:760px">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="record_placement">
        <input type="hidden" name="app" value="<?= (int)$manageApp['id'] ?>">
        <input type="hidden" name="app_id" value="<?= (int)$manageApp['id'] ?>">
        <label>Recommended course
          <select name="course_code" required>
            <option value="">— pick a course —</option>
            <?php foreach ($mCourses as $co): ?>
              <option value="<?= htmlspecialchars((string)$co['code']) ?>" <?= (strtolower((string)($manageApp['discipline'] ?? '')) === strtolower((string)$co['name'])) ? 'selected' : '' ?>><?= htmlspecialchars((string)$co['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Placement level
          <select name="level_code" required>
            <?php foreach ($mLevels as $lv): ?><option value="<?= htmlspecialchars((string)$lv['code']) ?>"><?= htmlspecialchars((string)$lv['name']) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label style="grid-column:1/-1">Notes <span class="muted">(optional — recorded with the placement)</span>
          <input type="text" name="notes" maxlength="2000" placeholder="Placement observations…">
        </label>
        <button class="btn" type="submit"><?= $mAutoPlacement ? 'Record placement &amp; enrol' : 'Record placement' ?></button>
      </form>
    <?php endif; ?>

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

    <h3 style="margin:18px 0 8px">Documents</h3>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <a class="btn ghost" href="/api/tuition-invoice/<?= rawurlencode((string)$manageApp['reference']) ?>" target="_blank" rel="noopener">📄 Programme Invoice</a>
      <a class="btn ghost" href="/api/invoice/<?= rawurlencode((string)$manageApp['reference']) ?>" target="_blank" rel="noopener">📋 Booking Confirmation</a>
      <?php if (!empty($manageApp['policy_mode'])): ?>
        <a class="btn ghost" href="/api/policy/<?= rawurlencode((string)$manageApp['reference']) ?>" target="_blank" rel="noopener">📝 Signed Policy</a>
      <?php endif; ?>
    </div>
  </aside>
  </div>
  <?php endif; ?>
  <div class="card">
    <form method="post" id="appBulk" style="display:none;gap:12px;align-items:center;flex-wrap:wrap;padding:12px 18px;border-bottom:1px solid var(--border);background:var(--raised)">
      <?= mam_csrf_field() ?>
      <input type="hidden" name="action" value="app_bulk_status">
      <input type="hidden" name="ids" id="appBulkIds">
      <strong id="appBulkCount">0 selected</strong>
      <label style="display:flex;align-items:center;gap:6px;font-size:.85rem;color:var(--muted)">Set status
        <select name="status" required>
          <option value="">—</option>
          <?php foreach (['in_review', 'accepted', 'waitlisted', 'paid', 'enrolled', 'cancelled'] as $s): ?><option value="<?= $s ?>"><?= $s ?></option><?php endforeach; ?>
        </select>
      </label>
      <button class="btn" type="submit">Apply to selected</button>
      <button type="button" class="btn ghost" id="appBulkClear">Clear</button>
    </form>
    <table class="stack">
      <thead><tr><th style="width:36px"><input type="checkbox" id="appSelAll" aria-label="Select all applications"></th><th>Photo</th><th>Reference</th><th>Student</th><th>Programme</th><th>Pathway · Mode</th><th>Course</th><th>Session</th><th>Age</th><th>Total</th><th>Status</th><th>Created</th><th></th></tr></thead>
      <tbody>
      <?php
      $perPage = 50; $page = mam_admin_page(); $total = (int)mam_admin_scalar('SELECT COUNT(*) FROM applications'); $off = ($page - 1) * $perPage;
      $rows = mam_db_all('SELECT a.*, c.first_name, c.last_name, c.email, c.phone, c.sex, c.address, c.lga, c.state, c.country, c.age_range FROM applications a LEFT JOIN customers c ON c.id=a.customer_id ORDER BY a.created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $off);
      if (!$rows): ?>
        <tr><td colspan="13" class="empty">No applications yet</td></tr>
      <?php endif;
      $sessionLabels = ['toni' => 'TONI', 'medi' => 'MEDI', 'summer' => 'SUMMER', 'domi' => 'DOMI'];
      foreach ($rows as $r):
        $loc = implode(', ', array_filter([$r['lga'] ?? '', $r['state'] ?? '', $r['country'] ?? ''])); ?>
        <tr>
          <td data-label="" style="width:36px"><input type="checkbox" class="appsel" value="<?= (int)$r['id'] ?>" aria-label="Select <?= htmlspecialchars((string)$r['reference']) ?>"></td>
          <td data-label="Photo">
            <?php if (!empty($r['headshot_url'])): ?>
              <a href="<?= htmlspecialchars($r['headshot_url']) ?>" target="_blank" rel="noopener"><img src="<?= htmlspecialchars($r['headshot_url']) ?>" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:1px solid var(--border-gold,#5a4327)"></a>
            <?php else: ?>
              <span class="muted" title="No headshot uploaded" style="display:inline-flex;width:40px;height:40px;border-radius:50%;align-items:center;justify-content:center;background:var(--panel);border:1px dashed var(--border)"><i class="fa-solid fa-user" aria-hidden="true"></i></span>
            <?php endif; ?>
          </td>
          <td class="mono" data-label="Reference"><?= htmlspecialchars($r['reference']) ?></td>
          <td data-label="Student"><strong><?= htmlspecialchars(mam_name($r['first_name'] ?? null, $r['last_name'] ?? null)) ?></strong>
            <?php if (!empty($r['sex'])): ?><span class="muted">· <?= htmlspecialchars(ucfirst((string)$r['sex'])) ?></span><?php endif; ?>
            <br><span class="muted"><?= htmlspecialchars((string)($r['email'] ?? '')) ?></span>
            <br><span class="muted"><?= htmlspecialchars((string)($r['phone'] ?? '')) ?></span>
            <?php if ($loc): ?><br><span class="muted"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <?= htmlspecialchars($loc) ?></span><?php endif; ?>
          </td>
          <td class="muted" data-label="Programme"><?= htmlspecialchars((string)($r['program'] ?? '—')) ?></td>
          <td data-label="Pathway · Mode"><?= htmlspecialchars((string)($r['pathway'] ?? '')) ?> · <span class="muted"><?= htmlspecialchars((string)($r['mode'] ?? '')) ?></span></td>
          <td data-label="Course"><?= htmlspecialchars((string)($r['discipline'] ?? '')) ?></td>
          <td data-label="Session"><?php $s = strtolower((string)($r['session'] ?? '')); echo $s ? '<span class="pill">' . htmlspecialchars($sessionLabels[$s] ?? strtoupper($s)) . '</span>' : '<span class="muted">—</span>'; ?></td>
          <td class="muted" data-label="Age"><?= htmlspecialchars((string)($r['age_range'] ?? '')) ?></td>
          <td data-label="Total">$<?= number_format((float)$r['total_usd'], 2) ?></td>
          <td data-label="Status"><span class="pill <?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span>
            <?php if (!empty($r['recovery_sent_at'])): ?><br><span class="muted" title="Recovery email sent" style="font-size:0.66rem"><i class="fa-solid fa-envelope-circle-check"></i> nudged</span><?php endif; ?>
          </td>
          <td class="muted" data-label="Created"><?= htmlspecialchars(substr($r['created_at'] ?? '', 0, 16)) ?></td>
          <td data-label="" style="white-space:nowrap">
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
    <?= mam_admin_pager($page, $total, $perPage) ?>
  </div>
  <script>
  (function () {
    var bar = document.getElementById('appBulk'); if (!bar) return;
    var all = document.getElementById('appSelAll'), ids = document.getElementById('appBulkIds'),
        count = document.getElementById('appBulkCount'), clear = document.getElementById('appBulkClear');
    function boxes() { return Array.prototype.slice.call(document.querySelectorAll('.appsel')); }
    function selected() { return boxes().filter(function (b) { return b.checked; }); }
    function refresh() {
      var s = selected();
      count.textContent = s.length + ' selected';
      bar.style.display = s.length ? 'flex' : 'none';
      if (all) { var n = boxes().length; all.checked = n > 0 && s.length === n; all.indeterminate = s.length > 0 && s.length < n; }
    }
    document.addEventListener('change', function (e) { if (e.target && e.target.classList && e.target.classList.contains('appsel')) refresh(); });
    if (all) all.addEventListener('change', function () { boxes().forEach(function (b) { b.checked = all.checked; }); refresh(); });
    if (clear) clear.addEventListener('click', function () { boxes().forEach(function (b) { b.checked = false; }); refresh(); });
    bar.addEventListener('submit', function (e) { var s = selected(); if (!s.length) { e.preventDefault(); return; } ids.value = s.map(function (b) { return b.value; }).join(','); });
  })();
  </script>

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

  <?php $studio = mam_funnel_studio_report($fDays); ?>
  <div class="card">
    <h3 style="margin:0 0 10px">Studio engagement <span class="muted" style="font-size:.72rem">(which practice tool drives applications)</span></h3>
    <?php if (empty($studio['by_tool']) && array_sum($studio['totals']) === 0): ?>
      <p class="muted">No Studio engagement events yet.</p>
    <?php else: ?>
      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px">
        <?php foreach ($studio['totals'] as $ev => $n): ?><span class="pill"><?= htmlspecialchars($ev) ?>: <strong><?= number_format($n) ?></strong></span><?php endforeach; ?>
      </div>
      <?php if ($studio['by_tool']): ?>
      <table>
        <thead><tr><th>Tool</th><th>Opens</th><th>Reach</th><th>Demos</th><th>Scores</th><th>Apply clicks</th></tr></thead>
        <tbody>
        <?php foreach ($studio['by_tool'] as $t): ?>
          <tr>
            <td><strong><?= htmlspecialchars($t['tool']) ?></strong></td>
            <td><?= number_format($t['opens']) ?></td>
            <td><?= number_format($t['reach']) ?></td>
            <td><?= number_format($t['demo_start']) ?></td>
            <td><?= number_format($t['score_logged']) ?></td>
            <td><?= number_format($t['gate_apply_click']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    <?php endif; ?>
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

<?php elseif ($view === 'summer'): ?>
  <?php
    $sPage   = max(1, (int)($_GET['pg']     ?? 1));
    $sStatus = trim((string)($_GET['status'] ?? ''));
    $sQ      = trim((string)($_GET['q']      ?? ''));
    $sPerPage = 40;
    $sOffset  = ($sPage - 1) * $sPerPage;
    $sValid   = ['submitted','confirmed','waitlisted','enrolled','cancelled','rejected'];
    if ($sStatus && !in_array($sStatus, $sValid, true)) $sStatus = '';

    $where = []; $bind = [];
    if ($sStatus)  { $where[] = 'status = ?';                            $bind[] = $sStatus; }
    if ($sQ !== '') { $where[] = "(LOWER(email) LIKE ? OR LOWER(first_name||' '||last_name) LIKE ? OR reference LIKE ?)";
                      $like = '%' . strtolower($sQ) . '%'; $bind[] = $like; $bind[] = $like; $bind[] = $like; }
    $wSql   = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $sTotal = (int)(mam_db_row('SELECT COUNT(*) AS n FROM summer_applications' . $wSql, $bind)['n'] ?? 0);
    $sPages = max(1, (int)ceil($sTotal / $sPerPage));
    $sList  = mam_db_all('SELECT id, reference, first_name, last_name, email, discipline, age_at_submission, status, created_at FROM summer_applications' . $wSql . ' ORDER BY id DESC LIMIT ' . $sPerPage . ' OFFSET ' . $sOffset, $bind);

    $sProg  = (string)mam_setting('summer_prog_name', 'MAM Summer Music Camp');
    $sUrl   = function (array $extra) use ($sStatus, $sQ, $sPage): string {
        $p = ['v' => 'summer'];
        if ($sStatus) $p['status'] = $sStatus;
        if ($sQ)      $p['q']      = $sQ;
        if ($sPage > 1) $p['pg']   = $sPage;
        foreach ($extra as $k => $v) { if ($v === '' || $v === null) unset($p[$k]); else $p[$k] = $v; }
        return '?' . http_build_query($p);
    };

    $sBadge = function (string $st): string {
        $map = ['submitted'=>'muted','confirmed'=>'confirmed','waitlisted'=>'waitlisted',
                'enrolled'=>'enrolled','cancelled'=>'expired','rejected'=>'expired'];
        $cls = $map[$st] ?? 'muted';
        return '<span class="pill ' . htmlspecialchars($cls) . '">' . htmlspecialchars($st) . '</span>';
    };

    // Handle inline status update (JS-less fallback — the JS route is api/routes/admin/summer.php)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && mam_admin_has_cap('view')) {
        mam_check_csrf();
        $updId  = (int)($_POST['id']     ?? 0);
        $updSt  = trim((string)($_POST['status'] ?? ''));
        if ($updId && in_array($updSt, $sValid, true)) {
            mam_db_run('UPDATE summer_applications SET status = ?, updated_at = ? WHERE id = ?',
                       [$updSt, date('Y-m-d H:i:s'), $updId]);
            mam_audit('update', 'summer_application', $updId, ['status' => $updSt]);
            header('Location: ' . $sUrl([]) . '&ok=1');
            exit;
        }
    }
  ?>
  <header class="top">
    <h1>Summer Programme — <?= htmlspecialchars($sProg) ?></h1>
    <a class="btn ghost" href="/summer" target="_blank" rel="noopener">View public page ↗</a>
  </header>

  <?php if (isset($_GET['ok'])): ?><div class="card" style="border-color:#34D399"><strong style="color:#34D399">Status updated.</strong></div><?php endif; ?>

  <!-- KPIs -->
  <?php
    $sCounts = mam_db_all('SELECT status, COUNT(*) AS n FROM summer_applications GROUP BY status ORDER BY n DESC', []);
    $sByStatus = [];
    foreach ($sCounts as $r) $sByStatus[$r['status']] = (int)$r['n'];
    $sAllTotal = array_sum($sByStatus);
  ?>
  <div class="kpis" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:8px">
    <div class="kpi"><div class="kpi-label">Total applications</div><div class="kpi-value"><?= number_format($sAllTotal) ?></div></div>
    <?php foreach (['submitted'=>'Pending review','confirmed'=>'Confirmed','enrolled'=>'Enrolled','waitlisted'=>'Waitlisted'] as $st => $lbl): ?>
      <?php if ($sByStatus[$st] ?? 0): ?>
        <div class="kpi">
          <div class="kpi-label"><?= htmlspecialchars($lbl) ?></div>
          <div class="kpi-value"><?= number_format($sByStatus[$st]) ?></div>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <!-- Search + filter -->
  <form class="card" method="get" style="padding:12px 16px;display:flex;flex-wrap:wrap;gap:10px;align-items:center">
    <input type="hidden" name="v" value="summer">
    <input type="search" name="q" value="<?= htmlspecialchars($sQ) ?>" placeholder="Search name or email…" style="flex:1;min-width:180px">
    <select name="status" style="min-width:160px">
      <option value="">All statuses</option>
      <?php foreach ($sValid as $st): ?>
        <option value="<?= htmlspecialchars($st) ?>"<?= $sStatus === $st ? ' selected' : '' ?>><?= ucfirst(htmlspecialchars($st)) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn" type="submit">Filter</button>
    <?php if ($sStatus || $sQ): ?><a class="btn ghost" href="?v=summer">Clear</a><?php endif; ?>
    <span class="muted" style="font-size:.8rem;margin-left:auto"><?= number_format($sTotal) ?> result<?= $sTotal !== 1 ? 's' : '' ?></span>
  </form>

  <!-- Applications table -->
  <?php if (!$sList): ?>
    <div class="card"><p class="empty">No summer applications yet<?= ($sStatus || $sQ) ? ' matching these filters' : '' ?>.</p></div>
  <?php else: ?>
  <div class="card" style="padding:0;overflow:hidden">
    <table class="stack">
      <thead><tr>
        <th>Reference</th><th>Applicant</th><th>Email</th><th>Discipline</th><th>Age</th><th>Status</th><th>Applied</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($sList as $sa): ?>
        <tr>
          <td data-label="Reference"><span class="mono" style="font-size:.8rem"><?= htmlspecialchars((string)$sa['reference']) ?></span></td>
          <td data-label="Applicant"><strong><?= htmlspecialchars(trim($sa['first_name'] . ' ' . $sa['last_name'])) ?></strong></td>
          <td data-label="Email"><?= htmlspecialchars((string)$sa['email']) ?></td>
          <td data-label="Discipline"><?= htmlspecialchars((string)($sa['discipline'] ?? '—')) ?></td>
          <td data-label="Age"><?= (int)($sa['age_at_submission'] ?? 0) ?: '—' ?></td>
          <td data-label="Status"><?= $sBadge((string)$sa['status']) ?></td>
          <td data-label="Applied" class="muted" style="font-size:.78rem"><?= htmlspecialchars(substr((string)$sa['created_at'], 0, 10)) ?></td>
          <td><a class="btn ghost" style="font-size:.78rem;padding:4px 10px" href="?v=summer&app=<?= (int)$sa['id'] ?>">Manage</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($sPages > 1): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
      <?php if ($sPage > 1): ?><a class="btn ghost" href="<?= htmlspecialchars($sUrl(['pg' => $sPage - 1])) ?>">← Prev</a><?php endif; ?>
      <span class="muted" style="line-height:2">Page <?= $sPage ?> of <?= $sPages ?></span>
      <?php if ($sPage < $sPages): ?><a class="btn ghost" href="<?= htmlspecialchars($sUrl(['pg' => $sPage + 1])) ?>">Next →</a><?php endif; ?>
    </div>
  <?php endif; ?>
  <?php endif; ?>

  <!-- Application detail drawer -->
  <?php
    $saId  = (int)($_GET['app'] ?? 0);
    $saRow = $saId ? mam_db_row('SELECT * FROM summer_applications WHERE id = ?', [$saId]) : null;
    if ($saRow):
  ?>
  <div class="mam-drawer-scrim" id="summerDrawerScrim" data-param="app">
  <aside class="mam-drawer" role="dialog" aria-modal="true" aria-label="Summer application">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
      <div style="display:flex;align-items:flex-start;gap:14px">
        <?php if (!empty($saRow['headshot_url'])): ?>
        <img src="<?= htmlspecialchars((string)$saRow['headshot_url']) ?>" alt="Applicant photo"
             style="width:60px;height:60px;border-radius:50%;object-fit:cover;border:2px solid var(--gold);flex-shrink:0">
        <?php endif; ?>
        <div>
          <h2 style="margin:0 0 4px">Summer application
            <span class="mono muted" style="font-size:.78rem"><?= htmlspecialchars((string)$saRow['reference']) ?></span>
          </h2>
          <div><strong><?= htmlspecialchars(trim($saRow['first_name'] . ' ' . $saRow['last_name'])) ?></strong>
            <span class="muted"> · <?= htmlspecialchars((string)$saRow['email']) ?></span></div>
          <div style="margin-top:6px"><?= $sBadge((string)$saRow['status']) ?></div>
        </div>
      </div>
      <a class="btn ghost" href="<?= htmlspecialchars($sUrl(['app' => ''])) ?>">✕ Close</a>
    </div>

    <!-- Detail grid -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 16px;margin:18px 0;font-size:.86rem">
      <?php $saField = function (string $l, $v) { if ($v === null || $v === '') return; echo '<div><span class="muted">' . htmlspecialchars($l) . '</span><br><strong>' . htmlspecialchars((string)$v) . '</strong></div>'; }; ?>
      <?php $saField('Date of birth', $saRow['dob']); ?>
      <?php $saField('Age at application', $saRow['age_at_submission']); ?>
      <?php $saField('Phone', $saRow['phone']); ?>
      <?php $saField('Sex', $saRow['sex']); ?>
      <?php $saField('Country', $saRow['country']); ?>
      <?php $saField('City', $saRow['city']); ?>
      <?php $saField('Discipline', $saRow['discipline']); ?>
      <?php $saField('Session year', $saRow['session_year']); ?>
      <?php $saField('Guardian name', $saRow['guardian_name']); ?>
      <?php $saField('Guardian phone', $saRow['guardian_phone']); ?>
      <?php $saField('Guardian email', $saRow['guardian_email']); ?>
      <?php $saField('Applied', substr((string)$saRow['created_at'], 0, 16)); ?>
    </div>
    <?php if ($saRow['experience']): ?>
      <div style="margin-bottom:10px"><div class="muted" style="font-size:.78rem;margin-bottom:4px">Musical experience</div>
        <div style="font-size:.88rem;line-height:1.65"><?= nl2br(htmlspecialchars((string)$saRow['experience'])) ?></div></div>
    <?php endif; ?>
    <?php if ($saRow['goals']): ?>
      <div style="margin-bottom:14px"><div class="muted" style="font-size:.78rem;margin-bottom:4px">Goals</div>
        <div style="font-size:.88rem;line-height:1.65"><?= nl2br(htmlspecialchars((string)$saRow['goals'])) ?></div></div>
    <?php endif; ?>

    <!-- Status update -->
    <form method="post" style="margin-top:8px;display:flex;flex-wrap:wrap;gap:10px;align-items:center">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(mam_csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= (int)$saRow['id'] ?>">
      <select name="status" style="flex:1;min-width:150px">
        <?php foreach ($sValid as $st): ?>
          <option value="<?= htmlspecialchars($st) ?>"<?= (string)$saRow['status'] === $st ? ' selected' : '' ?>><?= ucfirst(htmlspecialchars($st)) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn" type="submit">Update status</button>
    </form>
  </aside>
  </div>
  <script>
  (function () {
    var scrim = document.getElementById('summerDrawerScrim');
    if (!scrim) return;
    scrim.addEventListener('click', function (e) {
      if (e.target === scrim) { location.href = <?= json_encode($sUrl(['app' => ''])) ?>; }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { location.href = <?= json_encode($sUrl(['app' => ''])) ?>; }
    });
  })();
  </script>
  <?php endif; ?>

<?php elseif ($view === 'analytics'): ?>
  <?php
    $an   = mam_analytics_overview();
    $risk = mam_analytics_at_risk();
    $ret  = $an['retention'];
  ?>
  <header class="top"><h1>Learning Analytics</h1></header>

  <div class="kpis" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin:6px 0 4px">
    <div class="kpi"><div class="kpi-label">Enrolments</div><div class="kpi-value"><?= number_format($an['enrolments']['total']) ?></div>
      <div class="muted" style="font-size:.72rem"><?= number_format($ret['active']) ?> active</div></div>
    <div class="kpi"><div class="kpi-label">Pass rate</div><div class="kpi-value"><?= $ret['pass_rate'] === null ? '—' : $ret['pass_rate'] . '%' ?></div>
      <div class="muted" style="font-size:.72rem"><?= number_format($ret['completed']) ?> passed · <?= number_format($ret['failed']) ?> failed</div></div>
    <div class="kpi"><div class="kpi-label">Attendance</div><div class="kpi-value"><?= $an['attendance']['rate'] === null ? '—' : $an['attendance']['rate'] . '%' ?></div>
      <div class="muted" style="font-size:.72rem"><?= number_format($an['attendance']['attended']) ?>/<?= number_format($an['attendance']['total']) ?> present</div></div>
    <div class="kpi"><div class="kpi-label">At-risk students</div><div class="kpi-value"><?= number_format(count($risk)) ?></div>
      <div class="muted" style="font-size:.72rem">flagged from active enrolments</div></div>
  </div>

  <div class="card">
    <h3 style="margin:0 0 10px">Enrolment outcomes</h3>
    <?php $bs = $an['enrolments']['by_status']; if (!$bs): ?>
      <p class="muted">No enrolments yet.</p>
    <?php else: ?>
      <div style="display:flex;flex-wrap:wrap;gap:8px">
        <?php foreach (['pending', 'active', 'completed', 'failed', 'withdrawn', 'rejected'] as $st): if (!empty($bs[$st])): ?>
          <span class="pill <?= htmlspecialchars($st) ?>"><?= htmlspecialchars($st) ?>: <strong><?= number_format($bs[$st]) ?></strong></span>
        <?php endif; endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3 style="margin:0 0 10px">By course</h3>
    <?php if (!$an['by_course']): ?>
      <p class="muted">No course enrolments yet.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>Course</th><th>Enrolled</th><th>Completed</th><th>Pass rate</th><th>Avg score</th></tr></thead>
        <tbody>
        <?php foreach ($an['by_course'] as $c): ?>
          <tr>
            <td><strong><?= htmlspecialchars($c['course']) ?></strong> <span class="muted mono">· <?= htmlspecialchars($c['code']) ?></span></td>
            <td><?= number_format($c['enrolled']) ?></td>
            <td><?= number_format($c['completed']) ?></td>
            <td><?= $c['pass_rate'] === null ? '<span class="muted">—</span>' : (int)$c['pass_rate'] . '%' ?></td>
            <td><?= $c['avg_score'] === null ? '<span class="muted">—</span>' : (int)$c['avg_score'] . '%' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3 style="margin:0 0 6px">At-risk students <span class="muted" style="font-size:.72rem">(transparent heuristic over attendance, resubmissions, assessment averages &amp; practice recency)</span></h3>
    <?php if (!$risk): ?>
      <p class="muted">No active students are flagged — nicely on track. 🎉</p>
    <?php else: ?>
      <table>
        <thead><tr><th>Student</th><th>Why flagged</th><th>Attendance</th><th>Avg score</th></tr></thead>
        <tbody>
        <?php foreach ($risk as $s): ?>
          <tr>
            <td><strong><?= htmlspecialchars($s['name']) ?></strong>
              <a class="muted" style="font-size:.7rem" href="?v=search&q=<?= rawurlencode($s['name']) ?>">view</a></td>
            <td><?php foreach ($s['reasons'] as $why): ?><span class="pill submitted" style="margin:1px 2px;display:inline-block"><?= htmlspecialchars($why) ?></span><?php endforeach; ?></td>
            <td><?= $s['attendance_rate'] === null ? '<span class="muted">n/a</span>' : (int)$s['attendance_rate'] . '%' ?></td>
            <td><?= $s['avg_score'] === null ? '<span class="muted">n/a</span>' : (int)$s['avg_score'] . '%' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

<?php elseif ($view === 'fines'): ?>
  <?php
    $finesFilter = in_array($_GET['status'] ?? '', ['pending','resolved','waived']) ? (string)$_GET['status'] : '';
    $finesList   = function_exists('mam_instructor_fines') ? mam_instructor_fines(200, $finesFilter) : [];
    // Counts always over the full set so the filter pills show accurate numbers.
    $allFinesForCount = $finesFilter !== '' && function_exists('mam_instructor_fines') ? mam_instructor_fines(5000, '') : $finesList;
    $finesCounts = ['pending' => 0, 'resolved' => 0, 'waived' => 0];
    foreach ($allFinesForCount as $f) {
        $st = (string)($f['status'] ?? 'pending');
        if (array_key_exists($st, $finesCounts)) $finesCounts[$st]++;
    }
  ?>
  <header class="top">
    <h1>Instructor Fines</h1>
    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
      <?php foreach ([''=>'All','pending'=>'Pending','resolved'=>'Resolved','waived'=>'Waived'] as $fs => $fl): ?>
        <a class="btn ghost tiny<?= $finesFilter===$fs?' btn-primary':'' ?>" href="?v=fines<?= $fs!==''?'&status='.urlencode($fs):'' ?>"><?= htmlspecialchars($fl) ?></a>
      <?php endforeach; ?>
    </div>
  </header>
  <div class="kpis" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;margin:6px 0 18px">
    <div class="kpi"><div class="kpi-label">Pending</div><div class="kpi-value" style="color:var(--danger)"><?= number_format($finesCounts['pending']) ?></div><div class="muted" style="font-size:.7rem">awaiting action</div></div>
    <div class="kpi"><div class="kpi-label">Resolved</div><div class="kpi-value" style="color:#4caf50"><?= number_format($finesCounts['resolved']) ?></div><div class="muted" style="font-size:.7rem">handled</div></div>
    <div class="kpi"><div class="kpi-label">Waived</div><div class="kpi-value" style="color:var(--muted)"><?= number_format($finesCounts['waived']) ?></div><div class="muted" style="font-size:.7rem">excused</div></div>
  </div>
  <div class="card" style="overflow-x:auto">
    <?php if (!$finesList): ?>
      <p class="muted"><?= $finesFilter ? 'No <strong>' . htmlspecialchars($finesFilter) . '</strong> fines.' : 'No instructor fines recorded yet.' ?></p>
    <?php else: ?>
    <table class="stack">
      <thead><tr>
        <th>Instructor</th><th>Session</th><th>Cohort</th><th>Reason</th><th>Amount</th><th>Status</th><th>Recorded</th><th style="width:180px"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($finesList as $f):
          $fst    = (string)($f['status'] ?? 'pending');
          $amt    = (int)($f['amount_kobo'] ?? 0);
          $amtFmt = $amt > 0 ? '&#8358;' . number_format($amt / 100, 2) : '<span class="muted" style="font-size:.78rem">Flag only</span>';
          $reasonMap = ['late_progress_report' => 'Late progress report'];
          $reasonFmt = htmlspecialchars($reasonMap[$f['reason'] ?? ''] ?? (string)($f['reason'] ?? 'Unknown'));
          $sessDate  = !empty($f['session_at']) ? substr((string)$f['session_at'], 0, 16) : '—';
          $stColor   = ['pending' => 'var(--danger)', 'resolved' => '#4caf50', 'waived' => 'var(--muted)'][$fst] ?? 'var(--ink)';
          $note      = (string)($f['resolution_note'] ?? '');
      ?>
        <tr id="fine-row-<?= (int)$f['id'] ?>">
          <td data-label="Instructor">
            <strong><?= htmlspecialchars($f['instructor_name'] ?? '—') ?></strong><br>
            <span class="muted mono" style="font-size:.72rem"><?= htmlspecialchars($f['instructor_email'] ?? '') ?></span>
          </td>
          <td data-label="Session"><?= htmlspecialchars($sessDate) ?></td>
          <td data-label="Cohort"><?= htmlspecialchars($f['cohort_title'] ?? '—') ?></td>
          <td data-label="Reason"><?= $reasonFmt ?></td>
          <td data-label="Amount"><?= $amtFmt ?></td>
          <td data-label="Status"><span style="color:<?= $stColor ?>;font-weight:600"><?= htmlspecialchars(ucfirst($fst)) ?></span></td>
          <td data-label="Recorded"><span class="muted" style="font-size:.78rem"><?= htmlspecialchars(substr((string)($f['created_at'] ?? ''), 0, 10)) ?></span></td>
          <td>
            <?php if ($fst === 'pending'): ?>
              <button class="tiny btn" style="color:#fff;background:#4caf50;border:0" onclick="fineAct(<?= (int)$f['id'] ?>,'resolve')">Resolve</button>
              <button class="tiny btn ghost" style="color:var(--muted);margin-left:4px" onclick="fineAct(<?= (int)$f['id'] ?>,'waive')">Waive</button>
            <?php elseif ($note !== ''): ?>
              <span class="muted" style="font-size:.75rem" title="<?= htmlspecialchars($note) ?>"><i class="fa-solid fa-circle-info"></i> <?= htmlspecialchars(mb_strlen($note) > 32 ? mb_substr($note, 0, 30) . '…' : $note) ?></span>
            <?php else: ?>
              <span class="muted" style="font-size:.75rem">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

<?php elseif ($view === 'payments'): ?>
  <header class="top">
    <h1>Payments</h1>
    <a class="btn ghost" href="?v=export&type=payments.csv">⬇ Export CSV</a>
  </header>
  <?php if (isset($_GET['ok'])): ?><div class="card" style="border-color:#34D399"><strong style="color:#34D399">Done.</strong></div><?php endif; ?>
  <?php if (isset($_GET['err'])): ?><div class="card" style="border-color:#FCA5A5"><strong style="color:#FCA5A5"><?= htmlspecialchars($_GET['err']) ?></strong></div><?php endif; ?>
  <div class="card">
    <table class="stack">
      <thead><tr><th>Reference</th><th>Purpose</th><th>Provider</th><th>Amount</th><th>Status</th><th>Provider Ref</th><th>Verified</th><th>Created</th><th>Actions</th></tr></thead>
      <tbody>
      <?php
      $perPage = 50; $page = mam_admin_page(); $total = (int)mam_admin_scalar('SELECT COUNT(*) FROM payments'); $off = ($page - 1) * $perPage;
      $rows = mam_db_all('SELECT * FROM payments ORDER BY created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $off);
      if (!$rows): ?><tr><td colspan="9" class="empty">No payments yet</td></tr><?php endif;
      foreach ($rows as $r): ?>
        <tr>
          <td class="mono" data-label="Reference"><?= htmlspecialchars($r['reference']) ?></td>
          <td data-label="Purpose"><span class="pill"><?= htmlspecialchars((string)($r['purpose'] ?? 'tuition')) ?></span></td>
          <td data-label="Provider"><?= htmlspecialchars($r['provider']) ?></td>
          <td data-label="Amount"><?= number_format((float)$r['amount']) ?> <?= htmlspecialchars($r['currency']) ?></td>
          <td data-label="Status"><span class="pill <?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
          <td class="mono muted" data-label="Provider Ref"><?= htmlspecialchars((string)$r['provider_reference']) ?></td>
          <td class="muted" data-label="Verified"><?= htmlspecialchars(substr((string)$r['verified_at'], 0, 16)) ?></td>
          <td class="muted" data-label="Created"><?= htmlspecialchars(substr($r['created_at'], 0, 16)) ?></td>
          <td data-label="">
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
    <?= mam_admin_pager($page, $total, $perPage) ?>
  </div>

<?php elseif ($view === 'users'): ?>
  <header class="top">
    <h1>Users</h1>
    <div style="display:flex;gap:8px;align-items:center;margin-left:auto">
      <button class="btn ghost tiny" id="usersViewCardBtn" onclick="setUsersView('card')" title="Card view"><i class="fa-solid fa-grip"></i></button>
      <button class="btn ghost tiny" id="usersViewListBtn" onclick="setUsersView('list')" title="List view"><i class="fa-solid fa-list"></i></button>
      <a class="btn ghost" href="?v=export&type=users.csv">⬇ Export CSV</a>
    </div>
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
      <label>Primary role
        <select name="role" required>
          <?php foreach (['student','instructor','admin'] as $r): ?>
            <option value="<?= $r ?>" <?= ($editUser['role'] ?? 'student') === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php if ($editUser): $heldRoles = mam_user_roles($editUser); ?>
      <label>Additional access <span class="muted" style="font-weight:400">— a user can be both a student and an instructor; on login they choose where to go</span>
        <span style="display:flex;gap:20px;margin-top:8px">
          <?php foreach (['student' => 'Student portal', 'instructor' => 'Instructor portal'] as $rc => $rl): ?>
            <label style="font-weight:400;display:flex;gap:7px;align-items:center;margin:0"><input type="checkbox" name="extra_roles[]" value="<?= $rc ?>" style="width:auto" <?= in_array($rc, $heldRoles, true) ? 'checked' : '' ?>> <?= $rl ?></label>
          <?php endforeach; ?>
        </span>
      </label>
      <?php endif; ?>
      <?php if (!$editUser): ?>
        <label>Temporary password<input type="text" name="password" minlength="8" required placeholder="≥ 8 chars"></label>
      <?php endif; ?>
      <div class="actions">
        <button class="btn" type="submit"><?= $editUser ? 'Save changes' : 'Create user' ?></button>
        <?php if ($editUser): ?><a class="btn ghost" href="?v=users">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>

  <?php if ($editUser && in_array('student', mam_user_roles($editUser), true)):
    $studentCE = [];
    try {
        $studentCE = mam_db_all(
            "SELECT ce.id, ce.status, c.title AS course_title, c.code AS course_code,
                    l.name AS level_name, u.name AS instructor_name
               FROM course_enrollments ce
               JOIN courses c ON c.id = ce.course_id
               LEFT JOIN levels l ON l.id = ce.level_id
               LEFT JOIN users u ON u.id = ce.instructor_id
              WHERE ce.student_id = ?
              ORDER BY ce.created_at DESC",
            [(int)$editUser['id']]
        );
    } catch (\Throwable $e) {}
    $ceCourses = [];
    try { $ceCourses = mam_db_all("SELECT code, name FROM courses WHERE active = 1 ORDER BY name"); } catch (\Throwable $e) {}
    $ceInstrs = [];
    try { $ceInstrs = mam_db_all("SELECT id, name FROM users WHERE role = 'instructor' ORDER BY name"); } catch (\Throwable $e) {}
  ?>
  <div class="card">
    <h3>Course enrollments</h3>
    <?php if ($studentCE): ?>
    <table class="stack" style="margin-bottom:20px">
      <thead><tr><th>Course</th><th>Level</th><th>Instructor</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($studentCE as $ce): ?>
        <tr>
          <td data-label="Course"><?= htmlspecialchars((string)$ce['course_title']) ?> <span class="muted"><?= htmlspecialchars((string)$ce['course_code']) ?></span></td>
          <td data-label="Level"><?= htmlspecialchars((string)($ce['level_name'] ?? '—')) ?></td>
          <td data-label="Instructor"><?= htmlspecialchars((string)($ce['instructor_name'] ?? '—')) ?></td>
          <td data-label="Status"><span class="pill <?= htmlspecialchars((string)$ce['status']) ?>"><?= htmlspecialchars((string)$ce['status']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?><p class="empty" style="margin-bottom:16px">No course enrollments yet.</p><?php endif; ?>
    <?php if ($ceCourses): ?>
    <details><summary style="cursor:pointer;font-weight:600;color:var(--gold)">+ Assign a course</summary>
    <form method="post" class="form-grid" style="margin-top:14px">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="student_course_assign">
      <input type="hidden" name="user_id" value="<?= (int)$editUser['id'] ?>">
      <label>Course
        <select name="course_code" required>
          <option value="">— select a course —</option>
          <?php foreach ($ceCourses as $co): ?>
            <option value="<?= htmlspecialchars((string)$co['code']) ?>"><?= htmlspecialchars((string)$co['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php if ($ceInstrs): ?>
      <label>Instructor <span class="muted" style="font-weight:400">— leave blank to use the course default</span>
        <select name="instructor_id">
          <option value="0">— course default —</option>
          <?php foreach ($ceInstrs as $ins): ?>
            <option value="<?= (int)$ins['id'] ?>"><?= htmlspecialchars((string)$ins['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>
      <div class="actions"><button class="btn" type="submit">Add &amp; activate</button></div>
    </form>
    </details>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($editUser && in_array('student', mam_user_roles($editUser), true)):
    $schType = (string)(mam_db_row('SELECT student_type FROM users WHERE id = ?', [(int)$editUser['id']])['student_type'] ?? '');
    $isScholar = ($schType === 'scholarship');
  ?>
  <div class="card">
    <h3>Scholarship tier</h3>
    <p class="muted" style="margin:0 0 14px">Scholarship students are billing-exempt — invoicing and auto-expiry skip them. Every grant or revoke is audited.</p>
    <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap">
      <span>Status: <strong><?= $isScholar ? '<span style="color:var(--gold)">MAM Scholar ✦</span>' : '<span class="muted">Fee-paying</span>' ?></strong></span>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="set_student_type">
        <input type="hidden" name="user_id" value="<?= (int)$editUser['id'] ?>">
        <input type="hidden" name="student_type" value="<?= $isScholar ? 'paying' : 'scholarship' ?>">
        <button class="btn <?= $isScholar ? 'ghost' : '' ?>" type="submit">
          <?= $isScholar ? 'Revoke scholarship' : 'Grant MAM scholarship' ?>
        </button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($editUser && in_array('instructor', mam_user_roles($editUser), true)):
    $ledger = mam_wallet_history((int)$editUser['id'], 30);
    $ledgerBal = mam_wallet_balance((int)$editUser['id']);
  ?>
  <div class="card">
    <h3>Earnings ledger <span class="muted" style="font-size:.82rem;font-weight:400">last 30 entries</span></h3>
    <p style="margin:0 0 14px;font-size:.85rem">Balance: <strong>$<?= number_format($ledgerBal / 100, 2) ?></strong></p>
    <?php if ($ledger): ?>
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:.82rem">
      <thead><tr style="border-bottom:1px solid var(--border)">
        <th style="text-align:left;padding:4px 10px 6px 0;color:var(--muted);font-weight:600">Date</th>
        <th style="text-align:left;padding:4px 10px 6px;color:var(--muted);font-weight:600">Type</th>
        <th style="text-align:left;padding:4px 10px 6px;color:var(--muted);font-weight:600">Note</th>
        <th style="text-align:right;padding:4px 0 6px 10px;color:var(--muted);font-weight:600">Amount</th>
        <th style="text-align:right;padding:4px 0 6px 10px;color:var(--muted);font-weight:600">Balance</th>
      </tr></thead>
      <tbody>
      <?php foreach ($ledger as $row): $pos = $row['amount_cents'] >= 0; ?>
        <tr style="border-bottom:1px solid var(--border-faint,#2a1e15)">
          <td style="padding:7px 10px 7px 0;color:var(--muted)" class="mono"><?= htmlspecialchars(substr((string)$row['created_at'], 0, 16)) ?></td>
          <td style="padding:7px 10px"><?= htmlspecialchars(mam_wallet_reason_label((string)$row['reason'])) ?></td>
          <td style="padding:7px 10px;color:var(--muted)"><?= htmlspecialchars((string)($row['note'] ?? '')) ?></td>
          <td style="padding:7px 0 7px 10px;text-align:right;font-variant-numeric:tabular-nums;color:<?= $pos ? '#4ADE80' : '#F87171' ?>;font-weight:600"><?= $pos ? '+' : '' ?>$<?= number_format(abs($row['amount_cents']) / 100, 2) ?></td>
          <td style="padding:7px 0 7px 10px;text-align:right;font-variant-numeric:tabular-nums;color:var(--muted)">$<?= number_format($row['balance_after_cents'] / 100, 2) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php else: ?>
      <p class="muted">No earnings recorded yet.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <h3>All users <button type="button" class="btn btn-ghost" style="font-size:.78rem;margin-left:12px;vertical-align:middle" onclick="backfillEnrollRefs(this)" title="Generate MAM/YY/NNN IDs for all active students who are missing one"><i class="fa-solid fa-id-card"></i> Backfill student IDs</button></h3>
    <div id="usersTableView">
    <table class="stack">
      <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last login</th><th>Created</th><th>Reset password</th><th></th></tr></thead>
      <tbody>
      <?php
      $roleFilter = $_GET['role'] ?? '';
      $where = ''; $params = [];
      if (in_array($roleFilter, ['admin','instructor','student'], true)) { $where = 'WHERE role = ?'; $params = [$roleFilter]; }
      $perPage = 50; $page = mam_admin_page();
      $total = (int)(mam_db_row("SELECT COUNT(*) AS n FROM users $where", $params)['n'] ?? 0);
      $off = ($page - 1) * $perPage;
      $users = mam_db_all("SELECT id, email, name, phone, role, status, last_login, created_at, admin_tier, enrollment_ref FROM users $where ORDER BY created_at DESC LIMIT $perPage OFFSET $off", $params);
      if (!$users): ?><tr><td colspan="8" class="empty">No users yet</td></tr><?php endif;
      foreach ($users as $u): ?>
        <tr>
          <td data-label="Name"><strong><?= htmlspecialchars((string)($u['name'] ?? '—')) ?></strong><br><span class="muted mono">#<?= (int)$u['id'] ?><?php if (!empty($u['enrollment_ref'])): ?> · <span style="color:var(--gold-bright);letter-spacing:0.04em" title="Student enrollment ID"><?= htmlspecialchars((string)$u['enrollment_ref']) ?></span><?php elseif ($u['role']==='student'): ?> · <button class="tiny btn ghost" style="padding:2px 7px;font-size:0.72rem" onclick="generateEnrollRef(<?= (int)$u['id'] ?>,this)" title="Generate enrollment ID for this student">Assign ID</button><?php endif; ?></span></td>
          <td data-label="Email"><?= htmlspecialchars($u['email']) ?><br><span class="muted"><?= htmlspecialchars((string)($u['phone'] ?? '')) ?></span></td>
          <td data-label="Role"><span class="pill <?= htmlspecialchars($u['role']) ?>"><?= htmlspecialchars($u['role']) ?></span>
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
            <?php if ($u['role'] === 'instructor'): $walletBal = mam_wallet_balance((int)$u['id']); ?>
              <form class="inline" method="post" style="margin-top:6px;display:flex;gap:4px;align-items:center;flex-wrap:wrap" title="Record instructor earnings (USD). Negative adjusts down; never below $0.">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="wallet_credit">
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <span class="muted mono" title="Earnings balance">💰 $<?= number_format($walletBal / 100, 2) ?></span>
                <input type="number" name="amount_usd" class="tiny" step="0.01" placeholder="± USD" style="width:74px" required>
                <input type="text" name="note" class="tiny" placeholder="note (optional)" maxlength="255" style="width:96px">
                <button class="tiny" type="submit">Earnings</button>
              </form>
            <?php endif; ?>
          </td>
          <td data-label="Status"><span class="pill <?= htmlspecialchars($u['status']) ?>"><?= htmlspecialchars($u['status']) ?></span></td>
          <td class="muted" data-label="Last login"><?= htmlspecialchars(substr((string)($u['last_login'] ?? ''), 0, 16)) ?></td>
          <td class="muted" data-label="Created"><?= htmlspecialchars(substr((string)$u['created_at'], 0, 16)) ?></td>
          <td data-label="Reset password">
            <form class="inline" method="post">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="user_reset">
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <input type="text" name="password" class="tiny" placeholder="new password" minlength="8" required>
              <button class="tiny" type="submit">Reset</button>
            </form>
          </td>
          <td data-label="">
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
            <?php if ($u['role'] === 'student'): ?>
            <button class="tiny btn ghost" style="margin-left:6px;padding:6px 10px" onclick="genProgressLink(<?= (int)$u['id'] ?>,<?= htmlspecialchars(json_encode($u['name'] ?? 'Student')) ?>)" title="Generate shareable learner progress report link"><i class="fa-solid fa-link"></i> Progress Link</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?= mam_admin_pager($page, $total, $perPage) ?>
    </div><!-- #usersTableView -->

    <div id="usersCardView" style="display:none">
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;padding:4px 0">
        <?php foreach ($users as $u): ?>
        <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:16px;display:flex;flex-direction:column;gap:8px">
          <div style="display:flex;align-items:center;gap:12px">
            <div style="width:42px;height:42px;border-radius:50%;background:var(--gold-light);color:var(--gold);font-weight:700;font-size:1.1rem;display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <?= htmlspecialchars(mb_strtoupper(mb_substr((string)($u['name'] ?? '?'), 0, 1))) ?>
            </div>
            <div style="min-width:0">
              <div style="font-weight:600;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars((string)($u['name'] ?? '—')) ?></div>
              <div style="font-size:.74rem;color:var(--muted)">#<?= (int)$u['id'] ?><?php if (!empty($u['enrollment_ref'])): ?> · <span style="color:var(--gold-bright);letter-spacing:.04em"><?= htmlspecialchars((string)$u['enrollment_ref']) ?></span><?php endif; ?></div>
            </div>
          </div>
          <div style="font-size:.82rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($u['email']) ?></div>
          <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
            <span class="pill <?= htmlspecialchars($u['role']) ?>"><?= htmlspecialchars($u['role']) ?></span>
            <span class="pill <?= htmlspecialchars($u['status']) ?>"><?= htmlspecialchars($u['status']) ?></span>
          </div>
          <?php if (!empty($u['last_login'])): ?>
          <div style="font-size:.72rem;color:var(--muted)">Last login: <?= htmlspecialchars(substr((string)$u['last_login'], 0, 16)) ?></div>
          <?php endif; ?>
          <div style="margin-top:4px;display:flex;gap:6px;flex-wrap:wrap">
            <a class="tiny btn ghost" href="?v=users&edit=<?= (int)$u['id'] ?>">Edit</a>
            <?php if ($u['role'] === 'student'): ?>
              <?php if (empty($u['enrollment_ref'])): ?>
              <button class="tiny btn ghost" onclick="generateEnrollRef(<?= (int)$u['id'] ?>,this)" title="Generate enrollment ID">Assign ID</button>
              <?php endif; ?>
              <button class="tiny btn ghost" onclick="genProgressLink(<?= (int)$u['id'] ?>,<?= htmlspecialchars(json_encode($u['name'] ?? 'Student')) ?>)" title="Progress report link"><i class="fa-solid fa-link"></i></button>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?= mam_admin_pager($page, $total, $perPage) ?>
    </div><!-- #usersCardView -->
  </div><!-- .card All users -->
  <script>
  (function(){
    function setUsersView(v) {
      localStorage.setItem('mam_users_view', v);
      var tv = document.getElementById('usersTableView');
      var cv = document.getElementById('usersCardView');
      var lb = document.getElementById('usersViewListBtn');
      var cb = document.getElementById('usersViewCardBtn');
      if (tv) tv.style.display = v === 'list' ? '' : 'none';
      if (cv) cv.style.display = v === 'card' ? '' : 'none';
      if (lb) lb.style.opacity = v === 'list' ? '1' : '.45';
      if (cb) cb.style.opacity = v === 'card' ? '1' : '.45';
    }
    window.setUsersView = setUsersView;
    setUsersView(localStorage.getItem('mam_users_view') || 'list');
  })();
  </script>

  <!-- ── Faculty (instructor) pipeline: requests → invited → applications ── -->
  <?php $facRequests = function_exists('mam_faculty_pending_requests') ? mam_faculty_pending_requests() : []; ?>
  <?php if ($facRequests): ?>
  <div class="card" style="border-color:var(--gold)">
    <h3>🎓 Teaching requests <span class="muted" style="font-weight:400;font-size:.8rem">— <?= count($facRequests) ?> awaiting your approval. Approving emails a single-use application link (valid <?= (int)MAM_FACULTY_INVITE_DAYS ?> days); nothing is sent until you approve.</span></h3>
    <table>
      <thead><tr><th>From</th><th>Source</th><th>Requested</th><th>Decision</th></tr></thead>
      <tbody>
      <?php foreach ($facRequests as $fr): ?>
        <tr>
          <td><strong><?= htmlspecialchars((string)($fr['name'] ?: '—')) ?></strong><br><span class="muted"><?= htmlspecialchars((string)$fr['email']) ?></span></td>
          <td><span class="pill"><?= htmlspecialchars((string)$fr['source']) ?></span></td>
          <td class="muted"><?= htmlspecialchars(substr((string)$fr['created_at'], 0, 16)) ?></td>
          <td style="white-space:nowrap">
            <form class="inline" method="post" style="display:flex;gap:6px;align-items:center">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="faculty_request_review">
              <input type="hidden" name="invite_id" value="<?= (int)$fr['id'] ?>">
              <button class="tiny" type="submit" name="decision" value="approve" style="border-color:#34D399;color:#34D399">Approve &amp; send form</button>
              <button class="tiny" type="submit" name="decision" value="dismiss" style="border-color:#FCA5A5;color:#FCA5A5">Dismiss</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <div class="card" id="faculty">
    <h3>Faculty applications <span class="muted" style="font-weight:400;font-size:.8rem">— instructor candidates who completed the details + headshot form</span></h3>
    <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:0 20px 14px" title="Email someone the instructor application form directly (single-use link, valid <?= (int)MAM_FACULTY_INVITE_DAYS ?> days)">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="faculty_invite">
      <span class="muted" style="font-size:.84rem">Invite an instructor directly:</span>
      <input type="email" name="email" class="tiny" placeholder="their@email.com" required style="width:220px">
      <button class="tiny" type="submit">Send form</button>
    </form>
    <table>
      <thead><tr><th>Candidate</th><th>Disciplines · Experience</th><th>Materials</th><th>Bio</th><th>Status</th></tr></thead>
      <tbody>
      <?php
        $facApps = mam_admin_rows("SELECT * FROM instructor_applications ORDER BY (status='submitted') DESC, id DESC LIMIT 100");
        if (!$facApps): ?><tr><td colspan="5" class="empty">No instructor applications yet. Teaching-interest contact messages are automatically sent the form; you can also invite someone above.</td></tr><?php endif;
      foreach ($facApps as $fa): ?>
        <tr>
          <td>
            <?php if (!empty($fa['headshot_url'])): ?><img src="<?= htmlspecialchars((string)$fa['headshot_url']) ?>" alt="" style="width:42px;height:42px;border-radius:50%;object-fit:cover;vertical-align:middle;margin-right:8px"><?php endif; ?>
            <strong><?= htmlspecialchars((string)($fa['full_name'] ?: '—')) ?></strong><br>
            <span class="muted"><?= htmlspecialchars((string)$fa['email']) ?><?= $fa['phone'] ? ' · ' . htmlspecialchars((string)$fa['phone']) : '' ?></span><br>
            <span class="muted" style="font-size:.76rem"><?= htmlspecialchars(substr((string)$fa['created_at'], 0, 16)) ?></span>
          </td>
          <td><?= htmlspecialchars((string)($fa['disciplines'] ?: '—')) ?><?= $fa['experience_years'] !== null ? '<br><span class="muted">' . (int)$fa['experience_years'] . ' yrs</span>' : '' ?></td>
          <td style="white-space:nowrap">
            <?php if (!empty($fa['cv_url'])): ?><a class="tiny btn ghost" href="<?= htmlspecialchars((string)$fa['cv_url']) ?>" target="_blank" rel="noopener">CV</a><?php endif; ?>
            <?php if (!empty($fa['links'])): ?><span class="muted" style="font-size:.78rem;display:block;max-width:180px;overflow:hidden;text-overflow:ellipsis" title="<?= htmlspecialchars((string)$fa['links']) ?>"><?= htmlspecialchars((string)$fa['links']) ?></span><?php endif; ?>
          </td>
          <td style="max-width:300px"><span class="muted" style="font-size:.82rem"><?= nl2br(htmlspecialchars(mb_substr((string)($fa['bio'] ?? ''), 0, 260))) ?><?= mb_strlen((string)($fa['bio'] ?? '')) > 260 ? '…' : '' ?></span></td>
          <td style="white-space:nowrap">
            <form class="inline" method="post" style="display:flex;gap:4px;align-items:center">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="faculty_app_status">
              <input type="hidden" name="app_id" value="<?= (int)$fa['id'] ?>">
              <select name="status" class="tiny">
                <?php foreach (['submitted','reviewing','approved','declined'] as $st): ?>
                  <option value="<?= $st ?>" <?= $fa['status'] === $st ? 'selected' : '' ?>><?= $st ?></option>
                <?php endforeach; ?>
              </select>
              <button class="tiny" type="submit">Save</button>
            </form>
            <?php if ($fa['status'] === 'approved' && !mam_db_row('SELECT id FROM users WHERE email = ?', [(string)$fa['email']])): ?>
              <span class="muted" style="display:block;font-size:.74rem;margin-top:4px">→ create their instructor account above (welcome email sends automatically)</span>
            <?php endif; ?>
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
    // Class detail drawer (roster + attendance + linked curriculum) — shown when ?class=N.
    $viewCid = (int)($_GET['class'] ?? 0);
    $viewClass = $viewCid ? mam_db_row('SELECT c.*, u.name AS instructor_name FROM classes c LEFT JOIN users u ON u.id = c.instructor_id WHERE c.id = ?', [$viewCid]) : null;
    if ($viewClass):
      $roster = mam_db_all(
        "SELECT u.name, u.email, e.status AS enr_status,
                (SELECT COUNT(*) FROM attendance a WHERE a.enrollment_id = e.id AND a.status = 'present') AS present,
                (SELECT COUNT(*) FROM attendance a WHERE a.enrollment_id = e.id) AS total
           FROM enrollments e JOIN users u ON u.id = e.student_id
          WHERE e.class_id = ? ORDER BY u.name", [$viewCid]);
      $attCounts = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0];
      foreach (mam_db_all("SELECT a.status, COUNT(*) c FROM attendance a JOIN enrollments e ON e.id = a.enrollment_id WHERE e.class_id = ? GROUP BY a.status", [$viewCid]) as $ac) { $attCounts[(string)$ac['status']] = (int)$ac['c']; }
      $activeRoster = array_filter($roster, fn($r) => $r['enr_status'] === 'active');
      $mailto = implode(',', array_filter(array_map(fn($r) => (string)$r['email'], $activeRoster)));
  ?>
  <div class="mam-drawer-scrim" data-param="class">
  <aside class="mam-drawer" role="dialog" aria-modal="true" aria-label="Class roster">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
      <div>
        <h2 style="margin:0 0 4px">Class roster <span class="mono muted" style="font-size:.8rem">#<?= (int)$viewClass['id'] ?></span></h2>
        <div><strong><?= htmlspecialchars((string)$viewClass['title']) ?></strong></div>
        <div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap">
          <span class="pill" style="background:var(--raised)"><?= htmlspecialchars((string)($viewClass['discipline'] ?? '—')) ?></span>
          <?php if (!empty($viewClass['mode'])): ?><span class="pill" style="background:var(--raised)"><?= htmlspecialchars((string)$viewClass['mode']) ?></span><?php endif; ?>
          <span class="pill <?= htmlspecialchars((string)$viewClass['status']) ?>"><?= htmlspecialchars((string)$viewClass['status']) ?></span>
        </div>
      </div>
      <a class="btn ghost" href="?v=classes">✕ Close</a>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(90px,1fr));gap:10px;margin:16px 0">
      <div class="kpi"><div class="kpi-label">Enrolled</div><div class="kpi-value"><?= count($activeRoster) ?></div></div>
      <div class="kpi"><div class="kpi-label">Present</div><div class="kpi-value" style="color:var(--emerald)"><?= (int)$attCounts['present'] ?></div></div>
      <div class="kpi"><div class="kpi-label">Absent</div><div class="kpi-value" style="color:var(--red)"><?= (int)$attCounts['absent'] ?></div></div>
    </div>

    <div style="display:flex;flex-direction:column;gap:4px;margin-bottom:16px;font-size:.86rem">
      <div><span class="muted">Instructor:</span> <?= htmlspecialchars((string)($viewClass['instructor_name'] ?? '— unassigned —')) ?></div>
      <?php if (!empty($viewClass['schedule'])): ?><div><span class="muted">Schedule:</span> <?= htmlspecialchars((string)$viewClass['schedule']) ?></div><?php endif; ?>
      <div><span class="muted">Capacity:</span> <?= (int)$viewClass['capacity'] ?></div>
      <?php if (!empty($viewClass['meeting_link'])): ?><div><span class="muted">Meeting link:</span> <a href="<?= htmlspecialchars((string)$viewClass['meeting_link']) ?>" target="_blank" rel="noopener noreferrer" style="color:var(--gold)">Join class ↗</a></div><?php endif; ?>
    </div>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
      <a class="btn ghost" href="?v=classes&edit=<?= (int)$viewClass['id'] ?>"><i class="fa-solid fa-pen" aria-hidden="true"></i> Edit class</a>
      <?php if ($mailto): ?><a class="btn ghost" href="mailto:?bcc=<?= htmlspecialchars(rawurlencode($mailto)) ?>"><i class="fa-solid fa-envelope" aria-hidden="true"></i> Message class</a><?php endif; ?>
    </div>

    <h3 style="margin:0 0 8px">Roster &amp; attendance</h3>
    <?php if (!$roster): ?>
      <p class="muted">No students enrolled yet. Enrol from <a href="?v=enrollments" style="color:var(--gold)">Enrollments →</a>.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>Student</th><th>Status</th><th>Attendance</th></tr></thead>
        <tbody>
        <?php foreach ($roster as $r): $rate = (int)$r['total'] > 0 ? round((int)$r['present'] / (int)$r['total'] * 100) : null; ?>
          <tr>
            <td><strong><?= htmlspecialchars((string)($r['name'] ?? '—')) ?></strong><br><span class="muted" style="font-size:.76rem"><?= htmlspecialchars((string)$r['email']) ?></span></td>
            <td><span class="pill <?= htmlspecialchars((string)$r['enr_status']) ?>"><?= htmlspecialchars((string)$r['enr_status']) ?></span></td>
            <td class="muted"><?= $rate === null ? '<span class="muted">no sessions</span>' : ($rate . '% · ' . (int)$r['present'] . '/' . (int)$r['total']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </aside>
  </div>
  <?php endif; ?>
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
      <label class="full">Meeting link <small class="muted">(Online/Hybrid only — https:// URL)</small><input type="url" name="meeting_link" placeholder="https://zoom.us/j/…" value="<?= htmlspecialchars((string)($editClass['meeting_link'] ?? '')) ?>"></label>
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
      $perPage = 50; $page = mam_admin_page(); $total = (int)mam_admin_scalar('SELECT COUNT(*) FROM classes'); $off = ($page - 1) * $perPage;
      $classes = mam_db_all(
        "SELECT c.*, u.name AS instructor_name, (SELECT COUNT(*) FROM enrollments e WHERE e.class_id = c.id AND e.status='active') AS enrolled_count
         FROM classes c LEFT JOIN users u ON u.id = c.instructor_id ORDER BY c.created_at DESC LIMIT " . $perPage . " OFFSET " . $off
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
            <a class="tiny btn ghost" style="margin-left:6px;padding:6px 10px" href="?v=classes&class=<?= (int)$c['id'] ?>">Roster</a>
            <a class="tiny btn ghost" style="margin-left:6px;padding:6px 10px" href="?v=classes&edit=<?= (int)$c['id'] ?>">Edit</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?= mam_admin_pager($page, $total, $perPage) ?>
  </div>

<?php elseif ($view === 'enrollments'): ?>
  <header class="top"><h1>Enrollments <span class="muted" style="font-size:0.6em;font-weight:400">· legacy per-class roster</span></h1></header>
  <div class="banner" style="background:rgba(224,138,26,0.08);border:1px solid var(--border)">These are the legacy <strong>class</strong> enrolments. Course registrations and cohorts (the unified view) live under <a href="?v=courseenroll" style="color:var(--gold)">Course Registrations →</a>. Both are read side-by-side there while the convergence settles.</div>
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

<?php elseif ($view === 'schedule'): ?>
  <?php
    // Week navigation: ?week=YYYY-Www (ISO week) or default = current week.
    $isoWeek = isset($_GET['week']) && preg_match('/^\d{4}-W\d{2}$/', (string)$_GET['week']) ? (string)$_GET['week'] : date('o-\WW');
    [$isoYear, $isoWn] = explode('-W', $isoWeek);
    $weekMon = new DateTimeImmutable();
    $weekMon = $weekMon->setISODate((int)$isoYear, (int)$isoWn, 1); // Monday
    $weekSun = $weekMon->modify('+6 days');
    $prevWeek = $weekMon->modify('-7 days')->format('o-\WW');
    $nextWeek = $weekMon->modify('+7 days')->format('o-\WW');
    $schedSessions = function_exists('mam_lms_schedule_range')
        ? mam_lms_schedule_range($weekMon->format('Y-m-d'), $weekSun->format('Y-m-d'))
        : [];
    // Group sessions by Y-m-d.
    $byDay = [];
    foreach ($schedSessions as $s) {
        $d = substr((string)($s['scheduled_at'] ?? ''), 0, 10);
        if ($d) $byDay[$d][] = $s;
    }
    $kindColors = ['class' => '#34D399', 'exam' => '#F87171', 'revision' => '#60A5FA', 'makeup' => '#FBBF24'];
  ?>
  <header class="top">
    <h1>Class Schedule</h1>
    <div style="display:flex;gap:8px;align-items:center;margin-left:auto">
      <a class="btn ghost tiny" href="?v=schedule&week=<?= htmlspecialchars($prevWeek) ?>"><i class="fa-solid fa-chevron-left"></i></a>
      <a class="btn ghost tiny" href="?v=schedule">This week</a>
      <a class="btn ghost tiny" href="?v=schedule&week=<?= htmlspecialchars($nextWeek) ?>"><i class="fa-solid fa-chevron-right"></i></a>
      <button class="btn btn-primary tiny" onclick="adminSchedModal.open()"><i class="fa-solid fa-plus"></i> New Session</button>
    </div>
  </header>
  <div class="card" style="overflow-x:auto">
    <div style="display:flex;gap:4px;min-width:700px">
    <?php
    $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    for ($i = 0; $i < 7; $i++):
        $day     = $weekMon->modify("+$i days");
        $dayKey  = $day->format('Y-m-d');
        $isToday = $dayKey === date('Y-m-d');
        $sessions = $byDay[$dayKey] ?? [];
    ?>
      <div style="flex:1;min-width:110px;border-right:1px solid var(--border);padding:0 6px<?= $isToday ? ';background:rgba(232,150,31,.05);border-radius:8px' : '' ?>">
        <div onclick="adminSchedModal.open('<?= $day->format('Y-m-d') ?>')" title="Add session on <?= htmlspecialchars($days[$i] . ' ' . $day->format('M j')) ?>" style="text-align:center;padding:10px 0 8px;font-size:.75rem;font-weight:700;color:<?= $isToday ? 'var(--gold)' : 'var(--muted)' ?>;text-transform:uppercase;letter-spacing:.06em;cursor:pointer;user-select:none">
          <?= htmlspecialchars($days[$i]) ?><br>
          <span style="font-size:1.05rem;color:<?= $isToday ? 'var(--gold)' : 'var(--ink)' ?>"><?= $day->format('j') ?></span>
        </div>
        <?php if (!$sessions): ?>
          <div onclick="adminSchedModal.open('<?= $day->format('Y-m-d') ?>')" style="font-size:.95rem;color:var(--border);text-align:center;padding:14px 0;cursor:pointer;border-radius:6px;transition:color .15s" onmouseenter="this.style.color='var(--gold)'" onmouseleave="this.style.color='var(--border)'">+</div>
        <?php else: foreach ($sessions as $s):
            $km   = $kindColors[$s['kind'] ?? 'class'] ?? '#34D399';
            $stat = (string)($s['status'] ?? 'scheduled');
            $time = substr((string)($s['scheduled_at'] ?? ''), 11, 5);
            $sub  = !empty($s['substitute_name']) ? '🔄 ' . $s['substitute_name'] : ($s['instructor_name'] ?? '—');
        ?>
          <div style="background:var(--raised);border:1px solid var(--border);border-left:3px solid <?= htmlspecialchars($km) ?>;border-radius:7px;padding:6px 7px;margin-bottom:5px;font-size:.72rem;<?= $stat === 'cancelled' ? 'opacity:.45;text-decoration:line-through' : '' ?>">
            <div style="font-weight:700;line-height:1.2;margin-bottom:2px"><?= htmlspecialchars($s['title'] ?: ($s['cohort_title'] ?: 'Session #' . $s['id'])) ?></div>
            <div style="color:var(--muted)"><?= htmlspecialchars($time) ?><?= !empty($s['duration_min']) ? ' · ' . (int)$s['duration_min'] . 'min' : '' ?></div>
            <div style="color:var(--muted);margin-top:2px"><?= htmlspecialchars($sub) ?></div>
            <?php if (!empty($s['room']) || !empty($s['location'])): ?>
            <div style="color:var(--muted);margin-top:1px"><i class="fa-solid fa-location-dot" style="font-size:.65rem"></i> <?= htmlspecialchars($s['room'] ?: $s['location']) ?></div>
            <?php endif; ?>
            <div style="display:flex;align-items:center;gap:4px;margin-top:4px;flex-wrap:wrap">
              <span style="background:<?= htmlspecialchars($km) ?>22;color:<?= htmlspecialchars($km) ?>;font-size:.6rem;padding:1px 5px;border-radius:4px;text-transform:uppercase"><?= htmlspecialchars($s['kind'] ?? 'class') ?></span>
              <?php if ($stat !== 'cancelled'): ?>
              <button onclick="schedRemind(<?= (int)$s['id'] ?>,this)" title="Send reminder to instructor + students" style="background:none;border:none;cursor:pointer;padding:1px 4px;border-radius:4px;color:var(--gold);font-size:.65rem;line-height:1" <?= $stat === 'cancelled' ? 'disabled' : '' ?>><i class="fa-solid fa-bell"></i></button>
              <button onclick="schedLinkModal(<?= (int)$s['id'] ?>,<?= json_encode($s['meeting_link'] ?? '') ?>)" title="Set meeting link" style="background:none;border:none;cursor:pointer;padding:1px 4px;border-radius:4px;font-size:.65rem;line-height:1;color:<?= !empty($s['meeting_link']) ? '#34D399' : 'var(--muted)' ?>"><i class="fa-solid fa-link"></i></button>
              <?php if (!empty($s['meeting_link'])): ?>
              <a href="/session/<?= (int)$s['id'] ?>/join" target="_blank" title="Test join link" style="color:#34D399;font-size:.65rem;padding:1px 3px"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
              <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    <?php endfor; ?>
    </div>
    <div style="padding:12px 6px 6px;font-size:.78rem;color:var(--muted)">
      <?= count($schedSessions) ?> session<?= count($schedSessions) !== 1 ? 's' : '' ?> this week (<?= htmlspecialchars($weekMon->format('M j')) ?> – <?= htmlspecialchars($weekSun->format('M j, Y')) ?>)
      &nbsp;·&nbsp; <a href="?v=courseenroll" style="color:var(--gold)">Manage cohorts →</a>
    </div>
  </div>
  <?php if (empty($schedSessions)): ?>
    <div class="card" style="text-align:center;padding:32px;color:var(--muted)"><i class="fa-solid fa-calendar-xmark" style="font-size:2rem;display:block;margin-bottom:12px;opacity:.4"></i>No sessions scheduled this week. <button class="btn ghost" style="margin-top:10px" onclick="adminSchedModal.open()">Schedule a session</button></div>
  <?php endif; ?>

  <!-- New Session modal -->
  <div id="adminSchedOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:200;align-items:center;justify-content:center">
    <div style="background:var(--raised);border-radius:12px;padding:28px 24px;max-width:480px;width:90%;max-height:90vh;overflow-y:auto;position:relative">
      <h3 style="margin:0 0 18px">Schedule a Session</h3>
      <form id="adminSchedForm" class="form-grid">
        <label>Cohort
          <select id="assCohort" required>
            <option value="">Loading cohorts…</option>
          </select>
        </label>
        <label>Title <span class="muted" style="font-weight:400">(optional)</span>
          <input type="text" id="assTitle" placeholder="e.g. Week 3 Class">
        </label>
        <label>Date &amp; Time
          <input type="datetime-local" id="assSchedAt" required>
        </label>
        <label>Duration (minutes)
          <input type="number" id="assDur" value="60" min="15" max="480">
        </label>
        <label>Kind
          <select id="assKind">
            <option value="class">Class</option>
            <option value="exam">Exam</option>
            <option value="revision">Revision</option>
            <option value="makeup">Makeup</option>
          </select>
        </label>
        <label>Location <span class="muted" style="font-weight:400">(optional)</span>
          <input type="text" id="assLoc" placeholder="e.g. Main Hall">
        </label>
        <label>Room <span class="muted" style="font-weight:400">(optional)</span>
          <input type="text" id="assRoom" placeholder="e.g. Room 4">
        </label>
        <div class="actions" style="margin-top:4px">
          <button class="btn" type="button" id="assSubmitBtn" onclick="adminSchedSubmit()">Create Session</button>
          <button class="btn ghost" type="button" onclick="adminSchedModal.close()">Cancel</button>
        </div>
        <div id="assErr" style="color:#F87171;font-size:.82rem;display:none;margin-top:6px"></div>
      </form>
    </div>
  </div>
  <script>
  var _adminCsrf = (document.querySelector('meta[name=csrf-token]')||{}).content || '';
  (function(){
    var overlay = document.getElementById('adminSchedOverlay');
    var cohortSel = document.getElementById('assCohort');
    var cohortsLoaded = false;
    window.adminSchedModal = {
      open: function() {
        overlay.style.display = 'flex';
        document.getElementById('assErr').style.display = 'none';
        document.getElementById('assSubmitBtn').disabled = false;
        document.getElementById('assSubmitBtn').textContent = 'Create Session';
        if (!cohortsLoaded) {
          fetch('/api/instructor/sessions', {credentials:'same-origin'})
            .then(function(r){return r.json();})
            .then(function(d){
              cohortsLoaded = true;
              var list = d.cohorts || [];
              if (!list.length) { cohortSel.innerHTML='<option value="">No cohorts found</option>'; return; }
              cohortSel.innerHTML = '<option value="">— choose cohort —</option>' +
                list.map(function(c){return '<option value="'+parseInt(c.id,10)+'">'+c.title.replace(/</g,'&lt;')+'</option>';}).join('');
            })
            .catch(function(){cohortSel.innerHTML='<option value="">Error loading cohorts</option>';});
        }
      },
      close: function() { overlay.style.display = 'none'; }
    };
    overlay.addEventListener('click', function(e){ if(e.target===overlay) adminSchedModal.close(); });
  })();
  function adminSchedSubmit() {
    var btn = document.getElementById('assSubmitBtn');
    var err = document.getElementById('assErr');
    var cohortId = parseInt(document.getElementById('assCohort').value, 10);
    var schedAt  = document.getElementById('assSchedAt').value;
    if (!cohortId || !schedAt) { err.textContent = 'Please choose a cohort and date/time.'; err.style.display='block'; return; }
    err.style.display = 'none';
    btn.disabled = true;
    btn.textContent = 'Creating…';
    var payload = {
      action: 'create',
      cohort_id: cohortId,
      scheduled_at: schedAt,
      duration_min: parseInt(document.getElementById('assDur').value, 10) || 60,
      kind: document.getElementById('assKind').value
    };
    var t = (document.getElementById('assTitle').value || '').trim();
    var l = (document.getElementById('assLoc').value || '').trim();
    var r = (document.getElementById('assRoom').value || '').trim();
    if (t) payload.title    = t;
    if (l) payload.location = l;
    if (r) payload.room     = r;
    fetch('/api/instructor/sessions', {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type':'application/json','X-CSRF-Token':_adminCsrf},
      body: JSON.stringify(payload)
    })
    .then(function(res){return res.json().then(function(d){return {ok:res.ok,d:d};});})
    .then(function(x){
      if (!x.ok) { err.textContent = (x.d && x.d.error) ? x.d.error : 'Failed to create session.'; err.style.display='block'; btn.disabled=false; btn.textContent='Create Session'; return; }
      window.location.reload();
    })
    .catch(function(){ err.textContent='Network error.'; err.style.display='block'; btn.disabled=false; btn.textContent='Create Session'; });
  }

  // Send reminder (instructor + all enrolled students)
  function schedRemind(sessionId, btn) {
    var orig = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    fetch('/api/admin/session-remind', {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type':'application/json','X-CSRF-Token':_adminCsrf},
      body: JSON.stringify({action:'send', session_id: sessionId})
    })
    .then(function(r){return r.json().then(function(d){return {ok:r.ok,d:d};});})
    .then(function(x){
      btn.disabled = false; btn.innerHTML = orig;
      if (!x.ok) { alert('Could not send reminder: ' + ((x.d && x.d.error) || 'unknown error')); return; }
      var n = x.d.sent || 0;
      var i = x.d.instructor_notified ? ' + instructor' : '';
      var msg = 'Reminder sent to ' + n + ' student' + (n !== 1 ? 's' : '') + i + '.';
      if (typeof showToast === 'function') showToast(msg, 'success'); else alert(msg);
    })
    .catch(function(){ btn.disabled=false; btn.innerHTML=orig; alert('Network error.'); });
  }

  // Set / clear meeting link modal
  (function(){
    var overlay = document.createElement('div');
    overlay.id = 'linkOverlay';
    overlay.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:300;align-items:center;justify-content:center';
    overlay.innerHTML = '<div style="background:var(--raised);border-radius:12px;padding:26px 22px;max-width:440px;width:90%;position:relative">'
      + '<h3 style="margin:0 0 14px;font-size:1rem">Meeting link for this session</h3>'
      + '<p style="color:var(--muted);font-size:.83rem;margin-bottom:12px">Paste your Zoom, Meet, or Teams URL. Students and the instructor will be redirected to it via the academy site.</p>'
      + '<input id="linkInput" type="url" placeholder="https://zoom.us/j/..." style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:8px;font-size:.9rem;background:var(--bg);color:var(--ink)">'
      + '<div id="linkErr" style="color:#F87171;font-size:.8rem;margin-top:6px;display:none"></div>'
      + '<div style="display:flex;gap:8px;margin-top:16px">'
      +   '<button id="linkSaveBtn" class="btn" onclick="schedLinkSave()">Save link</button>'
      +   '<button class="btn ghost" onclick="schedLinkClose()">Cancel</button>'
      +   '<button class="btn ghost" style="margin-left:auto;color:#F87171" onclick="schedLinkSave(true)">Remove link</button>'
      + '</div>'
      + '</div>';
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function(e){ if (e.target === overlay) schedLinkClose(); });
  })();
  var _schedLinkSessionId = 0;
  function schedLinkModal(sessionId, current) {
    _schedLinkSessionId = sessionId;
    document.getElementById('linkInput').value = current || '';
    document.getElementById('linkErr').style.display = 'none';
    document.getElementById('linkOverlay').style.display = 'flex';
    setTimeout(function(){ document.getElementById('linkInput').focus(); }, 50);
  }
  function schedLinkClose() { document.getElementById('linkOverlay').style.display = 'none'; }
  function schedLinkSave(remove) {
    var link = remove ? '' : (document.getElementById('linkInput').value || '').trim();
    var errEl = document.getElementById('linkErr');
    if (link !== '' && !/^https?:\/\//i.test(link)) { errEl.textContent = 'URL must start with https://'; errEl.style.display='block'; return; }
    errEl.style.display = 'none';
    var btn = document.getElementById('linkSaveBtn');
    btn.disabled = true; btn.textContent = 'Saving…';
    fetch('/api/admin/session-remind', {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type':'application/json','X-CSRF-Token':_adminCsrf},
      body: JSON.stringify({action:'set_link', session_id: _schedLinkSessionId, meeting_link: link})
    })
    .then(function(r){return r.json().then(function(d){return {ok:r.ok,d:d};});})
    .then(function(x){
      btn.disabled=false; btn.textContent='Save link';
      if (!x.ok){ errEl.textContent = (x.d && x.d.error) || 'Failed to save.'; errEl.style.display='block'; return; }
      schedLinkClose();
      window.location.reload();
    })
    .catch(function(){ btn.disabled=false; btn.textContent='Save link'; errEl.textContent='Network error.'; errEl.style.display='block'; });
  }
  </script>

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
  <?php
    // Cohort convergence (P2): a cohort backfilled from a legacy class carries a
    // `legacy_class_id`. Fold the legacy `enrollments` count into the same row so
    // this one panel shows the unified registration reality — v16 course
    // enrolments AND the class enrolments that back the same cohort.
    $legacyCounts = [];
    foreach (mam_admin_rows("SELECT class_id, COUNT(*) AS n FROM enrollments WHERE status IN ('active','paused') GROUP BY class_id") as $lc) {
        $legacyCounts[(int)$lc['class_id']] = (int)$lc['n'];
    }
  ?>
  <?php
    // Pre-compute fill counts and waitlist counts for all cohorts.
    $coFillMap = []; $coWaitMap = [];
    foreach ($cohortList as $co) {
        $cid = (int)$co['id'];
        try { $fc = mam_lms_cohort_fill_count($cid); $coFillMap[$cid] = $fc; $coWaitMap[$cid] = (int)$fc['waitlist_count']; } catch (Throwable $e) {}
    }
  ?>
  <?php
    $editCohortId = (int)($_GET['edit_cohort'] ?? 0);
    $editCohort   = $editCohortId > 0 ? mam_db_row('SELECT * FROM cohorts WHERE id = ?', [$editCohortId]) : null;
    $courseOpts   = mam_db_all("SELECT id, name FROM courses WHERE active = 1 ORDER BY name");
    $instrOpts    = mam_db_all("SELECT id, name FROM users WHERE role = 'instructor' AND status = 'active' ORDER BY name");
  ?>
  <?php if ($editCohort): ?>
  <div class="card" style="border-color:var(--border-gold)">
    <h3 style="margin:0 0 14px">Edit cohort #<?= $editCohortId ?></h3>
    <form method="post" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <?= mam_csrf_field() ?>
      <input type="hidden" name="action" value="cohort_edit">
      <input type="hidden" name="cohort_id" value="<?= $editCohortId ?>">
      <label style="display:flex;flex-direction:column;gap:4px;font-size:.82rem;font-weight:600;grid-column:1/-1">Title
        <input type="text" name="title" value="<?= htmlspecialchars((string)($editCohort['title'] ?? '')) ?>" maxlength="200" required style="padding:8px 10px;border-radius:7px;border:1px solid var(--border);background:var(--bg);color:var(--ink)">
      </label>
      <label style="display:flex;flex-direction:column;gap:4px;font-size:.82rem;font-weight:600">Course
        <select name="course_id" style="padding:8px 10px;border-radius:7px;border:1px solid var(--border);background:var(--bg);color:var(--ink)">
          <option value="">— none —</option>
          <?php foreach ($courseOpts as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (int)($editCohort['course_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label style="display:flex;flex-direction:column;gap:4px;font-size:.82rem;font-weight:600">Instructor
        <select name="instructor_id" style="padding:8px 10px;border-radius:7px;border:1px solid var(--border);background:var(--bg);color:var(--ink)">
          <option value="">— unassigned —</option>
          <?php foreach ($instrOpts as $i): ?>
            <option value="<?= (int)$i['id'] ?>" <?= (int)($editCohort['instructor_id'] ?? 0) === (int)$i['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$i['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label style="display:flex;flex-direction:column;gap:4px;font-size:.82rem;font-weight:600">Capacity
        <input type="number" name="capacity" min="1" max="500" value="<?= (int)($editCohort['capacity'] ?? 12) ?>" style="padding:8px 10px;border-radius:7px;border:1px solid var(--border);background:var(--bg);color:var(--ink)">
      </label>
      <label style="display:flex;flex-direction:column;gap:4px;font-size:.82rem;font-weight:600">Term
        <input type="text" name="term" value="<?= htmlspecialchars((string)($editCohort['term'] ?? '')) ?>" maxlength="60" placeholder="e.g. Jan 2026" style="padding:8px 10px;border-radius:7px;border:1px solid var(--border);background:var(--bg);color:var(--ink)">
      </label>
      <label style="display:flex;flex-direction:column;gap:4px;font-size:.82rem;font-weight:600">Start date
        <input type="date" name="starts_on" value="<?= htmlspecialchars((string)($editCohort['starts_on'] ?? '')) ?>" style="padding:8px 10px;border-radius:7px;border:1px solid var(--border);background:var(--bg);color:var(--ink)">
      </label>
      <label style="display:flex;flex-direction:column;gap:4px;font-size:.82rem;font-weight:600">End date
        <input type="date" name="ends_on" value="<?= htmlspecialchars((string)($editCohort['ends_on'] ?? '')) ?>" style="padding:8px 10px;border-radius:7px;border:1px solid var(--border);background:var(--bg);color:var(--ink)">
      </label>
      <label style="display:flex;flex-direction:column;gap:4px;font-size:.82rem;font-weight:600">Status
        <select name="status" style="padding:8px 10px;border-radius:7px;border:1px solid var(--border);background:var(--bg);color:var(--ink)">
          <?php foreach (['active','inactive','archived'] as $st): ?>
            <option value="<?= $st ?>" <?= ($editCohort['status'] ?? 'active') === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div style="grid-column:1/-1;display:flex;gap:8px;margin-top:4px">
        <button class="btn" type="submit">Save changes</button>
        <a class="btn ghost" href="?v=courseenroll">Cancel</a>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:0 4px 6px">
      <h3 style="margin:0">Cohorts <span class="muted" style="font-weight:400">· the unified &ldquo;scheduled run&rdquo; grouping</span></h3>
      <div style="display:flex;gap:8px;align-items:center">
        <a class="btn ghost tiny" href="?v=schedule"><i class="fa-solid fa-calendar-week" aria-hidden="true"></i> Schedule Calendar</a>
        <button class="btn tiny" type="button" onclick="document.getElementById('newCohortForm').hidden=!document.getElementById('newCohortForm').hidden" title="Create a new cohort"><i class="fa-solid fa-plus"></i> New cohort</button>
      </div>
    </div>
    <div id="newCohortForm" hidden style="border:1px solid var(--border-gold);border-radius:10px;padding:16px 18px;margin:8px 0 16px;background:var(--surface)">
      <h4 style="margin:0 0 12px;font-size:.9rem">Create cohort</h4>
      <form method="post" style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <?= mam_csrf_field() ?>
        <input type="hidden" name="action" value="cohort_create">
        <label style="display:flex;flex-direction:column;gap:4px;font-size:.82rem;font-weight:600;grid-column:1/-1">Title <span style="font-weight:400;color:var(--muted)">(required)</span>
          <input type="text" name="title" required maxlength="200" placeholder="e.g. Piano — Cert I · Jan 2026" style="padding:8px 10px;border-radius:7px;border:1px solid var(--border);background:var(--bg);color:var(--ink)">
        </label>
        <label style="display:flex;flex-direction:column;gap:4px;font-size:.82rem;font-weight:600">Course
          <select name="course_id" style="padding:8px 10px;border-radius:7px;border:1px solid var(--border);background:var(--bg);color:var(--ink)">
            <option value="">— none —</option>
            <?php foreach ($courseOpts as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars((string)$c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label style="display:flex;flex-direction:column;gap:4px;font-size:.82rem;font-weight:600">Instructor
          <select name="instructor_id" style="padding:8px 10px;border-radius:7px;border:1px solid var(--border);background:var(--bg);color:var(--ink)">
            <option value="">— unassigned —</option>
            <?php foreach ($instrOpts as $i): ?>
              <option value="<?= (int)$i['id'] ?>"><?= htmlspecialchars((string)$i['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label style="display:flex;flex-direction:column;gap:4px;font-size:.82rem;font-weight:600">Capacity
          <input type="number" name="capacity" min="1" max="500" value="12" style="padding:8px 10px;border-radius:7px;border:1px solid var(--border);background:var(--bg);color:var(--ink)">
        </label>
        <label style="display:flex;flex-direction:column;gap:4px;font-size:.82rem;font-weight:600">Term
          <input type="text" name="term" maxlength="60" placeholder="e.g. Jan 2026" style="padding:8px 10px;border-radius:7px;border:1px solid var(--border);background:var(--bg);color:var(--ink)">
        </label>
        <label style="display:flex;flex-direction:column;gap:4px;font-size:.82rem;font-weight:600">Start date
          <input type="date" name="starts_on" style="padding:8px 10px;border-radius:7px;border:1px solid var(--border);background:var(--bg);color:var(--ink)">
        </label>
        <label style="display:flex;flex-direction:column;gap:4px;font-size:.82rem;font-weight:600">End date
          <input type="date" name="ends_on" style="padding:8px 10px;border-radius:7px;border:1px solid var(--border);background:var(--bg);color:var(--ink)">
        </label>
        <div style="grid-column:1/-1;margin-top:4px">
          <button class="btn" type="submit">Create cohort</button>
        </div>
      </form>
    </div>
    <table>
      <thead><tr><th>Cohort</th><th>Course / Level</th><th>Instructor</th><th>Capacity</th><th>Waitlist</th><th>Sessions</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($cohortList as $co):
        $cid     = (int)$co['id'];
        $lcid    = (int)($co['legacy_class_id'] ?? 0);
        $legCount = (int)($legacyCounts[$lcid] ?? 0);
        $hasCourse = !empty($co['course_name']);
        $fc      = $coFillMap[$cid] ?? null;
        $enrolled = $fc ? (int)$fc['enrolled'] : (int)($co['enrollment_count'] ?? 0);
        $capacity = $fc ? (int)$fc['capacity'] : (int)($co['capacity'] ?? 999);
        $isFull   = $fc ? $fc['is_full'] : false;
        $wlCount  = $coWaitMap[$cid] ?? 0;
        $sessCount = (int)($co['session_count'] ?? 0);
        $fillColor = $capacity < 999 ? ($isFull ? '#F87171' : ($enrolled / $capacity >= 0.8 ? '#FBBF24' : '#34D399')) : 'var(--muted)';
      ?>
        <tr>
          <td><strong><?= htmlspecialchars((string)($co['title'] ?: ('Cohort #' . $co['id']))) ?></strong><?= $lcid ? '<br><span class="muted" style="font-size:.74rem">from class #' . $lcid . '</span>' : '' ?></td>
          <td><?= htmlspecialchars((string)($co['course_name'] ?? $co['discipline'] ?? '—')) ?><?= !empty($co['level_name']) ? '<br><span class="muted" style="font-size:.8rem">' . htmlspecialchars((string)$co['level_name']) . '</span>' : '' ?></td>
          <td><?= htmlspecialchars((string)($co['instructor_name'] ?? '—')) ?></td>
          <td>
            <span style="font-weight:600;color:<?= $fillColor ?>"><?= $enrolled ?></span><?= $capacity < 999 ? '<span class="muted">/' . $capacity . '</span>' : '<span class="muted"> enrolled</span>' ?>
            <?php if ($isFull): ?><br><span style="font-size:.7rem;color:#F87171">Full</span><?php endif; ?>
          </td>
          <td>
            <?php if ($wlCount > 0): ?>
              <span style="color:var(--gold);font-weight:600"><?= $wlCount ?></span> <span class="muted" style="font-size:.78rem">waiting</span>
              <form method="post" style="display:inline;margin-left:4px">
                <?= mam_csrf_field() ?>
                <input type="hidden" name="action" value="waitlist_promote">
                <input type="hidden" name="cohort_id" value="<?= $cid ?>">
                <button class="btn ghost" type="submit" style="padding:2px 7px;font-size:.72rem" title="Enrol the next waiting student (respects capacity)" onclick="return confirm('Enrol the next student from the waitlist?')"><i class="fa-solid fa-arrow-up-from-bracket"></i> Promote</button>
              </form>
              <?php if ($isFull): ?>
              <form method="post" style="display:inline;margin-left:2px">
                <?= mam_csrf_field() ?>
                <input type="hidden" name="action" value="waitlist_promote">
                <input type="hidden" name="cohort_id" value="<?= $cid ?>">
                <input type="hidden" name="force" value="1">
                <button class="btn ghost" type="submit" style="padding:2px 7px;font-size:.72rem;border-color:#F87171;color:#F87171" title="Force-enrol next student even though cohort is over capacity" onclick="return confirm('Force-promote from waitlist — this will exceed the cohort capacity. Continue?')"><i class="fa-solid fa-bolt"></i> Force</button>
              </form>
              <?php endif; ?>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td><?= $sessCount ?> <a href="?v=schedule" style="color:var(--muted);font-size:.76rem" title="View schedule"><i class="fa-solid fa-calendar-week"></i></a></td>
          <td><span class="pill <?= htmlspecialchars((string)$co['status']) ?>"><?= htmlspecialchars((string)$co['status']) ?></span></td>
          <td style="white-space:nowrap">
            <?php if ($lcid && $legCount && $hasCourse): ?>
              <form method="post" style="display:inline;margin:0" onsubmit="return confirm('Create course enrolments for this cohort\'s legacy class roster? Additive and idempotent.');">
                <?= mam_csrf_field() ?>
                <input type="hidden" name="action" value="cohort_converge">
                <input type="hidden" name="cohort_id" value="<?= $cid ?>">
                <button class="btn ghost tiny" type="submit" title="Converge legacy roster"><i class="fa-solid fa-code-merge" aria-hidden="true"></i></button>
              </form>
            <?php endif; ?>
            <button class="btn ghost tiny" onclick="openBulkScheduler(<?= $cid ?>, <?= htmlspecialchars(json_encode($co['title'] ?: 'Cohort #'.$cid)) ?>)" title="Generate sessions for a term"><i class="fa-solid fa-wand-magic-sparkles"></i></button>
            <a class="btn ghost tiny" href="?v=courseenroll&edit_cohort=<?= $cid ?>" title="Edit cohort"><i class="fa-solid fa-pen-to-square"></i></a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p style="padding:4px 20px 16px;color:var(--muted);font-size:0.84rem;line-height:1.6">Course students come from course registrations (below); class enrolments are the legacy per-class roster (<a href="?v=enrollments" style="color:var(--gold)">Enrollments</a>). <strong>Converge</strong> enrols a backfilled cohort's legacy class roster into course records. <strong><i class="fa-solid fa-wand-magic-sparkles"></i></strong> generates a full term's sessions in one step.</p>
  </div>

  <!-- Bulk session scheduler modal -->
  <div id="bulkSchedModal" style="display:none;position:fixed;inset:0;z-index:2000;background:rgba(10,7,4,.75);display:none;align-items:center;justify-content:center;padding:20px">
    <div style="background:var(--surface);border:1px solid var(--border-gold);border-radius:18px;padding:28px 26px;max-width:520px;width:100%;box-shadow:0 30px 80px rgba(0,0,0,.5)">
      <h3 style="margin:0 0 4px;color:var(--ink)"><i class="fa-solid fa-wand-magic-sparkles" style="color:var(--gold)"></i> Generate Sessions</h3>
      <p id="bulkSchedSubtitle" style="color:var(--muted);font-size:.83rem;margin:0 0 18px"></p>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <label style="display:flex;flex-direction:column;gap:4px;font-size:.8rem;font-weight:600">From<input type="date" id="bsStart" style="padding:8px;border-radius:7px;border:1px solid var(--border)"></label>
        <label style="display:flex;flex-direction:column;gap:4px;font-size:.8rem;font-weight:600">To<input type="date" id="bsEnd" style="padding:8px;border-radius:7px;border:1px solid var(--border)"></label>
        <label style="display:flex;flex-direction:column;gap:4px;font-size:.8rem;font-weight:600">Time<input type="time" id="bsTime" value="09:00" style="padding:8px;border-radius:7px;border:1px solid var(--border)"></label>
        <label style="display:flex;flex-direction:column;gap:4px;font-size:.8rem;font-weight:600">Duration (min)<input type="number" id="bsDur" value="60" min="15" max="480" style="padding:8px;border-radius:7px;border:1px solid var(--border)"></label>
        <label style="display:flex;flex-direction:column;gap:4px;font-size:.8rem;font-weight:600;grid-column:1/-1">Title prefix <span style="font-weight:400;color:var(--muted)">(e.g. "Week" → "Week 1", "Week 2"…)</span><input type="text" id="bsPrefix" placeholder="Session" style="padding:8px;border-radius:7px;border:1px solid var(--border)"></label>
        <label style="display:flex;flex-direction:column;gap:4px;font-size:.8rem;font-weight:600;grid-column:1/-1">Room / location<input type="text" id="bsRoom" placeholder="Studio A, Room 101…" style="padding:8px;border-radius:7px;border:1px solid var(--border)"></label>
        <div style="grid-column:1/-1">
          <div style="font-size:.8rem;font-weight:600;margin-bottom:6px">Days of week</div>
          <div id="bsDays" style="display:flex;gap:6px;flex-wrap:wrap">
            <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $di => $dl): ?>
              <label style="display:flex;align-items:center;gap:4px;font-size:.8rem;cursor:pointer">
                <input type="checkbox" name="bsDay" value="<?= $di ?>" <?= in_array($di, [1, 3]) ? 'checked' : '' ?>><?= htmlspecialchars($dl) ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
        <label style="display:flex;flex-direction:column;gap:4px;font-size:.8rem;font-weight:600">Kind
          <select id="bsKind" style="padding:8px;border-radius:7px;border:1px solid var(--border)">
            <option value="class">Class</option><option value="revision">Revision</option><option value="exam">Exam</option><option value="makeup">Make-up</option>
          </select>
        </label>
        <label style="display:flex;flex-direction:column;gap:4px;font-size:.8rem;font-weight:600">Skip dates <span style="font-weight:400;color:var(--muted)">(comma-separated Y-m-d)</span><input type="text" id="bsSkip" placeholder="2026-12-25, 2027-01-01" style="padding:8px;border-radius:7px;border:1px solid var(--border)"></label>
      </div>
      <div id="bsPreview" style="margin:14px 0 0;font-size:.8rem;color:var(--muted)"></div>
      <div style="display:flex;gap:10px;margin-top:18px">
        <button class="btn" onclick="runBulkPreview()" style="background:var(--raised);color:var(--ink)"><i class="fa-solid fa-eye"></i> Preview</button>
        <button class="btn btn-primary" onclick="runBulkCreate()" id="bsCreateBtn"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate</button>
        <button class="btn ghost" onclick="closeBulkScheduler()" style="margin-left:auto">Cancel</button>
      </div>
    </div>
  </div>
  <script>
  (function(){
    var _cohortId = null;
    window.openBulkScheduler = function(cid, title) {
      _cohortId = cid;
      document.getElementById('bulkSchedSubtitle').textContent = title;
      document.getElementById('bulkSchedModal').style.display = 'flex';
      // default dates: today to +12 weeks
      var d = new Date(); document.getElementById('bsStart').value = d.toISOString().slice(0,10);
      d.setDate(d.getDate() + 84); document.getElementById('bsEnd').value = d.toISOString().slice(0,10);
      document.getElementById('bsPreview').textContent = '';
    };
    window.closeBulkScheduler = function() { document.getElementById('bulkSchedModal').style.display = 'none'; };
    function getBulkPayload() {
      var days = [];
      document.querySelectorAll('input[name="bsDay"]:checked').forEach(function(cb){ days.push(parseInt(cb.value, 10)); });
      var skip = (document.getElementById('bsSkip').value || '').split(',').map(function(s){return s.trim();}).filter(Boolean);
      return {
        cohort_id: _cohortId, start_date: document.getElementById('bsStart').value,
        end_date: document.getElementById('bsEnd').value, day_of_week: days,
        time_of_day: document.getElementById('bsTime').value, duration_min: parseInt(document.getElementById('bsDur').value, 10) || 60,
        kind: document.getElementById('bsKind').value, title_prefix: document.getElementById('bsPrefix').value.trim(),
        room: document.getElementById('bsRoom').value.trim(), skip_dates: skip,
      };
    }
    window.runBulkPreview = function() {
      var prev = document.getElementById('bsPreview');
      prev.textContent = 'Computing…';
      var payload = getBulkPayload(); payload.action = 'preview';
      var t = document.querySelector('meta[name=csrf-token]') ? document.querySelector('meta[name=csrf-token]').content : '';
      fetch('/api/instructor/sessions', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': t },
        body: JSON.stringify(payload) })
        .then(function(r){ return r.json(); }).then(function(j){
          if (j.ok) prev.textContent = j.count + ' session' + (j.count === 1 ? '' : 's') + ' would be created: ' + (j.dates || []).slice(0, 5).join(', ') + (j.count > 5 ? '…' : '');
          else prev.textContent = 'Error: ' + (j.error || 'unknown');
        }).catch(function(){ prev.textContent = 'Preview failed.'; });
    };
    window.runBulkCreate = function() {
      if (!confirm('Generate sessions for this cohort? This cannot be undone in bulk.')) return;
      var btn = document.getElementById('bsCreateBtn');
      btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Generating…';
      var payload = getBulkPayload(); payload.action = 'bulk_create';
      var t = document.querySelector('meta[name=csrf-token]') ? document.querySelector('meta[name=csrf-token]').content : '';
      fetch('/api/instructor/sessions', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': t },
        body: JSON.stringify(payload) })
        .then(function(r){ return r.json(); }).then(function(j){
          btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Generate';
          if (j.ok) { closeBulkScheduler(); window.location = '?v=schedule'; }
          else alert('Error: ' + (j.error || j.detail || 'unknown'));
        }).catch(function(){ btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Generate'; alert('Request failed.'); });
    };
    document.getElementById('bulkSchedModal').addEventListener('click', function(e){ if(e.target===this) closeBulkScheduler(); });
  })();
  </script>
  <?php endif; ?>
  <?php
    $autoApprove = (int)mam_setting_num('lms_auto_approve', 0) === 1;
    $instructors = mam_lms_instructors();
    // Per-instructor live load (active + pending) so the admin can assign by
    // capacity rather than guessing.
    $loadMap = [];
    foreach (mam_admin_rows("SELECT instructor_id, COUNT(*) AS n FROM course_enrollments WHERE instructor_id IS NOT NULL AND status IN ('active','pending') GROUP BY instructor_id") as $lr) {
        $loadMap[(int)$lr['instructor_id']] = (int)$lr['n'];
    }
    // Focus filter: all | pending (awaiting approval) | unassigned (active but
    // no instructor — the real gap to close).
    $ceFilter = in_array(($_GET['ce'] ?? 'all'), ['all', 'pending', 'unassigned'], true) ? $_GET['ce'] : 'all';
    $where = '';
    if ($ceFilter === 'pending')         $where = "WHERE ce.status = 'pending'";
    elseif ($ceFilter === 'unassigned')  $where = "WHERE ce.instructor_id IS NULL AND ce.status IN ('active','pending')";
    // Try with grade columns first; fall back silently if results table not yet created.
    $cesCore = "SELECT ce.id, ce.status, ce.created_at, ce.instructor_id,
              c.name AS course_name, c.kind AS course_kind,
              l.code AS level_code, l.name AS level_name, p.name AS program_name,
              su.name AS student_name, su.email AS student_email";
    $cesJoin = "FROM course_enrollments ce
         JOIN courses c ON c.id = ce.course_id
         LEFT JOIN levels l ON l.id = ce.level_id
         LEFT JOIN programs p ON p.id = ce.program_id
         JOIN users su ON su.id = ce.student_id";
    $cesTail = "$where ORDER BY (ce.status = 'pending') DESC, (ce.instructor_id IS NULL) DESC, ce.created_at DESC LIMIT 400";
    try {
        $ces = mam_db_all("$cesCore, r.grade AS result_grade, r.outcome AS result_outcome,
              (SELECT cr.reference FROM certificates cr
                 WHERE cr.student_id = ce.student_id AND cr.program_id = ce.program_id
                   AND (cr.level_id = ce.level_id OR (cr.level_id IS NULL AND ce.level_id IS NULL))
                 LIMIT 1) AS cert_ref
         $cesJoin LEFT JOIN results r ON r.enrollment_id = ce.id $cesTail");
    } catch (\Throwable $e) {
        // results or certificates table missing — return core columns only
        $ces = mam_admin_rows("$cesCore, NULL AS result_grade, NULL AS result_outcome, NULL AS cert_ref $cesJoin $cesTail");
    }
    $pendingCount    = (int)mam_admin_scalar("SELECT COUNT(*) FROM course_enrollments WHERE status='pending'");
    $unassignedCount = (int)mam_admin_scalar("SELECT COUNT(*) FROM course_enrollments WHERE instructor_id IS NULL AND status IN ('active','pending')");
  ?>
  <div class="card">
    <h3>Approval policy</h3>
    <p style="padding:0 20px 12px;color:var(--muted);font-size:0.86rem;line-height:1.6">
      Course additions are currently <strong style="color:var(--gold)"><?= $autoApprove ? 'auto-approved' : 'manually reviewed' ?></strong>.
      Toggle <code>lms_auto_approve</code> in <a href="?v=settings" style="color:var(--gold)">Pricing &amp; Settings</a>.
      <?php if ($pendingCount): ?><br><strong style="color:var(--gold)"><?= $pendingCount ?></strong> registration<?= $pendingCount === 1 ? '' : 's' ?> awaiting review.<?php endif; ?>
    </p>
    <?php
      // Count legacy class enrollments that aren't yet linked to course_enrollments
      $unlinkedLegacy = mam_admin_scalar(
          "SELECT COUNT(*) FROM enrollments e
             JOIN classes cl ON cl.id = e.class_id
            WHERE e.status = 'active' AND cl.discipline IS NOT NULL AND cl.discipline <> ''
              AND NOT EXISTS (
                SELECT 1 FROM course_enrollments ce
                  JOIN courses c ON c.id = ce.course_id
                 WHERE ce.student_id = e.student_id AND c.code = cl.discipline AND ce.status IN ('pending','active')
              )"
      );
    ?>
    <?php if ($unlinkedLegacy > 0): ?>
    <div style="padding:0 20px 16px">
      <p style="color:var(--muted);font-size:.85rem;margin:0 0 10px"><strong style="color:var(--gold)"><?= (int)$unlinkedLegacy ?></strong> legacy class enrollment<?= $unlinkedLegacy !== 1 ? 's' : '' ?> not yet linked to course registrations. Sync them so they appear here and in the gradebook.</p>
      <form method="post" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="bridge_legacy_enrollments">
        <button class="btn" type="submit" onclick="return confirm('Sync <?= (int)$unlinkedLegacy ?> legacy enrollment<?= $unlinkedLegacy !== 1 ? 's' : '' ?> into course registrations?')">Sync legacy class enrollments →</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
  <div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:0 4px 6px">
      <h3 style="margin:0">Course registrations</h3>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <?php foreach ([['all','All'],['pending','Pending'.($pendingCount?' ('.$pendingCount.')':'')],['unassigned','Needs instructor'.($unassignedCount?' ('.$unassignedCount.')':'')]] as $f): ?>
          <a class="btn <?= $ceFilter === $f[0] ? '' : 'ghost' ?> tiny" href="?v=courseenroll&ce=<?= $f[0] ?>"><?= htmlspecialchars($f[1]) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
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
            <?php if (empty($r['instructor_id']) && in_array((string)$r['status'], ['active','pending'], true)): ?><br><span class="pill cancelled" style="font-size:0.7rem;margin-top:3px;display:inline-block"><i class="fa-solid fa-user-slash" aria-hidden="true"></i> needs instructor</span><?php endif; ?>
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
                <?php foreach ($instructors as $ins): $ld = $loadMap[(int)$ins['id']] ?? 0; ?>
                  <option value="<?= (int)$ins['id'] ?>" <?= (int)$ins['id'] === (int)$r['instructor_id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)($ins['name'] ?: $ins['email'])) ?><?= $ld ? ' · ' . $ld . ' student' . ($ld === 1 ? '' : 's') : ' · free' ?></option>
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

<?php elseif ($view === 'programs'): ?>
  <?php
    $allProgs     = mam_lms_all_programs_with_courses();
    $allCourses   = mam_db_all('SELECT * FROM courses ORDER BY sort_order, name');
    $focusProg    = (int)($_GET['prog'] ?? 0);
    $certOptions  = ['participation' => 'Certificate of Participation', 'completion' => 'Certificate of Completion', 'internal' => 'Internal Certification', 'internal_diploma' => 'Diploma & Internal Cert'];
    $levelOptions = ['precert' => 'Pre-Cert', 'cert' => 'Certificate', 'prediploma' => 'Pre-Diploma', 'diploma' => 'Diploma'];
    $kindOptions  = ['core' => 'Core', 'elective' => 'Elective'];
    // Pre-compute total authored weeks per programme (sum of module_count across all linked courses)
    $progStats = [];
    foreach ($allProgs as $pg) {
        $authored = array_sum(array_column($pg['courses'], 'module_count'));
        $possible = max(1, (int)$pg['weeks']) * max(1, count($pg['courses']));
        $pct = $possible > 0 ? min(100, round($authored / $possible * 100)) : 0;
        $progStats[(int)$pg['id']] = ['authored' => $authored, 'possible' => $possible, 'pct' => $pct, 'course_count' => count($pg['courses'])];
    }
  ?>
  <style>
  /* ── Programme Catalogue styles ── */
  .prog-tabs{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:24px}
  .prog-tab{display:inline-flex;align-items:center;gap:7px;padding:8px 14px;border-radius:10px;font-size:.84rem;font-weight:600;border:1px solid var(--border);color:var(--muted);cursor:pointer;transition:all .15s;text-decoration:none}
  .prog-tab:hover{border-color:var(--gold);color:var(--text)}
  .prog-tab.active{background:linear-gradient(135deg,rgba(232,150,31,.2),rgba(252,198,71,.06));color:var(--goldhi);border-color:var(--border-strong)}
  .prog-tab .prog-tab-n{background:rgba(232,150,31,.15);color:var(--gold);font-size:.72rem;padding:1px 6px;border-radius:6px}
  .prog-card{background:var(--panel);border:1px solid var(--border);border-radius:var(--r-l);margin-bottom:20px;overflow:hidden}
  .prog-card-head{padding:18px 22px 14px;border-bottom:1px solid var(--border)}
  .prog-card-title-row{display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:12px}
  .prog-card-name{font-size:1.08rem;font-weight:700;flex:1;min-width:180px}
  .prog-card-meta{font-size:.78rem;color:var(--muted);margin-top:2px}
  .prog-actions{display:flex;gap:7px;flex-wrap:wrap;flex-shrink:0}
  .prog-progress{display:flex;align-items:center;gap:10px}
  .prog-progress-bar{flex:1;height:5px;background:rgba(255,255,255,.1);border-radius:3px;max-width:200px;min-width:80px}
  .prog-progress-fill{height:100%;background:linear-gradient(90deg,var(--gold),var(--gold-2));border-radius:3px;transition:width .4s ease}
  .prog-progress-label{font-size:.75rem;color:var(--muted);white-space:nowrap}
  .prog-course-table td,.prog-course-table th{padding:10px 14px;font-size:.84rem}
  .prog-course-table th{font-size:.68rem}
  .prog-week-bar{display:inline-flex;gap:2px;align-items:center}
  .prog-week-pip{width:7px;height:7px;border-radius:2px;background:var(--border)}
  .prog-week-pip.done{background:var(--gold)}
  .course-kind-core{display:inline-block;font-size:.68rem;padding:2px 7px;border-radius:8px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;background:#FEF3C7;color:#92400E}
  .course-kind-elec{display:inline-block;font-size:.68rem;padding:2px 7px;border-radius:8px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;background:#E0F2FE;color:#075985}
  .prog-empty-courses{padding:20px 22px;color:var(--muted);font-style:italic;font-size:.88rem;text-align:center;border-top:1px solid var(--border)}
  .prog-add-course{padding:14px 22px;border-top:1px solid var(--border);background:rgba(0,0,0,.1)}
  .prog-edit-form{padding:18px 22px;background:rgba(0,0,0,.15);border-top:1px solid var(--border)}
  .prog-edit-form .fg{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;align-items:end}
  /* ── Toast ── */
  .toast{position:fixed;bottom:28px;right:28px;z-index:9999;padding:14px 20px;border-radius:12px;font-size:.88rem;font-weight:600;max-width:360px;box-shadow:0 8px 32px rgba(0,0,0,.4);animation:toastIn .25s ease forwards}
  .toast.ok{background:rgba(16,185,129,.18);border:1px solid rgba(52,211,153,.55);color:#A7F3D0}
  .toast.err{background:rgba(244,63,94,.18);border:1px solid rgba(251,113,133,.55);color:#FECDD3}
  @keyframes toastIn{from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1}}
  /* ── Course catalogue search ── */
  .course-search-wrap{padding:14px 20px 0}
  #courseSearch{width:100%;max-width:340px;padding:8px 12px;background:var(--raised);border:1px solid var(--border);border-radius:9px;color:var(--text);font:inherit;font-size:.88rem}
  #courseSearch:focus{outline:none;border-color:var(--gold)}
  .course-catalogue-row.hidden-search{display:none}
  .no-search-results{display:none;padding:24px;text-align:center;color:var(--muted);font-style:italic;font-size:.88rem}
  </style>

  <header class="top">
    <h1>Programme Catalogue</h1>
    <span class="muted" style="font-size:.82rem">Manage programmes, course assignments &amp; PDF exports</span>
  </header>

  <?php /* ── Auto-dismiss toasts ── */ ?>
  <?php if (isset($_GET['ok'])): ?>
  <div class="toast ok" id="flashToast"><i class="fa-solid fa-check-circle" aria-hidden="true"></i> <?= htmlspecialchars($_GET['ok'] === '1' ? 'Saved.' : (string)$_GET['ok']) ?></div>
  <?php elseif (isset($_GET['err'])): ?>
  <div class="toast err" id="flashToast"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i> <?= htmlspecialchars((string)$_GET['err']) ?></div>
  <?php endif; ?>
  <script>
  (function(){var t=document.getElementById('flashToast');if(!t)return;setTimeout(function(){t.style.transition='opacity .4s';t.style.opacity='0';setTimeout(function(){t.remove();},420);},4000);})();
  </script>

  <?php /* ── Programme tabs ── */ ?>
  <div class="prog-tabs">
    <a class="prog-tab <?= $focusProg === 0 ? 'active' : '' ?>" href="?v=programs">All <span class="prog-tab-n"><?= count($allProgs) ?></span></a>
    <?php foreach ($allProgs as $pg): $ps = $progStats[(int)$pg['id']] ?? []; ?>
      <a class="prog-tab <?= $focusProg === (int)$pg['id'] ? 'active' : '' ?>" href="?v=programs&prog=<?= (int)$pg['id'] ?>">
        <?= htmlspecialchars((string)$pg['name']) ?>
        <?php if (!$pg['active']): ?><span style="opacity:.45;font-size:.7em">(off)</span><?php endif; ?>
        <?php if (!empty($ps['course_count'])): ?><span class="prog-tab-n"><?= (int)$ps['course_count'] ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <?php foreach ($allProgs as $pg):
    if ($focusProg && (int)$pg['id'] !== $focusProg) continue;
    $pgCourseIds = array_column($pg['courses'], 'course_id');
    $ps = $progStats[(int)$pg['id']] ?? ['authored' => 0, 'possible' => 0, 'pct' => 0, 'course_count' => 0];
    $unlinked = array_values(array_filter($allCourses, fn($c) => !in_array((int)$c['id'], $pgCourseIds, true)));
  ?>
  <div class="prog-card">
    <div class="prog-card-head">
      <div class="prog-card-title-row">
        <div style="flex:1;min-width:180px">
          <div class="prog-card-name">
            <?= htmlspecialchars((string)$pg['name']) ?>
            <?php if (!$pg['active']): ?><span class="pill cancelled" style="font-size:.62rem;vertical-align:middle;margin-left:6px">inactive</span><?php endif; ?>
          </div>
          <div class="prog-card-meta">
            <span><?= htmlspecialchars(strtoupper((string)$pg['code'])) ?></span> ·
            <span><?= (int)$pg['weeks'] ?> weeks</span> ·
            <span><?= htmlspecialchars($certOptions[$pg['certification'] ?? ''] ?? (string)($pg['certification'] ?? '')) ?></span>
            <?php if ($pg['requires_core']): ?> · <span>Core required</span><?php endif; ?>
          </div>
        </div>
        <div class="prog-actions">
          <a class="tiny btn ghost" href="?v=export&type=curriculum.pdf&program=<?= urlencode((string)$pg['code']) ?>" target="_blank" title="Download PDF curriculum"><i class="fa-solid fa-file-pdf" aria-hidden="true"></i> PDF</a>
          <a class="tiny btn ghost" href="?v=modules&program=<?= urlencode((string)$pg['code']) ?>" title="Author weekly modules"><i class="fa-solid fa-book-open" aria-hidden="true"></i> Author</a>
          <button class="tiny btn ghost" onclick="var e=document.getElementById('prog-edit-<?= (int)$pg['id'] ?>');e.hidden=!e.hidden" type="button"><i class="fa-solid fa-pencil" aria-hidden="true"></i> Edit</button>
        </div>
      </div>
      <?php if ($ps['possible'] > 0): ?>
      <div class="prog-progress">
        <div class="prog-progress-bar"><div class="prog-progress-fill" style="width:<?= $ps['pct'] ?>%"></div></div>
        <span class="prog-progress-label"><?= $ps['authored'] ?> of <?= $ps['possible'] ?> module weeks authored (<?= $ps['pct'] ?>%)</span>
      </div>
      <?php endif; ?>
    </div>

    <?php /* ── Edit form (hidden by default) ── */ ?>
    <div id="prog-edit-<?= (int)$pg['id'] ?>" hidden class="prog-edit-form">
      <form method="post" class="fg">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="program_update">
        <input type="hidden" name="program_id" value="<?= (int)$pg['id'] ?>">
        <label class="form-grid" style="grid-column:1/-1;padding:0;display:block">
          <span class="muted" style="font-size:.72rem;letter-spacing:.06em;text-transform:uppercase;font-weight:600">Name</span>
          <input name="name" value="<?= htmlspecialchars((string)$pg['name']) ?>" required style="width:100%;margin-top:4px">
        </label>
        <label><span class="muted" style="font-size:.72rem;letter-spacing:.06em;text-transform:uppercase;font-weight:600;display:block;margin-bottom:4px">Total weeks</span>
          <input type="number" name="weeks" value="<?= (int)$pg['weeks'] ?>" min="1" max="104"></label>
        <label><span class="muted" style="font-size:.72rem;letter-spacing:.06em;text-transform:uppercase;font-weight:600;display:block;margin-bottom:4px">Revision week</span>
          <input type="number" name="revision_week" value="<?= (int)($pg['revision_week'] ?? 0) ?: '' ?>" min="1" placeholder="none"></label>
        <label><span class="muted" style="font-size:.72rem;letter-spacing:.06em;text-transform:uppercase;font-weight:600;display:block;margin-bottom:4px">Exam week</span>
          <input type="number" name="exam_week" value="<?= (int)($pg['exam_week'] ?? 0) ?: '' ?>" min="1" placeholder="none"></label>
        <label><span class="muted" style="font-size:.72rem;letter-spacing:.06em;text-transform:uppercase;font-weight:600;display:block;margin-bottom:4px">Certification</span>
          <select name="certification">
            <?php foreach ($certOptions as $cv => $cl): ?><option value="<?= $cv ?>" <?= ($pg['certification'] ?? '') === $cv ? 'selected' : '' ?>><?= htmlspecialchars($cl) ?></option><?php endforeach; ?>
          </select></label>
        <label><span class="muted" style="font-size:.72rem;letter-spacing:.06em;text-transform:uppercase;font-weight:600;display:block;margin-bottom:4px">Sort order</span>
          <input type="number" name="sort_order" value="<?= (int)$pg['sort_order'] ?>"></label>
        <label style="display:flex;align-items:center;gap:7px;align-self:flex-end;padding-bottom:10px">
          <input type="checkbox" name="requires_core" value="1" <?= !empty($pg['requires_core']) ? 'checked' : '' ?>>
          <span style="font-size:.88rem">Requires core courses</span>
        </label>
        <div style="display:flex;gap:8px;align-items:flex-end;padding-bottom:0">
          <button class="btn" type="submit" style="align-self:flex-end">Save changes</button>
          <button class="btn ghost" type="button" style="align-self:flex-end" onclick="document.getElementById('prog-edit-<?= (int)$pg['id'] ?>').hidden=true">Cancel</button>
        </div>
      </form>
    </div>

    <?php /* ── Linked courses ── */ ?>
    <?php if (empty($pg['courses'])): ?>
      <div class="prog-empty-courses">
        <i class="fa-solid fa-book-open" aria-hidden="true" style="opacity:.4;display:block;font-size:1.4rem;margin-bottom:8px"></i>
        No courses linked yet — use the form below to add your first course.
      </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="prog-course-table" style="width:100%;border-collapse:collapse">
      <thead>
        <tr style="border-bottom:1px solid var(--border)">
          <th style="text-align:left;padding:10px 22px 10px 22px;color:var(--muted);font-size:.68rem;letter-spacing:.08em;text-transform:uppercase;font-weight:700">Course</th>
          <th style="text-align:left;padding:10px 14px;color:var(--muted);font-size:.68rem;letter-spacing:.08em;text-transform:uppercase;font-weight:700">Kind</th>
          <th style="text-align:left;padding:10px 14px;color:var(--muted);font-size:.68rem;letter-spacing:.08em;text-transform:uppercase;font-weight:700">Level</th>
          <th style="text-align:left;padding:10px 14px;color:var(--muted);font-size:.68rem;letter-spacing:.08em;text-transform:uppercase;font-weight:700">Progress</th>
          <th style="padding:10px 22px 10px 14px"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($pg['courses'] as $pc):
        $mcnt = (int)($pc['module_count'] ?? 0);
        $mmax = max(1, (int)$pg['weeks']);
        $mpct = min(100, round($mcnt / $mmax * 100));
        $pips = min(12, $mmax);
        $done = round($mcnt / $mmax * $pips);
      ?>
        <tr style="border-bottom:1px solid var(--border)">
          <td style="padding:12px 22px">
            <strong><?= htmlspecialchars((string)($pc['name'] ?? '')) ?></strong>
            <span class="muted" style="font-size:.76rem;margin-left:6px"><?= htmlspecialchars((string)($pc['code'] ?? '')) ?></span>
          </td>
          <td style="padding:12px 14px">
            <span class="<?= ($pc['kind'] ?? '') === 'core' ? 'course-kind-core' : 'course-kind-elec' ?>"><?= htmlspecialchars((string)($pc['kind'] ?? 'elective')) ?></span>
          </td>
          <td style="padding:12px 14px;color:var(--muted);font-size:.84rem">
            <?= htmlspecialchars($levelOptions[$pc['level_code'] ?? ''] ?? (empty($pc['level_code']) ? 'Any' : (string)$pc['level_code'])) ?>
          </td>
          <td style="padding:12px 14px">
            <div style="display:flex;align-items:center;gap:8px">
              <div class="prog-week-bar">
                <?php for ($p = 0; $p < $pips; $p++): ?><span class="prog-week-pip <?= $p < $done ? 'done' : '' ?>"></span><?php endfor; ?>
              </div>
              <span class="muted" style="font-size:.75rem;white-space:nowrap"><?= $mcnt ?>/<?= $mmax ?> wks</span>
            </div>
          </td>
          <td style="padding:12px 22px 12px 14px;white-space:nowrap;text-align:right">
            <a class="tiny btn ghost" href="?v=modules&course=<?= urlencode((string)($pc['code'] ?? '')) ?>&program=<?= urlencode((string)$pg['code']) ?>">Edit modules</a>
            <form method="post" class="inline" style="margin-left:6px" onsubmit="return confirm('Remove <?= addslashes(htmlspecialchars((string)($pc['name'] ?? 'this course'))) ?> from <?= addslashes(htmlspecialchars((string)$pg['name'])) ?>?')">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="unlink_course">
              <input type="hidden" name="course_id" value="<?= (int)($pc['course_id'] ?? 0) ?>">
              <input type="hidden" name="program_id" value="<?= (int)$pg['id'] ?>">
              <button class="tiny btn ghost" type="submit" style="color:var(--red)">Remove</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php /* ── Add course ── */ ?>
    <?php if ($unlinked): ?>
    <div class="prog-add-course">
      <details>
        <summary style="cursor:pointer;font-size:.84rem;color:var(--gold);font-weight:600;list-style:none;display:flex;align-items:center;gap:7px">
          <i class="fa-solid fa-plus" aria-hidden="true" style="font-size:.75rem"></i> Add course to <?= htmlspecialchars((string)$pg['name']) ?>
        </summary>
        <form method="post" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-top:14px">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="link_course">
          <input type="hidden" name="program_id" value="<?= (int)$pg['id'] ?>">
          <label style="display:flex;flex-direction:column;gap:4px;font-size:.76rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.06em">Course
            <select name="course_id" required style="min-width:200px">
              <option value="">— select —</option>
              <?php foreach ($unlinked as $uc): ?><option value="<?= (int)$uc['id'] ?>"><?= htmlspecialchars((string)$uc['name']) ?> (<?= htmlspecialchars((string)$uc['code']) ?>)</option><?php endforeach; ?>
            </select></label>
          <label style="display:flex;flex-direction:column;gap:4px;font-size:.76rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.06em">Preferred level
            <select name="level_code">
              <option value="">Any level</option>
              <?php foreach ($levelOptions as $lv => $ll): ?><option value="<?= $lv ?>"><?= htmlspecialchars($ll) ?></option><?php endforeach; ?>
            </select></label>
          <label style="display:flex;flex-direction:column;gap:4px;font-size:.76rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.06em">Sort order
            <input type="number" name="sort_order" value="99" min="0" style="width:80px"></label>
          <button class="btn" type="submit" style="align-self:flex-end">Add</button>
        </form>
      </details>
    </div>
    <?php else: ?>
      <div style="padding:12px 22px;font-size:.82rem;color:var(--muted);border-top:1px solid var(--border)">All available courses are already linked to this programme.</div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  <?php /* ── New Programme ── */ ?>
  <div class="card">
    <h3 style="display:flex;align-items:center;gap:8px"><i class="fa-solid fa-plus" aria-hidden="true" style="color:var(--gold);font-size:.85em"></i> New Programme</h3>
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="program_create">
      <label>Code <small class="muted" style="font-weight:400;letter-spacing:0;text-transform:none">(unique slug)</small>
        <input name="code" placeholder="grand" required pattern="[a-z0-9_]+" autocomplete="off"></label>
      <label class="full">Name
        <input name="name" placeholder="Grand Musicians II" required></label>
      <label>Total weeks<input type="number" name="weeks" value="12" min="1" max="104"></label>
      <label>Revision week<input type="number" name="revision_week" placeholder="none" min="1"></label>
      <label>Exam week<input type="number" name="exam_week" placeholder="none" min="1"></label>
      <label>Certification
        <select name="certification">
          <?php foreach ($certOptions as $cv => $cl): ?><option value="<?= $cv ?>" <?= $cv === 'completion' ? 'selected' : '' ?>><?= htmlspecialchars($cl) ?></option><?php endforeach; ?>
        </select></label>
      <label>Sort order<input type="number" name="sort_order" value="99"></label>
      <label style="flex-direction:row;align-items:center;gap:8px;align-self:flex-end;padding-bottom:10px">
        <input type="checkbox" name="requires_core" value="1" style="width:auto;min-height:0">
        <span style="font-size:.88rem;font-weight:500;text-transform:none;letter-spacing:0;color:var(--text)">Requires core courses</span>
      </label>
      <div class="actions"><button class="btn" type="submit"><i class="fa-solid fa-plus" aria-hidden="true"></i> Create Programme</button></div>
    </form>
  </div>

  <?php /* ── Course Catalogue ── */ ?>
  <div class="card">
    <h3>Course Catalogue
      <button class="tiny btn ghost" onclick="var f=document.getElementById('new-course-form');f.hidden=!f.hidden" type="button" style="font-size:.78rem"><i class="fa-solid fa-plus" aria-hidden="true"></i> New course</button>
    </h3>

    <div id="new-course-form" hidden style="padding:18px 20px;border-bottom:1px solid var(--border);background:rgba(0,0,0,.12)">
      <h4 style="font-size:.9rem;margin-bottom:14px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.07em">New course</h4>
      <form method="post" class="form-grid" style="padding:0">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="course_create">
        <label>Code <small class="muted" style="font-weight:400;letter-spacing:0;text-transform:none">(e.g. piano)</small>
          <input name="code" placeholder="piano" required pattern="[a-z0-9_]+" autocomplete="off"></label>
        <label class="full">Name<input name="name" placeholder="Piano" required></label>
        <label>Kind<select name="kind"><option value="elective">Elective</option><option value="core">Core</option></select></label>
        <label>Sort order<input type="number" name="sort_order" value="99"></label>
        <label class="full">Description <small class="muted" style="font-weight:400;letter-spacing:0;text-transform:none">(optional)</small>
          <input name="description" placeholder="Short description…"></label>
        <div class="actions"><button class="btn" type="submit"><i class="fa-solid fa-plus" aria-hidden="true"></i> Create</button></div>
      </form>
    </div>

    <div class="course-search-wrap">
      <input type="search" id="courseSearch" placeholder="Filter courses by name or code…" autocomplete="off" aria-label="Search courses">
    </div>

    <table style="width:100%;border-collapse:collapse;font-size:.86rem">
      <thead>
        <tr style="border-bottom:1px solid var(--border)">
          <th style="text-align:left;padding:10px 20px;color:var(--muted);font-size:.68rem;letter-spacing:.08em;text-transform:uppercase;font-weight:700">Course</th>
          <th style="text-align:left;padding:10px 14px;color:var(--muted);font-size:.68rem;letter-spacing:.08em;text-transform:uppercase;font-weight:700">Kind</th>
          <th style="text-align:left;padding:10px 14px;color:var(--muted);font-size:.68rem;letter-spacing:.08em;text-transform:uppercase;font-weight:700">Programmes</th>
          <th style="text-align:left;padding:10px 14px;color:var(--muted);font-size:.68rem;letter-spacing:.08em;text-transform:uppercase;font-weight:700">Status</th>
          <th style="padding:10px 20px 10px 14px"></th>
        </tr>
      </thead>
      <tbody id="courseTableBody">
      <?php foreach ($allCourses as $co):
        $inProgs = array_filter($allProgs, fn($pg) => in_array((int)$co['id'], array_column($pg['courses'], 'course_id'), true));
        $searchVal = strtolower((string)$co['name'] . ' ' . (string)$co['code'] . ' ' . (string)($co['description'] ?? ''));
      ?>
        <tr class="course-catalogue-row" data-search="<?= htmlspecialchars($searchVal) ?>" style="border-bottom:1px solid var(--border)">
          <td style="padding:12px 20px">
            <strong><?= htmlspecialchars((string)$co['name']) ?></strong>
            <span class="muted" style="font-size:.76rem;margin-left:6px"><?= htmlspecialchars((string)$co['code']) ?></span>
            <?php if (!empty($co['description'])): ?><br><span class="muted" style="font-size:.76rem"><?= htmlspecialchars(substr((string)$co['description'], 0, 90)) ?></span><?php endif; ?>
          </td>
          <td style="padding:12px 14px">
            <span class="<?= ($co['kind'] ?? '') === 'core' ? 'course-kind-core' : 'course-kind-elec' ?>"><?= htmlspecialchars((string)($co['kind'] ?? 'elective')) ?></span>
          </td>
          <td style="padding:12px 14px;font-size:.82rem;color:var(--muted)">
            <?php if ($inProgs): ?>
              <?= implode('<br>', array_map(fn($p) => '<span style="white-space:nowrap">' . htmlspecialchars((string)$p['name']) . '</span>', $inProgs)) ?>
            <?php else: ?><span style="color:var(--faint);font-style:italic">Not linked</span><?php endif; ?>
          </td>
          <td style="padding:12px 14px">
            <?php if ($co['active']): ?><span class="pill active">Active</span><?php else: ?><span class="pill cancelled">Inactive</span><?php endif; ?>
          </td>
          <td style="padding:12px 20px 12px 14px;white-space:nowrap;text-align:right">
            <button class="tiny btn ghost" onclick="var r=document.getElementById('ce-<?= (int)$co['id'] ?>');r.hidden=!r.hidden" type="button">Edit</button>
            <form method="post" class="inline" style="margin:0 4px">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="course_toggle">
              <input type="hidden" name="course_id" value="<?= (int)$co['id'] ?>">
              <button class="tiny btn ghost" type="submit"><?= $co['active'] ? 'Deactivate' : 'Activate' ?></button>
            </form>
            <a class="tiny btn ghost" href="?v=modules&course=<?= urlencode((string)$co['code']) ?>">Modules</a>
          </td>
        </tr>
        <tr id="ce-<?= (int)$co['id'] ?>" hidden style="background:rgba(0,0,0,.12)">
          <td colspan="5" style="padding:0">
            <form method="post" class="form-grid" style="padding:16px 20px">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="course_update">
              <input type="hidden" name="course_id" value="<?= (int)$co['id'] ?>">
              <label class="full">Name<input name="name" value="<?= htmlspecialchars((string)$co['name']) ?>" required></label>
              <label>Kind<select name="kind">
                <?php foreach ($kindOptions as $kv => $kl): ?><option value="<?= $kv ?>" <?= ($co['kind'] ?? '') === $kv ? 'selected' : '' ?>><?= $kl ?></option><?php endforeach; ?>
              </select></label>
              <label>Sort order<input type="number" name="sort_order" value="<?= (int)($co['sort_order'] ?? 0) ?>"></label>
              <label class="full">Description<input name="description" value="<?= htmlspecialchars((string)($co['description'] ?? '')) ?>"></label>
              <div class="actions">
                <button class="btn ghost" type="button" onclick="document.getElementById('ce-<?= (int)$co['id'] ?>').hidden=true">Cancel</button>
                <button class="btn" type="submit">Save changes</button>
              </div>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="no-search-results" id="noSearchResults">No courses match your search.</p>
  </div>

  <script>
  (function(){
    var inp = document.getElementById('courseSearch');
    var rows = document.querySelectorAll('.course-catalogue-row');
    var none = document.getElementById('noSearchResults');
    if (!inp) return;
    inp.addEventListener('input', function() {
      var q = inp.value.toLowerCase().trim();
      var shown = 0;
      rows.forEach(function(r) {
        var match = !q || (r.dataset.search || '').indexOf(q) !== -1;
        r.classList.toggle('hidden-search', !match);
        // also hide the edit row below
        var editRow = r.nextElementSibling;
        if (editRow && editRow.id && editRow.id.startsWith('ce-')) editRow.classList.toggle('hidden-search', !match);
        if (match) shown++;
      });
      none.style.display = shown === 0 ? 'block' : 'none';
    });
  })();
  </script>

<?php elseif ($view === 'modules'): ?>
  <?php
    $cat        = mam_lms_catalog();
    $selCourse  = (string)($_GET['course'] ?? '');
    $selLevel   = (string)($_GET['level'] ?? '');
    $selProgram = (string)($_GET['program'] ?? '');
    $selProgRow = $selProgram !== '' ? mam_db_row('SELECT * FROM programs WHERE code = ?', [$selProgram]) : null;
    $selCourseRow = $selCourse !== '' ? mam_db_row('SELECT * FROM courses WHERE code = ?', [$selCourse]) : null;
  ?>
  <?php if ($selProgRow || $selCourseRow): ?>
  <div style="display:flex;align-items:center;gap:6px;font-size:.82rem;color:var(--muted);margin-bottom:14px;flex-wrap:wrap">
    <a href="?v=programs" style="color:var(--gold)"><i class="fa-solid fa-layer-group" aria-hidden="true"></i> Programme Catalogue</a>
    <?php if ($selProgRow): ?>
      <span style="opacity:.5">›</span>
      <a href="?v=programs&prog=<?= (int)$selProgRow['id'] ?>" style="color:var(--muted)"><?= htmlspecialchars((string)$selProgRow['name']) ?></a>
    <?php endif; ?>
    <?php if ($selCourseRow): ?>
      <span style="opacity:.5">›</span>
      <span style="color:var(--text)"><?= htmlspecialchars((string)$selCourseRow['name']) ?></span>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <header class="top">
    <div>
      <h1><?php if ($selCourseRow): ?><?= htmlspecialchars((string)$selCourseRow['name']) ?><?php else: ?>Standard Curriculum<?php endif; ?></h1>
      <span class="muted" style="font-size:.82rem">
        <?php if ($selProgRow && $selCourseRow): ?>
          <?= htmlspecialchars((string)$selProgRow['name']) ?> · week-by-week authoring
        <?php else: ?>Author the week-by-week syllabus per course &amp; level · scaffold · import / export<?php endif; ?>
      </span>
    </div>
    <?php if (!$selCourseRow): ?><a class="btn ghost" href="?v=programs" style="margin-left:auto"><i class="fa-solid fa-layer-group" aria-hidden="true"></i> Programme Catalogue</a><?php endif; ?>
  </header>
  <?php
    // Module edit drawer (redesign) — a focused, roomy editor for one week,
    // shown when ?module=N. The inline week list remains for quick edits.
    $drawerMid = (int)($_GET['module'] ?? 0);
    $drawerMod = $drawerMid ? mam_db_row('SELECT * FROM modules WHERE id = ?', [$drawerMid]) : null;
    $backUrl = '?v=modules&course=' . urlencode($selCourse) . '&level=' . urlencode($selLevel) . '&program=' . urlencode($selProgram);
    if ($drawerMod):
  ?>
  <div class="mam-drawer-scrim" data-param="module">
  <aside class="mam-drawer" role="dialog" aria-modal="true" aria-label="Edit module">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
      <div>
        <h2 style="margin:0 0 4px">Edit module <span class="mono muted" style="font-size:.8rem">week <?= (int)$drawerMod['week'] ?></span></h2>
        <div class="muted" style="font-size:.84rem"><?= htmlspecialchars($selCourse) ?><?= $selLevel ? ' · ' . htmlspecialchars($selLevel) : '' ?></div>
      </div>
      <a class="btn ghost" href="<?= htmlspecialchars($backUrl) ?>">✕ Close</a>
    </div>
    <form method="post" style="display:flex;flex-direction:column;gap:12px;margin-top:16px">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="module_save">
      <input type="hidden" name="module_id" value="<?= (int)$drawerMod['id'] ?>">
      <input type="hidden" name="course_code" value="<?= htmlspecialchars($selCourse) ?>">
      <input type="hidden" name="level_code" value="<?= htmlspecialchars($selLevel) ?>">
      <input type="hidden" name="program_code" value="<?= htmlspecialchars($selProgram) ?>">
      <div style="display:flex;gap:12px">
        <label style="display:flex;flex-direction:column;gap:5px;font-size:.78rem;color:var(--muted);flex:0 0 90px">Week<input type="number" name="week" value="<?= (int)$drawerMod['week'] ?>" min="1"></label>
        <label style="display:flex;flex-direction:column;gap:5px;font-size:.78rem;color:var(--muted);flex:1">Kind
          <select name="kind">
            <?php foreach (['module', 'revision', 'exam'] as $k): ?><option value="<?= $k ?>" <?= $k === $drawerMod['kind'] ? 'selected' : '' ?>><?= $k ?></option><?php endforeach; ?>
          </select>
        </label>
      </div>
      <label style="display:flex;flex-direction:column;gap:5px;font-size:.78rem;color:var(--muted)">Title<input name="title" value="<?= htmlspecialchars((string)($drawerMod['title'] ?? '')) ?>" placeholder="Module title"></label>
      <label style="display:flex;flex-direction:column;gap:5px;font-size:.78rem;color:var(--muted)">Content &amp; learning objectives<textarea name="content" rows="12" placeholder="What this week covers…"><?= htmlspecialchars((string)($drawerMod['content'] ?? '')) ?></textarea></label>
      <button class="btn" type="submit">Save module</button>
    </form>
    <form method="post" style="margin-top:14px;text-align:right">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="module_delete">
      <input type="hidden" name="module_id" value="<?= (int)$drawerMod['id'] ?>">
      <input type="hidden" name="course_code" value="<?= htmlspecialchars($selCourse) ?>">
      <input type="hidden" name="level_code" value="<?= htmlspecialchars($selLevel) ?>">
      <button class="tiny" type="submit" style="background:transparent;color:var(--red);border:1px solid rgba(240,86,126,.4)">Delete this week</button>
    </form>
  </aside>
  </div>
  <?php endif; ?>
  <?php
    // Curriculum proposal review drawer (redesign §3) — full proposal in a
    // focused slide-in, shown when ?proposal=N.
    $dpid = (int)($_GET['proposal'] ?? 0);
    $drawerProp = null;
    if ($dpid && function_exists('mam_lms_pending_proposals')) { foreach (mam_lms_pending_proposals() as $pp) { if ((int)$pp['id'] === $dpid) { $drawerProp = $pp; break; } } }
    if ($drawerProp):
  ?>
  <div class="mam-drawer-scrim" data-param="proposal">
  <aside class="mam-drawer" role="dialog" aria-modal="true" aria-label="Review curriculum proposal">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
      <div>
        <h2 style="margin:0 0 4px">Review proposal</h2>
        <div class="muted" style="font-size:.84rem">From <?= htmlspecialchars((string)($drawerProp['proposer_name'] ?? '—')) ?> · <?= htmlspecialchars(substr((string)$drawerProp['created_at'], 0, 16)) ?></div>
      </div>
      <a class="btn ghost" href="?v=modules">✕ Close</a>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin:14px 0">
      <span class="pill" style="background:var(--raised)"><?= htmlspecialchars((string)($drawerProp['course_name'] ?? ('#' . (int)$drawerProp['course_id']))) ?><?= $drawerProp['level_name'] ? ' · ' . htmlspecialchars((string)$drawerProp['level_name']) : '' ?></span>
      <span class="pill" style="background:var(--raised)">Week <?= (int)$drawerProp['week'] ?></span>
      <span class="pill"><?= htmlspecialchars((string)$drawerProp['kind']) ?></span>
    </div>
    <h3 style="margin:0 0 6px"><?= htmlspecialchars((string)$drawerProp['title']) ?></h3>
    <div style="background:var(--bg);border:1px solid var(--border);border-radius:12px;padding:14px 16px;white-space:pre-wrap;line-height:1.6;font-size:.9rem;max-height:46vh;overflow:auto"><?= htmlspecialchars((string)($drawerProp['content'] ?? '')) ?: '<span class="muted">No content provided.</span>' ?></div>
    <p class="muted" style="font-size:.82rem;margin:12px 0 4px">Approving applies this week to the live syllabus.</p>
    <form method="post" style="display:flex;flex-direction:column;gap:10px;margin-top:6px">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="module_proposal_review">
      <input type="hidden" name="proposal_id" value="<?= (int)$drawerProp['id'] ?>">
      <label style="display:flex;flex-direction:column;gap:5px;font-size:.78rem;color:var(--muted)">Review note (optional)<input type="text" name="review_note" maxlength="500" placeholder="Feedback for the proposer…"></label>
      <div style="display:flex;gap:10px">
        <button class="btn" type="submit" name="decision" value="approve" style="flex:1">Approve &amp; apply</button>
        <button class="btn ghost" type="submit" name="decision" value="reject" style="flex:1;border-color:rgba(240,86,126,.4);color:var(--red)">Reject</button>
      </div>
    </form>
  </aside>
  </div>
  <?php endif; ?>
  <?php
    if (isset($_GET['made'])) echo '<div class="banner ok">Scaffolded ' . (int)$_GET['made'] . ' week(s).</div>';
    if (isset($_GET['imp_c'])) {
        $ic = (int)$_GET['imp_c']; $iu = (int)($_GET['imp_u'] ?? 0); $is = (int)($_GET['imp_s'] ?? 0);
        echo '<div class="banner ok">Imported curriculum — <strong>' . $ic . '</strong> created, <strong>' . $iu . '</strong> updated'
           . ($is ? ', <strong>' . $is . '</strong> skipped' : '') . '.'
           . (!empty($_GET['imp_e']) ? ' <span style="opacity:.85">First issue — ' . htmlspecialchars((string)$_GET['imp_e']) . '</span>' : '')
           . '</div>';
    }
    if (isset($_GET['seed_c'])) {
        echo '<div class="banner ok">Starter curriculum loaded — <strong>' . (int)$_GET['seed_c'] . '</strong> week(s) created'
           . ((int)($_GET['seed_u'] ?? 0) ? ', <strong>' . (int)$_GET['seed_u'] . '</strong> reset' : '')
           . ' across <strong>' . (int)($_GET['seed_n'] ?? 0) . '</strong> course syllabi. Edit any week below.</div>';
    }
    $modulesAuthored = (int)mam_admin_scalar('SELECT COUNT(*) FROM modules');
    $ov = mam_lms_curriculum_overview();
    $proposals = function_exists('mam_lms_pending_proposals') ? mam_lms_pending_proposals() : [];
  ?>

  <?php if ($proposals): ?>
  <div class="card" style="border-color:var(--gold)">
    <h3>📝 Faculty curriculum proposals <span class="muted" style="font-weight:400;font-size:.8rem">— <?= count($proposals) ?> awaiting review. Approving applies the week to the live syllabus.</span></h3>
    <table>
      <thead><tr><th>Proposed by</th><th>Course / Level</th><th>Week</th><th>Title &amp; content</th><th>Decision</th></tr></thead>
      <tbody>
      <?php foreach ($proposals as $pr): ?>
        <tr>
          <td><?= htmlspecialchars((string)($pr['proposer_name'] ?? '—')) ?><br><span class="muted"><?= htmlspecialchars(substr((string)$pr['created_at'], 0, 16)) ?></span></td>
          <td><?= htmlspecialchars((string)($pr['course_name'] ?? ('#' . (int)$pr['course_id']))) ?><?= $pr['level_name'] ? ' · ' . htmlspecialchars((string)$pr['level_name']) : '' ?></td>
          <td>Wk <?= (int)$pr['week'] ?> <span class="pill"><?= htmlspecialchars((string)$pr['kind']) ?></span></td>
          <td style="max-width:340px"><strong><?= htmlspecialchars((string)$pr['title']) ?></strong><?php if (!empty($pr['content'])): ?><br><span class="muted" style="font-size:.84rem"><?= nl2br(htmlspecialchars(substr((string)$pr['content'], 0, 400))) ?></span><?php endif; ?></td>
          <td style="white-space:nowrap">
            <a class="tiny btn ghost" href="?v=modules&proposal=<?= (int)$pr['id'] ?>" style="padding:5px 10px;margin-bottom:6px;display:inline-block">Review →</a>
            <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="module_proposal_review">
              <input type="hidden" name="proposal_id" value="<?= (int)$pr['id'] ?>">
              <input type="text" name="review_note" class="tiny" placeholder="note (optional)" maxlength="500" style="width:120px">
              <button class="tiny" type="submit" name="decision" value="approve" style="border-color:#34D399;color:#34D399">Approve</button>
              <button class="tiny" type="submit" name="decision" value="reject" style="border-color:#FCA5A5;color:#FCA5A5">Reject</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <div class="card">
    <h3>1 · Pick a syllabus <span class="muted" style="font-weight:400;font-size:.8rem">— a number is weeks already authored, “·” is empty. Click any cell to open it below.</span></h3>
    <div style="padding:0 20px 16px;overflow-x:auto">
      <table class="curric-matrix">
        <thead><tr><th>Course</th>
          <?php foreach ($ov['levels'] as $l): ?><th><?= htmlspecialchars((string)$l['name']) ?></th><?php endforeach; ?>
          <th>General</th></tr></thead>
        <tbody>
        <?php foreach ($ov['courses'] as $c): ?>
          <tr>
            <td class="cm-course"><?= htmlspecialchars((string)$c['name']) ?><?php if (($c['kind'] ?? '') === 'core'): ?><span class="cm-core">core</span><?php endif; ?></td>
            <?php foreach ($ov['levels'] as $l): $n = (int)$c['levels'][(string)$l['code']]; ?>
              <td><a class="cm-cell <?= $n ? 'on' : 'off' ?>" href="?v=modules&amp;course=<?= urlencode((string)$c['code']) ?>&amp;level=<?= urlencode((string)$l['code']) ?>" title="<?= htmlspecialchars((string)$c['name'] . ' · ' . (string)$l['name']) ?>" aria-label="<?= htmlspecialchars((string)$c['name'] . ' · ' . (string)$l['name'] . ': ' . ($n ? $n . ' week' . ($n === 1 ? '' : 's') . ' authored' : 'no weeks authored')) ?>"><?= $n ?: '·' ?></a></td>
            <?php endforeach; ?>
            <?php $ng = (int)$c['levels']['_none']; ?>
            <td><a class="cm-cell <?= $ng ? 'on' : 'off' ?>" href="?v=modules&amp;course=<?= urlencode((string)$c['code']) ?>" title="<?= htmlspecialchars((string)$c['name']) ?> · level-agnostic" aria-label="<?= htmlspecialchars((string)$c['name'] . ' · general: ' . ($ng ? $ng . ' week' . ($ng === 1 ? '' : 's') . ' authored' : 'no weeks authored')) ?>"><?= $ng ?: '·' ?></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="muted" style="font-size:.78rem;margin-top:10px"><strong><?= (int)$ov['authored_cells'] ?></strong> syllabus cell(s) authored · <strong><?= (int)$ov['total'] ?></strong> total weeks. A number = weeks authored for that course at that level; “·” = empty. Load the starter below to fill the core syllabi instantly.</div>
    </div>
  </div>

  <div class="card">
    <h3>…or choose it here</h3>
    <form method="get" class="form-grid">
      <input type="hidden" name="v" value="modules">
      <label>Course
        <select name="course" required>
          <option value="">— choose a course —</option>
          <?php foreach ($cat['courses'] as $c): ?>
            <option value="<?= htmlspecialchars($c['code']) ?>" <?= $c['code'] === $selCourse ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?> · <?= htmlspecialchars($c['kind']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Level
        <select name="level">
          <option value="">— level-agnostic —</option>
          <?php foreach ($cat['levels'] as $l): ?>
            <option value="<?= htmlspecialchars($l['code']) ?>" <?= $l['code'] === $selLevel ? 'selected' : '' ?>><?= htmlspecialchars($l['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Programme frame
        <select name="program">
          <option value="">12-week (default)</option>
          <?php foreach ($cat['programs'] as $p): ?>
            <option value="<?= htmlspecialchars($p['code']) ?>" <?= $p['code'] === $selProgram ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?> · <?= (int)$p['weeks'] ?>wk</option>
          <?php endforeach; ?>
        </select>
      </label>
      <div><button type="submit" class="btn btn-primary" style="margin-top:22px">View Curriculum</button></div>
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
      <?php $wkAuthored = count(array_filter($outline['weeks'], fn ($w) => !empty($w['id']))); $wkTotal = count($outline['weeks']); ?>
      <div class="card">
        <h3>2 · Author — <?= htmlspecialchars($outline['course']['name']) ?><?= $outline['level'] ? ' · ' . htmlspecialchars($outline['level']['name']) : '' ?>
          <span class="muted" style="font-weight:400;font-size:0.8rem">— <?= $wkTotal ?>-week plan<?= $outline['program'] ? ' (' . htmlspecialchars($outline['program']['name']) . ')' : '' ?></span></h3>
        <div style="padding:0 20px 4px;display:flex;align-items:center;gap:12px">
          <div style="flex:1;height:8px;border-radius:4px;background:var(--bg);overflow:hidden"><div style="height:100%;width:<?= $wkTotal ? (int)round($wkAuthored / $wkTotal * 100) : 0 ?>%;background:linear-gradient(90deg,var(--gold),var(--goldhi))"></div></div>
          <span class="muted" style="font-size:.78rem;white-space:nowrap"><strong><?= $wkAuthored ?></strong> of <?= $wkTotal ?> weeks authored</span>
        </div>
        <div style="padding:0 20px 16px">
          <form method="post" class="inline"><?= $hid ?><input type="hidden" name="action" value="module_scaffold"><button class="btn" type="submit">⚙ Scaffold missing weeks</button></form>
          <span class="muted" style="margin-left:12px;font-size:0.82rem">Creates a placeholder row for every week (modules · revision · exam).</span>
        </div>
        <div style="display:flex;flex-direction:column;gap:10px;padding:0 20px 20px">
        <?php foreach ($outline['weeks'] as $wk):
          $isAuthored = !empty($wk['id']);
          $kindColor = ['module' => 'var(--gold)', 'revision' => '#34D399', 'exam' => '#FB7185'][$wk['kind']] ?? 'var(--gold)';
        ?>
          <form method="post" id="wk<?= (int)$wk['week'] ?>" class="cm-week" style="display:flex;gap:10px;align-items:flex-start;border:1px solid var(--border);border-left:3px solid <?= $kindColor ?>;border-radius:10px;padding:12px;background:var(--bg);<?= $isAuthored ? '' : 'border-style:dashed;border-left-style:solid;opacity:.82' ?>">
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
            <div style="display:flex;flex-direction:column;gap:6px;flex:0 0 auto;align-self:center;text-align:center">
              <button class="tiny cm-save" type="submit" style="opacity:.45" title="Edit the week to enable">Save</button>
              <?php if ($isAuthored): ?><a class="tiny btn ghost" href="?v=modules&course=<?= urlencode($selCourse) ?>&level=<?= urlencode($selLevel) ?>&program=<?= urlencode($selProgram) ?>&module=<?= (int)$wk['id'] ?>" style="padding:5px 8px" title="Open the focused editor"><i class="fa-solid fa-up-right-and-down-left-from-center" aria-hidden="true"></i> Expand</a>
              <?php else: ?><span class="muted" style="font-size:.62rem;letter-spacing:.06em;text-transform:uppercase">not saved yet</span><?php endif; ?>
            </div>
        </form>
        <?php if (!empty($wk['id'])): ?>
          <form method="post" style="margin-top:-4px;text-align:right;padding-right:4px"><?= $hid ?><input type="hidden" name="action" value="module_delete"><input type="hidden" name="module_id" value="<?= (int)$wk['id'] ?>"><button class="tiny" type="submit" style="background:transparent;color:var(--muted);border:0;cursor:pointer;font-size:0.74rem">✕ remove week <?= (int)$wk['week'] ?></button></form>
        <?php endif; ?>
        <?php endforeach; ?>
        </div>
        <script>
        // Enable each row's Save only once it's actually edited, so the wall of
        // forms reads as "content" until you touch one.
        document.querySelectorAll('.cm-week').forEach(function (f) {
          var btn = f.querySelector('.cm-save');
          f.addEventListener('input', function () { if (btn) { btn.style.opacity = '1'; btn.title = 'Save week'; f.style.borderColor = 'var(--gold)'; } });
        });
        </script>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <!-- ── Bulk tools: starter syllabus + import/export (kept below authoring — most days you never need them) ── -->
  <div class="card">
    <h3>Starter curriculum</h3>
    <div style="padding:0 20px 18px">
      <p class="muted" style="font-size:.85rem;line-height:1.6;margin:0 0 12px">
        Load MAM's ready-made syllabus — transcribed from the <strong>KTAMS Young Musicians</strong> (foundation · pre-cert) and
        <strong>Grand Musicians II</strong> (Grades 3–4 · certificate) documents: 8 course syllabi across Rhythm &amp; Pitch, Piano, Guitar, Voice and Drums,
        each a full 12-week plan. It's <strong>non-destructive</strong> — weeks you've already authored are left untouched — so it's safe to run now and refine afterwards.
      </p>
      <form method="post" class="inline">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="seed_starter">
        <button class="btn" type="submit"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Load starter curriculum</button>
      </form>
      <form method="post" class="inline" style="margin-left:8px" onsubmit="return confirm('Reset the starter weeks back to MAM\'s original content? This overwrites edits on those weeks.');">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="seed_starter">
        <input type="hidden" name="force" value="1">
        <button class="btn ghost" type="submit" title="Overwrite the starter weeks back to the original document content">Reset to starter</button>
      </form>
      <?php if ($modulesAuthored === 0): ?>
        <div class="muted" style="font-size:.78rem;margin-top:10px"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> No weeks authored yet — loading the starter is the quickest way to begin.</div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($selCourse !== ''): ?>
  <div class="card">
    <details>
    <summary style="cursor:pointer;padding:16px 20px;font-weight:700;font-size:.95rem">⇅ Import &amp; export this syllabus <span class="muted" style="font-weight:400;font-size:.78rem">— spreadsheet round-trips, Google-Sheet URL import, JSON backups</span></summary>
    <div style="padding:0 20px 18px;display:flex;flex-direction:column;gap:16px">
      <div>
        <div class="muted" style="font-size:.82rem;margin-bottom:9px">Download the authored weeks to edit in a spreadsheet or back up, then re-import to apply. A re-import <strong>upserts by week</strong> — it refines existing weeks instead of duplicating them. JSON exports are portable between installs.</div>
        <a class="btn ghost" href="?v=export&amp;type=curriculum.csv&amp;course=<?= urlencode($selCourse) ?>&amp;level=<?= urlencode($selLevel) ?>">⬇ Export CSV</a>
        <a class="btn ghost" href="?v=export&amp;type=curriculum&amp;course=<?= urlencode($selCourse) ?>&amp;level=<?= urlencode($selLevel) ?>">⬇ Export JSON</a>
      </div>
      <div style="border-top:1px solid var(--border);padding-top:16px">
        <div class="muted" style="font-size:.82rem;margin-bottom:9px">Clone this level's authored weeks into another level — upserts by week (safe to re-run), so it's a quick way to reuse a syllabus as a starting point.</div>
        <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="curriculum_clone">
          <input type="hidden" name="course_code" value="<?= htmlspecialchars($selCourse) ?>">
          <input type="hidden" name="from_level" value="<?= htmlspecialchars($selLevel) ?>">
          <span class="muted" style="font-size:.85rem">Clone <strong><?= $selLevel !== '' ? htmlspecialchars($selLevel) : 'General' ?></strong> →</span>
          <select name="to_level" required style="max-width:230px" aria-label="Target level to clone into">
            <option value="">— target level —</option>
            <?php foreach ($cat['levels'] as $l): if ((string)$l['code'] === $selLevel) continue; ?>
              <option value="<?= htmlspecialchars((string)$l['code']) ?>"><?= htmlspecialchars((string)$l['name']) ?></option>
            <?php endforeach; ?>
            <?php if ($selLevel !== ''): ?><option value="general">General (level-agnostic)</option><?php endif; ?>
          </select>
          <button class="btn ghost" type="submit"><i class="fa-solid fa-copy" aria-hidden="true"></i> Clone syllabus</button>
        </form>
      </div>
      <form method="post" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:11px;border-top:1px solid var(--border);padding-top:16px">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="module_import">
        <input type="hidden" name="course_code" value="<?= htmlspecialchars($selCourse) ?>">
        <input type="hidden" name="level_code" value="<?= htmlspecialchars($selLevel) ?>">
        <input type="hidden" name="program_code" value="<?= htmlspecialchars($selProgram) ?>">
        <label style="font-size:.82rem;font-weight:600">Import from a data source (URL)
          <input type="url" name="import_url" placeholder="https://docs.google.com/…/pub?output=csv  ·  or any public CSV/JSON endpoint" style="width:100%;font-size:.82rem;padding:9px 12px;background:var(--panel);color:var(--text);border:1px solid var(--border);border-radius:8px">
          <span class="muted" style="font-size:.72rem;font-weight:400">We fetch it server-side (public http(s) only). Great for a published Google Sheet or a partner feed.</span>
        </label>
        <label style="font-size:.82rem;font-weight:600">…or paste JSON / CSV
          <textarea name="import_text" rows="5" placeholder="week,kind,title,content&#10;1,module,Intro to Rhythm,Clap &amp; count&#10;2,module,Sight Reading,&#10;11,revision,Revision,&#10;12,exam,Final Examination," style="width:100%;font-family:var(--mono,ui-monospace,monospace);font-size:.8rem;resize:vertical"></textarea>
        </label>
        <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center">
          <label style="font-size:.82rem">…or choose a file <input type="file" name="import_file" accept=".csv,.json,text/csv,application/json"></label>
          <label style="font-size:.82rem">Format
            <select name="import_format" class="tiny">
              <option value="">Auto-detect</option>
              <option value="csv">CSV</option>
              <option value="json">JSON</option>
            </select>
          </label>
          <button class="btn" type="submit">⬆ Import into <?= htmlspecialchars($selCourse) ?><?= $selLevel !== '' ? ' · ' . htmlspecialchars($selLevel) : '' ?></button>
        </div>
        <div class="muted" style="font-size:.74rem;line-height:1.6">Columns: <code>week</code> &amp; <code>title</code> (required); optional <code>kind</code> (module · revision · exam) and <code>content</code>. A row may also set <code>course_code</code> / <code>level_code</code> to target a different course in the same file. Without those, rows import into the selected course/level above.</div>
      </form>
    </div>
    </details>
  </div>
  <?php endif; ?>


<?php elseif ($view === 'messages'): ?>
  <header class="top"><h1>Contact Messages</h1><a class="btn ghost" href="?v=export&type=messages.csv">⬇ Export</a></header>
  <div class="card">
    <table>
      <thead><tr><th>When</th><th>From</th><th>Topic</th><th>Message</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php
      $perPage = 50; $page = mam_admin_page(); $total = (int)mam_admin_scalar('SELECT COUNT(*) FROM messages'); $off = ($page - 1) * $perPage;
      $rows = mam_db_all('SELECT * FROM messages ORDER BY created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $off);
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
    <?= mam_admin_pager($page, $total, $perPage) ?>
  </div>

<?php elseif ($view === 'webhooks'): ?>
  <header class="top"><h1>Webhook Events</h1></header>
  <div class="card">
    <table>
      <thead><tr><th>When</th><th>Provider</th><th>Event</th><th>Event ID</th><th>Sig</th><th>Status</th></tr></thead>
      <tbody>
      <?php
      $perPage = 50; $page = mam_admin_page(); $total = (int)mam_admin_scalar('SELECT COUNT(*) FROM webhook_events'); $off = ($page - 1) * $perPage;
      $rows = mam_db_all('SELECT * FROM webhook_events ORDER BY received_at DESC LIMIT ' . $perPage . ' OFFSET ' . $off);
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
    <?= mam_admin_pager($page, $total, $perPage) ?>
  </div>

<?php elseif ($view === 'newsletter'): ?>
  <header class="top"><h1>Newsletter</h1><a class="btn ghost" href="?v=export&type=newsletter.csv">⬇ Export</a></header>
  <div class="card">
    <table>
      <thead><tr><th>Email</th><th>Source</th><th>Status</th><th>When</th></tr></thead>
      <tbody>
      <?php
      $perPage = 100; $page = mam_admin_page(); $total = (int)mam_admin_scalar('SELECT COUNT(*) FROM newsletter'); $off = ($page - 1) * $perPage;
      $rows = mam_db_all('SELECT * FROM newsletter ORDER BY created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $off);
      if (!$rows): ?><tr><td colspan="4" class="empty">No subscribers yet</td></tr><?php endif;
      foreach ($rows as $r): ?>
        <tr><td><?= htmlspecialchars($r['email']) ?></td><td class="muted"><?= htmlspecialchars((string)$r['source']) ?></td><td><span class="pill <?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td><td class="muted"><?= htmlspecialchars(substr($r['created_at'], 0, 16)) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?= mam_admin_pager($page, $total, $perPage) ?>
  </div>

<?php elseif ($view === 'support'):
  /* ── Support Hub ──────────────────────────────────────────────────────
     Three sections selectable via ?sv=tickets|surveys|feedback (default: tickets).
     All data loaded server-side; reply/assign/resolve POSTs go to /api/admin/support. */
  $sv = (string)($_GET['sv'] ?? 'tickets');
  if (!in_array($sv, ['tickets','surveys','feedback'], true)) $sv = 'tickets';
  $svTab = fn($id, $lbl) => '<a href="?v=support&sv=' . $id . '" class="btn' . ($sv === $id ? '' : ' ghost') . '" style="font-size:0.82rem">' . $lbl . '</a>';
?>
  <header class="top">
    <h1><i class="fa-solid fa-headset" style="margin-right:10px;color:var(--gold)"></i>Support Hub</h1>
    <div style="display:flex;gap:6px"><?= $svTab('tickets','🎫 Tickets') ?><?= $svTab('surveys','📋 Surveys') ?><?= $svTab('feedback','⭐ Feedback') ?></div>
  </header>

<?php if ($sv === 'tickets'):
  $tkStatus = (string)($_GET['ts'] ?? '');
  if (!in_array($tkStatus, ['','open','in_progress','resolved','closed'], true)) $tkStatus = '';
  $tkId = (int)($_GET['tk'] ?? 0);
  if ($tkId > 0):
    $tk = mam_db_row("SELECT t.*, s.name AS student_name, s.email AS student_email, a.name AS assigned_to_name
      FROM support_tickets t JOIN users s ON s.id = t.student_id LEFT JOIN users a ON a.id = t.assigned_to
      WHERE t.id = ?", [$tkId]);
    if ($tk):
      $msgs = mam_db_all("SELECT m.id, m.body, m.is_internal, m.created_at, u.name AS author_name, u.role AS author_role
        FROM ticket_messages m JOIN users u ON u.id = m.author_id WHERE m.ticket_id = ? ORDER BY m.created_at ASC", [$tkId]);
      $staffUsers = mam_db_all("SELECT id, name FROM users WHERE role = 'admin' ORDER BY name");
      $sc = ['open' => '#e09a1a', 'in_progress' => '#4a9eff', 'resolved' => '#4caf50', 'closed' => '#999'][$tk['status']] ?? '#999';
?>
  <a href="?v=support&sv=tickets&ts=<?= htmlspecialchars($tkStatus) ?>" style="display:inline-flex;align-items:center;gap:6px;color:var(--gold);font-size:0.87rem;margin-bottom:14px"><i class="fa-solid fa-arrow-left"></i> All tickets</a>
  <div style="display:grid;grid-template-columns:1fr 320px;gap:18px;align-items:start">
    <div>
      <div class="card" style="padding:20px 24px;margin-bottom:16px">
        <div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap">
          <div style="flex:1">
            <div style="font-family:var(--font-mono,monospace);font-size:0.82rem;color:var(--gold);margin-bottom:3px"><?= htmlspecialchars($tk['reference']) ?></div>
            <div style="font-size:1.15rem;font-weight:700;margin-bottom:4px"><?= htmlspecialchars($tk['subject']) ?></div>
            <div style="font-size:0.82rem;color:var(--muted)">
              From <strong><?= htmlspecialchars($tk['student_name']) ?></strong> (<?= htmlspecialchars($tk['student_email']) ?>)
              · <?= htmlspecialchars($tk['category']) ?> · Priority: <?= htmlspecialchars($tk['priority']) ?>
              · Opened <?= htmlspecialchars(substr($tk['created_at'], 0, 16)) ?>
              <?= $tk['assigned_to_name'] ? ' · Assigned to ' . htmlspecialchars($tk['assigned_to_name']) : '' ?>
            </div>
          </div>
          <span class="pill" style="background:rgba(0,0,0,0.15);color:<?= $sc ?>"><?= htmlspecialchars($tk['status']) ?></span>
        </div>
        <div style="margin-top:14px;padding:14px 16px;background:var(--bg-alt,#f9f9f9);border-radius:8px;font-size:0.9rem;line-height:1.6;white-space:pre-wrap"><?= htmlspecialchars($tk['body']) ?></div>
      </div>
      <?php foreach ($msgs as $m):
        $isInternal = (int)$m['is_internal'] === 1;
        $isStaff = in_array($m['author_role'], ['admin','instructor'], true);
        $bg = $isInternal ? 'rgba(255,200,60,0.1)' : ($isStaff ? '#f3f4f6' : 'rgba(224,138,26,0.08)');
        $border = $isInternal ? '1px dashed #e09a1a' : '1px solid var(--border)';
      ?>
        <div class="card" style="padding:14px 18px;margin-bottom:10px;background:<?= $bg ?>;border:<?= $border ?>">
          <div style="display:flex;justify-content:space-between;margin-bottom:6px">
            <span style="font-size:0.8rem;font-weight:600"><?= htmlspecialchars($m['author_name']) ?> <span class="pill" style="font-size:0.68rem"><?= htmlspecialchars($m['author_role']) ?></span><?= $isInternal ? ' <span class="pill" style="font-size:0.68rem;background:rgba(255,200,60,0.2);color:#8a6a00">internal</span>' : '' ?></span>
            <span style="font-size:0.78rem;color:var(--muted)"><?= htmlspecialchars(substr($m['created_at'], 0, 16)) ?></span>
          </div>
          <div style="font-size:0.9rem;line-height:1.55;white-space:pre-wrap"><?= htmlspecialchars($m['body']) ?></div>
        </div>
      <?php endforeach; ?>
      <!-- Reply form -->
      <?php if ($tk['status'] !== 'closed'): ?>
      <div class="card" style="padding:18px 22px">
        <h3 style="margin:0 0 12px;font-size:0.95rem">Reply</h3>
        <form id="tkReplyForm" style="display:flex;flex-direction:column;gap:10px">
          <input type="hidden" name="ticket_id" value="<?= (int)$tkId ?>">
          <textarea name="body" rows="4" placeholder="Your reply to the student…" style="padding:9px 12px;border-radius:8px;border:1px solid var(--border);font-size:0.9rem;resize:vertical"></textarea>
          <label style="display:flex;align-items:center;gap:8px;font-size:0.84rem"><input type="checkbox" name="internal" value="1"> Internal note (not shown to student)</label>
          <div style="display:flex;gap:8px"><button type="submit" class="btn" style="font-size:0.84rem">Send Reply</button><span id="tkReplyMsg" style="font-size:0.84rem;color:var(--muted)"></span></div>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <!-- Sidebar: quick actions -->
    <div style="display:flex;flex-direction:column;gap:12px">
      <div class="card" style="padding:16px 18px">
        <h3 style="margin:0 0 12px;font-size:0.88rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted)">Actions</h3>
        <?php if ($tk['status'] !== 'resolved' && $tk['status'] !== 'closed'): ?>
        <form id="tkResolveForm" style="margin-bottom:8px"><input type="hidden" name="ticket_id" value="<?= (int)$tkId ?>"><button type="submit" class="btn" style="width:100%;font-size:0.83rem"><i class="fa-solid fa-circle-check" style="margin-right:5px;color:#4caf50"></i>Mark Resolved</button></form>
        <?php endif; ?>
        <form id="tkAssignForm" style="display:flex;flex-direction:column;gap:6px">
          <input type="hidden" name="ticket_id" value="<?= (int)$tkId ?>">
          <select name="assigned_to" style="padding:8px 10px;border-radius:8px;border:1px solid var(--border);font-size:0.84rem">
            <option value="0">Unassigned</option>
            <?php foreach ($staffUsers as $su): ?>
            <option value="<?= (int)$su['id'] ?>" <?= (int)$tk['assigned_to'] === (int)$su['id'] ? 'selected' : '' ?>><?= htmlspecialchars($su['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn ghost" style="font-size:0.82rem">Assign</button>
        </form>
      </div>
    </div>
  </div>
  <script>
  (function () {
    var csrf = null;
    function getToken() { return fetch('/api/csrf', { credentials: 'include' }).then(r => r.json()).then(j => { csrf = j.token; }); }
    getToken();
    function apiPost(body) {
      return (csrf ? Promise.resolve() : getToken()).then(function () {
        return fetch('/api/admin/support', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(body) }).then(r => r.json());
      });
    }
    var replyForm = document.getElementById('tkReplyForm');
    if (replyForm) replyForm.addEventListener('submit', function (e) {
      e.preventDefault(); var fd = new FormData(replyForm);
      var btn = replyForm.querySelector('button[type=submit]'), msg = document.getElementById('tkReplyMsg');
      btn.disabled = true; btn.textContent = 'Sending…';
      apiPost({ action: 'ticket_reply', ticket_id: parseInt(fd.get('ticket_id'), 10), body: fd.get('body'), internal: fd.get('internal') === '1' })
        .then(function () { location.reload(); })
        .catch(function (e) { btn.disabled = false; btn.textContent = 'Send Reply'; msg.textContent = 'Error: ' + (e && e.error || 'try again'); });
    });
    var resolveForm = document.getElementById('tkResolveForm');
    if (resolveForm) resolveForm.addEventListener('submit', function (e) {
      e.preventDefault(); var fd = new FormData(resolveForm);
      if (!confirm('Mark this ticket as resolved?')) return;
      apiPost({ action: 'ticket_resolve', ticket_id: parseInt(fd.get('ticket_id'), 10) }).then(function () { location.reload(); });
    });
    var assignForm = document.getElementById('tkAssignForm');
    if (assignForm) assignForm.addEventListener('submit', function (e) {
      e.preventDefault(); var fd = new FormData(assignForm);
      apiPost({ action: 'ticket_assign', ticket_id: parseInt(fd.get('ticket_id'), 10), assigned_to: parseInt(fd.get('assigned_to'), 10) || 0 }).then(function () { location.reload(); });
    });
  })();
  </script>
<?php else: echo '<div class="card empty">Ticket not found.</div>'; endif;
  else: // ticket list
  $tkList = mam_tickets_admin($tkStatus);
  $sc2 = ['open' => '#e09a1a', 'in_progress' => '#4a9eff', 'resolved' => '#4caf50', 'closed' => '#999'];
?>
  <div style="display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap">
    <?php foreach ([''=>'All', 'open'=>'Open', 'in_progress'=>'In Progress', 'resolved'=>'Resolved', 'closed'=>'Closed'] as $s => $l): ?>
    <a href="?v=support&sv=tickets&ts=<?= urlencode($s) ?>" class="btn <?= $tkStatus === $s ? '' : 'ghost' ?>" style="font-size:0.81rem"><?= htmlspecialchars($l) ?></a>
    <?php endforeach; ?>
  </div>
  <?php if (!$tkList): ?><div class="card"><p class="empty">No tickets<?= $tkStatus ? ' with status "' . htmlspecialchars($tkStatus) . '"' : '' ?>.</p></div><?php else: ?>
  <div class="card" style="padding:0">
    <table>
      <thead><tr><th>Ref</th><th>Student</th><th>Subject</th><th>Category</th><th>Priority</th><th>Status</th><th>Assigned</th><th>Updated</th></tr></thead>
      <tbody>
      <?php foreach ($tkList as $t): $sc3 = $sc2[$t['status']] ?? '#999'; ?>
        <tr>
          <td><a href="?v=support&sv=tickets&tk=<?= (int)$t['id'] ?>" class="mono" style="color:var(--gold);font-size:0.82rem"><?= htmlspecialchars($t['reference']) ?></a></td>
          <td><?= htmlspecialchars($t['student_name'] ?? '—') ?><br><span class="muted" style="font-size:0.78rem"><?= htmlspecialchars($t['student_email'] ?? '') ?></span></td>
          <td><a href="?v=support&sv=tickets&tk=<?= (int)$t['id'] ?>" style="font-weight:600"><?= htmlspecialchars($t['subject']) ?></a></td>
          <td class="muted"><?= htmlspecialchars($t['category']) ?></td>
          <td class="muted"><?= htmlspecialchars($t['priority']) ?></td>
          <td><span class="pill" style="background:rgba(0,0,0,0.12);color:<?= $sc3 ?>"><?= htmlspecialchars($t['status']) ?></span></td>
          <td class="muted" style="font-size:0.84rem"><?= htmlspecialchars($t['assigned_to_name'] ?? '—') ?></td>
          <td class="muted" style="font-size:0.82rem"><?= htmlspecialchars(substr($t['updated_at'] ?? $t['created_at'], 0, 16)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

<?php endif; // sv=tickets ?>

<?php elseif ($sv === 'surveys'):
  $surveyId = (int)($_GET['sid'] ?? 0);
  if ($surveyId > 0):
    try { $results = mam_survey_results($surveyId); } catch (Throwable $e) { $results = null; }
    $surveyRow = mam_db_row('SELECT * FROM surveys WHERE id = ?', [$surveyId]);
?>
  <a href="?v=support&sv=surveys" style="display:inline-flex;align-items:center;gap:6px;color:var(--gold);font-size:0.87rem;margin-bottom:14px"><i class="fa-solid fa-arrow-left"></i> All surveys</a>
  <?php if ($results && $surveyRow): ?>
  <div class="card" style="padding:20px 24px;margin-bottom:16px">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
      <div>
        <h2 style="margin:0 0 4px"><?= htmlspecialchars($surveyRow['title']) ?></h2>
        <?= $surveyRow['description'] ? '<p style="color:var(--muted);font-size:0.88rem;margin:0">' . htmlspecialchars($surveyRow['description']) . '</p>' : '' ?>
      </div>
      <div style="text-align:right">
        <div style="font-size:1.6rem;font-weight:700;color:var(--gold)"><?= (int)$results['response_count'] ?></div>
        <div style="font-size:0.8rem;color:var(--muted)">responses</div>
        <span class="pill <?= htmlspecialchars($surveyRow['status']) ?>" style="margin-top:4px;display:inline-block"><?= htmlspecialchars($surveyRow['status']) ?></span>
      </div>
    </div>
    <form id="surveyStatusForm" style="margin-top:14px;display:flex;gap:6px;align-items:center">
      <select name="status" style="padding:7px 10px;border-radius:8px;border:1px solid var(--border);font-size:0.84rem">
        <option value="draft" <?= $surveyRow['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
        <option value="active" <?= $surveyRow['status'] === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="closed" <?= $surveyRow['status'] === 'closed' ? 'selected' : '' ?>>Closed</option>
      </select>
      <button type="submit" class="btn ghost" style="font-size:0.82rem">Update status</button>
      <span id="surveyStatusMsg" style="font-size:0.82rem;color:var(--muted)"></span>
    </form>
  </div>
  <?php foreach ($results['by_question'] as $qi => $q): ?>
  <div class="card" style="padding:16px 20px;margin-bottom:12px">
    <h3 style="margin:0 0 10px;font-size:0.95rem"><?= (int)$qi + 1 ?>. <?= htmlspecialchars($q['text']) ?></h3>
    <?php if ($q['type'] === 'rating' && !empty($q['distribution'])): ?>
      <div style="font-size:1.4rem;font-weight:700;color:var(--gold);margin-bottom:8px"><?= number_format((float)($q['avg'] ?? 0), 1) ?> / 5</div>
      <?php foreach ($q['distribution'] as $star => $c): ?>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;font-size:0.87rem">
          <span style="width:16px;text-align:right"><?= (int)$star ?>★</span>
          <div style="flex:1;height:8px;background:var(--border);border-radius:4px"><div style="height:100%;background:var(--gold);border-radius:4px;width:<?= $results['response_count'] > 0 ? round((int)$c / $results['response_count'] * 100) : 0 ?>%"></div></div>
          <span style="width:24px;color:var(--muted)"><?= (int)$c ?></span>
        </div>
      <?php endforeach; ?>
    <?php elseif (!empty($q['answers'])): ?>
      <ul style="margin:0;padding-left:18px;font-size:0.88rem;color:var(--text-soft,#555)">
        <?php foreach (array_slice($q['answers'], 0, 50) as $ans): ?>
          <li style="margin-bottom:3px"><?= htmlspecialchars((string)$ans) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php else: ?><div class="card empty">Survey not found.</div><?php endif; ?>
  <script>
  (function () {
    var csrf = null;
    function getToken() { return fetch('/api/csrf', { credentials: 'include' }).then(r => r.json()).then(j => { csrf = j.token; }); }
    getToken();
    var sf = document.getElementById('surveyStatusForm');
    if (sf) sf.addEventListener('submit', function (e) {
      e.preventDefault();
      (csrf ? Promise.resolve() : getToken()).then(function () {
        return fetch('/api/admin/support', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ action: 'survey_status', survey_id: <?= (int)$surveyId ?>, status: sf.querySelector('[name=status]').value }) }).then(r => r.json());
      }).then(function () { document.getElementById('surveyStatusMsg').textContent = 'Updated.'; }).catch(function (e) { document.getElementById('surveyStatusMsg').textContent = 'Error.'; });
    });
  })();
  </script>
<?php else: // survey list
  $surveys = mam_surveys_admin();
?>
  <!-- Create survey form -->
  <details class="card" style="padding:18px 22px;margin-bottom:18px">
    <summary style="font-weight:700;cursor:pointer;font-size:0.95rem"><i class="fa-solid fa-plus" style="margin-right:6px;color:var(--gold)"></i>Create New Survey</summary>
    <form id="createSurveyForm" style="margin-top:16px;display:flex;flex-direction:column;gap:10px">
      <input name="title" type="text" placeholder="Survey title *" style="padding:9px 12px;border-radius:8px;border:1px solid var(--border);font-size:0.9rem" required>
      <textarea name="description" rows="2" placeholder="Description (optional)" style="padding:9px 12px;border-radius:8px;border:1px solid var(--border);font-size:0.9rem;resize:vertical"></textarea>
      <select name="target_track" style="padding:9px 12px;border-radius:8px;border:1px solid var(--border);font-size:0.9rem">
        <option value="">All students</option>
        <option value="MAM">MAM students only</option>
        <option value="KTAMS">KTAMS students only</option>
      </select>
      <input name="closes_at" type="datetime-local" style="padding:9px 12px;border-radius:8px;border:1px solid var(--border);font-size:0.9rem">
      <p style="margin:4px 0 0;font-size:0.82rem;color:var(--muted)">Add questions below (one per line) in the format: <code>text|type|option1,option2</code><br>Types: <code>text</code>, <code>long_text</code>, <code>rating</code>, <code>select</code></p>
      <textarea id="surveyQsRaw" rows="5" placeholder="e.g.&#10;How would you rate this month's classes?|rating&#10;Which course do you enjoy most?|select|Piano,Drums,Guitar&#10;Any other feedback?|long_text" style="padding:9px 12px;border-radius:8px;border:1px solid var(--border);font-size:0.85rem;resize:vertical;font-family:monospace"></textarea>
      <div style="display:flex;gap:8px"><button type="submit" class="btn">Create Survey</button><span id="createSurveyMsg" style="font-size:0.84rem;color:var(--muted)"></span></div>
    </form>
  </details>
  <?php if (!$surveys): ?><div class="card"><p class="empty">No surveys yet — create one above.</p></div><?php else: ?>
  <div class="card" style="padding:0">
    <table>
      <thead><tr><th>Title</th><th>Target</th><th>Status</th><th>Responses</th><th>Closes</th><th>Created</th></tr></thead>
      <tbody>
      <?php foreach ($surveys as $sv2):
        $rcount = (int)mam_db()->query("SELECT COUNT(*) FROM survey_responses WHERE survey_id = " . (int)$sv2['id'])->fetchColumn();
      ?>
        <tr>
          <td><a href="?v=support&sv=surveys&sid=<?= (int)$sv2['id'] ?>" style="font-weight:600"><?= htmlspecialchars($sv2['title']) ?></a></td>
          <td class="muted"><?= htmlspecialchars($sv2['target_track'] ?: 'All') ?></td>
          <td><span class="pill <?= htmlspecialchars($sv2['status']) ?>"><?= htmlspecialchars($sv2['status']) ?></span></td>
          <td><?= $rcount ?></td>
          <td class="muted"><?= $sv2['closes_at'] ? htmlspecialchars(substr($sv2['closes_at'], 0, 10)) : '—' ?></td>
          <td class="muted"><?= htmlspecialchars(substr($sv2['created_at'], 0, 10)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <script>
  (function () {
    var csrf = null;
    function getToken() { return fetch('/api/csrf', { credentials: 'include' }).then(r => r.json()).then(j => { csrf = j.token; }); }
    getToken();
    document.getElementById('createSurveyForm').addEventListener('submit', function (e) {
      e.preventDefault();
      var title = e.target.title.value.trim();
      var rawQs = document.getElementById('surveyQsRaw').value.trim();
      if (!title || !rawQs) { document.getElementById('createSurveyMsg').textContent = 'Title and questions required.'; return; }
      var questions = rawQs.split('\n').filter(Boolean).map(function (line) {
        var parts = line.split('|'); var text = parts[0].trim(), type = (parts[1] || 'text').trim();
        var opts = parts[2] ? parts[2].split(',').map(function (o) { return o.trim(); }) : undefined;
        var q = { text: text, type: type, required: true };
        if (opts) q.options = opts;
        return q;
      });
      var btn = e.target.querySelector('button'), msg = document.getElementById('createSurveyMsg');
      btn.disabled = true; btn.textContent = 'Creating…';
      var closes = e.target.closes_at.value || null;
      (csrf ? Promise.resolve() : getToken()).then(function () {
        return fetch('/api/admin/support', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ action: 'survey_create', title: title, description: e.target.description.value.trim() || null, questions: questions, target_track: e.target.target_track.value || null, closes_at: closes }) }).then(r => r.json());
      }).then(function (r) {
        if (r.id) location.href = '?v=support&sv=surveys&sid=' + r.id;
        else { btn.disabled = false; btn.textContent = 'Create Survey'; msg.textContent = r.error || 'Created.'; }
      }).catch(function () { btn.disabled = false; btn.textContent = 'Create Survey'; msg.textContent = 'Error — try again.'; });
    });
  })();
  </script>
<?php endif; // survey list/detail ?>

<?php else: // sv === 'feedback'
  $fbAgg = mam_feedback_aggregate();
  $fbRecent = $fbAgg['recent'] ?? [];
  $fbByCat = $fbAgg['by_category'] ?? [];
?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-bottom:20px">
    <?php foreach ($fbByCat as $cat => $d): ?>
    <div class="card" style="padding:16px;text-align:center">
      <div style="font-size:1.6rem;font-weight:700;color:var(--gold)"><?= number_format((float)($d['avg_rating'] ?? 0), 1) ?>★</div>
      <div style="font-size:0.82rem;text-transform:capitalize;margin-top:2px"><?= htmlspecialchars($cat) ?></div>
      <div style="font-size:0.78rem;color:var(--muted)"><?= (int)($d['count'] ?? 0) ?> response<?= (int)($d['count'] ?? 0) !== 1 ? 's' : '' ?></div>
    </div>
    <?php endforeach; ?>
    <?php if (!$fbByCat): ?><div class="card"><p class="empty">No feedback yet.</p></div><?php endif; ?>
  </div>
  <?php if ($fbRecent): ?>
  <div class="card" style="padding:0">
    <div style="padding:14px 20px;border-bottom:1px solid var(--border);font-weight:700;font-size:0.9rem">Recent feedback</div>
    <table>
      <thead><tr><th>Student</th><th>Rating</th><th>Category</th><th>Subject</th><th>Feedback</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach ($fbRecent as $fb):
        $stars = str_repeat('★', (int)$fb['rating']) . str_repeat('☆', max(0, 5 - (int)$fb['rating']));
      ?>
        <tr>
          <td><?= (int)$fb['is_anonymous'] ? '<em style="color:var(--muted)">Anonymous</em>' : htmlspecialchars($fb['student_name'] ?? '—') ?></td>
          <td style="color:var(--gold);letter-spacing:1px"><?= htmlspecialchars($stars) ?></td>
          <td class="muted"><?= htmlspecialchars($fb['category']) ?></td>
          <td><?= htmlspecialchars($fb['subject'] ?? '—') ?></td>
          <td style="max-width:320px;font-size:0.87rem;color:var(--text-soft,#555)"><?= htmlspecialchars(mb_substr($fb['body'], 0, 200)) ?><?= mb_strlen($fb['body']) > 200 ? '…' : '' ?></td>
          <td class="muted"><?= htmlspecialchars(substr($fb['created_at'], 0, 10)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
<?php endif; // sv=feedback ?>

<?php elseif ($view === 'audit'): ?>
  <header class="top"><h1>Audit Log</h1></header>
  <div class="card">
    <table>
      <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>Meta</th><th>IP</th></tr></thead>
      <tbody>
      <?php
      $perPage = 100; $page = mam_admin_page(); $total = (int)mam_admin_scalar('SELECT COUNT(*) FROM audit_log'); $off = ($page - 1) * $perPage;
      foreach (mam_db_all('SELECT * FROM audit_log ORDER BY created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $off) as $r): ?>
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
    <?= mam_admin_pager($page, $total, $perPage) ?>
  </div>

<?php elseif ($view === 'logs'):
  // Tail the most recent app-YYYY-MM-DD.log file. Strict allow-list on the
  // date param prevents any path traversal.
  $logDir  = MAM_LOGS;
  $reqDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? '')) ? $_GET['date'] : date('Y-m-d');
  $logFile = $logDir . '/app-' . $reqDate . '.log';
  $level   = in_array($_GET['level'] ?? '', ['info','warn','error',''], true) ? ($_GET['level'] ?? '') : '';

  // Collect available log dates (files matching the pattern, newest first).
  $availDates = [];
  if (is_dir($logDir)) {
      foreach (glob($logDir . '/app-????-??-??.log') ?: [] as $f) {
          if (preg_match('/app-(\d{4}-\d{2}-\d{2})\.log$/', $f, $m)) $availDates[] = $m[1];
      }
      rsort($availDates);
  }

  // Read up to 500 lines from the chosen file (tail-style for large files).
  $lines = [];
  if (is_file($logFile) && is_readable($logFile)) {
      $raw = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
      $raw = array_slice($raw, -500);
      foreach (array_reverse($raw) as $ln) {
          $row = json_decode($ln, true);
          if (!is_array($row)) continue;
          if ($level !== '' && ($row['level'] ?? '') !== $level) continue;
          $lines[] = $row;
      }
  }
?>
  <header class="top"><h1>App Logs</h1></header>
  <div class="card" style="padding-bottom:6px">
    <form method="get" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px">
      <input type="hidden" name="v" value="logs">
      <label style="display:flex;flex-direction:column;gap:3px;font-size:.8rem;font-weight:600">Date
        <select name="date" style="padding:7px 10px;border-radius:7px;border:1px solid var(--border);background:var(--bg);color:var(--ink)">
          <?php foreach ($availDates as $d): ?>
            <option value="<?= htmlspecialchars($d) ?>" <?= $d === $reqDate ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
          <?php endforeach; ?>
          <?php if (!$availDates): ?><option value="<?= htmlspecialchars($reqDate) ?>" selected><?= htmlspecialchars($reqDate) ?></option><?php endif; ?>
        </select>
      </label>
      <label style="display:flex;flex-direction:column;gap:3px;font-size:.8rem;font-weight:600">Level
        <select name="level" style="padding:7px 10px;border-radius:7px;border:1px solid var(--border);background:var(--bg);color:var(--ink)">
          <option value="" <?= $level === '' ? 'selected' : '' ?>>All</option>
          <option value="info"  <?= $level === 'info'  ? 'selected' : '' ?>>info</option>
          <option value="warn"  <?= $level === 'warn'  ? 'selected' : '' ?>>warn</option>
          <option value="error" <?= $level === 'error' ? 'selected' : '' ?>>error</option>
        </select>
      </label>
      <button class="btn" type="submit">Filter</button>
    </form>
    <?php if (!$lines): ?>
      <p class="empty">No log entries found<?= $level ? ' at level "' . htmlspecialchars($level) . '"' : '' ?> for <?= htmlspecialchars($reqDate) ?>.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:.78rem">
      <thead><tr style="border-bottom:1px solid var(--border)">
        <th style="text-align:left;padding:4px 10px 6px 0;color:var(--muted);white-space:nowrap">Time</th>
        <th style="text-align:left;padding:4px 8px 6px;color:var(--muted)">Level</th>
        <th style="text-align:left;padding:4px 8px 6px;color:var(--muted)">Event</th>
        <th style="text-align:left;padding:4px 8px 6px;color:var(--muted)">IP</th>
        <th style="text-align:left;padding:4px 0 6px 8px;color:var(--muted)">Meta</th>
      </tr></thead>
      <tbody>
      <?php foreach ($lines as $row):
        $lv = (string)($row['level'] ?? 'info');
        $lvColor = $lv === 'error' ? '#F87171' : ($lv === 'warn' ? '#FBBF24' : 'var(--muted)');
      ?>
        <tr style="border-bottom:1px solid var(--border-faint,#2a1e15);vertical-align:top">
          <td style="padding:5px 10px 5px 0;color:var(--muted);white-space:nowrap" class="mono"><?= htmlspecialchars(substr((string)($row['ts'] ?? ''), 0, 19)) ?></td>
          <td style="padding:5px 8px;font-weight:700;color:<?= $lvColor ?>;white-space:nowrap"><?= htmlspecialchars($lv) ?></td>
          <td style="padding:5px 8px;font-weight:600"><?= htmlspecialchars((string)($row['event'] ?? '')) ?></td>
          <td style="padding:5px 8px;color:var(--muted)" class="mono"><?= htmlspecialchars((string)($row['ip'] ?? '')) ?></td>
          <td style="padding:5px 0 5px 8px;color:var(--muted);max-width:420px;word-break:break-all" class="json-pop"><?= htmlspecialchars(json_encode($row['meta'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p style="padding:8px 0 0;font-size:.74rem;color:var(--muted)">Showing up to 500 most-recent entries (newest first) · Log file: <span class="mono">app-<?= htmlspecialchars($reqDate) ?>.log</span></p>
    <?php endif; ?>
  </div>

<?php elseif ($view === 'datarights'): ?>
  <header class="top"><h1>Data Rights <span class="muted" style="font-size:.8rem">(NDPA / GDPR)</span></h1></header>
  <?php if (isset($_GET['erased'])): ?><div class="card" style="border-color:#34D399"><strong style="color:#34D399">Erased.</strong> The person's personal data was anonymised and free-form PII deleted; financial &amp; audit records were preserved as required.</div><?php endif; ?>
  <?php if (isset($_GET['err'])): ?><div class="card" style="border-color:#FCA5A5"><strong style="color:#FCA5A5"><?= htmlspecialchars((string)$_GET['err']) ?></strong></div><?php endif; ?>

  <div class="card">
    <h3 style="margin:0 0 8px">Look up a person</h3>
    <form method="get" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
      <input type="hidden" name="v" value="datarights">
      <label style="flex:1;min-width:240px">Email
        <input type="email" name="email" required value="<?= htmlspecialchars((string)($_GET['email'] ?? '')) ?>" placeholder="person@example.com">
      </label>
      <button class="btn" type="submit">Look up</button>
    </form>
    <p class="muted" style="font-size:.78rem;margin:10px 0 0">Export returns everything we hold about the person (a Data Subject Access Request). Erasure anonymises identifying fields and deletes free-form PII, while keeping financial and audit records there is a lawful basis to retain.</p>
  </div>

  <?php
    $drEmail = strtolower(trim((string)($_GET['email'] ?? '')));
    if ($drEmail !== ''):
      $bundle = mam_datarights_export($drEmail);
      if (!$bundle):
  ?>
    <div class="card"><p class="empty">No customer or user found for <strong><?= htmlspecialchars($drEmail) ?></strong>.</p></div>
  <?php else: ?>
    <div class="card">
      <h3 style="margin:0 0 10px">What we hold for <span class="mono"><?= htmlspecialchars($drEmail) ?></span></h3>
      <ul style="line-height:1.9;margin:0 0 8px">
        <li>Customer record: <strong><?= $bundle['customer'] ? 'yes (#' . (int)$bundle['customer']['id'] . ')' : 'no' ?></strong></li>
        <li>User account: <strong><?= $bundle['user'] ? 'yes (#' . (int)$bundle['user']['id'] . ', ' . htmlspecialchars((string)$bundle['user']['role']) . ')' : 'no' ?></strong></li>
        <li>Applications: <strong><?= count($bundle['applications'] ?? []) ?></strong> · Payments: <strong><?= count($bundle['payments'] ?? []) ?></strong></li>
        <li>Enrolments: <strong><?= count($bundle['enrolments'] ?? []) ?></strong> · Submissions: <strong><?= count($bundle['submissions'] ?? []) ?></strong> · Practice logs: <strong><?= count($bundle['practice_logs'] ?? []) ?></strong></li>
        <li>Attendance rows: <strong><?= count($bundle['attendance'] ?? []) ?></strong> · Notifications: <strong><?= count($bundle['notifications'] ?? []) ?></strong></li>
        <li>Contact messages: <strong><?= count($bundle['messages'] ?? []) ?></strong> · Newsletter: <strong><?= count($bundle['newsletter'] ?? []) ?></strong></li>
      </ul>
      <a class="btn" href="?v=export&type=datarights&email=<?= rawurlencode($drEmail) ?>"><i class="fa-solid fa-download"></i> Download JSON export</a>
    </div>

    <div class="card" style="border-color:#FCA5A5">
      <h3 style="margin:0 0 6px;color:#FCA5A5">Erase personal data</h3>
      <p class="muted" style="font-size:.82rem;margin:0 0 10px">This anonymises the customer/user identity, deletes contact messages and newsletter entries, and nulls free-form notes. It cannot be undone. Financial (payment) and audit records are preserved. Type <strong>ERASE</strong> to confirm.</p>
      <form method="post" onsubmit="return confirm('Permanently erase personal data for <?= htmlspecialchars($drEmail) ?>? This cannot be undone.');" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="datarights_erase">
        <input type="hidden" name="email" value="<?= htmlspecialchars($drEmail) ?>">
        <label>Type ERASE<input type="text" name="confirm" autocomplete="off" placeholder="ERASE" style="text-transform:uppercase"></label>
        <button class="btn" type="submit" style="background:transparent;border:1px solid #FCA5A5;color:#FCA5A5">Erase data</button>
      </form>
    </div>
  <?php endif; endif; ?>

<?php elseif ($view === 'media'): ?>
  <header class="top"><h1>Media &amp; Documents</h1></header>
  <?php if (isset($_GET['ok'])): ?><div class="card" style="border-color:#34D399"><strong style="color:#34D399">Saved.</strong></div><?php endif; ?>
  <?php if (isset($_GET['err'])): ?><div class="card" style="border-color:#FCA5A5"><strong style="color:#FCA5A5"><?= htmlspecialchars($_GET['err']) ?></strong></div><?php endif; ?>
  <?php
    $mediaClasses = mam_db_all("SELECT id, title FROM classes WHERE status = 'active' ORDER BY title");
    $resPage = mam_admin_page();
    $resTotal = (int)(mam_db_row('SELECT COUNT(*) AS n FROM class_resources')['n'] ?? 0);
    $resOff = ($resPage - 1) * 50;
    $resources = mam_db_all(
      "SELECT r.id, r.title, r.url, r.type, r.created_at, c.title AS class_title, u.name AS poster
         FROM class_resources r
         LEFT JOIN classes c ON c.id = r.class_id
         LEFT JOIN users u ON u.id = r.posted_by
        ORDER BY r.id DESC LIMIT 50 OFFSET $resOff"
    );
    $photos = mam_db_all("SELECT id, name, email, role, avatar_url FROM users WHERE avatar_url IS NOT NULL AND avatar_url <> '' ORDER BY role, name LIMIT 120");
    $shotCount = (int)(mam_db_row("SELECT COUNT(*) AS n FROM applications WHERE headshot_url IS NOT NULL AND headshot_url <> ''")['n'] ?? 0);
    $typeIcon = ['link' => 'link', 'sheet' => 'file-lines', 'video' => 'video', 'doc' => 'file-pdf'];
  ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:18px">
    <div class="kpi"><div class="kpi-label">Documents &amp; resources</div><div class="kpi-value"><?= number_format($resTotal) ?></div></div>
    <div class="kpi"><div class="kpi-label">Student &amp; staff photos</div><div class="kpi-value"><?= number_format(count($photos)) ?></div></div>
    <div class="kpi"><div class="kpi-label">Applicant headshots</div><div class="kpi-value"><?= number_format($shotCount) ?></div></div>
  </div>

  <div class="card">
    <h3>Add a document / resource</h3>
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="resource_add">
      <label>Class
        <select name="class_id" required>
          <option value="">Select a class</option>
          <?php foreach ($mediaClasses as $mc): ?><option value="<?= (int)$mc['id'] ?>"><?= htmlspecialchars($mc['title']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Title<input type="text" name="title" required maxlength="200" placeholder="e.g. Grade 3 scales PDF"></label>
      <label>URL<input type="url" name="url" required maxlength="500" placeholder="https://…"></label>
      <label>Type
        <select name="type"><option value="link">Link</option><option value="sheet">Sheet music</option><option value="video">Video</option><option value="doc">Document</option></select>
      </label>
      <div class="actions" style="grid-column:1/-1"><button class="btn" type="submit">Add resource</button>
        <?php if (!$mediaClasses): ?><span class="muted">Create a class first (Classes tab).</span><?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card">
    <h3>All documents &amp; resources</h3>
    <table>
      <thead><tr><th>Title</th><th>Class</th><th>Type</th><th>Added by</th><th>Added</th><th>Open</th><th></th></tr></thead>
      <tbody>
      <?php if (!$resources): ?><tr><td colspan="7" class="empty">No documents or resources yet.</td></tr><?php endif; ?>
      <?php foreach ($resources as $r): ?>
        <tr>
          <td><strong><?= htmlspecialchars((string)$r['title']) ?></strong></td>
          <td class="muted"><?= htmlspecialchars((string)($r['class_title'] ?? '—')) ?></td>
          <td><span class="pill"><i class="fa-solid fa-<?= htmlspecialchars($typeIcon[$r['type']] ?? 'link') ?>" aria-hidden="true"></i> <?= htmlspecialchars((string)$r['type']) ?></span></td>
          <td class="muted"><?= htmlspecialchars((string)($r['poster'] ?? '—')) ?></td>
          <td class="muted"><?= htmlspecialchars(substr((string)$r['created_at'], 0, 16)) ?></td>
          <td><a class="tiny btn ghost" href="<?= htmlspecialchars((string)$r['url']) ?>" target="_blank" rel="noopener">Open ↗</a></td>
          <td>
            <form class="inline" method="post" onsubmit="return confirm('Delete this resource?')">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="resource_delete">
              <input type="hidden" name="resource_id" value="<?= (int)$r['id'] ?>">
              <button class="tiny" type="submit" style="background:rgba(244,63,94,0.14);color:#FCA5A5;border:1px solid rgba(244,63,94,0.4)">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?= mam_admin_pager($resPage, $resTotal, 50) ?>
  </div>

  <div class="card">
    <h3>Photos (<?= count($photos) ?>)</h3>
    <p class="muted" style="margin:0 0 14px">Student &amp; staff portal photos. A student's application headshot becomes their avatar automatically; clearing it here reverts them to initials until they upload a new one.</p>
    <?php if (!$photos): ?><p class="empty">No photos on file yet.</p><?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px">
      <?php foreach ($photos as $ph): ?>
        <div style="text-align:center;padding:12px;border:1px solid var(--border);border-radius:12px">
          <img src="<?= htmlspecialchars((string)$ph['avatar_url']) ?>" alt="" loading="lazy" style="width:84px;height:84px;border-radius:50%;object-fit:cover;background:var(--bg-raised)" onerror="this.style.opacity=.3">
          <div style="font-weight:700;font-size:.85rem;margin-top:8px"><?= htmlspecialchars((string)($ph['name'] ?? '—')) ?></div>
          <div class="muted" style="font-size:.72rem"><?= htmlspecialchars((string)$ph['role']) ?></div>
          <form class="inline" method="post" style="margin-top:8px" onsubmit="return confirm('Remove this photo?')">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="avatar_clear">
            <input type="hidden" name="user_id" value="<?= (int)$ph['id'] ?>">
            <button class="tiny" type="submit" style="background:transparent;border:1px solid var(--border-strong)">Clear</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
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
    $perPage = 50; $page = mam_admin_page(); $total = (int)mam_admin_scalar('SELECT COUNT(*) FROM store_orders'); $off = ($page - 1) * $perPage;
    $orders = mam_db_all('SELECT * FROM store_orders ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $off);
?>
  <h1>Store Orders</h1>
  <?php if (isset($_GET['ok'])): ?><div class="card" style="border-color:#34D399"><strong style="color:#34D399">Updated.</strong></div><?php endif; ?>
  <table class="stack">
    <thead><tr><th>Reference</th><th>Customer</th><th>Items</th><th>Total</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($orders as $o):
      $its = json_decode((string)$o['items_json'], true) ?: [];
      $summary = implode(', ', array_map(function($i){ return $i['name'] . '×' . $i['qty']; }, array_slice($its, 0, 3)));
    ?>
      <tr>
        <td class="mono" data-label="Reference"><?= htmlspecialchars($o['reference']) ?></td>
        <td data-label="Customer">
          <strong><?= htmlspecialchars($o['customer_name']) ?></strong><br>
          <small style="color:var(--muted)"><?= htmlspecialchars($o['customer_email']) ?> <?= $o['customer_country'] ? '· ' . htmlspecialchars($o['customer_country']) : '' ?></small>
        </td>
        <td data-label="Items"><small><?= htmlspecialchars($summary) ?><?= count($its) > 3 ? '…' : '' ?></small></td>
        <td data-label="Total">$<?= number_format((float)$o['total_usd'], 2) ?></td>
        <td data-label="Status">
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
        <td data-label="Created"><small><?= htmlspecialchars((string)$o['created_at']) ?></small></td>
        <td data-label=""><a class="btn" style="padding:6px 10px;font-size:0.78rem" href="#" onclick="alert(<?= htmlspecialchars(json_encode(json_encode($its, JSON_PRETTY_PRINT))) ?>);return false">View items</a></td>
      </tr>
    <?php endforeach; if (!$orders): ?><tr><td colspan="7" class="empty">No store orders yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <?= mam_admin_pager($page, $total, $perPage) ?>

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
        <?php if ($groupName === 'CDN (Cloudinary)'): ?>
        <div style="background:rgba(232,150,31,.06);border:1px solid rgba(232,150,31,.2);border-radius:10px;padding:14px 16px;margin-bottom:14px;font-size:0.83rem;line-height:1.7;color:var(--muted)">
          <strong style="color:var(--gold)">Two values are required</strong> — both must be filled in for headshot uploads to work:<br>
          <ol style="margin:8px 0 8px 18px;padding:0">
            <li><strong style="color:var(--ink)">Cloud name</strong> — find it on your <a href="https://console.cloudinary.com/" target="_blank" rel="noopener" style="color:var(--gold)">Cloudinary dashboard</a> (top-left, e.g. <code>mam-academy</code>).</li>
            <li><strong style="color:var(--ink)">Upload preset</strong> — in Cloudinary go to <strong>Settings → Upload → Upload presets</strong>, click <em>Add upload preset</em>, set <em>Signing mode</em> to <strong>Unsigned</strong>, save, then copy the preset name here.</li>
          </ol>
          Without the preset name, photos are validated locally but <strong>not stored</strong> in Cloudinary.
        </div>
        <?php endif; ?>
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

<?php elseif ($view === 'automations'):
    // Lifecycle automations — the academy's "when X, do Y" rules, each with a
    // persisted on/off flag honoured at its trigger point (api/lib/automations.php).
    $autos = mam_automations_list();
    $activeCount = mam_automations_active_count();
    $totalCount  = count($autos);
    $autoGroups  = [];
    foreach ($autos as $a) { $autoGroups[$a['group']][] = $a; }
?>
  <header class="top">
    <h1>Automations</h1>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <span class="pill" style="background:rgba(232,150,31,.14);color:var(--gold);font-weight:700"><?= (int)$activeCount ?> of <?= (int)$totalCount ?> active</span>
      <form method="post" style="margin:0">
        <?= mam_csrf_field() ?>
        <input type="hidden" name="action" value="run_automations">
        <button class="btn ghost" type="submit" title="Run the scheduled sweeps (reminders, birthdays, drip) now — the same work the cron does"><i class="fa-solid fa-play" aria-hidden="true"></i> Run scheduled sweeps now</button>
      </form>
    </div>
  </header>
  <div class="card" style="max-width:820px">
    <div style="padding:16px 20px 4px;color:var(--muted);font-size:.9rem;line-height:1.6">
      Each rule runs a small "when this happens, do that" behaviour. Pause one to stop it firing —
      the flag takes effect immediately, everywhere the trigger runs. Rules tagged
      <span class="auto-tag live" style="vertical-align:middle">live</span> act the moment their trigger fires;
      <span class="auto-tag planned" style="vertical-align:middle">queued</span> rules persist their setting for the
      background runner (<code>api/tools/run-jobs.php</code>) as each handler adopts it.
    </div>
  </div>
  <?php foreach ($autoGroups as $groupName => $rules): ?>
  <div class="card" style="max-width:820px">
    <h3><?= htmlspecialchars($groupName) ?></h3>
    <?php foreach ($rules as $a):
      $on = (bool)$a['enabled'];
      $lastRun = $a['last_run_at'] ? 'Last ran ' . htmlspecialchars(substr((string)$a['last_run_at'], 0, 16)) . ($a['run_count'] ? ' · ' . (int)$a['run_count'] . ' run' . ($a['run_count'] === 1 ? '' : 's') : '') : '';
    ?>
      <div class="auto-rule">
        <span class="auto-ico"><i class="fa-solid fa-<?= htmlspecialchars($a['icon']) ?>" aria-hidden="true"></i></span>
        <div class="auto-body">
          <div class="auto-trig"><?= htmlspecialchars($a['trigger']) ?>
            <span class="auto-tag <?= $a['live'] ? 'live' : 'planned' ?>"><?= $a['live'] ? 'live' : 'queued' ?></span>
          </div>
          <div class="auto-act"><i class="fa-solid fa-arrow-right-long" aria-hidden="true"></i><?= htmlspecialchars($a['action']) ?></div>
          <?php if ($lastRun): ?><div class="auto-meta"><?= $lastRun ?></div><?php endif; ?>
        </div>
        <form method="post" action="" style="margin:0">
          <?= mam_csrf_field() ?>
          <input type="hidden" name="action" value="automation_toggle">
          <input type="hidden" name="key" value="<?= htmlspecialchars($a['key']) ?>">
          <input type="hidden" name="enabled" value="<?= $on ? '0' : '1' ?>">
          <button type="submit" class="auto-switch <?= $on ? 'on' : '' ?>" role="switch" aria-checked="<?= $on ? 'true' : 'false' ?>" aria-label="<?= $on ? 'Pause' : 'Enable' ?> automation: <?= htmlspecialchars($a['trigger']) ?>">
            <span class="track"><span class="knob"></span></span>
          </button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>

<?php elseif ($view === 'forms'): ?>
  <!-- ── Form Builder (v46) ──────────────────────────────────────────────── -->
  <header class="top">
    <h1><i class="fa-solid fa-wpforms" style="margin-right:9px;color:var(--gold)"></i>Form Builder</h1>
    <button class="btn btn-primary tiny" onclick="fbNewForm()"><i class="fa-solid fa-plus"></i> New Form</button>
  </header>
  <div id="fbMsg" style="display:none" class="banner ok"></div>

  <!-- Two-column layout: form list + editor -->
  <div style="display:grid;grid-template-columns:340px 1fr;gap:18px;align-items:start">
    <!-- Left: form list -->
    <div>
      <div style="margin-bottom:10px;display:flex;gap:8px;align-items:center">
        <select id="fbKindFilter" onchange="fbLoadForms()" class="input" style="flex:1;font-size:.82rem;padding:6px 8px">
          <option value="">All types</option>
          <option value="progress_report">Progress Reports</option>
          <option value="survey">Surveys</option>
        </select>
      </div>
      <div id="fbList" style="display:flex;flex-direction:column;gap:8px">
        <div class="muted" style="padding:14px;text-align:center">Loading…</div>
      </div>
    </div>

    <!-- Right: editor panel -->
    <div id="fbEditor" style="display:none">
      <div class="card" style="padding:20px">
        <h3 id="fbEdTitle" style="margin:0 0 16px;font-size:1rem">New Form</h3>
        <input type="hidden" id="fbId" value="">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px">
          <div>
            <label style="font-size:.78rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:5px">Title</label>
            <input id="fbTitle" class="input" type="text" placeholder="e.g. Post-Session Report" style="width:100%;box-sizing:border-box">
          </div>
          <div>
            <label style="font-size:.78rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:5px">Kind</label>
            <select id="fbKind" class="input" style="width:100%;box-sizing:border-box">
              <option value="progress_report">Progress Report</option>
              <option value="survey">Survey</option>
            </select>
          </div>
        </div>
        <div style="margin-bottom:12px">
          <label style="font-size:.78rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:5px">Description (optional)</label>
          <textarea id="fbDesc" class="input" rows="2" placeholder="Short description of what this form captures" style="width:100%;box-sizing:border-box;resize:vertical"></textarea>
        </div>

        <!-- AI Generate -->
        <div style="background:rgba(232,150,31,.07);border:1px solid rgba(232,150,31,.25);border-radius:10px;padding:14px;margin-bottom:16px">
          <div style="font-size:.78rem;font-weight:700;color:var(--gold);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px"><i class="fa-solid fa-wand-magic-sparkles"></i> AI Generate</div>
          <textarea id="fbAiPrompt" class="input" rows="2" placeholder="Describe what you want the form to assess, e.g. 'Piano lesson progress for a beginner student, including technique, rhythm, and practice habits'" style="width:100%;box-sizing:border-box;resize:vertical;margin-bottom:8px"></textarea>
          <button class="btn ghost tiny" onclick="fbAiGenerate()" id="fbAiBtn"><i class="fa-solid fa-sparkles"></i> Generate questions</button>
          <span id="fbAiStatus" style="font-size:.78rem;color:var(--muted);margin-left:10px"></span>
        </div>

        <!-- Questions -->
        <div style="margin-bottom:14px">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
            <span style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)">Questions</span>
            <button class="btn ghost tiny" onclick="fbAddQuestion()"><i class="fa-solid fa-plus"></i> Add field</button>
          </div>
          <div id="fbQuestions" style="display:flex;flex-direction:column;gap:8px"></div>
          <div id="fbNoQ" style="color:var(--muted);font-size:.83rem;padding:10px;text-align:center;border:1px dashed var(--border);border-radius:8px">No questions yet — add one or use AI generate.</div>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn btn-primary" onclick="fbSave()"><i class="fa-solid fa-floppy-disk"></i> Save Form</button>
          <button id="fbSetActiveBtn" class="btn ghost" onclick="fbSetActive()" style="display:none"><i class="fa-solid fa-star"></i> Set as Active Template</button>
          <button class="btn ghost" onclick="fbCancel()">Cancel</button>
          <button id="fbDeleteBtn" class="btn ghost" onclick="fbDelete()" style="display:none;color:#F87171;border-color:#F87171"><i class="fa-solid fa-trash"></i> Delete</button>
        </div>
      </div>
    </div>
  </div>

<script>
(function() {
  var _forms = [];

  function api(method, body) {
    var opts = { method: method, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': (document.cookie.match(/mam_csrf=([^;]+)/) || ['',''])[1] } };
    if (body) opts.body = JSON.stringify(body);
    return fetch('/api/admin/survey-forms', opts).then(function(r){ return r.json(); });
  }

  window.fbLoadForms = function() {
    var kind = document.getElementById('fbKindFilter').value;
    var url = '/api/admin/survey-forms' + (kind ? '?kind=' + encodeURIComponent(kind) : '');
    fetch(url).then(function(r){ return r.json(); }).then(function(d){
      _forms = d.forms || [];
      renderList();
    });
  };

  function renderList() {
    var list = document.getElementById('fbList');
    if (!_forms.length) { list.innerHTML = '<div class="muted" style="padding:14px;text-align:center">No forms yet.</div>'; return; }
    list.innerHTML = _forms.map(function(f) {
      var kindPill = f.kind === 'progress_report'
        ? '<span class="pill" style="background:rgba(52,211,153,.12);color:#34D399;font-size:.68rem">Progress Report</span>'
        : '<span class="pill" style="background:rgba(96,165,250,.12);color:#60A5FA;font-size:.68rem">Survey</span>';
      var activePill = f.status === 'active'
        ? '<span class="pill" style="background:rgba(232,150,31,.14);color:var(--gold);font-size:.68rem">Active</span>' : '';
      return '<div onclick="fbEditForm(' + f.id + ')" style="cursor:pointer;padding:12px 14px;border:1px solid var(--border);border-radius:10px;background:var(--card);transition:border-color .15s" onmouseenter="this.style.borderColor=\'var(--gold)\'" onmouseleave="this.style.borderColor=\'var(--border)\'">'
        + '<div style="font-weight:600;font-size:.9rem;margin-bottom:4px">' + escapeHtml(f.title) + '</div>'
        + '<div style="display:flex;gap:5px;align-items:center;flex-wrap:wrap">' + kindPill + activePill
        + '<span style="font-size:.72rem;color:var(--muted)">' + (f.response_count || 0) + ' response' + (f.response_count == 1 ? '' : 's') + '</span>'
        + '</div></div>';
    }).join('');
  }

  window.fbNewForm = function() {
    document.getElementById('fbId').value = '';
    document.getElementById('fbTitle').value = '';
    document.getElementById('fbKind').value = 'progress_report';
    document.getElementById('fbDesc').value = '';
    document.getElementById('fbAiPrompt').value = '';
    document.getElementById('fbSetActiveBtn').style.display = 'none';
    document.getElementById('fbDeleteBtn').style.display = 'none';
    document.getElementById('fbEdTitle').textContent = 'New Form';
    renderQuestions([]);
    document.getElementById('fbEditor').style.display = 'block';
  };

  window.fbEditForm = function(id) {
    var f = _forms.find(function(x){ return x.id == id; });
    if (!f) return;
    document.getElementById('fbId').value = f.id;
    document.getElementById('fbTitle').value = f.title || '';
    document.getElementById('fbKind').value = f.kind || 'survey';
    document.getElementById('fbDesc').value = f.description || '';
    document.getElementById('fbAiPrompt').value = '';
    document.getElementById('fbEdTitle').textContent = 'Edit: ' + escapeHtml(f.title);
    document.getElementById('fbSetActiveBtn').style.display = f.kind === 'progress_report' ? '' : 'none';
    document.getElementById('fbDeleteBtn').style.display = '';
    renderQuestions(f.questions || []);
    document.getElementById('fbEditor').style.display = 'block';
  };

  window.fbCancel = function() {
    document.getElementById('fbEditor').style.display = 'none';
  };

  /* ── Questions ── */
  var _questions = [];

  function renderQuestions(qs) {
    _questions = qs.map(function(q){ return Object.assign({}, q); });
    paintQuestions();
  }

  function paintQuestions() {
    var wrap = document.getElementById('fbQuestions');
    var noQ  = document.getElementById('fbNoQ');
    if (!_questions.length) { wrap.innerHTML = ''; noQ.style.display = ''; return; }
    noQ.style.display = 'none';
    wrap.innerHTML = _questions.map(function(q, i) {
      var optRow = (q.type === 'select' || q.type === 'checkbox')
        ? '<div style="margin-top:6px"><label style="font-size:.74rem;color:var(--muted)">Options (one per line)</label><textarea data-qi="' + i + '" data-f="options" class="input" rows="2" style="width:100%;box-sizing:border-box;margin-top:3px;font-size:.8rem;resize:vertical">' + escapeHtml((q.options || []).join('\n')) + '</textarea></div>' : '';
      return '<div style="border:1px solid var(--border);border-radius:8px;padding:12px;background:var(--surface)">'
        + '<div style="display:grid;grid-template-columns:1fr auto auto;gap:6px;align-items:start">'
        + '<input data-qi="' + i + '" data-f="label" value="' + escapeHtml(q.label || '') + '" class="input" placeholder="Question text" style="font-size:.85rem">'
        + '<select data-qi="' + i + '" data-f="type" class="input" style="font-size:.82rem;width:110px" onchange="fbQTypeChange(this,' + i + ')">'
        + ['text','textarea','rating','select','checkbox'].map(function(t){ return '<option value="' + t + '"' + (q.type===t?' selected':'') + '>' + t + '</option>'; }).join('')
        + '</select>'
        + '<div style="display:flex;gap:6px;align-items:center">'
        + '<label style="font-size:.78rem;white-space:nowrap;display:flex;gap:4px;align-items:center"><input type="checkbox" data-qi="' + i + '" data-f="required"' + (q.required?' checked':'') + ' onchange="fbQField(this)"> Required</label>'
        + '<button onclick="fbRemoveQ(' + i + ')" style="background:none;border:none;color:var(--muted);cursor:pointer;padding:2px 4px;font-size:.9rem" title="Remove">✕</button>'
        + '</div></div>'
        + optRow + '</div>';
    }).join('');
    wrap.querySelectorAll('input[data-f],textarea[data-f],select[data-f]').forEach(function(el) {
      if (el.dataset.f !== 'type' && el.dataset.f !== 'required') {
        el.addEventListener('input', function(){ fbQField(el); });
      } else if (el.dataset.f === 'type') {
        // already has onchange
      }
    });
  }

  window.fbQTypeChange = function(sel, i) {
    _questions[i].type = sel.value;
    if (sel.value !== 'select' && sel.value !== 'checkbox') delete _questions[i].options;
    paintQuestions();
  };

  window.fbQField = function(el) {
    var i = parseInt(el.dataset.qi, 10);
    var f = el.dataset.f;
    if (f === 'required') { _questions[i].required = el.checked; }
    else if (f === 'options') { _questions[i].options = el.value.split('\n').map(function(s){ return s.trim(); }).filter(Boolean); }
    else { _questions[i][f] = el.value; }
  };

  window.fbRemoveQ = function(i) {
    _questions.splice(i, 1);
    paintQuestions();
  };

  window.fbAddQuestion = function() {
    _questions.push({ label: '', type: 'text', required: false });
    paintQuestions();
    document.getElementById('fbNoQ').style.display = 'none';
  };

  /* ── AI Generate ── */
  window.fbAiGenerate = function() {
    var prompt = document.getElementById('fbAiPrompt').value.trim();
    if (!prompt) { fbShowMsg('Enter a prompt first.', 'err'); return; }
    var btn = document.getElementById('fbAiBtn');
    var status = document.getElementById('fbAiStatus');
    btn.disabled = true; status.textContent = 'Generating…';
    api('POST', { action: 'ai_generate', prompt: prompt, kind: document.getElementById('fbKind').value })
      .then(function(d) {
        btn.disabled = false;
        if (!d.ok) { status.textContent = 'Error: ' + (d.message || 'AI unavailable'); return; }
        status.textContent = 'Generated ' + d.questions.length + ' question(s) via ' + (d.model || d.provider || 'AI');
        renderQuestions(d.questions);
      }).catch(function() { btn.disabled = false; status.textContent = 'Request failed.'; });
  };

  /* ── Save ── */
  window.fbSave = function() {
    // Flush any un-blur inputs
    document.getElementById('fbQuestions').querySelectorAll('input[data-f],textarea[data-f]').forEach(function(el){
      if (el.type !== 'checkbox') { var i = parseInt(el.dataset.qi,10); var f = el.dataset.f; if (f==='options') _questions[i].options = el.value.split('\n').map(function(s){return s.trim();}).filter(Boolean); else _questions[i][f] = el.value; }
    });
    var id = document.getElementById('fbId').value;
    var body = {
      title:       document.getElementById('fbTitle').value.trim(),
      description: document.getElementById('fbDesc').value.trim() || null,
      kind:        document.getElementById('fbKind').value,
      questions:   _questions,
    };
    if (!body.title) { fbShowMsg('Title is required.', 'err'); return; }
    if (id) { body.action = 'update'; body.id = parseInt(id, 10); }
    else     { body.action = 'create'; }
    api('POST', body).then(function(d) {
      if (!d.ok) { fbShowMsg(d.message || 'Save failed.', 'err'); return; }
      fbShowMsg(id ? 'Form saved.' : 'Form created.', 'ok');
      if (!id && d.id) document.getElementById('fbId').value = d.id;
      fbLoadForms();
    }).catch(function(){ fbShowMsg('Save failed.', 'err'); });
  };

  /* ── Set Active ── */
  window.fbSetActive = function() {
    var id = parseInt(document.getElementById('fbId').value, 10);
    if (!id) return;
    api('POST', { action: 'set_active', id: id }).then(function(d) {
      if (d.ok) { fbShowMsg('Set as active progress report template.', 'ok'); fbLoadForms(); }
      else fbShowMsg(d.message || 'Failed.', 'err');
    });
  };

  /* ── Delete ── */
  window.fbDelete = function() {
    var id = parseInt(document.getElementById('fbId').value, 10);
    if (!id || !confirm('Delete this form? This cannot be undone.')) return;
    api('POST', { action: 'delete', id: id }).then(function(d) {
      if (d.ok) { fbShowMsg('Form deleted.', 'ok'); document.getElementById('fbEditor').style.display = 'none'; fbLoadForms(); }
      else fbShowMsg(d.message || 'Delete failed.', 'err');
    });
  };

  function fbShowMsg(msg, type) {
    var el = document.getElementById('fbMsg');
    el.textContent = msg;
    el.className = 'banner ' + (type === 'err' ? 'err' : 'ok');
    el.style.display = '';
    clearTimeout(el._t);
    el._t = setTimeout(function(){ el.style.display = 'none'; }, 4000);
  }

  function escapeHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // Init
  fbLoadForms();
})();
</script>

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

    <?php $sessions = function_exists('mam_sessions_list') ? mam_sessions_list((int)$me['id']) : []; ?>
    <div class="card" style="max-width:620px">
      <h3 style="margin:0 0 6px;color:var(--gold);font-size:0.92rem;letter-spacing:.04em;text-transform:uppercase">Active sessions</h3>
      <p class="muted" style="font-size:.8rem;margin:0 0 12px">Devices currently signed in to your account. Revoke any you don't recognise.</p>
      <table>
        <thead><tr><th>Device</th><th>IP</th><th>Signed in</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($sessions as $s): ?>
          <tr>
            <td style="max-width:260px"><?= htmlspecialchars($s['ua'] !== '' ? $s['ua'] : 'Unknown device') ?>
              <?php if ($s['current']): ?><span class="pill enrolled">this device</span><?php endif; ?></td>
            <td class="mono muted"><?= htmlspecialchars($s['ip']) ?></td>
            <td class="muted"><?= htmlspecialchars(substr($s['created_at'], 0, 16)) ?></td>
            <td><?php if (!$s['current']): ?>
              <form method="post" style="display:inline">
                <?= mam_csrf_field() ?>
                <input type="hidden" name="action" value="session_revoke">
                <input type="hidden" name="sid" value="<?= htmlspecialchars($s['id']) ?>">
                <button class="tiny btn ghost" type="submit">Revoke</button>
              </form>
            <?php else: ?><span class="muted">—</span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (count($sessions) > 1): ?>
        <form method="post" style="margin-top:12px" onsubmit="return confirm('Sign out of every other device?');">
          <?= mam_csrf_field() ?>
          <input type="hidden" name="action" value="session_revoke_others">
          <button class="btn ghost" type="submit">Sign out everywhere else</button>
        </form>
      <?php endif; ?>
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

<?php elseif ($view === 'id-cards'):
    $appUrl = rtrim((string)mam_env('APP_URL', ''), '/');
?>
  <header class="top"><h1>ID Cards</h1></header>
  <div class="card" style="margin-bottom:16px">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
      <input type="text" id="icSearch" placeholder="Search name, email, serial…" style="flex:1;min-width:180px;padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--bg2);color:var(--fg);font-size:14px">
      <select id="icStatus" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--bg2);color:var(--fg);font-size:14px">
        <option value="">All statuses</option>
        <option value="active">Active</option>
        <option value="revoked">Revoked</option>
      </select>
      <select id="icRole" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--bg2);color:var(--fg);font-size:14px">
        <option value="">All roles</option>
        <option value="student">Student</option>
        <option value="instructor">Instructor</option>
      </select>
      <button class="btn-sm btn-outline" onclick="icLoad(1)">Search</button>
    </div>
  </div>

  <div class="card" style="padding:0;overflow:hidden">
    <table class="stack" id="icTable">
      <thead><tr>
        <th>Holder</th>
        <th>Serial</th>
        <th>Role</th>
        <th>Session / Programme</th>
        <th>Issued</th>
        <th>Printed</th>
        <th>Status</th>
        <th>Actions</th>
      </tr></thead>
      <tbody id="icTbody"><tr><td colspan="8" style="padding:32px;text-align:center;color:var(--muted)">Loading…</td></tr></tbody>
    </table>
  </div>
  <div id="icPager" style="display:flex;gap:8px;justify-content:center;margin-top:16px;flex-wrap:wrap"></div>

  <div id="icConfirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9000;align-items:center;justify-content:center">
    <div style="background:var(--bg2);border-radius:14px;padding:28px 24px;max-width:380px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,0.3)">
      <h3 id="icModalTitle" style="margin:0 0 12px;color:var(--fg)">Confirm</h3>
      <p id="icModalBody" style="margin:0 0 24px;color:var(--muted);font-size:14px"></p>
      <div style="display:flex;gap:10px;justify-content:flex-end">
        <button class="btn-sm btn-outline" onclick="document.getElementById('icConfirmModal').style.display='none'">Cancel</button>
        <button class="btn-sm" id="icModalConfirm" style="background:var(--danger,#B91C1C);color:#fff;border:none;padding:8px 18px;border-radius:8px;font-weight:600;cursor:pointer">Confirm</button>
      </div>
    </div>
  </div>

<script>
(function(){
  var API    = '<?= $appUrl ?>/api';
  var CARD_URL = '<?= $appUrl ?>/card/';
  var csrfToken = (document.cookie.match(/(?:^|; )mam_csrf=([^;]+)/) || [])[1] || '';
  var currentPage = 1;

  function icLoad(page) {
    currentPage = page || 1;
    var q      = document.getElementById('icSearch').value.trim();
    var status = document.getElementById('icStatus').value;
    var role   = document.getElementById('icRole').value;
    var params = new URLSearchParams({page: currentPage});
    if (q)      params.set('q',      q);
    if (status) params.set('status', status);
    if (role)   params.set('role',   role);
    fetch(API + '/admin/id-cards?' + params)
      .then(r => r.json()).then(function(d) {
        var tb = document.getElementById('icTbody');
        if (!d.ok || !d.cards || !d.cards.length) {
          tb.innerHTML = '<tr><td colspan="8" style="padding:32px;text-align:center;color:var(--muted)">No cards found.</td></tr>';
          document.getElementById('icPager').innerHTML = '';
          return;
        }
        tb.innerHTML = d.cards.map(function(c) {
          var statusBadge = c.status === 'active'
            ? '<span class="badge badge-green">Active</span>'
            : '<span class="badge badge-red">Revoked</span>';
          var printedBadge = c.printed_at
            ? '<span class="badge badge-grey">Printed</span>'
            : '<span style="color:var(--muted);font-size:12px">Not printed</span>';
          var actions = [
            '<a href="' + CARD_URL + encodeURIComponent(c.serial) + '" target="_blank" class="btn-sm btn-outline" style="font-size:12px">View</a>',
          ];
          if (c.status === 'active') {
            actions.push('<button class="btn-sm btn-outline" style="font-size:12px" onclick="icConfirm(\'reissue\','+c.id+',\''+escHtml(c.holder_name)+'\',\''+escHtml(c.serial)+'\')">Reissue</button>');
            actions.push('<button class="btn-sm" style="font-size:12px;background:var(--danger,#B91C1C);color:#fff;border:none;border-radius:6px;padding:4px 10px;cursor:pointer" onclick="icConfirm(\'revoke\','+c.id+',\''+escHtml(c.holder_name)+'\',\''+escHtml(c.serial)+'\')">Revoke</button>');
            if (!c.printed_at) {
              actions.push('<button class="btn-sm btn-outline" style="font-size:12px" onclick="icAction(\'mark_printed\','+c.id+',null)">Mark Printed</button>');
            }
          }
          return '<tr>'
            + '<td data-label="Holder"><strong>' + escHtml(c.holder_name) + '</strong><br><span style="font-size:12px;color:var(--muted)">' + escHtml(c.holder_email||'') + '</span></td>'
            + '<td data-label="Serial"><code style="font-size:13px;letter-spacing:1px">' + escHtml(c.serial) + '</code></td>'
            + '<td data-label="Role" style="text-transform:capitalize">' + escHtml(c.role) + '</td>'
            + '<td data-label="Programme">' + escHtml(c.session_code||'—') + '</td>'
            + '<td data-label="Issued">' + (c.issued_at ? c.issued_at.substring(0,10) : '—') + '</td>'
            + '<td data-label="Printed">' + printedBadge + '</td>'
            + '<td data-label="Status">' + statusBadge + '</td>'
            + '<td data-label="Actions" style="white-space:nowrap">' + actions.join(' ') + '</td>'
            + '</tr>';
        }).join('');

        // Pagination
        var pg = document.getElementById('icPager');
        if (d.pages <= 1) { pg.innerHTML = ''; return; }
        var html = '';
        for (var i = 1; i <= d.pages; i++) {
          html += '<button class="btn-sm ' + (i === d.page ? 'btn-primary' : 'btn-outline') + '" onclick="icLoad(' + i + ')">' + i + '</button>';
        }
        pg.innerHTML = html;
      }).catch(function(e) {
        document.getElementById('icTbody').innerHTML = '<tr><td colspan="8" style="padding:24px;color:var(--danger)">Error loading cards.</td></tr>';
      });
  }

  function icConfirm(action, cardId, holderName, serial) {
    var modal = document.getElementById('icConfirmModal');
    var titles = { reissue: 'Reissue Card', revoke: 'Revoke Card' };
    var bodies = {
      reissue: 'Reissue the card for <strong>' + escHtml(holderName) + '</strong> (' + escHtml(serial) + ')? Their current card will be revoked and a new one emailed to them.',
      revoke:  'Revoke the card for <strong>' + escHtml(holderName) + '</strong> (' + escHtml(serial) + ')? This is immediate — the card will stop working for attendance.',
    };
    document.getElementById('icModalTitle').textContent = titles[action] || 'Confirm';
    document.getElementById('icModalBody').innerHTML    = bodies[action] || '';
    document.getElementById('icModalConfirm').onclick   = function() {
      modal.style.display = 'none';
      icAction(action, cardId, null);
    };
    modal.style.display = 'flex';
  }

  function icAction(action, cardId, reason) {
    fetch(API + '/admin/id-cards', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify({ action: action, card_id: cardId, reason: reason }),
    }).then(r => r.json()).then(function(d) {
      if (d.ok) {
        if (action === 'reissue' && d.new_serial) {
          showToast('Card reissued. New serial: ' + d.new_serial, 'success');
        } else {
          showToast('Done.', 'success');
        }
        icLoad(currentPage);
      } else {
        showToast(d.message || 'Action failed.', 'error');
      }
    });
  }

  function escHtml(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function showToast(msg, type) {
    var t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:' + (type==='success'?'#1A120B':'#B91C1C') + ';color:#fff;padding:12px 20px;border-radius:10px;font-size:14px;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,0.25)';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function(){ t.remove(); }, 3500);
  }

  // Expose for inline onclick handlers
  window.icLoad    = icLoad;
  window.icConfirm = icConfirm;
  window.icAction  = icAction;

  // Trigger on Enter in the search box
  document.getElementById('icSearch').addEventListener('keydown', function(e){ if (e.key === 'Enter') icLoad(1); });

  icLoad(1);
})();
</script>

<?php elseif ($view === 'email-branding'):
    $emailLogo = mam_setting('email_logo_url', '');
    $banners   = mam_db_all('SELECT * FROM promo_banners ORDER BY starts_at DESC, id DESC');
    $today     = date('Y-m-d');
?>
  <header class="top"><h1>Email Branding</h1></header>
  <?php if (isset($_GET['ok'])): ?>
    <div class="banner ok">Saved.</div>
  <?php elseif (isset($_GET['err'])): ?>
    <div class="banner err"><?= htmlspecialchars((string)$_GET['err']) ?></div>
  <?php endif; ?>

  <div class="card" style="max-width:700px">
    <h2 style="margin:0 0 6px;font-size:1.05rem">Email Logo</h2>
    <p class="muted" style="margin:0 0 18px;font-size:0.85rem">
      Shown in every outgoing email in place of the text wordmark. Provide a direct URL to a hosted
      PNG or JPG — no SVG (not supported by email clients). Leave blank to keep the text wordmark.
    </p>
    <form method="POST" action="">
      <?= mam_csrf_field() ?>
      <input type="hidden" name="action" value="save_email_branding">
      <label style="display:block">
        <span style="display:block;font-size:0.82rem;font-weight:600;margin-bottom:6px;color:var(--muted)">Logo URL</span>
        <input type="url" name="email_logo_url" value="<?= htmlspecialchars($emailLogo) ?>"
               placeholder="https://cdn.example.com/mam-logo.png" style="width:100%;max-width:500px">
      </label>
      <div style="margin-top:14px;display:flex;gap:10px;align-items:center">
        <button class="btn" type="submit">Save logo</button>
        <?php if ($emailLogo): ?>
          <span class="muted" style="font-size:0.8rem">Current: <code><?= htmlspecialchars(mb_strimwidth($emailLogo, 0, 60, '…')) ?></code></span>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card" style="max-width:960px">
    <h2 style="margin:0 0 8px;font-size:1.05rem">Promotional Banners</h2>
    <p class="muted" style="margin:0 0 20px;font-size:0.85rem">
      A banner appears after the header rule in every outgoing email during the configured window.
      When multiple banners are active, the most recently started one wins.
    </p>

    <?php if ($banners): ?>
    <table class="stack" style="margin-bottom:28px">
      <thead><tr><th>Title</th><th>Dates</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($banners as $b):
          $live = $b['active'] == 1
              && ($b['starts_at'] === null || $b['starts_at'] <= $today)
              && ($b['ends_at']   === null || $b['ends_at']   >= $today);
      ?>
        <tr>
          <td data-label="Title">
            <strong><?= htmlspecialchars((string)$b['title']) ?></strong>
            <div class="muted" style="font-size:0.8rem;margin-top:2px"><?= htmlspecialchars(mb_strimwidth((string)$b['body'], 0, 70, '…')) ?></div>
          </td>
          <td data-label="Dates">
            <?php if ($b['starts_at'] || $b['ends_at']): ?>
              <?= htmlspecialchars((string)($b['starts_at'] ?? '—')) ?> &rarr; <?= htmlspecialchars((string)($b['ends_at'] ?? 'no end')) ?>
            <?php else: ?><span class="muted">Always active</span><?php endif; ?>
          </td>
          <td data-label="Status">
            <?php if (!$b['active']): ?>
              <span class="pill" style="background:#6b7280;color:#fff">Inactive</span>
            <?php elseif ($live): ?>
              <span class="pill" style="background:#16a34a;color:#fff">Live now</span>
            <?php elseif ($b['starts_at'] && $b['starts_at'] > $today): ?>
              <span class="pill" style="background:#d97706;color:#fff">Scheduled</span>
            <?php else: ?>
              <span class="pill" style="background:#6b7280;color:#fff">Ended</span>
            <?php endif; ?>
          </td>
          <td data-label="Actions" style="white-space:nowrap">
            <button type="button" class="btn btn-sm"
              onclick="ebEdit(<?= (int)$b['id'] ?>,<?= htmlspecialchars(json_encode($b, JSON_HEX_QUOT|JSON_HEX_TAG), ENT_QUOTES) ?>)">Edit</button>
            <form method="POST" action="" style="display:inline"
                  onsubmit="return confirm('Delete “<?= htmlspecialchars(addslashes((string)$b['title'])) ?>”?')">
              <?= mam_csrf_field() ?>
              <input type="hidden" name="action" value="delete_promo_banner">
              <input type="hidden" name="banner_id" value="<?= (int)$b['id'] ?>">
              <button class="btn btn-sm" type="submit" style="background:#dc2626;border-color:#dc2626;color:#fff">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
      <p class="muted" style="margin:0 0 24px">No banners yet. Create one below.</p>
    <?php endif; ?>

    <h3 id="ebFormTitle" style="margin:0 0 16px;font-size:0.95rem;border-top:1px solid var(--border);padding-top:20px">Create new banner</h3>
    <form method="POST" action="" id="ebForm">
      <?= mam_csrf_field() ?>
      <input type="hidden" name="action" value="save_promo_banner">
      <input type="hidden" name="banner_id" id="ebBannerId" value="">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px">
        <label style="grid-column:1/-1">
          <span style="display:block;font-size:0.82rem;font-weight:600;margin-bottom:6px;color:var(--muted)">Title (internal label)</span>
          <input type="text" name="banner_title" id="ebTitle" placeholder="e.g. Summer 2026 campaign" style="width:100%" required>
        </label>
        <label style="grid-column:1/-1">
          <span style="display:block;font-size:0.82rem;font-weight:600;margin-bottom:6px;color:var(--muted)">Banner text</span>
          <textarea name="banner_body" id="ebBody" rows="3" required
            placeholder="e.g. Summer camp applications are now open. Secure your spot before places run out."
            style="width:100%;resize:vertical"></textarea>
        </label>
        <label>
          <span style="display:block;font-size:0.82rem;font-weight:600;margin-bottom:6px;color:var(--muted)">CTA button label (optional)</span>
          <input type="text" name="banner_cta_label" id="ebCtaLabel" placeholder="e.g. Apply now" style="width:100%">
        </label>
        <label>
          <span style="display:block;font-size:0.82rem;font-weight:600;margin-bottom:6px;color:var(--muted)">CTA button URL (optional)</span>
          <input type="url" name="banner_cta_url" id="ebCtaUrl" placeholder="https://…" style="width:100%">
        </label>
        <label>
          <span style="display:block;font-size:0.82rem;font-weight:600;margin-bottom:6px;color:var(--muted)">Start date (leave blank for no start restriction)</span>
          <input type="date" name="banner_starts_at" id="ebStartsAt" style="width:100%">
        </label>
        <label>
          <span style="display:block;font-size:0.82rem;font-weight:600;margin-bottom:6px;color:var(--muted)">End date (leave blank for no expiry)</span>
          <input type="date" name="banner_ends_at" id="ebEndsAt" style="width:100%">
        </label>
        <label>
          <span style="display:block;font-size:0.82rem;font-weight:600;margin-bottom:6px;color:var(--muted)">Background color</span>
          <div style="display:flex;align-items:center;gap:8px">
            <input type="color" name="banner_bg_color" id="ebBgColor" value="#FEF3C7"
                   style="width:44px;height:34px;padding:2px;cursor:pointer;border:1px solid var(--border,#3a2818);border-radius:6px;background:none">
            <code id="ebBgHex" style="font-size:0.82rem;color:var(--muted)">#FEF3C7</code>
          </div>
        </label>
        <label style="display:flex;align-items:center;gap:10px;padding-top:24px">
          <input type="checkbox" name="banner_active" id="ebActive" value="1" checked style="width:16px;height:16px;cursor:pointer">
          <span style="font-size:0.9rem">Active</span>
        </label>
      </div>
      <div style="display:flex;gap:10px;align-items:center">
        <button class="btn" type="submit" id="ebSubmitBtn">Create banner</button>
        <button type="button" class="btn" id="ebCancelBtn" style="display:none" onclick="ebReset()">Cancel</button>
      </div>
    </form>
  </div>

  <script>
  function ebEdit(id, b) {
    document.getElementById('ebFormTitle').textContent = 'Edit banner';
    document.getElementById('ebSubmitBtn').textContent = 'Save changes';
    document.getElementById('ebCancelBtn').style.display = '';
    document.getElementById('ebBannerId').value  = id;
    document.getElementById('ebTitle').value     = b.title     || '';
    document.getElementById('ebBody').value      = b.body      || '';
    document.getElementById('ebCtaLabel').value  = b.cta_label || '';
    document.getElementById('ebCtaUrl').value    = b.cta_url   || '';
    document.getElementById('ebStartsAt').value  = b.starts_at || '';
    document.getElementById('ebEndsAt').value    = b.ends_at   || '';
    var bg = b.bg_color || '#FEF3C7';
    document.getElementById('ebBgColor').value   = bg;
    document.getElementById('ebBgHex').textContent = bg;
    document.getElementById('ebActive').checked  = b.active == 1;
    document.getElementById('ebForm').scrollIntoView({behavior:'smooth'});
  }
  function ebReset() {
    document.getElementById('ebFormTitle').textContent = 'Create new banner';
    document.getElementById('ebSubmitBtn').textContent = 'Create banner';
    document.getElementById('ebCancelBtn').style.display = 'none';
    document.getElementById('ebBannerId').value = '';
    document.getElementById('ebForm').reset();
    document.getElementById('ebBgHex').textContent = '#FEF3C7';
    document.getElementById('ebActive').checked = true;
  }
  document.getElementById('ebBgColor').addEventListener('input', function() {
    document.getElementById('ebBgHex').textContent = this.value;
  });
  </script>

<?php else: ?>
  <p>Unknown view.</p>
<?php endif; ?>
</main>
<script>
(function () {
  // Action feedback you can actually see: ?ok= / ?err= become toasts (the
  // static banners remain for no-JS). String values carry the real message;
  // the params are then stripped so refresh/back doesn't re-announce.
  var params = new URLSearchParams(location.search);
  function toast(msg, kind) {
    var stack = document.querySelector('.mam-toast-stack');
    if (!stack) { stack = document.createElement('div'); stack.className = 'mam-toast-stack'; document.body.appendChild(stack); }
    var t = document.createElement('div');
    t.className = 'mam-toast ' + kind;
    t.setAttribute('role', 'status');
    t.textContent = (kind === 'ok' ? '✓ ' : '⚠ ') + msg;
    stack.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('in'); });
    setTimeout(function () { t.classList.remove('in'); setTimeout(function () { t.remove(); }, 350); }, 4600);
  }
  // Mobile: hamburger toggles the off-canvas sidebar. A nav tap or scrim/Esc
  // closes it.
  (function () {
    var t = document.getElementById('navToggle'), s = document.getElementById('navScrim');
    if (!t) return;
    function set(open) { document.body.classList.toggle('nav-open', open); t.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    t.addEventListener('click', function () { set(!document.body.classList.contains('nav-open')); });
    if (s) s.addEventListener('click', function () { set(false); });
    var nav = document.querySelector('aside nav');
    if (nav) nav.addEventListener('click', function (e) { if (e.target.closest('a')) set(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && document.body.classList.contains('nav-open')) set(false); });
  })();
  // Applications "Manage" renders as a right slide-in drawer (server-rendered
  // when ?app=N). Dismiss via scrim click or Esc → back to the list.
  (function () {
    var scrim = document.querySelector('.mam-drawer-scrim');
    if (!scrim) return;
    var param = scrim.getAttribute('data-param') || 'app';
    document.body.style.overflow = 'hidden';
    var panel = scrim.querySelector('.mam-drawer');
    if (panel) { panel.setAttribute('tabindex', '-1'); setTimeout(function () { panel.focus(); }, 30); }
    function close() { var u = new URLSearchParams(location.search); u.delete(param); location.href = '?' + u.toString(); }
    scrim.addEventListener('mousedown', function (e) { if (e.target === scrim) close(); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !document.querySelector('.cmdk-scrim.open')) close();
    });
  })();
  var ok = params.get('ok'), err = params.get('err');
  if (ok !== null) toast(ok && ok !== '1' ? ok : 'Saved.', 'ok');
  if (err !== null && err !== '') toast(err, 'err');
  if (ok !== null || err !== null) {
    params.delete('ok'); params.delete('err');
    try { history.replaceState(null, '', location.pathname + (params.toString() ? '?' + params.toString() : '') + location.hash); } catch (e) {}
  }
  // ── Command palette (⌘K / Ctrl+K, or '/') ──────────────────────────
  // Fuzzy-jump to any section the current admin can reach, run a quick action,
  // or fall through to a full search. Fully keyboard-navigable (↑/↓/Enter/Esc).
  var CMD = <?php
    $cmdGo = [];
    foreach ($navGroups as $__g => $__items) {
      foreach ($__items as $__k => $__m) {
        if (!$canView($__k)) continue;
        $cmdGo[] = ['label' => $__m[0], 'icon' => $__m[1], 'url' => '?v=' . $__k, 'group' => 'Go to'];
      }
    }
    $cmdActions = [
      ['label' => 'Export applications (CSV)', 'icon' => 'file-csv', 'url' => '?v=export&type=applications.csv', 'group' => 'Action'],
      ['label' => 'Export payments (CSV)', 'icon' => 'file-csv', 'url' => '?v=export&type=payments.csv', 'group' => 'Action'],
      ['label' => 'View public site', 'icon' => 'arrow-up-right-from-square', 'url' => '/', 'group' => 'Action'],
    ];
    echo json_encode(array_merge($cmdGo, $cmdActions), JSON_UNESCAPED_SLASHES);
  ?>;
  (function () {
    var scrim = document.createElement('div'); scrim.className = 'cmdk-scrim'; scrim.setAttribute('role', 'dialog'); scrim.setAttribute('aria-modal', 'true');
    scrim.innerHTML = '<div class="cmdk"><div class="cmdk-in"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>'
      + '<input type="text" placeholder="Search or jump to…" aria-label="Command palette" autocomplete="off" spellcheck="false">'
      + '<span style="font-size:.64rem;color:var(--faint);border:1px solid var(--border);border-radius:6px;padding:2px 6px">ESC</span></div>'
      + '<div class="cmdk-list" role="listbox"></div></div>';
    document.body.appendChild(scrim);
    var input = scrim.querySelector('input'), list = scrim.querySelector('.cmdk-list');
    var open = false, sel = 0, results = [];
    function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    // Subsequence match — "sto" matches "Store Orders" — with a light score so
    // tighter matches float up.
    function score(q, s) {
      q = q.toLowerCase(); s = s.toLowerCase();
      if (!q) return 1;
      if (s.indexOf(q) >= 0) return 100 - s.indexOf(q);
      var qi = 0; for (var i = 0; i < s.length && qi < q.length; i++) if (s[i] === q[qi]) qi++;
      return qi === q.length ? 1 : 0;
    }
    function render() {
      var q = input.value.trim();
      results = CMD.map(function (e) { return { e: e, sc: score(q, e.label) }; })
                   .filter(function (r) { return r.sc > 0; })
                   .sort(function (a, b) { return b.sc - a.sc; })
                   .map(function (r) { return r.e; });
      if (q) results.push({ label: 'Search for “' + q + '”', icon: 'magnifying-glass', url: '?v=search&q=' + encodeURIComponent(q), group: 'Search' });
      sel = 0;
      if (!results.length) { list.innerHTML = '<div class="cmdk-empty">No matches.</div>'; return; }
      var html = '', lastGroup = '';
      results.forEach(function (e, i) {
        if (e.group !== lastGroup) { html += '<div class="cmdk-sec">' + esc(e.group) + '</div>'; lastGroup = e.group; }
        html += '<div class="cmdk-item' + (i === sel ? ' sel' : '') + '" data-i="' + i + '" role="option">'
             + '<i class="fa-solid fa-' + esc(e.icon) + '" aria-hidden="true"></i><span>' + esc(e.label) + '</span>'
             + (e.group === 'Go to' ? '' : '<span class="tag">' + esc(e.group) + '</span>') + '</div>';
      });
      list.innerHTML = html;
    }
    function move(d) {
      var items = list.querySelectorAll('.cmdk-item'); if (!items.length) return;
      items[sel] && items[sel].classList.remove('sel');
      sel = (sel + d + items.length) % items.length;
      items[sel].classList.add('sel'); items[sel].scrollIntoView({ block: 'nearest' });
    }
    function go(i) { var e = results[i]; if (e) location.href = e.url; }
    var lastFocus = null;
    function show() { lastFocus = document.activeElement; open = true; scrim.classList.add('open'); input.value = ''; render(); setTimeout(function () { input.focus(); }, 0); }
    function hide() { open = false; scrim.classList.remove('open'); if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus(); }
    var sb = document.getElementById('atbSearch'); if (sb) sb.addEventListener('click', show);
    input.addEventListener('input', render);
    list.addEventListener('click', function (e) { var it = e.target.closest('.cmdk-item'); if (it) go(+it.getAttribute('data-i')); });
    scrim.addEventListener('mousedown', function (e) { if (e.target === scrim) hide(); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
      else if (e.key === 'Enter') { e.preventDefault(); go(sel); }
      else if (e.key === 'Escape') { e.preventDefault(); hide(); }
      else if (e.key === 'Tab') { e.preventDefault(); } // trap focus in the palette
    });
    document.addEventListener('keydown', function (e) {
      var typing = /^(INPUT|TEXTAREA|SELECT)$/.test((document.activeElement || {}).tagName || '');
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); open ? hide() : show(); }
      else if (e.key === '/' && !typing && !open) { e.preventDefault(); show(); }
      else if (e.key === 'Escape' && open) { hide(); }
    });
  })();
})();
</script>
<script>
// Keyboard-shortcuts help — press ? (accessible modal; Esc/scrim closes, focus
// returns to the opener).
(function () {
  var scrim = document.createElement('div');
  scrim.className = 'kbd-scrim'; scrim.setAttribute('role', 'dialog'); scrim.setAttribute('aria-modal', 'true'); scrim.setAttribute('aria-label', 'Keyboard shortcuts');
  var rows = [
    ['⌘K  /  Ctrl K', 'Command palette — jump to any section or action'],
    ['/', 'Open the command palette'],
    ['?', 'Show this shortcuts help'],
    ['Esc', 'Close the palette, a drawer, or this dialog']
  ];
  scrim.innerHTML = '<div class="kbd-modal"><h2>Keyboard shortcuts</h2><p class="sub">Move around MAM Admin without the mouse.</p>'
    + rows.map(function (r) { return '<div class="kbd-row"><span>' + r[1] + '</span><kbd>' + r[0] + '</kbd></div>'; }).join('')
    + '<div style="text-align:right;margin-top:16px"><button type="button" class="btn ghost" id="kbdClose">Close</button></div></div>';
  document.body.appendChild(scrim);
  var last = null;
  function open() { last = document.activeElement; scrim.classList.add('open'); var b = document.getElementById('kbdClose'); if (b) b.focus(); }
  function close() { scrim.classList.remove('open'); if (last && last.focus) last.focus(); }
  scrim.addEventListener('mousedown', function (e) { if (e.target === scrim) close(); });
  document.getElementById('kbdClose').addEventListener('click', close);
  document.addEventListener('keydown', function (e) {
    var typing = /^(INPUT|TEXTAREA|SELECT)$/.test((document.activeElement || {}).tagName || '');
    var openDialog = document.querySelector('.cmdk-scrim.open') || document.querySelector('.mam-drawer-scrim');
    if (e.key === '?' && !typing && !openDialog && !scrim.classList.contains('open')) { e.preventDefault(); open(); }
    else if (e.key === 'Escape' && scrim.classList.contains('open')) { e.preventDefault(); close(); }
  });
})();

/* ── Generate shareable learner progress report link ──────────────────── */
window.genProgressLink = function (studentId, studentName) {
  var label = prompt('Progress report label for ' + studentName + ':\n(e.g. "Sent to Mr. Balogun — July 2026")', 'Learner report — ' + new Date().toLocaleDateString('en-GB', {month:'short',year:'numeric'}));
  if (label === null) return;
  var expiresIn = prompt('Expiry (days from today, blank = never):','90');
  var expiresAt = null;
  if (expiresIn && parseInt(expiresIn, 10) > 0) {
    var d = new Date(); d.setDate(d.getDate() + parseInt(expiresIn, 10));
    expiresAt = d.toISOString().slice(0, 19).replace('T', ' ');
  }
  var btn = event.target.closest('button');
  // Disable and restore without innerHTML — use a data attribute to track state
  function setBusy(b) { if (btn) { btn.disabled = true; btn.setAttribute('data-orig-label', btn.textContent); btn.textContent = '…'; } }
  function setIdle(b) { if (btn) { btn.disabled = false; btn.textContent = btn.getAttribute('data-orig-label') || 'Progress Link'; } }
  setBusy();

  // Use the CSRF from the page's meta or fall back to a fresh fetch
  function doPost(t) {
    fetch('/api/admin/progress-report', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': t },
      body: JSON.stringify({ action: 'generate', student_id: studentId, label: label, expires_at: expiresAt })
    }).then(function (r) { return r.json(); }).then(function (j) {
      setIdle();
      if (!j.ok || !j.url) { alert('Error: ' + (j.error || 'unknown')); return; }
      var msg = 'Progress report link generated!\n\n' + j.url + '\n\nThe student\'s enrollment ID can also be used at /learner-report\n\n(Click OK to copy the link.)';
      if (window.confirm(msg)) {
        navigator.clipboard ? navigator.clipboard.writeText(j.url) : (function(){var ta=document.createElement('textarea');ta.value=j.url;document.body.appendChild(ta);ta.select();document.execCommand('copy');document.body.removeChild(ta);})();
      }
    }).catch(function (e) {
      setIdle();
      alert('Could not generate link: ' + e.message);
    });
  }

  if (token) { doPost(token); return; }
  fetch('/api/csrf', { credentials: 'same-origin' }).then(r => r.json()).then(j => doPost(j.token)).catch(function () {
    if (btn) { btn.disabled = false; btn.textContent = btn.getAttribute('data-orig-label') || 'Progress Link'; }
    alert('Could not get security token. Try refreshing the page.');
  });
};

/* ── Instructor fine resolve / waive ──────────────────────────────────── */
window.fineAct = function (fineId, action) {
  var label = action === 'resolve' ? 'Resolve' : 'Waive';
  var note  = prompt(label + ' fine #' + fineId + ' — add a note (optional):', '');
  if (note === null) return;
  var btn = event.target;
  btn.disabled = true;
  var csrf = (document.cookie.match(/mam_csrf=([^;]+)/) || ['',''])[1];
  fetch('/api/admin/fines', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
    body: JSON.stringify({ action: action, fine_id: fineId, note: note })
  }).then(function (r) { return r.json(); }).then(function (j) {
    if (j.ok) {
      var row = document.getElementById('fine-row-' + fineId);
      if (row) row.style.opacity = '0.4';
      location.reload();
    } else {
      btn.disabled = false;
      alert('Error: ' + (j.error || 'unknown'));
    }
  }).catch(function (e) { btn.disabled = false; alert('Request failed: ' + e.message); });
};

/* ── Assign enrollment reference to a student who doesn't have one yet ─── */
window.generateEnrollRef = function (studentId, btn) {
  btn.disabled = true; btn.textContent = '…';
  function doPost(t) {
    fetch('/api/admin/progress-report', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': t },
      body: JSON.stringify({ action: 'generate_ref', student_id: studentId })
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok || !j.enrollment_ref) {
        btn.disabled = false; btn.textContent = 'Assign ID';
        alert('Error: ' + (j.error || 'unknown'));
        return;
      }
      // Replace the button with the new ref inline, no page reload
      var refSpan = document.createElement('span');
      refSpan.style.cssText = 'color:var(--gold-bright);letter-spacing:0.04em';
      refSpan.title = 'Student enrollment ID';
      refSpan.textContent = j.enrollment_ref;
      btn.parentNode.replaceChild(refSpan, btn);
    }).catch(function (e) {
      btn.disabled = false; btn.textContent = 'Assign ID';
      alert('Could not assign ID: ' + e.message);
    });
  }
  if (typeof token !== 'undefined' && token) { doPost(token); return; }
  fetch('/api/csrf', { credentials: 'same-origin' }).then(function(r){return r.json();}).then(function(j){doPost(j.token);}).catch(function(){
    btn.disabled = false; btn.textContent = 'Assign ID';
    alert('Could not get security token.');
  });
};

/* ── Bulk-generate enrollment refs for all students missing one ─────────── */
window.backfillEnrollRefs = function (btn) {
  if (!confirm('Generate MAM/YY/NNN IDs for all active students who don\'t have one yet?')) return;
  btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Working…';
  var t = (document.cookie.match(/mam_csrf=([^;]+)/) || ['',''])[1];
  fetch('/api/admin/progress-report', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': t },
    body: JSON.stringify({ action: 'backfill_refs' })
  }).then(function (r) { return r.json(); }).then(function (j) {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-id-card"></i> Backfill student IDs';
    if (!j.ok) { alert('Error: ' + (j.error || 'unknown')); return; }
    alert('Done — ' + j.generated + ' ID' + (j.generated !== 1 ? 's' : '') + ' generated' + (j.errors ? ', ' + j.errors + ' errors (see server log)' : '') + '.');
    if (j.generated > 0) location.reload();
  }).catch(function (e) {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-id-card"></i> Backfill student IDs';
    alert('Could not run backfill: ' + e.message);
  });
};
</script>
</body>
</html>
