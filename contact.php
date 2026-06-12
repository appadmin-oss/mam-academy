<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark">
<title>Contact MAM Academy</title>
<meta name="description" content="Get in touch with MAM Academy. Email, phone and contact form.">
<link rel="canonical" href="https://academy.mamcentre.org/contact">
<link rel="icon" type="image/svg+xml" href="mam-academy-logo.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Cinzel:wght@600;700&family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" referrerpolicy="no-referrer">
<link rel="stylesheet" href="assets/css/main.css?v=20260612b">
<link rel="stylesheet" href="assets/css/apply.css?v=20260530a">
</head>
<body>
<a href="#main" class="skip-link">Skip to main content</a>
<div id="preloader"><div class="preloader-logo"><img src="mam-academy-logo.svg" alt="" onerror="this.style.display='none'"><div class="preloader-name">MAM ACADEMY</div><div class="preloader-tag">Contact</div></div><div class="waves"><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div></div>

<?php include __DIR__ . '/partials/header.html'; ?>

<main id="main">
  <header class="page-hero" style="--page-hero-img:url('https://images.unsplash.com/photo-1516979187457-637abb4f9353?w=1920&q=80')">
    <div class="page-hero-inner">
      <nav class="breadcrumb"><a href="/">Home</a><i class="fa-solid fa-chevron-right"></i><span>Contact</span></nav>
      <div class="page-hero-eyebrow"><i class="fa-solid fa-envelope"></i>Get in Touch</div>
      <h1 class="page-hero-title">Talk to <span class="highlight-gold">MAM Academy</span></h1>
      <p class="page-hero-lede">Questions about pathways, the MetaMusic Order or your child's musical journey? We reply within 24 hours.</p>
    </div>
  </header>

  <section>
    <div class="container about-grid" style="display:grid;grid-template-columns:1fr 1.5fr;gap:48px;align-items:start">
      <aside style="display:flex;flex-direction:column;gap:18px">
        <div class="apply-side-card">
          <h4>Reach Us Directly</h4>
          <div class="help-list">
            <div class="help-item"><i class="fa-solid fa-envelope"></i><span><strong>Email</strong><br><a href="mailto:academy@mamcentre.org" style="color:var(--gold-bright)">academy@mamcentre.org</a></span></div>
            <div class="help-item"><i class="fa-solid fa-phone"></i><span><strong>Phone</strong><br>+234 800 000 0000</span></div>
            <div class="help-item"><i class="fa-solid fa-location-dot"></i><span><strong>Office</strong><br>MAM Centre, Lagos, Nigeria</span></div>
            <div class="help-item"><i class="fa-solid fa-clock"></i><span><strong>Hours</strong><br>Mon–Sat · 9am–6pm WAT</span></div>
          </div>
        </div>
        <div class="apply-side-card">
          <h4>Follow MAM</h4>
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <a href="https://instagram.com/mamcentre" target="_blank" rel="noopener" class="footer-social"><i class="fa-brands fa-instagram"></i></a>
            <a href="https://twitter.com/mamcentre" target="_blank" rel="noopener" class="footer-social"><i class="fa-brands fa-x-twitter"></i></a>
            <a href="https://facebook.com/mamcentre" target="_blank" rel="noopener" class="footer-social"><i class="fa-brands fa-facebook-f"></i></a>
            <a href="https://youtube.com/mamcentre" target="_blank" rel="noopener" class="footer-social"><i class="fa-brands fa-youtube"></i></a>
          </div>
        </div>
      </aside>

      <div class="apply-main" style="margin:0">
        <h2 class="step-title">Send Us a Message</h2>
        <p class="step-desc">All messages go to <strong>academy@mamcentre.org</strong>. We reply within 24 hours.</p>

        <form id="contactForm" novalidate style="margin-top:24px">
          <input type="text" name="_honeypot" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;opacity:0" aria-hidden="true">

          <div class="field-row">
            <div class="field"><label class="field-label" for="cf-name">Your Name <span class="req">*</span></label><input type="text" id="cf-name" class="field-input" placeholder="Full name" required></div>
            <div class="field"><label class="field-label" for="cf-email">Email <span class="req">*</span></label><input type="email" id="cf-email" class="field-input" placeholder="you@example.com" required></div>
          </div>
          <div class="field-row">
            <div class="field"><label class="field-label" for="cf-phone">Phone</label><input type="tel" id="cf-phone" class="field-input" placeholder="+234…"></div>
            <div class="field"><label class="field-label" for="cf-topic">Topic <span class="req">*</span></label><div class="field-select-wrap"><select id="cf-topic" class="field-select" required><option value="">Select topic</option><option>Enrollment &amp; Pathways</option><option>MAM Auditer Assessment</option><option>MetaMusic Order</option><option>Power Play series</option><option>Crash Programmes</option><option>Partnerships &amp; Speaking</option><option>Press &amp; Media</option><option>Other</option></select><i class="fa-solid fa-chevron-down field-select-arrow"></i></div></div>
          </div>
          <div class="field"><label class="field-label" for="cf-message">Message <span class="req">*</span></label><textarea id="cf-message" class="field-textarea" placeholder="Tell us how we can help…" required maxlength="2000"></textarea></div>
          <button type="submit" class="btn-step btn-step-next" id="cf-submit"><i class="fa-solid fa-paper-plane"></i>Send Message</button>
          <p id="cf-msg" style="margin-top:14px;font-size:0.88rem;color:var(--emerald);display:none">Thanks — we'll be in touch shortly.</p>
        </form>
      </div>
    </div>
  </section>
</main>

<?php include __DIR__ . '/partials/footer.html'; ?>
<script src="assets/js/config.js?v=20260602d"></script>
<script src="assets/js/main.js?v=20260612b"></script>
<script>
document.addEventListener('mam:ready', function () {
  var form = document.getElementById('contactForm'); if (!form) return;
  var submit = document.getElementById('cf-submit');

  // Topic-aware tip: most contact-form senders are actually looking for an
  // existing flow (apply, auditer, partnerships). Surface the shortcut so
  // they don't wait 24h for an answer we can give in one link.
  var TOPIC_TIPS = {
    'Enrollment & Pathways':    { icon: 'graduation-cap', html: "Tip: you can start your enrollment directly at <a href='apply'>start your application</a> — 5 quick steps with auto-save." },
    'MAM Auditer Assessment':   { icon: 'gauge-high',     html: "Tip: the MAM Auditer is automatically included with your application. <a href='auditer'>Read about it</a>." },
    'MetaMusic Order':          { icon: 'fire',           html: "The MetaMusic Order is a 12-week intensive. <a href='apply?pathway=meta-order'>Apply</a> or <a href='metamusic-order'>learn more</a>." },
    'Power Play series':        { icon: 'bolt',           html: "Catch the series at <a href='power-play'>the Power Play page</a>." },
    'Crash Programmes':         { icon: 'forward',        html: "6-week fast-track. <a href='crash'>See details</a> or <a href='apply?pathway=crash'>start an application</a>." },
    'Partnerships & Speaking':  { icon: 'handshake',      html: "Briefly include the organisation, timeline and audience size so we can route this to the right team." },
    'Press & Media':            { icon: 'newspaper',      html: 'For press kits and interview requests, please include your outlet and deadline.' }
  };
  var topicEl = document.getElementById('cf-topic');
  if (topicEl) {
    topicEl.addEventListener('change', function () {
      var existing = document.getElementById('cf-topic-tip');
      var spec = TOPIC_TIPS[topicEl.value];
      if (!spec) { if (existing) existing.remove(); return; }
      if (!existing) {
        existing = document.createElement('div');
        existing.id = 'cf-topic-tip';
        existing.className = 'context-tip';
        topicEl.closest('.field').appendChild(existing);
      }
      existing.innerHTML = '<i class="fa-solid fa-' + spec.icon + '"></i><span>' + spec.html + '</span>';
    });
  }
  var doSubmit = function () {
    var name = document.getElementById('cf-name').value.trim();
    var email = document.getElementById('cf-email').value.trim();
    var phone = document.getElementById('cf-phone').value.trim();
    var topic = document.getElementById('cf-topic').value;
    var message = document.getElementById('cf-message').value.trim();
    var msgEl = document.getElementById('cf-msg');
    if (form.querySelector('[name=_honeypot]').value) { msgEl.style.display='block'; return; }
    submit.disabled = true; var origLabel = submit.innerHTML;
    submit.innerHTML = '<span class="spinner"></span><span>Sending…</span>';
    var payload = { name: name, email: email, phone: phone, topic: topic, message: message };
    var done = function () { submit.disabled = false; submit.innerHTML = origLabel; };
    if (window.MAM_API) {
      window.MAM_API.sendContact(payload)
        .then(function () { msgEl.textContent = "Thanks — we'll be in touch shortly."; msgEl.style.display='block'; form.reset(); done(); })
        .catch(function (err) {
          if (err && err.status === 429) { msgEl.textContent = 'Too many submissions — please try later.'; msgEl.style.color = 'var(--red-hot)'; msgEl.style.display='block'; done(); return; }
          mailtoFallback();
        });
    } else { mailtoFallback(); }
    function mailtoFallback() {
      done();
      var fallback = window.MAM_CONFIG.forms.fallbackEmail;
      var subject = 'MAM Contact — ' + topic;
      var body = 'From: ' + name + ' <' + email + '>\nPhone: ' + (phone||'—') + '\nTopic: ' + topic + '\n\n' + message;
      window.location.href = 'mailto:' + fallback + '?subject=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(body);
      msgEl.textContent = 'Opening your email…'; msgEl.style.display='block';
    }
  };

  // Hand validation to JustValidate when reachable. The library handles
  // aria-live errors, focus management and inline error rendering — the
  // raw submit handler is the fallback when the CDN is blocked.
  if (typeof window.MAM_LOAD_JUSTVALIDATE === 'function') {
    window.MAM_LOAD_JUSTVALIDATE().then(function (JV) {
      if (!JV) {
        form.addEventListener('submit', function (e) {
          e.preventDefault();
          var em = document.getElementById('cf-email').value.trim();
          if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) { alert('Please enter a valid email.'); return; }
          doSubmit();
        });
        return;
      }
      var v = new JV(form, { errorFieldCssClass: 'jv-error', errorLabelCssClass: 'jv-error-label', focusInvalidField: true });
      v.addField('#cf-name',    [{ rule: 'required', errorMessage: 'Enter your name' }, { rule: 'minLength', value: 2 }])
       .addField('#cf-email',   [{ rule: 'required', errorMessage: 'Email is required' }, { rule: 'email' }])
       .addField('#cf-topic',   [{ rule: 'required', errorMessage: 'Pick a topic' }])
       .addField('#cf-message', [{ rule: 'required', errorMessage: 'Tell us how we can help' }, { rule: 'minLength', value: 10, errorMessage: 'A little more detail would help' }])
       .onSuccess(function (e) { e.preventDefault(); doSubmit(); });
    });
  } else {
    form.addEventListener('submit', function (e) { e.preventDefault(); doSubmit(); });
  }
});
</script>
</body>
</html>
