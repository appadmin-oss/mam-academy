<?php
/**
 * Timetable & rooms — the office's scheduling desk.
 *
 * A week by time, by room or by teacher, with every clash computed (never read
 * from a flag); filters by class, teacher and room; and the four scheduling
 * writes — a weekly series, one session, a move and a cancellation — each
 * posted to admin/index.php and implemented in api/lib/scheduling.php.
 * Below 720px the grid gives way to a list of the week, which stacks like
 * every other console table.
 */
declare(strict_types=1);
/** @var array $d */
$rows = $d['rows'] ?? [];
$by   = (string) ($d['by'] ?? 'time');
$f    = $d['filters'] ?? ['cohort' => 0, 'instructor' => 0, 'room' => 0];
$sel  = $d['session'] ?? null;
$days = [];
for ($i = 0; $i < 7; $i++) $days[] = date('Y-m-d', strtotime((string) $d['from'] . ' +' . $i . ' day'));
$tz = (string) (defined('MAM_CALENDAR_TZ') ? MAM_CALENDAR_TZ : 'Africa/Lagos');

/* The view's state, carried by every link and every form on the screen. */
$state = array_filter([
    'w' => (int) $d['offset'] !== 0 ? (int) $d['offset'] : null,
    'by' => $by !== 'time' ? $by : null,
    'cohort' => $f['cohort'] ?: null, 'instructor' => $f['instructor'] ?: null, 'room' => $f['room'] ?: null,
], fn($v) => $v !== null);
$tt = fn(array $extra = []) => ac_url('timetable', array_merge($state, $extra));
$keep = [];
foreach ($state as $k => $v) $keep['_tt_' . $k] = (string) $v;

$roomsActive = array_values(array_filter($d['rooms'] ?? [], fn($r) => (int) ($r['active'] ?? 1) === 1));
$dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun'];
$label = fn(array $s) => (string) ($s['title'] ?: $s['cohort'] ?: 'Session');
$where = fn(array $s) => ($s['mode'] ?? '') === 'online' ? 'Online' : (string) ($s['room_name'] ?: $s['room'] ?: 'No room set');

/* One event chip in the grid. */
$chip = function (array $s) use ($tt, $label, $where, $by, $sel): string {
    $cls = 'ac-wk-ev';
    if (!empty($s['clash'])) $cls .= ' is-clash';
    if (($s['status'] ?? '') === 'cancelled') $cls .= ' is-off';
    if ($sel && (int) $sel['id'] === (int) $s['id']) $cls .= ' is-sel';
    $meta = ac_join([
        mam_console_date((string) $s['scheduled_at'], 'H:i'),
        $by === 'room' ? (string) ($s['instructor'] ?? '') : $where($s),
        ($s['status'] ?? '') === 'cancelled' ? 'cancelled' : '',
        !empty($s['clash']) ? ['instructor' => 'teacher clash', 'learner' => 'learner clash'][$s['clash_kind']] ?? 'room clash' : '',
    ]);
    return '<a class="' . $cls . '" href="' . ac_h($tt(['s' => (int) $s['id']])) . '">'
        . '<strong>' . ac_h(mb_strimwidth($label($s), 0, 24, '…')) . '</strong>'
        . '<span>' . ac_h($meta) . '</span></a>';
};

/* Group for the chosen view: row key → day → sessions. */
$grid = []; $rowLabels = [];
foreach ($rows as $r) {
    $day = date('Y-m-d', strtotime((string) $r['scheduled_at']));
    if ($by === 'room') {
        $k = $where($r);
    } elseif ($by === 'instructor') {
        $k = (string) ($r['substitute'] ?: $r['instructor'] ?: 'No teacher');
    } else {
        $k = date('H:00', strtotime((string) $r['scheduled_at']));
    }
    $grid[$k][$day][] = $r;
    $rowLabels[$k] = $k;
}
if ($by === 'room') {
    // Every active room gets a row, so an empty room reads as free rather than missing.
    foreach ($roomsActive as $rm) if (!$f['room'] || (int) $rm['id'] === (int) $f['room']) $grid[(string) $rm['name']] = $grid[(string) $rm['name']] ?? [];
}
ksort($grid, SORT_NATURAL | SORT_FLAG_CASE);

$byLink = fn(string $v, string $text) => '<a href="' . ac_h(ac_url('timetable', array_merge($state, ['by' => $v !== 'time' ? $v : null]))) . '"'
    . ($by === $v ? ' class="is-on" aria-current="true"' : '') . '>' . ac_h($text) . '</a>';
?>
<?php ac_head('Timetable & rooms',
    'Week of ' . ac_h(mam_console_date((string) $d['from'], 'j M Y')) . ' · all times ' . ac_h($tz),
    '<a class="ac-btn" href="' . ac_h($tt(['w' => (int) $d['offset'] - 1 ?: null])) . '"><span aria-hidden="true">←</span> Previous</a>'
    . '<a class="ac-btn" href="' . ac_h(ac_url('timetable', array_merge($state, ['w' => null]))) . '">This week</a>'
    . '<a class="ac-btn" href="' . ac_h($tt(['w' => (int) $d['offset'] + 1 ?: null])) . '">Next <span aria-hidden="true">→</span></a>'); ?>

<form method="get" action="" class="ac-row ac-filters ac-tt-filters" aria-label="Filter the timetable">
  <input type="hidden" name="v" value="timetable">
  <?php if ((int) $d['offset'] !== 0): ?><input type="hidden" name="w" value="<?= (int) $d['offset'] ?>"><?php endif; ?>
  <?php if ($by !== 'time'): ?><input type="hidden" name="by" value="<?= ac_h($by) ?>"><?php endif; ?>
  <nav class="ac-seg" aria-label="Arrange the week by"><?= $byLink('time', 'By time') . $byLink('room', 'By room') . $byLink('instructor', 'By teacher') ?></nav>
  <label class="ac-sr" for="tt-f-cohort">Class</label>
  <select class="ac-select ac-select--sm" id="tt-f-cohort" name="cohort">
    <option value="">All classes</option>
    <?php foreach ($d['cohorts'] ?? [] as $c): ?>
      <option value="<?= (int) $c['id'] ?>"<?= (int) $f['cohort'] === (int) $c['id'] ? ' selected' : '' ?>><?= ac_h((string) $c['title']) ?></option>
    <?php endforeach; ?>
  </select>
  <label class="ac-sr" for="tt-f-instr">Teacher</label>
  <select class="ac-select ac-select--sm" id="tt-f-instr" name="instructor">
    <option value="">All teachers</option>
    <?php foreach ($d['instructors'] ?? [] as $u): ?>
      <option value="<?= (int) $u['id'] ?>"<?= (int) $f['instructor'] === (int) $u['id'] ? ' selected' : '' ?>><?= ac_h((string) $u['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <label class="ac-sr" for="tt-f-room">Room</label>
  <select class="ac-select ac-select--sm" id="tt-f-room" name="room">
    <option value="">All rooms</option>
    <?php foreach ($d['rooms'] ?? [] as $rm): ?>
      <option value="<?= (int) $rm['id'] ?>"<?= (int) $f['room'] === (int) $rm['id'] ? ' selected' : '' ?>><?= ac_h((string) $rm['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="ac-btn ac-btn--sm">Show</button>
  <?php if ($f['cohort'] || $f['instructor'] || $f['room']): ?>
    <a class="ac-btn ac-btn--sm" href="<?= ac_h(ac_url('timetable', array_filter(['w' => $state['w'] ?? null, 'by' => $state['by'] ?? null]))) ?>">Clear filters</a>
    <span class="ac-tabnote"><?= count($rows) ?> of <?= (int) $d['total'] ?> sessions shown</span>
  <?php endif; ?>
</form>

<?php foreach ($d['clashes'] ?? [] as $c): ?>
  <?php ac_banner('bad', 'fa-triangle-exclamation',
    '<strong>' . ac_h($c['kind'] === 'instructor' ? $c['who'] . ' is booked twice.' : ($c['kind'] === 'learner' ? $c['who'] . ' would be in two classes at once.' : $c['room'] . ' is double-booked.')) . '</strong> '
    . ac_h($label($c['a'])) . ' at ' . ac_h(mam_console_date((string) $c['a']['scheduled_at'], 'D H:i')) . ' and '
    . ac_h($label($c['b'])) . ' at ' . ac_h(mam_console_date((string) $c['b']['scheduled_at'], 'D H:i')) . ' overlap by '
    . (int) $c['overlap_min'] . ' minutes.',
    '<a class="ac-btn ac-btn--sm" href="' . ac_h($tt(['s' => (int) $c['b']['id']])) . '">Open the later one</a>'); ?>
<?php endforeach; ?>

<?php if ($sel): ?>
  <?php
    $isLive = ($sel['status'] ?? '') === 'scheduled';
    $aud = $sel['audience'] ?? ['students' => 0, 'guardians' => 0, 'staff' => 0];
    ac_card_open($label($sel), ac_join([mam_console_date((string) $sel['scheduled_at'], 'l j M, H:i'), (int) ($sel['duration_min'] ?: 60) . ' min']),
        ac_status((string) $sel['status']) . ' <a class="ac-btn ac-btn--sm" href="' . ac_h($tt()) . '">Close</a>');
  ?>
    <div class="ac-cardbody">
      <div class="ac-defs">
        <?php ac_def('Class', ac_h((string) ($sel['cohort'] ?? '—'))); ?>
        <?php ac_def('Teacher', ac_h(ac_join([(string) ($sel['instructor'] ?? ''), !empty($sel['substitute']) ? 'covered by ' . $sel['substitute'] : '']) ?: 'None assigned')); ?>
        <?php ac_def('Where', ac_h(ac_join([$where($sel), ucfirst((string) ($sel['mode'] ?: 'onsite'))]))); ?>
        <?php ac_def('Told of a change', ac_h($aud['students'] . ' learner' . ($aud['students'] === 1 ? '' : 's') . ', ' . $aud['guardians'] . ' guardian' . ($aud['guardians'] === 1 ? '' : 's') . ', ' . $aud['staff'] . ' staff')); ?>
        <?php if (!empty($sel['cancellation_reason'])): ?><?php ac_def('Cancelled', ac_h((string) $sel['cancellation_reason'])); ?><?php endif; ?>
        <?php if ((int) ($sel['credits'] ?? 0) > 0): ?><?php ac_def('Make-up credits', (int) $sel['credits'] . ' granted for this session'); ?><?php endif; ?>
        <?php if (!empty($sel['meeting_link'])): ?><?php ac_def('Online link', '<a href="' . ac_h((string) $sel['meeting_link']) . '" rel="noopener">' . ac_h(ucfirst((string) ($sel['meeting_provider'] === 'jaas' ? 'Jitsi' : $sel['meeting_provider']))) . ' — generated</a>'); ?><?php endif; ?>
      </div>
      <?php if ($isLive && !empty($sel['links'])): ?>
        <div class="ac-row ac-mt" aria-label="Add this session to a calendar">
          <span class="ac-label">Add to calendar</span>
          <a class="ac-btn ac-btn--sm" href="<?= ac_h($sel['links']['google']) ?>" target="_blank" rel="noopener">Google</a>
          <a class="ac-btn ac-btn--sm" href="<?= ac_h($sel['links']['outlook']) ?>" target="_blank" rel="noopener">Outlook</a>
          <a class="ac-btn ac-btn--sm" href="<?= ac_h($sel['links']['ics']) ?>">.ics file</a>
        </div>
      <?php endif; ?>

      <?php foreach ($sel['clashes_now'] ?? [] as $c): ?>
        <?php ac_banner('warn', 'fa-triangle-exclamation', 'Overlaps <strong>' . ac_h((string) ($c['title'] ?: $c['cohort_title'])) . '</strong> at '
            . ac_h(mam_console_date((string) $c['scheduled_at'], 'H:i')) . ac_h(!empty($c['room']) ? ' in ' . $c['room'] : '') . '.'); ?>
      <?php endforeach; ?>

      <?php if ($isLive): ?>
        <div class="ac-grid ac-grid--2 ac-mt">
          <?php ac_form('sched_reschedule', 'timetable', ['session_id' => (int) $sel['id']] + $keep, 'class="ac-tt-form" aria-labelledby="tt-move-h"'); ?>
            <h3 class="ac-h3" id="tt-move-h">Move this session</h3>
            <label class="ac-field"><span class="ac-label">New date and time</span>
              <input class="ac-input" type="datetime-local" name="at" required value="<?= ac_h(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', (string) ($_GET['at'] ?? '')) ? (string) $_GET['at'] : str_replace(' ', 'T', substr((string) $sel['scheduled_at'], 0, 16))) ?>"></label>
            <label class="ac-field ac-mt"><span class="ac-label">Reason</span>
              <input class="ac-input" type="text" name="reason" required maxlength="500" placeholder="Told to the learners, their guardians and the teacher"></label>
            <label class="ac-tick ac-mt"><input type="checkbox" name="force" value="1"> Move it anyway if the new time clashes</label>
            <div class="ac-row ac-mt"><button type="submit" class="ac-btn ac-btn--primary">Move and tell them</button></div>
          <?php ac_form_end(); ?>

          <?php ac_form('sched_cancel', 'timetable', ['session_id' => (int) $sel['id']] + $keep, 'class="ac-tt-form" aria-labelledby="tt-cancel-h"'); ?>
            <h3 class="ac-h3" id="tt-cancel-h">Cancel this session</h3>
            <fieldset class="ac-tt-radios">
              <legend class="ac-label">Who is cancelling</legend>
              <label class="ac-tick"><input type="radio" name="cause" value="teacher" required> The teacher</label>
              <label class="ac-tick"><input type="radio" name="cause" value="closure"> A closure (weather, power, venue)</label>
              <label class="ac-tick"><input type="radio" name="cause" value="academy"> The academy</label>
            </fieldset>
            <label class="ac-field ac-mt"><span class="ac-label">Reason</span>
              <input class="ac-input" type="text" name="reason" required maxlength="500"></label>
            <p class="ac-hint">Each enrolled learner gets a free make-up credit. It never counts against a family's allowance, and no money moves.</p>
            <div class="ac-row ac-mt"><button type="submit" class="ac-btn ac-btn--danger">Cancel the session</button></div>
          <?php ac_form_end(); ?>
        </div>
      <?php else: ?>
        <p class="ac-note ac-mt">This session has been <?= ac_h((string) $sel['status']) ?>, so it can no longer be moved or cancelled.</p>
      <?php endif; ?>

      <?php if (!empty($sel['suggest'])): ?>
        <h3 class="ac-h3 ac-mt"><?= $isLive ? 'Better times, if it has to move' : 'Book the make-up' ?></h3>
        <ul class="ac-tt-suggest">
          <?php foreach ($sel['suggest'] as $sg): $at = $sg['dates'][0] . ' ' . $sg['time']; ?>
            <li>
              <div><strong><?= ac_h(mam_console_date($at, 'D j M, H:i')) ?></strong>
                <span class="ac-sub"><?= ac_h(preg_replace('/^[A-Za-z]{3} [^:]+: /', '', $sg['sentence'])) ?></span></div>
              <?php if ($isLive): ?>
                <a class="ac-btn ac-btn--sm" href="<?= ac_h($tt(['s' => (int) $sel['id'], 'at' => str_replace(' ', 'T', $at)])) ?>#tt-move-h">Use this time</a>
              <?php else: ?>
                <?php ac_form('sched_session_add', 'timetable', ['cohort_id' => (int) $sel['cohort_id'], 'at' => $at, 'duration' => (int) ($sel['duration_min'] ?: 60),
                    'kind' => 'makeup', 'mode' => (string) ($sel['mode'] ?: 'onsite'), 'room_id' => (string) ($sg['room']['id'] ?? ''),
                    'title' => 'Make-up: ' . $label($sel)] + $keep, 'class="ac-row"'); ?>
                  <button type="submit" class="ac-btn ac-btn--sm ac-btn--primary">Book it</button>
                <?php ac_form_end(); ?>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if (!empty($sel['history'])): ?>
        <div class="ac-tablewrap ac-mt">
          <table class="ac-table">
            <caption class="ac-sr">Changes to this session</caption>
            <thead><tr><th>When</th><th>Change</th><th>Reason</th><th>By</th></tr></thead>
            <tbody>
              <?php foreach ($sel['history'] as $h): ?>
                <tr>
                  <td><?= ac_h(mam_console_date((string) $h['changed_at'], 'j M H:i')) ?></td>
                  <td><?= ac_h($h['field_changed'] === 'scheduled_at'
                      ? 'Moved from ' . mam_console_date((string) $h['old_value'], 'D j M H:i') . ' to ' . mam_console_date((string) $h['new_value'], 'D j M H:i')
                      : $h['field_changed'] . ': ' . ($h['old_value'] ?? '—') . ' → ' . ($h['new_value'] ?? '—')) ?></td>
                  <td><?= ac_h((string) ($h['reason'] ?: '—')) ?></td>
                  <td><?= ac_h((string) ($h['who'] ?: '—')) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  <?php ac_card_close(); ?>
<?php elseif ((int) ($_GET['s'] ?? 0) > 0): ?>
  <?php ac_banner('warn', 'fa-circle-info', 'That session could not be found. It may have been deleted.'); ?>
<?php endif; ?>

<?php ac_card_open('Week ' . mam_console_date((string) $d['from'], 'j M') . ' – ' . mam_console_date(date('Y-m-d', strtotime((string) $d['to'] . ' -1 day')), 'j M'),
    count($rows) . ' session' . (count($rows) === 1 ? '' : 's')); ?>
  <?php if (!$grid): ?>
    <div class="ac-cardbody"><?php ac_empty('Nothing scheduled this week',
        'An empty week is a closure or a gap in the schedule. Schedule a class below to fill it.', ''); ?></div>
  <?php else: ?>
    <div class="ac-tt-grid"><div class="ac-weekwrap">
      <div class="ac-week<?= $by !== 'time' ? ' ac-week--named' : '' ?>">
        <div class="ac-wk-h"><span class="ac-sr"><?= $by === 'room' ? 'Room' : ($by === 'instructor' ? 'Teacher' : 'Time') ?></span></div>
        <?php foreach ($days as $day): ?>
          <div class="ac-wk-h"><?= ac_h(strtoupper(date('D j', strtotime($day)))) ?></div>
        <?php endforeach; ?>
        <?php foreach ($grid as $key => $byDay): ?>
          <div class="ac-wk-t"><?= ac_h((string) $key) ?></div>
          <?php foreach ($days as $day): ?>
            <div><?php foreach ($byDay[$day] ?? [] as $s) echo $chip($s); ?></div>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    </div></div>

    <div class="ac-tablewrap ac-tt-agenda">
      <table class="ac-table">
        <caption class="ac-sr">This week's sessions, in order</caption>
        <thead><tr><th>When</th><th>Session</th><th>Where</th><th>Teacher</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($rows as $s): ?>
            <tr>
              <td><?= ac_h(mam_console_date((string) $s['scheduled_at'], 'D j M, H:i')) ?></td>
              <td><div class="ac-strong"><?= ac_h($label($s)) ?></div>
                <?php if (!empty($s['clash']) || ($s['status'] ?? '') !== 'scheduled'): ?><div class="ac-sub"><?= ac_join([
                    ($s['status'] ?? '') !== 'scheduled' ? ac_status((string) $s['status']) : '',
                    !empty($s['clash']) ? ac_pill(['instructor' => 'Teacher clash', 'learner' => 'Learner clash'][$s['clash_kind']] ?? 'Room clash', 'bad') : '',
                ], ' ') ?></div><?php endif; ?></td>
              <td><?= ac_h($where($s)) ?></td>
              <td><?= ac_h((string) ($s['substitute'] ?: $s['instructor'] ?: '—')) ?></td>
              <td><a class="ac-btn ac-btn--sm" href="<?= ac_h($tt(['s' => (int) $s['id']])) ?>">Open</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php ac_card_close('A clash is found from the room, the teacher and the length of each session; an online session clashes only on its teacher. Open a session to move or cancel it.'); ?>

<div class="ac-cols ac-cols--2">
  <?php ac_card_open('Schedule a class', 'A weekly series'); ?>
    <div class="ac-cardbody">
      <?php $pi = $d['plan_in'] ?: []; $pcId = (int) ($pi['cohort_id'] ?? ($f['cohort'] ?: 0)); ?>
      <form method="get" action="" class="ac-tt-form" aria-label="Plan a weekly series">
        <input type="hidden" name="v" value="timetable"><input type="hidden" name="plan" value="1">
        <?php foreach ($state as $k => $v): ?><input type="hidden" name="<?= ac_h($k) ?>" value="<?= ac_h((string) $v) ?>"><?php endforeach; ?>
        <label class="ac-field"><span class="ac-label">Class</span>
          <select class="ac-select" name="pc" required>
            <option value="">Choose a class…</option>
            <?php foreach ($d['cohorts'] ?? [] as $c): ?>
              <option value="<?= (int) $c['id'] ?>"<?= $pcId === (int) $c['id'] ? ' selected' : '' ?>><?= ac_h(ac_join([(string) $c['title'], (string) ($c['instructor'] ?? '')])) ?></option>
            <?php endforeach; ?>
          </select></label>
        <fieldset class="ac-tt-days ac-mt">
          <legend class="ac-label">Every</legend>
          <?php $pdays = $pi['days'] ?? []; foreach ($dayNames as $n => $nm): ?>
            <label class="ac-tick"><input type="checkbox" name="days[]" value="<?= $n ?>"<?= in_array($n, $pdays, true) ? ' checked' : '' ?>> <?= $nm ?></label>
          <?php endforeach; ?>
        </fieldset>
        <div class="ac-grid ac-grid--3 ac-mt">
          <label class="ac-field"><span class="ac-label">Starts at</span>
            <input class="ac-input" type="time" name="time" value="<?= ac_h((string) ($pi['time'] ?? '')) ?>"></label>
          <label class="ac-field"><span class="ac-label">Minutes</span>
            <input class="ac-input" type="number" name="dur" min="15" max="480" step="5" value="<?= ac_h((string) ($pi['duration'] ?? '')) ?>" placeholder="60"></label>
          <label class="ac-field"><span class="ac-label">Mode</span>
            <select class="ac-select" name="mode">
              <?php foreach (['onsite' => 'In person', 'online' => 'Online', 'hybrid' => 'Hybrid'] as $mv => $mt): ?>
                <option value="<?= $mv ?>"<?= ($pi['mode'] ?? 'onsite') === $mv ? ' selected' : '' ?>><?= $mt ?></option>
              <?php endforeach; ?>
            </select></label>
        </div>
        <div class="ac-grid ac-grid--3 ac-mt">
          <label class="ac-field"><span class="ac-label">Room</span>
            <select class="ac-select" name="room_id">
              <option value="">No room</option>
              <?php foreach ($roomsActive as $rm): ?>
                <option value="<?= (int) $rm['id'] ?>"<?= (int) ($pi['room_id'] ?? 0) === (int) $rm['id'] ? ' selected' : '' ?>><?= ac_h((string) $rm['name']) ?></option>
              <?php endforeach; ?>
            </select></label>
          <label class="ac-field"><span class="ac-label">From</span>
            <input class="ac-input" type="date" name="from" value="<?= ac_h((string) ($pi['from'] ?? '')) ?>"></label>
          <label class="ac-field"><span class="ac-label">Until</span>
            <input class="ac-input" type="date" name="to" value="<?= ac_h((string) ($pi['to'] ?? '')) ?>"></label>
        </div>
        <p class="ac-hint">Leave the dates empty to use the class's own, or the current term's. A blank time or length uses the class's usual one.</p>
        <div class="ac-row ac-mt"><button type="submit" class="ac-btn">Preview the dates</button>
          <button type="submit" class="ac-btn" name="suggest" value="1">Suggest the best times</button></div>
      </form>

      <?php if (is_array($d['suggest'] ?? null)): ?>
        <h3 class="ac-h3 ac-mt">Best weekly times</h3>
        <?php if (!$d['suggest']): ?><p class="ac-note">No time this week pattern works: every slot clashes with the teacher, a room or a learner.</p><?php endif; ?>
        <ul class="ac-tt-suggest">
          <?php foreach ($d['suggest'] as $sg): ?>
            <li><div><?= ac_h($sg['sentence']) ?></div>
              <a class="ac-btn ac-btn--sm" href="<?= ac_h(ac_url('timetable', array_merge($state, ['plan' => 1, 'pc' => $pcId, 'days' => [$sg['dow']], 'time' => $sg['time'],
                  'dur' => $sg['dur'], 'mode' => $pi['mode'] ?? ($_GET['mode'] ?? 'onsite'), 'room_id' => $sg['room']['id'] ?? null, 'from' => $_GET['from'] ?? null, 'to' => $_GET['to'] ?? null]))) ?>">Preview this</a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if (!empty($d['plan_error'])): ?>
        <?php ac_banner('bad', 'fa-circle-exclamation', ac_h(mam_sched_error_text((string) $d['plan_error']))); ?>
      <?php elseif ($p = $d['plan']): ?>
        <?php $pc = $p['count']; $pr = $p['rule']; ?>
        <h3 class="ac-h3 ac-mt" id="tt-plan-h"><?= ac_h($pr['cohort_title']) ?> · <?= ac_h(implode(', ', array_map(fn($n) => $dayNames[$n], $pr['days']))) ?> at <?= ac_h($pr['time']) ?></h3>
        <p class="ac-note"><?= ac_h(ac_join([
            $pc['ok'] . ' to create', $pc['clash'] ? $pc['clash'] . ' clash' : '', $pc['closed'] ? $pc['closed'] . ' closed' : '',
            $pc['exists'] ? $pc['exists'] . ' already scheduled' : '',
        ])) ?> · <?= ac_h(mam_console_date($pr['from'], 'j M Y')) ?> – <?= ac_h(mam_console_date($pr['to'], 'j M Y')) ?></p>
        <div class="ac-tablewrap">
          <table class="ac-table" aria-labelledby="tt-plan-h">
            <thead><tr><th>Date</th><th>Outcome</th><th>Why</th></tr></thead>
            <tbody>
              <?php if (!$p['slots']): ?><?php ac_table_empty(3, 'No dates fall on those weekdays in that range.'); ?><?php endif; ?>
              <?php foreach ($p['slots'] as $sl): ?>
                <tr>
                  <td><?= ac_h(mam_console_date($sl['at'], 'D j M, H:i')) ?></td>
                  <td><?= ac_pill(['ok' => 'Will be created', 'clash' => 'Clash', 'closed' => 'Skipped', 'exists' => 'Already there'][$sl['status']],
                      ['ok' => 'good', 'clash' => 'bad', 'closed' => '', 'exists' => ''][$sl['status']]) ?></td>
                  <td><?= ac_h($sl['status'] === 'closed' ? (string) $sl['closure']['label'] . ' (' . $sl['closure']['kind'] . ')'
                      : ($sl['status'] === 'clash' ? 'Overlaps ' . implode(', ', array_map(fn($c) => (string) ($c['title'] ?: $c['cohort_title']) . ' at ' . mam_console_date((string) $c['scheduled_at'], 'H:i'), $sl['with']))
                      : ($sl['status'] === 'exists' ? 'This class already meets then' : '—'))) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php
          $post = function (string $onClash, string $text, string $cls) use ($pr, $keep) {
              ac_form('sched_series', 'timetable', [
                  'cohort_id' => $pr['cohort_id'], 'time' => $pr['time'], 'duration' => $pr['duration'], 'room_id' => (string) ($pr['room_id'] ?? ''),
                  'mode' => $pr['mode'], 'from' => $pr['from'], 'to' => $pr['to'], 'on_clash' => $onClash,
              ] + $keep, 'class="ac-row"');
              foreach ($pr['days'] as $n) echo '<input type="hidden" name="days[]" value="' . (int) $n . '">';
              echo '<button type="submit" class="' . ac_h($cls) . '">' . ac_h($text) . '</button>';
              ac_form_end();
          };
        ?>
        <div class="ac-row ac-mt">
          <?php if ($pc['clash'] === 0 && $pc['ok'] > 0): ?>
            <?php $post('refuse', 'Create ' . $pc['ok'] . ' session' . ($pc['ok'] === 1 ? '' : 's'), 'ac-btn ac-btn--primary'); ?>
          <?php elseif ($pc['clash'] > 0): ?>
            <?php if ($pc['ok'] > 0) $post('skip', 'Create the ' . $pc['ok'] . ' without a clash', 'ac-btn ac-btn--primary'); ?>
            <?php $post('create', 'Create all ' . ($pc['ok'] + $pc['clash']) . ', clashes included', 'ac-btn'); ?>
          <?php else: ?>
            <p class="ac-note">Nothing to create — every date is closed or already on the timetable.</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php ac_card_close('Nothing is written until you press Create. Holidays and mid-term breaks are skipped; exam weeks are not. Running the same series twice creates nothing new.'); ?>

  <?php ac_card_open('Add one session'); ?>
    <div class="ac-cardbody">
      <?php ac_form('sched_session_add', 'timetable', $keep, 'class="ac-tt-form" aria-label="Add one session"'); ?>
        <label class="ac-field"><span class="ac-label">Class</span>
          <select class="ac-select" name="cohort_id" required>
            <option value="">Choose a class…</option>
            <?php foreach ($d['cohorts'] ?? [] as $c): ?>
              <option value="<?= (int) $c['id'] ?>"<?= (int) $f['cohort'] === (int) $c['id'] ? ' selected' : '' ?>><?= ac_h((string) $c['title']) ?></option>
            <?php endforeach; ?>
          </select></label>
        <div class="ac-grid ac-grid--2 ac-mt">
          <label class="ac-field"><span class="ac-label">Date and time</span>
            <input class="ac-input" type="datetime-local" name="at" required></label>
          <label class="ac-field"><span class="ac-label">Minutes</span>
            <input class="ac-input" type="number" name="duration" min="15" max="480" step="5" value="60"></label>
        </div>
        <div class="ac-grid ac-grid--3 ac-mt">
          <label class="ac-field"><span class="ac-label">Kind</span>
            <select class="ac-select" name="kind">
              <?php foreach (['class' => 'Class', 'makeup' => 'Make-up', 'revision' => 'Revision', 'exam' => 'Exam'] as $kv => $kt): ?>
                <option value="<?= $kv ?>"><?= $kt ?></option>
              <?php endforeach; ?>
            </select></label>
          <label class="ac-field"><span class="ac-label">Mode</span>
            <select class="ac-select" name="mode">
              <option value="onsite">In person</option><option value="online">Online</option><option value="hybrid">Hybrid</option>
            </select></label>
          <label class="ac-field"><span class="ac-label">Room</span>
            <select class="ac-select" name="room_id">
              <option value="">No room</option>
              <?php foreach ($roomsActive as $rm): ?><option value="<?= (int) $rm['id'] ?>"><?= ac_h((string) $rm['name']) ?></option><?php endforeach; ?>
            </select></label>
        </div>
        <label class="ac-field ac-mt"><span class="ac-label">Title <span class="ac-sub">(optional)</span></span>
          <input class="ac-input" type="text" name="title" maxlength="160" placeholder="Uses the class name when empty"></label>
        <label class="ac-tick ac-mt"><input type="checkbox" name="force" value="1"> Add it anyway if it clashes</label>
        <div class="ac-row ac-mt"><button type="submit" class="ac-btn ac-btn--primary">Add the session</button></div>
      <?php ac_form_end(); ?>
    </div>
  <?php ac_card_close('A clash with the teacher or the room refuses the session unless you tick the box. Families are not notified of a new session; they see it on their timetable.'); ?>
</div>

<?php foreach ($d['feeds'] ?? [] as $fk => $fd): ?>
  <?php $fname = $fk === 'room' ? (string) (array_values(array_filter($d['rooms'] ?? [], fn($r) => (int) $r['id'] === (int) $fd['id']))[0]['name'] ?? 'this room')
                                : (string) (array_values(array_filter($d['cohorts'] ?? [], fn($c) => (int) $c['id'] === (int) $fd['id']))[0]['title'] ?? 'this class'); ?>
  <?php ac_card_open('Calendar for ' . $fname, $fk === 'room' ? 'Every booking of the room' : 'Every session of the class'); ?>
    <div class="ac-cardbody">
      <?php if ($fd['links']): ?>
        <div class="ac-row" aria-label="Subscribe to this calendar">
          <a class="ac-btn ac-btn--sm" href="<?= ac_h($fd['links']['google']) ?>" target="_blank" rel="noopener">Google Calendar</a>
          <a class="ac-btn ac-btn--sm" href="<?= ac_h($fd['links']['apple']) ?>">Apple / iPhone</a>
          <a class="ac-btn ac-btn--sm" href="<?= ac_h($fd['links']['outlook']) ?>" target="_blank" rel="noopener">Outlook.com</a>
          <a class="ac-btn ac-btn--sm" href="<?= ac_h($fd['links']['office']) ?>" target="_blank" rel="noopener">Office 365</a>
        </div>
        <p class="ac-hint">Anyone with the link sees this calendar. Moves and cancellations reach subscribers within hours.</p>
        <div class="ac-row ac-mt">
          <?php ac_form('sched_feed', 'timetable', ['kind' => $fk, 'subject' => (int) $fd['id'], 'rotate' => 1] + $keep, 'class="ac-row"'); ?><button class="ac-btn ac-btn--sm" type="submit">Issue a new link</button><?php ac_form_end(); ?>
          <?php ac_form('sched_feed', 'timetable', ['kind' => $fk, 'subject' => (int) $fd['id'], 'revoke' => 1] + $keep, 'class="ac-row"'); ?><button class="ac-btn ac-btn--sm" type="submit">Withdraw the link</button><?php ac_form_end(); ?>
        </div>
      <?php else: ?>
        <?php ac_form('sched_feed', 'timetable', ['kind' => $fk, 'subject' => (int) $fd['id']] + $keep, 'class="ac-row"'); ?>
          <button class="ac-btn ac-btn--primary" type="submit">Get a calendar link</button>
        <?php ac_form_end(); ?>
      <?php endif; ?>
    </div>
  <?php ac_card_close(); ?>
<?php endforeach; ?>

<div class="ac-cols ac-cols--2">
<?php ac_card_open('Teacher load', 'This week'); ?>
  <div class="ac-tablewrap">
    <table class="ac-table">
      <thead><tr><th>Teacher</th><th class="ac-num">Sessions</th><th>Hours</th></tr></thead>
      <tbody>
        <?php if (!($d['load'] ?? [])): ?><?php ac_table_empty(3, 'No teaching scheduled this week.'); ?><?php endif; ?>
        <?php foreach ($d['load'] ?? [] as $l): $pct = (int) min(100, round($l['min'] / (25 * 60) * 100)); ?>
          <tr><td><?= ac_h($l['name'] ?: '—') ?></td><td class="ac-num"><?= (int) $l['sessions'] ?></td>
            <td><div class="ac-row"><?= ac_meter($pct, $pct > 90 ? 'bad' : 'accent') ?><span class="ac-pctfig"><?= ac_h(rtrim(rtrim(number_format($l['min'] / 60, 1), '0'), '.')) ?>h</span></div></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php ac_card_close('Against a 25-hour teaching week — a way to see who is stretched, not a contract.'); ?>

<?php if ($ig = $d['integrations'] ?? null): ?>
<?php ac_card_open('Integrations', 'Meetings, calendars, messages'); ?>
  <div class="ac-tablewrap">
    <table class="ac-table">
      <thead><tr><th>Platform</th><th>State</th><th>What it needs</th></tr></thead>
      <tbody>
        <?php foreach ($ig['list'] as $it): ?>
          <tr><td class="ac-strong"><?= ac_h($it['name']) ?></td>
            <td><?= ac_pill($it['ready'] ? 'Working' : 'Not set up', $it['ready'] ? 'good' : '') ?></td>
            <td><?= ac_h($it['needs']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="ac-cardbody">
    <?php if (!$ig['jaas_ready']): ?><?php ac_banner('warn', 'fa-video-slash', '<strong>Live classes are not set up.</strong> Online sessions still get their class page, which says so until JaaS keys are added below.'); ?><?php endif; ?>
    <p class="ac-note">Every online and hybrid session gets its own link automatically<?= $ig['order'] ? ', tried in this order: ' . ac_h(implode(' → ', array_map('strtoupper', $ig['order']))) . '. A provider that fails falls through to the next' : '' ?>. Nobody types a link.</p>
    <?php if ($ig['pending_links'] > 0): ?>
      <?php ac_form('sched_meeting_backfill', 'timetable', $keep, 'class="ac-row ac-mt"'); ?>
        <button type="submit" class="ac-btn ac-btn--primary">Generate links for <?= (int) $ig['pending_links'] ?> upcoming online session<?= $ig['pending_links'] === 1 ? '' : 's' ?></button>
      <?php ac_form_end(); ?>
    <?php endif; ?>
    <details class="ac-mt"><summary>Settings</summary>
      <?php ac_form('sched_integrations_save', 'timetable', $keep, 'class="ac-tt-form ac-mt" aria-label="Integration settings"'); ?>
        <label class="ac-field"><span class="ac-label">Meeting provider to try first</span>
          <select class="ac-select" name="meeting_link_provider">
            <?php foreach (['auto' => 'Automatic (JaaS, then Meet, then Zoom)', 'jaas' => 'JaaS (8x8)', 'meet' => 'Google Meet', 'zoom' => 'Zoom'] as $pv => $pt): ?>
              <option value="<?= $pv ?>"<?= $ig['provider'] === $pv ? ' selected' : '' ?>><?= ac_h($pt) ?></option>
            <?php endforeach; ?>
          </select></label>
        <div class="ac-grid ac-grid--2 ac-mt">
          <label class="ac-field"><span class="ac-label">JaaS app ID</span>
            <input class="ac-input" name="jaas_app_id" value="<?= ac_h($ig['jaas_app_id']) ?>" placeholder="vpaas-magic-cookie-…"></label>
          <label class="ac-field"><span class="ac-label">JaaS key ID <span class="ac-sub">(blank keeps)</span></span>
            <input class="ac-input" name="jaas_kid" autocomplete="off"></label>
          <label class="ac-field"><span class="ac-label">JaaS private key <span class="ac-sub"><?= $ig['jaas_ready'] ? '(set — blank keeps it)' : '(PEM)' ?></span></span>
            <textarea class="ac-textarea" name="jaas_private_key" rows="2" autocomplete="off"></textarea></label>
          <label class="ac-field"><span class="ac-label">Kapso phone number ID</span>
            <input class="ac-input" name="kapso_phone_number_id" inputmode="numeric" value="<?= ac_h($ig['kapso']['phone_number_id']) ?>"></label>
          <label class="ac-field"><span class="ac-label">Kapso API key <span class="ac-sub"><?= $ig['kapso']['key_set'] ? '(set — blank keeps it)' : '(not set)' ?></span></span>
            <input class="ac-input" type="password" name="kapso_api_key" autocomplete="off"></label>
          <label class="ac-field"><span class="ac-label">Kapso webhook secret <span class="ac-sub"><?= $ig['kapso']['secret_set'] ? '(set)' : '(optional)' ?></span></span>
            <input class="ac-input" type="password" name="kapso_webhook_secret" autocomplete="off"></label>
          <label class="ac-field"><span class="ac-label">Zoom account ID <span class="ac-sub"><?= $ig['zoom']['account_set'] ? '(set)' : '(optional)' ?></span></span>
            <input class="ac-input" type="password" name="zoom_account_id" autocomplete="off"></label>
          <label class="ac-field"><span class="ac-label">Zoom client ID <span class="ac-sub"><?= $ig['zoom']['client_set'] ? '(set)' : '' ?></span></span>
            <input class="ac-input" type="password" name="zoom_client_id" autocomplete="off"></label>
          <label class="ac-field"><span class="ac-label">Zoom client secret</span>
            <input class="ac-input" type="password" name="zoom_client_secret" autocomplete="off"></label>
        </div>
        <label class="ac-tick ac-mt"><input type="checkbox" name="kapso_message_students" value="1"<?= $ig['kapso']['students'] ? ' checked' : '' ?>> Also message students' own numbers (off: guardians and staff only)</label>
        <div class="ac-row ac-mt"><button type="submit" class="ac-btn ac-btn--primary">Save</button></div>
      <?php ac_form_end(); ?>
    </details>
    <?php if ($ig['list'][4]['ready']): ?>
      <?php ac_form('sched_kapso_test', 'timetable', $keep, 'class="ac-inline ac-mt" aria-label="Send a WhatsApp test"'); ?>
        <label class="ac-field"><span class="ac-label">Send a WhatsApp test to</span><input class="ac-input" name="number" inputmode="tel" placeholder="0803 123 4567" required></label>
        <button type="submit" class="ac-btn">Send test</button>
      <?php ac_form_end(); ?>
      <p class="ac-hint">Last 7 days: <?= (int) $ig['kapso']['sent_7d'] ?> sent, <?= (int) $ig['kapso']['failed_7d'] ?> failed.</p>
    <?php endif; ?>
  </div>
<?php ac_card_close('Secrets are never shown again once saved; the environment (api/.env) is preferred and wins over these fields.'); ?>
<?php endif; ?>
</div>

<?php ac_card_open('Rooms', count($d['rooms'] ?? []) . ' on file'); ?>
  <div class="ac-tablewrap">
    <table class="ac-table">
      <thead><tr><th>Room</th><th class="ac-num">Capacity</th><th>Used this week</th><th></th></tr></thead>
      <tbody>
        <?php if (!($d['rooms'] ?? [])): ?>
          <?php ac_table_empty(4, 'No rooms recorded. A session can still name a room as free text.'); ?>
        <?php else: foreach ($d['rooms'] as $r): ?>
          <tr>
            <td><div class="ac-strong"><?= ac_h((string) $r['name']) ?></div>
              <?php if (!empty($r['location'])): ?><div class="ac-sub"><?= ac_h((string) $r['location']) ?></div><?php endif; ?></td>
            <td class="ac-num"><?= $r['capacity'] !== null ? (int) $r['capacity'] : '—' ?></td>
            <td><div class="ac-row"><?= ac_meter((int) $r['util'], (int) $r['util'] > 90 ? 'bad' : 'accent') ?>
              <span class="ac-pctfig"><?= (int) $r['util'] ?>%</span></div></td>
            <td><a class="ac-btn ac-btn--sm" href="<?= ac_h(ac_url('timetable', array_merge($state, ['by' => 'room', 'room' => (int) $r['id']]))) ?>">This room's week</a></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
<?php ac_card_close('Utilisation is against a nominal eight-hour day, six days a week — a comparison between rooms, not a claim about capacity.'); ?>
