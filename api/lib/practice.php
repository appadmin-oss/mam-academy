<?php
/**
 * Practice-log helpers — server-side stats for the student portal so streaks
 * and totals persist across devices (they used to live only in localStorage).
 */
declare(strict_types=1);

/**
 * Aggregate a student's practice logs into the same shape the dashboard UI
 * expects: a recent list plus { streak, weekMin, total, sessions }.
 * The streak counts consecutive days up to today (a session logged yesterday
 * keeps the streak alive until end of today).
 */
function mam_practice_stats(int $studentId): array {
    $rows = mam_db_all(
        'SELECT id, minutes, focus, notes, logged_on, created_at
           FROM practice_logs WHERE student_id = ? ORDER BY logged_on DESC, id DESC LIMIT 60',
        [$studentId]
    );
    $total = 0; $weekMin = 0; $days = [];
    $weekAgo = date('Y-m-d', strtotime('-6 days'));
    foreach ($rows as $r) {
        $m = (int)$r['minutes']; $total += $m;
        $d = (string)$r['logged_on'];
        if ($d !== '' && $d >= $weekAgo) $weekMin += $m;
        if ($d !== '') $days[$d] = true;
    }
    $streak = 0;
    $cur = date('Y-m-d');
    if (!isset($days[$cur])) $cur = date('Y-m-d', strtotime('-1 day')); // grace for "today not yet logged"
    while (isset($days[$cur])) { $streak++; $cur = date('Y-m-d', strtotime($cur . ' -1 day')); }
    return [
        'practice'      => $rows,
        'practiceStats' => ['streak' => $streak, 'weekMin' => $weekMin, 'total' => $total, 'sessions' => count($rows)],
    ];
}
