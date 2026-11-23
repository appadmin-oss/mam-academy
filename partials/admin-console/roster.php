<?php
/**
 * Enrol & staffing — pick a cohort and run it: who is in it and how they are
 * doing, who is waiting, who teaches it, what is coming up; enrol, move,
 * withdraw, approve; change the instructor with the safeguarding gate's verdict
 * shown BEFORE saving; close it. The legacy classes sit in their own panel
 * underneath, because they are a second record of the same students (gotcha #2).
 *
 * Every write posts to /admin/ (admin/index.php); the rules live in
 * api/lib/cohort-admin.php and lms.php, never here. `_cohort` / `_class` on a
 * form tell the console's return hook which record to come back to.
 */
declare(strict_types=1);
/** @var array $d */
$c = $d['cohort'] ?? null;
$sel = (int) ($d['selected'] ?? 0);
$mode = (string) ($d['vetting_mode'] ?? 'warn');
$back = ['_cohort' => $sel ?: ''];
$cls = $d['class'] ?? null;
$clsSel = (int) ($d['class_sel'] ?? 0);
$live = array_values(array_filter($d['roster'] ?? [], fn($r) => !empty($r['live'])));
$ended = array_values(array_filter($d['roster'] ?? [], fn($r) => empty($r['live'])));
?>
<?php ac_head('Enrol & staffing',
    ac_h((string) ($d['term']['line'] ?? '')) . ' · a private class is a cohort with capacity 1',
    '<a class="ac-btn" href="' . ac_h(ac_url('cohorts')) . '">All cohorts</a>'
    . ' <a class="ac-btn" href="' . ac_h(ac_url('timetable')) . '">Timetable</a>'); ?>

<div class="ac-cols ac-cols--rail">
  <?php ac_card_open('Cohorts', count($d['cohorts'] ?? []) . ''); ?>
    <div class="ac-queue">
      <?php foreach ($d['cohorts'] ?? [] as $r): ?>
        <a class="ac-qitem<?= (int) $r['id'] === $sel ? ' is-on' : '' ?>"
           href="<?= ac_h(ac_url('roster', ['cohort' => (int) $r['id']])) ?>"<?= (int) $r['id'] === $sel ? ' aria-current="true"' : '' ?>>
          <div>
            <div class="ac-qtitle"><?= ac_h((string) $r['title']) ?><?= ($r['status'] ?? '') !== 'active' ? ' · ' . ac_h(ucfirst((string) $r['status'])) : '' ?></div>
            <div class="ac-qwhy"><?= ac_h(ac_join([
                (string) ($r['course_code'] ?? ''), (string) ($r['schedule_days'] ?? ''),
                (string) ($r['schedule_time'] ?? ''), (string) ($r['instructor'] ?? '') ?: 'unassigned'])) ?></div>
          </div>
          <span class="ac-qage">
            <?= (int) $r['fill'] ?><?= $r['capacity'] === null ? '' : ' / ' . (int) $r['capacity'] ?><?= (int) ($r['waiting'] ?? 0) > 0 ? ' +' . (int) $r['waiting'] : '' ?>
          </span>
        </a>
      <?php endforeach; ?>
      <?php if (!($d['cohorts'] ?? [])): ?>
        <?php ac_empty('No cohorts', 'Create one below and it appears here.'); ?>
      <?php endif; ?>
    </div>
  <?php ac_card_close('Seats taken / capacity, then +N waiting. A private class is a cohort with capacity 1 — same registers, same credits, same report.'); ?>

  <div class="ac-stack">
    <?php if (!$c): ?>
      <?php ac_card_open('Nothing selected'); ?>
        <div class="ac-cardbody"><?php ac_empty('Pick a cohort', 'Its roster, waitlist, staff and sessions appear here.'); ?></div>
      <?php ac_card_close(); ?>
    <?php else:
      $isOpen = ($c['status'] ?? '') === 'active';
      $fill = (int) $c['fill']; $cap = $c['capacity'] === null ? null : (int) $c['capacity'];
      $waiting = count($d['waitlist'] ?? []);
      $pill = !$isOpen ? ac_pill('Closed', '') : (!empty($c['is_full']) ? ac_pill('Full', 'bad') : ac_status('active', 'Open'));
    ?>
      <?php /* ── The cohort ─────────────────────────────────────────── */ ?>
      <?php ac_card_open((string) $c['title'], ac_join([(string) ($c['course_name'] ?? $c['course_code'] ?? ''), (string) ($c['level_code'] ?? ''), (string) ($c['term'] ?? '')]), $pill); ?>
        <div class="ac-cardbody">
          <div class="ac-grid ac-grid--3">
            <div><div class="ac-kpi-l">Seats</div><div class="ac-strong ac-mt-xs">
              <?= $fill ?><?= $cap === null ? ' · uncapped' : ' of ' . $cap ?></div>
              <?php if ($cap): ?><div class="ac-mt-xs"><?= ac_meter(min(100, (int) $c['pct']), !empty($c['is_full']) ? 'bad' : 'accent') ?></div><?php endif; ?></div>
            <div><div class="ac-kpi-l">Waiting</div><div class="ac-strong ac-mt-xs"><?= $waiting ?></div></div>
            <div><div class="ac-kpi-l">Meets</div><div class="ac-strong ac-mt-xs">
              <?= ac_h(trim((string) ($c['schedule_days'] ?? '') . ' ' . (string) ($c['schedule_time'] ?? '')) ?: 'Not scheduled') ?></div></div>
          </div>
          <?php if (empty($c['course_code'])): ?>
            <div class="ac-mt"><?php ac_banner('warn', 'fa-triangle-exclamation',
              '<strong>Not linked to a course.</strong> Nobody can be enrolled into it until it is — a course enrolment needs a course.'); ?></div>
          <?php endif; ?>
        </div>
      <?php ac_card_close(); ?>

      <?php /* ── The roster ─────────────────────────────────────────── */ ?>
      <?php ac_card_open('Roster', count($live) . ' enrolled' . ($ended ? ' · ' . count($ended) . ' past' : '')); ?>
        <div class="ac-tablewrap">
          <table class="ac-table">
            <thead><tr><th>Student</th><th>Status</th><th>Attendance</th><th>Mastery</th><th class="ac-num">Act</th></tr></thead>
            <tbody>
              <?php if (!$live && !$ended): ?>
                <?php ac_table_empty(5, 'Nobody enrolled yet. Find a student below to enrol them.'); ?>
              <?php else: foreach (array_merge($live, $ended) as $s):
                $isCourse = ($s['model'] ?? '') === 'course'; ?>
                <tr>
                  <td><div class="ac-row"><?= ac_avatar((string) $s['name'], (string) ($s['avatar_url'] ?? '')) ?>
                    <div class="ac-grow"><div class="ac-strong"><?= ac_h((string) $s['name']) ?></div>
                    <div class="ac-sub"><?= ac_h(ac_join([(string) ($s['enrollment_ref'] ?? ''), (string) $s['email']])) ?></div></div></div></td>
                  <td><?= ac_status((string) $s['status']) ?>
                    <?php if (!$isCourse): ?><div class="ac-sub">Legacy class record</div><?php endif; ?></td>
                  <td><?php if (($s['att_pct'] ?? null) === null): ?><span class="ac-sub">No marks yet</span>
                    <?php else: ?><span class="ac-strong"><?= (int) $s['att_pct'] ?>%</span>
                      <div class="ac-sub">of <?= (int) $s['att_n'] ?> marked</div><?php endif; ?></td>
                  <td><?php if (!empty($s['band'])): ?><span class="ac-strong"><?= ac_h((string) $s['band']) ?></span>
                    <?php else: ?><span class="ac-sub"><?= ac_h((string) ($s['band_why'] ?? '') ?: 'Not assessed') ?></span><?php endif; ?></td>
                  <td class="ac-num">
                    <?php if ($isCourse && !empty($s['live'])): ?>
                      <div class="ac-row">
                        <?php foreach ($s['changes'] ?? [] as $to => $label): ?>
                          <?php ac_action_btn('cohort_enrolment_status', 'roster', $back + ['cohort_id' => $sel, 'enrollment_id' => (int) $s['id'], 'to' => $to],
                              $label, 'ac-btn ac-btn--sm' . ($to === 'active' ? ' ac-btn--primary' : ''),
                              $to === 'rejected' ? 'Reject this enrolment? It cannot be reopened.' : ''); ?>
                        <?php endforeach; ?>
                      </div>
                      <details class="ac-disclose"><summary class="ac-btn ac-btn--sm">Move…</summary>
                        <?php ac_form('cohort_transfer', 'roster', $back + ['cohort_id' => $sel, 'enrollment_id' => (int) $s['id']], 'class="ac-mt"'); ?>
                          <label class="ac-field"><span class="ac-label">To cohort</span>
                            <select class="ac-select" name="to_cohort_id" required>
                              <option value="">— pick —</option>
                              <?php foreach ($d['transfer_to'] ?? [] as $t): ?>
                                <option value="<?= (int) $t['id'] ?>"<?= $t['full'] ? ' disabled' : '' ?>><?= ac_h($t['title']) ?><?= $t['full'] ? ' (full)' : '' ?><?= $t['same_course'] ? '' : ' · other course' ?></option>
                              <?php endforeach; ?>
                            </select></label>
                          <label class="ac-field ac-mt-sm"><span class="ac-label">Why</span>
                            <input class="ac-input" name="reason" required minlength="3" maxlength="120" autocomplete="off" placeholder="Saturday suits the family better"></label>
                          <div class="ac-hint ac-mt-sm">The old enrolment is withdrawn: <?= ac_h((string) ($d['outcome_line'] ?? '')) ?>.</div>
                          <div class="ac-row ac-mt-sm"><button type="submit" class="ac-btn ac-btn--sm ac-btn--primary">Move</button></div>
                        <?php ac_form_end(); ?>
                      </details>
                      <details class="ac-disclose"><summary class="ac-btn ac-btn--sm">Withdraw…</summary>
                        <?php ac_form('cohort_withdraw', 'roster', $back + ['cohort_id' => $sel, 'enrollment_id' => (int) $s['id']], 'class="ac-mt"'); ?>
                          <label class="ac-field"><span class="ac-label">Why they are leaving</span>
                            <input class="ac-input" name="reason" required minlength="3" maxlength="160" autocomplete="off"></label>
                          <div class="ac-hint ac-mt-sm">If withdrawn today: <strong><?= ac_h((string) ($d['outcome_line'] ?? '')) ?></strong>.</div>
                          <div class="ac-row ac-mt-sm"><button type="submit" class="ac-btn ac-btn--sm ac-btn--danger">Withdraw</button></div>
                        <?php ac_form_end(); ?>
                      </details>
                    <?php elseif ($isCourse && (string) $s['status'] === 'completed'): ?>
                      <?php ac_action_btn('course_promote', 'roster', $back + ['enrollment_id' => (int) $s['id']], 'Next level'); ?>
                    <?php elseif (!$isCourse): ?>
                      <?php ac_action_btn('cohort_converge', 'roster', $back + ['cohort_id' => $sel], 'Bring across'); ?>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      <?php ac_card_close('Attendance counts present, late and left-early against every mark except excused and rescheduled. Mastery is the published band, never a raw score, and blank until there is enough evidence. A legacy class record is the same student in the older class model; “Bring across” enrols the class roster into this cohort so they can be marked and moved.'); ?>

      <?php /* ── Enrol a student ─────────────────────────────────────── */ ?>
      <?php ac_card_open('Enrol a student', $isOpen ? ($cap !== null && $fill >= $cap ? 'full — new students join the waitlist' : ($cap === null ? 'uncapped' : ($cap - $fill) . ' seat' . ($cap - $fill === 1 ? '' : 's') . ' free')) : 'closed'); ?>
        <div class="ac-cardbody">
          <form method="get" action="/admin/console.php" role="search">
            <input type="hidden" name="v" value="roster">
            <input type="hidden" name="cohort" value="<?= $sel ?>">
            <div class="ac-inline">
              <label class="ac-field"><span class="ac-label">Name, email or member number</span>
                <input class="ac-input" type="search" name="find" value="<?= ac_h((string) ($d['find'] ?? '')) ?>" minlength="2" autocomplete="off" placeholder="e.g. Ada, ada@…, MAM-2026-0012"></label>
              <button type="submit" class="ac-btn">Find</button>
            </div>
          </form>
        </div>
        <?php if (($d['find'] ?? '') !== ''): ?>
          <div class="ac-tablewrap">
            <table class="ac-table">
              <thead><tr><th>Student</th><th>What would happen</th><th class="ac-num"></th></tr></thead>
              <tbody>
                <?php if (!($d['found'] ?? [])): ?>
                  <?php ac_table_empty(3, 'No student matches “' . (string) $d['find'] . '”. Students are created by admission or from People & access.'); ?>
                <?php else: foreach ($d['found'] as $h): $p = $h['preview'] ?? []; ?>
                  <tr>
                    <td><div class="ac-strong"><?= ac_h((string) $h['name']) ?></div>
                      <div class="ac-sub"><?= ac_h(ac_join([(string) ($h['enrollment_ref'] ?? ''), (string) $h['email']])) ?></div></td>
                    <td><?= !empty($p['ok']) ? ($p['will'] === 'waitlist' ? ac_pill('Waitlist', 'warn') : ac_pill('Seat free', 'good')) : ac_pill('Refused', 'bad') ?>
                      <div class="ac-sub"><?= ac_h((string) ($p['words'] ?? '')) ?></div></td>
                    <td class="ac-num">
                      <?php if (!empty($p['ok'])): ?>
                        <?php ac_action_btn('cohort_enrol', 'roster', $back + ['cohort_id' => $sel, 'student_id' => (int) $h['id']],
                            $p['will'] === 'waitlist' ? 'Add to waitlist' : 'Enrol', 'ac-btn ac-btn--sm ac-btn--primary'); ?>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      <?php ac_card_close('Enrolment normally happens on payment settlement; doing it here is the exception path and carries your name in the audit log. The duplicate guard is level-aware: a student already in this course at this level (or with no level) is refused and pointed at their existing class — move them instead. A full cohort queues the student rather than over-filling it. The cohort\'s instructor is attached through the safeguarding gate; if the gate refuses, the student is still enrolled and nobody is attached.'); ?>

      <?php /* ── Waitlist ────────────────────────────────────────────── */ ?>
      <?php ac_card_open('Waitlist', $waiting . ' waiting',
          $waiting > 0 && $isOpen ? (function () use ($back, $sel, $c) { ob_start();
              ac_action_btn('waitlist_promote', 'roster', $back + ['cohort_id' => $sel], 'Promote next',
                  'ac-btn ac-btn--sm' . (empty($c['is_full']) ? ' ac-btn--primary' : ''));
              return (string) ob_get_clean(); })() : ''); ?>
        <div class="ac-tablewrap">
          <table class="ac-table">
            <thead><tr><th>#</th><th>Student</th><th>Since</th><th class="ac-num"></th></tr></thead>
            <tbody>
              <?php if (!$waiting): ?>
                <?php ac_table_empty(4, 'Nobody is waiting.'); ?>
              <?php else: foreach ($d['waitlist'] as $w): ?>
                <tr>
                  <td class="ac-mono"><?= (int) $w['position'] ?></td>
                  <td><div class="ac-strong"><?= ac_h((string) $w['name']) ?></div><div class="ac-sub"><?= ac_h((string) $w['email']) ?></div></td>
                  <td><?= ac_h(mam_console_date((string) $w['created_at'])) ?></td>
                  <td class="ac-num"><?php ac_action_btn('cohort_waitlist_remove', 'roster', $back + ['cohort_id' => $sel, 'student_id' => (int) $w['student_id']],
                      'Remove', 'ac-btn ac-btn--sm', 'Take this student off the waitlist?'); ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      <?php ac_card_close('Promotion takes the first in line and tells them. It refuses while the cohort is full; withdraw or move somebody first, and with automatic promotion on, a freed seat is offered to the queue by itself.'); ?>

      <?php /* ── Staff ───────────────────────────────────────────────── */ ?>
      <?php ac_card_open('Staff', count($d['staff'] ?? []) . ' attached'); ?>
        <div class="ac-tablewrap">
          <table class="ac-table">
            <thead><tr><th>Person</th><th>Clearance</th><th></th></tr></thead>
            <tbody>
              <?php if (!($d['staff'] ?? [])): ?>
                <?php ac_table_empty(3, 'Nobody is attached. Assign an instructor below — an unassigned roster is one no instructor can open.'); ?>
              <?php else: foreach ($d['staff'] as $s):
                $g = $s['gate'] ?? null; ?>
                <tr>
                  <td><div class="ac-strong"><?= ac_h((string) $s['name']) ?></div>
                      <div class="ac-sub"><?= ac_h((string) $s['email']) ?><?= (int) $s['id'] === (int) ($c['instructor_id'] ?? 0) ? ' · teaches the cohort' : ' · on individual enrolments' ?></div></td>
                  <td>
                    <?php if (!is_array($g)): ?><span class="ac-sub">Unknown</span>
                    <?php elseif (!empty($g['ok'])): ?><?= ac_pill('Cleared', 'good') ?>
                    <?php else: ?><?= ac_pill('Not cleared', 'bad') ?>
                      <div class="ac-sub"><?= ac_h(mam_console_try('ros.exp', fn() => mam_vetting_explain($g), '') ?: '') ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="ac-num"><a class="ac-btn ac-btn--sm" href="<?= ac_h(ac_url('vetting')) ?>">Record a check</a></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <div class="ac-cardbody">
          <?php $pv = $d['instr_preview'] ?? null; ?>
          <form method="get" action="/admin/console.php">
            <input type="hidden" name="v" value="roster">
            <input type="hidden" name="cohort" value="<?= $sel ?>">
            <div class="ac-inline">
              <label class="ac-field"><span class="ac-label">Change the instructor</span>
                <select class="ac-select" name="instr" required>
                  <option value="">— pick somebody —</option>
                  <?php foreach ($d['instructors'] ?? [] as $i):
                    $on = $pv ? (int) $pv['id'] === (int) $i['id'] : false; ?>
                    <option value="<?= (int) $i['id'] ?>"<?= $on ? ' selected' : '' ?>><?= ac_h((string) $i['name']) ?><?= (int) ($c['instructor_id'] ?? 0) === (int) $i['id'] ? ' (current)' : '' ?></option>
                  <?php endforeach; ?>
                </select></label>
              <button type="submit" class="ac-btn">Check the gate</button>
            </div>
          </form>
          <?php if ($pv): $tone = ['clear' => 'good', 'not_required' => 'good', 'off' => 'warn', 'warn' => 'warn', 'refuse' => 'bad'][$pv['verdict']] ?? 'warn'; ?>
            <div class="ac-mt">
              <?php ac_banner($tone, $pv['allow'] ? 'fa-shield-halved' : 'fa-ban',
                '<strong>' . ac_h((string) $pv['name']) . ':</strong> ' . ac_h((string) $pv['words'])); ?>
            </div>
            <?php if (!empty($pv['allow'])): ?>
              <?php ac_form('cohort_instructor_set', 'roster', $back + ['cohort_id' => $sel, 'instructor_id' => (int) $pv['id']], 'class="ac-row ac-mt"'); ?>
                <button type="submit" class="ac-btn ac-btn--primary">Make <?= ac_h((string) $pv['name']) ?> the instructor</button>
                <a class="ac-btn" href="<?= ac_h(ac_url('roster', ['cohort' => $sel])) ?>">Cancel</a>
              <?php ac_form_end(); ?>
            <?php else: ?>
              <div class="ac-row ac-mt"><a class="ac-btn ac-btn--primary" href="<?= ac_h(ac_url('vetting')) ?>">Record the missing checks</a>
                <a class="ac-btn" href="<?= ac_h(ac_url('roster', ['cohort' => $sel])) ?>">Cancel</a></div>
            <?php endif; ?>
          <?php elseif ((int) ($c['instructor_id'] ?? 0) > 0): ?>
            <div class="ac-row ac-mt">
              <?php ac_action_btn('cohort_instructor_set', 'roster', $back + ['cohort_id' => $sel, 'instructor_id' => 0],
                  'Remove the instructor', 'ac-btn ac-btn--sm', 'Nobody will be able to open this roster until somebody is assigned. Remove the instructor?'); ?>
            </div>
          <?php endif; ?>
        </div>
      <?php ac_card_close(
        'The safeguarding gate is in <strong>' . ac_h($mode) . '</strong> mode. '
        . ($mode === 'enforce'
            ? 'An uncleared instructor is refused on any class containing a child.'
            : 'Every gap is recorded and the assignment still goes through — the right setting while the '
              . 'records held on paper are being loaded, and the wrong one to stay in.')
        . ' The verdict is checked again at the moment you save. Students whose enrolment carried the old instructor, or none, follow the cohort; a deliberate one-off stays.'); ?>

      <?php /* ── Upcoming sessions ───────────────────────────────────── */ ?>
      <?php ac_card_open('Coming up', count($d['upcoming'] ?? []) . ' scheduled',
          '<a class="ac-btn ac-btn--sm" href="' . ac_h(ac_url('timetable', ['cohort' => $sel])) . '">Open in the timetable</a>'); ?>
        <div class="ac-tablewrap">
          <table class="ac-table">
            <thead><tr><th>When</th><th>Session</th><th>Where</th></tr></thead>
            <tbody>
              <?php if (!($d['upcoming'] ?? [])): ?>
                <?php ac_table_empty(3, 'Nothing scheduled. Sessions are planned in the timetable.'); ?>
              <?php else: foreach ($d['upcoming'] as $u): ?>
                <tr>
                  <td class="ac-mono"><?= ac_h(mam_console_date((string) $u['scheduled_at'], 'D j M · H:i')) ?></td>
                  <td><div class="ac-strong"><?= ac_h((string) ($u['title'] ?: 'Class')) ?></div>
                    <div class="ac-sub"><?= (int) ($u['duration_min'] ?? 60) ?> min</div></td>
                  <td><?= ac_h(ac_join([(string) ($u['room'] ?? ''), ucfirst((string) ($u['mode'] ?? ''))]) ?: '—') ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      <?php ac_card_close(); ?>

      <?php /* ── Capacity, and closing the cohort ────────────────────── */ ?>
      <?php ac_card_open('Capacity and closing'); ?>
        <div class="ac-cardbody">
          <?php ac_form('cohort_edit', 'roster', $back + ['cohort_id' => $sel, 'title' => (string) $c['title'],
              'instructor_id' => (int) ($c['instructor_id'] ?? 0) ?: '']); ?>
            <div class="ac-inline">
              <label class="ac-field ac-w-sm"><span class="ac-label">Capacity</span>
                <input class="ac-input" type="number" name="capacity" min="1" max="500"
                       value="<?= $cap === null ? '' : $cap ?>" placeholder="uncapped"></label>
              <button type="submit" class="ac-btn">Save capacity</button>
            </div>
          <?php ac_form_end(); ?>
          <div class="ac-hint">Lowering capacity below the seats taken removes nobody; it only stops new enrolments until the cohort drains.</div>
          <div class="ac-row ac-mt">
            <?php if ($isOpen): ?>
              <?php if ($fill > 0 || $waiting > 0): ?>
                <span class="ac-sub">To close this cohort, move or withdraw its <?= $fill ?> student<?= $fill === 1 ? '' : 's' ?><?= $waiting ? ' and clear its waitlist' : '' ?> first.</span>
              <?php endif; ?>
              <?php ac_action_btn('cohort_archive', 'roster', $back + ['cohort_id' => $sel], 'Close cohort', 'ac-btn ac-btn--sm',
                  'Close this cohort? It keeps its history and can be reopened.'); ?>
            <?php else: ?>
              <?php ac_action_btn('cohort_reopen', 'roster', $back + ['cohort_id' => $sel], 'Reopen cohort', 'ac-btn ac-btn--sm ac-btn--primary'); ?>
            <?php endif; ?>
          </div>
        </div>
      <?php ac_card_close('Closing is refused while anybody is enrolled or waiting: a closed class with children in it is a roster nobody teaches.'); ?>
    <?php endif; ?>

    <?php ac_card_open('New cohort'); ?>
      <div class="ac-cardbody">
        <?php ac_form('cohort_create', 'roster'); ?>
          <div class="ac-grid ac-grid--2">
            <label class="ac-field"><span class="ac-label">Title</span>
              <input class="ac-input" name="title" required placeholder="Piano · Level 1 · Saturday"></label>
            <label class="ac-field"><span class="ac-label">Term</span>
              <input class="ac-input" name="term" value="<?= ac_h(trim((string) ($d['term']['label'] ?? '') . ' ' . (string) ($d['term']['year'] ?? ''))) ?>"></label>
            <label class="ac-field"><span class="ac-label">Course id</span>
              <input class="ac-input" type="number" name="course_id" min="1" required></label>
            <label class="ac-field"><span class="ac-label">Capacity</span>
              <input class="ac-input" type="number" name="capacity" min="1" max="500" value="12"></label>
            <label class="ac-field"><span class="ac-label">Instructor</span>
              <select class="ac-select" name="instructor_id">
                <option value="">— assign later —</option>
                <?php foreach ($d['instructors'] ?? [] as $i): ?>
                  <option value="<?= (int) $i['id'] ?>"><?= ac_h((string) $i['name']) ?></option>
                <?php endforeach; ?>
              </select></label>
            <label class="ac-field"><span class="ac-label">Starts on</span>
              <input class="ac-input" type="date" name="starts_on"></label>
          </div>
          <div class="ac-row ac-mt"><button type="submit" class="ac-btn ac-btn--primary">Create</button>
            <span class="ac-hint">Capacity 1 makes it a private class. Set its weekly time in the timetable.</span></div>
        <?php ac_form_end(); ?>
      </div>
    <?php ac_card_close(); ?>
  </div>
</div>

<?php /* ── Legacy classes ─────────────────────────────────────────────────
   The older class model (classes + enrollments) that the student dashboard's
   classes and attendance still read. Every form posts the ORIGINAL handler. */ ?>
<?php ac_card_open('Legacy classes', count($d['classes'] ?? []) . ' classes · the older class model',
    (function () { ob_start();
        ac_action_btn('bridge_legacy_enrollments', 'roster', [], 'Bridge all into courses', 'ac-btn ac-btn--sm',
            'Enrol every active legacy class student into the matching course? Already-enrolled students are skipped.');
        return (string) ob_get_clean(); })()); ?>
  <div class="ac-tablewrap">
    <table class="ac-table">
      <thead><tr><th>Class</th><th>Status</th><th>Enrolled</th><th>Cohort</th><th class="ac-num"></th></tr></thead>
      <tbody>
        <?php if (!($d['classes'] ?? [])): ?>
          <?php ac_table_empty(5, 'No legacy classes.'); ?>
        <?php else: foreach ($d['classes'] as $cl): ?>
          <tr>
            <td><a class="ac-strong" href="<?= ac_h(ac_url('roster', ['cohort' => $sel ?: null, 'class' => (int) $cl['id']])) ?>#legacy"><?= ac_h((string) $cl['title']) ?></a>
              <div class="ac-sub"><?= ac_h(ac_join([(string) $cl['discipline'], (string) ($cl['schedule'] ?? ''), (string) ($cl['instructor'] ?? '') ?: 'unassigned'])) ?></div></td>
            <td><?= ac_status((string) $cl['status']) ?></td>
            <td><?= (int) $cl['enrolled'] ?><?= $cl['capacity'] ? ' / ' . (int) $cl['capacity'] : '' ?></td>
            <td><?php if ($cl['cohort_id']): ?><a href="<?= ac_h(ac_url('roster', ['cohort' => (int) $cl['cohort_id']])) ?>">Backfilled</a>
              <?php else: ?><span class="ac-sub">None</span><?php endif; ?></td>
            <td class="ac-num">
              <?php if ((string) $cl['status'] === 'active'): ?>
                <?php ac_action_btn('class_status', 'roster', $back + ['_class' => (int) $cl['id'], 'class_id' => (int) $cl['id'], 'status' => 'paused'], 'Pause'); ?>
              <?php elseif ((string) $cl['status'] !== 'active'): ?>
                <?php ac_action_btn('class_status', 'roster', $back + ['_class' => (int) $cl['id'], 'class_id' => (int) $cl['id'], 'status' => 'active'], 'Reactivate'); ?>
              <?php endif; ?>
              <?php if ((string) $cl['status'] !== 'archived'): ?>
                <?php ac_action_btn('class_delete', 'roster', $back + ['class_id' => (int) $cl['id']], 'Archive', 'ac-btn ac-btn--sm',
                    'Archive this class? Its enrolments and attendance stay on record.'); ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
<?php ac_card_close('“Bridge all into courses” enrols each active legacy student into the course named by the class\'s discipline; a discipline that is not a course is skipped and logged. Archiving keeps the record — nothing here deletes a class.'); ?>

<div class="ac-cols ac-mt" id="legacy">
  <?php if ($cls): ?>
    <div class="ac-stack">
      <?php ac_card_open((string) $cls['title'], 'legacy class · ' . (int) $cls['enrolled'] . ' active', ac_status((string) $cls['status'])); ?>
        <div class="ac-tablewrap">
          <table class="ac-table">
            <thead><tr><th>Student</th><th>Status</th><th class="ac-num">Change</th></tr></thead>
            <tbody>
              <?php if (!($d['class_roster'] ?? [])): ?>
                <?php ac_table_empty(3, 'Nobody enrolled in this class.'); ?>
              <?php else: foreach ($d['class_roster'] as $e): ?>
                <tr>
                  <td><div class="ac-strong"><?= ac_h((string) $e['name']) ?></div><div class="ac-sub"><?= ac_h((string) $e['email']) ?></div></td>
                  <td><?= ac_status((string) $e['status']) ?></td>
                  <td class="ac-num">
                    <?php ac_form('enrollment_status', 'roster', $back + ['_class' => $clsSel, 'enrollment_id' => (int) $e['id']], 'class="ac-row"'); ?>
                      <label class="ac-sr" for="es<?= (int) $e['id'] ?>">Status for <?= ac_h((string) $e['name']) ?></label>
                      <select class="ac-select" id="es<?= (int) $e['id'] ?>" name="status">
                        <?php foreach (['active', 'paused', 'completed', 'withdrawn'] as $st): ?>
                          <option value="<?= $st ?>"<?= (string) $e['status'] === $st ? ' selected' : '' ?>><?= ucfirst($st) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button type="submit" class="ac-btn ac-btn--sm">Set</button>
                    <?php ac_form_end(); ?>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <div class="ac-cardbody">
          <?php ac_form('enroll', 'roster', $back + ['_class' => $clsSel, 'class_id' => $clsSel]); ?>
            <div class="ac-inline">
              <label class="ac-field"><span class="ac-label">Enrol into this class</span>
                <select class="ac-select" name="student_id" required>
                  <option value="">— pick a student —</option>
                  <?php foreach ($d['candidates'] ?? [] as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"><?= ac_h((string) $s['name']) ?> · <?= ac_h((string) $s['email']) ?></option>
                  <?php endforeach; ?>
                </select></label>
              <button type="submit" class="ac-btn ac-btn--primary">Enrol</button>
            </div>
          <?php ac_form_end(); ?>
          <div class="ac-hint">This writes the legacy record only. <?= $cls['cohort_id'] ? 'This class is backfilled into a cohort — enrol there instead so the student can be marked, or use “Bring across” afterwards.' : 'For a student who should be gradable, enrol them into a cohort above.' ?></div>
        </div>
      <?php ac_card_close(); ?>
    </div>
    <div class="ac-stack">
      <?php ac_card_open('Edit class'); ?>
        <div class="ac-cardbody">
          <?php ac_form('class_edit', 'roster', $back + ['_class' => $clsSel, 'class_id' => $clsSel]); ?>
            <div class="ac-grid ac-grid--2">
              <label class="ac-field"><span class="ac-label">Title</span><input class="ac-input" name="title" required value="<?= ac_h((string) $cls['title']) ?>"></label>
              <label class="ac-field"><span class="ac-label">Discipline</span><input class="ac-input" name="discipline" required value="<?= ac_h((string) $cls['discipline']) ?>"></label>
              <label class="ac-field"><span class="ac-label">Instructor</span>
                <select class="ac-select" name="instructor_id">
                  <option value="">— nobody —</option>
                  <?php foreach ($d['instructors'] ?? [] as $i): ?>
                    <option value="<?= (int) $i['id'] ?>"<?= (int) ($cls['instructor_id'] ?? 0) === (int) $i['id'] ? ' selected' : '' ?>><?= ac_h((string) $i['name']) ?></option>
                  <?php endforeach; ?>
                </select></label>
              <label class="ac-field"><span class="ac-label">Capacity</span><input class="ac-input" type="number" name="capacity" min="1" value="<?= (int) $cls['capacity'] ?>"></label>
              <label class="ac-field"><span class="ac-label">Schedule</span><input class="ac-input" name="schedule" value="<?= ac_h((string) ($cls['schedule'] ?? '')) ?>"></label>
              <label class="ac-field"><span class="ac-label">Mode</span><input class="ac-input" name="mode" value="<?= ac_h((string) ($cls['mode'] ?? '')) ?>" placeholder="onsite / online"></label>
              <label class="ac-field"><span class="ac-label">Starts on</span><input class="ac-input" type="date" name="starts_on" value="<?= ac_h((string) ($cls['starts_on'] ?? '')) ?>"></label>
              <label class="ac-field"><span class="ac-label">Meeting link</span><input class="ac-input" type="url" name="meeting_link" value="<?= ac_h((string) ($cls['meeting_link'] ?? '')) ?>" placeholder="https://…"></label>
            </div>
            <div class="ac-row ac-mt"><button type="submit" class="ac-btn ac-btn--primary">Save class</button>
              <a class="ac-btn" href="<?= ac_h(ac_url('roster', ['cohort' => $sel ?: null])) ?>">Close</a></div>
          <?php ac_form_end(); ?>
          <div class="ac-hint">The instructor here is the legacy class's only. Whoever teaches a cohort is set through the safeguarding gate above.</div>
        </div>
      <?php ac_card_close(); ?>
    </div>
  <?php else: ?>
    <?php ac_card_open('New legacy class'); ?>
      <div class="ac-cardbody">
        <?php ac_form('class_create', 'roster', $back); ?>
          <div class="ac-grid ac-grid--3">
            <label class="ac-field"><span class="ac-label">Title</span><input class="ac-input" name="title" required></label>
            <label class="ac-field"><span class="ac-label">Discipline</span><input class="ac-input" name="discipline" required placeholder="piano"></label>
            <label class="ac-field"><span class="ac-label">Capacity</span><input class="ac-input" type="number" name="capacity" min="1" value="12"></label>
            <label class="ac-field"><span class="ac-label">Schedule</span><input class="ac-input" name="schedule" placeholder="Sat 10:00"></label>
            <label class="ac-field"><span class="ac-label">Starts on</span><input class="ac-input" type="date" name="starts_on"></label>
            <label class="ac-field"><span class="ac-label">Instructor</span>
              <select class="ac-select" name="instructor_id">
                <option value="">— assign later —</option>
                <?php foreach ($d['instructors'] ?? [] as $i): ?>
                  <option value="<?= (int) $i['id'] ?>"><?= ac_h((string) $i['name']) ?></option>
                <?php endforeach; ?>
              </select></label>
          </div>
          <div class="ac-row ac-mt"><button type="submit" class="ac-btn">Create legacy class</button>
            <span class="ac-hint">New teaching belongs in a cohort; this exists for the classes the older dashboards still read. Open a class above to see its roster and edit it.</span></div>
        <?php ac_form_end(); ?>
      </div>
    <?php ac_card_close(); ?>
  <?php endif; ?>
</div>
