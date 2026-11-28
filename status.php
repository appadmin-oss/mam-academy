<?php
/**
 * /status — track an application by reference + email. On the 2026-11 redesign's
 * shell; the behaviour is assets/js/status.js, moved out of this page.
 * The shell (partials/site/shell.php) defines MAM_HTML_RESPONSE, which keeps bootstrap's
 * JSON envelope out of this page; MAM_RESPONSE_SENT is never pre-defined here.
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';

$stStyle = <<<'CSS'
<meta name="robots" content="noindex, follow">
<style>
.st-card{background:#FFFFFF;border:1px solid #E6DED2;border-radius:20px;padding:clamp(24px,3vw,32px);box-sizing:border-box}
.st-eyebrow{margin:0;font-size:13px;font-weight:600;letter-spacing:.16em;text-transform:uppercase;color:#8A5208}
.st-title{margin:8px 0 8px;font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:34px;line-height:1.1;color:#211A13}
.st-sub{margin:0;font-size:15px;line-height:1.6;color:#574B40}
.st-field{margin:16px 0}
.st-field-label{display:block;font-size:14px;font-weight:600;color:#3E342B;margin-bottom:8px}
.st-field input{width:100%;box-sizing:border-box;min-height:48px;padding:0 16px;border-radius:12px;border:1px solid #CFC3B3;background:#FFFFFF;color:#211A13;font:inherit;font-size:16px}
.st-field input:focus{outline:3px solid #2563EB;outline-offset:1px;border-color:#211A13}
.st-submit{width:100%;min-height:52px;margin-top:8px;border:0;border-radius:999px;background:#F2A93B;color:#211A13;font:inherit;font-size:16px;font-weight:600;cursor:pointer}
.st-submit[disabled]{opacity:.6;cursor:default}
.st-quick-links{display:flex;flex-wrap:wrap;gap:8px 20px;margin-top:20px;padding-top:16px;border-top:1px solid #E6DED2}
.st-quick-link{display:inline-flex;align-items:center;min-height:44px;font-size:15px;font-weight:600;color:#8A5208;text-decoration:none}
.st-tuition-banner{display:none;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px 24px;padding:16px 20px;border-radius:16px;background:#F3EEE6;border:1px solid #E6DED2}
.st-tuition-banner.show{display:flex}
.st-tuition-banner p{margin:0;font-size:16px;color:#211A13}
.st-tuition-banner a{display:inline-flex;align-items:center;min-height:44px;padding:0 20px;border-radius:999px;background:#211A13;color:#FAF8F4;font-weight:600;text-decoration:none}
.st-result{display:none}
.st-result.visible{display:block}
.st-result-card{background:#FFFFFF;border:1px solid #E6DED2;border-radius:20px;overflow:hidden}
.st-stripe{height:6px;background:#F2A93B}
.stage-closed .st-stripe,.stage-expired .st-stripe{background:#CFC3B3}
.stage-waitlisted .st-stripe{background:#C27A12}
.st-result-header{display:flex;align-items:center;gap:16px;padding:24px clamp(20px,3vw,32px)}
.st-result-photo,.st-result-photo-placeholder{width:64px;height:64px;border-radius:999px;flex:none;object-fit:cover}
.st-result-photo-placeholder{display:flex;align-items:center;justify-content:center;background:#F3EEE6;font-family:'Cormorant Garamond',Georgia,serif;font-size:28px;color:#8A5208}
.st-result-head-text{min-width:0}
.st-result-stage-label{font-size:13px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#8A5208}
.st-result-name{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:30px;line-height:1.1;color:#211A13}
.st-result-prog{display:inline-block;margin-top:6px;font-size:14px;color:#574B40}
.st-rail{display:flex;gap:0;padding:0 clamp(20px,3vw,32px) 20px}
.st-rail-step{position:relative;flex:1;display:flex;flex-direction:column;align-items:center;gap:8px;font-size:13px;color:#574B40;text-align:center}
.st-rail-line{position:absolute;top:16px;right:50%;width:100%;height:2px;background:#E6DED2;z-index:0}
.st-rail-line.done{background:#C27A12}
.st-rail-dot{position:relative;z-index:1;width:32px;height:32px;border-radius:999px;display:flex;align-items:center;justify-content:center;background:#FFFFFF;border:2px solid #E6DED2;font-size:14px;font-weight:600;color:#574B40}
.st-rail-step.done .st-rail-dot{background:#C27A12;border-color:#C27A12;color:#FFFFFF}
.st-rail-step.active .st-rail-dot{border-color:#211A13;color:#211A13}
.st-rail-step.active{color:#211A13;font-weight:600}
.st-dates{margin:0 clamp(20px,3vw,32px) 16px;padding:12px 16px;border-radius:12px;background:#F3EEE6;font-size:15px;color:#3E342B}
.st-next{margin:0 clamp(20px,3vw,32px) 16px;padding:16px;border-radius:12px;border:1px solid #E6DED2}
.st-next-label{font-size:13px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#8A5208;margin-bottom:6px}
.st-next-text{font-size:16px;line-height:1.6;color:#211A13}
.st-meta{display:flex;flex-wrap:wrap;gap:8px;padding:16px clamp(20px,3vw,32px) 24px;border-top:1px solid #E6DED2}
.st-meta-chip{display:inline-flex;gap:6px;padding:6px 12px;border-radius:999px;background:#F3EEE6;font-size:14px;color:#574B40}
.st-meta-chip strong{color:#211A13}
.st-not-found{padding:24px;border-radius:20px;border:2px solid #B0361C;background:#FFFFFF;color:#211A13;font-size:16px;line-height:1.6}
.st-not-found p{margin:0 0 8px}
.st-not-found a{color:#8A5208}
</style>
CSS;

site_head([
    'title' => 'Track your application — MAM Academy',
    'description' => 'Enter your reference number and email to see where your MAM Academy application stands.',
    'path' => '/status',
    'jsonld' => $stStyle,
]);
site_header('inner');
?>
<main id="main">
  <section aria-labelledby="page-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(40px, 5vw, 72px) clamp(20px, 4vw, 40px) clamp(32px, 4vw, 56px); box-sizing: border-box; display: flex; flex-direction: column; gap: 24px">
    <nav aria-label="Breadcrumb" class="fade-in" style="font-size: 14px; color: #574B40; display: flex; gap: 8px"><a href="/" class="ul" style="color: #574B40; text-decoration: none">Home</a><span aria-hidden="true">/</span><span aria-current="page">Application status</span></nav>
    <h1 id="page-h" class="fade-in" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(46px, 5.6vw, 84px); line-height: 1.02; letter-spacing: -.01em">Track your application</h1>
    <p class="fade-in" style="margin: 0; max-width: 640px; font-size: 19px; line-height: 1.7; color: #3E342B">Enter your reference number and email to see exactly where your application stands.</p>
  </section>

  <section style="max-width: 1200px; margin: 0 auto; padding: 0 clamp(20px, 4vw, 40px) clamp(64px, 8vw, 120px); box-sizing: border-box">
    <div style="max-width: 680px; display: flex; flex-direction: column; gap: 16px">
      <div class="st-card">
        <p class="st-eyebrow">Application lookup</p>
        <h2 class="st-title">Check your status</h2>
        <p class="st-sub">Your reference is in your confirmation email. Regular applications start with <strong>MAM-</strong>, summer applications with <strong>SUM-</strong>, and MAM Scholars Initiative applications with <strong>SCH-</strong>. For a child's application, use the parent or guardian's email.</p>
        <form id="stForm" novalidate>
          <div class="st-field">
            <label class="st-field-label" for="stRef">Application reference</label>
            <input id="stRef" type="text" autocomplete="off" placeholder="MAM-… or SUM-…" spellcheck="false" required>
          </div>
          <div class="st-field">
            <label class="st-field-label" for="stEmail">Email you applied with</label>
            <input id="stEmail" type="email" autocomplete="email" placeholder="you@example.com" required>
          </div>
          <button class="st-submit" id="stGo" type="submit">Check status</button>
        </form>
        <div class="st-quick-links">
          <a href="/apply" class="st-quick-link">Apply to MAM</a>
          <a href="/summer" class="st-quick-link">Summer programme</a>
          <a href="/auditer#checklist" class="st-quick-link">Applicant checklist</a>
        </div>
      </div>

      <div class="st-tuition-banner" id="stTuitionBanner">
        <p>You’ve been accepted. Complete your enrolment by paying your tuition.</p>
        <a href="/tuition" id="stTuitionLink">Pay tuition →</a>
      </div>

      <div class="st-result" id="stResult" aria-live="polite"></div>
    </div>
  </section>
</main>
<?php site_footer(['status.js']); ?>
