<?php
/**
 * Input validation & sanitization.
 */
declare(strict_types=1);

/**
 * Academic levels — the progression tiers a student moves through. The base
 * price is set per level; every programme price derives from it (spec §1–2).
 */
const MAM_LEVELS = [
    'precert'    => 'Pre-Certificate',
    'cert'       => 'Certificate',
    'prediploma' => 'Pre-Diploma',
    'diploma'    => 'Diploma',
];

/** Standard base price for a level (admin-editable). 0 for an unknown level. */
function mam_level_base_price(string $level): float {
    static $map = [
        'precert'    => ['price_level_precert',    120],
        'cert'       => ['price_level_cert',       130],
        'prediploma' => ['price_level_prediploma', 160],
        'diploma'    => ['price_level_diploma',    180],
    ];
    if (!isset($map[$level])) return 0.0;
    return mam_setting_num($map[$level][0], (float)$map[$level][1]);
}

/** All level base prices, keyed by level code. */
function mam_level_base_prices(): array {
    $out = [];
    foreach (array_keys(MAM_LEVELS) as $lv) $out[$lv] = mam_level_base_price($lv);
    return $out;
}

/** Grand/Certified compulsory-courses fee (USD). Admin-editable. Covers Rhythm
 *  & Pitch, Theory of Music, Performance and Meta Music. */
function mam_compulsory_fee(): float {
    return mam_setting_num('compulsory_fee_usd', 120);
}

/** Grand promotional discount (percent) on the 3×base tuition. Admin-editable. */
function mam_grand_discount_pct(): float {
    return mam_setting_num('grand_discount_pct', 13);
}

/**
 * Compute an application's price from the level-based model.
 *
 *   $args: programme, level, mode ('online'|'onsite'), extraCourses (int),
 *          includeRegistration (bool)
 *
 * Programme rules (B = level base price, C = compulsory fee $120):
 *   crash              → flat price_crash ($70), level-independent, no online adj.
 *   standard           → B + 0.5B·extra
 *   certified-musician → B + C + 0.5B·extra              (up to 5 courses)
 *   grand-musician     → (3B + 0.5B·extra)·(1 − discount) (up to 7 courses)
 *   meta-order         → flat price (not level-based)
 *
 * Online adds +online_surcharge_pct% (20) to the subtotal; it is folded
 * silently into `total` and never returned as its own field. Registration ($5)
 * is added separately at checkout, not part of the tuition formula.
 */
function mam_compute_application_price(array $args): array {
    $programme = (string)($args['programme'] ?? '');
    $level     = (string)($args['level'] ?? '');
    $mode      = (($args['mode'] ?? 'onsite') === 'online') ? 'online' : 'onsite';
    $extra     = max(0, (int)($args['extraCourses'] ?? 0));
    $reg       = !empty($args['includeRegistration']);

    $B    = mam_level_base_price($level);
    $half = $B / 2;

    $onlineApplies  = true;
    $compulsory     = 0.0;
    $preDiscount    = null;
    $discountPct    = 0.0;
    $discountAmount = 0.0;

    switch ($programme) {
        case 'crash':
            // Flat fee, level-independent — Crash has no levels (spec/review).
            $subtotal = mam_setting_num('price_crash', 70); $onlineApplies = false; break;
        case 'standard':
            // One primary course at the chosen level; no compulsory courses,
            // but additional courses (+50%·base each) are still allowed.
            $subtotal = $B + $half * $extra; break;
        case 'certified-musician':
            // Base (by level) + flat compulsory-courses fee ($120) + 50%·base
            // per additional course. Up to 5 courses.
            $compulsory = mam_compulsory_fee();
            $subtotal   = $B + $compulsory + $half * $extra;
            break;
        case 'grand-musician':
            // Flagship — all three expressions: 3× the level base + 50%·base per
            // additional course, with a promotional discount. Up to 7 courses.
            $preDiscount    = 3 * $B + $half * $extra;
            $discountPct    = mam_grand_discount_pct();
            $subtotal       = $preDiscount * (1 - $discountPct / 100);
            $discountAmount = $preDiscount - $subtotal;
            break;
        case 'meta-order':
            $subtotal = mam_setting_num('price_meta_order', 60); $onlineApplies = false; break;
        default:
            $subtotal = 0.0;
    }

    $adjusted = $subtotal;
    if ($onlineApplies && $mode === 'online') {
        $adjusted = $subtotal * (1 + mam_online_surcharge_pct() / 100);
    }
    $registration = $reg ? mam_registration_fee() : 0.0;

    // Prices are always presented as flat, whole-dollar amounts (no decimals).
    // Round each money field to the nearest dollar; the client mirrors this so
    // the displayed and charged totals match exactly.
    $r0 = static function ($n) { return round((float)$n, 0); };
    $adjusted      = $r0($adjusted);
    $registration  = $r0($registration);
    $total         = $adjusted + $registration;
    return [
        'programme'       => $programme,
        'level'           => $level,
        'currency'        => 'USD',
        'base'            => $r0($B),
        'compulsory'      => $r0($compulsory),
        'pre_discount'    => $preDiscount === null ? null : $r0($preDiscount),
        'discount_pct'    => $r0($discountPct),
        'discount_amount' => $r0($discountAmount),
        'subtotal'        => $adjusted,
        'registration'    => $registration,
        'total'           => $total,
    ];
}

/**
 * Legacy flat programme prices (USD) — still the source of truth for the
 * CURRENT application form and application.php charging, until the level-based
 * form rebuild flips charging over to mam_compute_application_price(). Kept
 * admin-editable so live prices stay controllable during the transition.
 */
function mam_prices(): array {
    return [
        'certified-musician' => mam_setting_num('price_certified_musician', 230),
        'grand-musician'     => mam_setting_num('price_grand_musician',     310),
        'crash'              => mam_setting_num('price_crash',               70),
        'meta-order'         => mam_setting_num('price_meta_order',          60),
    ];
}

/**
 * Programme "from" prices (USD) under the new level model — the lowest-level
 * (Pre-Certificate) price for each programme. Display-only ("from $X"); the
 * server charges the exact level/course total via mam_compute_application_price().
 */
function mam_programme_from_prices(): array {
    $base = mam_level_base_price('precert');
    $comp = mam_compulsory_fee();
    $disc = mam_grand_discount_pct();
    return [
        'standard'           => round($base, 0),
        'certified-musician' => round($base + $comp, 0),
        'grand-musician'     => round(3 * $base * (1 - $disc / 100), 0),
        'crash'              => round(mam_setting_num('price_crash', 70), 0),
        'meta-order'         => round(mam_setting_num('price_meta_order', 60), 0),
    ];
}

/** Online learners pay the programme fee + this percentage premium. Onsite is
 *  the standard fee. Server-authoritative so it can't be bypassed client-side.
 *  Admin-editable; legacy ONLINE_SURCHARGE_PCT env still honoured as fallback. */
function mam_online_surcharge_pct(): float {
    return mam_setting_num('online_surcharge_pct', (float)mam_env('ONLINE_SURCHARGE_PCT', '20'));
}

/** One-time registration / student-file fee added at checkout. Admin-editable. */
function mam_registration_fee(): float {
    return mam_setting_num('registration_fee_usd', 5);
}

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

    $allowedPathways = ['standard','certified-musician','grand-musician','crash','meta-order'];
    $out['pathway'] = mam_str($in, 'pathway', 32);
    if (!mam_in($out['pathway'], $allowedPathways)) $errors[] = 'pathway';

    $out['mode'] = mam_str($in, 'mode', 16);
    if (!mam_in($out['mode'], ['online','onsite'])) $errors[] = 'mode';

    // Academic level (spec §2). Required for the level-priced programmes
    // (standard/certified/grand); Crash is a flat fee with no level, and the
    // MetaMusic Order is a standalone intensive — both leave it blank.
    $levelled = in_array($out['pathway'], ['standard','certified-musician','grand-musician'], true);
    $out['level'] = mam_str($in, 'level', 16);
    if ($levelled) {
        if (!mam_in($out['level'], array_keys(MAM_LEVELS))) $errors[] = 'level';
    } else {
        $out['level'] = ''; // ignored by pricing for crash/meta-order
    }

    // Performance expression. Asked for crash/standard/certified (exactly one);
    // Grand spans all three (stored as 'all'); the MetaMusic Order has none.
    $out['expression'] = mam_str($in, 'expression', 16);
    if ($out['pathway'] === 'grand-musician') {
        $out['expression'] = 'all';
    } elseif ($out['pathway'] === 'meta-order') {
        $out['expression'] = '';
    } else {
        if (!mam_in($out['expression'], ['classical','contemporary','african'])) $errors[] = 'expression';
    }

    // Session is NEVER user-chosen — it's resolved from the academic calendar
    // server-side (see application.php / mam_session_current). Accept a client
    // hint if it happens to be a valid code, but it is authoritatively
    // overwritten before persistence, so it is never a validation error.
    $sessHint = mam_str($in, 'session', 12);
    $out['session'] = mam_in($sessHint, ['toni','medi','summer','domi']) ? $sessHint : '';

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
        'drums','theory','performance','rhythm','metamusic','multiple'
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

    // Additional ("minor") courses beyond the primary one — each adds 0.5×base
    // to Certified/Grand (spec §6). Only those two programmes carry minors; the
    // count drives pricing, the label is a human-readable list for the record.
    $minorsCarry = in_array($out['pathway'], ['standard','certified-musician','grand-musician'], true);
    $out['extraCourses'] = $minorsCarry ? max(0, min(12, (int)($in['extraCourses'] ?? 0))) : 0;
    $out['coursesLabel'] = $minorsCarry ? (mam_str($in, 'coursesLabel', 400) ?: null) : null;

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
