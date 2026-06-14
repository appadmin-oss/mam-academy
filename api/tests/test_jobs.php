<?php
/**
 * Automation spine (LMS L3) — domain events + the job queue + runner, exercised
 * with a faked clock (the project's established pattern).
 */
declare(strict_types=1);
t_group('automation: events + jobs');

// ── Events ──
$before = (int)(mam_db_row("SELECT COUNT(*) AS n FROM events")['n'] ?? 0);
mam_event('test.happened', 'thing', 42, ['k' => 'v']);
t_eq((int)(mam_db_row("SELECT COUNT(*) AS n FROM events")['n'] ?? 0), $before + 1, 'mam_event records an append-only event');
$ev = mam_db_row("SELECT * FROM events WHERE type = 'test.happened' ORDER BY id DESC LIMIT 1");
t_eq((int)$ev['entity_id'], 42, 'event carries the entity id');
t_ok(strpos((string)$ev['payload'], 'v') !== false, 'event payload stored as JSON');

// ── Enqueue + run a due job (noop handler → done) ──
$jid = mam_job_enqueue('noop', ['x' => 1]);
t_ok($jid > 0, 'enqueue returns a job id');
$due = mam_jobs_due(50);
t_ok(count(array_filter($due, function ($j) use ($jid) { return (int)$j['id'] === $jid; })) === 1, 'an immediately-scheduled job is due');
$sum = mam_run_jobs(50);
t_ok($sum['ran'] >= 1 && $sum['done'] >= 1, 'run_jobs drains and completes the noop job');
t_eq(mam_db_row('SELECT status FROM jobs WHERE id = ?', [$jid])['status'], 'done', 'completed job is marked done');

// ── Future jobs are NOT due (faked clock) ──
$future = mam_job_enqueue('noop', [], '2099-01-01 00:00:00');
t_ok(count(array_filter(mam_jobs_due(50), function ($j) use ($future) { return (int)$j['id'] === $future; })) === 0, 'a future-dated job is not yet due');
t_ok(count(array_filter(mam_jobs_due(50, 6, '2099-06-01 00:00:00'), function ($j) use ($future) { return (int)$j['id'] === $future; })) === 1, 'it becomes due once the (faked) clock passes run_after');

// ── Unknown kind → failure path (attempts increment) ──
$bad = mam_job_enqueue('does_not_exist', []);
mam_run_jobs(50);
$badRow = mam_db_row('SELECT attempts, status, last_error FROM jobs WHERE id = ?', [$bad]);
t_eq((int)$badRow['attempts'], 1, 'a job with no handler increments attempts');
t_eq($badRow['status'], 'pending', 'and stays pending for retry (under the cap)');
t_ok(strpos((string)$badRow['last_error'], 'no_handler') !== false, 'the failure reason is recorded');

// ── Auto-progression on pass (finalize → event + job → promote) ──
mam_setting_set('lms_auto_promote', '1');
$stu = mam_db_insert('users', ['email' => 't_job_stu@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Climber', 'status' => 'active']);
$cidCourse = (int)mam_db_row("SELECT id FROM courses WHERE code='piano'")['id'];
$pre = (int)mam_db_row("SELECT id FROM levels WHERE code='precert'")['id'];
$std = (int)mam_db_row("SELECT id FROM programs WHERE code='standard'")['id'];
$eid = mam_db_insert('course_enrollments', ['student_id' => $stu, 'course_id' => $cidCourse, 'level_id' => $pre, 'program_id' => $std, 'status' => 'active']);
mam_lms_record_assessment($eid, 'final', null, 90, 100, 12, null);   // passing
$fin = mam_lms_finalize($eid, 1);
t_eq($fin['status'], 'completed', 'a passing finalize completes the enrolment');
t_ok(mam_db_row("SELECT id FROM events WHERE type='enrolment.finalized' AND entity_id=?", [$eid]) !== null, 'finalize records an enrolment.finalized event');
$job = mam_db_row("SELECT id FROM jobs WHERE kind='lms_auto_progress' AND status='pending' AND payload LIKE '%\"enrollment_id\":" . $eid . "%'");
t_ok($job !== null, 'auto-promotion is enqueued (not done inline) when lms_auto_promote is on');
// Before running the job, no next-level enrolment exists yet.
t_ok(mam_db_row("SELECT id FROM course_enrollments WHERE student_id=? AND course_id=? AND level_id=?", [$stu, $cidCourse, (int)mam_db_row("SELECT id FROM levels WHERE code='cert'")['id']]) === null, 'no next-level enrolment until the job runs');
mam_run_jobs(50);
$cert = (int)mam_db_row("SELECT id FROM levels WHERE code='cert'")['id'];
t_ok(mam_db_row("SELECT id FROM course_enrollments WHERE student_id=? AND course_id=? AND level_id=?", [$stu, $cidCourse, $cert]) !== null, 'running the job promotes the student to the next level');
mam_setting_set('lms_auto_promote', '0');
