<?php
/**
 * Instructor earnings — what each instructor is owed, as their own Earnings
 * tab shows it (wallet.php). Recorded here; paid off the platform. Posts to the
 * existing `wallet_credit` handler (HANDOFF §4y: its button went with the
 * retired dashboard), which the `users` capability gates.
 */
declare(strict_types=1);
/** @var array $d */
$people = $d['people'] ?? [];
?>
<?php ac_head('Instructor earnings',
    'What each instructor is owed · recorded here, paid outside the platform',
    '<a class="ac-btn" href="' . ac_h(ac_url('deductions')) . '">Instructor deductions</a>'); ?>

<?php ac_kpis([
    ['label' => 'Instructors', 'value' => (string) count($people), 'note' => 'active'],
    ['label' => 'Owed in total', 'value' => mam_console_usd_short((int) ($d['owed_cents'] ?? 0)), 'note' => 'sum of statements'],
    ['label' => 'Entries shown', 'value' => (string) count($d['recent'] ?? []), 'note' => 'most recent'],
]); ?>

<div class="ac-cols ac-cols--main">
  <div class="ac-stack">
    <?php ac_card_open('Record earnings', 'Adds a line to the instructor\'s statement'); ?>
      <div class="ac-cardbody">
        <?php if (!$people): ?>
          <div class="ac-note">No active instructors.</div>
        <?php else: ?>
          <?php ac_form('wallet_credit', 'earnings'); ?>
            <div class="ac-grid ac-grid--2">
              <label class="ac-field"><span class="ac-label">Instructor</span>
                <select class="ac-input" name="user_id" required>
                  <option value="">Choose…</option>
                  <?php foreach ($people as $pp): ?>
                    <option value="<?= (int) $pp['id'] ?>"><?= ac_h((string) $pp['name'] . ' · ' . (string) $pp['email']) ?></option>
                  <?php endforeach; ?>
                </select></label>
              <label class="ac-field"><span class="ac-label">Amount (USD)</span>
                <input class="ac-input" name="amount_usd" inputmode="decimal" required pattern="[0-9]+(\.[0-9]{1,2})?" autocomplete="off"></label>
            </div>
            <div class="ac-row ac-mt" role="radiogroup" aria-label="What this entry is">
              <label class="ac-tick"><input type="radio" name="direction" value="add" checked><span>Earned — add to what they are owed</span></label>
              <label class="ac-tick"><input type="radio" name="direction" value="subtract"><span>Correction — earnings recorded in error</span></label>
            </div>
            <label class="ac-field ac-mt"><span class="ac-label">What it is for (needed for a correction)</span>
              <input class="ac-input" name="note" maxlength="255" autocomplete="off" placeholder="October lessons, 12 × $20"></label>
            <div class="ac-row ac-mt"><button type="submit" class="ac-btn ac-btn--primary">Record it</button></div>
          <?php ac_form_end(); ?>
        <?php endif; ?>
      </div>
    <?php ac_card_close('A correction is for earnings recorded in error. It is never a fine: deducting a fine from pay needs a labour officer\'s written consent, which is why flags live on Instructor deductions and do not move money.'); ?>

    <?php ac_card_open('Recent entries', count($d['recent'] ?? []) . ''); ?>
      <div class="ac-tablewrap">
        <table class="ac-table">
          <thead><tr><th>Instructor</th><th>What</th><th class="ac-num">Amount</th><th class="ac-num">Balance after</th><th class="ac-num">When</th></tr></thead>
          <tbody>
            <?php if (!($d['recent'] ?? [])): ?>
              <?php ac_table_empty(5, 'No earnings recorded yet.'); ?>
            <?php else: foreach ($d['recent'] as $e): ?>
              <tr>
                <td><?= ac_h((string) $e['instructor']) ?>
                  <?php if (!empty($e['recorded_by'])): ?><div class="ac-sub">by <?= ac_h((string) $e['recorded_by']) ?></div><?php endif; ?></td>
                <td><?= ac_h(mam_wallet_reason_label((string) $e['reason'])) ?>
                  <?php if (!empty($e['note'])): ?><div class="ac-sub"><?= ac_h((string) $e['note']) ?></div><?php endif; ?></td>
                <td class="ac-num<?= (int) $e['amount_cents'] < 0 ? ' ac-bad-text' : '' ?>"><?= ac_h(mam_console_usd((int) $e['amount_cents'])) ?></td>
                <td class="ac-num"><?= ac_h(mam_console_usd((int) $e['balance_after_cents'])) ?></td>
                <td class="ac-num"><?= ac_h(mam_console_date((string) $e['created_at'], 'j M y')) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    <?php ac_card_close('The statement is a ledger: an entry is never edited. A mistake is put right with a correction, so the statement still shows what happened.'); ?>
  </div>

  <?php ac_card_open('Owed now', 'Per instructor'); ?>
    <div class="ac-cardbody">
      <div class="ac-defs">
        <?php foreach ($people as $pp): ?>
          <?php /* The address beside the name: two instructors can share a name, and money
                   recorded against the wrong one is a statement somebody else reads. */
                ac_def((string) $pp['name'] . ' · ' . (string) $pp['email'], ac_h(mam_console_usd((int) ($pp['balance'] ?? 0)))
              . (!empty($pp['last_at']) ? ' <span class="ac-subi">· ' . ac_h(mam_console_date((string) $pp['last_at'], 'j M')) . '</span>' : '')); ?>
        <?php endforeach; ?>
        <?php if (!$people): ?><div class="ac-note">No active instructors.</div><?php endif; ?>
      </div>
    </div>
  <?php ac_card_close('The balance on each instructor\'s own statement. Paying them happens outside the platform; the statement does not record payouts.'); ?>
</div>
