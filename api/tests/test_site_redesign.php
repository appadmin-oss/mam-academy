<?php
/**
 * The 2026-11 public site (Home, Programmes, About, MAM Auditer, Life at MAM),
 * ported from the design handoff in docs/design/2026-11-site/ (HANDOFF §4z16).
 *
 * The visual match itself is checked against the design sources by Faithful
 * (`npx @faithful/cli verify <page>.dc.html --build <url>`), which needs a
 * browser. What is pinned here is what a browser run would not keep true on its
 * own: that no value the design lacks creeps into the pages, that no figure the
 * server owns is typed into them, that no placeholder of the handoff ships, and
 * that the redesign keeps every destination the old footer offered.
 */
declare(strict_types=1);

$SR_root = dirname(dirname(__DIR__));
/* The redesigned pages are the ones built on the shell — derived, not listed, so a
   sixth page is covered the day it is written. */
$SR_pages = array_values(array_filter(glob($SR_root . '/*.php') ?: [],
    static fn($f) => str_contains($c = (string) file_get_contents($f), "partials/site/shell.php") && !str_contains($c, "partials/header.html")));
$SR_parts = glob($SR_root . '/partials/site/*.php') ?: [];
$SR_all = array_merge($SR_pages, $SR_parts, [$SR_root . '/assets/css/site.css', $SR_root . '/assets/css/expressions.css']);
$SR_src = static fn(array $files) => implode("\n", array_map(static fn($f) => (string) file_get_contents($f), $files));
$SR_design = $SR_src(glob($SR_root . '/docs/design/2026-11-site/source/pages/*.dc.html') ?: []);

t_group('site redesign — the pages are the ones the design describes');

t_eq(array_map('basename', $SR_pages), ['404.php', 'about.php', 'accessibility.php', 'apply.php', 'auditer.php', 'book.php', 'checkout.php', 'compare.php', 'contact.php', 'crash.php', 'faculty.php', 'faq.php', 'how-it-works.php', 'index.php', 'life.php', 'metamusic-order.php', 'notation.php', 'pathways.php', 'policies.php', 'power-play.php', 'privacy.php', 'programmes.php', 'safeguarding.php', 'scholarship.php', 'schools.php', 'status.php', 'store.php', 'summer.php', 'terms.php', 'thank-you.php', 'tuition.php', 'verify.php'],
    'the redesigned pages are the ones on the shell and not on the legacy header');
t_ok(strlen($SR_design) > 100000, 'the design sources are committed beside the build, so the check can be repeated');

t_group('site redesign — no value the design does not have');

/* Tokens first (the handoff's own rule): a radius, a colour or a type size the
   design never used is a misreading of it, not a choice. The sets are READ from
   the design sources, so a revised handoff moves them with it. */
$SR_build = $SR_src($SR_all);
$SR_set = static function (string $re, string $s): array {
    preg_match_all($re, $s, $m);
    return array_values(array_unique(array_map('strtolower', $m[1])));
};
$SR_off = static fn(array $build, array $design) => array_values(array_diff($build, $design));
t_eq($SR_off($SR_set('/border-radius:\s*(\d+px)/', $SR_build), $SR_set('/border-radius:\s*(\d+px)/', $SR_design)), [],
    'every radius on the redesigned pages is one the design uses');
t_eq($SR_off($SR_set('/(#[0-9a-f]{6})\b/i', $SR_build), $SR_set('/(#[0-9a-f]{6})\b/i', $SR_design)), [],
    'every hex colour on the redesigned pages is one the design uses');
t_eq($SR_off($SR_set('/font-size:\s*(\d+(?:\.\d+)?px)/', $SR_build), $SR_set('/font-size:\s*(\d+(?:\.\d+)?px)/', $SR_design)), [],
    'every fixed type size on the redesigned pages is one the design uses');

t_group('site redesign — nothing of the prototype ships');

/* Read what a visitor is sent, not the file: a comment explaining where a page
   came from may name the design file it was ported from. */
$SR_pagesSrc = preg_replace(['#/\*.*?\*/#s', '#<!--.*?-->#s'], '', $SR_src(array_merge($SR_pages, $SR_parts)));
foreach (['[INTAKE]' => 'the intake placeholder', '(upload)' => 'a photo-upload caption', '.dc.html' => 'a link into the design files',
          '/_blob/' => 'a design-canvas asset', 'onclick=' => 'an inline handler (the design runtime\'s, not ours)',
          'Photo placeholder' => 'a placeholder label read aloud'] as $SR_needle => $SR_what) {
    t_ok(!str_contains($SR_pagesSrc, $SR_needle), "no $SR_what on the redesigned pages");
}
/* Every page links somewhere real: an href is a route, an anchor on the page, or
   an outside address — never an empty "#" the design used as a stand-in. */
preg_match_all('/href="(#[^"]*)"/', $SR_pagesSrc, $SR_m);
t_eq(array_values(array_diff(array_unique($SR_m[1]), ['#main', '#programmes', '#levels', '#fees', '#instruments', '#intensives',
    '#measures', '#try', '#process', '#powerplay', '#term', '#events', '#tools', '#learn-more', '#history', '#register', '#why', '#categories', '#what', '#estimator', '#apply', '#schools-h', '#questions'])), [],
    'every in-page link names an anchor the pages define');

t_group('site redesign — every fee is the server\'s');

/* A dollar figure typed into a page is a second price list. Strip the PHP and
   look at what is left: there must be no money in it. */
$SR_html = preg_replace('/<\?(php|=).*?\?>/s', '', $SR_src($SR_pages));
preg_match_all('/[$₦£]\s?\d[\d,]*/u', (string) $SR_html, $SR_money);
t_eq($SR_money[0], [], 'no fee is typed into a redesigned page; each comes from site_fee()');

$SR_f = site_fees();
$SR_from = mam_programme_from_prices();
t_eq(site_fee('from:certified-musician'), '$' . number_format($SR_from['certified-musician']), 'the "from" price printed is the one the server charges from');
t_eq(site_fee('level3'), '$' . number_format(mam_level_base_price('diploma')), 'a level\'s printed base is the server\'s');
t_eq(site_fee('reg'), '$' . number_format(mam_registration_fee()), 'registration is the server\'s');

/* The handoff ships its fee model as a reference with check values. Run the
   estimator's arithmetic (the same rules site-programmes.js applies) over the
   SERVER's figures and require the handoff's answers — if the academy changes a
   fee the check values move and this says so, which is the point. */
$SR_q = static function (string $prog, int $lvl, string $mode, int $x) use ($SR_f): float {
    $B = $SR_f['levels'][$lvl]['base']; $half = $B / 2;
    $sub = match ($prog) {
        'standard'  => $B + $half * $x,
        'certified' => $B + $SR_f['compulsory'],
        'grand'     => (3 * $B + $half * $x) * (1 - $SR_f['grandPct'] / 100),
        'crash'     => $SR_f['crash'],
    };
    return round($prog !== 'crash' && $mode === 'online' ? $sub * (1 + $SR_f['onlinePct'] / 100) : $sub);
};
t_eq($SR_q('certified', 0, 'onsite', 0), 240.0, 'handoff check: Certified, Pre-Certificate, in person = $240');
t_eq($SR_q('grand', 0, 'onsite', 0), 313.0, 'handoff check: Grand, Pre-Certificate = $313');
t_eq($SR_q('standard', 0, 'online', 2), 288.0, 'handoff check: Standard + 2 courses, online = $288');
t_eq($SR_q('crash', 0, 'online', 0), 70.0, 'handoff check: Crash carries no surcharge = $70');
foreach ([['grand', 2, 'online', 3], ['certified', 1, 'onsite', 0], ['standard', 3, 'onsite', 4]] as [$p, $l, $m, $x]) {
    $SR_server = mam_compute_application_price(['programme' => ['grand' => 'grand-musician', 'certified' => 'certified-musician'][$p] ?? $p,
        'level' => $SR_f['levels'][$l]['id'], 'mode' => $m, 'extraCourses' => $x]);
    t_eq($SR_q($p, $l, $m, $x), (float) $SR_server['subtotal'], "the estimator's $p/{$SR_f['levels'][$l]['id']}/$m/+$x equals the charged subtotal");
}
$SR_js = (string) file_get_contents($SR_root . '/assets/js/site-programmes.js');
t_ok(!preg_match('/\b(120|130|160|180|313|1500)\b/', $SR_js), 'the estimator script types no fee or rate of its own');

t_group('site redesign — the intake is the calendar\'s');

$SR_intake = site_intake();
t_ok($SR_intake !== '' && !str_contains($SR_intake, 'INTAKE'), 'the intake name is read from the academic session');
t_ok(!preg_match('/\bSeptember\b/', $SR_pagesSrc), 'no month is typed into the redesigned pages');

t_group('site redesign — nothing a visitor could reach is lost');

/* The legacy footer still frames every page the redesign has not reached; every
   destination it offers must still be reachable from the redesigned pages — the
   shell (header, panel, footer) or the pages themselves. Faithful's parity run on
   the old and new pages found the rest; HANDOFF §4z16 lists what was dropped and
   why. Derived from the legacy file, so a link added there is owed here. */
$SR_dest = static fn(string $h) => strtolower(trim(preg_replace('/[#?].*$/', '', preg_replace('#^https?://academy\.mamcentre\.org#', '', $h)), '/'));
preg_match_all('/href="([^"]+)"/', (string) file_get_contents(__DIR__ . '/fixtures/footer-before-2026-11.html'), $SR_o);
preg_match_all("/(?:href=\"|\[[^\]]*?', ')((?:https?:|mailto:|tel:|\/)[^\"']*)/", $SR_src(array_merge($SR_pages, $SR_parts)), $SR_n);
$SR_have = array_unique(array_map($SR_dest, $SR_n[1]));
$SR_missing = array_values(array_filter(array_unique(array_map($SR_dest, $SR_o[1])),
    static fn($d) => $d !== '' && !in_array($d, $SR_have, true)));
t_eq($SR_missing, [], 'every destination of the legacy footer is reachable from the redesigned pages');
t_ok(str_contains((string) file_get_contents($SR_root . '/programmes.php'), 'https://mamcentre.org/scholarships/ngv'),
    'the external scholarship form is still linked, from the scholarship line');
$SR_aud = (string) file_get_contents($SR_root . '/auditer.php');
t_ok(str_contains($SR_aud, 'id="checklist"') && substr_count($SR_aud, "['") >= 19,
    '/auditer#checklist survives (thank-you, status, booking and the portal link to it)');
t_ok(str_contains((string) file_get_contents($SR_root . '/assets/js/site-auditer.js'), "'mam.auditer.checklist.v1'"),
    'the checklist keeps its storage key, so ticks made before the redesign are kept');
t_ok(str_contains((string) file_get_contents($SR_root . '/about.php'), 'id="method"'),
    '/about#method still lands on the method (the legacy header links it)');

t_group('site redesign — the application sends what the server requires');

/* The design's seven questions collect less than mam_validate_application()
   refuses without, so the flow adds screens. Each field the validator can reject
   is READ from the validator ($errors[] = '…') and must be in the payload the
   flow builds — a renamed field there fails here, not on a family's submission. */
$SR_val  = (string) file_get_contents(MAM_API_ROOT . '/lib/validation.php');
$SR_fn   = substr($SR_val, (int) strpos($SR_val, 'function mam_validate_application'));
$SR_fn   = substr($SR_fn, 0, (int) strpos($SR_fn, "\nfunction "));
preg_match_all("/\\\$errors\\[\\] = '([a-zA-Z]+)'/", $SR_fn, $SR_req);
$SR_flow = (string) file_get_contents($SR_root . '/assets/js/apply-flow.js');
$SR_pay  = substr($SR_flow, (int) strpos($SR_flow, 'function payload()'));
$SR_pay  = substr($SR_pay, 0, (int) strpos($SR_pay, '  }'));
$SR_missingFields = array_values(array_filter(array_unique($SR_req[1]),
    static fn($k) => !preg_match('/\b' . $k . ':/', $SR_pay) && $k !== 'headshotUrl'));
t_ok(count($SR_req[1]) >= 15, 'the validator\'s required fields were found (the sweep is reading something)');
t_eq($SR_missingFields, [], 'every field the server requires is in the application flow\'s payload (headshot is optional)');
t_ok(str_contains($SR_flow, "purpose: 'booking_fee'"), 'the only charge is the registration, through the existing booking-fee payment');
t_ok(str_contains($SR_val, "'undecided'"), '"Help me choose" is a value the server accepts');
t_ok(str_contains((string) file_get_contents($SR_root . '/api/lib/admissions.php'), "/apply?resume=")
     && str_contains((string) file_get_contents($SR_root . '/apply.php'), '$apFromDraft'),
    'a "resume your draft" link from the retired wizard lands on the live form with a note');
