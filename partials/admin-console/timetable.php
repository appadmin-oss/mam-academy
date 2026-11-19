<?php
/** Timetable & rooms — a week, its clashes, and what the rooms are doing. */
declare(strict_types=1);
/** @var array $d */
$rows = $d['rows'] ?? [];
$days = [];
for ($i = 0; $i < 7; $i++) $days[] = date('Y-m-d', strtotime((string) $d['from'] . ' +' . $i . ' day'));

/* Group by day and by the hour slot the session starts in, so the grid only
   carries rows that actually have something in them. */
$slots = [];
foreach ($rows as $r) {
    $t = strtotime((string) $r['scheduled_at']);
    $slots[date('H:00', $t)][date('Y-m-d', $t)][] = $r;
}
ksort($slots);
?>
<?php ac_head('Timetable & rooms',
    'Week of ' . ac_h(mam_console_date((string) $d['from'], 'j M')) . ' · all times '
    . ac_h((string) (defined('MAM_CALENDAR_TZ') ? MAM_CALENDAR_TZ : 'Africa/Lagos')),
    '<a class="ac-btn" href="' . ac_h(ac_url('timetable', ['w' => (int) $d['offset'] - 1])) . '">← Previous</a>'
    . '<a class="ac-btn" href="' . ac_h(ac_url('timetable')) . '">This week</a>'
    . '<a class="ac-btn" href="' . ac_h(ac_url('timetable', ['w' => (int) $d['offset'] + 1])) . '">Next →</a>'); ?>

<?php foreach ($d['clashes'] ?? [] as $c): ?>
  <?php ac_banner('bad', 'fa-triangle-exclamation',
    '<strong>' . ac_h((string) $c['room']) . ' double-booked.</strong> '
    . ac_h((string) ($c['a']['title'] ?: $c['a']['cohort'] ?: 'A session')) . ' at '
    . ac_h(mam_console_date((string) $c['a']['scheduled_at'], 'H:i')) . ' and '
    . ac_h((string) ($c['b']['title'] ?: $c['b']['cohort'] ?: 'another')) . ' at '
    . ac_h(mam_console_date((string) $c['b']['scheduled_at'], 'H:i')) . ' overlap by '
    . (int) $c['overlap_min'] . ' minutes.'); ?>
<?php endforeach; ?>

<?php ac_card_open('Week ' . mam_console_date((string) $d['from'], 'j M') . ' – ' . mam_console_date(date('Y-m-d', strtotime((string) $d['to'] . ' -1 day')), 'j M')); ?>
  <?php if (!$slots): ?>
    <div class="ac-cardbody"><?php ac_empty('Nothing scheduled this week',
        'Sessions are created per cohort. An empty week is either a closure or a gap in the schedule.',
        ''); ?></div>
  <?php else: ?>
    <div class="ac-tablewrap">
      <div class="ac-week">
        <div class="ac-wk-h"></div>
        <?php foreach ($days as $day): ?>
          <div class="ac-wk-h"><?= ac_h(strtoupper(date('D j', strtotime($day)))) ?></div>
        <?php endforeach; ?>
        <?php foreach ($slots as $hour => $byDay): ?>
          <div class="ac-wk-t"><?= ac_h($hour) ?></div>
          <?php foreach ($days as $day): ?>
            <div>
              <?php foreach ($byDay[$day] ?? [] as $s): ?>
                <a class="ac-wk-ev<?= !empty($s['clash']) ? ' is-clash' : '' ?>" href="<?= ac_h(ac_url('attendance', ['d' => $day])) ?>">
                  <strong><?= ac_h(mb_strimwidth((string) ($s['title'] ?: $s['cohort'] ?: 'Session'), 0, 22, '…')) ?></strong>
                  <span><?= ac_h(trim(implode(' · ', array_filter([
                      (string) ($s['room_name'] ?: $s['room'] ?: ($s['mode'] ?: '')),
                      (int) $s['enrolled'] > 0 ? (int) $s['enrolled'] . '' : '',
                      !empty($s['clash']) ? 'clash' : '',
                  ])))) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
<?php ac_card_close('A clash is computed from the room and the duration, not read from a flag — nothing in this schema writes one. An online session shares no walls, so it never clashes.'); ?>

<div class="ac-cols ac-cols--2">
  <?php ac_card_open('Rooms', count($d['rooms'] ?? []) . ' on file'); ?>
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead><tr><th>Room</th><th class="ac-num">Capacity</th><th>Used this week</th></tr></thead>
        <tbody>
          <?php if (!($d['rooms'] ?? [])): ?>
            <?php ac_table_empty(3, 'No rooms recorded. A session can still name a room as free text.'); ?>
          <?php else: foreach ($d['rooms'] as $r): ?>
            <tr>
              <td><div class="ac-strong"><?= ac_h((string) $r['name']) ?></div>
                <?php if (!empty($r['location'])): ?><div class="ac-sub"><?= ac_h((string) $r['location']) ?></div><?php endif; ?></td>
              <td class="ac-num"><?= $r['capacity'] !== null ? (int) $r['capacity'] : '—' ?></td>
              <td><div class="ac-row"><?= ac_meter((int) $r['util'], (int) $r['util'] > 90 ? 'bad' : 'accent') ?>
                <span class="ac-pctfig"><?= (int) $r['util'] ?>%</span></div></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php ac_card_close('Utilisation is against a nominal eight-hour day, six days a week — a comparison between rooms, not a claim about capacity.'); ?>

  <?php ac_card_open('Cancelling a session'); ?>
    <div class="ac-cardbody">
      <p class="ac-note">A reason is required at the source, because a class that vanishes from a family's timetable
      with no explanation is the complaint every academy gets — <span class="ac-mono">cancellation_reason</span> is
      on the session row for exactly that.</p>
      <p class="ac-note">Where the academy cancels, a make-up credit is granted and does <strong>not</strong> count
      against the family's allowance. Only a guardian's own notice spends it — a teacher cancellation, a closure and
      a goodwill credit are all free, and that is enforced by a database check on both engines.</p>
    </div>
  <?php ac_card_close('A closure withdraws the class from the timetable, the register and the fee calculation in one move — see the academic calendar.'); ?>
</div>
