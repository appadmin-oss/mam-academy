<?php
/**
 * Admissions → Scholars Initiative: the MAM Scholars Initiative applications,
 * from scholarship_applications. They were invisible: the console's filter read
 * applications.pathway = 'scholarship', which nothing has ever written. The
 * committee reads the essays here; an award is still made through the student
 * type on the member record (scholarship.php decides nothing).
 */
declare(strict_types=1);
/** @var array $d */
$cd = $d['scholar_detail'] ?? null;
$schLabel = ['submitted' => 'Received', 'reviewing' => 'With the committee', 'awarded' => 'Awarded', 'declined' => 'Not awarded'];
?>
<div class="ac-cols ac-cols--main">
  <div class="ac-card">
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead><tr><th>Applicant</th><th>Age · experience</th><th>Readiness</th><th class="ac-num">Waiting</th><th></th></tr></thead>
        <tbody>
          <?php if (!($d['scholar_rows'] ?? [])): ?>
            <?php ac_table_empty(5, 'No Scholars Initiative applications yet. They land here from the /scholarship form.'); ?>
          <?php else: foreach ($d['scholar_rows'] as $r):
            $sel = $cd && (int) $cd['id'] === (int) $r['id']; ?>
            <tr class="<?= $sel ? 'is-selected' : '' ?>">
              <td>
                <div class="ac-strong"><?= ac_h((string) $r['full_name']) ?></div>
                <div class="ac-sub"><?= ac_h(ac_join([(string) $r['reference'], (string) ($r['application_reference'] ?? '')])) ?></div>
              </td>
              <td><?= ac_h(ac_join([str_replace('-', '–', (string) ($r['age_band'] ?? '')), (string) ($r['experience'] ?? '')]) ?: '—') ?></td>
              <td><?= ac_status((string) ($r['readiness_signal'] ?? 'review')) ?></td>
              <td class="ac-num"><?= mam_console_days_since((string) $r['created_at']) ?>d</td>
              <td class="ac-num"><a class="ac-btn ac-btn--sm" href="<?= ac_h(ac_url('admissions', ['type' => 'scholarship', 'app' => (int) $r['id']])) ?>">Read</a></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="ac-cardfoot">Readiness is advisory only — the requirements are shown to applicants as guidance, never as a gate. A human committee decides.</div>
  </div>

  <div class="ac-stack">
    <?php if (!$cd): ?>
      <?php ac_card_open('Scholars Initiative applicant'); ?>
        <div class="ac-cardbody"><?php ac_empty('No application selected', 'Open one from the list to read it.'); ?></div>
      <?php ac_card_close(); ?>
    <?php else: ?>
      <?php ac_card_open((string) $cd['full_name'], (string) $cd['reference'], ac_status((string) $cd['status'], $schLabel[(string) $cd['status']] ?? (string) $cd['status'])); ?>
        <div class="ac-cardbody">
          <div class="ac-defs">
            <?php
              ac_def('Email', ac_h((string) $cd['email']));
              ac_def('Age band', ac_h(str_replace('-', '–', (string) ($cd['age_band'] ?? '—'))));
              ac_def('Experience', ac_h((string) ($cd['experience'] ?? '—')));
              ac_def('Programme application', ac_h((string) ($cd['application_reference'] ?? '') ?: 'None linked'));
              ac_def('Committed to finish', ac_h((int) ($cd['commitment_ack'] ?? 0) === 1 ? 'Yes' : 'No'));
              ac_def('Received', ac_h(mam_console_date((string) $cd['created_at'], 'j M Y')));
            ?>
          </div>
          <?php foreach (['financial_need' => 'Financial need', 'musical_journey' => 'Musical journey',
                          'values_alignment' => 'Values', 'community_impact' => 'Community impact'] as $k => $label): ?>
            <h3 class="ac-label ac-mt"><?= ac_h($label) ?></h3>
            <p class="ac-note"><?= nl2br(ac_h((string) ($cd[$k] ?? '—'))) ?></p>
          <?php endforeach; ?>
        </div>
      <?php ac_card_close('An award is recorded on the student\'s member record (student type: scholarship), which exempts them from billing.'); ?>
    <?php endif; ?>
  </div>
</div>
