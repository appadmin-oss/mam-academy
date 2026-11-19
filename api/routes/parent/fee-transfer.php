<?php
/**
 * POST /api/parent/fee-transfer
 *   {invoice_id, amount, paid_on, reference, bank_name, payer_name, evidence: "data:…;base64,…", note?}
 *
 * "I paid by bank transfer." The guardian reports it with the slip; the office
 * confirms it against the bank statement (mam_fee_transfer_verify). Nothing is
 * credited here — a report is a claim until somebody has seen the money.
 */
declare(strict_types=1);
$u  = mam_require_role('parent');
$in = mam_json_input();
$invId = (int) ($in['invoice_id'] ?? 0);
if ($invId < 1) mam_fail('validation', 422, ['fields' => ['invoice_id']]);

$words = [
    'evidence_required'   => 'Attach a photo or PDF of the transfer slip, receipt or bank alert.',
    'evidence_unreadable' => 'That file could not be read. Try attaching it again.',
    'evidence_too_large'  => 'That file is larger than 5 MB. A phone photo of the slip is enough.',
    'evidence_type'       => 'Attach a photo (JPG, PNG or WebP) or a PDF of the slip, receipt or bank alert.',
    'evidence_too_small'  => 'That picture is too small to read. Photograph the whole slip.',
    'evidence_reused'     => 'That exact file has already been sent for a payment. Attach the slip for this transfer.',
    'reference_required'  => 'Type the transaction reference or session ID from the slip or alert.',
    'reference_reused'    => 'That reference is already recorded for a payment. If this is a different transfer, check the reference on the slip.',
    'reference_rejected'  => 'A transfer with that reference was checked before and could not be confirmed. Please contact the academy office.',
    'payer_required'      => 'Type the name on the account the money came from.',
    'bank_required'       => 'Say which bank the money was sent from.',
    'bad_amount'          => 'Type the amount you sent.',
    'more_than_due'       => 'That is more than is owed on this invoice. Type the amount you sent for this invoice only.',
    'bad_date'            => 'Give the date of the transfer.',
    'date_in_future'      => 'That date has not happened yet.',
    'date_before_invoice' => 'That date is before this invoice was issued, so the transfer cannot be for it.',
    'claim_pending'       => 'A transfer you reported for this invoice is still being checked. You will hear as soon as it is.',
    'not_payable'         => 'This invoice is not open for payment.',
    'nothing_owing'       => 'Nothing is owed on this invoice.',
];
try {
    $id = mam_fee_transfer_claim((int) $u['id'], $invId, $in);
} catch (\InvalidArgumentException | \RuntimeException $e) {
    $code = $e->getMessage();
    mam_fail($code, $code === 'forbidden' ? 403 : ($code === 'invoice_not_found' ? 404 : 422), ['detail' => $words[$code] ?? 'That could not be recorded.']);
}
mam_ok(['claim_id' => $id, 'status' => 'pending']);
