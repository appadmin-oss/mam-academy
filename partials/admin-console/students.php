<?php
/**
 * Students — the members roster (HANDOFF §4w; NGG's roster rules, ported).
 *
 * Bands, never a sortable composite. Every filter and the sort are allow-listed
 * in the provider; "all N matching" is resolved on the server from the same
 * filter, never from the drawn page. The bulk bar works with no JavaScript: tick
 * rows, or press the "all matching" button. A run's per-row verdicts are shown
 * on the screen it returns to — a run where some were refused names them.
 */
declare(strict_types=1);
/** @var array $d */
$stage   = (string) ($d['stage'] ?? '');
$filters = $d['filters'] ?? [];
$words   = function_exists('mam_member_verdict_words') ? mam_member_verdict_words() : [];
$m       = $d['member'] ?? null;
$sel     = static fn(string $k, string $v): string => ((string) ($filters[$k] ?? '') === $v) ? ' selected' : '';
?>
<?php ac_head('Students',
    'Members of the academy · ' . (int) ($d['stages']['active'] ?? 0) . ' current',
    '<a class="ac-btn" href="' . ac_h(ac_url('students', ['onboarding' => 1])) . '">Onboarding</a>'
    . '<a class="ac-btn" href="' . ac_h(ac_url('students', ['dupes' => 1])) . '">Possible duplicates</a>'
    . '<a class="ac-btn" href="/admin/?v=export&amp;type=users.csv">Export</a>'); ?>

<div class="ac-tabs">
  <a class="ac-tab" href="<?= ac_h(ac_url('students', array_diff_key($filters, ['stage' => 1]))) ?>" <?= $stage === '' ? 'aria-current="true"' : '' ?>>All</a>
  <?php foreach (['active' => 'Current student', 'roster' => 'Roster only', 'suspended' => 'Suspended'] as $k => $label): ?>
    <a class="ac-tab" href="<?= ac_h(ac_url('students', ['stage' => $k] + $filters)) ?>" <?= $stage === $k ? 'aria-current="true"' : '' ?>>
      <?= ac_h($label) ?><span class="ac-tabn"><?= (int) ($d['stages'][$k] ?? 0) ?></span>
    </a>
  <?php endforeach; ?>
</div>

<form method="get" class="ac-row ac-mt ac-filters" role="search">
  <input type="hidden" name="v" value="students">
  <?php if ($stage !== ''): ?><input type="hidden" name="stage" value="<?= ac_h($stage) ?>"><?php endif; ?>
  <input class="ac-input ac-input--sm ac-searchbox" type="search" name="q" value="<?= ac_h($d['q'] ?? '') ?>"
         placeholder="Name, email, number or phone…" aria-label="Search members">
  <select class="ac-select ac-input--sm" name="type" aria-label="Fee type">
    <option value="">Any fee type</option>
    <option value="paying"<?= $sel('type', 'paying') ?>>Fee-paying</option>
    <option value="scholarship"<?= $sel('type', 'scholarship') ?>>Scholarship</option>
  </select>
  <select class="ac-select ac-input--sm" name="missing" aria-label="Missing">
    <option value="">Any record</option>
    <option value="number"<?= $sel('missing', 'number') ?>>No member number</option>
    <option value="photo"<?= $sel('missing', 'photo') ?>>No photo</option>
    <option value="guardian"<?= $sel('missing', 'guardian') ?>>No guardian linked</option>
    <option value="learning"<?= $sel('missing', 'learning') ?>>Not enrolled in anything</option>
    <option value="login"<?= $sel('missing', 'login') ?>>Never signed in</option>
  </select>
  <select class="ac-select ac-input--sm" name="sort" aria-label="Sort">
    <?php foreach (['name' => 'Name', 'newest' => 'Newest', 'number' => 'Member number', 'login' => 'Last signed in'] as $k => $label): ?>
      <option value="<?= $k ?>"<?= ($d['sort'] ?? 'name') === $k ? ' selected' : '' ?>><?= ac_h($label) ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="ac-btn ac-btn--sm">Show</button>
  <span class="ac-sub" role="status"><?= (int) ($d['total'] ?? 0) ?> match<?= (int) ($d['total'] ?? 0) === 1 ? '' : 'es' ?></span>
</form>

<?php if ($run = ($d['run'] ?? null)): ?>
  <?php ac_card_open('Last bulk run · ' . ac_h(($d['bulk_actions'][$run['action']]['label'] ?? $run['action'])),
      (int) $run['applied'] . ' changed · ' . (int) $run['unchanged'] . ' already so · ' . (int) $run['refused'] . ' refused'); ?>
    <?php $refused = array_values(array_filter($run['results'], fn($x) => $x['result'] === 'refused')); ?>
    <div class="ac-cardbody">
      <?php if (!$refused): ?>
        <p class="ac-note">Every member asked for was dealt with.</p>
      <?php else: ?>
        <div class="ac-defs">
          <?php foreach ($refused as $x): ?>
            <?php ac_def((string) $x['name'], ac_h($words[$x['code']] ?? $x['code'])); ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php ac_card_close('One record of this run is kept, with each member\'s outcome.'); ?>
<?php endif; ?>

<?php if (is_array($d['onboarding'] ?? null)): $labels = MAM_ONBOARDING_ITEMS; ?>
  <?php ac_card_open('Still joining', count($d['onboarding']) . ''); ?>
    <div class="ac-cardbody">
      <?php if (!$d['onboarding']): ?>
        <p class="ac-note">Every current student has everything on the onboarding list.</p>
      <?php else: ?>
        <div class="ac-defs">
          <?php foreach ($d['onboarding'] as $ob): ?>
            <?php ac_def((string) $ob['name'] . ' · ' . (int) $ob['onboarding']['done'] . ' of ' . (int) $ob['onboarding']['total'],
                '<a href="' . ac_h(ac_url('students', ['id' => (int) $ob['id']])) . '">'
                . ac_h(implode(', ', array_map(fn($k) => $labels[$k] ?? $k, $ob['onboarding']['missing']))) . '</a>'); ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php ac_card_close('Read from each record, never ticked by hand. To nudge families, tick them in the list below and choose "Remind the family what is missing" — they are told only what they can do themselves.'); ?>
<?php endif; ?>

<?php if (is_array($d['duplicates'] ?? null)): ?>
  <?php ac_card_open('Possible duplicates', count($d['duplicates']) . ''); ?>
    <div class="ac-cardbody">
      <?php if (!$d['duplicates']): ?>
        <p class="ac-note">No two student records share a name or a phone number.</p>
      <?php else: foreach ($d['duplicates'] as $g): ?>
        <h3 class="ac-label ac-mt"><?= ac_h($g['why']) ?></h3>
        <div class="ac-defs">
          <?php foreach ($g['members'] as $dm): ?>
            <?php ac_def((string) $dm['name'], '<a href="' . ac_h(ac_url('students', ['id' => (int) $dm['id']])) . '">'
                . ac_h((string) $dm['email']) . '</a> · ' . ac_h((string) ($dm['enrollment_ref'] ?: 'no number')) . ' · ' . ac_h((string) $dm['status'])); ?>
          <?php endforeach; ?>
        </div>
      <?php endforeach; endif; ?>
    </div>
  <?php ac_card_close('Shown, never merged: brothers and sisters share a parent\'s phone. Decide from the records.'); ?>
<?php endif; ?>

<?php if ($m): $u = $m['user']; $sid = (int) $u['id']; $back = ['_console_id' => $sid]; ?>
  <?php /* ── Student management: the student profile (Students → a student) ──
     One record, every action. Each form carries `_console_id` so whichever
     handler runs, the operator lands back here with its verdict. Nothing on
     this card computes a score: the band is the publishable one, the timetable
     is read-only, and safeguarding is a count shown only to a viewer with that
     capability (the provider decides; the template cannot widen it). */ ?>
  <?php ac_card_open((string) ($u['name'] ?: $u['email']), (string) ($u['enrollment_ref'] ?: 'No member number yet'),
      '<a class="ac-btn ac-btn--sm ac-btn--primary" href="' . ac_h(ac_url('roster', ['student' => $sid])) . '">Enrol in a class</a>'
      . '<a class="ac-btn ac-btn--sm" href="' . ac_h(ac_url('students', $filters)) . '">Close</a>'); ?>
    <div class="ac-cardbody ac-memgrid">
     <div>
      <div class="ac-row">
        <?= ac_avatar((string) ($u['name'] ?: $u['email']), (string) ($u['avatar_url'] ?? '')) ?>
        <div class="ac-grow">
          <div class="ac-strong"><?= ac_h((string) $u['name']) ?></div>
          <div class="ac-sub"><?= ac_h((string) ($u['enrollment_ref'] ?: 'No member number yet')) ?><?= empty($u['avatar_url']) ? ' · no photo' : '' ?></div>
        </div>
        <?php if (($m['concerns'] ?? null) !== null && (int) $m['concerns'] > 0): ?>
          <a class="ac-pill ac-pill--bad" href="<?= ac_h(ac_url('safeguarding')) ?>"><?= (int) $m['concerns'] ?> open safeguarding concern<?= (int) $m['concerns'] === 1 ? '' : 's' ?></a>
        <?php endif; ?>
      </div>
      <div class="ac-defs ac-mt">
        <?php ac_def('Email', ac_h((string) $u['email'])); ?>
        <?php ac_def('Phone', ($u['phone'] ?? '') !== '' && $u['phone'] !== null ? ac_h((string) $u['phone']) : '<span class="ac-sub">None given</span>'); ?>
        <?php ac_def('Status', ac_status((string) $u['status'])); ?>
        <?php ac_def('Fee type', ($u['student_type'] ?? '') === 'scholarship' ? ac_pill('Scholarship', 'accent') : 'Fee-paying'); ?>
        <?php ac_def('Signed in', $u['last_login'] ? ac_h(mam_console_age((string) $u['last_login'])) . ' ago' : 'Never'); ?>
      </div>

      <?php if ($m['is_staff']): ?>
        <p class="ac-note ac-mt">This person is also staff. Their details, status and sign-in are changed from
          <a href="<?= ac_h(ac_url('access')) ?>">People &amp; access</a>, not from the members list.</p>
      <?php else: ?>
        <details class="ac-mt"><summary class="ac-btn ac-btn--sm">Edit details</summary>
          <?php ac_form('user_edit', 'students', ['user_id' => $sid, 'role' => 'student'] + $back, 'class="ac-grid ac-grid--2 ac-mt-sm"'); ?>
            <label><span class="ac-label">Name</span><input class="ac-input" name="name" maxlength="120" required value="<?= ac_h((string) $u['name']) ?>"></label>
            <label><span class="ac-label">Email</span><input class="ac-input" type="email" name="email" maxlength="190" required value="<?= ac_h((string) $u['email']) ?>"></label>
            <label><span class="ac-label">Phone</span><input class="ac-input" name="phone" maxlength="40" value="<?= ac_h((string) ($u['phone'] ?? '')) ?>"></label>
            <div class="ac-row"><button type="submit" class="ac-btn ac-btn--sm">Save details</button></div>
          <?php ac_form_end(); ?>
        </details>

        <?php ac_form('member_status_set', 'students', ['user_id' => $sid] + $back, 'class="ac-row ac-mt"'); ?>
          <select class="ac-select ac-input--sm" name="status" aria-label="Status">
            <option value="active"<?= $u['status'] === 'active' ? ' selected' : '' ?>>Active</option>
            <option value="suspended"<?= $u['status'] === 'suspended' ? ' selected' : '' ?>>Suspended</option>
          </select>
          <input class="ac-input ac-input--sm ac-grow" name="reason" maxlength="500" aria-label="Reason"
                 placeholder="Why — needed to suspend, kept with the record">
          <button type="submit" class="ac-btn ac-btn--sm">Set status</button>
        <?php ac_form_end(); ?>
      <?php endif; ?>
      <div class="ac-row ac-mt">
        <?php if (!$m['is_staff']): ?>
          <?php ac_action_btn('user_reset', 'students', ['user_id' => $sid] + $back, 'Email a link to set a password',
              'ac-btn ac-btn--sm', 'Email this student a link to set a password? Their current sessions end. No password is sent.'); ?>
        <?php endif; ?>
        <?php if ((int) $m['sessions'] > 0): ?>
          <?php ac_action_btn('member_signout', 'students', ['user_id' => $sid] + $back, 'Sign out everywhere (' . (int) $m['sessions'] . ')',
              'ac-btn ac-btn--sm ac-btn--danger', 'End every session this person holds?'); ?>
        <?php endif; ?>
      </div>

      <h3 class="ac-label ac-mt" id="guardians">Guardians</h3>
      <?php if ($m['guardians']): ?>
        <div class="ac-defs">
          <?php foreach ($m['guardians'] as $g): ?>
            <div class="ac-def">
              <div><?= ac_h((string) ($g['name'] ?: $g['email'])) ?><div class="ac-sub"><?= ac_h((string) $g['email']) ?></div></div>
              <div class="ac-row">
                <?php ac_form('guardian_scope_set', 'students', ['parent_id' => (int) $g['id'], 'student_id' => $sid] + $back, 'class="ac-row"'); ?>
                  <select class="ac-select ac-input--sm" name="access_scope" aria-label="What <?= ac_h((string) $g['name']) ?> may see">
                    <?php foreach ($m['scopes'] as $sc): ?>
                      <option value="<?= ac_h($sc) ?>"<?= (string) $g['access_scope'] === $sc ? ' selected' : '' ?>><?= ac_h(ucfirst($sc)) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="ac-btn ac-btn--sm">Set</button>
                <?php ac_form_end(); ?>
                <?php ac_action_btn('guardian_unlink', 'students', ['parent_id' => (int) $g['id'], 'student_id' => $sid] + $back, 'Unlink',
                    'ac-btn ac-btn--sm ac-btn--danger', 'Unlink this guardian? Their account stays; they stop seeing this student.'); ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="ac-note">No guardian linked.</p>
      <?php endif; ?>
      <?php ac_form('guardian_link', 'students', ['student_id' => $sid] + $back, 'class="ac-row ac-mt-sm"'); ?>
        <input class="ac-input ac-input--sm ac-grow" type="email" name="parent_email" maxlength="190" required aria-label="Guardian email" placeholder="Guardian's email">
        <select class="ac-select ac-input--sm" name="relationship" aria-label="Relationship">
          <?php foreach (['guardian' => 'Guardian', 'mother' => 'Mother', 'father' => 'Father', 'other' => 'Other'] as $k => $label): ?>
            <option value="<?= $k ?>"><?= $label ?></option>
          <?php endforeach; ?>
        </select>
        <select class="ac-select ac-input--sm" name="access_scope" aria-label="What they may see">
          <?php foreach ($m['scopes'] as $sc): ?>
            <option value="<?= ac_h($sc) ?>"<?= $sc === 'standard' ? ' selected' : '' ?>><?= ac_h(ucfirst($sc)) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="ac-btn ac-btn--sm">Link guardian</button>
      <?php ac_form_end(); ?>
      <p class="ac-sub ac-mt-xs"><strong>Billing</strong>: invoices and receipts. <strong>Standard</strong>: adds reports, attendance, timetable.
        <strong>Full</strong>: adds consents. Practice logs, recordings and a student's own messages are never shown to a guardian, at any scope.
        A new guardian is emailed an invitation to set their own password.</p>

      <h3 class="ac-label ac-mt">Notes</h3>
      <?php ac_form('member_note_add', 'students', ['user_id' => $sid] + $back, 'class="ac-row"'); ?>
        <input class="ac-input ac-input--sm ac-grow" name="body" maxlength="2000" required aria-label="New note" placeholder="Something staff should know">
        <button type="submit" class="ac-btn ac-btn--sm">Add note</button>
      <?php ac_form_end(); ?>
      <?php if ($m['notes']): ?>
        <div class="ac-defs ac-mt">
          <?php foreach ($m['notes'] as $n): ?>
            <?php ac_def(substr((string) $n['created_at'], 0, 10) . ' · ' . (string) ($n['author_name'] ?? 'system'), ac_h((string) $n['body'])); ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($m['history']): ?>
        <h3 class="ac-label ac-mt">Status history</h3>
        <div class="ac-defs">
          <?php foreach ($m['history'] as $h): ?>
            <?php ac_def(substr((string) $h['created_at'], 0, 16) . ' · ' . (string) ($h['changed_by_name'] ?? 'system'),
                ac_h((string) $h['from_status']) . ' → ' . ac_h((string) $h['to_status']) . ($h['reason'] ? ' · ' . ac_h((string) $h['reason']) : '')); ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
     </div>
     <div>
      <h3 class="ac-label">Learning</h3>
      <?php if (!$m['courses']): ?>
        <p class="ac-note">Not enrolled in anything yet. <a href="<?= ac_h(ac_url('roster', ['student' => $sid])) ?>">Enrol in a class</a>.</p>
      <?php else: ?>
        <div class="ac-defs">
          <?php foreach ($m['courses'] as $c): ?>
            <?php ac_def(trim((string) $c['course'] . ' ' . (string) ($c['level'] ?? '')),
                ac_status((string) $c['status'])
                . ' <span class="ac-sub">' . ac_h(ac_join(array_filter([
                    $c['cohort'] ? (string) $c['cohort'] : 'no class yet',
                    trim((string) ($c['schedule_days'] ?? '') . ' ' . (string) ($c['schedule_time'] ?? '')),
                    $c['instructor'] ? (string) $c['instructor'] : 'no teacher yet']))) . '</span>'); ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <h3 class="ac-label ac-mt">This week · from <?= ac_h((string) $m['week_from']) ?></h3>
      <?php if (!$m['week']): ?>
        <p class="ac-note">No sessions scheduled this week.</p>
      <?php else: ?>
        <div class="ac-defs">
          <?php foreach ($m['week'] as $w): ?>
            <?php ac_def(date('D j M, H:i', strtotime((string) $w['scheduled_at'])),
                ac_h((string) ($w['title'] ?: $w['cohort'])) . ' <span class="ac-sub">' . ac_h(ac_join(array_filter([(string) $w['cohort'], (string) ($w['room'] ?? ''), (int) $w['duration_min'] . ' min']))) . '</span>'
                . ((string) $w['status'] === 'cancelled' ? ' ' . ac_status('cancelled') : '')
                . ($w['attended'] ? ' ' . ac_status((string) $w['attended']) : '')); ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <h3 class="ac-label ac-mt">Attendance</h3>
      <?php $at = $m['attendance']; ?>
      <?php if ((int) $at['total'] === 0): ?>
        <p class="ac-note">No attendance recorded yet.</p>
      <?php else: ?>
        <div class="ac-defs">
          <?php ac_def('Came', (int) $at['pct'] . '% of ' . (int) $at['total'] . ' marked'); ?>
          <?php ac_def('Breakdown', (int) $at['present'] . ' present · ' . (int) $at['late'] . ' late · ' . (int) $at['absent'] . ' absent · ' . (int) $at['excused'] . ' excused'
              . ((int) $at['legacy'] > 0 ? ' <span class="ac-sub">+ ' . (int) $at['legacy'] . ' from legacy classes</span>' : '')); ?>
        </div>
      <?php endif; ?>

      <h3 class="ac-label ac-mt">Mastery</h3>
      <?php $ms = $m['mastery']; ?>
      <?php if (is_array($ms) && is_array($ms['band'])): ?>
        <p><?= ac_pill((int) ($ms['band'][0] ?? 0) . '–' . (int) ($ms['band'][1] ?? 0)) ?> <span class="ac-sub">a band, never a single figure</span></p>
      <?php else: ?>
        <p class="ac-note"><?= is_array($ms) && ($ms['withheld_reason'] ?? '') === 'below_coverage_floor' ? 'Not enough assessed yet to state a band.' : 'Not assessed yet — a different statement from a low score.' ?></p>
      <?php endif; ?>

      <h3 class="ac-label ac-mt">Fees</h3>
      <?php if (!$m['invoices']): ?>
        <p class="ac-note">No invoices.</p>
      <?php else: ?>
        <div class="ac-defs">
          <?php foreach ($m['invoices'] as $iv): ?>
            <?php ac_def((string) ($iv['term_label'] ?: $iv['public_ref']),
                '<a href="' . ac_h(ac_url('invoices', ['id' => (int) $iv['id']])) . '">' . ac_h((string) $iv['public_ref']) . '</a> '
                . ac_status((string) $iv['status'])
                . ' <span class="ac-sub">' . ac_h((string) $iv['currency']) . ' ' . number_format(((int) $iv['total_minor'] - (int) $iv['paid_minor']) / 100, 2) . ' to pay'
                . ($iv['due_on'] ? ' · due ' . ac_h((string) $iv['due_on']) : '') . '</span>'); ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($m['onboarding'])): $ob = $m['onboarding']; ?>
        <h3 class="ac-label ac-mt">Onboarding · <?= (int) $ob['done'] ?> of <?= (int) $ob['total'] ?></h3>
        <div class="ac-defs">
          <?php foreach ($ob['items'] as $it): ?>
            <?php ac_def((string) $it['label'], ($it['done'] ? ac_status('recorded', 'Done') : ac_pill('To do', 'warn')) . ($it['hint'] ? ' <span class="ac-sub">' . ac_h((string) $it['hint']) . '</span>' : '')); ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
     </div>
    </div>
  <?php ac_card_close('Notes and status history are kept; nothing here is edited after the fact. The timetable is read-only here — sessions are scheduled from Timetable.'); ?>
  <?php /* ── end student management ── */ ?>
<?php endif; ?>

<?php ac_form('member_bulk', 'students', $filters); ?>
<?php ac_card_open('', ''); ?>
  <div class="ac-cardbody ac-row ac-bulkbar" data-ac-bulkbar="ids[]">
    <select class="ac-select ac-input--sm" name="bulk_action" required aria-label="Bulk action">
      <option value="">Do this…</option>
      <?php foreach (($d['bulk_actions'] ?? []) as $k => $a): ?><option value="<?= ac_h($k) ?>"><?= ac_h($a['label']) ?></option><?php endforeach; ?>
    </select>
    <input class="ac-input ac-input--sm ac-grow" name="reason" maxlength="500" aria-label="Reason for the bulk action"
           placeholder="Reason — needed to suspend, kept on each record">
    <button type="submit" class="ac-btn ac-btn--sm" data-ac-needs-tick="ids[]">To the ticked members</button>
    <?php if ((int) ($d['total'] ?? 0) > 0): ?>
      <button type="submit" class="ac-btn ac-btn--sm" name="all_matching" value="1"
              <?= (int) $d['total'] > (int) $d['bulk_max'] ? 'disabled' : '' ?>>To all <?= (int) $d['total'] ?> matching</button>
    <?php endif; ?>
    <?php if ((int) ($d['total'] ?? 0) > (int) ($d['bulk_max'] ?? 500)): ?>
      <span class="ac-sub">At most <?= (int) $d['bulk_max'] ?> at once — narrow the filter.</span>
    <?php endif; ?>
  </div>
  <div class="ac-tablewrap">
    <table class="ac-table">
      <thead>
        <tr><th><input type="checkbox" data-ac-checkall="ids[]" aria-label="Tick every member on this page"></th>
            <th>Number</th><th>Name</th><th class="ac-num">Learning</th><th class="ac-num">Attendance</th>
            <th>Band</th><th class="ac-num">Owed</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!($d['rows'] ?? [])): ?>
          <?php ac_table_empty(8, 'No students match. A student account is created at provisioning, from a settled payment or by hand.'); ?>
        <?php else: foreach ($d['rows'] as $r): ?>
          <tr>
            <td><input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" aria-label="Tick <?= ac_h((string) $r['name']) ?>"></td>
            <td class="ac-mono"><?= ac_h((string) ($r['enrollment_ref'] ?: '—')) ?></td>
            <td>
              <div class="ac-row">
                <?= ac_avatar((string) $r['name'], (string) ($r['avatar_url'] ?? '')) ?>
                <div class="ac-grow">
                  <div class="ac-strong"><?= ac_h((string) $r['name']) ?>
                    <?php if ((string) ($r['student_type'] ?? '') === 'scholarship'): ?><?= ac_pill('Scholar', 'accent') ?><?php endif; ?>
                    <?php if ((string) $r['status'] !== 'active'): ?><?= ac_status((string) $r['status']) ?><?php endif; ?>
                  </div>
                  <div class="ac-sub"><?= ac_h((string) $r['email']) ?><?= (int) $r['guardians'] === 0 ? ' · no guardian' : '' ?>
                    <?php if (!empty($r['onboarding']) && !$r['onboarding']['complete']): ?> · onboarding <?= (int) $r['onboarding']['done'] ?>/<?= (int) $r['onboarding']['total'] ?><?php endif; ?></div>
                </div>
              </div>
            </td>
            <td class="ac-num">
              <?= (int) $r['courses'] ?> course<?= (int) $r['courses'] === 1 ? '' : 's' ?>
              <?php if ((int) ($r['courses_pending'] ?? 0) > 0): /* Enrol & staffing counts these as enrolled-awaiting-approval; saying 0 here contradicted it */ ?>
                <div class="ac-sub"><?= (int) $r['courses_pending'] ?> awaiting approval</div>
              <?php endif; ?>
            </td>
            <td class="ac-num"><?= $r['attendance'] !== null ? (int) $r['attendance'] . '%' : '<span class="ac-sub">—</span>' ?></td>
            <td>
              <?php if (is_array($r['band'] ?? null)): ?>
                <?= ac_pill((int) ($r['band'][0] ?? 0) . '–' . (int) ($r['band'][1] ?? 0)) ?>
              <?php else: ?>
                <span class="ac-sub">Not enough yet</span>
              <?php endif; ?>
            </td>
            <td class="ac-num">
              <?php $owed = (int) ($r['owed'] ?? 0); ?>
              <?php if ($owed > 0): ?><span class="ac-pill ac-pill--bad"><?= ac_h(mam_console_usd($owed)) ?></span>
              <?php else: ?><span class="ac-sub">—</span><?php endif; ?>
            </td>
            <td class="ac-num">
              <a class="ac-btn ac-btn--sm" href="<?= ac_h(ac_url('students', ['id' => (int) $r['id']] + $filters + ['page' => (int) $d['page']])) ?>">Record</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
<?php ac_card_close(
    'A <strong>band</strong> rather than a computed score, and never a sort: sorting a cohort of children by a '
    . 'composite turns the table into a ranking whatever the heading says. "Not enough yet" is a different statement '
    . 'from a low score. <strong>Learning</strong> counts both enrolment models. A staff member on this list is '
    . 'refused by every bulk action — staff are administered from People &amp; access.'); ?>
<?php ac_form_end(); ?>

<?php if ((int) ($d['pages'] ?? 1) > 1): ?>
  <nav class="ac-row ac-mt" aria-label="Pages">
    <?php if ((int) $d['page'] > 1): ?>
      <a class="ac-btn ac-btn--sm" href="<?= ac_h(ac_url('students', $filters + ['page' => (int) $d['page'] - 1])) ?>">Previous</a>
    <?php endif; ?>
    <span class="ac-sub">Page <?= (int) $d['page'] ?> of <?= (int) $d['pages'] ?></span>
    <?php if ((int) $d['page'] < (int) $d['pages']): ?>
      <a class="ac-btn ac-btn--sm" href="<?= ac_h(ac_url('students', $filters + ['page' => (int) $d['page'] + 1])) ?>">Next</a>
    <?php endif; ?>
  </nav>
<?php endif; ?>
