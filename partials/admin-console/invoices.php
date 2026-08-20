<?php
/** Invoices & payments — base currency USD, displayed at each row's frozen rate. */
declare(strict_types=1);
/** @var array $d */
$a = $d['aging'] ?? [];
?>
<?php ac_head('Invoices & payments',
    'Base currency USD · displayed in ₦ at each row\'s frozen rate',
    '<a class="ac-btn" href="' . ac_h(ac_url('ledger')) . '">Ledger</a>'
    . '<a class="ac-btn" href="/admin/?v=payments">Payments</a>'); ?>

<?php if ($d['suspense'] ?? []): ?>
  <?php
    $s = $d['suspense'][0];
    ac_banner('bad', 'fa-circle-question',
      '<strong>' . count($d['suspense']) . ' payment' . (count($d['suspense']) === 1 ? '' : 's')
      . ' in suspense.</strong> ' . ac_h((string) ($s['provider'] ?? '')) . ' '
      . ac_h((string) ($s['provider_reference'] ?: $s['reference'] ?? '')) . ' · '
      . ac_h(mam_console_usd((int) ($s['amount_usd_cents'] ?? 0)))
      . '. The signature verified but there is nothing to apply it to, so it sits unapplied rather than being '
      . 'guessed onto the nearest invoice.',
      '<a class="ac-btn ac-btn--sm" href="/admin/?v=payments">Match it</a>'); ?>
<?php endif; ?>

<?php ac_kpis([
    ['label' => 'Current', 'value' => mam_console_usd_short((int) ($a['current'] ?? 0)), 'note' => 'not yet due'],
    ['label' => '1–30 days', 'value' => mam_console_usd_short((int) ($a['d30'] ?? 0)), 'tone' => 'warn', 'note' => 'inside the grace window'],
    ['label' => '31–60 days', 'value' => mam_console_usd_short((int) ($a['d60'] ?? 0)), 'tone' => 'warn', 'note' => 'a conversation, not a lockout'],
    ['label' => '60 days +', 'value' => mam_console_usd_short((int) ($a['d60p'] ?? 0)), 'tone' => 'bad', 'note' => 'still in class'],
]); ?>

<?php ac_card_open('Invoices', count($d['rows'] ?? []) . '', '<span class="ac-cardmeta">Totals computed once, in the invoice\'s own currency</span>'); ?>
  <div class="ac-tablewrap">
    <table class="ac-table">
      <thead><tr><th>Invoice</th><th>Family</th><th class="ac-num">Total</th><th class="ac-num">Shown as</th>
                 <th>Status</th><th class="ac-num">Due</th></tr></thead>
      <tbody>
        <?php if (!($d['rows'] ?? [])): ?>
          <?php ac_table_empty(6, 'No fee invoices issued. A tuition invoice is raised per term, per learner.'); ?>
        <?php else: foreach ($d['rows'] as $r):
          $overdueDays = $r['due_on'] && (string) $r['status'] !== 'paid'
              ? (int) floor((time() - (int) strtotime((string) $r['due_on'])) / 86400) : 0;
          $aid = (int) ($r['aid_minor'] ?? 0) + (int) ($r['waiver_minor'] ?? 0) + (int) ($r['sibling_minor'] ?? 0); ?>
          <tr>
            <td class="ac-mono"><?= ac_h((string) ($r['public_ref'] ?: '#' . (int) $r['id'])) ?></td>
            <td><div class="ac-strong"><?= ac_h((string) ($r['student'] ?: '—')) ?>
                <?php if ($aid > 0): ?><?= ac_pill('aid', 'good') ?><?php endif; ?></div>
                <?php if (!empty($r['guardian'])): ?><div class="ac-sub">billed to <?= ac_h((string) $r['guardian']) ?></div><?php endif; ?></td>
            <td class="ac-num"><?= ac_h(mam_console_usd((int) $r['total_usd_cents'])) ?></td>
            <td class="ac-num">
              <?= ac_h(mam_console_ngn((int) $r['total_usd_cents'], $r['fx_rate_usd_ngn'] !== null ? (float) $r['fx_rate_usd_ngn'] : null)) ?>
              <?php if ($r['fx_rate_usd_ngn'] === null): ?><div class="ac-sub">estimate</div><?php endif; ?>
            </td>
            <td>
              <?php if ($overdueDays > 0): ?><?= ac_pill('Overdue ' . $overdueDays . 'd', 'bad') ?>
              <?php else: ?><?= ac_status((string) $r['status']) ?><?php endif; ?>
            </td>
            <td class="ac-num"><?= ac_h(mam_console_date((string) $r['due_on'])) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
<?php ac_card_close('The naira column is a display conversion. Where a rate was frozen on the invoice it is that rate — a May figure must still reconcile next year — and where it was not, the figure is labelled an estimate. Totals are computed ONCE from the lines in the invoice\'s own currency and stored; summing per-line conversions is where the one-naira gap a parent reads as a pricing error comes from.'); ?>

<div class="ac-cols ac-cols--2">
  <?php ac_card_open('Dunning, not disconnection'); ?>
    <div class="ac-cardbody">
      <p class="ac-note">The grace window is <strong><?= (int) ($d['grace_days'] ?? 14) ?> days</strong>, and after it
      the next step is a conversation. A learner sixty days overdue is still in class.</p>
      <p class="ac-note">There is <strong>no column and no function that suspends anything over money</strong>. The
      refusal is kept by leaving the mechanism unbuilt — an access hold would eventually be pointed at a minor's
      report, and a report is not a debt-collection instrument.</p>
    </div>
  <?php ac_card_close('A minor\'s academic record and any safeguarding-relevant file are never withheld for debt.'); ?>

  <?php ac_card_open('Aid is not a discount', 'Four mechanisms, one order'); ?>
    <div class="ac-cardbody">
      <div class="ac-defs">
        <?php
          ac_def('Waiver', 'Reduces the invoice line · never a cash entry');
          ac_def('Bursary', 'Needs-based gift aid · a credit from the aid pool');
          ac_def('Scholarship', 'Merit gift aid · same treatment, different criteria');
          ac_def('Sibling', 'A discount on the POST-aid balance, capped on the amount');
        ?>
      </div>
      <p class="ac-note ac-mt">The order is <span class="ac-mono">gross → waivers → aid credits →
      sibling (capped) → total</span>, and there is one implementation of it. A sibling percentage taken off the gross
      would discount money the aid pool paid.</p>
    </div>
  <?php ac_card_close('<a href="' . ac_h(ac_url('bursaries')) . '">Bursaries & awards</a> is where an award is decided; this screen only shows its effect.'); ?>
</div>
