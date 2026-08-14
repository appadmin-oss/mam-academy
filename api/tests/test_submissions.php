<?php
/**
 * Submissions & feedback (LMS L2). A student submits an artifact against an
 * assignment; the instructor leaves feedback whose outcome drives the
 * submission status. Resubmissions create new rows; feedback is 1:1 + editable.
 */
declare(strict_types=1);
t_group('submissions & feedback (L2)');

$ins = mam_db_insert('users', ['email' => 't_sub_ins@x.dev', 'password_hash' => 'x', 'role' => 'instructor', 'name' => 'Mentor M', 'status' => 'active']);
$stu = mam_db_insert('users', ['email' => 't_sub_stu@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Lerner L', 'status' => 'active']);
$other = mam_db_insert('users', ['email' => 't_sub_oth@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Other O', 'status' => 'active']);
$aid = mam_db_insert('assignments', ['student_id' => $stu, 'author_id' => $ins, 'title' => 'Record a C-major scale', 'status' => 'open']);

// ── Submit ──
$sub = mam_lms_submit($aid, $stu, 'https://example.com/scale.mp3', ['artifact_kind' => 'audio', 'note' => 'first try']);
t_ok($sub > 0, 'submit returns a submission id');
$row = mam_lms_submission($sub);
t_eq($row['status'], 'submitted', 'a new submission is "submitted"');
t_eq($row['artifact_kind'], 'audio', 'artifact kind carried');
t_eq($row['assignment_title'], 'Record a C-major scale', 'submission resolves the assignment title');
t_eq($row['student_name'], 'Lerner L', 'submission resolves the student name');

// invalid kind falls back to link
$sub2 = mam_lms_submit($aid, $stu, 'https://x/y', ['artifact_kind' => 'bogus']);
t_eq(mam_lms_submission($sub2)['artifact_kind'], 'link', 'an unknown artifact kind falls back to link');

// ── Ownership / existence guards ──
$threw = false; try { mam_lms_submit($aid, $other, 'https://x'); } catch (RuntimeException $e) { $threw = $e->getMessage() === 'not_your_assignment'; }
t_ok($threw, 'submitting to someone else\'s assignment throws not_your_assignment');
$threw = false; try { mam_lms_submit(99999, $stu, 'https://x'); } catch (RuntimeException $e) { $threw = $e->getMessage() === 'assignment_not_found'; }
t_ok($threw, 'submitting to a missing assignment throws assignment_not_found');
// A non-http(s) artifact URL is rejected (it would land in an instructor-facing href).
$threw = false; try { mam_lms_submit($aid, $stu, 'javascript:alert(document.cookie)'); } catch (RuntimeException $e) { $threw = $e->getMessage() === 'invalid_url'; }
t_ok($threw, 'a javascript: artifact URL is rejected with invalid_url');
$threw = false; try { mam_lms_submit($aid, $stu, 'data:text/html,<script>alert(1)</script>'); } catch (RuntimeException $e) { $threw = $e->getMessage() === 'invalid_url'; }
t_ok($threw, 'a data: artifact URL is rejected with invalid_url');

// ── Listing ──
t_eq(count(mam_lms_assignment_submissions($aid)), 2, 'assignment submissions lists both attempts');
t_eq(mam_lms_assignment_submissions($aid)[0]['student_name'], 'Lerner L', 'assignment submissions carry the student name');
t_eq(count(mam_lms_student_submissions($stu, $aid)), 2, 'student submissions scoped to the assignment');
t_eq(mam_lms_student_submissions($stu)[0]['assignment_title'], 'Record a C-major scale', 'student submissions carry the assignment title');

// ── Feedback: revise → needs_resubmission ──
$fb = mam_lms_give_feedback($sub, $ins, ['outcome' => 'revise', 'comments' => 'Watch the timing on bar 2.']);
t_eq($fb['status'], 'needs_resubmission', 'revise feedback sets needs_resubmission');
t_eq(mam_lms_submission($sub)['status'], 'needs_resubmission', 'submission status updated');
t_eq(mam_lms_submission($sub)['feedback_comments'], 'Watch the timing on bar 2.', 'feedback comments stored');

// ── Editing feedback (1:1 upsert): accept ──
$fb2 = mam_lms_give_feedback($sub, $ins, ['outcome' => 'accepted', 'comments' => 'Fixed — nice work.']);
t_eq($fb2['status'], 'accepted', 'editing to accepted updates the status');
t_eq((int)mam_db_row('SELECT COUNT(*) AS n FROM feedback WHERE submission_id = ?', [$sub])['n'], 1, 'feedback stays 1:1 with the submission (edit, not duplicate)');
t_eq(mam_lms_submission($sub)['feedback_outcome'], 'accepted', 'latest outcome reflected');

// ── §22 outcomes: reject + assign remedial map to their own statuses ──
$fb3 = mam_lms_give_feedback($sub, $ins, ['outcome' => 'remedial', 'comments' => 'Do the remedial set first.']);
t_eq($fb3['status'], 'remedial', 'remedial feedback sets a remedial status');
t_eq(mam_lms_submission($sub)['status'], 'remedial', 'submission reflects remedial');
$fb4 = mam_lms_give_feedback($sub, $ins, ['outcome' => 'rejected', 'comments' => 'Not this piece.']);
t_eq($fb4['status'], 'rejected', 'reject feedback sets a rejected status');
t_eq(mam_lms_submission($sub)['feedback_outcome'], 'rejected', 'latest §22 outcome reflected');

// ── Guards ──
$threw = false; try { mam_lms_give_feedback($sub, $ins, ['outcome' => 'bogus']); } catch (RuntimeException $e) { $threw = $e->getMessage() === 'invalid_outcome'; }
t_ok($threw, 'an invalid feedback outcome is rejected');
$threw = false; try { mam_lms_give_feedback(99999, $ins, ['outcome' => 'accepted']); } catch (RuntimeException $e) { $threw = $e->getMessage() === 'submission_not_found'; }
t_ok($threw, 'feedback on a missing submission throws submission_not_found');

// ── AI grading assist (mock provider) — drafts feedback the instructor edits ──
mam_test_env(['AI_PROVIDER' => 'none']);
t_ok(mam_ai_draft_feedback($sub, $ins) === null, 'AI feedback draft is null when AI is disabled');
mam_test_env(['AI_PROVIDER' => 'mock']);
$draft = mam_ai_draft_feedback($sub, $ins);
t_ok(is_array($draft) && !empty($draft['text']), 'AI feedback draft returns text when AI is enabled');
t_ok(mam_ai_draft_feedback(99999, $ins) === null, 'AI draft for a missing submission is null (no crash)');
mam_test_env(['AI_PROVIDER' => 'none']);
