<?php
/**
 * Academic calendar — the only authority on a refund.
 *
 * The writes went with the retired dashboard (HANDOFF §4y) and are back here,
 * posting to the EXISTING handlers in admin/index.php: term_save,
 * term_seed_year, term_refund_reseed, closure_add, closure_delete, term_census.
 * All of them demand the `settings` capability, so the forms are offered under
 * the same one.
 */
declare(strict_types=1);
/** @var array $d */
$cur = $d['current'] ?? [];
$sel = $d['selected'] ?? null;
$maySet = isset($adminUser) ? (!$adminUser || mam_admin_can($adminUser, 'settings')) : false;
$termName = static fn(array $t): string => trim((string) ($t['label'] ?: $t['code']) . ' ' . (string) $t['year_label']);
/* A refund band reads as the weeks it covers. The last band runs to the end of
   the term (stored as week 999), so it says "and after", not "to week 999". */
$band = static function (array $r): string {
    $from = (int) ($r['week_from'] ?? 0); $to = (int) ($r['week_to'] ?? 0);
    if ($from === 0 && $to <= 0) return 'Before teaching starts';
    $a = $from === 0 ? 'Before teaching' : 'Week ' . $from;
    if ($to >= 99) return $a . ' and after';
    return $to > $from ? $a . ' – week ' . $to : $a;
};
?>
<?php ac_head('Academic calendar',
    'Everything else in the console reads its dates from here · adjudicated in '
    . ac_h((string) ($d['tz'] ?? 'Africa/Lagos')) . ', displayed in your zone',
    $maySet ? '<a class="ac-btn" href="' . ac_h(ac_url('calendar')) . '#term-form">Author a term</a>' : ''); ?>

<?php if (($cur['source'] ?? '') === 'computed'): ?>
  <?php ac_banner('warn', 'fa-circle-info',
    '<strong>The current term is computed, not authored.</strong> The window comes from the code\'s own constants, '
    . 'which is fine for a label on a page and not enough for a refund — a family is entitled to the table that was '
    . 'published when they enrolled. Author the term to make the refund schedule real.'); ?>
<?php endif; ?>

<?php ac_card_open('Terms', count($d['terms'] ?? []) . ' on file', '<span class="ac-cardmeta">Overlapping sibling terms are refused</span>'); ?>
  <div class="ac-tablewrap">
    <table class="ac-table">
      <thead><tr><th>Term</th><th>Dates</th><th class="ac-num">Weeks</th><th>State</th><th class="ac-num">Census</th><th></th></tr></thead>
      <tbody>
        <?php if (!($d['terms'] ?? [])): ?>
          <?php ac_table_empty(6, 'No terms authored. Until one is, every screen falls back to the computed window — the strangler pattern, not a cutover.'); ?>
        <?php else: foreach ($d['terms'] as $t):
          $isCurrent = (int) ($cur['id'] ?? 0) === (int) $t['id'];
          $isSel = $sel && (int) $sel['id'] === (int) $t['id']; ?>
          <tr class="<?= ($isSel || (!$sel && $isCurrent)) ? 'is-selected' : '' ?>">
            <td><div class="ac-strong"><?= ac_h($termName($t)) ?></div>
                <?php if ($isCurrent && !empty($cur['week'])): ?>
                  <div class="ac-sub">Week <?= (int) $cur['week'] ?><?= $cur['weeks'] ? ' of ' . (int) $cur['weeks'] : '' ?></div>
                <?php endif; ?></td>
            <td><?= ac_h(mam_console_date((string) $t['starts_on'], 'j M Y')) ?> –
                <?= ac_h(mam_console_date((string) $t['ends_on'], 'j M Y')) ?></td>
            <td class="ac-num"><?= $t['teaching_weeks'] !== null ? (int) $t['teaching_weeks'] : '—' ?></td>
            <td><?= ac_status((string) ($t['status'] ?? 'planned')) ?><?= $isCurrent ? ' ' . ac_pill('Current', 'accent') : '' ?></td>
            <td class="ac-num">
              <?php if (!empty($t['census'])): ?>
                <?= (int) ($t['census']['enrolled'] ?? 0) ?> enrolled
                <div class="ac-sub"><?= ac_h(mam_console_date((string) ($t['census']['taken_on'] ?? null), 'j M Y')) ?></div>
              <?php else: ?><span class="ac-sub">not taken</span><?php endif; ?>
            </td>
            <td class="ac-num">
              <div class="ac-row">
                <?php if ($maySet): ?>
                  <a class="ac-btn ac-btn--sm" href="<?= ac_h(ac_url('calendar', ['t' => (int) $t['id']])) ?>#term-form">Edit</a>
                <?php endif; ?>
                <?php if (empty($t['census']) && $maySet): ?>
                  <?php ac_action_btn('term_census', 'calendar', ['term_id' => (int) $t['id']], 'Take census', 'ac-btn ac-btn--sm',
                      'Take the enrolment census for this term? It is a snapshot and cannot be retaken.'); ?>
                <?php elseif (!empty($t['census'])): ?><span class="ac-sub">census locked</span><?php endif; ?>
              </div>
            </td>
          </tr>
          <?php if ($t['closures'] ?? []): ?>
            <tr><td colspan="6">
              <div class="ac-row">
                <span class="ac-kpi-l">Closures</span>
                <?php foreach ($t['closures'] as $c): ?>
                  <span class="ac-pill"><?= ac_h((string) $c['label']) ?> ·
                    <?= ac_h(mam_console_date((string) $c['from_on'], 'j M')) ?><?php
                    if ((string) $c['to_on'] !== (string) $c['from_on']): ?>–<?= ac_h(mam_console_date((string) $c['to_on'], 'j M')) ?><?php endif; ?></span>
                  <?php if ($maySet): ?>
                    <?php ac_action_btn('closure_delete', 'calendar', ['term_id' => (int) $t['id'], 'closure_id' => (int) $c['id']],
                        'Remove', 'ac-btn ac-btn--sm ac-btn--ghost',
                        'Remove the closure "' . (string) $c['label'] . '"? Classes on those days go back on the timetable.'); ?>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            </td></tr>
          <?php endif; ?>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
<?php ac_card_close('A census is taken once. A student who withdraws later in the term does not change it.'); ?>

<?php if ($maySet): ?>
<div class="ac-cols ac-cols--main" id="term-form">
  <?php ac_card_open($sel ? 'Edit ' . $termName($sel) : 'Author a term',
      $sel ? 'Session and code identify the term, so they are fixed here' : 'Saving an existing session and code edits that term'); ?>
    <div class="ac-cardbody">
      <?php ac_form('term_save', 'calendar'); $v = static fn(string $k, string $def = '') => (string) ($sel[$k] ?? $def); ?>
        <div class="ac-grid ac-grid--2">
          <label class="ac-field"><span class="ac-label">Session</span>
            <input class="ac-input" name="year_label" required maxlength="20" placeholder="2026/2027" value="<?= ac_h($v('year_label')) ?>"<?= $sel ? ' readonly' : '' ?>></label>
          <label class="ac-field"><span class="ac-label">Term code</span>
            <input class="ac-input" name="code" required maxlength="20" placeholder="toni" value="<?= ac_h($v('code')) ?>"<?= $sel ? ' readonly' : '' ?>></label>
          <label class="ac-field"><span class="ac-label">Name shown to families</span>
            <input class="ac-input" name="label" maxlength="80" placeholder="TONI Term" value="<?= ac_h($v('label')) ?>"></label>
          <label class="ac-field"><span class="ac-label">Term number in the session</span>
            <input class="ac-input" name="term_no" type="number" min="1" max="6" value="<?= ac_h($v('term_no', '1')) ?>"></label>
          <label class="ac-field"><span class="ac-label">Starts</span>
            <input class="ac-input" name="starts_on" type="date" required value="<?= ac_h($v('starts_on')) ?>"></label>
          <label class="ac-field"><span class="ac-label">Ends</span>
            <input class="ac-input" name="ends_on" type="date" required value="<?= ac_h($v('ends_on')) ?>"></label>
          <label class="ac-field"><span class="ac-label">Add/drop deadline</span>
            <input class="ac-input" name="add_drop_deadline" type="date" value="<?= ac_h($v('add_drop_deadline')) ?>"></label>
          <label class="ac-field"><span class="ac-label">Withdrawal deadline</span>
            <input class="ac-input" name="withdrawal_deadline" type="date" value="<?= ac_h($v('withdrawal_deadline')) ?>"></label>
          <label class="ac-field"><span class="ac-label">Census date</span>
            <input class="ac-input" name="census_date" type="date" value="<?= ac_h($v('census_date')) ?>"></label>
          <label class="ac-field"><span class="ac-label">Teaching weeks</span>
            <input class="ac-input" name="teaching_weeks" type="number" min="1" max="52" value="<?= ac_h($v('teaching_weeks', '12')) ?>"></label>
          <label class="ac-field"><span class="ac-label">State</span>
            <select class="ac-input" name="status">
              <?php foreach ($d['statuses'] ?? [] as $s): ?>
                <option value="<?= ac_h($s) ?>"<?= $v('status', 'planned') === $s ? ' selected' : '' ?>><?= ac_h(ucfirst($s)) ?></option>
              <?php endforeach; ?>
            </select></label>
        </div>
        <label class="ac-field ac-mt"><span class="ac-label">Notes</span>
          <textarea class="ac-textarea" name="notes" rows="2"><?= ac_h($v('notes')) ?></textarea></label>
        <div class="ac-row ac-mt">
          <button type="submit" class="ac-btn ac-btn--primary"><?= $sel ? 'Save the term' : 'Author the term' ?></button>
          <?php if ($sel): ?><a class="ac-btn" href="<?= ac_h(ac_url('calendar')) ?>#term-form">Author a new one instead</a><?php endif; ?>
        </div>
      <?php ac_form_end(); ?>
    </div>
  <?php ac_card_close('A new term is given the conventional refund table. Changing dates later never touches a published table — a family is entitled to the one published when they enrolled.'); ?>

  <div class="ac-stack">
    <?php if ($sel): ?>
      <?php ac_card_open('Add a closure', 'To ' . $termName($sel)); ?>
        <div class="ac-cardbody">
          <?php ac_form('closure_add', 'calendar', ['term_id' => (int) $sel['id']]); ?>
            <div class="ac-grid ac-grid--2">
              <label class="ac-field"><span class="ac-label">Kind</span>
                <select class="ac-input" name="kind">
                  <option value="holiday">Public holiday</option><option value="break">Mid-term break</option>
                  <option value="exam">Exam window</option><option value="makeup">Make-up week</option>
                </select></label>
              <label class="ac-field"><span class="ac-label">Name</span>
                <input class="ac-input" name="label" required maxlength="120" placeholder="Independence Day"></label>
              <label class="ac-field"><span class="ac-label">From</span>
                <input class="ac-input" name="from_on" type="date" required min="<?= ac_h((string) $sel['starts_on']) ?>" max="<?= ac_h((string) $sel['ends_on']) ?>"></label>
              <label class="ac-field"><span class="ac-label">To</span>
                <input class="ac-input" name="to_on" type="date" required min="<?= ac_h((string) $sel['starts_on']) ?>" max="<?= ac_h((string) $sel['ends_on']) ?>"></label>
            </div>
            <div class="ac-row ac-mt"><button type="submit" class="ac-btn">Add the closure</button></div>
          <?php ac_form_end(); ?>
        </div>
      <?php ac_card_close('A closure must fall inside its term, or it would quietly never apply.'); ?>
    <?php endif; ?>

    <?php ac_card_open('Author a whole session', 'Four terms from the academy\'s own shape'); ?>
      <div class="ac-cardbody">
        <?php ac_form('term_seed_year', 'calendar'); ?>
          <label class="ac-field"><span class="ac-label">Session starting in</span>
            <input class="ac-input" name="year" type="number" min="2020" max="2100" required value="<?= ac_h(date('Y')) ?>"></label>
          <div class="ac-row ac-mt"><button type="submit" class="ac-btn">Author the session</button></div>
        <?php ac_form_end(); ?>
        <p class="ac-sub ac-mt">Deadlines are derived from each term's length: add/drop at the end of week 1, withdrawal at
        the 60% point, census a fortnight in. They are a starting point to check against the published calendar, and a
        term already authored for that session is left as it is.</p>
      </div>
    <?php ac_card_close(); ?>
  </div>
</div>
<?php endif; ?>

<div class="ac-cols ac-cols--2">
  <?php
  /* One term's table at a time — the term being edited, else the current one,
     else the newest. Every term's full table at once ran ~1,300px with five
     terms on file, which buries the one an operator actually came to check. */
  $focus = $sel;
  if (!$focus) foreach ($d['terms'] ?? [] as $t) if ((int) $t['id'] === (int) ($cur['id'] ?? 0)) { $focus = $t; break; }
  if (!$focus) $focus = ($d['terms'] ?? [])[0] ?? null;
  ac_card_open('Refund schedule', $focus ? $termName($focus) . ' · as published' : 'None published'); ?>
    <div class="ac-cardbody">
      <?php if ($focus && ($focus['refunds'] ?? [])): ?>
        <div class="ac-defs">
          <?php foreach ($focus['refunds'] as $r): ?>
            <?php ac_def($band($r), (int) ($r['percent'] ?? 0) . '% refunded'); ?>
          <?php endforeach; ?>
        </div>
        <?php if ($maySet): ?>
          <div class="ac-mt">
            <?php ac_action_btn('term_refund_reseed', 'calendar', ['term_id' => (int) $focus['id']],
                'Reset to the conventional table', 'ac-btn ac-btn--sm ac-btn--ghost',
                'Replace this term\'s published refund table with the conventional one? Families who enrolled under the current table are entitled to it.'); ?>
          </div>
        <?php endif; ?>
        <?php if (count($d['terms'] ?? []) > 1): ?>
          <p class="ac-sub ac-mt">Choose Edit on another term to see its table.</p>
        <?php endif; ?>
      <?php else: ?>
        <div class="ac-note">No refund table is published. The default only <em>seeds</em> — once seeded, the rows
        are the truth, so re-saving a term's dates never silently reverts an edited table.</div>
      <?php endif; ?>
    </div>
  <?php ac_card_close('A refund percentage is COMPUTED from a date, never stored per student: two families withdrawing on the same day must get the same answer, and the answer must be reproducible a year later in an audit.'); ?>

  <?php ac_card_open('What the calendar decides'); ?>
    <div class="ac-cardbody">
      <div class="ac-defs">
        <?php
          ac_def('Today, in the academy\'s zone', ac_h((string) ($d['today'] ?? '')));
          ac_def('Server clock', 'never read for a deadline');
          ac_def('A closure', 'withdraws the class from the timetable, the register and the fee in one move');
          ac_def('A withdrawal', 'records add/drop or a "W" with its percentage into the transition reason');
        ?>
      </div>
      <p class="ac-note ac-mt">A diaspora guardian in New York must not get a different refund
      from a Lagos guardian because their clocks disagree about the date. Consulting the calendar on a withdrawal is
      best-effort by design — an unauthored calendar must never stop a student leaving a course.</p>
    </div>
  <?php ac_card_close(); ?>
</div>
