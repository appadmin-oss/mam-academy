<?php
/** System health — automations, outbound events, and the schema. */
declare(strict_types=1);
/** @var array $d */
$h = $d['health'] ?? [];
$behind = ($d['schema']['target'] ?? null) !== null
    && (int) $d['schema']['version'] < (int) $d['schema']['target'];
?>
<?php ac_head('System health',
    'Automations, outbound events, mail and the schema'); ?>

<?php /* Above the schema warning on purpose: an unstable signing key breaks
         every form on the site, and an admin arriving here because "the forms
         are not submitting" should meet the reason first. */ ?>
<?php if (isset($h['signing']) && empty($h['signing']['stable'])): ?>
  <?php ac_banner('bad', 'fa-key',
    '<strong>The signing key is not stable between requests.</strong> '
    . ac_h((string) ($h['signing']['detail'] ?? ''))); ?>
<?php endif; ?>

<?php
  /* Beside the signing-key banner on purpose: a restore needs BOTH the archive
     and the key that signed what is in it, and an academy that has one without
     the other finds out at a door when an ID card will not verify. */
  $bk = (array) ($d['backup'] ?? []);
  $bkState = (string) ($bk['state'] ?? '');
?>
<?php if ($bkState !== '' && $bkState !== 'ok'): ?>
  <?php ac_banner($bkState === 'none' || $bkState === 'stale' ? 'bad' : 'warn', 'fa-box-archive',
    '<strong>' . ($bkState === 'none' ? 'There is no backup.'
      : ($bkState === 'stale' ? 'The backup is stale.' : 'The backup is incomplete.')) . '</strong> '
    . ac_h((string) ($bk['message'] ?? ''))); ?>
<?php endif; ?>

<?php if ($behind): ?>
  <?php ac_banner('bad', 'fa-database',
    '<strong>The database is behind the code.</strong> Schema ' . (int) $d['schema']['version']
    . ' against a target of ' . (int) $d['schema']['target']
    . '. Screens that read a newer column will report a failed read rather than showing an empty table.',
    (function () { ob_start(); ac_action_btn('run_migrations', 'system', [], 'Run migrations'); return (string) ob_get_clean(); })()); ?>
<?php endif; ?>

<?php ac_kpis([
    ['label' => 'Automations on', 'value' => (int) ($d['on'] ?? 0) . ' of ' . (int) ($d['total'] ?? 0),
     'note' => ((int) ($d['total'] ?? 0) - (int) ($d['on'] ?? 0)) . ' deliberately off'],
    ['label' => 'Unverified webhooks', 'value' => (string) (int) ($h['webhooks']['unverified'] ?? 0),
     'tone' => (int) ($h['webhooks']['unverified'] ?? 0) > 0 ? 'bad' : '', 'note' => 'signature did not check out'],
    ['label' => 'Failed mail', 'value' => (string) count($d['failed_mail'] ?? []),
     'tone' => count($d['failed_mail'] ?? []) > 0 ? 'warn' : '', 'note' => 'retryable, with the error on the row'],
    ['label' => 'Schema', 'value' => (string) (int) ($d['schema']['version'] ?? 0),
     'tone' => $behind ? 'bad' : 'good',
     'note' => 'target ' . (int) ($d['schema']['target'] ?? 0) . ' · ' . ac_h((string) ($d['driver'] ?? ''))],
]); ?>

<?php $st = $d['storage'] ?? []; $sc = (array) ($st['cfg'] ?? []); ?>
<?php ac_card_open('File storage', !empty($st['configured']) ? 'Private bucket' : 'This host',
    !empty($st['configured']) ? ac_pill('Bucket set', 'good') : ac_pill('No bucket', 'warn')); ?>
  <div class="ac-cardbody">
    <p class="ac-note">Class videos, recordings and teachers' materials. Videos are made smaller in the uploader's
    browser first (720p, about 17 MB a minute). With a bucket set they go straight to it and are read through
    ten-minute private links; without one they are kept on this host, up to <?= (int) (MAM_MEDIA_LOCAL_MAX / 1048576) ?> MB each.
    Cloudflare R2 is the cheapest that works here: storage is cents a month and downloads cost nothing.
    R2 has no African region, so the data-protection register should note the transfer.</p>
    <?php if (!empty($st['used'])): ?>
      <p class="ac-sub ac-mt"><?php foreach ($st['used'] as $u): ?>
        <?= ac_h((string) $u['provider']) ?> · <?= ac_h((string) $u['kind']) ?>: <?= (int) $u['n'] ?> files, <?= ac_h(number_format(((int) $u['bytes']) / 1048576, 1)) ?> MB<br>
      <?php endforeach; ?></p>
    <?php endif; ?>
    <?php ac_form('storage_save', 'system'); ?>
      <div class="ac-inline ac-mt">
        <label class="ac-field ac-grow"><span class="ac-label">Endpoint (https)</span>
          <input class="ac-input" name="endpoint" value="<?= ac_h((string) ($sc['endpoint'] ?? '')) ?>" placeholder="https://ACCOUNT.r2.cloudflarestorage.com"></label>
        <label class="ac-field ac-w-md"><span class="ac-label">Bucket</span>
          <input class="ac-input" name="bucket" value="<?= ac_h((string) ($sc['bucket'] ?? '')) ?>"></label>
        <label class="ac-field ac-w-md"><span class="ac-label">Region</span>
          <input class="ac-input" name="region" value="<?= ac_h((string) ($sc['region'] ?? 'auto')) ?>"></label>
      </div>
      <div class="ac-inline ac-mt">
        <label class="ac-field ac-grow"><span class="ac-label">Access key<?= !empty($st['has_keys']) ? ' (set — leave blank to keep)' : '' ?></span>
          <input class="ac-input" name="access_key" autocomplete="off"></label>
        <label class="ac-field ac-grow"><span class="ac-label">Secret key<?= !empty($st['has_keys']) ? ' (set — leave blank to keep)' : '' ?></span>
          <input class="ac-input" type="password" name="secret_key" autocomplete="new-password"></label>
      </div>
      <div class="ac-inline ac-mt">
        <button type="submit" class="ac-btn ac-btn--primary">Save</button>
        <button type="submit" class="ac-btn" formaction="/admin/?test=1" name="test" value="1">Save and test</button>
      </div>
    <?php ac_form_end(); ?>
    <details class="ac-mt"><summary class="ac-sub">The CORS rule the bucket needs (R2: Settings → CORS policy)</summary>
      <pre class="ac-mono"><?= ac_h((string) ($st['cors'] ?? '')) ?></pre></details>
  </div>
<?php ac_card_close(); ?>

<?php ac_card_open('Automations', (int) ($d['on'] ?? 0) . ' on',
    (function () { ob_start(); ac_action_btn('run_automations', 'system', [], 'Run the sweeps now', 'ac-btn ac-btn--sm'); return ob_get_clean(); })()); ?>
  <div class="ac-tablewrap">
    <table class="ac-table">
      <thead><tr><th>Rule</th><th>Fires when</th><th class="ac-num">Runs</th><th class="ac-num">Last</th><th>State</th><th></th></tr></thead>
      <tbody>
        <?php if (!($d['registry'] ?? [])): ?>
          <?php ac_table_empty(6, 'The automation registry could not be read.'); ?>
        <?php else: foreach ($d['registry'] as $key => $rule):
          $st = $d['state'][$key] ?? null;
          $on = (int) ($st['enabled'] ?? 0) === 1; ?>
          <tr>
            <td><div class="ac-strong"><?= ac_h((string) ($rule['label'] ?? $key)) ?></div>
                <div class="ac-sub ac-mono"><?= ac_h((string) $key) ?></div></td>
            <td class="ac-sub"><?= ac_h((string) ($rule['when'] ?? $rule['description'] ?? '')) ?></td>
            <td class="ac-num"><?= (int) ($st['run_count'] ?? 0) ?></td>
            <td class="ac-num"><?= $st && !empty($st['last_run_at'])
                ? ac_h(mam_console_age((string) $st['last_run_at'])) : '<span class="ac-sub">never</span>' ?></td>
            <td><?= $on ? ac_pill('On', 'good') : ac_pill('Off') ?></td>
            <td class="ac-num">
              <?php ac_action_btn('automation_toggle', 'system',
                  ['key' => (string) $key, 'enabled' => $on ? '0' : '1'],
                  $on ? 'Turn off' : 'Turn on', 'ac-btn ac-btn--sm'); ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
<?php ac_card_close('Each rule is honoured at ITS trigger point, not by a scheduler that decides for it. The count of rules is derived from the registry — hardcoding it is how adding a rule has broken the suite twice. Placing a class and provisioning a login are deliberately two different switches: a login and a class placement are two different decisions.'); ?>

<div class="ac-cols ac-cols--main">
  <div class="ac-stack">
    <?php ac_card_open('Outbound events', count($d['events'] ?? []) . ' most recent'); ?>
      <div class="ac-tablewrap">
        <table class="ac-table">
          <thead><tr><th>Provider</th><th>Event</th><th>Signature</th><th>Status</th><th class="ac-num">When</th></tr></thead>
          <tbody>
            <?php if (!($d['events'] ?? [])): ?>
              <?php ac_table_empty(5, 'No webhooks received.'); ?>
            <?php else: foreach ($d['events'] as $e): ?>
              <tr>
                <td><?= ac_h((string) $e['provider']) ?></td>
                <td class="ac-mono"><?= ac_h((string) ($e['event_type'] ?? '')) ?>
                    <?php if (!empty($e['error'])): ?><div class="ac-sub"><?= ac_h(mb_strimwidth((string) $e['error'], 0, 80, '…')) ?></div><?php endif; ?></td>
                <td><?= (int) ($e['signature_valid'] ?? 0) === 1 ? ac_pill('Verified', 'good') : ac_pill('Failed', 'bad') ?></td>
                <td><?= ac_status((string) $e['status']) ?></td>
                <td class="ac-num"><?= ac_h(mam_console_age((string) $e['received_at'])) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    <?php ac_card_close('A webhook is HMAC-verified and idempotent, so a provider replaying a delivery cannot double-credit a payment. A payment re-checks the amount and the currency against what is owed before it settles anything.'); ?>

    <?php if ($d['failed_mail'] ?? []): ?>
      <?php ac_card_open('Failed mail', count($d['failed_mail']) . ''); ?>
        <div class="ac-tablewrap">
          <table class="ac-table">
            <thead><tr><th>To</th><th>Context</th><th>Error</th><th class="ac-num">Attempts</th></tr></thead>
            <tbody>
              <?php foreach ($d['failed_mail'] as $m): ?>
                <tr>
                  <td><?= ac_h((string) $m['to_email']) ?>
                      <div class="ac-sub"><?= ac_h(mb_strimwidth((string) $m['subject'], 0, 50, '…')) ?></div></td>
                  <td class="ac-mono"><?= ac_h((string) ($m['context'] ?? '')) ?></td>
                  <td class="ac-sub"><?= ac_h(mb_strimwidth((string) ($m['error'] ?? ''), 0, 100, '…')) ?></td>
                  <td class="ac-num"><?= (int) ($m['attempts'] ?? 0) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php ac_card_close('A failure is a row with its error, which is what makes a retry possible; the mail-retry job resends failed rows on its schedule.'); ?>
    <?php endif; ?>
  </div>

  <div class="ac-stack">
    <?php ac_card_open('Build'); ?>
      <div class="ac-cardbody">
        <div class="ac-defs">
          <?php
            ac_def('Database driver', ac_h((string) ($d['driver'] ?? '')));
            ac_def('Schema version', (string) (int) ($d['schema']['version'] ?? 0)
                . ' <span class="ac-subi">target ' . (int) ($d['schema']['target'] ?? 0) . '</span>');
            ac_def('API version', ac_h((string) ($d['api']['version'] ?? '—')));
            ac_def('PHP', ac_h(PHP_VERSION));
            ac_def('Audit head', number_format((int) ($h['audit']['head'] ?? 0)));
          ?>
        </div>
      </div>
    <?php ac_card_close('The suite runs against SQLite and MySQL, and four defects existed only on one of them — the driver line matters.'); ?>

    <?php if ($d['jobs'] ?? []): ?>
      <?php ac_card_open('Jobs', count($d['jobs']) . ''); ?>
        <div class="ac-cardbody">
          <div class="ac-defs">
            <?php foreach (array_slice($d['jobs'], 0, 8) as $j): ?>
              <?php ac_def((string) ($j['kind'] ?? 'job') . ' #' . (int) $j['id'],
                  ac_status((string) $j['status'])
                  . ((int) ($j['attempts'] ?? 0) > 1 ? ' <span class="ac-subi">' . (int) $j['attempts'] . ' attempts</span>' : '')); ?>
            <?php endforeach; ?>
          </div>
        </div>
      <?php ac_card_close('The job runner holds an exclusive lock, so two crons overlapping cannot run the same sweep twice.'); ?>
    <?php endif; ?>

    <?php
      /* Visible even when nothing is wrong: an admin cannot confirm a backup
         is working from a banner that only appears when it has stopped. */
      $bkList = (array) ($d['backups'] ?? []);
      ac_card_open('Backups', $bkState !== '' ? strtoupper($bkState) : '');
    ?>
      <div class="ac-cardbody">
        <?php if (!$bkList): ?>
          <p class="ac-note">No snapshot has ever been written to
            <span class="ac-mono">api/storage/export</span>. DEPLOYMENT.md carries the cron line;
            without it nothing here could be restored.</p>
        <?php else: ?>
          <div class="ac-defs">
            <?php foreach (array_slice($bkList, 0, 5) as $b): ?>
              <?php
                $lbl = $b['complete']
                  ? (int) $b['tables'] . ' tables · ' . (int) $b['rows'] . ' rows · '
                    . ($b['has_uploads'] ? (int) $b['upload_files'] . ' files' : '<strong>no files</strong>')
                  : '<strong>incomplete</strong> — no manifest, written while the export was interrupted';
                /* ac_def(label, valueHtml) — the LABEL is escaped and only the
                   value takes markup, so the age chip belongs on the value side.
                   Putting it in the label printed the <span> as visible text. */
                ac_def((string) $b['name'],
                  '<span class="ac-subi">' . (int) ($b['age_hours'] ?? 0) . 'h</span> · ' . $lbl);
              ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <p class="ac-note"><?= ac_h((string) ($bk['message'] ?? '')) ?></p>
      </div>
    <?php ac_card_close('Verify one before trusting it: <span class="ac-mono">php api/tools/export.php --verify-only=api/storage/export/&lt;stamp&gt;</span> — it exits 2 if the archive does not match its own manifest.'); ?>

    <?php ac_card_open('Reading a failure'); ?>
      <div class="ac-cardbody">
        <p class="ac-note">Every read in this console goes through one helper that <strong>records</strong> a failure
        instead of swallowing it, and a screen that could not read its data prints the failure at the bottom with the
        database's own words.</p>
        <p class="ac-note">That is deliberate: the legacy admin caught a bad column, logged it, and rendered the table
        as nothing at all — so a screen that was silently empty looked correct for months.</p>
      </div>
    <?php ac_card_close('The same lines land in <span class="ac-mono">api/logs/app-*.log</span> against <span class="ac-mono">console_read_failed</span>.'); ?>
  </div>
</div>
