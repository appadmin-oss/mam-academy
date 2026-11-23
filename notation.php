<?php
/**
 * One song, three expressions — the full showcase (2026-11 rebuild; the owner
 * judged the earlier "one melody in three notations" page ineffective).
 *
 * A song is arranged, not re-skinned: assets/js/expressions-engine.js reads its key,
 * beat and phrases, then harmonises and arranges it as a Classical, a
 * Contemporary and an African musician would; expressions-audio.js plays it;
 * expressions.js engraves it — a grand staff, a five-line lead sheet, and the
 * MAM 6-line staff (six lines, five spaces, doh on the bottom line) with bell,
 * shekere and dùndún staves. Songs are traditional or public domain; a visitor can
 * also sing or play one in (assets/js/notation.js supplies the pitch detector).
 * The compact version sits in /programmes beside the three expressions.
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';
site_head([
    'title' => 'One song, three expressions — MAM Academy',
    'description' => 'Hear one song arranged three ways — African, Contemporary and Classical — and see each one written out, including the MAM 6-line staff. Sing or play your own tune in.',
    'path' => '/notation',
    'jsonld' => '<link rel="stylesheet" href="/assets/css/expressions.css?v=20261123">',
]);
site_header('inner', '/programmes');
ob_start();
?>
<main id="main">
    <section aria-labelledby="page-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(40px, 5vw, 72px) clamp(20px, 4vw, 40px) clamp(24px, 3vw, 40px); box-sizing: border-box; display: flex; flex-direction: column; gap: 20px">
      <nav aria-label="Breadcrumb" class="fade-in" style="font-size: 14px; color: #574B40; display: flex; gap: 8px; flex-wrap: wrap"><a href="/" class="ul" style="color: #574B40; text-decoration: none">Home</a><span aria-hidden="true">/</span><a href="/programmes" class="ul" style="color: #574B40; text-decoration: none">Programmes</a><span aria-hidden="true">/</span><span aria-current="page">Three expressions</span></nav>
      <h1 id="page-h" class="fade-in" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(46px, 5.6vw, 84px); line-height: 1.02; letter-spacing: -.01em">One song, <em style="font-style: italic; color: #8A5208">three</em> expressions</h1>
      <p class="fade-in" style="margin: 0; max-width: 760px; font-size: 19px; line-height: 1.7; color: #3E342B">Every MAM student learns music through three expressions: African, Contemporary and Classical. Choose a song, or sing or play your own, and hear it arranged each way — with the harmony, rhythm and instruments each tradition would bring — then read it written out.</p>
    </section>

    <section aria-label="The showcase" style="max-width: 1200px; margin: 0 auto; padding: 0 clamp(20px, 4vw, 40px) clamp(48px, 6vw, 88px); box-sizing: border-box">
      <div class="xs" data-expressions data-style="african">
        <noscript><p style="font-size: 17px">The showcase needs JavaScript to arrange and play the songs.</p></noscript>
      </div>
    </section>

    <section id="how" aria-labelledby="how-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(48px, 6vw, 88px) clamp(20px, 4vw, 40px); box-sizing: border-box; border-top: 1px solid #E6DED2; display: flex; flex-wrap: wrap; gap: 24px clamp(40px, 6vw, 96px)">
      <h2 id="how-h" style="flex: 1 1 280px; margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(32px, 3vw, 44px); line-height: 1.1">What changes in each expression</h2>
      <div class="doc" style="flex: 2 1 480px; min-width: 0; display: flex; flex-direction: column; gap: 16px; font-size: 18px; line-height: 1.7; color: #3E342B">
        <p><strong>African.</strong> The tune is moved onto the twelve-pulse cycle the bell keeps, so it swings in twelve-eight. It is sung as call and response: a lead voice, then the chorus answering in thirds and fourths. The standard seven-stroke bell, the shekere and the talking drum (dùndún) play with it, the drum answering the call in the gaps, over a highlife guitar and bass. Fa and ti that carry weight lean toward the five-note scale most African songs use.</p>
        <p><strong>Contemporary.</strong> The harmony is opened up with added ninths and thirteenths, a suspended chord that resolves, a two-five into each cadence, and passing chords where the melody allows. The melody anticipates the beat the way singers do, over drums, bass and electric piano.</p>
        <p><strong>Classical.</strong> Four-part harmony on the piano, written for the hands: broken chords in the left hand through the phrase and full chords at the cadences, moving by the smallest steps, with a cadential six-four into the last chord and a slowing at the close.</p>
      </div>
    </section>

    <section id="six-line" aria-labelledby="six-h" style="max-width: 1200px; margin: 0 auto; padding: clamp(48px, 6vw, 88px) clamp(20px, 4vw, 40px); box-sizing: border-box; border-top: 1px solid #E6DED2; display: flex; flex-wrap: wrap; gap: 24px clamp(40px, 6vw, 96px)">
      <h2 id="six-h" style="flex: 1 1 280px; margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(32px, 3vw, 44px); line-height: 1.1">Reading the MAM 6-line staff</h2>
      <div class="doc" style="flex: 2 1 480px; min-width: 0; display: flex; flex-direction: column; gap: 16px; font-size: 18px; line-height: 1.7; color: #3E342B">
        <p>The African expression is written on MAM's own staff: six lines and five spaces, read in solfa. Doh is always the bottom line, whatever the key, and the key is named beside the clef (“doh = G”).</p>
        <ul><li>Lines, bottom to top: d, m, s, t, r', f'.</li><li>Spaces, bottom to top: r, f, l, d', m'.</li><li>Notes beyond the staff take short ledger lines; the solfa syllable is written under every note.</li></ul>
        <p>Time is the bell's: a bar is one twelve-pulse cycle in twelve-eight, beamed in dotted-quarter beats. The bell, shekere and dùndún are written on their own one-line staves beneath the voice. The lead's call is in gold, the chorus's response in black.</p>
      </div>
    </section>

    <section aria-labelledby="cta-h" style="padding: 0 clamp(8px, 1vw, 14px) clamp(8px, 1vw, 14px)">
      <div style="max-width: 1440px; margin: 0 auto; border-radius: clamp(14px, 1.6vw, 22px); background: radial-gradient(120% 140% at 80% 0%, #5A3815 0%, #24160B 45%, #140D08 100%); color: #FCF8F3; padding: clamp(56px, 7vw, 112px) clamp(20px, 4vw, 40px); box-sizing: border-box; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 20px">
        <h2 id="cta-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(34px, 4.17vw, 60px); line-height: 1.05">Learn all <em style="font-style: italic; color: #F2A93B">three</em></h2>
        <p style="margin: 0; max-width: 560px; font-size: 18px; line-height: 1.6; color: #D8CEC1">Every MAM programme teaches the African, Contemporary and Classical expressions, from a first lesson to diploma.</p>
        <div style="display: flex; gap: 12px; flex-wrap: wrap; justify-content: center">
          <a href="/apply" class="btn arrow" style="display: inline-flex; align-items: center; gap: 8px; min-height: 52px; padding: 0 26px; border-radius: 999px; background: #F2A93B; color: #211A13; font-weight: 600; text-decoration: none">Apply now <span aria-hidden="true">→</span></a>
          <a href="/programmes" style="display: inline-flex; align-items: center; min-height: 52px; padding: 0 25px; border-radius: 999px; border: 1px solid rgba(252,248,243,0.6); color: #FCF8F3; font-weight: 600; text-decoration: none">See the programmes</a>
        </div>
      </div>
    </section>
</main>
<?php
echo (string) ob_get_clean();
site_footer(['notation.js', 'expressions-engine.js', 'expressions-audio.js', 'expressions.js']);
