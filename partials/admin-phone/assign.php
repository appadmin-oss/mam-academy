<?php
/**
 * Phone panel 3 — Assign. Pick a learner, pick a class, done.
 *
 * ⚠️ The frame's rule, and the reason this panel is worth having at all:
 * "Only classes she actually passes are tappable. A full cohort offers the
 * waitlist instead, and anything she fails is listed with the reason rather
 * than hidden." A list that silently omits the ineligible teaches a registrar
 * that the class does not exist, and they go looking for it on a desktop.
 *
 * The enrol itself posts to `student_course_assign` in admin/index.php — the
 * same handler the desktop console uses, so capacity, the level-aware
 * duplicate guard and the audit row are all the existing ones.
 */
declare(strict_types=1);
$a = mam_phone_assign($_GET['q'] ?? '');
?>
<h1 class="ap-h1">Assign</h1>
<p class="ap-lede">Find a learner, then a class they can actually join.</p>

<form class="ap-search" method="get" action="">
  <input type="hidden" name="p" value="assign">
  <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
  <input class="ap-search-in" type="search" name="q" value="<?= ac_h((string) $a['query']) ?>"
         placeholder="Name or email" aria-label="Search for a learner" enterkeyhint="search">
  <button class="ap-search-go" type="submit">Find</button>
</form>

<?php if ($a['query'] === ''): ?>
  <section class="ap-card ap-empty">
    <div class="ap-card-t">Search for a learner</div>
    <p class="ap-card-b">Their eligibility, the classes with a free seat, and anything blocking them will show here.</p>
  </section>
<?php elseif (!$a['learner']): ?>
  <section class="ap-card ap-empty">
    <div class="ap-card-t">Nobody matched “<?= ac_h((string) $a['query']) ?>”</div>
    <p class="ap-card-b">Try a surname, or the email the family applied with.</p>
  </section>
<?php else:
  $L = $a['learner']; ?>
  <section class="ap-card is-picked">
    <div class="ap-who">
      <?= ac_avatar((string) ($L['name'] ?? '')) ?>
      <div class="ap-who-main">
        <div class="ap-who-n"><?= ac_h((string) ($L['name'] ?? 'Unnamed')) ?></div>
        <div class="ap-who-s"><?= ac_h((string) ($L['email'] ?? '')) ?></div>
      </div>
    </div>
  </section>

  <?php if ($a['matches']): ?>
    <p class="ap-quiet">
      Also matched:
      <?php foreach ($a['matches'] as $m): ?>
        <a class="ap-inline" href="?p=assign&amp;q=<?= urlencode((string) ($m['email'] ?? $m['name'])) ?>"><?= ac_h((string) $m['name']) ?></a>
      <?php endforeach; ?>
    </p>
  <?php endif; ?>

  <div class="ap-eyebrow">Classes</div>
  <?php if (!$a['options']): ?>
    <section class="ap-card ap-empty">
      <div class="ap-card-t">No active cohorts</div>
      <p class="ap-card-b">A class has to exist and be active before anyone can be put in it.</p>
    </section>
  <?php else: ?>
    <div class="ap-stack">
      <?php foreach ($a['options'] as $o): ?>
        <?php if ($o['joinable']): ?>
          <?php ac_form('student_course_assign', 'phone:assign',
                ['student_id' => (int) $L['id'], 'cohort_id' => $o['cohort_id']], 'class="ap-opt-form"'); ?>
            <button class="ap-opt" type="submit">
              <span class="ap-opt-main">
                <span class="ap-opt-t"><?= ac_h($o['title']) ?></span>
                <span class="ap-opt-s"><?= ac_h($o['sub']) ?></span>
              </span>
              <span class="ap-opt-cap">
                <span class="ap-opt-n"><?= (int) $o['taken'] ?><?= $o['capacity'] !== null ? ' / ' . (int) $o['capacity'] : '' ?></span>
                <span class="ap-opt-l"><?= $o['capacity'] === null ? 'uncapped' : max(0, (int) $o['capacity'] - (int) $o['taken']) . ' seats' ?></span>
              </span>
            </button>
          <?php ac_form_end(); ?>
        <?php else: ?>
          <?php /* Listed, never hidden, with the reason on its face. A full
                   cohort offers the queue instead of nothing at all. */ ?>
          <div class="ap-opt is-off">
            <span class="ap-opt-main">
              <span class="ap-opt-t"><?= ac_h($o['title']) ?></span>
              <span class="ap-opt-s is-bad">
                <?= ac_h($o['blocked'] ?? 'Full' . ($o['capacity'] !== null ? ' · ' . (int) $o['taken'] . ' of ' . (int) $o['capacity'] : '')) ?>
              </span>
            </span>
            <?php if ($o['full'] && $o['blocked'] === null): ?>
              <?php ac_form('waitlist_promote', 'phone:assign',
                    ['student_id' => (int) $L['id'], 'cohort_id' => $o['cohort_id']], 'class="ap-opt-form is-inline"'); ?>
                <button class="ap-btn ap-btn--sm" type="submit">Waitlist</button>
              <?php ac_form_end(); ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
