<?php
/**
 * GET /api/tuition-invoice/{reference}
 *
 * Renders a standard commercial invoice for the programme tuition — a proper
 * business invoice showing the course cost as the billable line item. Distinct
 * from GET /api/invoice/{ref} which is the booking-confirmation / assessment-fee
 * document. Use this one for corporate reimbursement, accounting records, or any
 * context that needs a clean "INVOICE" header rather than a booking summary.
 *
 * Invoice number format: INV-T-MAM-YY-XXXX
 * No auth required — same security model as the booking-confirmation invoice.
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

$year          = date('y', strtotime((string)$app['created_at']));
$invoiceNumber = 'INV-T-MAM-' . $year . '-' . str_pad((string)$app['id'], 4, '0', STR_PAD_LEFT);
$html          = mam_programme_invoice_html($app, $customer, $invoiceNumber);
mam_raw_response($html);
