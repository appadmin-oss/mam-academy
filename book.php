<?php
/**
 * /book — confirm a seat with the one-time booking fee, then pick a time. On the
 * 2026-11 redesign's shell; the behaviour is assets/js/book.js (moved out of this
 * page). The fee printed in the prose is the visitor's server-side tier
 * (mam_booking_tier(), the same function the charge uses); the gate's own figure is
 * repainted from /api/booking/pricing as before.
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';

try { $bkFee = (string) (mam_booking_tier()['label'] ?? ''); } catch (Throwable $e) { $bkFee = ''; }
if ($bkFee === '') $bkFee = site_usd(mam_registration_fee());

$bkStyle = <<<'CSS'
<meta name="robots" content="noindex">
<style>
.bk-card{background:#FFFFFF;border:1px solid #E6DED2;border-radius:20px;padding:clamp(24px,3vw,32px);box-sizing:border-box}
.bk-card h2{margin:0 0 8px;font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:34px;line-height:1.1;color:#211A13}
.bk-card p{margin:0;font-size:16px;line-height:1.65;color:#3E342B}
.book-grid{display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start}
.book-grid>.gate{flex:1 1 380px;min-width:0}
.book-grid>.truth{flex:1 1 320px;min-width:0;display:flex;flex-direction:column;gap:12px}
.gate-price{display:flex;align-items:baseline;gap:6px;margin:16px 0}
.gate-price .currency{font-size:24px;color:#574B40}
.gate-price b{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:56px;line-height:1;color:#211A13}
.gate-price .unit{font-size:14px;color:#574B40;margin-left:6px}
.gate-covers{list-style:none;margin:0 0 20px;padding:0;display:flex;flex-direction:column;gap:8px;font-size:15px;color:#3E342B}
.gate-covers li{display:flex;gap:10px}
.gate-covers li span:first-child{color:#8A5208;font-weight:600}
.gate-methods{display:flex;flex-direction:column;gap:12px}
.gate-pay{min-height:52px;border:0;border-radius:999px;background:#F2A93B;color:#211A13;font:inherit;font-size:16px;font-weight:600;cursor:pointer}
.gate-alt{min-height:48px;border-radius:999px;border:1px solid #CFC3B3;background:#FFFFFF;color:#211A13;font:inherit;font-size:15px;font-weight:600;cursor:pointer}
.gate-pay[disabled],.gate-alt[disabled]{opacity:.6;cursor:default}
.gate-msg{display:none;margin-top:16px;padding:12px 16px;border-radius:12px;font-size:15px;line-height:1.55;background:#F3EEE6;border:1px solid #E6DED2;color:#211A13}
.gate-msg.show{display:block}
.gate-msg.err{background:#FFFFFF;border:2px solid #B0361C;color:#B0361C}
.gate-trust{display:flex;flex-wrap:wrap;gap:6px 16px;margin-top:16px;font-size:13px;color:#574B40}
.truth-card{background:#F3EEE6;border-radius:16px;padding:20px}
.truth-card h3{margin:0 0 6px;font-size:16px;font-weight:600;color:#211A13}
.truth-card p{margin:0;font-size:15px;line-height:1.6;color:#3E342B}
.truth-card a{color:#8A5208}
.spinner{display:inline-block;width:14px;height:14px;border:2px solid #CFC3B3;border-top-color:#211A13;border-radius:999px;animation:bkspin .7s linear infinite;vertical-align:-2px}
@keyframes bkspin{to{transform:rotate(360deg)}}
.embed-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px}
.embed-head h2{margin:0;font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:34px;color:#211A13}
.ok-pill{display:inline-flex;align-items:center;min-height:32px;padding:0 14px;border-radius:999px;background:#F3EEE6;font-size:14px;font-weight:600;color:#211A13}
.embed-frame{width:100%;height:720px;border:1px solid #E6DED2;border-radius:16px;background:#FFFFFF}
.embed-fallback{padding:20px;border-radius:16px;background:#F3EEE6;font-size:16px;color:#211A13}
.embed-fallback p{margin:0 0 12px}
.embed-fallback a,.no-ref a.btn{display:inline-flex;align-items:center;min-height:48px;padding:0 24px;border-radius:999px;background:#211A13;color:#FAF8F4;font-weight:600;text-decoration:none}
.no-ref{max-width:640px}
.no-ref p{margin:0 0 16px}
</style>
CSS;

site_head([
    'title' => 'Book a session — MAM Academy',
    'description' => 'Book your session with our Admin Officer and MAM Auditer Judges. A one-time fee covers registration, your ID card and student file; tuition is discussed only after placement.',
    'path' => '/book',
    'jsonld' => $bkStyle,
]);
site_header('inner');
?>
<main id="main">
  <section aria-labelledby="page-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(40px, 5vw, 72px) clamp(20px, 4vw, 40px) clamp(32px, 4vw, 56px); box-sizing: border-box; display: flex; flex-direction: column; gap: 24px">
    <nav aria-label="Breadcrumb" class="fade-in" style="font-size: 14px; color: #574B40; display: flex; gap: 8px"><a href="/" class="ul" style="color: #574B40; text-decoration: none">Home</a><span aria-hidden="true">/</span><a href="/apply" class="ul" style="color: #574B40; text-decoration: none">Apply</a><span aria-hidden="true">/</span><span aria-current="page">Book a session</span></nav>
    <p class="fade-in" style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">Next step · seat confirmation</p>
    <h1 id="page-h" class="fade-in" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(46px, 5.6vw, 84px); line-height: 1.02; letter-spacing: -.01em">Book a session</h1>
    <p class="fade-in" style="margin: 0; max-width: 680px; font-size: 19px; line-height: 1.7; color: #3E342B">You’ve applied, beautifully done. The final step is a short conversation: we listen, place you accurately, and reserve your spot. A one-time confirmation locks in your seat and unlocks the calendar.</p>
  </section>

  <section style="max-width: 1200px; margin: 0 auto; padding: 0 clamp(20px, 4vw, 40px) clamp(64px, 8vw, 120px); box-sizing: border-box">
    <div id="bkLoading" class="bk-card no-ref" aria-live="polite">
      <h2><span class="spinner" aria-hidden="true"></span> Checking your application…</h2>
      <p>One moment while we find your reference in our records.</p>
    </div>

    <div id="bkNoRef" class="bk-card no-ref" hidden>
      <h2>We need your application reference</h2>
      <p>This page is for applicants we already have on file. If you’ve just applied, the link in your email comes straight here. Otherwise, please start with the application form.</p>
      <a href="/apply" class="btn">Start my application →</a>
    </div>

    <div id="bkGate" class="book-grid" hidden>
      <div class="gate bk-card" role="region" aria-labelledby="gateTitle">
        <h2 id="gateTitle">Reserve your seat</h2>
        <p id="gateSub">A one-time confirmation to lock in your meeting and your placement assessment.</p>
        <div class="gate-price" id="gatePrice" aria-label="Booking fee"><b><?= site_h($bkFee) ?></b><span class="unit">one-time</span></div>
        <ul class="gate-covers" id="gateCovers"></ul>
        <div class="gate-methods">
          <button type="button" class="gate-pay" id="payPaystack"><span id="payPaystackLabel">Pay <?= site_h($bkFee) ?> with Card / Bank</span></button>
          <button type="button" class="gate-alt" id="payBank">I’d rather bank-transfer</button>
        </div>
        <div id="gateMsg" class="gate-msg" role="status" aria-live="polite"></div>
        <div class="gate-trust"><span>Encrypted &amp; PCI-DSS</span><span>Refundable within 24h*</span><span>Real-person support</span></div>
      </div>

      <aside class="truth" aria-label="About the fee">
        <div class="truth-card">
          <h3>Why we ask for <?= site_h($bkFee) ?></h3>
          <p>Plenty of academies ask you to pay tuition before they’ve even met you. We don’t. The <?= site_h($bkFee) ?> is a <strong>good-faith confirmation</strong>: it earns you a real conversation, a real assessment and a real seat. It’s also exactly what your registration, ID card and student file actually cost us to issue. Nothing more.</p>
        </div>
        <div class="truth-card">
          <h3>Total transparency</h3>
          <p>The <?= site_h($bkFee) ?> is final and strictly non-refundable after 24 hours per our class policy. It does <strong>not</strong> include tuition: tuition is discussed and agreed only after the MAM Auditer Judges have placed you accurately. We will never spring a surprise charge on you.</p>
        </div>
        <div class="truth-card">
          <h3>Who you’ll meet</h3>
          <p>An <strong>Admin Officer</strong> who walks you through onboarding, and the <strong>MAM Auditer Judges</strong>, our placement experts. 30 minutes. Bring your instrument (or your voice). Bring questions. You’ll leave knowing exactly where you stand.</p>
        </div>
        <div class="truth-card">
          <h3>Come fully prepared</h3>
          <p>Your audition measures talent <em>and</em> readiness, for professional and ministry opportunities alike. Work through the <a href="/auditer#checklist">Applicant Checklist</a> before your session: documents, your prepared piece, backing track, portfolio, and arriving 30–60 minutes early.</p>
        </div>
      </aside>
    </div>

    <div id="bkUnlocked" class="bk-card" hidden>
      <div class="embed-head">
        <h2 id="unlockedTitle">Pick a time, <span id="unlockedName">there</span></h2>
        <span class="ok-pill">Seat reserved</span>
      </div>
      <iframe id="bookFrame" class="embed-frame" title="Schedule your MAM Academy session" loading="lazy"
              referrerpolicy="strict-origin-when-cross-origin" sandbox="allow-scripts allow-same-origin allow-forms allow-top-navigation-by-user-activation allow-popups"></iframe>
      <div id="bookFallback" class="embed-fallback" hidden>
        <p>If the calendar doesn’t load above, your browser may be blocking the embed.</p>
        <a id="bookOpenTab" target="_blank" rel="noopener noreferrer">Open the booking page in a new tab</a>
      </div>
    </div>
  </section>
</main>
<script src="/assets/js/config.js?v=20261128v"></script>
<?php site_footer(['site-ccy-bridge.js', 'book.js']); ?>
