<?php
/**
 * The public site's design system, pinned where it can actually drift.
 *
 * These are not style preferences. Each one was MEASURED in a browser during
 * the 2026-09-29 design pass, each was a real defect, and each is the kind
 * that no PHP test would otherwise catch because nothing in the suite loads
 * a stylesheet. A CSS regression ships silently: the page still renders.
 */
declare(strict_types=1);

$DS_root = dirname(dirname(__DIR__));

/* The public sheets and the pages that carry their own <style> blocks.
   portal-v2.css, admin-v2.css and portal.css are SEPARATE design systems with
   their own scales — CLAUDE.md pins the console's 14/20 — so they are not this
   ladder's business and are deliberately excluded. */
$DS_files = array_merge(
    [$DS_root . '/assets/css/main.css', $DS_root . '/assets/css/apply.css', $DS_root . '/assets/css/motion.css'],
    glob($DS_root . '/*.php') ?: [],
    [$DS_root . '/assets/js/main.js', $DS_root . '/assets/js/rhythm-game.js',
     $DS_root . '/assets/js/studio-gate.js', $DS_root . '/assets/js/headshot.js']
);

t_group('design system — the radius ladder');

/* There were 21 authored radius values here: 9/10/11/12/13/14 all in use at
   once, three spellings of a pill (100px, 99px, 999px), and eight ways to
   round a small chip. 21 steps is not a scale — it means the next rule picks
   a number rather than a step, which is exactly how it got to 21. */
$DS_ladder = [4, 8, 10, 12, 16, 20, 30, 44, 100];
$DS_off = [];
foreach ($DS_files as $DS_f) {
    $DS_src = (string) @file_get_contents($DS_f);
    if ($DS_src === '') continue;
    if (!preg_match_all('/border-radius:\s*(\d+)px(?=\s*[;}\'"])/', $DS_src, $m)) continue;
    foreach ($m[1] as $DS_v) {
        if (!in_array((int) $DS_v, $DS_ladder, true)) {
            $DS_off[] = basename($DS_f) . ':' . $DS_v . 'px';
        }
    }
}
t_eq(array_values(array_unique($DS_off)), [],
    'every authored radius on the public site is a rung of the ladder (4/8/10/12/16/20/30/44/100)');

$DS_css = (string) file_get_contents($DS_root . '/assets/css/main.css');
/* 10 and 12 both survive on purpose. An inner corner has to be tighter than
   the card it sits inside or the two read as concentric and wrong, so
   collapsing them into one step would make the nested cases look worse, not
   better. The tokens must keep saying so. */
t_ok(str_contains($DS_css, '--radius-ctl:10px') && str_contains($DS_css, '--radius-sm:12px'),
    'the ladder keeps a control radius AND a card radius — an inner corner is tighter than its card');

t_group('design system — the reading measure');

/* MEASURED before this: .section-desc\'s 680px cap read as 81 characters,
   terms\' 800px as 98 and .sch-note ran to 124. */
t_ok(preg_match('/--measure:\s*\d+ch/', $DS_css) === 1,
    'the measure is a token, and stated in `ch` — a px cap makes the line SHORTER in '
    . 'characters exactly when the accessibility toolbar scales the root font up');

foreach ([
    ['assets/css/main.css', '.section-desc',    'the section lede'],
    ['assets/css/main.css', '.cta-desc',        'the closing CTA'],
    ['assets/css/main.css', '.next-step-desc',  'the next-step card'],
    ['assets/css/main.css', '.faq-answer-inner','a FAQ answer'],
    ['terms.php',           '.legal p',         'legal prose'],
    ['how-it-works.php',    '.hiw-card p',      'a journey step'],
    ['scholarship.php',     '.sch-note',        'the scholarship note'],
] as [$DS_file, $DS_sel, $DS_what]) {
    $DS_s = (string) file_get_contents($DS_root . '/' . $DS_file);
    /* A selector can carry several rules (.faq-answer-inner has its padding in
       one and its cap in a responsive block further down), so ANY of them
       holding the measure is the guarantee — checking only the first reads a
       rule that was never meant to set a width. */
    $DS_hit = false;
    $DS_from = 0;
    while (($DS_at = strpos($DS_s, $DS_sel, $DS_from)) !== false) {
        $DS_from = $DS_at + 1;
        $DS_next = $DS_s[$DS_at + strlen($DS_sel)] ?? '';
        if ($DS_next !== '{' && $DS_next !== ',' && $DS_next !== ' ') continue;
        $DS_open = strpos($DS_s, '{', $DS_at);
        $DS_close = $DS_open === false ? false : strpos($DS_s, '}', $DS_open);
        if ($DS_close === false) continue;
        if (str_contains(substr($DS_s, $DS_open, $DS_close - $DS_open), 'var(--measure)')) { $DS_hit = true; break; }
    }
    t_ok($DS_hit, "$DS_what ($DS_sel) is capped at the shared measure, not left to the column width");
}

t_group('design system — target floors that were measured under WCAG 2.5.8');

/* The desktop footer reached for a tighter column with `min-height:0` and went
   straight through the 24px floor: MEASURED 19px on .footer-link and 21px on
   .footer-legal-link. `0` is the spelling that caused it, so `0` is what this
   guards — a smaller-but-nonzero value would at least be a deliberate number. */
foreach (['.footer-link', '.footer-legal-link', '.footer-contact .footer-link'] as $DS_sel) {
    t_ok(!preg_match('/' . preg_quote($DS_sel, '/') . '\{[^}]*min-height:\s*0[;}]/', $DS_css),
        "$DS_sel never sets min-height:0 — that is how it measured 19px, under WCAG 2.5.8's 24");
}

/* The hero carousel dot: the BUTTON is the target, the 14px circle is the
   mark. Two rules inside the `pointer:coarse` block once sized the button to
   the mark, which put the control at 32x14 on the phones that are most of this
   academy's traffic. The same separation now carries the testimonial dot. */
t_ok(str_contains($DS_css, '.hero-dot-face') && str_contains($DS_css, '.t-dot::before'),
    'both carousels paint the mark on a child/pseudo-element, so the hit area is sized independently');

t_group('design system — contrast that was computed, not eyeballed');

/* MEASURED 1.79:1 at alpha 0.24 on --footer-bg. This is 34px display type, so
   1.4.3 judges it as LARGE and asks 3:1 — it was still a failure, and a
   wordmark nobody can quite see is not restraint, it is a link that looks
   broken. 0.44 computes to 3.19:1 and still reads as a watermark. */
if (preg_match('/\.footer-wordmark span\{[^}]*color:rgba\(232,\s*201,\s*160,\s*([0-9.]+)\)/', $DS_css, $m)) {
    t_ok((float) $m[1] >= 0.42,
        'the parent-org wordmark clears 3:1 on the footer background (alpha ' . $m[1] . ', needs >= 0.42)');
} else {
    t_ok(false, 'the footer wordmark colour is still an rgba on --footer-bg this test can check');
}
