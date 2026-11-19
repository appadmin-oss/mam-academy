<?php
declare(strict_types=1);
require_once __DIR__ . '/api/lib/bootstrap.php';
header_remove('Content-Type');
header('Content-Type: text/html; charset=utf-8');
if (!defined('MAM_HTML_RESPONSE')) define('MAM_HTML_RESPONSE', true);

$today       = date('Y-m-d');
$_sumOpen    = (string)mam_setting('summer_app_open',  '');
$_sumClose   = (string)mam_setting('summer_app_close', '');
// Shared predicate (admissions.php) — the chooser must appear exactly when
// POST /api/summer/apply would accept, and each window bound counts on its own.
$showChooser = mam_summer_accepting($today);
if ($showChooser) {
    $sumProgName  = (string)mam_setting('summer_prog_name',         'MAM Summer Music Camp');
    $sumTagline   = (string)mam_setting('summer_tagline',           '');
    $sumFee       = (float)mam_setting_num('summer_fee',             0);
    $sumMinAge    = (int)mam_setting_num('summer_min_age',           8);
    $sumMaxAge    = (int)mam_setting_num('summer_max_age',          18);
    $mainProgName = (string)mam_setting('main_prog_chooser_name',   'Full Year Programmes');
    $mainProgDesc = (string)mam_setting('main_prog_chooser_desc',   'Apply to our year-round music academy. Choose your programme, mode, and level.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="color-scheme" content="dark">
<title>Apply Now — MAM Academy | Smart Onboarding &amp; Secure Payment</title>
<meta name="description" content="Complete your MAM Academy enrollment in five guided steps. Pay securely with Paystack, Flutterwave, Stripe or bank transfer.">
<meta name="robots" content="noindex, follow">
<link rel="canonical" href="https://academy.mamcentre.org/apply">
<meta property="og:type" content="website">
<meta property="og:site_name" content="MAM Academy">
<meta property="og:title" content="Apply Now — MAM Academy | Smart Onboarding &amp; Secure Payment">
<meta property="og:description" content="Complete your MAM Academy enrollment in five guided steps. Pay securely with Paystack, Flutterwave, Stripe or bank transfer.">
<meta property="og:url" content="https://academy.mamcentre.org/apply">
<meta property="og:image" content="https://academy.mamcentre.org/api/og?t=Apply+to+MAM+Academy&amp;s=Five+guided+steps%2C+auto-saved.+Secure+your+seat+in+minutes.&amp;e=Applications+Open">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:type" content="image/png">
<meta property="og:locale" content="en_US">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Apply Now — MAM Academy | Smart Onboarding &amp; Secure Payment">
<meta name="twitter:description" content="Complete your MAM Academy enrollment in five guided steps. Pay securely with Paystack, Flutterwave, Stripe or bank transfer.">
<meta name="twitter:image" content="https://academy.mamcentre.org/api/og?t=Apply+to+MAM+Academy&amp;s=Five+guided+steps%2C+auto-saved.+Secure+your+seat+in+minutes.&amp;e=Applications+Open">
<script type="application/ld+json">{"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[{"@type":"ListItem","position":1,"name":"Home","item":"https://academy.mamcentre.org/"},{"@type":"ListItem","position":2,"name":"Apply","item":"https://academy.mamcentre.org/apply"}]}</script>
<link rel="icon" type="image/svg+xml" href="mam-academy-logo.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Cinzel:wght@600;700&family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" referrerpolicy="no-referrer">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@23.0.12/build/css/intlTelInput.min.css">
<link rel="stylesheet" href="assets/css/main.css?v=20260920">
<link rel="stylesheet" href="assets/css/apply.css?v=20260920">
<style>
/* ── Programme Chooser ───────────────────────────── */
.prog-chooser{padding:clamp(40px,7vw,72px) 20px;background:linear-gradient(180deg,#0C0703 0%,#120B05 100%)}
.pc-shell{max-width:720px;margin:0 auto;text-align:center}
.pc-eyebrow{font-size:.72rem;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:#B09070;margin-bottom:10px}
.pc-title{font-family:'Cinzel',serif;color:#fff;font-size:clamp(1.4rem,4vw,2rem);margin:0 0 10px}
.pc-sub{color:#9a8264;font-size:.93rem;line-height:1.65;margin:0 0 32px;max-width:480px;margin-left:auto;margin-right:auto}
.pc-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:520px){.pc-grid{grid-template-columns:1fr}}
.pc-card{
  display:flex;flex-direction:column;align-items:flex-start;gap:10px;
  background:#1A120B;border:1.5px solid rgba(224,138,26,.22);
  border-radius:16px;padding:clamp(20px,4vw,28px);
  text-align:left;cursor:pointer;text-decoration:none;
  transition:transform .18s,box-shadow .18s,border-color .18s;
}
.pc-card:hover,.pc-card:focus-visible{
  transform:translateY(-4px);
  box-shadow:0 16px 40px rgba(0,0,0,.35);
}
.pc-card--summer{border-color:rgba(251,191,36,.35)}
.pc-card--summer:hover,.pc-card--summer:focus-visible{border-color:#FBBF24;box-shadow:0 16px 40px rgba(251,191,36,.2)}
.pc-card--regular:hover,.pc-card--regular:focus-visible{border-color:rgba(224,138,26,.6);box-shadow:0 16px 40px rgba(224,138,26,.15)}
.pc-card-badge{
  background:linear-gradient(135deg,#E08A1A,#FBBF24);color:#1A120B;
  font-size:.65rem;font-weight:800;letter-spacing:.08em;padding:3px 10px;
  border-radius:20px;text-transform:uppercase;
}
.pc-card-icon{font-size:2rem;line-height:1}
.pc-card-title{font-family:'Cinzel',serif;color:#fff;font-size:1.05rem;font-weight:700;margin:0}
.pc-card-desc{color:#9a8264;font-size:.84rem;line-height:1.6;margin:0;flex:1}
.pc-card-fee{color:#FBBF24;font-size:.82rem;font-weight:700}
.pc-card-cta{
  margin-top:6px;display:inline-flex;align-items:center;gap:7px;
  color:#FBBF24;font-size:.84rem;font-weight:700;
}
.pc-card--regular .pc-card-cta{color:#E08A1A}
</style>
</head>
<body>
<a href="#main" class="skip-link">Skip to main content</a>

<div id="preloader" role="status" aria-label="Loading">
  <div class="preloader-logo">
    <img src="mam-academy-logo.svg" alt="" onerror="this.style.display='none'">
    <div class="preloader-name">MAM ACADEMY</div>
    <div class="preloader-tag">Preparing your application</div>
  </div>
  <div class="waves" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div>
</div>

<?php include __DIR__ . '/partials/header.html'; ?>

<main id="main">
  <header class="page-hero" style="--page-hero-img:url('https://images.unsplash.com/photo-1493225457124-a3eb161ffa5f?w=1920&q=80');padding:clamp(64px,9vw,112px) 24px clamp(52px,7vw,84px)">
    <div class="page-hero-inner">
      <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="/">Home</a><i class="fa-solid fa-chevron-right"></i>
        <span>Apply</span>
      </nav>
      <div class="page-hero-eyebrow"><i class="fa-solid fa-music"></i>Application &amp; Enrollment</div>
      <h1 class="page-hero-title">Begin Your <span class="highlight-gold">Musical Journey</span></h1>
      <p class="page-hero-lede">Complete your MAM Academy enrollment in five guided steps. We auto-save your progress, so you can pause and return anytime.</p>
      <div data-scholarship-banner="compact" style="max-width:520px;margin:22px 0 0"></div>
    </div>
  </header>

<?php if ($showChooser): ?>
  <section class="prog-chooser" id="progChooser" aria-label="Choose your application track">
    <div class="pc-shell">
      <div class="pc-eyebrow">Where are you headed?</div>
      <h2 class="pc-title">Two paths, one Academy</h2>
      <p class="pc-sub">Tell us which programme you're applying for and we'll take you to the right form.</p>
      <div class="pc-grid">
        <a href="/summer" class="pc-card pc-card--summer">
          <div class="pc-card-badge">Summer <?= date('Y') ?></div>
          <div class="pc-card-icon">☀️</div>
          <div class="pc-card-title"><?= htmlspecialchars($sumProgName) ?></div>
          <div class="pc-card-desc"><?= $sumTagline ? htmlspecialchars($sumTagline) : 'An intensive summer music experience for ages ' . $sumMinAge . '–' . $sumMaxAge . '.' ?></div>
          <?php if ($sumFee > 0): ?><div class="pc-card-fee">From $<?= number_format($sumFee, 0) ?></div><?php endif; ?>
          <div class="pc-card-cta">Apply now <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></div>
        </a>
        <button type="button" class="pc-card pc-card--regular" id="pcChooseRegular">
          <div class="pc-card-icon">🎓</div>
          <div class="pc-card-title"><?= htmlspecialchars($mainProgName) ?></div>
          <div class="pc-card-desc"><?= htmlspecialchars($mainProgDesc) ?></div>
          <div class="pc-card-cta">Continue <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></div>
        </button>
      </div>
    </div>
  </section>
<?php endif; ?>

  <section class="apply-shell" id="applyShell" aria-label="Application form"<?php if ($showChooser): ?> hidden<?php endif; ?>>
    <!-- Find Your Path — the Learning Advisor. JS-only enhancement: it stays
         hidden (and the wizard below works normally) unless apply-discovery.js
         opens it for an undecided applicant. Hand-off is the wizard's own
         allow-listed ?pathway/&mode/&level/&discipline/&expression prefill. -->
    <section class="advisor" id="mamAdvisor" aria-label="Find your path" hidden></section>
    <?php /* Without JS the wizard shows one question per step and nothing can
             advance it — true before this rework (the five .step panels worked
             the same way) and truer now that step 1 is seven screens. A form
             that silently does nothing is worse than one that says why, and
             the academy would rather take the application by email than lose
             it to a browser setting. */ ?>
    <noscript>
      <div class="a-noscript">
        <p><strong>This application needs JavaScript.</strong> It asks one question at a time and adapts to the programme you choose, so it cannot be shown as a plain page.</p>
        <p>Please turn JavaScript on and reload &mdash; or <a href="/contact?topic=Admissions">email the admissions team</a> and we will send you the questions directly.</p>
      </div>
    </noscript>

    <!-- The app bar. `1 of 5 · Programme` on the left, with a back arrow from
         the second question onward (there is nowhere to go back to from the
         first, and a dead control is worse than no control). `Save & exit` on
         the right states what autosave already does. -->
    <div class="a-appbar js-only">
      <button type="button" class="a-back" id="aBack" aria-label="Back to the previous question" hidden><i class="fa-solid fa-arrow-left" aria-hidden="true"></i></button>
      <span class="a-crumb" id="aCrumb">1 of 5 &middot; Programme</span>
      <button type="button" class="a-exit" id="aExit">Save &amp; exit</button>
    </div>
    <!-- 2px. The fill advances WITHIN a step, not only between them: a bar that
         sits still for six screens teaches people the form is not progressing. -->
    <div class="a-prog js-only" role="progressbar" aria-label="Application progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="aProg">
      <span class="a-prog-fill" id="aProgFill"></span>
    </div>
    <!-- Every screen change is announced. Moving focus alone never says which
         question of how many. -->
    <p class="sr-only" id="applyAnnounce" role="status" aria-live="polite"></p>

    <!-- The horizontal stepper survives as the no-JS fallback and as the
         desktop rail's source of truth for step names. JS hides it in favour of
         the app bar + progress line. -->
    <nav class="apply-stepper" id="applyProgress" aria-label="Application progress">
      <button type="button" class="apply-progress-step active" data-step="0"><span class="step-dot"><span>1</span></span><span class="step-name">Programme</span></button>
      <button type="button" class="apply-progress-step" data-step="1"><span class="step-dot"><span>2</span></span><span class="step-name">Profile</span></button>
      <button type="button" class="apply-progress-step" data-step="2"><span class="step-dot"><span>3</span></span><span class="step-name">Background</span></button>
      <button type="button" class="apply-progress-step" data-step="3"><span class="step-dot"><span>4</span></span><span class="step-name">Review</span></button>
      <button type="button" class="apply-progress-step" data-step="4"><span class="step-dot"><span>5</span></span><span class="step-name">Payment</span></button>
    </nav>

    <?php /* The wizard is a real <form>. It was 51 inputs and 4 selects sitting
             outside any form at all, which cost the browser's whole contribution:
             Enter did nothing (implicit submission requires a form), autofill
             could not see a name/address block to group, password managers would
             not offer to save, and native constraint validation never ran because
             nothing ever fired a submit.

             `novalidate` is deliberate and load-bearing. Steps 2-5 stay in the
             DOM while hidden, so a `required` field on a hidden step would make
             the form unsubmittable with "An invalid form control is not
             focusable" — an error the applicant can neither see nor fix. The
             wizard's own validateStep() gates each step instead, and the
             attributes remain as a machine-readable description of the field. */ ?>
    <?php /* `required` marks exactly the fields validateStep() enforces
             unconditionally — not a wish-list. `city` is absent because the
             wizard does not validate it; parentName/parentPhone are absent
             because they apply only to minors, and a hardcoded `required`
             would misdescribe an adult's application.

             Under `novalidate` these do not block submission — the wizard's own
             messages stay the ones people see. What they DO give is the screen
             reader announcing "required", a machine-readable description of the
             form, and a real backstop the day novalidate is reconsidered. */ ?>
    <form id="applyForm" novalidate autocomplete="on">
    <div class="apply-layout">
    <div class="apply-main">
      <!-- STEP 1: Programme — one question per screen -->
      <div class="step active" data-step="0">
        <h2 class="a-pagetitle">Choose your programme</h2>

        <!-- 1a · Mode ------------------------------------------------------ -->
        <section class="a-sub active" data-sub="0" data-q="mode" aria-label="How will you learn?">
          <h2 class="a-title">How will you learn?</h2>
          <div class="a-cards">
            <label class="a-card"><input type="radio" name="mode" value="online">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-card-body">
                <span class="a-card-title">Online</span>
                <span class="a-card-desc">Live video classes from anywhere.</span>
              </span>
            </label>
            <label class="a-card"><input type="radio" name="mode" value="onsite">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-card-body">
                <span class="a-card-title">Onsite, Lagos</span>
                <span class="a-card-desc">Lessons and ensembles at MAM Centre.</span>
              </span>
            </label>
          </div>
          <div class="field-error" id="err-mode"><i class="fa-solid fa-circle-exclamation"></i><span>Please choose a learning mode</span></div>
          <p class="a-close">Everything here can change before you pay.</p>
        </section>

        <!-- 1b · Programme -------------------------------------------------- -->
        <section class="a-sub" data-sub="1" data-q="pathway" aria-label="Which programme?">
          <h2 class="a-title">Which programme?</h2>
          <div class="a-rows" id="programmeGrid">
            <label class="a-row a-row-lg"><input type="radio" name="pathway" value="crash">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Crash Programme</span>
              <span class="a-row-desc">One course, flat fee, no levels.</span></span>
              <span class="a-row-tag">6 weeks</span>
            </label>
            <label class="a-row a-row-lg"><input type="radio" name="pathway" value="standard">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Standard</span>
              <span class="a-row-desc">One course at your chosen level.</span></span>
              <span class="a-row-tag">1 course</span>
            </label>
            <label class="a-row a-row-lg"><input type="radio" name="pathway" value="certified-musician">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Certified Musician</span>
              <span class="a-row-desc">Your major plus the four compulsory MAM courses.</span></span>
              <span class="a-row-tag">up to 5</span>
            </label>
            <label class="a-row a-row-lg"><input type="radio" name="pathway" value="grand-musician">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Grand Musician</span>
              <span class="a-row-desc">African, Classical and Contemporary together. The flagship path.</span></span>
              <span class="a-row-tag">all 3</span>
            </label>
            <label class="a-row a-row-lg"><input type="radio" name="pathway" value="meta-order">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">MetaMusic Order</span>
              <span class="a-row-desc">Civilization-engineering intensive. Standalone.</span></span>
              <span class="a-row-tag">12 weeks</span>
            </label>
          </div>
          <div class="field-error" id="err-pathway"><i class="fa-solid fa-circle-exclamation"></i><span>Please choose a programme</span></div>
          <p class="a-close">You pay $5 today. Tuition is set after your MAM Auditer assessment, not here.</p>
        </section>

        <!-- 1c · Level (skipped for crash and meta-order) -------------------- -->
        <section class="a-sub" data-sub="2" data-q="level" aria-label="What level are you starting at?">
          <h2 class="a-title">What level are you starting at?</h2>
          <div class="a-rows">
            <label class="a-row"><input type="radio" name="level" value="precert">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Pre-Certificate</span>
              <span class="a-row-desc">Foundations, for new and returning students.</span></span>
              <span class="a-row-tag">Grade 1&ndash;2</span>
            </label>
            <label class="a-row"><input type="radio" name="level" value="cert">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Certificate</span>
              <span class="a-row-desc">Working-musician competencies.</span></span>
              <span class="a-row-tag">Grade 3&ndash;4</span>
            </label>
            <label class="a-row"><input type="radio" name="level" value="prediploma">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Pre-Diploma</span>
              <span class="a-row-desc">Advanced performance and theory.</span></span>
              <span class="a-row-tag">Grade 5&ndash;6</span>
            </label>
            <label class="a-row"><input type="radio" name="level" value="diploma">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Diploma</span>
              <span class="a-row-desc">Professional mastery and capstone.</span></span>
              <span class="a-row-tag">Grade 7&ndash;8</span>
            </label>
          </div>
          <div class="field-error" id="err-level"><i class="fa-solid fa-circle-exclamation"></i><span>Please choose your level</span></div>
          <p class="a-close">Not sure? Pick the nearest &mdash; the Auditer places you properly before teaching starts.</p>
        </section>

        <!-- 1d · Expression (skipped for grand-musician and meta-order) ------ -->
        <section class="a-sub" data-sub="3" data-q="expression" aria-label="Which tradition?">
          <h2 class="a-title">Which tradition?</h2>
          <div class="a-rows">
            <label class="a-row"><input type="radio" name="expression" value="classical">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Classical</span>
              <span class="a-row-desc">The Western art-music tradition.</span></span>
            </label>
            <label class="a-row"><input type="radio" name="expression" value="contemporary">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Contemporary</span>
              <span class="a-row-desc">Pop, gospel, jazz and modern styles.</span></span>
            </label>
            <label class="a-row"><input type="radio" name="expression" value="african">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">African</span>
              <span class="a-row-desc">Indigenous and afro traditions.</span></span>
            </label>
          </div>
          <div class="field-error" id="err-expression"><i class="fa-solid fa-circle-exclamation"></i><span>Please choose a tradition</span></div>
          <p class="a-close">This is what you specialise in. Everything else is still taught.</p>
        </section>

        <!-- 1e · Major ------------------------------------------------------ -->
        <section class="a-sub" data-sub="4" data-q="discipline" aria-label="What will you major in?">
          <h2 class="a-title">What will you major in?</h2>
          <div class="a-search">
            <label class="sr-only" for="majorSearch">Search the courses</label>
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input type="search" id="majorSearch" data-filter="majorRows" placeholder="Search courses" autocomplete="off">
          </div>
          <div class="a-rows" id="majorRows">
            <label class="a-row"><input type="radio" name="discipline" value="piano">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Piano &amp; Keyboard</span>
              <span class="a-row-desc">Classical, gospel and contemporary</span></span>
            </label>
            <label class="a-row"><input type="radio" name="discipline" value="voice">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Voice &amp; Choir</span>
              <span class="a-row-desc">Breath, tone and performance</span></span>
            </label>
            <label class="a-row"><input type="radio" name="discipline" value="guitar">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Guitar</span>
              <span class="a-row-desc">Classical and contemporary</span></span>
            </label>
            <label class="a-row"><input type="radio" name="discipline" value="bass">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Bass</span>
              <span class="a-row-desc">Electric and double bass</span></span>
            </label>
            <label class="a-row"><input type="radio" name="discipline" value="violin">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Violin &amp; Strings</span>
              <span class="a-row-desc">Bow technique and ensemble</span></span>
            </label>
            <label class="a-row"><input type="radio" name="discipline" value="saxophone">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Saxophone</span>
              <span class="a-row-desc">Jazz, classical and afro</span></span>
            </label>
            <label class="a-row"><input type="radio" name="discipline" value="trumpet">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Trumpet</span>
              <span class="a-row-desc">Brass technique and ensemble</span></span>
            </label>
            <label class="a-row"><input type="radio" name="discipline" value="flute">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Flute</span>
              <span class="a-row-desc">Tone, breath and repertoire</span></span>
            </label>
            <label class="a-row"><input type="radio" name="discipline" value="clarinet">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Clarinet</span>
              <span class="a-row-desc">Reed mastery and ensemble</span></span>
            </label>
            <label class="a-row"><input type="radio" name="discipline" value="drums">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Drums &amp; Percussion</span>
              <span class="a-row-desc">Kit, djembe, afro-rhythms</span></span>
            </label>
            <label class="a-row"><input type="radio" name="discipline" value="theory">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Music Theory &amp; Ear</span>
              <span class="a-row-desc">Notation and aural skills</span></span>
            </label>
            <label class="a-row"><input type="radio" name="discipline" value="performance">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Performance Intelligence</span>
              <span class="a-row-desc">Stagecraft and psychology</span></span>
            </label>
            <label class="a-row"><input type="radio" name="discipline" value="rhythm">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Rhythm Intelligence</span>
              <span class="a-row-desc">The MAM Rapid System</span></span>
            </label>
            <label class="a-row"><input type="radio" name="discipline" value="metamusic">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">MetaMusic &amp; Consciousness</span>
              <span class="a-row-desc">The philosophy of sound</span></span>
            </label>
          </div>
          <p class="a-empty" data-empty-for="majorRows" hidden>No course matches that. Clear the search to see all fourteen.</p>
          <div class="field-error" id="err-discipline"><i class="fa-solid fa-circle-exclamation"></i><span>Please choose a course</span></div>
        </section>

        <!-- 1f · Focus area -------------------------------------------------
             ⚠️ NOT merged into 1e. The handoff asks for "one searchable list of
             14 rows" replacing both this and the major, on the reading that the
             two ask "two versions of what will you study". They do not: the
             major is an INSTRUMENT (piano, clarinet) and this is a career
             focus (film scoring, music business). There is no mapping from the
             eleven onto the fourteen, `applications.program` is a stored column
             the server validates against its own allowlist, and the handoff's
             own open question 5 asks whether this taxonomy needs to survive.
             It does — as its own screen, in the same row treatment. -->
        <section class="a-sub" data-sub="5" data-q="program" aria-label="Where are you heading?">
          <h2 class="a-title">Where are you heading?</h2>
          <div class="a-search">
            <label class="sr-only" for="focusSearch">Search the focus areas</label>
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input type="search" id="focusSearch" data-filter="focusRows" placeholder="Search focus areas" autocomplete="off">
          </div>
          <div class="a-rows" id="focusRows">
            <p class="a-group">Creation &amp; production</p>
            <label class="a-row"><input type="radio" name="program" value="songwriting">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Songwriting &amp; Composition</span>
              <span class="a-row-desc">Writing the material itself</span></span>
            </label>
            <label class="a-row"><input type="radio" name="program" value="audio-engineering">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Audio Engineering</span>
              <span class="a-row-desc">Capture, mixing and the room</span></span>
            </label>
            <label class="a-row"><input type="radio" name="program" value="music-production">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Music Production</span>
              <span class="a-row-desc">Taking a record from idea to master</span></span>
            </label>
            <label class="a-row"><input type="radio" name="program" value="orchestration">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Orchestration</span>
              <span class="a-row-desc">Writing for the ensemble</span></span>
            </label>
            <label class="a-row"><input type="radio" name="program" value="arranging">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Music Arranging</span>
              <span class="a-row-desc">Re-shaping a piece for its players</span></span>
            </label>
            <label class="a-row"><input type="radio" name="program" value="film-scoring">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Film Scoring</span>
              <span class="a-row-desc">Music written to picture</span></span>
            </label>
            <p class="a-group">Leadership</p>
            <label class="a-row"><input type="radio" name="program" value="music-education">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Music Education</span>
              <span class="a-row-desc">Teaching it to the next people</span></span>
            </label>
            <label class="a-row"><input type="radio" name="program" value="music-direction">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Music Direction</span>
              <span class="a-row-desc">Running the band or the service</span></span>
            </label>
            <label class="a-row"><input type="radio" name="program" value="artistic-direction">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Artistic Direction</span>
              <span class="a-row-desc">Shaping what a company performs</span></span>
            </label>
            <label class="a-row"><input type="radio" name="program" value="conducting">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Conducting</span>
              <span class="a-row-desc">Leading from the podium</span></span>
            </label>
            <label class="a-row"><input type="radio" name="program" value="music-business">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-row-main"><span class="a-row-name">Music Business</span>
              <span class="a-row-desc">Rights, releases and the money</span></span>
            </label>
          </div>
          <p class="a-empty" data-empty-for="focusRows" hidden>No focus area matches that. Clear the search to see all eleven.</p>
          <div class="field-error" id="err-program"><i class="fa-solid fa-circle-exclamation"></i><span>Please choose a focus area</span></div>
          <p class="a-close">This frames your studies. It does not change what you pay today.</p>
        </section>

        <!-- 1g · Add more courses (certified-musician and grand-musician only) -->
        <section class="a-sub" data-sub="6" data-q="minors" aria-label="Add more courses">
          <h2 class="a-title">Add more courses?</h2>
          <p class="a-lede" id="compulsoryNote">Rhythm &amp; Pitch, Theory of Music, Performance and MetaMusic are already built into your programme.</p>
          <div class="a-rows" id="minorsGrid"><!-- rendered by JS; excludes your chosen major --></div>
          <p class="a-close">Optional. You can add courses later, at the same half price.</p>
        </section>

        <!-- Session is resolved silently from the academic calendar (no screen). -->
        <div class="session-note" id="sessionNote" hidden>
          <i class="fa-solid fa-calendar-day"></i>
          <div>Your intake: <strong id="sessionNoteLabel">&mdash;</strong><span id="sessionNoteSub"></span></div>
        </div>
      </div>

      <!-- STEP 2: Student Profile -->
      <div class="step" data-step="1">
        <h2 class="a-pagetitle">Your profile</h2>

        <!-- 2a · Who is studying? ------------------------------------------- -->
        <section class="a-sub active" data-sub="0" data-q="who" aria-label="Who is studying?">
          <h2 class="a-title">Who is studying?</h2>
          <div class="track-badge" id="trackBadge" hidden>
            <i class="fa-solid fa-graduation-cap"></i>
            <div><strong id="trackBadgeName">MAM Academy</strong><span id="trackBadgeNote"></span></div>
          </div>

          <div class="a-fields">
            <div class="field">
              <label class="field-label" for="firstName">First name</label>
              <input type="text" id="firstName" class="field-input" autocomplete="given-name" maxlength="60" required>
              <div class="field-error" id="err-firstName"><i class="fa-solid fa-circle-exclamation"></i><span>Enter first name</span></div>
            </div>
            <div class="field">
              <label class="field-label" for="lastName">Last name</label>
              <input type="text" id="lastName" class="field-input" autocomplete="family-name" maxlength="60" required>
              <div class="field-error" id="err-lastName"><i class="fa-solid fa-circle-exclamation"></i><span>Enter last name</span></div>
            </div>
            <div class="field">
              <label class="field-label" for="email">Email</label>
              <input type="email" id="email" class="field-input" autocomplete="email" required>
              <div class="field-help">Your application copy and invoice go here.</div>
              <div class="field-error" id="err-email"><i class="fa-solid fa-circle-exclamation"></i><span>Valid email required</span></div>
            </div>
            <div class="field">
              <label class="field-label" for="phone">Mobile number</label>
              <input type="tel" id="phone" class="field-input" autocomplete="tel" required>
              <div class="field-error" id="err-phone"><i class="fa-solid fa-circle-exclamation"></i><span>Valid phone required</span></div>
            </div>
            <div class="field">
              <label class="field-label" for="dob">Date of birth</label>
              <div class="dob-row">
                <input type="date" id="dob" class="field-input" required autocomplete="bday">
                <div class="dob-readout" id="dobReadout" hidden>
                  <i class="fa-solid fa-cake-candles"></i>
                  <div><strong id="dobAge">&mdash;</strong><span id="dobBand"></span></div>
                </div>
              </div>
              <div class="field-help">Sets your age band &mdash; under 16 adds a guardian contact.</div>
              <div class="field-error" id="err-dob"><i class="fa-solid fa-circle-exclamation"></i><span>A valid date of birth is required</span></div>
            </div>
            <div class="field">
              <label class="field-label" id="sexLabel">Sex</label>
              <div class="sex-row" role="radiogroup" aria-labelledby="sexLabel">
                <label class="sex-pill"><input type="radio" name="sex" value="female">Female</label>
                <label class="sex-pill"><input type="radio" name="sex" value="male">Male</label>
                <label class="sex-pill"><input type="radio" name="sex" value="prefer-not-to-say">Prefer not to say</label>
              </div>
              <div class="field-error" id="err-sex"><i class="fa-solid fa-circle-exclamation"></i><span>Please select</span></div>
            </div>
          </div>

          <!-- The headshot widget is unchanged (four independent failure points,
               all four of which have failed in production — see CLAUDE.md). Only
               its container moved behind a disclosure row, and #hsStatus stays in
               the DOM: validateStep()'s "a photo is required" branch used to
               write into an element that was never on the page. -->
          <details class="a-disc" id="headshotDisc">
            <summary class="a-disc-head">
              <span class="a-avatar" id="hsAvatar" aria-hidden="true"><i class="fa-solid fa-camera"></i></span>
              <span class="a-disc-main"><span class="a-disc-name">Your photo</span>
              <span class="a-disc-desc" id="hsSummary">Required &mdash; for your student tag and class roster</span></span>
              <i class="fa-solid fa-chevron-right a-disc-go" aria-hidden="true"></i>
            </summary>
            <div class="a-disc-body">
              <div id="headshotMount"></div>
              <div class="hs-status" id="hsStatus" role="status" hidden></div>
              <div class="field-help">Front-facing, clear, and just you. Nothing is sent anywhere before you confirm it.</div>
            </div>
          </details>

          <p class="a-close">Address comes next, on its own screen &mdash; your phone can autofill it in one tap.</p>
        </section>

        <!-- 2b · Address ---------------------------------------------------- -->
        <section class="a-sub" data-sub="1" data-q="address" aria-label="Where do you live?">
          <h2 class="a-title">Where do you live?</h2>
          <div class="a-fields">
            <div class="field">
              <label class="field-label" for="address">Street address</label>
              <input type="text" id="address" class="field-input" autocomplete="street-address" maxlength="200" required>
              <div class="field-error" id="err-address"><i class="fa-solid fa-circle-exclamation"></i><span>Enter your address</span></div>
            </div>
            <div class="field">
              <label class="field-label" for="lga">Local government / county</label>
              <input type="text" id="lga" class="field-input" autocomplete="address-level3" maxlength="80" required>
              <div class="field-error" id="err-lga"><i class="fa-solid fa-circle-exclamation"></i><span>Enter LGA / county</span></div>
            </div>
            <div class="field">
              <label class="field-label" for="city">City</label>
              <input type="text" id="city" class="field-input" autocomplete="address-level2">
            </div>
            <div class="field">
              <label class="field-label" for="state">State / province</label>
              <input type="text" id="state" class="field-input" autocomplete="address-level1" maxlength="80" required>
              <div class="field-error" id="err-state"><i class="fa-solid fa-circle-exclamation"></i><span>Enter state / province</span></div>
            </div>
            <div class="field">
              <label class="field-label" for="country">Country</label>
              <input type="text" id="country" class="field-input" autocomplete="country-name" required>
              <div class="field-error" id="err-country"><i class="fa-solid fa-circle-exclamation"></i><span>Enter country</span></div>
            </div>
          </div>
          <p class="a-close">We use this for your student file and, if you study onsite, your travel time.</p>
        </section>

        <!-- 2c · Guardian (skipped unless the student is under 16) ----------- -->
        <section class="a-sub" data-sub="2" data-q="guardian" aria-label="Guardian contact">
          <h2 class="a-title">Who do we contact?</h2>
          <p class="a-lede">The student is under 16, so a parent or guardian is the contact we use for scheduling and updates.</p>
          <div class="a-fields" id="guardianField">
            <div class="field">
              <label class="field-label" for="parentName">Parent or guardian name</label>
              <input type="text" id="parentName" class="field-input" autocomplete="name">
              <div class="field-error" id="err-parentName"><i class="fa-solid fa-circle-exclamation"></i><span>A parent/guardian is required for under-16 students</span></div>
            </div>
            <div class="field">
              <label class="field-label" for="parentEmail">Their email address</label>
              <input type="email" id="parentEmail" class="field-input" autocomplete="email" inputmode="email" aria-describedby="help-parentEmail">
              <div class="field-help" id="help-parentEmail">Their own address, so they get their own login. One address can hold only one login, so if the family shares an inbox with the student, the guardian&rsquo;s login is set up with the office instead.</div>
              <div class="field-error" id="err-parentEmail"><i class="fa-solid fa-circle-exclamation"></i><span>Enter the guardian&rsquo;s email address</span></div>
            </div>
            <div class="field">
              <label class="field-label" for="parentPhone">Their mobile number</label>
              <input type="tel" id="parentPhone" class="field-input" autocomplete="tel">
              <div class="field-error" id="err-parentPhone"><i class="fa-solid fa-circle-exclamation"></i><span>A guardian phone number is required</span></div>
            </div>
          </div>
          <p class="a-close">They get their own portal account once you are enrolled.</p>
        </section>
      </div>

      <!-- STEP 3: Background -->
      <div class="step" data-step="2">
        <h2 class="a-pagetitle">Background and goals</h2>
        <section class="a-sub active" data-sub="0" data-q="background" aria-label="Where are you now?">
          <h2 class="a-title">Where are you now?</h2>
          <div class="a-fields">
            <div class="field">
              <label class="field-label" for="experience">Current musical level</label>
              <div class="field-select-wrap">
                <select id="experience" class="field-select">
                  <option value="">Select level</option>
                  <option value="none">Complete beginner</option>
                  <option value="some">Some basic knowledge</option>
                  <option value="intermediate">Intermediate</option>
                  <option value="advanced">Advanced musician</option>
                </select>
                <i class="fa-solid fa-chevron-down field-select-arrow"></i>
              </div>
            </div>

            <!-- Learning-Readiness note (#1): a warm, encouraging reflection derived
                 from the level + date of birth already entered. Reassurance, never a
                 gate — the server derives the authoritative signal, and the MAM
                 Auditer confirms placement. Announced to assistive tech. -->
            <div class="readiness-note" id="readinessNote" role="status" aria-live="polite" hidden></div>

            <div class="field">
              <label class="field-label" for="goals">What do you want to achieve at MAM?</label>
              <textarea id="goals" class="field-textarea" placeholder="e.g. lead my church worship team, perform on stage, pass Grade 5 ABRSM, write my own songs…" maxlength="1000"></textarea>
            </div>

            <div class="field">
              <label class="field-label" for="referrer">How did you hear about MAM Academy?</label>
              <div class="field-select-wrap">
                <select id="referrer" class="field-select">
                  <option value="">Select source</option>
                  <option value="search">Google / Search</option>
                  <option value="instagram">Instagram</option>
                  <option value="twitter">Twitter / X</option>
                  <option value="facebook">Facebook</option>
                  <option value="youtube">YouTube</option>
                  <option value="friend">Referred by a friend</option>
                  <option value="church">My church</option>
                  <option value="power-play">Power Play series</option>
                  <option value="other">Other</option>
                </select>
                <i class="fa-solid fa-chevron-down field-select-arrow"></i>
              </div>
            </div>
          </div>

          <label class="consent">
            <input type="checkbox" id="auditerOptIn" checked>
            <span class="consent-text">Yes &mdash; book a <strong>MAM Auditer entry assessment</strong> with my enrolment (recommended).</span>
          </label>
        </section>
      </div>

      <!-- STEP 4: Review -->
      <div class="step" data-step="3">
        <h2 class="a-pagetitle">Review and confirm</h2>
        <section class="a-sub active" data-sub="0" data-q="review" aria-label="Check it over">
          <h2 class="a-title">Check it over</h2>

          <!-- Six hairline rows, each with its own Edit. The old block was a
               read-only dump with one "Edit" per SECTION, so correcting a
               misread programme meant re-walking the whole step. -->
          <div id="reviewBody"></div>

          <!-- The price is stated BEFORE the consents, not after them: a fee
               disclosed below the box you have already ticked is disclosed too
               late to inform the tick. -->
          <div class="a-due">
            <span class="a-due-label">Due today</span>
            <span class="a-due-amount" id="reviewFeeLabel">$5</span>
          </div>
          <p class="a-due-note">Covers registration, your MAM Academy student ID card, your student file and a 30-minute MAM Auditer assessment. Tuition is <strong>not</strong> charged here &mdash; it is discussed only after the Auditer Judges have placed you.</p>
          <label class="a-reg">
            <input type="checkbox" id="includeRegistration" checked>
            <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
            <span>Include registration and student file (<span data-amt-usd="5">$5</span>)</span>
          </label>

          <!-- The full policy is emailed on submit and is web-viewable at
               /api/policy/<ref>. A scrollable excerpt inside a phone form is
               read by nobody, so it collapses behind the consent it belongs to.
               The typed signature stays: it is a legal artefact, not chrome. -->
          <div class="a-consents">
            <label class="a-consent">
              <input type="checkbox" id="policyAgree">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-consent-text">I have read and agree to abide by the <strong id="policyConsentName">MAM Academy class policy</strong>.</span>
            </label>
            <div class="field-error" id="err-policyAgree"><i class="fa-solid fa-circle-exclamation"></i><span>Please agree to the class policy to continue</span></div>

            <details class="a-disc a-disc-quiet" id="policyBlock">
              <summary class="a-disc-head">
                <span class="a-disc-main"><span class="a-disc-name" id="policyTitle">Read the class policy</span>
                <span class="a-disc-desc">The full version is emailed to you on submit.</span></span>
                <i class="fa-solid fa-chevron-right a-disc-go" aria-hidden="true"></i>
              </summary>
              <div class="a-disc-body">
                <p class="policy-intro" id="policyIntro"></p>
                <ul class="policy-points" id="policyPoints"></ul>
              </div>
            </details>

            <div class="field" id="policySignField">
              <label class="field-label" for="policySignName">Type your full name to sign</label>
              <input type="text" id="policySignName" class="field-input" autocomplete="name" maxlength="120">
              <div class="field-help">Signed on <span id="policySignDate">&mdash;</span>. This is your electronic signature on the policy.</div>
              <div class="field-error" id="err-policySignName"><i class="fa-solid fa-circle-exclamation"></i><span>Type your full name to sign the policy</span></div>
            </div>

            <label class="a-consent">
              <input type="checkbox" id="consent">
              <span class="a-mark" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
              <span class="a-consent-text">I confirm the information above is accurate and I agree to MAM Academy's <a href="terms" target="_blank" rel="noopener">Terms of Service</a> and <a href="privacy" target="_blank" rel="noopener">Privacy Policy</a>.</span>
            </label>
            <div class="field-error" id="err-consent"><i class="fa-solid fa-circle-exclamation"></i><span>Please confirm to continue</span></div>
          </div>
        </section>
      </div>

      <!-- STEP 5: Payment — booking fee only. The price is regional and the
           server is authoritative; we just render what /api/booking/pricing
           returned at page-load. The confirmation email + invoice were
           already sent on Step-4 submit, so the applicant has those in hand
           whether or not they finish this step now (they can always pay
           later via the link in the email which lands on /book?ref=…). -->
      <div class="step" data-step="4">
        <div class="step-head">
          <div class="step-num">Step 5 of 5 · Confirm your seat</div>
          <h2 class="step-title">Pay <span id="payHeroLabel">$5</span> &mdash; secure your session</h2>
          <p class="step-desc">A one-time confirmation. Covers registration, your MAM Academy student ID card, your student file and a 30-minute MAM Auditer assessment. Tuition is <strong>not</strong> charged here &mdash; it's discussed only after the Auditer Judges have placed you.</p>
        </div>

        <div class="pay-card">
          <div class="pay-card-price">
            <div class="pay-card-amount" id="payAmount">$5</div>
            <div class="pay-card-meta"><span id="payCountryFlag">🌍</span> <span id="payCountryName">your region</span> · one-time</div>
          </div>
          <ul class="pay-card-covers">
            <li><i class="fa-solid fa-check"></i>Registration &amp; admission processing</li>
            <li><i class="fa-solid fa-check"></i>MAM Academy student ID card</li>
            <li><i class="fa-solid fa-check"></i>Student file (kept on record)</li>
            <li><i class="fa-solid fa-check"></i>30-minute MAM Auditer assessment</li>
          </ul>
          <div class="pay-card-disclose" id="payDisclose"></div>
        </div>

        <div class="payment-secure">
          <i class="fa-solid fa-shield-halved"></i>
          <span>Encrypted &amp; PCI-DSS compliant. Verified by Paystack &amp; Flutterwave. We never store card details.</span>
        </div>

        <div class="payment-methods">
          <label class="payment-method"><input type="radio" name="payment" value="paystack" checked>
            <div class="payment-logo paystack">PAY<br>STACK</div>
            <div class="payment-method-info"><div class="payment-method-name">Card / Bank / USSD</div><div class="payment-method-desc">Paystack &middot; instant confirmation</div></div>
            <div class="payment-method-check"></div>
          </label>
          <label class="payment-method"><input type="radio" name="payment" value="flutterwave">
            <div class="payment-logo flutterwave">Flutter<br>wave</div>
            <div class="payment-method-info"><div class="payment-method-name">Card · Bank · M-Pesa · Apple/Google Pay</div><div class="payment-method-desc">Flutterwave &middot; international friendly</div></div>
            <div class="payment-method-check"></div>
          </label>
          <label class="payment-method"><input type="radio" name="payment" value="bank">
            <div class="payment-logo bank"><i class="fa-solid fa-building-columns"></i></div>
            <div class="payment-method-info"><div class="payment-method-name">Bank Transfer</div><div class="payment-method-desc">Manual confirmation &middot; ~24h</div></div>
            <div class="payment-method-check"></div>
          </label>
        </div>
        <div class="field-error" id="err-payment"><i class="fa-solid fa-circle-exclamation"></i><span>Please choose a payment method</span></div>

        <div class="bank-details" id="bankDetails">
          <h4><i class="fa-solid fa-building-columns" style="margin-right:8px"></i>Bank Transfer Instructions</h4>
          <div class="bank-row"><span>Bank</span><span><span id="bankName">—</span><button type="button" class="bank-copy" data-copy="bankName">copy</button></span></div>
          <div class="bank-row"><span>Account Name</span><span><span id="bankAccountName">—</span><button type="button" class="bank-copy" data-copy="bankAccountName">copy</button></span></div>
          <div class="bank-row"><span>Account Number</span><span><span id="bankAccountNumber">—</span><button type="button" class="bank-copy" data-copy="bankAccountNumber">copy</button></span></div>
          <p style="font-size:0.82rem;color:var(--text-muted);margin-top:12px;line-height:1.6">After transfer, email proof of payment to <a href="mailto:academy@mamcentre.org" style="color:var(--gold-bright)">academy@mamcentre.org</a> with your application reference (issued on submit).</p>
        </div>

        <div class="step-actions" style="flex-direction:column;align-items:stretch">
          <button type="button" class="btn-pay" id="btnPay"><span id="btnPayLabel">Pay $5</span> <i class="fa-solid fa-lock"></i></button>
          <p style="text-align:center;font-size:0.78rem;color:var(--text-muted);margin:10px 0 0;line-height:1.55">We already emailed your invoice. You can pay later via the link in that email &mdash; this step never has to happen now.</p>
        </div>
      </div>

    </div><!-- /.apply-main -->

    <!-- The desktop summary column. This REPLACES the collapsed
         <details class="summary-panel">, which hid the running total behind a
         tap at every screen size. It appears from 1024px, where there is a
         column to put it in. -->
    <aside class="a-side" id="aSide" aria-label="Your application so far">
      <ol class="a-steps" id="aSteps">
        <li class="a-stepline is-now" data-step="0"><span class="a-dot" aria-hidden="true"></span>Programme</li>
        <li class="a-stepline" data-step="1"><span class="a-dot" aria-hidden="true"></span>Profile</li>
        <li class="a-stepline" data-step="2"><span class="a-dot" aria-hidden="true"></span>Background</li>
        <li class="a-stepline" data-step="3"><span class="a-dot" aria-hidden="true"></span>Review</li>
        <li class="a-stepline" data-step="4"><span class="a-dot" aria-hidden="true"></span>Payment</li>
      </ol>
      <div class="a-sidecard">
        <p class="a-sidehead">Your selection</p>
        <div id="applySummary">
          <div class="summary-row"><span>Programme</span><span>&mdash;</span></div>
        </div>
        <div class="a-sidetotal"><span>Due today</span><span id="spTotal">$5</span></div>
        <div class="field">
          <label class="field-label" for="applyCurrency">Display currency</label>
          <div class="field-select-wrap">
            <select id="applyCurrency" class="field-select">
              <option value="USD">USD ($)</option>
              <option value="NGN">NGN (&#8358;)</option>
              <option value="GBP">GBP (&pound;)</option>
              <option value="EUR">EUR (&euro;)</option>
            </select>
            <i class="fa-solid fa-chevron-down field-select-arrow"></i>
          </div>
        </div>
        <p class="a-sidenote"><i class="fa-solid fa-lock" aria-hidden="true"></i> Secure &middot; auto-saved on this device &middot; <a href="mailto:academy@mamcentre.org">need help?</a></p>
      </div>
    </aside>
    </div><!-- /.apply-layout -->

    <!-- One sticky action bar for the whole wizard. It replaces four separate
         .step-actions rows; the payment step keeps its own #btnPay block
         because that button opens a checkout rather than advancing a step. -->
    <div class="a-bar js-only" id="aBar">
      <span class="a-bar-meta" id="aBarMeta">Question 1 of 7</span>
      <button type="submit" class="a-next" id="aNext"><span id="aNextLabel">Next</span> <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
    </div>
    </form>

  </section>

  <!-- Step-5 regional-fee + payment-card styles. Scoped to .pay-card / .pay-* so
       they don't bleed into other pages. -->
  <style>
    /* Step-1 additions: conditional substeps, save badge, included-courses note,
       silent-session note. Scoped to apply-specific classes. */
    .substep{margin-top:6px}
    .prog-badge{display:inline-block;margin-left:6px;padding:2px 8px;border-radius:100px;background:#34D399;color:#062b1e;font-size:0.66rem;font-weight:800;letter-spacing:0.03em;vertical-align:middle}
    .substep-note{display:flex;gap:8px;align-items:flex-start;font-size:0.84rem;color:var(--text-muted);margin:10px 2px 0;line-height:1.5}
    .substep-note i{color:var(--gold-bright);margin-top:2px}
    .compulsory-note{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;margin:0 0 14px;background:rgba(52,211,153,0.08);border:1px solid rgba(52,211,153,0.28);border-radius:12px;font-size:0.86rem;color:var(--text-soft);line-height:1.5}
    .compulsory-note i{color:#34D399;margin-top:2px;font-size:1rem}
    .session-note{display:flex;gap:10px;align-items:center;padding:12px 14px;margin:16px 0 0;background:var(--bg-surface);border:1px dashed var(--border-gold);border-radius:12px;font-size:0.88rem;color:var(--text-soft)}
    .session-note i{color:var(--gold-bright);font-size:1.05rem}
    .session-note span{display:block;font-size:0.78rem;color:var(--text-muted);margin-top:2px}
    .summary-discount span:last-child{color:#34D399;font-weight:700}
    .pay-card{display:grid;grid-template-columns:auto 1fr;gap:24px;align-items:center;padding:22px;margin:0 0 22px;background:linear-gradient(160deg,rgba(224,138,26,0.10),var(--bg-surface));border:1px solid var(--border-gold);border-radius:16px}
    @media (max-width:560px){.pay-card{grid-template-columns:1fr;text-align:center}.pay-card-covers{text-align:left}}
    .pay-card-amount{font-family:var(--font-display);font-size:3.4rem;line-height:1;color:var(--text);letter-spacing:0.02em}
    .pay-card-meta{font-size:0.84rem;color:var(--text-muted);margin-top:6px;letter-spacing:0.02em}
    .pay-card-covers{list-style:none;padding:0;margin:0;display:grid;gap:7px;font-size:0.92rem;color:var(--text-soft);line-height:1.5}
    .pay-card-covers li{display:flex;gap:9px;align-items:flex-start}
    .pay-card-covers li i{flex:0 0 18px;height:18px;border-radius:50%;background:rgba(52,211,153,0.18);color:#34D399;display:inline-flex;align-items:center;justify-content:center;font-size:0.66rem;margin-top:3px}
    .pay-card-disclose{grid-column:1/-1;padding-top:14px;border-top:1px dashed var(--border);font-size:0.8rem;color:var(--text-muted);line-height:1.55;display:none}
    .pay-card-disclose.show{display:block}
    .pay-card-disclose i{color:var(--gold-bright);margin-right:4px}
  </style>

</main>

<?php include __DIR__ . '/partials/footer.html'; ?>

<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@23.0.12/build/js/intlTelInputWithUtils.min.js"></script>
<script src="assets/js/config.js?v=20260807a"></script>
<script src="assets/js/main.js?v=20260920"></script>
<script src="assets/js/validate.js?v=20260530a"></script>
<script src="assets/js/headshot.js?v=20260817a"></script>
<script src="assets/js/apply.js?v=20261119"></script>
<script src="assets/js/apply-discovery.js?v=20260703a"></script>
<?php if ($showChooser): ?>
<script>
(function () {
  var btn = document.getElementById('pcChooseRegular');
  var chooser = document.getElementById('progChooser');
  var shell   = document.getElementById('applyShell');
  if (!btn || !shell) return;
  btn.addEventListener('click', function () {
    if (chooser) { chooser.style.display = 'none'; }
    shell.hidden = false;
    shell.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
})();
</script>
<?php endif; ?>
</body>
</html>
