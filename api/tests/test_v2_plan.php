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
        'assessment_types' => [
            ['id' => 1, 'code' => 'practical', 'name' => 'Practical', 'weight' => 30, 'sort_order' => 1, 'active' => 1],
            ['id' => 2, 'code' => 'final', 'name' => 'Final examination', 'weight' => 40, 'sort_order' => 2, 'active' => 1],
            // Weighted in v1's gradebook; §24 keeps it OUT of the mastery score.
            ['id' => 3, 'code' => 'attendance', 'name' => 'Attendance', 'weight' => 10, 'sort_order' => 3, 'active' => 1],
        ],
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
        /* Safeguarding, identity, practice and compliance. */
        'vetting_records' => [
            ['id' => 1, 'instructor_id' => 2, 'clearance_type' => 'criminal_record',
             'issuing_body' => 'Lagos State CID', 'reference_no' => 'CID-2026-114',
             'issued_on' => '2026-01-05', 'expires_on' => '2029-01-04', 'verified_by' => 4,
             'verified_at' => '2026-01-06 09:00:00', 'evidence_held' => 'Safeguarding file, cabinet 2',
             'created_at' => '2026-01-06 09:00:00'],
            // A verifier with no date: not a verification.
            ['id' => 2, 'instructor_id' => 2, 'clearance_type' => 'identity',
             'issuing_body' => 'NIMC', 'reference_no' => 'NIN-***', 'issued_on' => '2026-01-05',
             'expires_on' => null, 'verified_by' => 4, 'verified_at' => null,
             'evidence_held' => null, 'created_at' => '2026-01-06 09:00:00'],
        ],
        'student_consents' => [
            ['student_id' => 1, 'media_consent' => 1, 'photo_consent' => 1, 'data_consent' => 1,
             'safeguarding_contact' => 'Ifeoma Bello, +2348000000002', 'updated_by' => 4,
             'updated_at' => '2026-01-14 09:00:00'],
        ],
        'id_cards' => [
            ['id' => 1, 'user_id' => 1, 'summer_app_id' => null, 'role' => 'student',
             'serial' => 'MAM-0001-A', 'version' => 1, 'status' => 'active', 'session_code' => 'TONI26',
             'photo_url' => 'https://files.example.test/ada.jpg', 'issued_at' => '2026-02-01 09:00:00',
             'printed_at' => '2026-02-02 09:00:00', 'revoked_at' => null, 'revoke_reason' => null,
             'created_by' => 4, 'created_at' => '2026-02-01 09:00:00', 'expires_at' => '2027-01-31 23:59:59'],
            // Minted before expiry was a real date (pre-v59).
            ['id' => 2, 'user_id' => 3, 'summer_app_id' => null, 'role' => 'student',
             'serial' => 'MAM-0003-A', 'version' => 1, 'status' => 'active', 'session_code' => '',
             'photo_url' => null, 'issued_at' => '2026-02-01 09:00:00', 'printed_at' => null,
             'revoked_at' => null, 'revoke_reason' => null, 'created_by' => 4,
             'created_at' => '2026-02-01 09:00:00', 'expires_at' => null],
        ],
        'card_scans' => [
            ['id' => 1, 'card_id' => 1, 'raw_serial' => 'MAM-0001-A', 'session_id' => 1, 'class_id' => 1,
             'scanned_by' => 2, 'result' => 'ok', 'scanned_at' => '2026-02-06 16:01:00'],
            // Matched no card at all — the row somebody investigates.
            ['id' => 2, 'card_id' => null, 'raw_serial' => 'NOT-A-CARD', 'session_id' => 1,
             'class_id' => 1, 'scanned_by' => 2, 'result' => 'not_found',
             'scanned_at' => '2026-02-06 16:02:00'],
        ],
        'achievements' => [
            ['id' => 1, 'student_id' => 1, 'code' => 'first_week', 'title' => 'First week done',
             'awarded_at' => '2026-02-13 09:00:00'],
            // The same badge twice: v1 had no unique key.
            ['id' => 2, 'student_id' => 1, 'code' => 'first_week', 'title' => 'First week done',
             'awarded_at' => '2026-02-20 09:00:00'],
        ],
        'practice_logs' => [
            ['id' => 1, 'student_id' => 1, 'minutes' => 35, 'focus' => 'Scales', 'notes' => null,
             'logged_on' => '2026-02-07', 'created_at' => '2026-02-07 20:00:00'],
            // More minutes than a day contains.
            ['id' => 2, 'student_id' => 1, 'minutes' => 2000, 'focus' => 'Typo', 'notes' => null,
             'logged_on' => '2026-02-08', 'created_at' => '2026-02-08 20:00:00'],
        ],
        'repertoire' => [
            ['id' => 1, 'student_id' => 1, 'title' => 'Prelude in C', 'composer' => 'J. S. Bach',
             'status' => 'performance_ready', 'notes' => null, 'sort_order' => 1,
             'created_at' => '2026-02-01 09:00:00', 'updated_at' => null],
        ],
        'recitals' => [
            ['id' => 1, 'slug' => 'toni-2026', 'title' => 'Toni Recital', 'subtitle' => null,
             'venue' => 'Studio A', 'starts_at' => '2026-04-25 16:00:00',
             'doors_open_at' => '2026-04-25 15:30:00', 'interval_after' => 5, 'interval_min' => 15,
             'status' => 'programmed', 'submissions_close_at' => '2026-04-10 00:00:00',
             'description' => null, 'programme_note' => null, 'created_by' => 4,
             'created_at' => '2026-03-01 09:00:00', 'updated_at' => null],
        ],
        'recital_items' => [
            ['id' => 1, 'recital_id' => 1, 'student_id' => 1, 'repertoire_id' => 1, 'submission_id' => null,
             'title' => 'Prelude in C', 'composer' => 'J. S. Bach', 'arranger' => null, 'movement' => null,
             'instrument' => 'Piano', 'duration_min' => 4, 'section' => 'main', 'sort_order' => 1,
             'status' => 'accepted', 'note' => null, 'decided_by' => 4,
             'decided_at' => '2026-04-11 09:00:00', 'created_at' => '2026-04-01 09:00:00'],
            // Declined with no decider, date or reason.
            ['id' => 2, 'recital_id' => 1, 'student_id' => 3, 'repertoire_id' => null,
             'submission_id' => null, 'title' => 'Something long', 'composer' => null, 'arranger' => null,
             'movement' => null, 'instrument' => 'Voice', 'duration_min' => 20, 'section' => 'main',
             'sort_order' => 2, 'status' => 'declined', 'note' => null, 'decided_by' => null,
             'decided_at' => null, 'created_at' => '2026-04-01 09:00:00'],
        ],
        'referral_codes' => [
            ['user_id' => 1, 'code' => 'ADA26', 'created_at' => '2026-01-20 09:00:00'],
        ],
        'referrals' => [
            // Marked rewarded with no amount and no date.
            ['id' => 1, 'referrer_id' => 1, 'code' => 'ADA26', 'referred_name' => 'A Friend',
             'referred_email' => 'friend@example.test', 'referred_user_id' => null, 'status' => 'rewarded',
             'reward_cents' => 0, 'created_at' => '2026-02-01 09:00:00', 'rewarded_at' => null],
        ],
        'dp_assessments' => [
            ['id' => 1, 'title' => 'Guardian portal', 'scope' => 'Reports and fees',
             'trigger_reason' => 'New processing', 'necessity' => 'Contract', 'risks' => 'Disclosure',
             'mitigations' => 'Access scopes', 'residual_risk' => 'high', 'decision' => 'proceed',
             'assessed_on' => '2026-08-01', 'assessed_by' => 4, 'review_due_on' => '2027-08-01',
             'consulted_ndpc' => 0, 'created_at' => '2026-08-01 09:00:00'],
        ],
        'dp_breaches' => [
            // HIGH risk, closed, nobody told and no reason given.
            ['id' => 1, 'reference' => 'BR-2026-01', 'occurred_at' => '2026-07-01 09:00:00',
             'discovered_at' => '2026-07-20 09:00:00', 'nature' => 'Portal exposure',
             'data_categories' => 'Learner records', 'subject_categories' => 'Learners',
             'subjects_affected' => 12, 'risk_level' => 'high', 'containment' => 'Patched',
             'ndpc_notified_at' => null, 'ndpc_reference' => null, 'subjects_notified_at' => null,
             'no_notify_reason' => null, 'lessons' => 'Review portal scopes.', 'status' => 'closed',
             'recorded_by' => 4, 'created_at' => '2026-07-20 10:00:00'],
        ],
        'dp_processors' => [
            ['id' => 1, 'key_name' => 'smtp', 'name' => 'A Mail Host', 'role' => 'processor',
             'purpose' => 'Transactional email', 'country' => 'United States', 'dpa_signed_on' => null,
             'dpa_reference' => null, 'sub_processors' => null, 'transfer_basis' => null,
             'review_due_on' => null, 'status' => 'active', 'notes' => null, 'updated_at' => null,
             'created_at' => '2026-01-01 09:00:00'],
        ],
        'dp_register_notes' => [
            ['id' => 1, 'kind' => 'retention', 'ref_key' => 'contact_messages', 'keep_days' => 730,
             'notes' => 'Kept for two years.', 'reviewed_on' => '2026-08-01', 'reviewed_by' => 4,
             'updated_at' => null],
        ],
        'automations' => [
            ['akey' => 'payment_provision', 'enabled' => 1, 'params' => null,
             'last_run_at' => '2026-08-19 09:00:00', 'run_count' => 42, 'updated_at' => null],
            // Never fired. Zeroing telemetry on cutover day would hide that.
            ['akey' => 'week_drip', 'enabled' => 1, 'params' => null, 'last_run_at' => null,
             'run_count' => 0, 'updated_at' => null],
        ],
        'ai_runs' => [
            ['id' => 1, 'use_case' => 'advisor', 'provider' => 'test', 'model' => 'test-1', 'ok' => 0,
             'prompt_hash' => 'abc', 'input_tokens' => 100, 'output_tokens' => 0, 'error' => null,
             'reviewed_by' => null, 'reviewed_at' => null, 'created_at' => '2026-08-01 09:00:00'],
        ],
        'store_items' => [
            ['id' => 1, 'sku' => 'HOODIE-M', 'slug' => 'hoodie-m', 'name' => 'MAM hoodie (M)',
             'description' => null, 'goods_type' => 'physical', 'delivery_type' => 'ship',
             'availability' => 'in_stock', 'stock' => 12, 'available_locations' => '*',
             'price_usd' => 25.00, 'currency' => 'USD', 'images' => null, 'category' => 'apparel',
             'file_url' => null, 'weight_grams' => 600, 'tax_rate' => 0, 'sort_order' => 1,
             'active' => 1, 'created_at' => '2026-01-05 09:00:00', 'updated_at' => null],
            // Instant digital delivery with no file, and the -1 stock sentinel.
            ['id' => 2, 'sku' => 'EBOOK', 'slug' => 'scales-ebook', 'name' => 'Scales ebook',
             'description' => null, 'goods_type' => 'digital', 'delivery_type' => 'instant',
             'availability' => 'in_stock', 'stock' => -1, 'available_locations' => '*',
             'price_usd' => 8.29, 'currency' => 'USD', 'images' => null, 'category' => 'books',
             'file_url' => null, 'weight_grams' => null, 'tax_rate' => 0, 'sort_order' => 2,
             'active' => 1, 'created_at' => '2026-01-05 09:00:00', 'updated_at' => null],
        ],
        'store_orders' => [
            ['id' => 1, 'reference' => 'ORD-0001', 'customer_id' => null,
             'customer_email' => 'ada@example.test', 'customer_name' => 'Ada Okoye',
             'customer_country' => 'Nigeria', 'customer_phone' => null,
             'shipping_address' => '1 Example Street, Lagos',
             'items_json' => '[{"sku":"HOODIE-M","name":"MAM hoodie (M)","qty":2,"price_usd":25.00}]',
             'subtotal_usd' => 50.00, 'tax_usd' => 0, 'shipping_usd' => 5.00, 'total_usd' => 50.00,
             'status' => 'paid', 'notes' => null, 'delivered_at' => null,
             'created_at' => '2026-02-20 09:00:00', 'updated_at' => null],
        ],
        'notifications' => [
            ['id' => 1, 'user_id' => 1, 'type' => 'invoice.due', 'title' => 'Your tuition is due Friday',
             'body' => null, 'url' => '/portal/student', 'read_at' => null,
             'created_at' => '2026-02-05 09:00:00'],
        ],
        /* Communications and admissions. */
        'announcements' => [
            ['id' => 1, 'class_id' => 1, 'cohort_id' => null, 'author_id' => 2, 'title' => 'Friday moves',
             'body' => 'Next Friday is 17:00.', 'created_at' => '2026-02-03 09:00:00'],
            // Neither a class nor a cohort: v1 allowed it, and it had no readers.
            ['id' => 2, 'class_id' => null, 'cohort_id' => null, 'author_id' => 4, 'title' => 'Term starts',
             'body' => 'Term starts Monday.', 'created_at' => '2026-01-28 09:00:00'],
        ],
        'messages' => [
            ['id' => 1, 'name' => 'A Parent', 'email' => 'enquirer@example.test', 'phone' => null,
             'topic' => 'tuition', 'message' => 'How much is piano?', 'ip' => '198.51.100.20',
             'status' => 'answered', 'created_at' => '2026-01-15 09:00:00'],
            // No address and no phone: nobody could ever have replied.
            ['id' => 2, 'name' => 'Anon', 'email' => null, 'phone' => null, 'topic' => 'other',
             'message' => 'No way to reply to this.', 'ip' => null, 'status' => 'unread',
             'created_at' => '2026-01-16 09:00:00'],
        ],
        'support_tickets' => [
            ['id' => 1, 'student_id' => 1, 'reference' => 'TKT-0001', 'category' => 'billing',
             'subject' => 'Cannot see my invoice', 'body' => 'The payments tab is empty.', 'status' => 'open',
             'priority' => 'normal', 'assigned_to' => 4, 'resolved_at' => null,
             'created_at' => '2026-02-11 09:00:00', 'updated_at' => '2026-02-11 09:00:00'],
            // Resolved in v1 with no resolution date.
            ['id' => 2, 'student_id' => 1, 'reference' => 'TKT-0002', 'category' => 'general',
             'subject' => 'Room change', 'body' => 'Which studio?', 'status' => 'resolved',
             'priority' => 'low', 'assigned_to' => null, 'resolved_at' => null,
             'created_at' => '2026-02-12 09:00:00', 'updated_at' => '2026-02-12 09:00:00'],
        ],
        'ticket_messages' => [
            ['id' => 1, 'ticket_id' => 1, 'author_id' => 4, 'body' => 'Looking into it.', 'is_internal' => 0,
             'created_at' => '2026-02-11 10:00:00'],
            // A staff-only note. Defaulting this to family-visible would
            // disclose a private note about a child.
            ['id' => 2, 'ticket_id' => 1, 'author_id' => 4, 'body' => 'Their card was declined twice.',
             'is_internal' => 1, 'created_at' => '2026-02-11 10:05:00'],
        ],
        'surveys' => [
            ['id' => 1, 'title' => 'How is the term going?', 'description' => null,
             'questions_json' => '[{"q":"Pace?"}]', 'status' => 'active', 'target_track' => null,
             'created_by' => 4, 'closes_at' => '2026-03-01 00:00:00', 'created_at' => '2026-02-01 09:00:00',
             'kind' => 'survey'],
        ],
        'survey_responses' => [
            ['id' => 1, 'survey_id' => 1, 'student_id' => 1, 'answers_json' => '{"Pace?":"good"}',
             'submitted_at' => '2026-02-02 09:00:00'],
        ],
        'student_feedback' => [
            // Told it was anonymous, stored with the author's id beside the flag.
            ['id' => 1, 'student_id' => 1, 'rating' => 4, 'category' => 'teaching', 'subject' => 'Pace',
             'body' => 'The pace suits me.', 'enrollment_id' => null, 'course_enrollment_id' => 1,
             'is_anonymous' => 1, 'created_at' => '2026-02-15 09:00:00'],
            ['id' => 2, 'student_id' => 1, 'rating' => 5, 'category' => 'general', 'subject' => null,
             'body' => 'Happy to be named.', 'enrollment_id' => null, 'course_enrollment_id' => 1,
             'is_anonymous' => 0, 'created_at' => '2026-02-16 09:00:00'],
        ],
        'feedback' => [
            ['id' => 1, 'submission_id' => 1, 'instructor_id' => 2, 'rubric_json' => '{"1":8}',
             'comments' => 'Even tone.', 'outcome' => 'pass', 'created_at' => '2026-02-06 09:00:00',
             'updated_at' => null],
        ],
        'application_notes' => [
            ['id' => 1, 'application_id' => 10, 'author_id' => 4,
             'note' => 'Guardian asked about a bursary; do not discuss with the child.',
             'created_at' => '2026-01-05 09:00:00'],
        ],
        'scholarship_applications' => [
            ['id' => 1, 'reference' => 'SCH-0001', 'application_reference' => 'APP-0010',
             'full_name' => 'A. Okoye-Balogun', 'email' => 'ada@example.test', 'age_band' => '13-15',
             'experience' => 'Two years.', 'financial_need' => 'One income.',
             'musical_journey' => 'Started on a keyboard.', 'values_alignment' => 'Community.',
             'community_impact' => 'Church band.', 'commitment_ack' => 1, 'readiness_signal' => 'ready',
             'status' => 'awarded', 'ip' => '198.51.100.31', 'created_at' => '2026-01-10 09:00:00'],
        ],
        'auditer_results' => [
            ['id' => 1, 'student_id' => 1, 'dimensions' => '{"pitch":4,"rhythm":3}',
             'recommended_pathway' => 'standard', 'notes' => 'Start at pre-cert.',
             'audited_at' => '2026-01-11 09:00:00', 'audited_by' => 2],
            // A pathway that is not a programme code any more.
            ['id' => 2, 'student_id' => 3, 'dimensions' => null, 'recommended_pathway' => 'legacy-track',
             'notes' => null, 'audited_at' => '2026-01-12 09:00:00', 'audited_by' => 2],
        ],
        'promo_banners' => [
            // ONE v1 row holding the copy, the colour AND the discount that
            // priced tuition.
            ['id' => 1, 'title' => 'Early-bird closes Friday', 'body' => '10% off Toni tuition.',
             'cta_label' => 'Apply now', 'cta_url' => '/apply', 'bg_color' => '#FEF3C7', 'active' => 1,
             'starts_at' => '2026-01-01 00:00:00', 'ends_at' => '2026-01-31 23:59:59',
             'created_at' => '2025-12-20 09:00:00', 'updated_at' => null, 'public' => 1,
             'discount_percent' => 10, 'applies_to' => 'tuition'],
            // Copy only, and a half-configured call to action.
            ['id' => 2, 'title' => 'Studio closed Monday', 'body' => 'Public holiday.',
             'cta_label' => 'Read more', 'cta_url' => '', 'bg_color' => '#FEE2E2', 'active' => 1,
             'starts_at' => null, 'ends_at' => null, 'created_at' => '2026-01-02 09:00:00',
             'updated_at' => null, 'public' => 0, 'discount_percent' => 0, 'applies_to' => null],
        ],
        /* Teaching operations. Cohort 1 is the private class student 1 is in. */
        'rooms' => [
            ['id' => 1, 'name' => 'Studio A', 'capacity' => 6, 'resources' => 'Upright piano', 'active' => 1],
        ],
        'class_sessions' => [
            ['id' => 1, 'cohort_id' => 1, 'title' => 'Week one', 'scheduled_at' => '2026-02-06 16:00:00',
             'duration_min' => 60, 'kind' => 'class', 'location' => null, 'status' => 'held', 'notes' => 'Went well.',
             'created_by' => 2, 'created_at' => '2026-01-20 09:00:00', 'lesson_notes' => 'Scales.',
             'topics' => 'C major', 'learning_outcomes' => 'Hands together', 'substitute_id' => null,
             'rescheduled_from' => null, 'cancellation_reason' => null, 'room' => 'Studio A',
             'meeting_link' => null, 'room_id' => 1, 'lesson_id' => 1, 'meeting_provider' => null,
             'mode' => 'onsite'],
            // Cancelled with no reason recorded: v1 allowed it, v2 does not.
            ['id' => 2, 'cohort_id' => 1, 'title' => 'Week two', 'scheduled_at' => '2026-02-13 16:00:00',
             'duration_min' => 60, 'kind' => 'class', 'location' => null, 'status' => 'cancelled', 'notes' => null,
             'created_by' => 2, 'created_at' => '2026-01-20 09:00:00', 'lesson_notes' => null,
             'topics' => null, 'learning_outcomes' => null, 'substitute_id' => null,
             'rescheduled_from' => null, 'cancellation_reason' => null, 'room' => null,
             'meeting_link' => 'https://meet.example.test/w2', 'room_id' => null, 'lesson_id' => null,
             'meeting_provider' => 'meet', 'mode' => 'online'],
            // A replacement for the cancelled one.
            ['id' => 3, 'cohort_id' => 1, 'title' => 'Week two (moved)', 'scheduled_at' => '2026-02-14 16:00:00',
             'duration_min' => 60, 'kind' => 'class', 'location' => null, 'status' => 'held', 'notes' => null,
             'created_by' => 2, 'created_at' => '2026-02-13 09:00:00', 'lesson_notes' => null,
             'topics' => null, 'learning_outcomes' => null, 'substitute_id' => null,
             'rescheduled_from' => 2, 'cancellation_reason' => null, 'room' => null, 'meeting_link' => null,
             'room_id' => null, 'lesson_id' => null, 'meeting_provider' => null, 'mode' => 'onsite'],
            // No time at all: cannot be attended, reported on or moved.
            ['id' => 4, 'cohort_id' => 1, 'title' => 'Someday', 'scheduled_at' => null,
             'duration_min' => 60, 'kind' => 'class', 'location' => null, 'status' => 'scheduled', 'notes' => null,
             'created_by' => 2, 'created_at' => '2026-01-20 09:00:00', 'lesson_notes' => null,
             'topics' => null, 'learning_outcomes' => null, 'substitute_id' => null,
             'rescheduled_from' => null, 'cancellation_reason' => null, 'room' => null, 'meeting_link' => null,
             'room_id' => null, 'lesson_id' => null, 'meeting_provider' => null, 'mode' => 'onsite'],
        ],
        'session_attendance' => [
            ['id' => 1, 'session_id' => 1, 'student_id' => 1, 'status' => 'late', 'mode' => 'onsite',
             'notes' => null, 'marked_by' => 2, 'checked_in_at' => '2026-02-06 16:06:00',
             'clocked_out_at' => '2026-02-06 17:00:00', 'duration_min' => 54, 'minutes_late' => 6,
             'method' => 'self', 'checkin_lat' => 6.5244, 'checkin_lng' => 3.3792,
             'created_at' => '2026-02-06 16:06:00', 'updated_at' => null],
            // Student 3 is enrolled in cohort 8, not in the cohort this session
            // belongs to: v1 could store the mark, v2 cannot express it.
            ['id' => 2, 'session_id' => 1, 'student_id' => 3, 'status' => 'absent', 'mode' => 'onsite',
             'notes' => null, 'marked_by' => 2, 'checked_in_at' => null, 'clocked_out_at' => null,
             'duration_min' => null, 'minutes_late' => 0, 'method' => 'instructor',
             'checkin_lat' => null, 'checkin_lng' => null, 'created_at' => '2026-02-06 17:00:00',
             'updated_at' => null],
        ],
        'lesson_progress' => [
            ['id' => 1, 'lesson_id' => 1, 'student_id' => 1, 'status' => 'completed', 'percent' => 100,
             'updated_at' => '2026-02-07 09:00:00'],
            // A second lesson instancing the same module, less far along: the
            // FURTHEST progress must win.
            ['id' => 2, 'lesson_id' => 2, 'student_id' => 1, 'status' => 'in_progress', 'percent' => 40,
             'updated_at' => '2026-02-08 09:00:00'],
        ],
        'instructor_availability' => [
            ['id' => 1, 'instructor_id' => 2, 'day_of_week' => 5, 'start_time' => '16:00',
             'end_time' => '18:00', 'effective_from' => '2026-02-01', 'effective_to' => null,
             'active' => 1, 'created_at' => '2026-01-10 09:00:00'],
            // Ends before it starts.
            ['id' => 2, 'instructor_id' => 2, 'day_of_week' => 6, 'start_time' => '18:00',
             'end_time' => '16:00', 'effective_from' => null, 'effective_to' => null, 'active' => 1,
             'created_at' => '2026-01-10 09:00:00'],
        ],
        'instructor_clockins' => [
            ['id' => 1, 'session_id' => 1, 'instructor_id' => 2, 'clock_in_at' => '2026-02-06 15:58:00',
             'clock_out_at' => '2026-02-06 17:04:00', 'status' => 'present', 'minutes_late' => 0,
             'source' => 'portal', 'created_at' => '2026-02-06 15:58:00'],
            // Marked present with no clock-in time at all.
            ['id' => 2, 'session_id' => 3, 'instructor_id' => 2, 'clock_in_at' => null,
             'clock_out_at' => null, 'status' => 'present', 'minutes_late' => 0, 'source' => 'portal',
             'created_at' => '2026-02-14 16:00:00'],
        ],
        'instructor_fines' => [
            ['id' => 1, 'instructor_id' => 2, 'session_id' => 1, 'reason' => 'late_progress_report',
             'amount_kobo' => 500000, 'status' => 'pending', 'created_at' => '2026-02-07 09:00:00',
             'resolved_at' => null, 'resolved_by' => null, 'resolution_note' => null, 'currency' => 'NGN',
             'amount_usd_cents' => null, 'fx_rate_usd_ngn' => null],
            // Waived in v1 with no decider and no date.
            ['id' => 2, 'instructor_id' => 2, 'session_id' => null, 'reason' => 'late_progress_report',
             'amount_kobo' => 500000, 'status' => 'waived', 'created_at' => '2026-02-08 09:00:00',
             'resolved_at' => null, 'resolved_by' => null, 'resolution_note' => null, 'currency' => 'NGN',
             'amount_usd_cents' => null, 'fx_rate_usd_ngn' => null],
        ],
        'faculty_invites' => [
            ['id' => 1, 'email' => 'candidate@example.test', 'name' => 'A Candidate', 'source' => 'contact',
             'status' => 'invited', 'nonce' => 'live-secret-nonce', 'expires_at' => '2026-03-01 09:00:00',
             'invited_by' => 4, 'invited_at' => '2026-02-01 09:00:00', 'used_at' => null,
             'created_at' => '2026-01-30 09:00:00'],
        ],
        'instructor_applications' => [
            ['id' => 1, 'email' => 'candidate@example.test', 'full_name' => 'A Candidate',
             'phone' => '+2348000000009', 'disciplines' => 'Piano', 'experience_years' => 7,
             'bio' => 'Taught for years.', 'links' => null, 'cv_url' => 'https://files.example.test/cv.pdf',
             'headshot_url' => null, 'status' => 'submitted', 'ip' => '198.51.100.44',
             'created_at' => '2026-02-02 09:00:00'],
            // Appointed in v1, but no account exists on that address.
            ['id' => 2, 'email' => 'ghost@example.test', 'full_name' => 'Never Onboarded',
             'phone' => null, 'disciplines' => 'Voice', 'experience_years' => 3, 'bio' => null,
             'links' => null, 'cv_url' => null, 'headshot_url' => null, 'status' => 'approved',
             'ip' => null, 'created_at' => '2026-02-03 09:00:00'],
        ],
        'meeting_reconciliation' => [
            ['id' => 1, 'session_id' => 1, 'subject_type' => 'student', 'subject_id' => 1,
             'display_name' => 'Ada O.', 'join_at' => '2026-02-06 16:06:00',
             'leave_at' => '2026-02-06 17:00:00', 'minutes' => 54, 'provider' => 'meet',
             'source' => 'import', 'created_at' => '2026-02-06 18:00:00'],
            // Nobody matched the reported name — the interesting row.
            ['id' => 2, 'session_id' => 1, 'subject_type' => 'guest', 'subject_id' => null,
             'display_name' => 'iPhone (2)', 'join_at' => null, 'leave_at' => '2026-02-06 16:40:00',
             'minutes' => 12, 'provider' => 'meet', 'source' => 'import',
             'created_at' => '2026-02-06 18:00:00'],
        ],
        'progress_reports' => [
            ['id' => 1, 'session_id' => 1, 'student_id' => 1, 'instructor_id' => 2, 'form_id' => null,
             'answers_json' => '{"focus":"good"}', 'narrative' => 'Slow the left hand.', 'email_sent' => 1,
             'email_sent_at' => '2026-02-06 18:05:00', 'submitted_at' => '2026-02-06 17:40:00'],
        ],
        'class_waitlist' => [
            // Marked promoted, but student 3 has no enrolment in cohort 1.
            ['id' => 1, 'student_id' => 3, 'cohort_id' => 1, 'position' => 1, 'status' => 'promoted',
             'notified_at' => '2026-02-01 09:00:00', 'created_at' => '2026-01-28 09:00:00',
             'updated_at' => '2026-02-01 09:00:00'],
            // Offered with no record of the family being told.
            ['id' => 2, 'student_id' => 1, 'cohort_id' => 8, 'position' => 2, 'status' => 'offered',
             'notified_at' => null, 'created_at' => '2026-01-29 09:00:00',
             'updated_at' => '2026-01-29 09:00:00'],
        ],
        'class_resources' => [
            ['id' => 1, 'class_id' => 1, 'cohort_id' => null, 'title' => 'C major sheet',
             'url' => 'https://files.example.test/cmaj.pdf', 'type' => 'score', 'posted_by' => 2,
             'created_at' => '2026-02-01 09:00:00'],
        ],
        'resource_versions' => [
            ['id' => 1, 'resource_id' => 1, 'version' => 2, 'title' => 'C major sheet (fingering added)',
             'url' => 'https://files.example.test/cmaj-v2.pdf', 'note' => 'Fingering added.',
             'created_by' => 2, 'created_at' => '2026-02-20 09:00:00'],
        ],
        'reschedule_requests' => [
            ['id' => 1, 'requester_id' => 1, 'cohort_id' => 1, 'status' => 'applied',
             'reason' => 'Travelling.', 'session_count' => 1, 'fee_usd_cents' => 1000,
             'fee_currency' => 'USD', 'fx_rate_usd_ngn' => 1600.0, 'waived' => 0, 'waived_reason' => null,
             'payment_ref' => 'PAY-100', 'payment_provider' => 'paystack', 'paid_at' => '2026-02-10 09:00:00',
             'decided_by' => 4, 'decided_at' => '2026-02-10 10:00:00', 'decision_note' => 'Fine.',
             'applied_at' => '2026-02-10 10:05:00', 'expires_at' => null,
             'created_at' => '2026-02-09 09:00:00', 'updated_at' => '2026-02-10 10:05:00'],
            // Paid against a reference the ledger has never heard of.
            ['id' => 2, 'requester_id' => 1, 'cohort_id' => 1, 'status' => 'pending_approval',
             'reason' => 'Again.', 'session_count' => 1, 'fee_usd_cents' => 1000, 'fee_currency' => 'USD',
             'fx_rate_usd_ngn' => 1600.0, 'waived' => 0, 'waived_reason' => null,
             'payment_ref' => 'PAY-NOPE', 'payment_provider' => 'bank', 'paid_at' => '2026-02-20 09:00:00',
             'decided_by' => null, 'decided_at' => null, 'decision_note' => null, 'applied_at' => null,
             'expires_at' => null, 'created_at' => '2026-02-19 09:00:00', 'updated_at' => null],
        ],
        'reschedule_items' => [
            ['id' => 1, 'request_id' => 1, 'session_id' => 3, 'old_scheduled_at' => '2026-02-14 16:00:00',
             'new_scheduled_at' => '2026-02-21 16:00:00', 'applied' => 1, 'created_at' => '2026-02-10 10:05:00'],
        ],
        'reschedule_events' => [
            ['id' => 1, 'request_id' => 1, 'event' => 'approved', 'actor_id' => 4, 'detail' => null,
             'created_at' => '2026-02-10 10:00:00'],
        ],
        /* The academic calendar: the only authority on a refund. */
        'academic_terms' => [
            ['id' => 1, 'year_label' => '2026', 'code' => 'TONI', 'term_no' => 1, 'label' => 'Toni 2026',
             'starts_on' => '2026-02-01', 'ends_on' => '2026-04-30', 'add_drop_deadline' => '2026-02-14',
             'withdrawal_deadline' => '2026-03-31', 'census_date' => '2026-02-21', 'teaching_weeks' => 12,
             'timezone' => 'Africa/Lagos', 'status' => 'active', 'notes' => null,
             'created_at' => '2026-01-05 09:00:00'],
        ],
        'term_refund_schedule' => [
            // A BAND in v1: weeks 1–2 at 100%, 3–5 at 50%. v2 stores a row per week.
            ['id' => 1, 'term_id' => 1, 'week_from' => 1, 'week_to' => 2, 'percent' => 100],
            ['id' => 2, 'term_id' => 1, 'week_from' => 3, 'week_to' => 5, 'percent' => 50],
            // Overlaps week 5: the first band must win, and it must be raised.
            ['id' => 3, 'term_id' => 1, 'week_from' => 5, 'week_to' => 6, 'percent' => 25],
        ],
        'term_census' => [
            ['id' => 1, 'term_id' => 1, 'taken_on' => '2026-02-21', 'enrolled' => 19, 'active_cohorts' => 4,
             'detail_json' => '{"by_programme":{"standard":19}}', 'created_at' => '2026-02-21 09:00:00'],
        ],
        'term_closures' => [
            ['id' => 1, 'term_id' => 1, 'kind' => 'holiday', 'label' => 'Easter', 'from_on' => '2026-04-03',
             'to_on' => '2026-04-06'],
        ],
        // A guardian link, carrying the v86 access scope.
        'parent_links' => [
            ['id' => 1, 'parent_id' => 2, 'student_id' => 1, 'relationship' => 'mother', 'created_by' => 4,
             'created_at' => '2026-01-14 09:00:00', 'access_scope' => 'full', 'scope_set_by' => 4,
             'scope_set_at' => '2026-01-14 09:00:00'],
            // An unrecognised scope must read as the NARROWEST, not the widest.
            ['id' => 2, 'parent_id' => 2, 'student_id' => 3, 'relationship' => 'guardian', 'created_by' => 4,
             'created_at' => '2026-01-15 09:00:00', 'access_scope' => 'everything', 'scope_set_by' => 4,
             'scope_set_at' => '2026-01-15 09:00:00'],
        ],
        'termly_reports' => [
            ['id' => 1, 'student_id' => 1, 'cohort_id' => 1, 'course_id' => 5, 'level_id' => 1, 'term_id' => 1,
             'term_label' => 'Toni 2026', 'status' => 'ratified', 'band_low' => 70, 'band_high' => 79,
             'coverage_evidenced' => 8, 'coverage_total' => 11, 'coverage_weight_pct' => 72,
             'instructor_id' => 2, 'instructor_comment' => 'Steady work.', 'attendance_summary' => '11 of 12',
             'ratified_by' => 4, 'ratified_at' => '2026-05-02 09:00:00', 'ratified_note' => 'Board of 3.',
             'withdrawn_at' => null, 'withdrawn_reason' => null, 'created_by' => 2,
             'created_at' => '2026-05-01 09:00:00', 'updated_at' => null],
            // Ratified by an account that does not exist: must return to moderating.
            ['id' => 2, 'student_id' => 3, 'cohort_id' => 8, 'course_id' => 5, 'level_id' => 1, 'term_id' => 1,
             'term_label' => 'Toni 2026', 'status' => 'ratified', 'band_low' => 100, 'band_high' => 100,
             'coverage_evidenced' => 11, 'coverage_total' => 11, 'coverage_weight_pct' => 100,
             'instructor_id' => 2, 'instructor_comment' => null, 'attendance_summary' => null,
             'ratified_by' => 99, 'ratified_at' => '2026-05-02 09:00:00', 'ratified_note' => null,
             'withdrawn_at' => null, 'withdrawn_reason' => null, 'created_by' => 2,
             'created_at' => '2026-05-01 10:00:00', 'updated_at' => null],
        ],
        'termly_report_criteria' => [
            // 'practical' is a real competency code (assessment_types above), so
            // this resolves to a FOREIGN KEY rather than a guess.
            ['id' => 1, 'report_id' => 1, 'competency_code' => 'rhythm_timing', 'label' => 'Rhythm & timing',
             'weight_pct' => 10, 'level' => 'secure', 'pct' => 74, 'grade_label' => 'Secure',
             'first_assessed_week' => 3, 'note' => null, 'sort_order' => 1],
            // Unevidenced: a ROW with no percentage, not a zero.
            ['id' => 2, 'report_id' => 1, 'competency_code' => 'theory', 'label' => 'Theory',
             'weight_pct' => 10, 'level' => 'insufficient', 'pct' => null, 'grade_label' => null,
             'first_assessed_week' => null, 'note' => 'Not yet assessed.', 'sort_order' => 2],
            // A code with no competency in v2: rejected, never written as an
            // orphan label.
            ['id' => 3, 'report_id' => 1, 'competency_code' => 'telepathy', 'label' => 'Telepathy',
             'weight_pct' => 5, 'level' => 'working', 'pct' => 50, 'grade_label' => null,
             'first_assessed_week' => null, 'note' => null, 'sort_order' => 3],
            // A second row for a competency the report already scored. v1 had no
            // unique key, v2 does — the first must win rather than the load failing.
            ['id' => 4, 'report_id' => 1, 'competency_code' => 'rhythm_timing', 'label' => 'Rhythm (again)',
             'weight_pct' => 10, 'level' => 'working', 'pct' => 55, 'grade_label' => null,
             'first_assessed_week' => null, 'note' => null, 'sort_order' => 4],
        ],
        'report_moderations' => [
            ['id' => 1, 'report_id' => 1, 'competency_code' => 'rhythm_timing', 'moderator_id' => 2,
             'agreed' => 1, 'moderator_level' => 'secure', 'note' => null,
             'moderated_at' => '2026-05-01 18:00:00'],
            // The moderator's account did not map; the sample still happened.
            ['id' => 2, 'report_id' => 1, 'competency_code' => 'rhythm_timing', 'moderator_id' => 99,
             'agreed' => 0, 'moderator_level' => 'working', 'note' => 'Would mark lower.',
             'moderated_at' => '2026-05-01 19:00:00'],
        ],
        'mark_appeals' => [
            // Closed twenty minutes after it was raised — the timestamp-precision
            // case that used to fail the load outright.
            ['id' => 1, 'report_id' => 1, 'student_id' => 1, 'raised_by' => 2, 'raised_role' => 'parent',
             'competency_code' => 'rhythm_timing', 'reason' => 'The recital mark does not match the video.',
             'status' => 'declined', 'outcome_note' => 'Moderation sample confirmed the mark.',
             'decided_by' => 4, 'opened_at' => '2026-05-03 09:00:00', 'closed_at' => '2026-05-03 09:20:00'],
            // Upheld with no note recorded: carried OPEN, because the family is
            // still owed an answer.
            ['id' => 2, 'report_id' => 1, 'student_id' => 1, 'raised_by' => 2, 'raised_role' => 'parent',
             'competency_code' => null, 'reason' => 'Second look please.', 'status' => 'upheld',
             'outcome_note' => null, 'decided_by' => 4, 'opened_at' => '2026-05-04 09:00:00',
             'closed_at' => '2026-05-05 09:00:00'],
        ],
        'guardian_threads' => [
            ['id' => 1, 'student_id' => 1, 'subject' => 'Friday timetable', 'kind' => 'timetable',
             'status' => 'open', 'opened_by' => 2, 'created_at' => '2026-02-10 09:00:00',
             'last_message_at' => '2026-02-10 10:00:00', 'closed_at' => null],
        ],
        'guardian_thread_participants' => [
            ['id' => 1, 'thread_id' => 1, 'user_id' => 2, 'role' => 'guardian',
             'last_read_at' => '2026-02-10 10:05:00', 'added_at' => '2026-02-10 09:00:00'],
            // A learner named as a participant in a thread ABOUT them: dropped,
            // never coerced into 'guardian'.
            ['id' => 2, 'thread_id' => 1, 'user_id' => 1, 'role' => 'student',
             'last_read_at' => null, 'added_at' => '2026-02-10 09:01:00'],
        ],
        'guardian_thread_messages' => [
            ['id' => 1, 'thread_id' => 1, 'author_id' => 2, 'author_name' => 'Ifeoma Bello',
             'author_role' => 'guardian', 'body' => 'Can we move Friday?',
             'created_at' => '2026-02-10 09:00:00'],
            // The author's account did not map; the NAME is what a family reads.
            ['id' => 2, 'thread_id' => 1, 'author_id' => 99, 'author_name' => 'The Office',
             'author_role' => 'office', 'body' => 'Yes — 17:00 works.',
             'created_at' => '2026-02-10 10:00:00'],
        ],
        'guardian_channel_prefs' => [
            ['user_id' => 2, 'whatsapp' => 1, 'email' => 1, 'sms' => 0, 'updated_at' => '2026-02-01 09:00:00'],
        ],
        'makeup_credits' => [
            ['id' => 1, 'student_id' => 1, 'term_id' => 1, 'year_label' => '2026/27', 'reason' => 'guardian_notice',
             'source_session_id' => null, 'notice_hours' => 30.0, 'counts_against_allowance' => 1,
             'status' => 'available', 'expires_on' => '2027-07-31', 'spent_session_id' => null,
             'spent_at' => null, 'granted_by' => 4, 'granted_at' => '2026-02-12 09:00:00',
             'revoked_reason' => null, 'note' => null],
            // v1 charged a goodwill credit to the family's allowance. The CHECK
            // on both engines refuses it, so it must arrive free.
            ['id' => 2, 'student_id' => 1, 'term_id' => 1, 'year_label' => '2026/27', 'reason' => 'goodwill',
             'source_session_id' => null, 'notice_hours' => null, 'counts_against_allowance' => 1,
             'status' => 'available', 'expires_on' => '2027-07-31', 'spent_session_id' => null,
             'spent_at' => null, 'granted_by' => 4, 'granted_at' => '2026-02-13 09:00:00',
             'revoked_reason' => null, 'note' => 'Apology for a late start.'],
        ],
        /* Fees. The base currency is NGN, so a naira invoice needs no rate and a
         * dollar one with no frozen rate must be REFUSED, not converted. */
        'fee_aid_awards' => [
            ['id' => 1, 'student_id' => 1, 'kind' => 'bursary', 'pct' => 25, 'amount_minor' => null,
             'currency' => 'NGN', 'term_id' => 1, 'term_label' => 'Toni 2026', 'basis' => 'band_b',
             'status' => 'active', 'starts_on' => '2026-02-01', 'expires_on' => null, 'awarded_by' => 4,
             'awarded_at' => '2026-01-20 09:00:00', 'revoked_at' => null, 'revoked_reason' => null,
             'note' => null],
        ],
        'fee_invoices' => [
            ['id' => 1, 'public_ref' => 'INV-2026-0001', 'student_id' => 1, 'guardian_id' => 2, 'term_id' => 1,
             'term_label' => 'Toni 2026', 'currency' => 'NGN', 'status' => 'part_paid',
             'issued_on' => '2026-01-25', 'due_on' => '2026-02-08', 'grace_until' => null,
             'dunning_stage' => 0, 'dunning_last' => null, 'fx_rate_usd_ngn' => 1600.0,
             'gross_minor' => 12000000, 'waiver_minor' => 500000, 'aid_minor' => 2875000,
             'sibling_minor' => 100000, 'total_minor' => 8525000, 'paid_minor' => 4000000,
             'total_usd_cents' => null, 'note' => null, 'void_reason' => null, 'created_by' => 4,
             'created_at' => '2026-01-25 09:00:00', 'updated_at' => '2026-02-03 09:00:00'],
            // A stated total that does not equal its own arithmetic.
            ['id' => 2, 'public_ref' => 'INV-2026-0002', 'student_id' => 3, 'guardian_id' => 2, 'term_id' => 1,
             'term_label' => 'Toni 2026', 'currency' => 'NGN', 'status' => 'issued',
             'issued_on' => '2026-01-25', 'due_on' => '2026-02-08', 'grace_until' => null,
             'dunning_stage' => 0, 'dunning_last' => null, 'fx_rate_usd_ngn' => null,
             'gross_minor' => 5000000, 'waiver_minor' => 0, 'aid_minor' => 0, 'sibling_minor' => 0,
             'total_minor' => 4000000, 'paid_minor' => 0, 'total_usd_cents' => null, 'note' => null,
             'void_reason' => null, 'created_by' => 4, 'created_at' => '2026-01-25 09:00:00',
             'updated_at' => null],
            // A dollar invoice with no frozen rate: refused.
            ['id' => 3, 'public_ref' => 'INV-2026-0003', 'student_id' => 1, 'guardian_id' => 2, 'term_id' => 1,
             'term_label' => 'Toni 2026', 'currency' => 'USD', 'status' => 'issued',
             'issued_on' => '2026-01-26', 'due_on' => '2026-02-09', 'grace_until' => null,
             'dunning_stage' => 0, 'dunning_last' => null, 'fx_rate_usd_ngn' => null,
             'gross_minor' => 18500, 'waiver_minor' => 0, 'aid_minor' => 0, 'sibling_minor' => 0,
             'total_minor' => 18500, 'paid_minor' => 0, 'total_usd_cents' => 18500, 'note' => null,
             'void_reason' => null, 'created_by' => 4, 'created_at' => '2026-01-26 09:00:00',
             'updated_at' => null],
        ],
        'fee_invoice_lines' => [
            ['id' => 1, 'invoice_id' => 1, 'kind' => 'tuition', 'label' => 'Piano, Toni 2026',
             'enrollment_id' => 1, 'cohort_id' => 1, 'qty' => 1, 'unit_minor' => 11000000,
             'amount_minor' => 11000000, 'waiver_minor' => 0, 'waiver_reason' => null, 'sort_order' => 1],
            ['id' => 2, 'invoice_id' => 1, 'kind' => 'exam', 'label' => 'Theory paper',
             'enrollment_id' => 1, 'cohort_id' => 1, 'qty' => 1, 'unit_minor' => 1000000,
             'amount_minor' => 1000000, 'waiver_minor' => 500000, 'waiver_reason' => 'Sat internally',
             'sort_order' => 2],
        ],
        'fee_invoice_credits' => [
            ['id' => 1, 'invoice_id' => 1, 'award_id' => 1, 'kind' => 'bursary', 'amount_minor' => 2875000,
             'currency' => 'NGN', 'note' => '25% of tuition', 'applied_by' => 4,
             'applied_at' => '2026-01-25 09:05:00'],
        ],
        'fee_payment_plans' => [
            ['id' => 1, 'invoice_id' => 1, 'instalments' => 3, 'status' => 'active', 'requested_by' => 2,
             'approved_by' => 4, 'created_at' => '2026-01-26 09:00:00', 'cancelled_at' => null,
             'cancel_reason' => null],
        ],
        'fee_plan_instalments' => [
            // Settled by v1 payment 100, which must be REMAPPED to its v2 id.
            ['id' => 1, 'plan_id' => 1, 'seq' => 1, 'due_on' => '2026-02-08', 'amount_minor' => 4000000,
             'status' => 'paid', 'paid_at' => '2026-02-03 09:00:00', 'payment_id' => 100],
            // Names a payment that is not in the export at all.
            ['id' => 2, 'plan_id' => 1, 'seq' => 2, 'due_on' => '2026-03-08', 'amount_minor' => 2262500,
             'status' => 'pending', 'paid_at' => null, 'payment_id' => 999],
            ['id' => 3, 'plan_id' => 1, 'seq' => 3, 'due_on' => '2026-04-08', 'amount_minor' => 2262500,
             'status' => 'pending', 'paid_at' => null, 'payment_id' => null],
        ],
        'bursary_applications' => [
            ['id' => 1, 'student_id' => 1, 'guardian_id' => 2, 'term_id' => 1, 'term_label' => 'Toni 2026',
             'income_band' => 'band_b', 'household_size' => 5, 'dependants_in_academy' => 2,
             'status' => 'awarded', 'awarded_pct' => 25, 'award_id' => 1, 'decided_by' => 4,
             'decided_at' => '2026-01-20 09:00:00', 'outcome_note' => 'Band B, 25%.', 'note' => null,
             'created_at' => '2026-01-18 09:00:00'],
        ],
        /* Academic work. Student 1 (user 1) has exactly ONE enrolment — the
         * cohort both v1 models point at — so a row naming no class resolves.
         * Student 3 is enrolled in cohort 8, NOT in the cohort class 1 became,
         * which is the rejection case. */
        'lessons' => [
            ['id' => 1, 'cohort_id' => 1, 'module_id' => 1, 'title' => 'Week one, Friday',
             'body' => null, 'url' => null, 'sort_order' => 1, 'published' => 1, 'created_by' => 2,
             'created_at' => '2026-01-16 09:00:00', 'week_no' => 1, 'objectives' => null,
             'prep_instructions' => null, 'resources_json' => null, 'teachback_required' => 1],
            ['id' => 2, 'cohort_id' => 1, 'module_id' => 1, 'title' => 'Week one, repeat',
             'body' => null, 'url' => null, 'sort_order' => 2, 'published' => 1, 'created_by' => 2,
             'created_at' => '2026-01-23 09:00:00', 'week_no' => 1, 'objectives' => null,
             'prep_instructions' => null, 'resources_json' => null, 'teachback_required' => 0],
        ],
        /* A second lesson instancing the SAME module, for the progress merge. */
        'teachbacks' => [
            ['id' => 1, 'lesson_id' => 1, 'student_id' => 1, 'cohort_id' => 1, 'kind' => 'written',
             'artifact_url' => 'https://files.example.test/tb1.m4a', 'body' => 'Scales go up.',
             'status' => 'reviewed', 'reviewer_id' => 2, 'decision' => 'developing', 'understanding' => 3,
             'feedback' => 'Good start.', 'created_at' => '2026-01-17 09:00:00',
             'reviewed_at' => '2026-01-17 18:00:00',
             'accuracy' => 4, 'vocabulary' => 3, 'explanation' => 4, 'application' => 3, 'confidence' => 90],
            // A second teach-back on the same module: v1's UNIQUE was (lesson,
            // student), v2's is (enrolment, module), so this one collides.
            ['id' => 2, 'lesson_id' => 1, 'student_id' => 1, 'cohort_id' => 1, 'kind' => 'audio',
             'artifact_url' => null, 'body' => 'Second go.', 'status' => 'submitted', 'reviewer_id' => null,
             'decision' => null, 'understanding' => null, 'feedback' => null,
             'created_at' => '2026-01-18 09:00:00', 'reviewed_at' => null,
             'accuracy' => null, 'vocabulary' => null, 'explanation' => null, 'application' => null, 'confidence' => null],
            // Marked reviewed in v1 with no decision recorded: v2 says that is
            // not a review.
            ['id' => 3, 'lesson_id' => 1, 'student_id' => 3, 'cohort_id' => 8, 'kind' => 'written',
             'artifact_url' => null, 'body' => 'Mine.', 'status' => 'reviewed', 'reviewer_id' => 2,
             'decision' => null, 'understanding' => null, 'feedback' => 'Seen.',
             'created_at' => '2026-01-19 09:00:00', 'reviewed_at' => '2026-01-19 10:00:00',
             'accuracy' => null, 'vocabulary' => null, 'explanation' => null, 'application' => null, 'confidence' => null],
        ],
        'rubrics' => [
            ['id' => 1, 'title' => 'Performance', 'cohort_id' => 1, 'created_by' => 2, 'active' => 1,
             'created_at' => '2026-01-15 09:00:00'],
            // Same title, second rubric: in v2 that is a VERSION.
            ['id' => 2, 'title' => 'Performance', 'cohort_id' => 1, 'created_by' => 2, 'active' => 0,
             'created_at' => '2026-01-20 09:00:00'],
        ],
        'rubric_criteria' => [
            ['id' => 1, 'rubric_id' => 1, 'label' => 'Score/Notation Accuracy', 'max_points' => 10,
             'weight' => 1.0, 'sort_order' => 1],
            ['id' => 2, 'rubric_id' => 1, 'label' => 'Ensemble', 'max_points' => 5, 'weight' => 1.0, 'sort_order' => 2],
        ],
        'assignment_groups' => [
            ['id' => 1, 'class_id' => 1, 'title' => 'Term one practice', 'description' => null,
             'sort_order' => 1, 'created_by' => 2, 'created_at' => '2026-01-15 09:00:00'],
        ],
        'assignments' => [
            ['id' => 1, 'student_id' => 1, 'author_id' => 2, 'class_id' => 1, 'group_id' => 1,
             'title' => 'C major, hands together', 'instructions' => 'Slowly.', 'due_on' => '2026-02-06',
             'status' => 'open', 'completed_at' => null, 'created_at' => '2026-01-16 09:00:00',
             'reference_url' => null, 'reference_kind' => null],
            // Set to a student who is not in the cohort that class became.
            ['id' => 2, 'student_id' => 3, 'author_id' => 2, 'class_id' => 1, 'group_id' => null,
             'title' => 'Wrong class', 'instructions' => null, 'due_on' => null, 'status' => 'open',
             'completed_at' => null, 'created_at' => '2026-01-16 09:00:00',
             'reference_url' => null, 'reference_kind' => null],
        ],
        'submissions' => [
            ['id' => 1, 'assignment_id' => 1, 'student_id' => 1,
             'artifact_url' => 'https://files.example.test/scale.mp3', 'artifact_kind' => 'recording',
             'note' => 'Third take.', 'status' => 'graded', 'submitted_at' => '2026-02-05 20:11:00',
             'created_at' => '2026-02-05 20:00:00', 'updated_at' => '2026-02-06 09:00:00'],
        ],
        'submission_files' => [
            ['id' => 1, 'submission_id' => 1, 'url' => 'https://files.example.test/score.pdf',
             'kind' => 'score', 'filename' => 'score.pdf', 'size_bytes' => 1024,
             'created_at' => '2026-02-05 20:12:00'],
        ],
        'submission_reviews' => [
            // scores_json is {criterion_id: points}. The NULL is a criterion that
            // was in scope and had no evidence — a row, not a zero.
            ['id' => 1, 'submission_id' => 1, 'rubric_id' => 1, 'reviewer_id' => 2,
             'scores_json' => '{"1":8,"2":null}', 'total_points' => 8, 'max_points' => 15,
             'feedback' => 'Even tone.', 'created_at' => '2026-02-06 09:00:00'],
        ],
        // v1's gradebook: typed by what v2 calls a competency.
        'assessments' => [
            ['id' => 1, 'enrollment_id' => 1, 'type_id' => 1, 'instructor_id' => 2, 'week' => 3,
             'score' => 72, 'max_score' => 100, 'notes' => 'Improving.', 'created_at' => '2026-02-10 09:00:00'],
        ],
        'results' => [
            ['id' => 1, 'enrollment_id' => 1, 'total_score' => 78.5, 'grade' => 'B', 'outcome' => 'passed',
             'finalized_by' => 4, 'finalized_at' => '2026-03-01 09:00:00'],
            // Finalised in v1 with a score off any percentage scale and no
            // outcome: both facts have to survive as questions, not as data.
            ['id' => 2, 'enrollment_id' => 2, 'total_score' => 900, 'grade' => null, 'outcome' => null,
             'finalized_by' => null, 'finalized_at' => '2026-03-01 09:00:00'],
        ],
        'certificates' => [
            ['id' => 1, 'reference' => 'MAM-CERT-1', 'student_id' => 1, 'program_id' => 3, 'level_id' => 1,
             'kind' => 'completion', 'title' => 'Standard Programme', 'issued_by' => 4,
             'issued_at' => '2026-03-02 09:00:00'],
        ],
        'module_proposals' => [
            ['id' => 1, 'course_id' => 5, 'level_id' => 1, 'week' => 3, 'title' => 'Swing feel',
             'kind' => 'module', 'content' => 'Listen first.', 'proposed_by' => 2, 'status' => 'approved',
             'review_note' => null, 'reviewed_by' => 4, 'reviewed_at' => '2026-01-25 09:00:00',
             'created_at' => '2026-01-24 09:00:00'],
            // Rejected with no reason: the proposer could not revise it.
            ['id' => 2, 'course_id' => 5, 'level_id' => 1, 'week' => 4, 'title' => 'Unclear week',
             'kind' => 'module', 'content' => null, 'proposed_by' => 2, 'status' => 'rejected',
             'review_note' => null, 'reviewed_by' => 4, 'reviewed_at' => '2026-01-26 09:00:00',
             'created_at' => '2026-01-24 10:00:00'],
        ],
        'tool_scores' => [
            ['id' => 1, 'user_id' => 1, 'tool' => 'metronome', 'best_score' => 90, 'plays' => 3,
             'updated_at' => '2026-02-01 09:00:00', 'created_at' => '2026-01-20 09:00:00'],
            // v1 put no unique key on (user, tool): two rows, one person.
            ['id' => 2, 'user_id' => 1, 'tool' => 'metronome', 'best_score' => 96, 'plays' => 2,
             'updated_at' => '2026-02-02 09:00:00', 'created_at' => '2026-01-21 09:00:00'],
        ],
        // Deliberately not carried, and must still be accounted for.
        'rate_limits' => [['k' => 'x', 'hits' => 3]],
        'newsletter'  => [
            ['id' => 1, 'email' => 'x@example.test', 'source' => 'footer', 'status' => 'active', 'created_at' => '2026-01-01 00:00:00'],
            // Asked not to be mailed. Dropping this row drops the SUPPRESSION
            // list, and the next import mails them again.
            ['id' => 2, 'email' => 'gone@example.test', 'source' => 'footer', 'status' => 'unsubscribed', 'created_at' => '2026-01-02 00:00:00'],
        ],
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

t_group('v2 plan — the competency vocabulary is the mastery registry, not v1\'s gradebook');

// The inherited mapping seeded `competencies` from v1's `assessment_types`. Its
// codes are KINDS of assessment (assignment|final|practical|attendance), so the
// loaded vocabulary had nothing in it that a report criterion could resolve to —
// and every criterion on every report was rejected as an unknown code. The
// families' reports would have arrived with no marks on them at all.
$comps = array_column($plan['tables']['competencies'], 'code');
t_ok(in_array('rhythm_timing', $comps, true) && in_array('theory', $comps, true),
    'the competencies are the mastery components');
t_ok(!in_array('assignment', $comps, true) && !in_array('attendance', $comps, true),
    'and NOT v1\'s gradebook weightings, which are kinds of assessment');
if (defined('MAM_MASTERY_COMPONENTS')) {
    // plan.php mirrors the registry rather than requiring mastery.php (which
    // pulls in the application). This is what stops the copy drifting.
    t_eq($comps, array_keys(MAM_MASTERY_COMPONENTS),
        'the mirrored registry in plan.php matches mastery.php exactly, in the same order');
    $planWeights = [];
    foreach ($plan['tables']['competencies'] as $c) $planWeights[$c['code']] = (float) $c['weight_pct'];
    $regWeights = [];
    foreach (MAM_MASTERY_COMPONENTS as $k => $m) $regWeights[$k] = (float) $m['weight'];
    t_eq($planWeights, $regWeights, 'and so do the weights');
}
$kinds2 = array_column($plan['tables']['assessment_kinds'], 'code');
t_ok(in_array('practical', $kinds2, true) && in_array('final', $kinds2, true),
    'v1\'s assessment_types land in assessment_kinds instead, keeping their weights');
$att = v2p_find($plan, 'assessment_kinds', 'code', 'attendance');
t_eq((int) $att['counts_to_mastery'], 0,
    'attendance is carried as a kind that does NOT count to mastery — §24 keeps attendance beside the '
    . 'score, never inside it');

t_group('v2 plan — the safeguarding gate carries no offence data');

$vets = $plan['tables']['vetting_records'];
t_eq(count($vets), 2, 'both clearances carry');
foreach ($vets as $v) {
    foreach (['certificate_scan', 'attachment_id', 'findings', 'disclosure', 'offences'] as $forbidden) {
        t_ok(!array_key_exists($forbidden, $v),
            "no `$forbidden` travels with a clearance — the record of a check must not become a copy of it");
    }
}
$half = array_values(array_filter($vets, static fn($v) => $v['clearance_type'] === 'identity'));
t_ok($half[0]['verified_by'] === null && $half[0]['verified_at'] === null,
    'a record "verified" by somebody with no date arrives UNVERIFIED — so the gate asks for it again, '
    . 'which is the outcome that protects a child');
$cleared = array_values(array_filter($vets, static fn($v) => $v['clearance_type'] === 'criminal_record'));
t_ok($cleared[0]['verified_at'] !== null && $cleared[0]['evidence_held'] !== null,
    'a complete one keeps its verification and the pointer to where the paper is filed');

// v1's three booleans become a row per purpose.
$consents = $plan['tables']['consents'];
t_eq(count($consents), 3, 'three boolean columns become three rows, one per purpose');
t_eq(array_column($consents, 'purpose'), ['media', 'photography', 'data_processing'],
    'each naming what was agreed to, which v1 could only express as a column name');
foreach ($consents as $c) {
    t_ok($c['effective_from'] !== null,
        'and each states when it took effect — v1 could not say WHEN a consent was withdrawn, which is the '
        . 'NDPA-relevant fact');
}
$cfields = array_column($plan['tables']['custom_field_defs'], 'field_key');
t_ok(in_array('safeguarding_contact', $cfields, true),
    'the safeguarding contact is a fact about the family, not a fourth consent that would mean something '
    . 'different from the other three');

t_group('v2 plan — a card that cannot lapse is the bug expiry was added to fix');

$cards = v2p_by_id($plan, 'id_cards');
t_eq(count($cards), 2, 'both cards carry');
t_eq($cards[1]['expires_at'], '2027-01-31 23:59:59', 'a card with an expiry keeps it');
t_ok($cards[2]['expires_at'] !== null, 'and one minted before expiry was a real date is GIVEN one');
t_eq(substr((string) $cards[2]['expires_at'], 0, 10), '2027-02-01',
    'one year from issue, matching the shipped default rather than a guess');
$kinds5 = array_column($plan['review'], 'kind');
t_ok(in_array('card_expiry_assumed', $kinds5, true),
    'and it is raised: before v59 the face printed EXP.12/<issue year> and nothing enforced it, so a lapsed '
    . 'card scanned into attendance for ever');
$scans = $plan['tables']['card_scans'];
t_eq(count($scans), 2, 'both scans carry');
$miss = array_values(array_filter($scans, static fn($s) => $s['card_id'] === null));
t_eq($miss[0]['raw_serial'], 'NOT-A-CARD',
    'a scan that matched no card keeps what the scanner READ — a door log recording only successes cannot '
    . 'answer "did somebody try to get in with a revoked card"');
t_eq($miss[0]['result'], 'unknown', 'under v2\'s vocabulary');

$cov5 = v2p_coverage($plan);
foreach (['password_resets', 'progress_report_tokens', 'calendar_tokens'] as $tokenTable) {
    t_ok($cov5[$tokenTable]['destination'] === null,
        "$tokenTable is not carried: a live token is a credential, and re-issuing one costs an email while "
        . 'migrating a table of them extends every window that should have closed');
}
t_ok(str_contains((string) $cov5['calendar_tokens']['note'], 'NEVER expired'),
    "and the ledger names why v1's calendar tokens especially cannot come across unchanged");
t_ok(str_contains((string) $cov5['rate_limits']['note'], 'auth_throttle'),
    'the request counter is dropped, but the ledger points at the AUTH failure counter that replaces it — a '
    . 'security control must not vanish in a migration');

t_group('v2 plan — practice, recognition and the claims v1 could not back');

$achs = $plan['tables']['achievements'];
t_eq(count($achs), 1, 'the same badge awarded twice becomes one row — a second replays the celebration');
$plogs = $plan['tables']['practice_logs'];
t_eq(count($plogs), 2, 'both practice logs carry');
$clamped = array_values(array_filter($plogs, static fn($l) => (int) $l['minutes'] === 1440));
t_eq(count($clamped), 1,
    'more minutes than a day contains is CLAMPED, not dropped: the learner did practise that day, and a '
    . 'streak built on typos is worthless');
t_ok(in_array('practice_minutes_clamped', $kinds5, true), 'and it is raised');

$rec = $plan['tables']['recitals'][0];
t_eq($rec['starts_local'], '2026-04-25 17:00:00.000',
    'a concert time is a LOCAL wall clock derived from the UTC instant v1 stored — families read the doors '
    . 'time off a poster');
t_eq($rec['timezone'], 'Africa/Lagos', 'with the zone stated');
$items = $plan['tables']['recital_items'];
t_eq(count($items), 2, 'both programme items carry');
$declined = array_values(array_filter($items, static fn($i) => $i['status'] === 'declined'));
t_eq(count($declined), 0,
    'a decline with no decider, date or reason returns to PROPOSED rather than standing as an unexplained '
    . "refusal on a child's record");
t_ok(in_array('recital_decline_unexplained', $kinds5, true), 'raised for somebody to decide again');

$refs = $plan['tables']['referrals'];
t_eq($refs[0]['status'], 'joined',
    'a referral marked rewarded with no amount and no date is carried as JOINED — a reward nothing backs is '
    . 'a promise to a family');
t_ok($refs[0]['currency'] === null, 'and no currency is asserted for a reward that was never paid');

t_group('v2 plan — the compliance record refuses to fabricate compliance');

$brs = $plan['tables']['dp_breaches'];
t_eq(count($brs), 1, 'the breach carries');
t_eq($brs[0]['status'], 'contained',
    'a HIGH-risk breach closed without telling anybody and without a written reason is REOPENED — the '
    . 'honest state, and the one that makes somebody finish it');
t_ok(in_array('breach_closed_incomplete', $kinds5, true), 'and it is raised');
t_eq($brs[0]['discovered_at'], '2026-07-20 09:00:00',
    'the clock runs from AWARENESS (NDPA s.40(2)), not from occurrence');
t_ok($brs[0]['occurred_at'] !== null && $brs[0]['occurred_at'] < $brs[0]['discovered_at'],
    'and occurrence, where known, precedes it');

$dpas = $plan['tables']['dp_assessments'];
t_eq($dpas[0]['decision'], 'proceed_with_controls',
    'a bare "proceed" on HIGH residual risk becomes proceed_with_controls — what the NDPA expects, and '
    . 'what the mitigations column already describes');
t_ok(in_array('dp_assessment_high_risk', $kinds5, true), 'raised for the academy to confirm the controls');

$procs = $plan['tables']['dp_processors'];
t_eq($procs[0]['status'], 'suspended',
    'a live processor outside Nigeria with no stated transfer basis is recorded as SUSPENDED: naming the '
    . 'country records the risk and nothing about how it is answered');
t_ok(in_array('processor_transfer_unstated', $kinds5, true), 'and raised');

$autos = $plan['tables']['automation_rules'];
t_eq(count($autos), 2, 'both automation rules carry');
$never = array_values(array_filter($autos, static fn($a) => (int) $a['run_count'] === 0));
t_eq(count($never), 1, 'including the telemetry of one that has NEVER run');
t_ok($never[0]['last_run_at'] === null,
    'zeroing telemetry on cutover day would hide a rule that has never fired');

$ai = $plan['tables']['ai_runs'][0];
t_ok($ai['error'] !== null, 'a failed AI call says what went wrong, even where v1 recorded nothing');
foreach (['prompt', 'response', 'output', 'completion'] as $forbidden) {
    t_ok(!array_key_exists($forbidden, $ai),
        "and no `$forbidden` travels with it — the prompts contain applicants' words about their children");
}

t_group('v2 plan — the shop, in minor units and in rows');

$prods = v2p_by_id($plan, 'store_products');
t_eq(count($prods), 2, 'both products carry');
t_eq((int) $prods[1]['price_minor'], 2500, '$25.00 becomes 2500 minor units');
t_eq((int) $prods[2]['price_minor'], 829,
    '$8.29 becomes 829 — through string arithmetic. Measured: (int)(8.29 * 100) is 828, so a float '
    . 'multiply loses a kobo on a price the academy set exactly');
t_ok($prods[2]['stock'] === null,
    "v1's -1 stock sentinel becomes NULL: it sorted and summed as a real quantity, so a catalogue-wide "
    . 'stock total came out negative');
t_eq((int) $prods[2]['is_active'], 0,
    'and an instantly-delivered digital product with no file is carried INACTIVE — it cannot be fulfilled, '
    . 'and listing it sells somebody nothing');
t_ok(in_array('store_digital_no_file', $kinds5, true), 'raised so the file can be attached');

$orders = $plan['tables']['store_orders'];
t_eq(count($orders), 1, 'the order carries');
t_eq((int) $orders[0]['total_minor'],
    (int) $orders[0]['subtotal_minor'] + (int) $orders[0]['tax_minor']
    + (int) $orders[0]['shipping_minor'] - (int) $orders[0]['discount_minor'],
    'and its total is its own arithmetic, which is what chk_sorder_total states');
t_eq((int) $orders[0]['discount_minor'], 500,
    "a stated total below its own lines is recorded as a DISCOUNT rather than silently dropped — v1 stored "
    . 'REAL dollars, so the gap was invisible until a customer added it up');
t_ok(in_array('store_total_restated', $kinds5, true), 'and raised');
$lines = $plan['tables']['store_order_items'];
t_eq(count($lines), 1, 'items_json becomes a ROW');
t_eq((int) $lines[0]['quantity'], 2, 'with the quantity');
t_eq((int) $lines[0]['line_total_minor'], 5000, 'and a line total that is unit price times quantity');
t_ok((int) $lines[0]['product_id'] > 0, 'resolved to the product by SKU');
t_eq($lines[0]['name'], 'MAM hoodie (M)',
    'keeping the name AT THE TIME, so a receipt does not change because somebody edited the catalogue');

$notifs = $plan['tables']['notifications'];
t_eq(count($notifs), 1, 'the notification carries');
t_ok($notifs[0]['dedupe_key'] === null,
    'with no dedupe key: v1 had none, and inventing one would suppress the new system\'s first legitimate '
    . 'send of the same notice');

t_group('v2 plan — anonymity, and the promise v1 did not keep');

// The defect: v1's `student_feedback` stored `is_anonymous` BESIDE `student_id`,
// so feedback a learner was told was anonymous named them to anybody who could
// read the table — which includes anybody with a backup.
$lfb = $plan['tables']['learner_feedback'];
t_eq(count($lfb), 2, 'both pieces of feedback carry');
$anon = array_values(array_filter($lfb, static fn($f) => (int) $f['is_anonymous'] === 1));
t_eq(count($anon), 1, 'one was given anonymously');
t_ok($anon[0]['membership_id'] === null,
    'and it arrives with the author REMOVED — v2 has nowhere to put the identity, so the migration is '
    . 'where the promise is made true');
t_ok($anon[0]['cohort_id'] === null,
    'and with the cohort removed too: a cohort of nine is small enough that naming it identifies the author');
t_ok($anon[0]['course_id'] !== null, 'while the course is kept, which is context without identity');
$named = array_values(array_filter($lfb, static fn($f) => (int) $f['is_anonymous'] === 0));
t_ok($named[0]['membership_id'] !== null, 'attributed feedback keeps its author');

// And no migrated survey may claim anonymity it never had.
$svy = $plan['tables']['surveys'][0];
t_eq((int) $svy['is_anonymous'], 0,
    'every migrated survey is NOT anonymous: v1 stored a NOT NULL student_id on every response, so '
    . 'claiming anonymity retroactively would be a promise about data never collected that way');
$sresp = $plan['tables']['survey_responses'][0];
t_ok($sresp['membership_id'] !== null && $sresp['respondent_hash'] === null,
    'so the responses stay attributed, matching how they were collected');

t_group('v2 plan — a staff note stays a staff note');

$tmsgs = $plan['tables']['ticket_messages'];
t_eq(count($tmsgs), 4, "each ticket's body becomes its opening message, plus the two replies");
$internal = array_values(array_filter($tmsgs, static fn($m) => $m['visibility'] === 'internal'));
t_eq(count($internal), 1, 'the internal note is carried as internal');
t_ok(str_contains((string) $internal[0]['body'], 'card was declined'),
    'and it is the right one — defaulting this to family-visible would disclose a private note about a child');
foreach ($tmsgs as $m) {
    t_ok(($m['author_name'] ?? '') !== '',
        'every message names its author in words, so what a family reads survives the account being closed');
}
$anotes = $plan['tables']['application_notes'];
t_eq($anotes[0]['visibility'], 'internal',
    'an admissions note is carried as INTERNAL without exception: a migration must not be the moment a '
    . 'judgement about a child becomes family-visible');

$tickets = v2p_by_id($plan, 'support_tickets');
t_eq($tickets[2]['status'], 'open',
    'a ticket v1 called resolved with no resolution date is carried OPEN — which is what the family '
    . 'experienced');
t_ok($tickets[1]['first_response_due_at'] === null && $tickets[1]['first_response_at'] === null,
    'and no response target is invented: v1 recorded none, and a computed one would claim the academy met '
    . 'a target it never had');

t_group('v2 plan — enquiries, announcements and the suppression list');

$cmsgs = $plan['tables']['contact_messages'];
t_eq(count($cmsgs), 1, 'the answerable enquiry carries');
$unanswerable = array_values(array_filter($plan['rejected'], static fn($r) => $r['table'] === 'messages'));
t_eq(count($unanswerable), 1,
    'one with no address and no phone number is DROPPED — nobody could ever have replied, and it is still '
    . "somebody's personal data");
t_ok($cmsgs[0]['membership_id'] === null,
    'an enquiry is NOT matched to a member by address: two people share a family inbox and one of them is '
    . "a child, so linking the wrong one attaches a stranger's words to a learner's record");

$anns = $plan['tables']['announcements'];
t_eq(count($anns), 2, 'both announcements carry');
$tenantWide = array_values(array_filter($anns, static fn($a) => $a['scope'] === 'tenant'));
t_eq(count($tenantWide), 1,
    'one that resolves to no cohort becomes a TENANT-wide notice rather than an announcement with no readers');

$mkt = $plan['tables']['marketing_contacts'];
t_eq(count($mkt), 2, 'subscribers are CARRIED, not dropped');
$unsub = array_values(array_filter($mkt, static fn($m) => $m['status'] === 'unsubscribed'));
t_eq(count($unsub), 1, 'including the one who asked not to be mailed');
t_ok($unsub[0]['unsubscribed_at'] !== null,
    'with a date, because the row exists to prove the academy stopped — dropping the list would drop the '
    . 'SUPPRESSION list and the next import would mail them again');
t_ok($mkt[0]['consent_at'] !== null && $mkt[0]['consent_evidence'] !== null,
    'and each carries the provenance of its consent, which is what makes it lawful to carry at all');

t_group('v2 plan — the banner and the price are different rows');

// In v1 ONE row held the headline, the background colour and a discount_percent
// that validation.php and store.php read when pricing tuition and orders.
$promos = $plan['tables']['promotions'];
$banners = $plan['tables']['announcement_banners'];
t_eq(count($banners), 2, 'both banners keep their copy');
t_eq(count($promos), 1, 'only the one that carried a discount becomes a promotion');
t_eq((float) $promos[0]['discount_pct'], 10.0, 'with the percentage, in the money layer where it belongs');
t_eq($promos[0]['applies_to'], 'tuition', 'and what it applies to');
$priced = array_values(array_filter($banners, static fn($b) => $b['promotion_id'] !== null));
t_eq(count($priced), 1, 'the banner POINTS AT the promotion, so the headline and the price cannot drift');
foreach ($banners as $b) {
    t_ok(!array_key_exists('discount_pct', $b) && !array_key_exists('bg_color', $b),
        'a banner carries no discount and no hex colour — editing a colour and repricing every family were '
        . 'the same operation in v1, and a colour typed into a marketing form is how a banner ends up at '
        . '2:1 contrast against its own text');
    t_eq($b['tone'], 'notice', 'every migrated banner takes a palette token an admin can change');
}
$halfCta = array_values(array_filter($banners, static fn($b) => $b['cta_label'] === null));
t_eq(count($halfCta), 1,
    "a half-configured call to action drops BOTH halves rather than leaving a labelled button with no "
    . "destination on the academy's front page");

t_group('v2 plan — scholarships and placements');

$schol = $plan['tables']['scholarship_applications'][0];
t_eq((int) $schol['application_id'], (int) v2p_find($plan, 'applications', 'reference', 'APP-0010')['id'],
    'v1 links a scholarship to an application by the REFERENCE the applicant quoted, and it resolves '
    . 'through that string');
t_ok($schol['membership_id'] !== null,
    'the applicant is matched to their own account on the address they applied WITH — safe here, unlike a '
    . 'contact-form enquiry, because that address IS the identity the application was made under');
t_eq($schol['status'], 'awarded', 'the decision is carried');
t_ok($schol['decided_at'] !== null && str_contains((string) $schol['outcome_note'], 'was not kept'),
    'dated from the record and saying the reason was not kept — which is the fact, and what the family '
    . 'would be told if they asked');
t_ok(!array_key_exists('admission_decision_id', $schol),
    'and nothing links it to an admission decision, on either side');

$aud = $plan['tables']['auditer_results'];
t_eq(count($aud), 2, 'both placements carry');
$resolvedPath = array_values(array_filter($aud, static fn($a) => $a['recommended_programme_id'] !== null));
t_eq(count($resolvedPath), 1, 'one pathway resolves to a real programme');
$stalePath = array_values(array_filter($aud, static fn($a) => $a['recommended_programme_id'] === null));
t_eq($stalePath[0]['recommended_pathway'], 'legacy-track',
    'and one that no longer exists keeps the WORD: a code that has gone is still what the placement said, '
    . 'and it must not silently become "no recommendation"');
foreach ($aud as $a) {
    t_ok($a['valid_until'] === null,
        'no expiry is invented — how long a placement stays good is the academy\'s policy, and a computed '
        . 'date would expire real placements on a day nobody chose');
}

$cov4 = v2p_coverage($plan);
t_eq($cov4['feedback']['destination'], 'assessments + assessment_marks',
    "v1's per-submission feedback is a MARK: one marking mechanism, not two");
t_ok(str_contains((string) $cov4['application_drafts']['note'], 'no lawful purpose'),
    'and abandoned wizard state is still accounted for, with the reason it is not carried');

t_group('v2 plan — sessions, and the one assumption only the academy can settle');

$sessions = v2p_by_id($plan, 'class_sessions');
t_eq(count($sessions), 3, 'three sessions carry; the one with no time at all does not');
$noTime = array_values(array_filter($plan['rejected'],
    static fn($r) => $r['table'] === 'class_sessions'));
t_eq(count($noTime), 1, 'a session with no scheduled time is rejected');
t_ok(str_contains((string) $noTime[0]['why'], 'no scheduled time'),
    'because it cannot be attended, reported on or moved: ' . $noTime[0]['why']);

$w1 = v2p_find($plan, 'class_sessions', 'title', 'Week one');
t_eq($w1['scheduled_start_utc'], '2026-02-06 16:00:00.000',
    "v1's single datetime is carried as the UTC INSTANT — the reading v1's own code used, with PHP pinned "
    . 'to UTC, and the reading its stored lateness figures were computed against');
t_eq($w1['scheduled_start_local'], '2026-02-06 17:00:00.000',
    'and the local wall clock is derived from it (Lagos is UTC+1)');
t_eq($w1['timezone'], 'Africa/Lagos', 'stated, so 16:00 stays 16:00 if an offset rule changes');
$kinds3 = array_column($plan['review'], 'kind');
t_ok(in_array('session_timezone_assumption', $kinds3, true),
    'and the assumption is raised ONCE with a count: if those values were typed as Lagos wall-clock times '
    . 'then every migrated session displays an hour early, and only the academy can say');
t_ok(str_contains((string) $w1['notes'], 'Topics:') && str_contains((string) $w1['notes'], 'Outcomes:'),
    "v1's three teaching-note columns are joined into v2's one rather than two of them being dropped");
t_ok((int) $w1['room_id'] > 0, 'a free-text room name that matches a real room resolves to it');

$cancelled = v2p_find($plan, 'class_sessions', 'status', 'cancelled');
t_ok($cancelled['cancellation_reason'] !== null,
    'a cancelled session says why — v1 did not require it, and "cancelled, no reason" is the row a family '
    . 'disputes');
$moved = v2p_find($plan, 'class_sessions', 'title', 'Week two (moved)');
t_eq((int) $moved['rescheduled_from_id'], (int) $cancelled['id'],
    'a replacement session links to the one it replaced, resolved in a second pass once both exist');

$att = $plan['tables']['attendance'];
t_eq(count($att), 1, 'one mark carries');
t_eq((int) $att[0]['minutes_late'], 6, 'with the lateness v1 recorded, STORED and not recomputed');
t_ok((int) $att[0]['enrolment_id'] > 0, 'keyed on the enrolment, because a register belongs to a course');
$wrongCohort = array_values(array_filter($plan['rejected'],
    static fn($r) => $r['table'] === 'session_attendance'));
t_eq(count($wrongCohort), 1,
    'a mark against a session of a cohort the learner was never enrolled in is rejected — inventing an '
    . 'enrolment to hold it would put somebody on a register they were never on');

$mp = $plan['tables']['module_progress'];
t_eq(count($mp), 1, 'two lessons instancing one module become ONE module_progress row');
t_eq($mp[0]['status'], 'completed',
    'and the FURTHEST progress wins: completing the material must not be undone by a second lesson row');
t_eq((int) $mp[0]['percent_complete'], 100, 'keeping the higher percentage');
t_ok($mp[0]['mastery_pct'] === null,
    'with NULL mastery — v1 kept no per-module figure, and 0 would report an unassessed learner as having '
    . 'mastered nothing');

t_group('v2 plan — teaching operations');

t_eq(count($plan['tables']['instructor_availability']), 1,
    'a window that ends before it starts is rejected rather than reversed');
t_eq($plan['tables']['instructor_availability'][0]['timezone'], 'Africa/Lagos',
    'and the academy\'s zone is stated rather than implied — v1 recorded none');

$clock = $plan['tables']['instructor_clockins'];
t_eq(count($clock), 2, 'both clock-ins carry');
$present = array_values(array_filter($clock, static fn($c) => $c['clocked_in_at'] !== null));
t_eq((int) $present[0]['minutes_present'], 66,
    'minutes_present is derived once from the two timestamps and STORED, because v1 stored neither');
$noTimeClock = array_values(array_filter($clock, static fn($c) => $c['clocked_in_at'] === null));
t_eq($noTimeClock[0]['status'], 'absent',
    'an instructor marked present with no time becomes an ABSENCE: present means they were in the room at '
    . 'a stated moment');

$fines = $plan['tables']['instructor_fines'];
t_eq(count($fines), 2, 'both charges carry');
$waived = array_values(array_filter($fines, static fn($f) => $f['status'] === 'waived'));
t_eq(count($waived), 0,
    'a waiver with no decider and no date is NOT carried as waived — an unaccountable waiver of a '
    . "colleague's money is worse than an open question");
t_ok(in_array('fine_resolution_incomplete', $kinds3, true), 'it is carried as pending and raised');
foreach ($fines as $f) {
    t_eq($f['currency'], 'NGN', 'the charge keeps the currency the academy stated');
    t_ok($f['base_amount_minor'] === null,
        'and needs no frozen rate, because that IS the base currency — v1 kept kobo in one column and USD '
        . 'cents in another, so a sum depended on which the reader picked');
}

$fi = $plan['tables']['faculty_invites'][0];
t_ok($fi['nonce_hash'] === null, 'the invitation nonce is not carried, in any form');
t_eq($fi['status'], 'approved',
    'and a live "sent" invitation lands as APPROVED: re-issuing costs an email, while migrating a table of '
    . 'live nonces extends every window that should have closed');
t_ok(!in_array('nonce', array_keys($fi), true), 'there is no column for it to be carried in');

$iapps = $plan['tables']['instructor_applications'];
t_eq(count($iapps), 2, 'both applications carry');
$ghost = array_values(array_filter($iapps, static fn($a) => $a['email'] === 'ghost@example.test'));
t_eq($ghost[0]['status'], 'offered',
    'an application v1 called appointed with no account on that address lands as OFFERED — the decision is '
    . 'real, the account is the missing half');
t_ok(in_array('instructor_appointed_no_account', $kinds3, true), 'and it is raised');
t_ok($ghost[0]['decided_by'] === null && $ghost[0]['decided_at'] !== null,
    'a migrated decision is dated but attributed to nobody, which is what v1 recorded');
t_ok(str_contains((string) $ghost[0]['decision_note'], 'decider not recorded'),
    'and says so on the row rather than in a comment somewhere');

$mparts = $plan['tables']['meeting_participants'];
t_eq(count($mparts), 2, 'both reported participants carry');
$guest = array_values(array_filter($mparts, static fn($m) => $m['membership_id'] === null));
t_eq($guest[0]['display_name'], 'iPhone (2)',
    'a participant who matched nobody keeps the reported NAME — that is the interesting row, not a '
    . 'discardable one');
t_eq((int) $guest[0]['joined_at_is_derived'], 0, 'with no join time, so nothing is claimed about one');
$known = array_values(array_filter($mparts, static fn($m) => $m['membership_id'] !== null));
t_eq((int) $known[0]['joined_at_is_derived'], 1,
    'a join time that could only have come from subtraction is flagged as derived — Google Reports emits '
    . 'the leave time and a duration, and a derived instant presented as an observation loses a dispute');

$sprep = $plan['tables']['session_progress_reports'][0];
t_ok($sprep['due_at'] === null && $sprep['minutes_late'] === null,
    'the two-hour deadline and its lateness are NULL: v1 never wrote them, and 0 would claim every '
    . 'migrated report was on time');
t_ok($sprep['sent_at'] !== null, 'whether the family was sent it is a different fact, and v1 did record that');

$wl = $plan['tables']['cohort_waitlist'];
t_eq(count($wl), 2, 'both queue entries carry');
t_eq(count(array_filter($wl, static fn($w) => $w['status'] !== 'waiting')), 0,
    'an entry marked promoted with no enrolment behind it, and one offered with no record of the family '
    . 'being told, are both carried as WAITING — which is what their position actually was');
t_ok(in_array('waitlist_promotion_unevidenced', $kinds3, true)
    && in_array('waitlist_offer_unnotified', $kinds3, true), 'both are raised');

$lres = $plan['tables']['learning_resources'];
t_eq(count($lres), 2, 'the resource and its second version are both rows');
$v2res = array_values(array_filter($lres, static fn($r) => (int) $r['version'] === 2));
t_eq((int) $v2res[0]['supersedes_id'], (int) $lres[0]['id'],
    'a new version POINTS AT the one it replaces, so a learner given version 1 can still be shown what '
    . 'they were given');
t_ok((int) $lres[0]['cohort_id'] > 0,
    "a v1 resource attached to a legacy CLASS resolves onto the one cohort spine");

t_group('v2 plan — moving a lesson');

$rr = v2p_by_id($plan, 'reschedule_requests');
t_eq(count($rr), 2, 'both requests carry');
t_eq($rr[1]['currency'], 'USD', 'the fee keeps the currency the family was quoted in');
t_eq((int) $rr[1]['base_fee_minor'], 1600000, 'with the base amount computed at the rate v1 froze');
t_eq((int) $rr[1]['payment_id'], (int) v2p_find($plan, 'payments', 'provider_ref', 'ps_100')['id'],
    "the payment is matched through the ledger's own human reference — the string on the receipt");
t_ok($rr[2]['paid_at'] === null && $rr[2]['payment_id'] === null,
    'a fee marked paid against a reference the ledger never heard of reads as UNPAID rather than as '
    . 'settled by nobody');
t_ok(in_array('reschedule_payment_unmatched', $kinds3, true),
    'and is raised, so the family is not asked twice');
$ri = $plan['tables']['reschedule_items'][0];
t_ok($ri['old_scheduled_at'] !== null,
    'an item keeps the time it moved FROM: "what was it before" is the first question asked when a family '
    . 'says a lesson moved and nobody told them');
t_ok($ri['applied_at'] !== null, 'and an applied move states when');
$cov3 = v2p_coverage($plan);
t_eq($cov3['reschedule_events']['destination'], 'lifecycle_events',
    'reschedule events go to v2\'s one stage machine rather than a table of their own');

t_group('v2 plan — the calendar, which is the only authority on a refund');

$term = v2p_find($plan, 'academic_terms', 'code', 'TONI');
t_ok($term !== null, 'the authored term is carried');
t_eq($term['timezone'], 'Africa/Lagos',
    'with the zone its deadlines are adjudicated in — a refund must not depend on whose clock is read');
$sched = $plan['tables']['term_refund_schedule'];
t_eq(count($sched), 6, 'v1 week RANGES expand to one row per week (1–2, 3–5, and week 6 of the overlap)');
$byWeek = [];
foreach ($sched as $r) $byWeek[(int) $r['week_no']] = (int) $r['refund_pct'];
t_eq($byWeek[2], 100, 'week 2 keeps its 100%');
t_eq($byWeek[4], 50, 'week 4 keeps its 50%');
t_eq($byWeek[5], 50,
    'a week covered by two bands keeps the FIRST — taking the last would change a published refund table '
    . 'by row order');
$kinds0 = array_column($plan['review'], 'kind');
t_ok(in_array('refund_week_overlap', $kinds0, true), 'and the overlap is raised for a human');
$census = $plan['tables']['term_census'][0];
t_eq((int) $census['enrolled_count'], 19, 'the census figure is carried verbatim');
t_ok($census['withdrawn_count'] === null,
    'and a figure v1 never counted arrives NULL, not 0 — 0 would state that nobody withdrew');

t_group('v2 plan — a guardian\'s reach is translated, not assumed');

$gs = $plan['tables']['guardianships'];
$full = array_values(array_filter($gs, static fn($g) => $g['scope'] === 'full'));
t_eq(count($full), 1, 'a full-access link keeps its scope');
$narrow = array_values(array_filter($gs, static fn($g) => $g['scope'] === 'billing_only'));
t_eq(count($narrow), 1,
    'an unrecognised scope reads as the NARROWEST — a typo must not widen what a guardian can see');
t_ok(!in_array('billing', array_column($gs, 'scope'), true),
    "v1 spells the narrowest scope 'billing' and v2 spells it 'billing_only'; the value is translated "
    . 'rather than left for a CHECK to refuse on cutover night');

t_group('v2 plan — the ratified report, and its competency FOREIGN KEY');

$reps = v2p_by_id($plan, 'termly_reports');
t_eq(count($reps), 2, 'both reports are carried');
t_eq($reps[1]['status'], 'ratified', 'a report with a mapped ratifier stays ratified');
t_eq($reps[2]['status'], 'moderating',
    'a report whose ratifying account did not map returns to MODERATING — an unattributable ratification '
    . 'is what a family\'s appeal cannot be answered from');
$kinds1 = array_column($plan['review'], 'kind');
t_ok(in_array('report_ratifier_lost', $kinds1, true), 'and it is raised');
t_eq((int) $reps[1]['band_high'] - (int) $reps[1]['band_low'], 9, 'the band is carried as a band');
t_eq((int) $reps[2]['band_low'], 100, 'and a perfect score keeps its (100,100) clamp');

$crit = $plan['tables']['termly_report_criteria'];
t_eq(count($crit), 2, 'two criteria resolve; the unknown code and the duplicate do not');
foreach ($crit as $c) {
    t_ok((int) $c['competency_id'] > 0,
        'every criterion carries a real competency FOREIGN KEY — the upgrade this layer exists for');
}
$insuf = array_values(array_filter($crit, static fn($c) => $c['level'] === 'insufficient'));
t_eq(count($insuf), 1, 'an unevidenced competency is a ROW');
t_ok($insuf[0]['pct'] === null, 'with no percentage — a zero there reports an unassessed beginner as failing');
$lost = array_values(array_filter($plan['rejected'],
    static fn($r) => $r['table'] === 'termly_report_criteria'));
t_eq(count($lost), 1, 'a competency code with no competency in v2 is rejected, not written as an orphan label');
t_ok(str_contains((string) $lost[0]['why'], 'telepathy'), 'naming the code: ' . $lost[0]['why']);
t_ok(in_array('report_criterion_duplicate', $kinds1, true),
    'and a report scoring one competency twice keeps the first rather than failing the load');

t_eq(count($plan['tables']['report_moderations']), 2, 'both moderations are carried');
$anon = array_values(array_filter($plan['tables']['report_moderations'],
    static fn($m) => $m['moderator_membership_id'] === null));
t_eq(count($anon), 1,
    'a moderation whose moderator did not map keeps the ROW: the sample happened, and dropping it would '
    . 'take the report below the moderation floor and make it unratifiable');

$appeals = v2p_by_id($plan, 'mark_appeals');
t_eq($appeals[1]['status'], 'declined',
    'an appeal declined twenty minutes after it was raised is carried — the timestamp-precision bug that '
    . 'used to fail the load outright');
t_eq($appeals[1]['raised_role'], 'guardian', "v1's 'parent' becomes v2's 'guardian': same person, the word the redesign uses");
t_eq($appeals[2]['status'], 'open',
    'an upheld appeal with no outcome note is carried OPEN — the family is still owed an answer');
t_ok($appeals[2]['closed_at'] === null, 'and it carries no closing date it cannot justify');
t_ok(in_array('appeal_outcome_incomplete', $kinds1, true), 'raised, not silently reshaped');

t_group('v2 plan — threads, channels and make-up credits');

t_eq(count($plan['tables']['guardian_thread_participants']), 1,
    'a learner named as a participant in a thread ABOUT them is DROPPED, never coerced into a guardian');
$msgs = $plan['tables']['guardian_thread_messages'];
t_eq(count($msgs), 2, 'both messages are carried');
$orphan = array_values(array_filter($msgs, static fn($m) => $m['author_membership_id'] === null));
t_eq(count($orphan), 1, 'a message whose author did not map keeps the row');
t_eq($orphan[0]['author_name'], 'The Office', 'with the NAME, because that is what a family reads');
$mk = v2p_by_id($plan, 'makeup_credits');
t_eq((int) $mk[1]['counts_against_allowance'], 1, 'a guardian cancellation costs the family a slot');
t_eq((int) $mk[2]['counts_against_allowance'], 0,
    'a goodwill credit v1 charged to the allowance arrives FREE — the allowance limits how often a FAMILY '
    . 'cancels, not how often the academy apologises');

t_group('v2 plan — an invoice states its own arithmetic');

$invs = v2p_by_id($plan, 'invoices');
t_eq(count($invs), 2, 'two invoices load; the dollar one with no frozen rate does not');
$refused = array_values(array_filter($plan['rejected'], static fn($r) => $r['table'] === 'fee_invoices'));
t_eq(count($refused), 1, 'a non-base-currency invoice with no rate is REFUSED, not converted at today\'s rate');
t_ok(str_contains((string) $refused[0]['why'], 'restate what the family was charged'),
    'and the ledger says why: ' . $refused[0]['why']);
$inv1 = $invs[1];
t_eq((int) $inv1['subtotal_minor'], 12000000, 'the gross is the subtotal');
t_eq((int) $inv1['total_minor'],
    (int) $inv1['subtotal_minor'] - (int) $inv1['waiver_minor'] - (int) $inv1['aid_minor']
    - (int) $inv1['sibling_minor'] - (int) $inv1['discount_minor'],
    'and the total is its own arithmetic, which is what chk_inv_total states');
t_eq($inv1['due_at'], '2026-02-08 23:59:59.999',
    'a due DATE becomes the end of that day: nobody is late until the day they were given has passed');
$restated = array_values(array_filter($plan['review'], static fn($r) => $r['kind'] === 'invoice_total_restated'));
t_eq(count($restated), 1, 'an invoice whose stated total disagreed with its own relief is raised');
t_eq((int) $restated[0]['computed'], 5000000, 'with both figures, so a human can see which was sent');

$lines = $plan['tables']['invoice_lines'];
$waived = array_values(array_filter($lines, static fn($l) => (int) $l['waiver_minor'] > 0));
t_eq(count($waived), 1, 'the waiver stays ON the line it applies to');
t_eq($waived[0]['waiver_reason'], 'Sat internally', 'with the reason, which is the fact a parent asks about');

$inst = $plan['tables']['invoice_plan_instalments'];
t_eq(count($inst), 3, 'every instalment is carried');
$paid = array_values(array_filter($inst, static fn($i) => $i['status'] === 'paid'));
$v2pay = (int) v2p_find($plan, 'payments', 'provider_ref', 'ps_100')['id'];
t_eq((int) $paid[0]['payment_id'], $v2pay,
    'the payment an instalment names is REMAPPED to its v2 id — a v1 id copied across is the defect this '
    . 'ETL keeps rediscovering');
$ghost = array_values(array_filter($inst, static fn($i) => (int) $i['seq'] === 2));
t_ok($ghost[0]['payment_id'] === null,
    'and a payment that is not in the export at all arrives NULL, never as a number that resolves to a stranger');

$burs = $plan['tables']['bursary_applications'][0];
t_eq($burs['income_band'], 'band_b', 'a bursary is assessed on a declared income BAND');
foreach (['hardship', 'circumstances', 'case_text'] as $forbidden) {
    t_ok(!array_key_exists($forbidden, $burs), "and carries no `$forbidden` — there is no such column on either side");
}

t_group('v2 plan — academic work lands on an enrolment or not at all');

// Set work: v1 keyed it on a student, v2 on an enrolment. A row naming a class
// the student is not in must be REJECTED, never attached to a nearby enrolment.
$asg = v2p_find($plan, 'assignments', 'title', 'C major, hands together');
t_ok($asg !== null && (int) $asg['enrolment_id'] > 0 && $asg['cohort_id'] === null,
    'an assignment resolves to the learner\'s enrolment, and keeps exactly one target');
t_eq($asg['due_at'], '2026-02-06 23:59:59.999',
    'a v1 due DATE becomes the END of that day — midnight would move every historic deadline a day earlier');
t_eq($asg['due_timezone'], 'Africa/Lagos', 'stated in a zone, so a diaspora learner reads the same Friday');
$wrongClass = array_values(array_filter($plan['rejected'],
    static fn($r) => $r['table'] === 'assignments' && (int) $r['id'] === 2));
t_eq(count($wrongClass), 1, 'work set to a student who is not in that class is rejected, not reattached');
t_ok(str_contains((string) $wrongClass[0]['why'], 'not enrolled'),
    'and the ledger says why, so a human can place it: ' . ($wrongClass[0]['why'] ?? ''));

// The blob becoming rows. This is the point of the migration.
$marks = $plan['tables']['assessment_marks'];
t_eq(count($marks), 3, 'scores_json became one row per criterion, plus the gradebook mark');
$insuff = array_values(array_filter($marks, static fn($m) => (int) $m['is_insufficient'] === 1));
t_eq(count($insuff), 1, 'a criterion in scope with no evidence is a ROW, not a zero and not an absence');
t_ok($insuff[0]['points_awarded'] === null, 'and it carries no award');
$scored = array_values(array_filter($marks, static fn($m) => $m['label'] === 'Score/Notation Accuracy'));
t_eq($scored[0]['points_awarded'], '8.00', 'the awarded points survive');
t_ok($scored[0]['competency_id'] === null,
    'competency_id is NULL on a rubric mark: v1 matched a criterion to a component by LABEL, and a '
    . 'guessed foreign key would make that guess permanent');
t_eq(count(array_filter($marks, static fn($m) => $m['competency_id'] !== null)), 0,
    'NO migrated mark claims a competency: v1\'s gradebook row is typed by an assessment KIND ("final", '
    . '"practical"), which says what the mark was for and not which musical competency it evidences');

// A mark has an author, or it is not a mark.
$assess = $plan['tables']['assessments'];
t_eq(count($assess), 2, 'a rubric review and a gradebook row are both assessments');
foreach ($assess as $a) {
    t_ok($a['status'] === 'draft' || ($a['marker_membership_id'] !== null && $a['marked_at'] !== null),
        'every marked assessment names who marked it and when [' . $a['status'] . ']');
}

t_group('v2 plan — what v1 recorded loosely arrives as a question, not as data');

$results = $plan['tables']['enrolment_results'];
t_eq(count($results), 2, 'both results are carried');
$good = array_values(array_filter($results, static fn($r) => $r['status'] === 'finalised'));
t_eq(count($good), 1, 'the signed one is finalised');
t_eq($good[0]['total_pct'], '78.50', 'with its percentage');
t_ok($good[0]['coverage_evidenced'] === null,
    'and NULL coverage: v1 stored a percentage with nothing saying how much of the syllabus it covered, '
    . 'and "unknown" is a different statement from "all of it"');
$prov = array_values(array_filter($results, static fn($r) => $r['status'] === 'provisional'));
t_eq(count($prov), 1, 'a result v1 called final with no signatory and no outcome stays PROVISIONAL');
t_ok($prov[0]['total_pct'] === null, 'a score off any percentage scale is dropped, not rescaled');
$kinds = array_column($plan['review'], 'kind');
t_ok(in_array('result_off_scale', $kinds, true), 'the off-scale score is raised for a human');
t_ok(in_array('result_unsigned', $kinds, true), 'as is the unsigned finalisation');
t_ok(in_array('criteria_need_competency', $kinds, true),
    'and the criteria a human must map to competencies before the mastery score has rubric evidence');

// A decision missing its decider, its time or (for a rejection) its reason is
// not a decision.
$props = v2p_by_id($plan, 'module_proposals');
$approved = v2p_find($plan, 'module_proposals', 'title', 'Swing feel');
t_eq($approved['status'], 'approved', 'a complete decision is carried as made');
$unclear = v2p_find($plan, 'module_proposals', 'title', 'Unclear week');
t_eq($unclear['status'], 'pending',
    'a rejection with no reason returns to the review queue rather than claiming a decision nobody explained');
t_ok(in_array('proposal_decision_incomplete', $kinds, true), 'and it is raised');

t_group('v2 plan — the teach-back moves from a lesson to a module');

$tbs = $plan['tables']['teachbacks'];
t_eq(count($tbs), 3, 'every teach-back is carried');
$live = array_values(array_filter($tbs, static fn($t) => (int) $t['deleted_seq'] === 0));
t_eq(count($live), 2,
    'two teach-backs on one module keep the first LIVE and soft-delete the second — the record survives '
    . 'and the slot stays free');
$rev = array_values(array_filter($tbs, static fn($t) => $t['status'] === 'reviewed'));
t_eq(count($rev), 1, 'only the one with a reviewer, a time AND a decision counts as reviewed');
t_eq($rev[0]['confidence'], null,
    'a dimension scored 90 on a 5-point scale is DROPPED, not rescaled — inventing a conversion would keep '
    . 'v1\'s habit of averaging two different scales');
t_eq($rev[0]['accuracy'], 4, 'the in-scale dimensions survive');
t_ok((int) $rev[0]['module_id'] > 0, 'and it points at the module its lesson instanced');
$seen = v2p_find($plan, 'teachbacks', 'feedback', 'Seen.');
t_eq($seen['status'], 'submitted', 'a v1 "reviewed" with no decision is not a review');

t_group('v2 plan — rubrics, attachments and practice');

t_eq(count($plan['tables']['rubrics']), 2, 'both rubrics are carried');
$versions = array_column($plan['tables']['rubrics'], 'version');
t_eq(array_sum($versions), 3, 'two rubrics sharing a title become version 1 and version 2');
t_ok(v2p_find($plan, 'rubrics', 'version', 1)['course_id'] !== null,
    "a v1 rubric scoped to a cohort takes that cohort's course, which is what makes it reusable");

$atts = $plan['tables']['attachments'];
// The submission artifact, its extra file, the teach-back recording and the ID
// card photo. Everything a v1 URL column held becomes one mechanism.
t_eq(count($atts), 4, 'every v1 file URL becomes an attachment, whatever column it lived in');
t_eq(count(array_filter($atts, static fn($a) => $a['entity_type'] === 'id_card')), 1,
    "including a card photo — a card cannot point at a file the media proxy does not know about");
foreach ($atts as $a) {
    t_eq($a['storage'], 'external',
        'the bytes were never in the academy\'s own store, and calling it local would promise a file the '
        . 'loader does not have');
}

$tool = $plan['tables']['tool_scores'];
t_eq(count($tool), 1, 'two v1 rows for one person and one tool become one');
t_eq((int) $tool[0]['best_score'], 96, 'keeping the better best-score');
t_eq((int) $tool[0]['plays'], 5, 'and the SUM of the plays, which is what that figure means');

$cert = $plan['tables']['certificates'][0];
t_eq(strlen((string) $cert['verification_code']), 32, 'a certificate carries a verification code');
t_ok($cert['verification_code'] !== $cert['reference'],
    'and it is not the printed reference — a verifier who can read the certificate must not be able to '
    . 'derive the code from it');

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
$tayoMember = (int) v2p_find($plan, 'memberships', 'user_id', $tayo['id'])['id'];
$tayoGuard = array_values(array_filter($plan['tables']['guardianships'],
    static fn($g) => (int) $g['learner_membership_id'] === $tayoMember));
t_eq(count($tayoGuard), 1,
    'the guardian_email resolved to somebody already in the data, so the guardianship is real');
t_ok((int) $tayoGuard[0]['guardian_membership_id'] !== $tayoMember,
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
