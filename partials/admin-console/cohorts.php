<?php
/** Cohorts & enrolments — seats count distinct students, never rows. */
declare(strict_types=1);
/** @var array $d */
$rows = $d['rows'] ?? [];
$full = array_values(array_filter($rows, fn($r) => !empty($r['is_full'])));
$teacherless = array_values(array_filter($rows, fn($r) => !empty($r['no_instructor']) && (string) $r['status'] === 'active'));
/* Filters, allow-listed with a fallback, so a stale bookmark shows the board
   rather than an error. Every cohort used to draw at once — 105 cards was
   6,600px on a desktop and 22,000px on a phone — and the classes that need
   somebody (no teacher, full, people waiting) were somewhere in the middle. */
$filters = [
    'all'       => ['All',            fn($r) => true],
    'teacher'   => ['No instructor',  fn($r) => !empty($r['no_instructor']) && (string) $r['status'] === 'active'],
    'full'      => ['Full',           fn($r) => !empty($r['is_full'])],
    'waiting'   => ['People waiting', fn($r) => (int) ($r['waiting'] ?? 0) > 0],
];
$f = (string) ($_GET['f'] ?? 'all');
if (!isset($filters[$f])) $f = 'all';
$shown = array_values(array_filter($rows, $filters[$f][1]));
?>
<?php ac_head('Cohorts',
    'Seats count distinct students, never rows · ' . count($rows) . ' cohort' . (count($rows) === 1 ? '' : 's'),
    '<a class="ac-btn ac-btn--primary" href="' . ac_h(ac_url('roster')) . '">Enrol & staffing</a>'); ?>

<?php if ($teacherless): ?>
  <?php ac_banner('warn', 'fa-user-slash',
    '<strong>' . count($teacherless) . ' active cohort' . (count($teacherless) === 1 ? '' : 's')
    . ' with no instructor attached.</strong> A roster with nobody assigned is a roster no instructor can open — '
    . 'the instructor list keys off the cohort\'s teacher OR an enrolment\'s, so setting neither hides the class from everyone.'); ?>
<?php endif; ?>

<?php $old = $d['old_classes'] ?? []; if ($old): ?>
  <?php ac_card_open('Old classes to bring across', count($old) . ' waiting'); ?>
    <?php ac_form('legacy_carry', 'cohorts'); ?>
      <div class="ac-tablewrap">
        <table class="ac-table">
          <thead><tr><th>Class</th><th class="ac-num">Students</th><th class="ac-num">Attendance marks</th><th>Course</th></tr></thead>
          <tbody>
            <?php foreach ($old as $o): $cid = (int) $o['class_id']; ?>
              <tr>
                <td class="ac-strong"><?= ac_h((string) $o['title']) ?><div class="ac-sub"><?= ac_h((string) ($o['discipline'] ?: 'no subject given')) ?></div></td>
                <td class="ac-num"><?= (int) $o['students'] ?></td>
                <td class="ac-num"><?= (int) $o['marks'] ?></td>
                <td><?php if ((int) ($o['course_id'] ?? 0) > 0): ?>
                    <span class="ac-sub">Matched</span>
                  <?php else: ?>
                    <label class="ac-sr" for="cf-<?= $cid ?>">Course for <?= ac_h((string) $o['title']) ?></label>
                    <select class="ac-select ac-w-fit" id="cf-<?= $cid ?>" name="course_for[<?= $cid ?>]">
                      <option value="">Match by subject</option>
                      <?php foreach ($d['courses'] ?? [] as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"><?= ac_h((string) $c['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  <?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="ac-cardbody ac-row">
        <button type="submit" class="ac-btn ac-btn--primary">Bring them across</button>
      </div>
    <?php ac_form_end(); ?>
  <?php ac_card_close('Students, attendance, notices and homework move into each class\'s cohort. Nothing is deleted, and pressing it twice changes nothing.'); ?>
<?php endif; ?>

<div class="ac-tabs">
  <?php foreach ($filters as $k => [$label, $test]): ?>
    <a class="ac-tab" href="<?= ac_h(ac_url('cohorts', ['f' => $k === 'all' ? null : $k])) ?>" <?= $f === $k ? 'aria-current="true"' : '' ?>>
      <?= ac_h($label) ?><span class="ac-tabn"><?= count(array_filter($rows, $test)) ?></span>
    </a>
  <?php endforeach; ?>
</div>

<div class="ac-kpis">
  <?php foreach ($shown as $r):
    $cap = $r['capacity'] === null ? null : (int) $r['capacity'];
    $tone = !empty($r['is_full']) ? 'bad' : ((string) $r['status'] === 'active' ? 'accent' : ''); ?>
    <div class="ac-card">
      <div class="ac-cardhead">
        <div class="ac-grow">
          <div class="ac-cardtitle"><?= ac_h((string) $r['title']) ?></div>
          <div class="ac-sub"><?= ac_h(ac_join([
              (string) ($r['course_code'] ?? ''), (string) ($r['level_code'] ?? ''),
              (string) ($r['mode'] ?? ''),
              (string) ($r['schedule_days'] ?? '') . ' ' . (string) ($r['schedule_time'] ?? ''),
              (string) ($r['instructor'] ?? '') ?: 'unassigned'])) ?></div>
          <div class="ac-row ac-mt-sm">
            <?php if ((string) ($r['programme_label'] ?? '') !== ''): ?><?= ac_pill((string) $r['programme_label'], 'accent') ?><?php endif; ?>
            <?= ac_pill((string) ($r['term_label'] ?? 'Year-round')) ?>
          </div>
        </div>
        <?= !empty($r['is_full']) ? ac_pill('Full', 'bad')
            : ($r['uncapped'] ? ac_pill('Uncapped') : ac_status((string) $r['status'])) ?>
      </div>
      <div class="ac-cardbody">
        <div class="ac-row ac-baseline">
          <span class="ac-figure"><?= (int) $r['fill'] ?></span>
          <span class="ac-sub"><?= $cap === null ? 'enrolled · uncapped' : 'of ' . $cap . ' seats' ?>
            <?php if ((int) $r['waiting'] > 0): ?> · <?= (int) $r['waiting'] ?> waiting<?php endif; ?>
          </span>
        </div>
        <?php if ($cap !== null): ?>
          <div class="ac-mt-sm"><?= ac_meter((int) $r['pct'], !empty($r['is_full']) ? 'bad' : 'accent') ?></div>
        <?php endif; ?>
      </div>
      <div class="ac-cardfoot">
        <div class="ac-row">
          <a class="ac-btn ac-btn--sm" href="<?= ac_h(ac_url('roster', ['cohort' => (int) $r['id']])) ?>">Roster</a>
          <?php if ((int) $r['waiting'] > 0): ?>
            <?php ac_action_btn('waitlist_promote', 'cohorts', ['cohort_id' => (int) $r['id']],
                'Promote next', 'ac-btn ac-btn--sm ac-btn--primary'); ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if ($rows && !$shown): ?>
    <div class="ac-card"><div class="ac-cardbody">
      <?php ac_empty('None here', 'No cohort matches this filter.', '<a class="ac-btn" href="' . ac_h(ac_url('cohorts')) . '">Show all</a>'); ?>
    </div></div>
  <?php endif; ?>
  <?php if (!$rows): ?>
    <div class="ac-card"><div class="ac-cardbody">
      <?php ac_empty('No cohorts yet', 'A cohort is created from Enrol & staffing, or resolved automatically when a payment settles.',
        '<a class="ac-btn" href="' . ac_h(ac_url('roster')) . '">Enrol & staffing</a>'); ?>
    </div></div>
  <?php endif; ?>
</div>

<div class="ac-cols ac-cols--2">
  <?php ac_card_open('When a cohort is full'); ?>
    <div class="ac-cardbody">
      <p class="ac-note">A new enrolment joins the <strong>waiting list</strong>, with its place in the queue, instead of
      taking a seat.</p>
      <p class="ac-note">When a student leaves, the next family on the list is given the seat and told by email.</p>
    </div>
  <?php ac_card_close('No capacity set means no limit. A capacity of 0 means no new places.'); ?>

  <?php ac_card_open('Seats vs. teachable'); ?>
    <div class="ac-cardbody">
      <div class="ac-defs">
        <?php
          ac_def('Seat count', 'active + pending, distinct students');
          ac_def('Teachable roster', 'active + completed, distinct students');
          ac_def('Full cohorts', (string) count($full));
          ac_def('Waiting overall', (string) array_sum(array_map(fn($r) => (int) $r['waiting'], $rows)));
        ?>
      </div>
    </div>
  <?php ac_card_close('The two status sets differ deliberately, and both count DISTINCT students — so they can differ by status but never by row multiplicity.'); ?>
</div>
