<?php
/** Certificates — issuance, idempotency, kind mapping, pass-gated enrolment issue. */
declare(strict_types=1);
t_group('certificates');

$sid  = mam_db_insert('users', ['email' => 't_cert@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Cert Student', 'status' => 'active']);
$grand = (int)mam_db_row("SELECT id FROM programs WHERE code='grand'")['id'];
$dip   = (int)mam_db_row("SELECT id FROM levels WHERE code='diploma'")['id'];

$r = mam_lms_issue_certificate($sid, $grand, $dip, 1);
t_ok($r['issued'] === true, 'certificate issued');
t_ok(strpos($r['reference'], 'CERT-') === 0, 'reference is CERT-…');

// Idempotent on (student, programme, level, kind).
$r2 = mam_lms_issue_certificate($sid, $grand, $dip, 1);
t_ok($r2['issued'] === false, 're-issue is idempotent (not re-created)');
t_eq($r2['reference'], $r['reference'], 'same reference returned on re-issue');

// Kind + title derive from the programme's certification tier.
$cert = mam_lms_certificate($r['reference']);
t_eq($cert['kind'], 'internal_diploma', 'grand → internal_diploma kind');
t_eq($cert['title'], 'Internal Certification & Diploma Transcript', 'diploma title');
t_eq($cert['student_name'], 'Cert Student', 'certificate joins the student name');
t_eq($cert['program_name'], 'Grand Certified Program', 'certificate joins the programme name');

// Crash → participation.
$crash = (int)mam_db_row("SELECT id FROM programs WHERE code='crash'")['id'];
$rc = mam_lms_issue_certificate($sid, $crash, null, 1);
$cc = mam_lms_certificate($rc['reference']);
t_eq($cc['kind'], 'participation', 'crash → participation kind');
t_eq($cc['title'], 'Certificate of Participation', 'participation title');

// issue_for_enrollment is pass-gated.
$cid = (int)mam_db_row("SELECT id FROM courses WHERE code='piano'")['id'];
$std = (int)mam_db_row("SELECT id FROM programs WHERE code='standard'")['id'];
$pre = (int)mam_db_row("SELECT id FROM levels WHERE code='precert'")['id'];
$eid = mam_db_insert('course_enrollments', ['student_id' => $sid, 'course_id' => $cid, 'program_id' => $std, 'level_id' => $pre, 'status' => 'active']);
t_ok(mam_lms_issue_for_enrollment($eid, 1) === null, 'no certificate without a passing result');
mam_db_insert('results', ['enrollment_id' => $eid, 'total_score' => 80, 'grade' => 'A', 'outcome' => 'pass']);
$re = mam_lms_issue_for_enrollment($eid, 1);
t_ok($re && $re['issued'] === true, 'certificate issued for a passed enrolment');
$re2 = mam_lms_issue_for_enrollment($eid, 1);
t_ok($re2 && $re2['issued'] === false, 'enrolment re-issue stays idempotent');

// Student listing.
$list = mam_lms_student_certificates($sid);
t_ok(count($list) >= 3, 'student certificates are listed');

t_group('certificates — public verification (§6.4)');
// The public verify endpoint resolves a reference to public-safe facts.
$verOk = mam_lms_certificate($r['reference']);
t_ok($verOk && $verOk['reference'] === $r['reference'], 'a valid reference resolves for verification');
t_ok(!empty($verOk['student_name']) && !empty($verOk['title']), 'verification exposes issued-to name + credential');
t_eq(mam_lms_certificate('NOPE-NOT-A-REAL-REF'), null, 'an unknown reference does not resolve (verify shows invalid)');
