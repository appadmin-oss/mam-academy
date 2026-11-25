<?php
/**
 * The student portal v2 — the read providers, and the refusals they keep.
 *
 * The refusals are the point of this file. A design refusal that lives only in
 * a template is one a later template forgets; these assert it at the layer the
 * markup reads from, so a screen CANNOT render what is refused because the
 * payload has nowhere to put it.
 *
 * `test_student_portal_ui.php` covers the three files a browser loads.
 */
declare(strict_types=1);

t_group('student portal — shape and screens');

t_eq(MAM_SPORTAL_TABS, ['home', 'train', 'learn', 'attend', 'more'],
    'the bottom bar is the design\'s five, in its order');
foreach (MAM_SPORTAL_TABS as $SP_t) {
    t_ok(in_array($SP_t, MAM_SPORTAL_SCREENS, true), "tab '$SP_t' is a screen the router knows");
}
foreach (['money', 'repertoire'] as $SP_s) {
    t_ok(in_array($SP_s, MAM_SPORTAL_SCREENS, true), "'$SP_s' is reachable (behind More)");
    /* ⛔ handoff §2: `Pay` is deliberately not a bar destination. Money surfaces
       on Home when something is owed, and for an under-16 the guardian pays —
       a child given a permanent tab about a debt is the thing §3.4 refuses. */
    t_ok(!in_array($SP_s, MAM_SPORTAL_TABS, true), '…and is NOT in the five-tab bar');
}

/* ⚠️ Every screen name must DISPATCH, not merely validate. A name that passes
   the unknown-screen check and falls through to `default` renders the HOME
   payload under another screen's heading — silently, because the client has
   something to draw. That shipped once on the instructor portal (`report`). */
$SP_route = (string) @file_get_contents(MAM_API_ROOT . '/routes/student/portal.php');
foreach (MAM_SPORTAL_SCREENS as $SP_s) {
    t_ok((bool) preg_match("/case '" . $SP_s . "':/", $SP_route),
        "the route dispatches '$SP_s'");
}

/* ── Fixtures: one student, a cohort, a session, a module ────────────────── */

$SP_ins = mam_db_insert('users', ['name' => 'Tunde Teacher', 'email' => 'sp_ins@test.local',
    'role' => 'instructor', 'password_hash' => 'x', 'status' => 'active']);
$SP_me  = mam_db_insert('users', ['name' => 'Ada Okonkwo', 'email' => 'sp_me@test.local',
    'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
/* ⚠️ Reuse a SEEDED course rather than inserting one — the test DB is shared
   and two assertions elsewhere count the catalogue (test_curriculum_clone.php
   documents the same trap). */
$SP_course = (int) mam_db_row("SELECT id FROM courses WHERE code = 'piano'")['id'];
$SP_cohort = mam_db_insert('cohorts', ['course_id' => $SP_course, 'instructor_id' => $SP_ins,
    'title' => 'SP Cohort', 'status' => 'active', 'capacity' => 10]);
mam_db_insert('course_enrollments', ['student_id' => $SP_me, 'course_id' => $SP_course,
    'cohort_id' => $SP_cohort, 'instructor_id' => $SP_ins, 'status' => 'active']);

$SP_les = mam_db_insert('lessons', ['cohort_id' => $SP_cohort, 'title' => 'Circle of fifths',
    'week_no' => 5, 'published' => 1, 'teachback_required' => 1, 'created_by' => $SP_ins,
    'prep_instructions' => 'Play the C, G and D major scales hands together.']);
/* Same day-boundary clamp the instructor fixture needs: a flat `time() + 480`
   crosses midnight for the last eight minutes of every UTC day. */
$SP_at   = min(time() + 480, (int) strtotime(gmdate('Y-m-d 23:58:00') . ' UTC'));
$SP_sess = mam_db_insert('class_sessions', ['cohort_id' => $SP_cohort, 'lesson_id' => $SP_les,
    'title' => 'Scales & sight-reading', 'scheduled_at' => gmdate('Y-m-d H:i:s', $SP_at),
    'duration_min' => 60, 'mode' => 'onsite', 'status' => 'scheduled']);

/* ── Home ────────────────────────────────────────────────────────────────── */

t_group('student portal — Home');

$SP_home = mam_sportal_home($SP_me);
t_eq($SP_home['me']['name'], 'Ada Okonkwo', 'the payload names the student');
t_eq($SP_home['me']['first'], 'Ada', 'and carries a first name for the greeting');
t_ok(is_array($SP_home['next']), 'the next class is resolved');
t_eq((int) $SP_home['next']['session_id'], (int) $SP_sess, '…and it is the seeded session');
t_ok(isset($SP_home['next']['window']['state']),
    'the §9 window travels with it — the client must never derive a boundary');
t_ok(is_int($SP_home['next']['starts_in_min']), 'the countdown is MINUTES, not a formatted string');

/* ⛔ handoff §2.3. ONE next step, not a list: a screen that offers four equal
   next steps has not chosen one, which is the state this design replaces. */
t_ok(is_array($SP_home['next_step']) || $SP_home['next_step'] === null,
    'next_step is one object or nothing — never a list');
t_ok(isset($SP_home['next_step']['screen']) && in_array($SP_home['next_step']['screen'], MAM_SPORTAL_SCREENS, true),
    'the next step names a screen the router knows');

/* ⛔ handoff §2.3 — no cohort effort board, ever. The leaderboard was removed
   from this codebase in 2026-09 (test_engagement2.php guards its absence) and
   the personal streak is what replaced it. The private sentence travels WITH
   the number so a template cannot show one without the other. */
t_ok(!empty($SP_home['streak_private']), 'the streak carries its privacy sentence');
t_ok(stripos($SP_home['streak_private'], 'nobody else') !== false,
    '…and the sentence actually says nobody else sees it');
$SP_json = json_encode($SP_home);
foreach (['rank', 'leaderboard', 'position', 'percentile'] as $SP_bad) {
    t_ok(stripos($SP_json, $SP_bad) === false, "the Home payload carries no '$SP_bad'");
}

/* ── The next step resolves the window's five-way state, not a boolean ──── */

/* ⚠️ `mam_checkin_window()` reports `state`, not `open`. A truthy test on
   `$w['open']` both misses the key and would skip `readiness_passed` and
   `in_progress` — the two states a LATE student is most likely to be in, and
   the ones where checking in matters most. */
$SP_step = mam_sportal_next_step($SP_me, $SP_home['next']);
t_eq($SP_step['kind'], 'checkin', 'a class inside the window is the step, ahead of everything else');
t_ok(strpos((string) $SP_step['body'], 'expected to be') !== false,
    '…and the sentence is the window\'s own, not one the portal composed');

mam_db_insert('session_attendance', ['session_id' => $SP_sess, 'student_id' => $SP_me,
    'status' => 'present', 'mode' => 'onsite', 'checked_in_at' => gmdate('Y-m-d H:i:s')]);
$SP_home2 = mam_sportal_home($SP_me);
t_ok(($SP_home2['next_step']['kind'] ?? '') !== 'checkin',
    'once checked in, the step moves on — a done thing is not a next step');

/* ── Train ───────────────────────────────────────────────────────────────── */

t_group('student portal — Train');

$SP_train = mam_sportal_train($SP_me);
t_ok(is_array($SP_train['target']), 'the module the next session delivers is the target');
t_eq($SP_train['target']['title'], 'Circle of fifths', '…read from the lesson, not the session');
t_ok(strpos((string) $SP_train['target']['body'], 'scales') !== false,
    'the preparation instructions come through');
/* ⚠️ `mam_preclass_next()` returns {session, module, prep, teachback} — NOT a
   flat row. Reading `$pre['title']` off the top level is silently empty rather
   than an error, which is how a card titled "Prepare for " would ship. */
t_eq((int) $SP_train['target']['lesson_id'], (int) $SP_les,
    'the lesson id is the module\'s, so the prepare and teach-back writes address the right row');

/* ⛔ handoff §2.1 — exactly THREE teach-back self-checks, formative only. The
   original six-dimension rubric was overlapping constructs scored by one
   untrained rater. Three live in the payload so a template cannot grow a
   fourth, and the sentence travels with them. */
t_eq(count($SP_train['teachback_checks']), 3, 'exactly three self-checks');
t_ok(stripos($SP_train['teachback_notice'], 'never') !== false,
    'and the formative sentence says plainly it is never marked');

/* ⛔ handoff §2.1 — retrieval practice is opt-in, never scored, and skipping
   costs nothing. The moment it is graded it stops being retrieval practice. */
t_ok(stripos($SP_train['warm_notice'], 'never scored') !== false, 'keep-it-warm says it is never scored');
t_eq(count($SP_train['warm_intervals']), 3, 'three honest answers about recall');

/* ⛔ handoff §2.1 — no automatic scoring of a child's playing, anywhere. A
   take carries its artifact and its status; a human marks it. */
foreach (($SP_train['takes'] ?? []) as $SP_k) {
    t_ok(!array_key_exists('score', $SP_k) && !array_key_exists('grade', $SP_k),
        'a recording carries no score field');
}
/* ⚠️ Strip the comments before an ABSENCE assertion. This file explains what
   it does not do, in prose, so a naive `strpos` finds the very string it is
   asserting is absent — a trap this suite has fallen into three times. */
$SP_lib = (string) @file_get_contents(MAM_API_ROOT . '/lib/student-portal.php');
$SP_code = preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', $SP_lib);
t_ok(!preg_match('/\bmam_(studio|notation)_score|auto_?score|detectPitch/i', $SP_code),
    'nothing in the provider scores a performance');

/* ── Learn ───────────────────────────────────────────────────────────────── */

t_group('student portal — Learn');

$SP_learn = mam_sportal_learn($SP_me);

/* ⛔ gotcha #12 and handoff §2.2 — the BAND, never the raw score. Everything
   student-facing goes through `mam_mastery_publishable()`, which strips
   `raw_score` and strips `bounds` while the score is withheld. */
t_ok(!array_key_exists('raw_score', (array) $SP_learn['mastery']),
    'raw_score never reaches the student — it is the number the coverage floor withholds');
t_ok(strpos($SP_code, "'raw_score'") === false && strpos($SP_code, '["raw_score"]') === false,
    'and the provider never reads it');
t_ok(strpos($SP_code, 'mam_mastery_publishable(') !== false,
    'the one publishable predicate is what decides, so this screen cannot disagree with the guardian\'s');

/* §2.2 rule 2 — coverage ON THE FACE, in words, not a figure the reader has to
   interpret against a threshold documented elsewhere. */
t_ok(isset($SP_learn['coverage']['sentence']), 'coverage is stated as a sentence');
t_ok(($SP_learn['coverage']['total'] ?? 0) === count(MAM_MASTERY_COMPONENTS),
    'the denominator is every component, not only the evidenced ones');

/* §2.2 rule 3 — "not enough evidence yet" is a VISIBLE ROW with a NULL pct,
   never a silent zero. A zero reports an unassessed beginner as failing. */
t_ok(count($SP_learn['unevidenced']) > 0, 'the unevidenced components come back as rows');
foreach ($SP_learn['unevidenced'] as $SP_u) {
    t_eq($SP_u['pct'], null, 'an unevidenced component has a NULL pct, never 0');
    t_eq($SP_u['level'], 'insufficient', '…and says so in words');
    t_ok($SP_u['name'] !== '', '…and is NAMED, so the reader can see what is missing');
}

/* §2.2 rule 5 — 80% is a convention the academy chose and reviews, and the
   payload says so where the number is shown. */
t_ok(stripos($SP_learn['convention'], 'chose') !== false,
    'the threshold is labelled a convention, not a fact about music');

/* ⛔ handoff §2.3 — intervention flags are staff-side only. A student sees the
   booking with a named human, never the reason it was made. */
$SP_lj = json_encode($SP_learn);
foreach (['risk', 'at_risk', 'flag', 'intervention'] as $SP_bad) {
    t_ok(stripos($SP_lj, $SP_bad) === false, "the Learn payload carries no '$SP_bad'");
}

/* ── Attend ──────────────────────────────────────────────────────────────── */

t_group('student portal — Attend');

$SP_att = mam_sportal_attend($SP_me);
t_ok(isset($SP_att['next']['window']['state']),
    'the actionable card carries the window — the dashboard summary alone does not');
t_ok(array_key_exists('rate', $SP_att), 'the attendance rate is reported');
t_ok(stripos($SP_att['makeup_rule'], '24 hours') !== false,
    'the make-up rule is stated where the credit is spent, not in a policy page');
t_ok(stripos($SP_att['makeup_rule'], 'cancels') !== false,
    '…including that a teacher cancellation never counts against the five');
t_ok(count($SP_att['recent']) >= 1, 'the record reads back');

/* ── Money ───────────────────────────────────────────────────────────────── */

t_group('student portal — Money');

/* A charge recorded with charging OFF is amount 0 and outside MAM_ACCT_OWED —
   it is a RECORD, never a debt (gotcha #11). */
mam_db_insert('accountability_charges', ['subject_type' => 'student', 'subject_id' => $SP_me,
    'session_id' => $SP_sess, 'reason' => 'late', 'minutes' => 9, 'amount_usd_cents' => 0,
    'currency' => 'USD', 'status' => 'recorded']);
$SP_money = mam_sportal_money($SP_me);
/* ⚠️ `mam_accountability_list()` takes a STATUS, not a subject. Passing
   'student' asks for charges whose status is 'student' and answers an empty
   list forever — a silent, permanent blank where a student's charges belong. */
t_ok(count($SP_money['ledger']['charges']) >= 1,
    'the ledger reads the SUBJECT\'s charges, not a status named "student"');
t_eq((int) $SP_money['ledger']['outstanding_usd_cents'], 0,
    'a recorded lateness at zero is not outstanding');

/* ⛔ handoff §3.4 — nothing is withheld from a child over money, and the
   sentence sits with the figure rather than in a policy page nobody opens. */
t_ok(stripos($SP_money['owed']['notice'], 'withheld') !== false,
    'the money screen states that nothing is withheld over a debt');

/* ── More ────────────────────────────────────────────────────────────────── */

t_group('student portal — More');

$SP_more = mam_sportal_more($SP_me);
t_ok(array_key_exists('owed_cents', $SP_more) && array_key_exists('awaiting_marks', $SP_more),
    'the sheet badges come from the screens themselves, so they cannot disagree with what they link to');

/* ── One model: the old class tables are retired and unread ──────────────── */

t_group('student portal — the cohort model only');

/* mam_legacy_carry() copied every old class enrolment into course_enrollments, so
   the portal reads that table alone. A row left only in the old `enrollments`
   table is not shown — and every screen still answers for that student rather
   than failing. */
$SP_leg = mam_db_insert('users', ['name' => 'Legacy Learner', 'email' => 'sp_leg@test.local',
    'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$SP_cls = mam_db_insert('classes', ['title' => 'Classical Foundations', 'instructor_id' => $SP_ins,
    'discipline' => 'piano', 'mode' => 'onsite', 'capacity' => 12, 'status' => 'active']);
mam_db_insert('enrollments', ['student_id' => $SP_leg, 'class_id' => $SP_cls, 'status' => 'active']);

t_eq(count(mam_sportal_enrolments($SP_leg)), 0, 'an enrolment left only in the old class table is not read');
foreach (['home', 'train', 'learn', 'attend', 'money', 'repertoire', 'more'] as $SP_s) {
    $SP_fn = 'mam_sportal_' . $SP_s;
    t_ok(is_array($SP_fn($SP_leg)), "'$SP_s' answers for a student with no course enrolment");
}

/* Once carried (a course enrolment in the class's cohort), the class is the cohort. */
$SP_carried = mam_db_insert('cohorts', ['course_id' => $SP_course, 'instructor_id' => $SP_ins,
    'title' => 'Classical Foundations', 'legacy_class_id' => $SP_cls, 'status' => 'active']);
mam_db_insert('course_enrollments', ['student_id' => $SP_leg, 'course_id' => $SP_course,
    'cohort_id' => $SP_carried, 'status' => 'active']);
$SP_en = mam_sportal_enrolments($SP_leg);
t_eq(count($SP_en), 1, 'the carried enrolment is the student\'s one enrolment');
t_eq($SP_en[0]['model'], 'course', '…read from the cohort model');
t_eq($SP_en[0]['cohort_id'], (int) $SP_carried, '…in the cohort the class became');
t_eq($SP_en[0]['cohort'], 'Classical Foundations', '…named by the cohort');
t_eq(count(mam_sportal_learn($SP_leg)['enrolments']), 1, 'and Learn shows them their class');
