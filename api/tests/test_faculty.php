<?php
/** Faculty invites — approval-gated, single-use, 7-day expiry — + form validation. */
declare(strict_types=1);
t_group('faculty invites & applications');

// ── Request: queued, nothing invited yet ──
$rid = mam_faculty_request('Prof.Keys@Example.com', 'Prof Keys', 'contact');
t_ok($rid > 0, 'teaching request queued');
$row = mam_db_row('SELECT * FROM faculty_invites WHERE id = ?', [$rid]);
t_eq($row['status'], 'requested', 'request awaits approval');
t_ok(empty($row['nonce']), 'no link exists before approval');
t_eq(mam_faculty_request('prof.keys@example.com'), $rid, 'repeat interest reuses the open request');
t_ok(count(mam_faculty_pending_requests()) >= 1, 'request appears in the admin queue');

// ── Approve: single-use nonce + 7-day expiry ──
$inv = mam_faculty_approve($rid, null);
t_eq($inv['status'], 'invited', 'approval marks invited');
t_ok(!empty($inv['nonce']), 'approval mints the nonce');
$expDays = (strtotime((string)$inv['expires_at']) - time()) / 86400;
t_ok($expDays > 6.9 && $expDays <= 7.05, 'invite expires in 7 days');

// ── Token: authentic + single-use ──
$url = mam_faculty_apply_url($inv);
parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
$tok = (string)$q['token'];
$live = mam_faculty_verify_token($tok);
t_ok(is_array($live) && $live['email'] === 'prof.keys@example.com', 'token resolves the invite');
t_eq(mam_faculty_verify_token('garbage'), 'invalid', 'garbage token invalid');
t_eq(mam_faculty_verify_token(substr($tok, 0, -4) . 'AAAA'), 'invalid', 'tampered token invalid');

// Re-approving re-issues: the OLD link stops working.
$inv2 = mam_faculty_approve($rid, null);
t_eq(mam_faculty_verify_token($tok), 'invalid', 're-approval revokes the previous link');
$url2 = mam_faculty_apply_url($inv2);
parse_str((string)parse_url($url2, PHP_URL_QUERY), $q2);
$tok2 = (string)$q2['token'];
t_ok(is_array(mam_faculty_verify_token($tok2)), 'fresh link works');

// Expiry: force the clock past it.
mam_db_update('faculty_invites', ['expires_at' => date('Y-m-d H:i:s', time() - 60)], ['id' => $rid]);
t_eq(mam_faculty_verify_token($tok2), 'expired', 'expired invite rejected');
mam_db_update('faculty_invites', ['expires_at' => date('Y-m-d H:i:s', time() + 3600)], ['id' => $rid]);

// ── Submit consumes: the link is never reusable ──
$good = mam_faculty_validate([
    'full_name' => 'Prof Keys', 'phone' => '+2348000000', 'disciplines' => 'Piano, Theory',
    'experience_years' => 12, 'bio' => str_repeat('Conservatory-trained pianist and educator. ', 3),
    'links' => 'https://x.com/profkeys', 'cv_url' => 'https://example.com/cv.pdf',
]);
t_eq($good['errors'], [], 'complete application validates');
$appId = mam_faculty_application_create('prof.keys@example.com', $good['data'], '10.0.0.1');
t_ok($appId > 0, 'application stored');
mam_faculty_consume($rid);
t_eq(mam_faculty_verify_token($tok2), 'used', 'consumed invite reports used — single-use enforced');
t_ok(mam_faculty_approve($rid, null) === null, 'a used invite cannot be re-approved');

// ── Dismiss path ──
$rid2 = mam_faculty_request('other@example.com', 'Other', 'contact');
mam_faculty_dismiss($rid2, null);
t_eq(mam_db_row('SELECT status FROM faculty_invites WHERE id = ?', [$rid2])['status'], 'dismissed', 'dismissed request closed without email');

// ── Validation edges ──
$bad = mam_faculty_validate(['full_name' => '', 'disciplines' => '', 'bio' => 'too short']);
t_ok(in_array('full_name', $bad['errors'], true) && in_array('bio', $bad['errors'], true), 'empty/short fields rejected');
$badUrl = mam_faculty_validate(['full_name' => 'A B', 'disciplines' => 'Piano', 'bio' => str_repeat('Teaching experience across levels. ', 3), 'headshot_url' => 'javascript:alert(1)']);
t_ok(in_array('headshot_url', $badUrl['errors'], true), 'non-https headshot URL rejected');

// ── A FIRST-PARTY headshot must be accepted (the photo-save bug) ──
// The uploader stores /api/media/headshot?k=… — not https — so the old
// https-only guard silently dropped every instructor's photo. It must validate.
$ownShot = '/api/media/headshot?k=202608/0123456789abcdef0123456789abcdef';
$fp = mam_faculty_validate(['full_name' => 'Prof Pic', 'disciplines' => 'Voice',
    'bio' => str_repeat('Vocal coach and choral director. ', 3), 'headshot_url' => $ownShot]);
t_eq($fp['errors'], [], 'a first-party /api/media/headshot URL validates (photo now saves)');
t_eq($fp['data']['headshot_url'], $ownShot, 'and is carried through, not dropped');

// ── Approval makes them an instructor automatically (with the photo as avatar) ──
$promEmail = 'prof.promote@example.com';
$promApp = mam_faculty_application_create($promEmail, mam_faculty_validate([
    'full_name' => 'Prof Promote', 'phone' => '+2348111111', 'disciplines' => 'Piano',
    'bio' => str_repeat('Experienced studio teacher. ', 3), 'headshot_url' => $ownShot,
])['data'], '10.0.0.2');
t_ok(mam_db_row('SELECT id FROM users WHERE LOWER(email) = ?', [$promEmail]) === null, 'no account exists before approval');
$prom = mam_faculty_promote_to_instructor($promApp, null);
t_ok($prom !== null && $prom['created'] === true && $prom['invite'] === true, 'approval creates an invited account');
$iu = mam_db_row('SELECT role, status, avatar_url FROM users WHERE LOWER(email) = ?', [$promEmail]);
t_eq($iu['role'], 'instructor', 'the new account is an instructor');
t_eq($iu['status'], 'active', 'and active, so they can sign in and be assigned a cohort');
t_eq($iu['avatar_url'], $ownShot, 'the application headshot became their portal avatar');
// Idempotent: a second approval must not duplicate or re-invite.
$prom2 = mam_faculty_promote_to_instructor($promApp, null);
t_ok($prom2['created'] === false && $prom2['invite'] === false, 're-approving is idempotent — no second account, no re-invite');
t_eq((int)mam_db_row('SELECT COUNT(*) n FROM users WHERE LOWER(email) = ?', [$promEmail])['n'], 1, 'exactly one instructor account');
// An admin account is never silently downgraded to instructor by an approval.
mam_db_insert('users', ['email' => 'boss@example.com', 'password_hash' => 'x', 'role' => 'admin', 'name' => 'Boss', 'status' => 'active']);
$adminApp = mam_faculty_application_create('boss@example.com', mam_faculty_validate([
    'full_name' => 'Boss', 'disciplines' => 'Piano', 'bio' => str_repeat('Teaches too. ', 4),
])['data'], '10.0.0.3');
mam_faculty_promote_to_instructor($adminApp, null);
t_eq(mam_db_row('SELECT role FROM users WHERE LOWER(email) = ?', ['boss@example.com'])['role'], 'admin',
    'an admin is never downgraded to instructor by an approval');
