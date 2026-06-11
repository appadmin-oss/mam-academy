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
