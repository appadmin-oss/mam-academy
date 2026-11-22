<?php
/**
 * Crash Programmes — the 6-week intensives, on the 2026-11 redesign shell
 * (partials/site/). The handoff did not draw this page; it is built from the
 * handoff's own parts (breadcrumb + serif H1, the sand band of cards, the dark
 * closing panel), the way faculty.php and faq.php are.
 *
 * The price is the server's (site_money('crash') — the same figure /apply
 * charges), never typed. #categories is kept: the hero links to it.
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';

$crPrice = site_money('crash');
$crUsd   = (int) round(site_fee_usd('crash'));
$crFor = [
    ['Beginners', 'Wanting fast exposure'], ['Exam Candidates', 'Preparing for grading'],
    ['Worship Teams', 'Sharper ensemble'], ['Choir Members', 'Better timing & tone'],
    ['Busy Professionals', 'Focused outcomes'], ['Summer Learners', 'School holiday programs'],
    ['Children on Break', 'Structured fun'], ['Event Performers', 'Stage-ready prep'],
];
$crCats = [
    ['1', 'Instrument Crash Courses', 'Focused instrument training', 'piano',
        ['Piano', 'Violin · Guitar', 'Saxophone · Trumpet · Recorder', 'Drums · Voice', 'Music Theory Foundations']],
    ['2', 'Rhythm Intelligence Crash', 'MAM Rapid Rhythm System', 'rhythm',
        ['Sight singing & reading', 'Rhythmic interpretation', 'Timing correction & ear training', 'Beat coordination', 'Ensemble synchronization']],
    ['3', 'Performance Intelligence Crash', 'Stagecraft & psychology', 'performance',
        ['Stage confidence', 'Microphone usage', 'Rehearsal discipline', 'Audience engagement', 'Overcoming stage fear']],
    ['4', 'MetaMusic Crash', 'Philosophy of music', 'metamusic',
        ['Music and emotions', 'African musical intelligence', 'Sound and consciousness', 'Music as communication', 'Artistic identity']],
];
$crOutcomes = ['Measurable improvement', 'Stronger musical confidence', 'Improved timing', 'Better stage presentation', 'Greater artistic understanding'];

$crLd = [
    '@context' => 'https://schema.org', '@type' => 'Course', 'name' => 'MAM Crash Programme',
    'url' => 'https://academy.mamcentre.org/crash',
    'description' => 'A 6-week accelerated music intensive at MAM Academy — one course, one tradition, 1 hour weekly. Instruments, Rhythm Intelligence, Performance Intelligence and MetaMusic. Online or onsite.',
    'provider' => ['@type' => 'EducationalOrganization', 'name' => 'MAM Academy', 'sameAs' => 'https://academy.mamcentre.org/'],
    'offers' => ['@type' => 'Offer', 'price' => (string) $crUsd, 'priceCurrency' => 'USD', 'category' => 'Tuition'],
    'hasCourseInstance' => [
        ['@type' => 'CourseInstance', 'courseMode' => 'onsite', 'courseWorkload' => 'PT1H', 'courseSchedule' => ['@type' => 'Schedule', 'repeatFrequency' => 'P1W', 'duration' => 'P6W']],
        ['@type' => 'CourseInstance', 'courseMode' => 'online', 'courseWorkload' => 'PT1H', 'courseSchedule' => ['@type' => 'Schedule', 'repeatFrequency' => 'P1W', 'duration' => 'P6W']],
    ],
];

site_head([
    'title' => 'MAM Crash Programmes — 6-Week Intensives | ' . site_fee('crash'),
    'description' => '6-week professional intensives for fast-track learning. Instruments, Rhythm Intelligence, Performance Intelligence and MetaMusic. Online or onsite. ' . site_fee('crash') . '.',
    'path' => '/crash',
    'jsonld' => '<script type="application/ld+json">' . json_encode($crLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>',
]);
site_header('inner', '/programmes');
ob_start();
?>
<main id="main">
    <section aria-labelledby="page-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(40px, 5vw, 72px) clamp(20px, 4vw, 40px) clamp(40px, 5vw, 64px); box-sizing: border-box; display: flex; flex-direction: column; gap: 24px">
      <nav aria-label="Breadcrumb" class="fade-in" style="font-size: 14px; color: #574B40; display: flex; gap: 8px; flex-wrap: wrap"><a href="/" class="ul" style="color: #574B40; text-decoration: none">Home</a><span aria-hidden="true">/</span><a href="/programmes" class="ul" style="color: #574B40; text-decoration: none">Programmes</a><span aria-hidden="true">/</span><span aria-current="page">Crash Programmes</span></nav>
      <p class="fade-in" style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">6 weeks · <?= $crPrice ?> · Online or onsite</p>
      <h1 id="page-h" aria-label="MAM Crash Programmes" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(46px, 5.6vw, 84px); line-height: 1.02; letter-spacing: -.01em"><span class="mask" aria-hidden="true"><span class="w" style="animation-delay: 0.15s;">MAM</span></span> <span class="mask" aria-hidden="true"><span class="w" style="animation-delay: 0.235s; font-style: italic; color: #8A5208">Crash</span></span> <span class="mask" aria-hidden="true"><span class="w" style="animation-delay: 0.32s;">Programmes</span></span></h1>
      <p class="fade-in" style="animation-delay: .3s; margin: 0; max-width: 760px; font-size: 19px; line-height: 1.7; color: #3E342B">Intensive 6-week professional courses designed for fast-track learning and rapid skill development. Online or onsite.</p>
      <div class="fade-in" style="animation-delay: .45s; display: flex; flex-wrap: wrap; gap: 12px">
        <a href="/apply?pathway=crash" class="btn arrow" style="display: inline-flex; align-items: center; gap: 8px; min-height: 52px; padding: 0 26px; border-radius: 999px; background: #F2A93B; color: #211A13; font-weight: 600; text-decoration: none">Apply — <?= $crPrice ?> <span aria-hidden="true">→</span></a>
        <a href="#categories" style="display: inline-flex; align-items: center; min-height: 52px; padding: 0 25px; border-radius: 999px; border: 1px solid #CFC3B3; color: #211A13; font-weight: 600; text-decoration: none">Browse categories</a>
      </div>
    </section>

    <section aria-labelledby="for-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(48px, 6vw, 88px) clamp(20px, 4vw, 40px); box-sizing: border-box; border-top: 1px solid #E6DED2; display: flex; flex-wrap: wrap; gap: 32px clamp(40px, 6vw, 96px)">
      <div style="flex: 1 1 280px; display: flex; flex-direction: column; gap: 16px">
        <p class="reveal" style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">Built for impact</p>
        <h2 id="for-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(32px, 3vw, 44px); line-height: 1.1">Who Crash Programmes are for</h2>
        <p class="read" style="margin: 0; font-size: 17px; line-height: 1.7; color: #3E342B">Designed for fast results, not surface exposure.</p>
      </div>
      <dl class="stagger" style="flex: 2 1 480px; min-width: 0; margin: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(min(200px, 100%), 1fr)); gap: 0 24px">
<?php foreach ($crFor as [$who, $why]): ?>
        <div style="padding: 16px 0; border-top: 1px solid #E6DED2"><dt style="font-weight: 600; font-size: 17px; color: #211A13"><?= site_h($who) ?></dt><dd style="margin: 4px 0 0; font-size: 15px; color: #574B40"><?= site_h($why) ?></dd></div>
<?php endforeach; ?>
      </dl>
    </section>

    <section id="categories" aria-labelledby="cat-h" style="background: #F3EEE6">
      <div style="max-width: 1200px; margin: 0 auto; padding: clamp(64px, 8vw, 120px) clamp(20px, 4vw, 40px); box-sizing: border-box; display: flex; flex-direction: column; gap: clamp(28px, 3vw, 44px)">
        <div style="max-width: 760px; display: flex; flex-direction: column; gap: 16px">
          <p class="reveal" style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">Four crash categories</p>
          <h2 id="cat-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(34px, 4.17vw, 60px); line-height: 1.05">Choose your intensive</h2>
        </div>
        <ul class="stagger" style="list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(min(260px, 100%), 1fr)); gap: 16px">
<?php foreach ($crCats as [$n, $name, $tag, $disc, $items]): ?>
          <li class="lift" style="display: flex; flex-direction: column; gap: 12px; padding: 28px; border-radius: 16px; background: #FFFFFF; border: 1px solid #E6DED2">
            <span aria-hidden="true" style="font-family: 'Cormorant Garamond', Georgia, serif; font-size: 28px; color: #8A5208"><?= $n ?></span>
            <h3 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 28px; line-height: 1.1"><?= site_h($name) ?></h3>
            <p style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: #8A5208"><?= site_h($tag) ?></p>
            <ul style="margin: 0; padding-left: 1.1em; display: flex; flex-direction: column; gap: 6px; font-size: 16px; line-height: 1.5; color: #3E342B">
<?php foreach ($items as $it): ?>
              <li><?= site_h($it) ?></li>
<?php endforeach; ?>
            </ul>
            <a href="/apply?pathway=crash&amp;discipline=<?= $disc ?>" class="lrow" style="margin-top: auto; display: inline-flex; align-items: center; gap: 8px; min-height: 44px; font-weight: 600; font-size: 16px; color: #211A13; text-decoration: none"><span class="ul">Apply</span> <span aria-hidden="true" style="color: #8A5208">→</span><span class="sr-xs"> for <?= site_h($name) ?></span></a>
          </li>
<?php endforeach; ?>
        </ul>
      </div>
    </section>

    <section aria-labelledby="why-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(64px, 8vw, 120px) clamp(20px, 4vw, 40px); box-sizing: border-box; display: flex; flex-wrap: wrap; gap: 32px clamp(40px, 6vw, 96px)">
      <div style="flex: 1 1 280px; display: flex; flex-direction: column; gap: 16px">
        <p class="reveal" style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">Why MAM Crash is different</p>
        <h2 id="why-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(32px, 3vw, 44px); line-height: 1.1">Skills that hold — not just hype</h2>
      </div>
      <div style="flex: 2 1 480px; min-width: 0; display: flex; flex-direction: column; gap: 16px; font-size: 18px; line-height: 1.7; color: #3E342B">
        <p class="read" style="margin: 0">Most short music courses only teach songs and exercises. MAM Crash develops <strong style="color: #211A13">skill, confidence, intelligence, performance culture, artistic awareness</strong> and <strong style="color: #211A13">rhythm fluency</strong>.</p>
        <ul style="margin: 0; padding-left: 1.2em; display: flex; flex-direction: column; gap: 8px">
<?php foreach ($crOutcomes as $o): ?>
          <li><?= site_h($o) ?></li>
<?php endforeach; ?>
        </ul>
      </div>
    </section>

    <section aria-labelledby="go-h" style="padding: 0 clamp(8px, 1vw, 14px) clamp(8px, 1vw, 14px)">
      <div style="max-width: 1440px; margin: 0 auto; border-radius: clamp(14px, 1.6vw, 22px); background: radial-gradient(120% 140% at 80% 0%, #5A3815 0%, #24160B 45%, #140D08 100%); color: #FCF8F3; padding: clamp(56px, 7vw, 112px) clamp(20px, 4vw, 40px); box-sizing: border-box; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 20px">
        <p style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #F2A93B">Ready to begin · 6 weeks</p>
        <h2 id="go-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(34px, 4.17vw, 60px); line-height: 1.05">Apply for a Crash Programme</h2>
        <p style="margin: 0; max-width: 560px; font-size: 18px; line-height: 1.6; color: #D8CEC1">Pick your instrument or stream. Online or onsite. <?= $crPrice ?> covers the full 6-week intensive — no extra fees.</p>
        <div style="display: flex; flex-wrap: wrap; justify-content: center; gap: 12px">
          <a href="/apply?pathway=crash" class="btn arrow" style="display: inline-flex; align-items: center; gap: 8px; min-height: 52px; padding: 0 26px; border-radius: 999px; background: #F2A93B; color: #211A13; font-weight: 600; text-decoration: none">Apply — <?= $crPrice ?> <span aria-hidden="true">→</span></a>
          <a href="/pathways" style="display: inline-flex; align-items: center; min-height: 52px; padding: 0 25px; border-radius: 999px; border: 1px solid rgba(252,248,243,.6); color: #FCF8F3; font-weight: 600; text-decoration: none">All term-based programmes</a>
        </div>
      </div>
    </section>
</main>
<?php
echo (string) ob_get_clean();
site_footer();
