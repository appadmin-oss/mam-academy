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

    // Time-limited promo (server-enforced): a live PUBLIC promo that applies to
    // tuition takes a % off the tuition subtotal, before the fixed registration
    // fee (registration is never discounted). function_exists guards the call
    // since promo.php loads after this lib; a 0% result leaves pricing untouched.
    $promoPct    = function_exists('mam_promo_discount_percent') ? mam_promo_discount_percent('tuition') : 0;
    $promoAmount = 0.0;
    if ($promoPct > 0 && $adjusted > 0) {
        $promoAmount = $adjusted * $promoPct / 100;
        $adjusted   -= $promoAmount;
    }

    $registration = $reg ? mam_registration_fee() : 0.0;

    // Prices are always presented as flat, whole-dollar amounts (no decimals).
    // Round each money field to the nearest dollar; the client mirrors this so
    // the displayed and charged totals match exactly.
    $r0 = static function ($n) { return round((float)$n, 0); };
    $adjusted      = $r0($adjusted);
    $promoAmount   = $r0($promoAmount);
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
        'promo_pct'       => (int)$promoPct,
        'promo_amount'    => $promoAmount,
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
/**
 * Public "from" prices for each programme.
 *
 * Certified & Grand are level-based: their advertised minimum is derived from
 * the SAME model that charges them (mam_compute_application_price) using the
 * cheapest level base, so the displayed "from $X" always equals the lowest
 * amount actually charged and can never drift. (Crash & MetaMusic are flat.)
 * The legacy flat settings price_certified_musician/price_grand_musician are
 * retained in admin for reference but no longer drive display or charge.
 */
function mam_prices(): array {
    $bases   = mam_level_base_prices();
    $minBase = $bases ? (float)min($bases) : 0.0;
    return [
        // 120 base + 120 compulsory = 240 at Pre-Certificate.
        'certified-musician' => round($minBase + mam_compulsory_fee(), 0),
        // 3×base − 13% ≈ 313 at Pre-Certificate.
        'grand-musician'     => round(3 * $minBase * (1 - mam_grand_discount_pct() / 100), 0),
        'crash'              => mam_setting_num('price_crash',      70),
        'meta-order'         => mam_setting_num('price_meta_order', 60),
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

/** Read a password/secret WITHOUT trimming — leading/trailing spaces are
 *  significant in a credential. Caps length only as a DoS guard. */
function mam_secret(array $src, string $key, int $max = 200): string {
    $v = $src[$key] ?? '';
    if (!is_string($v)) return '';
    return strlen($v) > $max ? substr($v, 0, $max) : $v;
}

/** Is $ip inside $cidr? Handles both IPv4 and IPv6 (bitwise on inet_pton).
 *  Shared by the trusted-proxy resolver (bootstrap) and the SSRF guard (lms). */
function mam_ip_in_cidr(string $ip, string $cidr): bool {
    if (strpos($cidr, '/') === false) return $ip === $cidr;
    [$subnet, $bits] = explode('/', $cidr, 2);
    $bits = (int)$bits;
    $ipB  = @inet_pton($ip);
    $subB = @inet_pton($subnet);
    if ($ipB === false || $subB === false || strlen($ipB) !== strlen($subB)) return false;
    $bytes = intdiv($bits, 8);
    $rem   = $bits % 8;
    if ($bytes > 0 && strncmp($ipB, $subB, $bytes) !== 0) return false;
    if ($rem === 0) return true;
    $mask = chr((0xff << (8 - $rem)) & 0xff);
    return (ord($ipB[$bytes]) & ord($mask)) === (ord($subB[$bytes]) & ord($mask));
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

    $out['sex'] = mam_str($in, 'sex', 20);
    if (!mam_in($out['sex'], ['female','male','prefer-not-to-say'])) $errors[] = 'sex';

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
        // Our OWN stored photo (/api/media/headshot?k=…) is first-party and
        // same-origin — it must be accepted, or every upload that did not go to
        // Cloudinary is silently dropped here and the registration arrives with no
        // photograph. That is the failure this whole path exists to prevent.
        //
        // It must NOT return early, though: this check sits halfway through the
        // validator, and an early `return $out` here once skipped everything
        // below it — date of birth, programme track, guardian, policy signature,
        // the $5 registration flag and extra_courses. Every applicant who uploaded
        // a photo got either a silently gutted record or, on a production table
        // whose extra_courses is NOT NULL, "Submission failed: save_failed".
        $valid = filter_var($hs, FILTER_VALIDATE_URL) && stripos($hs, 'https://') === 0;
        $host = $valid ? parse_url($hs, PHP_URL_HOST) : '';
        $allowedHosts = ['res.cloudinary.com'];
        if (function_exists('mam_upload_is_own_url') && mam_upload_is_own_url($hs)) {
            $out['headshotUrl'] = $hs;
        } elseif (!$valid || !in_array($host, $allowedHosts, true)) {
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

    // Learning-Readiness signal (#1) — server-derived from data we ALREADY have
    // (age + experience); no new form fields. Advisory only: it routes/flags for
    // admissions and never blocks a submission. Client-supplied values are never
    // trusted — it is computed here from the sanitized inputs.
    $readiness = mam_application_readiness([
        'age'        => (isset($age) && $age !== null && $age >= 3 && $age <= 120) ? $age : null,
        'experience' => $out['experience'],
    ]);
    $out['readinessSignal']  = $readiness['signal'];
    $out['readinessReasons'] = $readiness['reasons'];
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

    // KTAMS (under-16): the policy signature must match the guardian's name
    if ($out['programTrack'] === 'KTAMS' && !empty($out['parentName']) && ($out['policySignedName'] ?? '') !== '') {
        if (mb_strtolower(trim($out['policySignedName'])) !== mb_strtolower(trim($out['parentName']))) {
            mam_fail('validation', 422, ['fields' => ['policySignedName']]);
        }
    }

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
 * Derive a Learning-Readiness SIGNAL for an application (portal brief #1,
 * reframed per flow-guide §6 as "readiness", not "eligibility").
 *
 * Deliberately friction-free: it is derived ENTIRELY from data the wizard
 * already collects — the applicant's age (from DOB) and stated experience — so
 * it adds NO new questions to the form. It is advisory ONLY: MAM places every
 * applicant via the Auditer, so this routes/flags for admissions; it never
 * rejects, never blocks submission, and never gates anything.
 *
 *   ready      — proceed normally.
 *   foundation — a complete beginner; route to a foundational start (Crash /
 *                Foundation), the Auditer confirming placement.
 *   review     — a soft flag for admissions to look individually (age outside
 *                the typical 16–26 intake). Still welcomed, never rejected.
 *
 * @param array{age?:?int,experience?:?string} $args
 * @return array{signal:string,reasons:string[]}
 */
function mam_application_readiness(array $args): array {
    $age        = $args['age'] ?? null;
    $experience = (string)($args['experience'] ?? '');

    $signal  = 'ready';
    $reasons = [];

    // A complete beginner is welcomed, not turned away — routed to a foundational
    // start; the Auditer fine-tunes placement either way. This takes precedence.
    if ($experience === 'none') {
        $signal    = 'foundation';
        $reasons[] = 'New to music — a foundational start is recommended';
    }

    // Soft review flags. None reject; each just asks admissions to look closer.
    $review = [];
    if ($age !== null && $age < 16) $review[] = 'Under 16 — junior (KTAMS) track with guardian involvement';
    if ($age !== null && $age > 26) $review[] = 'Over 26 — adult intake, reviewed individually';

    if ($signal === 'ready' && $review) $signal = 'review';
    foreach ($review as $r) $reasons[] = $r;
    if (!$reasons) $reasons[] = 'Meets our readiness guidance';

    return ['signal' => $signal, 'reasons' => $reasons];
}

/**
 * Validate and sanitise a summer programme application payload.
 * Age is checked against admin-configured summer_min_age / summer_max_age.
 * Returns the clean validated array; calls mam_fail() on any violation.
 */
function mam_validate_summer_application(array $src): array {
    // Always-required fields (independent of age)
    $always = [
        'firstName'       => 'First name',
        'lastName'        => 'Last name',
        'email'           => 'Email',
        'phone'           => 'Phone number',
        'dob'             => 'Date of birth',
        'policySignedName'=> 'Signature',
    ];
    $errors = [];
    foreach ($always as $field => $label) {
        if (empty($src[$field]) || trim((string)$src[$field]) === '') {
            $errors[] = $field; // field name — client maps to inline error element
        }
    }
    if (empty($src['policyAgreed'])) $errors[] = 'policyAgreed';
    if ($errors) mam_fail('validation_error', 422, ['errors' => $errors]);

    $email = filter_var(trim((string)$src['email']), FILTER_VALIDATE_EMAIL);
    if (!$email) mam_fail('invalid_email', 422);

    $dob = trim((string)$src['dob']);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) mam_fail('invalid_dob', 422);
    $age = 0;
    try {
        $dobDt = new DateTimeImmutable($dob);
        $age   = (int)$dobDt->diff(new DateTimeImmutable())->y;
    } catch (Throwable $e) {
        mam_fail('invalid_dob', 422);
    }

    $minAge = (int)mam_setting_num('summer_min_age', 8);
    $maxAge = (int)mam_setting_num('summer_max_age', 18);
    if ($minAge > 0 && $age < $minAge) mam_fail('age_too_young', 422, ['min_age' => $minAge, 'your_age' => $age]);
    if ($maxAge > 0 && $age > $maxAge) mam_fail('age_too_old',   422, ['max_age' => $maxAge, 'your_age' => $age]);

    // Guardian contact is required for minors (under 18) only
    $isMinor = $age < 18;
    if ($isMinor) {
        $guardErrors = [];
        if (empty($src['guardianName'])  || trim((string)$src['guardianName'])  === '') $guardErrors[] = 'guardianName';
        if (empty($src['guardianPhone']) || trim((string)$src['guardianPhone']) === '') $guardErrors[] = 'guardianPhone';
        if ($guardErrors) mam_fail('validation_error', 422, ['errors' => $guardErrors]);
        // Signature must match the guardian's name (case-insensitive, trimmed)
        $sigNorm   = mb_strtolower(trim((string)($src['policySignedName'] ?? '')));
        $guardNorm = mb_strtolower(trim((string)$src['guardianName']));
        if ($sigNorm !== $guardNorm) {
            mam_fail('validation_error', 422, ['errors' => ['policySignedName']]);
        }
    }

    // Validate headshot URL if provided (must be an https Cloudinary URL or empty)
    $rawHs = trim((string)($src['headshotUrl'] ?? ''));
    $headshotUrl = '';
    if ($rawHs !== '') {
        // Same-origin first-party storage, or an https URL. Without the first
        // clause a locally-stored headshot is dropped and the summer applicant's
        // ID card prints faceless.
        if (function_exists('mam_upload_is_own_url') && mam_upload_is_own_url($rawHs)) {
            $headshotUrl = substr($rawHs, 0, 500);
        } else {
            $hsUrl = filter_var($rawHs, FILTER_VALIDATE_URL);
            if ($hsUrl && preg_match('#^https://#i', $hsUrl)) {
                $headshotUrl = substr($hsUrl, 0, 500);
            }
        }
    }

    $validSex = ['male', 'female', 'prefer-not-to-say'];
    return [
        'firstName'       => trim(substr((string)$src['firstName'],        0, 80)),
        'lastName'        => trim(substr((string)$src['lastName'],         0, 80)),
        'email'           => $email,
        'phone'           => trim(substr((string)($src['phone'] ?? ''),    0, 30)),
        'dob'             => $dob,
        'ageAtSubmission' => $age,
        'sex'             => in_array($src['sex'] ?? '', $validSex, true) ? (string)$src['sex'] : null,
        'country'         => trim(substr((string)($src['country'] ?? ''),  0, 60)),
        'city'            => trim(substr((string)($src['city']    ?? ''),  0, 80)),
        'guardianName'    => $isMinor ? trim(substr((string)$src['guardianName'],  0, 160)) : trim(substr((string)($src['guardianName'] ?? ''),  0, 160)),
        'guardianPhone'   => $isMinor ? trim(substr((string)$src['guardianPhone'], 0, 30))  : trim(substr((string)($src['guardianPhone'] ?? ''), 0, 30)),
        'guardianEmail'   => filter_var(trim((string)($src['guardianEmail'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '',
        'discipline'      => trim(substr((string)($src['discipline']  ?? ''), 0, 100)),
        'experience'      => trim(substr((string)($src['experience']  ?? ''), 0, 500)),
        'goals'           => trim(substr((string)($src['goals']       ?? ''), 0, 1000)),
        'referrer'        => trim(substr((string)($src['referrer']    ?? ''), 0, 200)),
        'policyAgreed'    => 1,
        'policySignedName'=> trim(substr((string)$src['policySignedName'], 0, 160)),
        'headshotUrl'     => $headshotUrl,
    ];
}

/**
 * Honeypot trap: any value here = bot.
 */
function mam_honeypot(array $src, string $key = '_honeypot'): void {
    if (!empty($src[$key])) {
        // Log the length only — never the raw attacker-controlled value (log
        // injection / PII if a real user trips it).
        mam_log('warn', 'honeypot_triggered', ['ip' => mam_client_ip(), 'value_len' => is_string($src[$key]) ? strlen($src[$key]) : -1]);
        // Return a fake-success to throw bots off
        mam_ok(['accepted' => true, 'reference' => 'NA-' . bin2hex(random_bytes(3))]);
    }
}

/**
 * Add whole months to a date, CLAMPING to the last day of the target month.
 *
 * ── WHY THIS EXISTS, AND WHY IT LIVES HERE ────────────────────────────────
 * `strtotime('+N months')` does not do what a person means by "six months
 * from now". When the day does not exist in the target month it rolls FORWARD
 * into the next one, so 31 August plus six months is 3 March rather than the
 * end of February. MEASURED against the real settings:
 *
 *   · a vetting clearance issued 2026-08-31 expired 2027-03-03, three days
 *     later than the academy granted — on the safeguarding gate, which is the
 *     highest-consequence control in this repo;
 *   · 2026-03-31 + 6 gave 1 October instead of 30 September;
 *   · the BI revenue chart, stepping BACKWARDS the same way, silently drew
 *     seven months where twelve were asked for.
 *
 * `cards.php` had already worked this out and clamped correctly for ID-card
 * expiry, while `vetting.php` carried a comment SAYING it clamped and a line
 * that did not. One implementation, in the file the harness and production
 * both load (gotcha #1), is what stops those two drifting apart again.
 *
 * Clamping is the conservative direction for a validity period: the last day
 * of the target month is never later than the caller intended, where rolling
 * forward silently extends a clearance.
 */
function mam_add_months(string $date, int $months): ?string {
    $ts = strtotime($date);
    if ($ts === false) return null;
    $y = (int) date('Y', $ts); $m = (int) date('n', $ts); $d = (int) date('j', $ts);
    $tm  = $m + $months;
    $ty  = $y + (int) floor(($tm - 1) / 12);
    $tmo = (($tm - 1) % 12 + 12) % 12 + 1;          // correct for a NEGATIVE $months too
    $last = (int) date('t', mktime(0, 0, 0, $tmo, 1, $ty));
    return date('Y-m-d', mktime(0, 0, 0, $tmo, min($d, $last), $ty));
}
