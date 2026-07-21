<?php
/**
 * /learner-report — Learner Progress Report
 *
 * Two access modes:
 *   ?id=MAM/26/001       → lookup by student enrollment reference
 *   ?t={token}           → lookup by admin-generated shareable token
 *
 * No login required. Rate-limited at the API level.
 */
declare(strict_types=1);
$token  = trim((string)($_GET['t'] ?? ''));
$refGet = trim(strtoupper((string)($_GET['id'] ?? ''))); // ?id= supports QR-code auto-load
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MAM Academy — Learner Progress Report</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous">
<style>
:root{
  --gold:#E8961F;--gold-light:rgba(232,150,31,0.12);--gold-mid:rgba(232,150,31,0.3);
  --text:#1a1a1a;--text-muted:#666;--text-soft:#888;
  --border:#e2e2e2;--border-soft:#efefef;
  --bg:#fafaf8;--card:#fff;
  --success:#4caf50;--success-bg:rgba(76,175,80,0.1);
  --danger:#e05252;--danger-bg:rgba(224,82,82,0.09);
  --warn:#e8961f;--warn-bg:rgba(232,150,31,0.12);
  --info:#1565c0;--info-bg:rgba(21,101,192,0.08);
  --radius:14px;--radius-sm:8px;
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);line-height:1.6}
a{color:var(--gold);text-decoration:none}
@media print{body{background:#fff}.no-print{display:none!important}.page-break{page-break-before:always}}

/* ── Header ── */
.site-header{background:#1B140D;color:#fff;padding:16px 24px;display:flex;align-items:center;gap:14px}
.logo-mark{background:var(--gold);width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;font-weight:900;color:#1B140D;flex-shrink:0}
.site-header h1{font-family:'Cormorant Garamond',serif;font-size:1.45rem;font-weight:700;color:#fff}
.site-header p{font-size:0.8rem;color:rgba(255,255,255,0.6);margin-top:1px}
.header-actions{margin-left:auto;display:flex;gap:10px;align-items:center}
.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:var(--radius-sm);font-size:0.84rem;font-weight:600;cursor:pointer;border:none;transition:opacity 0.15s}
.btn-gold{background:var(--gold);color:#fff}
.btn-ghost{background:transparent;border:1.5px solid rgba(255,255,255,0.25);color:#fff}
.btn:hover{opacity:0.88}
.btn:disabled{opacity:0.5;cursor:not-allowed}

/* ── Landing page ── */
.landing{display:flex;align-items:center;justify-content:center;min-height:70vh;padding:40px 20px}
.landing-card{background:var(--card);border-radius:20px;padding:44px 40px;max-width:480px;width:100%;box-shadow:0 4px 32px rgba(0,0,0,0.08);text-align:center}
.landing-icon{font-size:2.6rem;color:var(--gold);margin-bottom:14px}
.landing-card h2{font-family:'Cormorant Garamond',serif;font-size:2rem;font-weight:700;margin-bottom:6px}
.landing-eyebrow{font-size:0.78rem;font-weight:600;letter-spacing:0.1em;text-transform:uppercase;color:var(--text-soft);margin-bottom:10px}
.landing-chips{display:flex;flex-wrap:wrap;justify-content:center;gap:6px;margin:14px 0 24px}
.landing-chip{display:inline-flex;align-items:center;gap:5px;padding:5px 11px;background:var(--gold-light);border-radius:20px;font-size:0.77rem;color:var(--text-muted);font-weight:500}
.landing-chip i{color:var(--gold);font-size:0.72rem}
.field{margin-bottom:16px;text-align:left}
.field label{display:block;font-size:0.82rem;font-weight:600;color:var(--text-muted);margin-bottom:5px;text-transform:uppercase;letter-spacing:0.05em}
.field input{width:100%;padding:12px 14px;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-size:1rem;font-family:inherit;outline:none;transition:border-color 0.15s;letter-spacing:0.03em}
.field input:focus{border-color:var(--gold)}
.field input.error{border-color:var(--danger)}
.submit-btn{width:100%;padding:13px;background:var(--gold);color:#fff;border:none;border-radius:var(--radius-sm);font-size:1rem;font-weight:700;cursor:pointer;font-family:inherit;margin-top:4px;transition:opacity 0.15s}
.submit-btn:hover{opacity:0.9}
.err-msg{color:var(--danger);font-size:0.85rem;margin-top:10px;padding:10px 14px;background:var(--danger-bg);border-radius:var(--radius-sm);display:none}
.landing-hint{font-size:0.79rem;color:var(--text-soft);margin-top:18px;line-height:1.55}

/* ── Dashboard layout ── */
.dashboard-wrap{max-width:1100px;margin:0 auto;padding:24px 20px 48px}
.dashboard-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:20px}
.print-btn{display:inline-flex;align-items:center;gap:8px;padding:9px 18px;background:var(--gold);color:#fff;border:none;border-radius:var(--radius-sm);font-size:0.85rem;font-weight:600;cursor:pointer}
.back-link{color:var(--text-muted);font-size:0.85rem;display:inline-flex;align-items:center;gap:6px}
.back-link:hover{color:var(--text)}

/* Student profile card */
.profile-banner{background:linear-gradient(135deg,#1B140D 0%,#2d1f0e 100%);border-radius:var(--radius);padding:24px 28px;display:flex;align-items:center;gap:20px;margin-bottom:20px;color:#fff;position:relative;overflow:hidden}
.profile-banner::before{content:'';position:absolute;right:-30px;top:-30px;width:160px;height:160px;border-radius:50%;background:var(--gold);opacity:0.07}
.student-avatar{width:72px;height:72px;border-radius:50%;object-fit:cover;background:var(--gold-mid);display:flex;align-items:center;justify-content:center;font-size:1.8rem;font-weight:700;color:var(--gold);border:3px solid rgba(232,150,31,0.35);flex-shrink:0}
.student-avatar img{width:72px;height:72px;border-radius:50%;object-fit:cover}
.profile-info{flex:1;min-width:0}
.student-name{font-family:'Cormorant Garamond',serif;font-size:1.75rem;font-weight:700;color:#fff;line-height:1.2}
.student-meta{font-size:0.83rem;color:rgba(255,255,255,0.65);margin-top:4px}
.student-meta span{margin-right:14px}
.enroll-badge{display:inline-block;background:rgba(232,150,31,0.2);border:1px solid rgba(232,150,31,0.4);color:#E8961F;padding:3px 10px;border-radius:20px;font-size:0.78rem;font-weight:700;letter-spacing:0.08em;font-family:'Inter',sans-serif;margin-top:6px}

/* KPI row */
.kpi-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-bottom:20px}
.kpi{background:var(--card);border:1px solid var(--border);border-radius:var(--radius-sm);padding:16px 18px;text-align:center}
.kpi-val{font-family:'Cormorant Garamond',serif;font-size:1.75rem;font-weight:700;color:var(--gold);line-height:1}
.kpi-lbl{font-size:0.75rem;color:var(--text-muted);margin-top:4px;font-weight:500}
.kpi-icon{font-size:1rem;color:var(--gold);margin-bottom:6px}

/* Section cards */
.section{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:20px;overflow:hidden}
.section-head{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.section-head h2{font-size:0.95rem;font-weight:700;flex:1}
.section-head i{color:var(--gold)}
.section-head .badge{background:var(--gold-light);color:var(--gold);padding:2px 8px;border-radius:12px;font-size:0.75rem;font-weight:700}
.section-body{padding:18px 20px}

/* Class summary cards */
.class-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;padding:16px 20px}
.class-card{border:1px solid var(--border);border-radius:var(--radius-sm);padding:16px;position:relative}
.class-card-head{display:flex;align-items:flex-start;gap:12px;margin-bottom:10px}
.attendance-ring{position:relative;width:52px;height:52px;flex-shrink:0}
.attendance-ring svg{transform:rotate(-90deg)}
.ring-val{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:0.7rem;font-weight:700;color:var(--text)}
.class-card-info{flex:1;min-width:0}
.class-title{font-weight:700;font-size:0.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.class-sub{font-size:0.78rem;color:var(--text-muted);margin-top:2px}
.class-stats{display:flex;gap:10px;margin-top:8px;flex-wrap:wrap}
.class-stat{font-size:0.78rem;color:var(--text-muted)}
.class-stat strong{color:var(--text);font-weight:600}
.pill{display:inline-block;padding:2px 8px;border-radius:20px;font-size:0.72rem;font-weight:600}
.pill.active,.pill.present,.pill.completed{background:var(--success-bg);color:#388e3c}
.pill.absent,.pill.open{background:var(--danger-bg);color:var(--danger)}
.pill.late,.pill.in_progress{background:var(--warn-bg);color:#b06000}
.pill.scheduled,.pill.pending{background:var(--info-bg);color:var(--info)}
.pill.cancelled{background:var(--border);color:var(--text-muted)}

/* Timeline / session list */
.timeline{padding:4px 0}
.tl-item{display:flex;gap:16px;padding:14px 20px;border-bottom:1px solid var(--border-soft)}
.tl-item:last-child{border-bottom:none}
.tl-dot-wrap{display:flex;flex-direction:column;align-items:center;padding-top:3px}
.tl-dot{width:10px;height:10px;border-radius:50%;background:var(--border);flex-shrink:0}
.tl-dot.present{background:var(--success)}
.tl-dot.absent{background:var(--danger)}
.tl-dot.late{background:var(--warn)}
.tl-dot.no-mark{background:var(--border)}
.tl-line{flex:1;width:1px;background:var(--border-soft);margin-top:4px}
.tl-content{flex:1;min-width:0}
.tl-title{font-weight:600;font-size:0.9rem}
.tl-meta{font-size:0.79rem;color:var(--text-muted);margin-top:2px}
.tl-notes{margin-top:10px;padding:12px 14px;background:var(--gold-light);border-radius:var(--radius-sm);font-size:0.85rem}
.tl-note-label{font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--gold);margin-bottom:3px}
.tl-note-text{color:var(--text);line-height:1.55}
.tl-no-notes{font-size:0.8rem;color:var(--text-soft);margin-top:6px;font-style:italic}

/* Upcoming sessions */
.upcoming-list{padding:0}
.upcoming-item{display:flex;gap:14px;align-items:flex-start;padding:12px 20px;border-bottom:1px solid var(--border-soft)}
.upcoming-item:last-child{border-bottom:none}
.upcoming-date-col{text-align:center;min-width:46px;background:var(--gold-light);border-radius:var(--radius-sm);padding:6px 8px}
.upcoming-day{font-family:'Cormorant Garamond',serif;font-size:1.4rem;font-weight:700;color:var(--gold);line-height:1}
.upcoming-mon{font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted)}
.upcoming-info{flex:1}
.upcoming-title{font-weight:600;font-size:0.9rem}
.upcoming-sub{font-size:0.79rem;color:var(--text-muted);margin-top:2px}
.upcoming-badge{display:inline-block;padding:1px 7px;background:var(--info-bg);color:var(--info);border-radius:12px;font-size:0.72rem;font-weight:600;margin-left:6px}

/* Assessments */
table{width:100%;border-collapse:collapse;font-size:0.87rem}
th{text-align:left;font-weight:600;color:var(--text-muted);font-size:0.74rem;text-transform:uppercase;letter-spacing:0.06em;padding:9px 14px;border-bottom:1.5px solid var(--border)}
td{padding:9px 14px;border-bottom:1px solid var(--border-soft);vertical-align:middle}
tr:last-child td{border-bottom:none}
.score-bar{height:6px;background:var(--border);border-radius:3px;margin-top:4px;min-width:60px}
.score-fill{height:100%;border-radius:3px}

/* Certificates */
.cert-list{padding:0}
.cert-item{display:flex;align-items:center;gap:14px;padding:12px 20px;border-bottom:1px solid var(--border-soft)}
.cert-item:last-child{border-bottom:none}
.cert-icon{width:40px;height:40px;border-radius:10px;background:var(--gold-light);display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:var(--gold);flex-shrink:0}
.cert-info{flex:1}
.cert-title{font-weight:600;font-size:0.9rem}
.cert-sub{font-size:0.79rem;color:var(--text-muted);margin-top:2px}

/* Announcements */
.announce-item{padding:14px 20px;border-bottom:1px solid var(--border-soft)}
.announce-item:last-child{border-bottom:none}
.announce-title{font-weight:600;font-size:0.9rem;margin-bottom:4px}
.announce-body{font-size:0.85rem;color:var(--text-muted);line-height:1.55}
.announce-meta{font-size:0.77rem;color:var(--text-soft);margin-top:6px}

/* Practice */
.practice-bar-wrap{padding:16px 20px}
.pbar-row{display:flex;align-items:center;gap:10px;margin-bottom:8px;font-size:0.83rem}
.pbar-label{min-width:80px;color:var(--text-muted);font-size:0.78rem}
.pbar{flex:1;height:8px;background:var(--border);border-radius:4px;overflow:hidden}
.pbar-fill{height:100%;background:var(--gold);border-radius:4px}
.pbar-val{min-width:36px;text-align:right;font-weight:600;font-size:0.8rem}

/* Results */
.result-item{padding:12px 20px;border-bottom:1px solid var(--border-soft);display:flex;align-items:center;gap:16px}
.result-item:last-child{border-bottom:none}
.result-info{flex:1}
.result-course{font-weight:600;font-size:0.9rem}
.result-sub{font-size:0.78rem;color:var(--text-muted);margin-top:2px}
.result-score{text-align:right;min-width:70px}
.result-pct{font-family:'Cormorant Garamond',serif;font-size:1.5rem;font-weight:700}
.result-bar-wrap{width:100%;margin-top:4px}

/* Empty state */
.empty{padding:32px;text-align:center;color:var(--text-muted);font-size:0.88rem}
.empty i{display:block;font-size:1.8rem;color:var(--border);margin-bottom:10px}

/* Two-column layout on desktop */
@media(min-width:800px){
  .two-col{display:grid;grid-template-columns:1fr 1fr;gap:20px}
}

/* Footer */
.report-footer{text-align:center;padding:20px;font-size:0.78rem;color:var(--text-muted);border-top:1px solid var(--border);margin-top:12px}
</style>
</head>
<body>
<header class="site-header no-print">
  <div class="logo-mark">M</div>
  <div>
    <h1>MAM Academy</h1>
    <p>Learner Progress Report</p>
  </div>
  <div class="header-actions">
    <button class="btn btn-ghost no-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
  </div>
</header>

<div id="appRoot">
  <!-- Landing -->
  <div class="landing" id="landingView">
    <div class="landing-card">
      <div class="landing-icon"><i class="fa-solid fa-graduation-cap"></i></div>
      <div class="landing-eyebrow">MAM Academy</div>
      <h2>Learner Progress Report</h2>
      <div class="landing-chips">
        <span class="landing-chip"><i class="fa-solid fa-calendar-check"></i> Attendance</span>
        <span class="landing-chip"><i class="fa-solid fa-book-open"></i> Lesson records</span>
        <span class="landing-chip"><i class="fa-solid fa-chart-bar"></i> Assessments</span>
        <span class="landing-chip"><i class="fa-solid fa-certificate"></i> Certificates</span>
      </div>
      <form id="lookupForm" onsubmit="handleLookup(event)">
        <div class="field">
          <label>Student Enrollment ID</label>
          <input id="refInput" type="text" placeholder="e.g. MAM/26/001 or JE/MAM/26/001" autocomplete="off" autocorrect="off" spellcheck="false">
        </div>
        <div class="field">
          <label>Email on the student's account</label>
          <input id="emailInput" type="email" placeholder="parent or student email" autocomplete="off" autocorrect="off" spellcheck="false">
        </div>
        <button class="submit-btn" type="submit"><i class="fa-solid fa-search" style="margin-right:8px"></i>View Report</button>
        <div class="err-msg" id="lookupErr"></div>
      </form>
      <p class="landing-hint">The enrollment ID is printed on the student's enrollment letter and shown on their student portal. For privacy, we also ask for the email on the student's account.</p>
    </div>
  </div>

  <!-- Dashboard (shown after load) -->
  <div id="dashView" style="display:none">
    <div class="dashboard-wrap">
      <div class="dashboard-actions no-print">
        <a href="/learner-report" class="back-link" id="backLink"><i class="fa-solid fa-arrow-left"></i> Search again</a>
        <button class="print-btn" onclick="window.print()"><i class="fa-solid fa-print"></i> Print / Save PDF</button>
      </div>
      <div id="dashContent"></div>
    </div>
  </div>

  <!-- Loading -->
  <div id="loadingView" style="display:none">
    <div style="display:flex;align-items:center;justify-content:center;min-height:60vh">
      <div style="text-align:center;color:var(--text-muted)">
        <i class="fa-solid fa-circle-notch fa-spin" style="font-size:2.5rem;color:var(--gold);display:block;margin-bottom:16px"></i>
        Loading dashboard…
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  // ── Helpers ────────────────────────────────────────────────────
  function esc(s) { var d = document.createElement('div'); d.textContent = s != null ? String(s) : ''; return d.innerHTML; }
  function fmtDate(s) {
    if (!s) return '—';
    var d = new Date(s.replace(' ','T'));
    return isNaN(d) ? s : d.toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'});
  }
  function fmtDateTime(s) {
    if (!s) return '—';
    var d = new Date(s.replace(' ','T'));
    return isNaN(d) ? s : d.toLocaleString('en-GB', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
  }
  function fmtDayMon(s) {
    if (!s) return {day:'?',mon:'?'};
    var d = new Date(s.replace(' ','T'));
    return { day: d.toLocaleDateString('en-GB',{day:'2-digit'}), mon: d.toLocaleDateString('en-GB',{month:'short'}).toUpperCase() };
  }
  function fmtMin(m) {
    if (!m) return '0 min';
    if (m < 60) return m + ' min';
    return Math.floor(m/60) + 'h ' + (m%60 ? (m%60) + 'm' : '');
  }
  function initials(name) {
    return (name||'?').split(' ').slice(0,2).map(function(w){return w[0]||'';}).join('').toUpperCase();
  }
  function scoreColor(pct) {
    if (pct === null || pct === undefined) return 'var(--text-muted)';
    return pct >= 70 ? 'var(--success)' : pct >= 50 ? 'var(--gold)' : 'var(--danger)';
  }
  function ring(pct, size) {
    size = size || 52;
    var r = (size/2) - 5;
    var circ = 2 * Math.PI * r;
    var fill = pct != null ? circ * (1 - pct/100) : circ;
    var col = pct == null ? '#ddd' : pct >= 75 ? '#4caf50' : pct >= 50 ? '#e8961f' : '#e05252';
    return '<svg width="'+size+'" height="'+size+'" viewBox="0 0 '+size+' '+size+'">'
      + '<circle cx="'+(size/2)+'" cy="'+(size/2)+'" r="'+r+'" fill="none" stroke="#eee" stroke-width="5"/>'
      + '<circle cx="'+(size/2)+'" cy="'+(size/2)+'" r="'+r+'" fill="none" stroke="'+col+'" stroke-width="5"'
      + ' stroke-dasharray="'+circ+'" stroke-dashoffset="'+fill+'" stroke-linecap="round"/>'
      + '</svg>';
  }

  // ── URL / state ───────────────────────────────────────────────
  var params = new URLSearchParams(window.location.search);
  var initToken = <?= json_encode($token) ?>;
  var initRef   = <?= json_encode($refGet) ?>;  // from ?id= (QR-code links)

  function showView(id) {
    ['landingView','dashView','loadingView'].forEach(function(v){
      document.getElementById(v).style.display = v === id ? '' : 'none';
    });
  }

  // ── Lookup form ───────────────────────────────────────────────
  function handleLookup(e) {
    e.preventDefault();
    var ref = document.getElementById('refInput').value.trim().toUpperCase().replace(/\s+/g,'');
    var email = document.getElementById('emailInput').value.trim();
    var errEl = document.getElementById('lookupErr');
    errEl.style.display = 'none';
    if (!ref) { showErr('Please enter the student\'s ID.'); return; }
    if (!email) { showErr('Please enter the email on the student\'s account.'); return; }
    loadByRef(ref, email);
  }
  function showErr(msg) {
    var e = document.getElementById('lookupErr');
    e.textContent = msg;
    e.style.display = 'block';
  }

  window.handleLookup = handleLookup;

  function loadByRef(ref, email) {
    showView('loadingView');
    fetch('/api/learner-lookup?ref=' + encodeURIComponent(ref) + '&email=' + encodeURIComponent(email || ''), {credentials:'omit'})
      .then(function(r){
        if (r.status === 429) throw new Error('Too many attempts. Please wait a moment and try again.');
        if (r.status === 400) throw new Error('Please enter both the student ID (MAM/YY/NNN or JE/MAM/YY/NNN) and the account email.');
        if (r.status === 404) throw new Error('No active student matched that ID and email. Please check both and try again.');
        if (!r.ok) throw new Error('Server error (' + r.status + '). Please try again later.');
        return r.json();
      })
      .then(function(d){ renderDashboard(d); })
      .catch(function(e){
        showView('landingView');
        showErr(e && e.message ? e.message : 'Could not load the dashboard. Please try again.');
      });
  }

  function loadByToken(token) {
    showView('loadingView');
    fetch('/api/report?t=' + encodeURIComponent(token), {credentials:'omit'})
      .then(function(r){
        if (!r.ok) throw new Error('This link is invalid or has expired.');
        return r.json();
      })
      .then(function(d){ renderDashboard(d); })
      .catch(function(e){
        showView('landingView');
        showErr(e && e.message ? e.message : 'Could not load the dashboard.');
      });
  }

  // Auto-load: ?t= token links (admin-shared) load directly (the token is the
  // secret). ?id= ref links (QR scans) only PREFILL the ref — the ref is not a
  // secret, so we still require the account email before showing any data.
  if (initToken) {
    loadByToken(initToken);
  } else if (initRef) {
    document.getElementById('refInput').value = initRef;
    var emailEl = document.getElementById('emailInput');
    if (emailEl) emailEl.focus();
  }

  // ── Render ────────────────────────────────────────────────────
  function renderDashboard(data) {
    var s              = data.student || {};
    var sessions       = data.sessions || [];
    var upcoming       = data.upcoming_sessions || [];
    var assessments    = data.assessments || [];
    var results        = data.results || [];
    var classSummary   = data.class_summary || [];
    var enrollments    = data.enrollments || [];
    var courseEnrolls  = data.course_enrollments || [];
    var certs          = data.certificates || [];
    var announcements  = data.announcements || [];
    var practiceLogs   = data.practice_logs || [];
    var practiceMin    = data.total_practice_min || 0;

    // KPIs
    var totalSess      = sessions.length;
    var present        = sessions.filter(function(x){return x.attendance_status==='present'||x.attendance_status==='late';}).length;
    var rate           = totalSess ? Math.round(present/totalSess*100) : null;
    var withNotes      = sessions.filter(function(x){return x.lesson_notes||x.topics;}).length;
    var totalEnrols    = enrollments.length + courseEnrolls.length;

    // Profile banner
    var avatarHtml = s.avatar_url
      ? '<img src="' + esc(s.avatar_url) + '" alt="" style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:3px solid rgba(232,150,31,0.35)">'
      : '<div class="student-avatar">' + esc(initials(s.name)) + '</div>';

    var programNames = [];
    courseEnrolls.forEach(function(e){ if (e.course_name) programNames.push(e.course_name); });
    enrollments.forEach(function(e){ if (e.class_name) programNames.push(e.class_name); });
    var uniquePrograms = programNames.filter(function(v,i,a){return a.indexOf(v)===i;}).slice(0,3);

    var html = '';

    // Profile banner
    html += '<div class="profile-banner">'
      + avatarHtml
      + '<div class="profile-info">'
      + '<div class="student-name">' + esc(s.name||'Student') + '</div>'
      + '<div class="student-meta">'
      + (s.enrollment_ref ? '<span><i class="fa-solid fa-id-card" style="opacity:0.6;margin-right:4px"></i>' + esc(s.enrollment_ref) + '</span>' : '')
      + (s.created_at ? '<span><i class="fa-solid fa-calendar-check" style="opacity:0.6;margin-right:4px"></i>Enrolled ' + fmtDate(s.created_at) + '</span>' : '')
      + '</div>'
      + (uniquePrograms.length ? '<div style="margin-top:8px">' + uniquePrograms.map(function(p){return '<span class="enroll-badge" style="margin-right:6px">' + esc(p) + '</span>';}).join('') + '</div>' : '')
      + '</div>'
      + '<div style="text-align:right;color:rgba(255,255,255,0.5);font-size:0.75rem">'
      + 'Report generated<br>' + fmtDate(data.generated_at)
      + '</div>'
      + '</div>';

    // KPI row
    html += '<div class="kpi-row">'
      + kpi(rate !== null ? rate+'%' : '—', 'Attendance Rate', 'fa-chart-bar')
      + kpi(present + (totalSess ? '/'+totalSess : ''), 'Sessions Attended', 'fa-clipboard-check')
      + kpi(upcoming.length, 'Upcoming Sessions', 'fa-calendar-days')
      + kpi(totalEnrols, 'Active Enrolments', 'fa-graduation-cap')
      + kpi(assessments.length, 'Assessments', 'fa-star')
      + kpi(certs.length || '—', 'Certificates', 'fa-medal')
      + kpi(practiceMin ? fmtMin(practiceMin) : '—', 'Practice Logged', 'fa-music')
      + '</div>';

    function kpi(val, lbl, icon) {
      return '<div class="kpi"><div class="kpi-icon"><i class="fa-solid ' + icon + '"></i></div>'
           + '<div class="kpi-val">' + esc(String(val)) + '</div><div class="kpi-lbl">' + esc(lbl) + '</div></div>';
    }

    // Class Summary
    if (classSummary.length) {
      var cards = classSummary.map(function(c){
        var name  = c.cohort_title || c.class_name || 'Class';
        var sub   = [c.course_name, c.level_name||c.level, c.instructor_name].filter(Boolean).join(' · ');
        var total = parseInt(c.total_sessions||c.total_marked||0, 10);
        var att   = parseInt(c.attended||0, 10);
        var abs   = parseInt(c.absent||0, 10);
        var late  = parseInt(c.late||0, 10);
        var pct   = c.attendance_rate != null ? Math.round(Number(c.attendance_rate)) : null;
        return '<div class="class-card">'
          + '<div class="class-card-head">'
          + '<div class="attendance-ring">' + ring(pct) + '<div class="ring-val">' + esc(pct != null ? pct+'%' : '—') + '</div></div>'
          + '<div class="class-card-info">'
          + '<div class="class-title">' + esc(name) + '</div>'
          + (sub ? '<div class="class-sub">' + esc(sub) + '</div>' : '')
          + '</div></div>'
          + '<div class="class-stats">'
          + '<div class="class-stat"><strong>' + total + '</strong> held</div>'
          + '<div class="class-stat"><strong>' + att + '</strong> present</div>'
          + (abs ? '<div class="class-stat"><strong>' + abs + '</strong> absent</div>' : '')
          + (late ? '<div class="class-stat"><strong>' + late + '</strong> late</div>' : '')
          + '</div>'
          + '<div style="margin-top:8px"><span class="pill ' + esc(c.status||'active') + '">' + esc(c.status||'active') + '</span></div>'
          + '</div>';
      }).join('');
      html += '<div class="section"><div class="section-head"><i class="fa-solid fa-chalkboard-teacher"></i><h2>Class &amp; Course Summary</h2><span class="badge">' + classSummary.length + '</span></div>'
            + '<div class="class-grid">' + cards + '</div></div>';
    }

    // Two-column: upcoming + announcements / lesson timeline
    var leftCol = '';
    var rightCol = '';

    // Upcoming sessions (left)
    if (upcoming.length) {
      var upItems = upcoming.slice(0,10).map(function(u){
        var dm = fmtDayMon(u.scheduled_at);
        return '<div class="upcoming-item">'
          + '<div class="upcoming-date-col"><div class="upcoming-day">' + esc(dm.day) + '</div><div class="upcoming-mon">' + esc(dm.mon) + '</div></div>'
          + '<div class="upcoming-info">'
          + '<div class="upcoming-title">' + esc(u.title||'Session') + '<span class="upcoming-badge">' + esc(u.kind||'class') + '</span></div>'
          + '<div class="upcoming-sub">' + (u.scheduled_at ? fmtDateTime(u.scheduled_at).split(',')[1]||'' : '') + (u.cohort_title ? ' · ' + esc(u.cohort_title) : '') + (u.duration_min ? ' · ' + parseInt(u.duration_min,10) + 'min' : '') + '</div>'
          + (u.instructor_name ? '<div class="upcoming-sub"><i class="fa-solid fa-user-tie" style="color:var(--gold);margin-right:4px;font-size:0.75rem"></i>' + esc(u.instructor_name) + '</div>' : '')
          + '</div></div>';
      }).join('');
      leftCol += '<div class="section"><div class="section-head"><i class="fa-solid fa-calendar-days"></i><h2>Upcoming Sessions</h2><span class="badge">' + upcoming.length + '</span></div>'
               + '<div class="upcoming-list">' + upItems + '</div></div>';
    }

    // Announcements (left)
    if (announcements.length) {
      var annItems = announcements.map(function(a){
        return '<div class="announce-item">'
          + (a.title ? '<div class="announce-title">' + esc(a.title) + '</div>' : '')
          + '<div class="announce-body">' + esc(a.body) + '</div>'
          + '<div class="announce-meta">' + (a.author_name ? esc(a.author_name) + ' · ' : '') + fmtDate(a.created_at) + '</div>'
          + '</div>';
      }).join('');
      leftCol += '<div class="section"><div class="section-head"><i class="fa-solid fa-bullhorn"></i><h2>Announcements</h2></div>' + annItems + '</div>';
    }

    // Lesson timeline (right column)
    var tlItems = sessions.slice(0,30).map(function(ses, i){
      var att  = ses.attendance_status || 'no-mark';
      var dots = ['present','absent','late'].indexOf(att) >= 0 ? att : 'no-mark';
      var hasNotes = ses.topics || ses.lesson_notes || ses.learning_outcomes;
      var notesHtml = '';
      if (ses.topics) notesHtml += '<div style="margin-bottom:4px"><div class="tl-note-label">Topics</div><div class="tl-note-text">' + esc(ses.topics) + '</div></div>';
      if (ses.lesson_notes) notesHtml += '<div style="margin-bottom:4px"><div class="tl-note-label">Lesson Notes</div><div class="tl-note-text">' + esc(ses.lesson_notes) + '</div></div>';
      if (ses.learning_outcomes) notesHtml += '<div><div class="tl-note-label">Learning Outcomes</div><div class="tl-note-text">' + esc(ses.learning_outcomes) + '</div></div>';
      return '<div class="tl-item">'
        + '<div class="tl-dot-wrap"><div class="tl-dot ' + dots + '"></div>' + (i < sessions.length-1 ? '<div class="tl-line"></div>' : '') + '</div>'
        + '<div class="tl-content">'
        + '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">'
        + '<span class="tl-title">' + esc(ses.title||('Session #'+(i+1))) + '</span>'
        + (ses.attendance_status ? '<span class="pill ' + esc(att) + '">' + esc(att) + '</span>' : '')
        + '</div>'
        + '<div class="tl-meta">' + fmtDateTime(ses.scheduled_at) + (ses.instructor_name ? ' · ' + esc(ses.instructor_name) : '') + (ses.cohort_title ? ' · ' + esc(ses.cohort_title) : '') + (ses.duration_min ? ' · ' + parseInt(ses.duration_min,10) + 'min' : '') + '</div>'
        + (hasNotes ? '<div class="tl-notes">' + notesHtml + '</div>' : '<div class="tl-no-notes">No lesson record for this session.</div>')
        + '</div></div>';
    }).join('');

    rightCol += '<div class="section"><div class="section-head"><i class="fa-solid fa-book-open"></i><h2>Lesson Timeline</h2><span class="badge">' + sessions.length + ' sessions</span></div>'
             + (tlItems ? '<div class="timeline">' + tlItems + '</div>' : '<div class="empty"><i class="fa-solid fa-book-open"></i>No session records yet.</div>')
             + '</div>';

    // Two-column wrapper
    if (leftCol && rightCol) {
      html += '<div class="two-col">' + '<div>' + leftCol + '</div>' + '<div>' + rightCol + '</div>' + '</div>';
    } else {
      html += leftCol + rightCol;
    }

    // Assessment records
    if (assessments.length) {
      var aRows = assessments.slice(0,30).map(function(a){
        var pct = (a.max_score > 0 && a.score !== null) ? Math.round(a.score/a.max_score*100) : null;
        var col = scoreColor(pct);
        return '<tr>'
          + '<td>' + esc(a.course_name||'—') + '</td>'
          + '<td>' + esc(a.type_name||'—') + '</td>'
          + '<td>' + (a.week ? 'Wk '+a.week : '—') + '</td>'
          + '<td>' + (pct !== null
              ? '<strong style="color:'+col+'">' + pct + '%</strong> <span style="color:var(--text-muted);font-size:0.8rem">(' + a.score + '/' + a.max_score + ')</span>'
                + '<div class="score-bar"><div class="score-fill" style="width:'+Math.min(100,pct)+'%;background:'+col+'"></div></div>'
              : '—') + '</td>'
          + '<td style="color:var(--text-muted);font-size:0.8rem">' + esc(a.grader_name||'—') + '</td>'
          + '<td style="color:var(--text-muted);font-size:0.8rem">' + fmtDate(a.created_at) + '</td>'
          + '</tr>';
      }).join('');
      html += '<div class="section"><div class="section-head"><i class="fa-solid fa-star"></i><h2>Assessment Records</h2><span class="badge">' + assessments.length + '</span></div>'
            + '<table><thead><tr><th>Course</th><th>Type</th><th>Week</th><th>Score</th><th>Graded by</th><th>Date</th></tr></thead><tbody>' + aRows + '</tbody></table></div>';
    }

    // Course Results
    if (results.length) {
      var resItems = results.map(function(r){
        var pct = r.total_score !== null ? Math.round(r.total_score) : null;
        var col = scoreColor(pct);
        return '<div class="result-item">'
          + '<div class="result-info">'
          + '<div class="result-course">' + esc(r.course_name) + (r.level_name ? ' <span style="color:var(--text-muted);font-weight:400">· ' + esc(r.level_name) + '</span>' : '') + '</div>'
          + (r.issued_at ? '<div class="result-sub">Issued ' + fmtDate(r.issued_at) + '</div>' : '')
          + '<div class="result-bar-wrap"><div class="score-bar"><div class="score-fill" style="width:'+Math.min(100,pct||0)+'%;background:'+col+'"></div></div></div>'
          + '</div>'
          + '<div class="result-score">'
          + '<div class="result-pct" style="color:'+col+'">' + (pct !== null ? pct+'%' : '—') + '</div>'
          + (r.grade ? '<div style="font-size:0.8rem;color:var(--text-muted)">Grade: ' + esc(r.grade) + '</div>' : '')
          + '<span class="pill ' + esc(r.outcome||'') + '">' + esc(r.outcome||'pending') + '</span>'
          + '</div></div>';
      }).join('');
      html += '<div class="section"><div class="section-head"><i class="fa-solid fa-chart-pie"></i><h2>Course Results</h2></div>' + resItems + '</div>';
    }

    // Certificates
    if (certs.length) {
      var certItems = certs.map(function(c){
        return '<div class="cert-item">'
          + '<div class="cert-icon"><i class="fa-solid fa-' + (c.kind==='completion'?'certificate':'medal') + '"></i></div>'
          + '<div class="cert-info">'
          + '<div class="cert-title">' + esc(c.title || (c.kind||'Certificate')) + '</div>'
          + '<div class="cert-sub">' + [c.program_name, c.level_name].filter(Boolean).join(' · ') + (c.issued_at ? ' · Issued ' + fmtDate(c.issued_at) : '') + '</div>'
          + (c.reference ? '<div style="font-size:0.75rem;color:var(--text-soft);margin-top:2px">Ref: ' + esc(c.reference) + '</div>' : '')
          + '</div></div>';
      }).join('');
      html += '<div class="section"><div class="section-head"><i class="fa-solid fa-medal"></i><h2>Certificates &amp; Achievements</h2><span class="badge">' + certs.length + '</span></div>'
            + '<div class="cert-list">' + certItems + '</div></div>';
    }

    // Practice logs
    if (practiceLogs.length) {
      var maxMin = Math.max.apply(null, practiceLogs.map(function(p){return parseInt(p.minutes)||0;}));
      var pbars  = practiceLogs.slice(0,14).map(function(p){
        var m   = parseInt(p.minutes)||0;
        var pct = maxMin > 0 ? Math.round(m/maxMin*100) : 0;
        return '<div class="pbar-row">'
          + '<div class="pbar-label">' + esc(p.logged_on||'') + '</div>'
          + '<div class="pbar"><div class="pbar-fill" style="width:'+pct+'%"></div></div>'
          + '<div class="pbar-val">' + esc(fmtMin(m)) + '</div>'
          + '</div>';
      }).join('');
      html += '<div class="section"><div class="section-head"><i class="fa-solid fa-music"></i><h2>Practice Activity</h2></div>'
            + '<div class="practice-bar-wrap">'
            + '<div style="font-size:0.8rem;color:var(--text-muted);margin-bottom:12px">Total logged: <strong>' + esc(fmtMin(practiceMin)) + '</strong></div>'
            + pbars + '</div></div>';
    }

    html += '<div class="report-footer">MAM Academy · academy.mamcentre.org · This report is confidential. Please handle it with care.</div>';

    document.getElementById('dashContent').innerHTML = html;
    showView('dashView');
  }
})();
</script>
</body>
</html>
