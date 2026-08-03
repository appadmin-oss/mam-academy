<?php
/**
 * Admissions helpers (Application proposal — A1+). The applicant-facing view of
 * an application: a privacy-safe status lookup (reference + matching email) that
 * maps the raw application/payment state to a friendly stage + next step, so the
 * journey no longer dead-ends at "submitted".
 */
declare(strict_types=1);

/**
 * Map the raw application status + latest payment status to a friendly stage.
 * Returns ['stage','label','next'].
 */
function mam_application_stage(string $status, string $paymentStatus): array {
    $s    = strtolower(trim($status));
    $paid = strtolower(trim($paymentStatus)) === 'succeeded' || $s === 'paid';

    switch ($s) {
        case 'enrolled':
            return ['stage' => 'enrolled', 'label' => 'Enrolled', 'next' => 'You\'re in — sign in to your student portal to begin.'];
        case 'accepted':
            return ['stage' => 'accepted', 'label' => 'Accepted', 'next' => 'Congratulations! Watch your email for enrolment and onboarding details.'];
        case 'waitlisted':
            return ['stage' => 'waitlisted', 'label' => 'Waitlisted', 'next' => 'You\'re on our waitlist — we\'ll be in touch if a place opens. Thank you for your patience.'];
        case 'in_review':
        case 'reviewing':
            return ['stage' => 'reviewing', 'label' => 'Under review', 'next' => 'Our admissions team is reviewing your application — we\'ll update you shortly.'];
        case 'cancelled':
        case 'declined':
        case 'rejected':
        case 'withdrawn':
            return ['stage' => 'closed', 'label' => 'Closed', 'next' => 'This application is closed — please check your email, or start a new application.'];
        case 'expired':
            return ['stage' => 'expired', 'label' => 'Expired', 'next' => 'This application expired because the processing fee wasn\'t paid in time. Please start a new application — the full processing fee applies afresh.'];
    }
    if ($paid) {
        return ['stage' => 'confirmed', 'label' => 'Seat confirmed', 'next' => 'Your booking fee is in. We\'ll be in touch about your MAM Auditer assessment and the next steps.'];
    }
    return ['stage' => 'received', 'label' => 'Application received', 'next' => 'Confirm your seat with the one-time booking fee — use the link in your confirmation email, or reopen your application to pay.'];
}

/* ── Admissions pipeline (A2): enforced state machine + notes + audit ─────── */

/** Legal application-status moves. Terminal states have an empty allow-list. */
function mam_admissions_transitions(): array {
    return [
        'submitted'  => ['in_review', 'accepted', 'waitlisted', 'paid', 'enrolled', 'cancelled', 'expired'],
        'in_review'  => ['accepted', 'waitlisted', 'paid', 'enrolled', 'cancelled', 'expired'],
        'waitlisted' => ['in_review', 'accepted', 'paid', 'enrolled', 'cancelled', 'expired'],
        'accepted'   => ['paid', 'enrolled', 'cancelled', 'expired'],
        'paid'       => ['accepted', 'enrolled', 'cancelled'],
        'enrolled'   => [],
        'cancelled'  => [],
        'expired'    => [],
    ];
}
function mam_admissions_can_transition(string $from, string $to): bool {
    $g = mam_admissions_transitions();
    return isset($g[$from]) && in_array($to, $g[$from], true);
}

/**
 * Move an application through the admissions pipeline (enforced + audited).
 * Idempotent on the current status. Throws application_not_found /
 * illegal_transition. Returns ['ok','changed','from','to'].
 */
function mam_admissions_transition(int $appId, string $to, ?int $by = null, ?string $reason = null): array {
    $row = mam_db_row('SELECT status FROM applications WHERE id = ?', [$appId]);
    if (!$row) throw new RuntimeException('application_not_found');
    $from = (string)$row['status'];
    if ($from === $to) return ['ok' => true, 'changed' => false, 'from' => $from, 'to' => $to];
    if (!mam_admissions_can_transition($from, $to)) throw new RuntimeException('illegal_transition');
    mam_db_update('applications', ['status' => $to, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $appId]);
    try {
        mam_db_insert('application_events', [
            'application_id' => $appId, 'from_status' => $from, 'to_status' => $to,
            'actor_id' => $by, 'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
        ]);
    } catch (\Throwable $e) { /* audit best-effort */ }
    if (function_exists('mam_event')) mam_event('application.' . $to, 'application', $appId, ['from' => $from]);
    mam_audit('admissions_transition', 'application', $appId, ['from' => $from, 'to' => $to, 'reason' => $reason]);
    return ['ok' => true, 'changed' => true, 'from' => $from, 'to' => $to];
}

/** Add an internal admissions note to an application. Returns the note id. */
function mam_admissions_note(int $appId, ?int $authorId, string $note): int {
    $note = trim($note);
    if ($note === '') throw new RuntimeException('empty_note');
    if (!mam_db_row('SELECT id FROM applications WHERE id = ?', [$appId])) throw new RuntimeException('application_not_found');
    $id = mam_db_insert('application_notes', ['application_id' => $appId, 'author_id' => $authorId, 'note' => mb_substr($note, 0, 4000)]);
    mam_audit('admissions_note', 'application', $appId, []);
    return $id;
}

/** Internal notes for an application, newest first (with author names). */
function mam_admissions_notes(int $appId): array {
    return mam_db_all(
        "SELECT n.id, n.note, n.created_at, u.name AS author_name
           FROM application_notes n LEFT JOIN users u ON u.id = n.author_id
          WHERE n.application_id = ? ORDER BY n.id DESC",
        [$appId]
    );
}

/** The status audit trail for an application, oldest first (with actor names). */
function mam_admissions_events(int $appId): array {
    return mam_db_all(
        "SELECT e.from_status, e.to_status, e.reason, e.created_at, u.name AS actor_name
           FROM application_events e LEFT JOIN users u ON u.id = e.actor_id
          WHERE e.application_id = ? ORDER BY e.id ASC",
        [$appId]
    );
}

/** Assign (or clear, with null) the staff member handling an application. */
function mam_admissions_assign(int $appId, ?int $userId, ?int $by = null): void {
    if (!mam_db_row('SELECT id FROM applications WHERE id = ?', [$appId])) throw new RuntimeException('application_not_found');
    mam_db_update('applications', ['assignee_id' => $userId, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $appId]);
    mam_audit('admissions_assign', 'application', $appId, ['assignee' => $userId, 'by' => $by]);
    // Notify the newly assigned staff member (best-effort, never-throw). Skip
    // when clearing the assignee, or when someone assigns an application to self.
    if ($userId && $userId !== $by && function_exists('mam_notify')) {
        $ref = (string)(mam_db_row('SELECT reference FROM applications WHERE id = ?', [$appId])['reference'] ?? '');
        mam_notify($userId, 'application.assigned', 'An application was assigned to you',
            $ref !== '' ? 'Reference: ' . $ref : null, '/admin/?v=applications&app=' . $appId);
    }
}

/** The admissions pipeline: applications with applicant, stage, assignee, and
 *  payment status — newest first, optionally filtered by status. */
function mam_admissions_pipeline(?string $status = null, int $limit = 500): array {
    $where = ''; $args = [];
    if ($status !== null && $status !== '') { $where = 'WHERE a.status = ?'; $args[] = $status; }
    $rows = mam_db_all(
        "SELECT a.id, a.reference, a.pathway, a.mode, a.status, a.created_at, a.assignee_id,
                c.first_name, c.last_name, c.email,
                asg.name AS assignee_name,
                (SELECT pp.status FROM payments pp WHERE pp.application_id = a.id ORDER BY pp.id DESC LIMIT 1) AS payment_status
           FROM applications a
           JOIN customers c    ON c.id = a.customer_id
           LEFT JOIN users asg ON asg.id = a.assignee_id
           $where
          ORDER BY a.id DESC LIMIT " . max(1, $limit),
        $args
    );
    foreach ($rows as &$r) {
        $st = mam_application_stage((string)$r['status'], (string)($r['payment_status'] ?? ''));
        $r['stage'] = $st['stage'];
        $r['stage_label'] = $st['label'];
    }
    return $rows;
}

/**
 * Privacy-safe applicant status lookup. Requires BOTH the reference and the
 * matching email (so applications aren't enumerable by reference alone).
 * Returns the friendly status array, or null when not found / mismatched.
 */
function mam_application_status(string $reference, string $email): ?array {
    $reference = trim($reference);
    $email     = strtolower(trim($email));
    if ($reference === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;

    $row = mam_db_row(
        "SELECT a.reference, a.pathway, a.mode, a.status, a.created_at, a.total_usd,
                c.first_name,
                (SELECT pp.status FROM payments pp WHERE pp.application_id = a.id ORDER BY pp.id DESC LIMIT 1) AS payment_status
           FROM applications a
           JOIN customers c ON c.id = a.customer_id
          WHERE a.reference = ? AND LOWER(c.email) = ?",
        [$reference, $email]
    );
    if (!$row) return null;

    $stage = mam_application_stage((string)$row['status'], (string)($row['payment_status'] ?? ''));
    return [
        'reference'      => $row['reference'],
        'first_name'     => $row['first_name'],
        'pathway'        => $row['pathway'],
        'mode'           => $row['mode'],
        'status'         => $row['status'],
        'payment_status' => $row['payment_status'],
        'submitted_at'   => $row['created_at'],
        'stage'          => $stage['stage'],
        'stage_label'    => $stage['label'],
        'next_step'      => $stage['next'],
    ];
}

/* ── Server-side application drafts (A1: cross-device resume) ─────────────── */

/** Upsert a server-side draft keyed by the client's idempotency key. Best-effort
 *  (a draft save must never break the apply page); caps payload size. */
function mam_app_draft_save(string $draftKey, string $email, $payload): void {
    $draftKey = trim($draftKey);
    if ($draftKey === '' || strlen($draftKey) > 64) return;
    $json = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || strlen($json) > 200000) return; // sanity cap
    $email = strtolower(trim($email));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
    $now = date('Y-m-d H:i:s');
    try {
        $existing = mam_db_row('SELECT id, email FROM application_drafts WHERE draft_key = ?', [$draftKey]);
        if ($existing) {
            // Integrity guard: once a draft is bound to an email, a save carrying
            // a DIFFERENT non-empty email is refused. Combined with the ~40-bit
            // client key + rate limit, this stops someone who guesses a key from
            // hijacking a known applicant's draft. Same-email autosave (and the
            // pre-email autosave, where the stored email is null) still works.
            $prevEmail = strtolower(trim((string)($existing['email'] ?? '')));
            if ($prevEmail !== '' && $email !== '' && $email !== $prevEmail) {
                mam_log('warn', 'app_draft_email_mismatch', ['draft_key' => substr($draftKey, 0, 12)]);
                return;
            }
            // Never blank out a previously-recorded email.
            $emailToStore = $email !== '' ? $email : ($prevEmail !== '' ? $prevEmail : null);
            mam_db_update('application_drafts', ['email' => $emailToStore, 'payload' => $json, 'updated_at' => $now], ['id' => (int)$existing['id']]);
        } else {
            mam_db_insert('application_drafts', ['draft_key' => $draftKey, 'email' => $email ?: null, 'payload' => $json, 'status' => 'draft']);
        }
    } catch (\Throwable $e) { /* best-effort */ }
}

/** Load a draft by key → ['draft_key','email','status','payload'(array|null)] or null. */
function mam_app_draft_load(string $draftKey): ?array {
    $row = mam_db_row('SELECT draft_key, email, payload, status FROM application_drafts WHERE draft_key = ?', [trim($draftKey)]);
    if (!$row) return null;
    $payload = $row['payload'] !== null ? json_decode((string)$row['payload'], true) : null;
    return ['draft_key' => $row['draft_key'], 'email' => $row['email'], 'status' => $row['status'], 'payload' => is_array($payload) ? $payload : null];
}

/** Signed, stable resume token for a draft key (HMAC over the app key). */
function mam_app_draft_token(string $draftKey): string {
    return substr(hash_hmac('sha256', 'draft:' . trim($draftKey), mam_app_key()), 0, 32);
}
/** Constant-time token check. */
function mam_app_draft_verify(string $draftKey, string $token): bool {
    return trim($draftKey) !== '' && hash_equals(mam_app_draft_token($draftKey), (string)$token);
}
/** Token-verified load — the resume path. Null on a bad/forged token. */
function mam_app_draft_load_verified(string $draftKey, string $token): ?array {
    return mam_app_draft_verify($draftKey, $token) ? mam_app_draft_load($draftKey) : null;
}
/** The magic-link resume URL emailed to the applicant. */
function mam_app_draft_resume_url(string $draftKey): string {
    $base = rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/');
    return $base . '/apply?resume=' . rawurlencode(trim($draftKey)) . '&t=' . mam_app_draft_token($draftKey);
}
/** Mark a draft submitted (called after a successful application submit). */
function mam_app_draft_mark_submitted(string $draftKey): void {
    if (trim($draftKey) === '') return;
    try { mam_db_update('application_drafts', ['status' => 'submitted', 'updated_at' => date('Y-m-d H:i:s')], ['draft_key' => trim($draftKey)]); } catch (\Throwable $e) {}
}

/**
 * Abandoned-draft nudge sweep (L3 automation): email a one-time "finish your
 * application" magic link to each draft that's been idle in the window
 * (default 24h–7d), has an email, and hasn't been nudged or submitted. Marks
 * each 'nudged' so it's emailed at most once. Returns how many were nudged.
 * Driven by the run-jobs cron. Never throws.
 */
function mam_app_draft_nudge_sweep(int $minHours = 24, int $maxHours = 168, int $limit = 50): int {
    $now   = time();
    $minTs = date('Y-m-d H:i:s', $now - max(1, $minHours) * 3600);  // older than this
    $maxTs = date('Y-m-d H:i:s', $now - max(2, $maxHours) * 3600);  // but newer than this
    $sent  = 0;
    try {
        $rows = mam_db_all(
            "SELECT draft_key, email FROM application_drafts
              WHERE status = 'draft' AND email IS NOT NULL AND email <> ''
                AND updated_at <= ? AND updated_at >= ?
              ORDER BY id ASC LIMIT " . max(1, $limit),
            [$minTs, $maxTs]
        );
        foreach ($rows as $r) {
            if (function_exists('mam_send')) {
                mam_send((string)$r['email'], 'Finish your MAM Academy application',
                    mam_email_resume_link(mam_app_draft_resume_url((string)$r['draft_key'])),
                    'application_draft_nudge', true);
            }
            mam_db_update('application_drafts', ['status' => 'nudged', 'updated_at' => date('Y-m-d H:i:s')], ['draft_key' => $r['draft_key']]);
            $sent++;
        }
    } catch (\Throwable $e) { /* best-effort */ }
    return $sent;
}

// ── Summer Programme ───────────────────────────────────────────────────────

/**
 * Map a raw summer_applications.status to a display stage + next-step message.
 */
function mam_summer_application_stage(string $status): array {
    static $map = [
        'confirmed'  => ['stage' => 'confirmed',  'label' => 'Confirmed',          'next' => "Your spot is confirmed! Watch your email for the programme schedule, packing list, and what to bring."],
        'waitlisted' => ['stage' => 'waitlisted', 'label' => 'On the waitlist',    'next' => "You're on our waitlist. We'll reach out as soon as a spot opens up — thank you for your patience."],
        'enrolled'   => ['stage' => 'enrolled',   'label' => 'Enrolled',           'next' => "You're officially enrolled! Check your email for arrival instructions and programme materials."],
        'cancelled'  => ['stage' => 'closed',     'label' => 'Cancelled',          'next' => "This application has been cancelled. Please contact us if you have any questions."],
        'rejected'   => ['stage' => 'closed',     'label' => 'Not accepted',       'next' => "We're unable to offer a spot this season. Contact us for more information."],
    ];
    return $map[$status] ?? [
        'stage' => 'received',
        'label' => 'Application received',
        'next'  => "We've received your application for the " . mam_setting('summer_prog_name', 'MAM Summer Music Camp') . "! Our team will review it and be in touch shortly.",
    ];
}

/**
 * Look up a summer application by reference + email (both required to prevent
 * enumeration). Returns null when not found or email doesn't match.
 */
function mam_summer_application_status(string $reference, string $email): ?array {
    $row = mam_db_row(
        'SELECT * FROM summer_applications WHERE reference = ? AND LOWER(email) = LOWER(?)',
        [trim($reference), trim($email)]
    );
    if (!$row) return null;

    $stage = mam_summer_application_stage((string)($row['status'] ?? 'submitted'));
    return [
        'reference'    => (string)$row['reference'],
        'first_name'   => (string)$row['first_name'],
        'last_name'    => (string)($row['last_name'] ?? ''),
        'programme'    => mam_setting('summer_prog_name', 'MAM Summer Music Camp'),
        'session_start'=> mam_setting('summer_session_start', ''),
        'session_end'  => mam_setting('summer_session_end', ''),
        'session_year' => (int)($row['session_year'] ?? date('Y')),
        'status'       => (string)$row['status'],
        'stage'        => $stage['stage'],
        'stage_label'  => $stage['label'],
        'next_step'    => $stage['next'],
        'submitted_at'  => (string)$row['created_at'],
        'headshot_url'  => (string)($row['headshot_url'] ?? ''),
        'is_summer'     => true,
    ];
}

/**
 * Is the summer application window open on $today (Y-m-d)?
 *
 * Each bound is honoured INDEPENDENTLY. The previous inline guard only ran when
 * both settings were present, so configuring just `summer_app_close` (the usual
 * "we close on the 30th" case) left applications open forever. An empty bound
 * means "unbounded on that side"; no bounds at all means always open.
 */
function mam_summer_window_open(string $today, string $open, string $close): bool {
    $open  = trim($open);
    $close = trim($close);
    if ($open !== ''  && $today < $open)  return false;
    if ($close !== '' && $today > $close) return false;
    return true;
}

/* ── Summer application status lifecycle ───────────────────────────────────
   The rest of the platform enforces its state machines (mam_admissions_transition,
   mam_lms_transitions); summer status was free assignment from any value to any
   other, so a rejected place could be walked back to 'enrolled' and a cancelled
   one re-confirmed. Terminal states are terminal; 'enrolled' can still be
   cancelled because a confirmed place genuinely can fall through. */

/** The legal summer transition graph (terminal states have an empty allow-list). */
function mam_summer_transitions(): array {
    return [
        'submitted'  => ['confirmed', 'waitlisted', 'rejected', 'cancelled'],
        'waitlisted' => ['confirmed', 'rejected', 'cancelled'],
        'confirmed'  => ['enrolled', 'cancelled'],
        'enrolled'   => ['cancelled'],
        'cancelled'  => [],
        'rejected'   => [],
    ];
}

/** Pure predicate: is $from → $to a legal summer application transition? */
function mam_summer_can_transition(string $from, string $to): bool {
    $g = mam_summer_transitions();
    return isset($g[$from]) && in_array($to, $g[$from], true);
}

/**
 * Find (or create) the dedicated cohort for one summer run of a discipline.
 * Keyed on (course, term='SUMMER-<year>') so it can never collide with the
 * ordinary (course, level, programme) cohort mam_lms_ensure_cohort() manages —
 * a summer camp is its own run, not an intake of the year-round programme.
 */
function mam_summer_cohort(int $courseId, int $year, string $courseName): int {
    $term = 'SUMMER-' . $year;
    $row  = mam_db_row(
        'SELECT id FROM cohorts WHERE course_id = ? AND term = ? AND legacy_class_id IS NULL ORDER BY id LIMIT 1',
        [$courseId, $term]
    );
    if ($row) return (int)$row['id'];
    return mam_lms_create_cohort([
        'course_id' => $courseId,
        'term'      => $term,
        'title'     => $term . ' — ' . $courseName,
        'capacity'  => (int)mam_setting_num('summer_cohort_capacity', 30),
    ]);
}

/**
 * Provision a summer applicant so they exist on the LMS the way every other
 * enrolled learner does. Setting status='enrolled' used to write nothing but the
 * status column (plus an ID card), leaving "enrolled" summer students absent
 * from every roster, gradebook, mark sheet and attendance gate.
 *
 * Creates, idempotently:
 *   1. a ROSTER IDENTITY — a users row with status='roster', which both
 *      mam_login() and mam_session_user() reject (they require status='active'),
 *      and which mam_password_reset cannot target. Summer children get a roster
 *      presence, NOT portal access. An existing account for the same email is
 *      reused untouched, so a year-round student is never downgraded.
 *   2. the SUMMER-<year> cohort for their discipline (mam_summer_cohort).
 *   3. an active course_enrollment linking the two, with a lifecycle event.
 *
 * Never throws for a missing/unmapped discipline — it provisions the identity
 * and reports why no course enrolment was made.
 *
 * @return array{user_id:int,cohort_id:?int,enrollment_id:?int,reason:string}
 */
function mam_summer_enrol(int $summerAppId, ?int $actorId = null): array {
    $app = mam_db_row('SELECT * FROM summer_applications WHERE id = ?', [$summerAppId]);
    if (!$app) throw new InvalidArgumentException('summer_app_not_found');

    $email = strtolower(trim((string)$app['email']));
    if ($email === '') throw new RuntimeException('summer_app_no_email');
    $name  = trim((string)$app['first_name'] . ' ' . (string)$app['last_name']);
    $year  = (int)($app['session_year'] ?? 0) ?: (int)date('Y');

    // 1. Roster identity — reuse any existing account for this email as-is.
    $user = mam_db_row('SELECT id FROM users WHERE LOWER(email) = ?', [$email]);
    if ($user) {
        $userId = (int)$user['id'];
    } else {
        $userId = mam_db_insert('users', [
            'email' => $email,
            // Not a valid hash, so password_verify() can never match it. The
            // status gate above is the real lock; this is defence in depth.
            'password_hash' => 'roster:' . bin2hex(random_bytes(16)),
            'role'          => 'student',
            'name'          => $name !== '' ? $name : 'Summer participant',
            'status'        => 'roster',
        ]);
        mam_audit('summer_roster_identity', 'user', $userId, ['summer_app' => $summerAppId, 'email' => $email]);
    }

    $out = ['user_id' => $userId, 'cohort_id' => null, 'enrollment_id' => null, 'reason' => 'enrolled'];

    // 2. Resolve the discipline → course. No discipline is a data gap, not an error.
    $discipline = trim((string)($app['discipline'] ?? ''));
    if ($discipline === '') { $out['reason'] = 'no_discipline'; return $out; }
    $course = mam_db_row('SELECT id, name FROM courses WHERE code = ? AND active = 1', [$discipline]);
    if (!$course) { $out['reason'] = 'course_not_found'; return $out; }
    $courseId = (int)$course['id'];

    $cohortId = mam_summer_cohort($courseId, $year, (string)$course['name']);
    $out['cohort_id'] = $cohortId;

    // 3. Enrol into the summer cohort — idempotent, and no capacity divert: this
    //    is an admin decision on an already-confirmed place, so it mirrors the
    //    forced/converge paths rather than the self-service one.
    $existing = mam_db_row(
        "SELECT id FROM course_enrollments
          WHERE student_id = ? AND course_id = ? AND cohort_id = ? AND status IN ('pending','active')
          ORDER BY id LIMIT 1",
        [$userId, $courseId, $cohortId]
    );
    if ($existing) {
        $out['enrollment_id'] = (int)$existing['id'];
        $out['reason'] = 'already_enrolled';
        return $out;
    }

    $enrId = mam_db_insert('course_enrollments', [
        'student_id'  => $userId,
        'course_id'   => $courseId,
        'cohort_id'   => $cohortId,
        'status'      => 'active',
        'approved_by' => mam_lms_actor_id($actorId),
        'approved_at' => date('Y-m-d H:i:s'),
    ]);
    mam_lms_log_event($enrId, null, 'active', $actorId, 'summer_enrolled:' . (string)$app['reference']);
    mam_audit('summer_enrol', 'course_enrollment', $enrId, [
        'summer_app' => $summerAppId, 'student' => $userId, 'cohort' => $cohortId, 'year' => $year,
    ]);
    $out['enrollment_id'] = $enrId;
    return $out;
}
