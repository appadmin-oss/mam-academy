<?php
/** Auditions — placement, not selection. A result assigns a level; it never rejects. */
declare(strict_types=1);
/** @var array $d */
?>
<?php ac_head('Auditions',
    'Placement, not selection · a result assigns a level, it never rejects',
    '<a class="ac-btn" href="/auditer" target="_blank" rel="noopener">The applicant checklist</a>'); ?>

<?php ac_banner('flat', 'fa-circle-info',
    'Nobody is turned away by an audition. A low result places a student lower and says what to work on; '
    . 're-audition is available each session, and the panel\'s marks can be appealed. '
    . 'The placement automation is <strong>' . (($d['placement_on'] ?? false) ? 'on' : 'off')
    . '</strong> — with it on, recording a placement also ensures the account and enrols at the recommended level.',
    '<a class="ac-btn ac-btn--sm" href="' . ac_h(ac_url('settings')) . '">Automations</a>'); ?>

<div class="ac-cols ac-cols--main">
  <?php ac_card_open('Booked and awaiting a panel', count($d['booked'] ?? []) . ' applicant' . (count($d['booked'] ?? []) === 1 ? '' : 's')); ?>
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead><tr><th>Applicant</th><th>Instrument</th><th>Status</th><th class="ac-num">Waiting</th><th></th></tr></thead>
        <tbody>
          <?php if (!($d['booked'] ?? [])): ?>
            <?php ac_table_empty(5, 'Nobody is waiting for a placement audition.'); ?>
          <?php else: foreach ($d['booked'] as $b):
            $name = trim((string) ($b['first_name'] ?? '') . ' ' . (string) ($b['last_name'] ?? '')) ?: '—'; ?>
            <tr>
              <td><div class="ac-strong"><?= ac_h($name) ?></div>
                  <div class="ac-sub"><?= ac_h((string) ($b['reference'] ?? '')) ?></div></td>
              <td><?= ac_h((string) ($b['discipline'] ?? '—')) ?>
                  <?= $b['level'] ? '<span class="ac-subi">· L' . ac_h((string) $b['level']) . '</span>' : '' ?></td>
              <td><?= ac_status((string) $b['status']) ?></td>
              <td class="ac-num"><?= ac_h(mam_console_age((string) $b['created_at'])) ?></td>
              <td class="ac-num"><a class="ac-btn ac-btn--sm" href="<?= ac_h(ac_url('admissions', ['app' => (int) $b['id']])) ?>">Open</a></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php ac_card_close('A placement is captured from the application card, where the decision and the reason sit together.'); ?>

  <?php ac_card_open('Recorded placements', count($d['results'] ?? []) . ' on file'); ?>
    <div class="ac-cardbody">
      <?php if (!($d['results'] ?? [])): ?>
        <?php ac_empty('No placements recorded', 'A panel result is captured from the applicant card, action “record a placement”.'); ?>
      <?php else: ?>
        <div class="ac-timeline">
          <?php foreach (array_slice($d['results'], 0, 12) as $r): ?>
            <div class="ac-tl">
              <span class="ac-tlmark"><i></i><span></span></span>
              <div class="ac-tlbody">
                <div><?= ac_h((string) ($r['student'] ?? 'Unknown student')) ?>
                  → <?= ac_h((string) ($r['recommended_pathway'] ?? 'no level')) ?></div>
                <div class="ac-sub"><?= ac_h(mam_console_date((string) $r['audited_at'], 'j M Y')) ?>
                  <?= $r['auditor'] ? ' · ' . ac_h((string) $r['auditor']) : '' ?></div>
                <?php if (!empty($r['notes'])): ?>
                  <div class="ac-sub"><?= ac_h(mb_strimwidth((string) $r['notes'], 0, 160, '…')) ?></div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php ac_card_close('A result satisfies a level gate that a prerequisite would otherwise refuse. Overriding a gate without one needs a reason and a name, and is logged whether it succeeds or not.'); ?>
</div>
