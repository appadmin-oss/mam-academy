<?php
/** Cohorts & enrolments — seats count distinct students, never rows. */
declare(strict_types=1);
/** @var array $d */
$rows = $d['rows'] ?? [];
$full = array_values(array_filter($rows, fn($r) => !empty($r['is_full'])));
$teacherless = array_values(array_filter($rows, fn($r) => !empty($r['no_instructor']) && (string) $r['status'] === 'active'));
?>
<?php ac_head('Cohorts & enrolments',
    'Seats count distinct students, never rows · ' . count($rows) . ' cohort' . (count($rows) === 1 ? '' : 's'),
    '<a class="ac-btn ac-btn--primary" href="' . ac_h(ac_url('roster')) . '">Enrol & staffing</a>'); ?>

<?php if ($teacherless): ?>
  <?php ac_banner('warn', 'fa-user-slash',
    '<strong>' . count($teacherless) . ' active cohort' . (count($teacherless) === 1 ? '' : 's')
    . ' with no instructor attached.</strong> A roster with nobody assigned is a roster no instructor can open — '
    . 'the instructor list keys off the cohort\'s teacher OR an enrolment\'s, so setting neither hides the class from everyone.'); ?>
<?php endif; ?>

<?php if ((int) ($d['legacy_only'] ?? 0) > 0): ?>
  <?php ac_banner('flat', 'fa-code-branch',
    '<strong>' . (int) $d['legacy_only'] . ' legacy class' . ((int) $d['legacy_only'] === 1 ? '' : 'es')
    . ' with no cohort.</strong> This schema carries two enrolment models and they are not reconciled. '
    . 'Every read in this console spans both; a purely-legacy class must be course-mapped before it can be converged.',
    '<a class="ac-btn ac-btn--sm" href="/admin/?v=classes">The legacy classes</a>'); ?>
<?php endif; ?>

<div class="ac-kpis">
  <?php foreach ($rows as $r):
    $cap = $r['capacity'] === null ? null : (int) $r['capacity'];
    $tone = !empty($r['is_full']) ? 'bad' : ((string) $r['status'] === 'active' ? 'accent' : ''); ?>
    <div class="ac-card">
      <div class="ac-cardhead">
        <div class="ac-grow">
          <div class="ac-cardtitle"><?= ac_h((string) $r['title']) ?></div>
          <div class="ac-sub"><?= ac_h(trim(implode(' · ', array_filter([
              (string) ($r['course_code'] ?? ''), (string) ($r['level_code'] ?? ''),
              (string) ($r['term'] ?? ''), (string) ($r['mode'] ?? ''),
              (string) ($r['schedule_days'] ?? '') . ' ' . (string) ($r['schedule_time'] ?? ''),
              (string) ($r['instructor'] ?? '') ?: 'unassigned'])))) ?></div>
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
        <?php if ((int) ($r['legacy_count'] ?? 0) > 0): ?>
          <div class="ac-note ac-mt-sm">Plus <strong><?= (int) $r['legacy_count'] ?></strong>
            on the backfilled class roster, who are not yet gradable.</div>
        <?php endif; ?>
      </div>
      <div class="ac-cardfoot">
        <div class="ac-row">
          <a class="ac-btn ac-btn--sm" href="<?= ac_h(ac_url('roster', ['cohort' => (int) $r['id']])) ?>">Roster</a>
          <?php if ((int) $r['waiting'] > 0): ?>
            <?php ac_action_btn('waitlist_promote', 'cohorts', ['cohort_id' => (int) $r['id']],
                'Promote next', 'ac-btn ac-btn--sm ac-btn--primary'); ?>
          <?php endif; ?>
          <?php if ((int) ($r['legacy_count'] ?? 0) > 0): ?>
            <?php ac_action_btn('cohort_converge', 'cohorts', ['cohort_id' => (int) $r['id']],
                'Converge', 'ac-btn ac-btn--sm',
                'Enrol this cohort\'s legacy roster into course_enrollments so they become gradable? Additive and idempotent.'); ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$rows): ?>
    <div class="ac-card"><div class="ac-cardbody">
      <?php ac_empty('No cohorts yet', 'A cohort is created from Enrol & staffing, or resolved automatically when a payment settles.',
        '<a class="ac-btn" href="' . ac_h(ac_url('roster')) . '">Enrol & staffing</a>'); ?>
    </div></div>
  <?php endif; ?>
</div>

<div class="ac-cols ac-cols--2">
  <?php ac_card_open('What a full cohort does'); ?>
    <div class="ac-cardbody">
      <p class="ac-note">Enrolling into a full cohort is <strong>refused and queued</strong>, not silently failed:
      the add returns a waitlist position rather than an enrolment, and a caller that reads the returned id without
      checking gets <span class="ac-mono">null</span>, not a wrong number.</p>
      <p class="ac-note">Leaving a seat-occupying status drains the queue and notifies the next family. That drain is
      best-effort by design — a withdrawal must never fail because the queue could not be drained.</p>
    </div>
  <?php ac_card_close('Capacity <span class="ac-mono">null</span> means uncapped. Zero would mean permanently full, and the schema keeps that distinction deliberately.'); ?>

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
