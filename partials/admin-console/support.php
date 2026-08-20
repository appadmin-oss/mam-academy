<?php
/** Support — one queue, answered in order. */
declare(strict_types=1);
/** @var array $d */
$median = $d['median_first_reply_min'] ?? null;
?>
<?php ac_head('Support',
    'Questions from parents, learners and staff · one queue, answered in order',
    '<a class="ac-btn" href="/admin/?v=support">Full ticket screen</a>'); ?>

<?php ac_kpis([
    ['label' => 'Open', 'value' => (string) (int) ($d['open'] ?? 0),
     'tone' => (int) ($d['open'] ?? 0) > 0 ? 'warn' : '', 'note' => 'oldest first'],
    ['label' => 'Median first reply', 'value' => $median !== null
        ? ($median >= 60 ? floor($median / 60) . 'h ' . ($median % 60) . 'm' : $median . 'm') : '—',
     'note' => 'over every ticket with a reply'],
    ['label' => 'Closed this week', 'value' => (string) (int) ($d['closed_week'] ?? 0), 'note' => 'resolved and shut'],
    ['label' => 'Contact-form messages', 'value' => (string) count($d['messages'] ?? []),
     'note' => 'a different table, a different queue'],
]); ?>

<?php ac_card_open('Tickets', count($d['rows'] ?? []) . ''); ?>
  <div class="ac-tablewrap">
    <table class="ac-table">
      <thead><tr><th>Ticket</th><th>Subject</th><th>From</th><th>Topic</th><th class="ac-num">Waiting</th>
                 <th>Status</th><th></th></tr></thead>
      <tbody>
        <?php if (!($d['rows'] ?? [])): ?>
          <?php ac_table_empty(7, 'Nothing in the queue.'); ?>
        <?php else: foreach ($d['rows'] as $t):
          $openTicket = in_array((string) $t['status'], ['open', 'pending', 'in_progress'], true); ?>
          <tr>
            <td class="ac-mono"><?= ac_h((string) ($t['reference'] ?: '#' . (int) $t['id'])) ?></td>
            <td><div class="ac-strong"><?= ac_h(mb_strimwidth((string) $t['subject'], 0, 60, '…')) ?></div>
                <?php if ((int) $t['replies'] > 0): ?>
                  <div class="ac-sub"><?= (int) $t['replies'] ?> message<?= (int) $t['replies'] === 1 ? '' : 's' ?></div>
                <?php endif; ?></td>
            <td><?= ac_h((string) ($t['student'] ?: '—')) ?>
                <div class="ac-sub"><?= ac_h((string) ($t['email'] ?? '')) ?></div></td>
            <td><?= ac_tag((string) ($t['category'] ?: 'general')) ?></td>
            <td class="ac-num"><?= $openTicket ? ac_h(mam_console_age((string) $t['created_at'])) : '<span class="ac-sub">—</span>' ?></td>
            <td><?= ac_status((string) $t['status']) ?></td>
            <td class="ac-num"><a class="ac-btn ac-btn--sm" href="/admin/?v=support&amp;ticket=<?= (int) $t['id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
<?php ac_card_close('A ticket assigned to nobody is a ticket nobody owns, so the queue is ordered by age rather than by assignee.'); ?>

<div class="ac-cols ac-cols--2">
  <?php ac_card_open('A ticket never carries the answer'); ?>
    <div class="ac-cardbody">
      <p class="ac-note">Replies point to the place in the portal where the family can see the thing for themselves.
      Pasting a balance into an email creates a second version of the truth, and the second version is the one people
      quote back six months later.</p>
    </div>
  <?php ac_card_close(); ?>

  <?php ac_card_open('The same question, more than once'); ?>
    <div class="ac-cardbody">
      <p class="ac-note">"The portal says my child is not enrolled" is usually not a support problem: it is a family
      whose payment cleared and whose enrolment was never completed. That is a gap in
      <a href="<?= ac_h(ac_url('roster')) ?>">Enrol &amp; staffing</a>, and the ticket is its symptom.</p>
      <p class="ac-note">Payment settlement completes the whole enrolment through one implementation, but a placement
      it cannot work out is deliberately left to the admin backlog rather than failing the payment — so that backlog
      is where to look first.</p>
      <div class="ac-row ac-mt">
        <a class="ac-btn" href="/admin/?v=applications&amp;f=incomplete">The incomplete-enrolment backlog</a>
      </div>
    </div>
  <?php ac_card_close(); ?>
</div>

<?php if ($d['messages'] ?? []): ?>
  <?php ac_card_open('Contact form', count($d['messages']) . ' most recent'); ?>
    <div class="ac-tablewrap">
      <table class="ac-table">
        <thead><tr><th>From</th><th>Topic</th><th>Status</th><th class="ac-num">When</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($d['messages'] as $m): ?>
            <tr>
              <td><div class="ac-strong"><?= ac_h((string) $m['name']) ?></div>
                  <div class="ac-sub"><?= ac_h((string) $m['email']) ?></div></td>
              <td><?= ac_tag((string) ($m['topic'] ?: 'general')) ?></td>
              <td><?= ac_status((string) $m['status']) ?></td>
              <td class="ac-num"><?= ac_h(mam_console_age((string) $m['created_at'])) ?></td>
              <td class="ac-num">
                <?php if ((string) $m['status'] !== 'closed'): ?>
                  <?php ac_action_btn('msg_status', 'support',
                      ['id' => (int) $m['id'], 'status' => 'closed'], 'Close', 'ac-btn ac-btn--sm'); ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php ac_card_close('A teaching-interest message queues a faculty invite rather than becoming a ticket — the pipeline for that is on the users screen.'); ?>
<?php endif; ?>
