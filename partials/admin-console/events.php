<?php
/** Recitals & events — a running order, and who may be photographed. */
declare(strict_types=1);
/** @var array $d */
$r = $d['recital'] ?? null;
$items = $d['items'] ?? [];
$accepted = array_values(array_filter($items, fn($i) => (string) $i['status'] === 'accepted'));
$pending  = array_values(array_filter($items, fn($i) => (string) $i['status'] === 'submitted'));
$minutes  = array_sum(array_map(fn($i) => (int) ($i['duration_min'] ?? 0), $accepted));
$noPhoto  = array_values(array_filter($accepted, fn($i) => (int) ($i['photo_consent'] ?? 0) !== 1));
$clock = 0;
?>
<?php ac_head('Recitals & events',
    $r ? ac_h((string) $r['title']) . ' · ' . ac_h(mam_console_date((string) $r['starts_at'], 'l j F, H:i'))
       . ($r['venue'] ? ' · ' . ac_h((string) $r['venue']) : '')
       : 'No recital selected',
    '<a class="ac-btn" href="/admin/?v=recitals">Programme a recital</a>'); ?>

<?php if (count($d['recitals'] ?? []) > 1): ?>
  <div class="ac-tabs">
    <?php foreach ($d['recitals'] as $rc): ?>
      <a class="ac-tab" href="<?= ac_h(ac_url('events', ['recital' => (int) $rc['id']])) ?>"
         <?= (int) $rc['id'] === (int) ($d['selected'] ?? 0) ? 'aria-current="true"' : '' ?>>
        <?= ac_h((string) $rc['title']) ?><span class="ac-tabn"><?= (int) $rc['items'] ?></span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (!$r): ?>
  <?php ac_card_open(); ?>
    <div class="ac-cardbody"><?php ac_empty('No recitals yet',
      'A recital collects submissions from instructors, then a running order is built from what is accepted.',
      '<a class="ac-btn" href="/admin/?v=recitals">Create one</a>'); ?></div>
  <?php ac_card_close(); ?>
<?php else: ?>

<?php ac_kpis([
    ['label' => 'Submitted', 'value' => (string) count($items), 'note' => 'pieces on this programme'],
    ['label' => 'Accepted', 'value' => (string) count($accepted), 'note' => 'in the running order'],
    ['label' => 'Running time', 'value' => $minutes . ' min', 'note' => 'accepted pieces only, no interval'],
    ['label' => 'No photo consent', 'value' => (string) count($noPhoto),
     'tone' => count($noPhoto) > 0 ? 'warn' : '', 'note' => 'flagged to the photographer'],
]); ?>

<?php if ($noPhoto): ?>
  <?php ac_banner('warn', 'fa-camera',
    '<strong>' . count($noPhoto) . ' performer' . (count($noPhoto) === 1 ? '' : 's')
    . ' without photography consent.</strong> The photographer\'s brief reads this list directly. Where two guardians '
    . 'disagree, the child counts as not consented.'); ?>
<?php endif; ?>

<div class="ac-cols ac-cols--main">
  <?php ac_card_open('Running order', count($accepted) . ' piece' . (count($accepted) === 1 ? '' : 's'),
      '<a class="ac-btn ac-btn--sm" href="/admin/?v=recitals&amp;recital=' . (int) $r['id'] . '">Print the programme</a>'); ?>
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead><tr><th class="ac-num">#</th><th class="ac-num">Time</th><th>Work</th><th>Performer</th><th>Section</th><th class="ac-num">Min</th></tr></thead>
        <tbody>
          <?php if (!$accepted): ?>
            <?php ac_table_empty(6, 'Nothing accepted yet. Accepting a piece places it at the end of its section; ordering is a separate step.'); ?>
          <?php else: $n = 0; $start = strtotime((string) $r['starts_at']);
            foreach ($accepted as $i): $n++; ?>
            <tr>
              <td class="ac-num"><?= $n ?></td>
              <td class="ac-num ac-mono"><?= ac_h(date('H:i', $start + $clock * 60)) ?></td>
              <td><div class="ac-strong"><?= ac_h((string) $i['title']) ?>
                  <?php if ((int) ($i['photo_consent'] ?? 0) !== 1): ?><?= ac_pill('No photo', 'warn') ?><?php endif; ?></div>
                  <?php if (!empty($i['composer'])): ?><div class="ac-sub"><?= ac_h((string) $i['composer']) ?></div><?php endif; ?></td>
              <td><?= ac_h((string) ($i['performer'] ?: '—')) ?></td>
              <td><?= (string) ($i['section'] ?? '') !== '' ? ac_tag((string) $i['section']) : '<span class="ac-sub">—</span>' ?></td>
              <td class="ac-num"><?= (int) ($i['duration_min'] ?? 0) ?></td>
            </tr>
            <?php $clock += (int) ($i['duration_min'] ?? 0); ?>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php ac_card_close('Times are computed from the door time and each piece\'s duration, so a reorder recalculates them rather than needing them re-typed.'); ?>

  <div class="ac-stack">
    <?php ac_card_open('Waiting on a decision', count($pending) . ''); ?>
      <div class="ac-cardbody">
        <?php if (!$pending): ?>
          <?php ac_empty('Nothing waiting', 'Every submission has been decided.'); ?>
        <?php else: foreach ($pending as $i): ?>
          <div class="ac-banner ac-banner--flat ac-mb-sm">
            <div class="ac-grow">
              <div class="ac-bannertitle"><?= ac_h((string) $i['title']) ?></div>
              <div class="ac-sub"><?= ac_h(trim(implode(' · ', array_filter([
                  (string) ($i['performer'] ?? ''), (int) ($i['duration_min'] ?? 0) . ' min',
                  (string) ($i['composer'] ?? '')])))) ?></div>
            </div>
            <?php ac_action_btn('recital_decide', 'events',
                ['item_id' => (int) $i['id'], 'decision' => 'accept'], 'Accept', 'ac-btn ac-btn--sm ac-btn--primary'); ?>
          </div>
        <?php endforeach; endif; ?>
      </div>
    <?php ac_card_close('Accepting places a piece at the end of its section. Ordering is a separate step, so accepting never silently rewrites a programme somebody has already printed.'); ?>

    <?php ac_card_open('Time', $minutes . ' minutes accepted'); ?>
      <div class="ac-cardbody">
        <?php $budget = 90; ?>
        <?= ac_meter(mam_console_pct($minutes, $budget), $minutes > $budget ? 'bad' : 'accent') ?>
        <div class="ac-note ac-mt-sm">
          <?= $minutes ?> of a nominal <?= $budget ?>-minute hall.
          <?php if ($pending): ?>
            Accepting everything still waiting would take it to
            <strong><?= $minutes + array_sum(array_map(fn($i) => (int) ($i['duration_min'] ?? 0), $pending)) ?> minutes</strong>.
          <?php endif; ?>
        </div>
      </div>
    <?php ac_card_close('A programme that overruns is a caretaker locking up mid-piece, so the arithmetic is on the screen where the decision is made.'); ?>
  </div>
</div>
<?php endif; ?>
