<?php
/**
 * JSON response helpers + method routing.
 *
 * Critical: every JSON response is preceded by `ob_get_clean()`-style buffer
 * drains. If anything (a PHP notice, a stray `echo`, a BOM in an included
 * file, a debug print, a deprecation warning from PHP 8.x) emitted output
 * before mam_ok/mam_fail ran, that output would prefix the JSON and break
 * `JSON.parse` on the client — the user-visible symptom is "We couldn't
 * reach our secure server" with status:200, body:undefined in DevTools.
 *
 * bootstrap.php opens an ob_start() at the top of the request lifecycle;
 * mam_json() closes it right before writing the canonical JSON envelope,
 * so the wire body is ALWAYS just the JSON we intended. Any garbage caught
 * in the buffer is logged so the operator can still trace it.
 */
declare(strict_types=1);

function mam_json($data, int $status = 200): void {
    // Drain anything that snuck into the output buffer (notices, BOMs, echo)
    // so the response body is strictly JSON. If there's leaked content we
    // log it once so the cause stays diagnosable.
    if (function_exists('ob_get_level') && ob_get_level() > 0) {
        $leaked = ob_get_clean();
        if (is_string($leaked) && $leaked !== '' && function_exists('mam_log')) {
            // Trim to a sane length; this is for forensic logs only.
            try { mam_log('warn', 'response_buffer_leak', ['bytes' => strlen($leaked), 'sample' => mb_substr($leaked, 0, 200)]); } catch (Throwable $e) {}
        }
    }
    http_response_code($status);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function mam_ok(array $data = [], int $status = 200): void {
    mam_json(array_merge(['ok' => true], $data), $status);
}

function mam_fail(string $error, int $status = 400, array $extra = []): void {
    mam_json(array_merge(['ok' => false, 'error' => $error], $extra), $status);
}

function mam_require_method(string $method): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== strtoupper($method)) {
        mam_fail('method_not_allowed', 405);
    }
}
