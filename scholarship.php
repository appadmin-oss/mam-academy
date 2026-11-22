<?php
/**
 * MAM Scholars Initiative — the opt-in scholarship application, on the 2026-11
 * redesign shell (partials/site/). The handoff did not draw this page; it is
 * built from the handoff's own parts (breadcrumb + serif H1, hairline sections,
 * the white form card the contact page uses).
 *
 * The form posts the same fields it always did to the CSRF-gated
 * POST /api/scholarship/apply (assets/js/scholarship.js — the page loads neither
 * config.js nor main.js). The requirements are printed from
 * mam_scholarship_requirements() rather than a second copy in the browser, and
 * the select options from the server's own allow-lists.
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';

$scReqs = mam_scholarship_requirements();
$scAges = ['under-16' => 'Under 16', '16-20' => '16–20', '21-26' => '21–26', '27-plus' => '27 & above'];
$scExp  = ['none' => 'Complete beginner', 'some' => 'Some experience', 'intermediate' => 'Intermediate', 'advanced' => 'Advanced'];
$scLabel = 'display: block; margin-bottom: 8px; font-size: 15px; font-weight: 600; color: #211A13';
$scHint  = 'display: block; margin-top: 2px; font-size: 14px; font-weight: 400; color: #574B40';
$scInput = 'box-sizing: border-box; width: 100%; min-height: 52px; padding: 12px 16px; border-radius: 12px; border: 1px solid #CFC3B3; background: #FFFFFF; font: inherit; font-size: 16px; color: #211A13';
$scErr   = 'margin: 6px 0 0; font-size: 14px; color: #B0361C';
$scStar  = '<span aria-hidden="true" style="color: #B0361C">*</span>';
$scEssays = [
    ['sch-fin', 'financial_need', 'Financial need', 'Help us understand your circumstances — in confidence.'],
    ['sch-journey', 'musical_journey', 'Your musical journey', 'Where you started, where you are, and where you dream of going.'],
    ['sch-values', 'values_alignment', 'Values & community', 'Tell us about a time you served your community through music.'],
    ['sch-impact', 'community_impact', 'Community impact', 'How will you multiply this investment in others?'],
];

site_head([
    'title' => 'MAM Scholars Initiative — Scholarship Application | MAM Academy',
    'description' => 'The MAM Scholars Initiative invests in exceptional musical potential and outstanding character. Apply to be considered — a values-driven, community-minded scholarship at MAM Academy.',
    'path' => '/scholarship',
]);
site_header('inner', '/programmes');
ob_start();
?>
<main id="main">
    <section aria-labelledby="page-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(40px, 5vw, 72px) clamp(20px, 4vw, 40px) clamp(32px, 4vw, 56px); box-sizing: border-box; display: flex; flex-direction: column; gap: 24px">
      <nav aria-label="Breadcrumb" class="fade-in" style="font-size: 14px; color: #574B40; display: flex; gap: 8px; flex-wrap: wrap"><a href="/" class="ul" style="color: #574B40; text-decoration: none">Home</a><span aria-hidden="true">/</span><span aria-current="page">MAM Scholars Initiative</span></nav>
      <p class="fade-in" style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">MAM Scholars Initiative</p>
      <h1 id="page-h" aria-label="We invest in potential and character" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(46px, 5.6vw, 84px); line-height: 1.02; letter-spacing: -.01em"><span class="mask" aria-hidden="true"><span class="w" style="animation-delay: 0.15s;">We</span></span> <span class="mask" aria-hidden="true"><span class="w" style="animation-delay: 0.235s;">invest</span></span> <span class="mask" aria-hidden="true"><span class="w" style="animation-delay: 0.32s;">in</span></span> <span class="mask" aria-hidden="true"><span class="w" style="animation-delay: 0.405s; font-style: italic; color: #8A5208">potential</span></span> <span class="mask" aria-hidden="true"><span class="w" style="animation-delay: 0.49s;">&amp;</span></span> <span class="mask" aria-hidden="true"><span class="w" style="animation-delay: 0.575s; font-style: italic; color: #8A5208">character</span></span></h1>
      <p class="fade-in" style="animation-delay: .3s; margin: 0; max-width: 760px; font-size: 19px; line-height: 1.7; color: #3E342B">Not charity — an investment. We entrust talented, values-driven musicians with a place at MAM, and trust them to multiply it in their communities.</p>
    </section>

    <section aria-labelledby="look-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(40px, 5vw, 72px) clamp(20px, 4vw, 40px); box-sizing: border-box; border-top: 1px solid #E6DED2; display: flex; flex-wrap: wrap; gap: 24px clamp(40px, 6vw, 96px)">
      <h2 id="look-h" style="flex: 1 1 280px; margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(32px, 3vw, 44px); line-height: 1.1">Who we look for</h2>
      <div class="doc" style="flex: 2 1 480px; min-width: 0; display: flex; flex-direction: column; gap: 16px; font-size: 18px; line-height: 1.7; color: #3E342B">
        <p>The MAM Scholars Initiative is a limited, mission-driven scholarship — <strong>earned, not given</strong>. Admission and the Scholars Initiative are separate: every application is reviewed holistically by a committee, and your MAM Auditer helps inform (never decides) the outcome.</p>
        <ul>
<?php foreach ($scReqs as $r): ?>
          <li><?= site_h($r) ?></li>
<?php endforeach; ?>
        </ul>
        <p style="padding: 14px 16px; border-radius: 12px; background: #F3EEE6; font-size: 16px; line-height: 1.6">Meeting these does not guarantee a scholarship, and not meeting one perfectly won’t rule you out — every application is reviewed holistically.</p>
      </div>
    </section>

    <section aria-labelledby="form-h" style="max-width: 1200px; margin: 0 auto; padding: 0 clamp(20px, 4vw, 40px) clamp(64px, 8vw, 120px); box-sizing: border-box">
      <div id="schGrid" style="max-width: 860px; margin: 0 auto; padding: clamp(24px, 3vw, 40px); border-radius: 20px; background: #FFFFFF; border: 1px solid #E6DED2; box-sizing: border-box">
        <h2 id="form-h" style="margin: 0 0 8px; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(32px, 3vw, 44px); line-height: 1.1">Your application</h2>
        <p style="margin: 0 0 24px; font-size: 16px; line-height: 1.6; color: #574B40">Fields marked <?= $scStar ?> are required. A sentence or two for each question is enough.</p>
        <form id="schForm" novalidate style="display: flex; flex-direction: column; gap: 20px">
          <div style="display: flex; flex-wrap: wrap; gap: 20px">
            <div style="flex: 1 1 220px; min-width: 0"><label for="sch-name" style="<?= $scLabel ?>">Full name <?= $scStar ?></label><input id="sch-name" name="full_name" maxlength="160" autocomplete="name" required aria-describedby="sch-name-err" style="<?= $scInput ?>"><p id="sch-name-err" hidden style="<?= $scErr ?>"></p></div>
            <div style="flex: 1 1 220px; min-width: 0"><label for="sch-email" style="<?= $scLabel ?>">Email <?= $scStar ?></label><input id="sch-email" name="email" type="email" maxlength="190" autocomplete="email" inputmode="email" required aria-describedby="sch-email-err" style="<?= $scInput ?>"><p id="sch-email-err" hidden style="<?= $scErr ?>"></p></div>
          </div>
          <div style="display: flex; flex-wrap: wrap; gap: 20px">
            <div style="flex: 1 1 220px; min-width: 0"><label for="sch-age" style="<?= $scLabel ?>">Age</label><select id="sch-age" name="age_band" style="<?= $scInput ?>"><option value="">Prefer not to say</option>
<?php foreach ($scAges as $k => $v): ?>
              <option value="<?= site_h($k) ?>"><?= site_h($v) ?></option>
<?php endforeach; ?>
            </select></div>
            <div style="flex: 1 1 220px; min-width: 0"><label for="sch-exp" style="<?= $scLabel ?>">Musical experience</label><select id="sch-exp" name="experience" style="<?= $scInput ?>"><option value="">Select…</option>
<?php foreach ($scExp as $k => $v): ?>
              <option value="<?= site_h($k) ?>"><?= site_h($v) ?></option>
<?php endforeach; ?>
            </select></div>
          </div>
<?php foreach ($scEssays as [$id, $name, $label, $hint]): ?>
          <div><label for="<?= $id ?>" style="<?= $scLabel ?>"><?= site_h($label) ?> <?= $scStar ?><span style="<?= $scHint ?>"><?= site_h($hint) ?></span></label><textarea id="<?= $id ?>" name="<?= $name ?>" maxlength="2000" rows="4" required aria-describedby="<?= $id ?>-err" style="<?= $scInput ?>; min-height: 120px; resize: vertical; line-height: 1.55"></textarea><p id="<?= $id ?>-err" hidden style="<?= $scErr ?>"></p></div>
<?php endforeach; ?>
          <div>
            <label id="sch-consent-wrap" style="display: flex; gap: 12px; align-items: flex-start; padding: 16px; border-radius: 12px; border: 1px solid #CFC3B3; background: #FAF8F4; cursor: pointer"><input type="checkbox" id="sch-commit" name="commitment_ack" aria-describedby="sch-commit-err" style="margin-top: 2px; width: 20px; height: 20px; flex: none; accent-color: #211A13"><span style="font-size: 16px; line-height: 1.55; color: #3E342B">I understand this is a limited, earned scholarship, and I commit to attending faithfully, giving my best, and completing the programme. <?= $scStar ?></span></label>
            <p id="sch-commit-err" hidden style="<?= $scErr ?>"></p>
          </div>
          <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 16px">
            <button type="submit" id="sch-submit" class="btn arrow" style="display: inline-flex; align-items: center; gap: 8px; min-height: 52px; padding: 0 26px; border: 0; border-radius: 999px; background: #211A13; color: #FAF8F4; font: inherit; font-weight: 600; cursor: pointer">Submit my application <span aria-hidden="true">→</span></button>
            <p id="sch-feedback" role="alert" style="margin: 0; font-size: 15px; color: #B0361C"></p>
          </div>
          <p style="margin: 0; font-size: 14px; line-height: 1.6; color: #574B40">Already applied to a programme? You can mention your application reference to your reviewer. This is a separate, parallel review.</p>
        </form>
      </div>
    </section>
</main>
<?php
echo (string) ob_get_clean();
site_footer(['scholarship.js']);
