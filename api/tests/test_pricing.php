<?php
/** Pricing engine — server-authoritative money math (mam_compute_application_price). */
declare(strict_types=1);
t_group('pricing engine');

// Crash: flat $70, level-independent, online-exempt; + $5 registration.
$r = mam_compute_application_price(['programme' => 'crash', 'level' => '', 'mode' => 'online', 'extraCourses' => 0, 'includeRegistration' => true]);
t_eq($r['total'], 75, 'crash online = 70 flat + 5 reg (online surcharge exempt)');

// Standard at Pre-Certificate: base 120, onsite, + reg.
$r = mam_compute_application_price(['programme' => 'standard', 'level' => 'precert', 'mode' => 'onsite', 'extraCourses' => 0, 'includeRegistration' => true]);
t_eq($r['total'], 125, 'standard precert onsite = 120 + 5 reg');

// Online adds +20% to the programme fee.
$r = mam_compute_application_price(['programme' => 'standard', 'level' => 'precert', 'mode' => 'online', 'extraCourses' => 0, 'includeRegistration' => false]);
t_eq($r['subtotal'], 144, 'standard precert online = 120 * 1.20');

// Certified: level base + compulsory-courses fee (120) => 240 at precert.
$r = mam_compute_application_price(['programme' => 'certified-musician', 'level' => 'precert', 'mode' => 'onsite', 'extraCourses' => 0, 'includeRegistration' => false]);
t_eq($r['subtotal'], 240, 'certified precert = 120 base + 120 compulsory');

// Grand: 3x base, with the promotional discount; 3*120*0.87 ≈ 313.
$r = mam_compute_application_price(['programme' => 'grand-musician', 'level' => 'precert', 'mode' => 'onsite', 'extraCourses' => 0, 'includeRegistration' => false]);
t_eq($r['subtotal'], 313, 'grand precert = 3*120*(1-13%) ≈ 313');
t_ok($r['discount_amount'] > 0, 'grand surfaces a discount amount');
t_ok($r['pre_discount'] == 360, 'grand pre-discount = 3*120 = 360');

// Additional ("minor") courses add 0.5*base each.
$r = mam_compute_application_price(['programme' => 'standard', 'level' => 'precert', 'mode' => 'onsite', 'extraCourses' => 2, 'includeRegistration' => false]);
t_eq($r['subtotal'], 240, 'standard + 2 extra = 120 + 0.5*120*2');

// Higher level lifts the base (diploma = 180).
$r = mam_compute_application_price(['programme' => 'standard', 'level' => 'diploma', 'mode' => 'onsite', 'extraCourses' => 0, 'includeRegistration' => false]);
t_eq($r['subtotal'], 180, 'standard diploma = 180 base');

// Registration toggles off.
$r = mam_compute_application_price(['programme' => 'standard', 'level' => 'cert', 'mode' => 'onsite', 'extraCourses' => 0, 'includeRegistration' => false]);
t_eq($r['registration'], 0, 'registration excluded when not requested');

// Unknown programme prices to zero (never NaN/negative).
$r = mam_compute_application_price(['programme' => 'bogus', 'level' => 'precert', 'mode' => 'onsite']);
t_eq($r['total'], 0, 'unknown programme = 0');
