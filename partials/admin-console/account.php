<?php
/**
 * My account — the signed-in admin's own details, password and devices.
 *
 * This was the legacy dashboard's ?v=account, and it is the one screen the
 * console cannot do without: the temporary-password gate in console.php sends
 * an admin here before anything else. The writes are the same handlers as
 * before (change_own_account, session_revoke, session_revoke_others), so the
 * password rules — eight characters, the current one checked — are unchanged.
 */
declare(strict_types=1);
/** @var array $d */
$me = $d['me'] ?? null;
?>
<?php ac_head('My account', 'Your own name, phone, password and the devices you are signed in on'); ?>

<?php if ($me === null): ?>
  <?php ac_empty('You are signed in with the recovery credential',
      'That sign-in has no account to edit. Sign in with your own admin account to change your details or password.'); ?>
<?php else: ?>

<?php if ($me['must_change']): ?>
  <?php ac_banner('warn', 'fa-key',
      '<strong>Set a new password before you carry on.</strong> You are on a temporary one, so every other screen will bring you back here until it is changed.'); ?>
<?php endif; ?>

<div class="ac-cols ac-cols--2">
  <?php ac_card_open('Details and password', $me['email']); ?>
    <div class="ac-cardbody">
      <?php ac_form('change_own_account', 'account', [], 'autocomplete="on"'); ?>
        <div class="ac-grid ac-grid--2">
          <label class="ac-field"><span class="ac-label">Name</span>
            <input class="ac-input" name="name" value="<?= ac_h($me['name']) ?>" autocomplete="name"></label>
          <label class="ac-field"><span class="ac-label">Phone</span>
            <input class="ac-input" name="phone" value="<?= ac_h($me['phone']) ?>" inputmode="tel" autocomplete="tel"></label>
        </div>
        <p class="ac-hint ac-mt">Your email is your sign-in and is not changed here.</p>
        <div class="ac-grid ac-grid--2 ac-mt">
          <label class="ac-field"><span class="ac-label">Current password</span>
            <input class="ac-input" type="password" name="current" autocomplete="current-password"<?= $me['must_change'] ? ' required' : '' ?>></label>
          <label class="ac-field"><span class="ac-label">New password</span>
            <input class="ac-input" type="password" name="next" minlength="8" autocomplete="new-password"<?= $me['must_change'] ? ' required' : '' ?>></label>
        </div>
        <p class="ac-hint">At least 8 characters. Leave both blank to keep your password.</p>
        <div class="ac-row ac-mt"><button type="submit" class="ac-btn ac-btn--primary">Save</button></div>
      <?php ac_form_end(); ?>
    </div>
  <?php ac_card_close(); ?>

  <?php $sess = $d['sessions'] ?? []; ac_card_open('Signed-in devices', count($sess) . ' active'); ?>
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead><tr><th>Device</th><th>Signed in</th><th class="ac-num"></th></tr></thead>
        <tbody>
          <?php if (!$sess): ?>
            <?php ac_table_empty(3, 'No active sessions found.'); ?>
          <?php else: foreach ($sess as $s): ?>
            <tr>
              <td><div class="ac-strong"><?= ac_h(mb_strimwidth((string) ($s['ua'] ?: 'Unknown browser'), 0, 70, '…')) ?></div>
                  <div class="ac-sub"><?= ac_h((string) $s['ip']) ?><?= !empty($s['current']) ? ' · this device' : '' ?></div></td>
              <td><?= ac_h(mam_console_date((string) $s['created_at'], 'j M Y, H:i')) ?></td>
              <td class="ac-num"><?php if (empty($s['current'])) ac_action_btn('session_revoke', 'account', ['sid' => (string) $s['id']], 'Sign out'); ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php ac_card_close(count($sess) > 1
      ? (function () { ob_start(); ac_action_btn('session_revoke_others', 'account', [], 'Sign out every other device'); return (string) ob_get_clean(); })()
      : ''); ?>
</div>

<?php endif; ?>
