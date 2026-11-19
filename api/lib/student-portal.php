<?php
/**
 * The student portal v2 — one read provider per screen.
 *
 * Built from the design's student mobile frame
 * (`docs/design/2026-08-portal-redesign/portals-mobile.dc.html`,
 * `data-phone="studentv2"`) and `HANDOFF-student-guardian.md` §2, whose IA is
 * the bottom bar **Home · Train · Learn · Attend · More**.
 *
 * ── WHY A PROVIDER LAYER AND NOT MORE ROUTES ──────────────────────────────
 * Same reason as `instructor-portal.php`, which this mirrors deliberately:
 * everything these screens need already exists somewhere — `checkin.php` has
 * the class clock, `mastery.php` the band, `preclass.php` the teach-back,
 * `practice.php` the streak, `makeup.php` the credits, `lms.php` the courses
 * and announcements. What did not exist is a single answer to "what should I
 * do now", and assembling that in the browser is six round trips on a phone
 * on Nigerian mobile data. One provider per screen, one request per screen.
 *
 * ⚠️ The handoff specifies an API surface that does NOT exist here
 * (`/api/practice/target`, `/api/practice/take`, `/api/practice/take/{id}/marker`).
 * The repo's equivalents — `POST /api/student/practice-log`, `/api/student/take`,
 * `/api/student/submit` — are already built, rate-limited, CSRF-gated and
 * tested, so the screens wire to those. Inventing a parallel surface to match
 * a document would have given the academy two ways to record one take.
 *
 * ── THE REFUSALS THIS FILE KEEPS ──────────────────────────────────────────
 * The handoff marks several refusals **⛔ by design**. Four of them are
 * enforced here rather than in the markup, because a refusal a template can
 * forget is not a refusal:
 *
 *  1. **No cohort effort board, ever.** §2.3. The leaderboard was removed from
 *     this codebase in 2026-09 and `test_engagement2.php` guards its absence;
 *     this file adds no way back in. The personal streak stays — it is a
 *     commitment device, not a ranking — and `streak_private` carries the
 *     sentence saying nobody else sees it, so the screen cannot omit it.
 *  2. **No automatic scoring of a child's playing.** §2.1. Takes are recorded
 *     and hosted for a human to mark. There is no score field on a take here.
 *  3. **Intervention flags are staff-side only.** §2.3. `mam_sportal_*` returns
 *     no risk score, no at-risk label and no flag — a student sees the booking
 *     with a named human, never the reason it was made.
 *  4. **The mastery band is the published shape, never the raw score.** §2.2
 *     and gotcha #12. Everything student-facing goes through
 *     `mam_mastery_publishable()`, which strips `raw_score` and strips
 *     `bounds` while the score is withheld. This file never reads
 *     `$m['raw_score']`, and a test asserts it never starts.
 */
declare(strict_types=1);

/** The five destinations in the bottom bar, in order. */
const MAM_SPORTAL_TABS = ['home', 'train', 'learn', 'attend', 'more'];

/** Every screen the portal can route to, bar included. */
const MAM_SPORTAL_SCREENS = ['home', 'train', 'learn', 'attend', 'more', 'money', 'repertoire'];

/* ─────────────────────────────────────────────────────────────────────────────
   Shared helpers
   ───────────────────────────────────────────────────────────────────────── */

/** Minutes until a timestamp. Negative = already started, which is a fact the
 *  client formats ("started 3 minutes ago"), not an error. */
function mam_sportal_starts_in(?string $at): ?int {
    if (!$at) return null;
    $ts = strtotime($at);
    return $ts === false ? null : (int) round(($ts - time()) / 60);
}

/**
 * The student's active enrolments, as the screens need them.
 *
 * Dual-read (gotcha #2): a student can hold a legacy `enrollments` row, a
 * `course_enrollments` row, or both, and a portal that reads one model shows
 * half of them nothing. The cohort id is what every other provider here keys
 * off, so a legacy-only enrolment carries a null one rather than being dropped.
 */
function mam_sportal_enrolments(int $studentId): array {
    $out = [];
    foreach (mam_db_all(
        "SELECT ce.id, ce.cohort_id, ce.status, c.code AS course_code, c.name AS course_name,
                l.name AS level_name, co.title AS cohort_title
           FROM course_enrollments ce
           JOIN courses c   ON c.id  = ce.course_id
      LEFT JOIN levels  l   ON l.id  = ce.level_id
      LEFT JOIN cohorts co  ON co.id = ce.cohort_id
          WHERE ce.student_id = ? AND ce.status IN ('active','completed')
          ORDER BY ce.id DESC", [$studentId]) as $r) {
        $out[] = [
            'id'        => (int) $r['id'],
            'cohort_id' => $r['cohort_id'] === null ? null : (int) $r['cohort_id'],
            'status'    => (string) $r['status'],
            'course'    => (string) $r['course_name'],
            'code'      => (string) $r['course_code'],
            'level'     => $r['level_name'] ?: null,
            'cohort'    => $r['cohort_title'] ?: null,
            'model'     => 'course',
        ];
    }
    foreach (mam_db_all(
        "SELECT e.id, e.status, cl.title AS class_name
           FROM enrollments e
      LEFT JOIN classes cl ON cl.id = e.class_id
          WHERE e.student_id = ? AND e.status IN ('active','completed')
          ORDER BY e.id DESC", [$studentId]) as $r) {
        $out[] = [
            'id'        => (int) $r['id'],
            'cohort_id' => null,
            'status'    => (string) $r['status'],
            'course'    => $r['class_name'] ?: 'Your class',
            'code'      => null,
            'level'     => null,
            'cohort'    => $r['class_name'] ?: null,
            'model'     => 'class',
        ];
    }
    return $out;
}

/**
 * What the student owes, in USD cents, and whether anything is overdue.
 *
 * ⚠️ Reads the punctuality ledger through `MAM_ACCT_OWED` rather than
 * `status = 'outstanding'` — `disputed` is non-terminal and still owed
 * (schema v81 note), and a portal that showed a disputed charge as settled
 * would tell a student they owe nothing right up to the day it is enforced.
 */
function mam_sportal_owed(int $studentId): array {
    /* ⚠️ TWO ledgers, and they must not be mixed into one figure. This used to
       return the punctuality total as `cents` while taking `due_on` from
       `fee_invoices` — so the money screen printed a $0.00 headline with an
       invoice's due date under it, directly above a row chipped "Overdue" for
       $485. The biggest number on the screen was answering a different
       question from the date beside it.
       `cents` is now everything genuinely outstanding; the two halves travel
       separately so the screen can name each. */
    $charges = 0;
    try { $charges = mam_accountability_outstanding_cents('student', $studentId); } catch (Throwable $e) { /* no ledger yet */ }

    $fees = 0; $due = null;
    try {
        $row = mam_db_row(
            "SELECT MIN(due_on) AS d,
                    COALESCE(SUM(MAX(total_minor - paid_minor, 0)), 0) AS owed
               FROM fee_invoices
              WHERE student_id = ? AND status IN ('issued','part_paid') AND currency = 'USD'",
            [$studentId]
        );
        $due  = $row && $row['d'] ? (string) $row['d'] : null;
        /* `total_minor` is in the invoice's own currency; only a USD invoice
           can be added to a USD-cent ledger. A naira invoice is shown on its
           own row rather than summed into this figure — the v61 column exists
           because summing NGN into a USD total is exactly the bug. */
        $fees = (int) ($row['owed'] ?? 0);
    } catch (Throwable $e) { /* fees layer not in use on this install */ }

    return [
        'cents'         => $charges + $fees,
        'charges_cents' => $charges,
        'fees_cents'    => $fees,
        'due_on'   => $due,
        /* ⛔ by design (handoff §3.4): nothing is withheld from a child over
           money, and the screen says so where the figure appears rather than
           leaving the student to wonder. */
        'notice'   => 'Nothing is withheld from a student over money. If this is hard right now, talk to us.',
    ];
}

/* ─────────────────────────────────────────────────────────────────────────────
   Home — one forward-looking action, and what needs the student today
   ───────────────────────────────────────────────────────────────────────── */

/**
 * The Home payload.
 *
 * The design's Home is "Good morning, Ada" plus *one* next step. `next_step`
 * is therefore a single object, not a list — a screen that offers four equal
 * next steps has not chosen one.
 */
function mam_sportal_home(int $studentId): array {
    $u = mam_db_row('SELECT name, avatar_url FROM users WHERE id = ?', [$studentId]) ?: [];

    $next = null;
    try { $next = mam_checkin_next_class($studentId); } catch (Throwable $e) { $next = null; }
    /* The check-in boundaries come from `mam_checkin_window()` and nowhere
       else — `mam_checkin_next_class()` already carries it. The old portal
       hardcoded READY = 15 in the browser, so an academy that changed the
       setting got a page that disagreed with the gate it was describing
       (schema v81 note). Don't recompute it here either: two callers of one
       predicate is still one source, two computations of it are two. */
    if ($next) $next['starts_in_min'] = mam_sportal_starts_in($next['scheduled_at'] ?? null);

    $practice = ['streak' => 0, 'weekMin' => 0, 'weekDays' => []];
    try { $practice = mam_practice_stats($studentId)['practiceStats'] ?? $practice; } catch (Throwable $e) {}

    $announce = [];
    try { $announce = array_slice(mam_student_announcements($studentId, 3), 0, 3); } catch (Throwable $e) {}

    return [
        'me' => [
            'name'     => (string) ($u['name'] ?? 'Student'),
            'first'    => trim(explode(' ', (string) ($u['name'] ?? ''))[0] ?? ''),
            'avatar'   => $u['avatar_url'] ?: null,
        ],
        'next'      => $next,
        'next_step' => mam_sportal_next_step($studentId, $next),
        'practice'  => $practice,
        /* ⛔ by design (handoff §2.3): the streak is a commitment device, not a
           ranking. The sentence travels with the number so the screen cannot
           show one without the other. */
        'streak_private' => 'Your streak is yours. Nobody else can see it — not your class, not your instructor.',
        'owed'      => mam_sportal_owed($studentId),
        'announcements' => $announce,
    ];
}

/**
 * ONE next step, chosen in priority order, each with a destination.
 *
 * ⛔ by design (handoff §2.3): where a step needs a person it names one. "An
 * assigned human is the most consistent feature across the retention
 * research; automation without one hurts." There is deliberately no branch
 * that computes a risk score, and none that shows the student why a step was
 * chosen for them beyond what they can already see.
 */
function mam_sportal_next_step(int $studentId, ?array $next): ?array {
    /* 1 · A class that can be checked into right now beats everything.
       ⚠️ `mam_checkin_window()` reports a five-way `state`, not a boolean —
       `open` is only the FIRST of the three states in which the door is
       actually open (`open` → `readiness_passed` → `in_progress`), so testing
       for a truthy `$w['open']` both misses the key entirely and would skip
       the two states a late student is most likely to be in. */
    $w = $next['window'] ?? null;
    $alreadyIn = !empty($next['attendance']['checked_in_at']);
    if ($w && !$alreadyIn && in_array((string) ($w['state'] ?? ''), ['open', 'readiness_passed', 'in_progress'], true)) {
        return ['kind' => 'checkin', 'screen' => 'attend',
                'title' => 'Check in to ' . (string) ($next['title'] ?? $next['cohort_title'] ?? 'your class'),
                'body'  => (string) ($w['message'] ?? ''), 'cta' => 'Check in'];
    }
    // 2 · Preparation the instructor has set for the next class.
    /* ⚠️ `mam_preclass_next()` returns `{session, module, prep, teachback}` —
       NOT a flat session row. Reading `$pre['title']`/`$pre['prepared_at']` off
       the top level is silently empty rather than an error, which is exactly
       how a next step reading "Prepare for " shipped once. */
    try {
        $pre = mam_preclass_next($studentId);
        $mod = $pre['module'] ?? null;
        if ($mod && (string) ($pre['prep'] ?? 'not_started') !== 'completed') {
            return ['kind' => 'prepare', 'screen' => 'train',
                    'title' => 'Prepare for ' . (string) ($mod['title'] ?? 'your next class'),
                    'body'  => 'Your instructor set this before the session.', 'cta' => 'Open'];
        }
    } catch (Throwable $e) {}
    // 3 · Practice not yet logged today.
    try {
        $today = mam_db_row('SELECT id FROM practice_logs WHERE student_id = ? AND logged_on = ?',
            [$studentId, date('Y-m-d')]);
        if (!$today) {
            return ['kind' => 'practice', 'screen' => 'train',
                    'title' => 'Practise today',
                    'body'  => 'Even ten minutes keeps the thread.', 'cta' => 'Open the practice room'];
        }
    } catch (Throwable $e) {}
    // 4 · Nothing outstanding is a real answer, not an empty screen.
    return ['kind' => 'clear', 'screen' => 'learn',
            'title' => 'You are up to date',
            'body'  => 'Nothing needs you right now. Have a look at where you stand.', 'cta' => 'Where I stand'];
}

/* ─────────────────────────────────────────────────────────────────────────────
   Train — the deliberate-practice loop (handoff §2.1)
   ───────────────────────────────────────────────────────────────────────── */

/**
 * Practice room · teach it back · keep it warm, in that fixed order.
 *
 * ⛔ by design: no automatic pitch/rhythm scoring anywhere in this payload.
 * A take carries its artifact and its status; a human marks it.
 */
function mam_sportal_train(int $studentId): array {
    $target = null;
    $teachback = null;
    try {
        $pre = mam_preclass_next($studentId);
        $mod = $pre['module'] ?? null;
        if ($mod) {
            $target = [
                'lesson_id'   => (int) ($mod['lesson_id'] ?? 0),
                'title'       => (string) ($mod['title'] ?? ''),
                'week_no'     => $mod['week_no'] ?? null,
                'objectives'  => $mod['objectives'] ?? null,
                'body'        => (string) ($mod['prep_instructions'] ?? ''),
                'resources'   => $mod['resources'] ?? [],
                'required'    => !empty($mod['teachback_required']),
                'prep'        => (string) ($pre['prep'] ?? 'not_started'),
                'session'     => $pre['session'] ?? null,
                'session_at'  => $pre['session']['scheduled_at'] ?? null,
            ];
            /* The teach-back comes back on the SAME call, already keyed to this
               module. Re-fetching it means remembering that
               `mam_preclass_teachback_for()` takes (lesson, student) in that
               order — a swap PHP accepts silently and answers null to. */
            $teachback = $pre['teachback'] ?? null;
        }
    } catch (Throwable $e) {}

    $practice = ['streak' => 0, 'weekMin' => 0, 'total' => 0, 'weekDays' => []];
    $logs = [];
    try {
        $st = mam_practice_stats($studentId);
        $practice = $st['practiceStats'] ?? $practice;
        $logs = array_slice($st['practice'] ?? [], 0, 6);
    } catch (Throwable $e) {}

    // Recent takes, so a student can hear what they sent. No score field.
    $takes = [];
    try {
        $takes = mam_db_all(
            "SELECT s.id, s.artifact_url, s.artifact_kind, s.status, s.submitted_at, a.title
               FROM submissions s
          LEFT JOIN assignments a ON a.id = s.assignment_id
              WHERE s.student_id = ? AND s.artifact_kind = 'audio'
              ORDER BY s.id DESC LIMIT 5", [$studentId]);
    } catch (Throwable $e) {}

    return [
        'target'    => $target,
        'practice'  => $practice,
        'logs'      => $logs,
        'takes'     => $takes,
        'teachback' => $teachback,
        /* ⛔ by design (handoff §2.1): exactly three self-checks, and the score
           is FORMATIVE — it never enters the mastery band. The original
           six-dimension rubric was overlapping constructs scored by one
           untrained rater. The three live here so a template cannot grow a
           fourth, and the sentence travels with them. */
        'teachback_checks' => [
            ['key' => 'why',     'label' => 'I explained WHY it works, not just what to do'],
            ['key' => 'example', 'label' => 'I gave an example of my own'],
            ['key' => 'stuck',   'label' => 'I said where I got stuck'],
        ],
        'teachback_notice' => 'This is for your own learning. It is never marked and never counts towards your band.',
        /* ⛔ by design (handoff §2.1): retrieval practice is opt-in, never
           scored, and skipping costs nothing. The moment it is graded it stops
           being retrieval practice and becomes surveillance. */
        'warm_notice' => 'Opt-in, never scored, and skipping costs you nothing.',
        'warm_intervals' => [
            ['key' => 'forgot',  'label' => "I'd forgotten it", 'days' => 1],
            ['key' => 'slow',    'label' => 'Got there, slowly', 'days' => 4],
            ['key' => 'instant', 'label' => 'Straight away',     'days' => 14],
        ],
    ];
}

/* ─────────────────────────────────────────────────────────────────────────────
   Learn — where I stand (handoff §2.2)
   ───────────────────────────────────────────────────────────────────────── */

/**
 * The mastery band, its coverage, and the competency rows.
 *
 * Five rules from §2.2, all load-bearing and all enforced here rather than in
 * the template:
 *   1. a BAND, never a decimal — `mam_mastery_publishable()` owns that;
 *   2. coverage on the face, as a sentence this function composes;
 *   3. "not enough evidence yet" is a VISIBLE row, never a silent zero and
 *      never renormalised away — so an `insufficient` component is passed
 *      through rather than filtered out;
 *   4. horizontal bars per competency, no radar chart (the markup's job, but
 *      the payload gives per-competency pct so a radar is never the easy
 *      option);
 *   5. 80% is labelled a CONVENTION the academy chose and reviews.
 */
function mam_sportal_learn(int $studentId): array {
    $published = null;
    try {
        /* `mam_mastery_publishable()` is the one predicate for what a student
           may be shown: it strips `raw_score`, and strips `bounds` while the
           score is withheld. This file never touches the raw figure. */
        $published = mam_mastery_publishable(mam_mastery_score($studentId));
    } catch (Throwable $e) { $published = null; }

    $coverage = null;
    $unevidenced = [];
    if ($published) {
        $ev  = count($published['components'] ?? []);
        $tot = $ev + count($published['missing'] ?? []);
        $pct = (int) round((float) ($published['coverage_pct'] ?? 0));
        $coverage = [
            'evidenced'  => $ev,
            'total'      => $tot,
            'weight_pct' => $pct,
            /* §2.2 rule 2 — coverage ON THE FACE, in words, not a figure the
               student has to interpret against a threshold elsewhere. */
            'sentence'   => $tot > 0
                ? 'Based on ' . $ev . ' of ' . $tot . ' parts of your work — ' . $pct . '% of what counts.'
                : null,
        ];
        /* §2.2 rule 3 — "not enough evidence yet" is a VISIBLE ROW, never a
           silent zero and never renormalised away. `mam_mastery_score()`
           returns the unevidenced components as bare keys in `missing`; they
           are resolved to labelled rows here so the screen has something it
           must render rather than something it may skip. A zero here would
           report an unassessed beginner as failing. */
        foreach (($published['missing'] ?? []) as $key) {
            $meta = MAM_MASTERY_COMPONENTS[$key] ?? null;
            if (!$meta) continue;
            $unevidenced[] = [
                'code'       => (string) $key,
                'name'       => (string) $meta['label'],
                'weight_pct' => (int) $meta['weight'],
                'level'      => 'insufficient',
                'pct'        => null,
            ];
        }
    }

    $enrolments = mam_sportal_enrolments($studentId);

    $weeks = [];
    try {
        $cohortIds = array_values(array_filter(array_map(
            static fn(array $e): ?int => $e['cohort_id'], $enrolments)));
        if ($cohortIds) {
            $ph = implode(',', array_fill(0, count($cohortIds), '?'));
            $weeks = mam_db_all(
                "SELECT id, title, week_no, published FROM lessons
                  WHERE cohort_id IN ($ph) AND published = 1
                  ORDER BY week_no ASC, id ASC LIMIT 30", $cohortIds);
        }
    } catch (Throwable $e) {}

    return [
        'mastery'    => $published,
        'coverage'   => $coverage,
        'unevidenced'=> $unevidenced,
        /* §2.2 rule 5. The threshold is the academy's convention, reviewed —
           not a fact about music — and it says so where it is shown. */
        'convention' => 'The 80% mark is a standard the academy chose and reviews. It is not a fact about music.',
        'enrolments' => $enrolments,
        'weeks'      => $weeks,
        /* The syllabus as lessons, with where this student stands on each
           (api/lib/lessons.php). In the same payload so the screen is still one
           request; a failure here costs the card, never the screen. */
        'lessons'    => (function () use ($studentId) {
            try { return function_exists('mam_lesson_student_board') ? mam_lesson_student_board($studentId) : []; }
            catch (Throwable $e) { return []; }
        })(),
        /* Tier certificates, recent exams and an exam still open (lessons-learn.php,
           lesson-exams.php, v98). Same rule: a failure costs the card. */
        'lesson_extra' => (function () use ($studentId) {
            try {
                if (!function_exists('mam_exam_history')) return null;
                $open = mam_exam_open_sitting($studentId);
                return ['certificates' => mam_lesson_certificates($studentId), 'exams' => mam_exam_history($studentId, 6),
                    'open_exam' => $open ? (int) $open['id'] : null];
            } catch (Throwable $e) { return null; }
        })(),
    ];
}

/* ─────────────────────────────────────────────────────────────────────────────
   Attend — the class clock and the record
   ───────────────────────────────────────────────────────────────────────── */

function mam_sportal_attend(int $studentId): array {
    $dash = [];
    try { $dash = mam_checkin_dashboard($studentId); } catch (Throwable $e) {}

    /* The actionable card is `mam_checkin_next_class()`, not the dashboard's
       `next_session` summary: only the former carries the §9 `window`, and a
       screen that offers a check-in button without the window would offer it
       at every hour of every day. */
    $next = null;
    try { $next = mam_checkin_next_class($studentId); } catch (Throwable $e) {}
    if ($next) $next['starts_in_min'] = mam_sportal_starts_in($next['scheduled_at'] ?? null);

    $cfg = [];
    try {
        $c = mam_checkin_config();
        $cfg = [
            'self_enabled'     => !empty($c['self_enabled']),
            'geofence_enabled' => !empty($c['geofence_enabled']),
            'open_before_min'  => (int) $c['open_before_min'],
            'ready_before_min' => (int) $c['ready_before_min'],
            'modes'            => defined('MAM_ATTENDANCE_MODES') ? MAM_ATTENDANCE_MODES : ['onsite', 'online'],
        ];
    } catch (Throwable $e) {}

    $recent = [];
    try {
        $recent = mam_db_all(
            "SELECT sa.status, sa.minutes_late, sa.mode, cs.title, cs.scheduled_at
               FROM session_attendance sa JOIN class_sessions cs ON cs.id = sa.session_id
              WHERE sa.student_id = ? ORDER BY cs.scheduled_at DESC LIMIT 12", [$studentId]);
    } catch (Throwable $e) {}

    $makeup = [];
    try { $makeup = mam_makeup_suggestions($studentId, 5); } catch (Throwable $e) {}

    return [
        'today'      => $dash['today'] ?? null,
        'next'       => $next,
        'week'       => $dash['week'] ?? null,
        'total_weeks'=> $dash['total_weeks'] ?? null,
        'cohort'     => $dash['cohort'] ?? null,
        'rate'       => $dash['attendance_rate'] ?? null,
        'config'     => $cfg,
        'recent'     => $recent,
        'makeup'     => $makeup,
        /* Handoff §3.5, stated where the credit is spent rather than in a
           policy page nobody opens. */
        'makeup_rule' => "24 hours' notice earns a credit · five a year · they expire and cannot be transferred or refunded. A lesson your teacher cancels always credits and never counts against your five.",
    ];
}

/* ─────────────────────────────────────────────────────────────────────────────
   Money, repertoire, and the More sheet
   ───────────────────────────────────────────────────────────────────────── */

function mam_sportal_money(int $studentId): array {
    $invoices = [];
    try {
        $invoices = mam_db_all(
            'SELECT id, public_ref, status, total_minor, currency, due_on, issued_on
               FROM fee_invoices WHERE student_id = ? ORDER BY id DESC LIMIT 12', [$studentId]);
    } catch (Throwable $e) {}
    /* ⚠️ `mam_accountability_list()` takes a STATUS, not a subject — passing
       'student' asks for charges whose status is 'student' and answers an
       empty list forever. `_ledger()` is the per-subject reader, and it also
       attaches each charge's open exception request, so the screen can say
       "waiver requested" rather than showing a debt with no way to act on it. */
    $ledger = ['charges' => [], 'outstanding_usd_cents' => 0, 'waived_usd_cents' => 0, 'pending_usd_cents' => 0];
    try { $ledger = mam_accountability_ledger('student', $studentId, 24); } catch (Throwable $e) {}
    return [
        'owed'     => mam_sportal_owed($studentId),
        'invoices' => $invoices,
        'ledger'   => $ledger,
        /* §15: a student may raise an exception against their OWN charge. The
           reason codes are the server's, so the screen cannot offer a seventh. */
        'exception_reasons' => defined('MAM_ACCT_EXCEPTION_REASONS') ? MAM_ACCT_EXCEPTION_REASONS : [],
    ];
}

function mam_sportal_repertoire(int $studentId): array {
    $list = [];
    try { $list = mam_repertoire_list($studentId); } catch (Throwable $e) {}
    return ['repertoire' => $list];
}

/**
 * The More sheet's badges come from the SCREENS themselves, never a count
 * endpoint of their own, so the sheet cannot disagree with what it links to.
 */
function mam_sportal_more(int $studentId): array {
    $owed = mam_sportal_owed($studentId);
    $takes = 0;
    try {
        $r = mam_db_row("SELECT COUNT(*) c FROM submissions WHERE student_id = ? AND status = 'submitted'", [$studentId]);
        $takes = (int) ($r['c'] ?? 0);
    } catch (Throwable $e) {}
    return ['owed_cents' => (int) $owed['cents'], 'awaiting_marks' => $takes];
}
