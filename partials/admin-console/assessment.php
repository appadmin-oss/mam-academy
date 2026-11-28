<?php
/**
 * Assessment & boards — a mark is provisional until a board says otherwise.
 *
 * Termly reports: the list by the schema's own status set, one report opened
 * with its moderation sample, the board's three acts (moderate, ratify,
 * withdraw) and the appeals queue. Every rule is the lib's
 * (api/modules/learning/lib/reports.php): whether "Ratify" is live, and the
 * sentence shown when it is not, come from mam_report_ratify_refusal() via the
 * provider — this file never re-derives a ratification rule.
 */
declare(strict_types=1);
/** @var array $d */
$open    = (int) ($d['open_appeals'] ?? 0);
$mod     = $d['moderation'] ?? [];
$counts  = $d['reports'] ?? [];
$det     = $d['detail'] ?? null;
$labels  = ['draft' => 'Draft', 'moderating' => 'With the panel', 'ratified' => 'Ratified', 'withdrawn' => 'Withdrawn'];
$appealLabels = ['open' => 'Open', 'upheld' => 'Upheld', 'declined' => 'Declined', 'withdrawn' => 'Withdrawn'];
?>
<?php ac_head('Assessment & boards',
    'Moderation, ratification and appeals · a mark is provisional until a board says otherwise'); ?>

<?php ac_kpis([
    ['label' => 'Drafts', 'value' => (string) (int) ($counts['draft'] ?? 0),
     'note' => 'sent by instructors · not visible to any family'],
    ['label' => 'With the panel', 'value' => (string) (int) ($counts['moderating'] ?? 0),
     'note' => ($d['min_sample'] ?? null) !== null
        ? 'sample floor ' . (int) $d['min_sample'] . ' or ' . (int) round((float) ($d['min_share'] ?? 0)) . '% of scored criteria'
        : 'being second-marked'],
    ['label' => 'Ratified', 'value' => (string) (int) ($counts['ratified'] ?? 0),
     'note' => 'the only reports a family sees'],
    ['label' => 'Open appeals', 'value' => (string) $open, 'tone' => $open > 0 ? 'bad' : '',
     'note' => $open > 0 ? 'a report with one open cannot be ratified' : 'nothing contested'],
]); ?>

<?php if ($det !== null):
    $rep = $det['report'];
    $ms  = $det['moderation'];
    $rid = (int) $rep['id'];
    $live = in_array((string) $rep['status'], ['draft', 'moderating'], true);
    $hid = ['report_id' => $rid, '_console_id' => $rid]; ?>

  <div class="ac-row ac-mt">
    <a class="ac-btn ac-btn--sm" href="<?= ac_h(ac_url('assessment')) ?>"><i class="fa-solid fa-arrow-left"></i>All reports</a>
  </div>

  <?php if ($det['refusal'] === null): ?>
    <?php ac_banner('good', 'fa-circle-check',
      '<strong>Nothing is blocking ratification.</strong> The moderation sample meets its floor and no appeal is open.'); ?>
  <?php elseif ($live): ?>
    <?php ac_banner('warn', 'fa-lock', '<strong>Ratification is refused.</strong> ' . ac_h((string) $det['refusal_text'])); ?>
  <?php endif; ?>

  <div class="ac-cols ac-cols--main">
    <div class="ac-stack">
      <?php ac_card_open((string) ($rep['student_name'] ?? 'Report'),
          ac_join([(string) $rep['term_label'], (string) ($rep['course_name'] ?? ''), (string) ($rep['level_name'] ?? '')]),
          ac_status((string) $rep['status'], $labels[(string) $rep['status']] ?? null)); ?>
        <div class="ac-tablewrap">
          <table class="ac-table">
            <thead><tr><th>Criterion</th><th>Level</th><th class="ac-num">%</th><th>Second-marked</th></tr></thead>
            <tbody>
              <?php
                $marked = [];
                foreach ($rep['moderations'] ?? [] as $m) $marked[(string) $m['competency_code']][] = $m;
                foreach ($rep['criteria'] ?? [] as $c):
                  $code = (string) $c['competency_code']; ?>
                <tr>
                  <td><div class="ac-strong"><?= ac_h((string) $c['label']) ?></div><div class="ac-sub"><?= ac_h($code) ?></div></td>
                  <td><?= ac_h((string) ($det['levels'][$c['level']]['label'] ?? $c['level'])) ?></td>
                  <td class="ac-num"><?= $c['pct'] === null ? '—' : (int) $c['pct'] ?></td>
                  <td><?php if (empty($marked[$code])): ?>
                        <span class="ac-sub"><?= $c['level'] === 'insufficient' ? 'nothing to sample' : 'not yet' ?></span>
                      <?php else: foreach ($marked[$code] as $m): ?>
                        <div><?= (int) $m['agreed'] === 1 ? ac_pill('Agreed', 'good')
                              : ac_pill('Adjusted to ' . (string) ($det['levels'][$m['moderator_level']]['label'] ?? $m['moderator_level'] ?? '—'), 'warn') ?>
                          <span class="ac-sub"><?= ac_h((string) ($m['moderator'] ?? 'Unnamed')) ?><?= !empty($m['note']) ? ' · ' . ac_h((string) $m['note']) : '' ?></span></div>
                      <?php endforeach; endif; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php ac_card_close('Sample: ' . (int) $ms['sampled'] . ' of ' . (int) $ms['required'] . ' required, from '
          . (int) $ms['scored'] . ' scored criteria' . ((int) $ms['disagreed'] > 0 ? ' · ' . (int) $ms['disagreed'] . ' disagreement(s) go to a third marker, never to an average' : '') . '.'); ?>

      <?php if (!empty($rep['comment'])): ?>
        <?php ac_card_open('The instructor\'s comment', (string) ($rep['instructor'] ?? '')); ?>
          <div class="ac-cardbody"><p><?= ac_h((string) $rep['comment']) ?></p></div>
        <?php ac_card_close(); ?>
      <?php endif; ?>
    </div>

    <div class="ac-stack">
      <?php if ($live): ?>
        <?php ac_card_open('Record a moderation', 'a second marker\'s verdict on one criterion'); ?>
          <div class="ac-cardbody">
            <?php ac_form('report_moderate', 'assessment', $hid); ?>
              <label class="ac-field"><span class="ac-label">Criterion</span>
                <select class="ac-select" name="competency_code" required>
                  <?php foreach ($rep['criteria'] ?? [] as $c): if ($c['level'] === 'insufficient') continue; ?>
                    <option value="<?= ac_h((string) $c['competency_code']) ?>"><?= ac_h((string) $c['label']) ?> — <?= ac_h((string) ($det['levels'][$c['level']]['label'] ?? '')) ?></option>
                  <?php endforeach; ?>
                </select></label>
              <label class="ac-field ac-mt"><span class="ac-label">Moderator</span>
                <select class="ac-select" name="moderator_id">
                  <?php foreach ($d['staff'] ?? [] as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"<?= (int) $s['id'] === (int) ($d['me'] ?? 0) ? ' selected' : '' ?>><?= ac_h((string) $s['name']) ?></option>
                  <?php endforeach; ?>
                </select></label>
              <div class="ac-grid ac-grid--2 ac-mt">
                <label class="ac-field"><span class="ac-label">Verdict</span>
                  <select class="ac-select" name="verdict" required>
                    <option value="agreed">Agreed with the instructor</option>
                    <option value="adjusted">Adjusted — would give another level</option>
                  </select></label>
                <label class="ac-field"><span class="ac-label">Level if adjusted</span>
                  <select class="ac-select" name="moderator_level">
                    <option value="">—</option>
                    <?php foreach ($det['levels'] as $k => $l): ?>
                      <option value="<?= ac_h((string) $k) ?>"><?= ac_h((string) $l['label']) ?></option>
                    <?php endforeach; ?>
                  </select></label>
              </div>
              <label class="ac-field ac-mt"><span class="ac-label">Reason</span>
                <textarea class="ac-textarea" name="note" rows="2" placeholder="Required for an adjustment. What the evidence shows."></textarea></label>
              <div class="ac-row ac-mt"><button type="submit" class="ac-btn ac-btn--primary">Record the moderation</button></div>
            <?php ac_form_end(); ?>
          </div>
        <?php ac_card_close('The first moderation moves a draft to "With the panel". A disagreement is recorded, never averaged.'); ?>

        <?php ac_card_open('Ratify', 'releases the report to the student and guardians'); ?>
          <div class="ac-cardbody">
            <?php ac_form('report_ratify', 'assessment', $hid); ?>
              <label class="ac-field"><span class="ac-label">Board note (optional)</span>
                <input class="ac-input" name="note" maxlength="500"></label>
              <div class="ac-row ac-mt">
                <button type="submit" class="ac-btn ac-btn--primary"<?= $det['refusal'] !== null ? ' disabled aria-describedby="ac-ratify-why"' : '' ?>>Ratify and release</button>
              </div>
              <?php if ($det['refusal'] !== null): ?>
                <p class="ac-hint ac-mt" id="ac-ratify-why"><?= ac_h((string) $det['refusal_text']) ?></p>
              <?php endif; ?>
            <?php ac_form_end(); ?>
          </div>
        <?php ac_card_close('Guardians whose access includes reports are told in the portal, and by email where they allow it. A billing-only guardian is told nothing.'); ?>
      <?php endif; ?>

      <?php if ((string) $rep['status'] !== 'withdrawn'): ?>
        <?php ac_card_open('Withdraw', (string) $rep['status'] === 'ratified' ? 'takes a published report back from the family' : 'closes this report'); ?>
          <div class="ac-cardbody">
            <?php ac_form('report_withdraw', 'assessment', $hid); ?>
              <label class="ac-field"><span class="ac-label">Reason (kept on the record)</span>
                <textarea class="ac-textarea" name="reason" rows="2" required></textarea></label>
              <div class="ac-row ac-mt"><button type="submit" class="ac-btn">Withdraw the report</button></div>
            <?php ac_form_end(); ?>
          </div>
        <?php ac_card_close('The row and the reason are kept; withdrawing frees the slot for a corrected report.'); ?>
      <?php else: ?>
        <?php ac_card_open('Withdrawn', mam_console_date((string) ($rep['withdrawn_at'] ?? ''), 'j M Y')); ?>
          <div class="ac-cardbody"><p><?= ac_h((string) ($rep['withdrawn_reason'] ?? '')) ?></p></div>
        <?php ac_card_close(); ?>
      <?php endif; ?>

      <?php if ((string) $rep['status'] === 'ratified'): ?>
        <?php ac_card_open('Ratified', ac_join([(string) ($rep['ratified_by'] ?? ''), mam_console_date((string) ($rep['ratified_at'] ?? ''), 'j M Y')])); ?>
          <div class="ac-cardbody">
            <?php ac_def('Appeal window', !empty($rep['appeal']['open'])
                ? ac_pill((int) $rep['appeal']['days_left'] . ' days left', 'good') : ac_pill('Closed')); ?>
          </div>
        <?php ac_card_close(); ?>
      <?php endif; ?>
    </div>
  </div>

<?php else: ?>

  <div class="ac-row ac-mt">
    <?php ac_facet('assessment', 'status', 'Status',
        array_combine($d['statuses'] ?? [], array_map(fn($s) => $labels[$s] ?? $s, $d['statuses'] ?? [])) ?: [],
        ['status' => (string) ($d['status'] ?? '')], $counts); ?>
  </div>

  <div class="ac-cols ac-cols--main">
    <div class="ac-stack">
      <?php ac_card_open('Termly reports', count($d['list'] ?? []) . ' shown'); ?>
        <div class="ac-tablewrap">
          <table class="ac-table">
            <thead><tr><th>Student</th><th>Term</th><th>Band</th><th class="ac-num">Coverage</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <?php if (!($d['list'] ?? [])): ?>
                <?php ac_table_empty(6, 'No reports here. An instructor sends one from the Termly report screen of their portal.'); ?>
              <?php else: foreach ($d['list'] as $r): ?>
                <tr>
                  <td><div class="ac-strong"><?= ac_h((string) ($r['student'] ?? '—')) ?></div>
                      <div class="ac-sub"><?= ac_h((string) ($r['cohort'] ?? '')) ?></div></td>
                  <td><?= ac_h((string) $r['term_label']) ?></td>
                  <td><?= $r['band_low'] !== null
                      ? ac_pill((int) $r['band_low'] . '–' . (int) $r['band_high'])
                      : '<span class="ac-sub">withheld</span>' ?></td>
                  <td class="ac-num"><?= (int) $r['coverage_evidenced'] ?> / <?= (int) $r['coverage_total'] ?></td>
                  <td><?= ac_status((string) $r['status'], $labels[(string) $r['status']] ?? null) ?>
                      <?= (int) $r['open_appeals'] > 0 ? ac_pill('Appeal open', 'bad') : '' ?></td>
                  <td><a class="ac-btn ac-btn--sm" href="<?= ac_h(ac_url('assessment', ['id' => (int) $r['id']])) ?>">Open</a></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      <?php ac_card_close('A competency nobody has assessed yet shows no percentage, not a zero: a beginner is not failing.'); ?>

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
      <?php ac_card_close('A rubric with marks against it is never edited in place.'); ?>
    </div>

    <div class="ac-stack">
      <?php ac_card_open('Appeals', $open . ' open'); ?>
        <div class="ac-cardbody">
          <?php if (!($d['appeals'] ?? [])): ?>
            <?php ac_empty('No appeals', 'A guardian or a student may contest a mark on a ratified report for three months.'); ?>
          <?php else: ?>
            <div class="ac-timeline">
              <?php foreach ($d['appeals'] as $a): ?>
                <div class="ac-tl">
                  <span class="ac-tlmark"><i></i><span></span></span>
                  <div class="ac-tlbody">
                    <div><?= ac_h((string) ($a['student'] ?? 'A student')) ?>
                      <?= !empty($a['competency_code']) ? '· ' . ac_h((string) $a['competency_code']) : '' ?>
                      <?= ac_status((string) $a['status'] === 'open' ? 'pending' : (string) $a['status'], $appealLabels[(string) $a['status']] ?? null) ?></div>
                    <div class="ac-sub"><?= ac_h(mam_console_date((string) $a['opened_at'], 'j M')) ?>
                      · <?= ac_h((string) ($a['term_label'] ?? '')) ?> · <?= ac_h(mb_strimwidth((string) ($a['reason'] ?? ''), 0, 200, '…')) ?></div>
                    <?php if (!empty($a['outcome_note'])): ?><div class="ac-sub"><?= ac_h((string) $a['outcome_note']) ?></div><?php endif; ?>
                    <?php if ((string) $a['status'] === 'open'): ?>
                      <details><summary>Decide this appeal</summary>
                        <?php ac_form('appeal_decide', 'assessment', ['appeal_id' => (int) $a['id'], 'report_id' => (int) $a['report_id']]); ?>
                          <label class="ac-field"><span class="ac-label">Decision</span>
                            <select class="ac-select" name="status" required>
                              <option value="declined">Decline — the mark stands</option>
                              <option value="upheld">Uphold — the mark will be corrected</option>
                            </select></label>
                          <label class="ac-field ac-mt"><span class="ac-label">The sentence the family will read</span>
                            <textarea class="ac-textarea" name="outcome_note" rows="2" required></textarea></label>
                          <div class="ac-row ac-mt"><button type="submit" class="ac-btn ac-btn--primary">Record the decision</button>
                            <a class="ac-btn ac-btn--sm" href="<?= ac_h(ac_url('assessment', ['id' => (int) $a['report_id']])) ?>">Open the report</a></div>
                        <?php ac_form_end(); ?>
                      </details>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php ac_card_close('Upholding does not rewrite the mark: withdraw the report and build a corrected one, so the original stays on the record.'); ?>
    </div>
  </div>

<?php endif; ?>
