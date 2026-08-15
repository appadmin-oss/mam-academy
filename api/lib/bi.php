<?php
/**
 * MAM Academy — admin business intelligence (Advanced Modernization §5.4).
 * ─────────────────────────────────────────────────────────────
 * Read-only aggregates over data the system already keeps: revenue by month,
 * the admissions funnel, lifetime value by pathway, and cohort retention.
 * No migration, no new writes — every function here is a SELECT.
 *
 * Money: `payments.amount_usd_cents` is the canonical USD figure, frozen at
 * charge time (v61). We deliberately do NOT fall back to `payments.amount`,
 * which is in the CHARGE currency — summing NGN and USD rows into one total is
 * exactly the class of bug the USD column was added to end. A row with a NULL
 * amount_usd_cents (pre-v61) contributes 0 and is counted separately so the
 * screen can say so rather than quietly under-reporting.
 */
declare(strict_types=1);

/** Succeeded-payment predicate shared by every revenue query. */
function mam_bi_revenue_where(): string {
    return "status = 'succeeded'";
}

/**
 * Revenue per calendar month, oldest first.
 * @return list<array{month:string,cents:int,payments:int,unpriced:int}>
 */
function mam_bi_revenue_by_month(int $months = 12, ?string $today = null): array {
    $today = $today ?: date('Y-m-d');
    $start = date('Y-m-01', strtotime($today . ' -' . max(0, $months - 1) . ' months'));
    $rows  = mam_db_all(
        "SELECT created_at, amount_usd_cents FROM payments
          WHERE " . mam_bi_revenue_where() . " AND created_at >= ?",
        [$start . ' 00:00:00']
    );
    // Pre-seed every month in the window so a quiet month renders as a zero bar
    // rather than vanishing and making the axis lie about the time span.
    $buckets = [];
    for ($i = max(0, $months - 1); $i >= 0; $i--) {
        $buckets[date('Y-m', strtotime($today . ' -' . $i . ' months'))] = ['cents' => 0, 'payments' => 0, 'unpriced' => 0];
    }
    foreach ($rows as $r) {
        $m = substr((string) $r['created_at'], 0, 7);
        if (!isset($buckets[$m])) continue;
        $buckets[$m]['payments']++;
        if ($r['amount_usd_cents'] === null) $buckets[$m]['unpriced']++;
        else $buckets[$m]['cents'] += (int) $r['amount_usd_cents'];
    }
    $out = [];
    foreach ($buckets as $month => $b) $out[] = ['month' => $month] + $b;
    return $out;
}

/**
 * Admissions funnel: how many applications reached each stage.
 *
 * Stages are CUMULATIVE, not a status census — an 'enrolled' application also
 * counts as accepted and as reviewed, because the question the funnel answers
 * is "how far did they get", not "where are they parked right now". A plain
 * GROUP BY status would show 0 accepted the moment everyone moved on.
 */
function mam_bi_funnel(?string $since = null): array {
    $where = $since ? ' WHERE created_at >= ?' : '';
    $args  = $since ? [$since] : [];
    $rows  = mam_db_all('SELECT status FROM applications' . $where, $args);

    $reviewed = ['reviewing', 'accepted', 'enrolled', 'waitlisted', 'rejected'];
    $accepted = ['accepted', 'enrolled'];
    $out = ['applied' => 0, 'reviewed' => 0, 'accepted' => 0, 'enrolled' => 0, 'rejected' => 0, 'withdrawn' => 0];
    foreach ($rows as $r) {
        $s = (string) $r['status'];
        $out['applied']++;
        if (in_array($s, $reviewed, true)) $out['reviewed']++;
        if (in_array($s, $accepted, true)) $out['accepted']++;
        if ($s === 'enrolled')  $out['enrolled']++;
        if ($s === 'rejected')  $out['rejected']++;
        if ($s === 'withdrawn') $out['withdrawn']++;
    }
    $out['accept_rate'] = $out['applied'] > 0 ? round($out['accepted'] / $out['applied'] * 100, 1) : 0.0;
    $out['enrol_rate']  = $out['accepted'] > 0 ? round($out['enrolled'] / $out['accepted'] * 100, 1) : 0.0;
    return $out;
}

/**
 * Lifetime value by pathway: total succeeded revenue attributed to the
 * applications of each pathway, and the mean per paying applicant.
 * @return list<array{pathway:string,applicants:int,payers:int,cents:int,ltv_cents:int}>
 */
function mam_bi_ltv_by_pathway(): array {
    $rows = mam_db_all(
        "SELECT a.pathway AS pathway, a.id AS app_id,
                COALESCE(SUM(CASE WHEN p.status = 'succeeded' THEN p.amount_usd_cents ELSE 0 END), 0) AS cents
           FROM applications a
      LEFT JOIN payments p ON p.application_id = a.id
       GROUP BY a.pathway, a.id"
    );
    $agg = [];
    foreach ($rows as $r) {
        $p = (string) ($r['pathway'] ?? '');
        if ($p === '') $p = 'unspecified';
        if (!isset($agg[$p])) $agg[$p] = ['pathway' => $p, 'applicants' => 0, 'payers' => 0, 'cents' => 0];
        $agg[$p]['applicants']++;
        $c = (int) $r['cents'];
        if ($c > 0) { $agg[$p]['payers']++; $agg[$p]['cents'] += $c; }
    }
    $out = [];
    foreach ($agg as $a) {
        // LTV is per PAYER, not per applicant: dividing by everyone who ever
        // filled the form measures form traffic, not what a customer is worth.
        $a['ltv_cents'] = $a['payers'] > 0 ? (int) round($a['cents'] / $a['payers']) : 0;
        $out[] = $a;
    }
    usort($out, function ($x, $y) { return $y['cents'] <=> $x['cents'] ?: strcmp($x['pathway'], $y['pathway']); });
    return $out;
}

/**
 * Retention: of the students with an active/completed course enrolment, how
 * many have any learning activity (practice log, session attendance, or
 * submission) inside the window. "Still with us" beats "still on the roster".
 */
function mam_bi_retention(int $days = 30, ?string $today = null): array {
    $today = $today ?: date('Y-m-d');
    $since = date('Y-m-d', strtotime($today . ' -' . max(1, $days) . ' days'));

    $enrolled = mam_db_all("SELECT DISTINCT student_id FROM course_enrollments WHERE status IN ('active','completed')");
    $ids = [];
    foreach ($enrolled as $e) $ids[(int) $e['student_id']] = true;
    $total = count($ids);
    if ($total === 0) return ['enrolled' => 0, 'active' => 0, 'dormant' => 0, 'rate' => 0.0, 'days' => $days];

    $active = [];
    foreach (
        [
            ['SELECT DISTINCT student_id AS id FROM practice_logs WHERE logged_on >= ?', $since],
            ["SELECT DISTINCT sa.student_id AS id FROM session_attendance sa JOIN class_sessions cs ON cs.id = sa.session_id WHERE cs.scheduled_at >= ? AND sa.status IN ('present','late')", $since . ' 00:00:00'],
            ['SELECT DISTINCT student_id AS id FROM submissions WHERE submitted_at >= ?', $since . ' 00:00:00'],
        ] as $q
    ) {
        foreach (mam_db_all($q[0], [$q[1]]) as $r) {
            $id = (int) $r['id'];
            if (isset($ids[$id])) $active[$id] = true;
        }
    }
    $n = count($active);
    return [
        'enrolled' => $total,
        'active'   => $n,
        'dormant'  => $total - $n,
        'rate'     => round($n / $total * 100, 1),
        'days'     => $days,
    ];
}
