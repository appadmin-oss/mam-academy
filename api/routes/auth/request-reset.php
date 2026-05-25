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
    $user = mam_db_row('SELECT id, email, name FROM users WHERE email = ? AND status = "active"', [$email]);
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
            $link   = $appUrl . '/portal/reset.html?token=' . $token;
            $name   = htmlspecialchars($user['name'] ?: explode('@', $user['email'])[0], ENT_QUOTES);
            $linkE  = htmlspecialchars($link, ENT_QUOTES);
            $body   = "<!doctype html><html><body style='background:#120D08;color:#fff;font-family:Arial,sans-serif;padding:24px'>"
                    . "<table style='max-width:560px;margin:0 auto;background:#1A120B;border:1px solid #251A10;border-radius:18px;overflow:hidden'>"
                    . "<tr><td style='background:linear-gradient(135deg,#E08A1A,#FBBF24);padding:24px;text-align:center'>"
                    . "<div style='font-family:Impact,sans-serif;font-size:24px;color:#1A120B;font-weight:900;letter-spacing:.08em'>MAM ACADEMY</div></td></tr>"
                    . "<tr><td style='padding:32px 28px;color:#FBF7F3;line-height:1.7'>"
                    . "<h2 style='font-family:Georgia,serif;margin:0 0 12px;color:#fff'>Reset your password, $name</h2>"
                    . "<p style='color:rgba(255,255,255,0.86)'>Click below to set a new password. The link works once and expires in <strong>60 minutes</strong>.</p>"
                    . "<p style='text-align:center;margin:28px 0'><a href='$linkE' style='display:inline-block;padding:14px 28px;background:linear-gradient(135deg,#E08A1A,#FBBF24);color:#1A120B;border-radius:8px;font-weight:800;text-decoration:none'>Set new password</a></p>"
                    . "<p style='font-size:13px;color:rgba(255,255,255,0.6)'>If the button doesn't work, paste this link into your browser:<br><span style='word-break:break-all;color:#FBBF24'>$linkE</span></p>"
                    . "<p style='font-size:13px;color:rgba(255,255,255,0.5);margin-top:24px'>Didn't request this? You can ignore this email — your password stays the same.</p>"
                    . "</td></tr></table></body></html>";

            @mam_send_mail($user['email'], 'Reset your MAM Academy password', $body, true);
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
