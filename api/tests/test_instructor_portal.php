<?php
/**
 * The instructor portal v2 — the read providers, and the refusals they keep.
 *
 * The refusals are the point of this file. A design refusal that lives only in
 * a template is one a later template forgets; these assert it at the layer the
 * markup reads from, so a screen CANNOT render what is refused because the
 * payload has nowhere to put it.
 */
declare(strict_types=1);

t_group('instructor portal — shape and screens');

t_eq(MAM_IPORTAL_TABS, ['home', 'register', 'marking', 'students', 'more'],
    'the bottom bar is the design\'s five, in its order');
foreach (MAM_IPORTAL_TABS as $IP_t) {
    t_ok(in_array($IP_t, MAM_IPORTAL_SCREENS, true), "tab '$IP_t' is a screen the router knows");
}
foreach (['teachbacks', 'report', 'earnings'] as $IP_s) {
    t_ok(in_array($IP_s, MAM_IPORTAL_SCREENS, true), "'$IP_s' is reachable (behind More)");
    t_ok(!in_array($IP_s, MAM_IPORTAL_TABS, true), "…and is NOT in the five-tab bar");
}

/* ── Fixtures: one instructor, two students, a cohort and a session ──────── */
$IP_ins = mam_db_insert('users', ['name' => 'Ada Teacher', 'email' => 'ip_ins@test.local',
    'role' => 'instructor', 'password_hash' => 'x', 'status' => 'active']);
$IP_s1 = mam_db_insert('users', ['name' => 'Ife Bankole', 'email' => 'ip_s1@test.local',
    'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$IP_s2 = mam_db_insert('users', ['name' => 'Zainab Musa', 'email' => 'ip_s2@test.local',
    'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
/* ⚠️ Reuse a SEEDED course rather than inserting one. The test DB is shared,
   and two assertions elsewhere count the catalogue — test_curriculum_clone.php
   documents the same trap and cleans up after itself. Not creating the row is
   simpler than remembering to delete it. */
$IP_course = (int) mam_db_row("SELECT id FROM courses WHERE code = 'piano'")['id'];
$IP_cohort = mam_db_insert('cohorts', ['course_id' => $IP_course, 'instructor_id' => $IP_ins,
    'title' => 'IP Cohort', 'status' => 'active', 'capacity' => 10]);
foreach ([$IP_s1, $IP_s2] as $IP_st) {
    mam_db_insert('course_enrollments', ['student_id' => $IP_st, 'course_id' => $IP_course,
        'cohort_id' => $IP_cohort, 'status' => 'active']);
}
/* ⚠️ `mam_iportal_home()`'s window ends at TODAY's 23:59:59 (UTC) — "Today"
   is the screen's whole promise. A fixture at a flat `time() + 480` therefore
   fell OUT of the window for the last eight minutes of every UTC day and took
   five assertions with it. Clamp it inside the day, and derive the expected
   countdown from the time actually stored rather than hardcoding 6..9. */
$IP_at   = min(time() + 480, (int) strtotime(gmdate('Y-m-d 23:58:00') . ' UTC'));
$IP_in   = (int) round(($IP_at - time()) / 60);
$IP_sess = mam_db_insert('class_sessions', ['cohort_id' => $IP_cohort, 'title' => 'Scales & sight-reading',
    'scheduled_at' => gmdate('Y-m-d H:i:s', $IP_at), 'status' => 'scheduled',
    'duration_min' => 60, 'kind' => 'class']);

t_group('instructor portal — Home');

$IP_home = mam_iportal_home($IP_ins);
t_ok($IP_home['next'] !== null, 'the next session today is found');
t_eq((string) $IP_home['next']['title'], 'Scales & sight-reading', '…and it is the right one');
t_eq((int) $IP_home['next']['roster'], 2, 'the roster count is the cohort, counted DISTINCT');
t_ok(abs((int) $IP_home['next']['starts_in_min'] - $IP_in) <= 1,
    'starts_in_min is minutes, not a formatted string (got ' . $IP_home['next']['starts_in_min']
    . ', want ~' . $IP_in . ')');
t_ok($IP_home['next']['register_taken'] === false, 'an unmarked session says so');

/* A session already running reports a NEGATIVE countdown rather than hiding.
   No clamp needed here, and one would be WRONG: the window opens two hours
   BEHIND now, so ten minutes ago is inside it even when that lands on
   yesterday — whereas clamping it into today would put it in the FUTURE for
   the first ten minutes of a day and invert the assertion. */
$IP_running = mam_db_insert('class_sessions', ['cohort_id' => $IP_cohort, 'title' => 'Running now',
    'scheduled_at' => gmdate('Y-m-d H:i:s', time() - 600), 'status' => 'scheduled',
    'duration_min' => 60, 'kind' => 'class']);
$IP_home2 = mam_iportal_home($IP_ins);
t_ok($IP_home2['next']['starts_in_min'] < 0, 'a session already under way counts DOWN past zero, it does not vanish');

t_group('instructor portal — Register');

$IP_reg = mam_iportal_register($IP_ins, $IP_sess);
t_eq(count($IP_reg['roster']), 2, 'the roster is the cohort');
t_eq($IP_reg['marked'], 0, 'nothing marked yet');
t_ok($IP_reg['roster'][0]['marked'] === false, 'an unmarked student reports marked=false, not a default status');
t_ok(str_contains($IP_reg['save_notice'], 'saves on its own'),
    'the per-mark save promise travels WITH the payload, so the screen cannot omit it');

// An instructor who does not teach it gets nothing back — not a roster.
$IP_other = mam_db_insert('users', ['name' => 'Other Teacher', 'email' => 'ip_other@test.local',
    'role' => 'instructor', 'password_hash' => 'x', 'status' => 'active']);
t_eq(mam_iportal_register($IP_other, $IP_sess), [],
    'an instructor who does not teach the session gets no roster — ownership is checked in the provider');

t_group('instructor portal — Students: a band and a sentence, NEVER a score');

$IP_students = mam_iportal_students($IP_ins);
t_ok(count($IP_students) >= 2, 'both students appear');
$IP_names = array_column($IP_students, 'name');
t_ok(in_array('Ife Bankole', $IP_names, true), 'a cohort student is listed');

/* THE REFUSAL. "There is no risk score here — a label a child could be given
   is not a substitute for you ringing a guardian." Asserted on the payload's
   own keys, so no template can render a score that does not exist. */
foreach ($IP_students as $IP_row) {
    foreach (array_keys($IP_row) as $IP_k) {
        t_ok(!preg_match('/risk|rating|percentile|rank/i', $IP_k),
            "no student key named '$IP_k' — a risk label is refused at the payload");
    }
    t_ok(array_key_exists('band', $IP_row), 'a band is what is published');
    t_ok(!array_key_exists('mastery', $IP_row) && !array_key_exists('raw_score', $IP_row),
        'the raw mastery figure is absent, as it is for a guardian');
    // The sentence, when there is one, is WORDS about something that happened.
    if ($IP_row['attention'] !== null) {
        t_ok(!preg_match('/^\s*\d/', (string) $IP_row['attention']),
            'an attention note is a sentence, never a number');
    }
}
$IP_unassessed = array_values(array_filter($IP_students, fn($r) => $r['band'] === null));
if ($IP_unassessed) {
    t_eq($IP_unassessed[0]['band_label'], 'Not enough yet',
        'an unassessed student reads "Not enough yet", never 0');
}

t_group('instructor portal — Earnings: recorded, and charged to nobody');

$IP_earn = mam_iportal_earnings($IP_ins);
t_ok(array_key_exists('balance_cents', $IP_earn), 'the statement carries a balance');
t_eq($IP_earn['punctuality']['deducted_cents'], 0, 'nothing is deducted by default');
t_eq($IP_earn['punctuality']['notice'], 'Noted for appraisal · no deduction',
    'a lateness is STATED at zero rather than omitted — an instructor who sees nothing cannot tell it was recorded');
t_ok(str_contains($IP_earn['statement_notice'], 'Read-only'),
    'the statement says it is read-only: there is no top-up or withdrawal here');

/* Labour Act s.5(1) is why the instructor gate is separate and off (gotcha
   #11). This asserts the provider never invents a deduction of its own. */
$IP_src = file_get_contents(dirname(__DIR__) . '/lib/instructor-portal.php');
t_ok(!str_contains($IP_src, 'mam_wallet_post') && !str_contains($IP_src, 'mam_wallet_apply'),
    'the earnings screen is a READ — this provider writes nothing to the ledger');

t_group('instructor portal — teach-back stays formative');

$IP_tb = mam_iportal_teachbacks($IP_ins);
t_ok(str_contains($IP_tb['notice'], 'does not enter the assessment band'),
    'the formative promise travels with the queue');
t_ok(str_contains($IP_tb['notice'], 'never triggers a charge'),
    '…and so does the promise that it costs the student nothing');

t_group('instructor portal — marking queue');

$IP_mark = mam_iportal_marking($IP_ins);
t_ok(is_array($IP_mark['queue']), 'the queue reads');
t_eq($IP_mark['audio_only_notice'], MAM_MARKING_AUDIO_NOTICE,
    'the audio-only explanation is the constant, not a string fetched with a row that may be missing');

t_group('instructor portal — marking finds work set to a cohort the instructor teaches');

/* It used to find work only through `JOIN classes ON classes.id = a.class_id`,
   so an assignment set to a COHORT — by another member of staff, or by an
   admin — never reached the cohort teacher's queue at all. The legacy class
   model is retired; an assignment is the instructor's when they wrote it, when
   it was set to a cohort they teach, or when its group was. */
$IP_office = mam_db_insert('users', ['name' => 'Office Admin', 'email' => 'ip_office@test.local',
    'role' => 'admin', 'password_hash' => 'x', 'status' => 'active']);
$IP_ids = function (array $rows): array { return array_map(fn($r) => (int) $r['id'], $rows); };

// 1. Set to the cohort directly, written by somebody else.
$IP_aCoh = mam_db_insert('assignments', ['student_id' => $IP_s1, 'author_id' => $IP_office,
    'cohort_id' => $IP_cohort, 'title' => 'IP cohort piece', 'status' => 'open']);
$IP_subCoh = mam_db_insert('submissions', ['assignment_id' => $IP_aCoh, 'student_id' => $IP_s1,
    'artifact_kind' => 'audio', 'status' => 'submitted']);
// 2. Through a group set to the cohort.
$IP_grp = mam_db_insert('assignment_groups', ['cohort_id' => $IP_cohort, 'author_id' => $IP_office, 'title' => 'IP group']);
$IP_aGrp = mam_db_insert('assignments', ['student_id' => $IP_s2, 'author_id' => $IP_office,
    'group_id' => $IP_grp, 'title' => 'IP group piece', 'status' => 'open']);
$IP_subGrp = mam_db_insert('submissions', ['assignment_id' => $IP_aGrp, 'student_id' => $IP_s2,
    'artifact_kind' => 'audio', 'status' => 'submitted']);
// 3. Written by the instructor, no cohort at all.
$IP_aOwn = mam_db_insert('assignments', ['student_id' => $IP_s1, 'author_id' => $IP_ins,
    'title' => 'IP own piece', 'status' => 'open']);
$IP_subOwn = mam_db_insert('submissions', ['assignment_id' => $IP_aOwn, 'student_id' => $IP_s1,
    'artifact_kind' => 'audio', 'status' => 'submitted']);
// 4. A cohort somebody ELSE teaches — must not appear.
$IP_ins2 = mam_db_insert('users', ['name' => 'Other Teacher', 'email' => 'ip_ins2@test.local',
    'role' => 'instructor', 'password_hash' => 'x', 'status' => 'active']);
$IP_cohort2 = mam_db_insert('cohorts', ['course_id' => $IP_course, 'instructor_id' => $IP_ins2,
    'title' => 'IP Other Cohort', 'status' => 'active', 'capacity' => 10]);
$IP_aOther = mam_db_insert('assignments', ['student_id' => $IP_s2, 'author_id' => $IP_ins2,
    'cohort_id' => $IP_cohort2, 'title' => 'IP not yours', 'status' => 'open']);
$IP_subOther = mam_db_insert('submissions', ['assignment_id' => $IP_aOther, 'student_id' => $IP_s2,
    'artifact_kind' => 'audio', 'status' => 'submitted']);

$IP_q = $IP_ids(mam_iportal_marking($IP_ins)['queue']);
t_ok(in_array($IP_subCoh, $IP_q, true), 'a submission to work set to the instructor\'s COHORT is on their marking queue');
t_ok(in_array($IP_subGrp, $IP_q, true), '…and so is one to work set through a group for that cohort');
t_ok(in_array($IP_subOwn, $IP_q, true), '…and one to work the instructor wrote themselves');
t_ok(!in_array($IP_subOther, $IP_q, true), 'work set to a cohort somebody else teaches is not');
t_ok(in_array($IP_subOther, $IP_ids(mam_iportal_marking($IP_ins2)['queue']), true),
    '…it is on THAT teacher\'s queue');

// The action queue counts with the same predicate, so the two cannot disagree.
$IP_act = null;
foreach (mam_iportal_actions($IP_ins) as $IP_a) if (($IP_a['kind'] ?? '') === 'marking') $IP_act = $IP_a;
t_ok($IP_act !== null && (int) $IP_act['count'] === count($IP_q),
    'the "to mark" action counts exactly what the marking queue lists');

// Neither query, nor the routes that set and open the work, reads the retired class model.
$IP_src = (string) file_get_contents(dirname(__DIR__) . '/lib/instructor-portal.php');
foreach (['routes/instructor/assignment.php', 'routes/instructor/marking-sheet.php',
          'routes/instructor/announce.php', 'routes/instructor/resource.php',
          'routes/instructor/drive-upload.php', 'routes/instructor/student.php',
          'routes/instructor/dashboard.php', 'routes/instructor/card-scan.php'] as $IP_rf) {
    $IP_src .= (string) file_get_contents(dirname(__DIR__) . '/' . $IP_rf);
}
t_ok(!preg_match('/\b(FROM|JOIN|INTO)\s+(classes|enrollments|attendance)\b/i', $IP_src),
    'the instructor portal and its write routes read no legacy class table');
t_ok(!preg_match("/'class_id'\s*=>/", $IP_src), '…and write no class_id');

t_group('instructor portal — a panel failure degrades, it does not 500');

// mam_iportal_try is what stops one lib's exception emptying the whole screen.
t_eq(mam_iportal_try(fn() => throw new RuntimeException('boom'), 'fallback'), 'fallback',
    'a throwing panel returns its fallback');
t_eq(mam_iportal_try(fn() => 'fine', 'fallback'), 'fine', 'a working panel returns its value');
