<?php
/**
 * v2 clarity kit — the accessibility claims are computed, not asserted.
 *
 * A comment saying "AA compliant" is worth nothing; the first draft of this
 * palette carried exactly that comment and its tone borders measured 1.6–2.4:1.
 * This parses the real CSS and does the WCAG arithmetic on every token pair.
 *
 * Both themes are checked. A dark palette that was eyeballed against a light
 * mock is the usual way half a design system fails silently — nobody looks at
 * the other theme until a user reports it.
 */
declare(strict_types=1);

$css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/v2-clarity.css');

/** Relative luminance, WCAG 2.x definition. */
function mam_t_lum(string $hex): float
{
    $h = ltrim($hex, '#');
    $c = [];
    foreach ([0, 2, 4] as $i) {
        $v = hexdec(substr($h, $i, 2)) / 255;
        $c[] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}

function mam_t_ratio(string $a, string $b): float
{
    $la = mam_t_lum($a); $lb = mam_t_lum($b);
    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/** Pull a token's value out of one theme block. */
function mam_t_token(string $block, string $name): ?string
{
    return preg_match('/--' . preg_quote($name, '/') . ':\s*(#[0-9A-Fa-f]{6})/', $block, $m)
        ? strtoupper($m[1]) : null;
}

t_group('v2 clarity kit — contrast, computed from the real CSS');

// Split the file into the two themes. The dark block is taken from the
// [data-mam-theme="dark"] selector rather than the media query, because that is
// the one an explicit toggle uses — and it must not have been left behind.
$darkAt = strpos($css, ':root[data-mam-theme="dark"]');
t_ok($darkAt !== false, 'an explicit dark theme block exists (not only a media query)');
$themes = [
    'light' => ['css' => substr($css, 0, (int) strpos($css, '@media (prefers-color-scheme: dark)')), 'surface' => '#FFFFFF'],
    'dark'  => ['css' => substr($css, (int) $darkAt), 'surface' => '#161C24'],
];

$tones = ['neutral', 'positive', 'progress', 'attention', 'warning', 'critical'];

foreach ($themes as $theme => $t) {
    $surface = mam_t_token($t['css'], 'mam-surface') ?? $t['surface'];

    foreach ($tones as $tone) {
        $fg = mam_t_token($t['css'], "mam-tone-$tone-fg");
        $bg = mam_t_token($t['css'], "mam-tone-$tone-bg");
        $bd = mam_t_token($t['css'], "mam-tone-$tone-bd");
        t_ok($fg && $bg && $bd, "$theme/$tone defines all three tokens");
        if (!($fg && $bg && $bd)) continue;

        // 1.4.3 — chip text on chip fill. Body-sized, so the 4.5:1 threshold.
        $r = mam_t_ratio($fg, $bg);
        t_ok($r >= 4.5, sprintf('%s/%s: chip text on its fill is %.2f:1 (needs 4.5)', $theme, $tone, $r));

        // 1.4.11 — the tone border is NOT decoration. It is how the to-do list
        // shows "blocking" and "overdue", so it must clear 3:1 against the page
        // it sits on. This is the check the first draft failed.
        $rb = mam_t_ratio($bd, $surface);
        t_ok($rb >= 3.0, sprintf('%s/%s: tone border on the surface is %.2f:1 (needs 3.0)', $theme, $tone, $rb));
    }

    // Body text, including the quiet variant — "muted" is where contrast is
    // usually lost, because it looks deliberate.
    foreach (['mam-ink' => 4.5, 'mam-ink-quiet' => 4.5] as $tok => $need) {
        $v = mam_t_token($t['css'], $tok);
        t_ok($v !== null, "$theme defines --$tok");
        if ($v === null) continue;
        $r = mam_t_ratio($v, $surface);
        t_ok($r >= $need, sprintf('%s/%s on the surface is %.2f:1 (needs %.1f)', $theme, $tok, $r, $need));
    }

    // The primary button paints white on --mam-focus; that pair is real text.
    $focus = mam_t_token($t['css'], 'mam-focus');
    if ($focus) {
        // The button's text colour is a token, not a constant: in dark mode
        // --mam-focus is a light blue, and a hardcoded white sat at 2.19:1.
        $onFocus = mam_t_token($t['css'], 'mam-on-focus');
        t_ok($onFocus !== null, "$theme defines --mam-on-focus (the button's text must flip with the theme)");
        if ($onFocus !== null) {
            t_ok(mam_t_ratio($onFocus, $focus) >= 4.5,
                sprintf('%s: the primary button\'s own text colour is %.2f:1 on it', $theme, mam_t_ratio($onFocus, $focus)));
        }
        // The focus ring must also be visible against the page, or "focus is
        // visible" is true only on paper.
        t_ok(mam_t_ratio($focus, $surface) >= 3.0,
            sprintf('%s: the focus ring is %.2f:1 against the surface (needs 3.0)', $theme, mam_t_ratio($focus, $surface)));
    }
}

t_group('v2 clarity kit — the other non-negotiables');

// 2.5.8 target size. The chip is not interactive but is often placed inline
// with controls; the CTA is a real touch target and gets the 44px recommendation.
t_ok((bool) preg_match('/\.mam-chip\s*\{[^}]*min-height:\s*24px/s', $css),
    'a status chip is at least 24px tall (WCAG 2.2 target minimum)');
t_ok((bool) preg_match('/\.mam-todo__cta\s*\{[^}]*min-height:\s*44px/s', $css),
    'the to-do action is a 44px touch target, not the bare minimum');
t_ok((bool) preg_match('/\.mam-todo__cta\s*\{[^}]*min-width:\s*44px/s', $css),
    '…in both dimensions, so a short label does not shrink it');

t_ok(strpos($css, ':focus-visible') !== false, 'focus is styled with :focus-visible, not removed');
t_ok(strpos($css, 'outline: 3px solid') !== false, '…as a 3px outline that survives a busy background');
t_ok(strpos($css, 'outline-offset') !== false, '…offset so it is not swallowed by the component border');
t_ok(!preg_match('/outline:\s*(none|0)\b/', $css), 'nothing anywhere sets outline: none');

t_ok(strpos($css, '@media (prefers-reduced-motion: reduce)') !== false, 'the OS reduced-motion setting is honoured');
t_ok(strpos($css, 'html[data-mam-motion="reduce"]') !== false,
    '…and so is the STORED preference, for someone on a device they cannot configure');

// A fixed px font-size on a component is how 200% zoom breaks: it ignores the
// user's browser setting entirely. Sizes must be relative.
t_ok(!preg_match('/font-size:\s*\d+px/', $css), 'no component pins a font size in px (200% zoom must work)');
t_ok(strpos($css, 'white-space: normal') !== false,
    'chip labels wrap rather than clip — a translated label runs ~30% longer');
t_ok((bool) preg_match('/max-width:\s*6\dch/', $css), 'body copy is capped at a readable measure');

// The empty state must be visually distinct from the error tone, or "you have
// nothing to do" reads as "something went wrong".
t_ok(strpos($css, '.mam-todo__empty') !== false, 'the empty state has its own rule');
t_ok(!preg_match('/\.mam-todo__empty\s*\{[^}]*critical/s', $css), '…and does not borrow the error colour');

t_group('v2 clarity kit — the JS half is wired into CI');

$ci = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');
t_ok(strpos($ci, 'v2-clarity.selftest.cjs') !== false,
    'v2-clarity.selftest.cjs is RUN by CI (a self-test CI only parses is not a test)');
t_ok(is_file(dirname(__DIR__, 2) . '/assets/js/v2-clarity.selftest.cjs'), 'and the file it names exists');
