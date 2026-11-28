<?php
/**
 * Meeting links, generated — never pasted.
 *
 * Every online or hybrid session gets a working link the moment it is created
 * (a series or a single session), keeps it when it moves, and loses it when it
 * is cancelled. Nobody types a URL.
 *
 * Providers (`meeting_link_provider` picks the one to try first; default
 * 'auto' = this order; a failure falls down the list and is logged):
 *   1. jaas — Jitsi as a Service (8x8.vc) through live-classes.php. The stored
 *      link is the portal's class page (/portal/live.html?session=N); at JOIN a
 *      fresh RS256 token is minted for that one person (staff moderate, students
 *      do not), valid only from the check-in window to the end + grace, in a
 *      room named by an HMAC of the session id. No external call to create it.
 *   2. meet — a Google Calendar event with a Meet conference, created through
 *      the service account meetings.php already uses (google_meet_sa_json +
 *      google_workspace_admin as the delegated user).
 *   3. zoom — Server-to-Server OAuth (ZOOM_ACCOUNT_ID / ZOOM_CLIENT_ID /
 *      ZOOM_CLIENT_SECRET, env preferred, settings as a fallback).
 * There is NO public meet.jit.si path: a room anybody with the link can enter
 * is not acceptable for a class of children. With nothing configured a session
 * still gets its portal link, and the class page says live classes are not set
 * up (an actionable state, not a dead link).
 *
 * The provider's own id is kept in class_sessions.meeting_external_id (v107) so
 * a reschedule moves the same meeting and a cancellation deletes it.
 *
 * All HTTP goes through mam_integration_http(), which a test replaces with a
 * fake transport — no test touches the network.
 */
declare(strict_types=1);

const MAM_MEETING_PROVIDERS = ['auto', 'jaas', 'meet', 'zoom'];  // choices for meeting_link_provider

/**
 * The one HTTP door for integrations. Returns [status, body]. A test sets
 * $GLOBALS['MAM_INTEGRATION_HTTP'] to a callable(method, url, headers, body)
 * and nothing leaves the process.
 */
function mam_integration_http(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): array {
    $fake = $GLOBALS['MAM_INTEGRATION_HTTP'] ?? null;
    if (is_callable($fake)) return $fake($method, $url, $headers, $body);
    if (!function_exists('curl_init')) return [0, ''];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $res === false ? '' : (string) $res];
}

/** The site's own base URL, for links that leave the site (feeds, messages). */
function mam_sched_app_url(): string {
    return rtrim((string) mam_env('APP_URL', 'https://academy.mamcentre.org'), '/');
}

/** A secret: the environment first (kept out of the database), then a setting. */
function mam_integration_secret(string $env, string $setting): string {
    $v = trim((string) mam_env($env, ''));
    return $v !== '' ? $v : trim((string) mam_setting($setting, ''));
}

function mam_meeting_zoom_cfg(): array {
    return ['account_id' => mam_integration_secret('ZOOM_ACCOUNT_ID', 'zoom_account_id'),
            'client_id' => mam_integration_secret('ZOOM_CLIENT_ID', 'zoom_client_id'),
            'client_secret' => mam_integration_secret('ZOOM_CLIENT_SECRET', 'zoom_client_secret')];
}
function mam_meeting_zoom_ready(): bool {
    $c = mam_meeting_zoom_cfg();
    return $c['account_id'] !== '' && $c['client_id'] !== '' && $c['client_secret'] !== '';
}
function mam_meeting_meet_ready(): bool {
    return function_exists('mam_meeting_google_configured') && mam_meeting_google_configured();
}

function mam_meeting_jaas_ready(): bool { return function_exists('mam_live_jaas_ready') && mam_live_jaas_ready(); }

/** The configured providers, in the order they will be tried. Empty when none is set up. */
function mam_meeting_provider_order(): array {
    $pref = (string) mam_setting('meeting_link_provider', 'auto');
    $ready = ['jaas' => mam_meeting_jaas_ready(), 'meet' => mam_meeting_meet_ready(), 'zoom' => mam_meeting_zoom_ready()];
    $order = [];
    if (!empty($ready[$pref])) $order[] = $pref;
    foreach ($ready as $p => $ok) if ($ok && !in_array($p, $order, true)) $order[] = $p;
    return $order;
}

/** ISO local time for providers that take a wall-clock + zone: 2026-02-14T16:00:00. */
function mam_meeting_local_iso(string $at): string { return str_replace(' ', 'T', substr($at, 0, 19)); }

/* ── Google Meet (Calendar API, conferenceData) ─────────────────────────── */

function mam_meeting_meet_token(): ?string {
    $sa = mam_meeting_google_service_account();
    $subject = trim((string) mam_setting('google_workspace_admin', ''));
    if (!$sa || $subject === '') return null;
    $cached = json_decode((string) mam_setting('google_calendar_token_cache', ''), true);
    if (is_array($cached) && !empty($cached['token']) && (int) ($cached['expires_at'] ?? 0) > time() + 60) return (string) $cached['token'];
    $jwt = mam_google_jwt_assertion($sa, 'https://www.googleapis.com/auth/calendar.events', $subject);
    [$code, $res] = mam_integration_http('POST', (string) ($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token'),
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]));
    $j = json_decode($res, true);
    if ($code !== 200 || empty($j['access_token'])) throw new RuntimeException('meet_token_http_' . $code);
    mam_setting_set('google_calendar_token_cache', (string) json_encode(['token' => $j['access_token'], 'expires_at' => time() + (int) ($j['expires_in'] ?? 3600)]));
    return (string) $j['access_token'];
}

function mam_meeting_meet_calendar(): string {
    return rawurlencode((string) mam_setting_or_default('google_meet_calendar_id', 'primary') ?: 'primary');
}

function mam_meeting_meet_create(array $s): array {
    $tok = mam_meeting_meet_token();
    if (!$tok) throw new RuntimeException('meet_not_configured');
    $start = (string) $s['scheduled_at']; $mins = (int) ($s['duration_min'] ?: 60);
    $body = [
        'summary' => (string) ($s['title'] ?: ($s['cohort_title'] ?? 'MAM class')),
        'start' => ['dateTime' => mam_meeting_local_iso($start), 'timeZone' => MAM_SCHED_TZ],
        'end' => ['dateTime' => mam_meeting_local_iso(mam_session_wall((mam_session_ts($start) ?? 0) + $mins * 60)), 'timeZone' => MAM_SCHED_TZ],
        'conferenceData' => ['createRequest' => ['requestId' => 'mam-' . (int) $s['id'] . '-' . substr(hash('sha256', $start . mam_app_key()), 0, 10),
                                                 'conferenceSolutionKey' => ['type' => 'hangoutsMeet']]],
    ];
    [$code, $res] = mam_integration_http('POST', 'https://www.googleapis.com/calendar/v3/calendars/' . mam_meeting_meet_calendar() . '/events?conferenceDataVersion=1',
        ['Authorization: Bearer ' . $tok, 'Content-Type: application/json'], (string) json_encode($body));
    $j = json_decode($res, true);
    $link = (string) ($j['hangoutLink'] ?? '');
    if ($code < 200 || $code >= 300 || $link === '' || empty($j['id'])) throw new RuntimeException('meet_create_http_' . $code);
    return ['link' => $link, 'external_id' => 'meet:' . $j['id']];
}

/* ── Zoom (Server-to-Server OAuth) ──────────────────────────────────────── */

function mam_meeting_zoom_token(): string {
    $c = mam_meeting_zoom_cfg();
    if ($c['account_id'] === '') throw new RuntimeException('zoom_not_configured');
    $cached = json_decode((string) mam_setting('zoom_token_cache', ''), true);
    if (is_array($cached) && !empty($cached['token']) && (int) ($cached['expires_at'] ?? 0) > time() + 60) return (string) $cached['token'];
    [$code, $res] = mam_integration_http('POST', 'https://zoom.us/oauth/token?' . http_build_query(['grant_type' => 'account_credentials', 'account_id' => $c['account_id']]),
        ['Authorization: Basic ' . base64_encode($c['client_id'] . ':' . $c['client_secret']), 'Content-Type: application/x-www-form-urlencoded'], '');
    $j = json_decode($res, true);
    if ($code !== 200 || empty($j['access_token'])) throw new RuntimeException('zoom_token_http_' . $code);
    mam_setting_set('zoom_token_cache', (string) json_encode(['token' => $j['access_token'], 'expires_at' => time() + (int) ($j['expires_in'] ?? 3600)]));
    return (string) $j['access_token'];
}

function mam_meeting_zoom_body(array $s): array {
    return ['topic' => (string) ($s['title'] ?: ($s['cohort_title'] ?? 'MAM class')), 'type' => 2,
            'start_time' => mam_meeting_local_iso((string) $s['scheduled_at']), 'timezone' => MAM_SCHED_TZ,
            'duration' => (int) ($s['duration_min'] ?: 60),
            // A waiting room, never join-before-host: the academy teaches children.
            'settings' => ['waiting_room' => true, 'join_before_host' => false, 'auto_recording' => 'none']];
}

function mam_meeting_zoom_create(array $s): array {
    $tok = mam_meeting_zoom_token();
    [$code, $res] = mam_integration_http('POST', 'https://api.zoom.us/v2/users/me/meetings',
        ['Authorization: Bearer ' . $tok, 'Content-Type: application/json'], (string) json_encode(mam_meeting_zoom_body($s)));
    $j = json_decode($res, true);
    if ($code !== 201 && $code !== 200 || empty($j['join_url']) || empty($j['id'])) throw new RuntimeException('zoom_create_http_' . $code);
    return ['link' => (string) $j['join_url'], 'external_id' => 'zoom:' . $j['id']];
}

/* ── JaaS — the class page ─────────────────────────────────────────────── */

/** The portal's class page for a session; the per-person JaaS token is minted there, at join. */
function mam_meeting_portal_link(int $sessionId): string {
    return mam_sched_app_url() . '/portal/live.html?session=' . $sessionId;
}

/* ── The lifecycle ──────────────────────────────────────────────────────── */

function mam_meeting_session_row(int $sid): ?array {
    return mam_db_row('SELECT cs.*, co.title AS cohort_title FROM class_sessions cs LEFT JOIN cohorts co ON co.id = cs.cohort_id WHERE cs.id = ?', [$sid]);
}

/**
 * Give an online or hybrid session its link. Idempotent: a session that has a
 * generated link keeps it. Never throws — the floor always succeeds.
 * Returns ['provider' => …, 'link' => …, 'fell_back' => [provider => error]].
 */
function mam_meeting_ensure(int $sid): array {
    $s = mam_meeting_session_row($sid);
    if (!$s || !in_array((string) ($s['mode'] ?? ''), ['online', 'hybrid'], true) || ($s['status'] ?? '') === 'cancelled') {
        return ['provider' => null, 'link' => null, 'fell_back' => []];
    }
    if (!empty($s['meeting_link']) && !empty($s['meeting_provider'])) {
        return ['provider' => (string) $s['meeting_provider'], 'link' => (string) $s['meeting_link'], 'fell_back' => []];
    }
    $fell = [];
    foreach (mam_meeting_provider_order() as $p) {
        try {
            $r = $p === 'meet' ? mam_meeting_meet_create($s)
               : ($p === 'zoom' ? mam_meeting_zoom_create($s)
               : ['link' => mam_meeting_portal_link($sid), 'external_id' => null]);
            mam_db_update('class_sessions', ['meeting_link' => $r['link'], 'meeting_provider' => $p, 'meeting_external_id' => $r['external_id']], ['id' => $sid]);
            if ($fell) mam_log('warn', 'meeting_link_fell_back', ['session' => $sid, 'to' => $p, 'errors' => $fell]);
            return ['provider' => $p, 'link' => $r['link'], 'fell_back' => $fell, 'ready' => true];
        } catch (\Throwable $e) {
            $fell[$p] = $e->getMessage();
        }
    }
    // Nothing configured (or everything failed): the session still gets its class
    // page, stored as 'jaas' so it starts working the moment JaaS keys are added;
    // until then the page says live classes are not set up.
    mam_db_update('class_sessions', ['meeting_link' => mam_meeting_portal_link($sid), 'meeting_provider' => 'jaas', 'meeting_external_id' => null], ['id' => $sid]);
    mam_log('warn', 'meeting_link_no_provider', ['session' => $sid, 'errors' => $fell]);
    return ['provider' => 'jaas', 'link' => mam_meeting_portal_link($sid), 'fell_back' => $fell, 'ready' => false];
}

/** After a move: move the provider's meeting too. A provider that refuses is replaced, never left stale. */
function mam_meeting_moved(int $sid): void {
    $s = mam_meeting_session_row($sid);
    if (!$s) return;
    $ext = (string) ($s['meeting_external_id'] ?? '');
    if (!in_array((string) ($s['mode'] ?? ''), ['online', 'hybrid'], true)) return;
    if ($ext === '') { mam_meeting_ensure($sid); return; }
    try {
        if (str_starts_with($ext, 'zoom:')) {
            [$code] = mam_integration_http('PATCH', 'https://api.zoom.us/v2/meetings/' . rawurlencode(substr($ext, 5)),
                ['Authorization: Bearer ' . mam_meeting_zoom_token(), 'Content-Type: application/json'], (string) json_encode(mam_meeting_zoom_body($s)));
            if ($code !== 204 && $code !== 200) throw new RuntimeException('zoom_patch_http_' . $code);
        } elseif (str_starts_with($ext, 'meet:')) {
            $mins = (int) ($s['duration_min'] ?: 60);
            [$code] = mam_integration_http('PATCH', 'https://www.googleapis.com/calendar/v3/calendars/' . mam_meeting_meet_calendar() . '/events/' . rawurlencode(substr($ext, 5)),
                ['Authorization: Bearer ' . mam_meeting_meet_token(), 'Content-Type: application/json'], (string) json_encode([
                    'start' => ['dateTime' => mam_meeting_local_iso((string) $s['scheduled_at']), 'timeZone' => MAM_SCHED_TZ],
                    'end' => ['dateTime' => mam_meeting_local_iso(mam_session_wall((mam_session_ts((string) $s['scheduled_at']) ?? 0) + $mins * 60)), 'timeZone' => MAM_SCHED_TZ]]));
            if ($code < 200 || $code >= 300) throw new RuntimeException('meet_patch_http_' . $code);
        }
    } catch (\Throwable $e) {
        mam_log('warn', 'meeting_move_failed', ['session' => $sid, 'err' => $e->getMessage()]);
        // A meeting at the old time is worse than a new one: start again.
        mam_db_update('class_sessions', ['meeting_link' => null, 'meeting_provider' => null, 'meeting_external_id' => null], ['id' => $sid]);
        mam_meeting_ensure($sid);
    }
}

/** On cancel: delete the provider's meeting and withdraw the link. Best-effort at the provider. */
function mam_meeting_withdraw(int $sid): void {
    $s = mam_db_row('SELECT meeting_external_id FROM class_sessions WHERE id = ?', [$sid]);
    $ext = (string) ($s['meeting_external_id'] ?? '');
    try {
        if (str_starts_with($ext, 'zoom:')) {
            mam_integration_http('DELETE', 'https://api.zoom.us/v2/meetings/' . rawurlencode(substr($ext, 5)), ['Authorization: Bearer ' . mam_meeting_zoom_token()]);
        } elseif (str_starts_with($ext, 'meet:')) {
            mam_integration_http('DELETE', 'https://www.googleapis.com/calendar/v3/calendars/' . mam_meeting_meet_calendar() . '/events/' . rawurlencode(substr($ext, 5)),
                ['Authorization: Bearer ' . mam_meeting_meet_token()]);
        }
    } catch (\Throwable $e) {
        mam_log('warn', 'meeting_withdraw_failed', ['session' => $sid, 'err' => $e->getMessage()]);
    }
    mam_db_update('class_sessions', ['meeting_link' => null, 'meeting_external_id' => null], ['id' => $sid]);
}

/** Give every upcoming online/hybrid session without a link one. Returns how many got one. */
function mam_meeting_backfill(int $limit = 500): array {
    $rows = mam_db_all("SELECT id FROM class_sessions WHERE status = 'scheduled' AND mode IN ('online','hybrid')
                          AND (meeting_link IS NULL OR meeting_link = '') AND scheduled_at >= ? ORDER BY scheduled_at LIMIT " . max(1, min(2000, $limit)),
                       [mam_session_wall(time() - 3600)]);
    $out = ['done' => 0, 'by' => []];
    foreach ($rows as $r) {
        $x = mam_meeting_ensure((int) $r['id']);
        if ($x['link']) { $out['done']++; $out['by'][$x['provider']] = ($out['by'][$x['provider']] ?? 0) + 1; }
    }
    return $out;
}
