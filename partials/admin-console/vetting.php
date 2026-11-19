<?php
/** Staff & vetting — suitability evidence. References and dates only. */
declare(strict_types=1);
/** @var array $d */
$s = $d['summary'] ?? [];
$mode = (string) ($d['mode'] ?? 'warn');
$required = (array) ($d['required'] ?? []);
?>
<?php ac_head('Staff & vetting',
    'Suitability evidence for everyone who teaches a child · references and dates only'); ?>

<?php ac_kpis([
    ['label' => 'Instructors', 'value' => (string) count($d['instructors'] ?? []), 'note' => 'on the books'],
    ['label' => 'Cleared for minors', 'value' => (string) (int) ($s['cleared'] ?? 0), 'note' => 'full record held'],
    ['label' => 'Need action', 'value' => (string) (int) ($s['gaps'] ?? 0),
     'tone' => (int) ($s['gaps'] ?? 0) > 0 ? 'bad' : '', 'note' => 'missing a required check'],
    ['label' => 'Lapsing soon', 'value' => (string) (int) ($s['expiring'] ?? 0),
     'tone' => (int) ($s['expiring'] ?? 0) > 0 ? 'warn' : '', 'note' => 'validity is derived from the date'],
]); ?>

<?php ac_card_open('The gate', '', ac_pill(strtoupper($mode), $mode === 'enforce' ? 'good' : ($mode === 'off' ? 'bad' : 'warn'))); ?>
  <div class="ac-cardbody">
    <p class="ac-note"><strong>Warn</strong> records every gap and still lets an assignment through — the right
    setting while the records the academy already holds on paper are being loaded, and the wrong one to stay in.
    <strong>Enforce</strong> refuses to assign an uncleared instructor to any class containing a child, which is the
    target state. <strong>Off</strong> checks nothing and suits an academy that teaches no children.</p>
    <?php ac_form('safeguarding_mode', 'vetting'); ?>
      <div class="ac-inline ac-mt">
        <label class="ac-field ac-w-md"><span class="ac-label">Mode</span>
          <select class="ac-select" name="mode">
            <?php foreach (['off' => 'Off', 'warn' => 'Warn', 'enforce' => 'Enforce'] as $k => $label): ?>
              <option value="<?= ac_h($k) ?>" <?= $mode === $k ? 'selected' : '' ?>><?= ac_h($label) ?></option>
            <?php endforeach; ?>
          </select></label>
        <button type="submit" class="ac-btn ac-btn--primary">Save the mode</button>
        <?php if ((int) ($s['gaps'] ?? 0) > 0): ?>
          <span class="ac-hint">Switching to Enforce with <?= (int) $s['gaps'] ?> gap<?= (int) $s['gaps'] === 1 ? '' : 's' ?>
          open would refuse those instructors on any class containing a child.</span>
        <?php endif; ?>
      </div>
    <?php ac_form_end(); ?>
  </div>
<?php ac_card_close('An unclear result never fails an enrolment — the student is enrolled and the instructor simply not attached, because "enrolled, awaiting a cleared teacher" is actionable and a refused placement is not. An adult-only cohort is not gated at all; unknown age counts as a minor.'); ?>

<?php ac_card_open('The record', count($d['instructors'] ?? []) . ' instructor' . (count($d['instructors'] ?? []) === 1 ? '' : 's')); ?>
  <div class="ac-tablewrap">
    <table class="ac-table">
      <thead>
        <tr><th>Instructor</th>
          <?php foreach ($required as $type): ?>
            <th><?= ac_h(str_replace('_', ' ', (string) $type)) ?></th>
          <?php endforeach; ?>
          <th>Clearance</th></tr>
      </thead>
      <tbody>
        <?php if (!($d['instructors'] ?? [])): ?>
          <?php ac_table_empty(count($required) + 2, 'No instructor accounts.'); ?>
        <?php else: foreach ($d['instructors'] as $i):
          $have = [];
          foreach ((array) ($i['records'] ?? []) as $rec) $have[(string) $rec['clearance_type']] = $rec;
          $g = $i['gate'] ?? null; ?>
          <tr>
            <td><div class="ac-strong"><?= ac_h((string) $i['name']) ?></div>
                <div class="ac-sub"><?= ac_h((string) $i['email']) ?></div></td>
            <?php foreach ($required as $type):
              $rec = $have[(string) $type] ?? null;
              $expires = $rec['expires_on'] ?? null;
              $lapsed = $expires !== null && (string) $expires < date('Y-m-d');
              $soon = $expires !== null && !$lapsed && strtotime((string) $expires) < time() + 90 * 86400; ?>
              <td>
                <?php if (!$rec): ?><?= ac_pill('Missing', 'bad') ?>
                <?php elseif ($lapsed): ?><?= ac_pill('Lapsed', 'bad') ?>
                <?php elseif ($soon): ?><?= ac_pill(mam_console_date((string) $expires, 'M y'), 'warn') ?>
                <?php elseif ($expires): ?><?= ac_pill('to ' . mam_console_date((string) $expires, 'M y'), 'good') ?>
                <?php else: ?><?= ac_pill('Held', 'good') ?><?php endif; ?>
              </td>
            <?php endforeach; ?>
            <td>
              <?php if (!is_array($g)): ?><span class="ac-sub">Unknown</span>
              <?php elseif (!empty($g['ok'])): ?><?= ac_pill('Cleared', 'good') ?>
              <?php else: ?><?= ac_pill('Not cleared', 'bad') ?><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
<?php ac_card_close('The required set is a <strong>constant, not a setting</strong>: an academy able to shorten it would have a gate that certifies nothing. Where a UK instructor holds an Enhanced DBS, that satisfies the criminal-record limb without a Lagos search they cannot obtain — an alternative, not an exemption. Re-recording a check REPLACES it, because the gate asks "is there a current one" and a history of superseded certificate numbers is exactly the sensitive data this avoids accumulating.'); ?>

<div class="ac-cols ac-cols--2">
  <?php ac_card_open('What is stored, and what is not'); ?>
    <div class="ac-cardbody">
      <div class="ac-defs">
        <?php
          ac_def('Stored', 'A reference number, the date it was verified, the issuing body, and where the paper is filed.');
          ac_def('Never stored', 'The content of any check. No certificate scan, no referee\'s words, no search result, no offence data.');
        ?>
      </div>
      <p class="ac-note ac-mt">A system that holds the documents becomes the thing that leaks them.
      Holding the index instead is a smaller promise the academy can actually keep — and under the NDPA this is
      sensitive personal data about the instructor, so a certificate scan in a shared-hosting database is a
      liability, not a control.</p>
    </div>
  <?php ac_card_close('The test suite asserts the schema has no room for check content.'); ?>

  <?php ac_card_open('Designated Safeguarding Officer'); ?>
    <div class="ac-cardbody">
      <?php if ($d['dso'] ?? null): ?>
        <div class="ac-defs">
          <?php
            ac_def('Named officer', ac_h((string) ($d['dso']['name'] ?? '—')));
            ac_def('Contact', ac_h((string) ($d['dso']['email'] ?? '—')));
          ?>
        </div>
      <?php else: ?>
        <?php ac_banner('warn', 'fa-user-shield',
          '<strong>No officer is named.</strong> The policy requires a named person who keeps these records and '
          . 'reports quarterly. A policy with nobody responsible for it is the commonest way this requirement is missed.',
          ''); ?>
      <?php endif; ?>
    </div>
  <?php ac_card_close('Validity is DERIVED from the expiry date, never a stored status column — so a clearance lapsing at midnight reads as lapsed on the next page load rather than the next cron run.'); ?>
</div>
