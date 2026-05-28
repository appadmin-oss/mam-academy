<?php
/**
 * POST /api/application
 * Accepts a new application, upserts the customer, records the row,
 * computes total, returns the persisted reference for the payment step.
 *
 * Body: JSON application payload (see apply.js)
 * Headers: X-CSRF-Token, X-Idempotency-Key (optional)
 */
declare(strict_types=1);

$rateLimit = (int)mam_env('RATE_LIMIT_APPLY', '10');
$rateWindow = (int)mam_env('RATE_LIMIT_APPLY_WINDOW', '600');

$input = mam_json_input();
mam_honeypot($input);

$v = mam_validate_application($input);

// Pricing — server is the source of truth, never trust client total
$prices = [
    'pre-cert'   => 120,
    'cert'       => 150,
    'pre-dip'    => 160,
    'diploma'    => 180,
    'crash'      => 40,
    'meta-order' => 240,
];
$total = $prices[$v['pathway']] ?? 0;
if ($v['includeRegistration'])  $total += 5;
if ($v['includeCertification']) $total += 22;

$db = mam_db();
$db->beginTransaction();
try {
    // Upsert customer
    $cust = mam_db_row('SELECT id FROM customers WHERE email = ?', [$v['email']]);
    if ($cust) {
        $customerId = (int)$cust['id'];
        mam_db_update('customers', [
            'first_name' => $v['firstName'],
            'last_name'  => $v['lastName'],
            'phone'      => $v['phone'],
            'sex'        => $v['sex'],
            'address'    => $v['address'],
            'lga'        => $v['lga'],
            'state'      => $v['state'],
            'country'    => $v['country'],
            'city'       => $v['city'],
            'age_range'  => $v['ageRange'],
            'dob'        => $v['dob'],
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $customerId]);
    } else {
        $customerId = mam_db_insert('customers', [
            'email'      => $v['email'],
            'first_name' => $v['firstName'],
            'last_name'  => $v['lastName'],
            'phone'      => $v['phone'],
            'sex'        => $v['sex'],
            'address'    => $v['address'],
            'lga'        => $v['lga'],
            'state'      => $v['state'],
            'country'    => $v['country'],
            'city'       => $v['city'],
            'age_range'  => $v['ageRange'],
            'dob'        => $v['dob'],
        ]);
    }

    $reference = mam_random_id('MAM');

    $appId = mam_db_insert('applications', [
        'reference'              => $reference,
        'customer_id'            => $customerId,
        'pathway'                => $v['pathway'],
        'mode'                   => $v['mode'],
        'program'                => $v['program'],
        'discipline'             => $v['discipline'],
        'headshot_url'           => $v['headshotUrl'],
        'experience'             => $v['experience'],
        'goals'                  => $v['goals'],
        'referrer'               => $v['referrer'],
        'parent_name'            => $v['parentName'],
        'parent_phone'           => $v['parentPhone'],
        'auditer_optin'          => $v['auditer'],
        'include_registration'   => $v['includeRegistration'],
        'include_certification'  => $v['includeCertification'],
        'program_track'          => $v['programTrack'],
        'policy_mode'            => $v['policyMode'],
        'policy_agreed'          => $v['policyAgreed'],
        'policy_signed_name'     => $v['policySignedName'],
        'policy_signed_at'       => $v['policyAgreed'] ? date('Y-m-d H:i:s') : null,
        'total_usd'              => $total,
        'status'                 => 'submitted',
        'source'                 => substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 200),
        'ip'                     => mam_client_ip(),
        'user_agent'             => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 240),
    ]);

    mam_audit('create', 'application', $appId, ['reference' => $reference, 'total' => $total]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    mam_log('error', 'application_failed', ['err' => $e->getMessage()]);
    mam_fail('save_failed', 500);
}

// Send confirmation (non-blocking on failure)
$customer = mam_db_row('SELECT * FROM customers WHERE id = ?', [$customerId]);
$app = mam_db_row('SELECT * FROM applications WHERE id = ?', [$appId]);
@mam_send_mail(
    $v['email'],
    'MAM Academy — Application received (' . $reference . ')',
    mam_email_application_confirmation($app, $customer),
    true
);
// Send the full class policy the applicant agreed to (online vs onsite),
// stamped with their electronic signature — keep for their records.
if (!empty($v['policyMode'])) {
    @mam_send_mail(
        $v['email'],
        'MAM Academy — Your ' . ($v['policyMode'] === 'onsite' ? 'Onsite' : 'Online/Home') . ' Class Policy',
        mam_email_policy($v['policyMode'], (string)$v['policySignedName'], $reference),
        true
    );
}
$notify = mam_env('NOTIFY_EMAIL');
if ($notify) {
    @mam_send_mail(
        $notify,
        'New MAM Academy application — ' . $reference,
        '<p><strong>' . htmlspecialchars($v['firstName'] . ' ' . $v['lastName']) . '</strong> applied.</p>'
        . '<p>Email: ' . htmlspecialchars($v['email']) . ' · Phone: ' . htmlspecialchars($v['phone']) . '</p>'
        . '<p>Pathway: ' . htmlspecialchars($v['pathway']) . ' · Mode: ' . htmlspecialchars($v['mode']) . ' · Discipline: ' . htmlspecialchars($v['discipline']) . '</p>'
        . '<p>Total: USD $' . number_format($total, 2) . '</p>',
        true,
        ['reply_to' => $v['email']]
    );
}

mam_ok([
    'reference' => $reference,
    'application_id' => $appId,
    'total_usd' => $total,
    'currency_base' => 'USD',
    'pathway' => $v['pathway'],
]);
