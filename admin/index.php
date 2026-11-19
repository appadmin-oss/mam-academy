<?php
/**
 * MAM Academy — admin actions and downloads.
 *
 * The admin INTERFACE is admin/console.php (and admin/phone.php on a phone).
 * This file draws no screens: it is the one place every admin write is
 * carried out — both consoles post their forms here — plus the downloads under
 * ?v=export. Any other GET is redirected to the console (see the foot of the
 * file). Protected by a logged-in admin session, or HTTP Basic Auth from the
 * env credentials as the break-glass path.
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
        if (!defined('MAM_HTML_RESPONSE')) define('MAM_HTML_RESPONSE', true);
        exit;
    }
}

// Stop the JSON content type set by bootstrap and serve HTML for views
header_remove('Content-Type');
header('Content-Type: text/html; charset=utf-8');
// This is a server-rendered HTML page, not a JSON route — tell the bootstrap
// shutdown handler so it doesn't append a {"error":"no_response"} envelope
// after our markup (which also truncated some views under output buffering).
if (!defined('MAM_HTML_RESPONSE')) define('MAM_HTML_RESPONSE', true);

$view  = $_GET['v'] ?? 'overview';
$flash = null;

// A session admin on a temporary password must set a new one before using the
// dashboard. Sends them to My Account (GET only, so the change POST still runs).
if (isset($adminUser) && $adminUser && (int)($adminUser['must_change_password'] ?? 0) === 1
    && $view !== 'account' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Location: /admin/console.php?v=account'); exit;
}

/** Compose a name out of customer first/last (portable across SQLite + MySQL). */
function mam_name(?string $first, ?string $last): string {
    $n = trim((string)$first . ' ' . (string)$last);
    return $n === '' ? '—' : $n;
}

/**
 * Redirect a console POST back to the console.
 *
 * The redesigned console (admin/console.php) posts every action to THIS file so
 * that there is one implementation of each action — one capability check, one
 * audit call, one set of transition rules. But each handler below ends with its
 * own `header('Location: ?v=…'); exit;`, naming a legacy view. Threading a
 * "come back here" parameter through ~140 branches would be 140 chances to
 * forget one.
 *
 * Instead: every handler exits having written a Location header and NO body, so
 * headers are still mutable at shutdown. Rewrite it there, once. The ?ok=/?err=
 * message the handler chose is carried over verbatim — it is the whole point of
 * the redirect — and anything else in the query string is dropped, because the
 * console screen re-reads its own data.
 *
 * Only a relative `?…` Location is rewritten. A handler that redirects
 * somewhere absolute (a download, an external provider) means it, and must not
 * be hijacked.
 */
function mam_admin_console_return(string $screen): void {
    // Validated against the console's OWN list of screens, not merely sanitised:
    // a stripped-down version of a hostile value ("../evil?x=1" -> "evilx") is
    // safe but still lands the admin somewhere that does not exist. An
    // unrecognised name means this was not a console POST, so leave the
    // handler's own redirect alone.
    $screen = strtolower(trim($screen));

    /* The phone subset (admin/phone.php) posts to the same handlers, so it
       reuses this hook rather than growing a second one — there is one
       implementation of "waive" and one place that decides where a handler
       returns to. `phone:<panel>` is validated against that surface's own
       panel list, exactly as a console screen is validated against
       mam_console_screens(): an unrecognised name means this was not a POST
       from either console, so the handler's own redirect is left alone. */
    $base = '/admin/console.php?';
    $key  = 'v';
    if (strncmp($screen, 'phone:', 6) === 0) {
        $screen = substr($screen, 6);
        if (!function_exists('mam_phone_panel_ok') || !mam_phone_panel_ok($screen)) return;
        $base = '/admin/phone.php?'; $key = 'p';
    } elseif (!function_exists('mam_console_screens') || !in_array($screen, mam_console_screens(), true)) {
        return;
    }
    register_shutdown_function(function () use ($screen, $base, $key) {
        if (headers_sent()) return;
        $loc = null;
        foreach (headers_list() as $h) {
            if (stripos($h, 'Location:') === 0) $loc = trim(substr($h, strlen('Location:')));
        }
        if ($loc === null || $loc === '' || $loc[0] !== '?') return;
        $q = [];
        parse_str(ltrim($loc, '?'), $q);
        $keep = [$key => $screen];
        foreach (['ok', 'err'] as $k) if (isset($q[$k]) && is_string($q[$k])) $keep[$k] = $q[$k];
        header_remove('Location');
        header('Location: ' . $base . http_build_query($keep));
    });
}

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
    /* ⚠️ These two guards were INERT until 2026-09-29, and the way they failed
       is worth keeping in mind. This page defines a constant to stop the API
       shutdown net appending a JSON envelope to its HTML; that same constant
       made `mam_json()` return instead of exit, so `mam_fail()` here logged
       nothing, stopped nothing, and execution ran straight on into the
       handler. An expired token answered 302 — byte for byte what a
       successful save answers. See MAM_HTML_RESPONSE in bootstrap.php.

       They answer with a REDIRECT rather than mam_fail's JSON because every
       caller here is a browser posting a form. A JSON 403 rendered as raw
       text in the tab tells an admin nothing about what to do; worse, the
       previous silent 302 told them the save had worked. A stale token is
       overwhelmingly a tab left open past the two-hour window, so the message
       says exactly that, and says the change was not saved. */
    $backTo = static function (string $msg): void {
        $screen = strtolower(trim((string) ($_POST['_console'] ?? '')));
        $to = '/admin/';
        if ($screen !== '') {
            if (strncmp($screen, 'phone:', 6) === 0) {
                $panel = substr($screen, 6);
                if (function_exists('mam_phone_panel_ok') && mam_phone_panel_ok($panel)) {
                    $to = '/admin/phone.php?p=' . rawurlencode($panel) . '&';
                }
            } elseif (function_exists('mam_console_screens') && in_array($screen, mam_console_screens(), true)) {
                $to = '/admin/console.php?v=' . rawurlencode($screen) . '&';
            }
        }
        if ($to === '/admin/') $to .= '?';
        header('Location: ' . $to . 'err=' . urlencode($msg));
        exit;
    };

    /* ⚠️ Compare HOST TO HOST. `HTTP_HOST` carries the port when it is not the
       scheme's default ("127.0.0.1:8221"), while `parse_url(..., PHP_URL_HOST)`
       never does — so a bare comparison is false for every install not on 80
       or 443 and refuses every admin form on a staging or dev port. It read as
       correct for as long as this guard was inert; the moment it started
       firing it rejected a legitimate POST from the console itself. */
    $origin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host !== '' && $host[0] === '[') {
        // An IPv6 literal: "[::1]:8221" -> "[::1]". parse_url KEEPS the
        // brackets, so they are kept here too rather than trimmed.
        $end  = strpos($host, ']');
        $host = $end !== false ? substr($host, 0, $end + 1) : $host;
    } elseif (($pos = strrpos($host, ':')) !== false) {
        $host = substr($host, 0, $pos);          // "127.0.0.1:8221" -> "127.0.0.1"
    }
    if ($origin !== '' && $host !== '' && (string) parse_url($origin, PHP_URL_HOST) !== $host) {
        mam_log('warn', 'admin_cross_origin_blocked', ['origin' => (string) $origin]);
        $backTo('That request came from another site and was refused. Nothing was changed.');
    }
    $csrf = $_POST['csrf_token'] ?? '';
    if (!mam_csrf_verify($csrf)) {
        mam_log('warn', 'admin_csrf_rejected', ['action' => (string) ($_POST['action'] ?? '')]);
        $backTo('Your session expired before that was saved, so nothing was changed. Please try again — the page has a fresh token now.');
    }

    $action = $_POST['action'] ?? '';

    // A POST from the redesigned console reuses every handler below and must
    // land back on the console, not on the legacy view the handler names.
    if (($_POST['_console'] ?? '') !== '') {
        mam_admin_console_return((string) $_POST['_console']);
    }

    // RBAC: gate sensitive actions to the right admin tier in one place.
    // Operational actions (course/enrolment/messages/orders/own-account) are
    // omitted → allowed for managers; everything here is super-only. Basic-Auth
    // admins (no $adminUser row) are the break-glass super and skip the check.
    $actionCaps = [
        // The console's "Run migrations" button (System health). The same
        // capability the API route uses; there is no shell on production, so
        // this button is how a database behind the code is brought level.
        'run_migrations' => 'migrate',
        'vetting_record' => 'users', 'vetting_delete' => 'users', 'safeguarding_mode' => 'settings',
        'term_save' => 'settings', 'term_seed_year' => 'settings', 'term_census' => 'settings',
        'term_refund_reseed' => 'settings', 'closure_add' => 'settings', 'closure_delete' => 'settings',
        // Data protection. Requests and breaches are about identified people, so
        // they sit under the `users` capability; the register, retention mode and
        // processor agreements are configuration.
        'dp_request_open' => 'users', 'dp_request_ack' => 'users', 'dp_request_close' => 'users',
        'dp_request_export' => 'users', 'dp_breach_record' => 'users', 'dp_breach_ndpc' => 'users',
        'dp_breach_subjects' => 'users', 'dp_breach_close' => 'users',
        'dp_processor_save' => 'settings', 'dp_note_save' => 'settings', 'dp_assessment_save' => 'settings',
        'dp_retention_mode' => 'settings', 'dp_retention_preview' => 'settings', 'dp_retention_run' => 'settings',
        'user_create' => 'users', 'user_reset' => 'users', 'user_status' => 'users',
        'user_delete' => 'users', 'user_edit' => 'users', 'set_admin_tier' => 'rbac',
        // Member records (members.php, HANDOFF §4w).
        'member_signout' => 'users', 'member_note_add' => 'users', 'member_status_set' => 'users', 'member_bulk' => 'users',
        'wallet_credit' => 'users',
        'faculty_invite' => 'users', 'faculty_app_status' => 'users', 'faculty_request_review' => 'users',
        'faculty_assign_subjects' => 'users',
        // Fees (fees.php, console Invoices / Bursaries). Money, so super-only by
        // default, like refunds and bank confirmations above.
        'fee_invoice_open' => 'fees', 'fee_line_add' => 'fees', 'fee_line_waive' => 'fees',
        'fee_invoice_issue' => 'fees', 'fee_invoice_void' => 'fees', 'fee_aid_award' => 'fees',
        'fee_aid_revoke' => 'fees', 'fee_credit_apply' => 'fees', 'fee_payment_record' => 'fees',
        'fee_payment_void' => 'fees', 'fee_plan_approve' => 'fees', 'fee_plan_cancel' => 'fees',
        'bursary_decide' => 'fees',
        'fee_transfer_verify' => 'fees', 'fee_transfer_reject' => 'fees', 'fee_unapplied_resolve' => 'fees',
        'module_proposal_review' => 'curriculum',
        'curriculum_clone' => 'curriculum',
        // Programming a recital is an academic judgement about what a
        // student is ready to perform, not an office task.
        'recital_create' => 'curriculum', 'recital_update' => 'curriculum', 'recital_status' => 'curriculum',
        'recital_decide' => 'curriculum', 'recital_reorder' => 'curriculum', 'recital_add_item' => 'curriculum',
        'recital_recording' => 'curriculum', 'recital_email' => 'curriculum',
        'course_create' => 'curriculum', 'course_update' => 'curriculum', 'course_toggle' => 'curriculum',
        'program_create' => 'curriculum', 'program_update' => 'curriculum',
        'link_course' => 'curriculum', 'unlink_course' => 'curriculum',
        'cohort_converge' => 'settings', 'cohort_create' => 'curriculum', 'cohort_edit' => 'curriculum',
        'bridge_legacy_enrollments' => 'settings',
        'module_save' => 'curriculum', 'module_delete' => 'curriculum',
        'module_scaffold' => 'curriculum', 'module_import' => 'curriculum',
        'rubric_create' => 'curriculum', 'rubric_update' => 'curriculum', 'rubric_delete' => 'curriculum',
        'meet_reconcile' => 'settings',
        'save_google_meet' => 'settings', 'meet_sync' => 'settings',
        'acct_exception' => 'enrollments',
        'set_student_type' => 'users',
        'acct_resolve' => 'enrollments', 'guardian_link' => 'enrollments', 'guardian_unlink' => 'enrollments',
        'resource_add' => 'media', 'resource_delete' => 'media', 'avatar_clear' => 'media',
        'datarights_erase' => 'users',
        'save_settings' => 'settings',
        'save_email_branding' => 'settings',
        'save_promo_banner' => 'settings',
        'delete_promo_banner' => 'settings',
        'automation_toggle' => 'settings',
        'run_automations' => 'settings',
        'acadmail_toggle' => 'settings',
        'referral_reward' => 'settings',
        'record_placement' => 'admissions',
        'enrol_complete'  => 'admissions',
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

    // ── Accountability ledger: resolve a charge (waive / paid / reversed) ──
    if ($action === 'acct_resolve') {
        $cid  = (int)($_POST['charge_id'] ?? 0);
        $st   = in_array(($_POST['resolution'] ?? ''), ['waived', 'paid', 'reversed', 'disputed'], true) ? (string)$_POST['resolution'] : '';
        $note = substr(trim((string)($_POST['note'] ?? '')), 0, 255) ?: null;
        $me   = mam_current_user();
        if ($cid < 1 || $st === '') { header('Location: ?v=accountability&err=' . urlencode('Choose a charge and an outcome.')); exit; }
        try { mam_accountability_resolve($cid, $me ? (int)$me['id'] : 0, $st, $note); }
        catch (\Throwable $e) { header('Location: ?v=accountability&err=' . urlencode('Could not update that charge.')); exit; }
        header('Location: ?v=accountability&ok=1'); exit;
    }

    // ── §15: decide an exception request. Approving waives the charge;
    //    rejecting restores it to outstanding — "Rejected — Charge Maintained".
    if ($action === 'acct_exception') {
        $rid     = (int)($_POST['request_id'] ?? 0);
        $approve = ($_POST['decision'] ?? '') === 'approve';
        $note    = substr(trim((string)($_POST['note'] ?? '')), 0, 255) ?: null;
        $me      = mam_current_user();
        if ($rid < 1) { header('Location: ?v=accountability&err=' . urlencode('Choose a request.')); exit; }
        try { mam_accountability_decide_exception($rid, $me ? (int)$me['id'] : 0, $approve, $note); }
        catch (\Throwable $e) { header('Location: ?v=accountability&err=' . urlencode('Could not decide that request.')); exit; }
        header('Location: ?v=accountability&ok=' . urlencode($approve ? 'Exception approved — charge waived.' : 'Exception rejected — charge maintained.')); exit;
    }

    // ── Guardians: link / unlink a parent account to a student ─────────────
    if ($action === 'guardian_link') {
        $sid   = (int)($_POST['student_id'] ?? 0);
        $email = mam_str($_POST, 'parent_email', 190);
        $rel   = mam_str($_POST, 'relationship', 32) ?: 'guardian';
        $me    = mam_current_user();
        if ($sid < 1 || $email === '') { header('Location: ?v=guardians&err=' . urlencode('Enter a student id and guardian email.')); exit; }
        try { $gl = mam_parent_link($email, $sid, $rel, $me ? (int)$me['id'] : null); }
        catch (\Throwable $e) { header('Location: ?v=guardians&err=' . urlencode('Could not link this guardian.')); exit; }
        // A guardian account made just now is sent an invitation. It used to be
        // made with a password nobody was given, so the guardian could never sign in.
        $gInv = !empty($gl['account_created']) ? mam_auth_send_invite((int) $gl['parent_id'], 'parent', 'guardian_invite') : null;
        header('Location: ?v=guardians&student=' . $sid . '&ok=' . urlencode(!empty($gl['account_created'])
            ? ($gInv ? 'Linked. The guardian was emailed an invitation to set a password.' : 'Linked, but the invitation could not be sent.')
            : 'Linked to their existing account.')); exit;
    }
    if ($action === 'guardian_unlink') {
        $pid = (int)($_POST['parent_id'] ?? 0);
        $sid = (int)($_POST['student_id'] ?? 0);
        if ($pid > 0 && $sid > 0) mam_parent_unlink($pid, $sid);
        header('Location: ?v=guardians' . ($sid ? '&student=' . $sid : '') . '&ok=1'); exit;
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
        header('Location: ?v=account&ok=' . urlencode(isset($upd['password_hash']) ? 'Saved. Your password is changed.' : 'Saved.')); exit;
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
                        // A link to set a password, never a password: this used to
                        // email a temporary one in plain text to the applicant.
                        if (!empty($prov['created'])) {
                            mam_auth_send_invite((int)$prov['user']['id'], 'student', 'portal_welcome');
                        }
                        // And the guardian the application named gets their own account.
                        mam_application_link_guardian($app, (int)$prov['user']['id'], $adminUser ? (int)$adminUser['id'] : null);
                        // Issue + email the student ID card on approval (idempotent;
                        // gated by the id_card_issue automation). This makes approval a
                        // reliable delivery point for a normal application, mirroring the
                        // enrolment-activation path — so no approved student is left
                        // without a card just because they didn't come through a paid
                        // course enrolment.
                        if (!empty($prov['user']['id']) && function_exists('mam_card_issue_for_user')
                            && (!function_exists('mam_automation_on') || mam_automation_on('id_card_issue'))) {
                            mam_card_issue_for_user((int)$prov['user']['id'], 'student', (string)($app['pathway'] ?? ''));
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

    // Summer programme: update an application's status, and issue the ID card
    // when it transitions to 'enrolled'. Server-form twin of the JS route
    // POST /api/admin/summer/status. (The old inline ?v=summer handler called
    // three non-existent helpers and sent the CSRF token under the wrong field
    // name, so status updates 403'd and no card was ever minted.)
    if ($action === 'summer_set_status') {
        $sid   = (int)($_POST['id'] ?? 0);
        $sst   = trim((string)($_POST['status'] ?? ''));
        $valid = ['submitted', 'confirmed', 'waitlisted', 'enrolled', 'cancelled', 'rejected'];
        $back  = '?v=summer' . (((int)($_POST['app'] ?? 0)) ? '&app=' . (int)$_POST['app'] : '');
        if ($sid && in_array($sst, $valid, true)) {
            $prev = mam_db_row('SELECT status FROM summer_applications WHERE id = ?', [$sid]);
            if ($prev) {
                mam_db_update('summer_applications', ['status' => $sst, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $sid]);
                mam_audit('update', 'summer_application', $sid, ['from' => $prev['status'], 'to' => $sst]);
                // Issue + email the ID card on the transition INTO 'enrolled'
                // (idempotent; gated by the id_card_issue automation).
                if ($sst === 'enrolled' && $prev['status'] !== 'enrolled'
                    && function_exists('mam_card_mint_for_summer_app')
                    && (!function_exists('mam_automation_on') || mam_automation_on('id_card_issue'))) {
                    mam_card_mint_for_summer_app($sid);
                }
                header('Location: ' . $back . '&ok=' . urlencode('Status updated.')); exit;
            }
        }
        header('Location: ' . $back . '&err=' . urlencode('Could not update status — pick a valid status.')); exit;
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
            else { $__n = mam_sessions_revoke_others((int)$adminUser['id']); }
        }
        header('Location: ?v=account&ok=' . urlencode($action === 'session_revoke' ? 'That device is signed out.' : 'Every other device is signed out' . (isset($__n) ? " ({$__n})." : '.'))); exit;
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
        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'email';
        if ($name === '') $errors[] = 'name';
        if (!in_array($role, ['admin', 'instructor', 'student', 'parent'], true)) $errors[] = 'role';
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
                // No password is typed or emailed: the new person is sent a
                // 7-day link to choose their own (HANDOFF §4w). An admin used to
                // type one here and the welcome email carried it in plain text.
                'password_hash' => mam_auth_unusable_password(),
                'must_change_password' => 0,
            ]);
            mam_user_ensure_role($uid, $role);
            if ($role === 'instructor') {
                try { mam_db_insert('instructor_profiles', ['user_id' => $uid]); } catch (Throwable $e) {}
            }
            mam_audit('create', 'user', $uid, ['role' => $role, 'email' => $email]);
            // An account isn't useful if nobody tells its owner it exists.
            $mailed = (bool) mam_auth_send_invite($uid, $role, 'account_invite');
            $flash = ['ok', 'User created · #' . $uid . ($mailed ? ' · an invitation to set a password was emailed'
                : ' · ⚠ the invitation could not be sent — send one again from the member record')];
        }
        $_SESSION_FLASH = $flash;
        header('Location: ?v=users' . ($flash[0] === 'ok' ? '&ok=1' : '&err=' . urlencode($flash[1])));
        exit;
    }

    /* "Reset" sends the person a link to choose a new password and ends their
       sessions. It used to take a password an administrator typed and email it
       in plain text — a credential that sat in an inbox, known to two people. */
    if ($action === 'user_reset') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $sent = $uid ? mam_auth_send_invite($uid, 'portal', 'admin_password_reset') : null;
        if ($sent) {
            mam_member_revoke_sessions($uid);
            mam_audit('admin_reset_password', 'user', $uid, ['link' => true]);
            header('Location: ?v=users&ok=' . urlencode('A link to set a new password was emailed. Their sessions have ended.'));
        } else {
            header('Location: ?v=users&err=' . urlencode('No link was sent: the account must be active and have an email address.'));
        }
        exit;
    }

    /* Every change to whether somebody can sign in goes through ONE setter
       (members.php): a reason for a suspension, a history row, the person's
       sessions ended, and the two lockouts refused — your own account, and the
       last super admin. This used to be a bare UPDATE with none of that. */
    /* Where a member action returns to: the console's member panel when it came
       from there (so the operator sees the verdict beside the record), the
       legacy users view otherwise. */
    $memberBack = static function (int $uid, string $kind, string $msg): void {
        if (($_POST['_console'] ?? '') === 'students') {
            header('Location: /admin/console.php?' . http_build_query(['v' => 'students', 'id' => $uid, $kind => $msg])); exit;
        }
        header('Location: ?v=users&' . ($kind === 'err' ? 'err=' : 'ok=') . urlencode($msg)); exit;
    };

    if ($action === 'member_bulk') {
        $words = mam_member_verdict_words();
        $filters = array_intersect_key($_POST, array_flip(['stage', 'type', 'missing', 'q', 'sort']));
        $filters = array_map(static fn($v) => is_string($v) ? $v : '', $filters);
        // "All N matching" is resolved HERE from the filter, never from the page
        // the operator happened to have on screen.
        $ids = !empty($_POST['all_matching']) ? mam_console_students_ids($filters, MAM_MEMBERS_BULK_MAX + 1)
                                              : array_map('intval', (array) ($_POST['ids'] ?? []));
        try {
            $run = mam_member_bulk_run((string) ($_POST['bulk_action'] ?? ''), $ids, ['reason' => (string) ($_POST['reason'] ?? '')],
                $adminUser ? (int) $adminUser['id'] : null);
            $msg = $run['applied'] . ' changed, ' . $run['unchanged'] . ' already so, ' . $run['refused'] . ' refused.';
            header('Location: /admin/console.php?' . http_build_query(['v' => 'students', 'run' => $run['run_id']] + $filters
                + [$run['refused'] > 0 ? 'err' : 'ok' => $msg])); exit;
        } catch (Throwable $e) {
            $say = ['unknown_action' => 'Choose what to do.', 'nothing_selected' => 'Tick at least one member, or choose all matching.',
                'too_many' => 'At most ' . MAM_MEMBERS_BULK_MAX . ' at once. Narrow the filter.', 'reason_required' => $words['reason_required']];
            header('Location: /admin/console.php?' . http_build_query(['v' => 'students'] + $filters + ['err' => $say[$e->getMessage()] ?? 'That did not run.'])); exit;
        }
    }

    if ($action === 'user_status' || $action === 'user_delete' || $action === 'member_status_set') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $status = $action === 'user_delete' ? 'suspended' : (string)($_POST['status'] ?? '');
        $r = mam_member_set_status($uid, $status, (string)($_POST['reason'] ?? ''), $adminUser ? (int)$adminUser['id'] : null);
        $words = mam_member_verdict_words();
        $memberBack($uid, $r['result'] === 'refused' ? 'err' : 'ok', $words[$r['code']] ?? ('Not changed (' . $r['code'] . ').'));
    }

    if ($action === 'member_signout') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $n = $uid ? mam_member_revoke_sessions($uid) : 0;
        mam_audit('member_signout', 'user', $uid, ['sessions' => $n]);
        $memberBack($uid, 'ok', $n . ' session' . ($n === 1 ? '' : 's') . ' ended.');
    }

    if ($action === 'member_note_add') {
        $uid = (int)($_POST['user_id'] ?? 0);
        try {
            mam_member_note_add($uid, (string)($_POST['body'] ?? ''), $adminUser ? (int)$adminUser['id'] : null);
        } catch (Throwable $e) {
            $memberBack($uid, 'err', 'Write the note first.');
        }
        $memberBack($uid, 'ok', 'Note added.');
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

    /* ─── Academic calendar ───────────────────────────────────────────────
       The dates here drive refund percentages and the withdrawal outcome, so a
       bad row does real damage. The schema enforces ordering; these handlers
       translate its refusals into something an admin can act on. */
    if ($action === 'term_save') {
        try {
            $tid = mam_calendar_term_save([
                'year_label'          => trim((string)($_POST['year_label'] ?? '')),
                'code'                => trim((string)($_POST['code'] ?? '')),
                'term_no'             => (int)($_POST['term_no'] ?? 1),
                'label'               => trim((string)($_POST['label'] ?? '')),
                'starts_on'           => trim((string)($_POST['starts_on'] ?? '')),
                'ends_on'             => trim((string)($_POST['ends_on'] ?? '')),
                'add_drop_deadline'   => trim((string)($_POST['add_drop_deadline'] ?? '')),
                'withdrawal_deadline' => trim((string)($_POST['withdrawal_deadline'] ?? '')),
                'census_date'         => trim((string)($_POST['census_date'] ?? '')),
                'teaching_weeks'      => (int)($_POST['teaching_weeks'] ?? 12),
                'status'              => trim((string)($_POST['status'] ?? 'planned')),
                'notes'               => trim((string)($_POST['notes'] ?? '')),
            ], (int)($adminUser['id'] ?? 0) ?: null);
            header('Location: ?v=calendar&t=' . $tid . '&ok=' . urlencode('Term saved.')); exit;
        } catch (Throwable $e) {
            $m = $e->getMessage();
            $why = strpos($m, 'overlaps_term:') === 0
                ? 'Those dates overlap "' . substr($m, 14) . '". Two terms covering one day means two possible refund tables for the same withdrawal.'
                : ($m === 'ends_before_starts' ? 'The end date is before the start date.'
                : ($m === 'dates_required' ? 'A start and end date are required.'
                : ($m === 'year_and_code_required' ? 'A session label and a term code are required.'
                : 'Could not save: ' . $m)));
            header('Location: ?v=calendar&err=' . urlencode($why)); exit;
        }
    }

    if ($action === 'term_seed_year') {
        $yr = (int)($_POST['year'] ?? date('Y'));
        try {
            $made = mam_calendar_seed_year($yr, (int)($adminUser['id'] ?? 0) ?: null);
            $okN  = count(array_filter($made, static fn($x) => $x['id'] !== null));
            header('Location: ?v=calendar&ok=' . urlencode($okN . ' of ' . count($made) . ' terms authored for ' . $yr . '. Check the dates against your published calendar and edit them.')); exit;
        } catch (Throwable $e) {
            header('Location: ?v=calendar&err=' . urlencode('Could not seed: ' . $e->getMessage())); exit;
        }
    }

    if ($action === 'term_census') {
        $tid = (int)($_POST['term_id'] ?? 0);
        try {
            $c = mam_calendar_take_census($tid, (int)($adminUser['id'] ?? 0) ?: null);
            $msg = $c['already']
                ? 'The census was already taken on ' . (string)$c['taken_on'] . ' (' . (int)$c['enrolled'] . ' enrolled). It is a snapshot and does not change.'
                : 'Census recorded: ' . (int)$c['enrolled'] . ' enrolled, ' . (int)$c['active_cohorts'] . ' active classes.';
            header('Location: ?v=calendar&t=' . $tid . '&ok=' . urlencode($msg)); exit;
        } catch (Throwable $e) {
            header('Location: ?v=calendar&err=' . urlencode('Could not take the census: ' . $e->getMessage())); exit;
        }
    }

    if ($action === 'term_refund_reseed') {
        $tid = (int)($_POST['term_id'] ?? 0);
        mam_calendar_seed_refund_schedule($tid, true);
        header('Location: ?v=calendar&t=' . $tid . '&ok=' . urlencode('Refund table reset to the conventional schedule.')); exit;
    }

    if ($action === 'closure_add') {
        $tid = (int)($_POST['term_id'] ?? 0);
        try {
            mam_calendar_closure_add($tid, trim((string)($_POST['kind'] ?? 'holiday')),
                trim((string)($_POST['label'] ?? '')), trim((string)($_POST['from_on'] ?? '')), trim((string)($_POST['to_on'] ?? '')));
            header('Location: ?v=calendar&t=' . $tid . '&ok=' . urlencode('Closure added.')); exit;
        } catch (Throwable $e) {
            $m = $e->getMessage();
            $why = $m === 'outside_term' ? 'Those dates fall outside the term, so the closure would never apply.'
                 : ($m === 'to_before_from' ? 'The end date is before the start date.' : 'Could not add: ' . $m);
            header('Location: ?v=calendar&t=' . $tid . '&err=' . urlencode($why)); exit;
        }
    }

    if ($action === 'closure_delete') {
        $tid = (int)($_POST['term_id'] ?? 0);
        mam_calendar_closure_delete((int)($_POST['closure_id'] ?? 0));
        header('Location: ?v=calendar&t=' . $tid . '&ok=' . urlencode('Closure removed.')); exit;
    }

    /* ─── Data protection (NDPA 2023 / GAID 2025) ─────────────────────────
       Every one of these writes an artefact somebody may have to produce to the
       Commission, so each records WHO and WHEN. Note that `received_at` and
       `discovered_at` are settable by hand: a request that arrived on Monday and
       was logged on Thursday is three days into its clock, and a log that
       pretends otherwise is worse than no log at all. */
    if ($action === 'dp_request_open') {
        try {
            $rid = mam_dp_request_open([
                'kind'          => trim((string)($_POST['kind'] ?? '')),
                'subject_email' => trim((string)($_POST['subject_email'] ?? '')),
                'channel'       => trim((string)($_POST['channel'] ?? 'email')),
                'received_at'   => trim((string)($_POST['received_at'] ?? '')),
                'detail'        => trim((string)($_POST['detail'] ?? '')),
            ], (int)($adminUser['id'] ?? 0) ?: null);
            header('Location: ?v=dataprotection&tab=requests&ok=' . urlencode('Request logged (#' . $rid . '). The clock runs from the date received.')); exit;
        } catch (Throwable $e) {
            $m = $e->getMessage();
            $why = $m === 'invalid_email' ? 'That is not a valid email address for the data subject.'
                 : ($m === 'unknown_kind' ? 'Pick a request type.' : 'Could not log the request: ' . $m);
            header('Location: ?v=dataprotection&tab=requests&err=' . urlencode($why)); exit;
        }
    }

    if ($action === 'dp_request_ack') {
        mam_dp_request_acknowledge((int)($_POST['id'] ?? 0), (int)($adminUser['id'] ?? 0) ?: null);
        header('Location: ?v=dataprotection&tab=requests&ok=' . urlencode('Acknowledgement recorded.')); exit;
    }

    if ($action === 'dp_request_close') {
        $rid = (int)($_POST['id'] ?? 0);
        try {
            mam_dp_request_close($rid, trim((string)($_POST['outcome'] ?? '')), [
                'refusal_reason' => trim((string)($_POST['refusal_reason'] ?? '')),
                'closed_at'      => trim((string)($_POST['closed_at'] ?? '')),
                'detail'         => trim((string)($_POST['detail'] ?? '')),
            ], (int)($adminUser['id'] ?? 0) ?: null);
            header('Location: ?v=dataprotection&tab=requests&ok=' . urlencode('Request closed.')); exit;
        } catch (Throwable $e) {
            $m = $e->getMessage();
            $why = $m === 'reason_required' ? 'A refusal has to say why — the data subject is entitled to the reason.'
                 : ($m === 'bad_outcome' ? 'Pick an outcome.' : 'Could not close: ' . $m);
            header('Location: ?v=dataprotection&tab=requests&err=' . urlencode($why)); exit;
        }
    }

    if ($action === 'dp_request_export') {
        $r = mam_dp_request_fulfil_access((int)($_POST['id'] ?? 0), (int)($adminUser['id'] ?? 0) ?: null);
        if (empty($r['ok'])) {
            $why = ($r['error'] ?? '') === 'no_data'
                ? 'Nothing is held under that email address, so there is nothing to send. Close the request as refused with that reason, or correct the address.'
                : 'Could not produce the export (' . ($r['error'] ?? 'error') . ').';
            header('Location: ?v=dataprotection&tab=requests&err=' . urlencode($why)); exit;
        }
        header('Location: ?v=dataprotection&tab=requests&ok=' . urlencode('Export produced and the request closed as fulfilled. Download it from Data Rights to send to the subject.')); exit;
    }

    if ($action === 'dp_breach_record') {
        try {
            $bid = mam_dp_breach_record([
                'occurred_at'        => trim((string)($_POST['occurred_at'] ?? '')),
                'discovered_at'      => trim((string)($_POST['discovered_at'] ?? '')),
                'nature'             => trim((string)($_POST['nature'] ?? '')),
                'data_categories'    => trim((string)($_POST['data_categories'] ?? '')),
                'subject_categories' => trim((string)($_POST['subject_categories'] ?? '')),
                'subjects_affected'  => trim((string)($_POST['subjects_affected'] ?? '')),
                'risk_level'         => trim((string)($_POST['risk_level'] ?? 'unknown')),
                'containment'        => trim((string)($_POST['containment'] ?? '')),
            ], (int)($adminUser['id'] ?? 0) ?: null);
            header('Location: ?v=dataprotection&tab=breaches&ok=' . urlencode('Breach recorded (#' . $bid . '). The 72 hours run from the time it was discovered.')); exit;
        } catch (Throwable $e) {
            $m = $e->getMessage();
            $why = $m === 'nature_required' ? 'Say what happened and which categories of data were involved.'
                 : ($m === 'occurred_after_discovered' ? 'A breach cannot have happened after it was discovered.'
                 : 'Could not record: ' . $m);
            header('Location: ?v=dataprotection&tab=breaches&err=' . urlencode($why)); exit;
        }
    }

    if ($action === 'dp_breach_ndpc') {
        mam_dp_breach_notify_ndpc((int)($_POST['id'] ?? 0), trim((string)($_POST['at'] ?? '')) ?: null, trim((string)($_POST['reference'] ?? '')));
        header('Location: ?v=dataprotection&tab=breaches&ok=' . urlencode('NDPC notification recorded.')); exit;
    }

    if ($action === 'dp_breach_subjects') {
        $reason = trim((string)($_POST['no_notify_reason'] ?? ''));
        mam_dp_breach_notify_subjects((int)($_POST['id'] ?? 0), trim((string)($_POST['at'] ?? '')) ?: null, $reason);
        header('Location: ?v=dataprotection&tab=breaches&ok=' . urlencode($reason !== '' ? 'The decision not to notify the subjects is on record.' : 'Data-subject communication recorded.')); exit;
    }

    if ($action === 'dp_breach_close') {
        $bid = (int)($_POST['id'] ?? 0);
        try {
            mam_dp_breach_close($bid, [
                'lessons'          => trim((string)($_POST['lessons'] ?? '')),
                'containment'      => trim((string)($_POST['containment'] ?? '')),
                'no_notify_reason' => trim((string)($_POST['no_notify_reason'] ?? '')),
            ]);
            header('Location: ?v=dataprotection&tab=breaches&ok=' . urlencode('Breach closed.')); exit;
        } catch (Throwable $e) {
            $why = $e->getMessage() === 'subjects_undecided'
                ? 'This is a high-risk breach. Either record that the data subjects were told, or record why they were not — s.40(3) does not allow the question to be left open.'
                : 'Could not close: ' . $e->getMessage();
            header('Location: ?v=dataprotection&tab=breaches&err=' . urlencode($why)); exit;
        }
    }

    if ($action === 'dp_processor_save') {
        try {
            mam_dp_processor_save(trim((string)($_POST['key_name'] ?? '')), [
                'name'           => trim((string)($_POST['name'] ?? '')),
                'role'           => trim((string)($_POST['role'] ?? 'processor')),
                'purpose'        => trim((string)($_POST['purpose'] ?? '')),
                'country'        => trim((string)($_POST['country'] ?? '')),
                'dpa_signed_on'  => trim((string)($_POST['dpa_signed_on'] ?? '')),
                'dpa_reference'  => trim((string)($_POST['dpa_reference'] ?? '')),
                'sub_processors' => trim((string)($_POST['sub_processors'] ?? '')),
                'transfer_basis' => trim((string)($_POST['transfer_basis'] ?? '')),
                'review_due_on'  => trim((string)($_POST['review_due_on'] ?? '')),
                'status'         => trim((string)($_POST['status'] ?? 'active')),
                'notes'          => trim((string)($_POST['notes'] ?? '')),
            ], (int)($adminUser['id'] ?? 0) ?: null);
            header('Location: ?v=dataprotection&tab=processors&ok=' . urlencode('Processor record saved.')); exit;
        } catch (Throwable $e) {
            $m = $e->getMessage();
            $why = $m === 'bad_key' ? 'A processor key is letters, numbers, hyphen or underscore (2–48 characters).'
                 : ($m === 'name_required' ? 'A processor needs a name.' : 'Could not save: ' . $m);
            header('Location: ?v=dataprotection&tab=processors&err=' . urlencode($why)); exit;
        }
    }

    if ($action === 'dp_note_save') {
        $kind = trim((string)($_POST['kind'] ?? ''));
        $ref  = trim((string)($_POST['ref_key'] ?? ''));
        try {
            $r = mam_dp_note_save($kind, $ref, [
                'keep_days'   => trim((string)($_POST['keep_days'] ?? '')),
                'notes'       => trim((string)($_POST['notes'] ?? '')),
                'reviewed_on' => trim((string)($_POST['reviewed_on'] ?? '')),
            ], (int)($adminUser['id'] ?? 0) ?: null);
            $msg = !empty($r['clamped'])
                ? 'Saved, but the retention period was NOT shortened: the schedule states a lawful basis for the longer period, so the shipped period still applies. Change the basis in the code if the period is genuinely wrong.'
                : 'Register entry updated.';
            header('Location: ?v=dataprotection&tab=register&ok=' . urlencode($msg)); exit;
        } catch (Throwable $e) {
            header('Location: ?v=dataprotection&tab=register&err=' . urlencode('Could not save: ' . $e->getMessage())); exit;
        }
    }

    if ($action === 'dp_assessment_save') {
        try {
            mam_dp_assessment_save([
                'id'             => (int)($_POST['id'] ?? 0),
                'title'          => trim((string)($_POST['title'] ?? '')),
                'scope'          => trim((string)($_POST['scope'] ?? '')),
                'trigger_reason' => trim((string)($_POST['trigger_reason'] ?? '')),
                'necessity'      => trim((string)($_POST['necessity'] ?? '')),
                'risks'          => trim((string)($_POST['risks'] ?? '')),
                'mitigations'    => trim((string)($_POST['mitigations'] ?? '')),
                'residual_risk'  => trim((string)($_POST['residual_risk'] ?? 'unknown')),
                'decision'       => trim((string)($_POST['decision'] ?? 'draft')),
                'assessed_on'    => trim((string)($_POST['assessed_on'] ?? '')),
                'review_due_on'  => trim((string)($_POST['review_due_on'] ?? '')),
                'consulted_ndpc' => !empty($_POST['consulted_ndpc']),
            ], (int)($adminUser['id'] ?? 0) ?: null);
            header('Location: ?v=dataprotection&tab=dpia&ok=' . urlencode('Impact assessment saved.')); exit;
        } catch (Throwable $e) {
            $why = $e->getMessage() === 'title_and_scope_required'
                ? 'An assessment needs a title and a description of what it covers.' : 'Could not save: ' . $e->getMessage();
            header('Location: ?v=dataprotection&tab=dpia&err=' . urlencode($why)); exit;
        }
    }

    if ($action === 'dp_retention_mode') {
        $mode = strtolower(trim((string)($_POST['mode'] ?? 'report')));
        if (!in_array($mode, MAM_DP_RETENTION_MODES, true)) $mode = 'report';
        mam_setting_set('dp_retention_mode', $mode);
        mam_audit('dp_retention_mode', 'setting', 0, ['mode' => $mode]);
        $note = $mode === 'enforce'
            ? 'Retention is now ENFORCED — the daily job will delete records past their period. Rules marked "review" are still never applied automatically.'
            : ($mode === 'off' ? 'The retention sweep will not run.' : 'Retention is in report mode: it will measure and audit, and delete nothing.');
        header('Location: ?v=dataprotection&tab=retention&ok=' . urlencode($note)); exit;
    }

    if ($action === 'dp_retention_preview') {
        $r = mam_dp_retention_sweep(true);
        header('Location: ?v=dataprotection&tab=retention&ok=' . urlencode('Preview only, nothing changed: ' . (int)$r['flagged'] . ' record(s) are past their retention period.')); exit;
    }

    if ($action === 'dp_retention_run') {
        $r = mam_dp_retention_sweep(false);
        $msg = $r['applied']
            ? 'Retention applied: ' . (int)$r['deleted'] . ' deleted, ' . (int)$r['anonymised'] . ' anonymised.'
            : 'Nothing was applied — the sweep is in ' . $r['mode'] . ' mode. ' . (int)$r['flagged'] . ' record(s) are past their period.';
        header('Location: ?v=dataprotection&tab=retention&ok=' . urlencode($msg)); exit;
    }

    /* ─── Safeguarding: record / remove a clearance, set the gate mode ───
       Child Rights Act 2003 s.2(2) requires standards on the SUITABILITY of staff
       caring for children; the Lagos State Safeguarding and Child Protection
       Policy 2016 mandates the specific safer-recruitment checks. This screen is
       where an academy proves it holds them.

       Only the reference number, dates and a note of WHERE the evidence is filed
       are accepted — never the content of a check. See api/lib/vetting.php. */
    if ($action === 'vetting_record') {
        $iid  = (int)($_POST['instructor_id'] ?? 0);
        $type = trim((string)($_POST['clearance_type'] ?? ''));
        try {
            mam_vetting_record(
                $iid, $type, (int)($adminUser['id'] ?? 0) ?: null,
                trim((string)($_POST['issued_on'] ?? '')) ?: null,
                trim((string)($_POST['expires_on'] ?? '')) ?: null,
                trim((string)($_POST['issuing_body'] ?? '')) ?: null,
                trim((string)($_POST['reference_no'] ?? '')) ?: null,
                trim((string)($_POST['evidence_held'] ?? '')) ?: null
            );
            $gate = mam_vetting_may_teach_minors($iid);
            header('Location: ?v=vetting&i=' . $iid . '&ok=' . urlencode('Clearance recorded. ' . mam_vetting_explain($gate))); exit;
        } catch (Throwable $e) {
            $why = $e->getMessage() === 'expiry_before_issue'
                ? 'The expiry date is before the issue date — check the dates.'
                : ($e->getMessage() === 'bad_type' ? 'That is not a known clearance type.' : $e->getMessage());
            header('Location: ?v=vetting&i=' . $iid . '&err=' . urlencode($why)); exit;
        }
    }

    if ($action === 'vetting_delete') {
        $rid = (int)($_POST['record_id'] ?? 0);
        $iid = (int)($_POST['instructor_id'] ?? 0);
        mam_vetting_delete($rid, (int)($adminUser['id'] ?? 0) ?: null);
        header('Location: ?v=vetting&i=' . $iid . '&ok=' . urlencode('Clearance record removed.')); exit;
    }

    if ($action === 'safeguarding_mode') {
        $mode = strtolower(trim((string)($_POST['mode'] ?? 'warn')));
        if (!in_array($mode, MAM_VETTING_MODES, true)) $mode = 'warn';
        mam_setting_set('safeguarding_gate_mode', $mode);
        mam_audit('safeguarding_mode', 'setting', 0, ['mode' => $mode]);
        $msg = $mode === 'enforce'
            ? 'Enforcing — an uncleared instructor can no longer be assigned to a class containing a child.'
            : ($mode === 'warn'
                ? 'Warning only — assignments proceed and gaps are reported. Switch to Enforce once your records are loaded.'
                : 'Gate OFF — nothing is checked. Only appropriate for an install that teaches no children.');
        header('Location: ?v=vetting&ok=' . urlencode($msg)); exit;
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
                $ceId  = (int)($ceRow['id'] ?? 0);   // null when diverted to a full cohort's waitlist
                $bridged++;
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'already_enrolled') {
                    $ex = mam_lms_find_enrollment($sid, $disc);
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
        $ceWaitPos = null;
        try {
            $ceRow = mam_lms_add_course($uid, $code);
            // A full cohort queues the student instead of over-filling it.
            if (($ceRow['status'] ?? '') === 'waitlisted') $ceWaitPos = (int)$ceRow['position'];
            $ceId  = (int)($ceRow['id'] ?? 0);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'already_enrolled') {
                $existing = mam_lms_find_enrollment($uid, $code);
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
        if ($ceWaitPos !== null) {
            header('Location: ?v=users&edit=' . $uid . '&ok=' . urlencode('That course is at capacity — the student was added to the waitlist at position ' . $ceWaitPos . '.')); exit;
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

    // ─── Competency rubrics (§21): create / edit / delete + assign to a cohort ──
    if (in_array($action, ['rubric_create', 'rubric_update', 'rubric_delete'], true)) {
        if ($action === 'rubric_delete') {
            $rid = (int)($_POST['rubric_id'] ?? 0);
            if ($rid) mam_lms2_rubric_delete($rid);
            header('Location: ?v=rubrics&ok=1'); exit;
        }
        $title    = trim((string)($_POST['title'] ?? ''));
        $cohortId = (int)($_POST['cohort_id'] ?? 0) ?: null;
        $active   = !empty($_POST['active']);
        // Criteria arrive as parallel arrays (label/max/weight rows).
        $labels  = (array)($_POST['crit_label'] ?? []);
        $maxes   = (array)($_POST['crit_max'] ?? []);
        $weights = (array)($_POST['crit_weight'] ?? []);
        $criteria = [];
        foreach ($labels as $i => $lab) {
            $lab = trim((string)$lab);
            if ($lab === '') continue;
            $criteria[] = ['label' => $lab, 'max_points' => (int)($maxes[$i] ?? 10), 'weight' => (float)($weights[$i] ?? 1)];
        }
        try {
            if ($action === 'rubric_create') {
                mam_lms2_rubric_create($title, $criteria, $cohortId, (int)($adminUser['id'] ?? 0) ?: null);
            } else {
                mam_lms2_rubric_update((int)($_POST['rubric_id'] ?? 0), $title, $criteria, $cohortId, $active);
            }
        } catch (\Throwable $e) {
            $m = $e->getMessage();
            $msg = $m === 'title_required' ? 'A rubric title is required.'
                 : ($m === 'criteria_required' ? 'Add at least one scored criterion.' : 'Could not save the rubric.');
            header('Location: ?v=rubrics&err=' . urlencode($msg)); exit;
        }
        header('Location: ?v=rubrics&ok=1'); exit;
    }

    // ─── Meet/Jitsi reconciliation (§11): import a participant CSV for a session ──
    if ($action === 'meet_reconcile') {
        $sid = (int)($_POST['session_id'] ?? 0);
        if ($sid < 1) { header('Location: ?v=meet&err=' . urlencode('Pick a session first.')); exit; }
        $csv = (string)($_POST['csv'] ?? '');
        try {
            $rows = mam_meeting_parse_csv($csv);
            if (!$rows) { header('Location: ?v=meet&session=' . $sid . '&err=' . urlencode('No rows parsed — paste a Name,Email,Join,Leave CSV.')); exit; }
            $n = mam_meeting_reconcile_import($sid, $rows, 'meet', 'import');
            header('Location: ?v=meet&session=' . $sid . '&imp=' . (int)$n); exit;
        } catch (\Throwable $e) {
            header('Location: ?v=meet&session=' . $sid . '&err=' . urlencode('Could not import that CSV.')); exit;
        }
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
    /* Accept an approved candidate's teaching matrix into
       instructor_assignments. This is the payoff for collecting (course, level)
       rows instead of a sentence: accepting them is a copy.

       Records CAPABILITY only. The safeguarding gate governs actual placement
       with students (mam_lms_approve / mam_enrol_complete) and is untouched by
       this — see mam_faculty_assign_subjects(). */
    /* ── Fees: the office's side of fees.php (HANDOFF §4t) ──────────────────
       v87 shipped invoices, aid, plans and bursary applications with no way for
       the office to act on any of them. Every action here calls the library;
       none re-implements a rule (the order of aid, the band ceiling, the
       overpayment refusal all live in fees.php). They return to the console's
       Invoices or Bursaries screen, to the invoice that was open. */
    if (str_starts_with((string) $action, 'fee_') || $action === 'bursary_decide') {
        $invId = (int) ($_POST['invoice_id'] ?? 0);
        $back = static function (string $kind, string $msg, int $inv = 0, string $screen = 'invoices'): void {
            $q = ['v' => $screen];
            if ($inv > 0) $q['id'] = $inv;
            $q[$kind] = $msg;
            header('Location: /admin/console.php?' . http_build_query($q)); exit;
        };
        $say = [
            'invoice_not_found' => 'That invoice no longer exists.', 'invoice_closed' => 'That invoice is paid or void, so it cannot be changed.',
            'not_issued' => 'Issue the invoice first.', 'invoice_void' => 'That invoice is void.', 'already_issued' => 'That invoice was already issued.',
            'no_lines' => 'Add at least one line before issuing.', 'reason_required' => 'Give a reason; it is kept on the record.',
            'label_required' => 'Describe the line.', 'bad_qty' => 'The quantity must be 1 or more.', 'bad_amount' => 'Enter an amount above zero.',
            'waiver_exceeds_line' => 'A waiver cannot be more than the line.', 'pct_or_amount' => 'Give a percentage or an amount, not both.',
            'bad_pct' => 'A percentage from 1 to 100.', 'award_not_active' => 'That award is no longer active.',
            'not_gift_aid' => 'Only a bursary or a scholarship is applied as a credit; a waiver goes on a line, and the sibling discount applies itself.',
            'award_other_student' => 'That award belongs to another student.', 'already_applied' => 'That award is already on this invoice.',
            'nothing_to_credit' => 'There is nothing left on this invoice for aid to cover.', 'more_than_due' => 'That is more than is still owed. Record what was owed and refund the rest.',
            'nothing_owing' => 'Nothing is owed on this invoice.', 'bad_method' => 'Choose how it was paid.', 'bad_date' => 'Give the date as YYYY-MM-DD.',
            'already_void' => 'That payment was already voided.', 'already_approved' => 'That plan is already approved.', 'not_active' => 'That plan is not active.',
            'already_decided' => 'That application was already decided.', 'outcome_note_required' => 'Write the note the family will read.',
            'above_published_band' => 'That is above the published band; the band is a ceiling.', 'student_not_found' => 'Choose a student.',
            'bad_kind' => 'Choose the kind of aid.', 'already_revoked' => 'That award was already revoked.',
            'reference_reused' => 'That reference is already recorded as a payment. One bank reference is one payment.',
            'claim_not_found' => 'That reported transfer no longer exists.', 'not_pending' => 'Somebody has already dealt with that transfer.',
            'not_checked' => 'Tick that you checked the money arrived on the bank statement.',
            'reference_mismatch' => 'The reference you typed from the statement does not match the one the family reported. Check it, or reject the claim with the reason.',
            'amount_mismatch' => 'The amount you typed from the statement does not match the one the family reported. Check it, or reject the claim with the reason.',
            'not_found' => 'That record no longer exists.', 'already_resolved' => 'That has already been resolved.',
            'bad_decision' => 'Choose what happened to the money.', 'apply_target' => 'Choose another open invoice for the same child.',
            'apply_currency' => 'That invoice is in a different currency.',
        ];
        $fail = static function (Throwable $e) use ($say, $back, $invId, $action): void {
            $code = $e->getMessage();
            mam_log('warn', 'admin_fee_action_refused', ['action' => $action, 'code' => $code]);
            $back('err', $say[$code] ?? 'That did not work (' . $code . ').', $invId, $action === 'bursary_decide' ? 'bursaries' : 'invoices');
        };
        try {
            $actor = mam_admin_require_actor();
            $by = (int) $actor['id'];
            switch ($action) {
                case 'fee_invoice_open':
                    $sid = (int) ($_POST['student_id'] ?? 0);
                    $new = mam_fee_invoice_open($sid, null, $by, ['term_label' => trim((string) ($_POST['term_label'] ?? '')), 'note' => trim((string) ($_POST['note'] ?? '')) ?: null]);
                    $back('ok', 'Draft invoice opened. Add its lines, then issue it.', $new);
                case 'fee_line_add':
                    mam_fee_line_add($invId, in_array($_POST['kind'] ?? '', ['tuition','materials','exam','registration','recital','late','other'], true) ? (string) $_POST['kind'] : 'other',
                        (string) ($_POST['label'] ?? ''), (string) ($_POST['unit'] ?? '0'), max(1, (int) ($_POST['qty'] ?? 1)));
                    $back('ok', 'Line added.', $invId);
                case 'fee_line_waive':
                    mam_fee_line_waive((int) ($_POST['line_id'] ?? 0), !empty($_POST['all']) ? true : (string) ($_POST['amount'] ?? '0'), (string) ($_POST['reason'] ?? ''), $by);
                    $back('ok', 'Waiver recorded on the line.', $invId);
                case 'fee_invoice_issue':
                    $due = trim((string) ($_POST['due_on'] ?? ''));
                    if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) throw new InvalidArgumentException('bad_date');
                    mam_fee_invoice_issue($invId, $by, $due !== '' ? $due : null);
                    $back('ok', 'Issued. The family can see it now.', $invId);
                case 'fee_invoice_void':
                    mam_fee_invoice_void($invId, (string) ($_POST['reason'] ?? ''), $by);
                    $back('ok', 'Invoice voided. It stays on the record with the reason.', $invId);
                case 'fee_aid_award':
                    $sid = (int) ($_POST['student_id'] ?? 0);
                    mam_fee_aid_award($sid, (string) ($_POST['kind'] ?? ''), [
                        'pct' => trim((string) ($_POST['pct'] ?? '')), 'amount' => trim((string) ($_POST['amount'] ?? '')),
                        'basis' => trim((string) ($_POST['basis'] ?? '')) ?: null, 'note' => trim((string) ($_POST['note'] ?? '')) ?: null,
                        'term_label' => trim((string) ($_POST['term_label'] ?? '')) ?: null,
                    ], $by);
                    $back('ok', 'Aid awarded. Apply it to an invoice to take effect.', $invId);
                case 'fee_aid_revoke':
                    mam_fee_aid_revoke((int) ($_POST['award_id'] ?? 0), (string) ($_POST['reason'] ?? ''), $by);
                    $back('ok', 'Award revoked. Credits already on issued invoices stand until those invoices change.', $invId);
                case 'fee_credit_apply':
                    $ov = trim((string) ($_POST['amount'] ?? ''));
                    mam_fee_credit_apply($invId, (int) ($_POST['award_id'] ?? 0), $by, $ov !== '' ? $ov : null);
                    $back('ok', 'Aid applied to the invoice.', $invId);
                case 'fee_payment_record':
                    mam_fee_payment_record($invId, (string) ($_POST['amount'] ?? '0'), (string) ($_POST['method'] ?? ''), $by, [
                        'reference' => (string) ($_POST['reference'] ?? ''), 'received_on' => (string) ($_POST['received_on'] ?? ''), 'note' => (string) ($_POST['note'] ?? ''),
                    ]);
                    $back('ok', 'Payment recorded. It is on the family\'s statement as a receipt.', $invId);
                case 'fee_payment_void':
                    mam_fee_payment_void((int) ($_POST['payment_id'] ?? 0), (string) ($_POST['reason'] ?? ''), $by);
                    $back('ok', 'Payment voided; the balance is owed again.', $invId);
                case 'fee_plan_approve':
                    mam_fee_plan_approve((int) ($_POST['plan_id'] ?? 0), $by);
                    $back('ok', 'Plan approved. Reminders now follow its instalments.', $invId);
                case 'fee_plan_cancel':
                    mam_fee_plan_cancel((int) ($_POST['plan_id'] ?? 0), (string) ($_POST['reason'] ?? ''), $by);
                    $back('ok', 'Plan cancelled. The balance is due on the invoice\'s own date.', $invId);
                case 'fee_transfer_verify':
                    mam_fee_transfer_verify((int) ($_POST['claim_id'] ?? 0), (string) ($_POST['confirm_reference'] ?? ''),
                        (string) ($_POST['confirm_amount'] ?? ''), !empty($_POST['checked']), $by);
                    $back('ok', 'Transfer confirmed and credited. The family has been told.', $invId);
                case 'fee_transfer_reject':
                    mam_fee_transfer_reject((int) ($_POST['claim_id'] ?? 0), (string) ($_POST['reason'] ?? ''), $by);
                    $back('ok', 'Transfer not confirmed. The family has been told why; the balance is still owed.', $invId);
                case 'fee_unapplied_resolve':
                    $to = (int) ($_POST['apply_to'] ?? 0);
                    mam_fee_unapplied_resolve((int) ($_POST['unapplied_id'] ?? 0), (string) ($_POST['decision'] ?? ''), (string) ($_POST['note'] ?? ''), $by, $to ?: null);
                    $back('ok', 'Recorded. The overpayment is resolved.', $invId);
                case 'bursary_decide':
                    $st = (string) ($_POST['status'] ?? '');
                    $pct = trim((string) ($_POST['pct'] ?? ''));
                    $r = mam_bursary_decide((int) ($_POST['app_id'] ?? 0), $st, $by, (string) ($_POST['outcome_note'] ?? ''), $pct !== '' ? (float) $pct : null);
                    $back('ok', $st === 'awarded' ? 'Bursary awarded at ' . rtrim(rtrim(number_format((float) $r['awarded_pct'], 1), '0'), '.') . '%. Apply it to the family\'s invoice.' : ($st === 'assessing' ? 'Marked as being assessed.' : 'Decision recorded.'), 0, 'bursaries');
            }
        } catch (Throwable $e) { $fail($e); }
    }

    if ($action === 'faculty_assign_subjects') {
        $fid = (int)($_POST['app_id'] ?? 0);
        try {
            $actor = mam_admin_require_actor();
            $res = mam_faculty_assign_subjects($fid, (int) $actor['id']);
            $msg = $res['added'] . ' subject' . ($res['added'] === 1 ? '' : 's') . ' assigned'
                 . ($res['already'] ? ', ' . $res['already'] . ' already there' : '') . '.';
            header('Location: ?v=users&ok=' . urlencode($msg) . '#faculty'); exit;
        } catch (Throwable $e) {
            // mam_fail() has already answered in prod; this catches the codes
            // it raises so the admin gets a sentence instead of a blank page.
            $code = $e->getMessage();
            $say = str_contains($code, 'no_account') ? 'Create their instructor account first, then assign.'
                 : (str_contains($code, 'not_an_instructor') ? 'That account exists but is not an instructor.'
                 : (str_contains($code, 'not_approved') ? 'Approve the application before assigning subjects.'
                 : 'Could not assign these subjects.'));
            header('Location: ?v=users&err=' . urlencode($say) . '#faculty'); exit;
        }
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

    // ── §11: the live Google Meet credentials. These cannot ride the generic
    //    settings form, which truncates text at 300 chars — a service-account
    //    key is roughly 2KB. Saving new credentials drops the cached access
    //    token, or the next sync would keep using one minted from the old key.
    if ($action === 'save_google_meet') {
        $saJson = trim((string)($_POST['sa_json'] ?? ''));
        $wsAdm  = mb_substr(trim((string)($_POST['workspace_admin'] ?? '')), 0, 320);
        if ($saJson !== '') {
            $probe = json_decode($saJson, true);
            if (!is_array($probe) || empty($probe['client_email']) || empty($probe['private_key'])) {
                header('Location: ?v=meet&err=' . urlencode('That does not look like a service-account key — it needs client_email and private_key.')); exit;
            }
        }
        if ($wsAdm !== '' && !filter_var($wsAdm, FILTER_VALIDATE_EMAIL)) {
            header('Location: ?v=meet&err=' . urlencode('The Workspace admin must be an email address.')); exit;
        }
        mam_setting_set('google_meet_sa_json', mb_substr($saJson, 0, 8000));
        mam_setting_set('google_workspace_admin', $wsAdm);
        mam_setting_set('google_meet_token_cache', '');
        // Never audit the key itself — record only that it changed.
        mam_audit('save_google_meet', 'settings', null, ['configured' => $saJson !== '' && $wsAdm !== '', 'admin' => $wsAdm]);
        header('Location: ?v=meet&ok=' . urlencode($saJson !== '' && $wsAdm !== '' ? 'Live Google Meet participation is configured.' : 'Google Meet credentials cleared.')); exit;
    }

    // ── §11: pull live participation for one session, on demand.
    if ($action === 'meet_sync') {
        $sid = (int)($_POST['session_id'] ?? 0);
        if ($sid < 1) { header('Location: ?v=meet&err=' . urlencode('Choose a session first.')); exit; }
        try { $n = mam_meeting_google_sync($sid); }
        catch (\Throwable $e) { $n = null; }
        if ($n === null) {
            header('Location: ?v=meet&session=' . $sid . '&err=' . urlencode('Could not reach Google — the portal clock-in remains the official record. Check the credentials and the app log.')); exit;
        }
        header('Location: ?v=meet&session=' . $sid . '&ok=' . urlencode('Pulled ' . $n . ' participant row' . ($n === 1 ? '' : 's') . ' from Google Meet.')); exit;
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
        $public   = !empty($_POST['banner_public']) ? 1 : 0;
        $discount = max(0, min(90, (int)($_POST['banner_discount_percent'] ?? 0)));
        $appliesTo = in_array((string)($_POST['banner_applies_to'] ?? ''), ['all', 'tuition', 'store'], true)
            ? (string)$_POST['banner_applies_to'] : 'all';
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
                'public' => $public, 'discount_percent' => $discount, 'applies_to' => $appliesTo,
                'starts_at' => $startsAt, 'ends_at' => $endsAt, 'updated_at' => $now,
            ], ['id' => $id]);
            mam_audit('update_promo_banner', 'promo_banner', $id, ['title' => $title, 'public' => $public, 'discount' => $discount]);
        } else {
            mam_db()->prepare(
                'INSERT INTO promo_banners (title,body,cta_label,cta_url,bg_color,active,public,discount_percent,applies_to,starts_at,ends_at,created_at,updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([$title, $body, $ctaLabel, $ctaUrl, $bgColor, $active, $public, $discount, $appliesTo, $startsAt, $endsAt, $now, $now]);
            mam_audit('create_promo_banner', 'promo_banner', null, ['title' => $title, 'public' => $public, 'discount' => $discount]);
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
    // Complete an enrolment: account → course → class → instructor, in one
    // action. Before this, marking an application "enrolled" created a portal
    // account and an ID card and stopped — no course, no class, no teacher — so
    // the academy had five applications marked accepted/enrolled against one
    // course enrolment. The status was a label, not an outcome.
    if ($action === 'enrol_complete') {
        $id = (int)($_POST['app_id'] ?? 0);
        $overrides = array_filter([
            'course'     => trim((string)($_POST['course'] ?? '')),
            'level'      => trim((string)($_POST['level'] ?? '')),
            'programme'  => trim((string)($_POST['programme'] ?? '')),
            'instructor' => trim((string)($_POST['instructor'] ?? '')),
        ], fn ($v) => $v !== '');
        // `level` is legitimately blank-able: the form offers "no level", and
        // array_filter would drop it and silently fall back to the application.
        if (isset($_POST['level']) && $_POST['level'] === '') $overrides['level'] = '';
        try {
            $res = mam_enrol_complete($id, $overrides, ((int)($adminUser['id'] ?? 0)) ?: null);
        } catch (Throwable $e) {
            header('Location: ?v=applications&app=' . $id . '&err=' . urlencode($e->getMessage())); exit;
        }
        // Every step is reported, success or not. The old provisioning path
        // caught its own failure and wrote a log line, so the admin was told
        // "Application updated" whether or not anything had happened.
        $msg = implode(' · ', array_map(fn ($st) => ($st['ok'] ? '' : '⚠ ') . $st['detail'], $res['steps']));
        $key = $res['ok'] ? 'ok' : 'err';
        header('Location: ?v=applications&app=' . $id . '&' . $key . '=' . urlencode($res['ok'] ? $msg : ($res['error'] . ' ' . $msg)));
        exit;
    }

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
        if (function_exists('mam_fee_dunning_sweep'))                  $r['fee_reminders'] = mam_fee_dunning_sweep();
        // §28 — academic email sweeps (gated behind academic_emails_enabled; a no-op when off).
        $ae = function_exists('mam_acadmail_run_all_sweeps') ? mam_acadmail_run_all_sweeps() : ['enabled' => false];
        $r['academic_emails'] = $ae;
        mam_audit('run_automations', 'automation', null, $r);
        $aeMsg = !empty($ae['enabled'])
            ? ' Academic emails — ' . (int)($ae['preclass'] ?? 0) . ' pre-class, ' . (int)($ae['absence'] ?? 0) . ' absence, ' . (int)($ae['prep'] ?? 0) . ' prep, ' . (int)($ae['weekly'] ?? 0) . ' weekly.'
            : ' Academic emails are off.';
        header('Location: ?v=automations&ok=' . urlencode('Ran scheduled sweeps — ' . (int)$r['reminders'] . ' session reminder(s), ' . (int)$r['birthdays'] . ' birthday note(s), ' . (int)$r['drip'] . ' module drip(s).' . $aeMsg)); exit;
    }

    // §28 — turn the academic email engine on/off (outbound parent/student mail).
    if ($action === 'acadmail_toggle') {
        $on = !empty($_POST['enabled']) ? 1 : 0;
        mam_setting_set('academic_emails_enabled', (string)$on);
        mam_audit('acadmail_toggle', 'setting', null, ['enabled' => $on]);
        header('Location: ?v=automations&ok=' . urlencode('Academic emails ' . ($on ? 'enabled — outbound parent/student mail is now on.' : 'disabled.'))); exit;
    }

    // §6.3 — reward a referral: credit the referrer's wallet + mark it rewarded.
    if ($action === 'referral_reward') {
        $rid  = (int)($_POST['referral_id'] ?? 0);
        $usd  = (float)($_POST['amount_usd'] ?? 0);
        $cents = $usd > 0 ? (int)round($usd * 100) : null;
        $me = mam_current_user();
        $ok = function_exists('mam_referral_reward') ? mam_referral_reward($rid, $cents, $me ? (int)$me['id'] : null) : false;
        header('Location: ?v=referrals&' . ($ok ? 'ok=' . urlencode('Referral rewarded — the referrer\'s wallet was credited.') : 'err=' . urlencode('Could not reward that referral (already rewarded?).'))); exit;
    }

    /* ── The Recital Hall ───────────────────────────────────────────────
       Curation is an academic act, so every one of these sits behind the
       'curriculum' capability (see $actionCaps). The lib owns the rules —
       this block only translates a form post into a call and a message. */
    if (strpos($action, 'recital_') === 0) {
        $me  = mam_current_user();
        $by  = $me ? (int)$me['id'] : null;
        $rid = (int)($_POST['recital_id'] ?? 0);
        $back = '?v=recitals' . ($rid ? '&r=' . $rid : '');
        try {
            if ($action === 'recital_create') {
                $newId = mam_recital_create([
                    'title'                => (string)($_POST['title'] ?? ''),
                    'subtitle'             => (string)($_POST['subtitle'] ?? ''),
                    'venue'                => (string)($_POST['venue'] ?? ''),
                    'starts_at'            => ($_POST['starts_at'] ?? '') !== '' ? str_replace('T', ' ', (string)$_POST['starts_at']) . ':00' : null,
                    'doors_open_at'        => ($_POST['doors_open_at'] ?? '') !== '' ? str_replace('T', ' ', (string)$_POST['doors_open_at']) . ':00' : null,
                    'submissions_close_at' => ($_POST['submissions_close_at'] ?? '') !== '' ? str_replace('T', ' ', (string)$_POST['submissions_close_at']) . ':00' : null,
                    'interval_min'         => (int)($_POST['interval_min'] ?? 15),
                    'description'          => (string)($_POST['description'] ?? ''),
                    'programme_note'       => (string)($_POST['programme_note'] ?? ''),
                ], $by);
                header('Location: ?v=recitals&r=' . $newId . '&ok=' . urlencode('Recital created — open it for submissions when you are ready.')); exit;
            }
            if ($action === 'recital_update') {
                $f = [
                    'title'          => (string)($_POST['title'] ?? ''),
                    'subtitle'       => (string)($_POST['subtitle'] ?? ''),
                    'venue'          => (string)($_POST['venue'] ?? ''),
                    'description'    => (string)($_POST['description'] ?? ''),
                    'programme_note' => (string)($_POST['programme_note'] ?? ''),
                    'interval_min'   => (int)($_POST['interval_min'] ?? 15),
                    'interval_after' => ($_POST['interval_after'] ?? '') === '' ? null : (int)$_POST['interval_after'],
                ];
                foreach (['starts_at', 'doors_open_at', 'submissions_close_at'] as $k) {
                    $f[$k] = ($_POST[$k] ?? '') !== '' ? str_replace('T', ' ', (string)$_POST[$k]) . ':00' : null;
                }
                mam_recital_update($rid, $f, $by);
                header('Location: ' . $back . '&ok=' . urlencode('Recital updated.')); exit;
            }
            if ($action === 'recital_status') {
                mam_recital_set_status($rid, (string)($_POST['status'] ?? ''), $by);
                $msg = ['open' => 'Submissions are open — students can now offer a piece.',
                        'programmed' => 'Programme published. Performers and their guardians have been told.',
                        'completed' => 'Recital closed. Every performed piece is now marked performed in the student\'s repertoire.',
                        'draft' => 'Back to draft.',
                        'cancelled' => 'Recital cancelled — everyone involved has been notified.'][(string)$_POST['status']] ?? 'Status updated.';
                header('Location: ' . $back . '&ok=' . urlencode($msg)); exit;
            }
            if ($action === 'recital_decide') {
                mam_recital_decide((int)($_POST['item_id'] ?? 0), (string)($_POST['decision'] ?? ''), $by, (string)($_POST['note'] ?? '') ?: null);
                header('Location: ' . $back . '&ok=' . urlencode($_POST['decision'] === 'accepted' ? 'Accepted and added to the running order.' : 'Declined — the student has been told kindly.')); exit;
            }
            if ($action === 'recital_reorder') {
                // The order arrives as "itemId:section" in running order.
                $order = [];
                foreach (explode(',', (string)($_POST['order'] ?? '')) as $tok) {
                    $tok = trim($tok); if ($tok === '') continue;
                    $bits = explode(':', $tok);
                    $order[] = ['id' => (int)$bits[0], 'section' => $bits[1] ?? 'main'];
                }
                $n = mam_recital_reorder($rid, $order, $by);
                header('Location: ' . $back . '&ok=' . urlencode($n . ' item' . ($n === 1 ? '' : 's') . ' repositioned.')); exit;
            }
            if ($action === 'recital_email') {
                // The deliberate resend, for when the running order really has
                // changed and families need to know. Publishing already sends
                // once; this bypasses the dedupe.
                $res = mam_recital_email_programme($rid, true, $by);
                header('Location: ' . $back . '&ok=' . urlencode('Programme emailed to ' . (int)$res['sent'] . ' of ' . (int)$res['recipients'] . ' recipient(s).')); exit;
            }
            if ($action === 'recital_recording') {
                // Publishing the evening. An empty submission clears it, which
                // is how a family withdraws a recording after the fact.
                $sub = ($_POST['submission_id'] ?? '') === '' ? null : (int)$_POST['submission_id'];
                mam_recital_attach_recording((int)($_POST['item_id'] ?? 0), $sub, $by);
                header('Location: ' . $back . '&ok=' . urlencode($sub === null ? 'Recording removed.' : 'Recording published to the listen-back gallery.')); exit;
            }
            if ($action === 'recital_add_item') {
                $sid = (int)($_POST['student_id'] ?? 0);
                if ($sid < 1 || trim((string)($_POST['title'] ?? '')) === '') throw new InvalidArgumentException('student and title are required');
                $itemId = mam_db_insert('recital_items', [
                    'recital_id'   => $rid,
                    'student_id'   => $sid,
                    'title'        => mb_substr(trim((string)$_POST['title']), 0, 200),
                    'composer'     => trim((string)($_POST['composer'] ?? '')) ?: null,
                    'instrument'   => trim((string)($_POST['instrument'] ?? '')) ?: null,
                    'duration_min' => max(1, min(60, (int)($_POST['duration_min'] ?? 4))),
                    'status'       => 'proposed',
                ]);
                mam_recital_decide($itemId, 'accepted', $by, null);
                header('Location: ' . $back . '&ok=' . urlencode('Added to the programme.')); exit;
            }
        } catch (\Throwable $ex) {
            header('Location: ' . $back . '&err=' . urlencode('Could not do that: ' . $ex->getMessage())); exit;
        }
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
        // Taking admin away from yourself, or from the last super admin, is the
        // same lockout as suspending them — by another door.
        $roleRefusal = mam_member_role_change_refusal($uid, $role, $adminUser ? (int)$adminUser['id'] : null);
        if ($roleRefusal !== null) {
            header('Location: ?v=users&edit=' . $uid . '&err=' . urlencode(mam_member_verdict_words()[$roleRefusal])); exit;
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

    if ($action === 'run_migrations') {
        $db = mam_db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $ver = static function () use ($db): int {
            try { return (int) $db->query('SELECT IFNULL(MAX(version),0) FROM schema_version')->fetchColumn(); }
            catch (Throwable $e) { return 0; }
        };
        $before = $ver();
        try {
            if ($driver === 'mysql') mam_db_migrate_mysql($db); else mam_db_migrate($db);
        } catch (Throwable $e) {
            mam_log('error', 'run_migrations_failed', ['error' => $e->getMessage()]);
            header('Location: ?v=system&err=' . urlencode('The migration stopped: ' . $e->getMessage())); exit;
        }
        $after = $ver();
        mam_audit('db_migrate', 'system', null, ['driver' => $driver, 'from' => $before, 'to' => $after, 'via' => 'console']);
        $target = defined('MAM_SCHEMA_TARGET') ? (int) MAM_SCHEMA_TARGET : $after;
        header('Location: ?v=system&' . ($after >= $target
            ? 'ok=' . urlencode("The database is up to date (schema {$after}" . ($after !== $before ? ", was {$before}" : '') . ').')
            : 'err=' . urlencode("Schema {$after} of {$target} after running. Something is stopping a migration; the application log has the error.")));
        exit;
    }

    // Unknown action — bounce back
    header('Location: ?v=' . urlencode($view));
    exit;
}

/* ─── No screens live here any more (retired 2026-11-19) ──────────────────────
   This file used to draw the whole legacy dashboard — forty-odd views, ~8,900
   lines of markup, styles and scripts — beside the console. That interface is
   gone. What this file still is, and must stay:
     • the ONE implementation of every admin write: the console and the phone
       subset post every form here (see ac_form() and mam_admin_console_return()),
       so there is one capability table, one audit call and one set of
       transition rules;
     • the CSV / JSON / PDF downloads under ?v=export.
   Every GET for anything else goes to the console. A bookmark or an old email
   link naming a retired view lands on the console screen that now does that
   job (or the dashboard), and any ?ok= / ?err= flash is carried across so a
   POST from an old tab still reports what happened. */
$__consoleFor = [
    'overview' => 'dashboard', 'applications' => 'admissions', 'summer' => 'admissions',
    'funnel' => 'funnel', 'users' => 'access', 'account' => 'account', 'guardians' => 'guardians',
    'payments' => 'invoices', 'storeorders' => 'store', 'storeitems' => 'store', 'store' => 'store', 'orders' => 'store',
    'classes' => 'cohorts', 'courseenroll' => 'roster', 'enrollments' => 'roster', 'schedule' => 'timetable',
    'calendar' => 'calendar', 'recitals' => 'events', 'modules' => 'curriculum', 'curriculum' => 'curriculum',
    'programs' => 'curriculum', 'rubrics' => 'assessment', 'academic' => 'reports', 'analytics' => 'reports',
    'bi' => 'reports', 'upper' => 'reports', 'fines' => 'deductions', 'accountability' => 'charges',
    'vetting' => 'vetting', 'dataprotection' => 'dataprotection', 'datarights' => 'dataprotection',
    'audit' => 'governance', 'support' => 'support', 'messages' => 'comms', 'newsletter' => 'comms', 'mail' => 'comms',
    'settings' => 'settings', 'automations' => 'system', 'logs' => 'system', 'diag' => 'system', 'migrate' => 'system',
    'webhooks' => 'system', 'media' => 'files', 'record' => 'students',
];
$__to = ['v' => $__consoleFor[$view] ?? 'dashboard'];
foreach (['ok', 'err'] as $__k) {
    if (isset($_GET[$__k]) && is_string($_GET[$__k])) $__to[$__k] = $_GET[$__k] === '1' ? 'Saved.' : $_GET[$__k];
}
header('Location: /admin/console.php?' . http_build_query($__to), true, 302);
exit;
