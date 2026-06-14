<?php
/**
 * /api/application/draft
 *   POST {draft_key, email, payload}  → save the server-side draft (CSRF + rate-limited)
 *   GET  ?k=<draft_key>&t=<token>      → token-verified load (cross-device resume)
 * Public — drafts are keyed by the client's idempotency key and the GET is
 * gated by a signed token, so they can't be enumerated.
 */
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $d = mam_app_draft_load_verified((string)($_GET['k'] ?? ''), (string)($_GET['t'] ?? ''));
    if (!$d) mam_fail('not_found', 404);
    mam_ok(['draft' => $d]);
}

$in  = mam_json_input();
$key = (string)($in['draft_key'] ?? '');
if (trim($key) === '') mam_fail('validation', 422, ['fields' => ['draft_key']]);

mam_app_draft_save($key, (string)($in['email'] ?? ''), $in['payload'] ?? null);
mam_ok(['saved' => true]);
