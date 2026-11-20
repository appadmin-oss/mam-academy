<?php
/**
 * MAM Academy — admin console render helpers (partials/admin-console/_ui.php)
 *
 * Small, boring, and the only place markup is generated more than once. Every
 * class name here comes from `assets/css/admin-v2.css`; no helper emits a
 * `style=` attribute except where the value IS data (a meter's fill width).
 *
 * Naming: ac_* to match the stylesheet's prefix, so a grep for a class finds
 * both the rule and the emitter.
 */
declare(strict_types=1);

/** Escape. Every dynamic value that reaches markup goes through this. */
function ac_h($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

/** A console URL. Screens are keys from mam_console_screens(); params are extra. */
function ac_url(string $screen, array $params = []): string {
    $q = array_merge(['v' => $screen], $params);
    return '?' . http_build_query(array_filter($q, fn($v) => $v !== null && $v !== ''));
}

/**
 * Join the parts of a meta line ("piano · Level 2 · Tue 16:00 · Ann") with the
 * empty ones left out. Each part is TRIMMED before it is judged empty: the old
 * inline `array_filter()` kept a part built as `days . ' ' . time`, which is a
 * single space when both are missing, so the line read "conducting · · Ann" on
 * every cohort with no schedule. Returns plain text — escape it where it lands.
 */
function ac_join(array $parts, string $sep = ' · '): string {
    $keep = [];
    foreach ($parts as $p) {
        $p = trim((string) $p);
        if ($p !== '') $keep[] = $p;
    }
    return implode($sep, $keep);
}

/** A screen header: serif title, subtitle, and the actions on the right. */
function ac_head(string $title, string $sub, string $actions = ''): void { ?>
  <div class="ac-head">
    <div>
      <h2><?= ac_h($title) ?></h2>
      <?php if ($sub !== ''): ?><p><?= $sub /* trusted: caller-composed, values escaped */ ?></p><?php endif; ?>
    </div>
    <?php if ($actions !== ''): ?><div class="ac-headactions"><?= $actions ?></div><?php endif; ?>
  </div>
<?php }

/**
 * The KPI strip. Each tile may link to the screen that explains it — a number
 * with no way through to the rows behind it is a number nobody trusts.
 */
function ac_kpis(array $kpis): void { ?>
  <div class="ac-kpis ac-kpis--tiles">
    <?php foreach ($kpis as $k):
      $tone = (string) ($k['tone'] ?? '');
      $tag  = !empty($k['screen']) ? 'a' : 'div';
      $href = !empty($k['screen']) ? ' href="' . ac_h(ac_url((string) $k['screen'])) . '"' : ''; ?>
      <<?= $tag ?> class="ac-kpi"<?= $href ?>>
        <div class="ac-kpi-l"><?= ac_h($k['label']) ?></div>
        <div class="ac-kpi-v<?= $tone !== '' ? ' is-' . ac_h($tone) : '' ?>"><?= ac_h($k['value']) ?></div>
        <?php if (!empty($k['note'])): ?><div class="ac-kpi-n"><?= ac_h($k['note']) ?></div><?php endif; ?>
      </<?= $tag ?>>
    <?php endforeach; ?>
  </div>
<?php }

/** A status pill. Tone is one of '', good, bad, warn, ink, accent. */
function ac_pill(string $text, string $tone = ''): string {
    $c = 'ac-pill' . ($tone !== '' ? ' ac-pill--' . $tone : '');
    return '<span class="' . $c . '">' . ac_h($text) . '</span>';
}

/** A small uppercase tag, for a type/kind column. */
function ac_tag(string $text): string { return '<span class="ac-tag">' . ac_h($text) . '</span>'; }

/**
 * Map a status string to a tone. One place, so "overdue" is never red on one
 * screen and grey on the next.
 */
function ac_tone(string $status): string {
    $s = strtolower(trim($status));
    if (in_array($s, ['paid', 'success', 'active', 'accepted', 'enrolled', 'ratified', 'cleared',
        'delivered', 'collected', 'resolved', 'closed', 'sent', 'present', 'on', 'published', 'in_force'], true)) return 'good';
    if (in_array($s, ['overdue', 'failed', 'rejected', 'void', 'revoked', 'expired', 'absent',
        'not_cleared', 'suspended', 'dead', 'blocked'], true)) return 'bad';
    if (in_array($s, ['pending', 'part_paid', 'issued', 'waitlisted', 'disputed', 'waiver_requested',
        'draft', 'submitted', 'under_review', 'review', 'expiring', 'late', 'warn', 'unpaid'], true)) return 'warn';
    return '';
}

function ac_status(string $status, ?string $label = null): string {
    $text = $label ?? ucfirst(str_replace('_', ' ', $status));
    return ac_pill($text, ac_tone($status));
}

/** A card. Open/close so a screen can put anything between them. */
function ac_card_open(string $title = '', string $meta = '', string $right = ''): void { ?>
  <div class="ac-card">
    <?php if ($title !== '' || $meta !== '' || $right !== ''): ?>
      <div class="ac-cardhead">
        <?php if ($title !== ''): ?><span class="ac-cardtitle"><?= ac_h($title) ?></span><?php endif; ?>
        <?php if ($meta !== ''): ?><span class="ac-cardmeta"><?= ac_h($meta) ?></span><?php endif; ?>
        <span class="ac-spacer"></span>
        <?= $right ?>
      </div>
    <?php endif; ?>
<?php }

function ac_card_close(string $foot = ''): void { ?>
    <?php if ($foot !== ''): ?><div class="ac-cardfoot"><?= $foot ?></div><?php endif; ?>
  </div>
<?php }

/** A banner. Tone: warn | bad | good | flat. */
function ac_banner(string $tone, string $icon, string $html, string $actions = ''): void { ?>
  <div class="ac-banner ac-banner--<?= ac_h($tone) ?>">
    <i class="fa-solid <?= ac_h($icon) ?>"></i>
    <div><?= $html ?></div>
    <?php if ($actions !== ''): ?><span class="ac-spacer"></span><?= $actions ?><?php endif; ?>
  </div>
<?php }

/** A meter. The fill is live data, which is why it is the one inline value. */
function ac_meter(int $pct, string $tone = ''): string {
    $pct = max(0, min(100, $pct));
    $c = 'ac-meter' . ($tone !== '' ? ' ac-meter--' . $tone : '');
    return '<div class="' . $c . '"><span style="--ac-fill:' . $pct . '%"></span></div>';
}

/** A label · bar · figure row, as the funnels and fund meters use. */
function ac_bar(string $label, int $pct, string $figure, string $tone = 'accent'): void { ?>
  <div class="ac-bar"><span><?= ac_h($label) ?></span><?= ac_meter($pct, $tone) ?><span><?= ac_h($figure) ?></span></div>
<?php }

/** A definition row inside a side panel. */
function ac_def(string $label, string $valueHtml): void { ?>
  <div class="ac-def"><span><?= ac_h($label) ?></span><span><?= $valueHtml ?></span></div>
<?php }

/** An avatar, from a name (initials) or a stored photo. */
function ac_avatar(?string $name, ?string $photo = null): string {
    if ($photo !== null && $photo !== '' && preg_match('#^(https://|/api/media/)#', $photo)) {
        return '<img class="ac-avatar" src="' . ac_h($photo) . '" alt="" width="30" height="30">';
    }
    return '<span class="ac-avatar">' . ac_h(mam_console_initials($name)) . '</span>';
}

/** The empty state the design specifies: a sentence, not a shrug. */
function ac_empty(string $title, string $why, string $actions = ''): void { ?>
  <div class="ac-empty">
    <div class="ac-empty-t"><?= ac_h($title) ?></div>
    <div><?= ac_h($why) ?></div>
    <?php if ($actions !== ''): ?><div class="ac-row ac-center ac-mt"><?= $actions ?></div><?php endif; ?>
  </div>
<?php }

/**
 * Open a form that posts to the LEGACY admin dispatcher. That is deliberate:
 * `admin/index.php` owns the capability table, the audit call and the
 * transition rules for every action, so the console reuses them rather than
 * growing a second implementation that can disagree. `_console` tells it which
 * console screen to return to (see mam_admin_console_return()).
 */
function ac_form(string $action, string $screen, array $hidden = [], string $attrs = ''): void {
    global $MAM_CONSOLE_CSRF; ?>
  <form method="post" action="/admin/" <?= $attrs ?>>
    <input type="hidden" name="csrf_token" value="<?= ac_h($MAM_CONSOLE_CSRF) ?>">
    <input type="hidden" name="action" value="<?= ac_h($action) ?>">
    <input type="hidden" name="_console" value="<?= ac_h($screen) ?>">
    <?php foreach ($hidden as $k => $v): ?>
      <input type="hidden" name="<?= ac_h($k) ?>" value="<?= ac_h($v) ?>">
    <?php endforeach; ?>
<?php }

function ac_form_end(): void { echo "</form>\n"; }

/** A one-button form — the commonest action shape in a table row. */
function ac_action_btn(string $action, string $screen, array $hidden, string $label,
                       string $class = 'ac-btn ac-btn--sm', string $confirm = ''): void {
    $attrs = 'class="ac-row"' . ($confirm !== '' ? ' onsubmit="return confirm(' . ac_h(json_encode($confirm)) . ')"' : '');
    ac_form($action, $screen, $hidden, $attrs);
    echo '<button type="submit" class="' . ac_h($class) . '">' . ac_h($label) . '</button>';
    ac_form_end();
}

/** Money, both ways: the accounting figure and the reader's figure. */
function ac_money(int $usdCents, ?float $rate = null): string {
    return '<span class="ac-num">' . ac_h(mam_console_usd($usdCents)) . '</span>';
}
function ac_money_dual(int $usdCents, ?float $rate = null): string {
    return ac_h(mam_console_usd($usdCents)) . ' <span class="ac-subi">'
        . ac_h(mam_console_ngn($usdCents, $rate)) . '</span>';
}

/** A "nothing here" table body with the right column span. */
function ac_table_empty(int $cols, string $text): void { ?>
  <tr><td colspan="<?= (int) $cols ?>"><div class="ac-empty"><?= ac_h($text) ?></div></td></tr>
<?php }

/**
 * Stamp `data-label` onto every body cell of every `.ac-table`, taking the
 * label from the column's own header.
 *
 * ── WHY THIS IS DERIVED AND NOT WRITTEN INTO THE PARTIALS ─────────────────
 * The phone layout turns each row into a card, and a cell in a card has to
 * carry its own column name or it is a value with nothing to say what it is.
 * The legacy dashboard does this with a hand-written `data-label` on each
 * `<td>` (admin/index.php, `table.stack`). Copying that here would mean 229
 * attributes across 32 partials — and it would be WRONG, not merely tedious:
 * `vetting.php` emits one column per required clearance type in a `foreach`,
 * so its headers are not known until the row is rendered. A hand-written
 * label cannot follow a column that does not exist until runtime.
 *
 * Deriving it from the `<thead>` also means the label cannot drift from the
 * header, which is the failure the legacy pattern invites: rename a column and
 * the desktop says one thing while the phone says another.
 *
 * ⚠️ A header cell may span columns, so the map is built by ADVANCING a column
 * index by each `colspan`, never by array position. Same on the way out — a
 * cell spanning the table (the empty state that `ac_table_empty()` writes) is
 * skipped rather than labelled with the first column's name.
 *
 * Lossless by construction and by test: `test_console.php` strips the
 * attribute back off every screen's output and asserts the result is byte
 * for byte what went in, so this can never quietly rewrite a screen.
 */
function ac_label_table_cells(string $html): string {
    // Nothing to do, and no reason to pay for a DOM parse.
    if (stripos($html, 'ac-table') === false) return $html;

    $prev = libxml_use_internal_errors(true);
    $doc  = new DOMDocument();
    /* The fragment is a fragment: no <html>, no doctype, and the encoding
       stated up front because DOMDocument otherwise reads it as Latin-1 and
       turns every naira sign into mojibake. */
    $ok = $doc->loadHTML(
        '<?xml encoding="UTF-8" ?><div data-ac-root="1">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) return $html;               // Unparseable: ship the screen as it is.

    $root = $doc->getElementsByTagName('div')->item(0);
    if (!$root || !$root->hasAttribute('data-ac-root')) return $html;

    foreach (iterator_to_array($doc->getElementsByTagName('table')) as $table) {
        if (strpos(' ' . $table->getAttribute('class') . ' ', ' ac-table ') === false) continue;

        /* ⚠️ EXPLICIT ROLES, and they are not decoration. The phone layout sets
           `display:block` on the table, its rows and its cells to stack them as
           cards — and changing `display` on a table element DROPS that element's
           implicit ARIA role. A stacked table without these reads to a screen
           reader as an unstructured run of text, which is precisely the
           regression the legacy `table.stack` pattern shipped. Stating each role
           restores the structure the layout took away.

           They are set unconditionally rather than at the breakpoint because a
           role cannot be applied by a media query. On a desktop each one names
           exactly the implicit role it replaces, so it changes nothing there. */
        $table->setAttribute('role', 'table');
        foreach ($table->getElementsByTagName('thead') as $g) $g->setAttribute('role', 'rowgroup');
        foreach ($table->getElementsByTagName('tbody') as $g) $g->setAttribute('role', 'rowgroup');
        foreach ($table->getElementsByTagName('tr')    as $r) $r->setAttribute('role', 'row');
        foreach ($table->getElementsByTagName('th')    as $c) {
            $c->setAttribute('role', $c->getAttribute('scope') === 'row' ? 'rowheader' : 'columnheader');
        }
        foreach ($table->getElementsByTagName('td')    as $c) $c->setAttribute('role', 'cell');

        // Column index → header text, advancing by colspan.
        $labels = [];
        $head   = null;
        foreach ($table->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'thead') { $head = $child; break; }
        }
        if (!$head) continue;
        $headRow = null;
        foreach ($head->getElementsByTagName('tr') as $tr) { $headRow = $tr; break; }
        if (!$headRow) continue;
        $col = 0;
        foreach ($headRow->childNodes as $th) {
            if (!($th instanceof DOMElement) || strtolower($th->nodeName) !== 'th') continue;
            $span = max(1, (int) ($th->getAttribute('colspan') ?: 1));
            $text = trim(preg_replace('/\s+/u', ' ', (string) $th->textContent));
            for ($i = 0; $i < $span; $i++) $labels[$col + $i] = $i === 0 ? $text : '';
            $col += $span;
        }
        $width = $col;
        if (!$width) continue;

        foreach ($table->getElementsByTagName('tbody') as $body) {
            foreach ($body->getElementsByTagName('tr') as $tr) {
                $col = 0;
                foreach ($tr->childNodes as $td) {
                    if (!($td instanceof DOMElement) || strtolower($td->nodeName) !== 'td') continue;
                    $span = max(1, (int) ($td->getAttribute('colspan') ?: 1));
                    /* A cell spanning the whole table is a message, not a
                       value — the empty state, a full-width note. Labelling it
                       with the first column's name is worse than not labelling
                       it, so it is left alone. */
                    if ($span < $width && !$td->hasAttribute('data-label')) {
                        $td->setAttribute('data-label', $labels[$col] ?? '');
                    }
                    $col += $span;
                }
            }
        }
    }

    $out = '';
    foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
    return $out;
}
