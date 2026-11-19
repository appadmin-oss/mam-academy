<?php
/** Attendance — register first, platform log second. */
declare(strict_types=1);
/** @var array $d */
$w = $d['window'] ?? null;
?>
<?php ac_head('Attendance',
    ac_h(date('l j F', strtotime((string) $d['day']))) . ' · ' . count($d['sessions'] ?? [])
    . ' class' . (count($d['sessions'] ?? []) === 1 ? '' : 'es') . ' · register first, platform log second',
    '<a class="ac-btn" href="' . ac_h(ac_url('attendance', ['d' => date('Y-m-d', strtotime((string) $d['day'] . ' -1 day'))])) . '">← Yesterday</a>'
    . '<a class="ac-btn" href="' . ac_h(ac_url('attendance')) . '">Today</a>'
    . '<a class="ac-btn" href="' . ac_h(ac_url('attendance', ['d' => date('Y-m-d', strtotime((string) $d['day'] . ' +1 day'))])) . '">Tomorrow →</a>'); ?>

<?php ac_kpis([
    ['label' => 'Marked', 'value' => (int) ($d['marked'] ?? 0) . ' of ' . (int) ($d['total'] ?? 0),
     'note' => 'classes with a register'],
    ['label' => 'Present', 'value' => (string) (int) ($d['present'] ?? 0),
     'note' => 'of ' . (int) ($d['enrolled'] ?? 0) . ' enrolled today'],
    ['label' => 'Second absence or worse', 'value' => (string) count($d['absent'] ?? []),
     'tone' => count($d['absent'] ?? []) > 0 ? 'warn' : '', 'note' => 'unexplained, last ninety days'],
    ['label' => 'Register late', 'value' => (string) (int) ($d['late'] ?? 0),
     'tone' => (int) ($d['late'] ?? 0) > 0 ? 'bad' : '', 'note' => 'the instructor has not marked it'],
]); ?>

<?php if ($w): ?>
  <?php ac_banner('flat', 'fa-clock',
    ac_h((string) ($w['notice'] ?? 'Check-in opens before the session starts.'))
    . ' <span class="ac-subi">Both boundaries come from one function; nothing restates '
    . 'them in the browser, so changing the setting changes the gate and the sentence together.</span>'); ?>
<?php endif; ?>

<?php ac_card_open('Today\'s registers'); ?>
  <div class="ac-tablewrap">
    <table class="ac-table">
      <thead><tr><th>Time</th><th>Class</th><th>Instructor</th><th class="ac-num">Present</th><th>Register</th></tr></thead>
      <tbody>
        <?php if (!($d['sessions'] ?? [])): ?>
          <?php ac_table_empty(5, 'No sessions scheduled on this day.'); ?>
        <?php else: foreach ($d['sessions'] as $s):
          $ts = strtotime((string) $s['scheduled_at']);
          $marks = (int) $s['marks'];
          $overdue = $marks === 0 && $ts < time() - 3600; ?>
          <tr>
            <td class="ac-num ac-mono"><?= ac_h(date('H:i', $ts)) ?></td>
            <td><div class="ac-strong"><?= ac_h((string) ($s['title'] ?: $s['cohort'] ?: 'Session')) ?>
                <?php if ((string) ($s['mode'] ?? '') === 'online'): ?><?= ac_tag('online') ?><?php endif; ?></div>
                <div class="ac-sub"><?= ac_h(trim(implode(' · ', array_filter([
                    (string) ($s['cohort'] ?? ''), (string) ($s['room'] ?? '')])))) ?></div></td>
            <td><?= ac_h((string) ($s['instructor'] ?: '—')) ?></td>
            <td class="ac-num"><?= $marks > 0 ? (int) $s['present'] . ' / ' . (int) $s['enrolled'] : '<span class="ac-sub">—</span>' ?></td>
            <td>
              <?php if ($marks > 0): ?>
                <?= ac_pill('Marked ' . mam_console_date((string) $s['marked_at'], 'H:i'), 'good') ?>
              <?php elseif ($overdue): ?>
                <?= ac_pill((int) round((time() - $ts) / 60) . ' min late', 'bad') ?>
              <?php else: ?>
                <?= ac_pill('Scheduled') ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
<?php ac_card_close('Seven statuses, not four: <span class="ac-mono">left_early</span>, <span class="ac-mono">technical_failure</span> and <span class="ac-mono">rescheduled</span> exist so that what happened is not recorded as an absence that misrepresents it. Durations are STORED, never recomputed on read — a session\'s scheduled time can be edited afterwards and the record of what actually happened must not move with it.'); ?>

<div class="ac-cols ac-cols--2">
  <?php ac_card_open('Platform log', (string) (($d['reconciliation'][0]['provider'] ?? '') ?: 'nothing imported')); ?>
    <div class="ac-cardbody">
      <p class="ac-note">The register is what the instructor saw. The platform log is what the platform recorded.
      Where they disagree, <strong>the register wins</strong> and the difference is kept — it is evidence, not an
      error to overwrite.</p>
      <?php if ($d['reconciliation'] ?? []): ?>
        <div class="ac-tablewrap ac-mt">
          <table class="ac-table">
            <thead><tr><th>Learner</th><th>Join</th><th>Leave</th><th class="ac-num">Minutes</th></tr></thead>
            <tbody>
              <?php foreach ($d['reconciliation'] as $r): ?>
                <tr>
                  <td><?= ac_h((string) ($r['display_name'] ?: '—')) ?>
                      <div class="ac-sub"><?= ac_h((string) ($r['title'] ?? '')) ?></div></td>
                  <td class="ac-mono"><?= ac_h(mam_console_date((string) $r['join_at'], 'H:i')) ?></td>
                  <td class="ac-mono"><?= ac_h(mam_console_date((string) $r['leave_at'], 'H:i')) ?></td>
                  <td class="ac-num"><?= $r['minutes'] !== null ? (int) $r['minutes'] : '—' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="ac-note ac-mt-sm">Nothing imported for this day. Meeting attendance is filled in by the
          scheduled Google Meet sync, where one is configured.</div>
      <?php endif; ?>
    </div>
  <?php ac_card_close('Reports emits the moment a participant LEFT plus a duration — there is no join timestamp, so the join is derived by subtraction. A failure here is a degradation, never an exception: the portal clock-in stays the official record.'); ?>

  <?php ac_card_open('Second absence, unexplained', count($d['absent'] ?? []) . ' learner' . (count($d['absent'] ?? []) === 1 ? '' : 's')); ?>
    <div class="ac-cardbody">
      <p class="ac-note">A first absence sends nothing — people get ill. The second opens a conversation, and the
      message names the classes rather than a percentage.</p>
      <?php if ($d['absent'] ?? []): ?>
        <div class="ac-tablewrap ac-mt">
          <table class="ac-table">
            <thead><tr><th>Learner</th><th class="ac-num">Absences</th><th>Escalation</th></tr></thead>
            <tbody>
              <?php foreach ($d['absent'] as $a): ?>
                <tr>
                  <td><div class="ac-strong"><?= ac_h((string) $a['name']) ?></div></td>
                  <td class="ac-num"><?= (int) $a['n'] ?></td>
                  <td><?= ac_pill((string) $a['level'], (int) $a['n'] >= 6 ? 'bad' : 'warn') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="ac-note ac-mt-sm">Nobody has a second unexplained absence.</div>
      <?php endif; ?>
    </div>
  <?php ac_card_close('The escalation level is never shown to the learner. A child who knows they are on a list stops turning up.'); ?>
</div>
