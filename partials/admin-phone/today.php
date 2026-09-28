<?php
/**
 * Phone panel 2 — Today.
 *
 * What is happening in the building, which room is double-booked, and which
 * registers are not marked. All three read real tables: `class_sessions` has a
 * `room_id` and `rooms` exists, so the clash is detected rather than mocked.
 *
 * The day is the ACADEMY's day (Africa/Lagos), never the server's — a console
 * open in London must not show tomorrow's sessions to somebody standing in the
 * building.
 */
declare(strict_types=1);
$d = mam_phone_today();
?>
<h1 class="ap-h1">Today</h1>
<p class="ap-lede"><?= ac_h(mam_console_date($d['date'], 'l j F')) ?></p>

<div class="ap-stack">
  <?php foreach ($d['clashes'] as $c): ?>
    <section class="ap-card is-bad">
      <div class="ap-eyebrow is-bad">Room clash</div>
      <h2 class="ap-card-t"><?= ac_h($c['room']) ?> · <?= ac_h($c['times']) ?></h2>
      <p class="ap-card-b">
        <?= ac_h(trim((string) ($c['a']['title'] ?? 'A session'))) ?> and
        <?= ac_h(trim((string) ($c['b']['title'] ?? 'another session'))) ?> are both booked in it.
      </p>
      <?php /* Moving a session is a timetable edit with consequences for
               everyone enrolled, so the phone names the clash and opens the
               timetable rather than resolving it one-handed. */ ?>
      <div class="ap-acts">
        <a class="ap-btn" href="/admin/console.php?v=timetable">Open the timetable</a>
      </div>
    </section>
  <?php endforeach; ?>

  <section class="ap-card">
    <div class="ap-eyebrow"><?= count($d['sessions']) ?> session<?= count($d['sessions']) === 1 ? '' : 's' ?></div>
    <?php if (!$d['sessions']): ?>
      <p class="ap-card-b">Nothing is scheduled today.</p>
    <?php else: ?>
      <ul class="ap-list">
        <?php foreach ($d['sessions'] as $s): ?>
          <li class="ap-line">
            <span class="ap-time"><?= ac_h(mam_phone_time((string) $s['scheduled_at'])) ?></span>
            <span class="ap-line-main">
              <span class="ap-line-t"><?= ac_h(trim((string) ($s['title'] ?? 'Session'))) ?></span>
              <span class="ap-line-s">
                <?= ac_h(trim((string) ($s['cohort'] ?? '')) ?: 'No cohort') ?>
                <?php if (!empty($s['instructor'])): ?> · <?= ac_h((string) $s['instructor']) ?><?php endif; ?>
                <?php if (!empty($s['room_name'])): ?> · <?= ac_h((string) $s['room_name']) ?><?php endif; ?>
              </span>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="ap-card">
    <h2 class="ap-card-t">Registers</h2>
    <div class="ap-figs">
      <div class="ap-fig">
        <span class="ap-fig-n"><?= (int) $d['marked'] ?></span>
        <span class="ap-fig-l">marked</span>
      </div>
      <div class="ap-fig<?= $d['unmarked'] > 0 ? ' is-bad' : '' ?>">
        <span class="ap-fig-n"><?= (int) $d['unmarked'] ?></span>
        <span class="ap-fig-l">unmarked</span>
      </div>
    </div>
    <?php if ($d['unmarked'] > 0): ?>
      <?php /* Nudging is a message to an instructor, which needs a messaging
               path this repo does not have configured. Rather than a dead
               button, the panel says who to chase and where. */ ?>
      <p class="ap-quiet">An unmarked register is the instructor's to complete. The attendance screen names which.</p>
      <div class="ap-acts">
        <a class="ap-btn" href="/admin/console.php?v=attendance">Open attendance</a>
      </div>
    <?php endif; ?>
  </section>
</div>
