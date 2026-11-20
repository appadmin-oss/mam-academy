<?php
/**
 * The admin console's phone subset (design frame `#5b`).
 *
 * The refusals are most of this file, and they are asserted against the
 * PAYLOAD and the FILES rather than the rendered markup — the same discipline
 * the instructor and student portals hold. A refusal that lives only in a
 * template is one a later template forgets, and nobody can eyeball four
 * renderers and be sure none of them grew a delete button.
 */
declare(strict_types=1);

t_group('phone subset — four panels, in the frame\'s order');

t_eq(MAM_PHONE_PANELS, ['decide', 'today', 'assign', 'lookup'],
    'the tab bar is the frame\'s four, in its order');

/* ⚠️ The frame contradicts itself here and the handoff resolves it: the
   caption under #5b says "Three destinations", while the tab bar it is
   captioning has four buttons and the handoff's own summary says "Four panels
   — Decide / Today / Assign / Look up". Four is right; the caption is stale.
   Pinned so nobody "corrects" the code to the caption. */
t_eq(count(MAM_PHONE_PANELS), 4,
    'FOUR panels — the frame\'s caption says three, but its own tab bar and the handoff both say four');

foreach (MAM_PHONE_PANELS as $PH_p) {
    t_ok(mam_phone_panel_ok($PH_p), "'$PH_p' is a panel the router accepts");
    t_ok(is_file(dirname(dirname(__DIR__)) . '/partials/admin-phone/' . $PH_p . '.php'),
        "'$PH_p' has a renderer");
}
t_ok(!mam_phone_panel_ok('settings'), 'a desktop console screen is NOT a phone panel');
t_ok(!mam_phone_panel_ok('../evil'), 'a traversal attempt is not a panel');

t_group('phone subset — the refusal is in the read layer, not the template');

/* THE REFUSAL. "Amending a record, voiding an invoice and closing a period are
   deliberately unavailable here. A consequential, hard-to-reverse action taken
   one-handed on a bus is how a ledger acquires a mystery."

   Every phone write posts to the same dispatcher as the desktop console, so
   the ONLY thing keeping a consequential action off this surface is which
   actions its files name. That makes it greppable, and therefore testable. */
$PH_files = glob(dirname(dirname(__DIR__)) . '/partials/admin-phone/*.php') ?: [];
$PH_files[] = dirname(dirname(__DIR__)) . '/admin/phone.php';
t_ok(count($PH_files) >= 5, 'the phone surface is four renderers and a shell');

foreach ($PH_files as $PH_f) {
    $PH_src = (string) @file_get_contents($PH_f);
    $PH_name = basename($PH_f);
    foreach (MAM_PHONE_REFUSED_ACTIONS as $PH_bad) {
        t_ok(!preg_match("/['\"]" . preg_quote($PH_bad, '/') . "['\"]/", $PH_src),
            "$PH_name never offers '$PH_bad' — the phone does reversible work only");
    }
}

t_ok(str_contains(MAM_PHONE_DESK_NOTICE, 'deliberately unavailable'),
    'the refusal is stated in words the admin reads, not only enforced');
$PH_lookup = (string) file_get_contents(dirname(dirname(__DIR__)) . '/partials/admin-phone/lookup.php');
t_ok(str_contains($PH_lookup, 'MAM_PHONE_DESK_NOTICE'),
    'Look up prints it — the panel where somebody goes hunting for the missing button');

/* No dead buttons. The frame offers WhatsApp; there is no messaging provider
   configured in this repo, and the modernization notes refuse to ship a
   control that cannot work. */
foreach ($PH_files as $PH_f) {
    /* A CONTROL, not the word — lookup.php explains in a comment why the
       frame's WhatsApp row is absent, and a test that cannot tell an
       explanation from an implementation forbids documenting the decision. */
    t_ok(!preg_match('~(wa\.me|whatsapp://|fa-whatsapp|href=[\'"][^\'"]*whatsapp)~i',
            (string) @file_get_contents($PH_f)),
        basename($PH_f) . ' ships no WhatsApp control — there is no provider behind one');
}

t_group('phone subset — every provider reads without a schema error');

/* The same guarantee `test_console.php` gives the desktop: a provider that
   names a column the schema does not have renders as NOTHING rather than as an
   error (gotcha #9), so the failure register is the only thing that catches
   it. Reading it here caught three bad columns while this was being built. */
$GLOBALS['__mam_console_fail'] = [];
mam_phone_decide();
mam_phone_today();
mam_phone_assign('Test');
mam_phone_lookup('Test');
$PH_fails = mam_console_failures();
t_eq($PH_fails, [], 'no phone provider records a failed read: '
    . json_encode(array_map(static fn($f) => ($f['where'] ?? '?') . ': ' . ($f['error'] ?? ''), $PH_fails)));

t_group('phone subset — what the panels promise');

$PH_today = mam_phone_today();
t_ok(isset($PH_today['date'], $PH_today['sessions'], $PH_today['clashes'], $PH_today['marked'], $PH_today['unmarked']),
    'Today carries the day, its sessions, its clashes and the register split');
t_eq($PH_today['date'], mam_phone_today_date(), 'the day is the provider\'s own');
/* ⚠️ The academy's day, never the server's. A console open in London must not
   show tomorrow's sessions to somebody standing in the building in Lagos. */
$PH_src = (string) file_get_contents(dirname(__DIR__) . '/lib/console-phone.php');
t_ok(str_contains($PH_src, 'mam_calendar_today'),
    'Today resolves the date through the calendar, not the server clock');

// Empty search is not a search — it must not list the whole academy.
t_eq(mam_phone_assign('')['options'], [], 'Assign with no query offers no classes');
t_eq(mam_phone_lookup('')['person'], null, 'Look up with no query resolves nobody');

$PH_look = mam_phone_lookup('Test');
t_ok(array_key_exists('attendance', $PH_look), 'Look up carries an attendance summary');
/* An em dash means NOT MEASURED, never zero — the line the whole console
   holds. A learner with no sessions has no rate, and 0% reports them as never
   turning up. */
$PH_none = mam_phone_lookup('Refuse');
if ($PH_none['person'] && (int) $PH_none['attendance']['total'] === 0) {
    t_eq($PH_none['attendance']['pct'], null,
        'a learner with no sessions has a NULL rate, not 0% — null is not zero');
}

/* Owed must use MAM_ACCT_OWED, never `= 'outstanding'`: a disputed charge is
   non-terminal and still counts as owed. */
t_ok(str_contains($PH_src, 'MAM_ACCT_OWED'),
    'the balance uses the owed PREDICATE, not a single status');

t_group('phone subset — Assign lists what is refused, and why');

/* The frame's rule: "Only classes she actually passes are tappable. A full
   cohort offers the waitlist instead, and anything she fails is listed with
   the reason rather than hidden." A list that quietly drops the ineligible
   teaches a registrar that the class does not exist. */
$PH_assign = mam_phone_assign('Test');
foreach ($PH_assign['options'] as $PH_o) {
    t_ok(array_key_exists('joinable', $PH_o) && array_key_exists('blocked', $PH_o),
        'every option says whether it can be joined, and what stops it');
    if (!$PH_o['joinable']) {
        t_ok(($PH_o['blocked'] !== null) || !empty($PH_o['full']),
            'an unjoinable option carries a reason — it is listed, never hidden');
    }
}

t_group('phone subset — the shell, and the one implementation of a write');

$PH_shell = (string) file_get_contents(dirname(dirname(__DIR__)) . '/admin/phone.php');
/* A root HTML page loading bootstrap must undo the JSON content type and
   claim the response, or it renders as source text with a no_response
   envelope appended (gotcha #5). */
t_ok(str_contains($PH_shell, "header_remove('Content-Type')"), 'the shell resets the JSON content type');
/* ⚠️ MAM_HTML_RESPONSE, not MAM_RESPONSE_SENT. The latter is what mam_json()
   reads to mean "bytes already sent", so a page defining it up front turned
   every mam_fail() on that page into a no-op that returned and let execution
   continue — see test_admin_screens.php, where that left the admin CSRF gate
   inert. This page suppresses the JSON envelope without making that claim. */
t_ok(str_contains($PH_shell, 'MAM_HTML_RESPONSE'), 'the shell suppresses the JSON envelope');
t_ok(!str_contains($PH_shell, "define('MAM_RESPONSE_SENT'"),
    '…without telling mam_json() a response has already gone out');
t_ok(str_contains($PH_shell, 'mam_require_admin') && str_contains($PH_shell, 'mam_user_has_role'),
    'the shell is admin-gated, break-glass included');
t_ok(str_contains($PH_shell, 'mam_console_failures'),
    'a read that failed is NAMED on the page, as it is on the desktop');

/* Writes stay in admin/index.php. A phone renderer that posted anywhere else
   would be a second implementation of "waive" that can drift from the first. */
foreach (glob(dirname(dirname(__DIR__)) . '/partials/admin-phone/*.php') ?: [] as $PH_f) {
    $PH_s = (string) file_get_contents($PH_f);
    /* ⚠️ A grep for `<form method="post">` finds NOTHING here and passes
       vacuously — the forms are emitted by `ac_form()`, which hardcodes
       `action="/admin/"`. So the assertion that carries the guarantee is the
       negative one: a phone renderer must not hand-roll a POST form, because
       a hand-rolled one could point anywhere. Writing through the helper is
       what keeps there being one implementation of "waive". */
    t_ok(!preg_match('/<form\b[^>]*method=[\'"]post[\'"]/i', $PH_s),
        basename($PH_f) . ' hand-rolls no POST form — writes go through ac_form(), which posts to /admin/');
    if (str_contains($PH_s, 'ac_form(')) {
        /* The action name is sometimes a variable (decide.php builds its
           buttons from the provider), so the literal to look for is the
           RETURN target, which is always written out. */
        t_ok(str_contains($PH_s, "'phone:"),
            basename($PH_f) . ': its writes return to a PHONE panel, not to a desktop screen');
        t_ok(!preg_match('/ac_form\([^)]*,\s*[\'"](?!phone:)[a-z]+[\'"]/', $PH_s),
            basename($PH_f) . ': no write returns to a desktop console screen instead');
    }
}
t_ok(str_contains($PH_shell, 'MAM_CONSOLE_CSRF'),
    'the shell sets the global ac_form() reads, so phone forms carry a CSRF token');

/* The return hook. `phone:<panel>` is validated against the panel list the
   same way a console screen is validated against mam_console_screens(). */
$PH_admin = (string) file_get_contents(dirname(dirname(__DIR__)) . '/admin/index.php');
t_ok(str_contains($PH_admin, "strncmp(\$screen, 'phone:', 6)"),
    'the return hook recognises a phone panel');
t_ok(str_contains($PH_admin, 'mam_phone_panel_ok'),
    '…and VALIDATES it against the panel list rather than sanitising a string');

t_group('phone subset — the conventions the rest of the console keeps');

// No inline style, exactly as the desktop partials are held.
foreach (glob(dirname(dirname(__DIR__)) . '/partials/admin-phone/*.php') ?: [] as $PH_f) {
    $PH_s = (string) file_get_contents($PH_f);
    t_ok(!preg_match('/\sstyle="/', $PH_s),
        basename($PH_f) . ' carries no inline style — the stylesheet is the design system');
}

/* Gotcha #1: a new lib must be in BOTH the bootstrap autoload list and the
   test harness's require block, or it works in one and is missing in the
   other. This file only runs at all because the harness loads it, so the
   assertion that matters is the bootstrap half. */
t_ok(in_array('console-phone', require dirname(__DIR__) . '/lib/libs.php', true),
    'console-phone is in the one library list (api/lib/libs.php), which the app and the harness both load');

// The stylesheet block exists and is scoped like the rest of the console.
$PH_css = (string) file_get_contents(dirname(dirname(__DIR__)) . '/assets/css/admin-v2.css');
t_ok(str_contains($PH_css, '.ap-tabs'), 'the phone block is in the console stylesheet');
t_ok(!preg_match('/^\s*\.ap-[a-z-]+\s*\{/m', $PH_css),
    'every phone rule is scoped under [data-admin="v2"] — an unscoped .ap-* rule would leak into any page');
