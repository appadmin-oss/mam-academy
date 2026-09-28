<?php
/**
 * Phone panel 4 — Look up.
 *
 * The facts a registrar is asked for while standing up, and only the actions
 * that can be undone. This is where the frame's refusal is stated on the page,
 * because this is the panel where somebody will look for the missing button:
 *
 *   "Amending a record, voiding an invoice and closing a period are
 *    deliberately unavailable here."
 *
 * The frame also offers "Message the guardian" over WhatsApp. There is no
 * messaging provider configured in this repo, and shipping a dead button for
 * one is refused elsewhere in the codebase for the same reason, so the
 * guardian's address is shown instead — it is real, and it is actionable on a
 * phone without pretending.
 */
declare(strict_types=1);
$r = mam_phone_lookup($_GET['q'] ?? '');
?>
<h1 class="ap-h1">Look up</h1>

<form class="ap-search" method="get" action="">
  <input type="hidden" name="p" value="lookup">
  <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
  <input class="ap-search-in" type="search" name="q" value="<?= ac_h((string) $r['query']) ?>"
         placeholder="Name or email" aria-label="Search for a person" enterkeyhint="search">
  <button class="ap-search-go" type="submit">Find</button>
</form>

<?php if ($r['query'] === ''): ?>
  <section class="ap-card ap-empty">
    <div class="ap-card-t">Search for anyone</div>
    <p class="ap-card-b">A learner, a guardian or an instructor. Their stage, enrolment, attendance and balance in one card.</p>
  </section>
<?php elseif (!$r['person']): ?>
  <section class="ap-card ap-empty">
    <div class="ap-card-t">Nobody matched “<?= ac_h((string) $r['query']) ?>”</div>
    <p class="ap-card-b">Try a surname, or the email on the account.</p>
  </section>
<?php else:
  $p = $r['person'];
  $att = $r['attendance']; ?>
  <section class="ap-card">
    <div class="ap-who">
      <?= ac_avatar((string) ($p['name'] ?? '')) ?>
      <div class="ap-who-main">
        <div class="ap-who-n"><?= ac_h((string) ($p['name'] ?? 'Unnamed')) ?></div>
        <div class="ap-who-s"><?= ac_h((string) ($p['email'] ?? '')) ?></div>
      </div>
    </div>

    <dl class="ap-facts">
      <div class="ap-fact"><dt>Role</dt><dd><?= ac_h(ucfirst((string) ($p['role'] ?? 'unknown'))) ?> · <?= ac_h((string) ($p['status'] ?? '')) ?></dd></div>

      <div class="ap-fact"><dt>Enrolment</dt><dd>
        <?php if (!$r['enrolments']): ?><span class="ap-dim">None on record</span>
        <?php else: foreach ($r['enrolments'] as $e): ?>
          <div><?= ac_h(trim((string) ($e['course'] ?? 'Course'))) ?>
            <?php if (!empty($e['cohort'])): ?> · <?= ac_h((string) $e['cohort']) ?><?php endif; ?>
            · <?= ac_h((string) ($e['status'] ?? '')) ?></div>
        <?php endforeach; endif; ?>
      </dd></div>

      <?php /* An em dash means NOT MEASURED, never zero — the line the whole
               console holds. A learner with no sessions yet has no attendance
               rate, and 0% would report them as never turning up. */ ?>
      <div class="ap-fact"><dt>Attendance</dt><dd>
        <?php if ($att['pct'] === null): ?><span class="ap-dim">— no sessions yet</span>
        <?php else: ?><?= (int) $att['pct'] ?>% · <?= (int) $att['total'] ?> session<?= $att['total'] === 1 ? '' : 's' ?><?php endif; ?>
      </dd></div>

      <div class="ap-fact"><dt>Owed</dt><dd>
        <?php if ((int) $r['owed_cents'] > 0): ?>
          <span class="ap-owed"><?= ac_h(mam_console_usd((int) $r['owed_cents'])) ?></span>
        <?php else: ?><span class="ap-dim">Nothing outstanding</span><?php endif; ?>
      </dd></div>

      <div class="ap-fact"><dt>Guardian</dt><dd>
        <?php if (!$r['guardians']): ?><span class="ap-dim">None linked</span>
        <?php else: foreach ($r['guardians'] as $g): ?>
          <div><?= ac_h((string) $g['name']) ?> · <?= ac_h((string) ($g['access_scope'] ?? 'standard')) ?> access</div>
          <div><a class="ap-inline" href="mailto:<?= ac_h((string) $g['email']) ?>"><?= ac_h((string) $g['email']) ?></a></div>
        <?php endforeach; endif; ?>
      </dd></div>
    </dl>
  </section>

  <?php if ($r['matches']): ?>
    <p class="ap-quiet">
      Also matched:
      <?php foreach ($r['matches'] as $m): ?>
        <a class="ap-inline" href="?p=lookup&amp;q=<?= urlencode((string) ($m['email'] ?? $m['name'])) ?>"><?= ac_h((string) $m['name']) ?></a>
      <?php endforeach; ?>
    </p>
  <?php endif; ?>

  <section class="ap-card">
    <div class="ap-eyebrow">On the desk</div>
    <ul class="ap-links">
      <li><a href="/admin/console.php?v=students">The full record</a></li>
      <li><a href="/admin/console.php?v=invoices">Invoices and payments</a></li>
      <li><a href="/admin/console.php?v=guardians">Guardian access</a></li>
    </ul>
  </section>

  <p class="ap-notice"><?= ac_h(MAM_PHONE_DESK_NOTICE) ?></p>
<?php endif; ?>
