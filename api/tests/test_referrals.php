<?php
declare(strict_types=1);
t_group('referral program (§6.3)');
$RF_a = mam_db_insert('users', ['name' => 'RF Ref', 'email' => 'rf_ref@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$code = mam_referral_code($RF_a);
t_ok(strpos($code, 'MAM-') === 0, 'code has the MAM- prefix');
t_eq(mam_referral_code($RF_a), $code, 'code is stable per user');
t_eq(mam_referral_by_code($code), $RF_a, 'code resolves to the referrer');
t_eq(mam_referral_by_code('NOPE-NONE'), null, 'unknown code resolves to null');

$rid = mam_referral_capture($code, 'A Friend', 'friend@x.com');
t_ok($rid > 0, 'capture records a pending referral');
t_eq(mam_referral_capture($code, 'A Friend', 'friend@x.com'), null, 'a duplicate un-rewarded email is de-duped');
t_eq(mam_referral_capture('BAD-CODE', 'x', 'y@x.com'), null, 'capture on an unknown code is null');
t_eq(mam_referral_capture($code, 'Self', 'self@x.com', $RF_a), null, 'a user cannot refer themselves');

$list = mam_referral_list_for($RF_a);
t_ok(count($list) >= 1 && $list[0]['status'] === 'pending', 'the referrer sees their pending referral');

$before = mam_wallet_balance($RF_a);
t_ok(mam_referral_reward($rid, 1500, null), 'reward succeeds');
t_eq(mam_wallet_balance($RF_a) - $before, 1500, 'the referrer wallet is credited by the reward');
t_eq(mam_db_row('SELECT status FROM referrals WHERE id = ?', [$rid])['status'], 'rewarded', 'referral is marked rewarded');
t_ok(!mam_referral_reward($rid, 1500, null), 'reward is idempotent (a second reward is a no-op)');
