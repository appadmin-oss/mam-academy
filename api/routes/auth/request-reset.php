<?php
/**
 * POST /api/auth/request-reset
 * Body: { email }
 *
 * Generates a one-hour reset token and emails the user a link.
 * To prevent account enumeration, the endpoint ALWAYS returns
 * success — whether the email matched a real account or not.
 */
declare(strict_types=1);

$in = mam_json_input();
$email = mam_email(mam_str($in, 'email', 120));

if ($email) {
    $user = mam_db_row("SELECT id, email, name FROM users WHERE email = ? AND status = 'active'", [$email]);
    if ($user) {
        $token   = bin2hex(random_bytes(20));
        $hash    = hash('sha256', $token);
        $expires = date('Y-m-d H:i:s', time() + 3600);
        try {
            mam_db_insert('password_resets', [
                'token_hash' => $hash,
                'user_id'    => (int)$user['id'],
                'expires_at' => $expires,
            ]);

            $appUrl = rtrim((string)mam_env('APP_URL', ''), '/');
            $link   = $appUrl . '/portal/reset?token=' . $token;
            $body   = mam_email_password_reset(
                (string)($user['name'] ?? explode('@', $user['email'])[0]),
                $link
            );

            mam_send($user['email'], 'Reset your MAM Academy password', $body, 'password_reset', true);
            mam_audit('request_reset', 'user', (int)$user['id']);
        } catch (Throwable $e) {
            mam_log('warn', 'reset_request_failed', ['err' => $e->getMessage()]);
        }
    } else {
        // Log for monitoring but don't tell the client.
        mam_log('info', 'reset_request_unknown_email', ['email_hash' => sha1($email)]);
    }
}

// Always tell the client we sent it — anti-enumeration.
mam_ok(['sent' => true]);
