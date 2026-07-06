<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark">
<meta name="robots" content="noindex, nofollow">
<title>Instructor Application — MAM Academy</title>
<link rel="canonical" href="https://academy.mamcentre.org/instructor-apply">
<link rel="icon" type="image/svg+xml" href="mam-academy-logo.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Cinzel:wght@600;700&family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" referrerpolicy="no-referrer">
<link rel="stylesheet" href="assets/css/main.css?v=20260706d">
<link rel="stylesheet" href="assets/css/apply.css?v=20260615a">
<style>
  .fac-shell{max-width:760px;margin:0 auto;padding:110px 20px 80px}
  .fac-card{background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius-xl);padding:34px 30px}
  .fac-card h1{font-family:var(--font-serif);font-size:clamp(1.7rem,4vw,2.3rem);margin:0 0 8px;color:var(--text)}
  .fac-eye{font-size:0.74rem;font-weight:800;letter-spacing:0.14em;text-transform:uppercase;color:var(--gold-bright);margin-bottom:10px}
  .fac-lede{color:var(--text-soft);line-height:1.7;font-size:0.98rem;margin:0 0 26px}
  .fac-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  .fac-grid .full{grid-column:1/-1}
  @media (max-width:620px){.fac-grid{grid-template-columns:1fr}.fac-card{padding:26px 20px}}
  .fac-field label{display:block;font-size:0.76rem;font-weight:800;letter-spacing:0.08em;text-transform:uppercase;color:var(--text-muted);margin-bottom:7px}
  .fac-field input,.fac-field textarea{width:100%;padding:12px 14px;background:var(--bg-raised);border:1.5px solid var(--border-strong);border-radius:10px;color:var(--text);font-size:0.95rem;font-family:inherit;min-height:44px}
  .fac-field textarea{min-height:130px;resize:vertical;line-height:1.6}
  .fac-field input:focus-visible,.fac-field textarea:focus-visible{outline:none;border-color:var(--gold-bright);box-shadow:0 0 0 4px rgba(252,198,71,0.18)}
  .fac-help{font-size:0.78rem;color:var(--text-muted);margin-top:6px;line-height:1.5}
  .fac-err{display:none;margin:0 0 18px;padding:12px 16px;border:1px solid rgba(244,63,94,0.45);background:rgba(244,63,94,0.08);border-radius:10px;color:#FB7185;font-size:0.88rem;line-height:1.5}
  .fac-err.show{display:block}
  .fac-gate{padding:26px;text-align:center;color:var(--text-soft);line-height:1.7}
  .fac-gate i{font-size:1.6rem;color:var(--gold-bright);display:block;margin-bottom:10px}
  .fac-success{text-align:center;padding:30px 10px}
  .fac-success .ic{width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;background:linear-gradient(135deg,rgba(31,191,143,0.2),rgba(31,191,143,0.05));border:1px solid rgba(31,191,143,0.45);color:#1FBF8F;font-size:1.5rem}
  .fac-success h2{font-family:var(--font-serif);color:var(--text);margin:0 0 10px}
</style>
</head>
<body>
<a href="#main" class="skip-link">Skip to main content</a>

<?php include __DIR__ . '/partials/header.html'; ?>

<main id="main">
  <div class="fac-shell">
    <div class="fac-card" id="facCard">
      <div class="fac-eye"><i class="fa-solid fa-chalkboard-user"></i> Teach at MAM</div>
      <h1>Instructor Application</h1>
      <p class="fac-lede" id="facLede">Tell us who you are as a musician and a teacher. The faculty team reviews every application personally — your details, links and headshot all help us review you properly.</p>

      <div class="fac-gate" id="facGate"><i class="fa-solid fa-circle-notch fa-spin"></i>Checking your invitation…</div>

      <form id="facForm" hidden novalidate>
        <div class="fac-err" id="facErr"></div>
        <div class="fac-grid">
          <div class="fac-field full"><label for="f-email">Email (from your invite)</label><input type="email" id="f-email" disabled></div>
          <div class="fac-field"><label for="f-name">Full name *</label><input type="text" id="f-name" maxlength="160" required autocomplete="name"></div>
          <div class="fac-field"><label for="f-phone">Phone</label><input type="tel" id="f-phone" maxlength="40" autocomplete="tel" placeholder="+234 …"></div>
          <div class="fac-field"><label for="f-disc">Disciplines you teach *</label><input type="text" id="f-disc" maxlength="300" required placeholder="e.g. Piano, Music Theory, Voice"></div>
          <div class="fac-field"><label for="f-years">Years of experience</label><input type="number" id="f-years" min="0" max="60" inputmode="numeric"></div>
          <div class="fac-field full"><label for="f-bio">Short professional bio *</label><textarea id="f-bio" maxlength="4000" required placeholder="Your musical training, teaching experience, notable performances or productions… (at least a few sentences)"></textarea><div class="fac-help">Minimum 40 characters — write it like the bio we'd publish on your faculty profile.</div></div>
          <div class="fac-field full"><label for="f-links">Links (portfolio, socials, streaming)</label><input type="text" id="f-links" maxlength="1000" placeholder="One or more links, separated by spaces"></div>
          <div class="fac-field full"><label for="f-cv">CV / résumé link</label><input type="url" id="f-cv" maxlength="500" placeholder="https://… (Google Drive, Dropbox, personal site)"><div class="fac-help">Host it anywhere link-shareable; https links only.</div></div>
          <div class="fac-field full"><label>Professional headshot</label><div id="facHeadshot"></div><input type="url" id="f-shot" maxlength="500" placeholder="…or paste an https image link" style="margin-top:10px"><div class="fac-help">A clear, front-facing photo — it becomes your faculty profile photo if you join.</div></div>
          <!-- Honeypot: humans never see this -->
          <div style="position:absolute;left:-9999px" aria-hidden="true"><label>Website<input type="text" id="f-web" tabindex="-1" autocomplete="off"></label></div>
          <div class="fac-field full" style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
            <button type="submit" class="btn btn-primary" id="facSubmit"><i class="fa-solid fa-paper-plane"></i> Submit application</button>
            <span style="font-size:0.8rem;color:var(--text-muted)">We reply within a few days.</span>
          </div>
        </div>
      </form>

      <div class="fac-success" id="facDone" hidden>
        <div class="ic"><i class="fa-solid fa-check"></i></div>
        <h2>Application received</h2>
        <p style="color:var(--text-soft);line-height:1.65">Thank you — the faculty team will review your application and reach out about next steps. A confirmation email is on its way to you.</p>
        <div style="margin-top:18px"><a class="btn btn-secondary" href="/"><i class="fa-solid fa-house"></i> Back to home</a></div>
      </div>
    </div>
  </div>
</main>

<?php include __DIR__ . '/partials/footer.html'; ?>
<script src="assets/js/config.js?v=20260616h"></script>
<script src="assets/js/headshot.js?v=20260615e" defer></script>
<script src="assets/js/main.js?v=20260706a"></script>
<script>
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var token = new URLSearchParams(location.search).get('token') || '';
  var gate = $('facGate'), form = $('facForm'), err = $('facErr');
  var headshotUrl = '';

  function gateFail(msg) {
    gate.innerHTML = '<i class="fa-solid fa-envelope-circle-check"></i>' + msg
      + '<div style="margin-top:16px"><a class="btn btn-secondary" href="/contact?topic=Faculty"><i class="fa-solid fa-paper-plane"></i> Request an invite</a></div>';
  }

  if (!token) { gateFail('This form opens from the personal link in your invitation email. Interested in teaching at MAM? Ask us for an invite — it takes a minute.'); return; }

  window.MAM_API.request('faculty-apply?token=' + encodeURIComponent(token), { method: 'GET' })
    .then(function (r) {
      gate.hidden = true; gate.style.display = 'none';
      form.hidden = false;
      $('f-email').value = (r && r.email) || '';
      // Headshot dropzone (validation + upload when the CDN is configured).
      if (window.MAM_HEADSHOT && $('facHeadshot')) {
        window.MAM_HEADSHOT.mount($('facHeadshot'), {
          onChange: function (s) { headshotUrl = (s.uploaded && s.uploaded.url) || ''; if (headshotUrl) $('f-shot').value = headshotUrl; }
        });
      }
    })
    .catch(function () { gateFail('This invitation link is invalid or has expired (links are valid for 14 days). Ask us for a fresh one — it takes a minute.'); });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    err.classList.remove('show');
    var bio = $('f-bio').value.trim();
    if ($('f-name').value.trim() === '' || $('f-disc').value.trim() === '' || bio.length < 40) {
      err.textContent = bio.length < 40 ? 'Please write a slightly fuller bio (at least 40 characters).' : 'Please fill your name, disciplines and bio.';
      err.classList.add('show');
      return;
    }
    var btn = $('facSubmit'), orig = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Submitting…';
    window.MAM_API.request('faculty-apply', { method: 'POST', body: {
      token: token,
      full_name: $('f-name').value.trim(),
      phone: $('f-phone').value.trim(),
      disciplines: $('f-disc').value.trim(),
      experience_years: $('f-years').value ? parseInt($('f-years').value, 10) : undefined,
      bio: bio,
      links: $('f-links').value.trim(),
      cv_url: $('f-cv').value.trim(),
      headshot_url: ($('f-shot').value.trim() || headshotUrl),
      website: $('f-web').value
    } }).then(function () {
      form.hidden = true;
      $('facDone').hidden = false;
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }).catch(function (e2) {
      btn.disabled = false; btn.innerHTML = orig;
      var code = (e2 && e2.body && e2.body.error) || '';
      err.textContent = code === 'invite_invalid' ? 'Your invitation link expired mid-session — request a fresh invite via the contact page.'
        : code === 'validation' ? 'Some fields look off — check the highlighted requirements (bio length, https links) and try again.'
        : 'Could not submit right now. Please try again in a moment.';
      err.classList.add('show');
    });
  });
})();
</script>
</body>
</html>
