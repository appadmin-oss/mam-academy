<?php
/** Charges & exceptions — punctuality is recorded for everyone; charging is a separate decision. */
declare(strict_types=1);
/** @var array $d */
$sg = (int) ($d['student_gate'] ?? 0) === 1;
$ig = (int) ($d['instructor_gate'] ?? 0) === 1;
?>
<?php ac_head('Charges & exceptions',
    'Punctuality is recorded for everyone · charging is two separate settings',
    '<a class="ac-btn" href="' . ac_h(ac_url('deductions')) . '">Instructor flags</a>'); ?>

<div class="ac-cols ac-cols--2">
  <?php ac_card_open('Student charges', '', ac_pill($sg ? 'On' : 'Off', $sg ? 'warn' : '')); ?>
    <div class="ac-cardbody">
      <p class="ac-note">A fine on a child for a guardian's traffic converts a shared obligation into a price.
      With charging off, a lateness is still recorded — at amount zero, outside the owed set, never a debt — so the
      pattern stays visible without anybody being billed for it.</p>
    </div>
  <?php ac_card_close('The gate lives INSIDE the charge function, not at its call sites: a gate at a call site short-circuits the whole call and swallows the no-charge RECORD as well as the charge, which is how a lateness once vanished entirely.'); ?>

  <?php ac_card_open('Instructor deductions', '', ac_pill($ig ? 'On' : 'Off and locked', $ig ? 'warn' : '')); ?>
    <div class="ac-cardbody">
      <p class="ac-note">Deducting a fine from wages needs an authorised labour officer's written consent under the
      Labour Act. This is a <strong>deduction from pay</strong>, not a fee to a customer, so it is a separate setting
      from the student one and the two are never merged.</p>
    </div>
  <?php ac_card_close('Lateness is recorded and goes to appraisal instead. Nothing on this screen reaches payroll.'); ?>
</div>

<?php ac_kpis([
    ['label' => 'Awaiting a decision', 'value' => (string) (int) ($d['pending'] ?? 0),
     'tone' => (int) ($d['pending'] ?? 0) > 0 ? 'warn' : '', 'note' => 'a waiver request or a dispute'],
    ['label' => 'Recorded', 'value' => (string) count($d['rows'] ?? []), 'note' => 'six statuses, not two'],
    ['label' => 'Owed', 'value' => mam_console_usd_short((int) ($d['owed_cents'] ?? 0)),
     'note' => 'outstanding + disputed — a dispute still counts as owed'],
    ['label' => 'Exceptions raised', 'value' => (string) count($d['exceptions'] ?? []),
     'note' => 'a request is how somebody ASKS'],
]); ?>

<?php ac_card_open('Recorded', count($d['rows'] ?? []) . '',
    '<span class="ac-cardmeta">A waiver or a refusal both need a note</span>'); ?>
  <div class="ac-tablewrap">
    <table class="ac-table">
      <thead><tr><th>Who</th><th>Reason</th><th class="ac-num">Minutes</th><th class="ac-num">Amount</th>
                 <th>Status</th><th></th></tr></thead>
      <tbody>
        <?php if (!($d['rows'] ?? [])): ?>
          <?php ac_table_empty(6, 'Nothing recorded. Punctuality is recorded even when charging is off.'); ?>
        <?php else: foreach ($d['rows'] as $r): ?>
          <tr>
            <td><div class="ac-strong"><?= ac_h((string) ($r['subject_name'] ?: '—')) ?>
                <?= ac_tag((string) $r['subject_type']) ?></div>
                <?php if (!empty($r['session_title'])): ?><div class="ac-sub"><?= ac_h((string) $r['session_title']) ?></div><?php endif; ?></td>
            <td><?= ac_h(str_replace('_', ' ', (string) $r['reason'])) ?>
                <?php if (!empty($r['note'])): ?><div class="ac-sub"><?= ac_h(mb_strimwidth((string) $r['note'], 0, 120, '…')) ?></div><?php endif; ?></td>
            <td class="ac-num"><?= $r['minutes'] !== null ? (int) $r['minutes'] : '—' ?></td>
            <td class="ac-num"><?= ac_h(mam_console_usd((int) ($r['amount_usd_cents'] ?? 0))) ?></td>
            <td><?= ac_status((string) $r['status']) ?></td>
            <td class="ac-num">
              <?php if (in_array((string) $r['status'], ['waiver_requested', 'disputed', 'outstanding'], true)): ?>
                <div class="ac-row">
                  <?php ac_action_btn('acct_resolve', 'charges',
                      ['charge_id' => (int) $r['id'], 'resolution' => 'waived'], 'Waive', 'ac-btn ac-btn--sm'); ?>
                  <?php ac_action_btn('acct_resolve', 'charges',
                      ['charge_id' => (int) $r['id'], 'resolution' => 'maintained'], 'Maintain', 'ac-btn ac-btn--sm ac-btn--danger'); ?>
                </div>
              <?php else: ?><span class="ac-sub">—</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
<?php ac_card_close(
    'While a waiver request is open the charge sits at <strong>pending</strong> — a student should not see a debt the '
    . 'academy has not decided is owed — and a refusal restores <strong>outstanding</strong>: "refused, charge '
    . 'maintained" is a decision the family must be able to read, not a quiet drop. A <span class="ac-mono">disputed</span> '
    . 'charge is non-terminal and still counts as owed, which is why the owed figure is a predicate rather than a '
    . 'single status test.'); ?>

<?php if ($d['exceptions'] ?? []): ?>
  <?php ac_card_open('Waiver requests', count($d['exceptions']) . ' raised'); ?>
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead><tr><th>Reason code</th><th>What was said</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($d['exceptions'] as $e): ?>
            <tr>
              <td class="ac-mono"><?= ac_h((string) $e['reason_code']) ?></td>
              <td><?= ac_h(mb_strimwidth((string) ($e['detail'] ?? ''), 0, 200, '…')) ?>
                <?php if (!empty($e['decision_note'])): ?>
                  <div class="ac-sub">Decision: <?= ac_h((string) $e['decision_note']) ?></div>
                <?php endif; ?></td>
              <td><?= ac_status((string) $e['status']) ?></td>
              <td class="ac-num">
                <?php if ((string) $e['status'] === 'pending'): ?>
                  <div class="ac-row">
                    <?php ac_action_btn('acct_exception', 'charges',
                        ['request_id' => (int) $e['id'], 'decision' => 'approve'], 'Approve', 'ac-btn ac-btn--sm'); ?>
                    <?php ac_action_btn('acct_exception', 'charges',
                        ['request_id' => (int) $e['id'], 'decision' => 'reject'], 'Refuse', 'ac-btn ac-btn--sm ac-btn--danger'); ?>
                  </div>
                <?php else: ?><span class="ac-sub">decided</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php ac_card_close('Seven reason codes, and neither outcome saves without a note.'); ?>
<?php endif; ?>
