<?php
/**
 * MAM Academy — the NDPA 2023 / GAID 2025 compliance layer (schema v84).
 * ─────────────────────────────────────────────────────────────────────────────
 * `datarights.php` already exports and erases a person's data. That is the
 * MACHINERY, and it is not the compliance artefact. What the Nigeria Data
 * Protection Commission asks for is the RECORD: which requests arrived, when,
 * whether they were answered in time, what is held and on what lawful basis,
 * how long it is kept, who else it is given to, and what happened when something
 * went wrong. None of that existed. An admin typed an email and downloaded a
 * bundle, and no trace of the request survived.
 *
 * ── The design decision that shapes this whole file ─────────────────────────
 * The record of processing activities (ROPA) and the retention schedule are
 * **code registries here**, not rows in a table with a CRUD form. A register
 * that describes what the software does must be DERIVED from the software: free
 * text in a table drifts from the code the day a feature ships, and a drifted
 * register is worse than a missing one, because it is a false assurance signed
 * by somebody. So each purpose names the tables it actually touches, each
 * retention rule names a real table and date column, and `test_dataprotection.php`
 * asserts those names against the live schema. A stale register fails CI.
 *
 * The academy's own additions — a review date, a note, a LENGTHENED retention
 * period where tax law demands it — live in `dp_register_notes` on top.
 *
 * ── What is deliberately NOT automated ──────────────────────────────────────
 * Registering with the NDPC, appointing a DPO and filing a Compliance Audit
 * Return are acts of the academy, not of a PHP file. What the code can do — and
 * nothing else can — is COUNT: the registration tier turns on how many data
 * subjects are processed in a six-month window, and only this system knows that
 * number. `mam_dp_tier()` computes it so the obligation is a measurement rather
 * than a diary note somebody forgot.
 *
 * ── Legal provenance, and its shelf life ────────────────────────────────────
 * Every day-count below carries its source in a comment and a verified-on date.
 * These are the facts in this file most likely to have moved: GAID 2025 took
 * effect 19 September 2025, the tier thresholds were restated in the NDPC's
 * registration guidance, and the 2025 audit-year filing deadline was extended
 * once already. Re-check before relying on a number, and change the constant
 * rather than the code around it.
 */
declare(strict_types=1);

/**
 * Statutory / directive deadlines, in the units the instrument states them.
 *
 * Verified 2026-08-17 against secondary sources (the NDPC's own site is not
 * reachable from this environment, so these are corroborated commentary, not
 * primary text — treat as needing counsel's confirmation before enforcement):
 *
 *  - `breach_ndpc_hours` = 72. NDPA 2023 s.40(2): notify the Commission within
 *    72 hours of BECOMING AWARE. Phased reporting is permitted where supplying
 *    everything at once is impossible.
 *  - `breach_subject` — s.40(3) says "immediately" where the breach is likely to
 *    result in high risk to the data subject's rights and freedoms, so there is
 *    no day count to hold; the register asks whether it was done and, if not,
 *    why.
 *  - `snag_ack_days` = 7, `snag_close_days` = 30. GAID 2025's Standard Notice to
 *    Address Grievance: acknowledge within 7 days, resolve within 30.
 *  - `request_days` = 30. ⚠️ **This one is not statutory.** The NDPA itself fixes
 *    no response deadline for a data-subject request (the repealed NDPR did).
 *    Thirty days is the settled professional expectation and what GAID's
 *    grievance clock implies, so it is the shipped default — but it is a POLICY
 *    choice the academy is making, which is why it is overridable in settings
 *    and why the admin screen labels it as the academy's own commitment rather
 *    than the law's.
 */
const MAM_DP_DEADLINES = [
    'breach_ndpc_hours' => 72,
    'snag_ack_days'     => 7,
    'snag_close_days'   => 30,
    'request_days'      => 30,
];

/**
 * NDPA 2023 s.25 lawful bases. A constant, not a setting: an academy able to
 * invent a basis would have a register that documents nothing.
 */
const MAM_DP_BASES = [
    'consent'          => 'Consent of the data subject',
    'contract'         => 'Performance of a contract with the data subject',
    'legal_obligation' => 'Compliance with a legal obligation',
    'vital_interest'   => 'Protection of the vital interests of a person',
    'public_interest'  => 'Public interest or the exercise of lawful authority',
    'legitimate'       => 'Legitimate interests of the controller',
];

/**
 * Registration tiers, by distinct data subjects processed in a rolling SIX
 * MONTHS — that window is part of the definition, not a reporting convenience,
 * so a naive `COUNT(*) FROM customers` over all time overstates the tier.
 *
 * Verified 2026-08-17 (secondary sources): a data controller or processor of
 * major importance ("DCPMI") processes the personal data of more than 200
 * Nigerian data subjects. Above 200 and below 1,000 in six months is the
 * Ordinary-High Level, which registers and RENEWS ANNUALLY but does not file
 * Compliance Audit Returns; the Ultra-High and Extra-High levels above that do
 * file, on or before 31 March each year.
 *
 * `car` is therefore the field that actually matters to an academy of MAM's
 * size, and it is false at the bottom two tiers.
 */
const MAM_DP_TIERS = [
    ['key' => 'below',  'min' => 0,    'max' => 200,  'label' => 'Below the DCPMI threshold', 'register' => false, 'car' => false],
    ['key' => 'ohl',    'min' => 201,  'max' => 1000, 'label' => 'Ordinary-High Level',       'register' => true,  'car' => false],
    ['key' => 'uhl',    'min' => 1001, 'max' => 5000, 'label' => 'Ultra-High Level',          'register' => true,  'car' => true],
    ['key' => 'ehl',    'min' => 5001, 'max' => null, 'label' => 'Extra-High Level',          'register' => true,  'car' => true],
];

/** Request kinds we log, and whether the SNAG clock or the request clock applies. */
const MAM_DP_REQUEST_KINDS = [
    'access'           => 'Access / copy of my data',
    'erasure'          => 'Erasure',
    'rectification'    => 'Correction',
    'portability'      => 'Portability',
    'objection'        => 'Objection to processing',
    'restriction'      => 'Restriction of processing',
    'withdraw_consent' => 'Withdrawal of consent',
    'snag'             => 'Grievance (SNAG)',
];

/** How the retention sweep behaves. Mirrors the safeguarding gate deliberately. */
const MAM_DP_RETENTION_MODES = ['off', 'report', 'enforce'];

// ─────────────────────────────────────────────────────────────────────────────
// The code registries: what we process, and how long we keep it.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The record of processing activities.
 *
 * `tables` is the load-bearing field — it ties each declared purpose to the
 * storage that actually implements it, which is what lets the test suite catch a
 * register that has drifted from the code. `special` marks processing of
 * sensitive personal data (health, biometrics, a child's data), which is what
 * drives the DPIA question.
 */
function mam_dp_purposes(): array {
    return [
        'admissions' => [
            'purpose'  => 'Assess applications and admit students',
            'basis'    => 'contract',
            'subjects' => 'Applicants, guardians of applicant children',
            'data'     => 'Name, email, phone, address, date of birth, age range, musical experience, goals',
            'tables'   => ['customers', 'applications', 'application_events', 'application_notes'],
            'special'  => true,   // a child's data
            'recipients' => 'None outside the academy',
            'retention_ref' => 'applications',
        ],
        'teaching' => [
            'purpose'  => 'Deliver tuition, mark work and record progress',
            'basis'    => 'contract',
            'subjects' => 'Enrolled students',
            'data'     => 'Enrolment, attendance, marks, submissions, teacher feedback, practice logs',
            'tables'   => ['course_enrollments', 'enrollments', 'session_attendance', 'attendance', 'results', 'submissions', 'practice_logs', 'feedback'],
            'special'  => true,
            'recipients' => 'The student\'s own instructor and linked guardian',
            'retention_ref' => 'academic_record',
        ],
        'guardian_access' => [
            'purpose'  => 'Give a parent or guardian visibility of their child\'s learning',
            'basis'    => 'contract',
            'subjects' => 'Guardians and their linked children',
            'data'     => 'Link between guardian and student, consent flags',
            'tables'   => ['parent_links', 'student_consents'],
            'special'  => true,
            'recipients' => 'The linked guardian only',
            'retention_ref' => 'academic_record',
        ],
        'payments' => [
            'purpose'  => 'Take tuition and store payments, and keep accounting records',
            'basis'    => 'contract',
            'subjects' => 'Paying customers and guardians',
            'data'     => 'Name, email, amount, currency, provider reference. No card data is ever held.',
            'tables'   => ['payments', 'store_orders', 'wallet_ledger'],
            'special'  => false,
            'recipients' => 'Paystack, Flutterwave (payment processing)',
            'retention_ref' => 'financial',
        ],
        'safeguarding' => [
            'purpose'  => 'Verify that staff teaching children are cleared to do so',
            'basis'    => 'legal_obligation',
            'subjects' => 'Instructors and prospective instructors',
            'data'     => 'Clearance type, issuing body, reference, dates, verifier. No certificate content and no offence data.',
            'tables'   => ['vetting_records'],
            'special'  => true,
            'recipients' => 'None; evidence is filed offline',
            'retention_ref' => 'vetting',
        ],
        'identity_cards' => [
            'purpose'  => 'Issue student identity cards and record door scans',
            'basis'    => 'legitimate',
            'subjects' => 'Enrolled students, summer participants',
            'data'     => 'Photograph, name, serial, scan timestamps',
            'tables'   => ['id_cards', 'card_scans'],
            'special'  => true,   // a photograph of a child
            'recipients' => 'Cloudinary, when configured, for photo hosting',
            'retention_ref' => 'card_photos',
        ],
        'communications' => [
            'purpose'  => 'Answer enquiries and send transactional email',
            'basis'    => 'legitimate',
            'subjects' => 'Enquirers, applicants, students, guardians, instructors',
            'data'     => 'Name, email, phone, message text, delivery outcome',
            'tables'   => ['messages', 'mail_log', 'notifications', 'support_tickets', 'ticket_messages'],
            'special'  => false,
            'recipients' => 'The configured SMTP provider',
            'retention_ref' => 'enquiries',
        ],
        'marketing' => [
            'purpose'  => 'Send the newsletter to people who asked for it',
            'basis'    => 'consent',
            'subjects' => 'Newsletter subscribers',
            'data'     => 'Email, source, subscription state',
            'tables'   => ['newsletter'],
            'special'  => false,
            'recipients' => 'The configured SMTP provider',
            'retention_ref' => 'newsletter',
        ],
        'recordings' => [
            'purpose'  => 'Hold performance recordings and recital media',
            'basis'    => 'consent',
            'subjects' => 'Performing students',
            'data'     => 'Audio and video of a named student',
            'tables'   => ['submissions', 'recital_items'],
            'special'  => true,
            'recipients' => 'Google Drive, when configured',
            'retention_ref' => 'recordings',
        ],
        'security' => [
            'purpose'  => 'Authenticate users, rate-limit abuse and keep an audit trail',
            'basis'    => 'legal_obligation',
            'subjects' => 'Everyone with an account',
            'data'     => 'Hashed session tokens, IP address, action log',
            'tables'   => ['sessions', 'rate_limits', 'audit_log', 'webhook_events'],
            'special'  => false,
            'recipients' => 'None',
            'retention_ref' => 'audit',
        ],
    ];
}

/**
 * The retention schedule.
 *
 * Each entry names a real table and a real date column — checked against the
 * live schema by the test suite, because a schedule pointing at a table nobody
 * has is a policy document, not a control.
 *
 * `action` is the constrained field:
 *   - `delete`    — remove the row. **Only legal on a LEAF table** (nothing
 *                   references it). The suite asserts this against the live
 *                   foreign-key graph, because a cascade through an enrolment
 *                   would destroy academic history to tidy up a note.
 *   - `anonymise` — null the named `pii` columns, keep the row and its links.
 *   - `review`    — surface it for a human. The right action where the decision
 *                   is legal rather than mechanical.
 */
function mam_dp_retention(): array {
    return [
        'enquiries' => [
            'label'  => 'Contact-form enquiries',
            'table'  => 'messages',
            'date'   => 'created_at',
            'days'   => 730,
            'basis'  => 'Legitimate interest — kept long enough to handle a returning enquiry, then of no further use',
            'action' => 'delete',
        ],
        'newsletter' => [
            'label'  => 'Unsubscribed newsletter records',
            'table'  => 'newsletter',
            'date'   => 'created_at',
            'days'   => 1095,
            'basis'  => 'Consent — an unsubscribe record is kept only to honour the unsubscribe',
            'action' => 'review',
        ],
        'sessions' => [
            'label'  => 'Expired login sessions',
            'table'  => 'sessions',
            'date'   => 'created_at',
            'days'   => 90,
            'basis'  => 'Security — a session token has no purpose once expired',
            'action' => 'delete',
        ],
        'rate_limits' => [
            'label'  => 'Rate-limit counters (hold an IP address)',
            'table'  => 'rate_limits',
            'date'   => 'reset_at',
            'days'   => 30,
            'basis'  => 'Security — the counter is spent once its window has passed',
            'action' => 'delete',
        ],
        'webhooks' => [
            'label'  => 'Payment webhook payloads',
            'table'  => 'webhook_events',
            'date'   => 'received_at',
            'days'   => 365,
            'basis'  => 'Legal obligation — kept for one year to reconcile a disputed charge',
            'action' => 'delete',
        ],
        'mail_log' => [
            'label'  => 'Outbound email log',
            'table'  => 'mail_log',
            'date'   => 'created_at',
            'days'   => 365,
            'basis'  => 'Legitimate interest — proof of delivery for a year',
            'action' => 'delete',
        ],
        'applications' => [
            'label'  => 'Applications that never became an enrolment',
            'table'  => 'applications',
            'date'   => 'created_at',
            'days'   => 1095,
            'basis'  => 'Contract — an unsuccessful application is kept three years for dispute and re-application, then anonymised',
            'action' => 'review',
        ],
        'financial' => [
            'label'  => 'Payments and accounting records',
            'table'  => 'payments',
            'date'   => 'created_at',
            'days'   => 2555,   // seven years
            'basis'  => 'Legal obligation — tax and accounting retention. Confirm the exact period with the academy\'s accountant before acting on it.',
            'action' => 'review',
        ],
        'academic_record' => [
            'label'  => 'Academic records, marks and certificates',
            'table'  => 'course_enrollments',
            'date'   => 'created_at',
            'days'   => 3650,   // ten years
            'basis'  => 'Legitimate interest — a former student may need a transcript years later. Never deleted on a timer.',
            'action' => 'review',
        ],
        'card_photos' => [
            'label'  => 'Identity-card photographs',
            'table'  => 'id_cards',
            'date'   => 'created_at',
            'days'   => 1095,
            'basis'  => 'Legitimate interest — a photograph outlives its card only as long as re-issue plausibly needs it',
            'action' => 'review',
            'pii'    => ['photo_url'],
        ],
        'vetting' => [
            'label'  => 'Instructor clearance records',
            'table'  => 'vetting_records',
            'date'   => 'created_at',
            'days'   => 2555,
            'basis'  => 'Legal obligation — safer-recruitment evidence is kept for the period the academy can be asked to account for a placement',
            'action' => 'review',
        ],
        'recordings' => [
            'label'  => 'Student performance recordings',
            'table'  => 'submissions',
            'date'   => 'created_at',
            'days'   => 1825,
            'basis'  => 'Consent — a recording of a child is kept only while the consent behind it stands',
            'action' => 'review',
        ],
        'audit' => [
            'label'  => 'Audit log',
            'table'  => 'audit_log',
            'date'   => 'created_at',
            'days'   => 2555,
            'basis'  => 'Legal obligation — the audit trail is the evidence that everything else here was done properly',
            'action' => 'review',
        ],
        'card_scans' => [
            'label'  => 'Door scan events',
            'table'  => 'card_scans',
            'date'   => 'scanned_at',
            'days'   => 365,
            'basis'  => 'Legitimate interest — attendance is recorded elsewhere; the raw scan is transient',
            'action' => 'delete',
        ],
    ];
}

/**
 * The processors the CODE can call, with a live "is it actually configured"
 * probe. An aspirational register is the commonest kind: it lists the vendor
 * somebody once considered and omits the SMTP host that has been carrying every
 * password-reset email for a year. The probe is what stops that.
 */
function mam_dp_processor_registry(): array {
    return [
        'paystack' => [
            'name' => 'Paystack', 'country' => 'Nigeria',
            'purpose' => 'Card and bank payment processing',
            'configured' => static fn(): bool => trim((string) mam_env('PAYSTACK_SECRET_KEY', '')) !== '',
        ],
        'flutterwave' => [
            'name' => 'Flutterwave', 'country' => 'Nigeria',
            'purpose' => 'Card and bank payment processing',
            'configured' => static fn(): bool => trim((string) mam_env('FLW_SECRET_KEY', '')) !== '',
        ],
        'smtp' => [
            'name' => 'Email delivery (SMTP host)', 'country' => 'Depends on the configured host',
            'purpose' => 'Transactional and academic email',
            'configured' => static fn(): bool => trim((string) mam_env('MAIL_HOST', '')) !== '',
        ],
        'cloudinary' => [
            'name' => 'Cloudinary', 'country' => 'United States',
            'purpose' => 'Hosting registration photographs',
            'configured' => static fn(): bool => trim((string) mam_setting('cloudinary_cloud_name', '')) !== '',
        ],
        'google_drive' => [
            'name' => 'Google (Apps Script / Drive)', 'country' => 'United States',
            'purpose' => 'Storing lesson resources and student recordings',
            'configured' => static fn(): bool => trim((string) mam_setting('drive_webapp_url', '')) !== '',
        ],
        'google_meet' => [
            'name' => 'Google Workspace (Meet reports)', 'country' => 'United States',
            'purpose' => 'Reconciling online-session attendance',
            'configured' => static fn(): bool => trim((string) mam_setting('google_meet_sa_json', '')) !== '',
        ],
        'cloudflare' => [
            'name' => 'Cloudflare', 'country' => 'United States',
            'purpose' => 'CDN and DDoS protection; sees request IP addresses',
            'configured' => static fn(): bool => true,   // the site sits behind it by deployment
        ],
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Small shared helpers.
// ─────────────────────────────────────────────────────────────────────────────

/** Now, as a comparable timestamp string. */
function mam_dp_now(): string { return date('Y-m-d H:i:s'); }

/** The academy's chosen response commitment for a rights request, in days. */
function mam_dp_request_days(): int {
    $d = (int) mam_setting_or_default('dp_request_days', (string) MAM_DP_DEADLINES['request_days']);
    return $d > 0 ? $d : MAM_DP_DEADLINES['request_days'];
}

/** How the retention sweep behaves: off | report | enforce. */
function mam_dp_retention_mode(): string {
    $m = strtolower(trim((string) mam_setting_or_default('dp_retention_mode', 'report')));
    return in_array($m, MAM_DP_RETENTION_MODES, true) ? $m : 'report';
}

/** Best-effort scalar query; a missing table reads as null rather than throwing. */
function mam_dp_val(string $sql, array $args = []) {
    try { $r = mam_db_row($sql, $args); return $r ? array_values($r)[0] : null; }
    catch (\Throwable $e) { return null; }
}

/** Best-effort row set. */
function mam_dp_rows(string $sql, array $args = []): array {
    try { return mam_db_all($sql, $args); } catch (\Throwable $e) { return []; }
}

/**
 * The academy's annotations for one registry entry, or an empty shape.
 * Keyed on (kind, ref_key) — the UNIQUE index means annotating twice edits.
 */
function mam_dp_note(string $kind, string $refKey): array {
    $r = mam_dp_rows("SELECT * FROM dp_register_notes WHERE kind = ? AND ref_key = ?", [$kind, $refKey]);
    return $r[0] ?? [];
}

/**
 * Record an annotation. A retention override may only LENGTHEN the shipped
 * period: shortening it would delete records the schedule says there is a
 * lawful basis to hold, on the strength of somebody typing a smaller number
 * into a form. Returns the period actually stored.
 */
function mam_dp_note_save(string $kind, string $refKey, array $in, ?int $by = null): array {
    if (!in_array($kind, ['purpose', 'retention'], true)) throw new \RuntimeException('bad_kind');
    $registry = $kind === 'retention' ? mam_dp_retention() : mam_dp_purposes();
    if (!isset($registry[$refKey])) throw new \RuntimeException('unknown_ref');

    $keep = null;
    $clamped = false;
    if ($kind === 'retention' && isset($in['keep_days']) && $in['keep_days'] !== '') {
        $keep = max(0, (int) $in['keep_days']);
        $shipped = (int) $registry[$refKey]['days'];
        if ($keep < $shipped) { $keep = $shipped; $clamped = true; }
    }

    $data = [
        'kind'        => $kind,
        'ref_key'     => $refKey,
        'keep_days'   => $keep,
        'notes'       => ($in['notes'] ?? '') !== '' ? (string) $in['notes'] : null,
        'reviewed_on' => ($in['reviewed_on'] ?? '') !== '' ? (string) $in['reviewed_on'] : null,
        'reviewed_by' => $by ?: null,
        'updated_at'  => mam_dp_now(),
    ];
    $existing = mam_dp_note($kind, $refKey);
    if ($existing) {
        unset($data['kind'], $data['ref_key']);
        mam_db_update('dp_register_notes', $data, ['id' => (int) $existing['id']]);
        $id = (int) $existing['id'];
    } else {
        $id = mam_db_insert('dp_register_notes', $data);
    }
    if (function_exists('mam_audit')) mam_audit('dp_note_save', $kind, $id, ['ref' => $refKey, 'keep_days' => $keep, 'clamped' => $clamped]);
    return ['id' => $id, 'keep_days' => $keep, 'clamped' => $clamped];
}

// ─────────────────────────────────────────────────────────────────────────────
// Registration tier — the one obligation the code can actually measure.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Distinct data subjects processed in a rolling window (default six months, the
 * window the tier definition uses).
 *
 * Deduplicated by lower-cased email ACROSS the four places a person can appear,
 * because a guardian who enquired, applied and subscribed is one data subject
 * and counting them three times would inflate MAM into a filing tier it does
 * not belong in. Rows with no email cannot be deduplicated and are counted
 * separately, disclosed rather than folded in.
 */
function mam_dp_subject_count(int $months = 6): array {
    $since = date('Y-m-d H:i:s', strtotime('-' . max(1, $months) . ' months'));
    $emails = [];
    $sources = [
        'customers'  => "SELECT LOWER(email) AS e FROM customers  WHERE created_at >= ?",
        'users'      => "SELECT LOWER(email) AS e FROM users      WHERE created_at >= ?",
        'newsletter' => "SELECT LOWER(email) AS e FROM newsletter WHERE created_at >= ?",
        'messages'   => "SELECT LOWER(email) AS e FROM messages   WHERE created_at >= ?",
    ];
    $per = [];
    $noEmail = 0;
    foreach ($sources as $key => $sql) {
        $rows = mam_dp_rows($sql, [$since]);
        $per[$key] = count($rows);
        foreach ($rows as $r) {
            $e = trim((string) ($r['e'] ?? ''));
            if ($e === '') { $noEmail++; continue; }
            $emails[$e] = true;
        }
    }
    return [
        'window_months' => $months,
        'since'         => $since,
        'distinct'      => count($emails),
        'without_email' => $noEmail,
        'per_source'    => $per,
    ];
}

/** The tier a subject count falls in. */
function mam_dp_tier_for(int $count): array {
    foreach (MAM_DP_TIERS as $t) {
        if ($count >= $t['min'] && ($t['max'] === null || $count <= $t['max'])) return $t;
    }
    return MAM_DP_TIERS[count(MAM_DP_TIERS) - 1];
}

/**
 * The live registration position: how many subjects, which tier, and what that
 * tier actually requires. `headroom` is the number of further subjects before
 * the obligation changes — the figure worth watching, since the academy crosses
 * a threshold by growing, not by deciding to.
 */
function mam_dp_tier(int $months = 6): array {
    $c = mam_dp_subject_count($months);
    $tier = mam_dp_tier_for((int) $c['distinct']);
    $headroom = $tier['max'] === null ? null : max(0, (int) $tier['max'] - (int) $c['distinct'] + 1);
    return [
        'count'    => (int) $c['distinct'],
        'measured' => $c,
        'tier'     => $tier,
        'headroom' => $headroom,
        'must_register' => (bool) $tier['register'],
        'must_file_car' => (bool) $tier['car'],
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Rights requests — the log with the clock.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Log a request. `received_at` defaults to now but is deliberately settable:
 * a request that arrived by email on Monday and was logged on Thursday is three
 * days into its clock, and a log that pretends otherwise is worse than no log.
 */
function mam_dp_request_open(array $in, ?int $by = null): int {
    $kind = (string) ($in['kind'] ?? '');
    if (!isset(MAM_DP_REQUEST_KINDS[$kind])) throw new \RuntimeException('unknown_kind');
    $email = strtolower(trim((string) ($in['subject_email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('invalid_email');

    $received = trim((string) ($in['received_at'] ?? '')) ?: mam_dp_now();
    $uid = (int) ($in['subject_user_id'] ?? 0);
    if (!$uid) {
        $u = mam_dp_rows("SELECT id FROM users WHERE LOWER(email) = ?", [$email]);
        $uid = (int) ($u[0]['id'] ?? 0);
    }

    $id = mam_db_insert('dp_requests', [
        'kind'            => $kind,
        'subject_email'   => $email,
        'subject_user_id' => $uid ?: null,
        'channel'         => trim((string) ($in['channel'] ?? 'email')) ?: 'email',
        'received_at'     => $received,
        'detail'          => ($in['detail'] ?? '') !== '' ? (string) $in['detail'] : null,
        'handled_by'      => $by ?: null,
        'created_at'      => mam_dp_now(),
    ]);
    if (function_exists('mam_audit')) mam_audit('dp_request_open', 'dp_request', $id, ['kind' => $kind, 'received_at' => $received]);
    return $id;
}

/** Stamp the acknowledgement. Idempotent — the first acknowledgement stands. */
function mam_dp_request_acknowledge(int $id, ?int $by = null, ?string $at = null): bool {
    $r = mam_dp_rows("SELECT * FROM dp_requests WHERE id = ?", [$id]);
    if (!$r) return false;
    if (!empty($r[0]['acknowledged_at'])) return true;
    mam_db_update('dp_requests', [
        'acknowledged_at' => $at ?: mam_dp_now(),
        'handled_by'      => $by ?: ($r[0]['handled_by'] ?? null),
    ], ['id' => $id]);
    if (function_exists('mam_audit')) mam_audit('dp_request_ack', 'dp_request', $id, []);
    return true;
}

/**
 * Close a request with an outcome. A refusal must carry a reason — the schema
 * enforces it too, but failing here gives the admin a message instead of a
 * constraint violation.
 */
function mam_dp_request_close(int $id, string $outcome, array $in = [], ?int $by = null): bool {
    $ok = ['fulfilled', 'partially_fulfilled', 'refused', 'withdrawn'];
    if (!in_array($outcome, $ok, true)) throw new \RuntimeException('bad_outcome');
    $reason = trim((string) ($in['refusal_reason'] ?? ''));
    if ($outcome === 'refused' && $reason === '') throw new \RuntimeException('reason_required');

    $r = mam_dp_rows("SELECT * FROM dp_requests WHERE id = ?", [$id]);
    if (!$r) return false;
    $at = trim((string) ($in['closed_at'] ?? '')) ?: mam_dp_now();
    if ($at < (string) $r[0]['received_at']) $at = (string) $r[0]['received_at'];

    // A closing note appends to whatever the request already said rather than
    // replacing it — the original description of what was asked for is part of
    // the record, and overwriting it loses the request in the answer.
    $detail = (string) ($r[0]['detail'] ?? '');
    $add = trim((string) ($in['detail'] ?? ''));
    if ($add !== '') $detail = $detail === '' ? $add : ($detail . "\n" . $add);

    mam_db_update('dp_requests', [
        'closed_at'       => $at,
        'outcome'         => $outcome,
        'detail'          => $detail !== '' ? $detail : null,
        'refusal_reason'  => $reason !== '' ? $reason : null,
        // Closing without an acknowledgement is answering it, which acknowledges it.
        'acknowledged_at' => $r[0]['acknowledged_at'] ?: $at,
        'handled_by'      => $by ?: ($r[0]['handled_by'] ?? null),
    ], ['id' => $id]);
    if (function_exists('mam_audit')) mam_audit('dp_request_close', 'dp_request', $id, ['outcome' => $outcome]);
    return true;
}

/**
 * The derived clock for one request. DERIVED, never stored — the same rule as
 * ID-card expiry and vetting validity: a request that falls due at midnight must
 * read as overdue on the next page load, not after the next cron run.
 *
 * A SNAG carries GAID's two deadlines (acknowledge in 7, resolve in 30); every
 * other kind carries the academy's single response commitment.
 */
function mam_dp_request_clock(array $r, ?string $now = null): array {
    $now = $now ?: mam_dp_now();
    $isSnag = ($r['kind'] ?? '') === 'snag';
    $recv = (string) ($r['received_at'] ?? '');
    $ackDays   = $isSnag ? MAM_DP_DEADLINES['snag_ack_days'] : null;
    $closeDays = $isSnag ? MAM_DP_DEADLINES['snag_close_days'] : mam_dp_request_days();

    $ackDue   = $ackDays === null ? null : date('Y-m-d H:i:s', strtotime($recv . ' +' . $ackDays . ' days'));
    $closeDue = date('Y-m-d H:i:s', strtotime($recv . ' +' . $closeDays . ' days'));

    $ackAt   = $r['acknowledged_at'] ?? null;
    $closeAt = $r['closed_at'] ?? null;

    // "Late" is judged against when it was DONE where it has been done, and
    // against now where it has not. Comparing a closed request to now would
    // turn every historic record overdue as time passed.
    $ackLate   = $ackDue !== null && ($ackAt ? $ackAt > $ackDue : $now > $ackDue);
    $closeLate = $closeAt ? $closeAt > $closeDue : $now > $closeDue;

    $daysLeft = $closeAt ? null : (int) floor((strtotime($closeDue) - strtotime($now)) / 86400);

    return [
        'ack_due_at'   => $ackDue,
        'close_due_at' => $closeDue,
        'ack_late'     => $ackLate,
        'close_late'   => $closeLate,
        'open'         => !$closeAt,
        'days_left'    => $daysLeft,
        'state'        => $closeAt ? ($closeLate ? 'closed_late' : 'closed')
                                  : ($closeLate ? 'overdue' : (($daysLeft !== null && $daysLeft <= 3) ? 'due_soon' : 'open')),
    ];
}

/** Requests, newest first, each decorated with its clock. `$openOnly` filters. */
function mam_dp_requests(bool $openOnly = false, int $limit = 200): array {
    $sql = "SELECT r.*, u.name AS handler_name FROM dp_requests r LEFT JOIN users u ON u.id = r.handled_by"
         . ($openOnly ? " WHERE r.closed_at IS NULL" : "")
         . " ORDER BY r.received_at DESC, r.id DESC LIMIT " . max(1, min(1000, $limit));
    $out = [];
    foreach (mam_dp_rows($sql) as $r) { $r['clock'] = mam_dp_request_clock($r); $out[] = $r; }
    return $out;
}

/**
 * Fulfil an access request by attaching the export bundle's shape — not the
 * bundle itself. The bundle is the subject's whole record; storing a copy of it
 * against the request would duplicate every field this layer exists to control.
 * What the log needs is that an export was produced and what it contained.
 */
function mam_dp_request_fulfil_access(int $id, ?int $by = null): array {
    $r = mam_dp_rows("SELECT * FROM dp_requests WHERE id = ?", [$id]);
    if (!$r) return ['ok' => false, 'error' => 'not_found'];
    $email = (string) $r[0]['subject_email'];
    if (!function_exists('mam_datarights_export')) return ['ok' => false, 'error' => 'unavailable'];
    $bundle = mam_datarights_export($email);
    if (!$bundle) return ['ok' => false, 'error' => 'no_data'];

    $sections = [];
    foreach ($bundle as $k => $v) { if (is_array($v)) $sections[$k] = count($v); }
    mam_dp_request_close($id, 'fulfilled', [
        'detail' => 'Export produced: ' . json_encode($sections),
    ], $by);
    return ['ok' => true, 'sections' => $sections];
}

// ─────────────────────────────────────────────────────────────────────────────
// Breach register — the 72-hour clock.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Record a breach. `discovered_at` is the statutory trigger and is required;
 * `occurred_at` is optional because it is frequently unknown at the point of
 * discovery, and demanding it would push somebody into guessing on the record.
 */
function mam_dp_breach_record(array $in, ?int $by = null): int {
    $disc = trim((string) ($in['discovered_at'] ?? '')) ?: mam_dp_now();
    $occ  = trim((string) ($in['occurred_at'] ?? ''));
    if ($occ !== '' && $occ > $disc) throw new \RuntimeException('occurred_after_discovered');
    $nature = trim((string) ($in['nature'] ?? ''));
    $cats   = trim((string) ($in['data_categories'] ?? ''));
    if ($nature === '' || $cats === '') throw new \RuntimeException('nature_required');
    $risk = (string) ($in['risk_level'] ?? 'unknown');
    if (!in_array($risk, ['unknown', 'low', 'medium', 'high'], true)) $risk = 'unknown';

    $ref = trim((string) ($in['reference'] ?? ''));
    if ($ref === '') {
        // Sequential within the discovery year, so a reference sorts and reads
        // like a case number rather than a random string.
        $yr = substr($disc, 0, 4);
        $n = (int) (mam_dp_val("SELECT COUNT(*) FROM dp_breaches WHERE reference LIKE ?", ['BR-' . $yr . '-%']) ?? 0);
        $ref = sprintf('BR-%s-%03d', $yr, $n + 1);
    }

    $id = mam_db_insert('dp_breaches', [
        'reference'          => $ref,
        'occurred_at'        => $occ !== '' ? $occ : null,
        'discovered_at'      => $disc,
        'nature'             => $nature,
        'data_categories'    => $cats,
        'subject_categories' => ($in['subject_categories'] ?? '') !== '' ? (string) $in['subject_categories'] : null,
        'subjects_affected'  => isset($in['subjects_affected']) && $in['subjects_affected'] !== '' ? max(0, (int) $in['subjects_affected']) : null,
        'risk_level'         => $risk,
        'containment'        => ($in['containment'] ?? '') !== '' ? (string) $in['containment'] : null,
        'recorded_by'        => $by ?: null,
        'created_at'         => mam_dp_now(),
    ]);
    if (function_exists('mam_audit')) mam_audit('dp_breach_record', 'dp_breach', $id, ['reference' => $ref, 'risk' => $risk]);
    if (function_exists('mam_log')) mam_log('warn', 'dp_breach_recorded', ['reference' => $ref, 'risk' => $risk]);
    return $id;
}

/** Stamp the NDPC notification (s.40(2)). Idempotent. */
function mam_dp_breach_notify_ndpc(int $id, ?string $at = null, string $reference = ''): bool {
    $r = mam_dp_rows("SELECT * FROM dp_breaches WHERE id = ?", [$id]);
    if (!$r) return false;
    if (!empty($r[0]['ndpc_notified_at']) && $reference === '') return true;
    mam_db_update('dp_breaches', [
        'ndpc_notified_at' => $r[0]['ndpc_notified_at'] ?: ($at ?: mam_dp_now()),
        'ndpc_reference'   => $reference !== '' ? $reference : ($r[0]['ndpc_reference'] ?? null),
    ], ['id' => $id]);
    if (function_exists('mam_audit')) mam_audit('dp_breach_ndpc', 'dp_breach', $id, ['reference' => $reference]);
    return true;
}

/** Stamp the data-subject communication (s.40(3)), or record why it was not made. */
function mam_dp_breach_notify_subjects(int $id, ?string $at = null, string $insteadReason = ''): bool {
    $r = mam_dp_rows("SELECT * FROM dp_breaches WHERE id = ?", [$id]);
    if (!$r) return false;
    if ($insteadReason !== '') {
        mam_db_update('dp_breaches', ['no_notify_reason' => $insteadReason], ['id' => $id]);
    } else {
        mam_db_update('dp_breaches', ['subjects_notified_at' => $r[0]['subjects_notified_at'] ?: ($at ?: mam_dp_now())], ['id' => $id]);
    }
    if (function_exists('mam_audit')) mam_audit('dp_breach_subjects', 'dp_breach', $id, ['declined' => $insteadReason !== '']);
    return true;
}

/**
 * Close a breach. A high-risk breach cannot be closed until the subjects have
 * either been told or the decision not to tell them has been recorded — the
 * schema says so as well, and this is the friendlier half of that pair.
 */
function mam_dp_breach_close(int $id, array $in = []): bool {
    $r = mam_dp_rows("SELECT * FROM dp_breaches WHERE id = ?", [$id]);
    if (!$r) return false;
    $b = $r[0];
    if (($b['risk_level'] ?? '') === 'high' && empty($b['subjects_notified_at']) && empty($b['no_notify_reason'])
        && trim((string) ($in['no_notify_reason'] ?? '')) === '') {
        throw new \RuntimeException('subjects_undecided');
    }
    $data = ['status' => 'closed'];
    if (($in['lessons'] ?? '') !== '')          $data['lessons'] = (string) $in['lessons'];
    if (($in['no_notify_reason'] ?? '') !== '') $data['no_notify_reason'] = (string) $in['no_notify_reason'];
    if (($in['containment'] ?? '') !== '')      $data['containment'] = (string) $in['containment'];
    mam_db_update('dp_breaches', $data, ['id' => $id]);
    if (function_exists('mam_audit')) mam_audit('dp_breach_close', 'dp_breach', $id, []);
    return true;
}

/**
 * The 72-hour clock for one breach, derived from AWARENESS.
 *
 * `hours_to_notify` is how long it actually took where notification happened,
 * which is the number that goes in the return; `hours_left` only means anything
 * while it is still outstanding.
 */
function mam_dp_breach_clock(array $b, ?string $now = null): array {
    $now = $now ?: mam_dp_now();
    $disc = (string) ($b['discovered_at'] ?? '');
    $deadline = date('Y-m-d H:i:s', strtotime($disc . ' +' . MAM_DP_DEADLINES['breach_ndpc_hours'] . ' hours'));
    $done = $b['ndpc_notified_at'] ?? null;
    $late = $done ? ($done > $deadline) : ($now > $deadline);
    $tookHours = $done ? (int) round((strtotime($done) - strtotime($disc)) / 3600) : null;
    $leftHours = $done ? null : (int) floor((strtotime($deadline) - strtotime($now)) / 3600);
    $detectionLagHours = !empty($b['occurred_at'])
        ? (int) round((strtotime($disc) - strtotime((string) $b['occurred_at'])) / 3600) : null;

    return [
        'deadline_at'      => $deadline,
        'notified'         => (bool) $done,
        'late'             => $late,
        'hours_to_notify'  => $tookHours,
        'hours_left'       => $leftHours,
        'detection_lag_h'  => $detectionLagHours,
        'state'            => $done ? ($late ? 'notified_late' : 'notified')
                                    : ($late ? 'overdue' : (($leftHours !== null && $leftHours <= 24) ? 'due_soon' : 'open')),
    ];
}

/** The register, newest discovery first, each row decorated with its clock. */
function mam_dp_breaches(int $limit = 200): array {
    $out = [];
    foreach (mam_dp_rows("SELECT * FROM dp_breaches ORDER BY discovered_at DESC, id DESC LIMIT " . max(1, min(1000, $limit))) as $b) {
        $b['clock'] = mam_dp_breach_clock($b);
        $out[] = $b;
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// Processor register.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The register: every processor the code can call, merged with what the academy
 * has recorded about it, plus whether it is configured RIGHT NOW.
 *
 * `gap` is the point of the screen — a processor that is live and has no
 * recorded data-processing agreement.
 */
function mam_dp_processors(): array {
    $rows = [];
    foreach (mam_dp_rows("SELECT * FROM dp_processors") as $r) $rows[(string) $r['key_name']] = $r;

    $out = [];
    foreach (mam_dp_processor_registry() as $key => $def) {
        $row = $rows[$key] ?? [];
        $configured = false;
        try { $configured = (bool) ($def['configured'])(); } catch (\Throwable $e) { $configured = false; }
        $out[] = [
            'key'            => $key,
            'name'           => (string) ($row['name'] ?? $def['name']),
            'purpose'        => (string) ($row['purpose'] ?? $def['purpose']),
            'country'        => (string) ($row['country'] ?? $def['country']),
            'role'           => (string) ($row['role'] ?? 'processor'),
            'configured'     => $configured,
            'dpa_signed_on'  => $row['dpa_signed_on'] ?? null,
            'dpa_reference'  => $row['dpa_reference'] ?? null,
            'transfer_basis' => $row['transfer_basis'] ?? null,
            'sub_processors' => $row['sub_processors'] ?? null,
            'review_due_on'  => $row['review_due_on'] ?? null,
            'status'         => (string) ($row['status'] ?? 'active'),
            'notes'          => $row['notes'] ?? null,
            'recorded'       => (bool) $row,
            // Live, in use, and no agreement on file.
            'gap'            => $configured && empty($row['dpa_signed_on']),
        ];
    }
    // Anything the academy added by hand that the code does not know about is
    // still shown — a processor engaged outside this system is exactly the kind
    // of thing that otherwise goes unregistered.
    foreach ($rows as $key => $r) {
        if (isset(mam_dp_processor_registry()[$key])) continue;
        $out[] = [
            'key' => $key, 'name' => (string) $r['name'], 'purpose' => (string) ($r['purpose'] ?? ''),
            'country' => (string) ($r['country'] ?? ''), 'role' => (string) $r['role'],
            'configured' => null, 'dpa_signed_on' => $r['dpa_signed_on'] ?? null,
            'dpa_reference' => $r['dpa_reference'] ?? null, 'transfer_basis' => $r['transfer_basis'] ?? null,
            'sub_processors' => $r['sub_processors'] ?? null, 'review_due_on' => $r['review_due_on'] ?? null,
            'status' => (string) $r['status'], 'notes' => $r['notes'] ?? null,
            'recorded' => true, 'gap' => empty($r['dpa_signed_on']),
        ];
    }
    return $out;
}

/** Record or update what the academy knows about a processor. Upsert on key. */
function mam_dp_processor_save(string $key, array $in, ?int $by = null): int {
    $key = trim($key);
    if ($key === '' || !preg_match('/^[a-z0-9_\-]{2,48}$/i', $key)) throw new \RuntimeException('bad_key');
    $def = mam_dp_processor_registry()[$key] ?? null;
    $name = trim((string) ($in['name'] ?? '')) ?: (string) ($def['name'] ?? '');
    if ($name === '') throw new \RuntimeException('name_required');
    $role = (string) ($in['role'] ?? 'processor');
    if (!in_array($role, ['processor', 'joint_controller', 'controller'], true)) $role = 'processor';
    $status = (string) ($in['status'] ?? 'active');
    if (!in_array($status, ['active', 'suspended', 'retired'], true)) $status = 'active';

    $data = [
        'name'           => $name,
        'role'           => $role,
        'purpose'        => trim((string) ($in['purpose'] ?? ($def['purpose'] ?? ''))) ?: null,
        'country'        => trim((string) ($in['country'] ?? ($def['country'] ?? ''))) ?: null,
        'dpa_signed_on'  => trim((string) ($in['dpa_signed_on'] ?? '')) ?: null,
        'dpa_reference'  => trim((string) ($in['dpa_reference'] ?? '')) ?: null,
        'sub_processors' => trim((string) ($in['sub_processors'] ?? '')) ?: null,
        'transfer_basis' => trim((string) ($in['transfer_basis'] ?? '')) ?: null,
        'review_due_on'  => trim((string) ($in['review_due_on'] ?? '')) ?: null,
        'status'         => $status,
        'notes'          => trim((string) ($in['notes'] ?? '')) ?: null,
        'updated_at'     => mam_dp_now(),
    ];
    $ex = mam_dp_rows("SELECT id FROM dp_processors WHERE key_name = ?", [$key]);
    if ($ex) { mam_db_update('dp_processors', $data, ['id' => (int) $ex[0]['id']]); $id = (int) $ex[0]['id']; }
    else     { $data['key_name'] = $key; $data['created_at'] = mam_dp_now(); $id = mam_db_insert('dp_processors', $data); }
    if (function_exists('mam_audit')) mam_audit('dp_processor_save', 'dp_processor', $id, ['key' => $key, 'dpa' => $data['dpa_signed_on']]);
    return $id;
}

// ─────────────────────────────────────────────────────────────────────────────
// DPIA records.
// ─────────────────────────────────────────────────────────────────────────────

/** Save (or update) a data-protection impact assessment. */
function mam_dp_assessment_save(array $in, ?int $by = null): int {
    $title = trim((string) ($in['title'] ?? ''));
    $scope = trim((string) ($in['scope'] ?? ''));
    if ($title === '' || $scope === '') throw new \RuntimeException('title_and_scope_required');
    $risk = (string) ($in['residual_risk'] ?? 'unknown');
    if (!in_array($risk, ['unknown', 'low', 'medium', 'high'], true)) $risk = 'unknown';
    $decision = (string) ($in['decision'] ?? 'draft');
    if (!in_array($decision, ['draft', 'proceed', 'proceed_with_controls', 'do_not_proceed'], true)) $decision = 'draft';

    $data = [
        'title'          => $title,
        'scope'          => $scope,
        'trigger_reason' => trim((string) ($in['trigger_reason'] ?? '')) ?: null,
        'necessity'      => trim((string) ($in['necessity'] ?? '')) ?: null,
        'risks'          => trim((string) ($in['risks'] ?? '')) ?: null,
        'mitigations'    => trim((string) ($in['mitigations'] ?? '')) ?: null,
        'residual_risk'  => $risk,
        'decision'       => $decision,
        'assessed_on'    => trim((string) ($in['assessed_on'] ?? '')) ?: null,
        'review_due_on'  => trim((string) ($in['review_due_on'] ?? '')) ?: null,
        'consulted_ndpc' => !empty($in['consulted_ndpc']) ? 1 : 0,
        'assessed_by'    => $by ?: null,
    ];
    $id = (int) ($in['id'] ?? 0);
    if ($id) { mam_db_update('dp_assessments', $data, ['id' => $id]); }
    else     { $data['created_at'] = mam_dp_now(); $id = mam_db_insert('dp_assessments', $data); }
    if (function_exists('mam_audit')) mam_audit('dp_assessment_save', 'dp_assessment', $id, ['decision' => $decision, 'risk' => $risk]);
    return $id;
}

/** All assessments, newest first. */
function mam_dp_assessments(int $limit = 100): array {
    return mam_dp_rows("SELECT a.*, u.name AS assessor FROM dp_assessments a LEFT JOIN users u ON u.id = a.assessed_by
                        ORDER BY COALESCE(a.assessed_on, a.created_at) DESC, a.id DESC LIMIT " . max(1, min(500, $limit)));
}

/**
 * Which registered purposes involve sensitive data and therefore ought to have
 * an assessment. Matched on the purpose key appearing in a DPIA's scope text —
 * deliberately loose, because insisting on a foreign key would mean a DPIA can
 * only ever describe one purpose, which is not how an assessment reads.
 */
function mam_dp_assessment_gaps(): array {
    $have = strtolower(implode(' ', array_map(
        static fn(array $a): string => (string) ($a['title'] ?? '') . ' ' . (string) ($a['scope'] ?? ''),
        mam_dp_assessments(500)
    )));
    $gaps = [];
    foreach (mam_dp_purposes() as $key => $p) {
        if (empty($p['special'])) continue;
        if (strpos($have, $key) !== false) continue;
        $gaps[$key] = (string) $p['purpose'];
    }
    return $gaps;
}

// ─────────────────────────────────────────────────────────────────────────────
// Retention: scan, then (optionally) act.
// ─────────────────────────────────────────────────────────────────────────────

/** The effective retention period for an entry — the shipped one unless lengthened. */
function mam_dp_keep_days(string $key): int {
    $reg = mam_dp_retention();
    if (!isset($reg[$key])) throw new \RuntimeException('unknown_ref');
    $shipped = (int) $reg[$key]['days'];
    $note = mam_dp_note('retention', $key);
    $over = isset($note['keep_days']) && $note['keep_days'] !== null ? (int) $note['keep_days'] : null;
    return $over !== null && $over > $shipped ? $over : $shipped;
}

/**
 * Count what is past its retention period, per entry. Reads only — this is the
 * number the admin screen shows and the sweep acts on, and it must be
 * computable without any risk of changing anything.
 */
function mam_dp_retention_scan(): array {
    $out = [];
    foreach (mam_dp_retention() as $key => $r) {
        $days = mam_dp_keep_days($key);
        $cut  = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
        $tbl  = (string) $r['table'];
        $col  = (string) $r['date'];
        $total = mam_dp_val("SELECT COUNT(*) FROM {$tbl}");
        $over  = mam_dp_val("SELECT COUNT(*) FROM {$tbl} WHERE {$col} < ?", [$cut]);
        $out[$key] = [
            'key'       => $key,
            'label'     => (string) $r['label'],
            'table'     => $tbl,
            'action'    => (string) $r['action'],
            'basis'     => (string) $r['basis'],
            'days'      => $days,
            'lengthened' => $days > (int) $r['days'],
            'cutoff'    => $cut,
            'exists'    => $total !== null,
            'rows'      => (int) ($total ?? 0),
            'overdue'   => (int) ($over ?? 0),
            'note'      => mam_dp_note('retention', $key),
        ];
    }
    return $out;
}

/**
 * Apply the schedule.
 *
 * Ships in `report`, like the safeguarding gate and for the same reason: a
 * retention sweep that starts deleting on the first cron run of an install
 * nobody has reviewed is how an academy loses records it needed, and the
 * reliable outcome of that is somebody switching the whole feature off. In
 * `report` it counts and audits; only `enforce` acts, and even then:
 *
 *   - only entries whose action is `delete` or `anonymise` are ever applied.
 *     `review` is a human decision by construction and the sweep will not make
 *     it — that is why the academic record, payments and vetting are `review`.
 *   - `$dryRun` overrides the mode, so the admin's "preview" button cannot act
 *     however the setting is configured.
 *   - a `delete` is capped per run, so a first enforcement on a long-neglected
 *     install cannot lock the database for minutes on shared hosting.
 */
function mam_dp_retention_sweep(bool $dryRun = false): array {
    $mode = mam_dp_retention_mode();
    $act = !$dryRun && $mode === 'enforce';
    $result = ['mode' => $mode, 'applied' => $act, 'deleted' => 0, 'anonymised' => 0, 'flagged' => 0, 'per_entry' => []];
    if ($mode === 'off' && !$dryRun) { $result['skipped'] = true; return $result; }

    $cap = 5000;
    foreach (mam_dp_retention_scan() as $key => $e) {
        $did = 0;
        if ($e['exists'] && $e['overdue'] > 0 && $act && in_array($e['action'], ['delete', 'anonymise'], true)) {
            $reg = mam_dp_retention()[$key];
            $tbl = (string) $reg['table'];
            $col = (string) $reg['date'];
            try {
                if ($e['action'] === 'delete') {
                    // MySQL supports DELETE ... LIMIT; SQLite (unless built with
                    // SQLITE_ENABLE_UPDATE_DELETE_LIMIT) does not, and raises at
                    // PREPARE time — so both the prepare and the execute have to
                    // sit inside the attempt, or the fallback never runs.
                    try {
                        $st = mam_db()->prepare("DELETE FROM {$tbl} WHERE {$col} < ? LIMIT " . $cap);
                        $st->execute([$e['cutoff']]);
                    } catch (\Throwable $inner) {
                        $st = mam_db()->prepare("DELETE FROM {$tbl} WHERE {$col} < ?");
                        $st->execute([$e['cutoff']]);
                    }
                    $did = $st->rowCount();
                    $result['deleted'] += $did;
                } else {
                    $cols = (array) ($reg['pii'] ?? []);
                    if ($cols) {
                        $set = implode(', ', array_map(static fn(string $c): string => $c . ' = NULL', $cols));
                        $st = mam_db()->prepare("UPDATE {$tbl} SET {$set} WHERE {$col} < ?");
                        $st->execute([$e['cutoff']]);
                        $did = $st->rowCount();
                        $result['anonymised'] += $did;
                    }
                }
            } catch (\Throwable $ex) {
                if (function_exists('mam_log')) mam_log('error', 'dp_retention_failed', ['entry' => $key, 'err' => $ex->getMessage()]);
                $did = -1;
            }
        } elseif ($e['overdue'] > 0) {
            $result['flagged'] += $e['overdue'];
        }
        $result['per_entry'][$key] = ['overdue' => $e['overdue'], 'action' => $e['action'], 'affected' => $did];
    }

    if (function_exists('mam_audit')) {
        mam_audit('dp_retention_sweep', 'setting', 0, [
            'mode' => $mode, 'applied' => $act, 'deleted' => $result['deleted'],
            'anonymised' => $result['anonymised'], 'flagged' => $result['flagged'],
        ]);
    }
    if (function_exists('mam_automation_touch')) mam_automation_touch('data_retention');
    return $result;
}

// ─────────────────────────────────────────────────────────────────────────────
// The register view, and the screen's summary.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * ── THE APPLICATION LOG IS PERSONAL DATA TOO ──────────────────────────────
 * `mam_log()` writes one JSON line per event into api/logs/app-YYYY-MM-DD.log,
 * and every line carries an IP address, a user agent and whatever `meta` the
 * call site passed. That is personal data on exactly the footing as the rows
 * the schedule above governs — but it is a FILE, so nothing in this lib saw
 * it. Nothing pruned it either: the daily files accumulated for the life of
 * the install, on shared hosting, outside the register that exists to say
 * what the academy keeps and for how long.
 *
 * ⚠️ This is deliberately NOT an entry in `mam_dp_retention()`. That registry
 * is asserted table-by-table against the LIVE SCHEMA — every rule must name a
 * real table and a real date column — so a file-based rule would either break
 * that test or force it to be weakened. The test is the thing keeping the
 * register honest, so the file rule lives beside it instead of inside it, and
 * `mam_dp_register()` reports it as its own row.
 *
 * Three decisions worth keeping:
 *
 *  • The day is read from the FILENAME, never from mtime. A restore from
 *    backup, an rsync or a filesystem copy rewrites every mtime, which would
 *    make a whole history of logs look like it was written today — the sweep
 *    would then keep them all, silently, forever. The name states which day
 *    the file covers and is the only thing that survives being moved.
 *
 *  • Only the exact `app-YYYY-MM-DD.log` pattern is ever considered. The
 *    directory is not globbed. Anything else somebody has put in api/logs —
 *    a capture they are mid-investigation on, a .gitignore — is not this
 *    function's to delete.
 *
 *  • The gate is the SAME `dp_retention_mode` the table sweep honours, and
 *    `$dryRun` overrides `enforce` rather than being overridden by it. The
 *    admin's preview button must not become destructive because a setting
 *    changed underneath it — the invariant the table sweep already holds.
 */

/** `app-YYYY-MM-DD.log`, and nothing else in the directory. */
const MAM_DP_LOG_FILE_RE = '/^app-(\d{4}-\d{2}-\d{2})\.log$/';

/**
 * How long a diagnostic log line is kept. Shorter than the 365 days on
 * `mail_log` and `card_scans` on purpose: those are records of something the
 * academy did, while this is diagnostic exhaust whose only purpose is working
 * out what went wrong recently. Once that need has passed the line is just a
 * retained IP address.
 */
function mam_dp_log_retention_days(): int {
    $d = (int) mam_setting_or_default('dp_log_retention_days', 90);
    // A zero or a negative from a mistyped setting would delete today's log.
    return $d >= 1 ? $d : 90;
}

/**
 * Sweep the application log files. Never throws — it runs from cron beside
 * jobs that matter more, and a log file it cannot unlink must not stop them.
 *
 * @return array{mode:string,applied:bool,scanned:int,overdue:int,deleted:int,
 *               bytes:int,cutoff:string,errors:int}
 */
function mam_dp_prune_app_logs(bool $dryRun = false): array {
    $mode = mam_dp_retention_mode();
    $act  = !$dryRun && $mode === 'enforce';
    $days = mam_dp_log_retention_days();

    // Adjudicated in the academy's own zone, like every other date in the
    // system, so a server clock in another zone cannot shift the boundary.
    $cutoff = gmdate('Y-m-d', time() - ($days * 86400));
    $today  = gmdate('Y-m-d');

    $out = ['mode' => $mode, 'applied' => $act, 'scanned' => 0, 'overdue' => 0,
            'deleted' => 0, 'bytes' => 0, 'cutoff' => $cutoff, 'errors' => 0];

    if ($mode === 'off' && !$dryRun) { $out['skipped'] = true; return $out; }
    if (!defined('MAM_LOGS') || !is_dir(MAM_LOGS)) return $out;

    $entries = @scandir(MAM_LOGS);
    if ($entries === false) return $out;

    foreach ($entries as $name) {
        if (!preg_match(MAM_DP_LOG_FILE_RE, $name, $m)) continue;
        $out['scanned']++;
        $day = $m[1];
        if ($day === $today) continue;          // never the file being written
        if ($day >= $cutoff) continue;          // ISO dates compare as strings
        $out['overdue']++;

        $path = MAM_LOGS . '/' . $name;
        $size = (int) @filesize($path);
        if (!$act) continue;
        if (@unlink($path)) { $out['deleted']++; $out['bytes'] += $size; }
        else { $out['errors']++; }
    }

    if ($out['deleted'] > 0) {
        // The register has to be able to show that the deletion happened; an
        // unrecorded deletion is indistinguishable from never having kept the
        // data at all.
        try {
            mam_audit('dp.logs_pruned', 'app_logs', null, [
                'deleted' => $out['deleted'], 'bytes' => $out['bytes'],
                'cutoff'  => $cutoff, 'days' => $days,
            ]);
        } catch (\Throwable $e) { /* never fail a sweep over its own audit row */ }
    }
    return $out;
}

/**
 * The ROPA for display: each purpose, its annotation, and — the load-bearing
 * part — whether the tables it names still exist. A purpose pointing at a
 * dropped table means the register has drifted from the code, which is the
 * failure mode this design exists to make visible.
 */
function mam_dp_register(): array {
    $out = [];
    foreach (mam_dp_purposes() as $key => $p) {
        $missing = [];
        foreach ((array) $p['tables'] as $t) {
            if (mam_dp_val("SELECT COUNT(*) FROM {$t}") === null) $missing[] = $t;
        }
        $out[$key] = $p + [
            'key'          => $key,
            'basis_label'  => MAM_DP_BASES[$p['basis']] ?? $p['basis'],
            'note'         => mam_dp_note('purpose', $key),
            'missing_tables' => $missing,
            'retention'    => mam_dp_retention()[$p['retention_ref']] ?? null,
            'keep_days'    => isset(mam_dp_retention()[$p['retention_ref']]) ? mam_dp_keep_days((string) $p['retention_ref']) : null,
        ];
    }
    return $out;
}

/**
 * Everything the admin screen's header needs, plus an attention list.
 *
 * The attention list is ordered by consequence, not by table: a breach inside
 * its 72 hours outranks an unreviewed register entry however old.
 */
function mam_dp_summary(): array {
    $requests = mam_dp_requests(false, 500);
    $open = array_values(array_filter($requests, static fn(array $r): bool => empty($r['closed_at'])));
    $overdue = array_values(array_filter($open, static fn(array $r): bool => $r['clock']['state'] === 'overdue'));
    $breaches = mam_dp_breaches(500);
    $breachOpen = array_values(array_filter($breaches, static fn(array $b): bool => ($b['status'] ?? '') !== 'closed'));
    $breachLate = array_values(array_filter($breaches, static fn(array $b): bool => !empty($b['clock']['late'])));
    $procs = mam_dp_processors();
    $procGaps = array_values(array_filter($procs, static fn(array $p): bool => !empty($p['gap'])));
    $scan = mam_dp_retention_scan();
    $tier = mam_dp_tier();

    $attention = [];
    // ANY breach with the Commission not yet notified is critical, not only one
    // that is nearly out of time. A 72-hour duty with 60 hours left is still the
    // most urgent thing on this screen, and waiting until it reads `due_soon` to
    // mention it is how a deadline gets missed over a weekend.
    foreach ($breachOpen as $b) {
        $st = (string) ($b['clock']['state'] ?? '');
        if ($st === 'overdue') {
            $attention[] = ['level' => 'critical', 'text' => 'Breach ' . $b['reference'] . ' is past its 72-hour notification deadline.'];
        } elseif (!$b['clock']['notified']) {
            $attention[] = ['level' => 'critical', 'text' => 'Breach ' . $b['reference'] . ' must be notified to the NDPC within ' . (int) $b['clock']['hours_left'] . 'h.'];
        } else {
            $attention[] = ['level' => 'warn', 'text' => 'Breach ' . $b['reference'] . ' is notified but still open — containment and lessons outstanding.'];
        }
    }
    if ($overdue)  $attention[] = ['level' => 'warn', 'text' => count($overdue) . ' rights request(s) past the response commitment.'];
    if ($procGaps) $attention[] = ['level' => 'warn', 'text' => count($procGaps) . ' live processor(s) with no data-processing agreement on record.'];
    if ($tier['must_register']) {
        $attention[] = ['level' => 'warn', 'text' => 'At ' . $tier['count'] . ' subjects in six months the academy is a ' . $tier['tier']['label']
            . ' and must be registered with the NDPC' . ($tier['must_file_car'] ? ', and file Compliance Audit Returns.' : '.')];
    }
    $unreviewed = 0;
    foreach (mam_dp_register() as $r) { if (empty($r['note']['reviewed_on'])) $unreviewed++; }
    if ($unreviewed) $attention[] = ['level' => 'info', 'text' => $unreviewed . ' processing purpose(s) never reviewed.'];
    $gaps = mam_dp_assessment_gaps();
    if ($gaps) $attention[] = ['level' => 'info', 'text' => count($gaps) . ' sensitive purpose(s) with no impact assessment: ' . implode(', ', array_keys($gaps)) . '.'];
    $drift = [];
    foreach (mam_dp_register() as $k => $r) { if (!empty($r['missing_tables'])) $drift[] = $k; }
    if ($drift) $attention[] = ['level' => 'critical', 'text' => 'The register names tables that do not exist (' . implode(', ', $drift) . ') — it has drifted from the code.'];

    // Ordered by consequence, and STABLY so — a live breach clock outranks an
    // unreviewed register entry however old, and equal levels keep the order
    // they were added in rather than shuffling between page loads.
    $rank = ['critical' => 0, 'warn' => 1, 'info' => 2];
    $i = 0;
    foreach ($attention as &$a) { $a['_i'] = $i++; }
    unset($a);
    usort($attention, static fn(array $x, array $y): int =>
        ($rank[$x['level']] <=> $rank[$y['level']]) ?: ($x['_i'] <=> $y['_i']));
    foreach ($attention as &$a2) { unset($a2['_i']); }
    unset($a2);

    return [
        'tier'             => $tier,
        'requests_open'    => count($open),
        'requests_overdue' => count($overdue),
        'requests_total'   => count($requests),
        'breaches_open'    => count($breachOpen),
        'breaches_late'    => count($breachLate),
        'breaches_total'   => count($breaches),
        'processors'       => count($procs),
        'processor_gaps'   => count($procGaps),
        'retention_mode'   => mam_dp_retention_mode(),
        /* The application log FILES, reported beside the table schedule so
           the register accounts for every store of personal data and not
           only the ones that happen to be rows. Always a dry run here: a
           screen that reads the register must never delete anything. */
        'logs'             => mam_dp_prune_app_logs(true),
        'log_keep_days'    => mam_dp_log_retention_days(),
        'retention_flagged' => array_sum(array_map(static fn(array $e): int => (int) $e['overdue'], $scan)),
        'purposes'         => count(mam_dp_purposes()),
        'purposes_unreviewed' => $unreviewed,
        'assessment_gaps'  => count($gaps),
        'dpo'              => trim((string) mam_setting_or_default('dp_officer', '')),
        'attention'        => $attention,
    ];
}
