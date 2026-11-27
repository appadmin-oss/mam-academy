<?php
/**
 * Funnel analytics (Application A3) — first-party, privacy-respecting.
 *
 * We record where applicants drop in the apply flow, with NO third-party pixel
 * and NO identity: `sid` is an anonymous funnel-session id minted client-side
 * (sessionStorage), used only to stitch one applicant's path together for
 * drop-off maths. Do-Not-Track is honoured at the edge (the /api/track route
 * drops the beacon before it reaches here). Events are allowlisted and the meta
 * blob is small and PII-free. Recording is best-effort / never-throw so a
 * beacon can never break a page.
 */
declare(strict_types=1);

/** The only events we accept — anything else is ignored at the edge.
 *  apply_* drive the application funnel; the rest are Studio engagement (S1),
 *  which reuse the same table with the tool key carried in `pathway`. */
function mam_funnel_event_names(): array {
    return [
        'apply_start', 'apply_step_view', 'apply_step_complete', 'apply_submit', 'apply_pay',
        'tool_open', 'demo_start', 'demo_complete', 'score_logged', 'gate_apply_click',
    ];
}

/**
 * Record one funnel event. Returns true if a row was written. Validates the
 * event against the allowlist, clamps every field, and keeps meta tiny + PII-
 * free (the caller is trusted not to send PII, but we cap size regardless).
 * Never throws.
 */
function mam_funnel_record(array $in): bool {
    if (!function_exists('mam_db')) return false;
    $event = strtolower(trim((string)($in['event'] ?? '')));
    if (!in_array($event, mam_funnel_event_names(), true)) return false;

    $sid = trim((string)($in['sid'] ?? ''));
    $sid = $sid !== '' ? mb_substr(preg_replace('/[^A-Za-z0-9_-]/', '', $sid), 0, 64) : null;

    $step = $in['step'] ?? null;
    $step = ($step === null || $step === '') ? null : max(0, min(99, (int)$step));

    $pathway = trim((string)($in['pathway'] ?? ''));
    $pathway = $pathway !== '' ? mb_substr(preg_replace('/[^a-z0-9 _-]/i', '', $pathway), 0, 32) : null;

    // meta: a small, no-PII associative blob (e.g. provider, mode). Cap hard.
    $meta = null;
    if (isset($in['meta']) && is_array($in['meta']) && $in['meta']) {
        $json = json_encode($in['meta'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($json) && strlen($json) <= 500) $meta = $json;
    }

    try {
        mam_db_insert('funnel_events', [
            'sid' => $sid, 'event' => $event, 'step' => $step, 'pathway' => $pathway, 'meta' => $meta,
        ]);
        return true;
    } catch (\Throwable $e) { return false; }
}

/**
 * Aggregate funnel report over the last $days. Counts DISTINCT funnel sessions
 * (sid) reaching each stage — the right denominator for drop-off — and also
 * raw event totals so nothing is invisible if a sid is ever missing.
 *
 * Returns:
 *   ['days', 'started', 'submitted', 'paid',
 *    'steps'      => [ ['step','viewed','completed','dropped'], ... ] ordered,
 *    'by_pathway' => [ ['pathway','submitted','paid'], ... ],
 *    'totals'     => [event => raw_count] ]
 */
function mam_funnel_report(int $days = 30): array {
    $days = max(1, min(365, $days));
    $since = date('Y-m-d H:i:s', time() - $days * 86400);
    $out = [
        'days' => $days, 'started' => 0, 'submitted' => 0, 'paid' => 0,
        'steps' => [], 'by_pathway' => [], 'totals' => [],
    ];
    if (!function_exists('mam_db')) return $out;

    $distinct = function (string $event, ?int $step = null) use ($since): int {
        $sql = "SELECT COUNT(DISTINCT sid) AS n FROM funnel_events WHERE event = ? AND created_at >= ?";
        $args = [$event, $since];
        if ($step !== null) { $sql .= " AND step = ?"; $args[] = $step; }
        try { return (int)(mam_db_row($sql, $args)['n'] ?? 0); } catch (\Throwable $e) { return 0; }
    };

    try {
        $out['started']   = $distinct('apply_start');
        $out['submitted'] = $distinct('apply_submit');
        $out['paid']      = $distinct('apply_pay');

        // Per-step viewed/completed for every step that appears in the window.
        $stepRows = mam_db_all(
            "SELECT step FROM funnel_events
              WHERE event IN ('apply_step_view','apply_step_complete') AND step IS NOT NULL AND created_at >= ?
              GROUP BY step ORDER BY step ASC",
            [$since]
        );
        foreach ($stepRows as $sr) {
            $st = (int)$sr['step'];
            $viewed = $distinct('apply_step_view', $st);
            $completed = $distinct('apply_step_complete', $st);
            $out['steps'][] = ['step' => $st, 'viewed' => $viewed, 'completed' => $completed, 'dropped' => max(0, $viewed - $completed)];
        }

        // Submit/pay split by pathway.
        $pathRows = mam_db_all(
            "SELECT pathway,
                    COUNT(DISTINCT CASE WHEN event='apply_submit' THEN sid END) AS submitted,
                    COUNT(DISTINCT CASE WHEN event='apply_pay'    THEN sid END) AS paid
               FROM funnel_events
              WHERE event IN ('apply_submit','apply_pay') AND pathway IS NOT NULL AND created_at >= ?
              GROUP BY pathway ORDER BY submitted DESC",
            [$since]
        );
        foreach ($pathRows as $pr) {
            $out['by_pathway'][] = ['pathway' => (string)$pr['pathway'], 'submitted' => (int)$pr['submitted'], 'paid' => (int)$pr['paid']];
        }

        // Raw per-event totals (a safety net independent of sid).
        $totRows = mam_db_all("SELECT event, COUNT(*) AS n FROM funnel_events WHERE created_at >= ? GROUP BY event", [$since]);
        foreach ($totRows as $tr) $out['totals'][(string)$tr['event']] = (int)$tr['n'];
    } catch (\Throwable $e) { /* report is best-effort; partial is fine */ }

    return $out;
}

/**
 * Studio engagement report (S1) over the last $days. Per-event totals plus a
 * per-tool breakdown (the tool key rides in `pathway`): opens, demo starts,
 * scores logged, and apply-clicks from the gate — answering "which tool drives
 * applications". Distinct-session reach per tool too.
 */
function mam_funnel_studio_report(int $days = 30): array {
    $days = max(1, min(365, $days));
    $since = date('Y-m-d H:i:s', time() - $days * 86400);
    $events = ['tool_open', 'demo_start', 'demo_complete', 'score_logged', 'gate_apply_click'];
    $out = ['days' => $days, 'totals' => [], 'by_tool' => []];
    if (!function_exists('mam_db')) return $out;
    try {
        foreach ($events as $e) $out['totals'][$e] = 0;
        $totRows = mam_db_all(
            "SELECT event, COUNT(*) AS n FROM funnel_events WHERE event IN ('tool_open','demo_start','demo_complete','score_logged','gate_apply_click') AND created_at >= ? GROUP BY event",
            [$since]
        );
        foreach ($totRows as $tr) $out['totals'][(string)$tr['event']] = (int)$tr['n'];

        // Per-tool matrix: one row per tool with each event's count + reach.
        $rows = mam_db_all(
            "SELECT pathway AS tool, event, COUNT(*) AS n, COUNT(DISTINCT sid) AS reach
               FROM funnel_events
              WHERE event IN ('tool_open','demo_start','demo_complete','score_logged','gate_apply_click')
                AND pathway IS NOT NULL AND created_at >= ?
              GROUP BY pathway, event",
            [$since]
        );
        $tools = [];
        foreach ($rows as $r) {
            $t = (string)$r['tool'];
            if (!isset($tools[$t])) { $tools[$t] = ['tool' => $t, 'opens' => 0, 'reach' => 0, 'demo_start' => 0, 'score_logged' => 0, 'gate_apply_click' => 0]; }
            if ((string)$r['event'] === 'tool_open') { $tools[$t]['opens'] = (int)$r['n']; $tools[$t]['reach'] = (int)$r['reach']; }
            elseif (isset($tools[$t][(string)$r['event']])) { $tools[$t][(string)$r['event']] = (int)$r['n']; }
        }
        usort($tools, function ($a, $b) { return $b['opens'] <=> $a['opens']; });
        $out['by_tool'] = array_values($tools);
    } catch (\Throwable $e) { /* best-effort */ }
    return $out;
}
