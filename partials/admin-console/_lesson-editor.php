<?php
/**
 * The lesson editor (api/lib/lessons.php), drawn by curriculum.php when
 * ?lesson=N or ?lesson=new is open. One form, posting `lesson_save` to the
 * legacy dispatcher like every console write. It works with no JavaScript:
 * existing questions and materials are drawn, plus a few empty slots, and an
 * empty slot is ignored on save — so "add another question" is "fill in the
 * next empty box", and saving draws a fresh empty one.
 *
 * The publishing rule (questions, and at least one a teacher marks) is the
 * library's; this form states it beside the status control so nobody meets it
 * only as a refusal.
 */
declare(strict_types=1);
/** @var array $d */
$L = $d['editing'];
$isNew = empty($L['id']);
$blankChecks = $isNew ? 3 : 2;
$blankMats = 2;
$kinds = ['mcq' => 'Multiple choice', 'order' => 'Put in order', 'short' => 'Short answer', 'task' => 'Task',
          'reflection' => 'Reflection', 'evidence' => 'Evidence (link to a recording or photo)'];
$matKinds = ['link' => 'Link', 'pdf' => 'PDF', 'score' => 'Sheet music', 'slides' => 'Slides', 'doc' => 'Document',
             'video' => 'Video', 'audio' => 'Audio', 'image' => 'Image'];
$checks = $L['checks'] ?? [];
for ($i = 0; $i < $blankChecks; $i++) $checks[] = ['id' => null, 'kind' => 'short', 'prompt' => '', 'options' => [], 'answer' => null, 'whys' => [], 'rubric' => '', 'points' => 1];
$mats = $L['materials'] ?? [];
for ($i = 0; $i < $blankMats; $i++) $mats[] = ['id' => null, 'kind' => 'link', 'title' => '', 'url' => '', 'guide' => ''];
$backUrl = ac_url('curriculum', ['program' => (int) ($d['program'] ?? 0), 'course' => (int) ($L['course_id'] ?: ($d['course'] ?? 0))]);
?>
<?php ac_head($isNew ? 'New lesson' : (string) $L['title'],
    $isNew ? 'One course, one level, one week' : ((string) ($L['course_name'] ?? '') . ' · Week ' . (int) $L['week'] . ' · version ' . (int) $L['version']),
    '<a class="ac-btn" href="' . ac_h($backUrl) . '">Back to the lessons</a>'); ?>

<?php if (!$isNew && (int) ($L['started'] ?? 0) > 0): ?>
  <?php ac_banner('warn', 'fa-users', '<strong>' . (int) $L['started'] . ' student' . ((int) $L['started'] === 1 ? ' has' : 's have')
    . ' started this lesson.</strong> Changing what a question asks makes it a new version, and their scores read as from an older one. '
    . 'Rewording the lesson, its materials or a marking guide does not.'); ?>
<?php endif; ?>

<?php ac_form('lesson_save', 'curriculum', ['lesson_id' => (string) ($L['id'] ?? '')]); ?>
<div class="ac-cols ac-cols--2">
  <?php ac_card_open('The lesson'); ?>
    <div class="ac-cardbody">
      <div class="ac-grid ac-grid--2">
        <label class="ac-field"><span class="ac-label">Course</span>
          <select class="ac-select" name="course_id" required>
            <?php foreach ($d['all_courses'] ?? [] as $c): ?>
              <option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === (int) $L['course_id'] ? ' selected' : '' ?>><?= ac_h((string) $c['name']) ?></option>
            <?php endforeach; ?>
          </select></label>
        <label class="ac-field"><span class="ac-label">Level</span>
          <select class="ac-select" name="level_id">
            <option value="">Every level (no level)</option>
            <?php foreach ($d['levels'] ?? [] as $lv): ?>
              <option value="<?= (int) $lv['id'] ?>"<?= (int) $lv['id'] === (int) ($L['level_id'] ?? 0) ? ' selected' : '' ?>><?= ac_h((string) $lv['name']) ?></option>
            <?php endforeach; ?>
          </select></label>
        <label class="ac-field"><span class="ac-label">Week</span>
          <input class="ac-input" type="number" name="week" min="1" max="104" required value="<?= (int) $L['week'] ?>"></label>
        <label class="ac-field"><span class="ac-label">Kind of week</span>
          <select class="ac-select" name="kind">
            <?php foreach (['module' => 'Lesson', 'revision' => 'Revision', 'exam' => 'Exam'] as $k => $lbl): ?>
              <option value="<?= $k ?>"<?= $k === ($L['kind'] ?? 'module') ? ' selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select></label>
      </div>
      <label class="ac-field ac-mt"><span class="ac-label">Title</span>
        <input class="ac-input" name="title" required maxlength="160" value="<?= ac_h((string) $L['title']) ?>"></label>
      <label class="ac-field ac-mt"><span class="ac-label">In one line</span>
        <input class="ac-input" name="summary" maxlength="600" value="<?= ac_h((string) ($L['summary'] ?? '')) ?>"
               placeholder="What this week is for, as a student reads it"></label>
      <label class="ac-field ac-mt"><span class="ac-label">By the end, the student can… (one per line)</span>
        <textarea class="ac-textarea" name="objectives" rows="3"><?= ac_h(implode("\n", $L['objectives'] ?? [])) ?></textarea></label>
      <div class="ac-grid ac-grid--2 ac-mt">
        <label class="ac-field"><span class="ac-label">About how long (minutes)</span>
          <input class="ac-input" type="number" name="duration_mins" min="1" max="600" value="<?= ac_h((string) ($L['duration_mins'] ?? '')) ?>"></label>
        <label class="ac-field"><span class="ac-label">Pass mark (%)</span>
          <input class="ac-input" type="number" name="pass_mark" min="0" max="100" value="<?= (int) ($L['pass_mark'] ?? 60) ?>"></label>
      </div>
    </div>
  <?php ac_card_close(); ?>

  <?php ac_card_open('Who sees it'); ?>
    <div class="ac-cardbody">
      <label class="ac-field"><span class="ac-label">State</span>
        <select class="ac-select" name="status">
          <?php foreach (['draft' => 'Draft — nobody sees it', 'published' => 'Published — students on the course see it', 'retired' => 'Retired — kept for the record, shown to nobody'] as $k => $lbl): ?>
            <option value="<?= $k ?>"<?= $k === ($L['status'] ?? 'draft') ? ' selected' : '' ?>><?= ac_h($lbl) ?></option>
          <?php endforeach; ?>
        </select></label>
      <p class="ac-hint ac-mt">To publish, a lesson needs questions, and at least one a teacher marks — a short answer,
        task, reflection or evidence. A lesson a student can finish by guessing a multiple choice has not been learned.
        If it does not qualify yet, saving keeps it as a draft and says why.</p>
      <p class="ac-hint">Multiple choice and put-in-order are marked the moment a student answers. Everything else waits
        for their teacher on the Marking screen. Students see the right answers only after they pass, or after
        <?= (int) MAM_LESSON_REVEAL_AFTER ?> tries.</p>
    </div>
  <?php ac_card_close(); ?>
</div>

<?php ac_card_open('The lesson itself', '', '<span class="ac-cardmeta">A blank line starts a new paragraph</span>'); ?>
  <div class="ac-cardbody">
    <label class="ac-field"><span class="ac-label">Text</span>
      <textarea class="ac-textarea" name="body" rows="10"><?= ac_h((string) ($L['body'] ?? '')) ?></textarea></label>
  </div>
<?php ac_card_close(); ?>

<?php ac_card_open('Questions', count($L['checks'] ?? []) . ' written', '<span class="ac-cardmeta">An empty box is ignored — fill the next one to add a question</span>'); ?>
  <div class="ac-cardbody">
    <?php foreach ($checks as $i => $c): $n = $i + 1; $opts = $c['options'] ?? []; ?>
      <fieldset class="ac-qset">
        <legend class="ac-label">Question <?= $n ?><?= empty($c['id']) ? ' (empty)' : '' ?></legend>
        <input type="hidden" name="checks[<?= $i ?>][id]" value="<?= ac_h((string) ($c['id'] ?? '')) ?>">
        <div class="ac-grid ac-grid--2">
          <label class="ac-field"><span class="ac-label">Kind</span>
            <select class="ac-select" name="checks[<?= $i ?>][kind]">
              <?php foreach ($kinds as $k => $lbl): ?>
                <option value="<?= $k ?>"<?= $k === ($c['kind'] ?? 'short') ? ' selected' : '' ?>><?= ac_h($lbl) ?></option>
              <?php endforeach; ?>
            </select></label>
          <label class="ac-field"><span class="ac-label">Points</span>
            <input class="ac-input" type="number" min="0" max="100" name="checks[<?= $i ?>][points]" value="<?= (int) ($c['points'] ?? 1) ?>"></label>
        </div>
        <label class="ac-field ac-mt"><span class="ac-label">The question</span>
          <textarea class="ac-textarea" rows="2" name="checks[<?= $i ?>][prompt]"><?= ac_h((string) ($c['prompt'] ?? '')) ?></textarea></label>
        <label class="ac-field ac-mt"><span class="ac-label">Options, one per line — for put-in-order, write the steps in the RIGHT order (students see them shuffled)</span>
          <textarea class="ac-textarea" rows="3" name="checks[<?= $i ?>][options]"><?= ac_h(implode("\n", array_map(fn($o) => is_array($o) ? (string) ($o['text'] ?? '') : (string) $o, $opts))) ?></textarea></label>
        <div class="ac-grid ac-grid--2 ac-mt">
          <label class="ac-field"><span class="ac-label">Multiple choice: the right option's number</span>
            <input class="ac-input" type="number" min="1" max="8" name="checks[<?= $i ?>][answer_n]"
                   value="<?= ($c['kind'] ?? '') === 'mcq' && ($c['answer'] ?? null) !== null && $c['answer'] !== '' ? (int) $c['answer'] + 1 : '' ?>"></label>
          <label class="ac-field"><span class="ac-label">For a teacher-marked question: what a good answer looks like</span>
            <input class="ac-input" name="checks[<?= $i ?>][rubric]" maxlength="2000" value="<?= ac_h((string) ($c['rubric'] ?? '')) ?>"></label>
        </div>
        <?php $whyLines = [];
          foreach (($c['whys'] ?? []) as $wi => $w) if (trim((string) $w) !== '') $whyLines[] = ($wi + 1) . ': ' . $w; ?>
        <label class="ac-field ac-mt"><span class="ac-label">Multiple choice: why a wrong option is tempting — one line each, starting with the option's number (2: …)</span>
          <textarea class="ac-textarea" rows="2" name="checks[<?= $i ?>][whys]" placeholder="2: C up to E-flat would be a minor third"><?= ac_h(implode("\n", $whyLines)) ?></textarea></label>
      </fieldset>
    <?php endforeach; ?>
  </div>
<?php ac_card_close(); ?>

<?php ac_card_open('Materials', count($L['materials'] ?? []) . ' attached', '<span class="ac-cardmeta">https:// links, or files stored here</span>'); ?>
  <div class="ac-cardbody">
    <?php foreach ($mats as $i => $m): ?>
      <div class="ac-grid ac-grid--2 ac-matrow">
        <input type="hidden" name="materials[<?= $i ?>][id]" value="<?= ac_h((string) ($m['id'] ?? '')) ?>">
        <label class="ac-field"><span class="ac-label">Title</span>
          <input class="ac-input" name="materials[<?= $i ?>][title]" maxlength="200" value="<?= ac_h((string) ($m['title'] ?? '')) ?>"></label>
        <label class="ac-field"><span class="ac-label">Link</span>
          <input class="ac-input" type="url" name="materials[<?= $i ?>][url]" maxlength="1000" placeholder="https://" value="<?= ac_h((string) ($m['url'] ?? '')) ?>"></label>
        <label class="ac-field"><span class="ac-label">Kind</span>
          <select class="ac-select" name="materials[<?= $i ?>][kind]">
            <?php foreach ($matKinds as $k => $lbl): ?>
              <option value="<?= $k ?>"<?= $k === ($m['kind'] ?? 'link') ? ' selected' : '' ?>><?= ac_h($lbl) ?></option>
            <?php endforeach; ?>
          </select></label>
        <label class="ac-field"><span class="ac-label">What to do with it</span>
          <input class="ac-input" name="materials[<?= $i ?>][guide]" maxlength="300" placeholder="Listen twice before the class" value="<?= ac_h((string) ($m['guide'] ?? '')) ?>"></label>
      </div>
    <?php endforeach; ?>
  </div>
<?php ac_card_close(); ?>

<div class="ac-row"><button type="submit" class="ac-btn ac-btn--primary"><?= $isNew ? 'Create the lesson' : 'Save the lesson' ?></button></div>
<?php ac_form_end(); ?>

<?php if (!$isNew): ?>
  <?php ac_card_open('Remove'); ?>
    <div class="ac-cardbody">
      <?php if ((int) ($L['started'] ?? 0) > 0): ?>
        <p class="ac-note">Students have started this lesson, so it cannot be deleted — their progress is their record. Retire it instead: set its state to Retired above.</p>
      <?php else: ?>
        <?php ac_action_btn('module_delete', 'curriculum', ['module_id' => (int) $L['id']], 'Delete this lesson', 'ac-btn ac-btn--sm',
            'Delete this lesson and its questions? Nobody has started it, so nothing else is lost.'); ?>
      <?php endif; ?>
    </div>
  <?php ac_card_close(); ?>
<?php endif; ?>
