<?php
/** Curriculum — programme → course at a level → modules. Not the schedule, not mastery. */
declare(strict_types=1);
/** @var array $d */
// A lesson open in the editor takes the whole screen (partials/admin-console/_lesson-editor.php).
if (($d['editing'] ?? null) !== null) { require __DIR__ . '/_lesson-editor.php'; return; }
$uncovered = array_values(array_filter($d['coverage'] ?? [], fn($c) => empty($c['evidenced'])));
$uncoveredWeight = array_sum(array_map(fn($c) => (float) $c['weight'], $uncovered));
?>
<?php ac_head('Lessons',
    'Programme → course at a level → lessons · curriculum is not the schedule and not mastery',
    (int) ($d['course'] ?? 0) > 0 ? '<a class="ac-btn ac-btn--primary" href="' . ac_h(ac_url('curriculum', ['program' => (int) ($d['program'] ?? 0), 'course' => (int) $d['course'], 'lesson' => 'new'])) . '">New lesson</a>' : ''); ?>

<?php if ((int) ($d['waiting'] ?? 0) > 0): ?>
  <?php ac_banner('warn', 'fa-pen-to-square', '<strong>' . (int) $d['waiting'] . ' lesson attempt' . ((int) $d['waiting'] === 1 ? ' is' : 's are')
    . ' waiting for a teacher to mark the written answers.</strong> Teachers mark them on the Marking screen of their portal; until then the student reads "with your teacher".'); ?>
<?php endif; ?>

<?php if ($uncovered && $uncoveredWeight > 0): ?>
  <?php ac_banner('warn', 'fa-triangle-exclamation',
    '<strong>' . count($uncovered) . ' competenc' . (count($uncovered) === 1 ? 'y' : 'ies')
    . ' carry ' . rtrim(rtrim(number_format($uncoveredWeight, 1), '0'), '.') . '% of the mastery weight and nothing in '
    . 'this course evidences ' . (count($uncovered) === 1 ? 'it' : 'them') . '.</strong> Every student\'s band will read '
    . '"not enough evidence yet" on those rows until a rubric criterion maps to them — which is honest, but it is a '
    . 'curriculum gap, not a reporting one.'); ?>
<?php endif; ?>

<div class="ac-cols ac-cols--2">
  <?php ac_card_open('Programme'); ?>
    <div class="ac-queue">
      <?php foreach ($d['programs'] ?? [] as $p): ?>
        <a class="ac-qitem<?= (int) $p['id'] === (int) ($d['program'] ?? 0) ? ' is-on' : '' ?>"
           href="<?= ac_h(ac_url('curriculum', ['program' => (int) $p['id']])) ?>">
          <div><div class="ac-qtitle"><?= ac_h((string) $p['name']) ?></div>
            <div class="ac-qwhy"><?= (int) $p['courses'] ?> course<?= (int) $p['courses'] === 1 ? '' : 's' ?>
              <?= $p['weeks'] ? ' · ' . (int) $p['weeks'] . ' weeks' : '' ?></div></div>
          <span class="ac-qage"><?= ac_h((string) $p['code']) ?></span>
        </a>
      <?php endforeach; ?>
      <?php if (!($d['programs'] ?? [])): ?><?php ac_empty('No programmes', 'There is no programme to put courses in yet.'); ?><?php endif; ?>
    </div>
  <?php ac_card_close(); ?>

  <?php ac_card_open('Course at level'); ?>
    <div class="ac-queue">
      <?php foreach ($d['courses'] ?? [] as $c): ?>
        <a class="ac-qitem<?= (int) $c['id'] === (int) ($d['course'] ?? 0) ? ' is-on' : '' ?>"
           href="<?= ac_h(ac_url('curriculum', ['program' => (int) ($d['program'] ?? 0), 'course' => (int) $c['id']])) ?>">
          <div><div class="ac-qtitle"><?= ac_h((string) $c['code']) ?> · <?= ac_h((string) $c['name']) ?>
              <?= (string) ($c['kind'] ?? '') !== '' ? ac_tag((string) $c['kind']) : '' ?></div>
            <div class="ac-qwhy"><?= ac_h((string) ($c['level_code'] ?: 'no level set')) ?>
              · <?= (int) $c['modules'] ?> module<?= (int) $c['modules'] === 1 ? '' : 's' ?></div></div>
        </a>
      <?php endforeach; ?>
      <?php if (!($d['courses'] ?? [])): ?><?php ac_empty('No courses linked', 'Link a course to this programme first.'); ?><?php endif; ?>
    </div>
  <?php ac_card_close(); ?>

</div>

<?php ac_card_open('Lessons', count($d['modules'] ?? []) . ' in this course', '<span class="ac-cardmeta">Week is the planned position, not a date</span>'); ?>
    <?php ac_form('lesson_status', 'curriculum', ['course' => (string) (int) ($d['course'] ?? 0)]); ?>
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead><tr><th><span class="ac-sr">Select</span></th><th>Wk</th><th>Lesson</th><th>State</th><th class="ac-num">Questions</th><th class="ac-num">Done / started</th></tr></thead>
        <tbody>
          <?php if (!($d['modules'] ?? [])): ?>
            <?php ac_table_empty(6, 'No lessons for this course yet. Start one with New lesson.'); ?>
          <?php else: foreach ($d['modules'] as $m): $st = (string) ($m['status'] ?? 'published'); ?>
            <tr>
              <td><input type="checkbox" name="ids[]" value="<?= (int) $m['id'] ?>" aria-label="Select week <?= (int) $m['week'] ?>, <?= ac_h((string) $m['title']) ?>"></td>
              <td class="ac-num ac-mono">W<?= (int) $m['week'] ?></td>
              <td><a href="<?= ac_h(ac_url('curriculum', ['program' => (int) ($d['program'] ?? 0), 'course' => (int) ($d['course'] ?? 0), 'lesson' => (int) $m['id']])) ?>"><?= ac_h((string) $m['title']) ?></a>
                <div class="ac-sub"><?= ac_h(trim(((string) ($m['level_code'] ?? '') ?: 'every level') . ' · ' . ((string) ($m['kind'] ?? 'module') === 'module' ? 'lesson' : (string) $m['kind']))) ?></div></td>
              <td><?= ac_pill(['published' => 'Published', 'draft' => 'Draft', 'retired' => 'Retired'][$st] ?? $st, $st === 'published' ? 'good' : ($st === 'draft' ? 'warn' : '')) ?>
                <?php if ($st === 'published' && (int) $m['questions'] === 0): ?><div class="ac-sub">no questions — the teacher ticks it off</div><?php endif; ?></td>
              <td class="ac-num"><?= (int) $m['questions'] ?></td>
              <td class="ac-num"><?= (int) $m['done'] ?> / <?= (int) $m['started'] ?></td>
            </tr>
          <?php endforeach; endif; ?>
          <?php if ($d['gaps'] ?? []): ?>
            <tr><td colspan="6"><div class="ac-note">Week<?= count($d['gaps']) === 1 ? '' : 's' ?>
              <strong><?= ac_h(implode(', ', array_slice($d['gaps'], 0, 14))) ?><?= count($d['gaps']) > 14 ? '…' : '' ?></strong>
              have no lesson yet.</div></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($d['modules'] ?? []): ?>
      <div class="ac-row ac-bulkbar">
        <label class="ac-field"><span class="ac-label">With the ticked lessons</span>
          <select class="ac-select" name="status" required>
            <option value="">Choose…</option>
            <option value="published">Publish</option>
            <option value="draft">Move back to draft</option>
            <option value="retired">Retire</option>
          </select></label>
        <button type="submit" class="ac-btn">Apply</button>
      </div>
    <?php endif; ?>
    <?php ac_form_end(); ?>
  <?php ac_card_close('A lesson somebody has started is retired, never deleted — their progress is the record of what they did. Publishing needs questions, at least one of them marked by a teacher.'); ?>


<div class="ac-cols ac-cols--2">
  <?php ac_card_open('Coverage check', count($uncovered) . ' unevidenced',
      '<span class="ac-cardmeta">Which competency does this course actually evidence?</span>'); ?>
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead><tr><th>Competency</th><th class="ac-num">Weight</th><th>Evidenced here</th></tr></thead>
        <tbody>
          <?php foreach ($d['coverage'] ?? [] as $c): ?>
            <tr>
              <td><?= ac_h((string) $c['label']) ?></td>
              <td class="ac-num"><?= rtrim(rtrim(number_format((float) $c['weight'], 1), '0'), '.') ?>%</td>
              <td><?= !empty($c['evidenced']) ? ac_pill('Yes', 'good') : ac_pill('Never', 'warn') ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!($d['coverage'] ?? [])): ?>
            <?php ac_table_empty(3, 'The mastery registry could not be read.'); ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php ac_card_close('A criterion maps to a competency by its LABEL, through one function — so this column is the same mapping the score itself uses, not a second guess at it.'); ?>

  <div class="ac-stack">
    <?php ac_card_open('Clone a syllabus across levels'); ?>
      <div class="ac-cardbody">
        <?php ac_form('curriculum_clone', 'curriculum'); ?>
          <div class="ac-inline">
            <label class="ac-field"><span class="ac-label">Course code</span>
              <input class="ac-input" name="course_code" required placeholder="CF-202"></label>
            <label class="ac-field ac-w-xs"><span class="ac-label">From level</span>
              <input class="ac-input" name="from_level" required placeholder="L1"></label>
            <label class="ac-field ac-w-xs"><span class="ac-label">To level</span>
              <input class="ac-input" name="to_level" required placeholder="L2"></label>
            <button type="submit" class="ac-btn ac-btn--primary">Clone</button>
          </div>
        <?php ac_form_end(); ?>
      </div>
    <?php ac_card_close('Idempotent — an upsert by week, so running it twice never duplicates. Same level is refused.'); ?>

    <?php ac_card_open('Publishing, and what a curriculum year guarantees'); ?>
      <div class="ac-cardbody">
        <p class="ac-note">A draft is invisible to students and cannot be assigned. Publishing is separate from
        ordering, so an unfinished week sitting in the plan never leaks into a learner's timetable.</p>
        <p class="ac-note">A student keeps the <strong>requirements</strong> they enrolled under but pays the
        <strong>current published schedule</strong>. Two different guarantees, deliberately — conflating them would
        freeze an academy's prices for four years.</p>
      </div>
    <?php ac_card_close('<a href="' . ac_h(ac_url('pricebooks')) . '">Price books</a> is where the second half of that lives.'); ?>
  </div>
</div>

<div class="ac-stack">
  <?php ac_card_open('How far students are', '', '<span class="ac-cardmeta">Lessons completed, every course</span>'); ?>
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead><tr><th>Course</th><th class="ac-num">Published</th><th class="ac-num">Drafts</th><th class="ac-num">Students</th><th class="ac-num">Completed</th><th class="ac-num">Waiting to mark</th></tr></thead>
        <tbody>
          <?php $rep = array_values(array_filter($d['report'] ?? [], fn($r) => $r['published'] + $r['draft'] + $r['students'] > 0)); ?>
          <?php if (!$rep): ?>
            <?php ac_table_empty(6, 'No course has lessons or students yet.'); ?>
          <?php else: foreach ($rep as $r): ?>
            <tr>
              <td><?= ac_h((string) $r['name']) ?><?= $r['active'] ? '' : ' ' . ac_pill('Inactive') ?></td>
              <td class="ac-num"><?= (int) $r['published'] ?></td>
              <td class="ac-num"><?= (int) $r['draft'] ?></td>
              <td class="ac-num"><?= (int) $r['students'] ?></td>
              <td class="ac-num"><?= $r['completion_pct'] === null ? '<span class="ac-sub">—</span>' : (int) $r['completion_pct'] . '%' ?></td>
              <td class="ac-num"><?= (int) $r['waiting'] ?: '<span class="ac-sub">—</span>' ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php ac_card_close('Completed is lessons done out of every published lesson times every student on the course. A dash means there is nothing to divide by yet — not 0%.'); ?>

  <?php ac_card_open('Courses', count($d['all_courses'] ?? []) . ' in the catalogue'); ?>
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead><tr><th>Course</th><th>Kind</th><th>In this programme</th><th class="ac-num"></th></tr></thead>
        <tbody>
          <?php foreach ($d['all_courses'] ?? [] as $c): ?>
            <tr>
              <td><?= ac_h((string) $c['name']) ?><div class="ac-sub ac-mono"><?= ac_h((string) $c['code']) ?></div></td>
              <td><?= ac_tag((string) ($c['kind'] ?: 'elective')) ?><?= (int) $c['active'] ? '' : ' ' . ac_pill('Inactive') ?></td>
              <td><?php if ((int) $c['linked'] > 0): ?>
                  <?php ac_action_btn('unlink_course', 'curriculum', ['program_id' => (int) ($d['program'] ?? 0), 'course_id' => (int) $c['id']], 'Take out',
                      'ac-btn ac-btn--sm', 'Take this course out of the programme? Its lessons and students are not touched.'); ?>
                <?php else: ?>
                  <?php ac_action_btn('link_course', 'curriculum', ['program_id' => (int) ($d['program'] ?? 0), 'course_id' => (int) $c['id']], 'Add to programme'); ?>
                <?php endif; ?></td>
              <td class="ac-num"><?php ac_action_btn('course_toggle', 'curriculum', ['course_id' => (int) $c['id']], (int) $c['active'] ? 'Make inactive' : 'Make active'); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="ac-cardbody">
      <?php ac_form('course_create', 'curriculum'); ?>
        <div class="ac-inline">
          <label class="ac-field ac-w-xs"><span class="ac-label">Code</span><input class="ac-input" name="code" required maxlength="40" placeholder="violin"></label>
          <label class="ac-field"><span class="ac-label">Name</span><input class="ac-input" name="name" required maxlength="120" placeholder="Violin"></label>
          <label class="ac-field ac-w-xs"><span class="ac-label">Kind</span>
            <select class="ac-select" name="kind"><option value="elective">Elective</option><option value="core">Core</option></select></label>
          <button type="submit" class="ac-btn ac-btn--primary">Add the course</button>
        </div>
      <?php ac_form_end(); ?>
    </div>
  <?php ac_card_close('An inactive course keeps its lessons and its students\' progress; it is just not offered.'); ?>
</div>

<?php /* Phase 2 (lessons-learn.php, lesson-exams.php). */
$mp = $d['materials_pending'] ?? [];
$fb = $d['feedback'] ?? [];
$sc = $d['schedule'] ?? null;
// Display only — the handler is what enforces it. Unknown means no.
$mayFees = isset($adminUser) ? (!$adminUser || mam_admin_can($adminUser, 'fees')) : false;
?>
<div class="ac-stack">
  <?php ac_card_open('Material teachers sent', count($mp) . ' waiting', '<span class="ac-cardmeta">Nothing reaches a student until it is approved</span>'); ?>
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead><tr><th>Material</th><th>Lesson</th><th>From</th><th>Decision</th></tr></thead>
        <tbody>
          <?php if (!$mp): ?>
            <?php ac_table_empty(4, 'Nothing waiting. Teachers send material from a student\'s lessons in their portal.'); ?>
          <?php else: foreach ($mp as $m): ?>
            <tr>
              <td><a href="<?= ac_h((string) $m['url']) ?>" target="_blank" rel="noopener"><?= ac_h((string) $m['title']) ?></a>
                <div class="ac-sub"><?= ac_h((string) $m['kind']) ?></div></td>
              <td><?= ac_h((string) $m['course_name']) ?><div class="ac-sub">Week <?= (int) $m['week'] ?> · <?= ac_h((string) $m['lesson_title']) ?></div></td>
              <td><?= ac_h((string) ($m['submitted_by_name'] ?? '—')) ?></td>
              <td>
                <?php ac_action_btn('lesson_material_review', 'curriculum', ['material_id' => (int) $m['id'], 'decision' => 'approve'], 'Approve', 'ac-btn ac-btn--sm ac-btn--primary'); ?>
                <?php ac_form('lesson_material_review', 'curriculum', ['material_id' => (int) $m['id'], 'decision' => 'reject'], 'class="ac-inline ac-mt"'); ?>
                  <label class="ac-field"><span class="ac-label">Why not</span><input class="ac-input" name="note" required maxlength="500"></label>
                  <button type="submit" class="ac-btn ac-btn--sm">Refuse</button>
                <?php ac_form_end(); ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php ac_card_close('A refusal needs a reason: the teacher who sent it is told, and "refused" alone teaches nothing.'); ?>

  <?php ac_card_open('How lessons are landing', '', '<span class="ac-cardmeta">Got it · mostly · lost, from students</span>'); ?>
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead><tr><th>Lesson</th><th class="ac-num">Got it</th><th class="ac-num">Mostly</th><th class="ac-num">Lost</th><th>What they said</th></tr></thead>
        <tbody>
          <?php if (!$fb): ?>
            <?php ac_table_empty(5, 'No student has answered yet. They are asked at the foot of every lesson.'); ?>
          <?php else: foreach ($fb as $f): ?>
            <tr>
              <td><?= ac_h((string) $f['title']) ?><?= $f['flagged'] ? ' ' . ac_pill('Not landing', 'warn') : '' ?>
                <div class="ac-sub"><?= ac_h((string) $f['course_name']) ?> · week <?= (int) $f['week'] ?></div></td>
              <td class="ac-num"><?= (int) $f['got'] ?></td>
              <td class="ac-num"><?= (int) $f['mostly'] ?></td>
              <td class="ac-num"><?= (int) $f['lost'] ?></td>
              <td><?= $f['comments'] ? implode('<br>', array_map(fn($c) => '“' . ac_h((string) $c) . '”', $f['comments'])) : '<span class="ac-sub">—</span>' ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php ac_card_close('Never which child said it. A lesson is flagged when at least five have answered and a third or more say lost.'); ?>

  <?php if ($sc): ?>
  <?php ac_card_open('Deadlines, fines and the assistant'); ?>
    <div class="ac-cardbody">
      <?php ac_form('lesson_settings', 'curriculum'); ?>
        <div class="ac-inline">
          <label class="ac-field ac-w-sm"><span class="ac-label">Term starts</span>
            <input class="ac-input" type="date" name="term_start" value="<?= ac_h((string) $sc['term_start_setting']) ?>"></label>
          <label class="ac-field ac-w-xs"><span class="ac-label">Remind from (days before due)</span>
            <input class="ac-input" type="number" min="0" max="30" name="remind_before" value="<?= (int) $sc['remind_before'] ?>"></label>
          <label class="ac-field ac-w-xs"><span class="ac-label">Then every (days)</span>
            <input class="ac-input" type="number" min="1" max="30" name="remind_every" value="<?= (int) $sc['remind_every'] ?>"></label>
        </div>
        <p class="ac-sub ac-mt"><?= $sc['term_start_setting'] === ''
            ? ($sc['term_start'] !== '' ? 'Blank uses the academic calendar\'s current term, which starts ' . ac_h($sc['term_start']) . '.' : 'Blank, and no term in the academic calendar either — so nothing is ever due and nobody is reminded.')
            : 'A lesson is due its week number of weeks after the later of this date and the student\'s enrolment.' ?></p>
        <fieldset class="ac-qset ac-mt" <?= $mayFees ? '' : 'disabled' ?>>
          <legend class="ac-label">Fines for overdue lessons<?= $mayFees ? '' : ' — needs the fees permission to change' ?></legend>
          <label class="ac-tick"><input type="checkbox" name="fines_enabled" value="1" <?= $sc['fines_enabled'] ? 'checked' : '' ?>>
            <span>Charge a fine when a lesson is overdue. Off by default: a money penalty on a child is the academy's decision to make.</span></label>
          <div class="ac-inline ac-mt">
            <label class="ac-field ac-w-xs"><span class="ac-label">Days past due</span>
              <input class="ac-input" type="number" min="1" max="90" name="fine_after" value="<?= (int) $sc['fine_after'] ?>"></label>
            <label class="ac-field ac-w-xs"><span class="ac-label">Amount (USD)</span>
              <input class="ac-input" type="number" min="0" max="50" step="0.5" name="fine_usd" value="<?= ac_h((string) $sc['fine_usd']) ?>"></label>
          </div>
        </fieldset>
        <fieldset class="ac-qset ac-mt">
          <legend class="ac-label">The assistant<?= $sc['ai_configured'] ? '' : ' — no AI provider is set up, so these do nothing yet' ?></legend>
          <label class="ac-tick"><input type="checkbox" name="ai_marking" value="1" <?= $sc['ai_marking'] ? 'checked' : '' ?>>
            <span>Let it mark written answers. When unsure it leaves them for a teacher, never marks a recording of a child playing, and every mark it gives a teacher can change.</span></label>
          <label class="ac-tick ac-mt"><input type="checkbox" name="helper" value="1" <?= $sc['helper'] ? 'checked' : '' ?>>
            <span>Offer the study helper on lessons — hints, not answers, <?= (int) MAM_HELPER_DAILY ?> questions a day, off during an exam.</span></label>
        </fieldset>
        <button type="submit" class="ac-btn ac-btn--primary ac-mt">Save</button>
      <?php ac_form_end(); ?>
    </div>
  <?php ac_card_close('Words that read as a child at risk are never marked or sent to the assistant: they are held for a person and the safeguarding lead is told.'); ?>
  <?php endif; ?>
</div>
