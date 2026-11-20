<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark">
<title>Thank You — MAM Academy</title>
<meta name="robots" content="noindex, follow">
<link rel="icon" type="image/svg+xml" href="mam-academy-logo.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Cinzel:wght@600;700&family=Cormorant+Garamond:ital,wght@0,400;0,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" referrerpolicy="no-referrer">
<link rel="stylesheet" href="assets/css/main.css?v=20260920">
<link rel="stylesheet" href="assets/css/apply.css?v=20260617b">
</head>
<body>
<a href="#main" class="skip-link">Skip to main content</a>
<?php include __DIR__ . '/partials/header.html'; ?>
<main id="main" style="min-height:calc(100vh - var(--nav-h));display:flex;align-items:center;justify-content:center;padding:60px 24px">
  <div class="apply-main" style="max-width:680px;margin:0">
    <div class="apply-success">
      <div class="success-emoji" aria-hidden="true" style="background:transparent;border:none"><dotlottie-wc src="https://fonts.gstatic.com/s/e/notoemoji/latest/1f389/lottie.json" speed="1" autoplay loop class="fx-lottie fx-xl" aria-hidden="true"></dotlottie-wc></div>
      <h1 class="success-h">Thank You!</h1>
      <p class="success-p">Your transaction has been received. Our team will contact you within <strong>24 hours</strong> to confirm your enrollment and schedule your MAM Auditer assessment.</p>
      <p class="success-p" style="font-size:0.9rem;margin-top:-6px">While you wait: <a href="/auditer#checklist" style="color:var(--gold-bright);font-weight:600">prepare with the Auditer Applicant Checklist →</a></p>
      <div class="success-ref" id="refDisplay">Reference: pending</div>
      <p class="success-p" style="font-size:0.88rem;color:var(--text-muted)">A copy of your application is also stored in your browser. We'll email a full confirmation to the address you provided.</p>
      <div style="margin:24px auto 28px;max-width:480px;padding:18px 22px;background:rgba(224,138,26,0.08);border:1px solid var(--border-gold);border-radius:12px;font-size:0.88rem;color:var(--text-soft);line-height:1.65;text-align:left">
        <strong style="display:block;color:var(--gold-bright);margin-bottom:6px"><i class="fa-solid fa-envelope-open-text"></i> Check your email</strong>
        Once your payment clears we'll email you student portal credentials — sign in to track your application, classes &amp; MAM Auditer report.
      </div>
      <div style="margin:0 auto 26px;max-width:480px;padding:18px 22px;background:linear-gradient(135deg,rgba(224,138,26,0.12),rgba(224,138,26,0.03));border:1px solid var(--border-gold);border-radius:12px;text-align:left">
        <strong style="display:flex;align-items:center;gap:8px;color:var(--gold-bright);margin-bottom:6px"><i class="fa-solid fa-hand-holding-heart"></i> MAM Scholars Initiative</strong>
        <p style="font-size:0.88rem;color:var(--text-soft);line-height:1.6;margin:0 0 12px">Would you like to be considered for a scholarship? It's a separate, holistic review — earned, values-driven, and community-minded.</p>
        <a href="scholarship" class="btn btn-secondary" style="width:100%;justify-content:center"><i class="fa-solid fa-arrow-right"></i> Consider me for a scholarship</a>
      </div>
      <div class="success-actions">
        <a href="portal/login" class="btn btn-primary"><i class="fa-solid fa-right-to-bracket"></i> Open Student Portal</a>
        <a href="/" class="btn btn-secondary"><i class="fa-solid fa-house"></i> Back to Home</a>
      </div>
    </div>
  </div>
</main>
<?php include __DIR__ . '/partials/footer.html'; ?>
<script src="assets/js/config.js?v=20261120a"></script>
<script src="assets/js/main.js?v=20260920"></script>
<script>
document.addEventListener('mam:ready', function () {
  try {
    var qs = new URLSearchParams(location.search);
    var ref = qs.get('ref') || qs.get('reference') || qs.get('tx_ref');
    if (!ref) {
      var last = JSON.parse(localStorage.getItem('mam.apply.lastSubmission') || 'null');
      if (last && last.reference) ref = last.reference;
    }
    if (ref) document.getElementById('refDisplay').textContent = 'Reference: ' + ref;
  } catch (e) {}
});
</script>
</body>
</html>
