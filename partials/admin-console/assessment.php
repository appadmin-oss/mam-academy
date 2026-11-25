<?php
/** Assessment & boards — a mark is provisional until a board says otherwise. */
declare(strict_types=1);
/** @var array $d */
$open = (int) ($d['open_appeals'] ?? 0);
$mod  = $d['moderation'] ?? [];
$ratifiable = $open === 0 && (int) ($mod['n'] ?? 0) >= (int) ($d['min_sample'] ?? 0);
?>
<?php ac_head('Assessment & boards',
    'Rubric versions, moderation, appeals, ratification · a mark is provisional until a board says otherwise'); ?>

<?php ac_kpis([
    ['label' => 'Drafts awaiting a board', 'value' => (string) count($d['drafts'] ?? []),
     'note' => 'not visible to any family'],
    ['label' => 'Second-marked', 'value' => (string) (int) ($mod['n'] ?? 0),
     'note' => ($d['min_sample'] ?? null) !== null
        ? 'the floor is ' . (int) $d['min_sample'] . ' or '
          . (int) round(((float) ($d['min_share'] ?? 0)) * 100) . '% of the cohort'
        : 'moderation sample'],
    ['label' => 'Markers disagreed', 'value' => (string) (int) ($mod['disagreed'] ?? 0),
     'tone' => (int) ($mod['disagreed'] ?? 0) > 0 ? 'warn' : '',
     'note' => 'a disagreement goes to a third marker, never to an average'],
    ['label' => 'Open appeals', 'value' => (string) $open, 'tone' => $open > 0 ? 'bad' : '',
     'note' => $open > 0 ? 'ratification is refused while one is open' : 'nothing contested'],
]); ?>

<?php if (!$ratifiable): ?>
  <?php ac_banner('warn', 'fa-lock',
    '<strong>Ratification is blocked.</strong> '
    . ($open > 0 ? 'An appeal is open. ' : '')
    . ((int) ($mod['n'] ?? 0) < (int) ($d['min_sample'] ?? 0) ? 'The moderation sample is short. ' : '')
    . 'Releasing a mark a family can then have overturned is worse than releasing it a week late — so the check '
    . 'throws rather than returning false, because a caller could ignore a false.'); ?>
<?php else: ?>
  <?php ac_banner('good', 'fa-circle-check',
    '<strong>Nothing is blocking a board.</strong> No appeal is open and the moderation sample meets its floor. '
    . 'A report still needs at least one scored criterion and a named ratifier.'); ?>
<?php endif; ?>

<div class="ac-cols ac-cols--main">
  <div class="ac-stack">
    <?php ac_card_open('Rubrics', count($d['rubrics'] ?? []) . ' on file',
        '<span class="ac-cardmeta">Editing a published rubric creates a version — it never rewrites an awarded mark</span>'); ?>
      <div class="ac-tablewrap">
        <table class="ac-table">
          <thead><tr><th>Rubric</th><th>Cohort</th><th class="ac-num">Criteria</th><th class="ac-num">Marks</th><th>State</th></tr></thead>
          <tbody>
            <?php if (!($d['rubrics'] ?? [])): ?>
              <?php ac_table_empty(5, 'No rubrics authored. A competency grid is what a mark is defended against.'); ?>
            <?php else: foreach ($d['rubrics'] as $r): ?>
              <tr>
                <td class="ac-strong"><?= ac_h((string) $r['title']) ?></td>
                <td><?= ac_h((string) ($r['cohort'] ?: 'All cohorts')) ?></td>
                <td class="ac-num"><?= (int) $r['criteria'] ?></td>
                <td class="ac-num"><?= (int) $r['marks'] ?></td>
                <td><?= (int) $r['active'] === 1 ? ac_pill('In force', 'good') : ac_pill('Locked') ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    <?php ac_card_close('A rubric with marks against it is never edited in place. Weights that do not sum cannot be published — a grid that does not add up produces a score nobody can defend.'); ?>

    <?php ac_card_open('Reports awaiting a board', count($d['drafts'] ?? []) . ' draft' . (count($d['drafts'] ?? []) === 1 ? '' : 's')); ?>
      <div class="ac-tablewrap">
        <table class="ac-table">
          <thead><tr><th>Student</th><th>Term</th><th>Band</th><th class="ac-num">Coverage</th><th>Status</th></tr></thead>
          <tbody>
            <?php if (!($d['drafts'] ?? [])): ?>
              <?php ac_table_empty(5, 'Nothing waiting. A ratified report is the only kind a family sees.'); ?>
            <?php else: foreach ($d['drafts'] as $r): ?>
              <tr>
                <td><div class="ac-strong"><?= ac_h((string) ($r['student'] ?? '—')) ?></div>
                    <div class="ac-sub"><?= ac_h((string) ($r['cohort'] ?? '')) ?></div></td>
                <td><?= ac_h((string) $r['term_label']) ?></td>
                <td><?= $r['band_low'] !== null
                    ? ac_pill((int) $r['band_low'] . '–' . (int) $r['band_high'])
                    : '<span class="ac-sub">withheld</span>' ?></td>
                <td class="ac-num"><?= (int) $r['coverage_evidenced'] ?> / <?= (int) $r['coverage_total'] ?></td>
                <td><?= ac_status((string) $r['status']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    <?php ac_card_close('A competency nobody has assessed yet shows no percentage, not a zero: a beginner is not failing.'); ?>
  </div>

  <div class="ac-stack">
    <?php ac_card_open('Appeals', $open . ' open'); ?>
      <div class="ac-cardbody">
        <?php if (!($d['appeals'] ?? [])): ?>
          <?php ac_empty('No appeals', 'A guardian or a student may contest a mark against its published descriptor.'); ?>
        <?php else: ?>
          <div class="ac-timeline">
            <?php foreach ($d['appeals'] as $a): ?>
              <div class="ac-tl">
                <span class="ac-tlmark"><i></i><span></span></span>
                <div class="ac-tlbody">
                  <div><?= ac_h((string) ($a['student'] ?? 'A student')) ?> ·
                    <?= ac_h((string) ($a['competency_code'] ?? '')) ?>
                    <?= empty($a['closed_at']) ? ac_pill('Open', 'warn') : ac_status((string) $a['status']) ?></div>
                  <div class="ac-sub"><?= ac_h(mam_console_date((string) $a['opened_at'], 'j M')) ?>
                    · <?= ac_h(mb_strimwidth((string) ($a['reason'] ?? ''), 0, 150, '…')) ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php ac_card_close('An appeal changes a mark by an amendment with a reason, never by an edit. The original stays visible in the record — that is what makes the amendment trustworthy.'); ?>

    <?php ac_card_open('What a board actually checks'); ?>
      <div class="ac-cardbody">
        <div class="ac-defs">
          <?php
            ac_def('An open appeal', $open === 0 ? ac_pill('None', 'good') : ac_pill($open . ' open', 'bad'));
            ac_def('Moderation sample', (int) ($mod['n'] ?? 0) . ' second-marked');
            ac_def('A scored criterion', 'at least one, per report');
            ac_def('A named ratifier', 'no anonymous ratification');
          ?>
        </div>
      </div>
    <?php ac_card_close(); ?>
  </div>
</div>
