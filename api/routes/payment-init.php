<?php
/**
 * POST /api/payment/initialize
 *
 * Body JSON:
 *   { reference: "MAM-…", provider: "paystack" | "flutterwave" | "stripe" | "bank" }
 *
 * For Paystack: returns the authorization_url + access_code (recommended)
 *   AND/OR keeps the existing inline-popup flow (the frontend can choose).
 *
 * For Flutterwave: returns the FlutterwaveCheckout payload (the frontend
 *   opens the SDK; we record the tx_ref server-side for verification).
 *
 * For Stripe: creates a hosted Checkout Session and returns its URL.
 * For bank: records intent + returns bank instructions.
 */
declare(strict_types=1);

$in = mam_json_input();
$ref = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($in['reference'] ?? ''));
$provider = strtolower(trim((string)($in['provider'] ?? '')));

$app = mam_db_row('SELECT a.*, c.email AS email, c.first_name, c.last_name, c.phone
                    FROM applications a JOIN customers c ON c.id = a.customer_id
                    WHERE a.reference = ?', [$ref]);
if (!$app) mam_fail('application_not_found', 404);

$totalUsd = (float)$app['total_usd'];
if ($totalUsd <= 0) mam_fail('zero_amount', 400);

$ngnRate = (float)mam_env('USD_TO_NGN', '1500');
$amountNgn = (int)round($totalUsd * $ngnRate);
$amountKobo = $amountNgn * 100;

/*
 * What this provider actually charges, in its own currency.
 *
 * Paystack and Flutterwave are Nigerian rails and take NGN. Stripe settles
 * the application's own USD directly. A bank transfer is quoted in NGN
 * because that is the account the money lands in.
 *
 * This used to be hard-coded to NGN for every provider, which was right
 * until Stripe existed and is wrong now: the row would have said NGN while
 * Stripe charged dollars, and the amount check in payment-verify compares
 * the two.
 */
if ($provider === 'stripe') {
    $rowAmount = $totalUsd;
    $rowCurrency = 'USD';
} else {
    $rowAmount = $amountNgn;
    $rowCurrency = 'NGN';
}

// Persist a payment row (idempotent via reference)
$existing = mam_db_row('SELECT * FROM payments WHERE reference = ?', [$ref]);
if (!$existing) {
    mam_db_insert('payments', [
        'application_id' => (int)$app['id'],
        'reference'      => $ref,
        'idempotency_key'=> $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? null,
        'provider'       => $provider,
        'status'         => 'pending',
        'amount'         => $rowAmount,
        'currency'       => $rowCurrency,
    ]);
} elseif ($existing['status'] === 'succeeded') {
    // Already paid. Re-initialising would let somebody be charged twice for
    // the same application because they pressed the button again.
    mam_ok([
        'provider'  => $existing['provider'],
        'reference' => $ref,
        'status'    => 'succeeded',
        'already_paid' => true,
    ]);
} else {
    /*
     * They changed their mind about how to pay.
     *
     * The amount and currency move with the provider. Updating only the
     * provider — which is what this did — left a row saying NGN 265,500
     * against a Stripe charge of USD 177, and payment-verify compares the
     * paid amount against exactly that number.
     *
     * The status moves too. 'pending_bank_transfer' is a bank-only state,
     * and it survived the switch: a Stripe attempt sat in the admin's
     * Payments list reading "awaiting bank transfer", which is a transfer
     * nobody is ever going to send. Anything not yet succeeded goes back to
     * plain 'pending'; the bank branch below sets its own state again.
     */
    mam_db_update('payments', [
        'provider'   => $provider,
        'status'     => 'pending',
        'amount'     => $rowAmount,
        'currency'   => $rowCurrency,
        'updated_at' => date('Y-m-d H:i:s'),
    ], ['reference' => $ref]);
}

$metadata = [
    'application_id' => (int)$app['id'],
    'reference'      => $ref,
    'pathway'        => $app['pathway'],
    'mode'           => $app['mode'],
    'discipline'     => $app['discipline'],
];

try {
    if ($provider === 'paystack') {
        $resp = mam_paystack_initialize($app['email'], $amountKobo, $ref, $metadata);
        $body = $resp['data'];
        if (!($body['status'] ?? false)) mam_fail('paystack_init_failed', 502, ['provider' => $body]);
        mam_audit('init', 'payment', null, ['ref' => $ref, 'provider' => $provider]);
        mam_ok([
            'provider' => 'paystack',
            'reference' => $ref,
            'amount_ngn' => $amountNgn,
            'amount_usd' => $totalUsd,
            'public_key' => mam_env('PAYSTACK_PUBLIC_KEY'),
            'access_code' => $body['data']['access_code'] ?? null,
            'authorization_url' => $body['data']['authorization_url'] ?? null,
        ]);
    } elseif ($provider === 'flutterwave') {
        // Flutterwave inline: build the payload the frontend will hand
        // to FlutterwaveCheckout(). We just persist tx_ref here so the
        // webhook + verify endpoints can reconcile.
        /*
         * The inline modal cannot open without a publishable key. Paystack
         * and Stripe both refuse when theirs is missing; this branch used to
         * answer ok:true with public_key:null, so the browser called
         * FlutterwaveCheckout() with no key and the payer was left looking at
         * a button that does nothing, having filled in the whole application.
         */
        $flwPublic = trim((string)mam_env('FLW_PUBLIC_KEY', ''));
        if ($flwPublic === '') {
            mam_log('error', 'provider_not_configured', ['provider' => 'flutterwave', 'ref' => $ref]);
            mam_fail('provider_not_configured', 502, [
                'provider' => 'flutterwave',
                'message'  => 'Flutterwave is not available on this site yet. Please choose another payment method.',
            ]);
        }
        mam_audit('init', 'payment', null, ['ref' => $ref, 'provider' => $provider]);
        mam_ok([
            'provider' => 'flutterwave',
            'reference' => $ref,
            'amount_ngn' => $amountNgn,
            'amount_usd' => $totalUsd,
            'public_key' => $flwPublic,
            'payload' => [
                'tx_ref'       => $ref,
                'amount'       => $amountNgn,
                'currency'     => 'NGN',
                'customer'     => [
                    'email'        => $app['email'],
                    'phone_number' => $app['phone'],
                    'name'         => trim($app['first_name'] . ' ' . $app['last_name']),
                ],
                'customizations' => [
                    'title'       => 'MAM Academy',
                    'description' => 'Application ' . $ref,
                    'logo'        => rtrim((string)mam_env('APP_URL', ''), '/') . '/mam-academy-logo.svg',
                ],
                'meta' => $metadata,
            ],
        ]);
    } elseif ($provider === 'stripe') {
        /*
         * A hosted Checkout Session, and the redirect to it.
         *
         * Charged in USD cents — the application's own base currency. The
         * other two providers convert to NGN because they are Nigerian rails;
         * Stripe settles USD directly, and converting anyway would leave a
         * payer disputing an FX rate we made up.
         */
        $amountCents = (int)round($totalUsd * 100);
        $resp = mam_stripe_create_session($app['email'], $amountCents, $ref, $metadata);
        $body = $resp['data'];
        if (($resp['code'] ?? 0) >= 400 || empty($body['url'])) {
            mam_fail('stripe_init_failed', 502, ['provider' => $body['error'] ?? $body]);
        }
        // The session id is kept so verify can retrieve it without the
        // browser having to hand one back — a value the payer could edit.
        mam_db_update('payments', [
            'provider_reference' => $body['id'] ?? null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['reference' => $ref]);
        mam_audit('init', 'payment', null, ['ref' => $ref, 'provider' => $provider]);
        mam_ok([
            'provider' => 'stripe',
            'reference' => $ref,
            'amount_usd' => $totalUsd,
            'amount_cents' => $amountCents,
            'public_key' => mam_env('STRIPE_PUBLIC_KEY'),
            'session_id' => $body['id'] ?? null,
            // apply.js redirects to this; it is the whole integration on the
            // client side.
            'checkout_url' => $body['url'],
        ]);
    } elseif ($provider === 'bank') {
        mam_db_update('payments', ['status' => 'pending_bank_transfer'], ['reference' => $ref]);
        mam_audit('init', 'payment', null, ['ref' => $ref, 'provider' => $provider]);
        mam_ok([
            'provider' => 'bank',
            'reference' => $ref,
            'amount_ngn' => $amountNgn,
            'amount_usd' => $totalUsd,
            /*
             * ?: not ??. A key present-but-empty in .env is a string, so ??
             * keeps the empty string and the payer is told to email proof of
             * payment to nobody — "Email proof of payment to  with reference
             * MAM-…". An unset key and a blank one should read the same.
             */
            'instructions' => 'Email proof of payment to '
                . (trim((string)mam_env('NOTIFY_EMAIL', '')) ?: 'connect@mamcentre.org')
                . ' with reference ' . $ref,
        ]);
    } else {
        mam_fail('unsupported_provider', 400, ['provider' => $provider]);
    }
} catch (Throwable $e) {
    mam_log('error', 'payment_init_failed', ['err' => $e->getMessage(), 'ref' => $ref, 'provider' => $provider]);
    /*
     * The exception text belongs in the log, not in front of the payer. It
     * carries curl transport detail and provider internals — "Stripe network
     * error: CONNECT tunnel failed, response 403" — which tell somebody
     * trying to pay nothing at all, and describe our infrastructure to
     * anybody who is not trying to pay. What they need is the other methods
     * and a reference to quote.
     */
    mam_fail('init_failed', 502, [
        'message' => 'We could not start that payment. Please try another method, or email '
            . (trim((string)mam_env('NOTIFY_EMAIL', '')) ?: 'connect@mamcentre.org')
            . ' quoting reference ' . $ref . '.',
    ]);
}
