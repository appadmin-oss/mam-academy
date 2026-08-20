<?php
/** Dashboard — the four decisions that block something, then the position. */
declare(strict_types=1);
/** @var array $d */
$term = $d['term'] ?? [];
$h = $d['health'] ?? [];
?>
<?php ac_head('Dashboard',
    ac_h(date('l j F')) . ' · ' . ac_h($term['line'] ?? '') . ' · all times '
    . ac_h((string) (defined('MAM_CALENDAR_TZ') ? MAM_CALENDAR_TZ : 'Africa/Lagos'))); ?>

<?php ac_kpis($d['kpis'] ?? []); ?>

<div class="ac-cols ac-cols--main">
  <div class="ac-stack">

    <?php
    $blocking = count(array_filter($d['decisions'] ?? [], fn($q) => !empty($q['blocking'])));
    ac_card_open('Needs a decision',
        $blocking > 0 ? $blocking . ' blocking' : 'Nothing blocking',
        '<span class="ac-cardmeta">Deduped — one row per obligation</span>'); ?>
      <div class="ac-queue">
        <?php if (!($d['decisions'] ?? [])): ?>
          <?php ac_empty('Nothing is waiting on you',
            'Every application has been looked at, every register is marked and no money is unmatched.',
            '<a class="ac-btn" href="' . ac_h(ac_url('admissions')) . '">Open admissions</a>'); ?>
        <?php else: foreach ($d['decisions'] as $q): ?>
          <a class="ac-qitem<?= !empty($q['blocking']) ? ' is-blocking' : '' ?>" href="<?= ac_h(ac_url((string) $q['screen'])) ?>">
            <div>
              <div class="ac-qtitle"><?= ac_h($q['title']) ?></div>
              <div class="ac-qwhy"><?= ac_h($q['why']) ?></div>
            </div>
            <span class="ac-qage"><?= ac_h($q['age']) ?></span>
          </a>
        <?php endforeach; endif; ?>
      </div>
    <?php ac_card_close(); ?>

    <?php ac_card_open('Admissions funnel', '', '<a class="ac-btn ac-btn--sm" href="' . ac_h(ac_url('admissions')) . '">Open the board</a>'); ?>
      <div class="ac-cardbody">
        <div class="ac-bars">
          <?php foreach ($d['funnel'] ?? [] as $s): ?>
            <?php ac_bar((string) $s['label'], (int) $s['pct'], (string) $s['n']); ?>
          <?php endforeach; ?>
        </div>
      </div>
    <?php
      $stages = $d['funnel'] ?? [];
      $worst = null; $prev = null;
      foreach ($stages as $s) {
          if ($prev !== null && ($worst === null || ($prev['n'] - $s['n']) > $worst['drop'])) {
              $worst = ['from' => $prev['label'], 'to' => $s['label'], 'drop' => $prev['n'] - $s['n']];
          }
          $prev = $s;
      }
      ac_card_close($worst && $worst['drop'] > 0
        ? 'The biggest drop is <strong>' . ac_h($worst['from']) . ' → ' . ac_h($worst['to'])
          . '</strong>, losing ' . (int) $worst['drop'] . '. Where that is a wait rather than a decision it is a '
          . 'staffing figure, not an applicant-quality figure.'
        : 'No stage is losing anybody yet.'); ?>
  </div>

  <div class="ac-stack">
    <?php ac_card_open("Today's operations", date('D j M')); ?>
      <div class="ac-cardbody">
        <div class="ac-defs">
          <?php
            $sessions = $d['today'] ?? [];
            $online = count(array_filter($sessions, fn($s) => ($s['mode'] ?? '') === 'online'));
            ac_def('Classes scheduled', count($sessions) . ' <span class="ac-subi">'
                . (count($sessions) - $online) . ' onsite · ' . $online . ' online</span>');
            $cap = $d['at_capacity'] ?? [];
            ac_def('Cohorts at capacity', $cap
                ? count($cap) . ' <span class="ac-subi">'
                  . ac_h((string) ($cap[0]['title'] ?? '')) . (count($cap) > 1 ? ' +' . (count($cap) - 1) : '')
                  . ' · ' . (int) ($cap[0]['waiting'] ?? 0) . ' waiting</span>'
                : 'None');
            ac_def('Make-up credits used this week', (string) (int) ($d['makeups'] ?? 0));
          ?>
        </div>
        <?php if ($sessions): ?>
          <div class="ac-tablewrap ac-mt">
            <table class="ac-table">
              <tbody>
                <?php foreach (array_slice($sessions, 0, 6) as $s): ?>
                  <tr>
                    <td class="ac-num ac-col-time"><?= ac_h(mam_console_date((string) $s['scheduled_at'], 'H:i')) ?></td>
                    <td>
                      <div class="ac-strong"><?= ac_h($s['title'] ?: ($s['cohort'] ?: 'Session')) ?></div>
                      <div class="ac-sub"><?= ac_h(trim((string) ($s['cohort'] ?? '') . ' · ' . (string) ($s['room'] ?: ($s['mode'] ?: '')), ' ·')) ?></div>
                    </td>
                    <td class="ac-num"><?= ac_status((string) $s['status']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    <?php ac_card_close('A class with no register cannot be reconciled against anything, so an unmarked one appears in the queue above rather than only here.'); ?>

    <?php ac_card_open('System health', '',
        ac_pill(!empty($h['nominal']) ? 'Nominal' : 'Needs a look', !empty($h['nominal']) ? 'good' : 'warn')); ?>
      <div class="ac-cardbody">
        <div class="ac-defs">
          <?php
            ac_def('Outbox', (int) ($h['outbox']['stuck'] ?? 0) . ' stuck · '
                . (int) ($h['outbox']['sent_24h'] ?? 0) . ' sent in 24h');
            ac_def('Webhooks', (int) ($h['webhooks']['unverified'] ?? 0) === 0
                ? 'All signatures verified'
                : (int) $h['webhooks']['unverified'] . ' unverified signature'
                  . ((int) $h['webhooks']['unverified'] === 1 ? '' : 's'));
            ac_def('FX rate', !empty($h['fx']['rate'])
                ? '1 USD = ₦' . number_format((float) $h['fx']['rate'], 2)
                : 'Not warmed');
            ac_def('Audit log', 'to entry ' . number_format((int) ($h['audit']['head'] ?? 0)));
          ?>
        </div>
      </div>
    <?php ac_card_close('<a href="' . ac_h(ac_url('system')) . '">Open system health</a> for the automations, the outbound events and the failed reads.'); ?>
  </div>
</div>
