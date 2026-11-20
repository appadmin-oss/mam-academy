<?php
/**
 * Structure cleanup of 2026-11-20 (HANDOFF §4z14): what was removed stays removed,
 * and the one cut that broke something while it was being made is pinned.
 */
declare(strict_types=1);

t_group('code structure — the store still boots after its dead dialog went');

$CS_store = (string) file_get_contents(dirname(__DIR__, 2) . '/store.php');
/* The first attempt at removing the unreachable checkout dialog cut to the end
   of the script and took the catalogue's start-up call with it: the store drew
   NO items and threw nothing. */
t_ok((bool) preg_match('/\n  load\(\);\n\}\)\(\);/', $CS_store), 'the store script ends by loading the catalogue');
t_ok(!str_contains($CS_store, 'id="checkoutModal"') && !str_contains($CS_store, 'function openCheckout'),
    'the checkout dialog nothing could open is gone — checkout is its own page');
t_ok(str_contains($CS_store, "window.location.href = '/checkout'"), 'and the cart sends a buyer to that page');
t_ok(str_contains($CS_store, '.modal-close{'), 'the cart drawer keeps the close-button style it shared with the dialog');

t_group('code structure — removed vestiges stay removed');

foreach (['ac_money', 'ac_money_dual', 'mam_acadmail_appurl', 'mam_ach_icon', 'mam_card_for_summer_app',
          'mam_csrf_field', 'mam_email_logo_png_b64', 'mam_fx_fetch_usd_ngn', 'mam_lesson_check_kind_label',
          'mam_lms2_rooms', 'mam_v2_load_tenant', 'mam_v2_load_centre', 'mam_v2_load_people',
          'mam_v2_load_applications', 'mam_v2_load_payments'] as $CS_fn) {
    t_ok(!function_exists($CS_fn), "$CS_fn() — defined and never called — is not reintroduced");
}
t_ok(!is_file(dirname(__DIR__, 2) . '/assets/js/inline-edit.js'), 'the legacy dashboard\'s inline-edit script, loaded by no page, is gone');
t_ok(str_contains((string) file_get_contents(dirname(__DIR__) . '/lib/console.php'), 'fn() => mam_sg_open_count()'),
    'the safeguarding badge asks the library\'s own count rather than a second copy of its query');

t_group('code structure — one list of libraries');

/* bootstrap.php and the harness each kept their own list (91 and 89 entries)
   and a new library had to be added to both. Now both read api/lib/libs.php;
   what is left to get wrong is forgetting the one list, which this catches. */
$CS_libs = require dirname(__DIR__) . '/lib/libs.php';
t_eq(count($CS_libs), count(array_unique($CS_libs)), 'the list names each library once');
$CS_files = array_map(fn($f) => basename($f, '.php'), glob(dirname(__DIR__) . '/lib/*.php'));
t_eq(array_values(array_diff($CS_files, $CS_libs, ['bootstrap', 'router', 'libs'])), [],
    'every library in api/lib is on the list — a file left off it is never loaded');
foreach ($CS_libs as $CS_l) if (!is_file(dirname(__DIR__) . "/lib/$CS_l.php")) t_ok(false, "listed library $CS_l exists");
t_ok(str_contains((string) file_get_contents(dirname(__DIR__) . '/lib/bootstrap.php'), "require __DIR__ . '/libs.php'")
     && str_contains((string) file_get_contents(__DIR__ . '/run.php'), "require \$ROOT . '/lib/libs.php'"),
    'the app and the harness both load that list, not a copy of it');

t_group('code structure — one answer to "may we measure this visitor"');

/* apply.js and studio-core.js each had their own check, and both asked only
   Do-Not-Track (removed from Chrome and Safari). Neither read the cookie
   banner, so Decline under "Essential cookies only" changed nothing while the
   privacy page promised analytics "only where you have not opted out". */
$CS_cfg = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/config.js');
t_ok(str_contains($CS_cfg, 'window.MAM_PRIVACY') && str_contains($CS_cfg, 'globalPrivacyControl')
     && str_contains($CS_cfg, "localStorage.getItem('mam.cookie') === 'decline'"),
    'the one predicate honours Do-Not-Track, Global Privacy Control and the banner\'s Decline');
foreach (['apply.js', 'studio-core.js'] as $CS_f) {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . "/assets/js/$CS_f");
    t_ok(str_contains($src, 'MAM_PRIVACY.mayMeasure()') && !str_contains($src, 'navigator.doNotTrack'),
        "$CS_f asks that predicate rather than keeping its own check");
}
t_ok(str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/main.js'), "dismiss('decline')"),
    'the banner still records a Decline under the key the predicate reads');

t_group('code structure — one base64url for every signed token');

foreach (['live-classes', 'meetings', 'attendance', 'faculty'] as $CS_l) {
    t_ok(!preg_match('/function mam_\w+_(un)?b64\(/', (string) file_get_contents(dirname(__DIR__) . "/lib/$CS_l.php")),
        "$CS_l.php has no private copy of the token encoding");
}
foreach (["\x00\xff\xfe", 'a', 'ab', 'abc', random_bytes(37)] as $CS_raw) {
    t_eq(mam_b64url_decode(mam_b64url_encode($CS_raw)), $CS_raw, 'a token round-trips, unpadded (' . strlen($CS_raw) . ' bytes)');
}
t_ok(!str_contains(mam_b64url_encode(random_bytes(60)), '='), 'and carries no padding');

t_group('code structure — the Display & access screen exists once');

/* All three portals carried the same screen and the same handler under the
   same storage key; it lives in the shared kit (portal.js) now. */
$CS_kit = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/portal.js');
t_ok(str_contains($CS_kit, 'a11yHtml: a11yHtml, a11yHandle: a11yHandle'), 'the kit exports the screen and its handler');
foreach (['student', 'instructor', 'guardian'] as $CS_p) {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . "/assets/js/portal-$CS_p.js");
    t_ok(str_contains($src, 'paint(P.a11yHtml())') && str_contains($src, 'P.a11yHandle(t)')
         && !str_contains($src, "localStorage.getItem('mam-guardian-a11y')"),
        "the $CS_p portal uses the kit's screen and keeps no copy of its own");
}
