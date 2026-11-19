<?php
/** People & access — roles, clearance, and the break-glass identity. */
declare(strict_types=1);
/** @var array $d */
?>
<?php ac_head('People & access',
    'Money and the academic record are deliberately separate hands',
    '<a class="ac-btn ac-btn--primary" href="/admin/?v=users">Invite staff</a>'); ?>

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
            <td class="ac-num"><?= (int) ($r['sessions'] ?? 0) ?></td>
            <td><?= ac_status((string) $r['status']) ?>
                <?php if (!empty($r['last_login'])): ?>
                  <div class="ac-sub">last in <?= ac_h(mam_console_age((string) $r['last_login'])) ?> ago</div>
                <?php endif; ?></td>
            <td class="ac-num">
              <div class="ac-row">
                <a class="ac-btn ac-btn--sm" href="/admin/?v=users&amp;edit=<?= (int) $r['id'] ?>">Edit</a>
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
