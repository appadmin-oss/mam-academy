<?php
/**
 * The four signature schools, on the 2026-11 redesign shell (partials/site/), in
 * the About page's alternating text-and-photo blocks. The old page's stock photos
 * are gone (handoff §9: real MAM photos, no stock); each block keeps a photo slot
 * that site_photos() fills the day assets/img/site/<slug>.webp exists.
 * The section ids (#instrumental, #performance, #rhythm, #metamusic) are linked
 * from elsewhere and are kept.
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';

$scSchools = [
    ['instrumental', 'School A', 'Instrumental Intelligence School',
     'Focused on technical and practical mastery of instruments — the foundation of every musical journey.',
     ['<strong>Piano &amp; Keyboard</strong> — classical, gospel &amp; contemporary', '<strong>Strings</strong> — Violin, Viola, Cello, Guitar',
      '<strong>Wind Instruments</strong> — Flute, Clarinet, Saxophone', '<strong>Brass Instruments</strong> — Trumpet, Trombone, Tuba',
      'Scales, technical drills, performance pieces', 'Accompaniment systems &amp; ensemble repertoire'],
     ['/apply?discipline=piano', 'Apply for Piano'], ['/apply', 'Choose another instrument'], 'a piano lesson'],
    ['performance', 'School B', 'Performance Intelligence School',
     'Focused on stagecraft, presentation, confidence and performance psychology. What most music schools never teach.',
     ['<strong>Stage Intelligence</strong> — entrance, posture, movement', '<strong>Microphone Discipline</strong> — control &amp; projection',
      '<strong>Performance Psychology</strong> — fear, confidence, energy', '<strong>Audience Communication</strong> &amp; engagement',
      '<strong>Rehearsal Culture</strong> — discipline &amp; ethics', '<strong>Live Presentation Systems</strong>'],
     ['/apply?discipline=performance', 'Apply for Performance'], ['/crash', 'Crash Course'], 'a live performance'],
    ['rhythm', 'School C', 'Rhythm Intelligence School',
     'Home of the <strong>MAM Rapid Rhythm Intelligence System</strong> — a signature structured method for sight singing, rhythm mastery, ear training, fluency and timing intelligence.',
     ['<strong>Sight Singing &amp; Sight Reading</strong>', '<strong>Ear Training Systems</strong>', '<strong>Rhythm Cognition</strong> &amp; coordination',
      '<strong>Tonal Recognition</strong>', '<strong>Ensemble Synchronization</strong>', '<strong>Polyrhythm Mastery</strong> (advanced)'],
     ['/apply?discipline=rhythm', 'Apply for Rhythm'], ['/crash', 'Rhythm Crash Course'], 'a rhythm class'],
    ['metamusic', 'School D', 'MetaMusic & Creative Consciousness',
     'Potentially MAM’s most revolutionary school. Beyond skill — into philosophy, consciousness, civilization and the spirituality of art.',
     ['<strong>Philosophy of Music</strong>', '<strong>Sound &amp; Civilization</strong>', '<strong>African Creative Intelligence</strong>',
      '<strong>Music &amp; Human Consciousness</strong>', '<strong>Timeless Creative Systems</strong>', '<strong>Cultural Artistry &amp; Leadership</strong>'],
     ['/apply?discipline=metamusic', 'Apply for MetaMusic'], ['/metamusic-order', 'The MetaMusic Order'], 'a MetaMusic seminar'],
];

site_head([
    'title' => 'The four signature schools — MAM Academy',
    'description' => 'Instrumental Intelligence, Performance Intelligence, Rhythm Intelligence and MetaMusic & Creative Consciousness — the four signature schools of MAM Academy.',
    'path' => '/schools',
]);
site_header('inner', '/about');
ob_start();
?>
<main id="main">
<?php
site_doc_open('The four schools', 'A structure no ordinary music school offers',
    'Craft, performance, rhythm cognition and artistic philosophy — united under one academy. Each school is anchored to a specific intelligence the modern musician needs.');
foreach ($scSchools as $i => [$id, $eyebrow, $name, $lede, $items, $primary, $secondary, $photo]):
?>
    <section id="<?= $id ?>" aria-labelledby="<?= $id ?>-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(48px, 6vw, 88px) clamp(20px, 4vw, 40px); box-sizing: border-box; border-top: 1px solid #E6DED2">
      <div style="display: flex; flex-wrap: wrap; gap: 40px clamp(40px, 6vw, 96px); align-items: center; flex-direction: <?= $i % 2 ? 'row-reverse' : 'row' ?>">
        <div style="flex: 1 1 440px; min-width: 0; display: flex; flex-direction: column; gap: 16px">
          <p class="reveal" style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208"><?= site_h($eyebrow) ?></p>
          <h2 id="<?= $id ?>-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(32px, 3vw, 44px); line-height: 1.1"><?= site_h($name) ?></h2>
          <p class="read" style="margin: 0; font-size: 18px; line-height: 1.7; color: #3E342B"><?= $lede ?></p>
          <ul style="list-style: none; margin: 4px 0 0; padding: 0; display: flex; flex-direction: column">
<?php foreach ($items as $k => $it): ?>
            <li style="display: flex; gap: 12px; padding: 10px 0; <?= $k ? 'border-top: 1px solid #ECE5DA; ' : '' ?>font-size: 16px; line-height: 1.6; color: #3E342B"><span aria-hidden="true" style="color: #C27A12">—</span><span><?= $it ?></span></li>
<?php endforeach; ?>
          </ul>
          <div style="display: flex; flex-wrap: wrap; gap: 12px; margin-top: 8px">
            <a href="<?= site_h($primary[0]) ?>" class="btn" style="display: inline-flex; align-items: center; min-height: 48px; padding: 0 24px; border-radius: 999px; background: #F2A93B; color: #211A13; font-weight: 600; text-decoration: none"><?= site_h($primary[1]) ?></a>
            <a href="<?= site_h($secondary[0]) ?>" class="btn arrow" style="display: inline-flex; align-items: center; gap: 8px; min-height: 48px; padding: 0 24px; border-radius: 999px; border: 1px solid #CFC3B3; color: #211A13; font-weight: 600; text-decoration: none"><?= site_h($secondary[1]) ?> <span aria-hidden="true">→</span></a>
          </div>
        </div>
        <div class="reveal" style="flex: 1 1 440px; min-width: 0"><div data-photo="<?= site_h($photo) ?>" aria-hidden="true" style="position: relative; overflow: hidden; aspect-ratio: 3 / 2; border-radius: 14px; background: linear-gradient(135deg, #E8DECF, #CDB89C)"></div></div>
      </div>
    </section>
<?php endforeach; ?>

    <section aria-labelledby="sc-next-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(48px, 6vw, 88px) clamp(20px, 4vw, 40px); box-sizing: border-box; border-top: 1px solid #E6DED2; display: flex; flex-wrap: wrap; gap: 16px">
      <h2 id="sc-next-h" class="sr">Continue your journey</h2>
      <a href="/about" class="lift" style="flex: 1 1 280px; display: flex; flex-direction: column; gap: 8px; padding: 28px; border-radius: 16px; background: #FFFFFF; border: 1px solid #E6DED2; color: #211A13; text-decoration: none"><span style="font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">Previous</span><span style="font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 28px; line-height: 1.1">About MAM Academy</span></a>
      <a href="/faculty" class="lift" style="flex: 2 1 380px; display: flex; flex-direction: column; gap: 8px; padding: 28px; border-radius: 16px; background: #FFFFFF; border: 1px solid #E6DED2; color: #211A13; text-decoration: none"><span style="font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">Up next</span><span style="font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 28px; line-height: 1.1">Meet the faculty <span aria-hidden="true" style="color: #8A5208">→</span></span><span style="font-size: 16px; line-height: 1.6; color: #3E342B">Every MAM class is led by a working musician, performer or composer — not someone repeating textbooks. Meet who’ll teach you.</span></a>
    </section>
</main>
<?php
echo site_photos((string) ob_get_clean());
site_footer();
