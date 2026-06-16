<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark">
<title>Application Status — MAM Academy</title>
<meta name="description" content="Check the status of your MAM Academy application with your reference and email.">
<link rel="canonical" href="https://academy.mamcentre.org/status">
<meta name="robots" content="noindex">
<meta property="og:type" content="website">
<meta property="og:site_name" content="MAM Academy">
<meta property="og:title" content="Application Status — MAM Academy">
<meta property="og:description" content="Check where your MAM Academy application stands.">
<meta property="og:url" content="https://academy.mamcentre.org/status">
<meta property="og:image" content="https://academy.mamcentre.org/mam-academy-logo.svg">
<link rel="icon" type="image/svg+xml" href="mam-academy-logo.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Cinzel:wght@600;700&family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" referrerpolicy="no-referrer">
<link rel="stylesheet" href="assets/css/main.css?v=20260616g">
<style>
  .st-wrap{max-width:620px;margin:0 auto}
  .st-card{background:#1A120B;border:1px solid rgba(224,138,26,.35);border-radius:16px;padding:26px 24px;box-shadow:0 12px 36px rgba(0,0,0,.28)}
  .st-card h2{font-family:'Cinzel',serif;color:#fff;margin:0 0 4px;font-size:1.3rem}
  .st-field{display:block;margin:14px 0}
  .st-field span{display:block;font-size:.82rem;color:#cbb89e;margin-bottom:6px;font-weight:600}
  .st-field input{width:100%;padding:12px 14px;border-radius:10px;border:1px solid rgba(224,138,26,.3);background:#120c07;color:#fff;font-size:1rem}
  .st-field input:focus{outline:none;border-color:#E08A1A}
  .st-btn{margin-top:6px;width:100%;padding:13px 18px;border:0;border-radius:11px;font-weight:800;letter-spacing:.04em;cursor:pointer;background:linear-gradient(135deg,#E08A1A,#FBBF24);color:#1A120B;font-size:1rem}
  .st-btn[disabled]{opacity:.6;cursor:default}
  .st-result{margin-top:22px}
  .st-badge{display:inline-flex;align-items:center;gap:8px;padding:7px 14px;border-radius:999px;font-weight:800;font-size:.8rem;letter-spacing:.06em;text-transform:uppercase;background:rgba(0,0,0,.25)}
  .st-steps{display:flex;gap:6px;margin:16px 0;flex-wrap:wrap}
  .st-step{flex:1;min-width:88px;text-align:center;padding:10px 6px;border-radius:10px;background:#120c07;border:1px solid rgba(224,138,26,.18);font-size:.74rem;color:#9a8264;font-weight:600}
  .st-step.on{border-color:#E08A1A;color:#FBBF24;background:rgba(224,138,26,.12)}
  .st-next{margin-top:14px;color:#e7d9c2;line-height:1.65;font-size:.96rem}
  .st-muted{color:#9a8264;font-size:.86rem;line-height:1.55}
  .st-muted a{color:#FBBF24}
</style>
</head>
<body>
<a href="#main" class="skip-link">Skip to main content</a>
<div id="preloader"><div class="preloader-logo"><img src="mam-academy-logo.svg" alt="" onerror="this.style.display='none'"><div class="preloader-name">MAM ACADEMY</div><div class="preloader-tag">Application Status</div></div><div class="waves"><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div></div>

<?php include __DIR__ . '/partials/header.html'; ?>

<main id="main">
  <header class="page-hero" style="--page-hero-img:url('https://images.unsplash.com/photo-1493225457124-a3eb161ffa5f?w=1920&q=80')">
    <div class="page-hero-inner">
      <nav class="breadcrumb"><a href="/">Home</a><i class="fa-solid fa-chevron-right"></i><span>Application Status</span></nav>
      <div class="page-hero-eyebrow"><i class="fa-solid fa-clipboard-check"></i>Admissions</div>
      <h1 class="page-hero-title">Your <span class="highlight-gold">application status</span></h1>
      <p class="page-hero-lede">Enter your reference and the email you applied with to see exactly where things stand.</p>
    </div>
  </header>

  <section style="padding:48px 20px">
    <div class="st-wrap">
      <div class="st-card">
        <h2>Check your status</h2>
        <p class="st-muted">Your reference looks like <strong>MAM-XXXX-XXXX</strong> — it's in your confirmation email.</p>
        <form id="stForm" novalidate>
          <label class="st-field"><span>Application reference</span><input id="stRef" type="text" autocomplete="off" placeholder="MAM-…" required></label>
          <label class="st-field"><span>Email you applied with</span><input id="stEmail" type="email" autocomplete="email" placeholder="you@example.com" required></label>
          <button class="st-btn" id="stGo" type="submit">Check status</button>
        </form>
        <div class="st-result" id="stResult" hidden></div>
      </div>
      <p class="st-muted" style="text-align:center;margin-top:18px">Need to finish applying? <a href="/apply">Continue your application →</a></p>
      <p class="st-muted" style="text-align:center;margin-top:6px">Been placed after your MAM Auditer? <a href="/tuition" id="stTuitionLink">Pay your tuition →</a></p>
    </div>
  </section>
</main>

<?php include __DIR__ . '/partials/footer.html'; ?>
<script src="assets/js/config.js?v=20260616a"></script>
<script src="assets/js/main.js?v=20260616e"></script>
<script>
(function () {
  var form = document.getElementById('stForm'), out = document.getElementById('stResult'), btn = document.getElementById('stGo');
  var refEl = document.getElementById('stRef'), emEl = document.getElementById('stEmail');
  var qs = new URLSearchParams(location.search);
  if (qs.get('ref')) refEl.value = qs.get('ref');
  if (qs.get('email')) emEl.value = qs.get('email');
  // Carry the reference + email through to the gated tuition page so the
  // applicant doesn't have to re-type them.
  var tuLink = document.getElementById('stTuitionLink');
  function syncTuition() {
    if (!tuLink) return;
    var r = refEl.value.trim(), e = emEl.value.trim(), q = [];
    if (r) q.push('ref=' + encodeURIComponent(r));
    if (e) q.push('email=' + encodeURIComponent(e));
    tuLink.href = '/tuition' + (q.length ? '?' + q.join('&') : '');
  }
  refEl.addEventListener('input', syncTuition); emEl.addEventListener('input', syncTuition); syncTuition();
  var STAGES = [['received', 'Received'], ['confirmed', 'Confirmed'], ['accepted', 'Accepted'], ['enrolled', 'Enrolled']];
  function esc(s) { return (s == null ? '' : String(s)).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function color(stage) { return (stage === 'enrolled' || stage === 'accepted' || stage === 'confirmed') ? '#34D399' : (stage === 'closed' ? '#FB7185' : '#FBBF24'); }
  function render(a) {
    var idx = { received: 0, confirmed: 1, accepted: 2, enrolled: 3 }, on = idx[a.stage];
    var steps = STAGES.map(function (s, i) { return '<div class="st-step' + (on != null && i <= on ? ' on' : '') + '">' + s[1] + '</div>'; }).join('');
    out.innerHTML = '<div style="text-align:center"><span class="st-badge" style="color:' + color(a.stage) + ';border:1px solid ' + color(a.stage) + '">' + esc(a.stage_label) + '</span></div>'
      + (a.stage !== 'closed' ? '<div class="st-steps">' + steps + '</div>' : '')
      + '<div class="st-next">' + esc(a.next_step) + '</div>'
      + '<p class="st-muted" style="margin-top:14px">Reference <strong>' + esc(a.reference) + '</strong>' + (a.pathway ? ' · ' + esc(a.pathway) : '') + (a.submitted_at ? ' · applied ' + esc(String(a.submitted_at).slice(0, 10)) : '') + '</p>';
    out.hidden = false;
  }
  function notFound() {
    out.innerHTML = '<p class="st-muted">We couldn\'t find an application with that reference and email. Double-check both, or <a href="/apply">start an application</a>.</p>';
    out.hidden = false;
  }
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var ref = refEl.value.trim(), em = emEl.value.trim();
    if (!ref || !em) return;
    btn.disabled = true; var orig = btn.textContent; btn.textContent = 'Checking…'; out.hidden = true;
    var url = 'application/status?ref=' + encodeURIComponent(ref) + '&email=' + encodeURIComponent(em);
    (window.MAM_API ? window.MAM_API.request(url, { method: 'GET' }) : Promise.reject()).then(function (r) {
      btn.disabled = false; btn.textContent = orig;
      if (r && r.application) render(r.application); else notFound();
    }).catch(function () { btn.disabled = false; btn.textContent = orig; notFound(); });
  });
  if (refEl.value && emEl.value) form.dispatchEvent(new Event('submit'));
})();
</script>
</body>
</html>
