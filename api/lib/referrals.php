<?php
/**
 * MAM Academy — referral program (Advanced Modernization §6.3).
 * ─────────────────────────────────────────────────────────────
 * Each user has a trackable referral code; a referred sign-up is recorded as a
 * pending referral, and staff reward the referrer's wallet on conversion.
 * Growth from your happiest families, natively.
 */
declare(strict_types=1);

/** Get (or lazily create) a user's unique referral code. */
function mam_referral_code(int $userId): string {
    $row = mam_db_row('SELECT code FROM referral_codes WHERE user_id = ?', [$userId]);
    if ($row) return (string) $row['code'];
    for ($i = 0; $i < 6; $i++) {
        $code = 'MAM-' . strtoupper(base_convert((string) $userId, 10, 36)) . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
        try {
            mam_db_insert('referral_codes', ['user_id' => $userId, 'code' => $code]);
            return $code;
        } catch (\Throwable $e) {
            $again = mam_db_row('SELECT code FROM referral_codes WHERE user_id = ?', [$userId]);
            if ($again) return (string) $again['code']; // lost a race — reuse the winner
        }
    }
    throw new RuntimeException('code_gen_failed');
}

/** Resolve a code to the referrer's user id, or null. */
function mam_referral_by_code(string $code): ?int {
    $r = mam_db_row('SELECT user_id FROM referral_codes WHERE code = ?', [strtoupper(trim($code))]);
    return $r ? (int) $r['user_id'] : null;
}

/**
 * Record a pending referral for a code. De-dupes an un-rewarded referral for
 * the same referrer + email. Returns the referral id, or null if the code is
 * unknown or a duplicate.
 */
function mam_referral_capture(string $code, ?string $name = null, ?string $email = null, ?int $referredUserId = null): ?int {
    $ref = mam_referral_by_code($code);
    if (!$ref) return null;
    if ($referredUserId !== null && $referredUserId === $ref) return null; // can't refer yourself
    $email = $email ? strtolower(trim($email)) : null;
    if ($email && mam_db_row("SELECT id FROM referrals WHERE referrer_id = ? AND referred_email = ? AND status != 'rewarded'", [$ref, $email])) return null;
    // A capture with NO email cannot be de-duped by identity, so it is capped
    // by volume instead. /api/referral/capture is public and CSRF-less by
    // design (a sign-up beacon, no session to bind to), which without this
    // lets anyone who knows a code post {code, name} on a loop and bury a
    // family's referral list — and the admin queue — in plausible-looking
    // pending rows. Real referrals arrive with an email and are unaffected.
    if ($email === null && !mam_referral_under_anon_cap($ref)) return null;
    return mam_db_insert('referrals', [
        'referrer_id'      => $ref,
        'code'             => strtoupper(trim($code)),
        'referred_name'    => $name ? mb_substr(trim($name), 0, 120) : null,
        'referred_email'   => $email,
        'referred_user_id' => $referredUserId,
        'status'           => 'pending',
    ]);
}

/**
 * Are there still slots for an anonymous (email-less) referral against this
 * referrer today? Deliberately per-referrer rather than per-IP: the thing being
 * protected is one family's list, and an attacker rotating IPs would walk
 * straight past an IP cap. The rate limiter on the route handles IP volume.
 */
function mam_referral_under_anon_cap(int $referrerId, ?string $today = null): bool {
    $today = $today ?: date('Y-m-d');
    $cap   = (int) mam_setting_num('referral_anon_daily_cap', 5);
    if ($cap <= 0) return false; // 0 disables email-less capture entirely
    $n = (int) (mam_db_row(
        "SELECT COUNT(*) AS n FROM referrals
          WHERE referrer_id = ? AND referred_email IS NULL AND created_at >= ? AND created_at < ?",
        [$referrerId, $today . ' 00:00:00', $today . ' 23:59:59']
    )['n'] ?? 0);
    return $n < $cap;
}

/** A referrer's own referrals (newest first). */
function mam_referral_list_for(int $userId): array {
    return mam_db_all('SELECT id, referred_name, referred_email, status, reward_cents, created_at, rewarded_at FROM referrals WHERE referrer_id = ? ORDER BY id DESC', [$userId]);
}

/** Admin listing (optionally by status) with referrer identity. */
function mam_referral_admin_list(?string $status = null, int $limit = 200): array {
    $lim = max(1, $limit);
    if ($status) {
        return mam_db_all("SELECT r.*, u.name AS referrer_name, u.email AS referrer_email FROM referrals r JOIN users u ON u.id = r.referrer_id WHERE r.status = ? ORDER BY r.id DESC LIMIT $lim", [$status]);
    }
    return mam_db_all("SELECT r.*, u.name AS referrer_name, u.email AS referrer_email FROM referrals r JOIN users u ON u.id = r.referrer_id ORDER BY r.id DESC LIMIT $lim");
}

/**
 * Reward a referral: credit the referrer's wallet and mark it rewarded.
 * Idempotent (an already-rewarded referral is a no-op). $rewardCents defaults
 * to the referral_reward_cents setting (fallback $10).
 */
function mam_referral_reward(int $referralId, ?int $rewardCents = null, ?int $by = null): bool {
    $r = mam_db_row('SELECT * FROM referrals WHERE id = ?', [$referralId]);
    if (!$r || (string) $r['status'] === 'rewarded') return false;
    $cents = $rewardCents !== null ? max(0, $rewardCents) : (int) mam_setting_num('referral_reward_cents', 1000);
    if ($cents > 0 && function_exists('mam_wallet_apply')) {
        mam_wallet_apply((int) $r['referrer_id'], $cents, 'referral', $by, 'referral:' . $referralId, 'Referral reward');
    }
    mam_db_update('referrals', ['status' => 'rewarded', 'reward_cents' => $cents, 'rewarded_at' => date('Y-m-d H:i:s')], ['id' => $referralId]);
    // Tell the referrer. Rewarding used to be entirely silent: the ledger row
    // appeared and the referral row flipped, but nothing reached the person who
    // did the referring — so a family only found out if they happened to
    // reopen the Refer a Friend panel.
    if ($cents > 0 && function_exists('mam_notify')) {
        $who = trim((string) ($r['referred_name'] ?? '')) ?: 'someone you referred';
        mam_notify(
            (int) $r['referrer_id'],
            'referral.rewarded',
            'Referral reward: $' . number_format($cents / 100, 2),
            'Thank you for introducing ' . $who . ' to MAM. Your reward has been credited and will be applied to your tuition by the academy office.',
            '/portal/student/#payments'
        );
    }
    if (function_exists('mam_audit')) mam_audit('referral_reward', 'user', (int) $r['referrer_id'], ['referral' => $referralId, 'cents' => $cents, 'by' => $by]);
    return true;
}
