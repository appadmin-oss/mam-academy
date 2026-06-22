<?php
/**
 * POST /api/admin/set-student-type  {user_id, type:'scholarship'|'paying'}
 * Flags a student as a scholarship (sponsored) recipient or a fee-paying
 * student. Scholarship students are billing-exempt (see mam_invoice_owes).
 * Audited so every grant/revoke of sponsored status is on the record.
 */
declare(strict_types=1);

$in   = mam_json_input();
$uid  = (int)($in['user_id'] ?? 0);
$type = strtolower(trim((string)($in['type'] ?? '')));
if ($uid <= 0) mam_fail('validation', 422, ['fields' => ['user_id']]);
if (!in_array($type, ['scholarship', 'paying'], true)) mam_fail('validation', 422, ['fields' => ['type']]);

$target = mam_db_row('SELECT id, role FROM users WHERE id = ?', [$uid]);
if (!$target) mam_fail('user_not_found', 404);
if (!mam_user_has_role($target, 'student')) {
    mam_fail('not_a_student', 422, ['detail' => 'Scholarship tier only applies to students.']);
}

// Store NULL for 'paying' so the column stays a clean opt-in flag.
mam_db_update('users', ['student_type' => $type === 'scholarship' ? 'scholarship' : null], ['id' => $uid]);
mam_audit('set_student_type', 'user', $uid, ['type' => $type]);
mam_ok(['ok' => true, 'type' => $type]);
