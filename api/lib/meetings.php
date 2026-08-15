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

/* ── §11 live Google Meet participation ──────────────────────────────────────
 *
 * The Admin SDK Reports API is the only Google surface that reports who was in
 * a Meet and for how long. It needs a service account with domain-wide
 * delegation impersonating a Workspace admin — there is no user-consent flow
 * that works for an unattended cron.
 *
 * Everything here is split so the logic is testable without credentials or a
 * network: the JWT builder, the meeting-code extractor and the activities
 * parser are pure functions with their own tests; only
 * `mam_meeting_google_fetch()` touches the wire, and it is inert until the two
 * settings are present. §11 says the MAM clock-in remains the official record
 * where Meet data cannot be retrieved — so a failure here degrades to the CSV
 * import rather than throwing.
 */

const MAM_GOOGLE_REPORTS_SCOPE = 'https://www.googleapis.com/auth/admin.reports.audit.readonly';
const MAM_GOOGLE_TOKEN_URL     = 'https://oauth2.googleapis.com/token';
const MAM_GOOGLE_REPORTS_URL   = 'https://admin.googleapis.com/admin/reports/v1/activity/users/all/applications/meet';

/** Is the live Google Reports API configured? */
function mam_meeting_google_configured(): bool {
    return mam_meeting_google_service_account() !== null
        && trim((string) mam_setting('google_workspace_admin', '')) !== '';
}

/**
 * The parsed service-account JSON, or null when absent/unusable. Validates the
 * two fields that actually matter (client_email + private_key) so a truncated
 * paste is caught at configuration time rather than at 3am in the cron.
 */
function mam_meeting_google_service_account(): ?array {
    $raw = trim((string) mam_setting('google_meet_sa_json', ''));
    if ($raw === '') return null;
    $sa = json_decode($raw, true);
    if (!is_array($sa)) return null;
    if (empty($sa['client_email']) || empty($sa['private_key'])) return null;
    return $sa;
}

/** base64url, no padding — the JWT alphabet. */
function mam_google_b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

/**
 * Build a signed RS256 assertion for the service account, impersonating
 * `$subject` (the delegated Workspace admin). Returns the compact JWT.
 * Throws on an unusable key rather than returning something unsigned.
 */
function mam_google_jwt_assertion(array $sa, string $scope, string $subject, ?int $now = null, int $ttl = 3600): string {
    if (empty($sa['client_email']) || empty($sa['private_key'])) throw new RuntimeException('bad_service_account');
    $now = $now ?? time();
    $header  = ['alg' => 'RS256', 'typ' => 'JWT'];
    if (!empty($sa['private_key_id'])) $header['kid'] = (string) $sa['private_key_id'];
    $claims = [
        'iss'   => (string) $sa['client_email'],
        'scope' => $scope,
        'aud'   => (string) ($sa['token_uri'] ?? MAM_GOOGLE_TOKEN_URL),
        'iat'   => $now,
        'exp'   => $now + max(60, min(3600, $ttl)),
    ];
    // Domain-wide delegation: without `sub` the token is the robot's own, which
    // has no Reports access at all.
    if ($subject !== '') $claims['sub'] = $subject;

    $signingInput = mam_google_b64((string) json_encode($header)) . '.' . mam_google_b64((string) json_encode($claims));
    $key = openssl_pkey_get_private((string) $sa['private_key']);
    if ($key === false) throw new RuntimeException('bad_private_key');
    $sig = '';
    $ok  = openssl_sign($signingInput, $sig, $key, OPENSSL_ALGO_SHA256);
    if (!$ok) throw new RuntimeException('sign_failed');
    return $signingInput . '.' . mam_google_b64($sig);
}

/**
 * Exchange the assertion for an access token. Cached in `settings` until a
 * minute before expiry — the Reports sweep runs per session, and minting a
 * fresh token for each one would be both slow and rude.
 */
function mam_meeting_google_token(bool $force = false): ?string {
    $sa = mam_meeting_google_service_account();
    $subject = trim((string) mam_setting('google_workspace_admin', ''));
    if (!$sa || $subject === '') return null;

    if (!$force) {
        $cached = (string) mam_setting('google_meet_token_cache', '');
        if ($cached !== '') {
            $c = json_decode($cached, true);
            if (is_array($c) && !empty($c['token']) && (int) ($c['expires_at'] ?? 0) > time() + 60) return (string) $c['token'];
        }
    }

    try {
        $jwt = mam_google_jwt_assertion($sa, MAM_GOOGLE_REPORTS_SCOPE, $subject);
    } catch (\Throwable $e) {
        mam_log('error', 'google_meet_jwt_failed', ['error' => $e->getMessage()]);
        return null;
    }

    $body = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt,
    ]);
    $ch = curl_init((string) ($sa['token_uri'] ?? MAM_GOOGLE_TOKEN_URL));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $res  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $code !== 200) {
        mam_log('error', 'google_meet_token_failed', ['http' => $code]);
        return null;
    }
    $j = json_decode((string) $res, true);
    if (!is_array($j) || empty($j['access_token'])) return null;
    mam_setting_set('google_meet_token_cache', (string) json_encode([
        'token'      => (string) $j['access_token'],
        'expires_at' => time() + max(60, (int) ($j['expires_in'] ?? 3600)),
    ]));
    return (string) $j['access_token'];
}

/**
 * Extract a Meet code (`abc-defg-hij`) from a join link. Returns null for a
 * Jitsi/other link — the caller then queries by time window alone, which is
 * correct but coarser.
 */
function mam_meeting_meet_code(?string $link): ?string {
    $link = trim((string) $link);
    if ($link === '') return null;
    if (stripos($link, 'meet.google.com') === false) return null;
    if (preg_match('~meet\.google\.com/(?:lookup/)?([a-z]{3}-[a-z]{4}-[a-z]{3}|[a-z0-9_-]{6,})~i', $link, $m)) {
        return strtolower($m[1]);
    }
    return null;
}

/**
 * Turn a Reports API activities payload into reconciliation rows.
 *
 * Reports emits one `call_ended` event per participant per call, carrying the
 * participant's identifier, display name and `duration_seconds`. The event's
 * own timestamp is when they LEFT, so the join time is derived by subtracting
 * the duration — Reports does not report a join timestamp directly.
 *
 * Pure: no network, no clock, no database. `$meetingCode` filters to one
 * meeting when the session's link gave us a code.
 */
function mam_meeting_google_parse_activities(array $payload, ?string $meetingCode = null): array {
    $out = [];
    foreach (($payload['items'] ?? []) as $item) {
        $leftAtIso = (string) ($item['id']['time'] ?? '');
        foreach (($item['events'] ?? []) as $ev) {
            if ((string) ($ev['name'] ?? '') !== 'call_ended') continue;
            $p = [];
            foreach (($ev['parameters'] ?? []) as $param) {
                $name = (string) ($param['name'] ?? '');
                if ($name === '') continue;
                // Reports types its parameters; take whichever field is present.
                $p[$name] = $param['value'] ?? $param['intValue'] ?? $param['boolValue'] ?? null;
            }
            $code = strtolower(trim((string) ($p['meeting_code'] ?? '')));
            if ($meetingCode !== null && $code !== '' && $code !== $meetingCode) continue;

            $email = strtolower(trim((string) ($p['identifier'] ?? '')));
            // A dial-in or anonymous participant has no email identifier; keep
            // the row (the operator can still see who was there) but it will not
            // map to a user.
            $name  = trim((string) ($p['display_name'] ?? ''));
            if ($email === '' && $name === '') continue;

            $duration = (int) ($p['duration_seconds'] ?? 0);
            $leaveTs  = $leftAtIso !== '' ? strtotime($leftAtIso) : false;
            $joinAt = $leaveAt = null;
            if ($leaveTs !== false) {
                $leaveAt = date('Y-m-d H:i:s', $leaveTs);
                if ($duration > 0) $joinAt = date('Y-m-d H:i:s', $leaveTs - $duration);
            }
            $out[] = [
                'name'     => $name !== '' ? $name : $email,
                'email'    => $email,
                'join_at'  => $joinAt,
                'leave_at' => $leaveAt,
            ];
        }
    }
    return $out;
}

/**
 * Live Google Meet fetch for a session (§11). Returns reconciliation rows, or
 * null when the integration is not configured or the call fails — callers then
 * fall back to the CSV import and the portal clock-in, which §11 designates the
 * official record.
 *
 * The query window is padded either side of the scheduled slot so a class that
 * started late or overran is still captured.
 */
function mam_meeting_google_fetch(int $sessionId, int $padMin = 60): ?array {
    if (!mam_meeting_google_configured()) return null;
    $sess = mam_db_row('SELECT id, scheduled_at, duration_min, meeting_link FROM class_sessions WHERE id = ?', [$sessionId]);
    if (!$sess || empty($sess['scheduled_at'])) return null;
    $startTs = strtotime((string) $sess['scheduled_at']);
    if ($startTs === false) return null;

    $token = mam_meeting_google_token();
    if ($token === null) return null;

    $from = gmdate('Y-m-d\TH:i:s\Z', $startTs - $padMin * 60);
    $to   = gmdate('Y-m-d\TH:i:s\Z', $startTs + ((int) ($sess['duration_min'] ?? 60) + $padMin) * 60);
    $url  = MAM_GOOGLE_REPORTS_URL . '?' . http_build_query([
        'startTime'  => $from,
        'endTime'    => $to,
        'eventName'  => 'call_ended',
        'maxResults' => 1000,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $res  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $code !== 200) {
        mam_log('error', 'google_meet_fetch_failed', ['session' => $sessionId, 'http' => $code]);
        return null;
    }
    $payload = json_decode((string) $res, true);
    if (!is_array($payload)) return null;

    return mam_meeting_google_parse_activities($payload, mam_meeting_meet_code($sess['meeting_link'] ?? null));
}

/**
 * Fetch AND store the live Meet participation for a session, under
 * `source='api'` so it never collides with an operator's CSV import (§11 keeps
 * both alongside the official clock-in). Returns the row count, or null when
 * the integration is unavailable — the caller decides whether that is an error.
 */
function mam_meeting_google_sync(int $sessionId): ?int {
    $rows = mam_meeting_google_fetch($sessionId);
    if ($rows === null) return null;
    return mam_meeting_reconcile_import($sessionId, $rows, 'meet', 'api');
}

/**
 * Cron sweep: reconcile recently-finished online sessions against Google.
 *
 * Reports data lags the call by a few minutes, so a session is only swept once
 * it has been over for `$settleMin`. Deduped through the `events` table, which
 * means a session is attempted once and a transient Google outage does not
 * cause it to be retried forever — an operator can still pull it by hand.
 * Never throws: a cron that dies on a network blip takes the other sweeps with
 * it.
 */
function mam_meeting_google_sweep(int $lookbackHours = 24, int $settleMin = 10, int $limit = 50): int {
    if (!mam_meeting_google_configured()) return 0;
    $now  = time();
    $from = date('Y-m-d H:i:s', $now - max(1, $lookbackHours) * 3600);
    $rows = mam_db_all(
        "SELECT id, scheduled_at, duration_min FROM class_sessions
          WHERE scheduled_at >= ? AND scheduled_at <= ? AND mode IN ('online','hybrid')
          ORDER BY scheduled_at DESC LIMIT " . max(1, min(200, $limit)),
        [$from, date('Y-m-d H:i:s', $now)]
    );
    $done = 0;
    foreach ($rows as $r) {
        $endTs = strtotime((string) $r['scheduled_at']);
        if ($endTs === false) continue;
        $endTs += (int) ($r['duration_min'] ?? 60) * 60;
        if ($now < $endTs + $settleMin * 60) continue;   // still settling
        // Deduped through `events`, the same mechanism the other sweeps use.
        if (mam_db_row("SELECT id FROM events WHERE type = 'meet.synced' AND entity = 'class_session' AND entity_id = ?", [(int) $r['id']])) continue;
        try {
            $n = mam_meeting_google_sync((int) $r['id']);
            if ($n !== null) {
                $done++;
                mam_db_insert('events', [
                    'type' => 'meet.synced', 'entity' => 'class_session', 'entity_id' => (int) $r['id'],
                    'payload' => (string) json_encode(['rows' => $n]),
                ]);
            }
        } catch (\Throwable $e) {
            mam_log('error', 'google_meet_sweep_failed', ['session' => (int) $r['id'], 'error' => $e->getMessage()]);
        }
    }
    return $done;
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
