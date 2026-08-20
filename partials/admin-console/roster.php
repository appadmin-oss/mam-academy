<?php
/** Enrol & staffing — pick a cohort, see its staff and its roster, act. */
declare(strict_types=1);
/** @var array $d */
$c = $d['cohort'] ?? null;
$mode = (string) ($d['vetting_mode'] ?? 'warn');
?>
<?php ac_head('Enrol & staffing',
    ac_h((string) ($d['term']['line'] ?? '')) . ' · a private class is a cohort with capacity 1',
    '<a class="ac-btn" href="' . ac_h(ac_url('cohorts')) . '">All cohorts</a>'); ?>

<div class="ac-cols ac-cols--rail">
  <?php ac_card_open('Cohorts', count($d['cohorts'] ?? []) . ''); ?>
    <div class="ac-queue">
      <?php foreach ($d['cohorts'] ?? [] as $r): ?>
        <a class="ac-qitem<?= (int) $r['id'] === (int) ($d['selected'] ?? 0) ? ' is-on' : '' ?>"
           href="<?= ac_h(ac_url('roster', ['cohort' => (int) $r['id']])) ?>">
          <div>
            <div class="ac-qtitle"><?= ac_h((string) $r['title']) ?></div>
            <div class="ac-qwhy"><?= ac_h(trim(implode(' · ', array_filter([
                (string) ($r['course_code'] ?? ''), (string) ($r['schedule_days'] ?? ''),
                (string) ($r['schedule_time'] ?? ''), (string) ($r['instructor'] ?? '') ?: 'unassigned'])))) ?></div>
          </div>
          <span class="ac-qage">
            <?= (int) $r['fill'] ?><?= $r['capacity'] === null ? '' : ' / ' . (int) $r['capacity'] ?>
          </span>
        </a>
      <?php endforeach; ?>
      <?php if (!($d['cohorts'] ?? [])): ?>
        <?php ac_empty('No cohorts', 'Create one below and it appears here.'); ?>
      <?php endif; ?>
    </div>
  <?php ac_card_close('A private class is a cohort with capacity 1 — same registers, same credits, same report.'); ?>

  <div class="ac-stack">
    <?php if (!$c): ?>
      <?php ac_card_open('Nothing selected'); ?>
        <div class="ac-cardbody"><?php ac_empty('Pick a cohort', 'Its staff, its roster and its sessions appear here.'); ?></div>
      <?php ac_card_close(); ?>
    <?php else: ?>
      <?php ac_card_open((string) $c['title'], (string) ($c['term'] ?? ''),
          !empty($c['is_full']) ? ac_pill('Full', 'bad') : ac_status((string) $c['status'])); ?>
        <div class="ac-cardbody">
          <div class="ac-grid ac-grid--3">
            <div><div class="ac-kpi-l">Seats</div><div class="ac-strong ac-mt-xs">
              <?= (int) $c['fill'] ?><?= $c['capacity'] === null ? ' · uncapped' : ' of ' . (int) $c['capacity'] ?></div></div>
            <div><div class="ac-kpi-l">Meets</div><div class="ac-strong ac-mt-xs">
              <?= ac_h(trim((string) ($c['schedule_days'] ?? '') . ' ' . (string) ($c['schedule_time'] ?? '')) ?: '—') ?></div></div>
            <div><div class="ac-kpi-l">Sessions</div><div class="ac-strong ac-mt-xs">
              <?= count($d['sessions'] ?? []) ?> recorded</div></div>
          </div>
        </div>
      <?php ac_card_close(); ?>

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
                      <div class="ac-sub"><?= ac_h((string) $s['email']) ?></div></td>
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
      <?php ac_card_close(
        'The safeguarding gate is in <strong>' . ac_h($mode) . '</strong> mode. '
        . ($mode === 'enforce'
            ? 'An uncleared instructor is refused on any class containing a child.'
            : 'Every gap is recorded and the assignment still goes through — which is the right setting while the '
              . 'records the academy holds on paper are being loaded, and the wrong one to stay in.')
        . ' An unclear result never fails an enrolment: the student is enrolled and the instructor simply not attached, '
        . 'because "enrolled, awaiting a cleared teacher" is actionable and a refused placement is not.'); ?>

      <?php ac_card_open('Assign an instructor'); ?>
        <div class="ac-cardbody">
          <?php ac_form('cohort_edit', 'roster', ['cohort_id' => (int) $c['id'], 'title' => (string) $c['title']]); ?>
            <div class="ac-inline">
              <label class="ac-field"><span class="ac-label">Instructor</span>
                <select class="ac-select" name="instructor_id">
                  <option value="">— nobody —</option>
                  <?php foreach ($d['instructors'] ?? [] as $i): ?>
                    <option value="<?= (int) $i['id'] ?>" <?= (int) ($c['instructor_id'] ?? 0) === (int) $i['id'] ? 'selected' : '' ?>>
                      <?= ac_h((string) $i['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="ac-field ac-w-xs"><span class="ac-label">Capacity</span>
                <input class="ac-input" type="number" name="capacity" min="0"
                       value="<?= $c['capacity'] === null ? '' : (int) $c['capacity'] ?>" placeholder="blank = uncapped"></label>
              <button type="submit" class="ac-btn ac-btn--primary">Save</button>
            </div>
          <?php ac_form_end(); ?>
          <div class="ac-hint">A blank capacity is uncapped. A literal 0 would read as permanently full.</div>
        </div>
      <?php ac_card_close(); ?>

      <?php ac_card_open('Enrolled', count($d['roster'] ?? []) . ' student' . (count($d['roster'] ?? []) === 1 ? '' : 's')); ?>
        <div class="ac-tablewrap">
          <table class="ac-table">
            <thead><tr><th>Student</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <?php if (!($d['roster'] ?? [])): ?>
                <?php ac_table_empty(3, 'Nobody enrolled yet.'); ?>
              <?php else: foreach ($d['roster'] as $s): ?>
                <tr>
                  <td><div class="ac-row"><?= ac_avatar((string) $s['name'], (string) ($s['avatar_url'] ?? '')) ?>
                    <div class="ac-grow"><div class="ac-strong"><?= ac_h((string) $s['name']) ?></div>
                    <div class="ac-sub"><?= ac_h((string) $s['email']) ?></div></div></div></td>
                  <td><?= ac_status((string) $s['status']) ?></td>
                  <td class="ac-num"><a class="ac-btn ac-btn--sm" href="/admin/?v=users&amp;edit=<?= (int) $s['student_id'] ?>">Record</a></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <div class="ac-cardbody">
          <?php ac_form('student_course_assign', 'roster', ['instructor_id' => (int) ($c['instructor_id'] ?? 0)]); ?>
            <div class="ac-inline">
              <label class="ac-field"><span class="ac-label">Enrol a student</span>
                <select class="ac-select" name="user_id" required>
                  <option value="">— pick —</option>
                  <?php foreach ($d['candidates'] ?? [] as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"><?= ac_h((string) $s['name']) ?> · <?= ac_h((string) $s['email']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="ac-field ac-w-sm"><span class="ac-label">Course code</span>
                <input class="ac-input" name="course_code" value="<?= ac_h((string) ($c['course_code'] ?? '')) ?>" required></label>
              <button type="submit" class="ac-btn ac-btn--primary">Enrol</button>
            </div>
          <?php ac_form_end(); ?>
        </div>
      <?php ac_card_close('Enrolment normally happens on payment settlement. Doing it here is the exception path and carries your name in the audit log. The duplicate guard is level-aware in both directions, so a level-less add is refused where a levelled enrolment already exists — and distinct levels of one course stay legal, because that is progression.'); ?>
    <?php endif; ?>

    <?php ac_card_open('New cohort'); ?>
      <div class="ac-cardbody">
        <?php ac_form('cohort_create', 'roster'); ?>
          <div class="ac-grid ac-grid--2">
            <label class="ac-field"><span class="ac-label">Title</span>
              <input class="ac-input" name="title" required placeholder="Piano · Level 1"></label>
            <label class="ac-field"><span class="ac-label">Term</span>
              <input class="ac-input" name="term" value="<?= ac_h(trim((string) ($d['term']['label'] ?? '') . ' ' . (string) ($d['term']['year'] ?? ''))) ?>"></label>
            <label class="ac-field"><span class="ac-label">Course id</span>
              <input class="ac-input" type="number" name="course_id" min="1" required></label>
            <label class="ac-field"><span class="ac-label">Capacity</span>
              <input class="ac-input" type="number" name="capacity" min="0" placeholder="blank = uncapped"></label>
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
            <span class="ac-hint">Capacity 1 makes it a private class.</span></div>
        <?php ac_form_end(); ?>
      </div>
    <?php ac_card_close(); ?>
  </div>
</div>
