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

/* An instructor reference (FAC-<id>) resolves against the faculty applications
   instead. One route and one URL shape for both policies: a second endpoint
   would mean a second place to get the lookup, the 404 copy and the rate limit
   right, and the class policy already proved that shape works. */
if (stripos($ref, 'FAC-') === 0) {
    $fid = (int) substr($ref, 4);
    $fa = $fid > 0 ? mam_db_row('SELECT id, full_name, created_at FROM instructor_applications WHERE id = ?', [$fid]) : null;
    if (!$fa) {
        mam_raw_response('<h1>Policy not found</h1><p>No instructor application on file for this reference.</p>', 404);
    }
    mam_raw_response(mam_policy_instructor_print_html(
        (string) ($fa['full_name'] ?? ''),
        'FAC-' . (int) $fa['id'],
        !empty($fa['created_at']) ? date('F j, Y', strtotime((string) $fa['created_at'])) : null
    ));
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
