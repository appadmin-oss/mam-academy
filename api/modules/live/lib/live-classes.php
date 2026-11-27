<?php
/**
 * Live online classes (schema v101): Jitsi as a Service (JaaS, 8x8.vc) with
 * signed tokens, and attendance worked out from who was actually in the call.
 *
 * Why JaaS and not a link to meet.jit.si or Google Meet: a class with children
 * in it needs to know WHO is in the room and for HOW LONG, and needs adults to
 * be in charge of it. Public meet.jit.si lets anybody with the link in under
 * any name and reports nothing. JaaS lets the academy sign a token for each
 * person (a name and an id we chose), make only staff moderators, keep a lobby
 * the teacher lets people in from, turn private chat off, and send a webhook
 * on every join and leave. It is free up to 25 monthly active users.
 * Google Meet stays as the fallback (meetings.php's Reports API sync).
 *
 * How presence is measured — two sources, combined:
 *   · WEBHOOKS (PARTICIPANT_JOINED / PARTICIPANT_LEFT), signed, deduplicated
 *     on their idempotency key; the user is the id WE put in their token.
 *   · A HEARTBEAT the class page sends once a minute while the call is open,
 *     so attendance still works where no webhook is configured (and on a
 *     dropped connection, where the webhook says "left" and the person comes
 *     straight back).
 * Each source becomes intervals; the union is time present. A disconnect and
 * rejoin is one stay, not two, and nothing is counted twice.
 *
 * What it writes: session_attendance for each student (online, minutes late,
 * minutes present, present / late / left early) — never over a mark a person
 * made, which is the rule every attendance writer here follows — and
 * meeting_reconciliation rows for the record.
 *
 * Safeguarding: for a class with a child in it, students wait in the lobby
 * until a teacher admits them, nobody but staff can mute, remove or record,
 * private chat is off, and the summary says how many adults were present —
 * one adult alone with children online is the case to notice.
 */
declare(strict_types=1);

const MAM_LIVE_OPEN_BEFORE_MIN = 15;   // the room opens this long before the start
const MAM_LIVE_CLOSE_AFTER_MIN = 30;   // and stays joinable this long after the end
const MAM_LIVE_LATE_GRACE_MIN = 5;
const MAM_LIVE_BEAT_SEC = 60;

/* ─── Configuration ─────────────────────────────────────────────────────── */

function mam_live_cfg(): array {
    $get = static fn(string $k, string $env): string => trim((string) mam_setting($k, (string) mam_env($env, '')));
    return ['app_id' => $get('jaas_app_id', 'JAAS_APP_ID'), 'kid' => $get('jaas_kid', 'JAAS_KID'),
            'private_key' => (string) mam_setting('jaas_private_key', (string) mam_env('JAAS_PRIVATE_KEY', '')),
            'webhook_secret' => $get('jaas_webhook_secret', 'JAAS_WEBHOOK_SECRET')];
}

function mam_live_jaas_ready(): bool {
    $c = mam_live_cfg();
    return $c['app_id'] !== '' && $c['kid'] !== '' && str_contains($c['private_key'], 'PRIVATE KEY');
}

/** Which provider a session uses: its own, else the academy's default; JaaS only when it is set up. */
function mam_live_provider(array $sess): string {
    $p = (string) ($sess['meeting_provider'] ?? '');
    if ($p === '' || $p === 'jitsi') $p = 'jaas';   // no public meet.jit.si: a room anyone with the link can enter is not for children
    if ($p === 'jaas' && !mam_live_jaas_ready()) return 'none';
    // 'zoom' (meeting-links.php) joins by its generated link, exactly as 'meet' does.
    return in_array($p, ['jaas', 'meet', 'zoom'], true) ? $p : 'none';
}

/* ─── Tokens (RS256) ─────────────────────────────────────────────────────── */


/**
 * A JaaS token for one person in one room. Only staff are moderators, and
 * recording is off for everybody unless the academy turns it on — recording
 * children needs a consent and a retention period first.
 */
function mam_live_jwt(array $cfg, string $room, array $who, bool $moderator, int $now, int $ttl = 4 * 3600, ?int $nbf = null, ?int $exp = null): string {
    $head = ['alg' => 'RS256', 'kid' => $cfg['kid'], 'typ' => 'JWT'];
    $rec = $moderator && (int) mam_setting_num('live_recording_enabled', 0) === 1;
    $body = ['aud' => 'jitsi', 'iss' => 'chat', 'sub' => $cfg['app_id'], 'room' => $room,
             // nbf/exp from the session's own window when given: a token cannot open the room outside its class.
             'iat' => $now, 'nbf' => $nbf ?? ($now - 30), 'exp' => $exp ?? ($now + $ttl),
             'context' => ['user' => ['id' => 'u' . (int) $who['id'], 'name' => (string) $who['name'],
                                      'avatar' => preg_match('~^https://~', (string) ($who['avatar_url'] ?? '')) ? (string) $who['avatar_url'] : '',
                                      'moderator' => $moderator ? 'true' : 'false'],
                           'features' => ['livestreaming' => 'false', 'recording' => $rec ? 'true' : 'false',
                                          'transcription' => 'false', 'outbound-call' => 'false', 'sip-outbound-call' => 'false']]];
    $input = mam_b64url_encode(json_encode($head)) . '.' . mam_b64url_encode(json_encode($body, JSON_UNESCAPED_SLASHES));
    $key = openssl_pkey_get_private($cfg['private_key']);
    if ($key === false || !openssl_sign($input, $sig, $key, OPENSSL_ALGO_SHA256)) throw new RuntimeException('jaas_key_invalid');
    return $input . '.' . mam_b64url_encode($sig);
}

/* ─── Who may join, and when ─────────────────────────────────────────────── */

function mam_live_session(int $sid): ?array {
    return mam_db_row('SELECT * FROM class_sessions WHERE id = ?', [$sid]);
}

/** teacher | student | admin, or null. A teacher is the cohort's, the enrolment's, or the substitute. */
function mam_live_role(array $sess, array $user): ?string {
    $uid = (int) $user['id']; $cid = (int) ($sess['cohort_id'] ?? 0);
    if ((int) ($sess['substitute_id'] ?? 0) === $uid) return 'teacher';
    if ($cid > 0 && function_exists('mam_space_teaches_cohort') && mam_space_teaches_cohort($cid, $uid)) return 'teacher';
    if (mam_user_has_role($user, 'admin')) return 'admin';
    if ($cid > 0 && mam_db_row("SELECT 1 FROM course_enrollments WHERE cohort_id = ? AND student_id = ? AND status IN ('active','completed')", [$cid, $uid])) return 'student';
    return null;
}

/** [opens, closes] as Unix times. */
function mam_live_window(array $sess): array {
    $start = strtotime((string) $sess['scheduled_at'] . (str_contains((string) $sess['scheduled_at'], 'Z') ? '' : ' UTC'));
    $len = max(15, (int) ($sess['duration_min'] ?? 60));
    return [$start - MAM_LIVE_OPEN_BEFORE_MIN * 60, $start + ($len + MAM_LIVE_CLOSE_AFTER_MIN) * 60, $start, $len];
}

/**
 * Everything the class page needs to put this person in the call — or a
 * sentence saying why not. Throws RuntimeException(code).
 */
function mam_live_join(int $sid, array $user, ?int $now = null): array {
    $now ??= time();
    $sess = mam_live_session($sid);
    if (!$sess) throw new RuntimeException('not_found');
    $role = mam_live_role($sess, $user);
    if (!$role) throw new RuntimeException('not_yours');
    if (in_array((string) $sess['status'], ['cancelled', 'canceled'], true)) throw new RuntimeException('cancelled');
    [$open, $close, $start, $len] = mam_live_window($sess);
    $staff = $role !== 'student';
    if (!$staff && $now < $open) throw new RuntimeException('not_open_yet');
    if ($now > $close) throw new RuntimeException('over');
    $provider = mam_live_provider($sess);
    $minors = function_exists('mam_vetting_cohort_has_minor') && $sess['cohort_id'] ? mam_vetting_cohort_has_minor((int) $sess['cohort_id']) : true;
    $room = mam_meeting_jitsi_room($sid);
    $out = ['session_id' => $sid, 'title' => (string) ($sess['title'] ?: 'Class'), 'provider' => $provider, 'role' => $role,
            'moderator' => $staff, 'starts_at' => gmdate('c', $start), 'minutes' => $len, 'minors' => $minors,
            'beat_sec' => MAM_LIVE_BEAT_SEC, 'name' => (string) $user['name']];
    if ($provider === 'jaas') {
        $cfg = mam_live_cfg();
        $out += ['domain' => '8x8.vc', 'room' => $cfg['app_id'] . '/' . $room,
                 'script' => 'https://8x8.vc/' . rawurlencode($cfg['app_id']) . '/external_api.js',
                 // Valid from the check-in window opening to the end + grace, for this person only.
                 'jwt' => mam_live_jwt($cfg, $room, $user, $staff, $now, 0,
                     $start - max(MAM_LIVE_OPEN_BEFORE_MIN, (int) mam_setting_num('checkin_open_before_min', 30)) * 60, $close),
                 // Students arrive in the lobby when a child is in the class; a teacher lets them in.
                 'lobby' => $minors,
                 'config' => ['prejoinConfig' => ['enabled' => true], 'disablePrivateChat' => true, 'disableDeepLinking' => true,
                              'startWithAudioMuted' => !$staff, 'startWithVideoMuted' => false,
                              'remoteVideoMenu' => ['disableKick' => !$staff, 'disableGrantModerator' => true],
                              'disableRemoteMute' => !$staff, 'fileRecordingsEnabled' => false, 'liveStreamingEnabled' => false]];
    } elseif (in_array($provider, ['meet', 'zoom'], true) && !empty($sess['meeting_link'])) {
        $out['link'] = (string) $sess['meeting_link'];
    } else {
        throw new RuntimeException('live_not_set_up');
    }
    mam_live_event($sid, (int) $user['id'], 'opened', $now, 'page', 'open:' . $sid . ':' . (int) $user['id'] . ':' . gmdate('YmdHi', $now));
    return $out;
}

/* ─── Events ─────────────────────────────────────────────────────────────── */

/** Record one event; a repeated idempotency key is ignored. */
function mam_live_event(int $sid, ?int $uid, string $kind, int $at, string $source, string $idem, string $raw = ''): bool {
    if (mam_db_row('SELECT 1 FROM live_events WHERE idem = ?', [$idem])) return false;
    mam_db_insert('live_events', ['session_id' => $sid, 'user_id' => $uid, 'kind' => $kind, 'at' => gmdate('Y-m-d H:i:s', $at),
        'source' => $source, 'idem' => mb_substr($idem, 0, 160), 'raw' => $raw !== '' ? mb_substr($raw, 0, 4000) : null]);
    return true;
}

/** The page says "still here" once a minute. One per person per minute counts. */
function mam_live_beat(int $sid, array $user, ?int $now = null): void {
    $now ??= time();
    $sess = mam_live_session($sid);
    if (!$sess || !mam_live_role($sess, $user)) throw new RuntimeException('not_yours');
    [$open, $close] = mam_live_window($sess);
    if ($now < $open - 3600 || $now > $close + 600) return;
    mam_live_event($sid, (int) $user['id'], 'beat', $now, 'beat', 'beat:' . $sid . ':' . (int) $user['id'] . ':' . gmdate('YmdHi', $now));
}

/** Room name → session id, refusing a name whose tag is not ours. */
function mam_live_session_of_room(string $room): ?int {
    $room = (string) preg_replace('#^.*/#', '', $room);
    if (!preg_match('/^MAM-(\d+)-[0-9a-f]{12}$/', $room, $m)) return null;
    $sid = (int) $m[1];
    return hash_equals(mam_meeting_jitsi_room($sid), $room) ? $sid : null;
}

/**
 * Verify a JaaS webhook. The header is `t=<ts>,v1=<sig>`; the signature is an
 * HMAC-SHA256 of "<ts>.<body>" with the webhook secret, base64 (hex is
 * accepted too). Older than ten minutes is refused, so a captured request
 * cannot be replayed.
 */
function mam_live_webhook_ok(string $header, string $body, string $secret, ?int $now = null): bool {
    if ($secret === '' || !preg_match('/t=(\d+)/', $header, $t) || !preg_match_all('/v1=([A-Za-z0-9+\/=_-]+)/', $header, $v)) return false;
    $ts = (int) $t[1];
    if ($ts > 1e12) $ts = intdiv($ts, 1000);
    if (abs(($now ?? time()) - $ts) > 600) return false;
    $mac = hash_hmac('sha256', $t[1] . '.' . $body, $secret, true);
    foreach ($v[1] as $sig) {
        if (hash_equals(base64_encode($mac), $sig) || hash_equals(bin2hex($mac), strtolower($sig)) || hash_equals(mam_b64url_encode($mac), $sig)) return true;
    }
    return false;
}

/** Take one webhook body. Returns what was recorded, or a reason it was not. */
function mam_live_webhook(array $p): string {
    $type = (string) ($p['eventType'] ?? '');
    if (!in_array($type, ['PARTICIPANT_JOINED', 'PARTICIPANT_LEFT'], true)) return 'ignored';
    $sid = mam_live_session_of_room((string) ($p['fqn'] ?? ''));
    if (!$sid) return 'unknown_room';
    $d = (array) ($p['data'] ?? []);
    $uid = null;
    foreach ([$d['id'] ?? null, $d['participantId'] ?? null] as $cand) {
        if (is_string($cand) && preg_match('/^u(\d+)$/', $cand, $m)) { $uid = (int) $m[1]; break; }
    }
    $ts = (int) ($p['timestamp'] ?? 0); if ($ts > 1e12) $ts = intdiv($ts, 1000);
    if ($ts <= 0) $ts = time();
    $idem = 'jaas:' . (string) ($p['idempotencyKey'] ?? hash('sha256', json_encode($p)));
    $ok = mam_live_event($sid, $uid, $type === 'PARTICIPANT_JOINED' ? 'joined' : 'left', $ts, 'webhook', $idem,
        json_encode(['name' => $d['name'] ?? null, 'moderator' => $d['moderator'] ?? null, 'reason' => $d['disconnectReason'] ?? null]));
    return $ok ? 'recorded' : 'duplicate';
}

/* ─── From events to attendance ─────────────────────────────────────────── */

/**
 * Each person's time in the call as merged intervals [from, to] (Unix).
 * A joined event opens a stay that the next left closes (or the class end
 * does); a heartbeat is the minute before it. Overlaps merge.
 */
function mam_live_intervals(int $sid): array {
    $sess = mam_live_session($sid);
    [, $close, $start, $len] = mam_live_window($sess);
    $end = $start + $len * 60;
    $by = [];
    foreach (mam_db_all("SELECT user_id, kind, at FROM live_events WHERE session_id = ? AND user_id IS NOT NULL AND kind IN ('joined','left','beat') ORDER BY at, id", [$sid]) as $e) {
        $by[(int) $e['user_id']][] = [$e['kind'], strtotime($e['at'] . ' UTC')];
    }
    $out = [];
    foreach ($by as $uid => $evs) {
        $iv = []; $openAt = null;
        foreach ($evs as [$k, $t]) {
            if ($k === 'beat') { $iv[] = [$t - MAM_LIVE_BEAT_SEC, $t]; continue; }
            if ($k === 'joined') { if ($openAt === null) $openAt = $t; continue; }
            if ($k === 'left' && $openAt !== null) { $iv[] = [$openAt, $t]; $openAt = null; }
        }
        if ($openAt !== null) $iv[] = [$openAt, max($openAt, min($close, max($end, $openAt)))];
        usort($iv, static fn($a, $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($iv as $x) {
            if ($merged && $x[0] <= $merged[count($merged) - 1][1] + 90) $merged[count($merged) - 1][1] = max($merged[count($merged) - 1][1], $x[1]);
            else $merged[] = $x;
        }
        $out[$uid] = $merged;
    }
    return $out;
}

/**
 * Work out who attended and for how long, and write it. Never overwrites a
 * mark a person made (method 'instructor'). Returns the summary the teacher
 * sees: per person minutes, how late, the status written, and adults present.
 */
function mam_live_reconcile(int $sid): array {
    $sess = mam_live_session($sid);
    if (!$sess) throw new RuntimeException('not_found');
    [, , $start, $len] = mam_live_window($sess);
    $end = $start + $len * 60;
    $rows = []; $adults = 0;
    mam_db()->prepare("DELETE FROM meeting_reconciliation WHERE session_id = ? AND provider = 'jaas' AND source = 'live'")->execute([$sid]);
    foreach (mam_live_intervals($sid) as $uid => $iv) {
        $u = mam_db_row('SELECT * FROM users WHERE id = ?', [$uid]);
        if (!$u || !$iv) continue;
        $role = mam_live_role($sess, $u);
        $secs = 0; foreach ($iv as [$a, $b]) $secs += max(0, min($b, $end + MAM_LIVE_CLOSE_AFTER_MIN * 60) - max($a, $start - MAM_LIVE_OPEN_BEFORE_MIN * 60));
        $mins = (int) round($secs / 60);
        $first = $iv[0][0]; $last = $iv[count($iv) - 1][1];
        $late = max(0, (int) floor(($first - $start) / 60));
        mam_db_insert('meeting_reconciliation', ['session_id' => $sid, 'subject_type' => $role === 'student' ? 'student' : 'instructor',
            'subject_id' => $uid, 'display_name' => (string) $u['name'], 'join_at' => gmdate('Y-m-d H:i:s', $first),
            'leave_at' => gmdate('Y-m-d H:i:s', $last), 'minutes' => $mins, 'provider' => 'jaas', 'source' => 'live']);
        if ($role !== 'student') { if ($mins >= 5) $adults++; $rows[] = ['user_id' => $uid, 'name' => $u['name'], 'role' => $role, 'minutes' => $mins, 'late' => $late, 'written' => null]; continue; }
        $status = $late > MAM_LIVE_LATE_GRACE_MIN ? 'late' : 'present';
        if ($last < $end - 10 * 60 && $mins < $len * 0.75) $status = 'left_early';
        if ($mins < 3) $status = 'absent';
        $have = mam_db_row('SELECT * FROM session_attendance WHERE session_id = ? AND student_id = ?', [$sid, $uid]);
        $fields = ['status' => $status, 'mode' => 'online', 'checked_in_at' => gmdate('Y-m-d H:i:s', $first), 'clocked_out_at' => gmdate('Y-m-d H:i:s', $last),
                   'duration_min' => $mins, 'minutes_late' => $late, 'updated_at' => gmdate('Y-m-d H:i:s')];
        $written = $status;
        if ($have && (string) $have['method'] === 'instructor' && $have['marked_by'] !== null) {
            // A person marked this. Keep their mark; fill in only the measured times.
            mam_db_update('session_attendance', array_diff_key($fields, ['status' => 1]), ['id' => (int) $have['id']]);
            $written = (string) $have['status'] . ' (kept)';
        } elseif ($have) {
            mam_db_update('session_attendance', $fields + ['method' => 'online'], ['id' => (int) $have['id']]);
        } else {
            mam_db_insert('session_attendance', $fields + ['session_id' => $sid, 'student_id' => $uid, 'method' => 'online', 'created_at' => gmdate('Y-m-d H:i:s')]);
        }
        $rows[] = ['user_id' => $uid, 'name' => $u['name'], 'role' => 'student', 'minutes' => $mins, 'late' => $late, 'written' => $written];
    }
    $minors = $sess['cohort_id'] && function_exists('mam_vetting_cohort_has_minor') ? mam_vetting_cohort_has_minor((int) $sess['cohort_id']) : true;
    $students = count(array_filter($rows, static fn($r) => $r['role'] === 'student'));
    mam_audit('live_reconciled', 'class_session', $sid, ['students' => $students, 'adults' => $adults]);
    return ['rows' => $rows, 'adults' => $adults, 'students' => $students,
            'single_adult' => $minors && $students > 0 && $adults < 2,
            'note' => $minors && $students > 0 && $adults < 2
                ? 'One adult was with the children for this class. The academy\'s rule for online classes with children is two adults, or a recorded session.' : null];
}

/** The cron sweep: reconcile classes that ended in the last day and had any presence. */
function mam_live_sweep(int $lookbackHours = 24): int {
    $n = 0;
    $from = gmdate('Y-m-d H:i:s', time() - $lookbackHours * 3600);
    foreach (mam_db_all("SELECT DISTINCT e.session_id FROM live_events e WHERE e.at >= ?", [$from]) as $r) {
        $s = mam_live_session((int) $r['session_id']);
        if (!$s) continue;
        [, $close] = mam_live_window($s);
        if (time() < $close) continue;
        try { mam_live_reconcile((int) $r['session_id']); $n++; } catch (Throwable $e) { mam_log('error', 'live_sweep_failed', ['session' => (int) $r['session_id'], 'err' => $e->getMessage()]); }
    }
    return $n;
}
