<?php
/**
 * The admin actions that lost their button when the legacy dashboard was retired (HANDOFF §4y),
 * put back in the console — and a guard that keeps them there.
 *
 * Every action `admin/index.php` handles must be posted by some console or phone form, except
 * the ones still listed in $CR_pending below. That list only ever SHRINKS: an action restored is
 * deleted from it, and an action that loses its form again fails here. Measured when this file
 * was written (25 Nov 2026): 168 handled, 59 with no form (Settings, the first, restored the same day).
 */
declare(strict_types=1);

$CR_root = dirname(__DIR__, 2);

/** Every action the dispatcher handles, and every action a console or phone form posts. */
$CR_handled = (static function () use ($CR_root): array {
    $idx = (string) file_get_contents("$CR_root/admin/index.php");
    // Three ways an action is dispatched: `$action === 'x'`, `in_array($action, ['x', …])`, and
    // the cases of a `switch ($action)`. Reading only the first missed 20 actions.
    preg_match_all("/\\\$action\\s*===\\s*'([a-z0-9_]+)'/", $idx, $m);
    $names = $m[1];
    preg_match_all('/in_array\\(\\$action,\\s*\\[([^\\]]*)\\]/', $idx, $lists);
    foreach ($lists[1] as $l) { preg_match_all("/'([a-z0-9_]+)'/", $l, $q); $names = array_merge($names, $q[1]); }
    if (preg_match('/switch \\(\\$action\\) \\{(.*?)\\n {12}\\}/s', $idx, $sw)) {
        preg_match_all("/case '([a-z0-9_]+)':/", $sw[1], $q);
        $names = array_merge($names, $q[1]);
    }
    return array_values(array_unique($names));
})();
$CR_posted = (static function () use ($CR_root): array {
    $files = array_merge(
        glob("$CR_root/partials/admin-console/*.php") ?: [],
        glob("$CR_root/partials/admin-phone/*.php") ?: [],
        ["$CR_root/admin/console.php", "$CR_root/admin/phone.php"],
    );
    $out = [];
    foreach ($files as $f) {
        $s = (string) file_get_contents($f);
        preg_match_all("/ac_(?:form|action_btn)\\(\\s*'([a-z0-9_]+)'/", $s, $a);
        preg_match_all("/name=[\"']action[\"']\\s+value=[\"']([a-z0-9_]+)[\"']/", $s, $b);
        foreach (array_merge($a[1], $b[1]) as $x) $out[$x] = true;
    }
    return array_keys($out);
})();

/* Still without a button. DELETE a name from here when its form exists; never add one. */
$CR_pending = [
    'acadmail_toggle', 'app_bulk_status', 'app_delete', 'app_note', 'avatar_clear', 'course_approve',
    'datarights_erase', 'delete_promo_banner', 'dp_assessment_save', 'dp_breach_close',
    'dp_breach_ndpc', 'dp_breach_record', 'dp_breach_subjects', 'dp_note_save', 'dp_processor_save',
    'dp_request_ack', 'dp_request_close', 'dp_request_export', 'dp_request_open', 'dp_retention_run',
    'module_save', 'rubric_update', 'faculty_app_status', 'faculty_assign_subjects', 'faculty_invite',
    'faculty_request_review', 'fee_aid_revoke', 'meet_reconcile', 'meet_sync', 'module_import',
    'module_proposal_review', 'module_scaffold', 'notif_read_all',
    'recital_add_item', 'recital_create', 'recital_email', 'recital_recording', 'recital_reorder',
    'recital_status', 'recital_update', 'referral_reward', 'resource_add', 'rubric_create', 'rubric_delete',
    'save_email_branding', 'save_google_meet', 'save_promo_banner', 'seed_starter',
    'set_student_type', 'store_item_delete', 'store_item_save', 'summer_set_status', 'user_delete', 'vetting_delete',
];

t_group('console — every admin action has a button (the legacy dashboard\'s 83, put back)');

$CR_orphans = array_values(array_diff($CR_handled, $CR_posted));
sort($CR_orphans);
$CR_unlisted = array_values(array_diff($CR_orphans, $CR_pending));
t_eq($CR_unlisted, [], 'no action handled by admin/index.php is without a console form, beyond the pending list');
$CR_done = array_values(array_intersect($CR_pending, $CR_posted));
t_eq($CR_done, [], 'every action on the pending list is still pending (delete a name once its form exists)');
t_ok(count($CR_handled) >= 160, 'the dispatcher was read (' . count($CR_handled) . ' actions)');

t_group('console — Settings: one form, every key, nothing reset by a save');

t_ok(!mam_setting_is_quantity('0117579643'),
    'an account number with a leading zero is text, not a number (stored as a number it lost the zero)');
t_ok(mam_setting_is_quantity(130) && mam_setting_is_quantity('8') && mam_setting_is_quantity(0.5),
    '…while a price, an age and a fraction are quantities');
t_ok(!mam_setting_is_quantity('per_session'), '…and a word is text');

require_once "$CR_root/partials/admin-console/_ui.php";
$GLOBALS['MAM_CONSOLE_CSRF'] = 'test-token';
$CR_render = static function (string $screen, array $d) use ($CR_root): string {
    ob_start();
    (static function () use ($CR_root, $screen, $d) { require "$CR_root/partials/admin-console/$screen.php"; })();
    return (string) ob_get_clean();
};
$CR_html = $CR_render('settings', mam_console_settings());

$CR_form = preg_match('/<form[^>]*id="settings-form".*?<\/form>/s', $CR_html, $m) ? $m[0] : '';
t_ok($CR_form !== '' && str_contains($CR_form, 'value="save_settings"'), 'Settings has a form that posts save_settings');
$CR_missing = [];
foreach (mam_settings_schema() as $row) {
    if (!preg_match('/name="' . preg_quote($row[1], '/') . '"/', $CR_form)) $CR_missing[] = $row[1];
}
t_eq($CR_missing, [], 'the form carries EVERY schema key — the handler resets any key it is not sent');
t_ok((bool) preg_match('/<input type="hidden" name="scholarship_enabled" value="0">\s*<input type="checkbox" role="switch"[^>]*name="scholarship_enabled" value="1"/', $CR_form),
    'an on/off setting is a switch that always posts a value: a hidden 0 BEFORE the box, because an unticked checkbox posts nothing, which the handler reads as "the default"');
t_ok((bool) preg_match('/<input[^>]*type="text"[^>]*name="invoice_account_number"/', $CR_form),
    'the bank account number is a text field, not a number field');
t_ok((bool) preg_match('/<select[^>]*name="safeguarding_gate_mode"/', $CR_form),
    'a mode setting offers its values rather than free text');

t_group('console — filters are chips that link');

ob_start(); ac_facet('timetable', 'room', 'Room', ['3' => 'Studio A', '4' => 'Hall'], ['room' => '4', 'w' => '1', 'page' => '3']);
$CR_fc = (string) ob_get_clean();
t_ok(str_contains($CR_fc, 'Room: <strong>Hall</strong>'), 'a chosen filter says what it is set to');
t_ok(str_contains($CR_fc, 'href="?v=timetable&amp;room=3&amp;w=1"'),
    'each choice is a link keeping the other filters and dropping the page number (a new filter starts at page one)');
t_ok(str_contains($CR_fc, 'href="?v=timetable&amp;w=1">Clear room'), '…and the chosen one can be cleared, keeping the rest');
ob_start(); ac_facet('timetable', 'room', 'Room', ['3' => 'Studio A'], []);
t_ok(str_contains((string) ob_get_clean(), '+</span> Room'), 'an unset filter reads "+ Room"');
