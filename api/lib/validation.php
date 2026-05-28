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

    $allowedPrograms = [
        'music-business','songwriting','audio-engineering',
        'classical','contemporary','african','grand',
        'music-education','music-direction','artistic-direction',
        'music-production','orchestration','arranging','conducting','film-scoring'
    ];
    $out['program'] = mam_str($in, 'program', 40);
    if (!mam_in($out['program'], $allowedPrograms)) $errors[] = 'program';

    // Courses (kept under "discipline" for back-compat with the existing schema).
    // Updated to match the academy's published course catalogue: every Google
    // Form option plus the legacy MAM-specific tracks (theory, performance,
    // rhythm, metamusic).
    $allowedDisc = [
        'piano','voice','guitar','bass','violin','saxophone','trumpet','flute','clarinet',
        'drums','robotics','theory','performance','rhythm','metamusic','multiple'
    ];
    $out['discipline'] = mam_str($in, 'discipline', 32);
    if (!mam_in($out['discipline'], $allowedDisc)) $errors[] = 'discipline';

    $out['sex'] = mam_str($in, 'sex', 12);
    if (!mam_in($out['sex'], ['female','male'])) $errors[] = 'sex';

    $out['address'] = mam_str($in, 'address', 200);
    if ($out['address'] === '' || strlen($out['address']) < 3) $errors[] = 'address';

    $out['lga']   = mam_str($in, 'lga',   80);
    $out['state'] = mam_str($in, 'state', 80);
    if ($out['lga']   === '') $errors[] = 'lga';
    if ($out['state'] === '') $errors[] = 'state';

    // Headshot URL is optional. If supplied, must be an https URL from a known
    // host so we don't store user-controlled redirects in the DB.
    $hs = mam_str($in, 'headshotUrl', 500);
    if ($hs !== '') {
        $valid = filter_var($hs, FILTER_VALIDATE_URL) && stripos($hs, 'https://') === 0;
        $host = $valid ? parse_url($hs, PHP_URL_HOST) : '';
        $allowedHosts = ['res.cloudinary.com'];
        if (!$valid || !in_array($host, $allowedHosts, true)) {
            $errors[] = 'headshotUrl';
            $out['headshotUrl'] = null;
        } else {
            $out['headshotUrl'] = $hs;
        }
    } else {
        $out['headshotUrl'] = null;
    }

    // Date of birth is now the single source of truth — age range, programme
    // track and guardian-requirement all derive from it server-side.
    $out['dob'] = mam_str($in, 'dob', 20);
    $age = mam_age_from_dob($out['dob']);
    if ($age === null || $age < 3 || $age > 120) {
        $errors[] = 'dob';
        $out['ageRange'] = null;
        $out['programTrack'] = 'MAM';
    } else {
        $out['ageRange']     = mam_age_band($age);
        $out['programTrack'] = $age < 16 ? 'KTAMS' : 'MAM';
    }

    $out['country']  = mam_str($in, 'country',  80) ?: null;
    $out['city']     = mam_str($in, 'city',     80) ?: null;
    $out['experience']= mam_str($in, 'experience', 32) ?: null;
    $out['goals']    = mam_str($in, 'goals',    1000) ?: null;
    $out['referrer'] = mam_str($in, 'referrer', 40) ?: null;
    $out['parentName']  = mam_str($in, 'parentName',  120) ?: null;
    $out['parentPhone'] = mam_str($in, 'parentPhone', 24)  ?: null;

    $out['auditer']               = mam_bool($in, 'auditer');
    $out['includeRegistration']   = mam_bool($in, 'includeRegistration');
    $out['includeCertification']  = mam_bool($in, 'includeCertification');

    // Under-16 (KTAMS) students must have a parent/guardian on record.
    if ($out['programTrack'] === 'KTAMS' && ($out['parentName'] === null || $out['parentName'] === '')) {
        $errors[] = 'parentName';
    }

    // Class policy agreement (online -> home policy, onsite -> onsite policy).
    // policyMode mirrors the chosen learning mode; the signed name is the
    // applicant's electronic signature on the policy.
    $out['policyMode']       = mam_in($out['mode'], ['online','onsite']) ? $out['mode'] : null;
    $out['policyAgreed']     = mam_bool($in, 'policyAgreed');
    $out['policySignedName'] = mam_str($in, 'policySignedName', 120) ?: null;
    if (!$out['policyAgreed'])                 $errors[] = 'policyAgreed';
    if (($out['policySignedName'] ?? '') === '') $errors[] = 'policySignedName';

    if (!empty($errors)) mam_fail('validation', 422, ['fields' => $errors]);

    return $out;
}

/**
 * Compute integer age in completed years from an ISO date string.
 * Returns null if the value is missing or unparseable.
 */
function mam_age_from_dob(?string $dob): ?int {
    if (!$dob) return null;
    $ts = strtotime($dob);
    if ($ts === false) return null;
    $now = new DateTimeImmutable('today');
    $d   = new DateTimeImmutable(date('Y-m-d', $ts));
    if ($d > $now) return null; // future-dated DOB
    return (int)$now->diff($d)->y;
}

/**
 * Bucket a completed-years age into the seven canonical bands. Kept in sync
 * with the bands the apply UI used to ask for explicitly.
 */
function mam_age_band(int $age): string {
    if ($age < 6)  return 'under-6';
    if ($age <= 9) return '6-9';
    if ($age <= 12) return '10-12';
    if ($age <= 15) return '13-15';
    if ($age <= 17) return '16-17';
    if ($age <= 25) return '18-25';
    return '26+';
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
