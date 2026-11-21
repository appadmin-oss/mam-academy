<?php
/**
 * Apply — the 2026-11 design handoff's application (Apply.dc.html): full screen,
 * no site header or footer, a dark panel of sections on the left, one question
 * per screen on the right, then check your answers and pay the $5 registration.
 *
 * The design asks seven questions; the server (mam_validate_application) needs
 * more than that, and the application is how a learner's record, their guardian's
 * account and their placement come to exist. So the flow keeps the design's shape
 * and adds, in the same tile-and-field language, the screens the record needs:
 * date of birth (age, track and the guardian rule are all derived from it), sex,
 * the style of music, the focus area, the starting level ("not sure" is a valid
 * answer — the assessment sets it), the address, the guardian's own email, and a
 * typed signature on the class policies. Nothing is collected that the server
 * does not read. HANDOFF §4z16 lists each addition and why.
 *
 * assets/js/apply-flow.js draws the screens; every fee comes from site_fees().
 * The previous wizard is /apply-classic, where emailed "resume your draft" links
 * still land.
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';

// A saved-draft link from an email belongs to the wizard that saved the draft.
if (isset($_GET['resume'])) {
    header('Location: /apply-classic?' . (string) $_SERVER['QUERY_STRING'], true, 302);
    exit;
}

$apIntake = site_intake();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Apply — MAM Academy</title>
<meta name="description" content="Apply to MAM Academy in about five minutes: tell us about the learner, choose an instrument and a programme, and pay the <?= site_h(site_fee('reg')) ?> registration.">
<link rel="canonical" href="https://academy.mamcentre.org/apply">
<meta name="robots" content="index, follow">
<meta name="theme-color" content="#211A13">
<link rel="icon" type="image/svg+xml" href="/mam-academy-logo.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500&amp;family=Inter:wght@400;500;600&amp;display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/site.css?v=20261121">
<style>
@media (max-width: 960px){.ap-aside{display:none!important}.ap-mob{display:flex!important}}
@keyframes qIn{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
.qin{animation:qIn .6s var(--out) both}
@media (prefers-reduced-motion: reduce){.qin{animation:none}}
</style>
<?= site_org_jsonld() ?>
</head>
<body class="site">
<a href="#main" class="skip" style="position: absolute; left: 8px; top: -60px; z-index: 50; background: #FFFFFF; color: #211A13; padding: 12px 16px; border-radius: 6px">Skip to the question</a>
<div class="ap" style="min-height: 100vh; display: flex; background: #FAF8F4">
  <aside class="ap-aside" aria-label="Your application" style="flex: 0 0 clamp(340px, 32vw, 440px); position: sticky; top: 0; height: 100vh; box-sizing: border-box; overflow-y: auto; background: #211A13; color: #FCF8F3; padding: 28px clamp(28px, 3vw, 44px); display: flex; flex-direction: column; gap: 36px">
    <a href="/" style="display: inline-flex; align-items: center; min-height: 44px; color: #FCF8F3; text-decoration: none; font-family: 'Cormorant Garamond', Georgia, serif; font-size: 24px; font-weight: 600">MAM Academy</a>
    <div style="display: flex; flex-direction: column; gap: 6px"><p style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #F2A93B">Application · <?= site_h($apIntake) ?></p><p style="margin: 0; font-size: 15px; color: #D8CEC1">About 5 minutes. Your answers are saved as you go.</p></div>
    <ol data-ap="sections" aria-label="Application sections" style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column"></ol>
    <div style="padding: 20px 22px; border-radius: 16px; background: rgba(252,248,243,.06); display: flex; flex-direction: column; gap: 2px">
      <p style="margin: 0; font-size: 14px; color: #D8CEC1">Estimated tuition</p>
      <p aria-live="polite" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-size: 40px; font-weight: 600; line-height: 1.15"><span data-ap="fee">—</span><span data-ap="fee-unit" style="font-family: Inter, sans-serif; font-size: 15px; font-weight: 400; color: #D8CEC1"></span></p>
      <p style="margin: 0; font-size: 13px; color: #D8CEC1"><span data-ap="fee-note">Choose a programme to see the fee.</span> Due today: <?= site_fee('reg') ?> registration.</p>
    </div>
    <div style="margin-top: auto; display: flex; flex-direction: column; gap: 6px; font-size: 13px; color: #B8AC9E">
      <span data-ap="saved" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap"><svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#9CC59A" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5L20 7"></path></svg>Progress saved on this device<span data-ap="reset-wrap" hidden> · <button type="button" data-ap="reset" class="ul" style="padding: 0; border: 0; background: none; font: inherit; color: #D8CEC1; cursor: pointer">Start over</button></span></span>
      <span>Questions? <a href="mailto:academy@mamcentre.org" style="color: #FCF8F3">academy@mamcentre.org</a> · <a href="tel:+2347044446517" style="color: #FCF8F3">+234 704 444 6517</a></span>
    </div>
  </aside>
  <div style="flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column">
    <div aria-hidden="true" style="position: sticky; top: 0; z-index: 5; height: 3px; background: #ECE5DA"><div data-ap="bar" style="height: 100%; background: #F2A93B; transition: width .8s cubic-bezier(.19,1,.22,1); width: 0%"></div></div>
    <div style="padding-left: clamp(20px, 4vw, 40px); padding-right: clamp(20px, 4vw, 40px); height: 72px; display: flex; align-items: center; justify-content: space-between; gap: 16px">
      <div style="display: flex; align-items: center; gap: 16px">
        <a href="/" class="ap-mob" style="display: none; align-items: center; min-height: 44px; font-family: 'Cormorant Garamond', Georgia, serif; font-size: 22px; font-weight: 600; color: #211A13; text-decoration: none">MAM</a>
        <button type="button" data-ap="back" hidden class="ul" style="display: inline-flex; align-items: center; gap: 8px; min-height: 44px; padding: 0; border: 0; background: none; font: inherit; font-size: 15px; color: #211A13; cursor: pointer"><span aria-hidden="true">←</span> Back</button>
      </div>
      <div style="display: flex; align-items: center; gap: 20px; font-size: 14px; color: #574B40"><span data-ap="counter" aria-live="polite">Question 1</span><a href="/" class="ul hide-sm" style="color: #574B40; text-decoration: none">Save and exit</a></div>
    </div>
    <div class="ap-mob" style="display: none; padding-left: clamp(20px, 4vw, 40px); padding-right: clamp(20px, 4vw, 40px); padding-bottom: 8px; font-size: 14px; color: #574B40; justify-content: space-between; gap: 12px"><span data-ap="mob-summary">Your application</span><strong data-ap="mob-fee" style="color: #211A13">—</strong></div>
    <main id="main" style="flex: 1; padding-left: clamp(20px, 4vw, 40px); padding-right: clamp(20px, 4vw, 40px); padding-top: clamp(32px, 7vh, 96px); padding-bottom: 96px">
      <div data-ap="screen" style="max-width: 640px; display: flex; flex-direction: column; gap: 28px">
        <noscript><p style="font-size: 17px">This application needs JavaScript. You can also apply by email at <a href="mailto:academy@mamcentre.org">academy@mamcentre.org</a> or by phone on +234 704 444 6517.</p></noscript>
      </div>
    </main>
  </div>
</div>
<script>window.MAM_SITE=<?= json_encode(['fees' => site_fees(), 'intake' => $apIntake], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="/assets/js/config.js?v=20261121"></script>
<script src="/assets/js/apply-flow.js?v=20261121" defer></script>
</body>
</html>
