<?php
/**
 * Admissions → Summer: the summer applications, listed from summer_applications
 * (the board used to count them and show none), with the decision panel. Every
 * move posts to `summer_set_status`, the one implementation it shares with the
 * JSON route (decisions.php): the state machine, a refusal's reason, the
 * identity hold, the real enrolment and the family's email.
 */
declare(strict_types=1);
/** @var array $d */
$sd = $d['summer_detail'] ?? null;
$sumLabel = ['submitted' => 'Received', 'confirmed' => 'Place confirmed', 'waitlisted' => 'Waiting list',
             'enrolled' => 'Enrolled', 'cancelled' => 'Cancelled', 'rejected' => 'Not offered'];
?>
<div class="ac-cols ac-cols--main">
  <div class="ac-card">
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead><tr><th>Applicant</th><th>Instrument</th><th>Status</th><th class="ac-num">Waiting</th><th></th></tr></thead>
        <tbody>
          <?php if (!($d['summer_rows'] ?? [])): ?>
            <?php ac_table_empty(5, 'No summer applications yet. They land here from the /summer form.'); ?>
          <?php else: foreach ($d['summer_rows'] as $r):
            $days = mam_console_days_since((string) $r['created_at']);
            $sel = $sd && (int) $sd['id'] === (int) $r['id']; ?>
            <tr class="<?= $sel ? 'is-selected' : '' ?>">
              <td>
                <div class="ac-strong"><?= ac_h(trim((string) $r['first_name'] . ' ' . (string) $r['last_name']) ?: '—') ?></div>
                <div class="ac-sub"><?= ac_h(ac_join([(string) $r['reference'], $r['age_at_submission'] !== null ? 'age ' . (int) $r['age_at_submission'] : '', (string) ($r['guardian_name'] ?? '')])) ?></div>
              </td>
              <td><?= ac_h((string) ($r['discipline'] ?? '—')) ?></td>
              <td><?= ac_status((string) $r['status'], $sumLabel[(string) $r['status']] ?? (string) $r['status']) ?>
                <?php if ((string) ($r['identity_hold'] ?? '') !== ''): ?><?= ac_pill('Held', 'bad') ?><?php endif; ?></td>
              <td class="ac-num"><?= $days ?>d</td>
              <td class="ac-num"><a class="ac-btn ac-btn--sm" href="<?= ac_h(ac_url('admissions', ['type' => 'summer', 'app' => (int) $r['id']])) ?>">Open</a></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="ac-cardfoot">Summer is a crash-programme intake. Enrolling makes the child's account, their guardian's, and their place in the SUMMER class — unless the email belongs to someone else, when it is held for you.</div>
  </div>

  <div class="ac-stack">
    <?php if (!$sd): ?>
      <?php ac_card_open('Summer applicant'); ?>
        <div class="ac-cardbody"><?php ac_empty('No application selected', 'Open one from the list to decide it.'); ?></div>
      <?php ac_card_close(); ?>
    <?php else: ?>
      <?php ac_card_open(trim((string) $sd['first_name'] . ' ' . (string) $sd['last_name']), (string) $sd['reference'],
          ac_status((string) $sd['status'], $sumLabel[(string) $sd['status']] ?? (string) $sd['status'])); ?>
        <div class="ac-cardbody">
          <div class="ac-defs">
            <?php
              ac_def('Instrument', ac_h((string) ($sd['discipline'] ?? '—')));
              ac_def('Born', ac_h(ac_join([(string) ($sd['dob'] ?? ''), $sd['age_at_submission'] !== null ? 'age ' . (int) $sd['age_at_submission'] . ' when they applied' : ''])) ?: '—');
              ac_def('Email', ac_h((string) ($sd['email'] ?? '—')));
              ac_def('Guardian', ac_h(ac_join([(string) ($sd['guardian_name'] ?? ''), (string) ($sd['guardian_email'] ?? ''), (string) ($sd['guardian_phone'] ?? '')])) ?: 'Not named');
              ac_def('Session', ac_h((string) ($sd['session_year'] ?? '')));
              if (!empty($sd['enrolment'])) ac_def('Class', ac_h(ac_join([(string) $sd['enrolment']['title'], (string) $sd['enrolment']['status']])));
              if ((string) ($sd['decision_reason'] ?? '') !== '') ac_def('Reason given', ac_h((string) $sd['decision_reason']));
            ?>
          </div>
        </div>
      <?php ac_card_close(); ?>

      <?php ac_card_open('Decision'); ?>
        <div class="ac-cardbody">
          <?php if ($sd['hold_text']): ?>
            <?php ac_banner('bad', 'fa-user-lock', ac_h((string) $sd['hold_text'])); ?>
          <?php endif; ?>
          <?php if (!$sd['legal']): ?>
            <p class="ac-sub">Final — <?= ac_h($sumLabel[(string) $sd['status']] ?? (string) $sd['status']) ?>. Nothing further to decide.</p>
          <?php else: ?>
            <?php ac_form('summer_set_status', 'admissions', ['id' => (int) $sd['id']]); ?>
              <div class="ac-grid">
                <label class="ac-field"><span class="ac-label">Move to</span>
                  <select class="ac-select" name="status" required>
                    <option value="">Choose the next step…</option>
                    <?php foreach ($sd['legal'] as $st): ?><option value="<?= ac_h($st) ?>"><?= ac_h($sumLabel[$st] ?? $st) ?></option><?php endforeach; ?>
                  </select></label>
                <label class="ac-field"><span class="ac-label">Reason the family can read</span>
                  <textarea class="ac-textarea" name="reason" rows="3" maxlength="2000" placeholder="Required for “Not offered” — it is emailed in these words."></textarea></label>
              </div>
              <?php if ($sd['hold_text']): ?>
                <label class="ac-field ac-mt"><span class="ac-label"><input type="checkbox" name="identity_confirmed" value="1"> I have checked: this is the same person who already holds this email</span></label>
              <?php endif; ?>
              <div class="ac-row ac-mt">
                <button type="submit" class="ac-btn ac-btn--primary">Save the decision</button>
                <span class="ac-hint">A confirmed place, a waiting-list place, a refusal and an enrolment are emailed to <?= ac_h(mam_summer_contact($sd)['guardian'] ? 'the guardian' : 'the applicant') ?>.</span>
              </div>
            <?php ac_form_end(); ?>
          <?php endif; ?>
        </div>
      <?php ac_card_close('Enrolling needs the fees capability: it makes accounts and a class place.'); ?>
    <?php endif; ?>
  </div>
</div>
