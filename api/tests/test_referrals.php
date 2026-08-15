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

t_group('referral — anonymous-capture cap (audit F5)');

// /api/referral/capture is public and CSRF-less by design (a sign-up beacon
// with no session to bind to). A capture WITH an email is de-duped by identity;
// one WITHOUT an email cannot be, so it is capped by volume instead. Without
// the cap, anyone holding a code could bury a family's referral list — and the
// admin queue — under plausible-looking pending rows.
$RF_b   = mam_db_insert('users', ['name' => 'RF Cap', 'email' => 'rf_cap@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$codeB  = mam_referral_code($RF_b);
$capNow = (int) mam_setting_num('referral_anon_daily_cap', 5);
t_ok($capNow > 0, 'a default anonymous cap is configured');

$accepted = 0;
for ($i = 0; $i < $capNow + 4; $i++) {
    if (mam_referral_capture($codeB, 'Anon ' . $i) !== null) $accepted++;
}
t_eq($accepted, $capNow, 'email-less captures stop at the daily cap');
t_eq(mam_referral_capture($codeB, 'One more'), null, 'a further email-less capture is refused');

// The cap must never block a REAL referral. Those carry an email and are
// de-duped by identity, so they are exempt.
t_ok(mam_referral_capture($codeB, 'Real Person', 'real_person@x.com') > 0, 'an emailed capture still lands once the anon cap is spent');
t_ok(mam_referral_capture($codeB, 'Another', 'another_person@x.com') > 0, 'and so does a second distinct email');

t_ok(!mam_referral_under_anon_cap($RF_b), 'the cap predicate reports the referrer as full');
t_ok(mam_referral_under_anon_cap($RF_a), 'and reports an unaffected referrer as having room');
// Yesterday's captures must not count against today.
t_ok(mam_referral_under_anon_cap($RF_b, date('Y-m-d', strtotime('+1 day'))), 'the cap is per day, not cumulative');

t_group('referral — the reward tells the referrer (audit F6)');

$RF_c  = mam_db_insert('users', ['name' => 'RF Notify', 'email' => 'rf_notify@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$codeC = mam_referral_code($RF_c);
$ridC  = mam_referral_capture($codeC, 'Nia Adeyemi', 'nia_referred@x.com');
$nBefore = (int) (mam_db_row('SELECT COUNT(*) AS n FROM notifications WHERE user_id = ?', [$RF_c])['n'] ?? 0);
mam_referral_reward($ridC, 2000, null);
$note = mam_db_row("SELECT * FROM notifications WHERE user_id = ? AND type = 'referral.rewarded' ORDER BY id DESC", [$RF_c]);
t_ok($note !== null && $note !== false, 'rewarding raises a notification (it used to be entirely silent)');
t_ok(strpos((string) $note['title'], '20.00') !== false, 'the notification states the amount');
t_ok(strpos((string) $note['body'], 'Nia Adeyemi') !== false, 'and names the person referred');
t_ok((int) (mam_db_row('SELECT COUNT(*) AS n FROM notifications WHERE user_id = ?', [$RF_c])['n'] ?? 0) > $nBefore, 'the referrer gains an unread item');

// A zero-value reward is a bookkeeping close, not good news — no notification.
$ridD = mam_referral_capture($codeC, 'Zero Reward', 'zero_reward@x.com');
mam_referral_reward($ridD, 0, null);
t_eq((int) (mam_db_row("SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND type = 'referral.rewarded'", [$RF_c])['n'] ?? 0), 1, 'a zero-cent reward raises no notification');
