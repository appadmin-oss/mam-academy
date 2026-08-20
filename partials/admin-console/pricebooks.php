<?php
/** Price books — a price already charged is never edited. */
declare(strict_types=1);
/** @var array $d */
$labels = [
    'tuition_usd' => ['Session tuition', 'Per session, per level'],
    'registration_fee_usd' => ['Registration fee', 'Once, at admission · non-refundable'],
    'certification_fee_usd' => ['Certification', 'On request, at cost'],
    'booking_fee_usd' => ['Audition booking fee', 'Per booking'],
    'summer_fee_usd' => ['Summer camp', 'Per intake · its own refund table'],
    'materials_fee_usd' => ['Materials', 'Per session · books and scores'],
    'certificate_reissue_usd' => ['Certificate reissue', 'On request'],
];
?>
<?php ac_head('Price books',
    'Accounting stays in USD · a guardian in Lagos reads naira, one in London reads sterling',
    '<a class="ac-btn" href="/admin/?v=settings">Edit the schedule</a>'); ?>

<?php if ((int) ($d['live_invoices'] ?? 0) > 0): ?>
  <?php ac_banner('flat', 'fa-lock',
    '<strong>' . (int) $d['live_invoices'] . ' live invoice' . ((int) $d['live_invoices'] === 1 ? '' : 's')
    . '</strong> still reference the current schedule and will keep doing so until they are paid or voided. '
    . 'Changing a price here affects what is charged NEXT, never what has been charged.'); ?>
<?php endif; ?>

<?php ac_card_open('In force', '', '<span class="ac-cardmeta">Shown at 1 USD = ₦'
    . ($d['rate'] ? number_format((float) $d['rate'], 2) : '—') . '</span>'); ?>
  <div class="ac-tablewrap">
    <table class="ac-table">
      <thead><tr><th>Item</th><th>Applies to</th><th class="ac-num">USD</th><th class="ac-num">Shown as</th></tr></thead>
      <tbody>
        <?php if (!($d['items'] ?? [])): ?>
          <?php ac_table_empty(4, 'No prices set. A setting reads empty until somebody saves the admin form — the registered default is not the stored value.'); ?>
        <?php else: foreach ($d['items'] as $it):
          $L = $labels[$it['key']] ?? [ucfirst(str_replace('_', ' ', (string) $it['key'])), '']; ?>
          <tr>
            <td class="ac-strong"><?= ac_h($L[0]) ?></td>
            <td class="ac-sub"><?= ac_h($L[1]) ?></td>
            <td class="ac-num"><?= ac_h(number_format((float) $it['usd'], 2)) ?></td>
            <td class="ac-num"><?= ac_h(mam_console_ngn((int) round(((float) $it['usd']) * 100), $d['rate'] ? (float) $d['rate'] : null)) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
<?php ac_card_close('Every figure here is the USD source; the naira column is computed from it, not stored beside it. Summing rounded conversions per line is where the one-cent mismatch comes from, and a family reads that as a pricing error.'); ?>

<div class="ac-cols ac-cols--2">
  <?php ac_card_open('Programmes charged against this book', count($d['programs'] ?? []) . ' active'); ?>
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead><tr><th>Programme</th><th class="ac-num">Weeks</th><th>Certification</th></tr></thead>
        <tbody>
          <?php if (!($d['programs'] ?? [])): ?>
            <?php ac_table_empty(3, 'No active programmes.'); ?>
          <?php else: foreach ($d['programs'] as $p): ?>
            <tr>
              <td><div class="ac-strong"><?= ac_h((string) $p['name']) ?></div>
                  <div class="ac-sub"><?= ac_h((string) $p['code']) ?></div></td>
              <td class="ac-num"><?= $p['weeks'] !== null ? (int) $p['weeks'] : '—' ?></td>
              <td><?= (int) ($p['certification'] ?? 0) === 1 ? ac_pill('Included', 'good') : ac_pill('Separate') ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php ac_card_close('A closure reduces the taught weeks in a term, and the fee calculation reads that from the calendar rather than from a number typed twice.'); ?>

  <?php ac_card_open('Displayed currency is not charged currency'); ?>
    <div class="ac-cardbody">
      <div class="ac-defs">
        <?php
          ac_def('Accounting unit', 'USD, integer cents');
          ac_def('Reader\'s currency', ac_h(implode(', ', array_slice(array_keys((array) ($d['currencies'] ?? [])), 0, 6)) ?: 'NGN'));
          ac_def('Rate on an invoice', 'frozen at issue');
          ac_def('Rate on the catalogue', 'today\'s warmed rate');
        ?>
      </div>
      <p class="ac-note ac-mt">A student keeps the <strong>requirements</strong> they enrolled
      under but pays the <strong>current published schedule</strong>. Two guarantees, deliberately separate —
      conflating them would freeze an academy's prices for four years.</p>
    </div>
  <?php ac_card_close('Rates are bounds-checked, and the cron warms the cache so display equals charge.'); ?>
</div>
