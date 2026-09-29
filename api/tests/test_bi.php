<?php
/**
 * Admin business intelligence (Advanced Modernization §5.4).
 *
 * The BI functions aggregate over the WHOLE table, and the suite shares one
 * database, so every assertion here is a DELTA against a baseline captured
 * before this file's fixtures land. Absolute counts would break the moment
 * another test file inserted an application.
 */
declare(strict_types=1);

t_group('BI — admissions funnel');

$BI_base = mam_bi_funnel();

/** Insert an application (with its customer) in a given status; returns its id. */
$bi_app = function (string $status, string $pathway, string $slug) {
    $cid = mam_db_insert('customers', ['email' => 'bi_' . $slug . '@test.local', 'first_name' => 'BI', 'last_name' => ucfirst($slug)]);
    return mam_db_insert('applications', [
        'reference'   => 'BI-' . strtoupper($slug),
        'customer_id' => $cid,
        'pathway'     => $pathway,
        'status'      => $status,
    ]);
};

$BI_sub  = $bi_app('submitted', 'ktams',  'sub');
$BI_rev  = $bi_app('reviewing', 'ktams',  'rev');
$BI_acc  = $bi_app('accepted',  'adults', 'acc');
$BI_enr  = $bi_app('enrolled',  'adults', 'enr');
$BI_rej  = $bi_app('rejected',  'adults', 'rej');

$f = mam_bi_funnel();
t_eq($f['applied']  - $BI_base['applied'],  5, 'every application counts as applied');
t_eq($f['reviewed'] - $BI_base['reviewed'], 4, 'reviewed is cumulative — submitted is the only stage not past review');
t_eq($f['accepted'] - $BI_base['accepted'], 2, 'accepted is cumulative — an enrolled application still counts as accepted');
t_eq($f['enrolled'] - $BI_base['enrolled'], 1, 'enrolled counts only the enrolled');
t_eq($f['rejected'] - $BI_base['rejected'], 1, 'rejected is tracked separately from the funnel stages');
t_ok($f['accept_rate'] >= 0 && $f['accept_rate'] <= 100, 'accept rate is a percentage');

// An empty window must not divide by zero.
$BI_empty = mam_bi_funnel('2999-01-01 00:00:00');
t_eq($BI_empty['applied'], 0, 'a window with no applications is empty, not an error');
t_eq($BI_empty['accept_rate'], 0.0, 'accept rate of zero applications is 0, not a division by zero');
t_eq($BI_empty['enrol_rate'], 0.0, 'enrol rate of zero accepted is 0, not a division by zero');

t_group('BI — revenue by month');

$BI_rBase = mam_bi_revenue_by_month(12);
$bi_sum = function (array $rows) { $t = 0; foreach ($rows as $r) $t += (int) $r['cents']; return $t; };
$BI_rBaseTotal = $bi_sum($BI_rBase);

/** A succeeded payment worth $N, stamped at a given time. */
$bi_pay = function (int $appId, string $slug, int $usdCents, string $at, string $status = 'succeeded') {
    return mam_db_insert('payments', [
        'application_id'   => $appId,
        'reference'        => 'BIPAY-' . strtoupper($slug),
        'provider'         => 'paystack',
        'status'           => $status,
        'amount'           => 100.00,
        'currency'         => 'USD',
        'amount_usd_cents' => $usdCents,
        'created_at'       => $at,
    ]);
};

$BI_now = date('Y-m-d H:i:s');
$bi_pay($BI_enr, 'a', 5000,  $BI_now);
$bi_pay($BI_enr, 'b', 2500,  $BI_now);
$bi_pay($BI_acc, 'c', 10000, $BI_now);
// A pending payment must never reach the revenue line.
$bi_pay($BI_sub, 'pending', 99999, $BI_now, 'pending');

$BI_r = mam_bi_revenue_by_month(12);
t_eq(count($BI_r), 12, 'a 12-month window returns 12 buckets, including quiet months');
t_eq($bi_sum($BI_r) - $BI_rBaseTotal, 17500, 'only succeeded payments are counted ($50 + $25 + $100)');

$BI_thisMonth = null;
foreach ($BI_r as $m) if ($m['month'] === date('Y-m')) $BI_thisMonth = $m;
t_ok($BI_thisMonth !== null, 'the current month is present in the window');
t_eq($BI_r[count($BI_r) - 1]['month'], date('Y-m'), 'buckets run oldest first, ending at the current month');

// A NULL amount_usd_cents (pre-v61) contributes nothing but is counted, so the
// screen can disclose the gap instead of quietly under-reporting.
$BI_uBase = 0;
foreach ($BI_r as $m) if ($m['month'] === date('Y-m')) $BI_uBase = (int) $m['unpriced'];
mam_db_insert('payments', [
    'application_id' => $BI_enr, 'reference' => 'BIPAY-NULLUSD', 'provider' => 'paystack',
    'status' => 'succeeded', 'amount' => 100.00, 'currency' => 'NGN', 'created_at' => $BI_now,
]);
$BI_r2 = mam_bi_revenue_by_month(12);
$BI_uNow = 0;
foreach ($BI_r2 as $m) if ($m['month'] === date('Y-m')) $BI_uNow = (int) $m['unpriced'];
t_eq($BI_uNow - $BI_uBase, 1, 'a payment with no USD figure is flagged as unpriced');
t_eq($bi_sum($BI_r2) - $bi_sum($BI_r), 0, 'an unpriced payment adds nothing to revenue (no charge-currency fallback)');

t_group('BI — lifetime value by pathway');

$BI_ltv = mam_bi_ltv_by_pathway();
$bi_row = function (array $rows, string $pathway) {
    foreach ($rows as $r) if ($r['pathway'] === $pathway) return $r;
    return null;
};
$BI_adults = $bi_row($BI_ltv, 'adults');
t_ok($BI_adults !== null, 'pathways with applications appear in the LTV table');
t_ok((int) $BI_adults['applicants'] >= 3, 'the adults pathway carries this suite\'s three applications');
// $BI_enr took $50 + $25 (+ an unpriced NGN row worth 0), $BI_acc took $100,
// $BI_rej paid nothing — so two payers, $175 total, $87.50 each.
t_ok((int) $BI_adults['cents'] >= 17500, 'revenue is attributed to the applicant\'s pathway');
t_eq((int) $BI_adults['ltv_cents'], (int) round((int) $BI_adults['cents'] / (int) $BI_adults['payers']), 'LTV is revenue divided by PAYERS, not by all applicants');
t_ok((int) $BI_adults['payers'] < (int) $BI_adults['applicants'], 'a non-paying applicant is not counted as a payer');

$BI_sorted = true;
for ($i = 1; $i < count($BI_ltv); $i++) if ((int) $BI_ltv[$i]['cents'] > (int) $BI_ltv[$i - 1]['cents']) $BI_sorted = false;
t_ok($BI_sorted, 'pathways are ranked by revenue, highest first');

t_group('BI — retention');

$BI_course = mam_db_insert('courses', ['code' => 'bi_c', 'name' => 'BI', 'kind' => 'core']);
$BI_coh    = mam_db_insert('cohorts', ['course_id' => $BI_course, 'title' => 'BI Cohort', 'status' => 'active', 'capacity' => 10]);
$BI_s1 = mam_db_insert('users', ['name' => 'BI Active',  'email' => 'bi_s1@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$BI_s2 = mam_db_insert('users', ['name' => 'BI Dormant', 'email' => 'bi_s2@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
foreach ([$BI_s1, $BI_s2] as $s) mam_db_insert('course_enrollments', ['student_id' => $s, 'course_id' => $BI_course, 'cohort_id' => $BI_coh, 'status' => 'active']);

$BI_retBase = mam_bi_retention(30);
mam_db_insert('practice_logs', ['student_id' => $BI_s1, 'minutes' => 45, 'logged_on' => date('Y-m-d')]);
$BI_ret = mam_bi_retention(30);

t_eq($BI_ret['enrolled'] - $BI_retBase['enrolled'], 0, 'the enrolled population is unchanged by logging practice');
t_eq($BI_ret['active'] - $BI_retBase['active'], 1, 'a recent practice log makes a student active');
t_eq($BI_ret['dormant'] - $BI_retBase['dormant'], -1, 'that student moves out of dormant, not in addition to it');
t_eq($BI_ret['enrolled'], $BI_ret['active'] + $BI_ret['dormant'], 'active + dormant always accounts for everyone enrolled');
t_ok($BI_ret['rate'] > $BI_retBase['rate'] || $BI_retBase['rate'] === 100.0, 'the retention rate rises with activity');
t_eq($BI_ret['days'], 30, 'the window is reported back so the screen can label it');

// A stale practice log must not count as retention.
$BI_old = mam_bi_retention(30);
mam_db_insert('practice_logs', ['student_id' => $BI_s2, 'minutes' => 60, 'logged_on' => date('Y-m-d', strtotime('-400 days'))]);
t_eq(mam_bi_retention(30)['active'], $BI_old['active'], 'a practice log outside the window does not revive a dormant student');

// Housekeeping: this file's throwaway course would inflate the catalogue test's
// absolute count of 12. FKs cascade the cohort and enrolments with it.
mam_db()->prepare('DELETE FROM courses WHERE id = ?')->execute([$BI_course]);

t_group('month arithmetic clamps — the silent axis-shortening bug');

/* ⚠️ Stepping months from TODAY'S DATE rather than the 1st is the trap
   CLAUDE.md records for card expiry, and the revenue chart had it backwards:
   `strtotime('-N months')` rolls FORWARD when the day does not exist in the
   target month, so two steps land in one month and another is skipped.

   MEASURED before the fix: from 2026-09-29 a twelve-month window produced
   ELEVEN buckets (February gone — 2026-09-29 minus seven months is the
   non-existent 2026-02-29, which rolls into March); from 2026-08-31 it
   produced SEVEN. The chart never reported a gap. It drew a shorter axis, so
   a month's revenue appeared to belong to a month it did not.

   Month-END dates are the whole test — a mid-month date passes either way,
   which is why this went unnoticed until the calendar reached the 29th. */
foreach (['2026-01-31', '2026-03-31', '2026-05-31', '2026-08-31', '2026-12-31',
          '2026-09-29', '2026-09-30', '2024-02-29', '2026-07-15'] as $BI_day) {
    $BI_w = mam_bi_revenue_by_month(12, $BI_day);
    t_eq(count($BI_w), 12, "a 12-month window from $BI_day has 12 buckets");
    t_eq(count(array_unique(array_column($BI_w, 'month'))), 12,
        "…and from $BI_day they are 12 DISTINCT months, none collapsed onto another");
    // Oldest first, strictly ascending, no gaps.
    $BI_ms = array_column($BI_w, 'month');
    t_eq($BI_ms, array_values(array_filter($BI_ms)), "…with no empty month key from $BI_day");
    $BI_sorted = $BI_ms; sort($BI_sorted);
    t_eq($BI_ms, $BI_sorted, "…in ascending order from $BI_day");
    t_eq(end($BI_ms), substr($BI_day, 0, 7), "…ending on the month asked for, from $BI_day");
}

t_group('mam_add_months — one clamp, shared by the card and the clearance');

/* Both surfaces date a real entitlement: how long an ID card opens a door, and
   how long a safeguarding clearance lets an instructor teach a child. Rolling
   forward silently EXTENDS both, which is the wrong direction to be wrong in. */
$MA = [
    ['2026-08-31',  6, '2027-02-28'],   // Feb has no 31st — the vetting bug
    ['2026-12-31',  6, '2027-06-30'],
    ['2026-03-31',  6, '2026-09-30'],
    ['2026-01-31',  1, '2026-02-28'],
    ['2026-01-31',  6, '2026-07-31'],   // the day DOES exist — must not move
    ['2024-01-31',  1, '2024-02-29'],   // a leap February
    ['2024-02-29', 12, '2025-02-28'],   // leap day into a common year
    ['2026-06-15', 12, '2027-06-15'],   // mid-month is untouched
    ['2026-08-31', -6, '2026-02-28'],   // stepping BACKWARDS clamps too
    ['2026-01-15', -1, '2025-12-15'],   // …and crosses the year correctly
];
foreach ($MA as [$MA_in, $MA_n, $MA_want]) {
    t_eq(mam_add_months($MA_in, $MA_n), $MA_want, "$MA_in " . ($MA_n >= 0 ? '+' : '') . "$MA_n months");
}
t_eq(mam_add_months('not a date', 3), null, 'an unparseable date returns null rather than today');

/* The clamp is never LATER than the naive result — the property that makes it
   safe for a validity period. */
foreach (['2026-01-31', '2026-03-31', '2026-08-31', '2026-05-31'] as $MA_d) {
    $MA_naive = date('Y-m-d', strtotime($MA_d . ' +6 months'));
    t_ok(mam_add_months($MA_d, 6) <= $MA_naive,
        "$MA_d: clamping never grants MORE time than asked for (naive gave $MA_naive)");
}

/* One implementation. vetting.php carried a comment claiming it clamped while
   calling strtotime() directly; cards.php had the real clamp. They must not
   drift apart again. */
foreach (['vetting', 'cards'] as $MA_lib) {
    $MA_src = (string) @file_get_contents(MAM_API_ROOT . '/lib/' . $MA_lib . '.php');
    t_ok(str_contains($MA_src, 'mam_add_months('),
        "$MA_lib.php dates its expiry through the shared clamp");
    t_ok(!preg_match("/strtotime\(\s*\\\$\w+\s*\.\s*' \+' \. \\\$months/", $MA_src),
        "$MA_lib.php no longer adds months with a bare strtotime()");
}
