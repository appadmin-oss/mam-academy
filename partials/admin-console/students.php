<?php
/** Students — bands, never a sortable composite. */
declare(strict_types=1);
/** @var array $d */
$stage = (string) ($d['stage'] ?? '');
?>
<?php ac_head('Students',
    'Membership stage, not a status guess · ' . (int) ($d['stages']['active'] ?? 0) . ' current',
    '<a class="ac-btn" href="/admin/?v=export&amp;type=users.csv">Export</a>'); ?>

<div class="ac-tabs">
  <a class="ac-tab" href="<?= ac_h(ac_url('students')) ?>" <?= $stage === '' ? 'aria-current="true"' : '' ?>>All</a>
  <?php foreach (['active' => 'Current student', 'roster' => 'Roster only', 'suspended' => 'Suspended'] as $k => $label): ?>
    <a class="ac-tab" href="<?= ac_h(ac_url('students', ['stage' => $k])) ?>" <?= $stage === $k ? 'aria-current="true"' : '' ?>>
      <?= ac_h($label) ?><span class="ac-tabn"><?= (int) ($d['stages'][$k] ?? 0) ?></span>
    </a>
  <?php endforeach; ?>
  <form method="get" class="ac-row ac-push">
    <input type="hidden" name="v" value="students">
    <input class="ac-input ac-input--sm ac-searchbox" type="search" name="q"
           value="<?= ac_h($d['q'] ?? '') ?>" placeholder="Name, email or reference…">
    <button type="submit" class="ac-btn ac-btn--sm">Find</button>
  </form>
</div>

<?php ac_card_open('', '', ''); ?>
  <div class="ac-tablewrap">
    <table class="ac-table">
      <thead>
        <tr><th>Number</th><th>Name</th><th class="ac-num">Learning</th><th class="ac-num">Attendance</th>
            <th>Band</th><th class="ac-num">Owed</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!($d['rows'] ?? [])): ?>
          <?php ac_table_empty(7, 'No students match. A student account is created at provisioning, from a settled payment or by hand.'); ?>
        <?php else: foreach ($d['rows'] as $r): ?>
          <tr>
            <td class="ac-mono"><?= ac_h((string) ($r['enrollment_ref'] ?: '—')) ?></td>
            <td>
              <div class="ac-row">
                <?= ac_avatar((string) $r['name'], (string) ($r['avatar_url'] ?? '')) ?>
                <div class="ac-grow">
                  <div class="ac-strong"><?= ac_h((string) $r['name']) ?>
                    <?php if ((string) ($r['student_type'] ?? '') === 'scholarship'): ?>
                      <?= ac_pill('Scholar', 'accent') ?>
                    <?php endif; ?>
                  </div>
                  <div class="ac-sub"><?= ac_h((string) $r['email']) ?></div>
                </div>
              </div>
            </td>
            <td class="ac-num">
              <?= (int) $r['courses'] ?> course<?= (int) $r['courses'] === 1 ? '' : 's' ?>
              <?php if ((int) $r['classes'] > 0): ?>
                <div class="ac-sub"><?= (int) $r['classes'] ?> legacy class<?= (int) $r['classes'] === 1 ? '' : 'es' ?></div>
              <?php endif; ?>
            </td>
            <td class="ac-num"><?= $r['attendance'] !== null ? (int) $r['attendance'] . '%' : '<span class="ac-sub">—</span>' ?></td>
            <td>
              <?php if (is_array($r['band'] ?? null)): ?>
                <?= ac_pill((int) ($r['band'][0] ?? 0) . '–' . (int) ($r['band'][1] ?? 0)) ?>
              <?php else: ?>
                <span class="ac-sub">Not enough yet</span>
              <?php endif; ?>
            </td>
            <td class="ac-num">
              <?php $owed = (int) ($r['owed'] ?? 0); ?>
              <?php if ($owed > 0): ?><span class="ac-pill ac-pill--bad"><?= ac_h(mam_console_usd($owed)) ?></span>
              <?php else: ?><span class="ac-sub">—</span><?php endif; ?>
            </td>
            <td class="ac-num">
              <a class="ac-btn ac-btn--sm" href="/admin/?v=users&amp;edit=<?= (int) $r['id'] ?>">Record</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
<?php ac_card_close(
    'A <strong>band</strong> rather than a computed score in a sortable column: sorting a cohort of children by a '
    . 'composite turns the table into a ranking whatever the heading says. Where a student has too little evidence '
    . 'there is no composite at all — "not enough yet" is a different statement from a low score, and the raw figure '
    . 'is never shown to a student, a guardian or a league table. '
    . '<strong>Learning</strong> counts both enrolment models, so a course-only student is not shown as enrolled in nothing.'); ?>
