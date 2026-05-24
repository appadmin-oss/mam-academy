<?php
declare(strict_types=1);
mam_require_method('GET');
$token = mam_csrf_token();
if (!$token) mam_fail('csrf_not_configured', 503);
mam_ok(['token' => $token, 'expires_in' => 7200]);
