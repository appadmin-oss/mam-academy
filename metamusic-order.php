<?php
/**
 * The MetaMusic Order — the 12-week intensive, on the 2026-11 redesign shell
 * (partials/site/). The handoff did not draw this page; it is built from the
 * handoff's own parts. The old page's animated emoji (third-party Lottie files)
 * are not carried over; every line of its copy is. The price is the server's
 * (site_money('meta'), the figure /apply charges). #what is kept: the hero
 * links to it.
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';

$moPrice = site_money('meta');
$moPillars = [
    ['Cultural Architects', 'Shape the spirit of a generation'],
    ['Psychological Influencers', 'Engineer identity through sound'],
    ['National Builders', 'Build civilization through art'],
    ['MetaMusicians', 'Creators with intent & depth'],
];
$moWeeks = [
    ['Sound & Civilization', 'How sound built every era of human culture.'],
    ['Music as Programming', 'The science of auditory conditioning.'],
    ['Identity Reconstruction', 'Renewing the inner musician.'],
    ['Atmosphere Architecture', 'Designing rooms with intention.'],
    ['Performance Intelligence', 'Stage as cultural platform.'],
    ['Sound Psychology', 'How sound moves emotion & behavior.'],
    ['African Creative Intelligence', 'Recovering ancestral rhythm wisdom.'],
    ['Cultural Engineering', 'Shaping the spirit of a generation.'],
    ['Spiritual Dimensions', 'Sacred sound and consecration.'],
    ['Creative Leadership', 'Lead artists, choirs, ensembles.'],
    ['National Responsibility', 'Sound as civic stewardship.'],
    ['The Architect Declaration', 'Commissioning & closing rites.'],
];
$moWho = ['Musicians', 'Singers', 'Producers', 'Worship Ministers', 'Instrumentalists', 'Spoken Word Artists', 'Creative Directors', 'Cultural Influencers'];

$moLd = [
    '@context' => 'https://schema.org', '@type' => 'Course', 'name' => 'The MetaMusic Order',
    'url' => 'https://academy.mamcentre.org/metamusic-order',
    'description' => 'A 12-week post-graduate cultural-architecture intensive at MAM Academy for producers, performers and creative leaders — the capstone of the MAM Method.',
    'provider' => ['@type' => 'EducationalOrganization', 'name' => 'MAM Academy', 'sameAs' => 'https://academy.mamcentre.org/'],
    'offers' => ['@type' => 'Offer', 'price' => (string) (int) round(site_fee_usd('meta')), 'priceCurrency' => 'USD', 'category' => 'Tuition'],
    'hasCourseInstance' => [['@type' => 'CourseInstance', 'courseMode' => ['online', 'onsite'], 'courseSchedule' => ['@type' => 'Schedule', 'repeatFrequency' => 'P1W', 'duration' => 'P12W']]],
];
$moH2 = "margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(34px, 4.17vw, 60px); line-height: 1.05";
$moEyebrow = 'margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208';
$moStatement = "margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(30px, 3.4vw, 50px); line-height: 1.18; color: #211A13";

site_head([
    'title' => 'The MetaMusic Order — 12-Week Civilization Engineering Intensive | MAM Academy',
    'description' => 'The MetaMusic Order is a 12-week civilization engineering intensive at MAM Academy. From Performer to Architect. Engineer the soundtrack of civilization.',
    'path' => '/metamusic-order',
    'jsonld' => '<script type="application/ld+json">' . json_encode($moLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>',
]);
site_header('inner', '/programmes');
ob_start();
?>
<main id="main">
    <section aria-labelledby="page-h" style="padding: clamp(8px, 1vw, 14px) clamp(8px, 1vw, 14px) 0">
      <div style="max-width: 1440px; margin: 0 auto; border-radius: clamp(14px, 1.6vw, 22px); background: radial-gradient(120% 140% at 80% 0%, #5A3815 0%, #24160B 45%, #140D08 100%); color: #FCF8F3; box-sizing: border-box; padding: clamp(48px, 6vw, 96px) clamp(20px, 4vw, 40px) clamp(56px, 7vw, 112px)">
        <div style="max-width: 1200px; margin: 0 auto; display: flex; flex-direction: column; gap: 24px">
          <nav aria-label="Breadcrumb" class="fade-in" style="font-size: 14px; color: #D8CEC1; display: flex; gap: 8px; flex-wrap: wrap"><a href="/" class="ul" style="color: #D8CEC1; text-decoration: none">Home</a><span aria-hidden="true">/</span><a href="/programmes" class="ul" style="color: #D8CEC1; text-decoration: none">Programmes</a><span aria-hidden="true">/</span><span aria-current="page">The MetaMusic Order</span></nav>
          <p class="fade-in" style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #F2A93B">12-week civilization engineering intensive</p>
          <h1 id="page-h" aria-label="The MetaMusic Order" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(52px, 6.4vw, 96px); line-height: 1; letter-spacing: -.01em"><span class="mask" aria-hidden="true"><span class="w" style="animation-delay: 0.15s;">The</span></span> <span class="mask" aria-hidden="true"><span class="w" style="animation-delay: 0.235s; font-style: italic; color: #F2A93B">MetaMusic</span></span> <span class="mask" aria-hidden="true"><span class="w" style="animation-delay: 0.32s;">Order</span></span></h1>
          <p class="fade-in" style="animation-delay: .3s; margin: 0; max-width: 760px; font-size: 19px; line-height: 1.7; color: #E9E1D6">This is not a music class. This is an Order. A reconstruction system designed to raise a new generation of musicians who understand that sound is not merely entertainment — sound is <strong style="color: #FCF8F3">influence, architecture, programming, governance</strong>.</p>
          <div class="fade-in" style="animation-delay: .45s; display: flex; flex-wrap: wrap; gap: 12px">
            <a href="/apply?pathway=meta-order" class="btn arrow" style="display: inline-flex; align-items: center; gap: 8px; min-height: 52px; padding: 0 26px; border-radius: 999px; background: #F2A93B; color: #211A13; font-weight: 600; text-decoration: none">Enter the Order — <?= $moPrice ?> <span aria-hidden="true">→</span></a>
            <a href="#what" style="display: inline-flex; align-items: center; min-height: 52px; padding: 0 25px; border-radius: 999px; border: 1px solid rgba(252,248,243,.6); color: #FCF8F3; font-weight: 600; text-decoration: none">Learn more</a>
          </div>
          <blockquote class="fade-in" style="animation-delay: .6s; margin: 16px 0 0; padding: 4px 0 4px 24px; border-left: 3px solid #F2A93B; max-width: 640px">
            <p style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-size: clamp(22px, 1.9vw, 28px); line-height: 1.35; color: #FCF8F3">“If the trumpet gives an uncertain sound, who shall prepare himself for battle?”</p>
            <cite style="display: block; margin-top: 8px; font-style: normal; font-size: 14px; color: #D8CEC1">— 1 Corinthians 14:8</cite>
          </blockquote>
        </div>
      </div>
    </section>

    <section id="what" aria-labelledby="what-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(64px, 8vw, 120px) clamp(20px, 4vw, 40px); box-sizing: border-box; display: flex; flex-direction: column; gap: clamp(28px, 3vw, 44px)">
      <div style="max-width: 760px; display: flex; flex-direction: column; gap: 16px">
        <p class="reveal" style="<?= $moEyebrow ?>">What is the Order</p>
        <h2 id="what-h" style="<?= $moH2 ?>">A radical cultural movement</h2>
        <p class="read" style="margin: 0; font-size: 18px; line-height: 1.7; color: #3E342B">The MetaMusic Order transforms musicians into <strong style="color: #211A13">Cultural Architects, Psychological Influencers, National Builders</strong> and <strong style="color: #211A13">MetaMusicians</strong>.</p>
      </div>
      <ul class="stagger" style="list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(min(240px, 100%), 1fr)); gap: 16px">
<?php foreach ($moPillars as [$t, $d]): ?>
        <li class="lift" style="display: flex; flex-direction: column; gap: 8px; padding: 28px; border-radius: 16px; background: #FFFFFF; border: 1px solid #E6DED2">
          <h3 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 28px; line-height: 1.1"><?= site_h($t) ?></h3>
          <p style="margin: 0; font-size: 16px; line-height: 1.6; color: #3E342B"><?= site_h($d) ?></p>
        </li>
<?php endforeach; ?>
      </ul>
      <p class="reveal" style="<?= $moStatement ?>">Music has the power to shape culture.<br>The Order trains the musicians who use it on purpose.</p>
    </section>

    <section aria-labelledby="phil-h" style="background: #F3EEE6">
      <div style="max-width: 1200px; margin: 0 auto; padding: clamp(64px, 8vw, 120px) clamp(20px, 4vw, 40px); box-sizing: border-box; display: flex; flex-direction: column; gap: clamp(28px, 3vw, 44px)">
        <div style="max-width: 760px; display: flex; flex-direction: column; gap: 16px">
          <p class="reveal" style="<?= $moEyebrow ?>">The philosophy</p>
          <h2 id="phil-h" style="<?= $moH2 ?>">What you repeatedly hear, you eventually become.</h2>
        </div>
        <div class="stagger" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(min(320px, 100%), 1fr)); gap: 16px">
          <article style="display: flex; flex-direction: column; gap: 12px; padding: 28px; border-radius: 16px; background: #FFFFFF; border: 1px solid #E6DED2">
            <h3 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 28px; line-height: 1.1">Science calls it</h3>
            <ul style="margin: 0; padding-left: 1.1em; display: flex; flex-direction: column; gap: 6px; font-size: 17px; color: #3E342B"><li>Neuroplasticity</li><li>Conditioning</li><li>Auditory entrainment</li></ul>
          </article>
          <article style="display: flex; flex-direction: column; gap: 12px; padding: 28px; border-radius: 16px; background: #FFFFFF; border: 1px solid #E6DED2">
            <h3 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 28px; line-height: 1.1">Scripture calls it</h3>
            <ul style="margin: 0; padding-left: 1.1em; display: flex; flex-direction: column; gap: 6px; font-size: 17px; color: #3E342B"><li>Transformation</li><li>Meditation</li><li>Renewal of the mind</li></ul>
            <blockquote style="margin: 4px 0 0; padding-left: 16px; border-left: 3px solid #C27A12; font-size: 16px; line-height: 1.6; color: #3E342B">“Be transformed by the renewing of your mind.” <span style="color: #574B40">— Romans 12:2</span></blockquote>
          </article>
        </div>
        <p class="reveal" style="<?= $moStatement ?>">Sound is never neutral.<br>It is always building something.</p>
      </div>
    </section>

    <section aria-labelledby="path-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(64px, 8vw, 120px) clamp(20px, 4vw, 40px); box-sizing: border-box; display: flex; flex-wrap: wrap; gap: 32px clamp(40px, 6vw, 96px)">
      <div style="flex: 1 1 280px; display: flex; flex-direction: column; gap: 16px">
        <p class="reveal" style="<?= $moEyebrow ?>">The 12-week path</p>
        <h2 id="path-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(32px, 3vw, 44px); line-height: 1.1">From Performer → <em style="color: #8A5208">Architect</em></h2>
        <p class="read" style="margin: 0; font-size: 17px; line-height: 1.7; color: #3E342B">A practical immersion into sound psychology, cultural engineering, atmosphere architecture, performance intelligence, identity reconstruction and national responsibility.</p>
      </div>
      <ol style="flex: 2 1 480px; min-width: 0; list-style: none; margin: 0; padding: 0">
<?php foreach ($moWeeks as $i => [$t, $d]): ?>
        <li style="display: flex; gap: 20px; padding: 16px 0; <?= $i ? 'border-top: 1px solid #E6DED2' : '' ?>">
          <span style="flex: 0 0 48px; font-size: 13px; font-weight: 600; letter-spacing: .08em; color: #8A5208; padding-top: 3px">W<?= $i + 1 ?></span>
          <span><strong style="display: block; font-size: 17px; color: #211A13"><?= site_h($t) ?></strong><span style="font-size: 15px; color: #574B40"><?= site_h($d) ?></span></span>
        </li>
<?php endforeach; ?>
      </ol>
    </section>

    <section aria-labelledby="who-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(48px, 6vw, 88px) clamp(20px, 4vw, 40px); box-sizing: border-box; border-top: 1px solid #E6DED2; display: flex; flex-wrap: wrap; gap: 32px clamp(40px, 6vw, 96px)">
      <div style="flex: 1 1 280px; display: flex; flex-direction: column; gap: 16px">
        <h2 id="who-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(32px, 3vw, 44px); line-height: 1.1">Who can join?</h2>
        <p class="read" style="margin: 0; font-size: 17px; line-height: 1.7; color: #3E342B">For those built for more than applause.</p>
      </div>
      <ul style="flex: 2 1 480px; min-width: 0; list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 8px; align-content: flex-start">
<?php foreach ($moWho as $w): ?>
        <li style="padding: 8px 16px; border-radius: 999px; border: 1px solid #D6CCBE; font-size: 16px; color: #211A13"><?= site_h($w) ?></li>
<?php endforeach; ?>
      </ul>
    </section>

    <section aria-label="The declaration" style="max-width: 1200px; margin: 0 auto; padding: clamp(48px, 6vw, 88px) clamp(20px, 4vw, 40px) clamp(64px, 8vw, 120px); box-sizing: border-box; border-top: 1px solid #E6DED2">
      <p class="reveal" style="<?= $moEyebrow ?>; margin-bottom: 20px">The declaration</p>
      <blockquote class="reveal" style="margin: 0; max-width: 820px; <?= $moStatement ?>">“I am not merely a performer.<br><br>I am a builder of minds.<br>A steward of atmosphere.<br>A guardian of culture.<br>A constructor of civilization through sound.”</blockquote>
    </section>

    <section aria-labelledby="go-h" style="padding: 0 clamp(8px, 1vw, 14px) clamp(8px, 1vw, 14px)">
      <div style="max-width: 1440px; margin: 0 auto; border-radius: clamp(14px, 1.6vw, 22px); background: radial-gradient(120% 140% at 80% 0%, #5A3815 0%, #24160B 45%, #140D08 100%); color: #FCF8F3; padding: clamp(56px, 7vw, 112px) clamp(20px, 4vw, 40px); box-sizing: border-box; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 20px">
        <p style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #F2A93B">The final question</p>
        <h2 id="go-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(34px, 4.17vw, 60px); line-height: 1.05">Who is engineering the soundtrack of civilization?</h2>
        <p style="margin: 0; max-width: 560px; font-size: 18px; line-height: 1.6; color: #D8CEC1">For musicians ready to engineer culture, not just perform. Twelve weeks. One commission. From Performer → Architect.</p>
        <div style="display: flex; flex-wrap: wrap; justify-content: center; gap: 12px">
          <a href="/apply?pathway=meta-order" class="btn arrow" style="display: inline-flex; align-items: center; gap: 8px; min-height: 52px; padding: 0 26px; border-radius: 999px; background: #F2A93B; color: #211A13; font-weight: 600; text-decoration: none">Enter the Order — <?= $moPrice ?> <span aria-hidden="true">→</span></a>
          <a href="/pathways" style="display: inline-flex; align-items: center; min-height: 52px; padding: 0 25px; border-radius: 999px; border: 1px solid rgba(252,248,243,.6); color: #FCF8F3; font-weight: 600; text-decoration: none">All pathways &amp; intensives</a>
        </div>
      </div>
    </section>
</main>
<?php
echo (string) ob_get_clean();
site_footer();
