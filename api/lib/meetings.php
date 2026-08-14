<?php
/**
 * MAM Academy — instructor clock-in + online-meeting reconciliation
 * (LMS 2.0, PDF §10-11, 14).
 * ─────────────────────────────────────────────────────────────
 *   • Clock-in/out: staff punctuality on the same model as student check-in.
 *     A late clock-in (past the grace window) feeds the accountability ledger's
 *     instructor charge (§14), when that policy is enabled.
 *   • Reconciliation: the portal clock-in is the OFFICIAL attendance record
 *     (§11); a provider's join/leave (Google Meet via CSV import, or Jitsi) is
 *     reconciled ALONGSIDE it. Google's live Reports API is scaffolded but
 *     credential-gated — where it can't run, the CSV import / clock-in stand.
 *   • Jitsi: we own the room name (derived + HMAC-tagged so it's unguessable),
 *     so a join link can be generated with no third-party account.
 */
declare(strict_types=1);

/** Resolved meeting configuration. */
function mam_meeting_config(): array {
    return [
        'clockin_grace_min' => (int) mam_setting_num('instructor_clockin_grace_min', 5),
        'jitsi_domain'      => (string) mam_setting_or_default('jitsi_domain', 'meet.jit.si'),
        'default_provider'  => (string) mam_setting_or_default('meeting_default_provider', 'none'),
    ];
}

/** A stable, unguessable Jitsi room name for a session (HMAC-tagged). */
function mam_meeting_jitsi_room(int $sessionId): string {
    $sig = substr(hash_hmac('sha256', 'jitsi:' . $sessionId, mam_app_key()), 0, 12);
    return 'MAM-' . $sessionId . '-' . $sig;
}

/** The Jitsi join URL for a session. */
function mam_meeting_jitsi_url(int $sessionId): string {
    $cfg = mam_meeting_config();
    $domain = preg_replace('~^https?://~', '', rtrim($cfg['jitsi_domain'], '/'));
    return 'https://' . $domain . '/' . mam_meeting_jitsi_room($sessionId);
}

/**
 * Instructor clocks IN to a session. Idempotent (one row per session+instructor;
 * a second call is a no-op returning the existing state). Marks 'late' past the
 * grace window and — when late_charge_enabled — records the §14 instructor
 * accountability charge. Returns the clock-in row.
 */
function mam_meeting_clock_in(int $sessionId, int $instructorId): array {
    if ($sessionId <= 0 || $instructorId <= 0) throw new RuntimeException('validation');
    $sess = mam_db_row('SELECT id, scheduled_at FROM class_sessions WHERE id = ?', [$sessionId]);
    if (!$sess) throw new RuntimeException('session_not_found');

    $existing = mam_db_row('SELECT * FROM instructor_clockins WHERE session_id = ? AND instructor_id = ?', [$sessionId, $instructorId]);
    if ($existing && $existing['clock_in_at']) return $existing;

    $cfg = mam_meeting_config();
    $now = time();
    $startTs = $sess['scheduled_at'] ? strtotime((string) $sess['scheduled_at']) : false;
    $minsLate = ($startTs !== false && $now > $startTs) ? (int) round(($now - $startTs) / 60) : 0;
    $late = $minsLate > $cfg['clockin_grace_min'];
    $status = $late ? 'late' : 'present';
    $nowStr = date('Y-m-d H:i:s');

    if ($existing) {
        mam_db_update('instructor_clockins', ['clock_in_at' => $nowStr, 'status' => $status, 'minutes_late' => $late ? $minsLate : 0], ['id' => (int) $existing['id']]);
    } else {
        mam_db_insert('instructor_clockins', [
            'session_id' => $sessionId, 'instructor_id' => $instructorId,
            'clock_in_at' => $nowStr, 'status' => $status, 'minutes_late' => $late ? $minsLate : 0, 'source' => 'portal',
        ]);
    }
    mam_audit('instructor_clock_in', 'class_session', $sessionId, ['instructor' => $instructorId, 'status' => $status, 'late' => $minsLate]);
    if ($late && function_exists('mam_accountability_charge')) {
        $acfg = mam_accountability_config();
        if ($acfg['enabled']) mam_accountability_charge('instructor', $instructorId, $sessionId, 'instructor_late', $minsLate);
    }
    return mam_db_row('SELECT * FROM instructor_clockins WHERE session_id = ? AND instructor_id = ?', [$sessionId, $instructorId]);
}

/** Instructor clocks OUT. Requires a prior clock-in. Returns the updated row. */
function mam_meeting_clock_out(int $sessionId, int $instructorId): array {
    $row = mam_db_row('SELECT * FROM instructor_clockins WHERE session_id = ? AND instructor_id = ?', [$sessionId, $instructorId]);
    if (!$row || !$row['clock_in_at']) throw new RuntimeException('not_clocked_in');
    if ($row['clock_out_at']) return $row;
    mam_db_update('instructor_clockins', ['clock_out_at' => date('Y-m-d H:i:s')], ['id' => (int) $row['id']]);
    mam_audit('instructor_clock_out', 'class_session', $sessionId, ['instructor' => $instructorId]);
    return mam_db_row('SELECT * FROM instructor_clockins WHERE id = ?', [(int) $row['id']]);
}

/**
 * Import provider participant rows (a Google Meet participant CSV, or a Jitsi
 * export) for a session. Each row: {name, email?, join_at, leave_at}. Matches
 * email → user where possible, computes minutes, and replaces any prior import
 * for the session+provider. Returns the number of rows stored.
 */
function mam_meeting_reconcile_import(int $sessionId, array $rows, string $provider = 'meet', string $source = 'import'): int {
    if (!mam_db_row('SELECT id FROM class_sessions WHERE id = ?', [$sessionId])) throw new RuntimeException('session_not_found');
    $db = mam_db();
    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM meeting_reconciliation WHERE session_id = ? AND provider = ? AND source = ?')->execute([$sessionId, $provider, $source]);
        $n = 0;
        foreach ($rows as $r) {
            $name  = trim((string) ($r['name'] ?? ''));
            $email = strtolower(trim((string) ($r['email'] ?? '')));
            $joinAt  = trim((string) ($r['join_at'] ?? '')) ?: null;
            $leaveAt = trim((string) ($r['leave_at'] ?? '')) ?: null;
            if ($name === '' && $email === '') continue;
            $subjectId = null; $subjectType = 'student';
            if ($email !== '') {
                $u = mam_db_row('SELECT id, role FROM users WHERE email = ?', [$email]);
                if ($u) { $subjectId = (int) $u['id']; $subjectType = ((string) $u['role'] === 'instructor') ? 'instructor' : 'student'; }
            }
            $minutes = null;
            if ($joinAt && $leaveAt) { $j = strtotime($joinAt); $l = strtotime($leaveAt); if ($j !== false && $l !== false && $l > $j) $minutes = (int) round(($l - $j) / 60); }
            mam_db_insert('meeting_reconciliation', [
                'session_id' => $sessionId, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
                'display_name' => $name ?: $email, 'join_at' => $joinAt, 'leave_at' => $leaveAt,
                'minutes' => $minutes, 'provider' => $provider, 'source' => $source,
            ]);
            $n++;
        }
        if ($own) $db->commit();
    } catch (\Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
    mam_audit('meeting_reconcile_import', 'class_session', $sessionId, ['provider' => $provider, 'rows' => $n]);
    return $n;
}

/** Parse a Google Meet-style participant CSV (Name,Email,Join,Leave) into rows. */
function mam_meeting_parse_csv(string $csv): array {
    $lines = preg_split('/\r\n|\r|\n/', trim($csv));
    if (!$lines) return [];
    $rows = []; $start = 0;
    // Skip a header row if the first line looks like column names.
    if (stripos($lines[0], 'name') !== false && (stripos($lines[0], 'join') !== false || stripos($lines[0], 'email') !== false)) $start = 1;
    for ($i = $start; $i < count($lines); $i++) {
        $line = trim($lines[$i]); if ($line === '') continue;
        $c = str_getcsv($line);
        $rows[] = ['name' => $c[0] ?? '', 'email' => $c[1] ?? '', 'join_at' => $c[2] ?? '', 'leave_at' => $c[3] ?? ''];
    }
    return $rows;
}

/** Is the live Google Reports API configured? (Credential-gated scaffold.) */
function mam_meeting_google_configured(): bool {
    return trim((string) mam_setting('google_meet_sa_json', '')) !== ''
        && trim((string) mam_setting('google_workspace_admin', '')) !== '';
}

/**
 * Live Google Meet fetch — scaffold. Returns null unless service-account
 * credentials + a delegated admin are configured (they aren't in this
 * environment), so callers fall back to the CSV import / clock-in record. The
 * shape mirrors mam_meeting_reconcile_import() rows so a real implementation
 * drops straight in.
 */
function mam_meeting_google_fetch(int $sessionId): ?array {
    if (!mam_meeting_google_configured()) return null;
    // A real implementation would: build a JWT from the service-account JSON,
    // exchange it for an access token with the Reports API scope, query
    // activities?applicationName=meet for the session's Meet code/time window,
    // and map participant identifiers → users. Intentionally not executed here.
    mam_log('info', 'google_meet_fetch_stub', ['session' => $sessionId]);
    return null;
}

/**
 * The reconciled attendance view for a session (§11): the instructor clock-in,
 * the portal (official) student check-ins, and any provider join/leave rows,
 * plus the Jitsi room. Read-only.
 */
function mam_meeting_session_view(int $sessionId): array {
    $sess = mam_db_row('SELECT id, cohort_id, title, scheduled_at, duration_min, meeting_link, meeting_provider FROM class_sessions WHERE id = ?', [$sessionId]);
    if (!$sess) throw new RuntimeException('session_not_found');
    return [
        'session'       => $sess,
        'jitsi_url'     => mam_meeting_jitsi_url($sessionId),
        'instructors'   => mam_db_all('SELECT ic.*, u.name AS instructor_name FROM instructor_clockins ic LEFT JOIN users u ON u.id = ic.instructor_id WHERE ic.session_id = ?', [$sessionId]),
        'checkins'      => mam_db_all("SELECT sa.student_id, sa.status, sa.checked_in_at, sa.method, u.name AS student_name FROM session_attendance sa LEFT JOIN users u ON u.id = sa.student_id WHERE sa.session_id = ?", [$sessionId]),
        'reconciliation'=> mam_db_all('SELECT * FROM meeting_reconciliation WHERE session_id = ? ORDER BY id', [$sessionId]),
        'google_live'   => mam_meeting_google_configured(),
    ];
}
