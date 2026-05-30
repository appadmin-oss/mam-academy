<?php
/**
 * GET /api/policy/{reference}
 *
 * Renders the print-friendly class policy (signed by the applicant on
 * submit) as a standalone webpage. Same rationale as /api/invoice — far
 * better UX than the email .html attachment that displays as source code
 * on most mail clients.
 */
declare(strict_types=1);

$ref = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($params['ref'] ?? ''));
if ($ref === '') {
    mam_raw_response('<h1>Policy not found</h1><p>Missing reference.</p>', 400);
}

$app = mam_db_row('SELECT * FROM applications WHERE reference = ?', [$ref]);
if (!$app || empty($app['policy_mode'])) {
    mam_raw_response('<h1>Policy not found</h1><p>No signed policy on file for this application.</p>', 404);
}

$html = mam_policy_print_html(
    (string)$app['policy_mode'],
    (string)($app['policy_signed_name'] ?: ''),
    (string)$app['reference']
);
mam_raw_response($html);
