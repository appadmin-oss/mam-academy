<?php
/**
 * Learning Readiness signal (#1) — advisory routing ONLY, never a rejection.
 * Friction-free: derived purely from age + experience the wizard already
 * collects. See mam_application_readiness() in validation.php.
 */
declare(strict_types=1);
t_group('learning readiness');

// A clear fit: adult in the typical intake with some experience → ready.
$r = mam_application_readiness(['age' => 20, 'experience' => 'some']);
t_eq($r['signal'], 'ready', 'in-range + experienced = ready');

// Complete beginners are routed to a foundational start, never rejected.
$r = mam_application_readiness(['age' => 20, 'experience' => 'none']);
t_eq($r['signal'], 'foundation', 'complete beginner routes to foundation');

// Foundation takes precedence over an age review flag — still a positive route.
$r = mam_application_readiness(['age' => 40, 'experience' => 'none']);
t_eq($r['signal'], 'foundation', 'beginner wins over the age flag');

// Age outside the typical 16–26 intake is a soft review flag, not a block.
$r = mam_application_readiness(['age' => 30, 'experience' => 'some']);
t_eq($r['signal'], 'review', 'over 26 → review (individual look), not rejection');

$r = mam_application_readiness(['age' => 14, 'experience' => 'intermediate']);
t_eq($r['signal'], 'review', 'under 16 → review (junior/KTAMS track)');
t_ok(count($r['reasons']) > 0, 'review always carries at least one reason');

// Missing inputs must not throw and must never fabricate a rejection.
$r = mam_application_readiness([]);
t_ok(in_array($r['signal'], ['ready', 'foundation', 'review'], true), 'empty input yields a valid, non-rejecting signal');
t_ok(count($r['reasons']) > 0, 'a reason is always present');

// The signal set is closed — it can only ever route/flag, never reject.
foreach ([
    ['age' => 22, 'experience' => 'advanced'],
    ['age' => 15, 'experience' => 'none'],
    ['age' => 70, 'experience' => 'some'],
    ['age' => null, 'experience' => 'none'],
] as $case) {
    $s = mam_application_readiness($case)['signal'];
    t_ok(in_array($s, ['ready', 'foundation', 'review'], true), 'signal is always one of ready|foundation|review');
}
