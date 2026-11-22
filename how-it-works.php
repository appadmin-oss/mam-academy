<?php
/**
 * How it works — the seven steps from first visit to first class, on the 2026-11
 * redesign shell (partials/site/). The handoff did not draw this page; it is built
 * from the handoff's parts (breadcrumb, serif H1, hairline-ruled rows, the dark
 * closing panel). The HowTo structured data is built from the same list the page
 * prints, so the two cannot drift.
 *
 * Two sentences follow what the site does now rather than what it did: /apply
 * asks one question per screen (it is no longer "five steps"), and the Learning
 * Advisor is gone with the retired wizard, so step 1 no longer mentions it.
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';

$hwA = 'class="ul" style="color: #8A5208"';
$hwSteps = [
    ['Discover', 'Find your path', 'Discover your path',
     'Not sure where to begin? Explore the <a href="/pathways" ' . $hwA . '>pathways</a> or <a href="/compare" ' . $hwA . '>compare them side by side</a>.', ''],
    ['Apply', 'Apply in minutes', 'Apply in minutes',
     'Complete the <a href="/apply" ' . $hwA . '>guided application</a>, one question at a time. It auto-saves on your device, so you can pause and return anytime. A one-time booking fee reserves your seat — tuition itself comes later, after your assessment.', 'About 5–10 minutes'],
    ['Assess', 'Meet the MAM Auditer', 'Meet the MAM Auditer',
     'The <a href="/auditer" ' . $hwA . '>MAM Auditer</a> is our structured entry assessment — it reads your musical potential across several dimensions so we place you in the right pathway and level from day one. You’re assessed, never randomly slotted. Come prepared with the <a href="/auditer#checklist" ' . $hwA . '>Applicant Checklist</a>, and <a href="/book" ' . $hwA . '>book your session</a> when you’re ready.', ''],
    ['Placement', 'Get placed & see your offer', 'Get placed and receive your offer',
     'We confirm your recommended pathway and starting level. <a href="/tuition" ' . $hwA . '>Tuition</a> is discussed after the Auditer and is flexible — you’ll always know the terms before you commit. Applying for support? Scholarship candidates are reviewed on their own track.', ''],
    ['Enrol', 'Enrol & join the community', 'Enrol',
     'Confirm your place and you’re in. You’ll be welcomed into the MAM community and set up with everything you need before your first lesson.', ''],
    ['Your portal', 'Open your student portal', 'Open your student portal',
     'Your <a href="/portal/login" ' . $hwA . '>student portal</a> is home base: class schedule, gradebook, progress, and the free <a href="/studio" ' . $hwA . '>Practice Studio</a> (metronome, tuner, rhythm &amp; pitch trainers) to sharpen your skills between lessons.', ''],
    ['Learn', 'Start learning — online or onsite', 'Start learning',
     'Begin weekly lessons from anywhere online, or in person at MAM Centre, Lagos. Each term ends in a student showcase — because stage experience is part of becoming a complete musician.', ''],
];

$hwLd = ['@context' => 'https://schema.org', '@type' => 'HowTo', 'name' => 'How to enrol at MAM Academy',
    'description' => 'From first visit to first class at MAM Academy.',
    'step' => array_map(static fn($s, $i) => ['@type' => 'HowToStep', 'position' => $i + 1, 'name' => $s[2],
        'text' => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($s[3]), ENT_QUOTES, 'UTF-8')))], $hwSteps, array_keys($hwSteps))];

site_head([
    'title' => 'How it works — MAM Academy',
    'description' => 'Your MAM Academy journey, step by step: discover your path, apply in minutes, meet the MAM Auditer, get placed accurately, enrol, and start learning online or onsite.',
    'path' => '/how-it-works',
    'jsonld' => '<script type="application/ld+json">' . json_encode($hwLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>',
]);
site_header('inner');
ob_start();
?>
<style>
  /* The step text keeps the shared reading measure (test_design_system: a journey step). */
  .hiw-card p { max-width: var(--measure); }
</style>
<main id="main">
<?php
site_doc_open('How it works', 'From first visit to first class',
    'There’s a place for you here — here’s exactly how you get from curious to enrolled, with no guesswork.');
?>
    <section aria-labelledby="steps-h" style="max-width: 1200px; margin: 0 auto; padding: 0 clamp(20px, 4vw, 40px) clamp(48px, 6vw, 88px); box-sizing: border-box">
      <h2 id="steps-h" class="sr">The seven steps</h2>
      <p class="read" style="margin: 0 0 clamp(28px, 3vw, 44px); max-width: 760px; font-size: 18px; line-height: 1.7; color: #3E342B">Applying to MAM isn’t a test you can fail — it’s how we get to know you so we can place you accurately and set you up to thrive. Every step below tells you what happens and what comes next.</p>
      <ol style="list-style: none; margin: 0; padding: 0; border-top: 1px solid #E6DED2">
<?php foreach ($hwSteps as $i => [$eyebrow, $title, , $body, $time]): ?>
        <li style="display: flex; flex-wrap: wrap; gap: 12px clamp(24px, 4vw, 64px); padding: clamp(24px, 3vw, 36px) 0; border-bottom: 1px solid #E6DED2">
          <span aria-hidden="true" style="flex: 0 0 auto; width: 64px; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(40px, 4vw, 56px); line-height: 1; color: #C27A12"><?= $i + 1 ?></span>
          <div class="hiw-card" style="flex: 1 1 420px; min-width: 0; display: flex; flex-direction: column; gap: 8px">
            <p style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208"><span class="sr">Step <?= $i + 1 ?>: </span><?= site_h($eyebrow) ?></p>
            <h3 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 28px; line-height: 1.1"><?= site_h($title) ?></h3>
            <p style="margin: 0; font-size: 17px; line-height: 1.7; color: #3E342B"><?= $body ?></p>
<?php if ($time !== ''): ?>
            <p style="margin: 4px 0 0"><span style="display: inline-flex; align-items: center; padding: 6px 14px; border-radius: 999px; border: 1px solid #D6CCBE; font-size: 14px; color: #3E342B"><?= site_h($time) ?></span></p>
<?php endif; ?>
          </div>
        </li>
<?php endforeach; ?>
      </ol>
      <p style="margin: clamp(28px, 3vw, 44px) 0 0; max-width: 860px; padding: 20px 24px; border-radius: 16px; background: #F3EEE6; font-size: 17px; line-height: 1.7; color: #3E342B"><strong style="color: #211A13">No surprises, ever.</strong> Recommendations are a starting point, not a promise — your MAM Auditer fine-tunes your exact placement, prices and terms are shown before you pay, and you can change your selections right up until checkout.</p>
    </section>

    <section aria-labelledby="hw-cta-h" style="padding: 0 clamp(8px, 1vw, 14px) clamp(8px, 1vw, 14px)">
      <div style="max-width: 1440px; margin: 0 auto; border-radius: clamp(14px, 1.6vw, 22px); background: radial-gradient(120% 140% at 80% 0%, #5A3815 0%, #24160B 45%, #140D08 100%); color: #FCF8F3; padding: clamp(56px, 7vw, 112px) clamp(20px, 4vw, 40px); box-sizing: border-box; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 20px">
        <h2 id="hw-cta-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(34px, 4.17vw, 60px); line-height: 1.05">Ready to <em style="font-style: italic; color: #F2A93B">begin?</em></h2>
        <div style="display: flex; flex-wrap: wrap; justify-content: center; gap: 12px">
          <a href="/apply" class="btn arrow" style="display: inline-flex; align-items: center; gap: 8px; min-height: 52px; padding: 0 26px; border-radius: 999px; background: #F2A93B; color: #211A13; font-weight: 600; text-decoration: none">Begin your application <span aria-hidden="true">→</span></a>
          <a href="/auditer" class="btn" style="display: inline-flex; align-items: center; gap: 8px; min-height: 52px; padding: 0 26px; border-radius: 999px; border: 1px solid rgba(252,248,243,.6); color: #FCF8F3; font-weight: 600; text-decoration: none">What is the MAM Auditer?</a>
        </div>
        <a href="/faq" class="ul" style="display: inline-flex; align-items: center; min-height: 44px; color: #D8CEC1; text-decoration: none">Read the questions &amp; answers</a>
      </div>
    </section>
</main>
<?php
echo (string) ob_get_clean();
site_footer();
