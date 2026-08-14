<?php
/**
 * GET /api/report-print?t={token} — printable (PDF-ready) learner report.
 *
 * Same token + data as /api/report, but rendered as a self-contained HTML
 * document with print styling, so a parent can open it and "Save as PDF" from
 * the browser — no PDF library needed in this buildless project. PII is trimmed
 * exactly as the JSON endpoint does.
 */
declare(strict_types=1);

$token = trim((string) ($_GET['t'] ?? ''));
if ($token === '') mam_fail('missing_token', 400);

$tokenRow = mam_progress_token_resolve($token);
if (!$tokenRow) mam_fail('invalid_or_expired_token', 404);

try {
    $report = mam_progress_report_data((int) $tokenRow['student_id']);
} catch (\Throwable $e) {
    mam_log('warn', 'report_print_unavailable', ['err' => $e->getMessage()]);
    mam_fail('report_unavailable', 404);
}
unset($report['student']['email']);

$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$stu = $report['student'];
$academic = $report['academic'] ?? null;
$att = $report['attendance'] ?? null;

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Learner Report — <?= $h($stu['name'] ?? 'Student') ?></title>
<style>
  :root { --ink:#1f2430; --muted:#6b7280; --line:#e5e7eb; --gold:#b8860b; --bg:#fff; }
  * { box-sizing: border-box; }
  body { font: 15px/1.5 -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; color: var(--ink); background: #f3f4f6; margin: 0; }
  .sheet { max-width: 820px; margin: 24px auto; background: var(--bg); padding: 40px; box-shadow: 0 1px 4px rgba(0,0,0,.12); }
  h1 { font-size: 24px; margin: 0 0 2px; }
  h2 { font-size: 15px; letter-spacing: .04em; text-transform: uppercase; color: var(--gold); border-bottom: 2px solid var(--line); padding-bottom: 6px; margin: 28px 0 12px; }
  .muted { color: var(--muted); }
  .row { display: flex; flex-wrap: wrap; gap: 16px; }
  .stat { flex: 1 1 120px; border: 1px solid var(--line); border-radius: 10px; padding: 12px 14px; }
  .stat .n { font-size: 22px; font-weight: 700; }
  .stat .l { font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: .03em; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th, td { text-align: left; padding: 7px 8px; border-bottom: 1px solid var(--line); }
  th { color: var(--muted); font-weight: 600; font-size: 12px; text-transform: uppercase; }
  .bar { height: 8px; border-radius: 4px; background: var(--line); overflow: hidden; }
  .bar > i { display: block; height: 100%; background: var(--gold); }
  .toolbar { max-width: 820px; margin: 16px auto -8px; text-align: right; }
  .btn { background: var(--gold); color: #fff; border: 0; border-radius: 8px; padding: 9px 16px; font-size: 14px; cursor: pointer; }
  .empty { color: var(--muted); font-style: italic; }
  @media print {
    body { background: #fff; }
    .sheet { box-shadow: none; margin: 0; max-width: none; padding: 0; }
    .toolbar { display: none; }
    h2 { break-after: avoid; }
    tr, .stat { break-inside: avoid; }
  }
</style>
</head>
<body>
<div class="toolbar"><button class="btn" onclick="window.print()">Print / Save as PDF</button></div>
<div class="sheet">
  <h1><?= $h($stu['name'] ?? 'Student') ?></h1>
  <div class="muted">
    <?= $h($stu['enrollment_ref'] ?? '') ?> · Report generated <?= $h(substr((string) $report['generated_at'], 0, 10)) ?>
  </div>

  <?php if ($academic && !empty($academic['gpa']) && $academic['gpa']['courses'] > 0): $g = $academic['gpa']; ?>
  <h2>Academic Summary</h2>
  <div class="row">
    <div class="stat"><div class="n"><?= $h($g['gpa'] ?? '—') ?></div><div class="l">GPA (4.0)</div></div>
    <div class="stat"><div class="n"><?= $h($g['avg_score'] ?? '—') ?>%</div><div class="l">Mean score</div></div>
    <div class="stat"><div class="n"><?= (int) $g['courses'] ?></div><div class="l">Courses graded</div></div>
    <?php if ($att): ?>
    <div class="stat"><div class="n"><?= $h($att['rate']) ?>%</div><div class="l">Attendance</div></div>
    <div class="stat"><div class="n"><?= (int) $att['streak'] ?></div><div class="l">Streak</div></div>
    <?php endif; ?>
  </div>
    <?php if (!empty($academic['percentiles'])): ?>
    <table style="margin-top:12px">
      <tr><th>Cohort</th><th>Score</th><th>Rank</th><th>Percentile</th></tr>
      <?php foreach ($academic['percentiles'] as $p): ?>
      <tr><td><?= $h($p['cohort_title']) ?></td><td><?= $h($p['score']) ?>%</td>
          <td><?= (int) $p['rank'] ?> / <?= (int) $p['cohort_size'] ?></td><td><?= $h($p['percentile']) ?>%</td></tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  <?php endif; ?>

  <?php if (!empty($report['class_summary'])): ?>
  <h2>Courses &amp; Attendance</h2>
  <table>
    <tr><th>Course</th><th>Instructor</th><th>Sessions</th><th>Attendance</th></tr>
    <?php foreach ($report['class_summary'] as $c): ?>
    <tr>
      <td><?= $h($c['cohort_title'] ?? $c['class_name'] ?? '—') ?></td>
      <td><?= $h($c['instructor_name'] ?? '—') ?></td>
      <td><?= (int) ($c['total_sessions'] ?? 0) ?></td>
      <td><?= $c['attendance_rate'] === null ? '—' : $h($c['attendance_rate']) . '%' ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>

  <?php if (!empty($report['lesson_progress'])): ?>
  <h2>Lesson Progress</h2>
  <table>
    <?php foreach ($report['lesson_progress'] as $lp): ?>
    <tr>
      <td style="width:40%"><?= $h($lp['cohort_title']) ?></td>
      <td><div class="bar"><i style="width:<?= (float) $lp['percent'] ?>%"></i></div></td>
      <td style="width:120px; text-align:right"><?= (int) $lp['completed'] ?>/<?= (int) $lp['total'] ?> (<?= $h($lp['percent']) ?>%)</td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>

  <?php if (!empty($report['results'])): ?>
  <h2>Results</h2>
  <table>
    <tr><th>Course</th><th>Score</th><th>Grade</th><th>Outcome</th></tr>
    <?php foreach ($report['results'] as $r): ?>
    <tr><td><?= $h($r['course_name']) ?></td><td><?= $h($r['total_score']) ?>%</td>
        <td><?= $h($r['grade']) ?></td><td><?= $h($r['outcome']) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>

  <?php if (!empty($report['rubric_reviews'])): ?>
  <h2>Rubric Feedback</h2>
  <table>
    <tr><th>Assignment</th><th>Rubric</th><th>Score</th><th>Reviewer</th></tr>
    <?php foreach ($report['rubric_reviews'] as $rv): ?>
    <tr><td><?= $h($rv['assignment_title']) ?></td><td><?= $h($rv['rubric_title'] ?? '—') ?></td>
        <td><?= $rv['total_points'] === null ? '—' : $h($rv['total_points']) . '/' . $h($rv['max_points']) ?></td>
        <td><?= $h($rv['reviewer_name'] ?? '—') ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>

  <?php if (!empty($report['reschedule_history'])): ?>
  <h2>Reschedule History</h2>
  <table>
    <tr><th>Cohort</th><th>Sessions</th><th>Status</th><th>When</th></tr>
    <?php foreach ($report['reschedule_history'] as $rr): ?>
    <tr><td><?= $h($rr['cohort_title'] ?? '—') ?></td><td><?= (int) $rr['session_count'] ?></td>
        <td><?= $h($rr['status']) ?></td><td><?= $h(substr((string) $rr['created_at'], 0, 10)) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>

  <?php if (!empty($report['certificates'])): ?>
  <h2>Certificates</h2>
  <table>
    <?php foreach ($report['certificates'] as $c): ?>
    <tr><td><?= $h($c['title'] ?? $c['kind']) ?></td><td class="muted"><?= $h(substr((string) $c['issued_at'], 0, 10)) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>

  <p class="muted" style="margin-top:32px; font-size:12px">MAM Academy · This report is a point-in-time summary generated for the student's guardian.</p>
</div>
</body>
</html><?php
exit;
