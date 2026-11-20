<?php
/** Admissions — one board across every intake type, with the applicant card beside it. */
declare(strict_types=1);
/** @var array $d */
$detail = $d['detail'] ?? null;
$stage  = (string) ($d['stage'] ?? '');
$type   = (string) ($d['type'] ?? '');
?>
<?php ac_head('Applications',
    'One board, four intake types · ' . (int) ($d['types']['all'] ?? 0) . ' applications',
    '<a class="ac-btn" href="' . ac_h(ac_url('admissions', ['stage' => 'submitted'])) . '">Needs a first look</a>'
    . '<a class="ac-btn" href="' . ac_h(ac_url('funnel')) . '">Where they stop</a>'); ?>

<?php if ((int) ($d['stale'] ?? 0) > 0): ?>
  <?php ac_banner('warn', 'fa-clock',
    '<strong>' . (int) $d['stale'] . ' past the five-day promise.</strong> That is a staffing figure, not an '
    . 'applicant-quality figure — the applicants have not got worse, the queue has got longer.',
    '<a class="ac-btn ac-btn--sm" href="' . ac_h(ac_url('admissions', ['stage' => 'submitted'])) . '">Open them</a>'); ?>
<?php endif; ?>

<div class="ac-tabs">
  <?php foreach ($d['stages'] ?? [] as $s): ?>
    <a class="ac-tab" href="<?= ac_h(ac_url('admissions', ['stage' => $s['key'], 'type' => $type])) ?>"
       <?= $stage === $s['key'] ? 'aria-current="true"' : '' ?>>
      <?= ac_h($s['label']) ?><span class="ac-tabn"><?= (int) $s['n'] ?></span>
    </a>
  <?php endforeach; ?>
  <?php if ($stage !== '' || $type !== '' || ($d['q'] ?? '') !== ''): ?>
    <a class="ac-tab" href="<?= ac_h(ac_url('admissions')) ?>">Clear</a>
  <?php endif; ?>
  <form method="get" class="ac-row ac-push">
    <input type="hidden" name="v" value="admissions">
    <?php if ($stage !== ''): ?><input type="hidden" name="stage" value="<?= ac_h($stage) ?>"><?php endif; ?>
    <input class="ac-input ac-input--sm ac-searchbox" type="search" name="q"
           value="<?= ac_h($d['q'] ?? '') ?>" placeholder="Name or reference…">
    <button type="submit" class="ac-btn ac-btn--sm">Find</button>
  </form>
</div>

<div class="ac-tabs">
  <?php
    $typeLabels = ['all' => 'All types', 'programme' => 'Programme', 'summer' => 'Summer',
                   'scholarship' => 'Scholarship', 'audition' => 'Audition'];
    foreach ($typeLabels as $k => $label):
      $n = (int) ($d['types'][$k] ?? 0);
      $href = ac_url('admissions', ['stage' => $stage, 'type' => $k === 'all' ? null : $k]); ?>
    <a class="ac-tab" href="<?= ac_h($href) ?>" <?= ($type === $k || ($type === '' && $k === 'all')) ? 'aria-current="true"' : '' ?>>
      <?= ac_h($label) ?><span class="ac-tabn"><?= $n ?></span>
    </a>
  <?php endforeach; ?>
  <span class="ac-tabnote">Summer applications are kept in their own table, so this board counts them but does not list them yet.</span>
</div>

<div class="ac-cols ac-cols--main">
  <div class="ac-card">
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead>
          <tr><th>Applicant</th><th>Type</th><th>Status</th><th class="ac-num">Waiting</th><th>Reviewer</th><th></th></tr>
        </thead>
        <tbody>
          <?php if (!($d['rows'] ?? [])): ?>
            <?php ac_table_empty(6, 'Nothing matches that filter. New applications land here within a minute of submission.'); ?>
          <?php else: foreach ($d['rows'] as $r):
            $name = trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['last_name'] ?? '')) ?: '—';
            $days = mam_console_days_since((string) $r['created_at']);
            $sel  = $detail && (int) $detail['id'] === (int) $r['id']; ?>
            <tr class="<?= $sel ? 'is-selected' : '' ?>">
              <td>
                <div class="ac-strong"><?= ac_h($name) ?></div>
                <div class="ac-sub"><?= ac_h(ac_join([
                    (string) ($r['program'] ?? ''), (string) ($r['discipline'] ?? ''),
                    $r['level'] ? 'Level ' . (string) $r['level'] : '', (string) ($r['reference'] ?? '')])) ?></div>
              </td>
              <td><?= ac_tag((string) (($r['pathway'] ?? '') === 'scholarship' ? 'scholarship' : 'programme')) ?></td>
              <td><?= ac_status((string) $r['status']) ?></td>
              <td class="ac-num">
                <?php if ($days > 5): ?><span class="ac-pill ac-pill--bad"><?= $days ?>d</span>
                <?php else: ?><?= $days ?>d<?php endif; ?>
              </td>
              <td><?= $r['assignee'] ? ac_avatar((string) $r['assignee']) : '<span class="ac-sub">—</span>' ?></td>
              <td class="ac-num">
                <a class="ac-btn ac-btn--sm" href="<?= ac_h(ac_url('admissions', ['stage' => $stage, 'type' => $type, 'app' => (int) $r['id']])) ?>">Open</a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="ac-cardfoot">
      A refusal needs its own reason, so there is no bulk decline — bulk moves forward only.
      Every attempted transition is logged, including one the rules refuse: a rejected move is
      evidence about the process.
    </div>
  </div>

  <div class="ac-stack">
    <?php if (!$detail): ?>
      <?php ac_card_open('Applicant'); ?>
        <div class="ac-cardbody"><?php ac_empty('No application selected', 'Open one from the board to see the decision panel.'); ?></div>
      <?php ac_card_close(); ?>
    <?php else:
      $name = trim((string) ($detail['first_name'] ?? '') . ' ' . (string) ($detail['last_name'] ?? '')) ?: '—';
      $days = mam_console_days_since((string) $detail['created_at']); ?>
      <?php ac_card_open($name, (string) ($detail['reference'] ?? ''),
          ac_pill($days . ' day' . ($days === 1 ? '' : 's') . ' waiting', $days > 5 ? 'bad' : '')); ?>
        <div class="ac-cardbody">
          <div class="ac-defs">
            <?php
              ac_def('Requested', ac_h(ac_join([
                  (string) ($detail['program'] ?? ''), (string) ($detail['discipline'] ?? ''),
                  $detail['level'] ? 'Level ' . (string) $detail['level'] : ''])) ?: '—');
              ac_def('Mode', ac_h((string) ($detail['mode'] ?? '—')));
              ac_def('Contact', ac_h((string) ($detail['email'] ?? '—')));
              ac_def('Guardian', ac_h((string) ($detail['parent_name'] ?? '') ?: 'Not named'));
              ac_def('Prior study', ac_h((string) ($detail['experience'] ?? '—')));
              $r = $detail['readiness'] ?? null;
              if (is_array($r)) {
                  ac_def('Readiness', ac_status((string) ($r['signal'] ?? 'review'))
                      . ' <span class="ac-subi">advisory only</span>');
              }
              ac_def('Money', $detail['payments']
                  ? ac_h((string) ($detail['payments'][0]['status'] ?? '')) . ' · '
                    . ac_h((string) ($detail['payments'][0]['currency'] ?? '')) . ' '
                    . ac_h(number_format((float) ($detail['payments'][0]['amount'] ?? 0), 2))
                  : 'No payment recorded');
            ?>
          </div>
        </div>
      <?php ac_card_close('Readiness routes and flags. It never rejects and never blocks a submission — that decision is a person\'s.'); ?>

      <?php ac_card_open('Decision'); ?>
        <div class="ac-cardbody">
          <?php ac_form('app_status', 'admissions', ['app' => (int) $detail['id']]); ?>
            <div class="ac-grid">
              <label class="ac-field">
                <span class="ac-label">Move to</span>
                <select class="ac-select" name="status">
                  <?php foreach (mam_console_status_vocabulary() as $v): ?>
                    <option value="<?= ac_h($v['stored']) ?>" <?= (string) $detail['status'] === $v['stored'] ? 'selected' : '' ?>>
                      <?= ac_h($v['shown']) ?> · <?= ac_h($v['stored']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="ac-field">
                <span class="ac-label">Reason the family can read</span>
                <textarea class="ac-textarea" name="reason" rows="3"
                  placeholder="Required for a refusal. Written to the audit log either way."></textarea>
              </label>
            </div>
            <div class="ac-row ac-mt">
              <button type="submit" class="ac-btn ac-btn--primary">Save the decision</button>
              <span class="ac-hint">The state machine refuses an illegal move and logs the attempt.</span>
            </div>
          <?php ac_form_end(); ?>
        </div>
      <?php ac_card_close(); ?>

      <?php ac_card_open('Complete the enrolment'); ?>
        <div class="ac-cardbody">
          <?php ac_form('enrol_complete', 'admissions', ['app_id' => (int) $detail['id']]); ?>
            <div class="ac-grid ac-grid--2">
              <label class="ac-field"><span class="ac-label">Course code</span>
                <input class="ac-input" name="course" placeholder="e.g. CF-202" required></label>
              <label class="ac-field"><span class="ac-label">Level code</span>
                <input class="ac-input" name="level" placeholder="e.g. L2"></label>
              <label class="ac-field"><span class="ac-label">Programme code</span>
                <input class="ac-input" name="programme" value="<?= ac_h((string) ($detail['program'] ?? '')) ?>"></label>
              <label class="ac-field"><span class="ac-label">Instructor</span>
                <input class="ac-input" name="instructor" placeholder="user id or email"></label>
            </div>
            <div class="ac-row ac-mt">
              <button type="submit" class="ac-btn">Account → course → cohort → instructor</button>
            </div>
          <?php ac_form_end(); ?>
        </div>
      <?php ac_card_close('One implementation, shared with payment settlement, so the two paths cannot place the same applicant in different classes. A settled payment never fails because the placement could not be worked out — it lands in the backlog instead.'); ?>

      <?php ac_card_open('History', count($detail['events'] ?? []) . ' transition' . (count($detail['events'] ?? []) === 1 ? '' : 's')); ?>
        <div class="ac-cardbody">
          <?php if (!($detail['events'] ?? [])): ?>
            <div class="ac-note">Nothing beyond the submission itself.</div>
          <?php else: ?>
            <div class="ac-timeline">
              <?php foreach ($detail['events'] as $e): ?>
                <div class="ac-tl">
                  <span class="ac-tlmark"><i></i><span></span></span>
                  <div class="ac-tlbody">
                    <div><?= ac_h(str_replace('_', ' ', (string) ($e['from_status'] ?? '?'))) ?>
                      → <?= ac_h(str_replace('_', ' ', (string) ($e['to_status'] ?? '?'))) ?></div>
                    <div class="ac-sub"><?= ac_h(mam_console_date((string) $e['created_at'], 'j M H:i')) ?>
                      <?= $e['actor'] ? ' · ' . ac_h((string) $e['actor']) : '' ?>
                      <?= $e['reason'] ? ' · ' . ac_h((string) $e['reason']) : '' ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php ac_card_close(); ?>
    <?php endif; ?>
  </div>
</div>
