<?php
/**
 * Phone panel 1 — Decide.
 *
 * The frame leads with this and says why: "Four items. Everything else waits
 * for a desk." The panel is a queue of things that BLOCK something, and every
 * card either settles a reversible decision or hands the item to the desk with
 * the reason named. An item that needs the full ledger in front of you is
 * surfaced here and settled there; it is never half-settled on a phone.
 *
 * $AP_decide is read in the shell, not here, because the tab bar dots the same
 * count — two reads could disagree about how much is waiting.
 */
declare(strict_types=1);
/** @var array $AP_decide */
?>
<h1 class="ap-h1">Needs a decision</h1>
<?php if (!$AP_decide): ?>
  <p class="ap-lede">Nothing is waiting on you. Everything else waits for a desk.</p>
  <section class="ap-card ap-empty">
    <div class="ap-card-t">The queue is clear</div>
    <p class="ap-card-b">Waiver requests, lapsing clearances and unmatched payments appear here as they arrive.</p>
  </section>
<?php else: ?>
  <p class="ap-lede">
    <?= count($AP_decide) ?> item<?= count($AP_decide) === 1 ? '' : 's' ?>.
    Everything else waits for a desk.
  </p>
  <div class="ap-stack">
    <?php foreach ($AP_decide as $i => $item):
      $urgent = ($item['urgency'] ?? '') === 'bad'; ?>
      <section class="ap-card<?= $urgent ? ' is-bad' : '' ?>">
        <div class="ap-eyebrow<?= $urgent ? ' is-bad' : ' is-warn' ?>"><?= ac_h((string) $item['eyebrow']) ?></div>
        <h2 class="ap-card-t"><?= ac_h((string) $item['title']) ?></h2>
        <p class="ap-card-b"><?= ac_h((string) $item['body']) ?></p>
        <?php if (!empty($item['note'])): ?>
          <p class="ap-quiet"><?= ac_h((string) $item['note']) ?></p>
        <?php endif; ?>

        <?php
          /* A decision that needs a note gets ONE form wrapping both buttons,
             so the note the registrar types is carried by whichever they press.
             Two forms would mean typing the reason twice to change your mind. */
          $posts = array_values(array_filter($item['actions'] ?? [], static fn($a) => isset($a['action'])));
          $links = array_values(array_filter($item['actions'] ?? [], static fn($a) => isset($a['href'])));
          $needsNote = (bool) array_filter($posts, static fn($a) => !empty($a['note_field']));
        ?>

        <?php if ($posts): ?>
          <?php
            $shared = [];
            foreach (($posts[0]['fields'] ?? []) as $k => $v) if ($k !== 'decision') $shared[$k] = $v;
            ac_form((string) $posts[0]['action'], 'phone:decide', $shared, 'class="ap-form"');
          ?>
            <?php if ($needsNote): ?>
              <label class="ap-label" for="note-<?= (int) $i ?>">A note the family can read</label>
              <textarea class="ap-input" id="note-<?= (int) $i ?>" name="note" rows="2"
                        maxlength="255" placeholder="Why this was decided this way"></textarea>
            <?php endif; ?>
            <div class="ap-acts">
              <?php foreach ($posts as $a): ?>
                <button type="submit"
                        <?php if (isset($a['fields']['decision'])): ?>name="decision" value="<?= ac_h((string) $a['fields']['decision']) ?>"<?php endif; ?>
                        class="ap-btn<?= !empty($a['primary']) ? ' ap-btn--primary' : '' ?>">
                  <?= ac_h((string) $a['label']) ?>
                </button>
              <?php endforeach; ?>
            </div>
          <?php ac_form_end(); ?>
        <?php endif; ?>

        <?php if ($links): ?>
          <div class="ap-acts">
            <?php foreach ($links as $a): ?>
              <a class="ap-btn<?= !empty($a['primary']) ? ' ap-btn--primary' : '' ?>"
                 href="<?= ac_h((string) $a['href']) ?>"><?= ac_h((string) $a['label']) ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($item['desk'])): ?>
          <p class="ap-quiet">Settling this needs the full record beside you — it is flagged for the desk.</p>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
