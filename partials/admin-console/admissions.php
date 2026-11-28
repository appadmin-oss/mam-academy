<?php
/** Admissions — one board across every intake type, with the applicant card beside it. */
declare(strict_types=1);
/** @var array $d */
$detail = $d['detail'] ?? null;
$stage  = (string) ($d['stage'] ?? '');
$type   = (string) ($d['type'] ?? '');
$tc     = $d['types'] ?? [];
?>
<?php ac_head('Applications',
    'Year-round, summer and the MAM Scholars Initiative · '
    . ((int) ($tc['programme'] ?? 0) + (int) ($tc['summer'] ?? 0) + (int) ($tc['scholarship'] ?? 0)) . ' applications',
    '<a class="ac-btn" href="' . ac_h(ac_url('admissions', ['stage' => 'submitted'])) . '">Needs a first look</a>'
    . '<a class="ac-btn" href="' . ac_h(ac_url('funnel')) . '">Where they stop</a>'); ?>

<?php if ((int) ($d['stale'] ?? 0) > 0): ?>
  <?php ac_banner('warn', 'fa-clock',
    '<strong>' . (int) $d['stale'] . ' past the five-day promise.</strong> That is a staffing figure, not an '
    . 'applicant-quality figure — the applicants have not got worse, the queue has got longer.',
    '<a class="ac-btn ac-btn--sm" href="' . ac_h(ac_url('admissions', ['stage' => 'submitted'])) . '">Open them</a>'); ?>
<?php endif; ?>

<?php if ($type === '' || $type === 'assess'): ?>
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
    <input class="ac-input ac-input--sm ac-searchbox" type="search" name="q" aria-label="Search applications by name or reference"
           value="<?= ac_h($d['q'] ?? '') ?>" placeholder="Name or reference…">
    <button type="submit" class="ac-btn ac-btn--sm">Find</button>
  </form>
</div>
<?php endif; ?>

<div class="ac-tabs">
  <?php
    /* The intakes. "Needs assessment" replaced an Audition filter that every
       application matched: /apply sends auditer=true for everybody, because
       every applicant books the assessment. */
    $typeLabels = ['' => ['Year-round', 'programme'], 'assess' => ['Needs assessment', 'assess'],
                   'summer' => ['Summer', 'summer'], 'scholarship' => ['Scholars Initiative', 'scholarship']];
    foreach ($typeLabels as $k => [$label, $countKey]):
      $href = ac_url('admissions', ['type' => $k]); ?>
    <a class="ac-tab" href="<?= ac_h($href) ?>" <?= $type === $k ? 'aria-current="true"' : '' ?>>
      <?= ac_h($label) ?><span class="ac-tabn"><?= (int) ($tc[$countKey] ?? 0) ?></span>
    </a>
  <?php endforeach; ?>
  <?php if ($type === 'summer' || $type === 'scholarship'): ?>
    <form method="get" class="ac-row ac-push">
      <input type="hidden" name="v" value="admissions">
      <input type="hidden" name="type" value="<?= ac_h($type) ?>">
      <input class="ac-input ac-input--sm ac-searchbox" type="search" name="q" aria-label="Search by name, email or reference"
             value="<?= ac_h($d['q'] ?? '') ?>" placeholder="Name or reference…">
      <button type="submit" class="ac-btn ac-btn--sm">Find</button>
    </form>
  <?php endif; ?>
</div>

<?php if ($type === 'summer'): include __DIR__ . '/_admissions-summer.php'; return; endif; ?>
<?php if ($type === 'scholarship'): include __DIR__ . '/_admissions-scholarship.php'; return; endif; ?>

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
              <td><?= ac_tag(['standard' => 'Standard', 'certified-musician' => 'Certified', 'grand-musician' => 'Grand',
                                 'crash' => 'Crash', 'meta-order' => 'MetaMusic'][(string) ($r['pathway'] ?? '')] ?? 'Year-round') ?>
                <?php if ((int) ($r['needs_assessment'] ?? 0) === 1): ?><?= ac_pill('Assess', 'warn') ?><?php endif; ?>
                <?php if ((string) ($r['identity_hold'] ?? '') !== ''): ?><?= ac_pill('Held', 'bad') ?><?php endif; ?></td>
              <td><?= ac_status((string) $r['status'], mam_console_status_label((string) $r['status'])) ?></td>
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
                  $detail['level'] ? 'Level ' . (string) $detail['level'] : 'Level: set at the assessment'])) ?: '—');
              if ((string) ($detail['courses_label'] ?? '') !== '') ac_def('Disciplines', ac_h((string) $detail['courses_label']));
              if (!empty($detail['assessment'])) ac_def('Assessment', ac_pill('Needs assessment', 'warn') . ' <span class="ac-subi">' . ac_h((string) $detail['assessment']) . '</span>');
              ac_def('Mode', ac_h((string) ($detail['mode'] ?? '—')));
              $ownEmail = (string) ($detail['email'] ?? '');
              ac_def('Learner email', ac_h(mam_email_is_placeholder($ownEmail) ? 'None of their own' : ($ownEmail ?: '—')));
              ac_def('Guardian', ac_h(ac_join([(string) ($detail['parent_name'] ?? ''), (string) ($detail['parent_email'] ?? '')]) ?: 'Not named'));
              if (!empty($detail['contact']['email'])) ac_def('We write to', ac_h((string) $detail['contact']['email']) . ($detail['contact']['guardian'] ? ' <span class="ac-subi">the guardian</span>' : ''));
              $tri = static fn($v) => $v === null || $v === '' ? 'Not answered' : ((int) $v === 1 ? 'Yes' : 'No');
              ac_def('Photos · recordings', ac_h($tri($detail['photo_consent'] ?? null) . ' · ' . $tri($detail['media_consent'] ?? null)));
              ac_def('Prior study', ac_h((string) ($detail['experience'] ?? '—')));
              $r = $detail['readiness'] ?? null;
              if (is_array($r)) {
                  ac_def('Readiness', ac_status((string) ($r['signal'] ?? 'review'))
                      . ' <span class="ac-subi">advisory only</span>');
              }
              // Words, not the stored code ("pending_bank_transfer").
              ac_def('Money', $detail['payments']
                  ? ac_h(mam_console_payment_status_label((string) ($detail['payments'][0]['status'] ?? ''), (string) ($detail['payments'][0]['purpose'] ?? ''))) . ' · '
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
                <?php
                  // Offer only the moves the state machine allows from here, so a
                  // choice that would be refused is never on the list. `expired`
                  // is the sweep's to set, not a person's. Paid and Enrolled are
                  // not decisions (decisions.php): they made an account and an ID
                  // card with no course. Money is recorded when it arrives, and a
                  // student is enrolled with Complete the enrolment below.
                  $cur = (string) $detail['status'];
                  $legal = array_values(array_intersect(mam_admissions_transitions()[$cur] ?? [], MAM_ADMISSIONS_DECISIONS));
                ?>
                <?php if (!$legal): ?>
                  <span class="ac-sub">Final — <?= ac_h(mam_console_status_label($cur)) ?>. Nothing further to decide.</span>
                <?php else: ?>
                <select class="ac-select" name="status" required>
                  <option value="">Choose the next step…</option>
                  <?php foreach (mam_console_status_vocabulary() as $v): if (!in_array($v['stored'], $legal, true)) continue; ?>
                    <option value="<?= ac_h($v['stored']) ?>"><?= ac_h($v['shown']) ?></option>
                  <?php endforeach; ?>
                </select>
                <?php endif; ?>
              </label>
              <label class="ac-field">
                <span class="ac-label">Reason the family can read</span>
                <textarea class="ac-textarea" name="reason" rows="3" maxlength="255"
                  placeholder="Required for “Not this intake” — it is emailed to the family in these words. Kept in the history either way."></textarea>
              </label>
            </div>
            <div class="ac-row ac-mt">
              <button type="submit" class="ac-btn ac-btn--primary">Save the decision</button>
              <span class="ac-hint">Now: <?= ac_h(mam_console_status_label((string) $detail['status'])) ?>. An offer, a waiting-list place or a refusal is emailed to <?= ac_h(!empty($detail['contact']['guardian']) ? 'the guardian' : 'the applicant') ?>.</span>
            </div>
          <?php ac_form_end(); ?>
        </div>
      <?php ac_card_close(); ?>

      <?php /* ── Student management: owner, Auditer placement, confirmation copy ──
         Three forms on the existing handlers (app_assign, record_placement,
         resend_confirmation). Each re-checks; these only offer the choices. */ ?>
      <?php ac_card_open('Owner and follow-up', $detail['assignee'] ? 'With ' . (string) $detail['assignee'] : 'Nobody owns this yet'); ?>
        <div class="ac-cardbody">
          <?php ac_form('app_assign', 'admissions', ['app_id' => (int) $detail['id']], 'class="ac-row"'); ?>
            <select class="ac-select ac-input--sm ac-grow" name="assignee_id" aria-label="Assign this application to">
              <option value="0">Nobody</option>
              <?php foreach ($detail['staff'] ?? [] as $st): ?>
                <option value="<?= (int) $st['id'] ?>"<?= (int) ($detail['assignee_id'] ?? 0) === (int) $st['id'] ? ' selected' : '' ?>><?= ac_h((string) $st['name']) ?> · <?= ac_h((string) $st['role']) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="ac-btn ac-btn--sm">Assign</button>
          <?php ac_form_end(); ?>

          <h3 class="ac-label ac-mt">Record an Auditer placement</h3>
          <?php if (!empty($detail['auditer'])): ?>
            <p class="ac-sub">Last placement on file: <?= ac_h((string) ($detail['auditer']['recommended_pathway'] ?? '')) ?>
              · <?= ac_h(mam_console_date((string) $detail['auditer']['audited_at'], 'j M Y')) ?></p>
          <?php endif; ?>
          <?php ac_form('record_placement', 'admissions', ['app_id' => (int) $detail['id']], 'class="ac-grid ac-grid--2"'); ?>
            <label class="ac-field"><span class="ac-label">Course</span>
              <select class="ac-select" name="course_code" required>
                <option value="">Choose…</option>
                <?php foreach ($detail['courses'] ?? [] as $c): ?><option value="<?= ac_h((string) $c['code']) ?>"><?= ac_h((string) $c['name']) ?></option><?php endforeach; ?>
              </select></label>
            <label class="ac-field"><span class="ac-label">Level</span>
              <select class="ac-select" name="level_code" required>
                <option value="">Choose…</option>
                <?php foreach ($detail['levels'] ?? [] as $l): ?><option value="<?= ac_h((string) $l['code']) ?>"><?= ac_h((string) $l['name']) ?></option><?php endforeach; ?>
              </select></label>
            <label class="ac-field"><span class="ac-label">Notes</span>
              <input class="ac-input" name="notes" maxlength="1000" placeholder="What the audition showed"></label>
            <div class="ac-row"><button type="submit" class="ac-btn ac-btn--sm">Record placement</button></div>
          <?php ac_form_end(); ?>

          <div class="ac-row ac-mt">
            <?php ac_action_btn('resend_confirmation', 'admissions', ['app_id' => (int) $detail['id']], 'Email the applicant a copy of their application',
                'ac-btn ac-btn--sm', 'Email the applicant a copy of their application?'); ?>
          </div>
        </div>
      <?php ac_card_close('With the Auditer automation on, recording a placement also opens the student account and enrols them at that level; with it off, the placement is recorded and nothing else happens. The confirmation says which.'); ?>
      <?php /* ── end student management ── */ ?>

      <?php ac_card_open('Complete the enrolment'); ?>
        <div class="ac-cardbody">
          <?php ac_form('enrol_complete', 'admissions', ['app_id' => (int) $detail['id']]); ?>
            <?php
              $rz = $detail['resolved'] ?? null;
              $pick = static fn($k) => (string) ($rz[$k]['code'] ?? '');
              $pickInstr = (int) ($rz['instructor']['id'] ?? 0);
            ?>
            <?php $identityBlock = false; foreach ($rz['blockers'] ?? [] as $bk): if (is_array($bk) && ($bk['field'] ?? '') === 'identity') $identityBlock = true; ?>
              <p class="ac-sub">⚠ <?= ac_h(is_array($bk) ? (string) ($bk['why'] ?? '') : (string) $bk) ?></p>
            <?php endforeach; ?>
            <?php if ($identityBlock): ?>
              <label class="ac-field"><span class="ac-label"><input type="checkbox" name="identity_confirmed" value="1"> I have checked: this application is for the same person who already holds this email</span></label>
            <?php endif; ?>
            <div class="ac-grid ac-grid--2">
              <label class="ac-field"><span class="ac-label">Course</span>
                <select class="ac-select" name="course" required>
                  <option value="">Choose…</option>
                  <?php foreach ($detail['courses'] ?? [] as $c): ?><option value="<?= ac_h((string) $c['code']) ?>"<?= $pick('course') === (string) $c['code'] ? ' selected' : '' ?>><?= ac_h((string) $c['name']) ?></option><?php endforeach; ?>
                </select></label>
              <label class="ac-field"><span class="ac-label">Level</span>
                <select class="ac-select" name="level">
                  <option value="">No level yet</option>
                  <?php foreach ($detail['levels'] ?? [] as $l): ?><option value="<?= ac_h((string) $l['code']) ?>"<?= $pick('level') === (string) $l['code'] ? ' selected' : '' ?>><?= ac_h((string) $l['name']) ?></option><?php endforeach; ?>
                </select></label>
              <label class="ac-field"><span class="ac-label">Programme</span>
                <select class="ac-select" name="programme">
                  <?php /* Nothing preselected unless the application decides it — a
                           browser otherwise selects the FIRST option, which put adults
                           into KTAMS (the under-16 programme). */ ?>
                  <option value=""<?= $pick('programme') === '' ? ' selected' : '' ?>>No programme — choose one if it applies</option>
                  <?php foreach ($detail['programmes'] ?? [] as $pg): ?><option value="<?= ac_h((string) $pg['code']) ?>"<?= $pick('programme') === (string) $pg['code'] ? ' selected' : '' ?>><?= ac_h((string) $pg['name']) ?></option><?php endforeach; ?>
                </select></label>
              <label class="ac-field"><span class="ac-label">Instructor</span>
                <select class="ac-select" name="instructor">
                  <option value="">The class's usual teacher</option>
                  <?php foreach ($detail['staff'] ?? [] as $st): if (($st['role'] ?? '') !== 'instructor') continue; ?><option value="<?= (int) $st['id'] ?>"<?= $pickInstr === (int) $st['id'] ? ' selected' : '' ?>><?= ac_h((string) $st['name']) ?></option><?php endforeach; ?>
                </select></label>
            </div>
            <p class="ac-hint">Filled in from the application. Change anything that is wrong, then confirm.</p>
            <?php
              /* The confirmation step (UX quick win 11): what is about to happen,
                 with the money first. Enrolling somebody who has not paid is
                 sometimes right, but it must never be done by accident. */
              $payRows = $detail['payments'] ?? [];
              $paid = array_values(array_filter($payRows, fn($p) => in_array((string) ($p['status'] ?? ''), ['succeeded', 'paid'], true)));
              $latestWords = $payRows ? mam_console_payment_status_label((string) ($payRows[0]['status'] ?? ''), (string) ($payRows[0]['purpose'] ?? '')) : '';
            ?>
            <div class="ac-banner <?= $paid ? 'ac-banner--good' : 'ac-banner--warn' ?> ac-mt">
              <i class="fa-solid <?= $paid ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
              <div>
                <strong><?= $paid
                    ? 'Paid: ' . ac_h((string) ($paid[0]['currency'] ?? '')) . ' ' . ac_h(number_format((float) ($paid[0]['amount'] ?? 0), 2)) . ' (' . ac_h((string) ($paid[0]['reference'] ?? '')) . ')'
                    : ($payRows ? 'No successful payment recorded — latest: ' . ac_h($latestWords) : 'No payment recorded') ?></strong>
                <div>Class: <?= ac_h(trim(($rz['course']['name'] ?? 'the course chosen above') . ' · ' . ($rz['level']['name'] ?? 'no level') . ' · ' . ($rz['programme']['name'] ?? 'no programme'))) ?><?= isset($rz['cohort']['title']) ? ' — ' . ac_h((string) $rz['cohort']['title']) : '' ?></div>
              </div>
            </div>
            <?php if (empty($detail['fee_settled'])): ?>
              <?php /* The $5 registration is unpaid: enrolling is a recorded WAIVER,
                       with a reason (admin/index.php enrol_complete refuses without). */ ?>
              <label class="ac-field ac-mt"><span class="ac-label"><input type="checkbox" name="waive_fee" value="1">
                Waive the registration fee — it has not been paid</span></label>
              <label class="ac-field"><span class="ac-label">Why it is waived (kept on the application)</span>
                <input class="ac-input" name="waiver_reason" maxlength="500" placeholder="For example: MAM Scholar, fee covered by the award"></label>
            <?php elseif (!empty($detail['fee_waiver'])): ?>
              <p class="ac-sub ac-mt">Registration fee waived: <?= ac_h((string) (json_decode((string) $detail['fee_waiver'], true)['reason'] ?? '')) ?></p>
            <?php endif; ?>
            <label class="ac-field ac-mt"><span class="ac-label"><input type="checkbox" name="confirm_enrol" value="1" required>
              I have checked the payment status and the class above</span></label>
            <div class="ac-row ac-mt">
              <button type="submit" class="ac-btn ac-btn--primary">Enrol this student</button>
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
