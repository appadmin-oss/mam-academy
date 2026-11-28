<?php
/**
 * Faculty applications — who wants to teach. Teaching requests (from the
 * contact form) waiting for a yes, invitations out, and instructor applications
 * with the panel's detail. Every button posts to an existing handler in
 * admin/index.php; this screen only offers the choices (admissions fix 4).
 */
declare(strict_types=1);
/** @var array $d */
$fd = $d['detail'] ?? null;
$cnt = $d['counts'] ?? [];
$facLabel = static fn(string $s): string => mam_faculty_app_status_label($s);
?>
<?php ac_head('Faculty applications',
    count($d['requests'] ?? []) . ' teaching request' . (count($d['requests'] ?? []) === 1 ? '' : 's') . ' waiting · '
    . ((int) ($cnt['submitted'] ?? 0) + (int) ($cnt['reviewing'] ?? 0)) . ' application(s) with the panel'); ?>

<div class="ac-cols ac-cols--2">
  <?php ac_card_open('Teaching requests', 'Somebody asked to teach — nothing has been sent yet'); ?>
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead><tr><th>Who</th><th>Asked</th><th></th></tr></thead>
        <tbody>
          <?php if (!($d['requests'] ?? [])): ?>
            <?php ac_table_empty(3, 'No requests waiting. They arrive from the contact form when somebody says they would like to teach.'); ?>
          <?php else: foreach ($d['requests'] as $r): ?>
            <tr>
              <td><div class="ac-strong"><?= ac_h((string) ($r['name'] ?? '') ?: (string) $r['email']) ?></div>
                  <div class="ac-sub"><?= ac_h(ac_join([(string) $r['email'], (string) ($r['source'] ?? '')])) ?></div></td>
              <td><?= ac_h(mam_console_date((string) $r['created_at'], 'j M')) ?></td>
              <td class="ac-num">
                <div class="ac-row">
                  <?php ac_action_btn('faculty_request_review', 'faculty', ['invite_id' => (int) $r['id'], 'decision' => 'approve'],
                      'Approve and send the form', 'ac-btn ac-btn--sm ac-btn--primary', 'Email ' . (string) $r['email'] . ' a single-use application form?'); ?>
                  <?php ac_action_btn('faculty_request_review', 'faculty', ['invite_id' => (int) $r['id'], 'decision' => 'dismiss'],
                      'Dismiss', 'ac-btn ac-btn--sm', 'Dismiss this request? Nothing is emailed.'); ?>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php ac_card_close('Approving emails a personal application form that works once and expires in ' . MAM_FACULTY_INVITE_DAYS . ' days.'); ?>

  <?php ac_card_open('Invite somebody directly'); ?>
    <div class="ac-cardbody">
      <?php ac_form('faculty_invite', 'faculty', [], 'class="ac-row"'); ?>
        <input class="ac-input ac-grow" type="email" name="email" required maxlength="190" aria-label="Their email address" placeholder="their@email.com">
        <button type="submit" class="ac-btn ac-btn--sm">Send the form</button>
      <?php ac_form_end(); ?>
      <?php if ($d['invited'] ?? []): ?>
        <h3 class="ac-label ac-mt">Forms out, not yet returned</h3>
        <ul class="ac-list">
          <?php foreach ($d['invited'] as $i): ?>
            <li><?= ac_h((string) ($i['name'] ?? '') ?: (string) $i['email']) ?>
              <span class="ac-sub"><?= ac_h(ac_join([(string) $i['email'], 'expires ' . mam_console_date((string) $i['expires_at'], 'j M')])) ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  <?php ac_card_close(); ?>
</div>

<div class="ac-cols ac-cols--main">
  <div class="ac-card">
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead><tr><th>Candidate</th><th>Teaches</th><th>Status</th><th class="ac-num">Waiting</th><th></th></tr></thead>
        <tbody>
          <?php if (!($d['apps'] ?? [])): ?>
            <?php ac_table_empty(5, 'No instructor applications yet.'); ?>
          <?php else: foreach ($d['apps'] as $a):
            $sel = $fd && (int) $fd['id'] === (int) $a['id']; ?>
            <tr class="<?= $sel ? 'is-selected' : '' ?>">
              <td><div class="ac-strong"><?= ac_h((string) ($a['full_name'] ?? '') ?: (string) $a['email']) ?></div>
                  <div class="ac-sub"><?= ac_h(ac_join(['FAC-' . (int) $a['id'], (string) $a['email'], (string) ($a['location'] ?? '')])) ?></div></td>
              <td><?= ac_h(ac_join([(string) ($a['disciplines'] ?? ''), $a['experience_years'] !== null ? (int) $a['experience_years'] . ' yrs' : ''])) ?: '—' ?></td>
              <td><?= ac_status((string) $a['status'], $facLabel((string) $a['status'])) ?></td>
              <td class="ac-num"><?= mam_console_days_since((string) $a['created_at']) ?>d</td>
              <td class="ac-num"><a class="ac-btn ac-btn--sm" href="<?= ac_h(ac_url('faculty', ['app' => (int) $a['id']])) ?>">Open</a></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="ac-cardfoot">Approving gives the candidate an instructor account and an invitation to set its password. It does not put them in front of a class: the safeguarding checks under Staff &amp; vetting still decide who may teach children.</div>
  </div>

  <div class="ac-stack">
    <?php if (!$fd): ?>
      <?php ac_card_open('Candidate'); ?>
        <div class="ac-cardbody"><?php ac_empty('No application selected', 'Open one from the list.'); ?></div>
      <?php ac_card_close(); ?>
    <?php else: ?>
      <?php ac_card_open((string) ($fd['full_name'] ?? '') ?: (string) $fd['email'], 'FAC-' . (int) $fd['id'],
          ac_status((string) $fd['status'], $facLabel((string) $fd['status']))); ?>
        <div class="ac-cardbody">
          <div class="ac-defs">
            <?php
              ac_def('Email', ac_h((string) $fd['email']));
              ac_def('Phone', ac_h((string) ($fd['phone'] ?? '') ?: '—'));
              ac_def('Location', ac_h((string) ($fd['location'] ?? '') ?: '—'));
              ac_def('Qualifications', ac_h((string) ($fd['qualifications'] ?? '') ?: '—'));
              ac_def('Hours · start', ac_h(ac_join([$fd['weekly_hours'] !== null ? (int) $fd['weekly_hours'] . ' h/week' : '', (string) ($fd['earliest_start'] ?? '')])) ?: '—');
              ac_def('Safeguarding', ac_h((int) ($fd['safeguarding_ack'] ?? 0) === 1 ? 'Agreed to the child-protection policy' : 'Not agreed'));
              ac_def('Account', ac_h($fd['account'] ? 'Has an account (' . (string) $fd['account']['status'] . ')' : 'No account yet'));
            ?>
          </div>
          <?php if ($fd['subjects'] ?? []): ?>
            <h3 class="ac-label ac-mt">What they can teach</h3>
            <ul class="ac-list">
              <?php foreach ($fd['subjects'] as $sj): ?>
                <li><?= ac_h(ac_join([(string) $sj['course_name'], (string) ($sj['level_name'] ?? 'any level'), $sj['years'] !== null ? (int) $sj['years'] . ' yrs' : ''])) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <?php if ($fd['referees'] ?? []): ?>
            <h3 class="ac-label ac-mt">Referees to take up</h3>
            <ul class="ac-list">
              <?php foreach ($fd['referees'] as $rf): ?>
                <li><?= ac_h(ac_join([(string) $rf['name'], (string) ($rf['relationship'] ?? ''), (string) ($rf['organisation'] ?? ''), (string) ($rf['email'] ?? ''), (string) ($rf['phone'] ?? '')])) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <?php if ((string) ($fd['bio'] ?? '') !== ''): ?>
            <h3 class="ac-label ac-mt">In their words</h3>
            <p class="ac-note"><?= nl2br(ac_h(mb_substr((string) $fd['bio'], 0, 2000))) ?></p>
          <?php endif; ?>
        </div>
      <?php ac_card_close(); ?>

      <?php ac_card_open('Decision'); ?>
        <div class="ac-cardbody">
          <?php if (!$fd['legal']): ?>
            <p class="ac-sub">Nothing further to decide.</p>
          <?php else: ?>
            <?php ac_form('faculty_app_status', 'faculty', ['app_id' => (int) $fd['id']], 'class="ac-row"'); ?>
              <select class="ac-select ac-grow" name="status" required aria-label="Move this application to">
                <option value="">Choose the next step…</option>
                <?php foreach ($fd['legal'] as $st): ?>
                  <option value="<?= ac_h($st) ?>"><?= ac_h($st === (string) $fd['status'] ? 'Approve again (retry the account)' : $facLabel($st)) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="ac-btn ac-btn--primary ac-btn--sm">Save</button>
            <?php ac_form_end(); ?>
          <?php endif; ?>
          <?php if ((string) $fd['status'] === 'approved' && ($fd['subjects'] ?? [])): ?>
            <div class="ac-row ac-mt">
              <?php ac_action_btn('faculty_assign_subjects', 'faculty', ['app_id' => (int) $fd['id']],
                  'Accept what they can teach', 'ac-btn ac-btn--sm', 'Record these subjects as what this instructor may teach?'); ?>
            </div>
          <?php endif; ?>
        </div>
      <?php ac_card_close('The panel emails the candidate a time for the interview; there is no booking page for instructors.'); ?>
    <?php endif; ?>
  </div>
</div>
