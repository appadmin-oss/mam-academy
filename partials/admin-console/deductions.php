<?php
/** Instructor deductions — flags against pay, which this console records and does not make. */
declare(strict_types=1);
/** @var array $d */
$by = $d['by_status'] ?? [];
$gate = (int) ($d['gate'] ?? 0) === 1;
?>
<?php ac_head('Instructor deductions',
    'Flags against instructor pay · ' . (int) ($by['pending'] ?? 0) . ' pending, none applied',
    '<a class="ac-btn" href="' . ac_h(ac_url('charges')) . '">Punctuality policy</a>'); ?>

<?php ac_banner('warn', 'fa-scale-balanced',
    '<strong>This console records deductions. It does not make them.</strong> Deducting a fine from wages requires '
    . 'the written consent of an authorised labour officer under the Labour Act. Nothing here reaches payroll: a flag '
    . 'is a conversation with an instructor, and every row below can be resolved or waived without money moving. '
    . 'The charging switch is <strong>' . ($gate ? 'on' : 'off') . '</strong>. If the academy ever does deduct, it '
    . 'happens in payroll with that consent on file, and this screen is the evidence of what it was for.'); ?>

<?php ac_kpis([
    ['label' => 'Pending', 'value' => (string) (int) ($by['pending'] ?? 0),
     'tone' => (int) ($by['pending'] ?? 0) > 0 ? 'warn' : '', 'note' => 'awaiting a conversation'],
    ['label' => 'Resolved', 'value' => (string) (int) ($by['resolved'] ?? 0), 'note' => 'discussed and closed'],
    ['label' => 'Waived', 'value' => (string) (int) ($by['waived'] ?? 0), 'note' => 'excused, with a reason'],
    ['label' => 'Value flagged', 'value' => mam_console_usd_short((int) ($d['flagged_cents'] ?? 0)),
     'note' => $gate ? 'a figure, not a deduction' : 'flag only — no amount charged'],
]); ?>

<?php ac_card_open('Flags', count($d['rows'] ?? []) . ''); ?>
  <div class="ac-tablewrap">
    <table class="ac-table">
      <thead><tr><th>Instructor</th><th>Session</th><th>Reason</th><th class="ac-num">Amount</th>
                 <th>State</th><th></th></tr></thead>
      <tbody>
        <?php if (!($d['rows'] ?? [])): ?>
          <?php ac_table_empty(6, 'Nothing flagged. A late progress report or an unmarked register raises one automatically.'); ?>
        <?php else: foreach ($d['rows'] as $r): ?>
          <tr>
            <td><div class="ac-strong"><?= ac_h((string) ($r['instructor'] ?: '—')) ?></div>
                <div class="ac-sub"><?= ac_h((string) ($r['email'] ?? '')) ?></div></td>
            <td><?= ac_h((string) ($r['session_title'] ?: '—')) ?>
                <?php if (!empty($r['scheduled_at'])): ?>
                  <div class="ac-sub"><?= ac_h(mam_console_date((string) $r['scheduled_at'], 'j M')) ?></div>
                <?php endif; ?></td>
            <td><?= ac_h(str_replace('_', ' ', (string) $r['reason'])) ?>
                <?php if (!empty($r['resolution_note'])): ?>
                  <div class="ac-sub"><?= ac_h(mb_strimwidth((string) $r['resolution_note'], 0, 110, '…')) ?></div>
                <?php endif; ?></td>
            <td class="ac-num"><?= (int) ($r['amount_usd_cents'] ?? 0) > 0
                ? ac_h(mam_console_usd((int) $r['amount_usd_cents'])) : '<span class="ac-sub">Flag only</span>' ?></td>
            <td><?= ac_status((string) $r['status']) ?></td>
            <td class="ac-num">
              <?php if ((string) $r['status'] === 'pending'): ?>
                <a class="ac-btn ac-btn--sm" href="/admin/?v=fines">Resolve or waive</a>
              <?php else: ?><span class="ac-sub"><?= ac_h(mam_console_date((string) $r['resolved_at'])) ?></span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
<?php ac_card_close('Resolving and waiving are JSON endpoints, so they are done from the legacy fines screen for now — both write a resolution note, and neither moves money.'); ?>
