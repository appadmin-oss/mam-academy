<?php
/** Governance & audit — what the academy can prove, and what it owes people. */
declare(strict_types=1);
/** @var array $d */
$vet = $d['vetting'] ?? [];
$tier = $d['tier'] ?? [];
?>
<?php ac_head('Governance & audit',
    'What the academy can prove, and what it owes people'); ?>

<?php ac_kpis([
    ['label' => 'Audit entries', 'value' => number_format((int) ($d['total'] ?? 0)),
     'note' => 'head at ' . number_format((int) ($d['head'] ?? 0))],
    ['label' => 'Open subject requests', 'value' => (string) count($d['dp_open'] ?? []),
     'tone' => count($d['dp_open'] ?? []) > 0 ? 'warn' : '', 'note' => 'thirty days is the academy\'s own commitment'],
    ['label' => 'Safeguarding gaps', 'value' => (string) ((int) ($vet['gaps'] ?? 0) + (int) ($vet['expiring'] ?? 0)),
     'tone' => ((int) ($vet['gaps'] ?? 0)) > 0 ? 'bad' : '',
     'note' => 'gate in ' . ac_h((string) ($vet['mode'] ?? 'warn')) . ' mode'],
    ['label' => 'Data subjects measured', 'value' => (string) (int) ($tier['count'] ?? 0),
     'note' => (string) ($tier['tier']['label'] ?? 'below the threshold')],
]); ?>

<?php ac_card_open('Recent consequential actions', count($d['recent'] ?? []) . '',
    '<span class="ac-cardmeta">Actor and credential on every row</span>'); ?>
  <div class="ac-tablewrap">
    <table class="ac-table">
      <thead><tr><th class="ac-num">When</th><th>Action</th><th>On</th><th>Actor</th><th>Detail</th></tr></thead>
      <tbody>
        <?php if (!($d['recent'] ?? [])): ?>
          <?php ac_table_empty(5, 'The audit log is empty. Every mutation writes one row.'); ?>
        <?php else: foreach ($d['recent'] as $a):
          $meta = json_decode((string) ($a['meta'] ?? ''), true); ?>
          <tr>
            <td class="ac-num ac-mono"><?= ac_h(mam_console_date((string) $a['created_at'], 'j M H:i')) ?></td>
            <td class="ac-strong"><?= ac_h(str_replace('_', ' ', (string) $a['action'])) ?></td>
            <td><?= ac_h((string) ($a['entity'] ?? '')) ?><?= $a['entity_id'] ? ' #' . (int) $a['entity_id'] : '' ?></td>
            <td><?= ac_h((string) ($a['actor'] ?: 'system')) ?>
                <?php if (!empty($a['ip'])): ?><div class="ac-sub ac-mono"><?= ac_h((string) $a['ip']) ?></div><?php endif; ?></td>
            <td class="ac-sub"><?= ac_h(is_array($meta)
                ? mb_strimwidth(implode(' · ', array_map(
                    fn($k, $v) => $k . '=' . (is_scalar($v) ? (string) $v : json_encode($v)),
                    array_keys($meta), $meta)), 0, 120, '…')
                : '') ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
<?php ac_card_close(
    'The <strong>actor</strong> column names the credential; where the break-glass credential was used the ledger '
    . 'still names an identity, so "who did this" and "which key opened the door" are two separate answers. '
    . ((int) ($d['break_glass'] ?? 0) > 0
        ? '<strong>' . (int) $d['break_glass'] . ' action' . ((int) $d['break_glass'] === 1 ? '' : 's')
          . '</strong> on this install used the recovery credential.'
        : 'The recovery credential has not been used here.')); ?>

<div class="ac-cols ac-cols--3">
  <?php ac_card_open('Safeguarding'); ?>
    <div class="ac-cardbody">
      <div class="ac-defs">
        <?php
          ac_def('Gate mode', ac_pill(ucfirst((string) ($vet['mode'] ?? 'warn')),
              ($vet['mode'] ?? '') === 'enforce' ? 'good' : 'warn'));
          ac_def('Instructors', (string) (int) ($vet['instructors'] ?? 0));
          ac_def('Cleared for minors', (string) (int) ($vet['cleared'] ?? 0));
          ac_def('Missing a check', (string) (int) ($vet['gaps'] ?? 0));
          ac_def('Lapsing soon', (string) (int) ($vet['expiring'] ?? 0));
        ?>
      </div>
    </div>
  <?php ac_card_close('Gaps are audited in every mode, so the record exists BEFORE enforcement rather than after. <a href="' . ac_h(ac_url('vetting')) . '">Staff & vetting</a>.'); ?>

  <?php ac_card_open('Subject requests', count($d['dp_open'] ?? []) . ' open'); ?>
    <div class="ac-cardbody">
      <?php if (!($d['dp_open'] ?? [])): ?>
        <div class="ac-note">Nothing open.</div>
      <?php else: ?>
        <div class="ac-defs">
          <?php foreach ($d['dp_open'] as $r): ?>
            <?php ac_def(ucfirst((string) $r['kind']) . ' · ' . (string) ($r['subject_email'] ?? ''),
                ac_h(mam_console_date((string) $r['received_at'], 'j M'))); ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <div class="ac-note ac-mt">Thirty days is the academy's own commitment, <strong>not a
      statutory deadline</strong>, and the clock runs from the request rather than from now.</div>
    </div>
  <?php ac_card_close('<a href="' . ac_h(ac_url('dataprotection')) . '">Data protection</a>.'); ?>

  <?php ac_card_open('Retention and registration'); ?>
    <div class="ac-cardbody">
      <div class="ac-defs">
        <?php
          ac_def('Retention sweep', ac_pill(ucfirst((string) ($d['retention_mode'] ?? 'report')),
              ($d['retention_mode'] ?? '') === 'enforce' ? 'warn' : ''));
          ac_def('Distinct subjects, 6 mo', (string) (int) ($tier['count'] ?? 0));
          /* mam_dp_tier() returns the tier as a RECORD and the two duties as
             must_register / must_file_car. This read `tier` as a string and
             `files_returns`, which does not exist — so it printed "Array" and
             answered "No" to the filing duty at every size. */
          ac_def('Registration tier', ac_h((string) ($tier['tier']['label'] ?? '—')));
          ac_def('Files returns?', !empty($tier['must_file_car']) ? 'Yes' : 'No');
        ?>
      </div>
      <div class="ac-note ac-mt">The tier is MEASURED over a rolling six months and deduplicated
      by address, because a guardian who applied, subscribed and messaged is one data subject — counting them three
      times puts the academy in a filing tier it does not belong in.</div>
    </div>
  <?php ac_card_close(); ?>
</div>
