<?php
/**
 * Questions — the FAQ on the 2026-11 redesign shell (partials/site/). The handoff
 * did not draw it; it is built from the handoff's own parts (the About page's
 * breadcrumb and serif H1, hairline-ruled rows) with native <details>, so every
 * answer opens without script.
 *
 * Each fee is the server's (site_money / site_fee), never typed. The FAQPage
 * structured data is built here from the same list the page prints, so the two
 * cannot drift.
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';

$fqA = 'color: #8A5208';
$fqItems = [
    ['What is MAM Auditer and why is it required?',
     'MAM Auditer is our structured entry assessment system. It measures 10 dimensions — rhythm intelligence, ear sensitivity, performance confidence, technical knowledge, artistic potential, discipline and learning capacity — so we place you in the right pathway from day one. Students are not randomly placed; they are intelligently assessed.'],
    ['Can I study online or do I have to come to Lagos?',
     'All MAM programmes are offered in two modes. <strong>Online:</strong> live video classes, online coaching, digital assignments, virtual rehearsals — from anywhere. <strong>Onsite:</strong> in-person at MAM Centre, Lagos — for practical rehearsals, ensemble training, live stagecraft and group interactions.'],
    ['How are MAM certifications equivalent to Trinity London?',
     'Our four pathways map directly to Trinity College London grade bands: Pre-Certification (Grade 1–2), Certification (Grade 3–4), Pre-Diploma (Grade 5–6) and Diploma (Grade 7–8). Plus the MAM Method adds rhythm intelligence, performance intelligence and creative consciousness on top.'],
    ['What does each pathway cost?',
     'Certified Musician from ' . site_money('from:certified-musician') . ' (one tradition) · Grand Musician from ' . site_money('from:grand-musician') . ' (all three traditions, ' . site_fee('grand') . ' off), both spanning all four certification levels and available online or onsite. Registration is ' . site_money('reg') . ' (one-time). Crash Programmes are a flat ' . site_money('crash') . ' for a 6-week accelerated intensive. The MetaMusic Order (12 weeks) is ' . site_money('meta') . '.'],
    ['What is The MetaMusic Order?',
     'A 12-week civilization-engineering intensive that moves musicians from <em>Performer</em> → <em>Architect</em>. It immerses students in sound psychology, cultural engineering, atmosphere architecture, performance intelligence and national responsibility. <a href="/metamusic-order" class="ul" style="' . $fqA . '">Read more →</a>'],
    ['What is Power Play?',
     'Power Play is a <strong>free</strong> monthly global series hosted by Just-Joe Adeola on Google Meet. It’s a fierce monthly gathering for musicians, creatives, worship leaders and culture shapers. <a href="/power-play" class="ul" style="' . $fqA . '">Register free →</a>'],
    ['What age can students start?',
     'From age 4. Our youngest students follow an early-childhood program developing coordination, rhythm and a love for music through play-based learning.'],
    ['How do I pay? Which methods do you accept?',
     'Through the apply page you can pay securely via <strong>Paystack</strong> (cards, bank, USSD, QR, mobile money), <strong>Flutterwave</strong> (cards, M-Pesa, Apple/Google Pay), <strong>Stripe</strong> (international cards), or <strong>bank transfer</strong>. All transactions are encrypted; we never see your card details.'],
    ['Do I need to own an instrument?',
     'For in-person lessons we provide instruments during class for beginners. We strongly recommend home practice and can advise on affordable instruments within your budget.'],
    ['How many lessons per week?',
     'Most programmes are one 60-minute lesson per week. Theory classes are twice weekly. We recommend 20–30 minutes of daily home practice for optimal progress.'],
    ['Are weekend classes available?',
     'Yes. We offer both weekday and weekend slots. Saturday morning sessions are particularly popular for school-age students and working adults.'],
    ['What’s the connection between MAM Academy and MAM Centre?',
     'MAM Academy is the training arm of MAM Centre — A Centre for Honourable Music, Arts &amp; Formation. The ecosystem also includes MAM Auditer (assessment platform) and FH-MAM (Fellowship for Honourable Musicians).'],
    ['Can I pause or cancel my enrollment?',
     'You may pause for up to 4 weeks per term and we hold your seat. For cancellations, give at least 2 weeks’ notice before the next billing date. Email <a href="mailto:academy@mamcentre.org" class="ul" style="' . $fqA . '">academy@mamcentre.org</a>.'],
    ['Is there a performance or recital at the end of term?',
     'Yes — each term ends in a student showcase. Stage experience is essential to formation, and parents, family and friends are warmly invited.'],
];

$fqLd = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(static fn($i) => [
    '@type' => 'Question', 'name' => $i[0],
    'acceptedAnswer' => ['@type' => 'Answer', 'text' => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($i[1]), ENT_QUOTES, 'UTF-8')))],
], $fqItems)];

site_head([
    'title' => 'Questions — MAM Academy',
    'description' => 'Everything you need to know about enrolling and studying at MAM Academy: assessment, online and onsite study, certification, fees and payment.',
    'path' => '/faq',
    'jsonld' => '<script type="application/ld+json">' . json_encode($fqLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>',
]);
site_header('inner');
ob_start();
?>
<style>
  .faq summary { list-style: none; }
  .faq summary::-webkit-details-marker { display: none; }
  .faq summary .pm { transition: transform .5s cubic-bezier(.19, 1, .22, 1); }
  .faq details[open] summary .pm { transform: rotate(45deg); }
  .faq summary:focus-visible { outline: 3px solid #F2A93B; outline-offset: 4px; border-radius: 10px; }
</style>
<main id="main">
<?php
site_doc_open('Questions', 'Questions & answers', 'Everything you need to know about enrolling and studying at MAM Academy.');
?>
    <section id="questions" aria-label="Frequently asked questions" style="max-width: 1200px; margin: 0 auto; padding: 0 clamp(20px, 4vw, 40px) clamp(48px, 6vw, 88px); box-sizing: border-box">
      <div class="faq" style="max-width: 860px; border-top: 1px solid #E6DED2">
<?php foreach ($fqItems as [$q, $a]): ?>
        <details style="border-bottom: 1px solid #E6DED2">
          <summary style="display: flex; justify-content: space-between; align-items: center; gap: 24px; min-height: 44px; padding: 22px 0; cursor: pointer; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: clamp(22px, 1.9vw, 28px); line-height: 1.2; color: #211A13"><?= site_h($q) ?><span class="pm" aria-hidden="true" style="flex: none; font-family: Inter, sans-serif; font-weight: 400; font-size: 28px; line-height: 1; color: #8A5208">+</span></summary>
          <div style="padding: 0 0 24px; max-width: 760px; font-size: 17px; line-height: 1.7; color: #3E342B"><?= $a ?></div>
        </details>
<?php endforeach; ?>
      </div>
    </section>

    <section aria-labelledby="still-h" style="padding: 0 clamp(8px, 1vw, 14px) clamp(8px, 1vw, 14px)">
      <div style="max-width: 1440px; margin: 0 auto; border-radius: clamp(14px, 1.6vw, 22px); background: radial-gradient(120% 140% at 80% 0%, #5A3815 0%, #24160B 45%, #140D08 100%); color: #FCF8F3; padding: clamp(56px, 7vw, 112px) clamp(20px, 4vw, 40px); box-sizing: border-box; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 20px">
        <h2 id="still-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(34px, 4.17vw, 60px); line-height: 1.05">Still have a <em style="font-style: italic; color: #F2A93B">question?</em></h2>
        <p style="margin: 0; max-width: 560px; font-size: 18px; line-height: 1.6; color: #D8CEC1">We reply within 24 hours.</p>
        <div style="display: flex; flex-wrap: wrap; justify-content: center; gap: 12px">
          <a href="/contact" class="btn arrow" style="display: inline-flex; align-items: center; gap: 8px; min-height: 52px; padding: 0 26px; border-radius: 999px; background: #F2A93B; color: #211A13; font-weight: 600; text-decoration: none">Contact us <span aria-hidden="true">→</span></a>
          <a href="/apply" class="btn" style="display: inline-flex; align-items: center; gap: 8px; min-height: 52px; padding: 0 26px; border-radius: 999px; border: 1px solid rgba(252,248,243,.6); color: #FCF8F3; font-weight: 600; text-decoration: none">Apply now</a>
        </div>
        <a href="/compare" class="ul" style="display: inline-flex; align-items: center; min-height: 44px; color: #D8CEC1; text-decoration: none">Or compare all programmes</a>
      </div>
    </section>
</main>
<?php
echo (string) ob_get_clean();
site_footer();
