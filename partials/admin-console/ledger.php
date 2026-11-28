<?php
/** Ledger & FX — what came in, at the rate it came in at. */
declare(strict_types=1);
/** @var array $d */
?>
<?php ac_head('Ledger & FX',
    'USD is the accounting unit · every payment carries the rate it was taken at',
    '<a class="ac-btn" href="' . ac_h(ac_url('invoices')) . '">Invoices</a>'); ?>

<?php if ((int) ($d['unpriced'] ?? 0) > 0): ?>
  <?php ac_banner('warn', 'fa-triangle-exclamation',
    '<strong>' . (int) $d['unpriced'] . ' settled payment' . ((int) $d['unpriced'] === 1 ? '' : 's')
    . ' with no USD figure.</strong> Those are counted and disclosed separately rather than silently dropped — and '
    . 'never back-filled from the charge currency, because summing naira into a dollar total is exactly the bug the '
    . 'USD column exists to prevent.'); ?>
<?php endif; ?>

<?php
/* Money decisions made off the platform, recorded here (HANDOFF §4y — these two
   buttons went with the retired dashboard). Both post to the existing handlers,
   which ask mam_payment_admin_refusal(); the forms only offer what it allows.
   The handlers are gated by the `settings` capability, so the forms are too. */
$mayMoney = isset($adminUser) ? (!$adminUser || mam_admin_can($adminUser, 'settings')) : false;
?>

<?php if ($d['awaiting'] ?? []): ?>
  <?php ac_card_open('Bank transfers waiting', count($d['awaiting']) . ' oldest first',
      '<span class="ac-cardmeta">Confirm only what you have seen arrive</span>'); ?>
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead><tr><th>Reference</th><th>Who</th><th>Expected</th><th class="ac-num">Waiting since</th><th>Confirm</th></tr></thead>
        <tbody>
          <?php foreach ($d['awaiting'] as $w):
            $who = trim((string) ($w['first_name'] ?? '') . ' ' . (string) ($w['last_name'] ?? '')); ?>
            <tr>
              <td class="ac-mono"><?= ac_h((string) ($w['reference'] ?: '#' . (int) $w['id'])) ?>
                <div class="ac-sub"><?= ac_h(str_replace('_', ' ', (string) ($w['purpose'] ?: 'tuition'))) ?></div></td>
              <td><?= ac_h($who !== '' ? $who : '—') ?>
                <?php if (!empty($w['app_ref'])): ?><div class="ac-sub"><?= ac_h((string) $w['app_ref']) ?></div><?php endif; ?></td>
              <td class="ac-num"><?= ac_h((string) ($w['currency'] ?? '')) ?> <?= ac_h(number_format((float) ($w['amount'] ?? 0), 2)) ?></td>
              <td class="ac-num"><?= ac_h(mam_console_date((string) $w['created_at'], 'j M y')) ?></td>
              <td>
                <?php if ($mayMoney): ?>
                  <?php ac_form('payment_confirm_bank', 'ledger', ['payment_id' => (int) $w['id']]); ?>
                    <label class="ac-field"><span class="ac-label">Reference on the bank statement</span>
                      <input class="ac-input" name="note" required minlength="3" autocomplete="off"></label>
                    <label class="ac-tick ac-mt"><input type="checkbox" name="checked" value="1" required>
                      <span>I have seen this money arrive in the academy's account.</span></label>
                    <div class="ac-row ac-mt"><button type="submit" class="ac-btn ac-btn--primary ac-btn--sm">Confirm received</button></div>
                  <?php ac_form_end(); ?>
                <?php else: ?>
                  <span class="ac-sub">Finance confirms these</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php ac_card_close('Confirming settles what the transfer paid for exactly as an automatic payment would: an application is provisioned and enrolled, a store order released.'); ?>
<?php endif; ?>

<?php if ($d['unfinished'] ?? []): ?>
  <?php ac_banner('warn', 'fa-triangle-exclamation',
    '<strong>' . count($d['unfinished']) . ' settled payment' . (count($d['unfinished']) === 1 ? '' : 's')
    . ' with work still to finish.</strong> The money was taken; what it paid for has not been applied yet.'); ?>
  <?php ac_card_open('Settled, not yet finished', count($d['unfinished']) . ' to reconcile',
      '<span class="ac-cardmeta">Finishing runs the same code the gateway runs</span>'); ?>
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead><tr><th>Reference</th><th>What is missing</th><th class="ac-num">Tries</th><th>Finish</th></tr></thead>
        <tbody>
          <?php foreach ($d['unfinished'] as $u): ?>
            <tr>
              <td class="ac-mono"><?= ac_h((string) $u['reference']) ?>
                <div class="ac-sub"><?= ac_h(str_replace('_', ' ', (string) ($u['purpose'] ?: 'tuition'))) ?></div></td>
              <td><?= ac_h((string) $u['why']) ?></td>
              <td class="ac-num"><?= (int) $u['attempts'] ?></td>
              <td>
                <?php if ($mayMoney): ?>
                  <?php ac_form('payment_settle_finish', 'ledger', ['reference' => (string) $u['reference']]); ?>
                    <button type="submit" class="ac-btn ac-btn--primary ac-btn--sm">Finish</button>
                  <?php ac_form_end(); ?>
                <?php else: ?>
                  <span class="ac-sub">Finance finishes these</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php ac_card_close('Finishing is safe to repeat: each effect checks whether it already happened. A payment held for an identity check stays held.'); ?>
<?php endif; ?>

<div class="ac-cols ac-cols--main">
  <div class="ac-stack">
  <?php ac_card_open('Payments', count($d['rows'] ?? []) . ' most recent',
      '<span class="ac-cardmeta">Rate frozen per row</span>'); ?>
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead><tr><th>Reference</th><th>Who</th><th>Charged</th><th class="ac-num">Base (USD)</th>
                   <th class="ac-num">Rate</th><th>Status</th><th class="ac-num">When</th></tr></thead>
        <tbody>
          <?php if (!($d['rows'] ?? [])): ?>
            <?php ac_table_empty(7, 'No payments recorded.'); ?>
          <?php else: foreach ($d['rows'] as $p):
            $who = trim((string) ($p['first_name'] ?? '') . ' ' . (string) ($p['last_name'] ?? '')); ?>
            <tr>
              <td class="ac-mono"><?= ac_h((string) ($p['reference'] ?: '#' . (int) $p['id'])) ?>
                <?php if (!empty($p['provider'])): ?><div class="ac-sub"><?= ac_h((string) $p['provider']) ?></div><?php endif; ?></td>
              <td><?= ac_h($who !== '' ? $who : '—') ?>
                <?php if (!empty($p['app_ref'])): ?><div class="ac-sub"><?= ac_h((string) $p['app_ref']) ?></div>
                <?php elseif ((string) $p['status'] === 'succeeded'): ?><div class="ac-sub">unmatched</div><?php endif; ?></td>
              <td class="ac-num"><?= ac_h((string) ($p['currency'] ?? '')) ?>
                  <?= ac_h(number_format((float) ($p['amount'] ?? 0), 2)) ?></td>
              <td class="ac-num"><?= $p['amount_usd_cents'] !== null
                  ? ac_h(mam_console_usd((int) $p['amount_usd_cents'])) : '<span class="ac-sub">unpriced</span>' ?></td>
              <td class="ac-num"><?= $p['fx_rate_usd_ngn'] !== null
                  ? ac_h(number_format((float) $p['fx_rate_usd_ngn'], 2)) : '<span class="ac-sub">—</span>' ?></td>
              <td><?= ac_status((string) $p['status']) ?></td>
              <td class="ac-num"><?= ac_h(mam_console_date((string) $p['created_at'], 'j M y')) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php ac_card_close('A correction is a new row, never an edit — a row that can be changed after the fact is not a ledger. The rate stays with the payment so a figure taken in May still reconciles next year.'); ?>

  <?php
  /* Recording a refund: its own card rather than a control in each row. In the
     table's narrow status column an opened form was squeezed to a sliver at
     1280; here it has the width it needs, and only money that was taken is
     offered (mam_payment_admin_refusal() refuses anything else anyway). */
  $refundable = array_values(array_filter($d['rows'] ?? [], static fn ($p) => (string) $p['status'] === 'succeeded'));
  if ($mayMoney && $refundable): ?>
    <?php ac_card_open('Record a refund', 'Money already sent back through the bank or gateway'); ?>
      <div class="ac-cardbody">
        <?php ac_form('payment_refund', 'ledger'); ?>
          <div class="ac-grid ac-grid--2">
            <label class="ac-field"><span class="ac-label">Payment</span>
              <select class="ac-input" name="payment_id" required>
                <option value="">Choose a payment…</option>
                <?php foreach ($refundable as $p):
                  $who = trim((string) ($p['first_name'] ?? '') . ' ' . (string) ($p['last_name'] ?? '')); ?>
                  <option value="<?= (int) $p['id'] ?>"><?= ac_h((string) ($p['reference'] ?: '#' . (int) $p['id'])
                    . ' · ' . (string) ($p['currency'] ?? '') . ' ' . number_format((float) ($p['amount'] ?? 0), 2)
                    . ($who !== '' ? ' · ' . $who : '')) ?></option>
                <?php endforeach; ?>
              </select></label>
            <label class="ac-field"><span class="ac-label">Why, and how the money went back</span>
              <input class="ac-input" name="note" required minlength="3" autocomplete="off"></label>
          </div>
          <p class="ac-sub ac-mt">This records a refund already made; it does not send money. What the payment bought is reversed — an application goes back to review, a booking fee is unpaid, a store order is refunded.</p>
          <div class="ac-row ac-mt"><button type="submit" class="ac-btn ac-btn--danger">Mark refunded</button></div>
        <?php ac_form_end(); ?>
      </div>
    <?php ac_card_close(); ?>
  <?php endif; ?>
  </div>

  <div class="ac-stack">
    <?php ac_card_open('Positions', 'USD'); ?>
      <div class="ac-cardbody">
        <div class="ac-defs">
          <?php foreach ($d['accounts'] ?? [] as $acc): ?>
            <?php ac_def((string) $acc['label'],
                ((int) $acc['cents'] < 0 ? '<span class="ac-bad-text">' : '<span>')
                . ac_h(mam_console_usd((int) $acc['cents'])) . '</span>'); ?>
          <?php endforeach; ?>
        </div>
      </div>
    <?php ac_card_close('Materials income is kept out of tuition income deliberately: a family buying two books must not read as a family paying more fees, or the bursary share of billed stops meaning anything.'); ?>

    <?php ac_card_open('Charge currencies', 'What families actually paid in'); ?>
      <div class="ac-cardbody">
        <?php if (!($d['by_currency'] ?? [])): ?>
          <div class="ac-note">Nothing settled yet.</div>
        <?php else: ?>
          <div class="ac-defs">
            <?php foreach ($d['by_currency'] as $c): ?>
              <?php ac_def((string) ($c['currency'] ?: 'unknown') . ' (' . (int) $c['n'] . ')',
                  ac_h(mam_console_usd((int) $c['usd'])) . ' <span class="ac-subi">in base</span>'); ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if (!empty($d['rate'])): ?>
          <div class="ac-note ac-mt">Today's warmed rate: <strong>1 USD =
            ₦<?= ac_h(number_format((float) $d['rate'], 2)) ?></strong>. The cron warms it so what a family is
            shown equals what they are charged.</div>
        <?php else: ?>
          <div class="ac-note ac-mt">No warmed rate. Display falls back to a bounded default,
            and a payment taken now stores whatever rate it was priced at.</div>
        <?php endif; ?>
      </div>
    <?php ac_card_close('Rates are bounds-checked on the way in, so a provider returning nonsense cannot reprice the catalogue.'); ?>

    <?php ac_card_open('Periods', count($d['terms'] ?? []) . ' terms'); ?>
      <div class="ac-cardbody">
        <div class="ac-defs">
          <?php foreach (array_slice($d['terms'] ?? [], 0, 6) as $t): ?>
            <?php ac_def(trim((string) (($t['label'] ?? '') ?: ($t['code'] ?? '')) . ' ' . (string) ($t['year_label'] ?? '')),
                ac_status((string) ($t['status'] ?? 'draft'))); ?>
          <?php endforeach; ?>
          <?php if (!($d['terms'] ?? [])): ?><div class="ac-note">No terms authored.</div><?php endif; ?>
        </div>
      </div>
    <?php ac_card_close('An unmatched payment in a closed period can never be put right, which is why anything in suspense is worth clearing before a term is treated as done.'); ?>
  </div>
</div>
