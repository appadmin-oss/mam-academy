<?php
/**
 * Member records — the person, not the application (HANDOFF §4w).
 *
 * Ported from NGG's member management (api/_lib/domain/admin.php,
 * members-bulk.php), whose rules were each learnt on a live roster:
 *
 *  - ONE status setter. Every screen that changes whether somebody can sign in
 *    goes through mam_member_set_status(), so a suspension always has a reason,
 *    always lands in member_status_log, and always ends the person's sessions.
 *    MAM had three writers (user_status, user_delete, nothing in bulk) and none
 *    recorded why.
 *  - Self-protection: an operator cannot suspend their own account
 *    (`own_account`), and the last active super admin cannot be suspended or
 *    demoted (`last_super_admin`) — either is how an academy locks itself out of
 *    its own console with nobody left who can let it back in.
 *  - A members roster does not administer STAFF (`staff_account`): suspending an
 *    instructor or an admin from a list of students is a different decision and
 *    belongs on the people-and-access screen.
 *  - A member number is minted once and never reissued; it is printed on a card
 *    in somebody's pocket (see mam_member_ensure_ref()).
 *
 * Results are verdicts, never exceptions, so a bulk run can report each row:
 * ['result' => 'applied'|'unchanged'|'refused', 'code' => …].
 */
declare(strict_types=1);

/** Statuses an operator may set. `closed` (erasure) and `system` (break-glass) are written only by their own code. */
const MAM_MEMBER_STATUSES = ['active', 'suspended'];
const MAM_MEMBER_REASON_MIN = 8;

/** Is this row an active super admin? (A NULL tier on an admin is super — mam_admin_tier().) */
function mam_member_is_active_super(array $u): bool {
    return ($u['status'] ?? '') === 'active' && function_exists('mam_admin_tier') && mam_admin_tier($u) === 'super';
}

/** How many active super admins are there besides $exceptId? */
function mam_member_other_supers(int $exceptId): int {
    $n = 0;
    foreach (mam_db_all("SELECT * FROM users WHERE status = 'active' AND id <> ?", [$exceptId]) as $u) {
        if (mam_member_is_active_super($u)) $n++;
    }
    return $n;
}

/** Staff = anybody who can act on other people: an admin or an instructor, by any role they hold. */
function mam_member_is_staff(array $u): bool {
    return function_exists('mam_user_has_role') ? mam_user_has_role($u, 'admin', 'instructor') : in_array($u['role'] ?? '', ['admin', 'instructor'], true);
}

/**
 * Change whether somebody can sign in. $opts['members_only'] = true is the
 * roster's mode: staff are refused there.
 */
function mam_member_set_status(int $userId, string $to, string $reason, ?int $actorId, array $opts = []): array {
    $u = mam_db_row('SELECT * FROM users WHERE id = ?', [$userId]);
    if (!$u) return ['result' => 'refused', 'code' => 'not_found'];
    if (!in_array($to, MAM_MEMBER_STATUSES, true)) return ['result' => 'refused', 'code' => 'bad_status'];
    $from = (string) $u['status'];
    if (in_array($from, ['system', 'closed'], true)) return ['result' => 'refused', 'code' => 'system_account'];
    if ($from === $to) return ['result' => 'unchanged', 'code' => 'unchanged', 'from' => $from, 'to' => $to];
    if ($actorId !== null && $actorId === $userId) return ['result' => 'refused', 'code' => 'own_account'];
    if (!empty($opts['members_only']) && mam_member_is_staff($u)) return ['result' => 'refused', 'code' => 'staff_account'];
    if ($to !== 'active' && mam_member_is_active_super($u) && mam_member_other_supers($userId) === 0) {
        return ['result' => 'refused', 'code' => 'last_super_admin'];
    }
    $reason = trim($reason);
    if ($to === 'suspended' && mb_strlen($reason) < MAM_MEMBER_REASON_MIN) return ['result' => 'refused', 'code' => 'reason_required'];

    // Claimed against the status we read, so two operators acting at once do not
    // both log a change from the same starting point.
    $st = mam_db()->prepare('UPDATE users SET status = ?, updated_at = ? WHERE id = ? AND status = ?');
    $st->execute([$to, date('Y-m-d H:i:s'), $userId, $from]);
    if ($st->rowCount() === 0) return ['result' => 'unchanged', 'code' => 'changed_meanwhile'];
    if ($to !== 'active') mam_member_revoke_sessions($userId);   // takes effect now, not at next sign-in
    mam_db_insert('member_status_log', [
        'user_id' => $userId, 'from_status' => $from, 'to_status' => $to,
        'reason' => $reason !== '' ? mb_substr($reason, 0, 500) : null,
        'changed_by' => ($actorId !== null && $actorId > 0) ? $actorId : null,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    mam_audit('member_status', 'user', $userId, ['from' => $from, 'to' => $to, 'reason' => $reason, 'by' => $actorId]);
    return ['result' => 'applied', 'code' => 'applied', 'from' => $from, 'to' => $to];
}

/** Every status change for one person, newest first, with who made it. */
function mam_member_status_history(int $userId): array {
    return mam_db_all('SELECT l.*, u.name AS changed_by_name FROM member_status_log l
        LEFT JOIN users u ON u.id = l.changed_by WHERE l.user_id = ? ORDER BY l.id DESC', [$userId]);
}

/**
 * May $actorId give $userId the primary role $newRole? Refuses an operator
 * taking admin away from themselves and the last super admin losing it — the
 * same lockout as a suspension, by another door (user_edit).
 */
function mam_member_role_change_refusal(int $userId, string $newRole, ?int $actorId): ?string {
    $u = mam_db_row('SELECT * FROM users WHERE id = ?', [$userId]);
    if (!$u || $newRole === 'admin' || ($u['role'] ?? '') !== 'admin') return null;
    if ($actorId !== null && $actorId === $userId) return 'own_account';
    if (mam_member_is_active_super($u) && mam_member_other_supers($userId) === 0) return 'last_super_admin';
    return null;
}

/** End every session a person holds — for a suspension, or "sign them out everywhere". */
function mam_member_revoke_sessions(int $userId): int {
    try {
        $st = mam_db()->prepare('DELETE FROM sessions WHERE user_id = ?');
        $st->execute([$userId]);
        return $st->rowCount();
    } catch (\Throwable $e) { return 0; }
}

/* ── Staff notes on a person ─────────────────────────────────────────────── */

function mam_member_note_add(int $userId, string $body, ?int $actorId): int {
    $body = trim($body);
    if (mb_strlen($body) < 2) throw new InvalidArgumentException('note_required');
    if (!mam_db_row('SELECT id FROM users WHERE id = ?', [$userId])) throw new RuntimeException('not_found');
    $id = mam_db_insert('member_notes', ['user_id' => $userId, 'body' => mb_substr($body, 0, 2000),
        'author_id' => ($actorId !== null && $actorId > 0) ? $actorId : null, 'created_at' => date('Y-m-d H:i:s')]);
    mam_audit('member_note', 'user', $userId, ['note' => $id, 'by' => $actorId]);
    return $id;
}

function mam_member_notes(int $userId): array {
    return mam_db_all('SELECT n.*, u.name AS author_name FROM member_notes n LEFT JOIN users u ON u.id = n.author_id
        WHERE n.user_id = ? ORDER BY n.id DESC', [$userId]);
}

/* ── The member number ────────────────────────────────────────────────────── */

/**
 * Give a student their member number if they have none. Never changes one that
 * exists (mam_enrollment_ref_generate() is idempotent and mints HIGHEST + 1,
 * never a count, so a gap left by a number printed and never recorded is not
 * reused). Called where an enrolment first goes active — the same moment the ID
 * card is minted — because the prefix depends on the programme (KTAMS students
 * are JE/MAM), which is known only once they are enrolled. Before this only
 * payment settlement minted one, so a student enrolled by the office, through
 * Summer or by an Auditer placement had a card and no number.
 */
function mam_member_ensure_ref(int $userId): ?string {
    try {
        $u = mam_db_row('SELECT * FROM users WHERE id = ?', [$userId]);
        if (!$u || !mam_user_has_role($u, 'student')) return null;
        return mam_enrollment_ref_generate($userId);
    } catch (\Throwable $e) {
        mam_log('warn', 'member_ref_mint_failed', ['user_id' => $userId, 'err' => $e->getMessage()]);
        return null;
    }
}

/** The sentence an operator reads for each verdict code. One table, shared by every screen. */
function mam_member_verdict_words(): array {
    return [
        'applied' => 'Done.', 'unchanged' => 'Already that status; nothing changed.',
        'changed_meanwhile' => 'Somebody changed this account a moment ago; look again.',
        'not_found' => 'That account no longer exists.', 'bad_status' => 'Choose active or suspended.',
        'system_account' => 'That account is closed or a system account; its status is not set here.',
        'own_account' => 'You cannot change your own account this way. Ask another administrator.',
        'staff_account' => 'That is a staff account. Change it from People & access, not from the members list.',
        'last_super_admin' => 'That is the last active super administrator. Make somebody else a super administrator first.',
        'reason_required' => 'Give a reason for the suspension (at least ' . MAM_MEMBER_REASON_MIN . ' characters). It is kept with the record.',
    ];
}
