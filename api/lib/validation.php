<?php
/**
 * Input validation & sanitization.
 */
declare(strict_types=1);

function mam_str(array $src, string $key, int $max = 255): string {
    $v = $src[$key] ?? '';
    if (!is_string($v)) $v = '';
    $v = trim($v);
    if (strlen($v) > $max) $v = substr($v, 0, $max);
    return $v;
}

function mam_bool(array $src, string $key): int {
    $v = $src[$key] ?? false;
    if (is_string($v)) $v = in_array(strtolower($v), ['1','true','yes','on'], true);
    return $v ? 1 : 0;
}

function mam_email(string $s): ?string {
    $s = trim(strtolower($s));
    return filter_var($s, FILTER_VALIDATE_EMAIL) ? $s : null;
}

function mam_phone(string $s): ?string {
    $s = preg_replace('/[^\d+\-() ]/', '', trim($s));
    return strlen($s) >= 7 ? $s : null;
}

function mam_in(string $v, array $allowed): bool {
    return in_array($v, $allowed, true);
}

/**
 * Validate application payload. Returns sanitized array or fails.
 */
function mam_validate_application(array $in): array {
    $out = [];
    $errors = [];

    $out['firstName'] = mam_str($in, 'firstName', 60);
    $out['lastName']  = mam_str($in, 'lastName',  60);
    if ($out['firstName'] === '') $errors[] = 'firstName';
    if ($out['lastName']  === '') $errors[] = 'lastName';

    $out['email'] = mam_email(mam_str($in, 'email', 120));
    if (!$out['email']) $errors[] = 'email';

    $out['phone'] = mam_phone(mam_str($in, 'phone', 24));
    if (!$out['phone']) $errors[] = 'phone';

    $allowedPathways = ['pre-cert','cert','pre-dip','diploma','crash','meta-order'];
    $out['pathway'] = mam_str($in, 'pathway', 32);
    if (!mam_in($out['pathway'], $allowedPathways)) $errors[] = 'pathway';

    $out['mode'] = mam_str($in, 'mode', 16);
    if (!mam_in($out['mode'], ['online','onsite'])) $errors[] = 'mode';

    $allowedDisc = ['piano','violin','guitar','drums','voice','wind','theory','performance','rhythm','metamusic','multiple'];
    $out['discipline'] = mam_str($in, 'discipline', 32);
    if (!mam_in($out['discipline'], $allowedDisc)) $errors[] = 'discipline';

    $allowedAges = ['4-7','8-12','13-17','18-25','26+'];
    $out['age'] = mam_str($in, 'age', 8);
    if (!mam_in($out['age'], $allowedAges)) $errors[] = 'age';

    $out['country']  = mam_str($in, 'country',  80) ?: null;
    $out['city']     = mam_str($in, 'city',     80) ?: null;
    $out['dob']      = mam_str($in, 'dob',      20) ?: null;
    $out['experience']= mam_str($in, 'experience', 32) ?: null;
    $out['goals']    = mam_str($in, 'goals',    1000) ?: null;
    $out['referrer'] = mam_str($in, 'referrer', 40) ?: null;
    $out['parentName']  = mam_str($in, 'parentName',  120) ?: null;
    $out['parentPhone'] = mam_str($in, 'parentPhone', 24)  ?: null;
    $out['emergencyContact'] = mam_str($in, 'emergencyContact', 200) ?: null;

    $out['auditer']               = mam_bool($in, 'auditer');
    $out['includeRegistration']   = mam_bool($in, 'includeRegistration');
    $out['includeCertification']  = mam_bool($in, 'includeCertification');

    if (!empty($errors)) mam_fail('validation', 422, ['fields' => $errors]);

    return $out;
}

/**
 * Honeypot trap: any value here = bot.
 */
function mam_honeypot(array $src, string $key = '_honeypot'): void {
    if (!empty($src[$key])) {
        mam_log('warn', 'honeypot_triggered', ['ip' => mam_client_ip(), 'value' => $src[$key]]);
        // Return a fake-success to throw bots off
        mam_ok(['accepted' => true, 'reference' => 'NA-' . bin2hex(random_bytes(3))]);
    }
}
