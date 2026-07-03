<?php
/** Learning Readiness signal — advisory routing only, NEVER a rejection (#1). */
declare(strict_types=1);
t_group('learning readiness');

// A clear fit: adult, some experience, owns an instrument, fully available.
$r = mam_application_readiness(['age' => 20, 'experience' => 'some', 'instrumentAccess' => 'own', 'weekendAvailable' => 'yes']);
t_eq($r['signal'], 'ready', 'in-range + experienced + equipped + available = ready');

// Complete beginners are routed to a foundational start, not rejected.
$r = mam_application_readiness(['age' => 20, 'experience' => 'none', 'instrumentAccess' => 'own', 'weekendAvailable' => 'yes']);
t_eq($r['signal'], 'foundation', 'complete beginner routes to foundation');

// Foundation takes precedence over review flags (beginner + no instrument).
$r = mam_application_readiness(['age' => 30, 'experience' => 'none', 'instrumentAccess' => 'none', 'weekendAvailable' => 'no']);
t_eq($r['signal'], 'foundation', 'beginner wins over review flags — still a positive route');

// Age outside 16-26 is a soft review flag, not a block.
$r = mam_application_readiness(['age' => 30, 'experience' => 'some', 'instrumentAccess' => 'own', 'weekendAvailable' => 'yes']);
t_eq($r['signal'], 'review', 'over 26 → review (individual look), not rejection');

$r = mam_application_readiness(['age' => 14, 'experience' => 'some', 'instrumentAccess' => 'own', 'weekendAvailable' => 'yes']);
t_eq($r['signal'], 'review', 'under 16 → review (guardian track)');

// No instrument yet → review, with a helpful reason.
$r = mam_application_readiness(['age' => 20, 'experience' => 'some', 'instrumentAccess' => 'none', 'weekendAvailable' => 'yes']);
t_eq($r['signal'], 'review', 'no instrument access → review');
t_ok(count($r['reasons']) > 0, 'review always carries at least one reason');

// Limited availability → review.
$r = mam_application_readiness(['age' => 20, 'experience' => 'some', 'instrumentAccess' => 'own', 'weekendAvailable' => 'limited']);
t_eq($r['signal'], 'review', 'limited availability → review');

// Missing inputs must not throw and must never fabricate a rejection.
$r = mam_application_readiness([]);
t_ok(in_array($r['signal'], ['ready', 'foundation', 'review'], true), 'empty input yields a valid, non-rejecting signal');
t_ok(count($r['reasons']) > 0, 'a reason is always present');
