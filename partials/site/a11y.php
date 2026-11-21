<?php
/**
 * The accessibility panel, the cookie notice and their launcher — one set for every
 * page (the redesigned ones and those still on the previous design), drawn in the
 * 2026-11 design's language. assets/js/a11y.js runs the panel; site.js the notice.
 *
 * The panel replaces the old four-button toolbar (contrast, bigger, smaller, print).
 * Every choice is kept on this device only ("mam.a11y") and applied before the page
 * paints (site_a11y_boot), so a reader who needs large text never sees small text first.
 *
 * $launcher: false on a page that offers its own way in (Apply's top bar).
 */
declare(strict_types=1);
// $launcher comes from site_a11y_panel().
$sw = static function (string $key, string $label, string $hint): string {
    return '<button type="button" class="a11y-opt" data-a11y="' . $key . '" aria-pressed="false">'
        . '<span class="a11y-opt-t">' . $label . '<span class="a11y-opt-h">' . $hint . '</span></span>'
        . '<span class="a11y-sw" aria-hidden="true"></span></button>';
};
?>
<?php if ($launcher): ?>
<button type="button" class="a11y-launch a11y-ui btn" data-a11y-open aria-haspopup="dialog" aria-controls="a11y-panel" title="Accessibility settings (Alt+Shift+A)">
  <svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="4.5" r="1.8"></circle><path d="M4 8.5l8 1.5 8-1.5M12 10v5M12 15l-3.5 6M12 15l3.5 6"></path></svg>
  <span class="sr-xs">Accessibility settings</span>
</button>
<?php endif; ?>
<div class="a11y-scrim a11y-ui" data-a11y-close hidden></div>
<section id="a11y-panel" class="a11y-panel a11y-ui" role="dialog" aria-modal="true" aria-labelledby="a11y-h" hidden>
  <div class="a11y-head">
    <h2 id="a11y-h">Accessibility</h2>
    <button type="button" class="a11y-x btn" data-a11y-close aria-label="Close accessibility settings"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"></path></svg></button>
  </div>
  <p class="a11y-lede">Your choices are kept on this device and apply to every page of the site.</p>

  <fieldset class="a11y-group"><legend>Text size</legend>
    <div class="a11y-seg" role="radiogroup" aria-label="Text size">
      <?php foreach (['100%', '112%', '125%', '150%', '175%'] as $i => $t): ?>
        <button type="button" role="radio" aria-checked="<?= $i === 0 ? 'true' : 'false' ?>" data-a11y-size="<?= $i ?>"><?= $t ?></button>
      <?php endforeach; ?>
    </div>
    <p class="a11y-cap" data-a11y-cap hidden></p>
  </fieldset>

  <?php foreach (site_a11y_options() as $group => $opts): ?>
  <fieldset class="a11y-group"><legend><?= site_h($group) ?></legend>
    <?php foreach ($opts as $key => [$label, $hint]) echo $sw($key, site_h($label), site_h($hint)); ?>
  </fieldset>
  <?php endforeach; ?>

  <fieldset class="a11y-group"><legend>Listen</legend>
    <div class="a11y-read">
      <button type="button" class="btn a11y-act" data-a11y-read>Read this page aloud</button>
      <div class="a11y-seg a11y-seg--sm" role="radiogroup" aria-label="Reading speed">
        <button type="button" role="radio" aria-checked="false" data-a11y-rate="0.8">Slower</button>
        <button type="button" role="radio" aria-checked="true" data-a11y-rate="1">Normal</button>
        <button type="button" role="radio" aria-checked="false" data-a11y-rate="1.25">Faster</button>
      </div>
    </div>
    <p class="a11y-note" data-a11y-read-note>Select some text first to hear only that. Your device’s own voice is used; nothing is sent anywhere.</p>
  </fieldset>

  <div class="a11y-foot">
    <button type="button" class="btn a11y-reset" data-a11y-reset>Reset all</button>
    <a href="/accessibility" class="ul">Accessibility statement</a>
  </div>
  <p role="status" aria-live="polite" class="sr-xs" data-a11y-status></p>
</section>
<div class="a11y-guide a11y-ui" aria-hidden="true" hidden></div>

<aside class="ck a11y-ui" data-cookie aria-label="Cookie notice" hidden>
  <p>Essential cookies only — they remember your draft and your preferences. <a href="/privacy">Details</a>.</p>
  <div class="ck-act">
    <button type="button" class="btn ck-no" data-cookie-choice="decline">Decline</button>
    <button type="button" class="btn ck-ok" data-cookie-choice="accept">OK</button>
  </div>
</aside>
