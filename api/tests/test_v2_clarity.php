<?php
/**
 * Layer H read model — no user is shown a word nobody wrote.
 *
 * The load-bearing test here is the COVERAGE one: it parses the CHECK
 * constraints out of db/schema-v2.sql, and asserts that every value the
 * database will accept has wording for every audience that reads it. That is
 * what makes it impossible to add a status and forget the copy — the exact
 * failure that put `carried_forward` in front of a fourteen-year-old in v1.
 *
 * Everything else here exists because each was a real v1 defect: money summed
 * in the wrong currency, a Lagos class time shown unqualified to a guardian in
 * London, a nightly sweep stacking duplicate to-dos, and quiet hours that
 * suppressed the one message that mattered.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/v2/clarity.php';
require_once dirname(__DIR__) . '/tools/schema-v2-sqlite.php';

/* ── a v2 database in memory ──────────────────────────────────────────────── */

$cdb = new PDO('sqlite::memory:');
$cdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ddl = (string) preg_replace('~^\s*--.*$~m', '', (string) file_get_contents(dirname(__DIR__, 2) . '/db/schema-v2-sqlite.sql'));
foreach (array_filter(array_map('trim', explode(';', $ddl))) as $stmt) {
    try { $cdb->exec($stmt); } catch (Throwable $e) { /* asserted in test_schema_v2 */ }
}
$cdb->exec('PRAGMA foreign_keys = ON');

t_group('v2 clarity — every status a person can reach has been written');

$catalogue = mam_v2_status_catalogue();
$schemaSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/db/schema-v2.sql');

// Pull each vocabulary straight out of the schema's CHECK constraints, so this
// compares the catalogue against what the DATABASE actually accepts rather than
// against a second list somebody maintains by hand.
$checkFor = function (string $constraint) use ($schemaSrc): array {
    if (!preg_match('/CONSTRAINT ' . preg_quote($constraint, '/') . ' CHECK \((?:status|stage|delivery_mode) IN\s*\((.*?)\)\s*\)/s', $schemaSrc, $m)) {
        return [];
    }
    preg_match_all("/'([a-z_]+)'/", $m[1], $v);
    return $v[1];
};
$schemaVocab = [
    'membership_stage'      => $checkFor('chk_membership_stage'),
    'application'           => $checkFor('chk_app_status'),
    'enrolment'             => $checkFor('chk_enrol_status'),
    'class_session'         => $checkFor('chk_session_status'),
    'attendance'            => $checkFor('chk_attendance_status'),
    'module_progress'       => $checkFor('chk_progress_status'),
    'invoice'               => $checkFor('chk_inv_status'),
    'payment'               => $checkFor('chk_pay_status'),
    'accountability_charge' => $checkFor('chk_charge_status'),
];

foreach ($schemaVocab as $entity => $fromSchema) {
    t_ok(count($fromSchema) > 0, "$entity's vocabulary is constrained in the schema, not just documented");
    $fromCatalogue = array_keys($catalogue[$entity] ?? []);
    sort($fromSchema); sort($fromCatalogue);
    t_eq(implode(',', $fromCatalogue), implode(',', $fromSchema),
        "$entity: the catalogue and the database agree on which values exist");
}

// Every learner-facing status must be worded for a learner AND a guardian.
// Deriving guardian copy from learner copy is not an option: "You have more
// time on this module" shown to a parent is wrong about who is being addressed.
$unworded = [];
foreach (MAM_V2_LEARNER_FACING as $entity) {
    foreach ($catalogue[$entity] ?? [] as $status => $def) {
        foreach (['learner', 'guardian', 'staff'] as $audience) {
            $w = mam_v2_catalogue_words($def, $audience);
            if ($w === null || trim((string) $w[0]) === '' || trim((string) $w[1]) === '') {
                $unworded[] = "$entity.$status/$audience";
            }
        }
    }
}
t_eq(count($unworded), 0, 'every learner-facing status is worded for learner, guardian AND staff'
    . ($unworded ? ': ' . implode(', ', array_slice($unworded, 0, 8)) : ''));

// Wording quality, checked mechanically because prose rots quietly.
// System vocabulary a learner or guardian has no way to decode. Adding a status
// to this list is a statement that it needs translating before anyone sees it.
const MAM_V2_TEST_OPAQUE = [
    'membership_stage.prospect', 'membership_stage.admitted', 'membership_stage.alumnus',
    'application.under_review', 'application.interview_scheduled', 'application.waitlisted',
    'enrolment.pending', 'enrolment.transferred', 'enrolment.waitlisted',
    'class_session.scheduled', 'class_session.held', 'class_session.rescheduled',
    'attendance.absent', 'attendance.technical_failure',
    'attendance.rescheduled',
    'module_progress.preparation_pending', 'module_progress.under_assessment',
    'module_progress.developing', 'module_progress.proficient', 'module_progress.advanced',
    'module_progress.carried_forward', 'module_progress.remedial', 'module_progress.exempted',
    'invoice.issued', 'invoice.part_paid', 'invoice.void', 'invoice.written_off',
    'payment.succeeded', 'payment.failed', 'payment.partially_refunded', 'payment.disputed',
    'accountability_charge.outstanding', 'accountability_charge.waived',
    'accountability_charge.disputed', 'accountability_charge.reversed',
];

$problems = [];
foreach ($catalogue as $entity => $statuses) {
    foreach ($statuses as $status => $def) {
        t_ok(in_array($def['tone'], MAM_V2_TONES, true), "$entity.$status uses a tone from the shared palette");
        foreach (['learner', 'guardian'] as $aud) {
            $w = mam_v2_catalogue_words($def, $aud);
            if ($w === null) continue;
            [$label, $meaning] = $w;
            // These are the values a learner cannot read cold — internal
            // vocabulary, not English. Each must have been rewritten, proven by
            // the label differing from the raw term. (Statuses like `paid` or
            // `paused` are absent deliberately: they are already the words a
            // person would use, and forcing a synonym would be worse.)
            if (in_array("$entity.$status", MAM_V2_TEST_OPAQUE, true)
                && strcasecmp(str_replace('_', ' ', $status), $label) === 0) {
                $problems[] = "$entity.$status/$aud: still shows the internal term";
            }
            if (strlen($label) > 40)  $problems[] = "$entity.$status/$aud: label too long for a chip";
            if (substr_count($meaning, '. ') > 1) $problems[] = "$entity.$status/$aud: meaning is more than one sentence";
            if (preg_match('/\b(null|enum|foreign key|cohort_id|status)\b/i', $meaning)) {
                $problems[] = "$entity.$status/$aud: meaning leaks system vocabulary";
            }
        }
    }
}
t_eq(count($problems), 0, 'no learner/guardian wording is a raw value, over-long, multi-sentence or jargon'
    . ($problems ? ': ' . implode(' | ', array_slice($problems, 0, 5)) : ''));

t_group('v2 clarity — resolving a status for a reader');

$seeded = mam_v2_status_seed($cdb);
t_ok($seeded > 250, "the platform glossary seeds ($seeded rows across four audiences)");

$before = (int) $cdb->query('SELECT COUNT(*) FROM status_definitions')->fetchColumn();
mam_v2_status_seed($cdb);
t_eq((int) $cdb->query('SELECT COUNT(*) FROM status_definitions')->fetchColumn(), $before,
    're-seeding updates in place — a copy fix ships as a re-seed, not a migration');

$l = mam_v2_status($cdb, 'module_progress', 'carried_forward', 'learner');
$g = mam_v2_status($cdb, 'module_progress', 'carried_forward', 'guardian');
t_ok($l['meaning'] !== $g['meaning'], 'a learner and their guardian are told the same fact in different words');
t_ok(stripos($l['meaning'], 'you') !== false,   '…the learner is addressed directly');
t_ok(preg_match('/\b(they|their|them)\b/i', $g['meaning']) === 1,
    '…the guardian is told about someone else');
t_ok(preg_match('/\byou(r)?\b/i', $g['meaning']) !== 1,
    '…and is never addressed as if the work were theirs');
t_eq($l['tone'], 'progress', 'and "more time given" is progress, not a failure');

// Every audience resolves. `admin` has no copy of its own and must inherit
// staff's rather than falling through to a synthesised label.
foreach (MAM_V2_AUDIENCES as $aud) {
    $r = mam_v2_status($cdb, 'invoice', 'part_paid', $aud);
    t_ok($r['resolved'] !== 'undefined', "an $aud reading invoice.part_paid gets real wording");
}

// NO status in the whole catalogue may fall through to the synthesised label.
$fellThrough = [];
foreach ($catalogue as $entity => $statuses) {
    foreach (array_keys($statuses) as $status) {
        foreach (MAM_V2_AUDIENCES as $aud) {
            if (mam_v2_status($cdb, $entity, $status, $aud)['resolved'] === 'undefined') {
                $fellThrough[] = "$entity.$status/$aud";
            }
        }
    }
}
t_eq(count($fellThrough), 0, 'nothing in the catalogue reaches the raw-value fallback'
    . ($fellThrough ? ': ' . implode(', ', array_slice($fellThrough, 0, 5)) : ''));

// A tenant may reword without forking the vocabulary.
$cdb->exec("INSERT INTO tenants (id,public_id,slug,legal_name,display_name) VALUES (1,'01JT0000000000000000000001','mam','MAM Centre','MAM Academy')");
$cdb->exec("INSERT INTO status_definitions (tenant_id,tenant_scope,entity_type,status,audience,locale,label,meaning,tone)
            VALUES (1,1,'module_progress','carried_forward','learner','en','Still working on it','This one carries into next week.','progress')");
t_eq(mam_v2_status($cdb, 'module_progress', 'carried_forward', 'learner', 1)['label'], 'Still working on it',
    'a tenant override wins for that tenant');
t_eq(mam_v2_status($cdb, 'module_progress', 'carried_forward', 'learner', 2)['label'], 'More time given',
    '…and does not leak to another tenant');

// The unknown path must be honest rather than inventive.
$u = mam_v2_status($cdb, 'invoice', 'exploded', 'learner');
t_eq($u['resolved'], 'undefined', 'an unworded status is reported as undefined');
t_eq($u['meaning'], '', '…and no meaning is invented for it (guessing tells someone the wrong thing)');
t_eq($u['label'], 'Exploded', '…but it still renders, so the portal does not 500');
t_eq(mam_v2_status($cdb, 'invoice', null, 'learner')['label'], 'Not set', 'a missing status renders too');

// The journey track is ordered, which is what a progress bar needs.
$track = mam_v2_status_track($cdb, 'application', 'learner');
t_eq(count($track), 9, 'the application track lists every stage');
t_eq($track[0]['status'], 'draft', '…starting at the beginning');
t_ok($track[count($track) - 1]['sort'] > $track[0]['sort'], '…in journey order');

t_group('v2 clarity — "what do I need to do?"');

$cdb->exec("INSERT INTO users (id,public_id,email,full_name) VALUES (1,'01JU0000000000000000000001','ada@example.test','Ada Obi')");
$cdb->exec("INSERT INTO memberships (id,public_id,tenant_id,user_id,reference,stage) VALUES (1,'01JM0000000000000000000001',1,1,'MAM-0001','active')");

mam_v2_action_upsert($cdb, 1, 1, 'invoice:1', [
    'kind' => 'pay_invoice', 'title' => 'Pay your September tuition',
    'detail' => 'Your place is held until 30 September.',
    'action_url' => '/portal/payments', 'action_label' => 'Pay ₦120,000',
    'priority' => 10, 'due_at' => '2020-09-30 23:59:00.000']);
mam_v2_action_upsert($cdb, 1, 1, 'consent:recital', [
    'kind' => 'sign_consent', 'title' => 'Confirm photo permissions', 'priority' => 50]);
mam_v2_action_upsert($cdb, 1, 1, 'safeguard:policy', [
    'kind' => 'acknowledge', 'title' => 'Read the new safeguarding policy',
    'priority' => 90, 'is_blocking' => true]);

// The sweep runs again. It must refresh, not stack.
mam_v2_action_upsert($cdb, 1, 1, 'invoice:1', [
    'kind' => 'pay_invoice', 'title' => 'Pay your September tuition',
    'action_label' => 'Pay ₦130,000', 'priority' => 10, 'due_at' => '2020-09-30 23:59:00.000']);

$items = mam_v2_action_items($cdb, 1);
t_eq(count($items), 3, 'a repeated sweep refreshes the item instead of stacking a duplicate');
t_eq((string) $items[0]['title'], 'Read the new safeguarding policy', 'a blocking item is read first');
t_eq((string) $items[1]['title'], 'Pay your September tuition', '…then by priority');
t_eq((string) $items[1]['action_label'], 'Pay ₦130,000', 'and the refreshed amount is what the button says');

// An undated item must not jump ahead of an overdue one. SQLite sorts NULLs
// first and MySQL sorts them last, so this is a cross-engine correctness test,
// not a cosmetic one.
$due = array_search('Pay your September tuition', array_column($items, 'title'), true);
$non = array_search('Confirm photo permissions', array_column($items, 'title'), true);
t_ok($due < $non, 'a dated item sorts before an undated one on both engines');

$sum = mam_v2_action_summary($cdb, 1);
t_eq($sum['total'], 3, 'the portal can say "you have 3 things to do"');
t_eq($sum['blocking'], 1, '…one of which is blocking');
t_eq($sum['overdue'], 1, '…and one of which is overdue');
t_ok($items[1]['is_overdue'] === true, 'an item past its due date is flagged overdue');
t_ok($items[1]['due_in_days'] < 0, '…with a negative countdown rather than a silent zero');

t_ok(mam_v2_action_resolve($cdb, 1, 1, 'invoice:1'), 'an obligation can be marked met');
t_eq(count(mam_v2_action_items($cdb, 1)), 2, '…and leaves the open list');
$again = mam_v2_action_upsert($cdb, 1, 1, 'invoice:1', ['kind' => 'pay_invoice', 'title' => 'Pay your October tuition']);
t_ok($again > 0, 'and the same obligation recurs next term (a term fee is not a one-off)');
t_eq(count(mam_v2_action_items($cdb, 1)), 3, '…as a new open item');

t_ok(mam_v2_action_resolve($cdb, 1, 1, 'consent:recital', 'dismissed'), 'an optional item can be set aside');
$dismissed = $cdb->query("SELECT completed_at, dismissed_at FROM action_items WHERE dedupe_key='consent:recital'")->fetch(PDO::FETCH_ASSOC);
t_ok($dismissed['completed_at'] === null && $dismissed['dismissed_at'] !== null,
    '…recorded as set aside, NOT as done — the thing was not actually done');

t_group('v2 clarity — speaking to someone the way they need');

$prefs = mam_v2_preferences($cdb, 1, ['default_locale' => 'en', 'default_timezone' => 'Africa/Lagos', 'base_currency' => 'USD']);
t_eq($prefs['text_scale_pct'], 100, 'a user with no stored row still gets a complete preference set');
t_eq($prefs['display_currency'], 'USD', '…defaulting to the tenant, not to nothing');

$cdb->exec("INSERT INTO user_preferences (user_id,locale,display_currency,text_scale_pct,dyslexia_font,reduce_motion,quiet_hours_start,quiet_hours_end,channel_sms)
            VALUES (1,'yo','NGN',150,1,1,'22:00:00','07:00:00',1)");
$prefs = mam_v2_preferences($cdb, 1, ['base_currency' => 'USD']);
t_eq($prefs['display_currency'], 'NGN', 'a stored preference overrides the tenant default');
t_eq($prefs['text_scale_pct'], 150, '…including text scale, so email honours it too');
t_ok($prefs['dyslexia_font'] && $prefs['reduce_motion'], '…and the accessibility flags survive as booleans');

t_ok(!mam_v2_may_deliver($prefs, 'whatsapp'), 'a channel the reader did not opt into is never used');
t_ok(mam_v2_may_deliver($prefs, 'sms', 'info', '10:00:00'), 'an opted-in channel works in waking hours');
t_ok(!mam_v2_may_deliver($prefs, 'sms', 'info', '23:30:00'), 'quiet hours are honoured across midnight');
t_ok(!mam_v2_may_deliver($prefs, 'sms', 'info', '03:00:00'), '…on the far side of midnight too');
t_ok(mam_v2_may_deliver($prefs, 'sms', 'critical', '03:00:00'),
    'but a critical message is never held — a preference system that cannot reach a parent gets switched off');

t_group('v2 clarity — money and time as the reader reads them');

t_eq(mam_v2_money(12000000, 'NGN', 2, '₦'), '₦120,000.00', 'naira renders with its own symbol and separators');
t_eq(mam_v2_money(7500, 'USD', 2, '$'), '$75.00', 'and dollars alongside it');
// The exponent is NOT a hardcoded 100. A platform that assumes two decimals
// prints ¥1,200 as ¥12.00, which is off by a factor of a hundred.
t_eq(mam_v2_money(1200, 'JPY', 0, '¥'), '¥1,200', 'a zero-exponent currency does not gain a fake decimal');
t_eq(mam_v2_money(1234567, 'KWD', 3, null), 'KWD 1,234.567', 'a three-exponent currency keeps all three');
t_eq(mam_v2_money(-7500, 'USD', 2, '$'), '-$75.00', 'a refund reads as negative, not as a credit surprise');
t_eq(mam_v2_money(0, 'USD', 2, '$'), '$0.00', 'and zero renders rather than vanishing');
t_eq(mam_v2_currency($cdb, 'JPY')['exponent'], 0, 'the exponent comes from the currencies table');
t_eq(mam_v2_currency($cdb, 'ZZZ')['exponent'], 2, '…with a safe default for an unknown code');

// A guardian in London reading a Lagos class time is the case that matters:
// unqualified, they turn up an hour late.
// NOTE: Europe/London would be a useless fixture here — in September it is on
// BST (UTC+1), the same clock as Lagos, so "no second time shown" would pass
// for the wrong reason. New York is genuinely different.
$w = mam_v2_when('2026-09-10 16:00:00', 'Africa/Lagos', 'America/New_York');
t_ok(str_contains($w['text'], '16:00'), 'a class shows its own local wall clock first');
t_ok($w['also'] !== null && str_contains($w['also'], 'your time'), '…and the viewer\'s time beside it when they differ');
t_eq(mam_v2_when('2026-09-10 16:00:00', 'Africa/Lagos', 'Africa/Lagos')['also'], null,
    'a viewer in the same zone is not shown a redundant second time');
t_eq(mam_v2_when('2026-09-10 16:00:00', 'Africa/Lagos', 'Europe/London')['also'], null,
    '…nor one in a different zone that happens to read the same clock (Lagos/BST in September)');
t_eq(mam_v2_when('2026-09-10 16:00:00', 'Africa/Lagos', 'Not/AZone')['also'], null,
    'an unknown viewer zone degrades to the class time rather than throwing');
t_eq(mam_v2_when('2026-09-10 16:00:00', 'Africa/Lagos')['utc'], '2026-09-10 15:00:00',
    'and the UTC instant is derived, not stored twice');

t_group('v2 clarity — ULIDs');

$a = mam_v2_ulid(1755300000000);
t_eq(strlen($a), 26, 'a ULID is 26 characters');
t_ok(preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $a) === 1, '…in Crockford base32 (no I, L, O or U to misread)');
// Time-ordered: two ULIDs a second apart must sort in that order as strings,
// which is what makes them usable as a cursor.
t_ok(mam_v2_ulid(1755300000000) < mam_v2_ulid(1755300001000), 'ULIDs sort by time as plain strings');
t_ok(mam_v2_ulid(1755300000000) !== mam_v2_ulid(1755300000000), '…while staying unique within a millisecond');
