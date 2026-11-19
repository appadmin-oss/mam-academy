<?php
/** Invoices & payments — base currency USD, displayed at each row's frozen rate. */
declare(strict_types=1);
/** @var array $d */
$a = $d['aging'] ?? [];
?>
<?php ac_head('Invoices & payments',
    'Base currency USD · displayed in ₦ at each row\'s frozen rate',
    '<a class="ac-btn" href="' . ac_h(ac_url('ledger')) . '">Ledger</a>'); ?>

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
      ''); ?>
<?php endif; ?>

<?php $det = $d['detail'] ?? null; $methods = $d['methods'] ?? []; ?>
<?php if (isset($_GET['id']) && !$det): ?>
  <?php ac_banner('bad', 'fa-circle-question', 'That invoice could not be found. It may have been opened in another tab and the list has moved on.'); ?>
<?php endif; ?>

<?php if ($det): $editable = !empty($det['editable']); $cur = (string) $det['currency']; ?>
  <?php ac_card_open('Invoice ' . (string) $det['ref'], (string) $det['student_name'] . ' · ' . (string) $det['term_label'],
      ac_status((string) $det['status']) . ' <a class="ac-btn ac-btn--sm" href="' . ac_h(ac_url('invoices')) . '">Close</a>'); ?>
    <div class="ac-cardbody">
      <?php if (!$det['has_guardian']): ?>
        <?php ac_banner('warn', 'fa-user-slash', 'No billing guardian is linked to this student, so the family cannot see this invoice and reminders have nobody to go to. Link a guardian first.'); ?>
      <?php endif; ?>
      <div class="ac-defs">
        <?php
          ac_def('Billed to', ac_h((string) ($det['guardian'] ?: '— nobody linked')));
          ac_def('Gross', ac_h((string) $det['gross']));
          if ($det['waiver'])  ac_def('Less waivers', ac_h((string) $det['waiver']));
          if ($det['aid'])     ac_def('Less aid', ac_h((string) $det['aid']));
          if ($det['sibling']) ac_def('Less sibling discount', ac_h((string) $det['sibling']));
          ac_def('Total', '<strong>' . ac_h((string) $det['total']) . '</strong>');
          ac_def('Paid', ac_h((string) $det['paid']));
          ac_def('Still owed', '<strong>' . ac_h((string) $det['due']) . '</strong>');
          if ($det['due_on']) ac_def('Due', ac_h((string) $det['due_on']) . ($det['overdue_days'] ? ' ' . ac_pill('Overdue ' . (int) $det['overdue_days'] . 'd', 'bad') : ''));
          if ($det['frozen_rate_label']) ac_def('Rate frozen at issue', ac_h((string) $det['frozen_rate_label']));
          if ($det['void_reason']) ac_def('Voided because', ac_h((string) $det['void_reason']));
        ?>
      </div>
    </div>
  <?php ac_card_close(); ?>

  <div class="ac-cols ac-cols--main">
    <div class="ac-stack">
      <?php ac_card_open('Lines', count($det['lines']) . ''); ?>
        <div class="ac-tablewrap">
          <table class="ac-table">
            <thead><tr><th>Line</th><th class="ac-num">Qty</th><th class="ac-num">Amount</th><th class="ac-num">Waived</th><?= $editable ? '<th>Waive</th>' : '' ?></tr></thead>
            <tbody>
              <?php if (!$det['lines']): ?>
                <?php ac_table_empty($editable ? 5 : 4, 'No lines yet. Add tuition, materials or an exam fee below.'); ?>
              <?php else: foreach ($det['lines'] as $l): ?>
                <tr>
                  <td><div class="ac-strong"><?= ac_h((string) $l['label']) ?></div><div class="ac-sub"><?= ac_h((string) $l['kind']) ?><?= $l['waiver_reason'] ? ' · waived: ' . ac_h((string) $l['waiver_reason']) : '' ?></div></td>
                  <td class="ac-num"><?= (int) $l['qty'] ?></td>
                  <td class="ac-num"><?= ac_h((string) $l['amount']) ?></td>
                  <td class="ac-num"><?= ac_h((string) ($l['waiver'] ?? '—')) ?></td>
                  <?php if ($editable): ?>
                    <td>
                      <?php ac_form('fee_line_waive', 'invoices', ['invoice_id' => (int) $det['id'], 'line_id' => (int) $l['id']], 'class="ac-row"'); ?>
                        <label class="ac-field"><span class="ac-label">Amount</span><input class="ac-input ac-input--sm" name="amount" inputmode="decimal" placeholder="0.00" aria-label="Amount to waive on <?= ac_h((string) $l['label']) ?>"></label>
                        <label class="ac-field"><span class="ac-label">Reason</span><input class="ac-input ac-input--sm" name="reason" required aria-label="Reason for the waiver"></label>
                        <button type="submit" class="ac-btn ac-btn--sm">Waive</button>
                      <?php ac_form_end(); ?>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <?php if ($editable): ?>
          <div class="ac-cardbody">
            <?php ac_form('fee_line_add', 'invoices', ['invoice_id' => (int) $det['id']]); ?>
              <div class="ac-grid ac-grid--2">
                <label class="ac-field"><span class="ac-label">Kind</span>
                  <select class="ac-select" name="kind">
                    <?php foreach (['tuition' => 'Tuition', 'materials' => 'Materials', 'exam' => 'Exam fee', 'registration' => 'Registration', 'recital' => 'Recital', 'other' => 'Other'] as $k => $lbl): ?>
                      <option value="<?= ac_h($k) ?>"><?= ac_h($lbl) ?></option>
                    <?php endforeach; ?>
                  </select></label>
                <label class="ac-field"><span class="ac-label">Description</span><input class="ac-input" name="label" required placeholder="Piano, Grade 3 — spring term"></label>
                <label class="ac-field"><span class="ac-label">Unit price (<?= ac_h($cur) ?>)</span><input class="ac-input" name="unit" inputmode="decimal" required placeholder="300.00"></label>
                <label class="ac-field"><span class="ac-label">Quantity</span><input class="ac-input" name="qty" type="number" min="1" value="1"></label>
              </div>
              <div class="ac-row ac-mt"><button type="submit" class="ac-btn">Add the line</button></div>
            <?php ac_form_end(); ?>
          </div>
        <?php endif; ?>
      <?php ac_card_close('Prices are typed in the invoice\'s own currency. A waiver reduces the line and is never a cash entry.'); ?>

      <?php if (!empty($det['claims'])): ?>
        <?php ac_card_open('Transfers the family reported', count($det['claims']) . ''); ?>
          <?php foreach ($det['claims'] as $cl): ?>
            <div class="ac-cardbody">
              <div class="ac-defs">
                <?php ac_def('Amount', ac_h((string) $cl['amount']) . ' · paid ' . ac_h((string) $cl['paid_on'])); ?>
                <?php ac_def('From', ac_h((string) $cl['payer_name']) . ' · ' . ac_h((string) $cl['bank_name'])); ?>
                <?php ac_def('Status', $cl['status'] === 'pending' ? ac_pill('Waiting to be checked', 'warn')
                    : ($cl['status'] === 'verified' ? ac_status('recorded', 'Confirmed') : ac_pill('Not confirmed', 'bad') . ' <span class="ac-sub">' . ac_h((string) $cl['reject_reason']) . '</span>')); ?>
                <?php ac_def('Slip', '<a href="/api/admin/fee-evidence?id=' . (int) $cl['id'] . '">Download the ' . ($cl['evidence_type'] === 'application/pdf' ? 'PDF' : 'photo') . '</a>'); ?>
                <?php if (!empty($cl['note'])): ?><?php ac_def('Their note', ac_h((string) $cl['note'])); ?><?php endif; ?>
              </div>
              <?php if ($cl['status'] === 'pending'): ?>
                <?php /* The reference and amount are NOT shown here on purpose: confirming
                         means typing them from the bank statement, and a form that
                         prints the claim's own figures beside the boxes gets them copied. */ ?>
                <?php ac_form('fee_transfer_verify', 'invoices', ['invoice_id' => (int) $det['id'], 'claim_id' => (int) $cl['id']], 'class="ac-mt"'); ?>
                  <div class="ac-grid ac-grid--2">
                    <label class="ac-field"><span class="ac-label">Reference, as it appears on the bank statement</span><input class="ac-input" name="confirm_reference" required autocomplete="off"></label>
                    <label class="ac-field"><span class="ac-label">Amount received (<?= ac_h((string) $det['currency']) ?>)</span><input class="ac-input" name="confirm_amount" inputmode="decimal" required autocomplete="off"></label>
                  </div>
                  <label class="ac-tick ac-mt"><input type="checkbox" name="checked" value="1" required><span>I have seen this money arrive in the academy's account.</span></label>
                  <div class="ac-row ac-mt"><button type="submit" class="ac-btn ac-btn--primary">Confirm and credit the invoice</button></div>
                <?php ac_form_end(); ?>
                <?php ac_form('fee_transfer_reject', 'invoices', ['invoice_id' => (int) $det['id'], 'claim_id' => (int) $cl['id']], 'class="ac-row ac-mt"'); ?>
                  <input class="ac-input" name="reason" required minlength="<?= MAM_FEE_REASON_MIN ?>" placeholder="Why it cannot be confirmed — the family reads this" aria-label="Why the transfer cannot be confirmed">
                  <button type="submit" class="ac-btn ac-btn--danger">Not confirmed</button>
                <?php ac_form_end(); ?>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php ac_card_close('One slip is evidence for one payment, and one bank reference is one payment. A reference that was not confirmed cannot be reported again.'); ?>
      <?php endif; ?>

      <?php if (!empty($det['online'])): ?>
        <?php ac_card_open('Paid online', count($det['online']) . ''); ?>
          <div class="ac-tablewrap">
            <table class="ac-table">
              <thead><tr><th>Cleared</th><th class="ac-num">Paid</th><th class="ac-num">Credited</th><th>To refund</th></tr></thead>
              <tbody>
                <?php foreach ($det['online'] as $o): ?>
                  <tr>
                    <td><?= ac_h(substr((string) $o['verified_at'], 0, 10)) ?><div class="ac-sub"><?= ac_h($o['reference'] . ' · ' . ucfirst((string) $o['provider'])) ?></div></td>
                    <td class="ac-num"><?= ac_h(mam_fee_display((int) $o['fee_minor'], $cur)) ?><div class="ac-sub">₦<?= ac_h(number_format((int) $o['amount_minor'] / 100, 2)) ?> charged</div></td>
                    <td class="ac-num"><?= ac_h(mam_fee_display((int) $o['credited'], $cur)) ?></td>
                    <td><?php $un = $o['unapplied'] ?? null; ?>
                      <?php if ($un && $un['status'] !== 'open'): ?>
                        <?= ac_status('recorded', ['refunded' => 'Refunded', 'applied' => 'Moved to another invoice', 'dismissed' => 'Dismissed'][$un['status']] ?? $un['status']) ?>
                        <?php if (!empty($un['note'])): ?><div class="ac-sub"><?= ac_h((string) $un['note']) ?></div><?php endif; ?>
                      <?php elseif ((int) $o['refund_minor'] > 0): ?>
                        <?= ac_pill(mam_fee_display((int) $o['refund_minor'], $cur), 'bad') ?>
                        <?php if ($un): ?>
                          <?php ac_form('fee_unapplied_resolve', 'invoices', ['invoice_id' => (int) $det['id'], 'unapplied_id' => (int) $un['id']], 'class="ac-mt"'); ?>
                            <select class="ac-select" name="decision" required aria-label="What happened to the money">
                              <option value="refunded">Refunded at the gateway</option>
                              <?php if (!empty($det['open_siblings'])): ?><option value="applied">Move to another invoice</option><?php endif; ?>
                              <option value="dismissed">Dismissed</option>
                            </select>
                            <?php if (!empty($det['open_siblings'])): ?>
                              <select class="ac-select ac-mt" name="apply_to" aria-label="Which invoice to move it to">
                                <option value="">— invoice to move it to —</option>
                                <?php foreach ($det['open_siblings'] as $sb): ?><option value="<?= (int) $sb['id'] ?>"><?= ac_h($sb['public_ref'] . ' · ' . $sb['term_label'] . ' · ' . mam_fee_display((int) $sb['due_minor'], (string) $sb['currency']) . ' owed') ?></option><?php endforeach; ?>
                              </select>
                            <?php endif; ?>
                            <input class="ac-input ac-mt" name="note" placeholder="Refund reference, or why" aria-label="Note">
                            <button type="submit" class="ac-btn ac-btn--sm ac-mt">Record</button>
                          <?php ac_form_end(); ?>
                        <?php endif; ?>
                      <?php else: ?><?= ac_h('—') ?><?php endif; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php ac_card_close('A charge that cleared after the balance was already settled, by hand or by a void, is credited only what was still owed. The rest belongs to the family; refund it from the gateway\'s own dashboard.'); ?>
      <?php endif; ?>

      <?php ac_card_open('Payments received', count($det['payments']) . ''); ?>
        <div class="ac-tablewrap">
          <table class="ac-table">
            <thead><tr><th>Received</th><th>How</th><th class="ac-num">Amount</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <?php if (!$det['payments']): ?>
                <?php ac_table_empty(5, 'Nothing recorded yet.'); ?>
              <?php else: foreach ($det['payments'] as $p): ?>
                <tr>
                  <td><?= ac_h((string) $p['received_on']) ?><div class="ac-sub"><?= ac_h(trim((string) ($p['reference'] ?? '') . ($p['recorded_by_name'] ? ' · by ' . $p['recorded_by_name'] : ''))) ?></div></td>
                  <td><?= ac_h((string) ($methods[$p['method']] ?? $p['method'])) ?></td>
                  <td class="ac-num"><?= ac_h(mam_fee_display((int) $p['amount_minor'], (string) $p['currency'])) ?></td>
                  <td><?= $p['status'] === 'void' ? ac_pill('Void', 'bad') . '<div class="ac-sub">' . ac_h((string) $p['void_reason']) . '</div>' : ac_status('recorded', 'Recorded') ?></td>
                  <td><?php if ($p['status'] !== 'void'): ?>
                    <?php ac_form('fee_payment_void', 'invoices', ['invoice_id' => (int) $det['id'], 'payment_id' => (int) $p['id']], 'class="ac-row"'); ?>
                      <input class="ac-input ac-input--sm" name="reason" required placeholder="Why it is void" aria-label="Reason for voiding this payment">
                      <button type="submit" class="ac-btn ac-btn--sm ac-btn--danger">Void</button>
                    <?php ac_form_end(); ?>
                  <?php endif; ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <?php if (in_array($det['status'], ['issued', 'part_paid'], true) && (int) $det['due_minor'] > 0): ?>
          <div class="ac-cardbody">
            <?php ac_form('fee_payment_record', 'invoices', ['invoice_id' => (int) $det['id']]); ?>
              <div class="ac-grid ac-grid--2">
                <label class="ac-field"><span class="ac-label">Amount (<?= ac_h($cur) ?>)</span><input class="ac-input" name="amount" inputmode="decimal" required placeholder="<?= ac_h(mam_fee_from_minor((int) $det['due_minor'])) ?>"></label>
                <label class="ac-field"><span class="ac-label">How it was paid</span>
                  <select class="ac-select" name="method" required>
                    <?php foreach ($methods as $k => $lbl): ?><option value="<?= ac_h($k) ?>"><?= ac_h($lbl) ?></option><?php endforeach; ?>
                  </select></label>
                <label class="ac-field"><span class="ac-label">Received on</span><input class="ac-input" type="date" name="received_on"></label>
                <label class="ac-field"><span class="ac-label">Bank or teller reference</span><input class="ac-input" name="reference" maxlength="120"></label>
              </div>
              <div class="ac-row ac-mt"><button type="submit" class="ac-btn ac-btn--primary">Record the payment</button>
                <span class="ac-hint">At most what is still owed. It appears on the family's statement as a receipt.</span></div>
            <?php ac_form_end(); ?>
          </div>
        <?php endif; ?>
      <?php ac_card_close('A payment recorded in error is voided with a reason, never deleted; the balance comes back and the record shows both.'); ?>
    </div>

    <div class="ac-stack">
      <?php if ($det['status'] === 'draft'): $rv = $det['review'] ?? []; $blocked = (bool) array_filter($rv['checks'] ?? [], fn($c) => $c['level'] === 'block'); ?>
        <?php ac_card_open('Review, then issue', !empty($rv['reviewed']) ? 'reviewed' : 'not reviewed',
            !empty($rv['reviewed']) ? ac_pill('Reviewed', 'good') : ac_pill('Needs review', 'warn')); ?>
          <div class="ac-cardbody">
            <?php if ($rv['checks'] ?? []): ?>
              <ul class="ac-note">
                <?php foreach ($rv['checks'] as $ck): ?>
                  <li><?= $ck['level'] === 'block' ? ac_pill('Fix', 'bad') : ac_pill('Check', 'warn') ?> <?= ac_h((string) $ck['text']) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <p class="ac-note">Nothing looks wrong. Read the lines and totals once more as the family will.</p>
            <?php endif; ?>
            <?php if (!empty($rv['stale'])): ?>
              <p class="ac-note ac-mt">It was reviewed, then changed. The review covered the invoice as it was, so it needs reviewing again.</p>
            <?php endif; ?>
            <?php if (!empty($rv['reviewed'])): ?>
              <p class="ac-sub">Reviewed by <?= ac_h((string) ($rv['reviewer'] ?? '')) ?>, <?= ac_h(mam_console_age((string) ($rv['reviewed_at'] ?? ''))) ?>.</p>
              <?php ac_form('fee_invoice_issue', 'invoices', ['invoice_id' => (int) $det['id']]); ?>
                <label class="ac-field"><span class="ac-label">Due on (leave empty for the usual terms)</span><input class="ac-input" type="date" name="due_on"></label>
                <div class="ac-row ac-mt"><button type="submit" class="ac-btn ac-btn--primary">Issue to the family</button></div>
              <?php ac_form_end(); ?>
            <?php elseif (!$blocked): ?>
              <?php ac_form('fee_invoice_review', 'invoices', ['invoice_id' => (int) $det['id']]); ?>
                <label class="ac-field"><span class="ac-label">Note (optional)</span><input class="ac-input" name="note" maxlength="500"></label>
                <div class="ac-row ac-mt"><button type="submit" class="ac-btn ac-btn--primary">Reviewed — it is right</button>
                  <span class="ac-hint">Somebody other than whoever drafted it.</span></div>
              <?php ac_form_end(); ?>
            <?php endif; ?>
          </div>
        <?php ac_card_close('Issuing freezes the exchange rate and makes the invoice visible. Lines can still be added while it is unpaid.'); ?>
      <?php endif; ?>

      <?php if ($det['queries'] ?? []): ?>
        <?php ac_card_open('The family asked', count($det['queries']) . ''); ?>
          <div class="ac-cardbody">
            <?php foreach ($det['queries'] as $fq): $cur = (string) $det['currency']; ?>
              <div class="ac-mt">
                <div class="ac-strong"><?= ac_h(MAM_FEE_QUERY_KINDS[$fq['kind']] ?? (string) $fq['kind']) ?> · <?= ac_status((string) $fq['status']) ?></div>
                <div class="ac-sub"><?= ac_h((string) ($fq['raised_by_name'] ?? '')) ?>, <?= ac_h(mam_console_age((string) $fq['created_at'])) ?></div>
                <p class="ac-note"><?= nl2br(ac_h((string) $fq['message'])) ?></p>
                <?php if ($fq['proposed_minor'] !== null): ?><p class="ac-sub">Proposes a total of <?= ac_h(mam_fee_display((int) $fq['proposed_minor'], $cur)) ?></p><?php endif; ?>
                <?php if ($fq['proposed_instalments'] !== null): ?><p class="ac-sub">Proposes <?= (int) $fq['proposed_instalments'] ?> monthly instalments</p><?php endif; ?>
                <?php if ($fq['office_reply']): ?><p class="ac-sub"><strong>Reply:</strong> <?= ac_h((string) $fq['office_reply']) ?></p><?php endif; ?>
                <?php if ($fq['status'] === 'countered'): ?>
                  <p class="ac-sub">Offered <?= $fq['counter_minor'] !== null ? ac_h(mam_fee_display((int) $fq['counter_minor'], $cur)) : '' ?><?= $fq['counter_instalments'] !== null ? ' ' . (int) $fq['counter_instalments'] . ' instalments' : '' ?> — waiting for the family.</p>
                <?php endif; ?>
                <?php if ($fq['status'] === 'open'): ?>
                  <?php ac_form('fee_query_decide', 'invoices', ['invoice_id' => (int) $det['id'], 'query_id' => (int) $fq['id']]); ?>
                    <label class="ac-field"><span class="ac-label">Reply the family will read</span><textarea class="ac-input" name="reply" rows="2" required></textarea></label>
                    <?php if ($fq['kind'] !== 'question'): ?>
                      <div class="ac-inline ac-mt">
                        <?php if ($fq['kind'] === 'amount'): ?><label class="ac-field ac-w-md"><span class="ac-label">Counter: total (<?= ac_h($cur) ?>)</span><input class="ac-input" name="counter_amount" inputmode="decimal"></label><?php endif; ?>
                        <?php if ($fq['kind'] === 'plan'): ?><label class="ac-field ac-w-md"><span class="ac-label">Counter: instalments</span><input class="ac-input" name="counter_instalments" type="number" min="2" max="6"></label><?php endif; ?>
                      </div>
                    <?php endif; ?>
                    <div class="ac-row ac-mt">
                      <?php if ($fq['kind'] === 'question'): ?>
                        <button type="submit" class="ac-btn ac-btn--primary" name="decision" value="answer">Send the answer</button>
                      <?php else: ?>
                        <button type="submit" class="ac-btn ac-btn--primary" name="decision" value="accept">Agree</button>
                        <button type="submit" class="ac-btn" name="decision" value="counter">Offer the counter</button>
                        <button type="submit" class="ac-btn" name="decision" value="decline">Decline</button>
                      <?php endif; ?>
                    </div>
                  <?php ac_form_end(); ?>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php ac_card_close('Agreeing a lower total is recorded as a waiver with the query\'s number as its reason. Reminders about this invoice stop while a question is open.'); ?>
      <?php endif; ?>

      <?php if ($editable): ?>
        <?php ac_card_open('Discount code', ($det['codes_used'] ?? []) ? count($det['codes_used']) . ' used' : ''); ?>
          <div class="ac-cardbody">
            <?php foreach ($det['codes_used'] ?? [] as $cu): ?>
              <p class="ac-sub"><?= ac_h((string) $cu['code']) ?> · <?= ac_h((string) $cu['label']) ?> · <?= ac_h(mam_fee_display((int) $cu['amount_minor'], (string) $det['currency'])) ?> off</p>
            <?php endforeach; ?>
            <?php ac_form('fee_code_apply', 'invoices', ['invoice_id' => (int) $det['id']]); ?>
              <div class="ac-inline"><label class="ac-field ac-w-md"><span class="ac-label">Code</span><input class="ac-input" name="code" maxlength="24"></label>
                <button type="submit" class="ac-btn">Apply</button></div>
            <?php ac_form_end(); ?>
          </div>
        <?php ac_card_close(); ?>
      <?php endif; ?>

      <?php if ($editable): ?>
        <?php ac_card_open('Aid on this invoice', $det['aid'] ? (string) $det['aid'] : 'none'); ?>
          <div class="ac-cardbody">
            <?php $gift = array_values(array_filter($det['awards'], fn($a) => $a['status'] === 'active' && in_array($a['kind'], ['bursary', 'scholarship'], true))); ?>
            <?php if ($gift): ?>
              <?php ac_form('fee_credit_apply', 'invoices', ['invoice_id' => (int) $det['id']]); ?>
                <label class="ac-field"><span class="ac-label">Apply an award</span>
                  <select class="ac-select" name="award_id">
                    <?php foreach ($gift as $a): ?>
                      <option value="<?= (int) $a['id'] ?>"><?= ac_h(ucfirst((string) $a['kind']) . ' · ' . ($a['pct'] !== null ? rtrim(rtrim(number_format((float) $a['pct'], 1), '0'), '.') . '%' : mam_fee_display((int) $a['amount_minor'], (string) $a['currency'])) . ($a['basis'] ? ' · ' . $a['basis'] : '')) ?></option>
                    <?php endforeach; ?>
                  </select></label>
                <div class="ac-row ac-mt"><button type="submit" class="ac-btn">Apply</button></div>
              <?php ac_form_end(); ?>
            <?php else: ?>
              <p class="ac-note">No active bursary or scholarship for this student. Award one below, or decide their bursary application.</p>
            <?php endif; ?>
            <details class="ac-mt"><summary>Award aid to <?= ac_h((string) $det['student_name']) ?></summary>
              <?php ac_form('fee_aid_award', 'invoices', ['invoice_id' => (int) $det['id'], 'student_id' => (int) $det['student_id'], 'term_label' => (string) $det['term_label']]); ?>
                <div class="ac-grid ac-grid--2">
                  <label class="ac-field"><span class="ac-label">Kind</span>
                    <select class="ac-select" name="kind"><option value="scholarship">Scholarship (merit)</option><option value="bursary">Bursary (need)</option><option value="sibling">Sibling discount</option></select></label>
                  <label class="ac-field"><span class="ac-label">Percentage</span><input class="ac-input" name="pct" inputmode="decimal" placeholder="e.g. 25"></label>
                  <label class="ac-field"><span class="ac-label">…or a fixed amount</span><input class="ac-input" name="amount" inputmode="decimal" placeholder="e.g. 100.00"></label>
                  <label class="ac-field"><span class="ac-label">Basis</span><input class="ac-input" name="basis" placeholder="Audition result, June"></label>
                </div>
                <div class="ac-row ac-mt"><button type="submit" class="ac-btn">Award</button></div>
              <?php ac_form_end(); ?>
            </details>
          </div>
        <?php ac_card_close('A bursary from an application is decided on the Bursaries screen, against the published band.'); ?>
      <?php endif; ?>

      <?php if ($det['plan']): $pr = $det['plan_row']; ?>
        <?php ac_card_open('Payment plan', (int) $det['plan']['instalments'] . ' instalments', $det['plan']['approved'] ? ac_pill('Approved', 'good') : ac_pill('Requested', 'warn')); ?>
          <div class="ac-cardbody">
            <div class="ac-defs">
              <?php foreach ($det['plan']['schedule'] as $i) ac_def('#' . (int) $i['seq'] . ' · ' . ac_h((string) $i['due_on']), ac_h((string) $i['amount']) . ' ' . ac_status((string) $i['status'])); ?>
            </div>
            <div class="ac-row ac-mt">
              <?php if (!$det['plan']['approved']) ac_action_btn('fee_plan_approve', 'invoices', ['invoice_id' => (int) $det['id'], 'plan_id' => (int) $det['plan']['id']], 'Approve the plan', 'ac-btn ac-btn--sm ac-btn--primary'); ?>
            </div>
            <?php ac_form('fee_plan_cancel', 'invoices', ['invoice_id' => (int) $det['id'], 'plan_id' => (int) $det['plan']['id']], 'class="ac-row ac-mt"'); ?>
              <input class="ac-input ac-input--sm" name="reason" required placeholder="Why" aria-label="Reason for cancelling the plan">
              <button type="submit" class="ac-btn ac-btn--sm"><?= $det['plan']['approved'] ? 'Cancel the plan' : 'Decline the request' ?></button>
            <?php ac_form_end(); ?>
          </div>
        <?php ac_card_close('Approved, reminders follow the instalments; until then they follow the invoice\'s own due date.'); ?>
      <?php endif; ?>

      <?php if ($det['status'] !== 'void' && $det['status'] !== 'paid'): ?>
        <?php ac_card_open('Void'); ?>
          <div class="ac-cardbody">
            <?php ac_form('fee_invoice_void', 'invoices', ['invoice_id' => (int) $det['id']]); ?>
              <label class="ac-field"><span class="ac-label">Reason</span><input class="ac-input" name="reason" required placeholder="Raised twice in error"></label>
              <div class="ac-row ac-mt"><button type="submit" class="ac-btn ac-btn--danger">Void the invoice</button></div>
            <?php ac_form_end(); ?>
          </div>
        <?php ac_card_close('A voided invoice stays on the record with its reason.'); ?>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php if (($d['drafts_waiting'] ?? []) || ($d['queries_open'] ?? [])): ?>
<div class="ac-cols ac-cols--2">
  <?php ac_card_open('Drafts', count($d['drafts_waiting'] ?? []) . '', !empty($d['review_required']) ? '<span class="ac-cardmeta">Reviewed by a second person before issue</span>' : ''); ?>
    <div class="ac-cardbody">
      <?php if (!($d['drafts_waiting'] ?? [])): ?><p class="ac-note">No drafts.</p><?php else: ?>
        <div class="ac-defs">
          <?php foreach ($d['drafts_waiting'] as $dw): ?>
            <?php ac_def((string) $dw['student'] . ' · ' . mam_fee_display((int) $dw['total_minor'], (string) $dw['currency']),
                '<a href="' . ac_h(ac_url('invoices', ['id' => (int) $dw['id']])) . '">' . ac_h((string) $dw['public_ref']) . '</a> · '
                . ($dw['reviewed_at'] ? 'reviewed' : 'drafted by ' . ac_h((string) ($dw['drafted_by'] ?? '—')))); ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php ac_card_close(); ?>
  <?php ac_card_open('Families asking', count($d['queries_open'] ?? []) . ''); ?>
    <div class="ac-cardbody">
      <?php if (!($d['queries_open'] ?? [])): ?><p class="ac-note">No family is waiting on an answer.</p><?php else: ?>
        <div class="ac-defs">
          <?php foreach ($d['queries_open'] as $qo): ?>
            <?php ac_def((string) $qo['student'] . ' · ' . (MAM_FEE_QUERY_KINDS[$qo['kind']] ?? $qo['kind']),
                '<a href="' . ac_h(ac_url('invoices', ['id' => (int) $qo['invoice_id']])) . '">' . ac_h((string) $qo['public_ref']) . '</a> · '
                . ((string) $qo['status'] === 'countered' ? 'waiting for the family' : 'asked ' . ac_h(mam_console_age((string) $qo['created_at'])))); ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php ac_card_close(); ?>
</div>
<?php endif; ?>

<div class="ac-cols ac-cols--2">
  <?php ac_card_open('New invoice'); ?>
    <div class="ac-cardbody">
      <?php ac_form('fee_invoice_open', 'invoices'); ?>
        <div class="ac-grid ac-grid--2">
          <label class="ac-field"><span class="ac-label">Student</span>
            <select class="ac-select" name="student_id" required>
              <option value="">Choose…</option>
              <?php foreach ($d['students'] ?? [] as $st): ?>
                <option value="<?= (int) $st['id'] ?>"><?= ac_h((string) $st['name'] . ' · ' . (string) $st['email']) ?></option>
              <?php endforeach; ?>
            </select></label>
          <label class="ac-field"><span class="ac-label">Term (empty: the current one)</span><input class="ac-input" name="term_label" placeholder="Spring 2027"></label>
        </div>
        <div class="ac-row ac-mt"><button type="submit" class="ac-btn ac-btn--primary">Open a draft</button>
          <span class="ac-hint">Billed to the student's linked guardian. A draft is invisible to the family until issued.</span></div>
      <?php ac_form_end(); ?>
    </div>
  <?php ac_card_close(); ?>

  <?php ac_card_open('Transfers to check', count($d['claims_waiting'] ?? []) . ''); ?>
    <div class="ac-cardbody">
      <?php if (!($d['claims_waiting'] ?? [])): ?>
        <p class="ac-note">No family is waiting on a transfer to be confirmed.</p>
      <?php else: ?>
        <div class="ac-defs">
          <?php foreach ($d['claims_waiting'] as $cw): ?>
            <?php ac_def((string) $cw['student'] . ' · ' . mam_fee_display((int) $cw['amount_minor'], (string) $cw['currency']),
                '<a href="' . ac_h(ac_url('invoices', ['id' => (int) $cw['invoice_id']])) . '">' . ac_h((string) $cw['public_ref']) . '</a> · reported ' . ac_h(mam_console_age((string) $cw['created_at']))); ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php ac_card_close('Families were promised confirmation within 24 hours.'); ?>

  <?php if ($d['unapplied_open'] ?? []): ?>
    <?php ac_card_open('Online overpayments', count($d['unapplied_open']) . ''); ?>
      <div class="ac-cardbody"><div class="ac-defs">
        <?php foreach ($d['unapplied_open'] as $uo): ?>
          <?php ac_def((string) $uo['student'] . ' · ' . mam_fee_display((int) $uo['over_minor'], (string) $uo['currency']),
              '<a href="' . ac_h(ac_url('invoices', ['id' => (int) $uo['invoice_id']])) . '">' . ac_h((string) $uo['public_ref']) . '</a> · ' . ac_h(mam_console_age((string) $uo['created_at']))); ?>
        <?php endforeach; ?>
      </div></div>
    <?php ac_card_close('Money taken after the balance was already settled. Refund it, or move it to another of the child\'s invoices.'); ?>
  <?php endif; ?>

  <?php ac_card_open('Plans waiting for approval', count($d['plans_waiting'] ?? []) . ''); ?>
    <div class="ac-cardbody">
      <?php if (!($d['plans_waiting'] ?? [])): ?>
        <p class="ac-note">No family is waiting on a plan.</p>
      <?php else: ?>
        <div class="ac-defs">
          <?php foreach ($d['plans_waiting'] as $pw): ?>
            <?php ac_def((string) $pw['student'] . ' · ' . (int) $pw['instalments'] . ' instalments',
                '<a href="' . ac_h(ac_url('invoices', ['id' => (int) $pw['invoice_id']])) . '">' . ac_h((string) $pw['public_ref']) . '</a> · asked ' . ac_h(mam_console_age((string) $pw['created_at']))); ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php ac_card_close('A plan a family asks for is a request until the office agrees it.'); ?>

  <?php ac_card_open('Discount codes', count($d['codes'] ?? []) . ''); ?>
    <div class="ac-cardbody">
      <?php foreach ($d['codes'] ?? [] as $fc): ?>
        <p class="ac-sub"><span class="ac-mono"><?= ac_h((string) $fc['code']) ?></span> · <?= ac_h((string) $fc['label']) ?> ·
          <?= $fc['pct'] !== null ? ac_h(rtrim(rtrim((string) $fc['pct'], '0'), '.')) . '%' : ac_h(mam_fee_display((int) $fc['amount_minor'], (string) $fc['currency'])) ?> ·
          used <?= (int) $fc['used'] ?><?= $fc['max_uses'] !== null ? ' of ' . (int) $fc['max_uses'] : '' ?>
          <?= (int) $fc['active'] === 1 ? '' : ' · ' . ac_pill('Off') ?></p>
      <?php endforeach; ?>
      <?php ac_form('fee_code_save', 'invoices'); ?>
        <div class="ac-grid ac-grid--2 ac-mt">
          <label class="ac-field"><span class="ac-label">Code</span><input class="ac-input" name="code" maxlength="24" required></label>
          <label class="ac-field"><span class="ac-label">What it is for (the reason on each invoice)</span><input class="ac-input" name="label" maxlength="120" required></label>
          <label class="ac-field"><span class="ac-label">Per cent off</span><input class="ac-input" name="pct" inputmode="decimal"></label>
          <label class="ac-field"><span class="ac-label">or an amount off (<?= ac_h(mam_fee_currency()) ?>)</span><input class="ac-input" name="amount" inputmode="decimal"></label>
          <label class="ac-field"><span class="ac-label">Most uses (empty: no limit)</span><input class="ac-input" type="number" min="1" name="max_uses"></label>
          <label class="ac-field"><span class="ac-label">Valid until</span><input class="ac-input" type="date" name="ends_on"></label>
        </div>
        <input type="hidden" name="currency" value="<?= ac_h(mam_fee_currency()) ?>">
        <input type="hidden" name="once_per_family" value="1">
        <div class="ac-row ac-mt"><button type="submit" class="ac-btn ac-btn--primary">Save the code</button>
          <span class="ac-hint">Saving an existing code changes it. Once per family.</span></div>
      <?php ac_form_end(); ?>
    </div>
  <?php ac_card_close('A code reduces what was billed, as a waiver with the code as its reason — never a payment.'); ?>

  <?php ac_card_open('Review before sending'); ?>
    <div class="ac-cardbody">
      <?php ac_form('fee_review_settings', 'invoices'); ?>
        <label class="ac-sub"><input type="checkbox" name="required" value="1" <?= !empty($d['review_required']) ? 'checked' : '' ?>> A draft is reviewed before it goes to a family</label><br>
        <label class="ac-sub"><input type="checkbox" name="two_person" value="1" <?= !empty($d['review_two_person']) ? 'checked' : '' ?>> …by somebody other than whoever drafted it</label>
        <div class="ac-row ac-mt"><button type="submit" class="ac-btn">Save</button></div>
      <?php ac_form_end(); ?>
      <p class="ac-note ac-mt">An office of one should untick the second box, not the first: the check still happens, by the same person.</p>
    </div>
  <?php ac_card_close(); ?>
</div>

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
          <?php ac_table_empty(6, 'No fee invoices yet. Open one above: a tuition invoice is raised per term, per learner.'); ?>
        <?php else: foreach ($d['rows'] as $r):
          $overdueDays = $r['due_on'] && (string) $r['status'] !== 'paid'
              ? (int) floor((time() - (int) strtotime((string) $r['due_on'])) / 86400) : 0;
          $aid = (int) ($r['aid_minor'] ?? 0) + (int) ($r['waiver_minor'] ?? 0) + (int) ($r['sibling_minor'] ?? 0); ?>
          <tr>
            <td class="ac-mono"><a href="<?= ac_h(ac_url('invoices', ['id' => (int) $r['id']])) ?>"><?= ac_h((string) ($r['public_ref'] ?: '#' . (int) $r['id'])) ?></a></td>
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
