<?php
/**
 * The instructor portal v2 — one read provider per screen.
 *
 * Built from the design's instructor mobile frame
 * (`docs/design/2026-08-portal-redesign/portals-mobile.dc.html`,
 * `data-phone="instructorv2"`): Home · Register · Marking · Students · More,
 * with Teach-backs, Termly report and Earnings behind More.
 *
 * ── WHY A PROVIDER LAYER AND NOT MORE ROUTES ──────────────────────────────
 * Everything these screens need already exists somewhere — `preclass.php` has
 * the teach-back queue, `wallet.php` the earnings statement, `vetting.php` the
 * clearance, `reports.php` the termly report, `marking.php` the grade sheet.
 * What did not exist is a single answer to "what does this instructor need to
 * do today", and assembling that in the browser would mean six round trips on
 * a phone on Nigerian mobile data. One provider per screen, one request per
 * screen.
 *
 * ── THE REFUSALS THIS FILE KEEPS ──────────────────────────────────────────
 * Three of the design's refusals are enforced here rather than in the markup,
 * because a refusal a template can forget is not a refusal:
 *
 *  1. **No risk score on the Students screen.** The design is explicit: "There
 *     is no risk score here — a label a child could be given is not a
 *     substitute for you ringing a guardian." So `mam_iportal_students()`
 *     returns a BAND and, where something needs a person, a plain sentence
 *     naming what happened. There is no numeric risk field to render, and a
 *     test asserts there never is one.
 *  2. **A lateness is recorded and charged to nobody.** The earnings screen
 *     shows "Noted for appraisal · no deduction". `late_charge_instructor_enabled`
 *     is off by default and Labour Act s.5(1) is why (gotcha #11); this screen
 *     states the amount as zero rather than omitting the row, because an
 *     instructor who sees nothing cannot tell whether it was recorded.
 *  3. **Teach-back is formative.** The queue carries the sentence with it.
 */
declare(strict_types=1);

/** The five destinations in the bottom bar, in order. */
const MAM_IPORTAL_TABS = ['home', 'register', 'marking', 'students', 'more'];

/** Every screen the portal can route to, bar included. */
const MAM_IPORTAL_SCREENS = ['home', 'register', 'marking', 'teachbacks', 'report', 'students', 'earnings', 'more'];

/* ─────────────────────────────────────────────────────────────────────────────
   Home — what needs doing, soonest first
   ───────────────────────────────────────────────────────────────────────── */

/**
 * The next session this instructor teaches, and the rest of today.
 *
 * "Starts in 8 minutes" is the design's headline, so the payload carries the
 * MINUTES rather than a formatted string — a client that formats it can say
 * "in 8 minutes" or "started 3 minutes ago" without re-deriving anything, and
 * a negative value is a session already running rather than an error.
 */
function mam_iportal_home(int $instructorId): array {
    /* ⚠️ The window opens BEHIND now, not at it. A session that began ten
       minutes ago is the one an instructor most wants on their Home screen —
       it is the register they are about to take. Filtering on
       `scheduled_at >= now` dropped exactly that class and showed the next
       one instead, which is the wrong answer at the only moment the screen
       is being looked at. Two hours back covers a long lesson over-running. */
    $now = gmdate('Y-m-d H:i:s', time() - 2 * 3600);
    $dayEnd = gmdate('Y-m-d 23:59:59');

    $sessions = mam_db_all(
        "SELECT cs.id, cs.title, cs.scheduled_at, cs.duration_min, cs.room, cs.mode, cs.status,
                co.id AS cohort_id, co.title AS cohort_title,
                (SELECT COUNT(DISTINCT ce.student_id) FROM course_enrollments ce
                  WHERE ce.cohort_id = co.id AND ce.status IN ('active','completed')) AS roster
           FROM class_sessions cs
           JOIN cohorts co ON co.id = cs.cohort_id
          WHERE co.instructor_id = ?
            AND cs.scheduled_at >= ? AND cs.scheduled_at <= ?
            AND cs.status IN ('scheduled','held')
          ORDER BY cs.scheduled_at", [$instructorId, $now, $dayEnd]);

    $next = $sessions ? $sessions[0] : null;
    if ($next) {
        $next['starts_in_min'] = (int) round((strtotime((string) $next['scheduled_at']) - time()) / 60);
        $next['register_taken'] = (int) (mam_db_row(
            'SELECT COUNT(*) c FROM session_attendance WHERE session_id = ?', [(int) $next['id']])['c'] ?? 0) > 0;
    }

    /* The header's two lines. They are on the HOME payload rather than a
       whoami call of their own because the portal already makes exactly one
       request per screen, and a second round trip to print a name is the
       round trip this design is built to avoid. */
    $me = mam_db_row('SELECT id, name, avatar_url FROM users WHERE id = ?', [$instructorId]);
    $term = mam_iportal_try(fn() => mam_calendar_current_term(), null);

    return [
        'me'         => [
            'id'         => $instructorId,
            'name'       => (string) ($me['name'] ?? 'Instructor'),
            'avatar_url' => $me['avatar_url'] ?? null,
        ],
        /* Whichever the calendar gave — an authored term or the computed
           window. `mam_calendar_current_term()` tags which, and an install with
           an empty calendar still gets a printable label. */
        'term_label' => is_array($term) ? (string) ($term['term']['label'] ?? $term['label'] ?? '') : '',
        'next'       => $next,
        'later'      => array_slice($sessions, 1),
        'actions'    => mam_iportal_actions($instructorId),
    ];
}

/**
 * The "Action required" queue — things a person must decide, oldest first.
 *
 * ⚠️ Every row carries `overdue_days` measured from when the WORK arrived, not
 * from now-minus-a-window. The design prints "4d late" and "Oldest submitted
 * 12 Aug", and an instructor deciding what to do first needs to know which
 * child has been waiting longest, not how many items there are.
 */
function mam_iportal_actions(int $instructorId): array {
    $out = [];

    $unmarked = mam_db_all(
        "SELECT s.id, s.submitted_at, u.name AS student_name, a.title
           FROM submissions s
           JOIN assignments a ON a.id = s.assignment_id
           JOIN users u ON u.id = s.student_id
           JOIN classes c ON c.id = a.class_id
          WHERE c.instructor_id = ? AND s.status = 'submitted'
            AND NOT EXISTS (SELECT 1 FROM submission_reviews sr WHERE sr.submission_id = s.id)
          ORDER BY s.submitted_at
          LIMIT 50", [$instructorId]);
    if ($unmarked) {
        $out[] = [
            'kind'    => 'marking',
            'count'   => count($unmarked),
            'label'   => count($unmarked) . ' performance' . (count($unmarked) === 1 ? '' : 's') . ' to mark',
            'oldest'  => (string) $unmarked[0]['submitted_at'],
            'days'    => mam_iportal_days_since((string) $unmarked[0]['submitted_at']),
            'screen'  => 'marking',
        ];
    }

    $tb = mam_iportal_try(fn() => mam_preclass_instructor_queue($instructorId, 'submitted'), []);
    if ($tb) {
        $oldest = null;
        foreach ($tb as $t) {
            $at = (string) ($t['submitted_at'] ?? $t['created_at'] ?? '');
            if ($at !== '' && ($oldest === null || $at < $oldest)) $oldest = $at;
        }
        $out[] = [
            'kind'   => 'teachbacks',
            'count'  => count($tb),
            'label'  => count($tb) . ' teach-back' . (count($tb) === 1 ? '' : 's') . ' waiting',
            'oldest' => $oldest,
            'days'   => $oldest ? mam_iportal_days_since($oldest) : null,
            'screen' => 'teachbacks',
        ];
    }

    // Registers for sessions that have already started and have no marks at all.
    $open = (int) (mam_db_row(
        "SELECT COUNT(*) c FROM class_sessions cs
           JOIN cohorts co ON co.id = cs.cohort_id
          WHERE co.instructor_id = ? AND cs.status = 'scheduled' AND cs.scheduled_at < ?
            AND NOT EXISTS (SELECT 1 FROM session_attendance sa WHERE sa.session_id = cs.id)",
        [$instructorId, gmdate('Y-m-d H:i:s')])['c'] ?? 0);
    if ($open > 0) {
        $out[] = ['kind' => 'register', 'count' => $open,
                  'label' => $open . ' register' . ($open === 1 ? '' : 's') . ' not taken',
                  'oldest' => null, 'days' => null, 'screen' => 'register'];
    }

    // Oldest first: the queue is ordered by who has been waiting, not by kind.
    usort($out, fn($a, $b) => ($b['days'] ?? -1) <=> ($a['days'] ?? -1));
    return $out;
}

function mam_iportal_days_since(?string $ts): ?int {
    if (!$ts) return null;
    $t = strtotime($ts);
    return $t ? (int) floor((time() - $t) / 86400) : null;
}

/** Best-effort read: a screen must not 500 because one panel's lib threw. */
function mam_iportal_try(callable $fn, $fallback) {
    try { return $fn(); } catch (Throwable $e) {
        mam_log('warn', 'iportal_panel_failed', ['err' => $e->getMessage()]);
        return $fallback;
    }
}

/* ─────────────────────────────────────────────────────────────────────────────
   Register
   ───────────────────────────────────────────────────────────────────────── */

/**
 * The roster for one session, with whatever mark each student already has.
 *
 * ⚠️ An absence carries `authorised`, which the design surfaces as a second
 * row of choices under Absent. That distinction is not decoration: an
 * authorised absence is a guardian having told the academy, and treating the
 * two the same is how a family who did everything right ends up in an
 * attendance report beside one that did not.
 */
function mam_iportal_register(int $instructorId, int $sessionId): array {
    $session = mam_db_row(
        "SELECT cs.id, cs.title, cs.scheduled_at, cs.status, cs.mode,
                co.id AS cohort_id, co.title AS cohort_title, co.instructor_id
           FROM class_sessions cs JOIN cohorts co ON co.id = cs.cohort_id
          WHERE cs.id = ?", [$sessionId]);
    if (!$session) return [];
    if ((int) $session['instructor_id'] !== $instructorId && !mam_iportal_is_admin()) return [];

    $rows = mam_db_all(
        "SELECT u.id AS student_id, u.name,
                sa.status, sa.minutes_late, sa.notes, sa.checked_in_at
           FROM course_enrollments ce
           JOIN users u ON u.id = ce.student_id
           LEFT JOIN session_attendance sa ON sa.session_id = ? AND sa.student_id = u.id
          WHERE ce.cohort_id = ? AND ce.status IN ('active','completed')
          ORDER BY u.name", [$sessionId, (int) $session['cohort_id']]);

    $marked = 0;
    foreach ($rows as &$r) {
        $r['marked'] = $r['status'] !== null;
        if ($r['marked']) $marked++;
        // A student who checked themselves in is stated as such — the design
        // prints "Checked herself in, 3:58 PM", because a mark the instructor
        // did not make should not look like one they did.
        $r['self_checked'] = $r['checked_in_at'] !== null && $r['status'] === 'present';
    }
    unset($r);

    return [
        'session' => $session,
        'roster'  => $rows,
        'marked'  => $marked,
        'total'   => count($rows),
        // Stated as data so the screen cannot forget it. Each mark is its own
        // write precisely so a dropped signal loses one mark, not a register.
        'save_notice' => "Each mark saves on its own, so a dropped signal can't lose the register.",
    ];
}

function mam_iportal_is_admin(): bool {
    $u = function_exists('mam_current_user') ? mam_current_user() : null;
    return $u !== null && function_exists('mam_user_has_role') && mam_user_has_role($u, 'admin');
}

/* ─────────────────────────────────────────────────────────────────────────────
   Students — bands and sentences, never a score
   ───────────────────────────────────────────────────────────────────────── */

/**
 * The instructor's students, each with a mastery BAND and, where something
 * needs a person, a plain sentence saying what.
 *
 * ⚠️ NO RISK SCORE, and no field that could become one. The design: "There is
 * no risk score here — a label a child could be given is not a substitute for
 * you ringing a guardian." The sentence names an OBSERVATION ("missed 3 in a
 * row"), which is checkable and about behaviour, rather than a rating, which
 * is about the child. `test_instructor_portal.php` asserts no key here matches
 * /risk|score|rating|flag/.
 *
 * Dual-read across both enrolment models (gotcha #2): an instructor teaching a
 * course-only student would otherwise see an empty list.
 */
function mam_iportal_students(int $instructorId): array {
    $rows = mam_db_all(
        "SELECT DISTINCT u.id, u.name, u.avatar_url
           FROM users u
           JOIN course_enrollments ce ON ce.student_id = u.id
           LEFT JOIN cohorts co ON co.id = ce.cohort_id
          WHERE (co.instructor_id = ? OR ce.instructor_id = ?)
            AND ce.status IN ('active','completed')
          UNION
         SELECT DISTINCT u2.id, u2.name, u2.avatar_url
           FROM users u2
           JOIN enrollments e ON e.student_id = u2.id
           JOIN classes c ON c.id = e.class_id
          WHERE c.instructor_id = ? AND e.status = 'active'
          ORDER BY name", [$instructorId, $instructorId, $instructorId]);

    $out = [];
    foreach ($rows as $r) {
        $sid = (int) $r['id'];
        $band = null;
        $m = mam_iportal_try(fn() => mam_mastery_score($sid), null);
        if (is_array($m)) {
            $pub = mam_iportal_try(fn() => mam_mastery_publishable($m), $m);
            $band = $pub['band'] ?? null;
        }
        /* The design's caption line — "Level 2 · 92% attended". These are
           STANDING FACTS about the teaching, not a judgement about the child:
           the level they are working at and how much of it they have been
           present for. Neither is derived from the mastery score, and neither
           is ordered or coloured anywhere. */
        $ctx = mam_db_row(
            "SELECT l.name AS level_name
               FROM course_enrollments ce
               LEFT JOIN cohorts co ON co.id = ce.cohort_id
               LEFT JOIN levels l ON l.id = COALESCE(ce.level_id, co.level_id)
              WHERE ce.student_id = ? AND (co.instructor_id = ? OR ce.instructor_id = ?)
                AND ce.status IN ('active','completed')
              ORDER BY ce.id DESC LIMIT 1", [$sid, $instructorId, $instructorId]);
        $att = mam_db_row(
            "SELECT COUNT(*) total,
                    SUM(CASE WHEN sa.status IN ('present','late','excused','left_early') THEN 1 ELSE 0 END) attended
               FROM session_attendance sa
               JOIN class_sessions cs ON cs.id = sa.session_id
               JOIN cohorts co ON co.id = cs.cohort_id
              WHERE sa.student_id = ? AND co.instructor_id = ?", [$sid, $instructorId]);
        $bits = [];
        if (!empty($ctx['level_name'])) $bits[] = (string) $ctx['level_name'];
        if ((int) ($att['total'] ?? 0) > 0) {
            $bits[] = (int) round(((int) $att['attended'] / (int) $att['total']) * 100) . '% attended';
        }

        $out[] = [
            'id'         => $sid,
            'name'       => (string) $r['name'],
            'avatar_url' => $r['avatar_url'] ?: null,
            // A band or nothing. Never a number, never a rating.
            'band'       => $band,
            'band_label' => $band ? ($band[0] . '–' . $band[1]) : 'Not enough yet',
            'summary'    => implode(' · ', $bits),
            'attention'  => mam_iportal_attention($sid, $instructorId),
        ];
    }
    return $out;
}

/**
 * The plain sentence, or null.
 *
 * ⚠️ Returns WORDS describing something that happened, never a level or a
 * score. "Missed 3 in a row — worth a call to the guardian" is actionable and
 * checkable; "risk: high" is a label the child would carry. Only the most
 * recent, most concrete observation is returned — a list of concerns is a
 * profile, which is the thing being refused.
 */
function mam_iportal_attention(int $studentId, int $instructorId): ?string {
    $recent = mam_db_all(
        "SELECT sa.status FROM session_attendance sa
           JOIN class_sessions cs ON cs.id = sa.session_id
           JOIN cohorts co ON co.id = cs.cohort_id
          WHERE sa.student_id = ? AND co.instructor_id = ?
          ORDER BY cs.scheduled_at DESC LIMIT 3", [$studentId, $instructorId]);
    if (count($recent) === 3) {
        $absent = 0;
        foreach ($recent as $r) if (in_array((string) $r['status'], ['absent', 'unauthorised'], true)) $absent++;
        if ($absent === 3) return 'Missed the last three — worth a call to the guardian.';
    }
    return null;
}

/* ─────────────────────────────────────────────────────────────────────────────
   Earnings — a statement, and a lateness charged to nobody
   ───────────────────────────────────────────────────────────────────────── */

/**
 * The read-only earnings statement, the clearance state, and the punctuality
 * record.
 *
 * ⚠️ A recorded lateness appears with an amount of ZERO and the words "no
 * deduction", rather than being omitted. Two reasons, and the second is the
 * one that matters: `late_charge_instructor_enabled` is off by default because
 * Labour Act s.5(1) bars deductions from wages for fines absent an authorised
 * labour officer's written consent (gotcha #11) — and an instructor who sees
 * nothing at all cannot tell whether the academy noticed, disagreed, or simply
 * has not looked. Stating it at zero is the honest version of not charging.
 */
function mam_iportal_earnings(int $instructorId): array {
    $cents = (int) mam_iportal_try(fn() => mam_wallet_balance($instructorId), 0);
    $ledger = mam_iportal_try(fn() => mam_wallet_history($instructorId, 50), []);
    foreach ($ledger as &$l) {
        $l['reason_label'] = mam_iportal_try(fn() => mam_wallet_reason_label((string) $l['reason']), (string) $l['reason']);
    }
    unset($l);

    $fines = mam_db_all(
        /* amount_usd_cents, NOT amount_kobo: the kobo column is the CHARGE
           currency and summing it into a dollar figure is the bug the USD
           column exists to prevent (the same rule BI money follows). */
        "SELECT id, amount_usd_cents, status, reason, created_at
           FROM instructor_fines WHERE instructor_id = ?
          ORDER BY created_at DESC LIMIT 20", [$instructorId]);
    $recorded = 0; $deducted = 0;
    foreach ($fines as $f) { $recorded++; $deducted += (int) ($f['amount_usd_cents'] ?? 0); }

    return [
        'balance_cents' => $cents,
        'ledger'        => $ledger,
        'punctuality'   => [
            'recorded'       => $recorded,
            'deducted_cents' => $deducted,
            // The sentence is DATA, so the screen cannot render the count
            // without the explanation beside it.
            'notice'         => $deducted === 0
                ? 'Noted for appraisal · no deduction'
                : 'Deductions are recorded against your statement.',
        ],
        'clearance'     => mam_iportal_try(fn() => mam_vetting_for($instructorId), []),
        'statement_notice' => 'A statement recorded by the academy. Read-only — no top-up or withdrawal here.',
    ];
}

/* ─────────────────────────────────────────────────────────────────────────────
   Marking + teach-back queues
   ───────────────────────────────────────────────────────────────────────── */

/** Submissions awaiting a mark, oldest first — the Grade entry worklist. */
function mam_iportal_marking(int $instructorId): array {
    $rows = mam_db_all(
        "SELECT s.id, s.submitted_at, s.artifact_kind, u.name AS student_name, a.title
           FROM submissions s
           JOIN assignments a ON a.id = s.assignment_id
           JOIN users u ON u.id = s.student_id
           JOIN classes c ON c.id = a.class_id
          WHERE c.instructor_id = ? AND s.status = 'submitted'
            AND NOT EXISTS (SELECT 1 FROM submission_reviews sr WHERE sr.submission_id = s.id)
          ORDER BY s.submitted_at
          LIMIT 50", [$instructorId]);
    foreach ($rows as &$r) {
        $r['days'] = mam_iportal_days_since((string) $r['submitted_at']);
        $r['second_marker'] = mam_marking_needs_second_marker((int) $r['id']);
    }
    unset($r);
    return ['queue' => $rows, 'audio_only_notice' => MAM_MARKING_AUDIO_NOTICE];
}

/** The teach-back queue, carrying the sentence that keeps it formative. */
function mam_iportal_teachbacks(int $instructorId): array {
    return [
        'queue'  => mam_iportal_try(fn() => mam_preclass_instructor_queue($instructorId, 'submitted'), []),
        'notice' => 'Formative only. This does not enter the assessment band and never triggers a charge.',
    ];
}

/* ─────────────────────────────────────────────────────────────────────────────
   Termly report — the facts already known, and the two things only a person
   can write
   ───────────────────────────────────────────────────────────────────────── */

/**
 * The Termly report screen.
 *
 * ⚠️ THIS READS; IT DOES NOT BUILD. `mam_report_build()` WRITES a
 * `termly_reports` row, and a GET that creates an academic record every time an
 * instructor opens a screen would fill the table with drafts nobody asked for
 * and burn the one-report-per-slot rule (`mam_report_slot_taken()`) on a glance.
 * So: an existing draft is returned if there is one, and otherwise the panel is
 * assembled from the same sources the builder would read. The row is created by
 * the POST, when a person has actually written something.
 *
 * The design's "Filled in for you" panel is the whole point of the screen — an
 * instructor writing 24 reports should be typing the two paragraphs only they
 * can write, not copying an attendance percentage off another screen.
 *
 * `progress` is "5 of 24 written", counted over the students this instructor
 * actually teaches, because that is the number the Home screen's queue prints.
 */
function mam_iportal_report(int $instructorId, int $studentId = 0): array {
    $students = mam_iportal_students($instructorId);
    $total = count($students);

    $ids = array_map(fn($s) => (int) $s['id'], $students);
    $written = 0;
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $written = (int) (mam_db_row(
            "SELECT COUNT(DISTINCT student_id) c FROM termly_reports
              WHERE student_id IN ($in) AND status <> 'withdrawn'", $ids)['c'] ?? 0);
    }

    $progress = ['written' => $written, 'total' => $total];

    // No student chosen → the screen is a picker, which is what an instructor
    // with 24 reports to write opens it as.
    if ($studentId <= 0) return ['progress' => $progress, 'students' => $students, 'student' => null];

    // Ownership: this must be one of MY students, checked against the same
    // dual-read list the Students screen is built from rather than a fresh
    // query that could disagree with it.
    if (!in_array($studentId, $ids, true) && !mam_iportal_is_admin()) return [];

    $stu = mam_db_row('SELECT id, name FROM users WHERE id = ?', [$studentId]);
    if (!$stu) return [];

    /* The cohort this instructor teaches them in — it names the course and the
       level the report is ABOUT, and a student taught by two instructors must
       get the cohort belonging to the caller, not whichever row sorts first. */
    $cohort = mam_db_row(
        "SELECT co.id, co.title, co.term, c.name AS course_name, l.name AS level_name, l.trinity_grades
           FROM course_enrollments ce
           JOIN cohorts co ON co.id = ce.cohort_id
           LEFT JOIN courses c ON c.id = co.course_id
           LEFT JOIN levels  l ON l.id = co.level_id
          WHERE ce.student_id = ? AND co.instructor_id = ?
            AND ce.status IN ('active','completed')
          ORDER BY co.id DESC LIMIT 1", [$studentId, $instructorId]);

    $draft = mam_db_row(
        "SELECT id, status, instructor_comment, band_low, band_high, term_label
           FROM termly_reports
          WHERE student_id = ? AND status <> 'withdrawn'
          ORDER BY id DESC LIMIT 1", [$studentId]);

    $m = mam_iportal_try(fn() => mam_mastery_score($studentId), null);
    $pub = is_array($m) ? mam_iportal_try(fn() => mam_mastery_publishable($m), $m) : null;
    $band = $pub['band'] ?? null;

    $att = mam_db_row(
        "SELECT COUNT(*) total,
                SUM(CASE WHEN sa.status IN ('present','late','excused','left_early') THEN 1 ELSE 0 END) attended,
                SUM(CASE WHEN sa.status = 'late' THEN 1 ELSE 0 END) lates
           FROM session_attendance sa
           JOIN class_sessions cs ON cs.id = sa.session_id
           JOIN cohorts co ON co.id = cs.cohort_id
          WHERE sa.student_id = ? AND co.instructor_id = ?", [$studentId, $instructorId]);
    $attTotal = (int) ($att['total'] ?? 0);
    $attPct = $attTotal > 0 ? (int) round(((int) $att['attended'] / $attTotal) * 100) : null;

    $rep = mam_iportal_try(fn() => mam_repertoire_list($studentId), []);
    $repTitles = [];
    foreach (array_slice(is_array($rep) ? $rep : [], 0, 3) as $r) {
        if (!empty($r['title'])) $repTitles[] = (string) $r['title'];
    }

    return [
        'progress' => $progress,
        'students' => $students,
        'student'  => [
            'id'   => $studentId,
            'name' => (string) $stu['name'],
            'term_label' => (string) ($draft['term_label'] ?? ($cohort['term'] ?? '')),
            /* The panel, in the design's own order. Every value is a FACT the
               system already holds; null reads as "not recorded yet" on the
               screen rather than as a zero. */
            'known' => [
                'instrument'    => $cohort['course_name'] ?? null,
                'working_level' => $cohort['level_name']
                    ? $cohort['level_name'] . ($cohort['trinity_grades'] ? ' (≈ ' . $cohort['trinity_grades'] . ')' : '')
                    : null,
                'attendance'    => $attPct === null ? null
                    : $attPct . '% · ' . (int) $att['attended'] . ' present, ' . (int) $att['lates'] . ' late',
                'repertoire'    => $repTitles ? implode(' · ', $repTitles) : null,
                /* The BAND and the coverage, never the score. `raw_score` is the
                   number the coverage floor exists to withhold; it is not in
                   this payload and must not be added to it. */
                'assessment'    => $band
                    ? 'Band ' . $band[0] . '–' . $band[1]
                      . (isset($pub['coverage_pct']) ? ' · ' . (int) round((float) $pub['coverage_pct']) . '% of the assessment covered' : '')
                    : 'Not enough evidence yet',
            ],
            'comment'   => $draft['instructor_comment'] ?? '',
            'report_id' => isset($draft['id']) ? (int) $draft['id'] : null,
            'status'    => $draft['status'] ?? null,
            'cohort_id' => isset($cohort['id']) ? (int) $cohort['id'] : null,
        ],
        /* Carried with the payload, not written into the template: the screen
           may not promise a family anything before the panel has ratified. */
        'notice' => 'The panel ratifies marks and releases the report to the student and their guardian together. Nothing reaches a family before then.',
        'prompts' => 'Name one thing they can now do that they could not in September, and one thing to work on next term. Avoid comparisons with other students.',
    ];
}
