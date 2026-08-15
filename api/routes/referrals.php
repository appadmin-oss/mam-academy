<?php
/**
 * GET /api/referrals — the current user's referral code, shareable link and
 * their referrals with statuses (Advanced Modernization §6.3).
 */
declare(strict_types=1);
$u    = mam_require_user();
$uid  = (int) $u['id'];
$code = mam_referral_code($uid);
$base = rtrim((string) mam_env('APP_URL', ''), '/');
mam_ok([
    'code'      => $code,
    'link'      => $base . '/apply?ref=' . rawurlencode($code),
    'referrals' => mam_referral_list_for($uid),
]);
