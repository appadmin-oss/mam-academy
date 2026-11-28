<?php
/** Intake funnel — where applicants stop. */
declare(strict_types=1);
/** @var array $d */
$steps = $d['steps'] ?? [];
$worst = null;
foreach ($steps as $s) if ($worst === null || (int) $s['dropped'] > (int) $worst['dropped']) $worst = $s;
// One number per screen of /apply, fixed (FUNNEL in assets/js/apply-flow.js).
$stepNames = [1 => 'Who it is for', 2 => 'Name', 3 => 'Date of birth', 4 => 'Sex', 5 => 'Instrument', 6 => 'Programme',
              7 => 'Grand: other disciplines', 8 => 'Starting level', 9 => 'Style', 10 => 'Focus', 11 => 'Mode',
              12 => 'Contact', 13 => 'Address', 14 => 'Photos & recordings', 15 => 'Review', 16 => 'Payment', 17 => 'Done'];
?>
<?php ac_head('Intake funnel', 'Where applicants stop inside the application form (/apply), all sessions.'); ?>

<?php ac_kpis([
    ['label' => 'Started', 'value' => number_format((int) ($d['starts'] ?? 0)), 'note' => 'opened step 1'],
    ['label' => 'Submitted', 'value' => number_format((int) ($d['submitted'] ?? 0)),
     'note' => $d['conversion'] !== null ? (int) $d['conversion'] . '% of starts' : 'no starts recorded'],
    ['label' => 'Biggest drop', 'value' => $worst ? ('Step ' . (int) $worst['step']) : '—',
     'tone' => 'warn', 'note' => $worst ? ((int) $worst['dropped'] . ' left there') : 'nothing measured yet'],
]); ?>

<?php ac_card_open('Step by step', '', '<span class="ac-cardmeta">One row per wizard step</span>'); ?>
  <div class="ac-tablewrap">
    <table class="ac-table">
      <thead><tr><th>Step</th><th class="ac-num">Reached</th><th class="ac-num">Dropped</th><th>Continued</th></tr></thead>
      <tbody>
        <?php if (!$steps): ?>
          <?php ac_table_empty(4, 'No funnel events recorded yet. The form reports each screen reached — only for visitors who allow measuring (Do-Not-Track and a declined cookie banner are honoured).'); ?>
        <?php else: foreach ($steps as $s): ?>
          <tr>
            <td><span class="ac-strong"><?= (int) $s['step'] ?></span> ·
                <?= ac_h($stepNames[(int) $s['step']] ?? 'Step ' . (int) $s['step']) ?></td>
            <td class="ac-num"><?= number_format((int) $s['reached']) ?></td>
            <td class="ac-num"><?= (int) $s['dropped'] > 0 ? '−' . number_format((int) $s['dropped']) : '—' ?></td>
            <td><div class="ac-row"><?= ac_meter((int) $s['pct'], 'accent') ?>
                <span class="ac-pctfig"><?= (int) $s['pct'] ?>%</span></div></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
<?php ac_card_close('Reached is a distinct visitor, not a page view, so a person who goes back a step is not counted twice.'); ?>

<div class="ac-cols ac-cols--2">
  <?php ac_card_open('By pathway', 'Distinct visitors'); ?>
    <div class="ac-cardbody">
      <?php if (!($d['by_pathway'] ?? [])): ?>
        <div class="ac-note">No pathway recorded on any funnel event.</div>
      <?php else: ?>
        <?php $max = max(array_map(fn($p) => (int) $p['n'], $d['by_pathway'])); ?>
        <div class="ac-bars">
          <?php foreach ($d['by_pathway'] as $p): ?>
            <?php ac_bar(ucfirst((string) $p['pathway']), mam_console_pct((int) $p['n'], max(1, $max)), (string) (int) $p['n']); ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php ac_card_close('A pathway that converts better is usually a fact about the form, not about the applicants — a route that skips a placement audition has less to abandon.'); ?>
</div>
