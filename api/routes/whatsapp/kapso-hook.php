<?php
/**
 * POST /api/whatsapp/kapso-webhook — Kapso's forwarded Meta events (kapso.php).
 * Refused unless X-Webhook-Signature is the HMAC of the raw body. Records
 * delivery/read and opens the 24-hour window for a number that wrote to us.
 */
declare(strict_types=1);
$raw = file_get_contents('php://input') ?: '';
if (!mam_kapso_webhook_ok($raw, (string) ($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? ''))) mam_fail('bad_signature', 401);
$p = json_decode($raw, true);
if (!is_array($p)) mam_fail('bad_body', 400);
mam_ok(['result' => mam_kapso_webhook_apply($p)]);
