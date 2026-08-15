<?php
declare(strict_types=1);
t_group('consent & safeguarding (§8.4)');
$CO_stu = mam_db_insert('users', ['name' => 'CO Stu', 'email' => 'co_stu@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$CO_by  = mam_db_insert('users', ['name' => 'CO Par', 'email' => 'co_par@test.local', 'role' => 'parent', 'password_hash' => 'x', 'status' => 'active']);

// Default-deny: no record → nothing allowed.
t_ok(!mam_consent_allows($CO_stu, 'photo'), 'photo consent defaults to deny');
t_ok(!mam_consent_allows($CO_stu, 'media'), 'media consent defaults to deny');
$c0 = mam_consent_get($CO_stu);
t_ok($c0['photo'] === false && $c0['data'] === false, 'get returns all-false by default');

// Set some consent (upsert insert).
mam_consent_set($CO_stu, ['media' => true, 'photo' => false, 'data' => true, 'safeguarding_contact' => 'Aunt Bola +234...'], $CO_by);
t_ok(mam_consent_allows($CO_stu, 'media'), 'media consent granted');
t_ok(!mam_consent_allows($CO_stu, 'photo'), 'photo still denied');
t_ok(mam_consent_allows($CO_stu, 'data'), 'data consent granted');
t_eq(mam_consent_get($CO_stu)['safeguarding_contact'], 'Aunt Bola +234...', 'safeguarding contact stored');

// Update (upsert update) flips photo on, media off — no duplicate row.
mam_consent_set($CO_stu, ['media' => false, 'photo' => true, 'data' => true], $CO_by);
t_ok(mam_consent_allows($CO_stu, 'photo'), 'photo consent now granted');
t_ok(!mam_consent_allows($CO_stu, 'media'), 'media consent revoked on update');
t_eq((int) mam_db_row('SELECT COUNT(*) AS n FROM student_consents WHERE student_id = ?', [$CO_stu])['n'], 1, 'consent stays one row per student');
