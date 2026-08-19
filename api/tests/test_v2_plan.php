<?php
/**
 * ETL plan + emit — the whole export mapped, and the file somebody else loads.
 *
 * The properties asserted here are the ones a migration is judged on afterwards,
 * when nobody can go back to the source system:
 *
 *   • **identity is decided by v1's own statement**, not by a coincidence of
 *     addresses — and a shared address under two different names does NOT fuse
 *     two people, because that is the one mistake with no way back;
 *   • **nothing is invented** — a cohort with no derivable subject is rejected
 *     with a reason rather than filed under a guess;
 *   • **nothing is silently dropped** — every table in the export appears in the
 *     coverage ledger, so "we migrated everything" is checkable;
 *   • **the output is a value** — two runs over one export are byte-identical, so
 *     a diff between transforms is a real change;
 *   • **the file actually loads** — into the real schema, with constraint
 *     checking ON, and then reconciles against the source by an independent path.
 *
 * Fixtures mirror the real v59 export's shapes — decimal-string money,
 * `pending_bank_transfer` dominant, an erased applicant who keeps their
 * applications, a private cohort with no course, a student in both enrolment
 * models, a guardian's address used to apply for a child — with invented content.
 * Live people must NOT use `.invalid` addresses: that TLD is the erasure
 * tombstone marker and a fixture under it reads as erased.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/v2/identity.php';
require_once dirname(__DIR__) . '/lib/v2/transform.php';
require_once dirname(__DIR__) . '/lib/v2/plan.php';
require_once dirname(__DIR__) . '/lib/v2/emit.php';
require_once dirname(__DIR__) . '/lib/v2/verify.php';

/**
 * A fixture export.
 *
 * customer 1 / user 1  — linked by users.customer_id, and the NAMES DISAGREE.
 *                        v1 said they are one person, so they merge anyway.
 * customer 2 / user 2  — one address, names disagree, no link: a guardian who
 *                        applied for a child. Must NOT merge.
 * customer 3 / user 3  — one address, names agree, no link: merges.
 * customers 4 and 5    — one address, one name, two rows: merge, and BOTH
 *                        customer ids must still resolve for their applications.
 * customer 6           — erased. Keeps the application, loses the payload.
 * customer 7           — no address at all.
 */
function v2p_source(): array {
    return [
        'customers' => [
            ['id' => 1, 'email' => 'ada@example.test', 'first_name' => 'Ada', 'last_name' => 'Okoye',
             'phone' => '+2348000000001', 'dob' => '2011-04-02', 'created_at' => '2026-01-04 09:12:00'],
            ['id' => 2, 'email' => 'guardian@example.test', 'first_name' => 'Zuri', 'last_name' => 'Bello',
             'phone' => null, 'dob' => '2014-07-01', 'created_at' => '2026-01-05 09:00:00'],
            ['id' => 3, 'email' => 'chidi@example.test', 'first_name' => 'Chidi', 'last_name' => 'Nwosu',
             'phone' => null, 'dob' => null, 'created_at' => '2026-01-06 09:00:00'],
            ['id' => 4, 'email' => 'twice@example.test', 'first_name' => 'Bola', 'last_name' => 'Adeyemi',
             'phone' => null, 'dob' => null, 'created_at' => '2026-01-07 09:00:00'],
            ['id' => 5, 'email' => 'twice@example.test', 'first_name' => 'Adeyemi', 'last_name' => 'Bola',
             'phone' => '+2348000000005', 'dob' => null, 'created_at' => '2026-01-07 10:00:00'],
            ['id' => 6, 'email' => 'erased-c6@deleted.invalid', 'first_name' => '', 'last_name' => '',
             'phone' => null, 'dob' => null, 'created_at' => '2026-01-08 09:00:00'],
            ['id' => 7, 'email' => '', 'first_name' => 'Walkin', 'last_name' => 'Enquiry',
             'phone' => null, 'dob' => null, 'created_at' => '2026-01-09 09:00:00'],
        ],
        'users' => [
            ['id' => 1, 'email' => 'ada@example.test', 'name' => 'A. Okoye-Balogun', 'role' => 'student',
             'status' => 'active', 'customer_id' => 1, 'must_change_password' => '1',
             'password_hash' => '$2y$10$abcdefghijklmnopqrstuv', 'enrollment_ref' => 'MAM-0001',
             'created_at' => '2026-01-04 10:00:00'],
            ['id' => 2, 'email' => 'guardian@example.test', 'name' => 'Ifeoma Bello', 'role' => 'instructor',
             'status' => 'active', 'customer_id' => null, 'must_change_password' => '0',
             'password_hash' => '$2y$10$zyxwvutsrqponmlkjihgfe', 'created_at' => '2026-01-02 08:00:00'],
            ['id' => 3, 'email' => 'chidi@example.test', 'name' => 'Chidi Nwosu', 'role' => 'student',
             'status' => 'active', 'customer_id' => null, 'must_change_password' => '0',
             'password_hash' => '$2y$10$aaaaaaaaaaaaaaaaaaaaaa', 'created_at' => '2026-01-06 10:00:00'],
            ['id' => 4, 'email' => 'boss@example.test', 'name' => 'Head Teacher', 'role' => 'admin',
             'status' => 'active', 'customer_id' => null, 'admin_tier' => 'super',
             'password_hash' => '$2y$10$bbbbbbbbbbbbbbbbbbbbbb', 'created_at' => '2026-01-01 08:00:00'],
        ],
        'user_roles' => [['user_id' => 1, 'role' => 'student'], ['user_id' => 2, 'role' => 'instructor'],
                         ['user_id' => 3, 'role' => 'student'], ['user_id' => 4, 'role' => 'admin']],
        'settings' => [
            ['skey' => 'registration_fee_usd', 'svalue' => '5', 'updated_at' => '2026-01-01 00:00:00'],
            ['skey' => 'drive_shared_secret', 'svalue' => 'super-secret-value', 'updated_at' => '2026-01-01 00:00:00'],
            ['skey' => 'fx_rates_cached', 'svalue' => '{"NGN":1380.7}', 'updated_at' => '2026-01-01 00:00:00'],
            ['skey' => 'invoice_bank_name', 'svalue' => 'A Bank', 'updated_at' => '2026-01-01 00:00:00'],
        ],
        'programs' => [
            ['id' => 3, 'code' => 'standard', 'name' => 'Standard Program', 'weeks' => 12,
             'revision_week' => 11, 'exam_week' => 12, 'requires_core' => 0,
             'certification' => 'completion', 'sort_order' => 2, 'active' => 1],
            // The summer intake resolves against this one by CODE. Its v1 id is
            // deliberately not 1 — the real catalogue has crash at 1 and ktams at
            // 22, and an id-based mapping would look correct here and be wrong
            // against production.
            ['id' => 9, 'code' => 'crash', 'name' => 'Crash Program', 'weeks' => 6,
             'revision_week' => null, 'exam_week' => 6, 'requires_core' => 0,
             'certification' => 'participation', 'sort_order' => 1, 'active' => 1],
        ],
        'levels' => [
            ['id' => 1, 'code' => 'precert', 'name' => 'Pre-Certification', 'trinity_grades' => 'Grade 1-2', 'sort_order' => 1],
        ],
        'courses' => [
            ['id' => 5, 'code' => 'piano', 'name' => 'Piano', 'kind' => 'elective', 'description' => null, 'sort_order' => 10, 'active' => 1],
            ['id' => 1, 'code' => 'theory', 'name' => 'Theory of Music', 'kind' => 'core', 'description' => null, 'sort_order' => 1, 'active' => 1],
        ],
        'program_courses' => [
            ['program_id' => 3, 'course_id' => 5, 'level_code' => 'precert', 'sort_order' => 10],
            ['program_id' => 3, 'course_id' => 1, 'level_code' => null, 'sort_order' => 1],
        ],
        'modules' => [
            ['id' => 1, 'course_id' => 5, 'level_id' => 1, 'week' => 1, 'title' => 'Week one', 'kind' => 'module', 'content' => 'Scales.'],
            ['id' => 2, 'course_id' => 5, 'level_id' => 1, 'week' => 12, 'title' => 'Exam', 'kind' => 'exam', 'content' => 'Perform.'],
        ],
        'assessment_types' => [['id' => 1, 'code' => 'practical', 'name' => 'Practical', 'weight' => 30, 'sort_order' => 1, 'active' => 1]],
        'instructor_profiles' => [['user_id' => 2, 'bio' => 'Teaches piano.', 'specialties' => 'Piano',
                                   'photo_url' => null, 'years_experience' => 9, 'updated_at' => '2026-01-02 08:00:00']],
        'cohorts' => [
            // Catalogue-backed.
            ['id' => 8, 'course_id' => 5, 'level_id' => 1, 'program_id' => 3, 'instructor_id' => 2,
             'title' => 'Piano — Pre-Cert', 'discipline' => null, 'pathway' => null, 'mode' => null,
             'term' => 'Toni', 'capacity' => 12, 'meeting_pattern' => null, 'schedule_days' => null,
             'schedule_time' => null, 'session_duration' => 60, 'starts_on' => '2026-02-01',
             'ends_on' => null, 'status' => 'active', 'legacy_class_id' => null, 'created_at' => '2026-01-10 09:00:00'],
            // Private lesson, no course: the subject must be DERIVED from the
            // free-text discipline, and raised for review.
            ['id' => 1, 'course_id' => null, 'level_id' => null, 'program_id' => null, 'instructor_id' => 2,
             'title' => 'PRIVATE PIANO', 'discipline' => 'Piano', 'pathway' => 'auditer', 'mode' => 'onsite',
             'term' => null, 'capacity' => 1, 'meeting_pattern' => 'Fri 16:00-18:00', 'schedule_days' => null,
             'schedule_time' => null, 'session_duration' => 60, 'starts_on' => '2026-01-15',
             'ends_on' => null, 'status' => 'active', 'legacy_class_id' => 1, 'created_at' => '2026-01-11 09:00:00'],
            // Nothing to derive from: must be REJECTED, not guessed.
            ['id' => 2, 'course_id' => null, 'level_id' => null, 'program_id' => null, 'instructor_id' => 2,
             'title' => 'MYSTERY CLASS', 'discipline' => 'Didgeridoo', 'pathway' => null, 'mode' => 'online',
             'term' => null, 'capacity' => 1, 'meeting_pattern' => 'Sun 17:00-18:00', 'schedule_days' => null,
             'schedule_time' => null, 'session_duration' => 60, 'starts_on' => null,
             'ends_on' => null, 'status' => 'active', 'legacy_class_id' => 2, 'created_at' => '2026-01-12 09:00:00'],
        ],
        'classes' => [
            ['id' => 1, 'title' => 'PRIVATE PIANO', 'discipline' => 'Piano', 'pathway' => 'auditer',
             'instructor_id' => 2, 'mode' => 'onsite', 'schedule' => 'Fri 16:00-18:00', 'capacity' => 1,
             'status' => 'active', 'starts_on' => '2026-01-15', 'created_at' => '2026-01-11 09:00:00',
             'meeting_link' => 'https://meet.example.test/abc'],
            ['id' => 2, 'title' => 'MYSTERY CLASS', 'discipline' => 'Didgeridoo', 'pathway' => null,
             'instructor_id' => 2, 'mode' => 'online', 'schedule' => 'Sun 17:00-18:00', 'capacity' => 1,
             'status' => 'active', 'starts_on' => null, 'created_at' => '2026-01-12 09:00:00', 'meeting_link' => null],
        ],
        // The same student, in both enrolment models, on the same cohort: ONE row out.
        'course_enrollments' => [
            ['id' => 1, 'student_id' => 1, 'course_id' => 5, 'level_id' => 1, 'program_id' => 3,
             'instructor_id' => 2, 'requested_instructor_id' => null, 'status' => 'active',
             'approved_by' => 4, 'approved_at' => '2026-01-13 09:00:00',
             'status_changed_at' => '2026-01-13 09:00:00', 'created_at' => '2026-01-12 09:00:00', 'cohort_id' => 1],
            ['id' => 2, 'student_id' => 3, 'course_id' => 5, 'level_id' => 1, 'program_id' => 3,
             'instructor_id' => 2, 'requested_instructor_id' => null, 'status' => 'dropped',
             'approved_by' => null, 'approved_at' => null,
             'status_changed_at' => '2026-01-20 09:00:00', 'created_at' => '2026-01-14 09:00:00', 'cohort_id' => 8],
        ],
        'enrollments' => [
            ['id' => 1, 'student_id' => 1, 'class_id' => 1, 'application_id' => null,
             'status' => 'active', 'enrolled_at' => '2026-01-13 10:00:00'],
        ],
        'instructor_assignments' => [['id' => 1, 'instructor_id' => 2, 'course_id' => 5, 'level_id' => 1, 'created_at' => '2026-01-10 09:00:00']],
        'applications' => [
            ['id' => 10, 'reference' => 'APP-0010', 'customer_id' => 1, 'status' => 'enrolled',
             'pathway' => 'standard', 'level' => 'precert', 'mode' => 'onsite', 'program' => 'songwriting',
             'discipline' => 'piano', 'session' => 'Toni 2026', 'total_usd' => '185.00',
             'parent_name' => 'Ada Senior', 'created_at' => '2026-01-04 09:20:00',
             'source' => 'https://academy.example.test/apply?pathway=standard'],
            ['id' => 11, 'reference' => 'APP-0011', 'customer_id' => 2, 'status' => 'submitted',
             'pathway' => 'crash', 'level' => null, 'mode' => 'online', 'program' => 'music-production',
             'discipline' => 'voice', 'session' => null, 'total_usd' => '75.00',
             'created_at' => '2026-01-05 09:20:00', 'source' => 'referral'],
            // The erased applicant keeps the application; the payload must not survive.
            ['id' => 12, 'reference' => 'APP-0012', 'customer_id' => 6, 'status' => 'submitted',
             'pathway' => 'standard', 'level' => null, 'mode' => 'onsite', 'program' => 'songwriting',
             'discipline' => 'guitar', 'goals' => 'A sentence that identifies him.',
             'parent_name' => 'A name that identifies him.', 'total_usd' => '185.00',
             'created_at' => '2026-01-08 09:20:00', 'source' => 'website'],
            ['id' => 13, 'reference' => 'APP-0013', 'customer_id' => 5, 'status' => 'submitted',
             'pathway' => 'standard', 'level' => null, 'mode' => 'onsite', 'program' => 'songwriting',
             'discipline' => 'piano', 'total_usd' => '185.00', 'created_at' => '2026-01-07 11:00:00', 'source' => 'website'],
        ],
        'application_events' => [
            ['id' => 1, 'application_id' => 10, 'from_status' => 'submitted', 'to_status' => 'enrolled',
             'actor_id' => 4, 'reason' => 'Paid in full', 'created_at' => '2026-01-13 09:05:00'],
        ],
        'enrolment_events' => [
            ['id' => 1, 'enrollment_id' => 2, 'from_status' => 'active', 'to_status' => 'dropped',
             'actor_id' => 4, 'reason' => 'Family moved', 'created_at' => '2026-01-20 09:00:00'],
        ],
        'payments' => [
            ['id' => 100, 'application_id' => 10, 'reference' => 'PAY-100', 'provider' => 'paystack',
             'status' => 'success', 'amount' => '75000.00', 'currency' => 'NGN', 'purpose' => 'tuition',
             'provider_reference' => 'ps_100', 'verified_at' => '2026-01-04 09:30:00', 'created_at' => '2026-01-04 09:25:00'],
            // The dominant real shape: a bank transfer nobody has confirmed, with
            // NO provider reference — the human one is all there is.
            ['id' => 101, 'application_id' => 11, 'reference' => 'PAY-101', 'provider' => 'bank',
             'status' => 'pending_bank_transfer', 'amount' => '52500.07', 'currency' => 'NGN',
             'purpose' => 'booking_fee', 'provider_reference' => null, 'verified_at' => null,
             'created_at' => '2026-01-05 09:25:00'],
            // A non-base currency with no observed rate: rejected, not converted.
            ['id' => 102, 'application_id' => 13, 'reference' => 'PAY-102', 'provider' => 'paystack',
             'status' => 'success', 'amount' => '120.00', 'currency' => 'USD', 'purpose' => 'tuition',
             'provider_reference' => 'ps_102', 'verified_at' => '2026-01-07 12:00:00', 'created_at' => '2026-01-07 11:30:00'],
        ],
        'summer_applications' => [
            // The guardian is user 2, already in the data: a REAL guardianship.
            ['id' => 1, 'reference' => 'SUM-0001', 'first_name' => 'Tayo', 'last_name' => 'Bello',
             'email' => 'tayo@example.test', 'phone' => null, 'dob' => '2015-03-03',
             'age_at_submission' => 11, 'sex' => 'male', 'country' => 'Nigeria', 'city' => 'Lagos',
             'guardian_name' => 'Ifeoma Bello', 'guardian_phone' => null, 'guardian_email' => 'guardian@example.test',
             'discipline' => 'Piano', 'experience' => 'None', 'goals' => 'Learn', 'referrer' => 'friend',
             'policy_agreed' => 1, 'policy_signed_name' => 'Ifeoma Bello', 'policy_signed_at' => '2026-06-01 09:00:00',
             'session_year' => '2026', 'fee_snapshot_usd' => '30.00', 'status' => 'submitted', 'notes' => null,
             'source' => 'https://academy.example.test/summer', 'ip' => '198.51.100.9', 'user_agent' => 'x',
             'created_at' => '2026-06-01 09:00:00', 'updated_at' => '2026-06-01 09:00:00', 'headshot_url' => null],
        ],
        'audit_log' => [
            ['id' => 1, 'actor' => 'public', 'action' => 'create', 'entity' => 'application', 'entity_id' => 10,
             'meta' => '{"reference":"APP-0010"}', 'ip' => '198.51.100.7', 'created_at' => '2026-01-04 09:20:00'],
            ['id' => 2, 'actor' => 'admin', 'action' => 'update', 'entity' => 'application', 'entity_id' => 10,
             'meta' => null, 'ip' => 'not-an-address', 'created_at' => '2026-01-13 09:05:00'],
        ],
        'mail_log' => [
            ['id' => 1, 'to_email' => 'ada@example.test', 'subject' => 'Welcome', 'context' => 'welcome',
             'is_html' => 1, 'status' => 'failed', 'transport' => 'smtp', 'error' => 'tls_handshake',
             'attempts' => 1, 'body' => 'A rendered email nobody needs to keep.', 'opts' => null,
             'created_at' => '2026-01-04 09:40:00', 'updated_at' => '2026-01-04 09:40:00', 'sent_at' => null],
        ],
        // Deliberately not carried, and must still be accounted for.
        'rate_limits' => [['k' => 'x', 'hits' => 3]],
        'newsletter'  => [['id' => 1, 'email' => 'x@example.test', 'source' => 'footer', 'status' => 'active', 'created_at' => '2026-01-01 00:00:00']],
    ];
}

/** Index a table's planned rows by id, for readable assertions. */
function v2p_by_id(array $plan, string $table): array {
    $out = [];
    foreach ((array) ($plan['tables'][$table] ?? []) as $r) $out[(int) $r['id']] = $r;
    return $out;
}

/** Find one planned row by a column value. */
function v2p_find(array $plan, string $table, string $col, $value): ?array {
    foreach ((array) ($plan['tables'][$table] ?? []) as $r) if (($r[$col] ?? null) === $value) return $r;
    return null;
}

/** The coverage ledger, keyed by v1 table. */
function v2p_coverage(array $plan): array {
    $out = [];
    foreach ($plan['coverage'] as $c) $out[$c['table']] = $c;
    return $out;
}

t_group('v2 plan — identity is decided by v1\'s own statement');

$src  = v2p_source();
$plan = mam_v2_plan($src);
t_ok(!empty($plan['ok']), 'the fixture export plans without a fatal disagreement');

$ada = v2p_find($plan, 'users', 'email', 'ada@example.test');
t_ok($ada !== null, 'customer 1 and user 1 merged into one person despite disagreeing names');
t_eq(count(array_filter($plan['tables']['users'], static fn($u) => $u['email'] === 'ada@example.test')), 1,
    'the explicit users.customer_id link merges even when the names disagree — v1 said they are one person');

// The one that must never regress: a guardian's address used to apply for a
// child is not one person, and merging them is unrecoverable.
$guardianLogin = v2p_find($plan, 'users', 'email', 'guardian@example.test');
t_ok($guardianLogin !== null && $guardianLogin['full_name'] === 'Ifeoma Bello',
    'the LOGIN keeps the shared address');
$child = v2p_find($plan, 'users', 'full_name', 'Zuri Bello');
t_ok($child !== null, 'the applicant on the same address survives as a separate person');
t_ok($child !== null && str_ends_with((string) $child['email'], '@migrated.invalid'),
    'the split applicant gets an unroutable address, because users.email is UNIQUE');
$split = array_values(array_filter($plan['review'], static fn($r) => $r['kind'] === 'shared_email_names_disagree'));
t_eq(count($split), 1, 'the split is RAISED for review, not decided silently');
t_ok(isset($split[0]['shared_email']) && $split[0]['shared_email'] === 'guardian@example.test',
    'the review item carries the real address, so it can be acted on without the export');

t_eq(count(array_filter($plan['tables']['users'], static fn($u) => $u['full_name'] === 'Chidi Nwosu')), 1,
    'a shared address with AGREEING names merges');

$twice = v2p_find($plan, 'users', 'email', 'twice@example.test');
t_ok($twice !== null, 'two customer rows on one address with one name become one person');
$app13 = v2p_find($plan, 'applications', 'reference', 'APP-0013');
t_ok($app13 !== null && $app13['membership_id'] !== null,
    'the SECOND of the two merged customer rows still resolves — its application is not orphaned');

t_group('v2 plan — erasure survives the migration');

$tomb = v2p_find($plan, 'users', 'status', 'closed');
t_ok($tomb !== null, 'the erased person is carried as a tombstone so the counts reconcile');
t_ok($tomb !== null && str_ends_with((string) $tomb['email'], '.invalid') && $tomb['full_name'] === 'Erased user',
    'a tombstone carries no recoverable identity');
$credUserIds = array_column($plan['tables']['user_credentials'], 'user_id');
t_ok($tomb !== null && !in_array($tomb['id'], $credUserIds, true),
    'a tombstone that can still log in is not a tombstone');
$app12 = v2p_find($plan, 'applications', 'reference', 'APP-0012');
t_ok($app12 !== null, 'the erased applicant keeps their application — it is the academy\'s own record');
t_ok($app12 !== null && ($app12['payload'] === null || $app12['payload'] === []),
    'the free-text answers that identify them do NOT survive');

t_group('v2 plan — credentials');

$adaCred = null;
foreach ($plan['tables']['user_credentials'] as $c) if ($c['user_id'] === $ada['id']) $adaCred = $c;
t_ok($adaCred !== null && $adaCred['password_hash'] === '$2y$10$abcdefghijklmnopqrstuv',
    'bcrypt hashes are carried — a forced reset for every family on cutover day is not a security win');
t_eq($adaCred['must_change'], 1, "v1's own must_change_password flag is honoured, not overridden either way");

t_group('v2 plan — nothing is invented');

$rejectedCohorts = array_values(array_filter($plan['rejected'], static fn($r) => $r['table'] === 'cohorts'));
t_eq(count($rejectedCohorts), 1, 'a cohort whose subject cannot be resolved is rejected, not filed under a guess');
t_ok(str_contains((string) $rejectedCohorts[0]['why'], 'Didgeridoo'),
    'the rejection names the value it could not resolve');
$derived = array_values(array_filter($plan['review'], static fn($r) => $r['kind'] === 'cohort_course_derived'));
t_eq(count($derived), 1, 'a subject derived from free text is raised for review');

$rejectedPay = array_values(array_filter($plan['rejected'], static fn($r) => $r['table'] === 'payments'));
t_eq(count($rejectedPay), 1, 'a payment in a currency with no observed rate is rejected, not converted at 1:1');
t_eq(count($plan['tables']['payments']), 2, 'only the payments that could be represented exactly are planned');
// 75000.00 + 52500.07 — exact, by string arithmetic. `52500.07 * 100` is
// 5250006.999999999 in binary floating point.
t_eq($plan['money']['total_minor'], 7500000 + 5250007, 'money is exact to the kobo');
t_eq($plan['money']['succeeded_minor'], 7500000, 'only settled money counts as received');
foreach ($plan['tables']['payments'] as $p) {
    t_ok($p['fx_rate'] === null, 'a same-currency payment carries no invented rate');
    t_eq($p['base_amount_minor'], $p['amount_minor'], 'base equals amount when there was no conversion');
}
$pay101 = v2p_find($plan, 'payments', 'provider', 'bank');
t_ok($pay101 !== null && ($pay101['metadata']['v1_reference'] ?? null) === 'PAY-101',
    "v1's own reference — the string on the receipt — is preserved");
t_ok(v2p_find($plan, 'legacy_reference_map', 'legacy_ref', 'PAY-101') !== null,
    'and it is resolvable through the reference map, which is what somebody quoting it on the phone needs');

t_group('v2 plan — the two enrolment models converge');

t_eq(count($plan['tables']['enrolments']), 2,
    'a student in a legacy class AND in the cohort backfilled from it gets ONE enrolment');
$dropped = v2p_find($plan, 'enrolments', 'status', 'withdrawn');
t_ok($dropped !== null && (string) $dropped['withdrawal_reason'] !== '',
    'a withdrawal must say why — the schema CHECK refuses a blank, so a NULL would fail the load');
$legacyMapped = null;
foreach ($plan['tables']['legacy_reference_map'] as $m) {
    if ($m['legacy_table'] === 'enrollments' && $m['legacy_id'] === '1') $legacyMapped = $m;
}
t_ok($legacyMapped !== null, 'the legacy enrolment row is still traceable after converging');
t_ok($legacyMapped !== null && str_contains((string) $legacyMapped['note'], 'converged'),
    'and the map SAYS it converged, rather than looking like a duplicate somebody lost');

$classMap = null;
foreach ($plan['tables']['legacy_reference_map'] as $m) {
    if ($m['legacy_table'] === 'classes' && $m['legacy_id'] === '1') $classMap = $m;
}
t_ok($classMap !== null && $classMap['v2_table'] === 'cohorts',
    'a legacy class already backfilled into a cohort maps to it rather than being duplicated');

$cohort = v2p_find($plan, 'cohorts', 'title', 'PRIVATE PIANO');
t_eq($cohort['meeting_days'], 'fri', "'Fri 16:00-18:00' parses into a day");
t_eq($cohort['meeting_time'], '16:00:00', 'and a wall-clock start');
t_eq($cohort['timezone'], 'Africa/Lagos', 'stored with a zone, so a lesson stays at 16:00 if offset rules change');

t_group('v2 plan — settings');

$cov = v2p_coverage($plan);
$secret = v2p_find($plan, 'tenant_settings', 'skey', 'drive_shared_secret');
t_ok($secret !== null, 'a secret-shaped setting is carried, so the feature does not go mysteriously off');
t_eq($secret['svalue'], null, 'but its VALUE is not written into a file on disk');
t_eq($secret['is_secret'], 1, 'and it is flagged for re-entry');
t_ok(v2p_find($plan, 'tenant_settings', 'skey', 'fx_rates_cached') === null,
    'a cached FX rate is not carried — a stale rate reads as current, and money that displays differently from what it charges is the bug the cache exists to avoid');
t_eq(v2p_find($plan, 'tenant_settings', 'skey', 'registration_fee_usd')['value_type'], 'int',
    'the value type is derived from the value, not asserted');

t_group('v2 plan — a v1 column with no v2 column is not just dropped');

$defs = [];
foreach ($plan['tables']['custom_field_defs'] as $d) $defs[$d['entity_type'] . '.' . $d['field_key']] = $d['id'];
t_ok(isset($defs['programmes.exam_week']), 'which week is the exam has no v2 column and becomes a custom field');
t_ok(isset($defs['modules.module_kind']), 'and so does what kind of week a module is');
t_ok(isset($defs['memberships.instructor_bio']), 'and an instructor\'s bio');
// Keyed to the STANDARD programme's row rather than "the first value for this
// field" — there is more than one programme now, and a positional assertion
// would pass or fail on catalogue ordering rather than on the mapping.
$stdProg = v2p_find($plan, 'programmes', 'code', 'standard');
$examValue = null;
foreach ($plan['tables']['custom_field_values'] as $v) {
    if ($v['field_def_id'] === $defs['programmes.exam_week'] && $v['entity_id'] === $stdProg['id']) $examValue = $v;
}
t_ok($examValue !== null && (string) $examValue['value_number'] === '12',
    'stored in the typed column, not a text blob');

t_group('v2 plan — summer is a crash-programme intake, not a separate system');

// v1 kept summer in its own table with its own status track. Mapping it to
// `type = 'summer'` would rebuild exactly the parallel system this migration
// exists to end — so it lands as a CRASH-programme application and shares the
// funnel, the catalogue and every grouped report with the rest.
$summerApp = null;
foreach ($plan['tables']['applications'] as $a) {
    if (is_array($a['payload'] ?? null) && ($a['payload']['intake'] ?? '') === 'summer') { $summerApp = $a; break; }
}
t_ok($summerApp !== null, 'a summer application is carried');
t_eq($summerApp['type'], 'programme', 'as an ordinary programme application, not a type of its own');
$crashProg = v2p_find($plan, 'programmes', 'code', 'crash');
t_ok($crashProg !== null && $summerApp['programme_id'] === $crashProg['id'],
    'pointed at the crash programme, resolved by code');
t_eq($summerApp['payload']['intake'], 'summer',
    'and the ROUTE is kept in the payload — it is real information the type column no longer carries');
t_ok(v2p_find($plan, 'applications', 'type', 'summer') === null,
    'nothing carries the old parallel type');

// An export with no crash programme is a data gap, not an error: the application
// is still carried, with a NULL programme rather than a guessed one.
$noCrash = v2p_source();
$noCrash['programs'] = array_values(array_filter($noCrash['programs'],
    static fn(array $p): bool => $p['code'] !== 'crash'));
$planNoCrash = mam_v2_plan($noCrash);
$sNoCrash = null;
foreach ($planNoCrash['tables']['applications'] as $a) {
    if (is_array($a['payload'] ?? null) && ($a['payload']['intake'] ?? '') === 'summer') { $sNoCrash = $a; break; }
}
t_ok($sNoCrash !== null, 'without a crash programme the summer application is still carried');
t_eq($sNoCrash['programme_id'], null, 'with a NULL programme rather than a guessed one');

$tayo = v2p_find($plan, 'users', 'full_name', 'Tayo Bello');
t_ok($tayo !== null, 'the child becomes a person');
t_eq(count($plan['tables']['guardianships']), 1,
    'the guardian_email resolved to somebody already in the data, so the guardianship is real');
t_eq((int) $plan['tables']['guardianships'][0]['learner_membership_id'],
    (int) v2p_find($plan, 'memberships', 'user_id', $tayo['id'])['id'],
    'pointing at the child, not at whoever shares their address');
t_eq($plan['tables']['guardianships'][0]['verified_at'], null,
    'and unverified — a verified flag set by an ETL is a safeguarding claim nobody made');

t_group('v2 plan — nothing is silently dropped');

foreach (array_keys($src) as $table) {
    t_ok(isset($cov[$table]), "the coverage ledger accounts for `$table`");
}
t_ok($cov['rate_limits']['destination'] === null && $cov['rate_limits']['note'] !== '',
    'a table that is deliberately not carried says so, with the reason');
t_ok($cov['newsletter']['destination'] === null,
    'a subscriber is not a member, so newsletter rows do not become consents on a roster');
$unmapped = array_values(array_filter($plan['review'], static fn($r) => $r['kind'] === 'unmapped_table'));
t_eq(count($unmapped), 0, 'and no table in the export is unaccounted for');

t_group('v2 plan — the audit log arrives verifiable');

t_eq(count($plan['tables']['audit_log']), 4,
    'v1\'s three separate trails become one log (2 audit + 1 application event + 1 enrolment event)');
$prev = null; $broken = 0;
foreach ($plan['tables']['audit_log'] as $r) {
    if ($r['prev_hash'] !== $prev) { $broken++; }
    $canon = json_encode([$r['tenant_id'], $r['actor_user_id'], $r['actor_label'], $r['action'],
        $r['entity_type'], $r['entity_id'], $r['metadata'], $r['ip_text'], $r['occurred_at']],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (hash('sha256', (string) $prev . '|' . (string) $canon) !== $r['row_hash']) $broken++;
    $prev = $r['row_hash'];
}
t_eq($broken, 0, 'row_hash = sha256(prev_hash || canonical(row)) holds along the whole chain');
$statusEvent = v2p_find($plan, 'audit_log', 'action', 'application.status_changed');
t_ok($statusEvent !== null && $statusEvent['entity_id'] === v2p_find($plan, 'applications', 'reference', 'APP-0010')['id'],
    'a status change points at the v2 application, not v1\'s id');
t_ok($statusEvent !== null && $statusEvent['actor_user_id'] !== null,
    'and at the v2 user who did it');
t_eq(count($plan['tables']['lifecycle_events'] ?? []), 0,
    'a status change is NOT a membership stage change — writing it to lifecycle_events would invent a journey v1 never recorded');

t_group('v2 plan — audit ids point at v2 rows, not v1 ones');

// The latent bug exclusions exposed: v1's `audit_log.entity_id` holds a v1 id,
// and everything else in v2 holds a v2 id. Copied across it LOOKS joinable and
// silently resolves to a stranger — application 10 in v1 is a different
// application 10 here.
$appOne = v2p_find($plan, 'applications', 'reference', 'APP-0010');
$created = v2p_find($plan, 'audit_log', 'action', 'create');
t_ok($created !== null, 'the v1 audit row is carried');
t_eq($created['entity_id'], $appOne['id'], 'and its entity_id is REMAPPED to the v2 application');
t_ok($created['entity_id'] !== 10, 'not left as the v1 id, which would point at a different row here');
t_ok(str_contains((string) $created['metadata'], '"v1_entity_id":10'),
    'the v1 id is kept in the metadata, so the old reference is still resolvable');

t_group('v2 plan — excluding a row somebody decided is not real');

// Customer 6 is the erased applicant; application 12 hangs off them and no
// money does. Excluding the person must take the application with it.
$exPlan = mam_v2_plan(v2p_source(), ['exclusions' => [
    ['row' => 'customers:6', 'reason' => 'Test run, not a real applicant.'],
]]);
t_ok(!empty($exPlan['ok']), 'the plan still builds');
t_eq(count($exPlan['excluded']), 2, 'excluding a person cascades to their application');
t_ok(v2p_find($exPlan, 'applications', 'reference', 'APP-0012') === null,
    'the application is gone');
t_eq(count($exPlan['tables']['users']), count($plan['tables']['users']) - 1,
    'and so is the person');
t_eq($exPlan['money']['total_minor'], $plan['money']['total_minor'],
    'money is untouched — they held none');

$exAudit = array_values(array_filter($exPlan['tables']['audit_log'],
    static fn($r) => $r['action'] === 'migration.excluded'));
t_eq(count($exAudit), 2, 'each removal is written into the audit log');
t_ok(str_contains((string) $exAudit[0]['metadata'], 'customers:6')
     && str_contains((string) $exAudit[0]['metadata'], 'Test run'),
    'naming the v1 row and the reason, so the database explains its own gaps');
t_ok(str_contains((string) $exAudit[0]['metadata'], 'v2-exclusions.json'),
    'and where the decision is recorded');

// The guard that matters. Customer 2 holds application 11, which carries a
// payment. Dropping a person who holds money is not a tidy-up.
$exMoney = mam_v2_plan(v2p_source(), ['exclusions' => [
    ['row' => 'customers:2', 'reason' => 'Looks like a test.'],
]]);
t_ok(empty($exMoney['ok']), 'excluding somebody who holds a payment REFUSES the whole run');
t_eq($exMoney['error'], 'exclusion_holds_money', 'naming why');
t_ok(!empty($exMoney['money']) && str_contains((string) $exMoney['money'][0]['payment'], 'payments:'),
    'and naming the payment, because a migration that quietly drops a real transaction cannot be reconciled');

// A typo would otherwise read as a successful exclusion while the row it named
// sailed through — which is what makes this kind of list untrustworthy.
$exGhost = mam_v2_plan(v2p_source(), ['exclusions' => [['row' => 'customers:999', 'reason' => 'x']]]);
t_ok(empty($exGhost['ok']), 'an exclusion matching nothing is an error, not a no-op');
t_eq($exGhost['error'], 'exclusion_not_found', 'naming the row that is not there');

// The shipped decisions must stay applicable to the shipped shapes.
$declared = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/db/v2-exclusions.json'), true);
t_ok(is_array($declared) && isset($declared['exclusions']), 'db/v2-exclusions.json parses');
foreach ((array) $declared['exclusions'] as $d) {
    t_ok(preg_match('/^[a-z_]+:\d+$/', (string) ($d['row'] ?? '')) === 1,
        'every exclusion names one v1 row as table:id [' . (string) ($d['row'] ?? '') . ']');
    t_ok(trim((string) ($d['reason'] ?? '')) !== '', 'with a reason — a bare id is not a decision');
    t_ok(trim((string) ($d['decided_by'] ?? '')) !== '' && trim((string) ($d['decided_on'] ?? '')) !== '',
        'and who decided it, when — this outlives everyone who remembers the call');
}

t_group('v2 emit — the dialect is not cosmetic');

$backslash = [['id' => 1, 'skey' => 'a', 'svalue' => 'C:\\path\\to', 'is_secret' => 0]];
$my = mam_v2_emit_table('tenant_settings', $backslash, 'mysql');
$lt = mam_v2_emit_table('tenant_settings', $backslash, 'sqlite');
t_ok(str_contains($my, "'C:\\\\path\\\\to'"),
    'MySQL treats a backslash as an escape, so it must be doubled');
t_ok(str_contains($lt, "'C:\\path\\to'"),
    'SQLite does not, so doubling it there would insert a second backslash');
t_ok(str_contains(mam_v2_emit_table('tenant_settings', [['id' => 1, 'skey' => "it's", 'svalue' => null, 'is_secret' => 0]], 'mysql'), "'it''s'"),
    "a quote is doubled in both dialects");
$nul = mam_v2_emit_table('tenant_settings', [['id' => 1, 'skey' => 'a', 'svalue' => "a\0b", 'is_secret' => 0]], 'mysql');
t_ok(str_contains($nul, '0x'),
    'a NUL becomes a hex literal — inside a quoted string it truncates the statement in some clients rather than erroring');

$threw = false;
try { mam_v2_emit_table('users', [['id' => 1, 'email' => 'a@example.test'], ['id' => 2]], 'mysql'); }
catch (Throwable $e) { $threw = true; }
t_ok($threw, 'a row of a different shape is a hard error — silently shifted values are unrecoverable');

$sql = mam_v2_emit_sql($plan, ['dialect' => 'sqlite']);
t_ok(str_contains($sql, 'BEGIN;') && str_contains($sql, 'COMMIT;'),
    'the whole load is one transaction: a half-loaded database reconciles against nothing');
t_ok(!str_contains($sql, 'FOREIGN_KEY_CHECKS') && !str_contains($sql, 'foreign_keys = OFF'),
    'and it never turns constraint checking off — if the order is right it is never needed');
t_ok(!str_contains($sql, 'INSERT INTO currencies'),
    'the schema seeds currencies itself, so re-inserting them would collide');

// The file has to explain its own prerequisite. Applied without the schema it
// fails with `ERROR 1146 … tenants doesn't exist`, which reads like a broken
// export rather than a skipped step — and the person reading it is usually in a
// phpMyAdmin tab with no other context.
t_ok(str_contains($sql, 'schema-v2-sqlite.sql INTO THIS DATABASE FIRST'),
    'the header states the prerequisite in the imperative, not as a footnote');
t_ok(str_contains($sql, 'ERROR 1146'),
    'and names the exact error skipping it produces, so the symptom is self-diagnosing');
t_ok(str_contains(mam_v2_emit_sql($plan, ['dialect' => 'mysql']), 'schema-v2.sql INTO THIS DATABASE FIRST'),
    'naming the right schema file for the dialect — the sqlite mirror is a different file');
$ipLine = null;
foreach (explode("\n", $sql) as $line) if (str_contains($line, "198.51.100.7")) $ipLine = $line;
t_eq($ipLine, null, 'an IP is emitted as the packed bytes VARBINARY(16) holds, never as text');
t_ok(str_contains($sql, "x'" . bin2hex((string) inet_pton('198.51.100.7')) . "'"), 'as a blob literal');
t_ok(!str_contains($sql, 'not-an-address'),
    'and an unparseable address becomes NULL rather than a string in a binary column');

t_group('v2 emit — the output is a value');

$again = mam_v2_plan(v2p_source());
t_eq(mam_v2_emit_sql($again, ['dialect' => 'sqlite']), $sql,
    'two transforms of one export are byte-identical, so a diff between them is a real change');
t_ok(mam_v2_plan_pid('user:customers:1', '2026-01-04 09:12:00')
     === mam_v2_plan_pid('user:customers:1', '2026-01-04 09:12:00'),
    'public ids are derived, not random — otherwise a re-transform rewrites every one of them');
t_ok(mam_v2_plan_pid('a', '2026-01-01 00:00:00') < mam_v2_plan_pid('a', '2026-06-01 00:00:00'),
    'and still sort by age, like the ULIDs they replace');

t_group('v2 emit — it actually loads, and then reconciles');

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec((string) file_get_contents(dirname(__DIR__, 2) . '/db/schema-v2-sqlite.sql'));
$loaded = true;
try { $db->exec($sql); } catch (Throwable $e) { $loaded = false; $why = $e->getMessage(); }
t_ok($loaded, 'the emitted SQL applies to the real schema with constraint checking ON'
    . ($loaded ? '' : ' — ' . ($why ?? '')));
t_eq(count($db->query('PRAGMA foreign_key_check')->fetchAll()), 0, 'and leaves no dangling reference');
t_eq((int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn(), count($plan['tables']['users']),
    'every planned person arrived');
t_eq((int) $db->query('SELECT COALESCE(SUM(amount_minor),0) FROM payments')->fetchColumn(),
    $plan['money']['total_minor'], 'and every kobo');

// The verifier recomputes from the SOURCE by an independent path. It is pointed
// at the loaded database here rather than at the plan, because agreeing with the
// plan would only prove the code agrees with itself.
$v = mam_v2_verify($db, $src, ['applications' => array_values(array_filter($plan['rejected'],
        static fn($r) => $r['table'] === 'applications'))]);
t_ok($v['ok'], 'independent verification passes: ' . $v['summary']['passed'] . '/' . $v['summary']['total']
    . ($v['ok'] ? '' : ' — failed: ' . json_encode($v['failed'])));

t_group('v2 emit — the check script, for when there is no shell');

// The people most likely to hand-load a .sql file are exactly the people with
// no PHP shell that can reach the database — a cPanel account and a phpMyAdmin
// tab is the whole toolkit. So the same expectations ship as SQL.
$checks = mam_v2_emit_checks($plan, ['dialect' => 'sqlite']);
$rowsOut = $db->query($checks)->fetchAll(PDO::FETCH_ASSOC);
t_ok(count($rowsOut) > 15, 'it produces a readable report, one row per check [' . count($rowsOut) . ']');
$failing = array_values(array_filter($rowsOut, static fn($r) => !str_contains((string) $r['verdict'], 'PASS')));
t_eq(count($failing), 0, 'and every check passes against the database it was emitted for'
    . ($failing ? ' — ' . json_encode($failing) : ''));

// It has to fail when the load is wrong, or it is decoration.
$db->exec('PRAGMA query_only = 0');
$db->exec("DELETE FROM audit_log WHERE id = (SELECT MAX(id) FROM audit_log)");
$after = $db->query($checks)->fetchAll(PDO::FETCH_ASSOC);
$nowFailing = array_column(array_values(array_filter($after,
    static fn($r) => !str_contains((string) $r['verdict'], 'PASS'))), 'check');
t_ok(count($nowFailing) > 0, 'removing one row makes it fail rather than still reading PASS');
t_ok(count(array_filter($nowFailing, static fn($c) => str_contains($c, 'audit_log'))) > 0,
    'naming the table that lost a row');

// And it must say what it does NOT do. A script called "verify" that quietly
// checks less than the reader assumes converts a real question into false
// confidence, which is worse than having no script.
t_ok(str_contains($checks, 'does not re-derive anything from the v1 export'),
    'the header states the limit of what it proves, in the file itself');
t_ok(str_contains($checks, 'v2-verify.php'),
    'and points at the tool that does the real reconciliation');

// A verifier nobody has seen fail is not evidence of anything.
$db->exec('PRAGMA query_only = 0');
$db->exec("UPDATE payments SET amount_minor = amount_minor - 1 WHERE id = 1");
$v2 = mam_v2_verify($db, $src, ['applications' => []]);
t_ok(!$v2['ok'], 'and it FAILS when a single kobo is altered under it');
t_ok(in_array('money.total_minor', array_column($v2['failed'], 'check'), true),
    'naming the figure that disagreed');
