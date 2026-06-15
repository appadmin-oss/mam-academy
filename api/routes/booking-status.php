<?php
/**
 * GET /api/booking/status?ref=<application-ref>
 *
 * Lightweight, unauthenticated check used by /book to decide what to render:
 *   - { ok: true, found: false }                    no such application
 *   - { ok: true, found: true,  paid: false, name } booking fee not yet paid
 *   - { ok: true, found: true,  paid: true,  name, paid_at, embed_url } unlocked
 *
 * We deliberately do NOT expose any contact details or programme metadata — a
 * leaked URL with a guessable reference must not reveal personal data. The
 * "first name" is the only identifier shown, used for the friendly greeting.
 */
declare(strict_types=1);

$ref = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($_GET['ref'] ?? ''));
if ($ref === '') mam_fail('missing_reference', 400);

$row = mam_db_row(
    'SELECT a.reference, a.customer_id, a.booking_fee_paid_at, c.first_name
       FROM applications a LEFT JOIN customers c ON c.id = a.customer_id
       WHERE a.reference = ?',
    [$ref]
);

if (!$row) mam_ok(['found' => false]);

// MAM Auditer validity for this customer (1-year, once-until-expiration,
// students exempt). Lets /book unlock the embed when ANY valid placement
// stands — and explain an expired one — not just this application's own fee.
$aud = mam_auditer_status((int)($row['customer_id'] ?? 0));
$paid = !empty($row['booking_fee_paid_at']);
// "Unlocked" = the customer holds a valid placement (this app's fee, a sibling
// application within the year, or enrolled-student exemption).
$unlocked = $paid || $aud['valid'];
$out = [
    'found'     => true,
    'reference' => $row['reference'],
    'name'      => $row['first_name'] ?: '',
    'paid'      => $paid,
    'auditer'   => $aud,
    'message'   => mam_auditer_message($aud),
];
if ($unlocked) {
    $out['paid_at']   = $row['booking_fee_paid_at'] ?: $aud['done_at'];
    // Admin-configured Google Appointment Schedule embed link (from .env).
    // Falls back to empty so /book can show a sensible "coming soon" state
    // instead of breaking when the operator hasn't pasted the URL yet.
    $out['embed_url'] = (string)mam_env('BOOKING_EMBED_URL', '');
}
mam_ok($out);
