<?php
/**
 * Compare programmes — on the 2026-11 redesign shell (partials/site/).
 *
 * One list ($cpProgs) draws the desktop table, the phone cards (rendered here,
 * not by script, so they work with JavaScript off) and the data MAM Match
 * recommends from (assets/js/site-compare.js). Every price is the server's:
 * site_money('from:…') on the page and window.MAM_SITE.fees in the recommender.
 * The old compare.js carried its own prices, and they had drifted ($230, $310,
 * $40 against the $240, $313, $70 the server charges); that file is no longer
 * loaded.
 */
declare(strict_types=1);
require __DIR__ . '/partials/site/shell.php';

$cpRows = [
    ['bestFor', 'Best for'], ['length', 'Length'], ['modes', 'Online & onsite'], ['auditer', 'MAM Auditer required'],
    ['levels', 'Certification levels'], ['traditions', 'Traditions'], ['supporting', 'Supporting courses'],
    ['performance', 'Performance Intelligence'], ['certificate', 'Certificate at end'], ['registration', 'Registration fee'], ['graduation', 'Graduation / included'],
];
$cpHeadline = ['bestFor', 'length', 'modes', 'certificate'];
$cpProgs = [
    ['id' => 'certified-musician', 'label' => 'Certified Musician', 'name' => 'One tradition', 'meta' => 'All four levels · 12 weeks', 'price' => 'from ' . site_money('from:certified-musician'), 'unit' => '',
     'tag' => 'Most chosen', 'featured' => true, 'more' => ['/pathways#certified-musician', 'Pathway page'],
     'match' => ['levels' => ['none', 'some', 'intermediate', 'advanced'], 'goals' => ['perform', 'worship', 'exam'], 'pace' => 'ongoing', 'budget' => 2],
     'f' => ['bestFor' => 'Focused mastery in one tradition', 'length' => '12 weeks', 'modes' => '✓ Both', 'auditer' => '✓', 'levels' => 'All four (Pre-Cert → Diploma)',
             'traditions' => 'One (Contemporary, Classical or African)', 'supporting' => 'Rhythm · APE · Meta Music · Theory', 'performance' => '✓✓', 'certificate' => '✓',
             'registration' => 'Once', 'graduation' => '✓ Included']],
    ['id' => 'grand-musician', 'label' => 'Grand Musician', 'name' => 'All three traditions', 'meta' => 'All four levels · 12 weeks · ' . site_fee('grand') . ' off', 'price' => 'from ' . site_money('from:grand-musician'), 'unit' => '',
     'tag' => 'Flagship', 'featured' => false, 'more' => ['/pathways#grand-musician', 'Pathway page'],
     'match' => ['levels' => ['some', 'intermediate', 'advanced'], 'goals' => ['perform', 'lead', 'exam', 'produce', 'culture'], 'pace' => 'ongoing', 'budget' => 3],
     'f' => ['bestFor' => 'Complete musicians across all traditions', 'length' => '12 weeks', 'modes' => '✓ Both', 'auditer' => '✓', 'levels' => 'All four (Pre-Cert → Diploma)',
             'traditions' => 'All three (African + Classical + Contemporary)', 'supporting' => 'Rhythm · APE · Meta Music · Theory', 'performance' => '✓✓✓', 'certificate' => '✓ Diploma',
             'registration' => 'Once', 'graduation' => '✓ Included']],
    ['id' => 'crash', 'label' => 'Crash', 'name' => 'Intensive', 'meta' => '6 weeks · any school', 'price' => site_money('crash'), 'unit' => ' flat',
     'tag' => '', 'featured' => false, 'more' => ['/crash', 'Crash page'],
     'match' => ['levels' => ['none', 'some', 'intermediate', 'advanced'], 'goals' => ['perform', 'produce', 'worship'], 'pace' => 'intensive', 'budget' => 1],
     'f' => ['bestFor' => 'Quick wins · summer · busy pros', 'length' => '6 weeks fixed', 'modes' => '✓ Both', 'auditer' => 'Recommended', 'levels' => '—',
             'traditions' => 'Per chosen course', 'supporting' => 'Per chosen course', 'performance' => 'Per chosen course', 'certificate' => 'Certificate of completion',
             'registration' => 'Once', 'graduation' => 'Certificate of completion']],
    ['id' => 'meta-order', 'label' => 'MetaMusic Order', 'name' => 'Civilization Architect', 'meta' => '12 weeks · post-graduate', 'price' => site_money('meta'), 'unit' => ' for 12 weeks',
     'tag' => 'Premium', 'featured' => false, 'more' => ['/metamusic-order', 'The Order'],
     'match' => ['levels' => ['advanced'], 'goals' => ['culture', 'produce', 'lead'], 'pace' => 'intensive', 'budget' => 3],
     'f' => ['bestFor' => 'Producers, performers, cultural leaders', 'length' => '12 weeks fixed', 'modes' => '✓ Online focus', 'auditer' => '✓ + interview', 'levels' => 'Post-Diploma',
             'traditions' => '—', 'supporting' => '✓ Core focus', 'performance' => '✓✓✓', 'certificate' => '✓ Order commission',
             'registration' => 'Included', 'graduation' => 'Included']],
];

/** One cell's value: a leading tick reads "Yes", a dash reads "Not included". */
function cp_val(string $v): string {
    if ($v === '—') return '<span aria-hidden="true" style="color: #574B40">—</span><span class="sr">Not included</span>';
    if (preg_match('/^(✓+)\s?(.*)$/u', $v, $m)) {
        $n = mb_strlen($m[1]);
        $label = $m[2] !== '' ? site_h($m[2]) : ($n > 1 ? '' : 'Yes');
        return '<span aria-hidden="true" style="color: #C27A12; font-weight: 600">' . $m[1] . '</span> ' . ($n > 1 ? '<span class="sr">' . $n . ' of 3</span>' : '') . $label;
    }
    return site_h($v);
}

$cpMatch = array_map(static fn($p) => ['id' => $p['id'], 'label' => $p['label'], 'name' => $p['name'], 'unit' => $p['unit']] + $p['match'], $cpProgs);

site_head([
    'title' => 'Compare programmes — MAM Academy',
    'description' => 'Side-by-side comparison of every MAM Academy programme — Certified Musician, Grand Musician, Crash and The MetaMusic Order. Pick what fits.',
    'path' => '/compare',
]);
site_header('inner', '/programmes');
ob_start();
?>
<style>
  .cp-table { width: 100%; border-collapse: collapse; font-size: 15px; line-height: 1.5; }
  .cp-table th, .cp-table td { padding: 14px 16px; text-align: left; vertical-align: top; border-bottom: 1px solid #E6DED2; }
  .cp-table tbody th { font-weight: 600; color: #211A13; width: 200px; }
  .cp-table td { color: #3E342B; }
  .cp-table .cp-feat { background: #F3EEE6; }
  .cp-cards { display: none; }
  @media (max-width: 720px) { .cp-desk { display: none; } .cp-cards { display: grid; } }
  .cp-cards summary { list-style: none; cursor: pointer; }
  .cp-cards summary::-webkit-details-marker { display: none; }
  .cp-opt[aria-pressed="true"] { background: #211A13 !important; color: #FAF8F4 !important; border-color: #211A13 !important; }
  .cp-flash { box-shadow: 0 0 0 3px #F2A93B; transition: box-shadow .6s ease; }
</style>
<main id="main">
<?php
site_doc_open('Compare', 'Find your programme',
    'Answer four quick questions and let <strong>MAM Match</strong> recommend your fit — or compare every programme side by side below.');
?>
    <section aria-label="MAM Match" style="max-width: 1200px; margin: 0 auto; padding: 0 clamp(20px, 4vw, 40px) clamp(40px, 5vw, 64px); box-sizing: border-box">
      <div id="mamMatch" hidden style="max-width: 860px; padding: clamp(24px, 3vw, 36px); border-radius: 20px; background: #FFFFFF; border: 1px solid #E6DED2"></div>
      <script type="application/json" id="cpData"><?= json_encode($cpMatch, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    </section>

    <section id="table" aria-labelledby="cp-h" style="max-width: 1200px; margin: 0 auto; padding: 0 clamp(20px, 4vw, 40px) clamp(48px, 6vw, 88px); box-sizing: border-box; display: flex; flex-direction: column; gap: 20px">
      <div style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 12px 32px">
        <h2 id="cp-h" style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 500; font-size: clamp(32px, 3vw, 44px); line-height: 1.1">Every programme, side by side</h2>
        <span class="cur-sw cur-sw--light" role="group" aria-label="Show prices in"><?php foreach (['USD' => '$ USD', 'NGN' => '₦ NGN', 'GBP' => '£ GBP'] as $c => $t): ?><button type="button" data-currency="<?= $c ?>" aria-pressed="<?= $c === 'USD' ? 'true' : 'false' ?>"><?= $t ?></button><?php endforeach; ?></span>
      </div>

      <div class="cp-desk" style="border: 1px solid #E6DED2; border-radius: 16px; background: #FFFFFF; overflow: hidden">
        <table class="cp-table">
          <caption class="sr">Comparison of the four MAM Academy programmes across price, levels, modes, intelligences and certification.</caption>
          <thead>
            <tr>
              <td style="border-bottom: 1px solid #E6DED2"></td>
<?php foreach ($cpProgs as $p): ?>
              <th scope="col" class="<?= $p['featured'] ? 'cp-feat' : '' ?>" style="padding: 20px 16px">
<?php if ($p['tag'] !== ''): ?>
                <span style="display: inline-block; margin-bottom: 8px; padding: 2px 10px; border-radius: 999px; background: #F2A93B; color: #211A13; font-size: 13px; font-weight: 600"><?= site_h($p['tag']) ?></span><br>
<?php endif; ?>
                <span style="font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 24px; line-height: 1.1; color: #211A13"><?= site_h($p['label']) ?></span><br>
                <span style="font-weight: 400; font-size: 14px; color: #574B40"><?= site_h($p['name']) ?> · <?= site_h($p['meta']) ?></span><br>
                <span style="display: inline-block; margin-top: 8px; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 28px; color: #211A13"><?= $p['price'] ?></span><span style="font-weight: 400; font-size: 14px; color: #574B40"><?= site_h($p['unit']) ?></span>
              </th>
<?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
<?php foreach ($cpRows as [$k, $label]): ?>
            <tr>
              <th scope="row"><?= site_h($label) ?><?= $k === 'registration' ? ' (+' . site_money('reg') . ')' : '' ?></th>
<?php foreach ($cpProgs as $p): ?>
              <td class="<?= $p['featured'] ? 'cp-feat' : '' ?>"><?= cp_val($p['f'][$k]) ?></td>
<?php endforeach; ?>
            </tr>
<?php endforeach; ?>
            <tr>
              <th scope="row">Learn more</th>
<?php foreach ($cpProgs as $p): ?>
              <td class="<?= $p['featured'] ? 'cp-feat' : '' ?>"><a href="<?= site_h($p['more'][0]) ?>" class="ul" style="color: #8A5208; font-weight: 600"><?= site_h($p['more'][1]) ?></a></td>
<?php endforeach; ?>
            </tr>
            <tr>
              <td style="border-bottom: 0"></td>
<?php foreach ($cpProgs as $p): ?>
              <td class="<?= $p['featured'] ? 'cp-feat' : '' ?>" style="border-bottom: 0"><a href="/apply?pathway=<?= $p['id'] ?>" class="btn" style="display: inline-flex; align-items: center; min-height: 44px; padding: 0 20px; border-radius: 999px; <?= $p['id'] === 'crash' ? 'border: 1px solid #CFC3B3; color: #211A13' : 'background: #F2A93B; color: #211A13' ?>; font-weight: 600; text-decoration: none">Apply<span class="sr"> for <?= site_h($p['label']) ?></span></a></td>
<?php endforeach; ?>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="cp-cards" style="gap: 16px">
<?php foreach ($cpProgs as $p): ?>
        <article id="pc-<?= $p['id'] ?>" style="display: flex; flex-direction: column; gap: 10px; padding: 24px; border-radius: 20px; background: <?= $p['featured'] ? '#F3EEE6' : '#FFFFFF' ?>; border: 1px solid #E6DED2">
<?php if ($p['tag'] !== ''): ?>
          <span style="align-self: flex-start; padding: 2px 10px; border-radius: 999px; background: #F2A93B; color: #211A13; font-size: 13px; font-weight: 600"><?= site_h($p['tag']) ?></span>
<?php endif; ?>
          <h3 style="margin: 0; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 28px; line-height: 1.1"><?= site_h($p['label']) ?></h3>
          <p style="margin: 0; font-size: 14px; color: #574B40"><?= site_h($p['name']) ?> · <?= site_h($p['meta']) ?></p>
          <p style="margin: 0"><span style="font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 32px"><?= $p['price'] ?></span><span style="font-size: 14px; color: #574B40"><?= site_h($p['unit']) ?></span></p>
          <dl style="margin: 0; display: flex; flex-direction: column">
<?php foreach ($cpRows as [$k, $label]): if (!in_array($k, $cpHeadline, true)) continue; ?>
            <div style="display: flex; justify-content: space-between; gap: 16px; padding: 10px 0; border-top: 1px solid #E6DED2; font-size: 15px"><dt style="color: #574B40"><?= site_h($label) ?></dt><dd style="margin: 0; text-align: right; color: #211A13"><?= cp_val($p['f'][$k]) ?></dd></div>
<?php endforeach; ?>
          </dl>
          <details>
            <summary style="display: flex; align-items: center; min-height: 44px; font-weight: 600; font-size: 15px; color: #8A5208">See the full breakdown</summary>
            <dl style="margin: 0; display: flex; flex-direction: column">
<?php foreach ($cpRows as [$k, $label]): if (in_array($k, $cpHeadline, true)) continue; ?>
              <div style="display: flex; justify-content: space-between; gap: 16px; padding: 10px 0; border-top: 1px solid #E6DED2; font-size: 15px"><dt style="color: #574B40"><?= site_h($label) ?></dt><dd style="margin: 0; text-align: right; color: #211A13"><?= cp_val($p['f'][$k]) ?></dd></div>
<?php endforeach; ?>
            </dl>
          </details>
          <a href="/apply?pathway=<?= $p['id'] ?>" class="btn arrow" style="display: inline-flex; justify-content: center; align-items: center; gap: 8px; min-height: 48px; padding: 0 22px; border-radius: 999px; background: #F2A93B; color: #211A13; font-weight: 600; text-decoration: none">Apply for <?= site_h($p['label']) ?> <span aria-hidden="true">→</span></a>
        </article>
<?php endforeach; ?>
      </div>
      <p style="margin: 0; font-size: 16px; color: #3E342B">Not sure? <a href="/auditer" class="ul" style="color: #8A5208; font-weight: 600">Book MAM Auditer first</a> — we’ll place you accurately.</p>
    </section>

    <section aria-labelledby="cp-next-h" style="max-width: 1200px; margin: 0 auto; padding: 0 clamp(20px, 4vw, 40px) clamp(48px, 6vw, 88px); box-sizing: border-box; display: flex; flex-wrap: wrap; gap: 16px">
      <h2 id="cp-next-h" class="sr">Continue your journey</h2>
      <a href="/pathways" class="lift" style="flex: 1 1 280px; display: flex; flex-direction: column; gap: 8px; padding: 28px; border-radius: 16px; background: #FFFFFF; border: 1px solid #E6DED2; color: #211A13; text-decoration: none"><span style="font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">Previous</span><span style="font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 28px; line-height: 1.1">Pathways overview</span></a>
      <a href="/auditer" class="lift" style="flex: 2 1 380px; display: flex; flex-direction: column; gap: 8px; padding: 28px; border-radius: 16px; background: #FFFFFF; border: 1px solid #E6DED2; color: #211A13; text-decoration: none"><span style="font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #8A5208">Up next</span><span style="font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; font-size: 28px; line-height: 1.1">Book MAM Auditer <span aria-hidden="true" style="color: #8A5208">→</span></span><span style="font-size: 16px; line-height: 1.6; color: #3E342B">Before you apply, get assessed. MAM Auditer measures 10 dimensions of musical readiness so you’re placed where you actually belong.</span></a>
    </section>
</main>
<?php
echo (string) ob_get_clean();
site_footer(['site-compare.js']);
