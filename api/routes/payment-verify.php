<?php
/**
 * POST /api/payment/verify
 * Body: { reference: "MAM-…", provider: "paystack" | "flutterwave", transaction_id?: int }
 * Verifies the payment server-side and marks the application paid.
 * Idempotent: re-verification of an already-paid payment returns success.
 */
declare(strict_types=1);

$in = mam_json_input();
$ref = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($in['reference'] ?? ''));
$provider = strtolower(trim((string)($in['provider'] ?? '')));
$txId = isset($in['transaction_id']) ? (int)$in['transaction_id'] : 0;

$payment = mam_db_row('SELECT * FROM payments WHERE reference = ?', [$ref]);
if (!$payment) mam_fail('payment_not_found', 404);
$app = mam_db_row('SELECT * FROM applications WHERE id = ?', [$payment['application_id']]);
if (!$app) mam_fail('application_not_found', 404);

if ($payment['status'] === 'succeeded') {
    mam_ok(['status' => 'succeeded', 'reference' => $ref, 'already_verified' => true]);
}

try {
    if ($provider === 'paystack') {
        $resp = mam_paystack_verify($ref);
        $data = $resp['data']['data'] ?? null;
        $status = $data['status'] ?? null;
        $ok = ($status === 'success');
        $providerRef = $data['reference'] ?? $ref;
    } elseif ($provider === 'flutterwave') {
        $resp = $txId > 0 ? mam_flw_verify_by_id($txId) : mam_flw_verify_by_tx_ref($ref);
        $data = $resp['data']['data'] ?? null;
        $status = $data['status'] ?? null;
        $ok = ($status === 'successful' && (float)($data['amount'] ?? 0) >= (float)$payment['amount']);
        $providerRef = $data['tx_ref'] ?? ($data['flw_ref'] ?? $ref);
    } else {
        mam_fail('unsupported_provider', 400);
    }
} catch (Throwable $e) {
    mam_log('error', 'verify_exception', ['err' => $e->getMessage(), 'ref' => $ref]);
    mam_fail('upstream_error', 502, ['message' => $e->getMessage()]);
}

if (!$ok) {
    mam_db_update('payments', [
        'status' => 'failed',
        'provider_response' => json_encode($data),
        'updated_at' => date('Y-m-d H:i:s'),
    ], ['reference' => $ref]);
    mam_audit('verify_failed', 'payment', null, ['ref' => $ref, 'status' => $status]);
    mam_fail('verification_failed', 402, ['provider_status' => $status]);
}

mam_db_update('payments', [
    'status'             => 'succeeded',
    'provider_reference' => $providerRef ?? null,
    'provider_response'  => json_encode($data),
    'verified_at'        => date('Y-m-d H:i:s'),
    'updated_at'         => date('Y-m-d H:i:s'),
], ['reference' => $ref]);

mam_db_update('applications', ['status' => 'paid', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $payment['application_id']]);
mam_audit('verify_succeeded', 'payment', (int)$payment['id'], ['ref' => $ref, 'provider' => $provider]);

// Auto-provision a student portal account once payment clears.
$portalNotice = null;
try {
    if ($app['customer_id']) {
        $provision = mam_ensure_student_user((int)$app['customer_id']);
        if ($provision['password']) {
            $customer = mam_db_row('SELECT * FROM customers WHERE id = ?', [(int)$app['customer_id']]);
            $loginUrl = rtrim((string)mam_env('APP_URL', ''), '/') . '/portal/login.html';
            $name = htmlspecialchars(trim($customer['first_name'] . ' ' . $customer['last_name']));
            $body = "<!doctype html><html><body style='background:#120D08;color:#fff;font-family:Arial,sans-serif;padding:24px'>"
                  . "<table style='max-width:560px;margin:0 auto;background:#1A120B;border:1px solid #251A10;border-radius:18px;overflow:hidden'>"
                  . "<tr><td style='background:linear-gradient(135deg,#E08A1A,#FBBF24);padding:24px;text-align:center'>"
                  . "<div style='font-family:Impact,sans-serif;font-size:24px;color:#1A120B;font-weight:900;letter-spacing:.08em'>MAM ACADEMY</div></td></tr>"
                  . "<tr><td style='padding:32px 28px;color:#FBF7F3;line-height:1.7'>"
                  . "<h2 style='font-family:Georgia,serif;margin:0 0 12px;color:#fff'>Welcome, $name</h2>"
                  . "<p style='color:rgba(255,255,255,0.86)'>Your student portal is now active. Sign in to view your applications, classes and MAM Auditer placement.</p>"
                  . "<div style='background:#251A10;border:1px dashed #FBBF24;border-radius:10px;padding:14px 18px;font-family:monospace;font-size:14px;color:#FBBF24;margin:16px 0'>"
                  . "<div>Email: <strong>" . htmlspecialchars($customer['email']) . "</strong></div>"
                  . "<div>Temporary password: <strong>" . htmlspecialchars($provision['password']) . "</strong></div>"
                  . "</div>"
                  . "<p style='color:rgba(255,255,255,0.86)'>Please change this password on first sign-in.</p>"
                  . "<p><a href='$loginUrl' style='display:inline-block;padding:12px 22px;background:linear-gradient(135deg,#E08A1A,#FBBF24);color:#1A120B;border-radius:8px;font-weight:800;text-decoration:none'>Open student portal →</a></p>"
                  . "</td></tr></table></body></html>";
            @mam_send_mail($customer['email'], 'Your MAM Academy student portal is ready', $body, true);
            $portalNotice = 'portal_credentials_emailed';
        } else {
            $portalNotice = 'portal_already_provisioned';
        }
    }
} catch (Throwable $e) {
    mam_log('warn', 'student_provision_failed', ['ref' => $ref, 'err' => $e->getMessage()]);
}

mam_ok([
    'status' => 'succeeded',
    'reference' => $ref,
    'provider' => $provider,
    'verified_at' => date('c'),
    'portal' => $portalNotice,
]);
