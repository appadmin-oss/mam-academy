<?php
/**
 * WhatsApp through Kapso — a Meta-compatible WhatsApp Cloud API proxy.
 *
 * Verified 2026-11-23 against docs.kapso.ai (Send a message; Webhooks →
 * Security): POST https://api.kapso.ai/meta/whatsapp/v24.0/{phone_number_id}/messages
 * with `X-API-Key`, a body identical to Meta's; webhooks signed as the hex
 * HMAC-SHA256 of the raw body in `X-Webhook-Signature`. Base URL and version
 * are settings so a version bump is not a code change.
 *
 * Rules, each a way a WhatsApp integration goes wrong:
 *  - Schedule messages are TEMPLATES. Free-form text is allowed only inside the
 *    24 hours after the recipient last wrote to us (whatsapp_inbound), and
 *    nothing about a timetable can wait for that. Template names are settings.
 *  - Consent: only to a person who has EXPLICITLY turned WhatsApp on
 *    (guardian_channel_prefs row with whatsapp = 1). The channel defaults read
 *    "on" for display, but a default is not an opt-in. Safeguarding and money
 *    reach every usable channel, as mam_guardian_channels() decides.
 *  - Guardians and staff only by default. A student's own number is never
 *    messaged unless the academy sets kapso_message_students = 1 — users carry no
 *    date of birth, so "only adult students" cannot be checked here.
 *  - Idempotent per (event, session, recipient): UNIQUE whatsapp_outbound.idem_key.
 *  - Never blocks the change it reports: every failure is a logged row.
 */
declare(strict_types=1);

/**
 * The four schedule templates, their default names, parameter ORDER and the
 * exact body to submit to Meta for approval (category: Utility, language: en).
 * docs/whatsapp-templates.md carries the same text for the owner. Changing a
 * body here means re-approval at Meta; the name is a setting.
 */
const MAM_KAPSO_TEMPLATES = [
    'session_reminder' => ['setting' => 'kapso_tpl_session_reminder', 'default' => 'mam_session_reminder',
        'params' => ['learner', 'class', 'when', 'where'],
        'body' => 'Hello, this is MAM Academy. {{1}} has {{2}} on {{3}} ({{4}}). See you there.'],
    'session_rescheduled' => ['setting' => 'kapso_tpl_session_rescheduled', 'default' => 'mam_session_rescheduled',
        'params' => ['learner', 'class', 'old_when', 'new_when', 'reason'],
        'body' => 'MAM Academy: {{1}}\'s {{2}} has moved from {{3}} to {{4}}. Reason: {{5}}. Your calendar updates automatically if you subscribed.'],
    'session_cancelled' => ['setting' => 'kapso_tpl_session_cancelled', 'default' => 'mam_session_cancelled',
        'params' => ['learner', 'class', 'when', 'reason'],
        'body' => 'MAM Academy: {{1}}\'s {{2}} on {{3}} is cancelled. Reason: {{4}}. A free make-up class has been credited; nothing is charged.'],
    'timetable_new' => ['setting' => 'kapso_tpl_timetable_new', 'default' => 'mam_timetable_ready',
        'params' => ['learner', 'class', 'first_when', 'count'],
        'body' => 'MAM Academy: {{1}}\'s timetable for {{2}} is ready — {{4}} sessions, starting {{3}}. Open the portal to add it to your calendar.'],
    // Meta's own sample template, present on every WhatsApp Business account: the console's test.
    'test' => ['setting' => 'kapso_tpl_test', 'default' => 'hello_world', 'params' => [], 'body' => '(Meta sample template)'],
];

function mam_kapso_cfg(): array {
    return [
        'api_key'         => mam_integration_secret('KAPSO_API_KEY', 'kapso_api_key'),
        'phone_number_id' => trim((string) mam_setting('kapso_phone_number_id', (string) mam_env('KAPSO_PHONE_NUMBER_ID', ''))),
        'base_url'        => rtrim((string) mam_setting('kapso_base_url', 'https://api.kapso.ai/meta/whatsapp'), '/'),
        'version'         => trim((string) mam_setting('kapso_api_version', 'v24.0'), '/'),
        'language'        => (string) mam_setting('kapso_template_language', 'en'),
        'webhook_secret'  => mam_integration_secret('KAPSO_WEBHOOK_SECRET', 'kapso_webhook_secret'),
        'students'        => (string) mam_setting('kapso_message_students', '0') === '1',
    ];
}

function mam_kapso_ready(): bool {
    $c = mam_kapso_cfg();
    return $c['api_key'] !== '' && preg_match('/^\d{5,25}$/', $c['phone_number_id']) === 1;
}

function mam_kapso_template_name(string $key): string {
    $t = MAM_KAPSO_TEMPLATES[$key] ?? null;
    if (!$t) return '';
    return trim((string) mam_setting($t['setting'], $t['default']));
}

/**
 * A phone number as WhatsApp wants it: country code + national number, digits
 * only, no '+'. Nigerian forms first — 0803…, 803…, +234 803…, 234-803… —
 * and any other international number written with + or 00. Null when it cannot
 * be a real number.
 */
function mam_kapso_e164(?string $raw, string $cc = '234'): ?string {
    $raw = trim((string) $raw);
    if ($raw === '') return null;
    $plus = str_starts_with($raw, '+') || str_starts_with($raw, '00');
    $d = preg_replace('/\D+/', '', $raw);
    if (str_starts_with($raw, '00')) $d = substr($d, 2);
    if ($plus) return (strlen($d) >= 8 && strlen($d) <= 15 && $d[0] !== '0') ? $d : null;
    if (str_starts_with($d, $cc) && strlen($d) === strlen($cc) + 10) return $d;          // 2348031234567
    if (strlen($d) === 11 && $d[0] === '0') return $cc . substr($d, 1);                     // 08031234567
    if (strlen($d) === 10 && $d[0] !== '0') return $cc . $d;                                 // 8031234567
    return null;
}

/** The Meta template body. Parameters are the template's {{1}}, {{2}}… in order. */
function mam_kapso_template_payload(string $to, string $name, string $lang, array $params): array {
    $p = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to, 'type' => 'template',
          'template' => ['name' => $name, 'language' => ['code' => $lang]]];
    if ($params) {
        $p['template']['components'] = [['type' => 'body', 'parameters' => array_map(
            static fn($v) => ['type' => 'text', 'text' => mb_substr(trim(preg_replace('/\s+/', ' ', (string) $v)), 0, 1000)], array_values($params))]];
    }
    return $p;
}

/**
 * May this person be sent a WhatsApp about this topic? Returns
 * ['ok' => bool, 'reason' => string, 'to' => ?string].
 */
function mam_kapso_recipient(int $userId, string $topic = 'general'): array {
    $u = mam_db_row('SELECT id, phone, role, status FROM users WHERE id = ?', [$userId]);
    if (!$u || ($u['status'] ?? 'active') !== 'active') return ['ok' => false, 'reason' => 'no_account', 'to' => null];
    $roles = array_column(mam_db_all('SELECT role FROM user_roles WHERE user_id = ?', [$userId]), 'role');
    $roles[] = (string) $u['role'];
    $grownUp = (bool) array_intersect($roles, ['parent', 'instructor', 'admin']);
    if (!$grownUp && !mam_kapso_cfg()['students']) return ['ok' => false, 'reason' => 'student_number', 'to' => null];
    $to = mam_kapso_e164((string) ($u['phone'] ?? ''));
    if (!$to) return ['ok' => false, 'reason' => 'no_number', 'to' => null];
    if (in_array($topic, MAM_THREAD_ALWAYS_REACH, true)) return ['ok' => true, 'reason' => 'override', 'to' => $to];
    $prefs = mam_guardian_prefs($userId);
    if (!$prefs['set'] || !$prefs['whatsapp']) return ['ok' => false, 'reason' => 'not_opted_in', 'to' => $to];
    return ['ok' => true, 'reason' => 'opted_in', 'to' => $to];
}

/** POST the payload. Returns [ok, provider message id or error]. */
function mam_kapso_post(array $payload): array {
    $c = mam_kapso_cfg();
    if (!mam_kapso_ready()) return [false, 'not_configured'];
    [$code, $res] = mam_integration_http('POST', $c['base_url'] . '/' . $c['version'] . '/' . rawurlencode($c['phone_number_id']) . '/messages',
        ['Content-Type: application/json', 'X-API-Key: ' . $c['api_key']], (string) json_encode($payload));
    $j = json_decode($res, true);
    $id = (string) ($j['messages'][0]['id'] ?? '');
    if ($code >= 200 && $code < 300 && $id !== '') return [true, $id];
    return [false, 'http_' . $code . (isset($j['error']['message']) ? ': ' . mb_substr((string) $j['error']['message'], 0, 200) : '')];
}

/**
 * Send one template, once. Returns one of: sent, failed, duplicate, skipped:<reason>.
 * Never throws.
 */
function mam_kapso_send_template(int $userId, string $templateKey, array $params, string $idemKey, ?int $sessionId = null, string $topic = 'general'): string {
    try {
        if (!mam_kapso_ready()) return 'skipped:not_configured';
        $r = mam_kapso_recipient($userId, $topic);
        if (!$r['ok']) return 'skipped:' . $r['reason'];
        $name = mam_kapso_template_name($templateKey);
        if ($name === '') return 'skipped:no_template';
        try {
            $rowId = mam_db_insert('whatsapp_outbound', ['idem_key' => mb_substr($idemKey, 0, 190), 'user_id' => $userId, 'session_id' => $sessionId,
                'to_number' => $r['to'], 'kind' => 'template', 'template' => $name, 'params' => (string) json_encode(array_values($params)), 'status' => 'queued']);
        } catch (\Throwable $e) {
            return 'duplicate';  // UNIQUE(idem_key): this person has already been sent this
        }
        [$ok, $info] = mam_kapso_post(mam_kapso_template_payload($r['to'], $name, mam_kapso_cfg()['language'], $params));
        mam_db_update('whatsapp_outbound', $ok
            ? ['status' => 'sent', 'provider_message_id' => $info, 'updated_at' => date('Y-m-d H:i:s')]
            : ['status' => 'failed', 'error' => mb_substr($info, 0, 490), 'updated_at' => date('Y-m-d H:i:s')], ['id' => $rowId]);
        if (!$ok) mam_log('warn', 'kapso_send_failed', ['user' => $userId, 'template' => $name, 'err' => $info]);
        return $ok ? 'sent' : 'failed';
    } catch (\Throwable $e) {
        mam_log('error', 'kapso_send_error', ['user' => $userId, 'err' => $e->getMessage()]);
        return 'failed';
    }
}

/** True while the number's 24-hour free-form window is open. */
function mam_kapso_window_open(string $to, ?int $now = null): bool {
    $r = mam_db_row('SELECT last_inbound_at FROM whatsapp_inbound WHERE wa_number = ?', [$to]);
    return $r && strtotime((string) $r['last_inbound_at']) > ($now ?? time()) - 86400;
}

/** The console's test: the test template to a number the operator typed. Logged like any other. */
function mam_kapso_send_test(string $number, int $actorId): array {
    $to = mam_kapso_e164($number);
    if (!$to) return ['ok' => false, 'detail' => 'That is not a phone number WhatsApp can reach.'];
    if (!mam_kapso_ready()) return ['ok' => false, 'detail' => 'Kapso is not configured.'];
    $name = mam_kapso_template_name('test');
    $rowId = mam_db_insert('whatsapp_outbound', ['idem_key' => 'test:' . $to . ':' . bin2hex(random_bytes(6)), 'user_id' => $actorId,
        'to_number' => $to, 'kind' => 'template', 'template' => $name, 'params' => '[]', 'status' => 'queued']);
    [$ok, $info] = mam_kapso_post(mam_kapso_template_payload($to, $name, mam_kapso_cfg()['language'], []));
    mam_db_update('whatsapp_outbound', $ok ? ['status' => 'sent', 'provider_message_id' => $info, 'updated_at' => date('Y-m-d H:i:s')]
        : ['status' => 'failed', 'error' => mb_substr($info, 0, 490), 'updated_at' => date('Y-m-d H:i:s')], ['id' => $rowId]);
    mam_audit('kapso_test', 'whatsapp', $rowId, ['ok' => $ok, 'by' => $actorId]);
    return ['ok' => $ok, 'detail' => $ok ? 'Sent the "' . $name . '" template to +' . $to . '.' : 'Kapso refused it: ' . $info];
}

/** Verify a Kapso webhook: hex HMAC-SHA256 of the raw body with the webhook secret. */
function mam_kapso_webhook_ok(string $rawBody, string $signature): bool {
    $secret = mam_kapso_cfg()['webhook_secret'];
    if ($secret === '' || $signature === '') return false;
    return hash_equals(hash_hmac('sha256', $rawBody, $secret), strtolower(trim($signature)));
}

/**
 * Record a (verified) forwarded Meta payload: delivery/read statuses update the
 * outbound row; an inbound message opens that number's 24-hour window.
 * Returns ['statuses' => n, 'inbound' => n].
 */
function mam_kapso_webhook_apply(array $payload): array {
    $out = ['statuses' => 0, 'inbound' => 0];
    $rank = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];
    foreach ((array) ($payload['entry'] ?? []) as $entry) {
        foreach ((array) ($entry['changes'] ?? []) as $ch) {
            $v = (array) ($ch['value'] ?? []);
            foreach ((array) ($v['statuses'] ?? []) as $st) {
                $id = (string) ($st['id'] ?? ''); $s = (string) ($st['status'] ?? '');
                if ($id === '' || !isset($rank[$s])) continue;
                $row = mam_db_row('SELECT id, status FROM whatsapp_outbound WHERE provider_message_id = ?', [$id]);
                // Statuses arrive out of order; never move backwards (read must not become delivered).
                if ($row && $rank[$s] > ($rank[(string) $row['status']] ?? 0)) {
                    mam_db_update('whatsapp_outbound', ['status' => $s, 'updated_at' => date('Y-m-d H:i:s'),
                        'error' => $s === 'failed' ? mb_substr((string) json_encode($st['errors'] ?? []), 0, 490) : null], ['id' => (int) $row['id']]);
                    $out['statuses']++;
                }
            }
            foreach ((array) ($v['messages'] ?? []) as $m) {
                $from = mam_kapso_e164('+' . preg_replace('/\D+/', '', (string) ($m['from'] ?? '')));
                if (!$from) continue;
                $at = date('Y-m-d H:i:s', (int) ($m['timestamp'] ?? time()));
                mam_db_upsert('whatsapp_inbound', ['wa_number' => $from, 'last_inbound_at' => $at], ['wa_number'], ['last_inbound_at' => $at]);
                $out['inbound']++;
            }
        }
    }
    return $out;
}
