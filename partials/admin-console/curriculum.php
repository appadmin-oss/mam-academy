<?php
/** Curriculum — programme → course at a level → modules. Not the schedule, not mastery. */
declare(strict_types=1);
/** @var array $d */
$uncovered = array_values(array_filter($d['coverage'] ?? [], fn($c) => empty($c['evidenced'])));
$uncoveredWeight = array_sum(array_map(fn($c) => (float) $c['weight'], $uncovered));
?>
<?php ac_head('Curriculum',
    'Programme → course at a level → modules · curriculum is not the schedule and not mastery'); ?>

<?php if ($uncovered && $uncoveredWeight > 0): ?>
  <?php ac_banner('warn', 'fa-triangle-exclamation',
    '<strong>' . count($uncovered) . ' competenc' . (count($uncovered) === 1 ? 'y' : 'ies')
    . ' carry ' . rtrim(rtrim(number_format($uncoveredWeight, 1), '0'), '.') . '% of the mastery weight and nothing in '
    . 'this course evidences ' . (count($uncovered) === 1 ? 'it' : 'them') . '.</strong> Every student\'s band will read '
    . '"not enough evidence yet" on those rows until a rubric criterion maps to them — which is honest, but it is a '
    . 'curriculum gap, not a reporting one.'); ?>
<?php endif; ?>

<div class="ac-cols ac-cols--3">
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
      <?php if (!($d['programs'] ?? [])): ?><?php ac_empty('No programmes', 'Seed the catalogue from the legacy curriculum screen.'); ?><?php endif; ?>
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

  <?php ac_card_open('Modules', count($d['modules'] ?? []) . ' authored', '<span class="ac-cardmeta">Week is the planned position, not a date</span>'); ?>
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead><tr><th>Wk</th><th>Title</th><th>Kind</th></tr></thead>
        <tbody>
          <?php if (!($d['modules'] ?? [])): ?>
            <?php ac_table_empty(3, 'No modules for this course yet.'); ?>
          <?php else: foreach ($d['modules'] as $m): ?>
            <tr>
              <td class="ac-num ac-mono">W<?= (int) $m['week'] ?></td>
              <td><?= ac_h((string) $m['title']) ?>
                <?php if (!empty($m['level_code'])): ?><div class="ac-sub"><?= ac_h((string) $m['level_code']) ?></div><?php endif; ?></td>
              <td><?= (string) ($m['kind'] ?? '') !== '' ? ac_tag((string) $m['kind']) : '<span class="ac-sub">—</span>' ?></td>
            </tr>
          <?php endforeach; endif; ?>
          <?php if ($d['gaps'] ?? []): ?>
            <tr><td colspan="3"><div class="ac-note">Week<?= count($d['gaps']) === 1 ? '' : 's' ?>
              <strong><?= ac_h(implode(', ', array_slice($d['gaps'], 0, 14))) ?><?= count($d['gaps']) > 14 ? '…' : '' ?></strong>
              have no module yet.</div></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php ac_card_close('Week is the planned position in the course, not a date; when a learner actually reaches it is recorded per learner.'); ?>
</div>

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
