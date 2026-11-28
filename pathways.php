<?php
/**
 * Pathways & tuition — programmes, levels and a live tuition estimator, on the
 * 2026-11 redesign shell (partials/site/). Every price is the server's:
 * site_money()/site_fee() for the printed figures, and window.MAM_SITE.fees (the
 * same functions that charge an application) for the estimator in
 * assets/js/site-pathways.js. The old page typed every figure into the markup.
 * Ids other pages link to are kept: #standard, #certified-musician,
 * #grand-musician, #programmes, #levels, #estimator.
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';

$pwA = 'class="ul" style="color: #8A5208"';
$pwProgs = [
    ['standard', 'Single discipline', 'Standard', 'Focus on one course at your level — add more whenever you like.',
     site_money('from:standard'), 'Pre-Certificate base · before any extras',
     ['One primary course (instrument or music education)', 'Choose an expression: African, Contemporary or Classical', 'Add courses any time at 50% of the base fee'],
     ['No compulsory-courses bundle'], '', false],
    ['certified-musician', 'Full certification', 'Certified', 'Your major plus the four compulsory courses — up to 5 courses in total.',
     site_money('from:certified-musician'), site_money('level0') . ' base + ' . site_money('compulsory') . ' compulsory · Pre-Certificate',
     ['Up to <strong>5 courses</strong> at a time', 'Includes 4 compulsory courses (the ' . site_money('compulsory') . ' fee)', 'One expression: African, Contemporary or Classical', 'Progresses across all four certification levels'],
     [], 'Most popular', true],
    ['grand-musician', 'Flagship', 'Grand Certified', 'The complete musician — all three expressions, up to 7 courses at a time.',
     site_money('from:grand-musician'), '3× the level base, ' . site_fee('grand') . ' off · Pre-Certificate',
     ['Up to <strong>7 courses</strong> at a time', '<strong>All three expressions</strong> — African, Contemporary &amp; Classical', 'All four compulsory courses included', '<strong>' . site_fee('grand') . ' discount</strong> baked into the price'],
     [], 'Save ' . site_fee('grand'), false],
];
$pwLevels = [['1', 'Grade 1–2', 'Pre-Certificate', 'level0'], ['2', 'Grade 3–4', 'Certificate', 'level1'], ['3', 'Grade 5–6', 'Pre-Diploma', 'level2'], ['4', 'Grade 7–8', 'Diploma', 'level3']];

$pwOrg = ['@type' => 'EducationalOrganization', 'name' => 'MAM Academy', 'sameAs' => 'https://academy.mamcentre.org'];
$pwLd = ['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => 'MAM Academy Programmes & Tuition', 'itemListElement' => [
    ['@type' => 'ListItem', 'position' => 1, 'item' => ['@type' => 'Course', 'name' => 'Standard Programme', 'url' => 'https://academy.mamcentre.org/pathways#standard', 'description' => 'One course at your chosen level, with optional additional courses at 50% of the base fee.', 'provider' => $pwOrg]],
    ['@type' => 'ListItem', 'position' => 2, 'item' => ['@type' => 'Course', 'name' => 'Certified Programme', 'url' => 'https://academy.mamcentre.org/pathways#certified-musician', 'description' => 'Up to 5 courses including the four compulsory courses (Rhythm & Pitch, Theory of Music, Performance, Meta Music). One expression: African, Contemporary or Classical.', 'provider' => $pwOrg]],
    ['@type' => 'ListItem', 'position' => 3, 'item' => ['@type' => 'Course', 'name' => 'Grand Certified Programme', 'url' => 'https://academy.mamcentre.org/pathways#grand-musician', 'description' => 'Up to 7 courses including the four compulsory courses, across all expressions.', 'provider' => $pwOrg]],
    ['@type' => 'ListItem', 'position' => 4, 'item' => ['@type' => 'Course', 'name' => 'MAM Crash Programme', 'url' => 'https://academy.mamcentre.org/crash', 'description' => '6-week accelerated intensive — flat fee, no levels.', 'provider' => $pwOrg]],
    ['@type' => 'ListItem', 'position' => 5, 'item' => ['@type' => 'Course', 'name' => 'The MetaMusic Order', 'url' => 'https://academy.mamcentre.org/metamusic-order', 'description' => '12-week post-diploma cultural-architecture intensive.', 'provider' => $pwOrg]],
]];

$pwSeg = 'display: inline-flex; align-items: center; min-height: 44px; padding: 0 18px; border-radius: 999px; border: 1px solid #D6CCBE; background: #FFFFFF; color: #211A13; font: inherit; font-size: 15px; font-weight: 600; cursor: pointer';
$pwSegs = [
    ['calcProg', 'Programme', [['standard', 'Standard'], ['certified-musician', 'Certified', true], ['grand-musician', 'Grand'], ['crash', 'Crash']]],
    ['calcLevel', 'Level', [['precert', 'Pre-Cert', true], ['cert', 'Certificate'], ['prediploma', 'Pre-Diploma'], ['diploma', 'Diploma']]],
    ['calcMode', 'Mode of study', [['onsite', 'Onsite', true], ['online', 'Online']]],
];

site_head([
    'title' => 'Pathways & tuition — MAM Academy',
    'description' => 'Choose a programme (Standard, Certified or Grand), a level (Pre-Certificate to Diploma) and your courses — then see your estimated tuition with the live estimator.',
    'path' => '/pathways',
    'jsonld' => '<script type="application/ld+json">' . json_encode($pwLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>',
]);
site_header('inner', '/programmes');
ob_start();
?>
<style>
  .pw-seg button[aria-pressed="true"] { background: #211A13 !important; border-color: #211A13 !important; color: #FAF8F4 !important; }
  .pw-off { opacity: .45; pointer-events: none; }
</style>
<main id="main">
<?php
site_doc_open('Pathways', 'Find your path & know your fee',
    'Pick a programme, a level and your courses — and see your estimated tuition instantly. Transparent pricing, four certification levels, online or onsite, with scholarships for those who need them.');
?>
    <section aria-label="At a glance" style="max-width: 1200px; margin: 0 auto; padding: 0 clamp(20px, 4vw, 40px) clamp(32px, 4vw, 56px); box-sizing: border-box; display: flex; flex-wrap: wrap; align-items: center; gap: 12px">
<?php foreach (['<strong>4</strong> certification levels', '<strong>3</strong> programmes + 2 intensives', 'Online <strong>or</strong> onsite', 'Scholarships available'] as $s): ?>
      <span style="display: inline-block; padding: 8px 16px; border-radius: 999px; border: 1px solid #D6CCBE; font-size: 15px; color: #3E342B"><?= $s ?></span>
<?php endforeach; ?>
      <a href="#estimator" class="btn" style="display: inline-flex; align-items: center; min-height: 48px; padding: 0 24px; border-radius: 999px; background: #F2A93B; color: #211A13; font-weight: 600; text-decoration: none">Estimate my tuition</a>
      <a href="/auditer" class="ul" style="display: inline-flex; align-items: center; min-height: 44px; color: #211A13; font-weight: 600; text-decoration: none">Book MAM Auditer first</a>
    </section>

    <section id="programmes" aria-labelledby="programmes-h" style="background: #F3EEE6">
      <div style="max-width: 1200px; margin: 0 auto; padding: clamp(64px, 8vw, 120px) clamp(20px, 4vw, 40px); box-sizing: border-box; display: flex; flex-direction: column; gap: clamp(28px, 3vw, 44px)">
        <div style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px 40px">
          <div style="flex: 1 1 520px; max-width: 760px; display: flex; flex-direction: column; gap: 16px">
            <p class="reveal" style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">Choose a programme</p>
            <h2 id="programmes-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(34px, 4.17vw, 60px); line-height: 1.05">Three ways to study</h2>
            <p class="read" style="margin: 0; font-size: 18px; line-height: 1.7; color: #3E342B">Every programme runs across all four levels, and your level sets the base fee. <strong>Certified</strong> adds a flat <strong><?= site_money('compulsory') ?></strong> for its four compulsory courses; <strong>Grand</strong> covers all three expressions at 3× the base with <strong><?= site_fee('grand') ?> off</strong>. Prices below start at the Pre-Certificate level — use the estimator for your exact figure.</p>
          </div>
          <span class="cur-sw cur-sw--light" role="group" aria-label="Show prices in"><?php foreach (['USD' => '$ USD', 'NGN' => '₦ NGN', 'GBP' => '£ GBP'] as $c => $t): ?><button type="button" data-currency="<?= $c ?>" aria-pressed="<?= $c === 'USD' ? 'true' : 'false' ?>"><?= $t ?></button><?php endforeach; ?></span>
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(300px, 100%), 1fr)); gap: 16px">
<?php foreach ($pwProgs as [$id, $eyebrow, $name, $tag, $from, $note, $feats, $nots, $badge, $featured]): ?>
          <article id="<?= $id ?>" class="lift" style="position: relative; display: flex; flex-direction: column; gap: 14px; padding: 28px; border-radius: 20px; background: <?= $featured ? '#211A13' : '#FFFFFF' ?>; color: <?= $featured ? '#FCF8F3' : '#211A13' ?>; border: 1px solid <?= $featured ? '#211A13' : '#E6DED2' ?>">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px">
              <span style="font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: <?= $featured ? '#F2A93B' : '#8A5208' ?>"><?= site_h($eyebrow) ?></span>
<?php if ($badge !== ''): ?>
              <span style="padding: 4px 12px; border-radius: 999px; background: #F2A93B; color: #211A13; font-size: 13px; font-weight: 600"><?= site_h($badge) ?></span>
<?php endif; ?>
            </div>
            <h3 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 32px; line-height: 1.05"><?= site_h($name) ?></h3>
            <p style="margin: 0; font-size: 16px; line-height: 1.6; color: <?= $featured ? '#D8CEC1' : '#3E342B' ?>"><?= site_h($tag) ?></p>
            <p style="margin: 4px 0 0; display: flex; align-items: baseline; gap: 8px"><span style="font-size: 14px; color: <?= $featured ? '#D8CEC1' : '#574B40' ?>">from</span><span style="font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 44px; line-height: 1"><?= $from ?></span></p>
            <p style="margin: 0; font-size: 14px; color: <?= $featured ? '#D8CEC1' : '#574B40' ?>"><?= $note ?></p>
            <ul style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; font-size: 15px; line-height: 1.55">
<?php foreach ($feats as $f): ?>
              <li style="display: flex; gap: 10px"><span aria-hidden="true" style="color: <?= $featured ? '#F2A93B' : '#C27A12' ?>">✓</span><span><?= $f ?></span></li>
<?php endforeach; foreach ($nots as $f): ?>
              <li style="display: flex; gap: 10px; color: <?= $featured ? '#D8CEC1' : '#574B40' ?>"><span aria-hidden="true">✕</span><span><span class="sr">Not included: </span><?= $f ?></span></li>
<?php endforeach; ?>
            </ul>
            <a href="/apply?pathway=<?= $id ?>" class="btn" style="margin-top: auto; display: inline-flex; justify-content: center; align-items: center; min-height: 48px; padding: 0 24px; border-radius: 999px; <?= $featured ? 'background: #F2A93B; color: #211A13' : 'border: 1px solid #CFC3B3; color: #211A13' ?>; font-weight: 600; text-decoration: none">Apply — <?= site_h($name === 'Grand Certified' ? 'Grand' : $name) ?></a>
          </article>
<?php endforeach; ?>
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(300px, 100%), 1fr)); gap: 16px">
          <a href="/crash" class="lift" style="display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 22px 26px; border-radius: 16px; background: #FFFFFF; border: 1px solid #E6DED2; color: #211A13; text-decoration: none"><span style="display: flex; flex-direction: column; gap: 4px"><span style="font-weight: 600; font-size: 17px">Crash Programme</span><span style="font-size: 14px; color: #574B40">6-week intensive · flat fee · no levels</span></span><span style="font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 28px"><?= site_money('crash') ?></span></a>
          <a href="/metamusic-order" class="lift" style="display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 22px 26px; border-radius: 16px; background: #FFFFFF; border: 1px solid #E6DED2; color: #211A13; text-decoration: none"><span style="display: flex; flex-direction: column; gap: 4px"><span style="font-weight: 600; font-size: 17px">The MetaMusic Order</span><span style="font-size: 14px; color: #574B40">12-week post-diploma intensive</span></span><span style="font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 28px"><?= site_money('meta') ?></span></a>
        </div>
      </div>
    </section>

    <section id="levels" aria-labelledby="levels-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(64px, 8vw, 120px) clamp(20px, 4vw, 40px); box-sizing: border-box; display: flex; flex-direction: column; gap: clamp(28px, 3vw, 44px)">
      <div style="max-width: 760px; display: flex; flex-direction: column; gap: 16px">
        <p class="reveal" style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">Choose a level</p>
        <h2 id="levels-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(34px, 4.17vw, 60px); line-height: 1.05">One continuous journey</h2>
        <p class="read" style="margin: 0; font-size: 18px; line-height: 1.7; color: #3E342B">You don’t buy a level — you progress through all four. The level sets your <strong>base tuition</strong>. Your starting point is placed by the <a href="/auditer" <?= $pwA ?>>MAM Auditer</a> assessment.</p>
      </div>
      <ol class="stagger" style="list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr)); gap: 16px">
<?php foreach ($pwLevels as [$n, $grade, $name, $key]): ?>
        <li style="display: flex; flex-direction: column; gap: 8px; padding: 24px; border-radius: 16px; background: #FFFFFF; border: 1px solid #E6DED2">
          <span aria-hidden="true" style="font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: 44px; line-height: 1; color: #C27A12"><?= $n ?></span>
          <span style="font-size: 14px; color: #574B40"><?= site_h($grade) ?></span>
          <span style="font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 28px; line-height: 1.1"><?= site_h($name) ?></span>
          <span style="font-size: 16px; color: #3E342B"><strong><?= site_money($key) ?></strong> base</span>
        </li>
<?php endforeach; ?>
      </ol>
    </section>

    <section id="estimator" aria-labelledby="estimator-h" style="background: #F3EEE6">
      <div style="max-width: 1200px; margin: 0 auto; padding: clamp(64px, 8vw, 120px) clamp(20px, 4vw, 40px); box-sizing: border-box; display: flex; flex-direction: column; gap: clamp(28px, 3vw, 44px)">
        <div style="max-width: 760px; display: flex; flex-direction: column; gap: 16px">
          <p class="reveal" style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">Estimate your tuition</p>
          <h2 id="estimator-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(34px, 4.17vw, 60px); line-height: 1.05">Build it, see the price</h2>
          <p class="read" style="margin: 0; font-size: 18px; line-height: 1.7; color: #3E342B">Pick your options and your estimated tuition updates instantly. The exact figure is confirmed at checkout.</p>
        </div>
        <div id="calc" style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-start">
          <div style="flex: 2 1 480px; min-width: 0; display: flex; flex-direction: column; gap: 22px; padding: 28px; border-radius: 20px; background: #FFFFFF; border: 1px solid #E6DED2">
<?php foreach ($pwSegs as [$id, $label, $opts]): ?>
            <div id="<?= $id ?>Field" style="display: flex; flex-direction: column; gap: 10px">
              <span id="<?= $id ?>-l" style="font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #574B40"><?= site_h($label) ?></span>
              <div id="<?= $id ?>" class="pw-seg" role="group" aria-labelledby="<?= $id ?>-l" style="display: flex; flex-wrap: wrap; gap: 8px">
<?php foreach ($opts as $o): ?>
                <button type="button" data-v="<?= $o[0] ?>" aria-pressed="<?= !empty($o[2]) ? 'true' : 'false' ?>" style="<?= $pwSeg ?>"><?= site_h($o[1]) ?></button>
<?php endforeach; ?>
              </div>
            </div>
<?php endforeach; ?>
            <div id="calcExtraField" style="display: flex; flex-direction: column; gap: 10px">
              <span style="font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #574B40">Additional courses <span style="text-transform: none; letter-spacing: 0; font-weight: 400">— 50% of base each</span></span>
              <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px">
                <span id="calcExtraHint" style="font-size: 15px; color: #3E342B">Beyond your primary course</span>
                <span style="display: inline-flex; align-items: center; gap: 4px">
                  <button type="button" id="calcMinus" aria-label="Fewer additional courses" style="<?= $pwSeg ?>; width: 44px; justify-content: center; padding: 0">−</button>
                  <output id="calcExtraVal" aria-live="polite" style="min-width: 36px; text-align: center; font-weight: 600; font-size: 17px">0</output>
                  <button type="button" id="calcPlus" aria-label="More additional courses" style="<?= $pwSeg ?>; width: 44px; justify-content: center; padding: 0">+</button>
                </span>
              </div>
            </div>
            <label style="display: flex; align-items: center; gap: 10px; min-height: 44px; font-size: 16px; color: #3E342B; cursor: pointer"><input type="checkbox" id="calcReg" checked style="width: 20px; height: 20px; accent-color: #211A13"> <span>Include one-time registration (<?= site_money('reg') ?>)</span></label>
          </div>
          <aside aria-labelledby="calc-h" style="flex: 1 1 320px; min-width: 0; display: flex; flex-direction: column; gap: 16px; padding: 28px; border-radius: 20px; background: radial-gradient(120% 140% at 80% 0%, #5A3815 0%, #24160B 45%, #140D08 100%); color: #FCF8F3">
            <h3 id="calc-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: 28px">Your estimate</h3>
            <ul id="calcLines" style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; font-size: 15px; color: #E9E1D6"></ul>
            <p style="margin: 0; display: flex; justify-content: space-between; align-items: baseline; gap: 12px; padding-top: 14px; border-top: 1px solid rgba(252,248,243,.2)"><span style="font-size: 15px; color: #D8CEC1">Total</span><output id="calcTotal" aria-live="polite" style="font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 44px; line-height: 1; color: #FCF8F3">—</output></p>
            <p id="calcFine" style="margin: 0; font-size: 14px; line-height: 1.6; color: #D8CEC1">Tuition is confirmed at checkout. Registration covers your student file, ID card &amp; a 30-minute MAM Auditer assessment.</p>
            <a id="calcApply" href="/apply" class="btn arrow" style="display: inline-flex; justify-content: center; align-items: center; gap: 8px; min-height: 52px; padding: 0 24px; border-radius: 999px; background: #F2A93B; color: #211A13; font-weight: 600; text-decoration: none">Apply with this selection <span aria-hidden="true">→</span></a>
            <a href="/auditer" class="ul" style="align-self: center; display: inline-flex; align-items: center; min-height: 44px; color: #D8CEC1; text-decoration: none">Not sure? Book the MAM Auditer</a>
          </aside>
        </div>
      </div>
    </section>

    <section aria-labelledby="pw-sch-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(48px, 6vw, 88px) clamp(20px, 4vw, 40px); box-sizing: border-box; display: flex; flex-wrap: wrap; gap: 24px clamp(40px, 6vw, 96px)">
      <h2 id="pw-sch-h" style="flex: 1 1 280px; margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(32px, 3vw, 44px); line-height: 1.1">Scholarships</h2>
      <div style="flex: 2 1 480px; min-width: 0; display: flex; flex-direction: column; gap: 16px; font-size: 18px; line-height: 1.7; color: #3E342B">
        <p style="margin: 0">Cost should not decide who becomes a musician. The MAM Scholars Initiative supports talented students for whom tuition is the only barrier.</p>
        <a href="/scholarship" class="lrow" style="display: inline-flex; align-items: center; gap: 8px; color: #211A13; font-weight: 600; font-size: 17px; text-decoration: none"><span class="ul">Read about the scholarship and apply</span> <span aria-hidden="true" style="color: #8A5208">→</span></a>
      </div>
    </section>

    <section aria-labelledby="pw-cta-h" style="padding: 0 clamp(8px, 1vw, 14px) clamp(8px, 1vw, 14px)">
      <div style="max-width: 1440px; margin: 0 auto; border-radius: clamp(14px, 1.6vw, 22px); background: radial-gradient(120% 140% at 80% 0%, #5A3815 0%, #24160B 45%, #140D08 100%); color: #FCF8F3; padding: clamp(56px, 7vw, 112px) clamp(20px, 4vw, 40px); box-sizing: border-box; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 20px">
        <h2 id="pw-cta-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(34px, 4.17vw, 60px); line-height: 1.05">Not sure where you <em style="font-style: italic; color: #F2A93B">fit?</em></h2>
        <p style="margin: 0; max-width: 560px; font-size: 18px; line-height: 1.6; color: #D8CEC1">The MAM Auditer is our structured entry assessment. Take it first — we’ll place you on the right level accurately.</p>
        <div style="display: flex; flex-wrap: wrap; justify-content: center; gap: 12px">
          <a href="/auditer" class="btn arrow" style="display: inline-flex; align-items: center; gap: 8px; min-height: 52px; padding: 0 26px; border-radius: 999px; background: #F2A93B; color: #211A13; font-weight: 600; text-decoration: none">Book MAM Auditer <span aria-hidden="true">→</span></a>
          <a href="/apply" class="btn" style="display: inline-flex; align-items: center; min-height: 52px; padding: 0 26px; border-radius: 999px; border: 1px solid rgba(252,248,243,.6); color: #FCF8F3; font-weight: 600; text-decoration: none">Apply now</a>
        </div>
        <a href="/compare" class="ul" style="display: inline-flex; align-items: center; min-height: 44px; color: #D8CEC1; text-decoration: none">Next: compare all programmes side by side</a>
      </div>
    </section>
</main>
<?php
echo (string) ob_get_clean();
site_footer(['site-pathways.js']);
