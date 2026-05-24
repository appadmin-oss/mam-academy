<?php
/**
 * JSON response helpers + method routing.
 */
declare(strict_types=1);

function mam_json($data, int $status = 200): void {
    http_response_code($status);
    if (!headers_sent() && !in_array('Content-Type: application/json; charset=utf-8', headers_list(), true)) {
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
