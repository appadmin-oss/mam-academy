<?php
/**
 * ID card expiry (schema v59).
 *
 * Before this, the 'EXP.12/YY' on the face was derived from the issue year and
 * enforced NOWHERE: a lapsed card kept scanning into attendance forever, and a
 * summer badge — meant to die a couple of months after the camp — never did.
 * These tests pin the three things that make the date real: it is computed from
 * the admin's validity period, it is stamped on the row at mint, and everything
 * downstream (face, mint, sweep) agrees about when a card is dead.
 */
declare(strict_types=1);
t_group('id card expiry (v59)');

require_once dirname(MAM_API_ROOT) . '/partials/id-card-face.php';

/* ── The column exists and is indexed ────────────────────────────────────── */
$cols = [];
foreach (mam_db_all("PRAGMA table_info(id_cards)") as $c) $cols[] = $c['name'];
t_ok(in_array('expires_at', $cols, true), 'id_cards has an expires_at column');

/* ── Validity periods are admin-defined, per card kind ───────────────────── */
mam_setting_set('card_validity_months', '');
mam_setting_set('summer_card_validity_months', '');
t_eq(mam_card_validity_months(false), 12, 'a student card defaults to 12 months');
t_eq(mam_card_validity_months(true), 2, 'a SUMMER card defaults to 2 months');
mam_setting_set('summer_card_validity_months', '3');
t_eq(mam_card_validity_months(true), 3, 'the admin can change the summer validity');
mam_setting_set('summer_card_validity_months', '0');
t_eq(mam_card_validity_months(true), 2, 'zero months falls back to the default rather than minting a dead card');
mam_setting_set('summer_card_validity_months', '9999');
t_eq(mam_card_validity_months(true), 120, 'an absurd value is capped, not honoured');
mam_setting_set('summer_card_validity_months', '2');

/* ── Date arithmetic, including the month-end trap ───────────────────────── */
t_eq(substr(mam_card_expiry_at(true, '2026-08-06 10:00:00'), 0, 10), '2026-10-06', 'summer: +2 months');
t_eq(substr(mam_card_expiry_at(false, '2026-08-06 10:00:00'), 0, 10), '2027-08-06', 'student: +12 months, crossing the year');
// The classic bug: strtotime('+1 month') on the 31st lands in the month AFTER next.
mam_setting_set('summer_card_validity_months', '1');
t_eq(substr(mam_card_expiry_at(true, '2026-01-31 09:00:00'), 0, 10), '2026-02-28', 'Jan 31 + 1 month clamps to Feb 28, not Mar 3');
t_eq(substr(mam_card_expiry_at(true, '2024-01-31 09:00:00'), 0, 10), '2024-02-29', 'and to Feb 29 in a leap year');
t_eq(substr(mam_card_expiry_at(true, '2026-12-15 09:00:00'), 0, 10), '2027-01-15', 'December rolls into the next year');
mam_setting_set('summer_card_validity_months', '2');
// The time of day is preserved, so a card issued at 14:30 dies at 14:30.
t_eq(substr(mam_card_expiry_at(true, '2026-08-06 14:30:45'), 11), '14:30:45', 'the time of day is carried over');

/* ── Expiry predicates ───────────────────────────────────────────────────── */
t_eq(mam_card_is_expired(['expires_at' => '2020-01-01 00:00:00']), true, 'a past date is expired');
t_eq(mam_card_is_expired(['expires_at' => '2099-01-01 00:00:00']), false, 'a future date is not expired');
// A NULL must NOT read as expired — that would lock real students out of sessions
// on the strength of missing data.
t_eq(mam_card_is_expired(['expires_at' => null]), false, 'a NULL expiry is treated as not expired');
t_eq(mam_card_is_expired([]), false, 'a missing expiry key is treated as not expired');
t_eq(mam_card_is_expired(['expires_at' => '2026-08-10 00:00:00'], '2026-08-09 23:59:59'), false, 'not yet expired one second before');
t_eq(mam_card_is_expired(['expires_at' => '2026-08-10 00:00:00'], '2026-08-10 00:00:01'), true, 'expired one second after');

t_eq(mam_card_days_to_expiry(['expires_at' => '2026-08-16 12:00:00'], '2026-08-06 12:00:00'), 10, 'ten days out');
t_ok(mam_card_days_to_expiry(['expires_at' => '2026-08-01 12:00:00'], '2026-08-06 12:00:00') < 0, 'negative once lapsed');
t_eq(mam_card_days_to_expiry(['expires_at' => null]), null, 'no expiry yields null days');

/* ── What the face prints, and what it voids ─────────────────────────────── */
t_eq(mam_card_exp_display(['expires_at' => '2026-10-06 00:00:00']), 'EXP.10/26', 'the printed expiry comes from the real date');
t_eq(mam_card_exp_display(['expires_at' => '', 'issued_at' => '2026-03-01 00:00:00']), 'EXP.12/26',
    'a pre-v59 row still prints via the old derived label');
t_eq(mam_card_state(['status' => 'active', 'expires_at' => '2099-01-01 00:00:00']), 'active', 'live card reads active');
t_eq(mam_card_state(['status' => 'active', 'expires_at' => '2020-01-01 00:00:00']), 'expired', 'lapsed card reads expired, not active');
t_eq(mam_card_state(['status' => 'revoked', 'expires_at' => '2020-01-01 00:00:00']), 'revoked', 'revoked wins over expired');

// An expired card must not LOOK valid at a door.
$faceLive = mam_card_face_html(['holder_name' => 'A B', 'status' => 'active', 'expires_at' => '2099-01-01 00:00:00']);
$faceDead = mam_card_face_html(['holder_name' => 'A B', 'status' => 'active', 'expires_at' => '2020-01-01 00:00:00']);
t_ok(strpos($faceLive, 'mam-card--revoked') === false, 'a live card carries no void overlay');
t_ok(strpos($faceDead, 'mam-card--revoked') !== false, 'an EXPIRED card carries the void overlay');
t_ok(strpos($faceDead, 'mam-card--expired') !== false, 'and is marked expired so the overlay reads EXPIRED, not VOID');
t_ok(strpos($faceLive, 'EXP.01/99') !== false, 'the face prints the real expiry date');

/* ── Mint stamps the expiry, per kind ────────────────────────────────────── */
$stu = mam_db_insert('users', ['email' => 't_exp_stu@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Exp Stu', 'status' => 'active']);
$cardStu = mam_card_mint($stu, null, 'student', 'AY2026/27');
t_ok(!empty($cardStu['expires_at']), 'a minted student card carries an expiry');
$monthsOut = (int)round((strtotime((string)$cardStu['expires_at']) - strtotime((string)$cardStu['issued_at'])) / 2629800);
t_eq($monthsOut, 12, 'the student card expires ~12 months out');

$sumApp = mam_db_insert('summer_applications', [
    'reference' => 'SUM-EXP-1', 'first_name' => 'Sum', 'last_name' => 'Exp',
    'email' => 't_exp_sum@x.dev', 'dob' => '2014-01-01', 'age_at_submission' => 12,
    'guardian_name' => 'G', 'guardian_phone' => '+234', 'policy_agreed' => 1,
    'policy_signed_name' => 'G', 'session_year' => 2026, 'status' => 'confirmed',
]);
$cardSum = mam_card_mint(null, $sumApp, 'student', 'SUMMER-2026');
$sumMonths = (int)round((strtotime((string)$cardSum['expires_at']) - strtotime((string)$cardSum['issued_at'])) / 2629800);
t_eq($sumMonths, 2, 'the SUMMER card expires ~2 months out — the whole point');
t_ok(strtotime((string)$cardSum['expires_at']) < strtotime((string)$cardStu['expires_at']),
    'a summer card dies well before a student card');

/* ── Minting for someone whose card has lapsed issues a FRESH card ───────── */
// A returning summer student must not be handed last year's dead badge.
mam_db_update('id_cards', ['expires_at' => '2020-01-01 00:00:00'], ['id' => (int)$cardSum['id']]);
$reMint = mam_card_mint(null, $sumApp, 'student', 'SUMMER-2027');
t_ok((int)$reMint['id'] !== (int)$cardSum['id'], 'a lapsed card is not returned by the idempotent mint');
t_ok((string)$reMint['serial'] !== (string)$cardSum['serial'], 'the replacement has its own serial');
t_eq(mam_db_row('SELECT status FROM id_cards WHERE id = ?', [(int)$cardSum['id']])['status'], 'revoked',
    'the lapsed card is retired rather than left active');
t_ok(!mam_card_is_expired($reMint), 'the replacement is live');
// And an UNexpired card is still returned as-is (idempotency preserved).
$again = mam_card_mint(null, $sumApp, 'student', 'SUMMER-2027');
t_eq((int)$again['id'], (int)$reMint['id'], 'an unexpired card is still returned unchanged');

/* ── The sweep: warn once, then retire ───────────────────────────────────── */
mam_automation_set('card_expiry', true);
mam_setting_set('card_expiry_warn_days', '14');

// A card expiring inside the warning window, owned by a real user so it notifies.
$warnUser = mam_db_insert('users', ['email' => 't_exp_warn@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Warn Me', 'status' => 'active']);
$warnCard = mam_card_mint($warnUser, null, 'student', 'AY2026/27');
mam_db_update('id_cards', ['expires_at' => date('Y-m-d H:i:s', time() + 5 * 86400)], ['id' => (int)$warnCard['id']]);

$before = (int)mam_db_row('SELECT COUNT(*) AS n FROM notifications WHERE user_id = ?', [$warnUser])['n'];
$r1 = mam_automation_card_expiry_sweep();
t_ok($r1['warned'] >= 1, 'the sweep warns a card inside the window');
$after = (int)mam_db_row('SELECT COUNT(*) AS n FROM notifications WHERE user_id = ?', [$warnUser])['n'];
t_eq($after, $before + 1, 'the holder gets exactly one in-app notice');
t_ok(mam_db_row("SELECT id FROM events WHERE type = 'card.expiring' AND entity_id = ?", [(int)$warnCard['id']]) !== null,
    'the warning is recorded as an event');

// Re-running must NOT re-notify — a cron runs this every few minutes.
$r2 = mam_automation_card_expiry_sweep();
$after2 = (int)mam_db_row('SELECT COUNT(*) AS n FROM notifications WHERE user_id = ?', [$warnUser])['n'];
t_eq($after2, $after, 'a second sweep does not warn the same card again (idempotent)');

// A lapsed card gets retired to status='expired' — not 'revoked'.
$deadUser = mam_db_insert('users', ['email' => 't_exp_dead@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Dead Card', 'status' => 'active']);
$deadCard = mam_card_mint($deadUser, null, 'student', 'AY2026/27');
mam_db_update('id_cards', ['expires_at' => date('Y-m-d H:i:s', time() - 86400)], ['id' => (int)$deadCard['id']]);
$r3 = mam_automation_card_expiry_sweep();
t_ok($r3['retired'] >= 1, 'the sweep retires a lapsed card');
$deadNow = mam_db_row('SELECT status, revoke_reason FROM id_cards WHERE id = ?', [(int)$deadCard['id']]);
t_eq($deadNow['status'], 'expired', "a lapsed card becomes 'expired', distinct from a revoked one");
t_eq($deadNow['revoke_reason'], 'expired', 'and records why');

// Switched off, the sweep does nothing at all.
$offUser = mam_db_insert('users', ['email' => 't_exp_off@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Off', 'status' => 'active']);
$offCard = mam_card_mint($offUser, null, 'student', 'AY2026/27');
mam_db_update('id_cards', ['expires_at' => date('Y-m-d H:i:s', time() - 86400)], ['id' => (int)$offCard['id']]);
mam_automation_set('card_expiry', false);
$r4 = mam_automation_card_expiry_sweep();
t_eq($r4['warned'] + $r4['retired'], 0, 'a disabled card_expiry rule does no work');
t_eq(mam_db_row('SELECT status FROM id_cards WHERE id = ?', [(int)$offCard['id']])['status'], 'active',
    'and leaves the lapsed card untouched');
mam_automation_set('card_expiry', true);
