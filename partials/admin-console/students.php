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

<?php if ($m): $u = $m['user']; ?>
  <?php ac_card_open((string) ($u['name'] ?: $u['email']), (string) ($u['enrollment_ref'] ?: 'No member number yet'),
      '<a class="ac-btn ac-btn--sm" href="' . ac_h(ac_url('students', $filters)) . '">Close</a>'); ?>
    <div class="ac-cardbody ac-memgrid">
     <div>
      <div class="ac-defs">
        <?php ac_def('Email', ac_h((string) $u['email'])); ?>
        <?php ac_def('Status', ac_status((string) $u['status'])); ?>
        <?php ac_def('Fee type', ($u['student_type'] ?? '') === 'scholarship' ? ac_pill('Scholarship', 'accent') : 'Fee-paying'); ?>
        <?php ac_def('Signed in', $u['last_login'] ? ac_h(mam_console_age((string) $u['last_login'])) . ' ago' : 'Never'); ?>
        <?php ac_def('Guardians', $m['guardians'] ? ac_h(implode(', ', array_map(fn($g) => $g['name'] . ' (' . $g['access_scope'] . ')', $m['guardians']))) : 'None linked'); ?>
      </div>
      <?php if ($m['is_staff']): ?>
        <p class="ac-note ac-mt">This person is also staff. Their status is changed from People &amp; access, not from the members list.</p>
      <?php else: ?>
        <?php ac_form('member_status_set', 'students', ['user_id' => (int) $u['id']], 'class="ac-row ac-mt"'); ?>
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
        <?php if ((int) $m['sessions'] > 0): ?>
          <?php ac_action_btn('member_signout', 'students', ['user_id' => (int) $u['id']], 'Sign out everywhere (' . (int) $m['sessions'] . ')',
              'ac-btn ac-btn--sm ac-btn--danger', 'End every session this person holds?'); ?>
        <?php endif; ?>
        <a class="ac-btn ac-btn--sm" href="/admin/?v=users&amp;edit=<?= (int) $u['id'] ?>">Edit details</a>
        <a class="ac-btn ac-btn--sm" href="/admin/?v=audit&amp;user=<?= (int) $u['id'] ?>">Full history</a>
      </div>

      <h3 class="ac-label ac-mt">Notes</h3>
      <?php ac_form('member_note_add', 'students', ['user_id' => (int) $u['id']], 'class="ac-row"'); ?>
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
      <?php if (!empty($m['onboarding'])): $ob = $m['onboarding']; ?>
        <h3 class="ac-label">Onboarding · <?= (int) $ob['done'] ?> of <?= (int) $ob['total'] ?></h3>
        <div class="ac-defs">
          <?php foreach ($ob['items'] as $it): ?>
            <?php ac_def((string) $it['label'], ($it['done'] ? ac_status('recorded', 'Done') : ac_pill('To do', 'warn')) . ($it['hint'] ? ' <span class="ac-sub">' . ac_h((string) $it['hint']) . '</span>' : '')); ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
     </div>
    </div>
  <?php ac_card_close('Notes and status history are kept; nothing here is edited after the fact.'); ?>
<?php endif; ?>

<?php ac_form('member_bulk', 'students', $filters); ?>
<?php ac_card_open('', '', ''); ?>
  <div class="ac-cardbody ac-row">
    <select class="ac-select ac-input--sm" name="bulk_action" required aria-label="Bulk action">
      <option value="">Do this…</option>
      <?php foreach (($d['bulk_actions'] ?? []) as $k => $a): ?><option value="<?= ac_h($k) ?>"><?= ac_h($a['label']) ?></option><?php endforeach; ?>
    </select>
    <input class="ac-input ac-input--sm ac-grow" name="reason" maxlength="500" aria-label="Reason for the bulk action"
           placeholder="Reason — needed to suspend, kept on each record">
    <button type="submit" class="ac-btn ac-btn--sm">To the ticked members</button>
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
              <?php if ((int) $r['classes'] > 0): ?>
                <div class="ac-sub"><?= (int) $r['classes'] ?> legacy class<?= (int) $r['classes'] === 1 ? '' : 'es' ?></div>
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
