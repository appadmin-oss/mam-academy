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
