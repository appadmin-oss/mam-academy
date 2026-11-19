<?php
/** Guardians & consent — who may see a record, who may collect, and who pays. */
declare(strict_types=1);
/** @var array $d */
$con = $d['consents'] ?? [];
$total = (int) ($con['total'] ?? 0);
$students = (int) ($d['students'] ?? 0);
?>
<?php ac_head('Guardians & consent',
    'Who may see a record, who may collect a child, and who pays. Three different answers.'); ?>

<?php ac_kpis([
    ['label' => 'Linked guardians', 'value' => (string) count($d['links'] ?? []),
     'note' => 'across ' . $students . ' active learner' . ($students === 1 ? '' : 's')],
    ['label' => 'No guardian on file', 'value' => (string) count($d['no_guardian'] ?? []),
     'tone' => count($d['no_guardian'] ?? []) > 0 ? 'bad' : '', 'note' => 'blocks a report release'],
    ['label' => 'Photo consent held', 'value' => $total > 0 ? (int) ($con['photo'] ?? 0) . ' of ' . $total : '—',
     'note' => 'per guardian, not per child'],
    ['label' => 'Billing-only scopes', 'value' => (string) (int) ($d['scopes']['billing'] ?? 0),
     'note' => 'invoices and receipts, no marks'],
]); ?>

<?php if ($d['no_guardian'] ?? []): ?>
  <?php ac_banner('warn', 'fa-people-roof',
    '<strong>' . count($d['no_guardian']) . ' learner' . (count($d['no_guardian']) === 1 ? '' : 's')
    . ' with no guardian named.</strong> They can attend — removing a child from class over a data gap punishes '
    . 'the wrong person — but a termly report cannot be released and no payment can be attributed to a guardian '
    . 'until one is named.'); ?>
<?php endif; ?>

<div class="ac-cols ac-cols--main">
  <?php ac_card_open('Links', count($d['links'] ?? []) . ''); ?>
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead><tr><th>Learner</th><th>Guardian</th><th>Relationship</th><th>Access scope</th><th></th></tr></thead>
        <tbody>
          <?php if (!($d['links'] ?? [])): ?>
            <?php ac_table_empty(5, 'No guardians linked yet.'); ?>
          <?php else: foreach ($d['links'] as $l):
            $scope = (string) ($l['access_scope'] ?: 'standard'); ?>
            <tr>
              <td class="ac-strong"><?= ac_h((string) $l['student']) ?></td>
              <td><div><?= ac_h((string) $l['parent']) ?></div>
                  <div class="ac-sub"><?= ac_h((string) $l['parent_email']) ?></div></td>
              <td><?= ac_h((string) ($l['relationship'] ?: '—')) ?></td>
              <td><?= ac_pill(ucfirst($scope), $scope === 'full' ? 'accent' : ($scope === 'billing' ? '' : 'good')) ?></td>
              <td class="ac-num">
                <?php ac_action_btn('guardian_unlink', 'guardians',
                    ['parent_id' => (int) $l['parent_id'], 'student_id' => (int) $l['student_id']],
                    'Unlink', 'ac-btn ac-btn--sm ac-btn--danger',
                    'Remove this guardian\'s link to the learner?'); ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php ac_card_close('An unrecognised scope value reads as the NARROWEST scope, not the widest — SQLite cannot add a CHECK to an existing column, so the predicate is where that guarantee lives.'); ?>

  <div class="ac-stack">
    <?php ac_card_open('The three access scopes'); ?>
      <div class="ac-cardbody">
        <div class="ac-defs">
          <?php
            ac_def('Billing only (' . (int) ($d['scopes']['billing'] ?? 0) . ')', 'Invoices and receipts. No marks, no attendance.');
            ac_def('Standard (' . (int) ($d['scopes']['standard'] ?? 0) . ')', 'Adds ratified reports, attendance, timetable.');
            ac_def('Full (' . (int) ($d['scopes']['full'] ?? 0) . ')', 'Adds consents and the ability to act for the child.');
          ?>
        </div>
        <?php if ($d['never'] ?? []): ?>
          <div class="ac-note ac-mt">
            <strong>No scope ever grants:</strong>
            <?= ac_h(implode(', ', array_map(fn($k) => str_replace('_', ' ', (string) $k), (array) $d['never']))) ?>.
            That list is checked FIRST, so adding a grant to “full” cannot open one.
          </div>
        <?php endif; ?>
      </div>
    <?php ac_card_close('Every existing link defaults to <strong>standard</strong>, because a migration that narrowed live access would be worse than the bug it fixed.'); ?>

    <?php ac_card_open('Consent is per guardian, not per child'); ?>
      <div class="ac-cardbody">
        <p class="ac-note">Where two guardians disagree, the child is treated as <strong>not consented</strong>.
        A recital photograph, a website portrait and a class recording are three separate permissions and none of
        them inherits from the others.</p>
        <?php if ($total > 0): ?>
          <div class="ac-defs ac-mt-sm">
            <?php
              ac_def('Photography', (int) ($con['photo'] ?? 0) . ' of ' . $total . ' held');
              ac_def('Media / recording', (int) ($con['media'] ?? 0) . ' of ' . $total . ' held');
              ac_def('Data processing', (int) ($con['data'] ?? 0) . ' of ' . $total . ' held');
            ?>
          </div>
        <?php else: ?>
          <div class="ac-note ac-mt-sm">No consent records yet.</div>
        <?php endif; ?>
      </div>
    <?php ac_card_close('The recital seating chart and the photographer\'s brief both read this list directly. Nobody re-types it, so nobody gets it wrong.'); ?>
  </div>
</div>

<?php if ($d['no_guardian'] ?? []): ?>
  <?php ac_card_open('Learners with nobody named', count($d['no_guardian']) . ''); ?>
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead><tr><th>Learner</th><th>Account since</th></tr></thead>
        <tbody>
          <?php foreach ($d['no_guardian'] as $s): ?>
            <tr>
              <td><div class="ac-strong"><?= ac_h((string) $s['name']) ?></div>
                  <div class="ac-sub"><?= ac_h((string) $s['email']) ?></div></td>
              <td><?= ac_h(mam_console_date((string) $s['created_at'], 'j M Y')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php ac_card_close(); ?>
<?php endif; ?>
