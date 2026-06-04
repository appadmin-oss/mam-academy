<?php
/**
 * Provider-agnostic AI gateway (server-side).
 *
 * One small client that talks to whichever free/low-cost provider is configured,
 * so the key never reaches the browser and the provider can be swapped via env
 * with zero code changes:
 *
 *   AI_PROVIDER = gemini | groq | openai | cloudflare      (default: gemini)
 *   AI_API_KEY  = <key>
 *   AI_MODEL    = <override>                               (sensible per-provider default)
 *   AI_BASE_URL = <OpenAI-compatible base>                 (groq/openai/openrouter…)
 *   CLOUDFLARE_ACCOUNT_ID = <id>                           (cloudflare only)
 *
 * The feature stays OFF until a key is present — mam_ai_configured() lets routes
 * and the front-end degrade gracefully instead of erroring.
 */
declare(strict_types=1);

function mam_ai_provider(): string {
    $p = strtolower(trim((string)mam_env('AI_PROVIDER', 'gemini')));
    return $p === '' ? 'gemini' : $p;
}
function mam_ai_key(): string { return trim((string)mam_env('AI_API_KEY', '')); }

function mam_ai_configured(): bool {
    if (!function_exists('curl_init')) return false;
    if (mam_ai_key() === '') return false;
    if (mam_ai_provider() === 'cloudflare' && trim((string)mam_env('CLOUDFLARE_ACCOUNT_ID', '')) === '') return false;
    return true;
}

function mam_ai_model(): string {
    $m = trim((string)mam_env('AI_MODEL', ''));
    if ($m !== '') return $m;
    switch (mam_ai_provider()) {
        case 'gemini':     return 'gemini-2.0-flash';
        case 'cloudflare': return '@cf/meta/llama-3.1-8b-instruct';
        default:           return 'llama-3.3-70b-versatile';   // groq / openai-compatible
    }
}

/** POST helper. Returns ['code'=>int,'json'=>array|null]. Throws only on transport failure. */
function mam_ai_http(string $url, array $headers, array $body, int $timeout = 30): array {
    if (!function_exists('curl_init')) throw new RuntimeException('curl unavailable');
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($body),
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) throw new RuntimeException('AI network error: ' . $err);
    return ['code' => $code, 'json' => json_decode($resp, true)];
}

/**
 * Run a chat turn. $messages = [['role'=>'user'|'assistant','content'=>string], …].
 * Returns the assistant reply text, or throws on failure.
 */
function mam_ai_chat(array $messages, string $system, array $opts = []): string {
    switch (mam_ai_provider()) {
        case 'gemini':     return mam_ai_gemini($messages, $system, $opts);
        case 'cloudflare': return mam_ai_cloudflare($messages, $system, $opts);
        default:           return mam_ai_openai($messages, $system, $opts);
    }
}

function mam_ai_openai(array $messages, string $system, array $opts): string {
    $base = rtrim((string)mam_env('AI_BASE_URL', 'https://api.groq.com/openai/v1'), '/');
    $msgs = array_merge([['role' => 'system', 'content' => $system]], mam_ai_norm_msgs($messages));
    $r = mam_ai_http($base . '/chat/completions',
        ['Authorization: Bearer ' . mam_ai_key(), 'Content-Type: application/json'],
        ['model' => mam_ai_model(), 'messages' => $msgs, 'temperature' => 0.5, 'max_tokens' => (int)($opts['max_tokens'] ?? 700)]);
    if ($r['code'] >= 300) throw new RuntimeException('AI provider error ' . $r['code']);
    $t = $r['json']['choices'][0]['message']['content'] ?? '';
    if (trim((string)$t) === '') throw new RuntimeException('AI returned no text');
    return (string)$t;
}

function mam_ai_gemini(array $messages, string $system, array $opts): string {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode(mam_ai_model())
         . ':generateContent?key=' . urlencode(mam_ai_key());
    $contents = array_map(function ($m) {
        return ['role' => ($m['role'] === 'assistant' ? 'model' : 'user'), 'parts' => [['text' => (string)$m['content']]]];
    }, mam_ai_norm_msgs($messages));
    $r = mam_ai_http($url, ['Content-Type: application/json'], [
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents' => $contents,
        'generationConfig' => ['temperature' => 0.5, 'maxOutputTokens' => (int)($opts['max_tokens'] ?? 700)],
    ]);
    if ($r['code'] >= 300) throw new RuntimeException('AI provider error ' . $r['code']);
    $t = $r['json']['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if (trim((string)$t) === '') throw new RuntimeException('AI returned no text');
    return (string)$t;
}

function mam_ai_cloudflare(array $messages, string $system, array $opts): string {
    $acct = trim((string)mam_env('CLOUDFLARE_ACCOUNT_ID', ''));
    $url = 'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode($acct) . '/ai/run/' . mam_ai_model();
    $msgs = array_merge([['role' => 'system', 'content' => $system]], mam_ai_norm_msgs($messages));
    $r = mam_ai_http($url, ['Authorization: Bearer ' . mam_ai_key(), 'Content-Type: application/json'],
        ['messages' => $msgs, 'max_tokens' => (int)($opts['max_tokens'] ?? 700)]);
    if ($r['code'] >= 300) throw new RuntimeException('AI provider error ' . $r['code']);
    $t = $r['json']['result']['response'] ?? '';
    if (trim((string)$t) === '') throw new RuntimeException('AI returned no text');
    return (string)$t;
}

/** Clamp + normalise the message list (role + trimmed content, recent turns only). */
function mam_ai_norm_msgs(array $messages): array {
    $out = [];
    foreach (array_slice($messages, -16) as $m) {
        $role = (($m['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
        $content = trim((string)($m['content'] ?? ''));
        if ($content === '') continue;
        if (strlen($content) > 4000) $content = substr($content, 0, 4000);
        $out[] = ['role' => $role, 'content' => $content];
    }
    return $out;
}
