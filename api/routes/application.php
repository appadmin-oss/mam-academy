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

// Idempotency: if this exact submission was already accepted (client retry,
// flaky network, double-tap that beat the UI guard), return the original
// application instead of creating a duplicate.
$idemKey = substr(trim((string)($_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? '')), 0, 80);
if ($idemKey !== '') {
    $prior = mam_db_row('SELECT id, reference, total_usd, pathway FROM applications WHERE idempotency_key = ?', [$idemKey]);
    if ($prior) {
        mam_ok([
            'reference'      => $prior['reference'],
            'application_id' => (int)$prior['id'],
            'total_usd'      => (float)$prior['total_usd'],
            'currency_base'  => 'USD',
            'pathway'        => $prior['pathway'],
            'idempotent'     => true,
        ]);
    }
}

$v = mam_validate_application($input);

// Pricing — server is the source of truth, never trust client total.
//   programme fee → online pricing adjustment (backend-only) → + $5 one-time
//   registration. Every programme (Grand included) may be taken online or
//   onsite; the online adjustment is applied here and is never surfaced to the
//   applicant as a separate line item.
// (The standalone $22 certification add-on was retired: certification is now
//  baked into the Certified/Grand Musician programme fee.)
// Level-based engine (spec §2): Crash flat $70 · Standard B · Certified
// 2B+0.5B·extra · Grand (3B+0.5B·extra)·(1−discount). The online adjustment is
// folded in silently; the Grand discount IS surfaced. Server is authoritative.
$priceInfo = mam_compute_application_price([
    'programme'           => $v['pathway'],
    'level'               => $v['level'],
    'mode'                => $v['mode'],
    'extraCourses'        => $v['extraCourses'],
    'includeRegistration' => $v['includeRegistration'],
]);
// MetaMusic Order isn't level-priced — its flat fee comes from mam_prices().
if ($v['pathway'] === 'meta-order') {
    $flat  = (float)(mam_prices()['meta-order'] ?? 0);
    $total = $flat + ($v['includeRegistration'] ? mam_registration_fee() : 0);
} else {
    $total = (float)$priceInfo['total'];
}

// Session is resolved authoritatively from the academic calendar — never the
// client. The form applies it silently; here we stamp the operative code.
$v['session'] = (string)(mam_session_current()['code'] ?? $v['session']);

$db = mam_db();
$db->beginTransaction();
try {
    // Upsert customer
    $cust = mam_db_row('SELECT id FROM customers WHERE email = ?', [$v['email']]);
    if ($cust) {
        $customerId = (int)$cust['id'];
        /* A value this submission does not carry must never write NULL over one an
           earlier application stored. The registration-photo bug (6fd1028) returned
           from validation before dob/age/country/city were set, and this update then
           blanked them on the SHARED customer row — erasing a returning family's
           details from their other, healthy applications too. */
        mam_db_update('customers', array_filter([
            'first_name' => $v['firstName'],
            'last_name'  => $v['lastName'],
            'phone'      => $v['phone'],
            'sex'        => $v['sex'],
            'address'    => $v['address'],
            'lga'        => $v['lga'],
            'state'      => $v['state'],
            'country'    => $v['country'] ?? null,
            'city'       => $v['city'] ?? null,
            'age_range'  => $v['ageRange'] ?? null,
            'dob'        => $v['dob'] ?? null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], static fn($x) => $x !== null && $x !== ''), ['id' => $customerId]);
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
        'idempotency_key'        => $idemKey ?: null,
        'customer_id'            => $customerId,
        'pathway'                => $v['pathway'],
        'mode'                   => $v['mode'],
        'level'                  => $v['level'] ?: null,
        'expression'             => $v['expression'] ?: null,
        'program'                => $v['program'],
        'discipline'             => $v['discipline'],
        'extra_courses'          => $v['extraCourses'],
        'courses_label'          => $v['coursesLabel'],
        'session'                => $v['session'],
        'headshot_url'           => $v['headshotUrl'],
        'experience'             => $v['experience'],
        'goals'                  => $v['goals'],
        'referrer'               => $v['referrer'],
        'readiness_signal'       => $v['readinessSignal'],
        'parent_name'            => $v['parentName'],
        'parent_phone'           => $v['parentPhone'],
        'parent_email'           => $v['parentEmail'],
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

    mam_audit('create', 'application', $appId, ['reference' => $reference, 'total' => $total, 'readiness' => $v['readinessSignal']]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    mam_log('error', 'application_failed', ['err' => $e->getMessage()]);
    // Expose the raw DB error in non-production so the operator can identify
    // which column is missing without digging into server logs.
    $extra = mam_env('APP_ENV', 'production') !== 'production'
        ? ['debug' => $e->getMessage()]
        : [];
    mam_fail('save_failed', 500, $extra);
}

// Send confirmation + policy + admin notice AFTER the client has its JSON
// response. Three SMTP round-trips at ~1-3 s each used to block the apply
// submit for up to 10 s; deferring them via mam_after_response means the
// applicant sees the success page instantly while the emails flush behind
// the scenes (PHP-FPM closes the client connection via fastcgi_finish_request).
$customer = mam_db_row('SELECT * FROM customers WHERE id = ?', [$customerId]);
$app = mam_db_row('SELECT * FROM applications WHERE id = ?', [$appId]);
$tier = mam_booking_tier();
$invoiceNumber = 'INV-MAM-' . date('y') . '-' . str_pad((string)$appId, 4, '0', STR_PAD_LEFT);
$notifyEmail = mam_env('NOTIFY_EMAIL');

mam_after_response(function () use ($v, $app, $customer, $tier, $invoiceNumber, $reference, $total, $notifyEmail) {
    // No more .html attachments — the email body now links to web-viewable
    // /api/invoice/{ref} and /api/policy/{ref} pages. Both open in any
    // browser, print clean, save-as-PDF in one shortcut. The previous
    // approach attached .html which most mail clients (Gmail mobile in
    // particular) render as source code.
    mam_send(
        $v['email'],
        'MAM Academy — Application received (' . $reference . ')',
        mam_email_application_confirmation($app, $customer, $tier, $invoiceNumber),
        'application_confirmation',
        true
    );
    if (!empty($v['policyMode'])) {
        mam_send(
            $v['email'],
            'MAM Academy — Your ' . ($v['policyMode'] === 'onsite' ? 'Onsite' : 'Online/Home') . ' Class Policy',
            mam_email_policy($v['policyMode'], (string)$v['policySignedName'], $reference),
            'application_policy',
            true
        );
    }
    if ($notifyEmail) {
        $adminUrl = rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/') . '/admin/?v=applications';
        mam_send(
            $notifyEmail,
            'New MAM Academy application — ' . $reference,
            mam_email_admin_notice(
                'New application received',
                [
                    'Reference'  => $reference,
                    'Applicant'  => $v['firstName'] . ' ' . $v['lastName'],
                    'Email'      => $v['email'],
                    'Phone'      => $v['phone'] ?: '—',
                    'Programme'  => $v['pathway'],
                    'Mode'       => $v['mode'] === 'online' ? 'Online' : 'Onsite',
                    'Discipline' => $v['discipline'] ?: '—',
                    'Total'      => 'USD $' . number_format($total, 2),
                ],
                [
                    'lede' => $v['firstName'] . ' ' . $v['lastName'] . ' just submitted an application. Details below.',
                    'cta'  => ['url' => $adminUrl, 'label' => 'Review in admin'],
                ]
            ),
            'application_admin_notice',
            true,
            ['reply_to' => $v['email']]
        );
    }
});

mam_ok([
    'reference' => $reference,
    'application_id' => $appId,
    'total_usd' => $total,
    'currency_base' => 'USD',
    'pathway' => $v['pathway'],
]);
