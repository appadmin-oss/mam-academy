<?php
/** CSRF tokens — gate every state-changing form / payment POST. */
declare(strict_types=1);
t_group('csrf tokens');

$tok = mam_csrf_token();
t_ok($tok !== '' && substr_count($tok, '.') === 2, 'token minted in ts.nonce.sig form');
t_ok(mam_csrf_verify($tok) === true, 'fresh token verifies');
t_ok(mam_csrf_verify('garbage') === false, 'malformed token rejected');
t_ok(mam_csrf_verify('') === false, 'empty token rejected');
t_ok(mam_csrf_verify($tok, -1) === false, 'expired token rejected (maxAge in the past)');

// A tampered signature must fail.
$parts = explode('.', $tok);
$forged = $parts[0] . '.' . $parts[1] . '.' . str_repeat('0', strlen($parts[2]));
t_ok(mam_csrf_verify($forged) === false, 'tampered signature rejected');

// ── Per-session binding (S2): a token is bound to the minting session, so one
//    user's token can't validate another user's state-changing request. ──
$validSid = str_repeat('a', 40);  // a 40-hex cookie → mam_session_current_hash() returns a real binding
$otherSid = str_repeat('b', 40);

$_COOKIE[MAM_COOKIE] = $validSid;
$boundTok = mam_csrf_token();
t_ok(mam_csrf_verify($boundTok) === true, 'session-bound token verifies for the same session');

$_COOKIE[MAM_COOKIE] = $otherSid;                 // a different (e.g. victim's) session
t_ok(mam_csrf_verify($boundTok) === false, 'token does NOT verify under a different session (binding enforced)');

unset($_COOKIE[MAM_COOKIE]);                       // anonymous context
t_ok(mam_csrf_verify($boundTok) === false, 'session-bound token does not verify when anonymous');

$anonTok = mam_csrf_token();
t_ok(mam_csrf_verify($anonTok) === true, 'anonymous token verifies anonymously (public forms still work)');
$_COOKIE[MAM_COOKIE] = $validSid;
t_ok(mam_csrf_verify($anonTok) === false, 'anonymous token does not carry into a logged-in session');
unset($_COOKIE[MAM_COOKIE]);

// ── mam_ip_in_cidr (S1/F2 shared helper): IPv4 + IPv6 membership. ──
t_group('ip cidr membership');
t_ok(mam_ip_in_cidr('10.1.2.3', '10.0.0.0/8') === true,        'IPv4 inside /8');
t_ok(mam_ip_in_cidr('11.0.0.1', '10.0.0.0/8') === false,       'IPv4 outside /8');
t_ok(mam_ip_in_cidr('162.158.9.9', '162.158.0.0/15') === true, 'IPv4 inside Cloudflare /15');
t_ok(mam_ip_in_cidr('100.64.0.1', '100.64.0.0/10') === true,   'CGNAT inside /10');
t_ok(mam_ip_in_cidr('::1', '::1/128') === true,                'IPv6 loopback exact');
t_ok(mam_ip_in_cidr('2606:4700::1', '2606:4700::/32') === true,'IPv6 inside /32');
t_ok(mam_ip_in_cidr('2400:cb00::1', '2606:4700::/32') === false,'IPv6 outside /32');
t_ok(mam_ip_in_cidr('1.2.3.4', 'garbage') === false,           'malformed cidr rejected safely');
