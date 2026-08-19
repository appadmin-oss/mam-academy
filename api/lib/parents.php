<?php
/**
 * MAM Academy — parent accounts + guardian portal (LMS 2.0, PDF §26).
 * ─────────────────────────────────────────────────────────────
 * A parent is a real user (role 'parent'); parent_links maps guardians to
 * students (many-to-many). Parents are provisioned by staff and see ONLY their
 * linked children. The child summary is the §26 weekly picture — preparation,
 * teach-back, attendance and mastery — assembled from the existing engines
 * (check-in, pre-class, grading) so there is one source of truth.
 */
declare(strict_types=1);

/** Create (or return) a parent user for an email; grants the 'parent' role.
 *  Returns ['user' => row, 'password' => tempOrNull]. */
function mam_parent_provision(string $email, ?string $name = null, ?string $phone = null): array {
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('bad_email');
    $existing = mam_db_row('SELECT * FROM users WHERE email = ?', [$email]);
    if ($existing) {
        mam_user_ensure_role((int) $existing['id'], 'parent');
        return ['user' => $existing, 'password' => null];
    }
    $plain = bin2hex(random_bytes(5));
    $userId = mam_db_insert('users', [
        'email'         => $email,
        'password_hash' => password_hash($plain, PASSWORD_BCRYPT),
        'role'          => 'parent',
        'name'          => $name !== null && trim($name) !== '' ? trim($name) : 'Parent/Guardian',
        'phone'         => $phone,
        'status'        => 'active',
        'must_change_password' => 1,
    ]);
    mam_user_ensure_role($userId, 'parent');
    mam_audit('create', 'user', $userId, ['role' => 'parent']);
    return ['user' => mam_db_row('SELECT * FROM users WHERE id = ?', [$userId]), 'password' => $plain];
}

/** Link a guardian (by email) to a student. Provisions the parent if needed.
 *  Idempotent. Returns ['parent_id','student_id','password','created']. */
function mam_parent_link(string $parentEmail, int $studentId, string $relationship = 'guardian', ?int $by = null): array {
    if ($studentId <= 0) throw new InvalidArgumentException('bad_student');
    $stu = mam_db_row("SELECT id FROM users WHERE id = ?", [$studentId]);
    if (!$stu) throw new RuntimeException('student_not_found');
    $prov = mam_parent_provision($parentEmail);
    $parentId = (int) $prov['user']['id'];
    $exists = mam_db_row('SELECT id FROM parent_links WHERE parent_id = ? AND student_id = ?', [$parentId, $studentId]);
    $created = false;
    if (!$exists) {
        mam_db_insert('parent_links', ['parent_id' => $parentId, 'student_id' => $studentId, 'relationship' => $relationship ?: 'guardian', 'created_by' => $by]);
        $created = true;
        mam_audit('parent_link', 'user', $studentId, ['parent' => $parentId, 'by' => $by]);
    }
    return ['parent_id' => $parentId, 'student_id' => $studentId, 'password' => $prov['password'], 'created' => $created];
}

/** Remove a guardian↔student link. */
function mam_parent_unlink(int $parentId, int $studentId): void {
    mam_db()->prepare('DELETE FROM parent_links WHERE parent_id = ? AND student_id = ?')->execute([$parentId, $studentId]);
    mam_audit('parent_unlink', 'user', $studentId, ['parent' => $parentId]);
}

/** True if this parent is linked to this student. */
function mam_parent_can_view(int $parentId, int $studentId): bool {
    return (bool) mam_db_row('SELECT 1 FROM parent_links WHERE parent_id = ? AND student_id = ? LIMIT 1', [$parentId, $studentId]);
}

/**
 * The guardian ACCESS SCOPES (guardian redesign §3.2), narrowest first.
 *
 * `grants` is the closed set of things a scope reaches. It is a list rather than
 * a boolean per feature because the design's own table is cumulative — Standard
 * is "Billing plus marks", Full is "Standard plus acting for the child" — and
 * spelling that as independent flags is how a new feature ends up reachable by a
 * billing-only contact because nobody remembered to add a flag for it.
 *
 * ⚠️ **What NO scope grants** is the part to keep: practice logs, teach-back
 * recordings, and a teenager's messages with their instructor. `full` is not
 * "everything"; it is "everything the academy has assessed, plus the ability to
 * act". The design is explicit that this must be enforced server-side and SAID on
 * the screen, "so the boundary is understood rather than discovered" — and the
 * reason is not squeamishness: a 14-year-old who knows their practice log is read
 * at home stops using it honestly, and the log is the input the whole teaching
 * loop runs on.
 *
 * That refusal is spelled here as MAM_PARENT_NEVER, checked FIRST, so adding a
 * grant to `full` cannot accidentally open one of them.
 */
const MAM_PARENT_SCOPES = [
    'billing'  => [
        'label'  => 'Billing only',
        'blurb'  => 'Invoices and receipts. No marks and no attendance.',
        'grants' => ['invoices', 'receipts', 'payments', 'announcements'],
    ],
    'standard' => [
        'label'  => 'Standard',
        'blurb'  => 'Invoices and receipts, plus ratified reports, attendance and the timetable.',
        'grants' => ['invoices', 'receipts', 'payments', 'announcements',
                     'reports', 'attendance', 'timetable', 'messages_staff'],
    ],
    'full'     => [
        'label'  => 'Full access',
        'blurb'  => 'Everything Standard reaches, plus consents, and can act for the child.',
        'grants' => ['invoices', 'receipts', 'payments', 'announcements',
                     'reports', 'attendance', 'timetable', 'messages_staff',
                     'consents', 'act_for_child', 'data_requests'],
    ],
];

/**
 * Things no guardian scope ever reaches. Checked before the grants, so this is a
 * refusal and not the absence of a permission.
 */
const MAM_PARENT_NEVER = ['practice_logs', 'teachback_recordings', 'student_instructor_messages', 'takes'];

/** The narrowest scope — what an unrecognised stored value is treated as. */
const MAM_PARENT_SCOPE_DEFAULT = 'standard';

/**
 * This guardian's scope for this child, or null when they are not linked.
 *
 * An unrecognised stored value reads as the NARROWEST scope, never as the widest
 * and never as the default: SQLite carries no CHECK on the column (it cannot be
 * added by ALTER), so a hand-edited or migrated row must not be able to widen
 * access by containing something this code does not know about.
 */
function mam_parent_scope(int $parentId, int $studentId): ?string {
    $row = mam_db_row('SELECT access_scope FROM parent_links WHERE parent_id = ? AND student_id = ? LIMIT 1', [$parentId, $studentId]);
    if (!$row) return null;
    $scope = (string) ($row['access_scope'] ?? '');
    return isset(MAM_PARENT_SCOPES[$scope]) ? $scope : 'billing';
}

/**
 * May this guardian see/do `$what` for this child?
 *
 * The one predicate for the boundary. Every guardian-facing read and write goes
 * through it, so there is one place to read to know what a scope means — and one
 * place a new capability has to be added, which is the point.
 */
function mam_parent_scope_allows(int $parentId, int $studentId, string $what): bool {
    if (in_array($what, MAM_PARENT_NEVER, true)) return false;
    $scope = mam_parent_scope($parentId, $studentId);
    if ($scope === null) return false;
    return in_array($what, MAM_PARENT_SCOPES[$scope]['grants'], true);
}

/**
 * Set a guardian's scope. The only writer, and therefore the validator: the
 * SQLite column has no CHECK, so an unknown value must be refused here or it
 * reaches the database and is then read as `billing` forever.
 */
function mam_parent_scope_set(int $parentId, int $studentId, string $scope, ?int $by = null): void {
    if (!isset(MAM_PARENT_SCOPES[$scope])) throw new InvalidArgumentException('bad_scope');
    if (!mam_parent_can_view($parentId, $studentId)) throw new RuntimeException('not_linked');
    $before = mam_parent_scope($parentId, $studentId);
    mam_db_update('parent_links', [
        'access_scope' => $scope,
        'scope_set_by' => $by !== null && $by > 0 ? $by : null,
        'scope_set_at' => date('Y-m-d H:i:s'),
    ], ['parent_id' => $parentId, 'student_id' => $studentId]);
    mam_audit('parent_scope', 'user', $studentId, ['parent' => $parentId, 'from' => $before, 'to' => $scope, 'by' => $by]);
}

/**
 * What this guardian CANNOT see, and why — the design's explicit
 * "What you cannot see, and why" panel, as data.
 *
 * A panel, not a silence. The design's reasoning is that a boundary a family
 * discovers by finding an empty screen reads as a bug or as concealment, while a
 * boundary stated in a sentence reads as a policy — and this one IS a policy the
 * academy is willing to defend.
 */
function mam_parent_scope_explain(int $parentId, int $studentId): array {
    $scope = mam_parent_scope($parentId, $studentId);
    if ($scope === null) return [];
    $out = [];
    // The universal refusals come first, because they are the ones that hold
    // however wide the scope is.
    $out[] = [
        'what' => 'Practice logs and teach-back recordings',
        'why'  => 'These are the child\'s own working notes. No guardian scope reaches them — a learner '
                . 'who knows their practice log is read at home stops recording it honestly, and that log is '
                . 'what the teaching plan is built from.',
        'scope_could_change' => false,
    ];
    $out[] = [
        'what' => 'Messages between the child and their instructor',
        'why'  => 'A young person needs somewhere to ask a question they are embarrassed by. Anything about '
                . 'safeguarding, money, or a change that affects you reaches you directly and always.',
        'scope_could_change' => false,
    ];
    if ($scope === 'billing') {
        $out[] = ['what' => 'Reports, marks and attendance',
                  'why'  => 'Your access is set to Billing only. The academy office can widen it.',
                  'scope_could_change' => true];
    }
    if ($scope !== 'full') {
        $out[] = ['what' => 'Giving and withdrawing consents',
                  'why'  => 'Consents are held by the guardian with Full access for this child.',
                  'scope_could_change' => true];
    }
    return $out;
}

/** The students a parent is linked to, each with a compact weekly status. */
function mam_parent_children(int $parentId): array {
    /* The course and level travel with the child, because the design's child card
     * names them ("Classical Foundations, Level 2") and a card that can only say
     * the relationship tells a guardian nothing they did not already know.
     *
     * Dual-read is deliberate (CLAUDE.md gotcha #2): the newer cohort model FIRST,
     * falling back to the legacy classes model, so a student who exists in only
     * one of the two still gets a subtitle. The subquery is correlated rather than
     * joined so a student on two courses yields one row here and not two — this is
     * a list of children, not of enrolments. */
    $rows = mam_db_all(
        "SELECT u.id AS student_id, u.name AS student_name, u.enrollment_ref, pl.relationship,
                (SELECT c.name FROM course_enrollments ce
                   JOIN courses c ON c.id = ce.course_id
                  WHERE ce.student_id = u.id AND ce.status IN ('active','completed')
                  ORDER BY ce.id DESC LIMIT 1) AS course_name,
                (SELECT l.name FROM course_enrollments ce
                   LEFT JOIN levels l ON l.id = ce.level_id
                  WHERE ce.student_id = u.id AND ce.status IN ('active','completed')
                  ORDER BY ce.id DESC LIMIT 1) AS level_name,
                -- `classes.title`, NOT `classes.name`. The legacy table has no
                -- `name` column, and the mistake is not cosmetic: this subquery is
                -- on the critical path of the whole guardian portal, so a wrong
                -- column here 500s the children list rather than emptying one card.
                -- Caught by running the portal, which is the only thing that would
                -- have caught it — see CLAUDE.md gotcha #9 for the admin-side twin.
                (SELECT cl.title FROM enrollments e
                   JOIN classes cl ON cl.id = e.class_id
                  WHERE e.student_id = u.id ORDER BY e.id DESC LIMIT 1) AS legacy_class
           FROM parent_links pl JOIN users u ON u.id = pl.student_id
          WHERE pl.parent_id = ? ORDER BY u.name",
        [$parentId]
    );
    foreach ($rows as &$r) $r['weekly'] = mam_parent_weekly_status((int) $r['student_id']);
    unset($r);
    return $rows;
}

/**
 * The guardians linked to a student — the reverse of mam_parent_children().
 *
 * Anything that tells a student something their family also needs to act on
 * (a recital date, a cancellation, a lift to arrange) should reach the
 * guardians directly. The person holding the student login is very often not
 * the person with the car.
 *
 * Only ACTIVE accounts are returned: a deactivated guardian must stop
 * receiving a child's information the moment they are deactivated.
 */
function mam_parent_guardians_of(int $studentId): array {
    return mam_db_all(
        "SELECT u.id, u.name, u.email, pl.relationship
           FROM parent_links pl JOIN users u ON u.id = pl.parent_id
          WHERE pl.student_id = ? AND u.status = 'active'
          ORDER BY u.name",
        [$studentId]
    );
}

/**
 * A traffic-light label from a 0–100 value against simple bands.
 *
 * `null` is a FOURTH state, not the bottom of the scale. The signature used to
 * be `float`, so "we have not measured this yet" had nowhere to go and arrived
 * as 0.0 — which this function then reported as red. A guardian opening a
 * brand-new child's page was shown two red lights and told their child was at
 * 0%, which is not a display quirk: it is the academy telling a parent
 * something untrue about their child on the first screen they ever see.
 *
 * Every caller must render `none` as an absence ("—", "not measured yet"), and
 * never as a low score.
 */
function mam_parent_light(?float $pct): string {
    if ($pct === null) return 'none';
    if ($pct >= 80) return 'green';
    if ($pct >= 50) return 'amber';
    return 'red';
}

/**
 * The plain-language sentence behind a light. Returned alongside it because a
 * coloured dot is a colour-only signal (WCAG 1.4.1) — the meaning has to exist
 * in text too, and "not measured yet" is precisely the meaning a colour cannot
 * carry.
 */
function mam_parent_light_label(string $light, string $subject = 'This'): string {
    return [
        'green' => $subject . ' is on track',
        'amber' => $subject . ' needs attention',
        'red'   => $subject . ' is behind',
        'none'  => 'Not measured yet',
    ][$light] ?? 'Not measured yet';
}

/**
 * The §26 weekly status for a student: preparation, teach-back, attendance and
 * mastery traffic lights + headline numbers, drawn from the check-in / pre-class
 * / grading engines.
 */
function mam_parent_weekly_status(int $studentId): array {
    // Both of these stay NULL when there is nothing to measure. The mastery line
    // used to read `… : 0.0`, which took an engine that correctly says "not
    // assessed" and turned it into a failing grade on the way to the parent.
    $attendance = function_exists('mam_checkin_attendance_rate') ? mam_checkin_attendance_rate($studentId) : null;
    $streak     = function_exists('mam_checkin_streak') ? mam_checkin_streak($studentId) : 0;
    /* The MASTERY BAND — the only mastery figure a guardian is given.
     *
     * `mam_lms2_student_gpa()` returns a decimal average of whatever happens to
     * have been graded. `mam_mastery_score()` is the engine that decides whether
     * such a number may be reported at all: below MAM_MASTERY_MIN_COVERAGE there
     * is no composite, only subscores, and above it the result is a ten-point
     * BAND. This payload used to carry the gradebook mean instead, so the one
     * screen the coverage floor exists to protect was the screen not using it.
     *
     * ⚠️ Adding the band BESIDE the mean did not fix that, and this is the trap
     * worth remembering: the guardian screen went on rendering
     * `Math.round(w.mastery) + '%'`, the traffic light went on being derived from
     * the mean, and the payload merely gained a key nobody read. A withheld
     * number is not withheld while anything downstream can still print it — so
     * the mean is GONE from here, and `mastery_light` is now derived from the
     * band, which reports `none` when the evidence is too thin rather than
     * quantising a withheld composite into a colour.
     *
     * Everything published goes through mam_mastery_publishable() (one predicate,
     * shared with the student's own route) rather than an allowlist spelled out
     * here — an allowlist has to be re-audited every time the engine gains a key,
     * and the `bounds`/`coverage_pct` pair showed why: `raw = bounds.min × 100 ÷
     * coverage_pct` recovered the withheld figure from two allow-listed keys.
     */
    $band = null; $masLight = 'none';
    if (function_exists('mam_mastery_score')) {
        try {
            /* The UNCACHED engine on purpose. The guardian children list computes
             * a band for every child (the design puts one in each child's tile),
             * so this is one mastery computation per child per request and a memo
             * would save nothing while risking a stale read. That cost is
             * inherent to the screen; see mam_mastery_score_cached() for where a
             * memo does earn its keep. */
            $m = mam_mastery_publishable(mam_mastery_score($studentId));
            $band = [
                'band'          => $m['band'] ?? null,   // ['low'=>70,'high'=>79,'label'=>'70–79'] or null
                'level_label'   => $m['level_label'] ?? null,
                'level_blurb'   => $m['level_blurb'] ?? null,
                'headline'      => $m['headline'] ?? null,
                'sufficient'    => (bool) ($m['sufficient'] ?? false),
                'coverage_pct'  => $m['coverage_pct'] ?? null,
                'min_coverage'  => $m['min_coverage'] ?? null,
                'provisional'   => (bool) ($m['provisional'] ?? false),
                'bounds'        => $m['bounds'] ?? null,
                'components'    => $m['components'] ?? [],
                'missing'       => $m['missing'] ?? [],
                'withheld_reason' => $m['withheld_reason'] ?? null,
            ];
            // The light follows the LEVEL the band reports, never a percentage:
            // level 0 is "not assessed", which is an absence and not a red flag.
            $lvl = (int) ($m['level'] ?? 0);
            $masLight = $lvl === 0 ? 'none' : ($lvl >= 4 ? 'green' : ($lvl >= 3 ? 'amber' : 'red'));
        } catch (\Throwable $e) {
            // A guardian screen must render without it rather than 500.
            mam_log('warn', 'parent_mastery_band_failed', ['student' => $studentId, 'err' => $e->getMessage()]);
        }
    }

    // Preparation + teach-back from the next class card, when there is one.
    $prep = null; $teachback = null;
    if (function_exists('mam_preclass_next')) {
        $next = mam_preclass_next($studentId);
        if ($next) {
            $prep = $next['prep'] ?? null;
            $teachback = $next['teachback'] ? ($next['teachback']['status'] ?? null) : null;
        }
    }
    $attLight = mam_parent_light($attendance);
    return [
        'attendance_rate'  => $attendance,
        'attendance'       => $attLight,
        // The sentence travels with the colour, so the client never has to
        // invent one and a screen reader is not left with a bare dot.
        'attendance_label' => mam_parent_light_label($attLight, 'Attendance'),
        'streak'           => $streak,
        /* No `mastery` key. It carried the raw gradebook mean and every consumer
         * printed it as a percentage; the band below is the whole of what a
         * guardian is told about mastery. Deliberately absent rather than null,
         * so a client still reading it fails visibly instead of rendering `NaN%`
         * or, worse, `0%` — which is the failing grade this engine exists to
         * avoid reporting about an unassessed child. */
        'mastery_light'    => $masLight,
        'mastery_label'    => $masLight === 'none'
            ? 'Not enough assessed work yet'
            : mam_parent_light_label($masLight, 'Mastery'),
        'mastery_band'     => $band,
        'preparation'      => $prep,        // not_started / in_progress / completed
        'teachback'        => $teachback,   // null / submitted / reviewed
    ];
}

/**
 * Full child detail for the parent portal — the weekly status plus the shared
 * learner report (reused, PII kept since the guardian is authorised). Throws
 * 'forbidden' if the parent isn't linked to the student.
 */
function mam_parent_child_detail(int $parentId, int $studentId): array {
    if (!mam_parent_can_view($parentId, $studentId)) throw new RuntimeException('forbidden');
    $report = function_exists('mam_progress_report_data') ? mam_progress_report_data($studentId) : [];
    return [
        'student'   => $report['student'] ?? mam_db_row('SELECT id, name, enrollment_ref FROM users WHERE id = ?', [$studentId]),
        'weekly'    => mam_parent_weekly_status($studentId),
        'academic'  => $report['academic'] ?? null,
        'attendance'=> $report['attendance'] ?? null,
        'results'   => $report['results'] ?? [],
        'sessions'  => array_slice($report['sessions'] ?? [], 0, 10),
        'upcoming_sessions' => array_slice($report['upcoming_sessions'] ?? [], 0, 5),
        'reschedule_history'=> $report['reschedule_history'] ?? [],
        'announcements'     => array_slice($report['announcements'] ?? [], 0, 5),
        // §26 — instructor feedback on the child's work, so guardians see the
        // qualitative remarks, not only grades.
        'instructor_feedback' => mam_parent_instructor_feedback($studentId),
        // §3.1/§3.5 — badges earned and the gentle "wins" stream.
        'achievements' => function_exists('mam_ach_evaluate') ? mam_ach_evaluate($studentId) : [],
        'wins'         => function_exists('mam_wins_feed') ? mam_wins_feed($studentId, 12) : [],
        // §8.4 — the guardian's current consent settings for this child.
        'consent'      => function_exists('mam_consent_get') ? mam_consent_get($studentId) : null,
        // §1.7 — the recital programme (polished + performed pieces).
        'programme'    => function_exists('mam_repertoire_programme') ? mam_repertoire_programme($studentId) : [],
        // §5.2 — the four numbers a guardian actually opens the page for,
        // computed once here rather than derived ad hoc in the portal.
        'value_kpis'   => mam_parent_value_kpis($studentId, $report),
        // The Recital Hall — what the child is performing, when they step out,
        // and what they have already played. The single most useful thing a
        // guardian can be told, and it was previously nowhere in this payload.
        'recitals'     => function_exists('mam_recital_for_guardian') ? mam_recital_for_guardian($studentId) : ['upcoming' => [], 'past' => []],
    ];
}

/**
 * The guardian headline strip (§5.2): hours practised, attendance, badges this
 * month, and what's next.
 *
 * `$report` is the already-fetched progress-report payload — attendance and
 * upcoming sessions are read from it rather than re-queried, so the strip costs
 * exactly one extra query (the practice-minutes sum).
 *
 * Every field degrades to a null/zero rather than throwing: a guardian opening
 * a brand-new child's page must see "—", never a 500.
 */
function mam_parent_value_kpis(int $studentId, array $report = []): array {
    $minutes = 0;
    try {
        $minutes = (int) (mam_db_row('SELECT COALESCE(SUM(minutes),0) AS m FROM practice_logs WHERE student_id = ?', [$studentId])['m'] ?? 0);
    } catch (\Throwable $e) {}

    $badges = 0;
    try {
        // Awarded THIS calendar month. A rolling 30-day window would make the
        // number drift day to day; guardians read this as "since the 1st".
        $badges = (int) (mam_db_row(
            'SELECT COUNT(*) AS n FROM achievements WHERE student_id = ? AND awarded_at >= ?',
            [$studentId, date('Y-m-01') . ' 00:00:00']
        )['n'] ?? 0);
    } catch (\Throwable $e) {}

    $rate = null;
    if (isset($report['attendance']['rate']) && is_numeric($report['attendance']['rate'])) {
        $rate = round((float) $report['attendance']['rate'], 1);
    }

    $next = null;
    $up = $report['upcoming_sessions'] ?? [];
    if ($up && isset($up[0])) {
        $next = [
            'title'        => $up[0]['title'] ?? 'Next session',
            'scheduled_at' => $up[0]['scheduled_at'] ?? null,
            'cohort_title' => $up[0]['cohort_title'] ?? null,
        ];
    }

    return [
        // Kept as minutes AND hours: the portal shows hours, but a caller that
        // wants to sum or compare needs the unrounded figure.
        'practice_minutes' => $minutes,
        'practice_hours'   => round($minutes / 60, 1),
        'attendance_rate'  => $rate,
        'badges_this_month'=> $badges,
        'next_session'     => $next,
    ];
}

/** Recent instructor feedback comments on a student's submissions (§26). */
function mam_parent_instructor_feedback(int $studentId, int $limit = 10): array {
    try {
        return mam_db_all(
            "SELECT f.outcome, f.comments,
                    COALESCE(f.updated_at, f.created_at) AS at,
                    a.title AS assignment_title, iu.name AS instructor_name
               FROM feedback f
               JOIN submissions s ON s.id = f.submission_id
          LEFT JOIN assignments a ON a.id = s.assignment_id
          LEFT JOIN users iu ON iu.id = f.instructor_id
              WHERE s.student_id = ? AND f.comments IS NOT NULL AND TRIM(f.comments) <> ''
              ORDER BY COALESCE(f.updated_at, f.created_at) DESC
              LIMIT " . max(1, $limit),
            [$studentId]
        );
    } catch (\Throwable $e) {
        return [];
    }
}
