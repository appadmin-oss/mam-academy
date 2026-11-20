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
