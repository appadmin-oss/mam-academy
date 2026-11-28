<?php
/**
 * GET /api/parent/dashboard — the guardian Home screen (design §3.1).
 *
 * One request for the whole screen. The design's dashboard is "Needs you" plus
 * "This week" plus the next class, drawn from four subsystems, and a phone on
 * metered data should not make four round trips to draw one card.
 *
 * `needs_you` is ORDERED by what the family can act on soonest and what costs
 * them most to miss — money first because it has a deadline, then a consent with
 * a date, then a report they have not opened. The design puts money at the top of
 * this screen for the same reason.
 */
declare(strict_types=1);

// Session times leave with their +01:00 offset (session-time.php, UX quick win 9).
$mamPortalOk = fn(array $p) => mam_ok(mam_session_iso_payload($p));

$u   = mam_require_role('parent');
$pid = (int) $u['id'];
$kids = mam_parent_children($pid);

$needs = [];
$week  = [];

foreach ($kids as &$k) {
    $sid = (int) $k['student_id'];
    $k['scope'] = mam_parent_scope($pid, $sid);

    // ── Money. Every scope reaches this, including billing-only.
    if (mam_parent_scope_allows($pid, $sid, 'invoices')) {
        try {
            $st = mam_fee_statement($pid, $sid);
            $k['owed'] = $st['owed'];
            $k['owed_minor'] = $st['owed_minor'];
            $k['currency'] = $st['currency'];
            if ($st['owed_minor'] > 0) {
                foreach ($st['invoices'] as $inv) {
                    if (!in_array($inv['status'], ['issued', 'part_paid'], true)) continue;
                    $needs[] = [
                        'rank'    => $inv['overdue_days'] !== null ? 0 : 1,
                        'kind'    => 'money',
                        'icon'    => 'file-invoice',
                        'tone'    => $inv['overdue_days'] !== null ? 'bad' : 'warn',
                        'title'   => $k['student_name'] . ' — ' . ($inv['lines'][0]['label'] ?? 'fees') . ' ' . $inv['due'],
                        'detail'  => $inv['overdue_days'] !== null
                            ? 'Overdue ' . $inv['overdue_days'] . ' ' . ($inv['overdue_days'] === 1 ? 'day' : 'days') . ' · ' . $inv['ref']
                            : 'Due ' . $inv['due_on'] . ' · ' . $inv['ref'],
                        'student_id' => $sid,
                        'goto'    => 'finance',
                    ];
                }
            }
        } catch (\Throwable $e) { mam_log('warn', 'dash_fees_failed', ['student' => $sid, 'err' => $e->getMessage()]); }
    }

    // ── A ratified report the family has not been shown yet.
    if (mam_parent_scope_allows($pid, $sid, 'reports')) {
        try {
            $rep = mam_report_for_guardian($pid, $sid);
            $k['reports'] = count($rep['reports']);
            if ($rep['reports']) {
                $latest = $rep['reports'][0];
                $week[] = [
                    'kind'   => 'report',
                    'icon'   => 'file-lines',
                    'tone'   => 'accent',
                    'title'  => $k['student_name'] . "'s termly report is ready",
                    'detail' => 'Released ' . substr((string) $latest['ratified_at'], 0, 10)
                              . ($latest['instructor'] ? ' · ' . $latest['instructor'] : ''),
                    'student_id' => $sid,
                    'goto'   => 'reports',
                ];
            }
        } catch (\Throwable $e) { mam_log('warn', 'dash_reports_failed', ['student' => $sid, 'err' => $e->getMessage()]); }
    }

    // ── Consent still to decide. Full access only — it is theirs to give.
    if (mam_parent_scope_allows($pid, $sid, 'consents') && function_exists('mam_consent_get')) {
        try {
            $c = mam_consent_get($sid);
            $undecided = [];
            foreach (['media' => 'Media and newsletter', 'photo' => 'Photography', 'data' => 'Holding personal data'] as $key => $label) {
                if (!array_key_exists($key, $c) || $c[$key] === null) $undecided[] = $label;
            }
            $k['consents_pending'] = count($undecided);
            if ($undecided) {
                $needs[] = [
                    'rank' => 2, 'kind' => 'consent', 'icon' => 'file-signature', 'tone' => 'warn',
                    'title'  => $undecided[0] . ' consent',
                    'detail' => 'Optional — you can say no and it changes nothing else',
                    'student_id' => $sid, 'goto' => 'consents',
                ];
            }
        } catch (\Throwable $e) { mam_log('warn', 'dash_consent_failed', ['student' => $sid, 'err' => $e->getMessage()]); }
    }

    // ── Make-up credits, for the child cards.
    if (mam_parent_scope_allows($pid, $sid, 'timetable')) {
        try {
            $mk = mam_makeup_balance($sid);
            $k['credits_available'] = $mk['available'];
            $k['credits_allowance_left'] = $mk['allowance_left'];
        } catch (\Throwable $e) { mam_log('warn', 'dash_makeup_failed', ['student' => $sid, 'err' => $e->getMessage()]); }
    }

    // ── The next class.
    if (mam_parent_scope_allows($pid, $sid, 'timetable') && function_exists('mam_checkin_next_class')) {
        try {
            $n = mam_checkin_next_class($sid);
            if ($n && !empty($n['scheduled_at'])) {
                $k['next_class'] = ['at' => $n['scheduled_at'], 'title' => $n['title'] ?? null, 'room' => $n['room'] ?? null];
            }
        } catch (\Throwable $e) { mam_log('warn', 'dash_next_failed', ['student' => $sid, 'err' => $e->getMessage()]); }
    }
}
unset($k);

usort($needs, fn($a, $b) => $a['rank'] <=> $b['rank']);

/* The owed headline. Summed PER CURRENCY and never across them — adding naira to
 * dollars is the bug `payments.amount_usd_cents` exists to prevent, and a
 * dashboard is exactly where somebody would do it for one tidy number. */

$owed = mam_fee_owed_headline($kids);

$threads = mam_thread_inbox($pid, 10);

$mamPortalOk([
    'guardian'  => ['name' => $u['name'], 'children' => count($kids)],
    'children'  => $kids,
    'needs_you' => $needs,
    'this_week' => $week,
    'owed'      => $owed,
    'unread_messages' => count(array_filter($threads, fn($t) => $t['unread'])),
    /* The design's "What you cannot see, and why" panel, as data. A boundary a
     * family discovers by finding an empty screen reads as a bug or as
     * concealment; one stated in a sentence reads as the policy it is. */
    'boundaries' => $kids ? mam_parent_scope_explain($pid, (int) $kids[0]['student_id']) : [],
]);
