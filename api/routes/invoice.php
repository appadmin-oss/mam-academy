<?php
/**
 * GET /api/invoice/{reference}
 *
 * Renders the print-friendly invoice as a standalone webpage so applicants
 * can view + print/save-as-PDF directly from their browser, instead of
 * downloading an .html attachment that most mail clients show as source.
 *
 * The reference alone identifies the application — same security model as
 * the booking-fee status endpoint: no PII surfaced beyond the holder's
 * own selections, which they already submitted themselves.
 */
declare(strict_types=1);

$ref = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($params['ref'] ?? ''));
if ($ref === '') {
    mam_raw_response('<h1>Invoice not found</h1><p>Missing reference.</p>', 400);
}

$app = mam_db_row('SELECT * FROM applications WHERE reference = ?', [$ref]);
if (!$app) {
    mam_raw_response('<h1>Invoice not found</h1><p>That application reference doesn\'t match anything on file.</p>', 404);
}
$customer = $app['customer_id'] ? mam_db_row('SELECT * FROM customers WHERE id = ?', [(int)$app['customer_id']]) : null;
if (!$customer) {
    mam_raw_response('<h1>Invoice not available</h1><p>Customer record missing.</p>', 404);
}

$tier = mam_booking_tier();
$invoiceNumber = 'INV-MAM-' . date('y', strtotime((string)$app['created_at'])) . '-' . str_pad((string)$app['id'], 4, '0', STR_PAD_LEFT);
$html = mam_invoice_print_html($app, $customer, $tier, $invoiceNumber);
mam_raw_response($html);
