<?php
/** Reports — every number links to the rows behind it, and says what it cannot tell you. */
declare(strict_types=1);
/** @var array $d */
?>
<?php ac_head('Reports',
    'Figures as at ' . ac_h(date('j M H:i')) . ' · every number here has rows behind it',
    '<a class="ac-btn" href="/admin/?v=export&amp;type=applications.csv">Export applications</a>'); ?>

<?php ac_kpis($d['kpis'] ?? []); ?>

<div class="ac-cols ac-cols--main">
  <div class="ac-stack">
    <?php ac_card_open('Enrolment by term', '', '<span class="ac-cardmeta">Counted at census, not on the day</span>'); ?>
      <div class="ac-cardbody">
        <?php
          $rows = array_values(array_filter($d['enrol_by_term'] ?? [], fn($t) => $t['n'] !== null));
          if (!$rows): ?>
            <?php ac_empty('No census taken', 'A term\'s headcount is a snapshot taken on its census date. Until one is taken there is no official figure — which is different from a figure of zero.',
              '<a class="ac-btn" href="' . ac_h(ac_url('calendar')) . '">Take a census</a>'); ?>
          <?php else:
            $max = max(array_map(fn($t) => (int) $t['n'], $rows)); ?>
            <div class="ac-bars">
              <?php foreach ($rows as $t): ?>
                <?php ac_bar((string) $t['label'], mam_console_pct((int) $t['n'], max(1, $max)), (string) (int) $t['n']); ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
      </div>
    <?php ac_card_close('A summer intake runs four weeks against a different fee table, so it is shown for completeness and does not compare like for like with a full term.'); ?>

    <?php ac_card_open('Where applicants stop', '', '<a class="ac-btn ac-btn--sm" href="' . ac_h(ac_url('funnel')) . '">The wizard funnel</a>'); ?>
      <div class="ac-cardbody">
        <div class="ac-bars">
          <?php
            $max = max(1, max(array_map(fn($s) => (int) $s['n'], $d['funnel'] ?? [['n' => 1]])));
            foreach ($d['funnel'] ?? [] as $s): ?>
            <?php ac_bar((string) $s['label'], mam_console_pct((int) $s['n'], $max), (string) (int) $s['n']); ?>
          <?php endforeach; ?>
        </div>
      </div>
    <?php ac_card_close('These are submitted applications by status. The half-finished drafts — the largest pool, and the one nothing reaches — are on the funnel screen.'); ?>

    <?php ac_card_open('Attendance by cohort', 'Session to date'); ?>
      <div class="ac-tablewrap">
        <table class="ac-table">
          <thead><tr><th>Cohort</th><th class="ac-num">Students</th><th class="ac-num">Marks</th><th>Rate</th></tr></thead>
          <tbody>
            <?php if (!($d['by_cohort'] ?? [])): ?>
              <?php ac_table_empty(4, 'No active cohorts with attendance recorded.'); ?>
            <?php else: foreach ($d['by_cohort'] as $c): ?>
              <tr>
                <td><?= ac_h((string) $c['title']) ?></td>
                <td class="ac-num"><?= (int) $c['students'] ?></td>
                <td class="ac-num"><?= (int) $c['marks'] ?></td>
                <td><?php if ($c['rate'] === null): ?><span class="ac-sub">nothing marked</span>
                    <?php else: ?><div class="ac-row"><?= ac_meter((int) $c['rate'], (int) $c['rate'] < 80 ? 'warn' : 'good') ?>
                      <span class="ac-pctfig"><?= (int) $c['rate'] ?>%</span></div><?php endif; ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    <?php ac_card_close('A cohort with nothing marked reads "nothing marked", never 0% — an unmeasured group is not a failing one.'); ?>
  </div>

  <div class="ac-stack">
    <?php ac_card_open('Aid and discounts'); ?>
      <div class="ac-cardbody">
        <?php if (!($d['aid'] ?? [])): ?>
          <div class="ac-note">Nothing awarded yet.</div>
        <?php else: ?>
          <div class="ac-defs">
            <?php $aidTotal = 0; foreach ($d['aid'] as $a): $aidTotal += (int) $a['usd_cents']; ?>
              <?php ac_def(ucfirst((string) $a['kind']) . ' (' . (int) $a['n'] . ')', ac_h(mam_console_usd((int) $a['usd_cents']))); ?>
            <?php endforeach; ?>
            <?php ac_def('Share of billed', (int) ($d['billed'] ?? 0) > 0
                ? mam_console_pct($aidTotal, (int) $d['billed']) . '%' : '—'); ?>
          </div>
        <?php endif; ?>
      </div>
    <?php ac_card_close('Gift aid and a discount are summed separately because only one of them is a cost the academy carries.'); ?>

    <?php ac_card_open('What this screen cannot tell you'); ?>
      <div class="ac-cardbody">
        <p class="ac-note">Continuation between terms is <strong>not</strong> whether a student is learning. A family
        can continue for a year and be poorly served.</p>
        <p class="ac-note">Mastery bands are <strong>not aggregated across students</strong>. A cohort average built
        from partial coverage would be a precise-looking number with nothing behind it.</p>
        <p class="ac-note">Nothing here is broken down by any protected characteristic. If the academy wants an equity
        view, that is a decision to take deliberately, with a stated purpose and a retention period.</p>
      </div>
    <?php ac_card_close(); ?>

    <?php if (($d['instructor_notice'] ?? '') !== ''): ?>
      <?php ac_card_open('On comparing instructors'); ?>
        <div class="ac-cardbody">
          <p class="ac-note"><?= ac_h((string) $d['instructor_notice']) ?></p>
        </div>
      <?php ac_card_close('The comparison table is formative only, discloses graded n, carries an interval, withholds comparative figures below a roster floor — and is ordered by NAME, because sorting by a metric makes it a ranking whatever the heading says.'); ?>
    <?php endif; ?>
  </div>
</div>
