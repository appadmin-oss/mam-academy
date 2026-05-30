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
    // Set the response-sent guard BEFORE writing anything. If the response
    // is in flight and a fatal error fires the shutdown handler, the guard
    // is already set so the safety net doesn't write a second JSON body
    // that would corrupt the client's view of the response.
    if (defined('MAM_RESPONSE_SENT')) return;
    define('MAM_RESPONSE_SENT', true);

    // Drain EVERY level of output buffer — PHP can nest them. Anything
    // we catch is appended to a forensic log; never reaches the wire.
    if (function_exists('ob_get_level')) {
        while (ob_get_level() > 0) {
            $leaked = ob_get_clean();
            if (is_string($leaked) && $leaked !== '') {
                @file_put_contents(MAM_LOGS . '/buffer-leak.log',
                    '[' . date('c') . '] ' . ($_SERVER['REQUEST_URI'] ?? '?') . ' ' . strlen($leaked) . "b: " . mb_substr($leaked, 0, 400) . "\n",
                    FILE_APPEND);
            }
        }
    }
    http_response_code($status);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-MAM-API-Response: ' . $status);
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

/**
 * Emit a raw text/html (or other content-type) response and mark the
 * response sent so the shutdown safety net doesn't eat it.
 *
 * Without this, any route that does `header('Content-Type:text/html'); echo $html; exit;`
 * gets its body drained by mam_json/mam_emit_failure_json — which is
 * exactly what was happening to the unsubscribe confirmation page.
 */
function mam_raw_response(string $body, int $status = 200, string $contentType = 'text/html; charset=utf-8'): void {
    if (defined('MAM_RESPONSE_SENT')) return;
    define('MAM_RESPONSE_SENT', true);
    if (function_exists('ob_get_level')) {
        while (ob_get_level() > 0) @ob_end_clean();
    }
    http_response_code($status);
    if (!headers_sent()) {
        header('Content-Type: ' . $contentType);
        header('X-MAM-API-Response: ' . $status);
    }
    echo $body;
    exit;
}
