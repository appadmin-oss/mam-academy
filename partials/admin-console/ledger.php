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

<div class="ac-cols ac-cols--main">
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
                <?php elseif ((string) $p['status'] === 'success'): ?><div class="ac-sub">unmatched</div><?php endif; ?></td>
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
