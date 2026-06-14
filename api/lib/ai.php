<?php
/**
 * MAM Academy — provider-agnostic AI gateway.
 *
 * The single audited entry point for every AI feature. FREE BY DEFAULT: with no
 * provider configured it stays off; set `ai_provider` to a free option such as
 * "ollama" (self-hosted, $0, data never leaves the box — best for student PII)
 * or a free-tier hosted provider ("groq", "openrouter", "mistral"). Premium
 * providers (Claude, etc.) are opt-in, per use-case, never required.
 *
 * Design:
 *   - One contract: mam_ai_complete($useCase, $prompt, $opts) → normalized array.
 *   - Pluggable adapters; "openai_compat" covers Ollama + Groq + OpenRouter +
 *     Mistral + llama.cpp + most hosted providers (all expose /v1/chat/completions).
 *   - NEVER throws — AI must never break a request. On any failure callers get
 *     ok=false and fall back to the manual flow.
 *   - Audited: each call records to ai_runs (a prompt HASH, not the prompt).
 *   - PII-safe: redact before egress to a non-local provider unless explicitly
 *     allowed (a zero-data-retention-safe premium model).
 */
declare(strict_types=1);

/** Known provider aliases → OpenAI-compatible base URL + whether data stays local. */
function mam_ai_provider_presets(): array {
    return [
        'ollama'     => ['base' => 'http://localhost:11434/v1',     'local' => true,  'model' => 'llama3.2'],
        'llamacpp'   => ['base' => 'http://localhost:8080/v1',      'local' => true,  'model' => 'local'],
        'groq'       => ['base' => 'https://api.groq.com/openai/v1', 'local' => false, 'model' => 'llama-3.3-70b-versatile'],
        'openrouter' => ['base' => 'https://openrouter.ai/api/v1',   'local' => false, 'model' => 'meta-llama/llama-3.3-70b-instruct:free'],
        'mistral'    => ['base' => 'https://api.mistral.ai/v1',      'local' => false, 'model' => 'mistral-small-latest'],
    ];
}

/** The configured provider id for a use-case: per-case override → global → env → 'none'. */
function mam_ai_provider(string $useCase = ''): string {
    if ($useCase !== '') {
        $perCase = (string)mam_setting('ai_provider_' . $useCase, '');
        if ($perCase !== '') return $perCase;
    }
    $p = (string)mam_setting('ai_provider', (string)mam_env('AI_PROVIDER', 'none'));
    return $p !== '' ? $p : 'none';
}

/** Whether AI is enabled (a provider other than 'none' is configured). */
function mam_ai_enabled(string $useCase = ''): bool {
    return mam_ai_provider($useCase) !== 'none';
}

/** Resolve the concrete connection (adapter/base/key/model/local) for a provider id. */
function mam_ai_resolve(string $provider): array {
    if ($provider === 'mock') {
        return ['adapter' => 'mock', 'base' => '', 'key' => '', 'model' => 'mock', 'local' => true];
    }
    $presets = mam_ai_provider_presets();
    $base  = (string)mam_env('AI_BASE_URL', '');
    $model = (string)mam_env('AI_MODEL', '');
    $local = false;
    if (isset($presets[$provider])) {
        if ($base === '')  $base  = (string)$presets[$provider]['base'];
        if ($model === '') $model = (string)$presets[$provider]['model'];
        $local = (bool)$presets[$provider]['local'];
    }
    if ($model === '') $model = 'default';
    return ['adapter' => 'openai_compat', 'base' => $base, 'key' => (string)mam_env('AI_API_KEY', ''), 'model' => $model, 'local' => $local];
}

/** Secret-free readiness summary for diag/admin (mirrors mam_mail_readiness). */
function mam_ai_readiness(): array {
    $p = mam_ai_provider();
    $r = mam_ai_resolve($p);
    $issues = [];
    if ($p === 'none') {
        $issues[] = 'AI is disabled (ai_provider=none). Set ai_provider to a free option like "ollama" (self-hosted, $0) or "groq" to enable AI features.';
    } elseif ($p !== 'mock' && $r['base'] === '') {
        $issues[] = 'ai_provider is set but no AI_BASE_URL/preset resolved a base URL — set AI_BASE_URL (or use a known preset).';
    }
    return [
        'enabled'  => $p !== 'none',
        'provider' => $p,
        'adapter'  => $r['adapter'],
        'base'     => $r['base'] ?: null,
        'model'    => $r['model'],
        'local'    => $r['local'],
        'key_set'  => $r['key'] !== '',
        'issues'   => $issues,
    ];
}

/**
 * The one entry point. Returns a normalized result; NEVER throws.
 *   ['ok'=>bool,'provider'=>string,'model'=>?string,'text'=>?string,'json'=>?array,'usage'=>array,'error'=>?string]
 * $opts: system, schema (JSON-schema array), max_tokens, temperature,
 *        allow_pii (permit raw PII egress on a ZDR-safe premium provider),
 *        reviewed_by.
 */
function mam_ai_complete(string $useCase, string $prompt, array $opts = []): array {
    $provider = mam_ai_provider($useCase);
    $out = ['ok' => false, 'provider' => $provider, 'model' => null, 'text' => null, 'json' => null, 'usage' => [], 'error' => null];
    if ($provider === 'none') { $out['error'] = 'disabled'; return $out; }

    try {
        if (!mam_ai_quota_ok()) {
            $out['error'] = 'quota_exceeded';
            mam_ai_record($useCase, $provider, null, false, $prompt, [], 'quota_exceeded');
            return $out;
        }
        $conn = mam_ai_resolve($provider);
        $out['model'] = $conn['model'];

        // PII guard: redact before egress to a non-local provider unless the
        // caller explicitly allows it (a ZDR-safe premium model).
        $sendPrompt = $prompt;
        if (!$conn['local'] && empty($opts['allow_pii'])) $sendPrompt = mam_ai_redact($prompt);

        $req = [
            'system'      => (string)($opts['system'] ?? ''),
            'prompt'      => $sendPrompt,
            'schema'      => $opts['schema'] ?? null,
            'max_tokens'  => (int)($opts['max_tokens'] ?? 1024),
            'temperature' => isset($opts['temperature']) ? (float)$opts['temperature'] : 0.4,
            'model'       => $conn['model'],
        ];

        $r = $conn['adapter'] === 'mock' ? mam_ai_adapter_mock($req) : mam_ai_adapter_openai_compat($conn, $req);

        // Schema enforcement across providers: if a schema was requested, ensure
        // we have valid JSON (parse/repair the text) regardless of native support.
        if (!empty($req['schema']) && !empty($r['ok']) && ($r['json'] ?? null) === null && ($r['text'] ?? null) !== null) {
            $r['json'] = mam_ai_extract_json((string)$r['text']);
            if ($r['json'] === null) { $r['ok'] = false; $r['error'] = 'schema_parse_failed'; }
        }

        $out = array_merge($out, $r);
        $out['provider'] = $provider;
        mam_ai_record($useCase, $provider, $out['model'], !empty($out['ok']), $prompt, $out['usage'] ?? [], !empty($out['ok']) ? null : ($out['error'] ?? 'failed'), $opts['reviewed_by'] ?? null);
        return $out;
    } catch (\Throwable $e) {
        if (function_exists('mam_log')) mam_log('error', 'ai_exception', ['use_case' => $useCase, 'err' => $e->getMessage()]);
        $out['error'] = 'exception';
        return $out;
    }
}

/** Offline, deterministic adapter for dev + tests. Echoes a canned answer; when
 *  a schema is requested, returns a minimal schema-shaped JSON stub. */
function mam_ai_adapter_mock(array $req): array {
    if (!empty($req['schema']) && is_array($req['schema'])) {
        $stub = mam_ai_schema_stub($req['schema']);
        return ['ok' => true, 'text' => json_encode($stub), 'json' => $stub, 'usage' => ['input_tokens' => 0, 'output_tokens' => 0], 'error' => null];
    }
    return ['ok' => true, 'text' => '[mock] ' . mb_substr(trim((string)$req['prompt']), 0, 200), 'json' => null, 'usage' => ['input_tokens' => 0, 'output_tokens' => 0], 'error' => null];
}

/** OpenAI-compatible chat-completions adapter — covers Ollama (local, free),
 *  Groq, OpenRouter, Mistral, llama.cpp, and most hosted providers. */
function mam_ai_adapter_openai_compat(array $conn, array $req): array {
    if ($conn['base'] === '') return ['ok' => false, 'text' => null, 'json' => null, 'usage' => [], 'error' => 'no_base_url'];
    $messages = [];
    if ($req['system'] !== '') $messages[] = ['role' => 'system', 'content' => $req['system']];
    $messages[] = ['role' => 'user', 'content' => $req['prompt']];
    $body = ['model' => $req['model'], 'messages' => $messages, 'max_tokens' => $req['max_tokens'], 'temperature' => $req['temperature']];
    if (!empty($req['schema'])) $body['response_format'] = ['type' => 'json_object']; // broad-compat JSON mode

    [$ok, $status, $resp] = mam_ai_http_post(rtrim($conn['base'], '/') . '/chat/completions', $body, $conn['key']);
    if (!$ok) return ['ok' => false, 'text' => null, 'json' => null, 'usage' => [], 'error' => 'transport:' . $status];
    $data = json_decode($resp, true);
    $text = $data['choices'][0]['message']['content'] ?? null;
    if ($text === null) return ['ok' => false, 'text' => null, 'json' => null, 'usage' => [], 'error' => 'no_content'];
    return [
        'ok'    => true,
        'text'  => (string)$text,
        'json'  => null,
        'usage' => ['input_tokens' => (int)($data['usage']['prompt_tokens'] ?? 0), 'output_tokens' => (int)($data['usage']['completion_tokens'] ?? 0)],
        'error' => null,
    ];
}

/** Best-effort HTTP JSON POST. Returns [ok, status, body]. Never throws. */
function mam_ai_http_post(string $url, array $body, string $apiKey = '', int $timeout = 45): array {
    $payload = (string)json_encode($body);
    $headers = ['Content-Type: application/json'];
    if ($apiKey !== '') $headers[] = 'Authorization: Bearer ' . $apiKey;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) return [false, $err ?: 'curl_failed', ''];
        return [$status >= 200 && $status < 300, (string)$status, (string)$resp];
    }
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $payload, 'timeout' => $timeout, 'ignore_errors' => true]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) return [false, 'stream_failed', ''];
    $status = 200;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $status = (int)$m[1];
    return [$status >= 200 && $status < 300, (string)$status, (string)$resp];
}

/** Redact obvious PII before egress to a non-local provider. */
function mam_ai_redact(string $s): string {
    $s = (string)preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/', '[email]', $s);
    $s = (string)preg_replace('/\+?\d[\d\s().-]{7,}\d/', '[phone]', $s);
    return $s;
}

/** Extract the first JSON object/array from a text blob (cross-provider schema repair). */
function mam_ai_extract_json(string $text): ?array {
    $t = trim($text);
    $d = json_decode($t, true);
    if (is_array($d)) return $d;
    if (preg_match('/(\{.*\}|\[.*\])/s', $t, $m)) {
        $d = json_decode($m[1], true);
        if (is_array($d)) return $d;
    }
    return null;
}

/** Minimal value matching a JSON-schema's top-level properties (mock adapter). */
function mam_ai_schema_stub(array $schema): array {
    $out = [];
    foreach (($schema['properties'] ?? []) as $k => $spec) {
        $type = $spec['type'] ?? 'string';
        if ($type === 'integer' || $type === 'number') $out[$k] = 0;
        elseif ($type === 'boolean') $out[$k] = false;
        elseif ($type === 'array') $out[$k] = [];
        elseif ($type === 'object') $out[$k] = [];
        else $out[$k] = 'mock';
    }
    return $out;
}

/** Daily call cap (best-effort, never-throw). ai_daily_cap <= 0 means unlimited. */
function mam_ai_quota_ok(): bool {
    $cap = (int)mam_setting_num('ai_daily_cap', 1000);
    if ($cap <= 0) return true;
    try {
        $row = mam_db_row('SELECT count FROM rate_limits WHERE bucket = ?', ['ai:' . date('Y-m-d')]);
        return (!$row ? 0 : (int)$row['count']) < $cap;
    } catch (\Throwable $e) { return true; }
}

/** Record an AI run for audit/reproducibility + bump the daily counter.
 *  Best-effort (never-throw). Stores a prompt HASH, never the raw prompt. */
function mam_ai_record(string $useCase, string $provider, ?string $model, bool $ok, string $prompt, array $usage, ?string $error = null, ?int $reviewedBy = null): void {
    if (!function_exists('mam_db')) return;
    try {
        mam_db_insert('ai_runs', [
            'use_case'      => mb_substr($useCase, 0, 64),
            'provider'      => mb_substr($provider, 0, 32),
            'model'         => $model !== null ? mb_substr($model, 0, 64) : null,
            'ok'            => $ok ? 1 : 0,
            'prompt_hash'   => substr(hash('sha256', $prompt), 0, 32),
            'input_tokens'  => (int)($usage['input_tokens'] ?? 0),
            'output_tokens' => (int)($usage['output_tokens'] ?? 0),
            'error'         => $error !== null ? mb_substr($error, 0, 255) : null,
            'reviewed_by'   => $reviewedBy,
        ]);
        $bucket = 'ai:' . date('Y-m-d');
        $reset  = strtotime('tomorrow') ?: (time() + 86400);
        if (mam_db_driver() === 'mysql') {
            mam_db()->prepare('INSERT INTO rate_limits (bucket, count, reset_at) VALUES (?,1,?) ON DUPLICATE KEY UPDATE count = count + 1')->execute([$bucket, $reset]);
        } else {
            mam_db()->prepare('INSERT INTO rate_limits (bucket, count, reset_at) VALUES (?,1,?) ON CONFLICT(bucket) DO UPDATE SET count = count + 1')->execute([$bucket, $reset]);
        }
    } catch (\Throwable $e) { /* audit + quota are best-effort */ }
}

/**
 * First wired use case (read-only, low-risk): a short, human-readable progress
 * narrative for a student from their finalized results. Returns
 * ['text','provider','model'] or null when AI is disabled/unavailable (the
 * caller then shows nothing, or a manual note). PII-light: first name + numbers.
 */
function mam_ai_progress_narrative(int $studentId): ?array {
    if (!mam_ai_enabled('progress_narrative')) return null;
    try {
        $u = mam_db_row('SELECT name FROM users WHERE id = ?', [$studentId]);
        $first = $u && trim((string)$u['name']) !== '' ? trim(explode(' ', (string)$u['name'])[0]) : 'there';
        $courses = mam_db_all(
            "SELECT c.name AS course, l.name AS level, r.total_score, r.outcome
               FROM course_enrollments ce
               JOIN courses c       ON c.id = ce.course_id
               LEFT JOIN levels l   ON l.id = ce.level_id
               LEFT JOIN results r  ON r.enrollment_id = ce.id
              WHERE ce.student_id = ? ORDER BY ce.id DESC LIMIT 20",
            [$studentId]
        );
        $lines = [];
        foreach ($courses as $c) {
            $lines[] = '- ' . $c['course'] . ($c['level'] ? ' (' . $c['level'] . ')' : '') . ': '
                     . ($c['total_score'] !== null ? ('score ' . round((float)$c['total_score']) . '%, ' . (string)($c['outcome'] ?? '')) : 'in progress');
        }
        $prompt = "Write a warm, specific 2-3 sentence progress note addressed to a music student named {$first}. "
                . "Use ONLY these course results — do not invent facts. If nothing is graded yet, encourage them to keep practising.\n\nResults:\n"
                . (count($lines) ? implode("\n", $lines) : '- (no graded results yet)');
        $res = mam_ai_complete('progress_narrative', $prompt, [
            'system'     => 'You are an encouraging but honest music tutor. Be concise and concrete.',
            'max_tokens' => 220,
        ]);
        return !empty($res['ok']) ? ['text' => trim((string)$res['text']), 'provider' => $res['provider'], 'model' => $res['model']] : null;
    } catch (\Throwable $e) { return null; }
}
