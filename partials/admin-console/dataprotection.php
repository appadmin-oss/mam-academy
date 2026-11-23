<?php
/** Data protection — subject requests, breaches, the register, the retention sweep. */
declare(strict_types=1);
/** @var array $d */
$mode = (string) ($d['mode'] ?? 'report');
$tier = $d['tier'] ?? [];
$dl = (array) ($d['deadlines'] ?? []);
$open = array_values(array_filter($d['requests'] ?? [], fn($r) => empty($r['closed_at'])));
?>
<?php ac_head('Data protection',
    'Subject requests, breaches, the processing register and the retention sweep'); ?>

<?php ac_kpis([
    ['label' => 'Open requests', 'value' => (string) count($open),
     'tone' => count($open) > 0 ? 'warn' : '', 'note' => count($open) > 0 ? 'clocked from the request' : 'nothing outstanding'],
    ['label' => 'Breaches logged', 'value' => (string) count($d['breaches'] ?? []),
     'tone' => count($d['breaches'] ?? []) > 0 ? 'bad' : '', 'note' => 'clocked from awareness, not occurrence'],
    ['label' => 'Register purposes', 'value' => (string) count($d['purposes'] ?? []), 'note' => 'derived from the code'],
    ['label' => 'Retention mode', 'value' => ucfirst($mode),
     'tone' => $mode === 'enforce' ? 'warn' : '', 'note' => $mode === 'report' ? 'deletes nothing' : 'applies delete rules'],
]); ?>

<div class="ac-cols ac-cols--main">
  <div class="ac-stack">
    <?php ac_card_open('Subject requests', count($d['requests'] ?? []) . ' on file'); ?>
      <div class="ac-tablewrap">
        <table class="ac-table">
          <thead><tr><th>Kind</th><th>Subject</th><th class="ac-num">Received</th><th>Clock</th><th>Outcome</th></tr></thead>
          <tbody>
            <?php if (!($d['requests'] ?? [])): ?>
              <?php ac_table_empty(5, 'No requests recorded. An admin used to type an email, download a bundle, and nothing survived — this is the record that did not exist.'); ?>
            <?php else: foreach ($d['requests'] as $r):
              $c = $r['clock'] ?? []; ?>
              <tr>
                <td><?= ac_pill(ucfirst((string) $r['kind']), (string) $r['kind'] === 'erasure' ? 'warn' : '') ?></td>
                <td><?= ac_h((string) ($r['subject_email'] ?? '—')) ?>
                    <?php if (!empty($r['channel'])): ?><div class="ac-sub">via <?= ac_h((string) $r['channel']) ?></div><?php endif; ?></td>
                <td class="ac-num"><?= ac_h(mam_console_date((string) $r['received_at'], 'j M Y')) ?></td>
                <td>
                  <?php if (!empty($r['closed_at'])): ?><?= ac_pill('Closed', 'good') ?>
                  <?php elseif (!empty($c['overdue'])): ?><?= ac_pill('Overdue', 'bad') ?>
                  <?php elseif (isset($c['days_left'])): ?><?= ac_pill((int) $c['days_left'] . 'd left', (int) $c['days_left'] <= 7 ? 'warn' : '') ?>
                  <?php else: ?><span class="ac-sub">—</span><?php endif; ?>
                </td>
                <td><?= ac_h((string) ($r['outcome'] ?: '—')) ?>
                    <?php if (!empty($r['refusal_reason'])): ?>
                      <div class="ac-sub"><?= ac_h(mb_strimwidth((string) $r['refusal_reason'], 0, 90, '…')) ?></div>
                    <?php endif; ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    <?php ac_card_close(
        (isset($dl['request_days'])
          ? '<strong>' . (int) $dl['request_days'] . ' days is the academy\'s own commitment, not a statutory '
            . 'deadline.</strong> The NDPA fixes none (the repealed instrument did), and this screen says so. '
          : '')
        . 'A closed request is judged against when it was CLOSED, not against now — measuring from now turns every '
        . 'historic record overdue as time passes.'); ?>

    <?php ac_card_open('The retention schedule', count($d['retention'] ?? []) . ' rules',
        ac_pill(strtoupper($mode), $mode === 'enforce' ? 'warn' : '')); ?>
      <div class="ac-tablewrap">
        <table class="ac-table">
          <thead><tr><th>Data</th><th>Table</th><th class="ac-num">After</th><th>Then</th></tr></thead>
          <tbody>
            <?php if (!($d['retention'] ?? [])): ?>
              <?php ac_table_empty(4, 'The schedule could not be read.'); ?>
            <?php else: foreach ($d['retention'] as $key => $r): ?>
              <tr>
                <td><?= ac_h((string) ($r['label'] ?? $key)) ?></td>
                <td class="ac-mono"><?= ac_h((string) ($r['table'] ?? '—')) ?></td>
                <td class="ac-num"><?= isset($r['months']) ? (int) $r['months'] . ' mo' : '—' ?></td>
                <td><?= ac_pill(strtoupper((string) ($r['action'] ?? 'review')),
                    (string) ($r['action'] ?? '') === 'delete' ? 'bad' : 'warn') ?></td>
              </tr>
            <?php endforeach; endif; ?>
            <?php
              /* Not a table, and deliberately not an entry in the code
                 registry above — that one is asserted table-by-table against
                 the live schema. It is listed here because the SCHEDULE has to
                 account for every store of personal data, and a log line
                 carries an IP and a user agent like any row does. */
              $lg = (array) ($d['logs'] ?? []);
            ?>
            <tr>
              <td>Application logs <span class="ac-sub">IP and user agent per line<?= isset($lg['overdue']) && (int) $lg['overdue'] > 0
                  ? ' · ' . (int) $lg['overdue'] . ' file' . ((int) $lg['overdue'] === 1 ? '' : 's') . ' past the period' : '' ?></span></td>
              <td class="ac-mono">api/logs/app-*.log</td>
              <td class="ac-num"><?= (int) ($d['log_keep_days'] ?? 90) ?> d</td>
              <td><?= ac_pill('DELETE', 'bad') ?></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="ac-cardbody">
        <div class="ac-row">
          <?php ac_action_btn('dp_retention_preview', 'dataprotection', [], 'Preview · changes nothing', 'ac-btn'); ?>
          <?php ac_form('dp_retention_mode', 'dataprotection'); ?>
            <div class="ac-inline">
              <select class="ac-select ac-select--sm ac-w-sm" name="mode" aria-label="Retention sweep mode">
                <option value="report" <?= $mode === 'report' ? 'selected' : '' ?>>Report only</option>
                <option value="enforce" <?= $mode === 'enforce' ? 'selected' : '' ?>>Enforce</option>
              </select>
              <button type="submit" class="ac-btn">Set the mode</button>
            </div>
          <?php ac_form_end(); ?>
        </div>
      </div>
    <?php ac_card_close(
        'A rule marked <strong>review</strong> is never applied automatically, in any mode — the academic record, '
        . 'payments, vetting and the audit log are human decisions by construction. <strong>Delete</strong> is legal '
        . 'only on a leaf table, asserted against the live foreign-key graph, because a cascade through an enrolment '
        . 'would destroy academic history to tidy up a note. A period may be LENGTHENED, never shortened: the schedule '
        . 'states a lawful basis for each period, and a smaller number typed into a form would delete lawfully-held records.'); ?>
  </div>

  <div class="ac-stack">
    <?php ac_card_open('Registration tier', 'Measured, not diarised'); ?>
      <div class="ac-cardbody">
        <div class="ac-defs">
          <?php
            ac_def('Distinct subjects, 6 months', (string) (int) ($tier['count'] ?? 0));
            /* See governance.php: the tier is a record, and the duty is
               `must_register` — `registers` does not exist, so this answered
               "No" to the registration duty at every size. */
            ac_def('Tier', ac_h((string) ($tier['tier']['label'] ?? '—')));
            ac_def('Must register?', !empty($tier['must_register']) ? 'Yes' : 'No');
            ac_def('Files audit returns?', !empty($tier['must_file_car']) ? 'Yes' : 'No');
          ?>
        </div>
        <p class="ac-note ac-mt">Counted over a rolling six months — the window the definition
        uses — and deduplicated by address, because a guardian who applied, subscribed and messaged is one data
        subject. Counting them three times puts the academy in a filing tier it does not belong in.</p>
      </div>
    <?php ac_card_close('Registering and filing returns are two different thresholds; the middle tier registers and does not file.'); ?>

    <?php ac_card_open('Processors', count($d['processors'] ?? []) . ' probed'); ?>
      <div class="ac-cardbody">
        <?php if (!($d['processors'] ?? [])): ?>
          <div class="ac-note">Nothing configured.</div>
        <?php else: ?>
          <div class="ac-tablewrap">
            <table class="ac-table">
              <thead><tr><th>Processor</th><th>For</th><th>DPA</th></tr></thead>
              <tbody>
                <?php foreach ($d['processors'] as $key => $p): ?>
                  <tr>
                    <td><?= ac_h((string) ($p['label'] ?? $key)) ?>
                      <?php if (!empty($p['detail'])): ?><div class="ac-sub"><?= ac_h((string) $p['detail']) ?></div><?php endif; ?></td>
                    <td class="ac-sub"><?= ac_h((string) ($p['purpose'] ?? '')) ?></td>
                    <td><?= !empty($p['gap']) ? ac_pill('No DPA', 'bad') : ac_pill('On file', 'good') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    <?php ac_card_close('The register probes what is CONFIGURED right now. An aspirational one lists the vendor somebody once considered and omits the SMTP host that has carried every password-reset email for a year.'); ?>

    <?php ac_card_open('Breaches', count($d['breaches'] ?? []) . ''); ?>
      <div class="ac-cardbody">
        <?php if (!($d['breaches'] ?? [])): ?>
          <div class="ac-note">None logged.</div>
        <?php else: foreach ($d['breaches'] as $b):
          $c = $b['clock'] ?? []; ?>
          <div class="ac-banner ac-banner--bad ac-mb-sm">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div>
              <div class="ac-bannertitle"><?= ac_h(ac_join([$b['reference'] ?? '', mb_strimwidth((string) ($b['nature'] ?? ''), 0, 90, '…')]) ?: 'Breach') ?></div>
              <div class="ac-sub">Discovered <?= ac_h(mam_console_date((string) $b['discovered_at'], 'j M H:i')) ?>
                <?php if (isset($c['hours_left'])): ?> · <?= (int) $c['hours_left'] ?>h of
                  <?= (int) ($dl['breach_ndpc_hours'] ?? 72) ?> left<?php endif; ?></div>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    <?php ac_card_close('The clock runs from <strong>awareness</strong>, not occurrence — measuring from occurrence makes an instantly-reported month-old breach look late.'); ?>
  </div>
</div>

<?php ac_card_open('The processing register', count($d['purposes'] ?? []) . ' purposes',
    '<span class="ac-cardmeta">Derived from the code, not typed into a form</span>'); ?>
  <div class="ac-tablewrap">
    <table class="ac-table">
      <thead><tr><th>Purpose</th><th>Lawful basis</th><th>Tables it touches</th></tr></thead>
      <tbody>
        <?php if (!($d['purposes'] ?? [])): ?>
          <?php ac_table_empty(3, 'The register could not be read.'); ?>
        <?php else: foreach ($d['purposes'] as $key => $p): ?>
          <tr>
            <td class="ac-strong"><?= ac_h((string) ($p['label'] ?? $key)) ?></td>
            <td><?= ac_h((string) ($p['basis'] ?? '—')) ?></td>
            <td class="ac-mono ac-sub"><?= ac_h(implode(', ', (array) ($p['tables'] ?? []))) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
<?php ac_card_close('Each purpose names the tables that implement it, and the test suite checks those names against the live schema — so a register that has drifted from the software fails the build instead of sitting in a folder looking reassuring. What the academy adds on top is its own part: a review date and a note.'); ?>
