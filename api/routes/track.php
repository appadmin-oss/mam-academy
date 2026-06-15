<?php
/**
 * POST /api/track — first-party funnel beacon (Application A3).
 *
 * Accepts a tiny JSON body {event, sid, step, pathway, meta} from the apply
 * flow (sent via navigator.sendBeacon). No CSRF: it's an append-only,
 * non-sensitive analytics signal that must work from a beacon (which can't
 * carry our CSRF cookie reliably) — abuse is bounded by the rate limiter and
 * the strict event allowlist. Do-Not-Track is honoured HERE, before anything
 * is written: a DNT header (or dnt flag) means we record nothing.
 *
 * Always replies 204 No Content (beacon-friendly, leaks nothing) regardless of
 * whether a row was written.
 */
declare(strict_types=1);

mam_require_method('POST');

// Honour Do-Not-Track at the edge — drop silently, write nothing.
$dntHeader = $_SERVER['HTTP_DNT'] ?? '';
$body = mam_json_input();
$dntFlag = (string)($body['dnt'] ?? '');
if ($dntHeader === '1' || $dntFlag === '1' || $dntFlag === 'true') {
    http_response_code(204);
    if (!defined('MAM_RESPONSE_SENT')) define('MAM_RESPONSE_SENT', true);
    exit;
}

// Best-effort record (validated + clamped + never-throw inside).
mam_funnel_record($body);

http_response_code(204);
if (!defined('MAM_RESPONSE_SENT')) define('MAM_RESPONSE_SENT', true);
exit;
