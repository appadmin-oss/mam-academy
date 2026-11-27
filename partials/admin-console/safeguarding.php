<?php
/** Safeguarding — the officer's queue of concerns (api/modules/safeguarding/lib/safeguarding.php, v99). */
declare(strict_types=1);
/** @var array $d */
$c = $d['counts'] ?? [];
$cats = (array) ($d['categories'] ?? []);
$one = $d['concern'] ?? null;
?>
<?php ac_head('Safeguarding',
    'Concerns raised by students, families, staff and the screens that hold words · never deleted, closed with an outcome'); ?>

<?php if (!($d['officer'] ?? null)): ?>
  <?php ac_banner('bad', 'fa-triangle-exclamation', '<strong>No safeguarding officer is named.</strong> Every super admin is told about each concern until one is. Name the officer on <a href="' . ac_h(ac_url('vetting')) . '">Staff &amp; vetting</a>.'); ?>
<?php endif; ?>

<?php ac_kpis([
    ['label' => 'Not yet looked at', 'value' => (string) (int) ($c['open'] ?? 0), 'tone' => (int) ($c['open'] ?? 0) > 0 ? 'bad' : '', 'note' => 'open'],
    ['label' => 'Urgent', 'value' => (string) (int) ($c['urgent'] ?? 0), 'tone' => (int) ($c['urgent'] ?? 0) > 0 ? 'bad' : '', 'note' => 'not closed'],
    ['label' => 'Being worked', 'value' => (string) (int) ($c['working'] ?? 0), 'note' => 'assessing or referred'],
    ['label' => 'Held in class spaces', 'value' => (string) (int) ($c['held'] ?? 0), 'note' => 'teachers decide most of these'],
]); ?>

<?php if ($one): ?>
  <?php ac_card_open('Concern #' . (int) $one['id'], MAM_SG_CATEGORIES[$one['category']] ?? (string) $one['category'],
      ac_status((string) $one['status']) . ((string) $one['urgency'] === 'urgent' ? ' ' . ac_pill('Urgent', 'bad') : '')); ?>
    <div class="ac-cardbody">
      <div class="ac-defs">
        <?php
          ac_def('Raised', ac_h(mam_console_date((string) $one['created_at'], 'j M Y, H:i')) . ' · '
              . ((string) $one['source'] === 'automatic' ? 'held automatically' : 'by ' . ac_h((string) ($one['raised_by_name'] ?? 'somebody')) . ' (' . ac_h((string) ($one['raised_by_role'] ?? '')) . ')'));
          if (!empty($one['about_name'])) ac_def('About', ac_h((string) $one['about_name']));
          if (!empty($one['acknowledged_at'])) ac_def('First looked at', ac_h(mam_console_date((string) $one['acknowledged_at'], 'j M Y, H:i')));
        ?>
      </div>
      <p class="ac-note ac-mt"><?= nl2br(ac_h((string) $one['body'])) ?></p>
      <?php if ($s = $one['subject']): ?>
        <div class="ac-banner ac-mt"><span><strong>What it is about:</strong>
          <?= ac_h((string) ($s['author_name'] ?? 'Somebody')) ?><?= isset($s['space_title']) ? ' in ' . ac_h((string) $s['space_title']) : '' ?> —
          “<?= ac_h(mb_substr((string) ($s['body'] ?? ''), 0, 400)) ?>”
          <?= !empty($s['media_id']) ? ' <a href="/api/media/object?id=' . (int) $s['media_id'] . '" target="_blank" rel="noopener">Open the file</a>' : '' ?>
          · now <?= ac_h((string) $s['status']) ?></span></div>
      <?php endif; ?>
      <?php foreach ((array) ($one['notes'] ?? []) as $n): ?>
        <p class="ac-sub ac-mt"><strong><?= ac_h((string) ($n['author_name'] ?? '')) ?></strong>,
          <?= ac_h(mam_console_date((string) $n['created_at'], 'j M H:i')) ?>: <?= ac_h((string) $n['body']) ?></p>
      <?php endforeach; ?>
      <?php if ((string) $one['status'] === 'closed'): ?>
        <p class="ac-note ac-mt"><strong>Outcome:</strong> <?= ac_h((string) ($one['outcome'] ?? '')) ?></p>
      <?php endif; ?>
      <?php ac_form('sg_update', 'safeguarding', ['id' => (int) $one['id']]); ?>
        <div class="ac-inline ac-mt">
          <label class="ac-field ac-w-md"><span class="ac-label">Status</span>
            <select class="ac-select" name="status">
              <?php foreach (['open' => 'Open', 'assessing' => 'Assessing', 'referred' => 'Referred on', 'closed' => 'Closed'] as $k => $l): ?>
                <option value="<?= ac_h($k) ?>" <?= (string) $one['status'] === $k ? 'selected' : '' ?>><?= ac_h($l) ?></option>
              <?php endforeach; ?>
            </select></label>
          <label class="ac-field ac-grow"><span class="ac-label">Note (who you spoke to, what you did)</span>
            <input class="ac-input" name="note" maxlength="5000"></label>
        </div>
        <label class="ac-field ac-mt"><span class="ac-label">Outcome — needed to close it</span>
          <textarea class="ac-input" name="outcome" rows="2" maxlength="4000"></textarea></label>
        <div class="ac-inline ac-mt">
          <label class="ac-sub"><input type="checkbox" name="take" value="1"> Take this concern</label>
          <button type="submit" class="ac-btn ac-btn--primary">Save</button>
        </div>
      <?php ac_form_end(); ?>
    </div>
  <?php ac_card_close('The person who raised it sees only that it was received. Tell them what was done, if you can, in person or by message — unless telling them would put a child at greater risk.'); ?>
<?php endif; ?>

<?php ac_card_open('Concerns', count($d['list'] ?? []) . ' shown',
    '<span class="ac-cardmeta">'
    . '<a href="' . ac_h(ac_url('safeguarding', ['f' => 'open'])) . '">Not closed</a> · '
    . '<a href="' . ac_h(ac_url('safeguarding', ['f' => 'closed'])) . '">Closed</a> · '
    . '<a href="' . ac_h(ac_url('safeguarding', ['f' => 'all'])) . '">All</a></span>'); ?>
  <div class="ac-tablewrap">
    <table class="ac-table">
      <thead><tr><th>#</th><th>Raised</th><th>What</th><th>About</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php if (!($d['list'] ?? [])): ?>
          <?php ac_table_empty(6, 'Nothing here.'); ?>
        <?php else: foreach ($d['list'] as $r): ?>
          <tr>
            <td class="ac-num"><?= (int) $r['id'] ?></td>
            <td><?= ac_h(mam_console_date((string) $r['created_at'], 'j M, H:i')) ?>
              <div class="ac-sub"><?= (string) $r['source'] === 'automatic' ? 'held automatically' : ac_h((string) ($r['raised_by_name'] ?? '')) ?></div></td>
            <td><div class="ac-strong"><?= ac_h($cats[$r['category']] ?? (string) $r['category']) ?></div>
              <div class="ac-sub"><?= ac_h(mb_substr((string) $r['body'], 0, 90)) ?></div></td>
            <td><?= ac_h((string) ($r['about_name'] ?? '—')) ?></td>
            <td><?= ac_status((string) $r['status']) ?><?= (string) $r['urgency'] === 'urgent' ? ' ' . ac_pill('Urgent', 'bad') : '' ?>
              <?= !empty($r['assigned_name']) ? '<div class="ac-sub">' . ac_h((string) $r['assigned_name']) . '</div>' : '' ?></td>
            <td><a class="ac-btn ac-btn--sm" href="<?= ac_h(ac_url('safeguarding', ['concern' => (int) $r['id'], 'f' => (string) ($d['filter'] ?? 'open')])) ?>">Open</a></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
<?php ac_card_close('Anybody signed in can raise a concern from their portal. A class post or comment with words about harm is held and appears here by itself, marked automatic.'); ?>

<?php ac_card_open('Incidents', count($d['incidents'] ?? []) . ' open', '<span class="ac-cardmeta">First aid, health, behaviour, collection — and safeguarding, which goes to the officer</span>'); ?>
  <div class="ac-cardbody">
    <?php foreach ($d['incidents'] ?? [] as $in): ?>
      <div class="ac-mt">
        <div class="ac-strong"><?= ac_h(($d['incident_kinds'][$in['kind']] ?? $in['kind']) . ' · ' . (string) $in['student_name']) ?>
          <?= (string) $in['kind'] === 'safeguarding' ? ac_pill('With the officer', 'bad') : '' ?>
          <?= $in['told_at'] ? ac_pill('Family told', 'good') : '' ?></div>
        <div class="ac-sub"><?= ac_h(mam_console_date((string) $in['happened_at'], 'j M, H:i')) ?><?= $in['place'] ? ' · ' . ac_h((string) $in['place']) : '' ?> · recorded by <?= ac_h((string) ($in['recorded_by_name'] ?? '')) ?></div>
        <p class="ac-note"><?= ac_h((string) $in['what']) ?><?= $in['action_taken'] ? ' — ' . ac_h((string) $in['action_taken']) : '' ?></p>
        <?php if ((string) $in['kind'] !== 'safeguarding'): ?>
          <div class="ac-inline">
            <?php if (!$in['told_at']): ?><?php ac_action_btn('incident_tell', 'safeguarding', ['id' => (int) $in['id']], $in['family_note'] ? 'Tell the family' : 'No family note yet', 'ac-btn ac-btn--sm'); ?><?php endif; ?>
            <?php ac_form('incident_close', 'safeguarding', ['id' => (int) $in['id']], 'class="ac-row"'); ?>
              <input class="ac-input" name="outcome" placeholder="How it ended" maxlength="1000" required>
              <button type="submit" class="ac-btn ac-btn--sm">Close</button>
            <?php ac_form_end(); ?>
          </div>
        <?php elseif ($in['concern_id']): ?>
          <a class="ac-subi" href="<?= ac_h(ac_url('safeguarding', ['concern' => (int) $in['concern_id']])) ?>">Open the concern</a>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <details class="ac-mt"><summary class="ac-strong">Record an incident</summary>
      <?php ac_form('incident_record', 'safeguarding'); ?>
        <div class="ac-grid ac-grid--2 ac-mt">
          <label class="ac-field"><span class="ac-label">Student's account number</span><input class="ac-input" name="student_id" inputmode="numeric" required></label>
          <label class="ac-field"><span class="ac-label">What kind</span><select class="ac-select" name="kind">
            <?php foreach ($d['incident_kinds'] ?? [] as $k => $l): ?><option value="<?= ac_h($k) ?>"><?= ac_h($l) ?></option><?php endforeach; ?></select></label>
          <label class="ac-field"><span class="ac-label">When</span><input class="ac-input" type="datetime-local" name="happened_at"></label>
          <label class="ac-field"><span class="ac-label">Where</span><input class="ac-input" name="place" maxlength="120"></label>
        </div>
        <label class="ac-field ac-mt"><span class="ac-label">What happened (staff only — never sent to a family)</span><textarea class="ac-input" name="what" rows="3" required></textarea></label>
        <label class="ac-field ac-mt"><span class="ac-label">What was done</span><input class="ac-input" name="action_taken" maxlength="2000"></label>
        <label class="ac-field ac-mt"><span class="ac-label">What the family will be told (not for safeguarding)</span><textarea class="ac-input" name="family_note" rows="2"></textarea></label>
        <div class="ac-row ac-mt"><button type="submit" class="ac-btn ac-btn--primary">Record it</button></div>
      <?php ac_form_end(); ?>
    </details>
  </div>
<?php ac_card_close('A concern nobody opens is sent again to the officer and every super admin after ' . (int) ($d['escalate_hours'] ?? 4) . ' hours. That cannot be switched off.'); ?>
