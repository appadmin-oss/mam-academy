<?php
/** Store & orders — every order posts to the same ledger as tuition. */
declare(strict_types=1);
/** @var array $d */
?>
<?php ac_head('Store & orders',
    'Books, strings, reeds and hall hire · every order posts to the same ledger as tuition'); ?>

<?php if ($d['out_of_stock'] ?? []): ?>
  <?php ac_banner('bad', 'fa-box-open',
    '<strong>' . count($d['out_of_stock']) . ' line' . (count($d['out_of_stock']) === 1 ? '' : 's')
    . ' orderable with nothing in stock:</strong> '
    . ac_h(implode(', ', array_map(fn($i) => (string) $i['name'], array_slice($d['out_of_stock'], 0, 4))))
    . '. The store will keep taking money for them until somebody restocks or hides the line, and a parent who pays '
    . 'for a book that does not exist has to be refunded by hand.'); ?>
<?php endif; ?>

<?php ac_kpis([
    ['label' => 'Awaiting collection', 'value' => (string) (int) ($d['awaiting'] ?? 0),
     'note' => 'paid, not yet handed over'],
    ['label' => 'Unpaid', 'value' => (string) (int) ($d['pending'] ?? 0),
     'tone' => (int) ($d['pending'] ?? 0) > 0 ? 'warn' : '', 'note' => 'stock is held only once paid'],
    ['label' => 'Taken', 'value' => '$' . number_format((float) ($d['revenue_usd'] ?? 0), 0),
     'note' => (int) ($d['orders_n'] ?? 0) . ' settled order' . ((int) ($d['orders_n'] ?? 0) === 1 ? '' : 's')],
    ['label' => 'Catalogue', 'value' => (string) count($d['items'] ?? []), 'note' => 'lines on file'],
]); ?>

<?php ac_card_open('Orders', count($d['orders'] ?? []) . ' most recent'); ?>
  <div class="ac-tablewrap">
    <table class="ac-table">
      <thead><tr><th>Order</th><th>Buyer</th><th>Items</th><th class="ac-num">Total</th><th>State</th><th></th></tr></thead>
      <tbody>
        <?php if (!($d['orders'] ?? [])): ?>
          <?php ac_table_empty(6, 'No orders yet.'); ?>
        <?php else: foreach ($d['orders'] as $o):
          $items = json_decode((string) ($o['items_json'] ?? '[]'), true);
          $summary = is_array($items)
              ? implode(', ', array_map(fn($i) => (string) ($i['name'] ?? $i['sku'] ?? 'item')
                  . (isset($i['qty']) && (int) $i['qty'] > 1 ? ' ×' . (int) $i['qty'] : ''), array_slice($items, 0, 3)))
              : ''; ?>
          <tr>
            <td class="ac-mono"><?= ac_h((string) ($o['reference'] ?: '#' . (int) $o['id'])) ?></td>
            <td><div class="ac-strong"><?= ac_h((string) ($o['customer_name'] ?: '—')) ?></div>
                <div class="ac-sub"><?= ac_h((string) ($o['customer_email'] ?? '')) ?></div></td>
            <td><?= ac_h($summary ?: '—') ?><?php if (is_array($items) && count($items) > 3): ?>
                  <div class="ac-sub">+<?= count($items) - 3 ?> more</div><?php endif; ?></td>
            <td class="ac-num">$<?= ac_h(number_format((float) ($o['total_usd'] ?? 0), 2)) ?></td>
            <td><?= ac_status((string) $o['status']) ?></td>
            <td class="ac-num">
              <?php if ((string) $o['status'] === 'paid'): ?>
                <?php ac_action_btn('store_order_status', 'store',
                    ['order_id' => (int) $o['id'], 'status' => 'delivered'], 'Hand over', 'ac-btn ac-btn--sm ac-btn--primary'); ?>
              <?php elseif ((string) $o['status'] === 'pending'): ?>
                
              <?php else: ?><span class="ac-sub"><?= ac_h(mam_console_date((string) $o['delivered_at'])) ?></span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
<?php ac_card_close('Stock is decremented with a race guard, so two parents buying the last book cannot both succeed. A digital or empty order is delivered on settlement rather than waiting for a hand-over.'); ?>

<div class="ac-cols ac-cols--2">
  <?php ac_card_open('Where a store payment lands'); ?>
    <div class="ac-cardbody">
      <div class="ac-defs">
        <?php
          ac_def('Debit', 'Cash · the settlement account');
          ac_def('Credit', 'Materials income — <em>not</em> tuition income');
        ?>
      </div>
      <p class="ac-note ac-mt">Keeping materials out of tuition income is what lets the termly
      bursary figure mean something. A family buying two books does not read as a family paying more fees.</p>
    </div>
  <?php ac_card_close('<a href="' . ac_h(ac_url('ledger')) . '">Ledger & FX</a> keeps the two positions separate for the same reason.'); ?>

  <?php ac_card_open('Catalogue', count($d['items'] ?? []) . ' lines'); ?>
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead><tr><th>Item</th><th class="ac-num">Price</th><th class="ac-num">Stock</th><th>Live</th></tr></thead>
        <tbody>
          <?php if (!($d['items'] ?? [])): ?>
            <?php ac_table_empty(4, 'Nothing in the catalogue.'); ?>
          <?php else: foreach (array_slice($d['items'], 0, 14) as $i): ?>
            <tr>
              <td><div class="ac-strong"><?= ac_h((string) $i['name']) ?></div>
                  <div class="ac-sub ac-mono"><?= ac_h((string) ($i['sku'] ?? '')) ?></div></td>
              <td class="ac-num"><?= ac_h((string) ($i['currency'] ?: 'USD')) ?>
                  <?= ac_h(number_format((float) ($i['price_usd'] ?? 0), 2)) ?></td>
              <td class="ac-num"><?= $i['stock'] === null ? '<span class="ac-sub">∞</span>' : (int) $i['stock'] ?></td>
              <td><?= (int) ($i['active'] ?? 0) === 1
                  ? ((($i['stock'] !== null && (int) $i['stock'] <= 0)) ? ac_pill('Orderable, empty', 'bad') : ac_pill('Live', 'good'))
                  : ac_pill('Hidden') ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php ac_card_close('A null stock is unlimited — a downloadable or a service. Zero means empty, and a line that is live AND empty is the row above.'); ?>
</div>
