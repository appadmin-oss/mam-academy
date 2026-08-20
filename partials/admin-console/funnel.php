<?php
/** Intake funnel — where applicants stop. */
declare(strict_types=1);
/** @var array $d */
$steps = $d['steps'] ?? [];
$worst = null;
foreach ($steps as $s) if ($worst === null || (int) $s['dropped'] > (int) $worst['dropped']) $worst = $s;
$stepNames = [1 => 'Programme & pathway', 2 => 'Your details', 3 => 'Experience & goals', 4 => 'Review', 5 => 'Payment'];
?>
<?php ac_head('Intake funnel', 'Where applicants stop. Every intake type, all sessions.'); ?>

<?php ac_kpis([
    ['label' => 'Started', 'value' => number_format((int) ($d['starts'] ?? 0)), 'note' => 'opened step 1'],
    ['label' => 'Submitted', 'value' => number_format((int) ($d['submitted'] ?? 0)),
     'note' => $d['conversion'] !== null ? (int) $d['conversion'] . '% of starts' : 'no starts recorded'],
    ['label' => 'Biggest drop', 'value' => $worst ? ('Step ' . (int) $worst['step']) : '—',
     'tone' => 'warn', 'note' => $worst ? ((int) $worst['dropped'] . ' left there') : 'nothing measured yet'],
    ['label' => 'Half-finished', 'value' => number_format((int) ($d['drafts'] ?? 0)), 'tone' => 'warn',
     'note' => 'drafts that never became an application'],
]); ?>

<?php ac_card_open('Step by step', '', '<span class="ac-cardmeta">One row per wizard step</span>'); ?>
  <div class="ac-tablewrap">
    <table class="ac-table">
      <thead><tr><th>Step</th><th class="ac-num">Reached</th><th class="ac-num">Dropped</th><th>Continued</th></tr></thead>
      <tbody>
        <?php if (!$steps): ?>
          <?php ac_table_empty(4, 'No funnel events recorded. The wizard writes one per step reached.'); ?>
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
  <?php ac_card_open('The largest pool is the one nothing reaches'); ?>
    <div class="ac-cardbody">
      <p class="ac-note"><strong><?= number_format((int) ($d['drafts'] ?? 0)) ?> half-finished applications</strong>
      sit in <span class="ac-mono">application_drafts</span> and were never submitted. Compare that with everything
      the academy declines and everything it is still waiting on: these are the cheapest families to recruit, and
      today nothing reaches them.</p>
      <div class="ac-row ac-mt">
        <a class="ac-btn" href="/admin/?v=applications">Read the abandoned drafts</a>
        <a class="ac-btn" href="<?= ac_h(ac_url('comms')) ?>">Draft a nudge</a>
      </div>
    </div>
  <?php ac_card_close('A recovery email exists on the application record (recovery_sent_at) — this is the list it should be pointed at.'); ?>

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
