<?php
/**
 * Leadership & faculty — the page About's "Meet our leadership and faculty" and
 * "Leadership & faculty" links lead to. The 2026-11 handoff did not draw it, so it
 * is built from the handoff's own parts (the About page's breadcrumb, serif H1,
 * hairline-ruled sections and photo frames) on partials/site/.
 *
 * Only the founder is named, because only the founder's name and photograph have
 * been confirmed (handoff §9 "facts to confirm"). Each school's faculty is
 * described, not named; nobody is shown in a stock photograph.
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';

$ffSchools = [
    ['Instrumental Intelligence', 'Piano & keyboard', 'Classically trained pianists with active gospel, jazz and contemporary worship experience. They place each student on a curriculum that maps to Trinity Grades 1–8 and keep joy in the practice.'],
    ['Instrumental Intelligence', 'Strings', 'Orchestra-trained players of violin, viola, cello and guitar who teach bow technique, intonation and ensemble craft — from first posture to diploma repertoire.'],
    ['Instrumental Intelligence', 'Voice & choir', 'Vocal coaches with active choir-direction experience. They teach breath, tone, resonance, diction and stage presence, from age four to professional performers.'],
    ['Performance Intelligence', 'Performance', 'Stage performers and worship leaders who teach what conservatories often do not: entrance, microphone discipline, communicating with an audience, performance psychology and rehearsal culture.'],
    ['Rhythm Intelligence', 'Rhythm', 'Drummers, percussionists and choirmasters behind the academy’s own rhythm method: rapid sight reading, ear training, tonal recognition and playing together in time.'],
];
$ffTeam = [
    ['Academic Director', 'Owns the MAM Method curriculum and its Trinity-equivalent certification, and guards the standard from Grade 1 to Diploma.'],
    ['Head of Admissions', 'Runs the MAM Auditer entry assessment and places each student with the right instructor, level and pathway.'],
    ['Operations', 'Coordinates onsite lessons, ensembles and stagecraft, and keeps every timetable running on time.'],
    ['Student care', 'The first point of contact: onboarding, reminders, progress check-ins and an answer whenever a student or family is stuck.'],
    ['Online learning', 'Makes online classes feel as present as the room — clear audio, recordings and a smooth experience on any device.'],
];

$ffPerson = [
    '@context' => 'https://schema.org', '@type' => 'Person',
    'name' => 'Just-Joe Adeola', 'alternateName' => 'Babatunde Oluwaseyi Just-Joe Adeola',
    'jobTitle' => 'Founder and Director', 'image' => 'https://academy.mamcentre.org/assets/img/faculty/just-joe-adeola.jpg',
    'worksFor' => ['@type' => 'EducationalOrganization', 'name' => 'MAM Academy', 'url' => 'https://academy.mamcentre.org/'],
];

site_head([
    'title' => 'Leadership & faculty — MAM Academy',
    'description' => 'The founder, school faculty and team behind MAM Academy. Every class is taught by a working musician.',
    'path' => '/faculty',
    'jsonld' => '<script type="application/ld+json">' . json_encode($ffPerson, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>',
]);
site_header('inner', '/about');
ob_start();
?>
<main id="main">
    <section aria-labelledby="page-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(40px, 5vw, 72px) clamp(20px, 4vw, 40px) clamp(32px, 4vw, 56px); box-sizing: border-box; display: flex; flex-direction: column; gap: 24px">
      <nav aria-label="Breadcrumb" class="fade-in" style="font-size: 14px; color: #574B40; display: flex; gap: 8px; flex-wrap: wrap"><a href="/" class="ul" style="color: #574B40; text-decoration: none">Home</a><span aria-hidden="true">/</span><a href="/about" class="ul" style="color: #574B40; text-decoration: none">About</a><span aria-hidden="true">/</span><span aria-current="page">Leadership &amp; faculty</span></nav>
      <h1 id="page-h" aria-label="Leadership and faculty" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(46px, 5.6vw, 84px); line-height: 1.02; letter-spacing: -.01em"><span class="mask" aria-hidden="true"><span class="w" style="animation-delay: 0.15s;">Leadership</span></span> <span class="mask" aria-hidden="true"><span class="w" style="animation-delay: 0.235s;">&amp;</span></span> <span class="mask" aria-hidden="true"><span class="w" style="animation-delay: 0.32s;">faculty</span></span></h1>
      <p class="fade-in" style="animation-delay: .3s; margin: 0; max-width: 760px; font-size: 19px; line-height: 1.7; color: #3E342B">Every class at MAM Academy is taught by a working musician — a performer, worship leader or composer who lives what they teach.</p>
    </section>

    <section id="founder" aria-labelledby="founder-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(40px, 5vw, 72px) clamp(20px, 4vw, 40px) clamp(48px, 6vw, 88px); box-sizing: border-box; border-top: 1px solid #E6DED2">
      <div style="display: flex; flex-wrap: wrap; gap: 40px clamp(40px, 6vw, 96px); align-items: center">
        <div class="reveal" style="flex: 1 1 340px; min-width: 0; max-width: 480px">
          <div style="position: relative; aspect-ratio: 1 / 1; border-radius: 20px; overflow: hidden; background: #E8DECF">
            <picture>
              <source type="image/webp" srcset="/assets/img/faculty/just-joe-adeola-400.webp 400w, /assets/img/faculty/just-joe-adeola.webp 800w" sizes="(max-width: 720px) 90vw, 480px">
              <img src="/assets/img/faculty/just-joe-adeola.jpg" srcset="/assets/img/faculty/just-joe-adeola-400.jpg 400w, /assets/img/faculty/just-joe-adeola.jpg 800w" sizes="(max-width: 720px) 90vw, 480px" width="800" height="800" alt="Portrait of Just-Joe Adeola, founder and director of MAM Academy" decoding="async" style="position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover">
            </picture>
          </div>
        </div>
        <div style="flex: 1.3 1 440px; min-width: 0; display: flex; flex-direction: column; gap: 16px">
          <p class="reveal" style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">Founder &amp; Director</p>
          <h2 id="founder-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(38px, 4vw, 58px); line-height: 1.05">Just-Joe Adeola</h2>
          <p style="margin: 0; font-size: 15px; color: #574B40">Babatunde Oluwaseyi Just-Joe Adeola</p>
          <p class="read" style="margin: 0; font-size: 18px; line-height: 1.7; color: #3E342B">Founder of MAM Centre and architect of the MAM Method. Just-Joe leads The MetaMusic Order and hosts the free monthly Power Play series, and trains performers, worship leaders and creative thinkers in the philosophy and discipline of sound.</p>
          <ul style="list-style: none; margin: 8px 0 0; padding: 0; display: flex; flex-wrap: wrap; gap: 8px">
            <li style="padding: 6px 14px; border-radius: 999px; border: 1px solid #D6CCBE; font-size: 14px; color: #3E342B">MetaMusic</li>
            <li style="padding: 6px 14px; border-radius: 999px; border: 1px solid #D6CCBE; font-size: 14px; color: #3E342B">Creative leadership</li>
            <li style="padding: 6px 14px; border-radius: 999px; border: 1px solid #D6CCBE; font-size: 14px; color: #3E342B">Performance intelligence</li>
          </ul>
          <a href="/power-play" class="lrow" style="display: inline-flex; flex-direction: column; gap: 2px; margin-top: 8px; color: #211A13; text-decoration: none"><span style="display: inline-flex; align-items: center; gap: 8px; font-weight: 600; font-size: 17px"><span class="ul">Join Just-Joe at Power Play</span> <span aria-hidden="true" style="color: #8A5208">→</span></span><span style="font-size: 14px; color: #574B40">Free, online, every month</span></a>
        </div>
      </div>
    </section>

    <section id="schools" aria-labelledby="schools-h" style="background: #F3EEE6">
      <div style="max-width: 1200px; margin: 0 auto; padding: clamp(64px, 8vw, 120px) clamp(20px, 4vw, 40px); box-sizing: border-box; display: flex; flex-direction: column; gap: clamp(28px, 3vw, 44px)">
        <div style="max-width: 760px; display: flex; flex-direction: column; gap: 16px">
          <p class="reveal" style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">The schools</p>
          <h2 id="schools-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(34px, 4.17vw, 60px); line-height: 1.05">Faculty across the schools</h2>
          <p class="read" style="margin: 0; font-size: 18px; line-height: 1.7; color: #3E342B">Your instructor is chosen after your <a href="/auditer" class="ul" style="color: #8A5208">MAM Auditer assessment</a>, matched to your level, your mode of study and your goals.</p>
        </div>
        <ul class="stagger" style="list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(min(300px, 100%), 1fr)); gap: 16px">
<?php foreach ($ffSchools as [$school, $name, $bio]): ?>
          <li class="lift" style="display: flex; flex-direction: column; gap: 10px; padding: 28px; border-radius: 16px; background: #FFFFFF; border: 1px solid #E6DED2">
            <span style="font-size: 13px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: #8A5208"><?= site_h($school) ?></span>
            <h3 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 28px; line-height: 1.1"><?= site_h($name) ?> faculty</h3>
            <p style="margin: 0; font-size: 16px; line-height: 1.65; color: #3E342B"><?= site_h($bio) ?></p>
          </li>
<?php endforeach; ?>
        </ul>
      </div>
    </section>

    <section id="team" aria-labelledby="team-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(64px, 8vw, 120px) clamp(20px, 4vw, 40px); box-sizing: border-box; display: flex; flex-wrap: wrap; gap: 32px clamp(40px, 6vw, 96px)">
      <div style="flex: 1 1 280px; display: flex; flex-direction: column; gap: 16px">
        <h2 id="team-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(32px, 3vw, 44px); line-height: 1.1">The team behind the classes</h2>
        <p class="read" style="margin: 0; font-size: 17px; line-height: 1.7; color: #3E342B">From your first assessment to your final recital, online or in person.</p>
      </div>
      <dl style="flex: 2 1 480px; min-width: 0; margin: 0; display: flex; flex-direction: column">
<?php foreach ($ffTeam as $i => [$role, $line]): ?>
        <div style="display: flex; flex-wrap: wrap; gap: 4px 24px; padding: 20px 0; <?= $i ? 'border-top: 1px solid #E6DED2' : '' ?>">
          <dt style="flex: 0 1 220px; font-weight: 600; font-size: 17px; color: #211A13"><?= site_h($role) ?></dt>
          <dd style="flex: 1 1 280px; margin: 0; font-size: 16px; line-height: 1.65; color: #3E342B"><?= site_h($line) ?></dd>
        </div>
<?php endforeach; ?>
      </dl>
    </section>

    <section aria-labelledby="teach-h" style="padding: 0 clamp(8px, 1vw, 14px) clamp(8px, 1vw, 14px)">
      <div style="max-width: 1440px; margin: 0 auto; border-radius: clamp(14px, 1.6vw, 22px); background: radial-gradient(120% 140% at 80% 0%, #5A3815 0%, #24160B 45%, #140D08 100%); color: #FCF8F3; padding: clamp(56px, 7vw, 112px) clamp(20px, 4vw, 40px); box-sizing: border-box; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 20px">
        <h2 id="teach-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(34px, 4.17vw, 60px); line-height: 1.05">Teach at <em style="font-style: italic; color: #F2A93B">MAM</em></h2>
        <p style="margin: 0; max-width: 560px; font-size: 18px; line-height: 1.6; color: #D8CEC1">We welcome experienced musicians and creative leaders who share the academy’s vision.</p>
        <a href="/instructor-apply" class="btn arrow" style="display: inline-flex; align-items: center; gap: 8px; min-height: 52px; padding: 0 26px; border-radius: 999px; background: #F2A93B; color: #211A13; font-weight: 600; text-decoration: none">Apply to teach <span aria-hidden="true">→</span></a>
      </div>
    </section>
</main>
<?php
echo (string) ob_get_clean();
site_footer();
