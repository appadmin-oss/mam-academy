<?php
/** Settings & vocabulary — words, features and fields, changed here rather than in a deploy. */
declare(strict_types=1);
/** @var array $d */
$t = $d['tenant'] ?? [];
?>
<?php ac_head('Settings & vocabulary',
    'Words, features and fields · changed here, not in a deploy'); ?>

<?php
  $cfg = $d['config'] ?? [];
  $nCfg = array_sum(array_map('count', $cfg));
  $changed = 0;
  foreach ($cfg as $fs) foreach ($fs as $f) if ($f['value'] !== $f['default']) $changed++;
?>
<?php ac_card_open('Configuration', $nCfg . ' settings · ' . $changed . ' changed from the default',
    '<span class="ac-cardmeta">One save for all of them</span>'); ?>
  <?php ac_form('save_settings', 'settings', [], 'class="ac-cfg" id="settings-form"'); ?>
    <?php foreach ($cfg as $group => $fields):
      $gChanged = count(array_filter($fields, fn($f) => $f['value'] !== $f['default'])); ?>
      <details class="ac-cfg-group">
        <summary><span class="ac-strong"><?= ac_h($group) ?></span>
          <span class="ac-sub"><?= count($fields) ?> <?= count($fields) === 1 ? 'setting' : 'settings' ?><?= $gChanged ? ' · ' . $gChanged . ' changed' : '' ?></span></summary>
        <div class="ac-grid ac-grid--2 ac-cfg-fields">
          <?php foreach ($fields as $f):
            $k = $f['key']; $val = $f['value']; $isChanged = $val !== $f['default'];
            $long = (bool) preg_match('/one per line|paragraph|description/i', $f['label']);
            $date = (bool) preg_match('/YYYY-MM-DD/', $f['label']); ?>
            <label class="ac-field<?= $long ? ' ac-cfg-wide' : '' ?>">
              <span class="ac-label"><?= ac_h($f['label']) ?><?= $f['suffix'] !== '' ? ' (' . ac_h($f['suffix']) . ')' : '' ?></span>
              <?php if ($f['kind'] === 'bool'): ?>
                <select class="ac-select" name="<?= ac_h($k) ?>">
                  <option value="1"<?= $val === '1' ? ' selected' : '' ?>>Yes</option>
                  <option value="0"<?= $val !== '1' ? ' selected' : '' ?>>No</option>
                </select>
              <?php elseif ($f['kind'] === 'enum'): ?>
                <select class="ac-select" name="<?= ac_h($k) ?>">
                  <?php foreach ($f['options'] as $o): ?>
                    <option value="<?= ac_h($o) ?>"<?= $val === $o ? ' selected' : '' ?>><?= ac_h($o) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php elseif ($f['kind'] === 'number'): ?>
                <input class="ac-input" type="number" step="any" min="0" name="<?= ac_h($k) ?>" value="<?= ac_h($val) ?>" required>
              <?php elseif ($long): ?>
                <textarea class="ac-textarea" name="<?= ac_h($k) ?>" rows="3" maxlength="300"><?= ac_h($val) ?></textarea>
              <?php else: ?>
                <input class="ac-input" type="<?= $date ? 'date' : 'text' ?>" maxlength="300" name="<?= ac_h($k) ?>" value="<?= ac_h($val) ?>">
              <?php endif; ?>
              <?php if ($isChanged): ?><span class="ac-hint">Default: <?= ac_h($f['default'] === '' ? '(blank)' : $f['default']) ?></span><?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endforeach; ?>
    <div class="ac-row ac-mt">
      <button type="submit" class="ac-btn ac-btn--primary">Save settings</button>
    </div>
  <?php ac_form_end(); ?>
<?php ac_card_close('A blank number goes back to its default, never to zero, so a cleared price cannot make a programme free.'); ?>

<?php ac_card_open('Sessions', count($d['terms'] ?? []) . ' authored',
    '<span class="ac-cardmeta">Four a year · MAM calls these sessions</span>'); ?>
  <div class="ac-tablewrap">
    <table class="ac-table">
      <thead><tr><th>Session</th><th>Runs</th><th class="ac-num">Weeks</th><th>State</th></tr></thead>
      <tbody>
        <?php if (!($d['terms'] ?? [])): ?>
          <?php ac_table_empty(4, 'No sessions authored. Until one is, dates come from the computed window.'); ?>
        <?php else: foreach ($d['terms'] as $tm): ?>
          <tr>
            <td><div class="ac-strong"><?= ac_h((string) ($tm['label'] ?: $tm['code'])) ?></div>
                <div class="ac-sub"><?= ac_h((string) $tm['year_label']) ?></div></td>
            <td><?= ac_h(mam_console_date((string) $tm['starts_on'], 'j M')) ?> –
                <?= ac_h(mam_console_date((string) $tm['ends_on'], 'j M Y')) ?></td>
            <td class="ac-num"><?= $tm['teaching_weeks'] !== null ? (int) $tm['teaching_weeks'] : '—' ?></td>
            <td><?= ac_status((string) ($tm['status'] ?? 'draft')) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
<?php ac_card_close('Renaming a session changes what every screen and every email calls it, and the prefix on NEW cohort references. Existing references keep the name they were created under, because a family has that on a receipt.'); ?>

<?php ac_card_open('Status vocabulary', count($d['statuses'] ?? []) . ' statuses',
    '<span class="ac-cardmeta">A status with no sentence cannot exist</span>'); ?>
  <div class="ac-tablewrap">
    <table class="ac-table">
      <thead><tr><th>Stored value</th><th>Shown to a family</th><th>What happens next</th><th>Needs an action?</th></tr></thead>
      <tbody>
        <?php foreach ($d['statuses'] ?? [] as $s): ?>
          <tr>
            <td class="ac-mono"><?= ac_h((string) $s['stored']) ?></td>
            <td class="ac-strong"><?= ac_h((string) $s['shown']) ?></td>
            <td class="ac-sub"><?= ac_h((string) $s['next']) ?></td>
            <td><?= !empty($s['action']) ? ac_pill('Yes', 'warn') : ac_pill('No') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php ac_card_close('These sentences ship with the code that produces the statuses, so a new status cannot be added without one — a stored value nobody can explain to a family is what makes a portal unreadable.'); ?>

<div class="ac-cols ac-cols--2">
  <?php ac_card_open('Gates', 'Each is a decision, not a default'); ?>
    <div class="ac-cardbody">
      <div class="ac-defs">
        <?php
          ac_def('Safeguarding gate', ac_pill(ucfirst((string) ($d['vetting_mode'] ?? 'warn')),
              ($d['vetting_mode'] ?? '') === 'enforce' ? 'good' : 'warn')
              . ' <a class="ac-subi" href="' . ac_h(ac_url('vetting')) . '">change</a>');
          ac_def('Retention sweep', ac_pill(ucfirst((string) ($d['retention_mode'] ?? 'report')),
              ($d['retention_mode'] ?? '') === 'enforce' ? 'warn' : '')
              . ' <a class="ac-subi" href="' . ac_h(ac_url('dataprotection')) . '">change</a>');
          ac_def('Automations on', (int) count(array_filter($d['automation_state'] ?? [], fn($s) => (int) ($s['enabled'] ?? 0) === 1))
              . ' of ' . count($d['automations'] ?? [])
              . ' <a class="ac-subi" href="' . ac_h(ac_url('system')) . '">change</a>');
        ?>
      </div>
      <p class="ac-note ac-mt">Both gates ship in their non-destructive mode. The reliable outcome
      of safeguarding theatre is somebody disabling the feature, and a retention job that starts deleting on the first
      run of an install nobody has reviewed is how an academy loses records it needed.</p>
    </div>
  <?php ac_card_close(); ?>

  <?php ac_card_open('This academy'); ?>
    <div class="ac-cardbody">
      <div class="ac-defs">
        <?php
          ac_def('Accounting currency', ac_h((string) ($t['base_currency'] ?? 'USD')) . ' · integer cents');
          ac_def('Reporting currency', ac_h((string) ($t['reporting_currency'] ?? 'NGN')));
          ac_def('Today\'s rate', !empty($t['rate']) ? '1 USD = ₦' . ac_h(number_format((float) $t['rate'], 2)) : 'not warmed');
          ac_def('Timezone', ac_h((string) ($t['timezone'] ?? 'Africa/Lagos')));
        ?>
      </div>
      <p class="ac-note ac-mt">Deadlines are adjudicated in the academy's zone and displayed in the
      reader's, so a diaspora guardian in New York and a Lagos guardian get the same refund.</p>
    </div>
  <?php ac_card_close('The accounting unit never changes with the reader — only the display does.'); ?>
</div>

<?php ac_card_open('Automation rules', count($d['automations'] ?? []) . ' registered'); ?>
  <div class="ac-tablewrap">
    <table class="ac-table">
      <thead><tr><th>Rule</th><th>What it does</th><th>State</th></tr></thead>
      <tbody>
        <?php if (!($d['automations'] ?? [])): ?>
          <?php ac_table_empty(3, 'The registry could not be read.'); ?>
        <?php else: foreach ($d['automations'] as $key => $rule):
          $on = (int) (($d['automation_state'][$key]['enabled'] ?? 0)) === 1; ?>
          <tr>
            <td><div class="ac-strong"><?= ac_h((string) ($rule['action'] ?? $key)) ?></div>
                <div class="ac-sub ac-mono"><?= ac_h((string) $key) ?></div></td>
            <td class="ac-sub"><?= ac_h((string) ($rule['trigger'] ?? '')) ?></td>
            <td><?= $on ? ac_pill('On', 'good') : ac_pill('Off') ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
<?php ac_card_close('Toggle them on <a href="' . ac_h(ac_url('system')) . '">System health</a>, where the telemetry for each rule sits beside its switch.'); ?>
