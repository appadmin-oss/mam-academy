<?php
/**
 * POST /api/hooks/jaas — JaaS's join and leave events (live-classes.php, v101).
 * Signed: refused unless the HMAC verifies and is under ten minutes old. A
 * correct request always answers 200, recorded or not, so JaaS does not retry
 * an event that was simply not for us.
 */
declare(strict_types=1);
$raw = file_get_contents('php://input') ?: '';
$sig = (string) ($_SERVER['HTTP_X_JAAS_SIGNATURE'] ?? '');
if (!mam_live_webhook_ok($sig, $raw, mam_live_cfg()['webhook_secret'])) mam_fail('bad_signature', 401);
$p = json_decode($raw, true);
if (!is_array($p)) mam_fail('bad_body', 400);
mam_ok(['result' => mam_live_webhook($p)]);
