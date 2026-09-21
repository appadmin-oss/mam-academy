<?php
/**
 * Stripe API client (server-side).
 * Docs: https://docs.stripe.com/api
 *
 * Built because apply.html has always offered Stripe and .env.example has
 * always carried its three keys, while nothing on the server answered to it —
 * anyone who picked it was told "Stripe Checkout is not configured for this
 * site yet" after filling in the whole form.
 *
 * ── Checkout Session, not Elements ───────────────────────────────────────
 *
 * The other two providers open an inline modal with a publishable key. Stripe
 * can do that too, but it needs a client secret minted per payment and a JS
 * bundle to mount card fields — more moving parts on a page that has to work
 * on a slow Nigerian connection. A Checkout Session is one server call and a
 * redirect: Stripe hosts the card form, handles SCA and wallets, and sends
 * the payer back. apply.js already expects exactly this — it redirects to
 * `init.checkout_url` when one is returned.
 *
 * ── Money ────────────────────────────────────────────────────────────────
 *
 * Charged in USD, in cents, because USD is the application's base currency
 * (applications.total_usd) and Stripe settles it directly. Paystack and
 * Flutterwave convert to NGN because they are Nigerian rails; Stripe has no
 * reason to, and converting twice would leave a payer arguing about an FX
 * rate we invented.
 */
declare(strict_types=1);

function mam_stripe_key(): string {
    $k = (string)mam_env('STRIPE_SECRET_KEY', '');
    if ($k === '') throw new RuntimeException('Stripe not configured');
    return $k;
}

/**
 * Stripe's API takes form encoding, not JSON — including for nested data,
 * which it expects as line_items[0][price_data][currency]. http_build_query
 * produces exactly that shape, so the payload is written as a normal nested
 * array and flattened here rather than by hand at each call site.
 */
function mam_stripe_request(string $method, string $path, array $payload = []): array {
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => 'https://api.stripe.com/v1' . $path,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . mam_stripe_key(),
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ];
    if (strtoupper($method) === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($payload);
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) throw new RuntimeException('Stripe network error: ' . $err);
    return ['code' => $code, 'data' => json_decode($resp, true), 'raw' => $resp];
}

/**
 * Create the Checkout Session the payer is redirected to.
 *
 * `client_reference_id` carries our own reference, so the webhook can find
 * the payment row without trusting anything the browser sends back.
 */
function mam_stripe_create_session(
    string $email, int $amountCents, string $reference, array $metadata = []
): array {
    $appUrl = rtrim((string)mam_env('APP_URL', ''), '/');
    return mam_stripe_request('POST', '/checkout/sessions', [
        'mode' => 'payment',
        'customer_email' => $email,
        'client_reference_id' => $reference,
        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => 'usd',
                'unit_amount' => $amountCents,
                'product_data' => [
                    'name' => 'MAM Academy — application ' . $reference,
                ],
            ],
        ]],
        'metadata' => $metadata + ['reference' => $reference],
        // Stripe substitutes the real id for the placeholder on redirect.
        'success_url' => $appUrl . '/thank-you.html?ref=' . urlencode($reference)
                       . '&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'  => $appUrl . '/apply.html?ref=' . urlencode($reference) . '&cancelled=1',
    ]);
}

function mam_stripe_retrieve_session(string $sessionId): array {
    return mam_stripe_request('GET', '/checkout/sessions/' . rawurlencode($sessionId));
}

/**
 * Verify a webhook's Stripe-Signature header.
 *
 * The header looks like `t=1690000000,v1=abc…`. The signed payload is the
 * timestamp, a dot, and the raw body — so it cannot be replayed against a
 * different body, and the timestamp lets an old capture be rejected.
 *
 * $tolerance is why the timestamp is in there at all: without an age check a
 * signature stays valid forever, and a webhook captured once can be replayed
 * whenever the sender likes.
 */
function mam_stripe_verify_signature(string $rawBody, string $header, int $tolerance = 300): bool {
    $secret = (string)mam_env('STRIPE_WEBHOOK_SECRET', '');
    if ($secret === '' || $header === '') return false;

    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $header) as $part) {
        $bits = explode('=', trim($part), 2);
        if (count($bits) !== 2) continue;
        if ($bits[0] === 't') $timestamp = $bits[1];
        if ($bits[0] === 'v1') $signatures[] = $bits[1];
    }
    if ($timestamp === null || $signatures === []) return false;
    if (abs(time() - (int)$timestamp) > $tolerance) return false;

    $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    foreach ($signatures as $sig) {
        // hash_equals, not ===: a timing-safe compare, because the attacker
        // controls the value being compared against.
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}
