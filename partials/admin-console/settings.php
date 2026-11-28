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
<?php
  /* A manager can reach this screen (search, an old link) but cannot save it:
     `save_settings` needs the super-only `settings` capability, and the form
     used to look editable and fail on Save (UX quick win 15). Read-only here,
     said in words; admin/index.php's capability table still enforces it. */
  $cfgUser = $adminUser ?? ($GLOBALS['adminUser'] ?? null);
  $canEditSettings = !$cfgUser || mam_admin_can($cfgUser, 'settings');
  if (!$canEditSettings) {
      ac_banner('warn', 'fa-lock', 'You can read these settings but not change them. Changing settings needs a super admin — ask one to make the change.');
  }
?>
<?php ac_card_open('Configuration', $nCfg . ' settings · ' . $changed . ' changed from the default',
    '<span class="ac-cardmeta">One save for all of them</span>'); ?>
  <div class="ac-cardbody">
  <?php ac_form('save_settings', 'settings', [], 'class="ac-cfg" id="settings-form"'); ?>
  <fieldset class="ac-plainset"<?= $canEditSettings ? '' : ' disabled' ?>>
    <?php foreach ($cfg as $group => $fields):
      $gChanged = count(array_filter($fields, fn($f) => $f['value'] !== $f['default'])); ?>
      <details class="ac-cfg-group"<?= $group === array_key_first($cfg) ? ' open' : '' ?>>
        <summary><span class="ac-strong"><?= ac_h($group) ?></span>
          <span class="ac-sub"><?= count($fields) ?> <?= count($fields) === 1 ? 'setting' : 'settings' ?><?= $gChanged ? ' · ' . $gChanged . ' changed' : '' ?></span></summary>
        <div class="ac-cfg-fields">
          <?php foreach ($fields as $f):
            $k = $f['key']; $val = $f['value']; $isChanged = $val !== $f['default'];
            $id = 'cfg-' . $k;
            $date = (bool) preg_match('/YYYY-MM-DD/', $f['label']);
            // A currency sign goes before the number; every other unit after it.
            $pre = in_array($f['suffix'], ['$', '₦', '£', '€'], true) ? $f['suffix'] : '';
            $post = $pre === '' ? $f['suffix'] : '';
            $hint = trim($f['hint'] . ($isChanged ? ($f['hint'] !== '' ? ' · ' : '') . 'Default: ' . ($f['default'] === '' ? '(blank)' : $f['default']) : '')); ?>
            <?php if ($f['kind'] === 'bool'): ?>
            <?php /* An on/off setting is a row with its switch on the right. The hidden 0 comes
                     FIRST so an unticked switch still posts a value (PHP keeps the last of two
                     same-named fields): the handler resets a key it is not sent to its default. */ ?>
            <div class="ac-cfg-row is-switch">
              <div class="ac-cfg-text">
                <label class="ac-label" for="<?= ac_h($id) ?>"><?= ac_h($f['title']) ?></label>
                <?php if ($hint !== ''): ?><p class="ac-hint" id="<?= ac_h($id) ?>-hint"><?= ac_h($hint) ?></p><?php endif; ?>
              </div>
              <span class="ac-toggle">
                <input type="hidden" name="<?= ac_h($k) ?>" value="0">
                <input type="checkbox" role="switch" id="<?= ac_h($id) ?>" name="<?= ac_h($k) ?>" value="1"<?= $val === '1' ? ' checked' : '' ?><?= $hint !== '' ? ' aria-describedby="' . ac_h($id) . '-hint"' : '' ?>>
                <label class="ac-switch" for="<?= ac_h($id) ?>" aria-hidden="true"></label>
              </span>
            </div>
            <?php continue; endif; ?>
            <div class="ac-cfg-row">
              <label class="ac-label" for="<?= ac_h($id) ?>"><?= ac_h($f['title']) ?></label>
              <?php if ($f['kind'] === 'enum'): ?>
                <select class="ac-select ac-w-fit" id="<?= ac_h($id) ?>" name="<?= ac_h($k) ?>">
                  <?php foreach ($f['options'] as $o): ?>
                    <option value="<?= ac_h($o) ?>"<?= $val === $o ? ' selected' : '' ?>><?= ac_h(ucfirst(str_replace('_', ' ', $o))) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php elseif ($f['kind'] === 'number'): ?>
                <span class="ac-affix">
                  <?php if ($pre !== ''): ?><span class="ac-affix-unit" aria-hidden="true"><?= ac_h($pre) ?></span><?php endif; ?>
                  <input class="ac-input ac-w-num" id="<?= ac_h($id) ?>" type="number" step="any" min="0" name="<?= ac_h($k) ?>" value="<?= ac_h($val) ?>" required<?= $f['suffix'] !== '' ? ' aria-describedby="' . ac_h($id) . '-unit"' : '' ?>>
                  <?php if ($post !== ''): ?><span class="ac-affix-unit" aria-hidden="true"><?= ac_h($post) ?></span><?php endif; ?>
                  <?php if ($f['suffix'] !== ''): ?><span class="ac-sr" id="<?= ac_h($id) ?>-unit">in <?= ac_h($f['suffix']) ?></span><?php endif; ?>
                </span>
              <?php elseif ($f['size'] === 'long'): ?>
                <textarea class="ac-textarea ac-w-long" id="<?= ac_h($id) ?>" name="<?= ac_h($k) ?>" rows="3" maxlength="300"><?= ac_h($val) ?></textarea>
              <?php else: ?>
                <input class="ac-input <?= $f['size'] === 'short' || $date ? 'ac-w-short' : 'ac-w-text' ?>" id="<?= ac_h($id) ?>" type="<?= $date ? 'date' : 'text' ?>" maxlength="300" name="<?= ac_h($k) ?>" value="<?= ac_h($val) ?>">
              <?php endif; ?>
              <?php if ($hint !== ''): ?><p class="ac-hint"><?= ac_h($hint) ?></p><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endforeach; ?>
  </fieldset>
    <?php if ($canEditSettings): ?>
    <div class="ac-row ac-mt">
      <button type="submit" class="ac-btn ac-btn--primary">Save settings</button>
    </div>
    <?php endif; ?>
  <?php ac_form_end(); ?>
  </div>
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
