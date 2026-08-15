<?php
/**
 * POST /api/referral/capture {code, name?, email?}  (public, rate-limited)
 * Records a pending referral for a code — called by the sign-up flow.
 */
declare(strict_types=1);
$in   = mam_json_input();
$code = (string) ($in['code'] ?? '');
if (trim($code) === '') mam_fail('missing_code', 422, ['fields' => ['code']]);
$id = mam_referral_capture($code, isset($in['name']) ? (string) $in['name'] : null, isset($in['email']) ? (string) $in['email'] : null);
mam_ok(['captured' => $id !== null]);
