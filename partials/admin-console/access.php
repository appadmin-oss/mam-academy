<?php
/** People & access — roles, clearance, and the break-glass identity. */
declare(strict_types=1);
/** @var array $d */
?>
<?php ac_head('People & access',
    'Money and the academic record are deliberately separate hands'); ?>

<?php /* ── Student management: create an account (an invitation, never a password) ── */ ?>
<?php if (!empty($d['can_users'])): ?>
  <?php ac_card_open('Add a person', 'They are emailed a link to choose their own password'); ?>
    <?php ac_form('user_create', 'access', [], 'class="ac-cardbody ac-row"'); ?>
      <input class="ac-input ac-input--sm ac-grow" name="name" maxlength="120" required aria-label="Full name" placeholder="Full name">
      <input class="ac-input ac-input--sm ac-grow" type="email" name="email" maxlength="190" required aria-label="Email" placeholder="Email">
      <input class="ac-input ac-input--sm" name="phone" maxlength="40" aria-label="Phone (optional)" placeholder="Phone (optional)">
      <select class="ac-select ac-input--sm" name="role" aria-label="Role" required>
        <option value="instructor">Instructor</option>
        <option value="admin">Admin</option>
        <option value="student">Student</option>
        <option value="parent">Guardian</option>
      </select>
      <button type="submit" class="ac-btn ac-btn--sm ac-btn--primary">Create and invite</button>
    <?php ac_form_end(); ?>
  <?php ac_card_close('No password is typed, generated or emailed. The confirmation says whether the invitation was actually sent; if it was not, use "Email a set-password link" on their row.'); ?>
<?php endif; ?>
<?php /* ── end student management ── */ ?>

<?php ac_card_open('Staff and instructors', count($d['rows'] ?? []) . ''); ?>
  <div class="ac-tablewrap">
    <table class="ac-table">
      <thead><tr><th>Person</th><th>Role</th><th>Clearance</th><th class="ac-num">Live sessions</th>
                 <th>Account</th><th></th></tr></thead>
      <tbody>
        <?php if (!($d['rows'] ?? [])): ?>
          <?php ac_table_empty(6, 'No staff accounts.'); ?>
        <?php else: foreach ($d['rows'] as $r):
          $g = $r['gate'] ?? null;
          $roles = array_filter(array_unique(array_merge([(string) $r['role']], (array) ($r['extra_roles'] ?? [])))); ?>
          <tr>
            <td><div class="ac-row"><?= ac_avatar((string) $r['name']) ?>
              <div class="ac-grow">
                <div class="ac-strong"><?= ac_h((string) $r['name']) ?></div>
                <div class="ac-sub"><?= ac_h((string) $r['email']) ?></div>
              </div></div></td>
            <td>
              <?php foreach ($roles as $role): ?><?= ac_pill(ucfirst($role), $role === 'admin' ? 'ink' : '') ?> <?php endforeach; ?>
              <?php if (!empty($r['admin_tier'])): ?><div class="ac-sub"><?= ac_h((string) $r['admin_tier']) ?></div><?php endif; ?>
            </td>
            <td>
              <?php if ((string) $r['role'] !== 'instructor'): ?><span class="ac-sub">Not teaching</span>
              <?php elseif (!is_array($g)): ?><span class="ac-sub">Unknown</span>
              <?php elseif (!empty($g['ok'])): ?><?= ac_pill('Cleared', 'good') ?>
              <?php else: ?><?= ac_pill('Not cleared', 'bad') ?><?php endif; ?>
            </td>
            <td class="ac-num"><?= (string) $r['role'] === 'instructor' || (int) ($r['sessions'] ?? 0) > 0 ? (int) ($r['sessions'] ?? 0) : '' ?></td>
            <td><?= ac_status((string) $r['status']) ?>
                <?php if (!empty($r['last_login'])): ?>
                  <div class="ac-sub">last in <?= ac_h(mam_console_age((string) $r['last_login'])) ?> ago</div>
                <?php endif; ?></td>
            <td class="ac-num">
              <div class="ac-row">
                <?php /* ── Student management: manage one person ──
                   Each control posts to the existing handler, which re-checks the
                   capability and the lockouts (own_account, last_super_admin, the
                   role-change refusal). The break-glass row is never offered any. */ ?>
                <?php if (!empty($d['can_users']) && (string) $r['status'] !== 'system'):
                  $isMe = (int) $r['id'] === (int) ($d['me'] ?? 0); ?>
                  <details class="ac-manage"><summary class="ac-btn ac-btn--sm">Manage</summary>
                    <?php ac_form('user_edit', 'access', ['user_id' => (int) $r['id']], 'class="ac-grid ac-grid--2 ac-mt-sm"'); ?>
                      <label><span class="ac-label">Name</span><input class="ac-input ac-input--sm" name="name" maxlength="120" required value="<?= ac_h((string) $r['name']) ?>"></label>
                      <label><span class="ac-label">Email</span><input class="ac-input ac-input--sm" type="email" name="email" maxlength="190" required value="<?= ac_h((string) $r['email']) ?>"></label>
                      <label><span class="ac-label">Phone</span><input class="ac-input ac-input--sm" name="phone" maxlength="40" value="<?= ac_h((string) ($r['phone'] ?? '')) ?>"></label>
                      <label><span class="ac-label">Main role</span>
                        <select class="ac-select ac-input--sm" name="role">
                          <?php foreach (['instructor' => 'Instructor', 'admin' => 'Admin', 'student' => 'Student'] as $rk => $rl): ?>
                            <option value="<?= $rk ?>"<?= (string) $r['role'] === $rk ? ' selected' : '' ?>><?= $rl ?></option>
                          <?php endforeach; ?>
                        </select></label>
                      <fieldset class="ac-row"><legend class="ac-label">Also has portal access as</legend>
                        <?php foreach (['student' => 'Student', 'instructor' => 'Instructor'] as $rk => $rl): ?>
                          <label class="ac-row"><input type="checkbox" name="extra_roles[]" value="<?= $rk ?>"<?= in_array($rk, (array) ($r['extra_roles'] ?? []), true) ? ' checked' : '' ?>> <?= $rl ?></label>
                        <?php endforeach; ?>
                      </fieldset>
                      <div class="ac-row"><button type="submit" class="ac-btn ac-btn--sm">Save details</button></div>
                    <?php ac_form_end(); ?>
                    <?php if ($isMe): ?>
                      <p class="ac-note ac-mt-sm">This is you. Your own status and tier are not changed here — <a href="<?= ac_h(ac_url('account')) ?>">My account</a>.</p>
                    <?php else: ?>
                      <?php ac_form('user_status', 'access', ['user_id' => (int) $r['id']], 'class="ac-row ac-mt-sm"'); ?>
                        <select class="ac-select ac-input--sm" name="status" aria-label="Status for <?= ac_h((string) $r['name']) ?>">
                          <option value="active"<?= (string) $r['status'] === 'active' ? ' selected' : '' ?>>Active</option>
                          <option value="suspended"<?= (string) $r['status'] === 'suspended' ? ' selected' : '' ?>>Suspended</option>
                        </select>
                        <input class="ac-input ac-input--sm ac-grow" name="reason" maxlength="500" aria-label="Reason" placeholder="Why — needed to suspend">
                        <button type="submit" class="ac-btn ac-btn--sm">Set status</button>
                      <?php ac_form_end(); ?>
                      <?php if (!empty($d['can_tier']) && in_array('admin', $roles, true)): ?>
                        <?php ac_form('set_admin_tier', 'access', ['user_id' => (int) $r['id']], 'class="ac-row ac-mt-sm"'); ?>
                          <select class="ac-select ac-input--sm" name="tier" aria-label="Admin tier for <?= ac_h((string) $r['name']) ?>">
                            <option value="manager"<?= (string) ($r['admin_tier'] ?? '') !== 'super' ? ' selected' : '' ?>>Manager — day-to-day work</option>
                            <option value="super"<?= (string) ($r['admin_tier'] ?? '') === 'super' ? ' selected' : '' ?>>Super — money, settings, people</option>
                          </select>
                          <button type="submit" class="ac-btn ac-btn--sm">Set tier</button>
                        <?php ac_form_end(); ?>
                      <?php endif; ?>
                    <?php endif; ?>
                    <div class="ac-mt-sm">
                      <?php ac_action_btn('user_reset', 'access', ['user_id' => (int) $r['id']], 'Email a set-password link',
                          'ac-btn ac-btn--sm', 'Email a link to set a password? Their sessions end. No password is sent.'); ?>
                    </div>
                  </details>
                <?php endif; ?>
                <?php /* ── end student management ── */ ?>
                <?php if ((int) ($r['sessions'] ?? 0) > 0): ?>
                  <?php ac_action_btn('member_signout', 'access', ['user_id' => (int) $r['id']],
                      'Sign out', 'ac-btn ac-btn--sm ac-btn--danger', 'Revoke every session this person holds?'); ?>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
<?php ac_card_close('A role can be the primary one on the account or granted through the roles table, and both open the same doors — so the column shows every role a person actually holds rather than only the first.'); ?>

<div class="ac-cols ac-cols--2">
  <?php ac_card_open('Guardian access scopes'); ?>
    <div class="ac-cardbody">
      <div class="ac-defs">
        <?php foreach ($d['scopes'] ?? [] as $k => $why): ?>
          <?php ac_def(ucfirst((string) $k), ac_h((string) $why)); ?>
        <?php endforeach; ?>
      </div>
      <?php if ($d['never'] ?? []): ?>
        <div class="ac-note ac-mt"><strong>No scope grants:</strong>
          <?= ac_h(implode(', ', array_map(fn($k) => str_replace('_', ' ', (string) $k), (array) $d['never']))) ?>.
          Checked first, so widening “full” cannot open one.</div>
      <?php endif; ?>
    </div>
  <?php ac_card_close('<a href="' . ac_h(ac_url('guardians')) . '">Guardians & consent</a> is where a scope is set.'); ?>

  <?php ac_card_open('The break-glass credential'); ?>
    <div class="ac-cardbody">
      <?php if ($d['break_glass'] ?? null): ?>
        <div class="ac-defs">
          <?php
            ac_def('Identity', '<span class="ac-mono">' . ac_h((string) $d['break_glass']['email']) . '</span>');
            ac_def('Account status', ac_status((string) $d['break_glass']['status']));
            ac_def('First used', ac_h(mam_console_date((string) $d['break_glass']['created_at'], 'j M Y')));
          ?>
        </div>
      <?php else: ?>
        <div class="ac-note">The recovery identity has never been needed on this install — the row is created on
        first use, not at setup.</div>
      <?php endif; ?>
      <p class="ac-note ac-mt">It exists because the columns recording who acted are non-nullable
      foreign keys: a zero breaks the constraint and a null erases who acted. It is <strong>not a usable
      account</strong> — every login path requires an active status, and its password hash is of thirty-two random
      bytes that were never stored.</p>
      <p class="ac-note">Two records result and both matter: the ledger names the <em>identity</em>, the audit log
      names the <em>credential</em>.</p>
    </div>
  <?php ac_card_close('<a href="' . ac_h(ac_url('governance')) . '">Governance & audit</a> shows what it has done.'); ?>
</div>
