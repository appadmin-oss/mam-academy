<?php
/**
 * GET /api/booking/pricing[?country=NG]
 *
 * Public, unauthenticated. Returns the booking-fee tier for the visitor's
 * region — used by the apply form (Step 5) and /book to render the right
 * currency + amount. The same lookup is used server-side at charge time, so
 * a client cannot spoof a cheaper tier by passing a fake country code.
 *   { ok:true, currency:"NGN", amount:7500, label:"₦7,500", country:"NG",
 *              amount_usd:5, amount_ngn:7500 }
 */
declare(strict_types=1);
$override = strtoupper(preg_replace('/[^A-Z]/i', '', (string)($_GET['country'] ?? '')));
$tier = mam_booking_tier($override !== '' ? $override : null);
mam_ok($tier);
