<?php
/**
 * /progress?t={token} — Parent-facing student progress report.
 * Token-gated, no login required. Clean, printable design.
 */
declare(strict_types=1);
$token = trim((string)($_GET['t'] ?? ''));
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MAM Academy — Student Progress Report</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600;700&family=Inter:wght@400;500;600&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous">
<style>
:root{--gold:#E8961F;--gold-light:rgba(232,150,31,0.12);--text:#1a1a1a;--muted:#666;--border:#e0e0e0;--bg:#fafaf8;--card:#fff;--success:#4caf50;--danger:#e05252}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);line-height:1.6;padding:0}
@media print{body{background:#fff}.no-print{display:none!important}.page-break{page-break-before:always}}
.header{background:var(--gold);color:#fff;padding:24px 32px;display:flex;align-items:center;gap:16px}
.header img{height:40px;width:auto}
.header h1{font-family:'Cormorant Garamond',serif;font-size:1.6rem;font-weight:700}
.header p{font-size:0.88rem;opacity:0.88;margin-top:2px}
.container{max-width:860px;margin:0 auto;padding:24px 20px}
.student-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:20px 24px;display:flex;align-items:center;gap:18px;margin-bottom:20px}
.student-avatar{width:56px;height:56px;border-radius:50%;background:var(--gold-light);display:flex;align-items:center;justify-content:center;font-size:1.4rem;font-weight:700;color:var(--gold);flex-shrink:0}
.student-name{font-family:'Cormorant Garamond',serif;font-size:1.4rem;font-weight:700}
.student-meta{font-size:0.84rem;color:var(--muted);margin-top:2px}
.section{background:var(--card);border:1px solid var(--border);border-radius:12px;margin-bottom:20px;overflow:hidden}
.section-head{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.section-head h2{font-size:0.95rem;font-weight:700;flex:1}
.section-head i{color:var(--gold)}
.section-body{padding:16px 20px}
.kpi-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-bottom:0}
.kpi{text-align:center;padding:14px;background:var(--bg);border-radius:10px;border:1px solid var(--border)}
.kpi-val{font-family:'Cormorant Garamond',serif;font-size:1.5rem;font-weight:700;color:var(--gold)}
.kpi-lbl{font-size:0.77rem;color:var(--muted);margin-top:2px}
.pill{display:inline-block;padding:2px 8px;border-radius:20px;font-size:0.72rem;font-weight:600;background:var(--border);color:var(--text)}
.pill.present,.pill.active,.pill.completed{background:rgba(76,175,80,0.14);color:#388e3c}
.pill.absent,.pill.open{background:rgba(224,82,82,0.12);color:var(--danger)}
.pill.late{background:rgba(255,167,38,0.16);color:#e65100}
.session-row{padding:12px 0;border-bottom:1px solid var(--border)}
.session-row:last-child{border-bottom:none}
.session-title{font-weight:600;font-size:0.92rem;margin-bottom:2px}
.session-meta{font-size:0.8rem;color:var(--muted)}
.lesson-detail{margin-top:8px;padding:10px 14px;background:var(--gold-light);border-radius:8px;font-size:0.85rem}
.lesson-label{font-size:0.74rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--gold);margin-bottom:3px}
.score-bar{height:8px;background:var(--border);border-radius:4px;margin-top:4px}
.score-fill{height:100%;border-radius:4px;background:var(--gold)}
table{width:100%;border-collapse:collapse;font-size:0.88rem}
th{text-align:left;font-weight:600;color:var(--muted);font-size:0.77rem;text-transform:uppercase;letter-spacing:0.06em;padding:8px 12px;border-bottom:1px solid var(--border)}
td{padding:9px 12px;border-bottom:1px solid var(--border);vertical-align:top}
tr:last-child td{border-bottom:none}
.footer{text-align:center;padding:20px;font-size:0.8rem;color:var(--muted);border-top:1px solid var(--border);margin-top:8px}
.print-btn{display:inline-flex;align-items:center;gap:8px;padding:9px 18px;background:var(--gold);color:#fff;border:none;border-radius:8px;font-size:0.86rem;font-weight:600;cursor:pointer;margin-bottom:16px}
.upcoming-badge{display:inline-block;padding:1px 7px;background:rgba(74,158,255,0.12);color:#1565c0;border-radius:12px;font-size:0.75rem;font-weight:600;margin-left:6px}
.empty{padding:24px;text-align:center;color:var(--muted);font-size:0.9rem}
</style>
</head>
<body>
<div class="header">
  <div>
    <h1>MAM Academy — Student Progress Report</h1>
    <p>Prepared for parents and guardians · Confidential</p>
  </div>
</div>
<div class="container">
  <button class="print-btn no-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print / Save as PDF</button>
  <div id="reportRoot"><div style="padding:60px;text-align:center;color:var(--muted)"><i class="fa-solid fa-circle-notch fa-spin" style="font-size:2rem;color:var(--gold)"></i><p style="margin-top:14px">Loading report…</p></div></div>
</div>
<script>
(function () {
  var token = <?= json_encode($token) ?>;
  if (!token) { document.getElementById('reportRoot').innerHTML = '<div class="empty"><i class="fa-solid fa-lock" style="font-size:2rem;color:var(--gold);margin-bottom:12px;display:block"></i>No access token provided.</div>'; return; }

  fetch('/api/report?t=' + encodeURIComponent(token), { credentials: 'omit' })
    .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .then(renderReport)
    .catch(function (e) {
      var errEl = document.getElementById('reportRoot');
      errEl.innerHTML = '<div class="empty"><i class="fa-solid fa-circle-exclamation" style="font-size:2rem;color:var(--danger);margin-bottom:12px;display:block"></i>Could not load the progress report. The link may have expired.<br><small id="errMsg"></small></div>';
      var msg = errEl.querySelector('#errMsg');
      if (msg) msg.textContent = 'Error: ' + (e && e.message ? e.message : 'unknown');
    });

  function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
  function fmtDate(s) { if (!s) return '—'; var d = new Date(s.replace(' ', 'T')); return isNaN(d) ? s : d.toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' }); }
  function fmtDateTime(s) { if (!s) return '—'; var d = new Date(s.replace(' ', 'T')); return isNaN(d) ? s : d.toLocaleString('en-GB', { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' }); }
  function fmtScore(score, max) { if (score === null || score === undefined) return '—'; return (max ? Math.round(score / max * 100) + '%' : score); }
  function initials(name) { return (name || '?').split(' ').slice(0,2).map(function(w){return w[0];}).join('').toUpperCase(); }

  function renderReport(data) {
    var s = data.student || {};
    var sessions = data.sessions || [];
    var upcoming = data.upcoming_sessions || [];
    var assessments = data.assessments || [];
    var results = data.results || [];
    var enrollments = data.enrollments || [];
    var courseEnrollments = data.course_enrollments || [];

    // KPIs
    var totalSessions = sessions.length;
    var present = sessions.filter(function(s){return s.attendance_status==='present'||s.attendance_status==='late';}).length;
    var rate = totalSessions ? Math.round(present/totalSessions*100) : null;
    var withNotes = sessions.filter(function(s){return s.lesson_notes||s.topics;}).length;

    var kpiRow = '<div class="kpi-row">'
      + '<div class="kpi"><div class="kpi-val">' + totalSessions + '</div><div class="kpi-lbl">Sessions recorded</div></div>'
      + '<div class="kpi"><div class="kpi-val">' + (rate !== null ? rate + '%' : '—') + '</div><div class="kpi-lbl">Attendance rate</div></div>'
      + '<div class="kpi"><div class="kpi-val">' + (enrollments.length + courseEnrollments.length) + '</div><div class="kpi-lbl">Active enrolments</div></div>'
      + '<div class="kpi"><div class="kpi-val">' + assessments.length + '</div><div class="kpi-lbl">Assessments on record</div></div>'
      + '<div class="kpi"><div class="kpi-val">' + upcoming.length + '</div><div class="kpi-lbl">Upcoming sessions</div></div>'
      + '</div>';

    // Enrollments summary
    var enrHtml = '';
    if (enrollments.length || courseEnrollments.length) {
      var rows = '';
      enrollments.forEach(function(e){
        rows += '<tr><td>' + esc(e.class_name||'—') + '</td><td>' + esc(e.instrument||'—') + '</td><td>' + esc(e.level||'—') + '</td><td>' + esc(e.instructor_name||'—') + '</td><td><span class="pill active">Active</span></td></tr>';
      });
      courseEnrollments.forEach(function(e){
        rows += '<tr><td>' + esc(e.course_name) + '</td><td>' + esc(e.cohort_title||'—') + '</td><td>' + esc(e.level_name||'—') + '</td><td>' + esc(e.instructor_name||'—') + '</td><td><span class="pill ' + esc(e.status) + '">' + esc(e.status) + '</span></td></tr>';
      });
      enrHtml = '<div class="section"><div class="section-head"><i class="fa-solid fa-graduation-cap"></i><h2>Current Enrolments</h2></div>'
        + '<table><thead><tr><th>Class / Course</th><th>Instrument / Cohort</th><th>Level</th><th>Instructor</th><th>Status</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
    }

    // Session + lesson notes
    var sessHtml = '<div class="section"><div class="section-head"><i class="fa-solid fa-book-open"></i><h2>Lesson Records <span style="font-weight:400;color:var(--muted);font-size:0.82rem">(most recent 30)</span></h2></div><div class="section-body">';
    if (!sessions.length) {
      sessHtml += '<div class="empty">No session records yet.</div>';
    } else {
      sessHtml += sessions.map(function(ses){
        var att = ses.attendance_status ? '<span class="pill ' + esc(ses.attendance_status) + '" style="margin-left:6px">' + esc(ses.attendance_status) + '</span>' : '';
        var notes = '';
        if (ses.topics) notes += '<div><div class="lesson-label">Topics covered</div>' + esc(ses.topics) + '</div>';
        if (ses.lesson_notes) notes += '<div style="margin-top:6px"><div class="lesson-label">Lesson notes</div>' + esc(ses.lesson_notes) + '</div>';
        if (ses.learning_outcomes) notes += '<div style="margin-top:6px"><div class="lesson-label">Learning outcomes</div>' + esc(ses.learning_outcomes) + '</div>';
        return '<div class="session-row">'
          + '<div class="session-title">' + esc(ses.title||'Session') + att + '</div>'
          + '<div class="session-meta">' + fmtDateTime(ses.scheduled_at) + (ses.instructor_name ? ' · ' + esc(ses.instructor_name) : '') + (ses.cohort_title ? ' · ' + esc(ses.cohort_title) : '') + '</div>'
          + (notes ? '<div class="lesson-detail">' + notes + '</div>' : '<div style="margin-top:4px;font-size:0.8rem;color:var(--muted)">No lesson notes recorded for this session.</div>')
          + '</div>';
      }).join('');
    }
    sessHtml += '</div></div>';

    // Upcoming
    var upHtml = '';
    if (upcoming.length) {
      upHtml = '<div class="section"><div class="section-head"><i class="fa-solid fa-calendar-days"></i><h2>Upcoming Sessions</h2></div><div class="section-body">'
        + upcoming.map(function(s){
            return '<div class="session-row"><div class="session-title">' + esc(s.title||'Session') + '<span class="upcoming-badge">upcoming</span></div>'
              + '<div class="session-meta">' + fmtDateTime(s.scheduled_at) + (s.cohort_title ? ' · ' + esc(s.cohort_title) : '') + (s.duration_min ? ' · ' + s.duration_min + 'min' : '') + '</div></div>';
          }).join('') + '</div></div>';
    }

    // Assessments + results
    var assHtml = '';
    if (assessments.length) {
      var assRows = assessments.slice(0, 20).map(function(a){
        var pct = a.max_score > 0 ? Math.round(a.score / a.max_score * 100) : null;
        return '<tr><td>' + esc(a.course_name||'—') + '</td><td>' + esc(a.type_name||'—') + '</td>'
          + '<td>' + (a.week ? 'Wk ' + a.week : '—') + '</td>'
          + '<td>' + (pct !== null ? '<strong>' + pct + '%</strong> <span style="color:var(--muted);font-size:0.82rem">(' + a.score + '/' + a.max_score + ')</span>' : '—') + '</td>'
          + '<td style="color:var(--muted);font-size:0.82rem">' + esc(a.grader_name||'—') + '</td>'
          + '<td style="color:var(--muted);font-size:0.82rem">' + fmtDate(a.created_at) + '</td></tr>';
      }).join('');
      assHtml = '<div class="section"><div class="section-head"><i class="fa-solid fa-star"></i><h2>Assessment Records</h2></div>'
        + '<table><thead><tr><th>Course</th><th>Type</th><th>Week</th><th>Score</th><th>Graded by</th><th>Date</th></tr></thead><tbody>' + assRows + '</tbody></table></div>';
    }

    // Results
    var resHtml = '';
    if (results.length) {
      resHtml = '<div class="section"><div class="section-head"><i class="fa-solid fa-medal"></i><h2>Course Results</h2></div><div class="section-body">'
        + results.map(function(r){
            var pct = r.total_score !== null ? Math.round(r.total_score) : null;
            var colour = pct >= 70 ? 'var(--success)' : pct >= 50 ? 'var(--gold)' : 'var(--danger)';
            return '<div style="padding:10px 0;border-bottom:1px solid var(--border)">'
              + '<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">'
              + '<div style="flex:1"><div style="font-weight:600">' + esc(r.course_name) + (r.level_name ? ' <span style="font-weight:400;color:var(--muted)">· ' + esc(r.level_name) + '</span>' : '') + '</div>'
              + '<div style="font-size:0.82rem;color:var(--muted)">' + (r.issued_at ? 'Issued ' + fmtDate(r.issued_at) : '') + '</div></div>'
              + '<div style="text-align:right"><div style="font-size:1.3rem;font-weight:700;color:' + colour + '">' + (pct !== null ? pct + '%' : '—') + '</div>'
              + (r.grade ? '<div style="font-size:0.84rem;color:var(--muted)">Grade: ' + esc(r.grade) + '</div>' : '')
              + '<span class="pill ' + esc(r.outcome||'') + '">' + esc(r.outcome||'pending') + '</span></div>'
              + '</div>'
              + (pct !== null ? '<div class="score-bar"><div class="score-fill" style="width:' + Math.min(100,pct) + '%;background:' + colour + '"></div></div>' : '')
              + '</div>';
          }).join('') + '</div></div>';
    }

    document.getElementById('reportRoot').innerHTML =
      '<div class="student-card">'
        + '<div class="student-avatar">' + esc(initials(s.name)) + '</div>'
        + '<div><div class="student-name">' + esc(s.name||'Student') + '</div>'
        + '<div class="student-meta">Progress report · Generated ' + fmtDateTime(data.generated_at) + '</div></div></div>'
      + kpiRow
      + enrHtml
      + upHtml
      + sessHtml
      + assHtml
      + resHtml
      + '<div class="footer">MAM Academy · academy.mamcentre.org · This report is intended for the student\'s parent or guardian. Do not share publicly.</div>';
  }
})();
</script>
</body>
</html>
