<?php
declare(strict_types=1);
require_once __DIR__ . '/api/lib/bootstrap.php';
header_remove('Content-Type');
header('Content-Type: text/html; charset=utf-8');
if (!defined('MAM_RESPONSE_SENT')) define('MAM_RESPONSE_SENT', true);
$siteName = 'MAM Academy';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="color-scheme" content="dark">
<title>Application Status — MAM Academy</title>
<meta name="description" content="Check the status of your MAM Academy application with your reference number and email.">
<link rel="canonical" href="https://academy.mamcentre.org/status">
<meta name="robots" content="noindex">
<meta property="og:type" content="website">
<meta property="og:site_name" content="MAM Academy">
<meta property="og:title" content="Application Status — MAM Academy">
<meta property="og:description" content="Track your MAM Academy or Summer Programme application in one place.">
<meta property="og:url" content="https://academy.mamcentre.org/status">
<meta property="og:image" content="https://academy.mamcentre.org/api/og?t=Application+Status&s=Track+your+application.&e=Status">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:type" content="image/png">
<link rel="icon" type="image/svg+xml" href="mam-academy-logo.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Cinzel:wght@600;700&family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" referrerpolicy="no-referrer">
<link rel="stylesheet" href="assets/css/main.css?v=20260706d">
<style>
/* ═══════════════════════════════════════════════════════
   Status page — tokens
═══════════════════════════════════════════════════════ */
:root {
  --st-bg:#0C0703; --st-card:#18100A; --st-border:rgba(224,138,26,.22);
  --st-muted:#9A8264; --st-ink:#F0E6D4; --st-gold:#E8961F; --st-gold-lt:#FBBF24;
}

/* ═══ Shell ══════════════════════════════════════════════ */
.st-page { max-width:640px; margin:0 auto; padding:clamp(36px,6vw,72px) 20px clamp(56px,9vw,96px); }

/* ═══ Lookup card ════════════════════════════════════════ */
.st-lookup {
  background:var(--st-card); border:1px solid var(--st-border);
  border-radius:20px; padding:clamp(24px,5vw,40px);
  box-shadow:0 24px 64px rgba(0,0,0,.4);
}
.st-lookup-eyebrow {
  font-size:.68rem; font-weight:800; letter-spacing:.14em; text-transform:uppercase;
  color:var(--st-gold); margin-bottom:8px;
}
.st-lookup-title {
  font-family:'Cinzel',serif; color:#fff; margin:0 0 4px;
  font-size:clamp(1.15rem,3vw,1.5rem); line-height:1.2;
}
.st-lookup-sub {
  color:var(--st-muted); font-size:.87rem; margin:0 0 24px; line-height:1.6;
}
.st-lookup-sub strong { color:var(--st-gold-lt); font-family:monospace; }
.st-field { margin-bottom:16px; }
.st-field-label {
  display:block; font-size:.75rem; font-weight:700; color:#B09070;
  margin-bottom:7px; letter-spacing:.05em; text-transform:uppercase;
}
.st-field input {
  width:100%; padding:13px 16px; border-radius:12px;
  border:1px solid rgba(224,138,26,.25); background:#0A0602; color:#fff;
  font-size:.98rem; font-family:inherit; box-sizing:border-box;
  transition:border-color .2s, box-shadow .2s;
}
.st-field input:focus {
  outline:none; border-color:var(--st-gold);
  box-shadow:0 0 0 3px rgba(224,138,26,.12);
}
.st-field input::placeholder { color:#4A3520; }
.st-submit {
  width:100%; padding:15px 20px; border:none; border-radius:13px;
  font-weight:800; font-size:1rem; letter-spacing:.04em; cursor:pointer;
  background:linear-gradient(135deg,#C97B10,var(--st-gold-lt));
  color:#0A0602; margin-top:2px;
  transition:transform .15s, box-shadow .15s, opacity .15s;
}
.st-submit:hover:not([disabled]) { transform:translateY(-2px); box-shadow:0 8px 28px rgba(224,138,26,.32); }
.st-submit[disabled] { opacity:.55; cursor:default; }

/* ═══ Quick links ════════════════════════════════════════ */
.st-quick-links {
  display:flex; flex-wrap:wrap; gap:8px; margin-top:18px;
  padding-top:18px; border-top:1px solid rgba(224,138,26,.12);
}
.st-quick-link {
  font-size:.8rem; color:var(--st-muted); text-decoration:none;
  display:inline-flex; align-items:center; gap:5px;
  padding:5px 12px; border:1px solid rgba(224,138,26,.18); border-radius:999px;
  transition:border-color .15s, color .15s;
}
.st-quick-link:hover { border-color:var(--st-gold); color:var(--st-gold); }

/* ═══ Result card ════════════════════════════════════════ */
.st-result { margin-top:20px; display:none; }
.st-result.visible { display:block; }
.st-result-card {
  background:var(--st-card); border:1.5px solid var(--stage-border,var(--st-border));
  border-radius:20px; overflow:hidden;
  box-shadow:0 16px 48px rgba(0,0,0,.4);
}
.st-stripe { height:4px; background:var(--stage-stripe,linear-gradient(90deg,#E08A1A,#FBBF24)); }

/* Header inside card */
.st-result-header {
  display:flex; align-items:flex-start; gap:16px;
  padding:24px 24px 0;
}
.st-result-photo {
  width:64px; height:64px; border-radius:50%; object-fit:cover; flex-shrink:0;
  border:2px solid var(--stage-color,var(--st-gold)); background:#0A0602;
}
.st-result-photo-placeholder {
  width:64px; height:64px; border-radius:50%; flex-shrink:0;
  background:var(--stage-bg,rgba(224,138,26,.1));
  border:2px solid var(--stage-border,rgba(224,138,26,.25));
  display:flex; align-items:center; justify-content:center;
  font-size:1.6rem;
}
.st-result-head-text { flex:1; min-width:0; }
.st-result-stage-label {
  font-family:'Cinzel',serif; color:var(--stage-color,var(--st-gold-lt));
  font-size:1.15rem; font-weight:700; margin:0 0 4px; line-height:1.2;
}
.st-result-name { color:var(--st-ink); font-size:.92rem; margin:0 0 8px; }
.st-result-prog {
  display:inline-flex; align-items:center; gap:6px;
  background:var(--stage-bg,rgba(224,138,26,.1));
  border:1px solid var(--stage-border,rgba(224,138,26,.22));
  color:var(--stage-color,var(--st-gold)); font-size:.75rem; font-weight:700;
  padding:4px 12px; border-radius:999px; letter-spacing:.04em;
}

/* Progress rail */
.st-rail {
  display:flex; align-items:stretch; gap:0; padding:22px 24px 0; margin:0;
}
.st-rail-step {
  flex:1; display:flex; flex-direction:column; align-items:center; gap:6px;
  font-size:.65rem; font-weight:700; letter-spacing:.05em; text-transform:uppercase;
  color:#3A2A18; text-align:center; position:relative;
}
.st-rail-dot {
  width:30px; height:30px; border-radius:50%; border:2px solid #221507;
  background:#0A0602; display:flex; align-items:center; justify-content:center;
  font-size:.75rem; color:#3A2A18; z-index:1; position:relative;
  transition:background .3s, border-color .3s, color .3s;
}
.st-rail-step.done .st-rail-dot  { background:var(--stage-color,var(--st-gold)); border-color:var(--stage-color,var(--st-gold)); color:#0A0602; }
.st-rail-step.active .st-rail-dot { border-color:var(--stage-color,var(--st-gold)); color:var(--stage-color,var(--st-gold)); box-shadow:0 0 0 4px var(--stage-glow,rgba(224,138,26,.15)); }
.st-rail-step.done,
.st-rail-step.active { color:var(--stage-color,var(--st-gold-lt)); }
.st-rail-line {
  position:absolute; top:14px; left:calc(50% + 15px);
  width:calc(100% - 30px); height:2px; background:#221507;
}
.st-rail-line.done { background:var(--stage-color,var(--st-gold)); }

/* Next step box */
.st-next {
  margin:20px 24px 0;
  background:var(--stage-bg,rgba(224,138,26,.06));
  border:1px solid var(--stage-border,rgba(224,138,26,.18));
  border-radius:12px; padding:16px 18px;
}
.st-next-label {
  font-size:.65rem; font-weight:800; letter-spacing:.12em; text-transform:uppercase;
  color:var(--stage-color,var(--st-gold)); margin-bottom:6px;
  display:flex; align-items:center; gap:6px;
}
.st-next-text { color:var(--st-ink); font-size:.92rem; line-height:1.7; }

/* Session dates (summer) */
.st-dates {
  margin:14px 24px 0; display:flex; align-items:center; gap:10px;
  background:rgba(245,158,11,.06); border:1px solid rgba(245,158,11,.18);
  border-radius:10px; padding:12px 16px;
}
.st-dates-icon { font-size:1.2rem; flex-shrink:0; }
.st-dates-text { color:var(--st-ink); font-size:.88rem; }
.st-dates-text strong { color:#F59E0B; }

/* Meta chips */
.st-meta { display:flex; flex-wrap:wrap; gap:8px; padding:18px 24px 24px; }
.st-meta-chip {
  display:inline-flex; align-items:center; gap:6px;
  background:#0A0602; border:1px solid #1E1009;
  border-radius:8px; padding:5px 12px; font-size:.77rem; color:var(--st-muted);
}
.st-meta-chip strong { color:var(--st-ink); }
.st-meta-chip i { color:var(--stage-color,var(--st-gold)); font-size:.72rem; }

/* Not found */
.st-not-found {
  margin-top:20px; background:var(--st-card); border:1px solid var(--st-border);
  border-radius:16px; padding:32px 24px; text-align:center;
}
.st-not-found p { color:var(--st-muted); font-size:.92rem; line-height:1.65; margin:0 0 8px; }
.st-not-found a { color:var(--st-gold-lt); text-decoration:none; }

/* Stage CSS variables */
.stage-received  { --stage-color:#FBBF24; --stage-bg:rgba(251,191,36,.08); --stage-border:rgba(251,191,36,.28); --stage-glow:rgba(251,191,36,.18); --stage-stripe:linear-gradient(90deg,#D97706,#FBBF24); }
.stage-reviewing { --stage-color:#A78BFA; --stage-bg:rgba(167,139,250,.08); --stage-border:rgba(167,139,250,.28); --stage-glow:rgba(167,139,250,.18); --stage-stripe:linear-gradient(90deg,#6D28D9,#A78BFA); }
.stage-confirmed { --stage-color:#34D399; --stage-bg:rgba(52,211,153,.08); --stage-border:rgba(52,211,153,.28); --stage-glow:rgba(52,211,153,.18); --stage-stripe:linear-gradient(90deg,#059669,#34D399); }
.stage-accepted  { --stage-color:#34D399; --stage-bg:rgba(52,211,153,.08); --stage-border:rgba(52,211,153,.28); --stage-glow:rgba(52,211,153,.18); --stage-stripe:linear-gradient(90deg,#059669,#34D399); }
.stage-enrolled  { --stage-color:#4ADE80; --stage-bg:rgba(74,222,128,.08); --stage-border:rgba(74,222,128,.28); --stage-glow:rgba(74,222,128,.18); --stage-stripe:linear-gradient(90deg,#16A34A,#4ADE80); }
.stage-waitlisted{ --stage-color:#FB923C; --stage-bg:rgba(251,146,60,.08); --stage-border:rgba(251,146,60,.28); --stage-glow:rgba(251,146,60,.18); --stage-stripe:linear-gradient(90deg,#C2410C,#FB923C); }
.stage-closed    { --stage-color:#94A3B8; --stage-bg:rgba(148,163,184,.06); --stage-border:rgba(148,163,184,.18); --stage-glow:rgba(148,163,184,.12); --stage-stripe:linear-gradient(90deg,#475569,#94A3B8); }
.stage-expired   { --stage-color:#94A3B8; --stage-bg:rgba(148,163,184,.06); --stage-border:rgba(148,163,184,.18); --stage-glow:rgba(148,163,184,.12); --stage-stripe:linear-gradient(90deg,#475569,#94A3B8); }

/* Tuition banner */
.st-tuition-banner {
  display:none; margin-top:12px;
  background:rgba(52,211,153,.07); border:1px solid rgba(52,211,153,.25);
  border-radius:12px; padding:14px 18px;
  align-items:center; gap:12px; flex-wrap:wrap;
}
.st-tuition-banner.show { display:flex; }
.st-tuition-banner p { margin:0; color:#A7F3D0; font-size:.88rem; flex:1; }
.st-tuition-banner a {
  display:inline-flex; align-items:center; gap:6px; white-space:nowrap;
  background:#059669; color:#fff; font-weight:700; font-size:.82rem;
  padding:8px 16px; border-radius:8px; text-decoration:none;
  transition:background .15s;
}
.st-tuition-banner a:hover { background:#047857; }
</style>
</head>
<body>
<a href="#main" class="skip-link">Skip to main content</a>
<div id="preloader">
  <div class="preloader-logo">
    <img src="mam-academy-logo.svg" alt="" onerror="this.style.display='none'">
    <div class="preloader-name">MAM ACADEMY</div>
    <div class="preloader-tag">Application Status</div>
  </div>
  <div class="waves"><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div>
</div>

<?php include __DIR__ . '/partials/header.html'; ?>

<main id="main">
  <header class="page-hero" style="--page-hero-img:url('https://images.unsplash.com/photo-1493225457124-a3eb161ffa5f?w=1920&q=80');padding:clamp(56px,8vw,100px) 24px clamp(40px,6vw,68px)">
    <div class="page-hero-inner">
      <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="/">Home</a><i class="fa-solid fa-chevron-right"></i><span>Status</span>
      </nav>
      <div class="page-hero-eyebrow"><i class="fa-solid fa-clipboard-check"></i>Admissions</div>
      <h1 class="page-hero-title">Track your <span class="highlight-gold">application</span></h1>
      <p class="page-hero-lede">Enter your reference number and email to see exactly where your application stands.</p>
    </div>
  </header>

  <section>
    <div class="st-page">

      <!-- ── Lookup card ─────────────────────────────────────── -->
      <div class="st-lookup">
        <div class="st-lookup-eyebrow"><i class="fa-solid fa-magnifying-glass"></i> Application lookup</div>
        <h2 class="st-lookup-title">Check your status</h2>
        <p class="st-lookup-sub">
          Your reference is in your confirmation email.
          Regular applications start with <strong>MAM-</strong>&ensp;·&ensp;
          Summer applications start with <strong>SUM-</strong>
        </p>

        <form id="stForm" novalidate>
          <div class="st-field">
            <label class="st-field-label" for="stRef">Application reference</label>
            <input id="stRef" type="text" autocomplete="off" placeholder="MAM-… or SUM-…" spellcheck="false" required>
          </div>
          <div class="st-field">
            <label class="st-field-label" for="stEmail">Email you applied with</label>
            <input id="stEmail" type="email" autocomplete="email" placeholder="you@example.com" required>
          </div>
          <button class="st-submit" id="stGo" type="submit">
            <i class="fa-solid fa-arrow-right"></i> Check status
          </button>
        </form>

        <div class="st-quick-links">
          <a href="/apply" class="st-quick-link"><i class="fa-solid fa-pen-to-square"></i> Apply to MAM</a>
          <a href="/summer" class="st-quick-link"><i class="fa-solid fa-sun"></i> Summer programme</a>
          <a href="/auditer#checklist" class="st-quick-link"><i class="fa-solid fa-list-check"></i> Applicant checklist</a>
        </div>
      </div>

      <!-- ── Tuition banner (shown on accepted/enrolled) ─────── -->
      <div class="st-tuition-banner" id="stTuitionBanner">
        <p><i class="fa-solid fa-check-circle"></i>&ensp;You've been accepted — complete your enrolment by paying your tuition.</p>
        <a href="/tuition" id="stTuitionLink">Pay tuition <i class="fa-solid fa-arrow-right"></i></a>
      </div>

      <!-- ── Result ──────────────────────────────────────────── -->
      <div class="st-result" id="stResult"></div>

    </div>
  </section>
</main>

<?php include __DIR__ . '/partials/footer.html'; ?>
<script src="assets/js/config.js?v=20260723a"></script>
<script src="assets/js/main.js?v=20260723a"></script>
<script>
(function () {
  'use strict';

  var form    = document.getElementById('stForm');
  var out     = document.getElementById('stResult');
  var btn     = document.getElementById('stGo');
  var refEl   = document.getElementById('stRef');
  var emEl    = document.getElementById('stEmail');
  var tuBanner = document.getElementById('stTuitionBanner');
  var tuLink   = document.getElementById('stTuitionLink');

  // Pre-fill from URL params
  var qs = new URLSearchParams(location.search);
  if (qs.get('ref'))   refEl.value = qs.get('ref');
  if (qs.get('email')) emEl.value  = qs.get('email');

  function syncTuition(ref, email) {
    if (!tuLink) return;
    var q = [];
    if (ref)   q.push('ref='   + encodeURIComponent(ref));
    if (email) q.push('email=' + encodeURIComponent(email));
    tuLink.href = '/tuition' + (q.length ? '?' + q.join('&') : '');
  }

  // ── Stage configs ─────────────────────────────────────────────
  var REG_STAGES = [
    { key: 'received',  label: 'Received',   icon: '📬' },
    { key: 'confirmed', label: 'Confirmed',  icon: '✅' },
    { key: 'accepted',  label: 'Accepted',   icon: '🎓' },
    { key: 'enrolled',  label: 'Enrolled',   icon: '🎉' },
  ];
  var REG_ORDER = { received: 0, reviewing: 0, confirmed: 1, accepted: 2, enrolled: 3 };

  var SUM_STAGES = [
    { key: 'received',  label: 'Received',  icon: '📬' },
    { key: 'confirmed', label: 'Confirmed', icon: '✅' },
    { key: 'enrolled',  label: 'Enrolled',  icon: '🎉' },
  ];
  var SUM_ORDER = { received: 0, confirmed: 1, enrolled: 2 };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function stageEmoji(stage) {
    return { received:'📬', reviewing:'🔍', confirmed:'✅', accepted:'🎓',
             enrolled:'🎉', waitlisted:'⏳', closed:'🚫', expired:'⏰' }[stage] || '📋';
  }

  function buildRail(stages, order, currentStage) {
    var on = order[currentStage] != null ? order[currentStage] : -1;
    var html = '<div class="st-rail" role="list" aria-label="Application progress">';
    stages.forEach(function (s, i) {
      var cls = i < on ? 'done' : (i === on ? 'active' : '');
      var dot = i < on ? '<i class="fa-solid fa-check" style="font-size:.65rem"></i>' : (i === on ? '●' : (i + 1));
      html += '<div class="st-rail-step ' + cls + '" role="listitem">'
        + (i > 0 ? '<div class="st-rail-line' + (i <= on ? ' done' : '') + '" aria-hidden="true"></div>' : '')
        + '<div class="st-rail-dot">' + dot + '</div>'
        + esc(s.label) + '</div>';
    });
    return html + '</div>';
  }

  function fmtDate(iso) {
    if (!iso) return '';
    try {
      return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
    } catch (e) { return iso.slice(0, 10); }
  }

  function renderResult(a) {
    var isSummer = !!(a.is_summer);
    var stage    = a.stage || 'received';
    var isClosed = stage === 'closed' || stage === 'expired';
    var isWait   = stage === 'waitlisted';
    var ref      = refEl.value.trim();
    var email    = emEl.value.trim();

    // Tuition banner
    if (!isSummer && (stage === 'accepted' || stage === 'enrolled' || stage === 'confirmed')) {
      syncTuition(ref, email);
      if (tuBanner) tuBanner.classList.add('show');
    } else {
      if (tuBanner) tuBanner.classList.remove('show');
    }

    // Photo section
    var photoHtml;
    if (a.headshot_url) {
      photoHtml = '<img class="st-result-photo" src="' + esc(a.headshot_url) + '" alt="Applicant photo">';
    } else {
      photoHtml = '<div class="st-result-photo-placeholder">' + stageEmoji(stage) + '</div>';
    }

    // Programme badge
    var progLabel = a.programme || a.pathway || (isSummer ? 'Summer Programme' : 'MAM Academy');
    var progBadge = isSummer
      ? '<span class="st-result-prog"><i class="fa-solid fa-sun"></i>' + esc(progLabel) + '</span>'
      : '<span class="st-result-prog"><i class="fa-solid fa-graduation-cap"></i>' + esc(progLabel) + '</span>';

    // Progress rail (skip for terminal states)
    var rail = (isClosed || isWait) ? '' : (isSummer
      ? buildRail(SUM_STAGES, SUM_ORDER, stage)
      : buildRail(REG_STAGES, REG_ORDER, stage));

    // Session dates (summer only)
    var datesHtml = '';
    if (isSummer && (a.session_start || a.session_end)) {
      var d = '';
      if (a.session_start && a.session_end) d = fmtDate(a.session_start) + ' — ' + fmtDate(a.session_end);
      else if (a.session_start) d = 'From ' + fmtDate(a.session_start);
      else d = 'Until ' + fmtDate(a.session_end);
      datesHtml = '<div class="st-dates"><div class="st-dates-icon">📅</div>'
        + '<div class="st-dates-text">Programme dates: <strong>' + esc(d) + '</strong></div></div>';
    }

    // Meta chips
    var meta = '';
    meta += '<span class="st-meta-chip"><i class="fa-solid fa-hashtag"></i><strong>' + esc(a.reference) + '</strong></span>';
    if (a.first_name) meta += '<span class="st-meta-chip"><i class="fa-solid fa-user"></i><strong>' + esc(a.first_name) + '</strong></span>';
    if (a.submitted_at) meta += '<span class="st-meta-chip"><i class="fa-regular fa-calendar"></i>Applied <strong>' + esc(String(a.submitted_at).slice(0,10)) + '</strong></span>';
    if (isSummer && a.session_year) meta += '<span class="st-meta-chip"><i class="fa-solid fa-sun"></i>Summer <strong>' + esc(a.session_year) + '</strong></span>';

    out.innerHTML =
      '<div class="st-result-card stage-' + esc(stage) + '">'
      + '<div class="st-stripe"></div>'
      + '<div class="st-result-header">'
        + photoHtml
        + '<div class="st-result-head-text">'
          + '<div class="st-result-stage-label">' + esc(a.stage_label) + '</div>'
          + '<div class="st-result-name">' + esc(a.first_name || '') + (a.last_name ? ' ' + esc(a.last_name) : '') + '</div>'
          + progBadge
        + '</div>'
      + '</div>'
      + rail
      + datesHtml
      + (a.next_step
        ? '<div class="st-next"><div class="st-next-label"><i class="fa-solid fa-arrow-right-long"></i>What\'s next</div><div class="st-next-text">' + esc(a.next_step) + '</div></div>'
        : '')
      + '<div class="st-meta">' + meta + '</div>'
      + '</div>';

    out.classList.add('visible');
    out.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function renderNotFound() {
    if (tuBanner) tuBanner.classList.remove('show');
    out.innerHTML =
      '<div class="st-not-found">'
      + '<p style="font-size:1.5rem;margin-bottom:12px">🔍</p>'
      + '<p>We couldn\'t find an application matching that reference and email.</p>'
      + '<p>Double-check both fields — or <a href="/apply">start a new application</a> · <a href="/summer">summer programme</a></p>'
      + '</div>';
    out.classList.add('visible');
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var ref   = refEl.value.trim();
    var email = emEl.value.trim();
    if (!ref || !email) return;

    btn.disabled = true;
    var orig = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Checking…';
    out.classList.remove('visible');
    out.innerHTML = '';

    // Route by prefix
    var isSummer = ref.replace(/\s/g,'').toUpperCase().startsWith('SUM-');
    var endpoint = isSummer
      ? 'summer/status?ref=' + encodeURIComponent(ref) + '&email=' + encodeURIComponent(email)
      : 'application/status?ref=' + encodeURIComponent(ref) + '&email=' + encodeURIComponent(email);

    function done() { btn.disabled = false; btn.innerHTML = orig; }

    var p = window.MAM_API
      ? window.MAM_API.request(endpoint, { method: 'GET' })
      : fetch('/api/' + endpoint).then(function (r) { return r.json(); });

    p.then(function (r) {
      done();
      if (r && r.application) renderResult(r.application);
      else renderNotFound();
    }).catch(function () { done(); renderNotFound(); });
  });

  // Auto-submit if URL has both params
  if (refEl.value && emEl.value) form.dispatchEvent(new Event('submit'));
})();
</script>
</body>
</html>
