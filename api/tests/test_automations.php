<?php
/** Lifecycle automations — registry, persisted toggles, gating + telemetry. */
declare(strict_types=1);
t_group('lifecycle automations');

$reg = mam_automations_registry();
t_ok(count($reg) === 8, 'the eight lifecycle rules are registered');
t_ok(isset($reg['payment_provision']['trigger'], $reg['payment_provision']['action']), 'a rule carries a trigger + action');

// ── Defaults: all rules ship enabled, so the active count starts full ──
t_ok(mam_automation_on('payment_provision'), 'payment_provision defaults on');
t_eq(mam_automations_active_count(), 8, 'all eight active by default');
t_ok(!mam_automation_on('does_not_exist'), 'an unknown rule is never "on"');

// ── Toggle: persisted + reflected by the gate + the count ──
mam_automation_set('payment_provision', false);
t_ok(!mam_automation_on('payment_provision'), 'disabling a rule is honoured by the gate');
t_eq(mam_automations_active_count(), 7, 'the active count drops when a rule is off');
$row = mam_db_row('SELECT enabled FROM automations WHERE akey = ?', ['payment_provision']);
t_eq((int)$row['enabled'], 0, 'the flag is persisted to the automations table');

mam_automation_set('payment_provision', true);
t_ok(mam_automation_on('payment_provision'), 're-enabling a rule is honoured');

// ── Unknown keys can't create junk rows (a crafted POST is inert) ──
mam_automation_set('__nope__', true);
t_ok(!mam_db_row('SELECT akey FROM automations WHERE akey = ?', ['__nope__']), 'set() ignores unknown rules');

// ── Telemetry: touch records a run (best-effort, never fatal) ──
mam_automation_touch('payment_provision');
$row = mam_db_row('SELECT run_count, last_run_at FROM automations WHERE akey = ?', ['payment_provision']);
t_ok((int)$row['run_count'] >= 1, 'touch increments the run count');
t_ok(!empty($row['last_run_at']), 'touch stamps the last-run time');

// ── The decorated list drives the admin screen ──
$list = mam_automations_list();
t_ok(count($list) === 8 && $list['payment_provision']['enabled'] === true, 'the admin list reflects live state');

// ── Live gate: a disabled payment_provision skips portal provisioning ──
// mam_payment_settle_tuition returns a distinct tag when the automation is off,
// without provisioning. Build a minimal paid-application fixture.
$cid = mam_db_insert('customers', ['email' => 'auto-gate@example.com', 'first_name' => 'Auto', 'last_name' => 'Gate']);
$aid = mam_db_insert('applications', ['reference' => 'MAM-AUTO-1', 'customer_id' => $cid, 'pathway' => 'metamusic', 'status' => 'submitted', 'total_usd' => 100]);
mam_automation_set('payment_provision', false);
t_eq(mam_payment_settle_tuition($aid), 'portal_provision_automation_off', 'disabled automation skips provisioning');
t_ok(!mam_db_row("SELECT id FROM users WHERE email = ?", ['auto-gate@example.com']), 'no student user was provisioned while off');
t_eq(mam_db_row('SELECT status FROM applications WHERE id = ?', [$aid])['status'], 'paid', 'the application is still marked paid');
mam_automation_set('payment_provision', true); // restore for any later tests

// ── session_reminder sweep: emails a cohort's students once per session ──
$coId = mam_db_insert('cohorts', ['title' => 'Reminder Cohort']);
$sess = mam_db_insert('class_sessions', ['cohort_id' => $coId, 'title' => 'Strings Lab', 'scheduled_at' => date('Y-m-d H:i:s', time() + 7200), 'status' => 'scheduled']);
$scid = mam_db_insert('customers', ['email' => 'rem-stu@example.com', 'first_name' => 'Remi', 'last_name' => 'Nder']);
$suid = mam_db_insert('users', ['email' => 'rem-stu@example.com', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Remi Nder', 'status' => 'active', 'customer_id' => $scid]);
mam_db_insert('course_enrollments', ['student_id' => $suid, 'course_id' => 1, 'cohort_id' => $coId, 'status' => 'active']);
t_eq(mam_automation_session_reminder_sweep(24), 1, 'a session in the next 24h is reminded');
t_eq(mam_automation_session_reminder_sweep(24), 0, 'the same session is not reminded twice (deduped)');
t_ok(mam_db_row("SELECT id FROM events WHERE type = 'session.reminded' AND entity_id = ?", [$sess]) !== null, 'a reminder event is recorded for the session');

// A far-future session is left alone.
mam_db_insert('class_sessions', ['cohort_id' => $coId, 'title' => 'Next month', 'scheduled_at' => date('Y-m-d H:i:s', time() + 40 * 86400), 'status' => 'scheduled']);
t_eq(mam_automation_session_reminder_sweep(24), 0, 'a session beyond the window is not reminded');

// Disabled → no work even with a due session.
mam_db_insert('class_sessions', ['cohort_id' => $coId, 'title' => 'Soon', 'scheduled_at' => date('Y-m-d H:i:s', time() + 3600), 'status' => 'scheduled']);
mam_automation_set('session_reminder', false);
t_eq(mam_automation_session_reminder_sweep(24), 0, 'a paused session_reminder does no work');
mam_automation_set('session_reminder', true);

// ── ktams_birthday sweep: a KTAMS student with a birthday today, once/year ──
$bday = date('1998-m-d');
$bcid = mam_db_insert('customers', ['email' => 'bday-kid@example.com', 'first_name' => 'Bibi', 'last_name' => 'Day', 'dob' => $bday]);
$buid = mam_db_insert('users', ['email' => 'bday-kid@example.com', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Bibi Day', 'status' => 'active', 'customer_id' => $bcid]);
mam_db_insert('applications', ['reference' => 'MAM-BDAY-1', 'customer_id' => $bcid, 'pathway' => 'metamusic', 'status' => 'enrolled', 'program_track' => 'KTAMS']);
t_eq(mam_automation_ktams_birthday_sweep(), 1, "today's KTAMS birthday gets a note");
t_eq(mam_automation_ktams_birthday_sweep(), 0, 'the birthday note is not re-sent the same year (deduped)');

// A non-KTAMS student with a birthday today is not swept by this rule.
$ncid = mam_db_insert('customers', ['email' => 'adult@example.com', 'first_name' => 'Ada', 'last_name' => 'Adult', 'dob' => $bday]);
$nuid = mam_db_insert('users', ['email' => 'adult@example.com', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Ada Adult', 'status' => 'active', 'customer_id' => $ncid]);
mam_db_insert('applications', ['reference' => 'MAM-BDAY-2', 'customer_id' => $ncid, 'pathway' => 'metamusic', 'status' => 'enrolled', 'program_track' => 'MAM']);
t_eq(mam_automation_ktams_birthday_sweep(), 0, 'a non-KTAMS birthday is not swept by ktams_birthday');
