<?php
/**
 * POST /api/summer/apply
 * Accepts a summer programme application.
 * No pricing engine, no pathway — fee is admin-configured.
 * Returns reference + programme details on success.
 */
declare(strict_types=1);

$input = mam_json_input();
mam_honeypot($input);

// Guard: the programme must be configured AND within its window. Shares the one
// predicate with /summer, the /apply chooser and config-public, so the form can
// never be hidden while this endpoint still accepts (or the reverse). Each window
// bound is honoured independently — requiring BOTH meant setting only
// `summer_app_close` left applications open indefinitely.
if (!mam_summer_accepting()) {
    mam_fail('applications_closed', 422, ['message' => 'Summer programme applications are not currently open.']);
}

$v = mam_validate_summer_application($input);

$reference = mam_random_id('SUM');

$db = mam_db();
$db->beginTransaction();
try {
    $appId = mam_db_insert('summer_applications', [
        'reference'         => $reference,
        'first_name'        => $v['firstName'],
        'last_name'         => $v['lastName'],
        'email'             => $v['email'],
        'phone'             => $v['phone'] ?: null,
        'dob'               => $v['dob'],
        'age_at_submission' => $v['ageAtSubmission'],
        'sex'               => $v['sex'],
        'country'           => $v['country'] ?: null,
        'city'              => $v['city'] ?: null,
        'guardian_name'     => $v['guardianName'],
        'guardian_phone'    => $v['guardianPhone'],
        'guardian_email'    => $v['guardianEmail'] ?: null,
        'discipline'        => $v['discipline'] ?: null,
        'experience'        => $v['experience'] ?: null,
        'goals'             => $v['goals'] ?: null,
        'referrer'          => $v['referrer'] ?: null,
        'policy_agreed'     => 1,
        'policy_signed_name'=> $v['policySignedName'],
        'policy_signed_at'  => date('Y-m-d H:i:s'),
        'session_year'      => (int)date('Y'),
        'headshot_url'      => $v['headshotUrl'] ?: null,
        // Distinguish "no fee configured" from a deliberate zero fee: only an
        // absent setting stores NULL. (`(float)… ?: null` collapsed both to NULL.)
        'fee_snapshot_usd'  => mam_setting('summer_fee', '') === '' ? null : (float)mam_setting_num('summer_fee', 0),
        'status'            => 'submitted',
        'source'            => substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 200),
        'ip'                => mam_client_ip(),
        'user_agent'        => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 240),
    ]);
    mam_audit('create', 'summer_application', $appId, ['reference' => $reference]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    mam_log('error', 'summer_application_failed', ['err' => $e->getMessage()]);
    $extra = mam_env('APP_ENV', 'production') !== 'production' ? ['debug' => $e->getMessage()] : [];
    mam_fail('save_failed', 500, $extra);
}

$app = mam_db_row('SELECT * FROM summer_applications WHERE id = ?', [$appId]);
$notifyEmail = mam_env('NOTIFY_EMAIL');
$progName = mam_setting('summer_prog_name', 'MAM Summer Music Camp');

mam_after_response(function () use ($v, $app, $reference, $progName, $notifyEmail) {
    if ($app) {
        mam_send(
            $v['email'],
            $progName . ' — Application received (' . $reference . ')',
            mam_email_summer_confirmation($app),
            'summer_application_confirmation',
            true
        );
    }
    if ($notifyEmail) {
        $adminUrl = rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/') . '/admin/?v=summer';
        mam_send(
            $notifyEmail,
            'New summer application — ' . $reference,
            mam_email_admin_notice(
                'New summer programme application',
                [
                    'Reference'    => $reference,
                    'Applicant'    => $v['firstName'] . ' ' . $v['lastName'],
                    'Email'        => $v['email'],
                    'Phone'        => $v['phone'] ?: '—',
                    'DOB'          => $v['dob'],
                    'Age'          => $v['ageAtSubmission'] . ' yrs',
                    'Guardian'     => $v['guardianName'] . ' (' . $v['guardianPhone'] . ')',
                    'Discipline'   => $v['discipline'] ?: '—',
                ],
                [
                    'lede' => $v['firstName'] . ' ' . $v['lastName'] . ' applied to the summer programme.',
                    'cta'  => ['url' => $adminUrl, 'label' => 'View in admin'],
                ]
            ),
            'summer_application_admin_notice',
            true,
            ['reply_to' => $v['email']]
        );
    }
});

mam_ok([
    'reference'   => $reference,
    'programme'   => $progName,
    'session_year'=> (int)date('Y'),
]);
