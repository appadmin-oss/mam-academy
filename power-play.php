<?php
/**
 * Power Play — the free monthly series, on the 2026-11 site shell (partials/site/),
 * in the language of Life at MAM's Power Play panel. The handoff did not draw this
 * page; Life links here (/power-play#register) to register.
 *
 * The next session is dated by mam_powerplay_next() (mailer.php), the same function
 * that dates the confirmation email, so the page and the email agree; the countdown
 * is recounted on the visitor's clock by site-powerplay.js. Registration posts to
 * the same endpoint with the same source as before: /api/newsletter, 'power-play'.
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';

$ppNext = mam_powerplay_next();
$ppAt   = $ppNext['dt'];
$ppDays = (int) (new DateTimeImmutable('today', $ppAt->getTimezone()))->diff($ppAt->setTime(0, 0))->days;
$ppCount = $ppDays === 0 ? 'Today — join us this evening' : ($ppDays === 1 ? '1 day to go' : $ppDays . ' days to go');
$ppMeet = (string) $ppNext['meetUrl'];

$ppEvent = [
    '@context' => 'https://schema.org', '@type' => 'Event',
    'name' => 'Power Play with Just-Joe Adeola',
    'description' => 'A free monthly gathering for musicians, creatives and culture shapers.',
    'startDate' => $ppAt->format(DATE_ATOM),
    'endDate' => $ppAt->modify('+90 minutes')->format(DATE_ATOM),
    'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
    'eventStatus' => 'https://schema.org/EventScheduled',
    'isAccessibleForFree' => true,
    'location' => ['@type' => 'VirtualLocation', 'url' => 'https://academy.mamcentre.org/power-play'],
    'organizer' => ['@type' => 'EducationalOrganization', 'name' => 'MAM Academy', 'url' => 'https://academy.mamcentre.org/'],
    'performer' => ['@type' => 'Person', 'name' => 'Just-Joe Adeola'],
];

site_head([
    'title' => 'Power Play — a free monthly series with Just-Joe Adeola | MAM Academy',
    'description' => 'Power Play is a free monthly global series for musicians, artists, worship leaders and culture shapers — hosted by Just-Joe Adeola. Online via Google Meet.',
    'path' => '/power-play',
    'jsonld' => '<script type="application/ld+json">' . json_encode($ppEvent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>',
]);
site_header('inner', '/life');
ob_start();
?>
<main id="main">
    <div style="padding: 0 clamp(8px, 1vw, 14px)">
    <section aria-labelledby="page-h" style="border-radius: clamp(14px, 1.6vw, 22px); background: radial-gradient(80% 120% at 85% 0%, #5A3815 0%, #24160B 50%, #140D08 100%); color: #FCF8F3">
      <div style="max-width: 1360px; margin: 0 auto; padding: clamp(40px, 5vw, 72px) clamp(20px, 4vw, 40px) clamp(72px, 10vw, 144px); box-sizing: border-box; display: flex; flex-direction: column; gap: clamp(32px, 4vw, 56px)">
        <nav aria-label="Breadcrumb" class="fade-in" style="font-size: 14px; color: rgba(252,248,243,.75); display: flex; gap: 8px; flex-wrap: wrap"><a href="/" class="ul" style="color: rgba(252,248,243,.85); text-decoration: none">Home</a><span aria-hidden="true">/</span><a href="/life" class="ul" style="color: rgba(252,248,243,.85); text-decoration: none">Life at MAM</a><span aria-hidden="true">/</span><span aria-current="page">Power Play</span></nav>
        <div style="display: flex; flex-wrap: wrap; gap: 48px clamp(40px, 6vw, 112px); align-items: center">
          <div style="flex: 1.2 1 460px; min-width: 0; display: flex; flex-direction: column; gap: 18px">
            <p class="fade-in" style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #F2A93B">Free · monthly · online</p>
            <h1 id="page-h" aria-label="Power Play with Just-Joe Adeola" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(52px, 6vw, 96px); line-height: .95"><span class="mask" aria-hidden="true"><span class="w" style="animation-delay: 0.15s;">Power</span></span> <span class="mask" aria-hidden="true"><span class="w" style="animation-delay: 0.235s;">Play</span></span></h1>
            <p class="fade-in" style="animation-delay: .3s; margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-style: italic; font-size: clamp(30px, 3.4vw, 50px); line-height: 1.18; color: #F2A93B">The war room for cultural architects</p>
            <p class="fade-in" style="animation-delay: .4s; margin: 0; font-size: 18px; line-height: 1.6; color: #E9E1D6; max-width: 560px">A monthly gathering for musicians, creatives and culture shapers — built to forge presence, mastery and intentional influence. Raw conversations, fierce insights, and growth for musicians who think like architects.</p>
            <p class="fade-in" style="animation-delay: .45s; margin: 0; font-size: 15px; color: #D8CEC1">First Wednesday of every month · 90 minutes · Google Meet · free, and open to all</p>
            <div class="fade-in" style="animation-delay: .5s; display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px">
              <a href="#register" data-pp-cta class="btn arrow" style="display: inline-flex; align-items: center; gap: 8px; min-height: 48px; padding: 0 24px; border-radius: 999px; background: #F2A93B; color: #211A13; font-weight: 600; text-decoration: none">Register free <span aria-hidden="true">→</span></a>
              <a href="#why" class="btn" style="display: inline-flex; align-items: center; gap: 8px; min-height: 48px; padding: 0 24px; border-radius: 999px; border: 1px solid rgba(252,248,243,.6); color: #FCF8F3; font-weight: 600; text-decoration: none">Who it is for</a>
            </div>
          </div>
          <div class="reveal" style="flex: 1 1 340px; min-width: 0; display: flex; flex-direction: column; gap: 6px; padding: clamp(24px, 3vw, 40px); border-radius: 20px; border: 1px solid rgba(252,248,243,.16); background: rgba(252,248,243,.04)">
            <p style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase; color: #F2A93B">Next session</p>
            <p style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-size: clamp(30px, 3.4vw, 50px); line-height: 1.18" data-pp-date data-pp-at="<?= site_h($ppAt->format(DATE_ATOM)) ?>"><?= site_h($ppAt->format('l j F Y')) ?></p>
            <p style="margin: 0; font-size: 17px; color: #E9E1D6" data-pp-time><?= site_h($ppNext['time']) ?></p>
            <p style="margin: 0; font-size: 15px; color: #D8CEC1" data-pp-count aria-live="polite"><?= site_h($ppCount) ?></p>
            <dl style="margin: 14px 0 0; padding-top: 14px; border-top: 1px solid rgba(252,248,243,.14); display: grid; grid-template-columns: auto 1fr; gap: 8px 20px; font-size: 15px">
              <dt style="color: #D8CEC1">Format</dt><dd style="margin: 0">Free · Google Meet</dd>
              <dt style="color: #D8CEC1">Frequency</dt><dd style="margin: 0">First Wednesday, monthly</dd>
              <dt style="color: #D8CEC1">Duration</dt><dd style="margin: 0">90 minutes</dd>
              <dt style="color: #D8CEC1">Audience</dt><dd style="margin: 0">Global · online</dd>
              <dt style="color: #D8CEC1">Host</dt><dd style="margin: 0">Just-Joe Adeola</dd>
            </dl>
          </div>
        </div>
      </div>
    </section>
    </div>

    <section id="host" aria-labelledby="host-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(64px, 8vw, 120px) clamp(20px, 4vw, 40px) clamp(48px, 6vw, 88px); box-sizing: border-box">
      <div style="display: flex; flex-wrap: wrap; gap: 40px clamp(40px, 6vw, 96px); align-items: center">
        <a href="/faculty#founder" class="reveal zoom" style="flex: 0 1 360px; min-width: 0; display: block; position: relative; aspect-ratio: 1 / 1; border-radius: 20px; overflow: hidden; background: #E8DECF">
          <picture>
            <source type="image/webp" srcset="/assets/img/faculty/just-joe-adeola-400.webp 400w, /assets/img/faculty/just-joe-adeola.webp 800w" sizes="(max-width: 720px) 90vw, 360px">
            <img src="/assets/img/faculty/just-joe-adeola.jpg" srcset="/assets/img/faculty/just-joe-adeola-400.jpg 400w, /assets/img/faculty/just-joe-adeola.jpg 800w" sizes="(max-width: 720px) 90vw, 360px" width="800" height="800" alt="Portrait of Just-Joe Adeola, host of Power Play" loading="lazy" decoding="async" style="position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover">
          </picture>
        </a>
        <div style="flex: 1.4 1 440px; min-width: 0; display: flex; flex-direction: column; gap: 16px">
          <p class="reveal" style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">Your host</p>
          <h2 id="host-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(38px, 4vw, 58px); line-height: 1.05">Just-Joe Adeola</h2>
          <p style="margin: 0; font-size: 15px; color: #574B40">Babatunde Oluwaseyi Just-Joe Adeola · Founder of MAM Centre</p>
          <blockquote style="margin: 8px 0 0; padding: 4px 0 4px 24px; border-left: 3px solid #C27A12; display: flex; flex-direction: column; gap: 10px">
            <p style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-size: clamp(30px, 3.4vw, 50px); line-height: 1.18; color: #211A13">“The future does not belong to the loudest voices. It belongs to the most prepared.”</p>
            <cite style="font-style: normal; font-size: 15px; color: #574B40">— Just-Joe Adeola</cite>
          </blockquote>
          <p class="read" style="margin: 0; font-size: 18px; line-height: 1.7; color: #3E342B">Building men who can move nations.</p>
          <a href="/faculty#founder" class="lrow" style="display: inline-flex; flex-direction: column; gap: 2px; margin-top: 4px; color: #211A13; text-decoration: none"><span style="display: inline-flex; align-items: center; gap: 8px; font-weight: 600; font-size: 17px"><span class="ul">About Just-Joe</span> <span aria-hidden="true" style="color: #8A5208">→</span></span><span style="font-size: 14px; color: #574B40">Leadership &amp; faculty</span></a>
        </div>
      </div>
    </section>

    <section id="why" aria-labelledby="why-h" style="background: #F3EEE6">
      <div style="max-width: 1200px; margin: 0 auto; padding: clamp(64px, 8vw, 120px) clamp(20px, 4vw, 40px); box-sizing: border-box; display: flex; flex-direction: column; gap: clamp(28px, 3vw, 44px)">
        <div style="max-width: 760px; display: flex; flex-direction: column; gap: 16px">
          <p class="reveal" style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">Not motivation</p>
          <h2 id="why-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(34px, 4.17vw, 60px); line-height: 1.05">Built for <em style="font-style: italic; color: #8A5208">influence</em></h2>
          <p class="read" style="margin: 0; font-size: 18px; line-height: 1.7; color: #3E342B">For musicians, artists, worship leaders and culture shapers who are:</p>
        </div>
        <ul class="stagger" style="list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(min(240px, 100%), 1fr)); gap: 16px">
<?php foreach ([
    ['Ready for global relevance', 'A framework for the global stage.'],
    ['Tired of mediocrity', 'The discipline mastery requires.'],
    ['Seeking mastery', 'For every creative discipline.'],
    ['Greatness without direction', 'Sharpen the lens, the path, the work.'],
] as [$t, $d]): ?>
          <li class="lift" style="display: flex; flex-direction: column; gap: 10px; padding: 28px; border-radius: 16px; background: #FFFFFF; border: 1px solid #E6DED2">
            <h3 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 28px; line-height: 1.1"><?= site_h($t) ?></h3>
            <p style="margin: 0; font-size: 16px; line-height: 1.65; color: #3E342B"><?= site_h($d) ?></p>
          </li>
<?php endforeach; ?>
        </ul>
      </div>
    </section>

    <section id="sessions" aria-labelledby="sessions-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(64px, 8vw, 120px) clamp(20px, 4vw, 40px); box-sizing: border-box; display: flex; flex-wrap: wrap; gap: 32px clamp(40px, 6vw, 96px)">
      <div style="flex: 1 1 280px; display: flex; flex-direction: column; gap: 16px">
        <p class="reveal" style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">Every month</p>
        <h2 id="sessions-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(32px, 3vw, 44px); line-height: 1.1">Mindset, mastery, movement</h2>
      </div>
      <ul style="flex: 2 1 480px; min-width: 0; list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column">
<?php foreach (['Mindset, discipline & artistry', 'The spirituality of music & presence', 'Leadership for creatives', 'Raw conversations & fierce insights', 'Q&A with Just-Joe Adeola', 'A growing global community of culture shapers'] as $i => $item): ?>
        <li style="padding: 18px 0; font-size: 18px; color: #211A13; <?= $i ? 'border-top: 1px solid #E6DED2' : '' ?>"><?= site_h($item) ?></li>
<?php endforeach; ?>
      </ul>
    </section>

    <section id="register" aria-labelledby="register-h" style="padding: 0 clamp(8px, 1vw, 14px) clamp(8px, 1vw, 14px)">
      <div style="max-width: 1440px; margin: 0 auto; border-radius: clamp(14px, 1.6vw, 22px); background: radial-gradient(120% 140% at 80% 0%, #5A3815 0%, #24160B 45%, #140D08 100%); color: #FCF8F3; padding: clamp(56px, 7vw, 112px) clamp(20px, 4vw, 40px); box-sizing: border-box; display: flex; flex-direction: column; align-items: center; gap: 20px; text-align: center">
        <p style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #F2A93B">Register free</p>
        <h2 id="register-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(34px, 4.17vw, 60px); line-height: 1.05">Join the next session</h2>
        <p style="margin: 0; max-width: 560px; font-size: 18px; line-height: 1.6; color: #D8CEC1">Leave your email and we will send the Google Meet link for the next monthly Power Play.</p>
        <form id="ppForm" novalidate style="width: 100%; max-width: 560px; display: flex; flex-wrap: wrap; gap: 10px; justify-content: center">
          <label for="ppEmail" class="sr">Email address</label>
          <input type="email" id="ppEmail" name="email" autocomplete="email" placeholder="you@example.com" required aria-describedby="ppMsg" style="flex: 1 1 260px; min-width: 0; min-height: 52px; padding: 0 18px; border-radius: 12px; border: 1px solid rgba(252,248,243,.35); background: rgba(252,248,243,.06); color: #FCF8F3; font: inherit; font-size: 16px; box-sizing: border-box">
          <button type="submit" class="btn" style="min-height: 52px; padding: 0 26px; border-radius: 999px; border: 0; background: #F2A93B; color: #211A13; font: inherit; font-weight: 600; cursor: pointer">Reserve my seat</button>
        </form>
        <p id="ppMsg" role="status" aria-live="polite" style="margin: 0; min-height: 24px; font-size: 15px; color: #E9E1D6"></p>
        <p style="margin: 8px 0 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #D8CEC1">Power · Influence · Legacy</p>
      </div>
    </section>

    <section aria-labelledby="next-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(64px, 8vw, 120px) clamp(20px, 4vw, 40px); box-sizing: border-box; display: flex; flex-direction: column; gap: 24px">
      <h2 id="next-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(32px, 3vw, 44px); line-height: 1.1">Go further</h2>
      <div class="stagger" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(min(320px, 100%), 1fr)); gap: 16px">
        <a href="/metamusic-order" class="lift" style="display: flex; flex-direction: column; gap: 8px; padding: 28px; border-radius: 16px; background: #FFFFFF; border: 1px solid #E6DED2; color: #211A13; text-decoration: none"><span style="font-family: 'Cormorant Garamond', Georgia, serif; font-size: 28px; font-weight: 600">The MetaMusic Order</span><span style="font-size: 16px; line-height: 1.6; color: #574B40">Go deeper: a 12-week intensive that moves musicians from performer to architect.</span><span aria-hidden="true" style="color: #8A5208">→</span></a>
        <a href="/apply" class="lift" style="display: flex; flex-direction: column; gap: 8px; padding: 28px; border-radius: 16px; background: #FFFFFF; border: 1px solid #E6DED2; color: #211A13; text-decoration: none"><span style="font-family: 'Cormorant Garamond', Georgia, serif; font-size: 28px; font-weight: 600">Apply to MAM Academy</span><span style="font-size: 16px; line-height: 1.6; color: #574B40">Power Play is the free spark. A full pathway keeps the fire structured — certification, instructors, ensemble and stage time, online or onsite.</span><span aria-hidden="true" style="color: #8A5208">→</span></a>
      </div>
    </section>
</main>
<?php if ($ppMeet !== ''): ?>
<script>window.MAM_POWERPLAY = <?= json_encode(['meetUrl' => $ppMeet], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<?php endif; ?>
<?php
echo (string) ob_get_clean();
site_footer(['site-powerplay.js']);
