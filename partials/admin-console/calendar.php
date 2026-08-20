<?php
/** Academic calendar — the only authority on a refund. */
declare(strict_types=1);
/** @var array $d */
$cur = $d['current'] ?? [];
?>
<?php ac_head('Academic calendar',
    'Everything else in the console reads its dates from here · adjudicated in '
    . ac_h((string) ($d['tz'] ?? 'Africa/Lagos')) . ', displayed in your zone',
    '<a class="ac-btn" href="/admin/?v=calendar">Author a term</a>'); ?>

<?php if (($cur['source'] ?? '') === 'computed'): ?>
  <?php ac_banner('warn', 'fa-circle-info',
    '<strong>The current term is computed, not authored.</strong> The window comes from the code\'s own constants, '
    . 'which is fine for a label on a page and not enough for a refund — a family is entitled to the table that was '
    . 'published when they enrolled. Author the term to make the refund schedule real.',
    '<a class="ac-btn ac-btn--sm" href="/admin/?v=calendar">Author it</a>'); ?>
<?php endif; ?>

<?php ac_card_open('Terms', count($d['terms'] ?? []) . ' on file', '<span class="ac-cardmeta">Overlapping sibling terms are refused</span>'); ?>
  <div class="ac-tablewrap">
    <table class="ac-table">
      <thead><tr><th>Term</th><th>Dates</th><th class="ac-num">Weeks</th><th>State</th><th class="ac-num">Census</th><th></th></tr></thead>
      <tbody>
        <?php if (!($d['terms'] ?? [])): ?>
          <?php ac_table_empty(6, 'No terms authored. Until one is, every screen falls back to the computed window — the strangler pattern, not a cutover.'); ?>
        <?php else: foreach ($d['terms'] as $t):
          $isCurrent = (int) ($cur['id'] ?? 0) === (int) $t['id']; ?>
          <tr class="<?= $isCurrent ? 'is-selected' : '' ?>">
            <td><div class="ac-strong"><?= ac_h(trim((string) ($t['label'] ?: $t['code']) . ' ' . (string) $t['year_label'])) ?></div>
                <?php if ($isCurrent && !empty($cur['week'])): ?>
                  <div class="ac-sub">Week <?= (int) $cur['week'] ?><?= $cur['weeks'] ? ' of ' . (int) $cur['weeks'] : '' ?></div>
                <?php endif; ?></td>
            <td><?= ac_h(mam_console_date((string) $t['starts_on'], 'j M Y')) ?> –
                <?= ac_h(mam_console_date((string) $t['ends_on'], 'j M Y')) ?></td>
            <td class="ac-num"><?= $t['teaching_weeks'] !== null ? (int) $t['teaching_weeks'] : '—' ?></td>
            <td><?= ac_status((string) ($t['status'] ?? 'draft')) ?><?= $isCurrent ? ' ' . ac_pill('Current', 'accent') : '' ?></td>
            <td class="ac-num">
              <?php if (!empty($t['census'])): ?>
                <?= (int) ($t['census']['headcount'] ?? 0) ?>
                <div class="ac-sub"><?= ac_h(mam_console_date((string) ($t['census']['taken_at'] ?? null))) ?></div>
              <?php else: ?><span class="ac-sub">not taken</span><?php endif; ?>
            </td>
            <td class="ac-num">
              <?php if (empty($t['census'])): ?>
                <?php ac_action_btn('term_census', 'calendar', ['term_id' => (int) $t['id']], 'Take census', 'ac-btn ac-btn--sm',
                    'Take the enrolment census for this term? It is a snapshot and cannot be retaken.'); ?>
              <?php else: ?><span class="ac-sub">locked</span><?php endif; ?>
            </td>
          </tr>
          <?php if ($t['closures'] ?? []): ?>
            <tr><td colspan="6">
              <div class="ac-row">
                <span class="ac-kpi-l">Closures</span>
                <?php foreach ($t['closures'] as $c): ?>
                  <span class="ac-pill"><?= ac_h((string) $c['label']) ?> ·
                    <?= ac_h(mam_console_date((string) $c['from_on'], 'j M')) ?><?php
                    if ((string) $c['to_on'] !== (string) $c['from_on']): ?>–<?= ac_h(mam_console_date((string) $c['to_on'], 'j M')) ?><?php endif; ?></span>
                <?php endforeach; ?>
              </div>
            </td></tr>
          <?php endif; ?>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
<?php ac_card_close('A census is a SNAPSHOT and append-only. "Enrolment as of the census date" must not move when somebody withdraws in week nine — recompute-on-read is the commonest way an official enrolment figure quietly rewrites history.'); ?>

<div class="ac-cols ac-cols--2">
  <?php ac_card_open('Refund schedules', 'Per term, published'); ?>
    <div class="ac-cardbody">
      <?php $any = false; foreach ($d['terms'] ?? [] as $t): if (!($t['refunds'] ?? [])) continue; $any = true; ?>
        <div class="ac-mb">
          <div class="ac-cardtitle"><?= ac_h(trim((string) ($t['label'] ?: $t['code']) . ' ' . (string) $t['year_label'])) ?></div>
          <div class="ac-defs">
            <?php foreach ($t['refunds'] as $r): ?>
              <?php ac_def(
                  'to ' . mam_console_date((string) ($r['until_on'] ?? $r['through_on'] ?? null), 'j M'),
                  (int) ($r['refund_pct'] ?? $r['pct'] ?? 0) . '%'
                  . (!empty($r['label']) ? ' <span class="ac-subi">' . ac_h((string) $r['label']) . '</span>' : '')); ?>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$any): ?>
        <div class="ac-note">No refund table is published. The default only <em>seeds</em> — once seeded, the rows
        are the truth, so re-saving a term's dates never silently reverts an edited table.</div>
      <?php endif; ?>
    </div>
  <?php ac_card_close('A refund percentage is COMPUTED from a date, never stored per student: two families withdrawing on the same day must get the same answer, and the answer must be reproducible a year later in an audit.'); ?>

  <?php ac_card_open('What the calendar decides'); ?>
    <div class="ac-cardbody">
      <div class="ac-defs">
        <?php
          ac_def('Today, in the academy\'s zone', ac_h((string) ($d['today'] ?? '')));
          ac_def('Server clock', 'never read for a deadline');
          ac_def('A closure', 'withdraws the class from the timetable, the register and the fee in one move');
          ac_def('A withdrawal', 'records add/drop or a "W" with its percentage into the transition reason');
        ?>
      </div>
      <p class="ac-note ac-mt">A diaspora guardian in New York must not get a different refund
      from a Lagos guardian because their clocks disagree about the date. Consulting the calendar on a withdrawal is
      best-effort by design — an unauthored calendar must never stop a student leaving a course.</p>
    </div>
  <?php ac_card_close(); ?>
</div>
