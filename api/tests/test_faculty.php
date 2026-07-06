<?php
/** Instructor (faculty) applications — invite tokens + form validation + storage. */
declare(strict_types=1);
t_group('faculty applications');

// ── Invite token round-trip ──
$tok = mam_faculty_invite_token('Prof.Keys@Example.com');
t_eq(mam_faculty_verify_token($tok), 'prof.keys@example.com', 'token round-trips (email lowercased)');
t_ok(mam_faculty_verify_token(null) === null, 'null token rejected');
t_ok(mam_faculty_verify_token('garbage') === null, 'garbage token rejected');
// Tampering breaks the HMAC.
$bad = substr($tok, 0, -4) . 'AAAA';
t_ok(mam_faculty_verify_token($bad) === null, 'tampered token rejected');
// Expired token rejected.
$expired = mam_faculty_b64('x@y.dev|' . (time() - 60) . '|' . hash_hmac('sha256', 'x@y.dev|' . (time() - 60), mam_app_key()));
t_ok(mam_faculty_verify_token($expired) === null, 'expired token rejected');

// ── Validation ──
$bad = mam_faculty_validate(['full_name' => '', 'disciplines' => '', 'bio' => 'too short']);
t_ok(in_array('full_name', $bad['errors'], true) && in_array('disciplines', $bad['errors'], true) && in_array('bio', $bad['errors'], true), 'empty/short fields rejected');
$badUrl = mam_faculty_validate(['full_name' => 'A B', 'disciplines' => 'Piano', 'bio' => str_repeat('Teaching experience across levels. ', 3), 'headshot_url' => 'javascript:alert(1)']);
t_ok(in_array('headshot_url', $badUrl['errors'], true), 'non-https headshot URL rejected');
$good = mam_faculty_validate([
    'full_name' => 'Prof Keys', 'phone' => '+2348000000', 'disciplines' => 'Piano, Theory',
    'experience_years' => 12, 'bio' => str_repeat('Conservatory-trained pianist and educator. ', 3),
    'links' => 'https://x.com/profkeys', 'cv_url' => 'https://example.com/cv.pdf',
    'headshot_url' => 'https://res.cloudinary.com/demo/image/upload/keys.jpg',
]);
t_eq($good['errors'], [], 'complete application validates');

// ── Storage: create, then a resubmit updates the same open application ──
$id1 = mam_faculty_application_create('prof.keys@example.com', $good['data'], '10.0.0.1');
t_ok($id1 > 0, 'application stored');
$row = mam_db_row('SELECT * FROM instructor_applications WHERE id = ?', [$id1]);
t_eq($row['status'], 'submitted', 'stored as submitted');
t_eq($row['disciplines'], 'Piano, Theory', 'disciplines stored');
$good2 = $good['data']; $good2['disciplines'] = 'Piano, Theory, Voice';
$id2 = mam_faculty_application_create('PROF.KEYS@example.com', $good2, '10.0.0.1');
t_eq($id2, $id1, 'resubmit updates the open application (no duplicate)');
t_eq(mam_db_row('SELECT disciplines FROM instructor_applications WHERE id = ?', [$id1])['disciplines'], 'Piano, Theory, Voice', 'resubmit data applied');
// A decided application → a fresh submission creates a new row.
mam_db_update('instructor_applications', ['status' => 'declined'], ['id' => $id1]);
$id3 = mam_faculty_application_create('prof.keys@example.com', $good['data'], null);
t_ok($id3 !== $id1, 'after a decision, a new application is a new row');
