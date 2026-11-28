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

/** Is the summer programme configured to run at all? A window bound is the
 *  marker: with neither set there is no advertised intake, and `/summer` +
 *  the `/apply` chooser stay hidden. */
function mam_summer_is_configured(): bool {
    return (string)mam_setting('summer_app_open', '') !== ''
        || (string)mam_setting('summer_app_close', '') !== '';
}

/**
 * THE single source of truth for "can a summer application be submitted right
 * now" — used by the POST guard, `/summer`, the `/apply` chooser and
 * config-public alike.
 *
 * Previously each of those made its own decision and they disagreed: the pages
 * demanded BOTH window bounds while the endpoint (after the independent-bound
 * fix) honoured either, so the form could be hidden while the endpoint still
 * accepted — and an entirely unconfigured programme accepted applications
 * outright, for an intake that was advertised nowhere.
 */
function mam_summer_accepting(?string $today = null): bool {
    if (!mam_summer_is_configured()) return false;
    return mam_summer_window_open(
        $today ?? date('Y-m-d'),
        (string)mam_setting('summer_app_open', ''),
        (string)mam_setting('summer_app_close', '')
    );
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
/**
 * Summer runs as a CRASH-programme intake, not a system of its own.
 *
 * A constant rather than a setting: which programme the holiday intake belongs
 * to is a curriculum fact, and an academy able to point it somewhere else would
 * get summer cohorts that report under a programme nobody teaches them.
 */
const MAM_SUMMER_PROGRAMME_CODE = 'crash';

/**
 * The programme a summer intake belongs to.
 *
 * Summer is not a parallel system — it is a CRASH-programme intake that runs in
 * the holidays. Naming that here means a summer cohort carries a programme like
 * every other cohort, so it appears in the programme catalogue, the curriculum
 * screens and every report that groups by programme, instead of sitting outside
 * all of them because its `program_id` was NULL.
 *
 * Returns null when the crash programme has not been seeded, which is a data
 * gap, not an error: the cohort is still created and still teaches.
 */
function mam_summer_programme_id(): ?int {
    $row = mam_db_row("SELECT id FROM programs WHERE code = ? AND active = 1", [MAM_SUMMER_PROGRAMME_CODE]);
    return $row ? (int) $row['id'] : null;
}

function mam_summer_cohort(int $courseId, int $year, string $courseName): int {
    $term = 'SUMMER-' . $year;
    // The remembered instructor for this course. Without one the cohort belongs
    // to nobody, and mam_lms_instructor_cohorts() keys off cohort.instructor_id
    // OR enrolment.instructor_id — so summer students would sit on a roster no
    // instructor could open.
    $instructorId = function_exists('mam_lms_default_instructor')
        ? mam_lms_default_instructor($courseId, null) : null;

    $row = mam_db_row(
        'SELECT id, instructor_id FROM cohorts WHERE course_id = ? AND term = ? AND legacy_class_id IS NULL ORDER BY id LIMIT 1',
        [$courseId, $term]
    );
    if ($row) {
        // Adopt a remembered instructor onto an existing unowned summer cohort.
        if (empty($row['instructor_id']) && $instructorId) {
            try { mam_cohort_set_lead((int)$row['id'], (int)$instructorId, null); }
            catch (Throwable $e) { /* best-effort */ }
        }
        return (int)$row['id'];
    }

    // `summer_capacity` is the REGISTERED admin setting (Summer Programme group)
    // and documents 0 as unlimited. Passing null leaves cohorts.capacity NULL,
    // which mam_lms_cohort_fill_count() reads as effectively unbounded — a
    // literal 0 would make the cohort read as permanently full.
    $cap = (int)mam_setting_num('summer_capacity', 0);
    return mam_lms_create_cohort([
        'course_id'     => $courseId,
        'term'          => $term,
        'title'         => $term . ' — ' . $courseName,
        'instructor_id' => $instructorId,
        'capacity'      => $cap > 0 ? $cap : null,
        // A summer intake IS a crash-programme intake. Without this the cohort
        // has no programme and drops out of the catalogue, the curriculum plan
        // and every programme-grouped report.
        'program_id'    => mam_summer_programme_id(),
    ]);
}

/**
 * Provision a summer applicant so they exist on the LMS the way every other
 * enrolled learner does. Setting status='enrolled' used to write nothing but the
 * status column (plus an ID card), leaving "enrolled" summer students absent
 * from every roster, gradebook, mark sheet and attendance gate.
 *
 * Creates, idempotently:
 *   1. a FULL STUDENT ACCOUNT — status='active', a real bcrypt password, exactly
 *      what a year-round student gets. This reverses an earlier decision to give
 *      summer children a login-less `roster` identity: the academy's position is
 *      that a summer student is a student, so they get the portal, the gradebook,
 *      practice logging and their own attendance record like anybody else. The
 *      account is created with an UNUSABLE password — no plaintext exists — and
 *      `invite` is set so the caller can send a link to choose one. Passwords are
 *      never emailed.
 *   2. a GUARDIAN LINK where the application named a guardian email — a real
 *      `parent` account and a `parent_links` row, so the guardian portal works
 *      for a summer family on day one. This is the control that makes a child's
 *      login safe rather than merely convenient: an adult can see everything the
 *      child can, which is why it is created in the same breath and not left to
 *      an admin to remember.
 *   3. the SUMMER-<year> cohort for their discipline (mam_summer_cohort), which
 *      now carries the crash programme.
 *   4. an active course_enrollment linking the two, with a lifecycle event.
 *
 * An existing account for the same email is reused and never downgraded — a
 * year-round student who also joins the summer intake keeps their own password.
 *
 * Never throws for a missing/unmapped discipline — it provisions the identity
 * and reports why no course enrolment was made.
 *
 * @return array{user_id:int,cohort_id:?int,enrollment_id:?int,reason:string,
 *               invite:bool,guardian_id:?int,guardian_email:?string,
 *               guardian_invite:bool,account_created:bool}
 */
function mam_summer_enrol(int $summerAppId, ?int $actorId = null): array {
    $app = mam_db_row('SELECT * FROM summer_applications WHERE id = ?', [$summerAppId]);
    if (!$app) throw new InvalidArgumentException('summer_app_not_found');

    $email = strtolower(trim((string)$app['email']));
    if ($email === '') throw new RuntimeException('summer_app_no_email');
    $name  = trim((string)$app['first_name'] . ' ' . (string)$app['last_name']);
    $year  = (int)($app['session_year'] ?? 0) ?: (int)date('Y');

    // 1. A full student account — reuse any existing one for this email as-is.
    $invite = false;
    $created = false;
    $user = mam_db_row('SELECT id, avatar_url, status FROM users WHERE LOWER(email) = ?', [$email]);
    if ($user) {
        $userId = (int)$user['id'];
        // Backfill a missing avatar from the registration headshot — never overwrite
        // one the student chose for themselves.
        $hs = (string)($app['headshot_url'] ?? '');
        if ($hs !== '' && empty($user['avatar_url'])) {
            try { mam_db_update('users', ['avatar_url' => $hs], ['id' => $userId]); }
            catch (Throwable $e) { /* cosmetic only */ }
        }
        // A `roster` row from a previous summer, before summer students had
        // accounts. Promote it rather than leaving a child unable to sign in
        // because of when they first applied — but only from `roster`, so a
        // suspended or closed account is never quietly reopened by an enrolment.
        if ((string)($user['status'] ?? '') === 'roster') {
            mam_db_update('users', [
                // No plaintext exists: the invitation is the only way in.
                'password_hash'        => mam_auth_unusable_password(),
                'status'               => 'active',
                'must_change_password' => 0,
            ], ['id' => $userId]);
            $invite = true;
            mam_audit('summer_roster_promoted', 'user', $userId, ['summer_app' => $summerAppId]);
        }
    } else {
        // No temporary password. The family receives a link and chooses their
        // own — a temp password sits in an inbox for years, is usually reused, is
        // readable by anyone holding the phone, and cannot be revoked once sent.
        // For a children's academy that inbox is usually a shared parental one.
        $userId = mam_db_insert('users', [
            'email' => $email,
            // Carry the registration headshot onto the account, so the portal
            // avatar AND the ID card both have a face. Without this the photo
            // stayed only on summer_applications, and a card minted against the
            // USER rather than the application printed initials.
            'avatar_url' => (string)($app['headshot_url'] ?? '') !== '' ? (string)$app['headshot_url'] : null,
            'password_hash'        => mam_auth_unusable_password(),
            'role'                 => 'student',
            'name'                 => $name !== '' ? $name : 'Summer participant',
            'phone'                => (string)($app['phone'] ?? '') !== '' ? (string)$app['phone'] : null,
            'status'               => 'active',
            // Nothing to change: they have never had a password to change.
            'must_change_password' => 0,
        ]);
        $created = true;
        $invite = true;
        mam_audit('summer_student_account', 'user', $userId, ['summer_app' => $summerAppId, 'email' => $email]);
    }

    // `invite` says an account was created or opened here and therefore has no
    // password its owner knows. It is a FLAG, not a token: minting the token is
    // the caller's job, because only the caller knows whether this is the moment
    // to send mail — and a mint inside a provisioner makes the provisioner
    // untestable without a mailer.
    $out = ['user_id' => $userId, 'cohort_id' => null, 'enrollment_id' => null, 'reason' => 'enrolled',
            'invite' => $invite, 'guardian_id' => null, 'guardian_email' => null,
            'guardian_invite' => false, 'account_created' => $created, 'customer_id' => null];

    // 1b. Parity with year-round students: link the account to a `customers`
    //     record. A year-round student ALWAYS has a customer_id (their applicant
    //     row), which is what the portal profile, the auditer panel and the
    //     applicant identity spine resolve through. A summer child provisioned
    //     only from `summer_applications` had none — so their portal profile read
    //     empty and the account could not be joined to the rest of the app, even
    //     though they could log in and be assigned to a class. We create/reuse a
    //     customer from the application and fill a MISSING link only — never
    //     repoint an account already tied to a customer (a returning child who
    //     once applied year-round). Best-effort: a link failure must not stop a
    //     child being enrolled.
    try {
        $existingCust = mam_db_row('SELECT id FROM customers WHERE LOWER(email) = ?', [$email]);
        if ($existingCust) {
            $custId = (int)$existingCust['id'];
        } else {
            $custId = (int)mam_db_insert('customers', [
                'email'      => $email,
                'first_name' => (string)($app['first_name'] ?? ''),
                'last_name'  => (string)($app['last_name'] ?? ''),
                'phone'      => (string)($app['phone'] ?? '') !== '' ? (string)$app['phone'] : null,
                'dob'        => (string)($app['dob'] ?? '') !== '' ? (string)$app['dob'] : null,
            ]);
        }
        $cur = mam_db_row('SELECT customer_id FROM users WHERE id = ?', [$userId]);
        if ($custId && empty($cur['customer_id'])) {
            mam_db_update('users', ['customer_id' => $custId], ['id' => $userId]);
            mam_audit('summer_customer_linked', 'user', $userId, ['customer_id' => $custId, 'summer_app' => $summerAppId]);
        }
        $out['customer_id'] = $custId ?: ((int)($cur['customer_id'] ?? 0) ?: null);
    } catch (Throwable $e) {
        mam_log('warn', 'summer_customer_link_failed', ['summer_app' => $summerAppId, 'err' => $e->getMessage()]);
    }

    // 2. The guardian. Best-effort by design: a summer child must still be
    //    enrolled if the guardian's address is missing or malformed, and an
    //    admin can link one later from ?v=guardians.
    $gEmail = strtolower(trim((string)($app['guardian_email'] ?? '')));
    if ($gEmail !== '' && $gEmail !== $email && filter_var($gEmail, FILTER_VALIDATE_EMAIL)
        && function_exists('mam_parent_link')) {
        try {
            $link = mam_parent_link($gEmail, $userId, 'guardian', $actorId);
            $out['guardian_id']    = isset($link['parent_id']) ? (int)$link['parent_id'] : null;
            $out['guardian_email'] = $gEmail;
            // True only when the guardian account was created just now, so a
            // guardian who already had one is never sent an invitation to an
            // account they are already using.
            $out['guardian_invite'] = !empty($link['account_created']);
        } catch (Throwable $e) {
            mam_log('warn', 'summer_guardian_link_failed', ['summer_app' => $summerAppId, 'err' => $e->getMessage()]);
        }
    }

    // 3. Resolve the discipline → course. No discipline is a data gap, not an error.
    $discipline = trim((string)($app['discipline'] ?? ''));
    if ($discipline === '') { $out['reason'] = 'no_discipline'; return $out; }
    $course = mam_db_row('SELECT id, name FROM courses WHERE code = ? AND active = 1', [$discipline]);
    if (!$course) { $out['reason'] = 'course_not_found'; return $out; }
    $courseId = (int)$course['id'];

    $cohortId = mam_summer_cohort($courseId, $year, (string)$course['name']);
    $out['cohort_id'] = $cohortId;

    // 4. Enrol into the summer cohort — idempotent, and no capacity divert: this
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

    // Name the instructor on the enrolment too — the instructor cohort view and
    // the gradebook both resolve teaching rights through it.
    $cohortInstructor = mam_db_row('SELECT instructor_id FROM cohorts WHERE id = ?', [$cohortId]);
    $enrId = mam_db_insert('course_enrollments', [
        'student_id'    => $userId,
        'course_id'     => $courseId,
        'cohort_id'     => $cohortId,
        'instructor_id' => !empty($cohortInstructor['instructor_id']) ? (int)$cohortInstructor['instructor_id'] : null,
        'status'        => 'active',
        'approved_by'   => mam_lms_actor_id($actorId),
        'approved_at'   => date('Y-m-d H:i:s'),
    ]);
    mam_lms_log_event($enrId, null, 'active', $actorId, 'summer_enrolled:' . (string)$app['reference']);
    mam_audit('summer_enrol', 'course_enrollment', $enrId, [
        'summer_app' => $summerAppId, 'student' => $userId, 'cohort' => $cohortId, 'year' => $year,
    ]);
    $out['enrollment_id'] = $enrId;
    return $out;
}
