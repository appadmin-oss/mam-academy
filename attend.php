<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark">
<title>Class Check-in — MAM Academy</title>
<meta name="description" content="Check in to your MAM Academy class session.">
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/svg+xml" href="mam-academy-logo.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Cinzel:wght@600;700&family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" referrerpolicy="no-referrer">
<link rel="stylesheet" href="assets/css/main.css?v=20260706d">
<style>
  .attend-wrap{min-height:70vh;display:flex;align-items:center;justify-content:center;padding:40px 20px}
  .attend-card{max-width:460px;width:100%;text-align:center;background:var(--bg-surface,#1A120B);border:1px solid var(--border-gold,rgba(224,138,26,.4));border-radius:20px;padding:36px 28px;box-shadow:0 30px 70px rgba(0,0,0,.5)}
  .attend-icon{width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;font-size:1.9rem}
  .attend-icon.wait{background:rgba(224,138,26,.14);color:var(--gold-bright,#FCC647)}
  .attend-icon.ok{background:rgba(52,211,153,.16);color:#34D399}
  .attend-icon.err{background:rgba(248,113,113,.16);color:#F87171}
  .attend-card h1{font-family:var(--font-serif,Georgia,serif);font-size:1.7rem;color:var(--text,#fff);margin:0 0 8px}
  .attend-card p{color:var(--text-soft,#cbb89e);font-size:.98rem;line-height:1.6;margin:0 0 8px}
  .attend-meta{margin-top:12px;font-size:.85rem;color:var(--text-muted,#9a8264)}
  .attend-actions{margin-top:22px;display:flex;gap:10px;flex-wrap:wrap;justify-content:center}
  .attend-actions .btn{margin:0}
  .attend-spin{display:inline-block;width:20px;height:20px;border:3px solid rgba(224,138,26,.3);border-top-color:var(--gold-bright,#FCC647);border-radius:50%;animation:attendspin .8s linear infinite;vertical-align:middle}
  @keyframes attendspin{to{transform:rotate(360deg)}}
  @media (prefers-reduced-motion:reduce){.attend-spin{animation:none}}
</style>
</head>
<body>
<a href="#main" class="skip-link">Skip to main content</a>

<?php include __DIR__ . '/partials/header.html'; ?>

<main id="main">
  <div class="attend-wrap">
    <div class="attend-card" id="attendCard" role="status" aria-live="polite">
      <div class="attend-icon wait" id="attendIcon"><span class="attend-spin" aria-hidden="true"></span></div>
      <h1 id="attendTitle">Checking you in…</h1>
      <p id="attendMsg">One moment while we mark your attendance.</p>
      <div class="attend-meta" id="attendMeta" hidden></div>
      <div class="attend-actions" id="attendActions" hidden></div>
    </div>
  </div>
</main>

<?php include __DIR__ . '/partials/footer.html'; ?>

<script src="assets/js/config.js?v=20260706a"></script>
<script src="assets/js/main.js?v=20260706a"></script>
<script>
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  function token() { try { return new URLSearchParams(location.search).get('token') || ''; } catch (e) { return ''; } }

  function render(kind, title, msg, meta, actions) {
    var icon = $('attendIcon'); icon.className = 'attend-icon ' + kind;
    icon.innerHTML = kind === 'ok' ? '<i class="fa-solid fa-circle-check"></i>'
      : kind === 'err' ? '<i class="fa-solid fa-circle-exclamation"></i>'
      : '<span class="attend-spin" aria-hidden="true"></span>';
    $('attendTitle').textContent = title;
    $('attendMsg').textContent = msg;
    var m = $('attendMeta'); if (meta) { m.textContent = meta; m.hidden = false; } else { m.hidden = true; }
    var a = $('attendActions');
    if (actions && actions.length) { a.innerHTML = actions.join(''); a.hidden = false; } else { a.hidden = true; }
  }
  var loginBtn = function () {
    var next = encodeURIComponent(location.pathname + location.search);
    return '<a class="btn btn-primary" href="/portal/login?next=' + next + '"><i class="fa-solid fa-right-to-bracket"></i> Sign in to check in</a>';
  };
  var portalBtn = '<a class="btn btn-secondary" href="/portal/student/"><i class="fa-solid fa-table-columns"></i> My portal</a>';

  function boot() {
    var tok = token();
    if (!tok) { render('err', 'Nothing to check in to', 'This link is missing its check-in code. Please scan the QR your instructor is showing.', null, [portalBtn]); return; }
    if (!window.MAM_API || !window.MAM_AUTH) { render('err', 'Something went wrong', 'Please reload the page and try again.', null, []); return; }

    window.MAM_AUTH.state().then(function (st) {
      if (!st || !st.authenticated) {
        render('wait', 'Almost there', 'Sign in as a student to check in — we’ll bring you right back here.', null, [loginBtn()]);
        return;
      }
      if (st.role !== 'student') {
        render('err', 'Students only', 'Attendance check-in is for enrolled students. You’re signed in as ' + st.role + '.', null, []);
        return;
      }
      window.MAM_API.request('attendance/checkin', { method: 'POST', body: { token: tok } })
        .then(function (r) {
          var when = r && r.date ? r.date : '';
          var cls = r && r.class_title ? r.class_title : 'your class';
          if (r && r.already) {
            render('ok', 'You’re already checked in', 'Your attendance for ' + cls + ' is recorded — no need to scan again.', when ? ('Session: ' + when) : null, [portalBtn]);
          } else {
            render('ok', 'Checked in ✓', 'You’re marked present for ' + cls + '. Have a great session!', when ? ('Session: ' + when) : null, [portalBtn]);
          }
        })
        .catch(function (e) {
          var code = (e && (e.error || e.code)) || '';
          var detail = (e && e.detail) || '';
          if (code === 'not_enrolled') render('err', 'Not enrolled here', detail || 'You’re not enrolled in this class, so we can’t check you in. Speak to your instructor if this looks wrong.', null, [portalBtn]);
          else if (code === 'invalid_or_expired') render('err', 'Code expired', detail || 'This check-in code has expired. Ask your instructor to show today’s QR again.', null, [portalBtn]);
          else render('err', 'Couldn’t check you in', detail || 'Please try scanning again in a moment.', null, [portalBtn]);
        });
    }).catch(function () {
      render('wait', 'Almost there', 'Sign in as a student to check in.', null, [loginBtn()]);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
</script>
</body>
</html>
