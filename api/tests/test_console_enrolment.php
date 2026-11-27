<?php
/**
 * Classes & enrolment from the console (api/modules/academics/lib/cohort-admin.php, the console's
 * Enrol & staffing screen). Each group pins a rule the screen promises in words:
 *
 *   enrol      — into THIS cohort (not the one the course tuple would pick)
 *   full       — a full cohort queues the student; it is never over-filled
 *   duplicate  — level-aware refusal, in words that name the existing class
 *   transfer   — one step, the old enrolment withdrawn, the outcome stated,
 *                refused onto a full cohort, and atomic
 *   withdraw   — needs a reason, records the calendar outcome, frees the seat
 *   instructor — the gate's verdict is shown before saving, without writing an
 *                audit row, and the save itself is refused in enforce mode
 *   archive    — refused while anybody is enrolled or waiting
 *   console    — the provider's new branches read without a schema error
 */
declare(strict_types=1);
t_group('console enrolment — enrol into a chosen cohort');

$CE_piano = (int) mam_db_row("SELECT id FROM courses WHERE code = 'piano'")['id'];
$CE_voice = (int) mam_db_row("SELECT id FROM courses WHERE code = 'voice'")['id'];
$CE_pre   = (int) mam_db_row("SELECT id FROM levels WHERE code = 'precert'")['id'];
$CE_cert  = (int) mam_db_row("SELECT id FROM levels WHERE code = 'cert'")['id'];
$CE_n = 0;
$CE_user = function (string $role, string $tag) use (&$CE_n): int {
    $CE_n++;
    return mam_db_insert('users', ['email' => "t_conenr_{$tag}_{$CE_n}@x.dev", 'password_hash' => 'x',
        'role' => $role, 'name' => "ConEnr {$tag} {$CE_n}", 'status' => 'active']);
};
$CE_admin = $CE_user('admin', 'admin');
$CE_teach = $CE_user('instructor', 'teach');
$CE_sat = mam_lms_create_cohort(['title' => 'ConEnr Piano Sat', 'course_id' => $CE_piano, 'level_id' => $CE_pre, 'capacity' => 2, 'instructor_id' => $CE_teach]);
$CE_wed = mam_lms_create_cohort(['title' => 'ConEnr Piano Wed', 'course_id' => $CE_piano, 'level_id' => $CE_pre, 'capacity' => 3]);
$CE_mode = mam_vetting_mode();
mam_setting_set('safeguarding_gate_mode', 'warn');

$s1 = $CE_user('student', 's');
$r1 = mam_cohort_enrol($CE_sat, $s1, $CE_admin);
t_eq($r1['status'], 'active', 'an office enrolment goes straight to active');
$row = mam_db_row('SELECT cohort_id, level_id, instructor_id, status FROM course_enrollments WHERE id = ?', [$r1['id']]);
t_eq((int) $row['cohort_id'], $CE_sat, 'it lands in the cohort the office chose, not the one the course tuple would resolve');
t_eq((int) $row['level_id'], $CE_pre, 'it takes the cohort\'s level');
t_eq((int) $row['instructor_id'], $CE_teach, 'the cohort\'s instructor is attached (warn mode lets an uncleared one through)');
t_ok((bool) mam_db_row("SELECT 1 x FROM enrolment_events WHERE enrollment_id = ? AND from_status IS NULL AND actor_id = ?", [$r1['id'], $CE_admin]),
    'the birth of the enrolment is on its history, with the admin as actor');

t_group('console enrolment — a full cohort queues, never over-fills');
$s2 = $CE_user('student', 's');
mam_cohort_enrol($CE_sat, $s2, $CE_admin);
$s3 = $CE_user('student', 's');
$p3 = mam_cohort_enrol_preview($CE_sat, $s3);
t_eq($p3['will'], 'waitlist', 'the preview says a full cohort means the waitlist, before the click');
$r3 = mam_cohort_enrol($CE_sat, $s3, $CE_admin);
t_eq($r3['status'], 'waitlisted', 'enrolling into a full cohort joins the waitlist');
t_eq($r3['position'], 1, '…at position 1');
t_eq(mam_lms_cohort_fill_count($CE_sat)['enrolled'], 2, 'the cohort holds exactly its capacity');
$e = null; try { mam_cohort_enrol($CE_sat, $s3, $CE_admin); } catch (RuntimeException $x) { $e = $x; }
t_eq($e ? mam_cohort_code($e)[0] : null, 'already_waiting', 'asking twice does not queue twice');
$e = null; try { mam_cohort_enrol($CE_sat, $CE_user('student', 's'), $CE_admin, false); } catch (RuntimeException $x) { $e = $x; }
t_eq($e ? $e->getMessage() : null, 'cohort_full', 'with the waitlist declined, a full cohort is refused outright');

t_group('console enrolment — the duplicate guard, in words');
$e = null; try { mam_cohort_enrol($CE_wed, $s1, $CE_admin); } catch (RuntimeException $x) { $e = $x; }
t_eq($e ? mam_cohort_code($e)[0] : null, 'already_enrolled', 'a second live enrolment in the same course+level is refused');
t_ok($e && str_contains(mam_cohort_error_words($e), 'ConEnr Piano Sat'), 'the refusal names the class they are already in');
t_ok($e && str_contains(mam_cohort_error_words($e), 'Transfer'), '…and points at moving them instead');
$e = null; try { mam_cohort_enrol($CE_sat, $s1, $CE_admin); } catch (RuntimeException $x) { $e = $x; }
t_eq($e ? $e->getMessage() : null, 'already_in_cohort', 'enrolling into the cohort they are already in says exactly that');
$CE_lvl = mam_lms_create_cohort(['title' => 'ConEnr Piano Cert', 'course_id' => $CE_piano, 'level_id' => $CE_cert, 'capacity' => 5]);
t_eq(mam_cohort_enrol($CE_lvl, $s1, $CE_admin)['status'], 'active', 'a DIFFERENT level of the same course stays legal — that is progression');
$CE_nolvl = mam_lms_create_cohort(['title' => 'ConEnr Piano open', 'course_id' => $CE_piano, 'capacity' => 5]);
$e = null; try { mam_cohort_enrol($CE_nolvl, $s2, $CE_admin); } catch (RuntimeException $x) { $e = $x; }
t_eq($e ? mam_cohort_code($e)[0] : null, 'already_enrolled', 'a level-less cohort collides with a levelled enrolment (both directions)');
$CE_nocourse = mam_lms_create_cohort(['title' => 'ConEnr unmapped', 'capacity' => 5]);
$e = null; try { mam_cohort_enrol($CE_nocourse, $CE_user('student', 's'), $CE_admin); } catch (RuntimeException $x) { $e = $x; }
t_eq($e ? $e->getMessage() : null, 'no_course_mapping', 'a cohort with no course cannot take an enrolment');
$e = null; try { mam_cohort_enrol($CE_wed, $CE_teach, $CE_admin); } catch (RuntimeException $x) { $e = $x; }
t_eq($e ? $e->getMessage() : null, 'not_a_student', 'an instructor account is not enrolled as a student');

t_group('console enrolment — transfer in one step');
$e = null; try { mam_cohort_transfer($r1['id'], $CE_wed, $CE_admin, ''); } catch (RuntimeException $x) { $e = $x; }
t_eq($e ? $e->getMessage() : null, 'reason_required', 'a transfer needs a reason');
$t = mam_cohort_transfer($r1['id'], $CE_wed, $CE_admin, 'Saturday clashes with football');
t_eq((string) mam_db_row('SELECT status FROM course_enrollments WHERE id = ?', [$r1['id']])['status'], 'withdrawn', 'the old enrolment is withdrawn');
t_eq((int) mam_db_row('SELECT cohort_id FROM course_enrollments WHERE id = ?', [$t['id']])['cohort_id'], $CE_wed, 'the new one is in the target cohort');
t_eq((string) mam_db_row('SELECT status FROM course_enrollments WHERE id = ?', [$t['id']])['status'], 'active', '…and active');
t_ok(is_string($t['line']) && $t['line'] !== '', 'the outcome of the old enrolment is stated as a sentence');
t_ok(str_contains((string) mam_db_row("SELECT reason FROM enrolment_events WHERE enrollment_id = ? AND to_status = 'withdrawn'", [$r1['id']])['reason'], 'transfer to ConEnr Piano Wed'),
    'the old enrolment\'s history says where they went');
// The seat freed in Saturday drained the queue (automation on by default).
$sat = mam_lms_cohort_fill_count($CE_sat);
t_eq($sat['enrolled'], 2, 'the freed seat went to the first student waiting');
t_eq($sat['waitlist_count'], 0, '…and the queue is empty');
// Refused onto a full cohort, and nothing written.
mam_db_update('cohorts', ['capacity' => 1], ['id' => $CE_wed]);
$before = (int) mam_db_row('SELECT COUNT(*) n FROM course_enrollments')['n'];
$s3row = mam_db_row("SELECT id FROM course_enrollments WHERE cohort_id = ? AND student_id = ? AND status = 'active'", [$CE_sat, $s3]);
$e = null; try { mam_cohort_transfer((int) $s3row['id'], $CE_wed, $CE_admin, 'try the full one'); } catch (RuntimeException $x) { $e = $x; }
t_eq($e ? $e->getMessage() : null, 'target_full', 'a transfer onto a full cohort is refused — it would leave the student in no class');
t_eq((string) mam_db_row('SELECT status FROM course_enrollments WHERE id = ?', [(int) $s3row['id']])['status'], 'active', '…and the student stays where they were');
t_eq((int) mam_db_row('SELECT COUNT(*) n FROM course_enrollments')['n'], $before, '…with nothing written');
$e = null; try { mam_cohort_transfer((int) $s3row['id'], $CE_sat, $CE_admin, 'same'); } catch (RuntimeException $x) { $e = $x; }
t_eq($e ? $e->getMessage() : null, 'same_cohort', 'moving a student to the cohort they are in is refused');

t_group('console enrolment — withdraw with a reason');
$wid = (int) $s3row['id'];
$e = null; try { mam_cohort_withdraw($wid, $CE_admin, ' '); } catch (RuntimeException $x) { $e = $x; }
t_eq($e ? $e->getMessage() : null, 'reason_required', 'a withdrawal needs a reason');
$w = mam_cohort_withdraw($wid, $CE_admin, 'Family relocating');
t_eq((string) mam_db_row('SELECT status FROM course_enrollments WHERE id = ?', [$wid])['status'], 'withdrawn', 'the enrolment is withdrawn');
t_ok(is_string($w['line']) && $w['line'] !== '', 'the outcome (or the absence of an authored term) is stated');
t_eq($w['outcome'] === null, str_contains($w['line'], 'No term is authored'), 'with no authored term the line says so, rather than inventing a refund');
t_ok(str_contains((string) mam_db_row("SELECT reason FROM enrolment_events WHERE enrollment_id = ? AND to_status = 'withdrawn'", [$wid])['reason'], 'Family relocating'),
    'the reason lands on the enrolment history');
$e = null; try { mam_cohort_withdraw($wid, $CE_admin, 'again'); } catch (RuntimeException $x) { $e = $x; }
t_eq($e ? $e->getMessage() : null, 'not_live', 'an ended enrolment cannot be withdrawn twice');

t_group('console enrolment — status changes go through the machine');
mam_db_update('cohorts', ['capacity' => 5], ['id' => $CE_wed]);
$pid = mam_db_insert('course_enrollments', ['student_id' => $CE_user('student', 's'), 'course_id' => $CE_piano,
    'level_id' => $CE_pre, 'cohort_id' => $CE_wed, 'status' => 'pending']);
t_eq(array_keys(mam_cohort_status_changes('pending')), ['active', 'rejected'], 'a pending enrolment can be approved or rejected');
t_eq(array_keys(mam_cohort_status_changes('active')), ['finalise'], 'an active one is finalised from marks, never typed as completed');
$e = null; try { mam_cohort_set_status($pid, 'completed', $CE_admin); } catch (RuntimeException $x) { $e = $x; }
t_eq($e ? $e->getMessage() : null, 'bad_status', 'typing "completed" is refused');
mam_cohort_set_status($pid, 'active', $CE_admin);
t_eq((string) mam_db_row('SELECT status FROM course_enrollments WHERE id = ?', [$pid])['status'], 'active', 'approve moves pending → active');

t_group('console enrolment — the instructor gate, before and at saving');
$CE_t2 = $CE_user('instructor', 'teach');
mam_setting_set('safeguarding_gate_mode', 'enforce');
$pv = mam_cohort_instructor_check($CE_wed, $CE_t2);
t_eq($pv['verdict'], 'refuse', 'enforce + an uncleared teacher + a child in the class previews as refused');
t_eq($pv['allow'], false, '…and is not allowed');
// mam_audit() is a no-op under the harness, so this is asserted on the source:
// the preview must not reach the auditing gate or write an audit row itself.
$CE_src = (string) file_get_contents(__DIR__ . '/../modules/academics/lib/cohort-admin.php');
$CE_fn = substr($CE_src, strpos($CE_src, 'function mam_cohort_instructor_check('));
$CE_fn = substr($CE_fn, 0, strpos($CE_fn, "\n}\n"));
t_ok(!str_contains($CE_fn, 'mam_vetting_check_assignment') && !str_contains($CE_fn, 'mam_audit'),
    'the preview never calls the auditing gate or mam_audit — a page load is not an assignment');
// Every write path in the lib audits.
foreach (['mam_cohort_enrol', 'mam_cohort_withdraw', 'mam_cohort_transfer', 'mam_cohort_set_status',
          'mam_cohort_set_instructor', 'mam_cohort_archive', 'mam_cohort_reopen'] as $CE_w) {
    $b = substr($CE_src, strpos($CE_src, 'function ' . $CE_w . '('));
    $b = substr($b, 0, strpos($b, "\n}\n"));
    t_ok(str_contains($b, 'mam_audit('), $CE_w . '() writes an audit row');
}
$e = null; try { mam_cohort_set_instructor($CE_wed, $CE_t2, $CE_admin); } catch (RuntimeException $x) { $e = $x; }
t_eq($e ? mam_cohort_code($e)[0] : null, 'vetting_required', 'saving it is refused by the gate');
t_eq(mam_db_row('SELECT instructor_id FROM cohorts WHERE id = ?', [$CE_wed])['instructor_id'], null, '…and nothing was written');
// In enforce, enrolling into a cohort whose teacher is uncleared still enrols.
$s9 = $CE_user('student', 's');
$r9 = mam_cohort_enrol($CE_lvl, $s9, $CE_admin);   // no instructor on this cohort: plain enrol
mam_db_update('cohorts', ['instructor_id' => $CE_t2], ['id' => $CE_lvl]);
$s10 = $CE_user('student', 's');
$r10 = mam_cohort_enrol($CE_lvl, $s10, $CE_admin);
t_eq($r10['status'], 'active', 'enforce refusing the teacher does not refuse the child: still enrolled');
t_ok(is_string($r10['instructor_withheld']) && $r10['instructor_withheld'] !== '', '…with the reason the teacher was withheld');
t_eq(mam_db_row('SELECT instructor_id FROM course_enrollments WHERE id = ?', [$r10['id']])['instructor_id'], null, '…and nobody attached');
mam_setting_set('safeguarding_gate_mode', 'warn');
t_eq(mam_cohort_instructor_check($CE_wed, $CE_t2)['verdict'], 'warn', 'in warn mode the same pick previews as allowed-with-a-gap');
$set = mam_cohort_set_instructor($CE_wed, $CE_t2, $CE_admin);
t_eq((int) mam_db_row('SELECT instructor_id FROM cohorts WHERE id = ?', [$CE_wed])['instructor_id'], $CE_t2, 'warn mode saves it');
t_ok($set['warning'] !== '', '…and reports the gap it recorded');
t_ok($set['moved'] >= 1, 'the cohort\'s enrolments with no instructor followed it');
$e = null; try { mam_cohort_set_instructor($CE_wed, $s9, $CE_admin); } catch (RuntimeException $x) { $e = $x; }
t_eq($e ? $e->getMessage() : null, 'instructor_not_found', 'a student cannot be made a cohort\'s instructor');

t_group('console enrolment — closing a cohort');
$e = null; try { mam_cohort_archive($CE_wed, $CE_admin); } catch (RuntimeException $x) { $e = $x; }
t_eq($e ? mam_cohort_code($e)[0] : null, 'has_students', 'a cohort with students is not closed');
t_ok($e && str_contains(mam_cohort_error_words($e), 'Move or withdraw'), '…and the refusal says what to do');
// A cohort nobody can sit in yet (capacity 0 reads as full) — anybody enrolled queues.
$CE_q = mam_lms_create_cohort(['title' => 'ConEnr queue only', 'course_id' => $CE_voice, 'capacity' => 0]);
$sq = $CE_user('student', 's');
t_eq(mam_cohort_enrol($CE_q, $sq, $CE_admin)['status'], 'waitlisted', 'a zero-capacity cohort queues');
$e = null; try { mam_cohort_archive($CE_q, $CE_admin); } catch (RuntimeException $x) { $e = $x; }
t_eq($e ? mam_cohort_code($e)[0] : null, 'has_waitlist', 'a cohort with somebody waiting is not closed — it would strike them off silently');
mam_lms_waitlist_remove($CE_q, $sq);
$CE_empty = $CE_q;
mam_cohort_archive($CE_empty, $CE_admin);
t_eq((string) mam_db_row('SELECT status FROM cohorts WHERE id = ?', [$CE_empty])['status'], 'archived', 'an empty cohort closes');
$e = null; try { mam_cohort_enrol($CE_empty, $CE_user('student', 's'), $CE_admin); } catch (RuntimeException $x) { $e = $x; }
t_eq($e ? $e->getMessage() : null, 'cohort_closed', 'a closed cohort takes no enrolment');
mam_cohort_reopen($CE_empty, $CE_admin);
t_eq((string) mam_db_row('SELECT status FROM cohorts WHERE id = ?', [$CE_empty])['status'], 'active', '…and can be reopened');

t_group('console enrolment — the screen\'s read model');
$CE_find = mam_cohort_find_students('ConEnr s', 50);
t_ok(count($CE_find) >= 3, 'students are found by name');
t_ok(!array_filter($CE_find, fn($u) => (int) $u['id'] === $CE_teach), 'staff are not offered as students');
t_eq(mam_cohort_find_students('%', 5), [], 'a bare wildcard finds nothing (LIKE wildcards are escaped)');
$GLOBALS['__mam_console_fail'] = [];
$CE_d = mam_console_roster(['cohort' => (string) $CE_wed, 'find' => 'ConEnr', 'instr' => (string) $CE_t2, 'class' => '1']);
t_eq(mam_console_failures(), [], 'every new branch of the roster provider reads without a schema error');
t_eq((int) $CE_d['selected'], $CE_wed, 'the chosen cohort is selected');
t_ok(is_array($CE_d['instr_preview']) && $CE_d['instr_preview']['verdict'] === 'warn', 'the instructor preview is computed for the picked person');
t_ok(count($CE_d['found']) > 0 && isset($CE_d['found'][0]['preview']['words']), 'each search hit carries its predicted outcome in words');
$CE_r = array_values(array_filter($CE_d['roster'], fn($r) => ($r['model'] ?? '') === 'course'));
t_ok($CE_r && array_key_exists('att_pct', $CE_r[0]) && array_key_exists('band', $CE_r[0]), 'roster rows carry attendance and a mastery band');
t_ok(!array_filter($CE_d['roster'], fn($r) => array_key_exists('raw_score', $r)), 'no roster row carries a raw mastery score');
t_ok(!array_filter($CE_d['transfer_to'], fn($t) => $t['id'] === $CE_wed), 'a cohort is never offered as a transfer target for itself');
t_ok(is_string($CE_d['outcome_line']) && $CE_d['outcome_line'] !== '', 'today\'s withdrawal outcome is stated on the screen');

mam_setting_set('safeguarding_gate_mode', $CE_mode);

// Fixture hygiene: these cohorts share (course, level, programme) tuples with
// the catalogue's system cohorts, and mam_lms_ensure_cohort() adopts the first
// ACTIVE match — left open, they would capture later tests' enrolments.
mam_db()->prepare("UPDATE cohorts SET status = 'archived' WHERE title LIKE 'ConEnr %'")->execute();
