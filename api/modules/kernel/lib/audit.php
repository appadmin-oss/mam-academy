<?php
/**
 * MAM Academy — audit-log reading helpers (Advanced Modernization §8.5).
 * ─────────────────────────────────────────────────────────────
 * Writing lives in log.php (mam_audit). This is the read side: the audit_log
 * made legible, as a per-person timeline.
 */
declare(strict_types=1);

/** Human-readable label for an audit action code. */
function mam_audit_label(string $action): string {
    static $map = [
        'assign' => 'Assignment set', 'lms_feedback' => 'Feedback given', 'rubric_create' => 'Rubric created',
        'rubric_update' => 'Rubric updated', 'rubric_delete' => 'Rubric deleted', 'consent_set' => 'Consent updated',
        'meeting_reconcile_import' => 'Meet attendance imported', 'run_automations' => 'Automations run',
        'acadmail_toggle' => 'Academic emails toggled', 'wallet_credit' => 'Wallet credited',
        'set_admin_tier' => 'Admin tier changed', 'set_student_type' => 'Student type changed',
        'lms_waitlist_autopromote' => 'Promoted from waitlist', 'checkin' => 'Checked in',
        'referral_reward' => 'Referral reward credited',
    ];
    return $map[$action] ?? ucfirst(str_replace(['_', '.'], ' ', $action));
}

/**
 * A per-person audit timeline: everything in the audit_log about a learner —
 * actions on their user row, actions they performed, and actions on the
 * enrolments and submissions they own.
 */
function mam_audit_timeline(int $userId, int $limit = 200): array {
    $u = mam_db_row('SELECT email FROM users WHERE id = ?', [$userId]);
    if (!$u) return [];
    $where = ["(entity = 'user' AND entity_id = ?)", "actor = ?"];
    $args  = [$userId, (string) $u['email']];
    $ids = function (string $sql) use ($userId) { try { return array_column(mam_db_all($sql, [$userId]), 'id'); } catch (\Throwable $e) { return []; } };
    $ce  = $ids('SELECT id FROM course_enrollments WHERE student_id = ?');
    $sub = $ids('SELECT id FROM submissions WHERE student_id = ?');
    if ($ce)  { $where[] = "(entity IN ('course_enrollment','enrollment','student') AND entity_id IN (" . implode(',', array_fill(0, count($ce), '?')) . "))"; $args = array_merge($args, $ce); }
    if ($sub) { $where[] = "(entity = 'submission' AND entity_id IN (" . implode(',', array_fill(0, count($sub), '?')) . "))"; $args = array_merge($args, $sub); }
    $rows = mam_db_all(
        "SELECT actor, action, entity, entity_id, meta, created_at FROM audit_log WHERE " . implode(' OR ', $where) . " ORDER BY created_at DESC LIMIT " . max(1, $limit),
        $args
    );
    foreach ($rows as &$r) { $r['label'] = mam_audit_label((string) $r['action']); }
    unset($r);
    return $rows;
}
