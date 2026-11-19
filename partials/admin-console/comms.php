<?php
/** Communications — the outbox, and what it is for. */
declare(strict_types=1);
/** @var array $d */
$by = $d['by_status'] ?? [];
?>
<?php ac_head('Communications',
    'Every message is written to the outbox by the thing that caused it'); ?>

<?php ac_kpis([
    ['label' => 'Sent, 24h', 'value' => (string) (int) ($d['sent_24h'] ?? 0), 'note' => 'across every context'],
    ['label' => 'Queued', 'value' => (string) (int) ($by['queued'] ?? 0), 'note' => 'awaiting the transport'],
    ['label' => 'Failed', 'value' => (string) (int) ($by['failed'] ?? 0),
     'tone' => (int) ($by['failed'] ?? 0) > 0 ? 'bad' : '', 'note' => 'retryable from the outbox'],
    ['label' => 'Guardian threads', 'value' => (string) (int) ($d['threads'] ?? 0), 'note' => 'a conversation, not a broadcast'],
]); ?>

<?php if ((int) ($by['failed'] ?? 0) > 0): ?>
  <?php ac_banner('bad', 'fa-envelope-circle-check',
    '<strong>' . (int) $by['failed'] . ' message' . ((int) $by['failed'] === 1 ? '' : 's')
    . ' failed to send.</strong> A failed mail is a row with its error, not a silence — the row is what makes a '
    . 'retry possible, and a transport that drops mail without one is indistinguishable from success.'); ?>
<?php endif; ?>

<?php ac_card_open('By context, last thirty days', count($d['contexts'] ?? []) . ' kinds',
    '<span class="ac-cardmeta">Each context states one required next action</span>'); ?>
  <div class="ac-tablewrap">
    <table class="ac-table">
      <thead><tr><th>Context</th><th class="ac-num">Sent</th><th class="ac-num">Failed</th><th class="ac-num">Total</th></tr></thead>
      <tbody>
        <?php if (!($d['contexts'] ?? [])): ?>
          <?php ac_table_empty(4, 'Nothing sent in the last thirty days.'); ?>
        <?php else: foreach ($d['contexts'] as $c): ?>
          <tr>
            <td class="ac-mono"><?= ac_h((string) ($c['context'] ?: 'unlabelled')) ?></td>
            <td class="ac-num"><?= (int) $c['sent'] ?></td>
            <td class="ac-num"><?= (int) $c['failed'] > 0
                ? '<span class="ac-pill ac-pill--bad">' . (int) $c['failed'] . '</span>' : '—' ?></td>
            <td class="ac-num"><?= (int) $c['n'] ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
<?php ac_card_close('A context is what the message was FOR, so the row that failed can be traced back to the decision that produced it rather than to a template name.'); ?>

<div class="ac-cols ac-cols--main">
  <?php ac_card_open('Recent', count($d['recent'] ?? []) . ''); ?>
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead><tr><th>To</th><th>Subject</th><th>Context</th><th>Status</th><th class="ac-num">When</th></tr></thead>
        <tbody>
          <?php if (!($d['recent'] ?? [])): ?>
            <?php ac_table_empty(5, 'Nothing in the outbox.'); ?>
          <?php else: foreach ($d['recent'] as $m): ?>
            <tr>
              <td><?= ac_h((string) $m['to_email']) ?></td>
              <td><?= ac_h(mb_strimwidth((string) $m['subject'], 0, 60, '…')) ?>
                  <?php if (!empty($m['error'])): ?><div class="ac-sub"><?= ac_h(mb_strimwidth((string) $m['error'], 0, 90, '…')) ?></div><?php endif; ?></td>
              <td class="ac-mono"><?= ac_h((string) ($m['context'] ?? '')) ?></td>
              <td><?= ac_status((string) $m['status']) ?></td>
              <td class="ac-num"><?= ac_h(mam_console_age((string) $m['created_at'])) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php ac_card_close('<strong>No password is ever emailed.</strong> An account is created with an unusable hash and an invitation link is sent instead; the invite lives seven days, because an invitation is answered when a parent next looks at their phone, not in the minutes a reset gets.'); ?>

  <div class="ac-stack">
    <?php ac_card_open('Announcements', count($d['announcements'] ?? []) . ''); ?>
      <div class="ac-cardbody">
        <?php if (!($d['announcements'] ?? [])): ?>
          <div class="ac-note">Nothing posted.</div>
        <?php else: ?>
          <div class="ac-timeline">
            <?php foreach (array_slice($d['announcements'], 0, 8) as $a): ?>
              <div class="ac-tl">
                <span class="ac-tlmark"><i></i><span></span></span>
                <div class="ac-tlbody">
                  <div><?= ac_h((string) $a['title']) ?></div>
                  <div class="ac-sub"><?= ac_h(mam_console_date((string) $a['created_at'], 'j M')) ?>
                    · <?= ac_h((string) (($a['cohort'] ?: $a['class']) ?: 'everyone')) ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php ac_card_close('An announcement may target a cohort or a legacy class, and a student sees the union of both — without that, a course-only learner never saw an update at all.'); ?>

    <?php ac_card_open('A preference is an instruction'); ?>
      <div class="ac-cardbody">
        <p class="ac-note">Channels, digest frequency and quiet hours live on the person, not the device, so what a
        guardian asked for on a phone applies to the email sent an hour later. <strong>An empty channel set is a valid
        answer</strong> and is honoured.</p>
        <p class="ac-note">Two topics override the toggles and say so in words: <strong>safeguarding</strong> and
        <strong>money</strong>. There is no per-topic column, so "mute safeguarding" is not a storable state — which
        is the point.</p>
      </div>
    <?php ac_card_close('Urgency is a property of the message, so a digest never delays either of those.'); ?>
  </div>
</div>
