<?php
/** Bursaries & awards — aid from a named fund, assessed on an income band. */
declare(strict_types=1);
/** @var array $d */
$awardTotals = [];
foreach ($d['awards'] ?? [] as $a) {
    $k = (string) $a['kind'];
    $awardTotals[$k] = ($awardTotals[$k] ?? 0) + (int) $a['minor'];
}
?>
<?php ac_head('Bursaries & awards',
    'Aid comes out of a named pool · assessed on an income band, never on an essay',
    '<a class="ac-btn" href="' . ac_h(ac_url('invoices')) . '">Invoices</a>'); ?>

<?php ac_kpis([
    ['label' => 'Awaiting a decision', 'value' => (string) (int) ($d['pending'] ?? 0),
     'tone' => (int) ($d['pending'] ?? 0) > 0 ? 'warn' : '', 'note' => 'An aid decision never reaches an admissions decision'],
    ['label' => 'Bursary credited', 'value' => mam_console_usd_short((int) ($awardTotals['bursary'] ?? 0)),
     'note' => 'Needs-based gift aid, posted as a credit'],
    ['label' => 'Scholarship credited', 'value' => mam_console_usd_short((int) ($awardTotals['scholarship'] ?? 0)),
     'note' => (int) ($d['scholars'] ?? 0) . ' account' . ((int) ($d['scholars'] ?? 0) === 1 ? '' : 's') . ' on the scholarship tier'],
    ['label' => 'Sibling discount', 'value' => ((float) ($d['sibling_pct'] ?? 0)) > 0
        ? rtrim(rtrim(number_format((float) $d['sibling_pct'], 1), '0'), '.') . '%' : 'Not set',
     'note' => 'A discount, not aid — applied after aid and capped'],
]); ?>

<?php ac_banner('flat', 'fa-circle-info',
    '<strong>Four kinds of aid are four mechanisms, in one order.</strong> '
    . 'A <strong>waiver</strong> reduces the invoice line and is never a cash entry. A <strong>bursary</strong> and a '
    . '<strong>scholarship</strong> are gift-aid credits against the invoice. <strong>Sibling</strong> is a discount on '
    . 'the post-aid balance — a percentage of the gross would discount money the aid pool paid — and the cap is on the '
    . 'amount, not the percentage.'); ?>

<div class="ac-cols ac-cols--main">
  <?php ac_card_open('Requests', (int) ($d['pending'] ?? 0) . ' pending', '<span class="ac-cardmeta">An aid decision never reaches an admissions decision</span>'); ?>
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead><tr><th>Family</th><th>Band</th><th class="ac-num">Awarded</th><th>Status</th><th class="ac-num">Raised</th></tr></thead>
        <tbody>
          <?php if (!($d['rows'] ?? [])): ?>
            <?php ac_table_empty(5, 'No bursary applications yet. Guardians apply from their portal\'s Fees screen.'); ?>
          <?php else: foreach ($d['rows'] as $r): ?>
            <tr>
              <td>
                <div class="ac-strong"><?= ac_h((string) ($r['student'] ?? 'Applicant')) ?></div>
                <div class="ac-sub"><?= ac_h(trim(implode(' · ', array_filter([
                    (string) ($r['term_label'] ?? ''),
                    $r['guardian'] ? 'guardian ' . (string) $r['guardian'] : '',
                    $r['household_size'] ? 'household ' . (int) $r['household_size'] : '',
                    (int) ($r['dependants_in_academy'] ?? 0) > 1 ? (int) $r['dependants_in_academy'] . ' in the academy' : '',
                ])))) ?></div>
              </td>
              <td><?= ac_pill('Band ' . strtoupper((string) ($r['income_band'] ?? '?'))) ?></td>
              <td class="ac-num"><?= $r['awarded_pct'] !== null ? (int) $r['awarded_pct'] . '%' : '—' ?></td>
              <td><?= ac_status((string) $r['status']) ?></td>
              <td class="ac-num"><?= ac_h(mam_console_age((string) $r['created_at'])) ?></td>
            </tr>
            <?php if (!empty($r['outcome_note'])): ?>
              <tr><td colspan="5"><div class="ac-sub"><?= ac_h((string) $r['outcome_note']) ?></div></td></tr>
            <?php endif; ?>
            <?php if (in_array((string) $r['status'], ['submitted', 'assessing'], true)):
              $ceil = null;
              foreach ($d['bands'] ?? [] as $b) if (is_array($b) && ($b['code'] ?? null) === $r['income_band']) $ceil = $b['indicative_pct'] ?? null; ?>
              <tr><td colspan="5">
                <details><summary>Decide this application</summary>
                  <?php ac_form('bursary_decide', 'bursaries', ['app_id' => (int) $r['id']]); ?>
                    <div class="ac-grid ac-grid--2">
                      <label class="ac-field"><span class="ac-label">Decision</span>
                        <select class="ac-select" name="status" required>
                          <option value="awarded" <?= $ceil === null ? 'disabled' : '' ?>>Award<?= $ceil === null ? ' — this band has no published award' : '' ?></option>
                          <option value="declined">Decline</option>
                          <option value="assessing">Still assessing</option>
                          <option value="withdrawn">Withdrawn by the family</option>
                        </select></label>
                      <label class="ac-field"><span class="ac-label">Award %<?= $ceil !== null ? ' (at most ' . (int) $ceil . ')' : '' ?></span>
                        <input class="ac-input" name="pct" inputmode="decimal" placeholder="<?= $ceil !== null ? (int) $ceil : '' ?>"></label>
                    </div>
                    <label class="ac-field ac-mt"><span class="ac-label">The note the family will read</span>
                      <textarea class="ac-textarea" name="outcome_note" rows="2" placeholder="Needed for an award or a refusal. Never mentions the admissions file."></textarea></label>
                    <div class="ac-row ac-mt"><button type="submit" class="ac-btn ac-btn--primary">Record the decision</button>
                      <span class="ac-hint">An award becomes aid on the student's record; apply it to their invoice on the Invoices screen.</span></div>
                  <?php ac_form_end(); ?>
                </details>
              </td></tr>
            <?php endif; ?>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php ac_card_close('A refusal is also a decision the family reads. Both outcomes need a note, and neither mentions the admissions file.'); ?>

  <div class="ac-stack">
    <?php ac_card_open('Income bands', 'Published ceilings'); ?>
      <div class="ac-cardbody">
        <?php if (!($d['bands'] ?? [])): ?>
          <div class="ac-note">No bands configured.</div>
        <?php else: ?>
          <div class="ac-defs">
            <?php foreach ($d['bands'] as $key => $b):
              $label = is_array($b) ? (string) ($b['label'] ?? $key) : (string) $b;
              $pct   = is_array($b) ? ($b['pct'] ?? $b['max_pct'] ?? null) : null; ?>
              <?php ac_def('Band ' . strtoupper((string) $key),
                  ac_h($label) . ($pct !== null ? ' <span class="ac-subi">up to ' . (int) $pct . '%</span>' : '')); ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php ac_card_close('A band comes from a declared range and one document, not from a written case for hardship. The published table is a ceiling a committee may go under and never over — and there is no column here for circumstances, on purpose.'); ?>

    <?php ac_card_open('Where aid is spent', 'By kind'); ?>
      <div class="ac-cardbody">
        <?php if (!($d['awards'] ?? [])): ?>
          <div class="ac-note">Nothing awarded yet.</div>
        <?php else: ?>
          <div class="ac-defs">
            <?php foreach ($d['awards'] as $a): ?>
              <?php ac_def(ucfirst((string) $a['kind']) . ' (' . (int) $a['n'] . ')',
                  ac_h(mam_console_usd((int) $a['minor']))); ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php ac_card_close('Gift aid and a discount are summed separately because only one of them is a cost the academy carries.'); ?>
  </div>
</div>
