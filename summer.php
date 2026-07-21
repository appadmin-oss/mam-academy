<?php
/**
 * /summer — MAM Summer Programme landing page + application form.
 * All content (dates, fee, age limits, description) is pulled from admin settings.
 * Shareable clean URL via .htaccess (summer.php → /summer).
 */
declare(strict_types=1);
require_once __DIR__ . '/api/lib/bootstrap.php';
header_remove('Content-Type');
header('Content-Type: text/html; charset=utf-8');
if (!defined('MAM_RESPONSE_SENT')) define('MAM_RESPONSE_SENT', true);

$progName    = (string)mam_setting('summer_prog_name',       'MAM Summer Music Camp');
$tagline     = (string)mam_setting('summer_tagline',         '');
$description = (string)mam_setting('summer_description',     '');
$highlights  = array_filter(array_map('trim', explode("\n", (string)mam_setting('summer_highlights', ''))));
$disciplines = array_filter(array_map('trim', explode("\n", (string)mam_setting('summer_disciplines', ''))));
$sessionStart= (string)mam_setting('summer_session_start',   '');
$sessionEnd  = (string)mam_setting('summer_session_end',     '');
$appOpen     = (string)mam_setting('summer_app_open',        '');
$appClose    = (string)mam_setting('summer_app_close',       '');
$minAge      = (int)mam_setting_num('summer_min_age',        8);
$maxAge      = (int)mam_setting_num('summer_max_age',        18);
$fee         = (float)mam_setting_num('summer_fee',          0);
$capacity    = (int)mam_setting_num('summer_capacity',       0);
$location    = (string)mam_setting('summer_location',        '');
$contactEmail= (string)mam_setting('summer_contact_email',   '');

$today       = date('Y-m-d');
$isOpen      = $appOpen && $appClose && $today >= $appOpen && $today <= $appClose;
$notYet      = $appOpen && $today < $appOpen;
$closed      = $appClose && $today > $appClose;

// Format session dates for display
function formatDate(string $d, string $fmt = 'F j, Y'): string {
    if (!$d) return '';
    try { return (new DateTimeImmutable($d))->format($fmt); } catch (Throwable $e) { return $d; }
}
$dateRangeDisplay = '';
if ($sessionStart && $sessionEnd) {
    $s = new DateTimeImmutable($sessionStart); $e = new DateTimeImmutable($sessionEnd);
    $dateRangeDisplay = $s->format('F j') . ' – ' . $e->format('F j, Y');
} elseif ($sessionStart) {
    $dateRangeDisplay = formatDate($sessionStart);
}

$ageRange = $minAge . ($maxAge > 0 ? '–' . $maxAge : '+') . ' years';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="color-scheme" content="dark">
<title><?= htmlspecialchars($progName) ?> — MAM Academy</title>
<meta name="description" content="<?= htmlspecialchars($tagline ?: "An immersive summer music programme for young musicians aged $ageRange.") ?>">
<link rel="canonical" href="https://academy.mamcentre.org/summer">
<meta property="og:type" content="website">
<meta property="og:site_name" content="MAM Academy">
<meta property="og:title" content="<?= htmlspecialchars($progName) ?> — MAM Academy">
<meta property="og:description" content="<?= htmlspecialchars($tagline ?: "An immersive summer music experience for young musicians.") ?>">
<meta property="og:url" content="https://academy.mamcentre.org/summer">
<meta property="og:image" content="https://academy.mamcentre.org/api/og?t=<?= rawurlencode($progName) ?>&amp;s=<?= rawurlencode($tagline ?: 'An immersive summer music experience') ?>&amp;e=<?= rawurlencode($dateRangeDisplay ?: 'Summer') ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:type" content="image/png">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" type="image/svg+xml" href="mam-academy-logo.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Cinzel:wght@600;700&family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" referrerpolicy="no-referrer">
<link rel="stylesheet" href="assets/css/main.css?v=20260706d">
<style>
/* ── Summer page tokens ──────────────────────────────── */
:root {
  --su-amber:#F59E0B; --su-amber-dk:#B45309; --su-glow:rgba(245,158,11,.18);
  --su-gold:#E8961F;  --su-gold-lt:rgba(232,150,31,.12);
  --su-bg:#0E0A06;    --su-card:#1A120A;     --su-border:rgba(245,158,11,.22);
  --su-ink:#F5EDE0;   --su-muted:#B09070;    --su-radius:16px;
}
/* ── Hero — uses site page-hero pattern with summer overrides ── */
.su-page-hero.page-hero::before { opacity:.32 } /* more image visible than the default 0.18 */
.su-page-hero.page-hero::after {
  background:
    radial-gradient(ellipse 80% 65% at 20% 55%,rgba(245,158,11,.14) 0%,transparent 58%),
    linear-gradient(105deg,rgba(14,10,4,.82) 0%,rgba(14,10,4,.55) 45%,rgba(14,10,4,.2) 100%);
}
.su-page-hero .page-hero-inner {
  text-align:left; align-items:flex-start; max-width:960px;
}
.su-page-hero .page-hero-title {
  font-family:'Bebas Neue',sans-serif;
  font-size:clamp(3.2rem,9vw,6.4rem);
  letter-spacing:.01em; line-height:.94;
  text-align:left;
}
.su-page-hero .page-hero-lede { text-align:left; margin-left:0; }
.su-hero-chips { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:28px; }
.su-hero-chip {
  display:inline-flex; align-items:center; gap:6px;
  background:rgba(0,0,0,.38); border:1px solid rgba(245,158,11,.3);
  color:#E5C88A; font-size:.78rem; font-weight:600;
  padding:5px 13px; border-radius:999px; backdrop-filter:blur(6px);
}
.su-hero-chip i { color:var(--su-amber); font-size:.7rem; }
.su-hero-ctas{display:flex;gap:12px;flex-wrap:wrap}
.su-btn-primary{
  display:inline-flex;align-items:center;gap:8px;
  background:linear-gradient(135deg,var(--su-amber),#FBBF24);
  color:#0E0A06;font-weight:800;font-size:1rem;padding:14px 28px;
  border:none;border-radius:10px;cursor:pointer;text-decoration:none;
  transition:transform .15s,box-shadow .15s;letter-spacing:.02em;
}
.su-btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(245,158,11,.35)}
.su-btn-ghost{
  display:inline-flex;align-items:center;gap:8px;
  background:rgba(0,0,0,.3);color:#C4A873;
  font-weight:600;font-size:.9rem;padding:14px 20px;
  border:1px solid rgba(245,158,11,.25);border-radius:10px;cursor:pointer;
  text-decoration:none;transition:border-color .15s,color .15s;backdrop-filter:blur(4px);
}
.su-btn-ghost:hover{border-color:var(--su-amber);color:var(--su-amber)}
/* ── Info cards grid ─────────────────────────────────── */
.su-info{background:var(--su-card);padding:clamp(48px,7vw,80px) 24px;border-top:1px solid var(--su-border);border-bottom:1px solid var(--su-border)}
.su-info-grid{
  display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
  gap:20px;max-width:960px;margin:0 auto;
}
.su-info-card{
  background:#120B04;border:1px solid var(--su-border);border-radius:14px;
  padding:22px 20px;text-align:center;
}
.su-info-card-icon{font-size:1.6rem;margin-bottom:10px}
.su-info-card-label{font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--su-muted);margin-bottom:6px}
.su-info-card-value{font-size:1.05rem;font-weight:700;color:#fff;line-height:1.3}
.su-info-card-sub{font-size:.78rem;color:var(--su-muted);margin-top:4px}
/* ── About / description section ───────────────────── */
.su-about{padding:clamp(52px,8vw,90px) 24px;max-width:780px;margin:0 auto}
.su-section-eyebrow{font-size:.7rem;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:var(--su-amber);margin-bottom:10px}
.su-section-title{font-family:'Cinzel',serif;font-size:clamp(1.3rem,3vw,1.9rem);color:#fff;margin:0 0 20px;line-height:1.25}
.su-description{font-size:1.02rem;color:var(--su-muted);line-height:1.75;margin:0 0 32px}
.su-highlights{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:12px}
.su-highlights li{
  display:flex;align-items:flex-start;gap:12px;
  background:rgba(245,158,11,.05);border:1px solid rgba(245,158,11,.15);
  border-radius:10px;padding:14px 16px;color:var(--su-ink);font-size:.95rem;line-height:1.5;
}
.su-highlights li::before{content:'✦';color:var(--su-amber);font-size:.7rem;margin-top:3px;flex-shrink:0}
/* ── Disciplines ───────────────────────────────────── */
.su-disciplines{
  padding:clamp(40px,6vw,70px) 24px;
  background:linear-gradient(180deg,transparent,rgba(245,158,11,.04),transparent);
  border-top:1px solid var(--su-border);border-bottom:1px solid var(--su-border);
}
.su-disciplines-inner{max-width:960px;margin:0 auto;text-align:center}
.su-discs{display:flex;flex-wrap:wrap;justify-content:center;gap:10px;margin-top:22px}
.su-disc{
  background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);
  color:var(--su-amber);font-size:.85rem;font-weight:600;
  padding:8px 18px;border-radius:999px;letter-spacing:.04em;
}
/* ── Application form section ───────────────────────── */
.su-apply{padding:clamp(52px,8vw,90px) 24px}
.su-apply-inner{max-width:680px;margin:0 auto}
.su-form-card{
  background:var(--su-card);border:1px solid var(--su-border);
  border-radius:var(--su-radius);padding:clamp(24px,5vw,44px);
  box-shadow:0 24px 64px rgba(0,0,0,.4);
}
.su-closed-notice{
  text-align:center;padding:40px 24px;
}
.su-closed-icon{font-size:2.5rem;margin-bottom:12px}
.su-closed-title{font-family:'Cinzel',serif;font-size:1.3rem;color:#fff;margin:0 0 10px}
.su-closed-msg{color:var(--su-muted);font-size:.95rem;line-height:1.6;max-width:400px;margin:0 auto}
/* form sections */
.su-form-group{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:520px){.su-form-group{grid-template-columns:1fr}}
.su-form-section{margin-bottom:28px}
.su-form-section-title{
  font-size:.7rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase;
  color:var(--su-amber);margin:0 0 14px;padding-bottom:8px;
  border-bottom:1px solid rgba(245,158,11,.2);
}
.su-field{margin-bottom:16px}
.su-field label{display:block;font-size:.8rem;font-weight:600;color:var(--su-muted);margin-bottom:6px}
.su-field label .req{color:var(--su-amber)}
.su-field input,.su-field select,.su-field textarea{
  width:100%;padding:12px 14px;
  background:#0A0703;border:1px solid rgba(245,158,11,.25);
  border-radius:10px;color:#fff;font-size:.95rem;font-family:inherit;
  box-sizing:border-box;transition:border-color .2s;
}
.su-field input:focus,.su-field select:focus,.su-field textarea:focus{
  outline:none;border-color:var(--su-amber);box-shadow:0 0 0 3px rgba(245,158,11,.1);
}
.su-field select option{background:#1A120A;color:#fff}
.su-field textarea{resize:vertical;min-height:90px}
.su-field-hint{font-size:.75rem;color:var(--su-muted);margin-top:5px;line-height:1.4}
.su-field-error{display:none;color:#FCA5A5;font-size:.76rem;margin-top:5px;line-height:1.4}
.su-field-error.show{display:block}
.su-field.has-error input,.su-field.has-error select,.su-field.has-error textarea{border-color:rgba(239,68,68,.5)!important}
.su-age-notice{
  display:flex;align-items:flex-start;gap:10px;
  background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.2);
  border-radius:10px;padding:12px 14px;margin-bottom:20px;
  font-size:.85rem;color:var(--su-muted);line-height:1.5;
}
.su-age-notice i{color:var(--su-amber);flex-shrink:0;margin-top:2px}
.su-policy-box{
  background:#0A0703;border:1px solid rgba(245,158,11,.18);
  border-radius:10px;padding:16px;margin-bottom:18px;
}
.su-policy-check{display:flex;align-items:flex-start;gap:12px;cursor:pointer;color:var(--su-muted);font-size:.88rem;line-height:1.55}
.su-policy-check input[type=checkbox]{accent-color:var(--su-amber);width:16px;height:16px;flex-shrink:0;margin-top:2px}
.su-submit-btn{
  width:100%;padding:16px;background:linear-gradient(135deg,var(--su-amber),#FBBF24);
  color:#0E0A06;font-weight:800;font-size:1.05rem;letter-spacing:.04em;
  border:none;border-radius:11px;cursor:pointer;transition:transform .15s,box-shadow .15s;
  margin-top:4px;
}
.su-submit-btn:hover:not([disabled]){transform:translateY(-2px);box-shadow:0 8px 24px rgba(245,158,11,.3)}
.su-submit-btn[disabled]{opacity:.6;cursor:default}
.su-form-error{
  display:none;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);
  border-radius:10px;padding:12px 14px;color:#FCA5A5;font-size:.88rem;
  margin-bottom:16px;line-height:1.5;
}
.su-form-error.show{display:block}
/* success screen */
.su-success{display:none;text-align:center;padding:32px 16px}
.su-success-icon{
  width:70px;height:70px;background:linear-gradient(135deg,var(--su-amber),#FBBF24);
  border-radius:50%;display:flex;align-items:center;justify-content:center;
  margin:0 auto 20px;font-size:1.8rem;
}
.su-success-title{font-family:'Cinzel',serif;font-size:1.5rem;color:#fff;margin:0 0 8px}
.su-success-ref{
  display:inline-block;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.25);
  color:var(--su-amber);font-family:monospace;font-size:1rem;font-weight:700;
  padding:8px 18px;border-radius:8px;margin:12px 0 20px;letter-spacing:.06em;
}
.su-success-msg{color:var(--su-muted);font-size:.95rem;line-height:1.65;max-width:440px;margin:0 auto 24px}
/* contact CTA */
.su-contact{
  padding:clamp(36px,5vw,60px) 24px;text-align:center;
  border-top:1px solid var(--su-border);
}
.su-contact p{color:var(--su-muted);margin:0 0 10px;font-size:.95rem}
.su-contact a{color:var(--su-amber);text-decoration:none}
.su-contact a:hover{text-decoration:underline}
</style>
</head>
<body>
<a href="#main" class="skip-link">Skip to main content</a>
<div id="preloader" role="status" aria-label="Loading">
  <div class="preloader-logo">
    <img src="mam-academy-logo.svg" alt="" onerror="this.style.display='none'">
    <div class="preloader-name">MAM ACADEMY</div>
    <div class="preloader-tag"><?= htmlspecialchars($progName) ?></div>
  </div>
  <div class="waves" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div>
</div>

<?php include __DIR__ . '/partials/header.html'; ?>

<main id="main">

<!-- ── HERO ─────────────────────────────────────────────── -->
<header class="page-hero su-page-hero" style="--page-hero-img:url('https://images.unsplash.com/photo-1516450360452-9312f5e86fc7?w=1920&q=80');padding:clamp(88px,13vw,148px) 24px clamp(64px,9vw,104px)">
  <div class="page-hero-inner">
    <nav class="breadcrumb" style="margin-bottom:18px;justify-content:flex-start" aria-label="Breadcrumb">
      <a href="/">Home</a><i class="fa-solid fa-chevron-right"></i><span>Summer</span>
    </nav>
    <div class="page-hero-eyebrow"><i class="fa-solid fa-sun"></i>Summer Programme</div>
    <h1 class="page-hero-title"><?= htmlspecialchars($progName) ?></h1>
    <p class="page-hero-lede">
      <?= htmlspecialchars($tagline ?: "An immersive music experience for young musicians aged $ageRange. Intensive tuition, performance, and community — all summer long.") ?>
    </p>
    <div class="su-hero-chips">
      <?php if ($dateRangeDisplay): ?>
        <span class="su-hero-chip"><i class="fa-regular fa-calendar"></i><?= htmlspecialchars($dateRangeDisplay) ?></span>
      <?php endif; ?>
      <span class="su-hero-chip"><i class="fa-solid fa-users"></i>Ages <?= htmlspecialchars($ageRange) ?></span>
      <?php if ($fee > 0): ?>
        <span class="su-hero-chip"><i class="fa-solid fa-tag"></i><span class="su-fee-local" data-usd="<?= htmlspecialchars((string)$fee) ?>">USD $<?= number_format($fee, 0) ?></span></span>
      <?php endif; ?>
      <?php if ($location): ?>
        <span class="su-hero-chip"><i class="fa-solid fa-location-dot"></i><?= htmlspecialchars($location) ?></span>
      <?php endif; ?>
    </div>
    <div class="su-hero-ctas">
      <?php if ($isOpen): ?>
        <a href="#apply" class="su-btn-primary"><i class="fa-solid fa-pen-to-square"></i>Apply Now</a>
      <?php elseif ($notYet): ?>
        <span class="su-btn-primary" style="opacity:.6;cursor:default"><i class="fa-solid fa-clock"></i>Opens <?= htmlspecialchars(formatDate($appOpen, 'F j')) ?></span>
      <?php else: ?>
        <span class="su-btn-primary" style="opacity:.6;cursor:default"><i class="fa-solid fa-lock"></i>Applications Closed</span>
      <?php endif; ?>
      <a href="/status" class="su-btn-ghost"><i class="fa-solid fa-magnifying-glass"></i>Check my status</a>
    </div>
  </div>
</header>

<!-- ── INFO CARDS GRID ──────────────────────────────────── -->
<section class="su-info" aria-label="Programme details">
  <div class="su-info-grid">
    <?php if ($dateRangeDisplay): ?>
    <div class="su-info-card">
      <div class="su-info-card-icon">📅</div>
      <div class="su-info-card-label">Session dates</div>
      <div class="su-info-card-value"><?= htmlspecialchars($dateRangeDisplay) ?></div>
    </div>
    <?php endif; ?>
    <div class="su-info-card">
      <div class="su-info-card-icon">🎂</div>
      <div class="su-info-card-label">Age range</div>
      <div class="su-info-card-value"><?= htmlspecialchars($ageRange) ?></div>
      <div class="su-info-card-sub">Admin-verified at submission</div>
    </div>
    <?php if ($fee > 0): ?>
    <div class="su-info-card">
      <div class="su-info-card-icon">💳</div>
      <div class="su-info-card-label">Programme fee</div>
      <div class="su-info-card-value su-fee-local" data-usd="<?= htmlspecialchars((string)$fee) ?>">USD $<?= number_format($fee, 0) ?></div>
      <div class="su-info-card-sub">Details via confirmation email</div>
    </div>
    <?php endif; ?>
    <?php if ($location): ?>
    <div class="su-info-card">
      <div class="su-info-card-icon">📍</div>
      <div class="su-info-card-label">Location</div>
      <div class="su-info-card-value"><?= htmlspecialchars($location) ?></div>
    </div>
    <?php endif; ?>
    <?php if ($capacity > 0): ?>
    <div class="su-info-card">
      <div class="su-info-card-icon">👥</div>
      <div class="su-info-card-label">Capacity</div>
      <div class="su-info-card-value"><?= (int)$capacity ?> participants</div>
      <div class="su-info-card-sub">Apply early — spots are limited</div>
    </div>
    <?php endif; ?>
    <?php if ($isOpen && $appClose): ?>
    <div class="su-info-card">
      <div class="su-info-card-icon">⏳</div>
      <div class="su-info-card-label">Apply by</div>
      <div class="su-info-card-value"><?= htmlspecialchars(formatDate($appClose, 'F j, Y')) ?></div>
      <div class="su-info-card-sub">Applications close at end of day</div>
    </div>
    <?php elseif ($notYet && $appOpen): ?>
    <div class="su-info-card">
      <div class="su-info-card-icon">🚀</div>
      <div class="su-info-card-label">Applications open</div>
      <div class="su-info-card-value"><?= htmlspecialchars(formatDate($appOpen, 'F j, Y')) ?></div>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- ── ABOUT / DESCRIPTION ──────────────────────────────── -->
<?php if ($description || $highlights): ?>
<section class="su-about" aria-label="About the programme">
  <?php if ($description): ?>
    <div class="su-section-eyebrow">About the programme</div>
    <h2 class="su-section-title">What is <?= htmlspecialchars($progName) ?>?</h2>
    <p class="su-description"><?= nl2br(htmlspecialchars($description)) ?></p>
  <?php endif; ?>
  <?php if ($highlights): ?>
    <div class="su-section-eyebrow" style="margin-top:<?= $description ? '0' : '0' ?>">What you'll experience</div>
    <ul class="su-highlights" aria-label="Programme highlights">
      <?php foreach ($highlights as $h): ?>
        <li><?= htmlspecialchars($h) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php endif; ?>

<!-- ── DISCIPLINES ───────────────────────────────────────── -->
<?php if ($disciplines): ?>
<section class="su-disciplines" aria-label="Disciplines offered">
  <div class="su-disciplines-inner">
    <div class="su-section-eyebrow">What's on offer</div>
    <h2 class="su-section-title">Disciplines &amp; Instruments</h2>
    <div class="su-discs" role="list">
      <?php foreach ($disciplines as $d): ?>
        <span class="su-disc" role="listitem"><?= htmlspecialchars($d) ?></span>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ── APPLICATION FORM ─────────────────────────────────── -->
<section class="su-apply" id="apply" aria-label="Apply for the summer programme">
  <div class="su-apply-inner">
    <div class="su-section-eyebrow" style="text-align:center">Apply now</div>
    <h2 class="su-section-title" style="text-align:center;margin-bottom:30px">
      Secure your spot in<br><?= htmlspecialchars($progName) ?>
    </h2>

    <div class="su-form-card">

      <?php if ($isOpen): ?>
      <!-- ── Open: show form ── -->
      <div class="su-age-notice" role="note">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        <span>This programme is open to participants aged <strong><?= htmlspecialchars($ageRange) ?></strong>. Age is verified from your date of birth at the time of submission.</span>
      </div>

      <div class="su-form-error" id="summerFormError" role="alert"></div>

      <form id="summerApplyForm" novalidate autocomplete="off" data-min-age="<?= $minAge ?>" data-max-age="<?= $maxAge > 0 ? $maxAge : '' ?>">
        <input type="hidden" name="_honeypot" value="">

        <!-- Headshot -->
        <div class="su-form-section">
          <div class="su-form-section-title">Your photo</div>
          <div id="suHeadshotMount"></div>
          <input type="hidden" id="suHeadshotUrl" name="headshotUrl" value="">
          <div class="su-field-hint" style="margin-top:8px">A recent, clear front-facing photo — used for your admission file.</div>
        </div>

        <!-- Personal details -->
        <div class="su-form-section">
          <div class="su-form-section-title">Personal details</div>
          <div class="su-form-group">
            <div class="su-field">
              <label for="suFirstName">First name <span class="req">*</span></label>
              <input type="text" id="suFirstName" name="firstName" autocomplete="given-name" required maxlength="80">
              <div class="su-field-error" id="su-err-firstName">First name is required.</div>
            </div>
            <div class="su-field">
              <label for="suLastName">Last name <span class="req">*</span></label>
              <input type="text" id="suLastName" name="lastName" autocomplete="family-name" required maxlength="80">
              <div class="su-field-error" id="su-err-lastName">Last name is required.</div>
            </div>
          </div>
          <div class="su-form-group">
            <div class="su-field">
              <label for="suDob">Date of birth <span class="req">*</span></label>
              <input type="date" id="suDob" name="dob" required
                max="<?= date('Y-m-d', strtotime("-{$minAge} years")) ?>"
                <?php if ($maxAge > 0): ?>min="<?= date('Y-m-d', strtotime("-{$maxAge} years")) ?>"<?php endif; ?>>
              <div class="su-field-error" id="su-err-dob">Date of birth is required.</div>
              <div class="su-field-hint">Age must be <?= htmlspecialchars($ageRange) ?></div>
            </div>
            <div class="su-field">
              <label for="suSex">Sex</label>
              <select id="suSex" name="sex">
                <option value="">— Select —</option>
                <option value="male">Male</option>
                <option value="female">Female</option>
              </select>
            </div>
          </div>
        </div>

        <!-- Contact -->
        <div class="su-form-section">
          <div class="su-form-section-title">Contact information</div>
          <div class="su-form-group">
            <div class="su-field">
              <label for="suEmail">Email address <span class="req">*</span></label>
              <input type="email" id="suEmail" name="email" autocomplete="email" required maxlength="200">
              <div class="su-field-error" id="su-err-email">A valid email address is required.</div>
            </div>
            <div class="su-field">
              <label for="suPhone">Phone number <span class="req">*</span></label>
              <input type="tel" id="suPhone" name="phone" autocomplete="tel" required maxlength="30">
              <div class="su-field-error" id="su-err-phone">Phone number is required.</div>
            </div>
          </div>
          <div class="su-form-group">
            <div class="su-field">
              <label for="suCountry">Country</label>
              <input type="text" id="suCountry" name="country" autocomplete="country-name" maxlength="60">
            </div>
            <div class="su-field">
              <label for="suCity">City</label>
              <input type="text" id="suCity" name="city" autocomplete="address-level2" maxlength="80">
            </div>
          </div>
        </div>

        <!-- Guardian (required for minors) -->
        <div class="su-form-section" id="suGuardianSection">
          <div class="su-form-section-title">
            Guardian information
            <span id="suGuardianReqNote" style="font-size:.65rem;letter-spacing:.04em;color:var(--su-muted);margin-left:8px;text-transform:none;font-weight:400">(required — applicant is under 18)</span>
            <span id="suGuardianOptNote" style="display:none;font-size:.65rem;letter-spacing:.04em;color:var(--su-muted);margin-left:8px;text-transform:none;font-weight:400">(optional — applicant is 18+)</span>
          </div>
          <div class="su-form-group">
            <div class="su-field">
              <label for="suGuardianName">Guardian full name <span class="req" id="suGuardianNameReq">*</span></label>
              <input type="text" id="suGuardianName" name="guardianName" autocomplete="off" maxlength="160">
              <div class="su-field-error" id="su-err-guardianName">Guardian name is required for applicants under 18.</div>
            </div>
            <div class="su-field">
              <label for="suGuardianPhone">Guardian phone <span class="req" id="suGuardianPhoneReq">*</span></label>
              <input type="tel" id="suGuardianPhone" name="guardianPhone" autocomplete="off" maxlength="30">
              <div class="su-field-error" id="su-err-guardianPhone">Guardian phone is required for applicants under 18.</div>
            </div>
          </div>
          <div class="su-field">
            <label for="suGuardianEmail">Guardian email</label>
            <input type="email" id="suGuardianEmail" name="guardianEmail" autocomplete="off" maxlength="200">
            <div class="su-field-hint">Optional — we may use this for important updates</div>
          </div>
        </div>

        <!-- Music background -->
        <div class="su-form-section">
          <div class="su-form-section-title">Musical background</div>
          <?php if ($disciplines): ?>
          <div class="su-field">
            <label for="suDiscipline">Instrument / area of interest</label>
            <select id="suDiscipline" name="discipline">
              <option value="">Select one (optional)</option>
              <?php foreach ($disciplines as $d): ?>
                <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
              <?php endforeach; ?>
              <option value="Other">Other</option>
            </select>
          </div>
          <?php else: ?>
          <div class="su-field">
            <label for="suDiscipline">Instrument / area of interest</label>
            <input type="text" id="suDiscipline" name="discipline" maxlength="100" placeholder="e.g. Piano, Drums, Voice, Theory…">
          </div>
          <?php endif; ?>
          <div class="su-field">
            <label for="suExperience">Music experience</label>
            <textarea id="suExperience" name="experience" maxlength="500" placeholder="How long have you been playing? Any lessons, performances, or styles you've explored?"></textarea>
          </div>
          <div class="su-field">
            <label for="suGoals">What do you hope to achieve?</label>
            <textarea id="suGoals" name="goals" maxlength="1000" placeholder="What excites you about this programme? What do you want to learn or improve?"></textarea>
          </div>
          <div class="su-field">
            <label for="suReferrer">How did you hear about us?</label>
            <select id="suReferrer" name="referrer">
              <option value="">Select one</option>
              <option value="social-media">Social media</option>
              <option value="friend-or-family">Friend or family</option>
              <option value="web-search">Web search</option>
              <option value="school">School / teacher</option>
              <option value="email">Email newsletter</option>
              <option value="returning">Returning from last year</option>
              <option value="other">Other</option>
            </select>
          </div>
        </div>

        <!-- Policy -->
        <div class="su-form-section">
          <div class="su-form-section-title">Agreement</div>
          <div class="su-policy-box">
            <label class="su-policy-check">
              <input type="checkbox" id="suPolicyAgreed" name="policyAgreed" required>
              <span>I confirm that the information provided is accurate and I agree to the MAM Academy <a href="/terms" target="_blank" style="color:var(--su-amber)">terms &amp; policies</a>. I understand that submitting this form does not guarantee a place, and the academy will confirm enrollment separately.</span>
            </label>
            <div class="su-field-error" id="su-err-policyAgreed" style="margin-top:8px">You must agree to the programme policy.</div>
          </div>
          <div class="su-field">
            <label for="suPolicySig">Electronic signature <span class="req">*</span></label>
            <input type="text" id="suPolicySig" name="policySignedName" placeholder="Guardian's full name" required maxlength="160">
            <div class="su-field-error" id="su-err-policySignedName">Signature is required and must match the guardian's name.</div>
            <div class="su-field-hint" id="suPolicySigHint">Type the guardian's full name as the electronic signature</div>
          </div>
        </div>

        <button type="submit" class="su-submit-btn" id="summerSubmitBtn">
          Submit Application
        </button>
        <p style="text-align:center;color:var(--su-muted);font-size:.8rem;margin:14px 0 0">
          <i class="fa-solid fa-shield-halved" style="color:var(--su-amber)"></i>&nbsp;
          Your information is private and never shared without your consent.
        </p>
      </form>

      <!-- Success state (shown by JS) -->
      <div class="su-success" id="summerSuccess" role="status">
        <div class="su-success-icon">✓</div>
        <h3 class="su-success-title">Application received!</h3>
        <div class="su-success-ref" id="summerSuccessRef"></div>
        <p class="su-success-msg">
          Thank you for applying to <strong><?= htmlspecialchars($progName) ?></strong>.
          We've sent a confirmation to your email. Our team will review your application and be in touch shortly.
        </p>
        <a href="/status" class="su-btn-primary" style="display:inline-flex;margin:0 auto">
          <i class="fa-solid fa-magnifying-glass"></i> Track your application
        </a>
      </div>

      <?php elseif ($notYet): ?>
      <!-- ── Not yet open ── -->
      <div class="su-closed-notice">
        <div class="su-closed-icon">🌅</div>
        <h3 class="su-closed-title">Applications open <?= htmlspecialchars(formatDate($appOpen, 'F j, Y')) ?></h3>
        <p class="su-closed-msg">
          The application window for <?= htmlspecialchars($progName) ?> hasn't opened yet.
          Check back on <?= htmlspecialchars(formatDate($appOpen, 'F j')) ?> to apply.
          <?php if ($contactEmail): ?><br><br>Have questions? Email <a href="mailto:<?= htmlspecialchars($contactEmail) ?>" style="color:var(--su-amber)"><?= htmlspecialchars($contactEmail) ?></a><?php endif; ?>
        </p>
      </div>

      <?php elseif ($closed): ?>
      <!-- ── Applications closed ── -->
      <div class="su-closed-notice">
        <div class="su-closed-icon">🏁</div>
        <h3 class="su-closed-title">Applications are now closed</h3>
        <p class="su-closed-msg">
          The application window for <?= htmlspecialchars($progName) ?> has ended.
          If you've already applied, <a href="/status" style="color:var(--su-amber)">check your application status</a>.
          <?php if ($contactEmail): ?><br><br>Questions? Email <a href="mailto:<?= htmlspecialchars($contactEmail) ?>" style="color:var(--su-amber)"><?= htmlspecialchars($contactEmail) ?></a><?php endif; ?>
        </p>
      </div>

      <?php else: ?>
      <!-- ── No dates configured yet ── -->
      <div class="su-closed-notice">
        <div class="su-closed-icon">🎵</div>
        <h3 class="su-closed-title">Coming soon</h3>
        <p class="su-closed-msg">
          Details for <?= htmlspecialchars($progName) ?> are being finalised.
          <?php if ($contactEmail): ?>Contact <a href="mailto:<?= htmlspecialchars($contactEmail) ?>" style="color:var(--su-amber)"><?= htmlspecialchars($contactEmail) ?></a> to register your interest.<?php else: ?>Check back soon for dates and application details.<?php endif; ?>
        </p>
      </div>
      <?php endif; ?>

    </div><!-- .su-form-card -->
  </div>
</section>

<!-- ── CONTACT ───────────────────────────────────────────── -->
<?php if ($contactEmail): ?>
<section class="su-contact">
  <p>Have questions about <?= htmlspecialchars($progName) ?>?</p>
  <a href="mailto:<?= htmlspecialchars($contactEmail) ?>">
    <i class="fa-solid fa-envelope" style="color:var(--su-amber)"></i>
    <?= htmlspecialchars($contactEmail) ?>
  </a>
</section>
<?php endif; ?>

</main>

<?php include __DIR__ . '/partials/footer.html'; ?>

<script src="assets/js/config.js?v=20260706a"></script>
<script src="assets/js/main.js?v=20260706a"></script>
<script src="assets/js/headshot.js?v=20260615e"></script>
<script src="assets/js/summer.js?v=20260720a"></script>
<script>
(function () {
  function convertFees() {
    if (!window.MAM_CCY) return;
    document.querySelectorAll('.su-fee-local[data-usd]').forEach(function (el) {
      var usd = parseFloat(el.dataset.usd);
      if (!isNaN(usd) && usd > 0) el.textContent = MAM_CCY.format(usd);
    });
  }
  convertFees();
  document.addEventListener('mam:config', convertFees);
})();
</script>
</body>
</html>
