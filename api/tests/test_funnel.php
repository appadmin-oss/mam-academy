<?php
/**
 * Funnel analytics (Application A3) — first-party, privacy-respecting
 * instrumentation. Records allowlisted events and aggregates distinct-session
 * drop-off per step and conversion per pathway.
 */
declare(strict_types=1);
t_group('funnel: analytics');

// ── Allowlist ──
$names = mam_funnel_event_names();
foreach (['apply_start', 'apply_step_view', 'apply_step_complete', 'apply_submit', 'apply_pay'] as $e) {
    t_ok(in_array($e, $names, true), "event allowlist contains $e");
}

// ── Record validation ──
t_ok(mam_funnel_record(['event' => 'apply_start', 'sid' => 'fs-1']) === true, 'a valid event records');
t_ok(mam_funnel_record(['event' => 'not_a_real_event', 'sid' => 'fs-1']) === false, 'an unknown event is rejected (allowlist)');
t_ok(mam_funnel_record(['event' => '', 'sid' => 'fs-1']) === false, 'an empty event is rejected');

// sid is sanitised (strip junk, cap length); step clamped; oversized meta dropped.
mam_funnel_record(['event' => 'apply_step_view', 'sid' => "fs bad/<>'\"2", 'step' => 999, 'pathway' => 'standard']);
$last = mam_db_row("SELECT sid, step FROM funnel_events ORDER BY id DESC LIMIT 1");
t_eq($last['sid'], 'fsbad2', 'sid is sanitised to safe chars');
t_eq((int)$last['step'], 99, 'step is clamped to a sane max');
$big = str_repeat('x', 800);
t_ok(mam_funnel_record(['event' => 'apply_start', 'sid' => 'fs-meta', 'meta' => ['blob' => $big]]) === true, 'an oversized meta still records (meta dropped, row kept)');
t_ok(mam_db_row("SELECT meta FROM funnel_events ORDER BY id DESC LIMIT 1")['meta'] === null, 'oversized meta is stored as null');

// ── Aggregation: a realistic 3-session funnel ──
// Clear the slate so the report maths is exact regardless of test order.
mam_db()->exec('DELETE FROM funnel_events');
$rec = function (string $event, string $sid, ?int $step = null, ?string $pathway = null) {
    mam_funnel_record(['event' => $event, 'sid' => $sid, 'step' => $step, 'pathway' => $pathway]);
};
foreach (['s1', 's2', 's3'] as $s) {
    $rec('apply_start', $s);
    $rec('apply_step_view', $s, 0); $rec('apply_step_complete', $s, 0);
    $rec('apply_step_view', $s, 1);
}
// Only s1, s2 complete step 1 (s3 drops here). s1 views/completes step 1 TWICE
// → distinct-sid must collapse it.
$rec('apply_step_complete', 's1', 1); $rec('apply_step_complete', 's1', 1);
$rec('apply_step_complete', 's2', 1);
$rec('apply_step_view', 's1', 2); $rec('apply_step_complete', 's1', 2);
$rec('apply_step_view', 's2', 2); $rec('apply_step_complete', 's2', 2);
// Submissions + one payment, split by pathway.
$rec('apply_submit', 's1', null, 'standard'); $rec('apply_pay', 's1', null, 'standard');
$rec('apply_submit', 's2', null, 'intensive');

$rep = mam_funnel_report(30);
t_eq($rep['started'], 3, 'distinct started = 3');
t_eq($rep['submitted'], 2, 'distinct submitted = 2');
t_eq($rep['paid'], 1, 'distinct paid = 1');

// Steps ordered, with drop-off (s1 double-complete collapsed by DISTINCT sid).
t_eq(count($rep['steps']), 3, 'three steps appear in the report');
$byStep = [];
foreach ($rep['steps'] as $s) $byStep[$s['step']] = $s;
t_eq($byStep[0]['viewed'], 3, 'step 0 reached by 3');
t_eq($byStep[0]['dropped'], 0, 'no drop at step 0');
t_eq($byStep[1]['viewed'], 3, 'step 1 reached by 3');
t_eq($byStep[1]['completed'], 2, 'step 1 completed by 2 (distinct — double-complete collapsed)');
t_eq($byStep[1]['dropped'], 1, 'one applicant dropped at step 1');
t_eq($byStep[2]['completed'], 2, 'step 2 completed by 2');

// Per-pathway conversion.
$byPath = [];
foreach ($rep['by_pathway'] as $p) $byPath[$p['pathway']] = $p;
t_eq($byPath['standard']['submitted'], 1, 'standard pathway: 1 submitted');
t_eq($byPath['standard']['paid'], 1, 'standard pathway: 1 paid');
t_eq($byPath['intensive']['submitted'], 1, 'intensive pathway: 1 submitted');
t_eq($byPath['intensive']['paid'], 0, 'intensive pathway: 0 paid');

// Raw totals safety net.
t_eq($rep['totals']['apply_start'], 3, 'raw totals count every apply_start row');

// ── Time window excludes old events ──
$rec('apply_start', 's-old');
mam_db_update('funnel_events', ['created_at' => date('Y-m-d H:i:s', time() - 40 * 86400)], ['sid' => 's-old']);
t_eq(mam_funnel_report(7)['started'], 3, 'a 40-day-old event is outside the 7-day window');
t_eq(mam_funnel_report(365)['started'], 4, 'the same event is inside the 1-year window');
