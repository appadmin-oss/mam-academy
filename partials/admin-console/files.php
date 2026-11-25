<?php
/** Files & ID cards — documents attached to a person or a session, and the cards printed from them. */
declare(strict_types=1);
/** @var array $d */
?>
<?php ac_head('Files & ID cards',
    'Documents attached to a person or a session, and the cards printed from them',
    '<a class="ac-btn" href="/card-studio">Card studio</a>'); ?>

<?php ac_kpis([
    ['label' => 'Learning resources', 'value' => (string) count($d['resources'] ?? []),
     'note' => 'attached to a class or a cohort'],
    ['label' => 'Cards ready to print', 'value' => (string) (int) ($d['ready'] ?? 0),
     'note' => 'active, never printed'],
    ['label' => 'Cards lapsed', 'value' => (string) (int) ($d['lapsed'] ?? 0),
     'tone' => (int) ($d['lapsed'] ?? 0) > 0 ? 'warn' : '', 'note' => 'derived from the date, not the status column'],
    ['label' => 'Portal photos held', 'value' => (string) (int) ($d['avatars'] ?? 0),
     'note' => 'stored first-party, re-encoded on upload'],
]); ?>

<?php ac_banner('flat', 'fa-shield-halved',
    '<strong>A card a child loses on a bus should tell a stranger nothing.</strong> The face carries a name, a '
    . 'membership number and a session. Expiry is a real date stamped at mint from the admin\'s validity period — not '
    . 'a label — so a lapsed card is refused at a door instead of scanning forever.'); ?>

<div class="ac-cols ac-cols--main">
  <?php ac_card_open('ID cards', count($d['cards'] ?? []) . ' issued'); ?>
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead><tr><th>Serial</th><th>Holder</th><th>Session</th><th>Expires</th><th>State</th></tr></thead>
        <tbody>
          <?php if (!($d['cards'] ?? [])): ?>
            <?php ac_table_empty(5, 'No cards minted. The studio proofs a sheet from the same renderer the card page uses, so what is proofed is what the student receives.'); ?>
          <?php else: foreach ($d['cards'] as $c): ?>
            <tr>
              <td class="ac-mono"><?= ac_h((string) $c['serial']) ?></td>
              <td><div class="ac-strong"><?= ac_h((string) ($c['name'] ?: '—')) ?></div>
                  <div class="ac-sub"><?= ac_h((string) ($c['role'] ?? '')) ?></div></td>
              <td><?= ac_h((string) ($c['session_code'] ?: '—')) ?></td>
              <td><?= ac_h(mam_console_date((string) $c['expires_at'], 'M Y')) ?></td>
              <td><?= !empty($c['lapsed']) ? ac_pill('Expired', 'bad') : ac_status((string) $c['status']) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php ac_card_close('Anything filtering cards filters on the DERIVED state, not the status column: between two cron runs a lapsed card still reads <span class="ac-mono">active</span>. A summer badge lapses after the camp on a shorter validity than a year-round card, deliberately.'); ?>

  <div class="ac-stack">
    <?php ac_card_open('Retention', count($d['retention'] ?? []) . ' rules'); ?>
      <div class="ac-cardbody">
        <?php if (!($d['retention'] ?? [])): ?>
          <div class="ac-note">The retention schedule could not be read.</div>
        <?php else: ?>
          <div class="ac-tablewrap">
            <table class="ac-table">
              <thead><tr><th>Data</th><th>After</th><th>Then</th></tr></thead>
              <tbody>
                <?php foreach (array_slice($d['retention'], 0, 8) as $key => $r): ?>
                  <tr>
                    <td><?= ac_h((string) ($r['label'] ?? $key)) ?></td>
                    <td class="ac-num"><?= isset($r['months']) ? (int) $r['months'] . ' mo' : '—' ?></td>
                    <td><?= ac_pill(strtoupper((string) ($r['action'] ?? 'review')),
                        (string) ($r['action'] ?? '') === 'delete' ? 'bad' : 'warn') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    <?php ac_card_close('A document inherits its retention from the PURPOSE it was uploaded for, not from a folder somebody chose. That is why vetting evidence reads <strong>review</strong> and never <strong>delete</strong> — and a rule marked review is never applied automatically, in any mode.'); ?>

    <?php ac_card_open('Learning resources', count($d['resources'] ?? []) . ''); ?>
      <div class="ac-tablewrap">
        <table class="ac-table">
          <thead><tr><th>Resource</th><th>Attached to</th><th></th></tr></thead>
          <tbody>
            <?php if (!($d['resources'] ?? [])): ?>
              <?php ac_table_empty(3, 'Nothing attached yet.'); ?>
            <?php else: foreach (array_slice($d['resources'], 0, 12) as $r): ?>
              <tr>
                <td><div class="ac-strong"><?= ac_h((string) $r['title']) ?></div>
                    <div class="ac-sub"><?= ac_tag((string) ($r['type'] ?: 'link')) ?></div></td>
                <td><?= ac_h((string) ($r['cohort'] ?: 'unattached')) ?></td>
                <td class="ac-num">
                  <?php ac_action_btn('resource_delete', 'files', ['resource_id' => (int) $r['id']],
                      'Remove', 'ac-btn ac-btn--sm ac-btn--danger', 'Remove this resource?'); ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    <?php ac_card_close('A resource may attach to a cohort or to a legacy class, and a student sees both — the union is what makes a course-only learner able to see their own materials.'); ?>
  </div>
</div>
