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
t_ok(str_contains((string) file_get_contents(dirname(__DIR__) . '/modules/admin/lib/console.php'), 'fn() => mam_sg_open_count()'),
    'the safeguarding badge asks the library\'s own count rather than a second copy of its query');

t_group('code structure — one list of libraries');

/* bootstrap.php and the harness each kept their own list (91 and 89 entries)
   and a new library had to be added to both. Now both read the list that
   api/modules/libs.php resolves from the module manifests; what is left to get
   wrong is a file no manifest names, which this catches. (The resolver itself
   throws on a lib named twice or a listed file that is missing.) */
$CS_libs = require dirname(__DIR__) . '/modules/libs.php';   // name => path
t_ok(count($CS_libs) > 90, 'the module manifests resolve to the library list [' . count($CS_libs) . ']');
$CS_onDisk = [];
foreach (glob(dirname(__DIR__) . '/modules/*/lib/*.php') ?: [] as $CS_f) $CS_onDisk[realpath($CS_f)] = $CS_f;
$CS_listed = array_flip(array_map('realpath', array_values($CS_libs)));
$CS_unlisted = array_values(array_diff_key($CS_onDisk, $CS_listed));
// The v2 package loads its own files (load.php requires plan.php, …); only its
// read model is on the list. Everything else in a module's lib/ must be listed.
$CS_unlisted = array_values(array_filter($CS_unlisted, fn($f) => !str_contains($f, '/modules/v2/lib/')));
t_eq($CS_unlisted, [], 'every library in api/modules/*/lib is in its module manifest — a file left off is never loaded');
t_eq(array_map(fn($f) => basename($f), glob(dirname(__DIR__) . '/lib/*.php') ?: []), ['bootstrap.php', 'router.php'],
    'api/lib holds only the boot files; libraries live in a module');
t_ok(str_contains((string) file_get_contents(dirname(__DIR__) . '/lib/bootstrap.php'), "require MAM_API_ROOT . '/modules/libs.php'")
     && str_contains((string) file_get_contents(__DIR__ . '/run.php'), "require \$ROOT . '/modules/libs.php'"),
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
foreach (['studio-core.js'] as $CS_f) {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . "/assets/js/$CS_f");
    t_ok(str_contains($src, 'MAM_PRIVACY.mayMeasure()') && !str_contains($src, 'navigator.doNotTrack'),
        "$CS_f asks that predicate rather than keeping its own check");
}
t_ok(str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/partials/site/a11y.php'), 'data-cookie-choice="decline"')
     && str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/site.js'), "store('mam.cookie', b.getAttribute('data-cookie-choice'))"),
    'the cookie notice still records a Decline under the key the predicate reads');

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
