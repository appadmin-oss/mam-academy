<?php
/**
 * GET /api/tuition/status?ref=...&email=...
 * Privacy-safe tuition lookup for the gated tuition page. BOTH the reference and
 * the matching application email are required, so tuition isn't enumerable by
 * reference alone. Returns {found:false} for not-found / mismatch / bad input
 * (no enumeration), else the payable amount + already_paid state. Rate-limited.
 */
declare(strict_types=1);

// An INV-… reference is a fee invoice, paid in the family portal. Saying so is
// not a disclosure: it names a reference FORMAT, not whether one exists.
if (preg_match('/^\s*INV-/i', (string) ($_GET['ref'] ?? ''))) {
    mam_ok(['found' => false, 'kind' => 'fee_invoice', 'portal' => '/portal/parent/',
            'message' => 'That is an invoice reference. Sign in to the family portal and open Fees to pay it.']);
}
$st = mam_tuition_status((string)($_GET['ref'] ?? ''), (string)($_GET['email'] ?? ''));
if ($st === null) mam_ok(['found' => false]);
mam_ok($st);
