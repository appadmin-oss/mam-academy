<?php
/**
 * MAM Academy — the ratified termly report (guardian redesign §3.3).
 * ─────────────────────────────────────────────────────────────
 * Owns the v85 tables: `termly_reports`, `termly_report_criteria`,
 * `report_moderations`, `mark_appeals`.
 *
 * ── What this is NOT ────────────────────────────────────────────────────────
 * `progress_reports` (api/modules/learning/lib/progress-reports.php) is a different artefact: the
 * instructor's post-session note, due within two hours, with no status, no board,
 * no bands and no per-competency rows. It answers "what happened in Tuesday's
 * lesson". This answers "what standard has this child reached this term", it is a
 * published academic record, and the family sees it only after a board has
 * ratified it. Do not merge them.
 *
 * ── The refusal rule, which is the point of the file ────────────────────────
 * The design: "A report appears only after a board has ratified it. Ratification
 * is refused while an appeal is open or the moderation sample is short. A
 * provisional mark a family can see and then have overturned is worse than one
 * arriving a week late."
 *
 * So `mam_report_ratify()` is the one function here whose FAILURE modes matter
 * more than its success path, and it throws rather than returning false: a caller
 * that ignores a return value publishes an unmoderated mark to a family.
 *
 * ── Four things that are easy to "simplify" into being wrong ────────────────
 *
 *  1. **The band is taken from `mam_mastery_score()`, never recomputed.** That
 *     engine owns the coverage floor: below MAM_MASTERY_MIN_COVERAGE there is no
 *     composite at all, and above it the answer is a ten-point band. A mean
 *     computed here from `results.total_score` would be exactly the thin
 *     composite the floor exists to withhold, wearing a report's letterhead.
 *
 *  2. **An unevidenced competency is a ROW, not an absence.** `level =
 *     'insufficient'` with a NULL pct, printed as "Not assessed — first assessed
 *     next term. Not counted as a zero." Dropping the row would let a reader
 *     assume the report covers everything; scoring it 0 would report an
 *     unassessed beginner as failing.
 *
 *  3. **Coverage prints on the face.** "Overall band 70–79 from 8 of 11 assessed
 *     parts (72% of the weight)" is part of the document, so it is stored with
 *     it and the columns are NOT NULL with no default.
 *
 *  4. **The slot rule lives here, on both engines.** One LIVE report per
 *     (student, term, course, level); a withdrawn one keeps its row and frees the
 *     slot, because the corrected report an upheld appeal produces has to go
 *     somewhere. See mam_report_slot_taken() for why no UNIQUE index can say
 *     that portably.
 *
 * ── The two scales, and why there are two ───────────────────────────────────
 * The design's endpoint shape gives each competency a `level` of
 * strong|secure|working|insufficient — four values, which is what the schema's
 * CHECK admits and what the coloured bar is drawn from. Its guardian-facing
 * grading legend prints FIVE words: "Distinction · Merit · Pass · Not yet at pass
 * · Not assessed". Both are right and they are not the same axis; the mock proves
 * it, because 68% and 46% are both labelled "Pass" there while their bars are
 * different colours. So `level` carries the colour/logic axis and `grade_label`
 * the printed word, and the thresholds below are read off the mock.
 */
declare(strict_types=1);

/**
 * The minimum moderation sample before a report may be ratified: a count of
 * second-marked criteria, and a share of the scored ones. Both must be met.
 *
 * Constants, not settings — the same reasoning as MAM_VETTING_REQUIRED. An
 * academy able to shorten the sample on the afternoon of a deadline has a
 * ratification step that certifies nothing, and the whole value of the step is
 * that a family can rely on it.
 *
 * Stated as the value judgement it is: two criteria is the smallest sample in
 * which two markers can actually disagree about something, and a quarter of the
 * scored criteria keeps the sample proportionate on a long report rather than
 * letting two out of eleven stand in for all of it.
 */
const MAM_REPORT_MODERATION_MIN = 2;
const MAM_REPORT_MODERATION_SHARE = 25.0;

/** How long a family has to appeal a mark. The design says three months. */
const MAM_REPORT_APPEAL_WINDOW_DAYS = 90;

/** The four stored levels, in order, with what each one means on the page. */
const MAM_REPORT_LEVELS = [
    'strong'       => ['label' => 'Strong',            'rank' => 3],
    'secure'       => ['label' => 'Secure',            'rank' => 2],
    'working'      => ['label' => 'Working towards',   'rank' => 1],
    'insufficient' => ['label' => 'Not assessed yet',  'rank' => 0],
];

/** The printed grade words, highest first. Thresholds are on the percentage. */
const MAM_REPORT_GRADES = [
    ['min' => 90, 'label' => 'Distinction'],
    ['min' => 70, 'label' => 'Merit'],
    ['min' => 40, 'label' => 'Pass'],
    ['min' =>  0, 'label' => 'Not yet at pass'],
];

/** The legend that prints on the report, in the design's own order. */
function mam_report_legend(): array {
    return [
        'grades' => ['Distinction', 'Merit', 'Pass', 'Not yet at pass', 'Not assessed'],
        'note'   => 'Bands are used rather than single figures where evidence is partial. '
                  . 'The 80% convention is one the academy chose and reviews; it is not a fact about music.',
    ];
}

/**
 * The stored `level` for a percentage — the colour/logic axis. NULL pct is the
 * evidence-free case and is the ONLY thing that yields 'insufficient'.
 *
 * Thresholds read off the design mock: 88 and 80 render green, 68 renders accent,
 * 46 renders warn. They line up with mam_mastery_level_from_pct()'s own 75/60
 * boundaries, which is deliberate — two scales in one system that disagree about
 * where "secure" starts would put a different word on the same evidence
 * depending on which screen a family opened.
 */
function mam_report_level_for(?float $pct): string {
    if ($pct === null) return 'insufficient';
    if ($pct >= 75) return 'strong';
    if ($pct >= 60) return 'secure';
    return 'working';
}

/** The printed grade word for a percentage. NULL is 'Not assessed'. */
function mam_report_grade_for(?float $pct): string {
    if ($pct === null) return 'Not assessed';
    foreach (MAM_REPORT_GRADES as $g) if ($pct >= $g['min']) return $g['label'];
    return 'Not yet at pass';
}

/**
 * The ten-point band for a score, in the shape `termly_reports` stores.
 *
 * The v85 CHECK admits exactly what this returns and nothing else, which is the
 * point: engine and schema agreeing means a correct report can always be written
 * and an incorrect one never can. Two cases the earlier CHECK got wrong —
 *
 *   • a perfect 100 bands as (100, 100), because the top of the band is clamped
 *     to the top of the scale. `band_high > band_low` rejected it, so the report
 *     of a student who got everything right could not be stored.
 *   • the bounds are WHOLE NUMBERS. SQLite's INTEGER is an affinity, not a type,
 *     so 74.3 went into `band_low` verbatim as a REAL until the CHECK said
 *     otherwise. The floor here is what makes that unreachable in the first place.
 */
function mam_report_band(?float $score): ?array {
    if ($score === null) return null;
    $w  = defined('MAM_MASTERY_BAND') ? (int) MAM_MASTERY_BAND : 10;
    $lo = (int) (floor(max(0.0, min(100.0, $score)) / $w) * $w);
    $hi = (int) min(100, $lo + $w - 1);
    if ($hi < $lo) $hi = $lo;                 // only reachable at the very top
    return ['low' => $lo, 'high' => $hi, 'label' => $lo . '–' . $hi];
}

/**
 * Is the slot for a live report already taken?
 *
 * The slot is (student, term, course, level), where the term is `term_id` when
 * the calendar has been authored and the printed `term_label` otherwise —
 * because api/modules/academics/lib/calendar.php deliberately falls back to a computed window on
 * an install with an empty calendar, so a report may legitimately have no
 * term_id at all.
 *
 * ⚠️ **This rule is PHP's on BOTH engines, and that is not a shortcut.** Three
 * spellings of it as a UNIQUE index were tried and each is wrong in a way that
 * only shows up later:
 *
 *   • With no status term the slot is consumed FOREVER. A withdrawn report keeps
 *     its row on purpose — pulling a published mark is an event, not a delete —
 *     so the corrected report that an upheld appeal exists to produce could never
 *     be written. SQLite can spell the exclusion as a partial index; MySQL 8
 *     cannot, so the engines would enforce different rules.
 *   • Keyed on `term_label`, the rule is keyed on a MUTABLE display string.
 *     Session names are renameable in Settings, which is the whole reason the
 *     label is snapshotted on the row; after a rename the same term reads as a
 *     new slot and a family gets two ratified reports for one term with different
 *     bands and nothing marking either as superseded.
 *   • `COALESCE(course_id, 0)` inside a UNIQUE index turns the table's own
 *     `ON DELETE SET NULL` into an abort: retiring a course collapses a
 *     course-scoped report onto a course-less one for the same term and the whole
 *     DELETE rolls back citing an index name no admin has ever seen.
 *
 * The residual race — two ratifications of the same slot arriving together — is
 * narrowed by the caller holding a transaction, and is acceptable: this is an
 * admin action at the end of a term, not a public endpoint.
 */
function mam_report_slot_taken(int $studentId, ?int $termId, string $termLabel, ?int $courseId, ?int $levelId, int $ignoreReportId = 0): bool {
    $sql = "SELECT id FROM termly_reports
             WHERE student_id = ? AND status <> 'withdrawn'
               AND " . ($courseId === null ? 'course_id IS NULL' : 'course_id = ?') . "
               AND " . ($levelId  === null ? 'level_id IS NULL'  : 'level_id = ?') . "
               AND " . ($termId   === null ? 'term_label = ?'    : 'term_id = ?') . "
               AND id <> ?
             LIMIT 1";
    $args = [$studentId];
    if ($courseId !== null) $args[] = $courseId;
    if ($levelId  !== null) $args[] = $levelId;
    $args[] = $termId === null ? $termLabel : $termId;
    $args[] = $ignoreReportId;
    return mam_db_row($sql, $args) !== null;
}

/**
 * The competencies a report covers, in the order they print.
 *
 * Derived from MAM_MASTERY_COMPONENTS so the report and the mastery engine cannot
 * disagree about what "8 of 11 parts" counts. The design's §2.2 asks for the
 * competency to be a CODE rather than a keyword match on a label — "v1 matched by
 * string and silently reclassified renamed criteria" — and that is exactly what
 * `termly_report_criteria.competency_code` is. The rubric-label mapping stays
 * where it belongs, inside mastery.php, and is applied once on the way in.
 */
function mam_report_competencies(): array {
    $out = [];
    $i = 0;
    foreach (MAM_MASTERY_COMPONENTS as $code => $c) {
        $out[$code] = ['code' => $code, 'label' => $c['label'], 'weight_pct' => (int) $c['weight'], 'sort_order' => $i++];
    }
    return $out;
}

/**
 * Assemble (or refresh) a DRAFT report for a student, optionally scoped to a
 * cohort. Idempotent on the slot: re-running replaces the draft's criteria and
 * figures rather than stacking a second report.
 *
 * Refuses to touch anything that is not a draft. A `moderating` report is with
 * the sample, and a `ratified` one is in a family's hands — rebuilding either
 * from live evidence would silently change a mark somebody has already read.
 *
 * @return int the report id
 */
function mam_report_build(int $studentId, ?int $cohortId = null, ?int $actorId = null): int {
    if ($studentId <= 0) throw new InvalidArgumentException('bad_student');
    $stu = mam_db_row('SELECT id, name FROM users WHERE id = ?', [$studentId]);
    if (!$stu) throw new RuntimeException('student_not_found');

    // ── Where the report sits: cohort → course/level/instructor, and the term.
    $cohort = null;
    if ($cohortId) {
        $cohort = mam_db_row('SELECT id, course_id, level_id, instructor_id, title, term FROM cohorts WHERE id = ?', [$cohortId]);
        if (!$cohort) throw new RuntimeException('cohort_not_found');
    }
    $courseId = $cohort ? ($cohort['course_id'] !== null ? (int) $cohort['course_id'] : null) : null;
    $levelId  = $cohort ? ($cohort['level_id']  !== null ? (int) $cohort['level_id']  : null) : null;
    $instrId  = $cohort ? ($cohort['instructor_id'] !== null ? (int) $cohort['instructor_id'] : null) : null;

    /* The term. `mam_calendar_current_term()` prefers an AUTHORED row and falls
     * back to the computed window, tagging which it returned — so an install with
     * an empty calendar still produces a report with a printable label, it just
     * has no term_id to key the slot on. The label is SNAPSHOTTED: session names
     * are renameable and a family has this one on their receipt. */
    $termId = null; $termLabel = '';
    if (function_exists('mam_calendar_current_term')) {
        try {
            $t = mam_calendar_current_term();
            if (!empty($t['term']) && ($t['source'] ?? '') === 'calendar') $termId = (int) $t['term']['id'];
            $termLabel = (string) ($t['term']['label'] ?? $t['label'] ?? '');
        } catch (\Throwable $e) {
            mam_log('warn', 'report_term_lookup_failed', ['student' => $studentId, 'err' => $e->getMessage()]);
        }
    }
    if ($termLabel === '') $termLabel = $cohort && $cohort['term'] ? (string) $cohort['term'] : date('Y');

    // ── The band and the coverage, from the mastery engine. Never recomputed.
    $m = mam_mastery_score($studentId);
    $band = mam_report_band($m['score'] !== null ? (float) $m['score'] : null);

    $comps    = mam_report_competencies();
    $evidence = [];
    foreach ($m['components'] as $c) {
        $code = (string) ($c['key'] ?? $c['code'] ?? '');
        if ($code !== '') $evidence[$code] = $c;
    }
    $total     = count($comps);
    $evidenced = count(array_intersect_key($comps, $evidence));
    /* The weight percentage prints on the face, so it is a WHOLE number here and
     * the v85 CHECK insists on that: '72% of the weight' is the sentence, and
     * 71.6 is not. Rounded once, at the point of storage, rather than on each
     * read — two readers must not see two figures. */
    $weightPct = (int) round((float) ($m['coverage_pct'] ?? 0));

    // ── Find or make the row.
    $existing = mam_db_row(
        "SELECT id, status FROM termly_reports
          WHERE student_id = ? AND status = 'draft'
            AND " . ($courseId === null ? 'course_id IS NULL' : 'course_id = ?') . "
            AND " . ($termId === null ? 'term_label = ?' : 'term_id = ?') . "
          ORDER BY id DESC LIMIT 1",
        array_merge([$studentId], $courseId === null ? [] : [$courseId], [$termId === null ? $termLabel : $termId])
    );

    if (!$existing && mam_report_slot_taken($studentId, $termId, $termLabel, $courseId, $levelId)) {
        // A live report already occupies this slot. Withdraw it first, deliberately.
        throw new RuntimeException('report_slot_taken');
    }

    $fields = [
        'student_id'          => $studentId,
        'cohort_id'           => $cohortId ?: null,
        'course_id'           => $courseId,
        'level_id'            => $levelId,
        'term_id'             => $termId,
        'term_label'          => $termLabel,
        'band_low'            => $band['low']  ?? null,
        'band_high'           => $band['high'] ?? null,
        'coverage_evidenced'  => $evidenced,
        'coverage_total'      => $total,
        'coverage_weight_pct' => $weightPct,
        'instructor_id'       => $instrId,
        'attendance_summary'  => mam_report_attendance_sentence($m['attendance'] ?? []),
        'updated_at'          => date('Y-m-d H:i:s'),
    ];

    if ($existing) {
        $reportId = (int) $existing['id'];
        mam_db_update('termly_reports', $fields, ['id' => $reportId]);
        mam_db()->prepare('DELETE FROM termly_report_criteria WHERE report_id = ?')->execute([$reportId]);
    } else {
        $fields['status']     = 'draft';
        $fields['created_by'] = mam_report_actor_id($actorId);
        $fields['created_at'] = date('Y-m-d H:i:s');
        $reportId = mam_db_insert('termly_reports', $fields);
    }

    // ── One row per competency, INCLUDING the unevidenced ones.
    foreach ($comps as $code => $c) {
        $pct = null;
        if (isset($evidence[$code]) && $evidence[$code]['pct'] !== null) {
            // Whole per cent: the bar is drawn from it and the v85 CHECK insists.
            $pct = (int) round((float) $evidence[$code]['pct']);
            $pct = max(0, min(100, $pct));
        }
        $level = mam_report_level_for($pct === null ? null : (float) $pct);
        mam_db_insert('termly_report_criteria', [
            'report_id'       => $reportId,
            'competency_code' => $code,
            'label'           => $c['label'],
            'weight_pct'      => $c['weight_pct'],
            'level'           => $level,
            'pct'             => $pct,
            'grade_label'     => mam_report_grade_for($pct === null ? null : (float) $pct),
            'sort_order'      => $c['sort_order'],
        ]);
    }

    mam_audit('report_build', 'termly_report', $reportId, [
        'student' => $studentId, 'cohort' => $cohortId,
        'band' => $band['label'] ?? null, 'coverage' => "$evidenced/$total",
    ]);
    return $reportId;
}

/** §24's attendance companion as one sentence, for the face of the report. */
function mam_report_attendance_sentence(array $att): ?string {
    $n = (int) ($att['sessions'] ?? 0);
    if ($n === 0) return null;
    $rate = $att['attendance_pct'];
    $lates = (int) ($att['lates'] ?? 0);
    /* Lateness is recorded for everyone and charged to nobody on a guardian
     * screen — the design is explicit. It appears here as a count beside the
     * rate, never as a penalty and never as a fee. */
    return 'Attendance ' . ($rate === null ? '—' : round((float) $rate) . '%')
         . ' (' . $n . ' ' . ($n === 1 ? 'session' : 'sessions') . ', ' . $lates . ' late)';
}

/**
 * Normalise an actor id. NULL, never 0 — `created_by` and friends carry a
 * `users(id)` FK under `PRAGMA foreign_keys = ON`, so a 0 raises a constraint
 * violation, and CLAUDE.md gotcha #3 records what that cost last time: the
 * catch swallowed it and destroyed the audit row.
 */
function mam_report_actor_id(?int $actorId): ?int {
    return $actorId !== null && $actorId > 0 ? $actorId : null;
}

/** Record a second marker's look at one criterion. Re-recording REPLACES. */
function mam_report_moderate(int $reportId, string $competencyCode, ?int $moderatorId, bool $agreed, ?string $moderatorLevel = null, ?string $note = null): int {
    $r = mam_db_row('SELECT id, status FROM termly_reports WHERE id = ?', [$reportId]);
    if (!$r) throw new RuntimeException('report_not_found');
    if ($r['status'] === 'ratified') throw new RuntimeException('already_ratified');
    if ($moderatorLevel !== null && !isset(MAM_REPORT_LEVELS[$moderatorLevel])) throw new InvalidArgumentException('bad_level');
    $crit = mam_db_row('SELECT id FROM termly_report_criteria WHERE report_id = ? AND competency_code = ?', [$reportId, $competencyCode]);
    if (!$crit) throw new RuntimeException('criterion_not_on_report');

    $mid = mam_report_actor_id($moderatorId);
    $existing = $mid === null
        ? mam_db_row('SELECT id FROM report_moderations WHERE report_id = ? AND competency_code = ? AND moderator_id IS NULL', [$reportId, $competencyCode])
        : mam_db_row('SELECT id FROM report_moderations WHERE report_id = ? AND competency_code = ? AND moderator_id = ?', [$reportId, $competencyCode, $mid]);

    $row = [
        'report_id' => $reportId, 'competency_code' => $competencyCode, 'moderator_id' => $mid,
        'agreed' => $agreed ? 1 : 0, 'moderator_level' => $moderatorLevel, 'note' => $note,
        'moderated_at' => date('Y-m-d H:i:s'),
    ];
    if ($existing) { mam_db_update('report_moderations', $row, ['id' => (int) $existing['id']]); $id = (int) $existing['id']; }
    else { $id = mam_db_insert('report_moderations', $row); }

    // Being with the sample is a state a family can be told about ("with the
    // examinations panel"), so it is recorded rather than inferred.
    if ($r['status'] === 'draft') {
        mam_db_update('termly_reports', ['status' => 'moderating', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $reportId]);
    }
    mam_audit('report_moderate', 'termly_report', $reportId, ['competency' => $competencyCode, 'agreed' => $agreed, 'by' => $mid]);
    return $id;
}

/**
 * The moderation sample for a report, and whether it is long enough.
 *
 * The sample is a count of second-marked SCORED criteria. An unevidenced
 * criterion is excluded from both halves — there is nothing there for a second
 * marker to agree or disagree with, so counting it in the denominator would make
 * a thinly-evidenced report harder to ratify than a complete one, which is
 * backwards.
 */
function mam_report_moderation_state(int $reportId): array {
    $scored = (int) (mam_db_row(
        "SELECT COUNT(*) AS n FROM termly_report_criteria WHERE report_id = ? AND level <> 'insufficient'",
        [$reportId]
    )['n'] ?? 0);
    $sampled = (int) (mam_db_row(
        "SELECT COUNT(DISTINCT m.competency_code) AS n
           FROM report_moderations m
           JOIN termly_report_criteria c
             ON c.report_id = m.report_id AND c.competency_code = m.competency_code
          WHERE m.report_id = ? AND c.level <> 'insufficient'",
        [$reportId]
    )['n'] ?? 0);
    $disagreed = (int) (mam_db_row(
        'SELECT COUNT(*) AS n FROM report_moderations WHERE report_id = ? AND agreed = 0',
        [$reportId]
    )['n'] ?? 0);

    $needShare = (int) ceil($scored * MAM_REPORT_MODERATION_SHARE / 100);
    $need = $scored === 0 ? 0 : max(MAM_REPORT_MODERATION_MIN, $needShare);
    if ($need > $scored) $need = $scored;   // can't sample more criteria than exist

    return [
        'scored'      => $scored,
        'sampled'     => $sampled,
        'required'    => $need,
        'disagreed'   => $disagreed,
        'sufficient'  => $scored === 0 ? false : $sampled >= $need,
    ];
}

/** Open appeals against a report — the other half of the refusal rule. */
function mam_report_open_appeals(int $reportId): array {
    return mam_db_all("SELECT * FROM mark_appeals WHERE report_id = ? AND status = 'open' ORDER BY opened_at", [$reportId]);
}

/**
 * Ratify a report — the transition that makes it visible to a family.
 *
 * THROWS, deliberately, on every refusal. A boolean return would let a caller
 * publish an unmoderated mark by ignoring it, and the whole reason this step
 * exists is that a family can rely on what reaches them.
 *
 *   report_not_found      — no such report.
 *   already_ratified      — idempotence is NOT silent here: re-ratifying would
 *                           restamp the board and the date on a document a
 *                           family already holds.
 *   withdrawn             — a withdrawn report is a closed event. Build a new one.
 *   appeal_open           — an appeal against this report is unresolved.
 *   moderation_short      — the sample is smaller than MAM_REPORT_MODERATION_MIN
 *                           or than MAM_REPORT_MODERATION_SHARE of the scored
 *                           criteria.
 *   no_evidence           — nothing on the report is scored. A report with
 *                           eleven "not assessed" rows is not a report; it is a
 *                           statement that the term has not been assessed, and
 *                           publishing it as a ratified academic record would be
 *                           the false precision this subsystem exists to refuse.
 *   no_ratifier           — ratification is a named person on a stated date.
 */
function mam_report_ratify(int $reportId, ?int $actorId, ?string $note = null): array {
    $r = mam_db_row('SELECT * FROM termly_reports WHERE id = ?', [$reportId]);
    if (!$r) throw new RuntimeException('report_not_found');
    if ($r['status'] === 'ratified')  throw new RuntimeException('already_ratified');
    if ($r['status'] === 'withdrawn') throw new RuntimeException('withdrawn');

    $by = mam_report_actor_id($actorId);
    if ($by === null) throw new RuntimeException('no_ratifier');

    $appeals = mam_report_open_appeals($reportId);
    if ($appeals) {
        mam_audit('report_ratify_refused', 'termly_report', $reportId, ['reason' => 'appeal_open', 'appeals' => count($appeals)]);
        throw new RuntimeException('appeal_open');
    }

    $mod = mam_report_moderation_state($reportId);
    if ($mod['scored'] === 0) {
        mam_audit('report_ratify_refused', 'termly_report', $reportId, ['reason' => 'no_evidence']);
        throw new RuntimeException('no_evidence');
    }
    if (!$mod['sufficient']) {
        mam_audit('report_ratify_refused', 'termly_report', $reportId, [
            'reason' => 'moderation_short', 'sampled' => $mod['sampled'], 'required' => $mod['required'],
        ]);
        throw new RuntimeException('moderation_short');
    }

    // The slot check runs again HERE, not only at build: a second draft may have
    // been ratified in between, and two ratified reports for one term is the
    // failure a family actually notices.
    if (mam_report_slot_taken((int) $r['student_id'], $r['term_id'] !== null ? (int) $r['term_id'] : null,
            (string) $r['term_label'], $r['course_id'] !== null ? (int) $r['course_id'] : null,
            $r['level_id'] !== null ? (int) $r['level_id'] : null, $reportId)) {
        throw new RuntimeException('report_slot_taken');
    }

    $now = date('Y-m-d H:i:s');
    mam_db_update('termly_reports', [
        'status' => 'ratified', 'ratified_by' => $by, 'ratified_at' => $now,
        'ratified_note' => $note, 'updated_at' => $now,
    ], ['id' => $reportId]);

    mam_audit('report_ratify', 'termly_report', $reportId, [
        'student' => (int) $r['student_id'], 'by' => $by,
        'sampled' => $mod['sampled'], 'required' => $mod['required'], 'disagreed' => $mod['disagreed'],
    ]);

    // Tell the family, best-effort. A notification that fails must never leave a
    // ratified report unratified.
    try {
        if (function_exists('mam_report_notify_ratified')) mam_report_notify_ratified($reportId);
    } catch (\Throwable $e) {
        mam_log('warn', 'report_notify_failed', ['report' => $reportId, 'err' => $e->getMessage()]);
    }

    return ['id' => $reportId, 'status' => 'ratified', 'ratified_at' => $now, 'moderation' => $mod];
}

/**
 * Withdraw a ratified report. Keeps the row and the reason: pulling a published
 * mark is a real event and must not be spelled as a delete. Frees the slot, so
 * the corrected report an upheld appeal produces has somewhere to go.
 */
function mam_report_withdraw(int $reportId, ?int $actorId, string $reason): array {
    $reason = trim($reason);
    if ($reason === '') throw new InvalidArgumentException('reason_required');
    $r = mam_db_row('SELECT * FROM termly_reports WHERE id = ?', [$reportId]);
    if (!$r) throw new RuntimeException('report_not_found');
    if ($r['status'] === 'withdrawn') throw new RuntimeException('already_withdrawn');

    $now = date('Y-m-d H:i:s');
    mam_db_update('termly_reports', [
        'status' => 'withdrawn', 'withdrawn_at' => $now, 'withdrawn_reason' => $reason, 'updated_at' => $now,
    ], ['id' => $reportId]);
    mam_audit('report_withdraw', 'termly_report', $reportId, ['by' => mam_report_actor_id($actorId), 'reason' => $reason]);
    return ['id' => $reportId, 'status' => 'withdrawn'];
}

/** Set or replace the instructor's comment on a report that is not yet published. */
function mam_report_set_comment(int $reportId, ?string $comment, ?int $actorId = null): void {
    $r = mam_db_row('SELECT status FROM termly_reports WHERE id = ?', [$reportId]);
    if (!$r) throw new RuntimeException('report_not_found');
    if ($r['status'] === 'ratified') throw new RuntimeException('already_ratified');
    mam_db_update('termly_reports', [
        'instructor_comment' => $comment !== null && trim($comment) !== '' ? trim($comment) : null,
        'updated_at' => date('Y-m-d H:i:s'),
    ], ['id' => $reportId]);
    mam_audit('report_comment', 'termly_report', $reportId, ['by' => mam_report_actor_id($actorId)]);
}

/**
 * One report, fully assembled: the row, its criteria in print order, the legend,
 * the moderation state, and the appeal window.
 *
 * `$forFamily` strips everything internal. It is not cosmetic: the moderation
 * sample names second markers and records where they disagreed with the
 * instructor, which is staff information about staff, and the design's §2.3
 * refusal ("intervention flags are staff-side only") is the same principle.
 */
function mam_report_get(int $reportId, bool $forFamily = false): ?array {
    $r = mam_db_row(
        "SELECT tr.*, u.name AS student_name, u.enrollment_ref,
                c.name AS course_name, l.name AS level_name, l.trinity_grades,
                ins.name AS instructor_name, rat.name AS ratified_by_name,
                co.title AS cohort_title
           FROM termly_reports tr
           JOIN users u        ON u.id  = tr.student_id
      LEFT JOIN courses c      ON c.id  = tr.course_id
      LEFT JOIN levels l       ON l.id  = tr.level_id
      LEFT JOIN cohorts co     ON co.id = tr.cohort_id
      LEFT JOIN users ins      ON ins.id = tr.instructor_id
      LEFT JOIN users rat      ON rat.id = tr.ratified_by
          WHERE tr.id = ?",
        [$reportId]
    );
    if (!$r) return null;

    $crit = mam_db_all(
        'SELECT competency_code, label, weight_pct, level, pct, grade_label, first_assessed_week, note
           FROM termly_report_criteria WHERE report_id = ? ORDER BY sort_order, id',
        [$reportId]
    );
    foreach ($crit as &$c) {
        $c['weight_pct'] = (int) $c['weight_pct'];
        $c['pct'] = $c['pct'] === null ? null : (int) $c['pct'];
        $c['rank'] = MAM_REPORT_LEVELS[$c['level']]['rank'] ?? 0;
    }
    unset($c);

    $out = [
        'id'            => (int) $r['id'],
        'student_id'    => (int) $r['student_id'],
        'student_name'  => $r['student_name'],
        'student_number'=> $r['enrollment_ref'],
        'status'        => $r['status'],
        'term_label'    => $r['term_label'],
        'course_name'   => $r['course_name'],
        'level_name'    => $r['level_name'],
        'level_equiv'   => $r['trinity_grades'],
        'cohort_title'  => $r['cohort_title'],
        'instructor'    => $r['instructor_name'],
        'comment'       => $r['instructor_comment'],
        'attendance'    => $r['attendance_summary'],
        'band'          => $r['band_low'] === null ? null : [
            'low'   => (int) $r['band_low'],
            'high'  => (int) $r['band_high'],
            'label' => (int) $r['band_low'] . '–' . (int) $r['band_high'],
        ],
        'coverage'      => [
            'evidenced'  => (int) $r['coverage_evidenced'],
            'total'      => (int) $r['coverage_total'],
            'weight_pct' => (int) $r['coverage_weight_pct'],
        ],
        'criteria'      => $crit,
        'legend'        => mam_report_legend(),
        'ratified_by'   => $r['ratified_by_name'],
        'ratified_at'   => $r['ratified_at'],
        'ratified_note' => $r['ratified_note'],
        'withdrawn_at'  => $r['withdrawn_at'],
        'withdrawn_reason' => $r['withdrawn_reason'],
        'appeal'        => mam_report_appeal_window($r),
    ];

    if (!$forFamily) {
        $out['moderation'] = mam_report_moderation_state($reportId);
        $out['moderations'] = mam_db_all(
            'SELECT m.competency_code, m.agreed, m.moderator_level, m.note, m.moderated_at, u.name AS moderator
               FROM report_moderations m LEFT JOIN users u ON u.id = m.moderator_id
              WHERE m.report_id = ? ORDER BY m.moderated_at',
            [$reportId]
        );
        $out['appeals'] = mam_db_all('SELECT * FROM mark_appeals WHERE report_id = ? ORDER BY opened_at DESC', [$reportId]);
        $out['created_at'] = $r['created_at'];
    } else {
        // A family sees THEIR OWN appeals against this report, and only those:
        // an appeal raised by staff on another guardian's behalf is not theirs.
        $out['my_appeals'] = [];
    }
    return $out;
}

/**
 * The appeal window for a report: open only once it is ratified, and only for
 * MAM_REPORT_APPEAL_WINDOW_DAYS after that.
 *
 * The deadline is adjudicated in MAM_CALENDAR_TZ (Africa/Lagos) for the same
 * reason every other deadline in this system is: a diaspora guardian in New York
 * must not lose a day's appeal window because their clock disagrees with Lagos
 * about the date.
 */
function mam_report_appeal_window(array $report): array {
    if (($report['status'] ?? '') !== 'ratified' || empty($report['ratified_at'])) {
        return ['open' => false, 'reason' => 'not_ratified', 'closes_on' => null, 'days_left' => null];
    }
    $tz = defined('MAM_CALENDAR_TZ') ? MAM_CALENDAR_TZ : 'Africa/Lagos';
    try {
        $zone = new DateTimeZone($tz);
        $from = new DateTimeImmutable((string) $report['ratified_at'], $zone);
        $close = $from->modify('+' . MAM_REPORT_APPEAL_WINDOW_DAYS . ' days');
        $today = new DateTimeImmutable(
            function_exists('mam_calendar_today') ? mam_calendar_today() : 'today', $zone
        );
        $days = (int) $today->diff($close)->format('%r%a');
        return [
            'open'      => $days >= 0,
            'reason'    => $days >= 0 ? null : 'window_closed',
            'closes_on' => $close->format('Y-m-d'),
            'days_left' => max(0, $days),
            'window_days' => MAM_REPORT_APPEAL_WINDOW_DAYS,
        ];
    } catch (\Throwable $e) {
        mam_log('warn', 'report_appeal_window_failed', ['err' => $e->getMessage()]);
        return ['open' => false, 'reason' => 'unknown', 'closes_on' => null, 'days_left' => null];
    }
}

/**
 * The ratified reports a guardian may see for one of their children.
 *
 * Two gates, both server-side, because the design says so in as many words:
 * "Enforce server-side, not by hiding UI."
 *
 *   1. `mam_parent_can_view()` — is this guardian linked to this child at all.
 *   2. `mam_parent_scope_allows(…, 'reports')` — a billing-only guardian sees
 *      invoices and receipts and no marks.
 *
 * And `status = 'ratified'` — never draft, never moderating, never withdrawn. A
 * withdrawn report disappears from the family's list on purpose: it is no longer
 * the academy's statement about their child, and leaving it visible with a
 * "withdrawn" chip invites a family to keep reading the mark that was pulled.
 */
function mam_report_for_guardian(int $parentId, int $studentId): array {
    if (!mam_parent_can_view($parentId, $studentId)) throw new RuntimeException('forbidden');
    if (!mam_parent_scope_allows($parentId, $studentId, 'reports')) {
        return ['scope_denied' => true, 'scope' => mam_parent_scope($parentId, $studentId), 'reports' => []];
    }
    $rows = mam_db_all(
        "SELECT id FROM termly_reports
          WHERE student_id = ? AND status = 'ratified'
          ORDER BY COALESCE(ratified_at, created_at) DESC, id DESC",
        [$studentId]
    );
    $out = [];
    foreach ($rows as $r) {
        $rep = mam_report_get((int) $r['id'], true);
        if ($rep === null) continue;
        $rep['my_appeals'] = mam_db_all(
            'SELECT id, competency_code, reason, status, outcome_note, opened_at, closed_at
               FROM mark_appeals WHERE report_id = ? AND raised_by = ? ORDER BY opened_at DESC',
            [(int) $r['id'], $parentId]
        );
        $out[] = $rep;
    }
    return ['scope_denied' => false, 'scope' => mam_parent_scope($parentId, $studentId), 'reports' => $out];
}

/**
 * Raise an appeal against a mark.
 *
 * A guardian, the student, or staff on their behalf. Refused outside the window,
 * and refused if this raiser already has one open on the report — an appeal is a
 * process, not a message, and two open ones on one report make "is an appeal
 * open" ambiguous exactly where mam_report_ratify() has to answer it.
 */
function mam_report_appeal_open(int $reportId, int $raisedBy, string $raisedRole, string $reason, ?string $competencyCode = null): int {
    $reason = trim($reason);
    if ($reason === '') throw new InvalidArgumentException('reason_required');
    if (!in_array($raisedRole, ['parent', 'student', 'staff'], true)) throw new InvalidArgumentException('bad_role');

    $r = mam_db_row('SELECT * FROM termly_reports WHERE id = ?', [$reportId]);
    if (!$r) throw new RuntimeException('report_not_found');

    if ($raisedRole === 'parent' && !mam_parent_can_view($raisedBy, (int) $r['student_id'])) throw new RuntimeException('forbidden');
    if ($raisedRole === 'student' && $raisedBy !== (int) $r['student_id']) throw new RuntimeException('forbidden');

    if ($competencyCode !== null) {
        $has = mam_db_row('SELECT id FROM termly_report_criteria WHERE report_id = ? AND competency_code = ?', [$reportId, $competencyCode]);
        if (!$has) throw new RuntimeException('criterion_not_on_report');
    }

    /* Staff may raise an appeal on a report that is not yet published — that is
     * how an error caught during moderation stops ratification. A family can only
     * appeal what they have been shown, and only inside the window. */
    if ($raisedRole !== 'staff') {
        $w = mam_report_appeal_window($r);
        if (!$w['open']) throw new RuntimeException($w['reason'] === 'not_ratified' ? 'not_ratified' : 'window_closed');
    }

    $dup = mam_db_row("SELECT id FROM mark_appeals WHERE report_id = ? AND raised_by = ? AND status = 'open'", [$reportId, $raisedBy]);
    if ($dup) throw new RuntimeException('appeal_already_open');

    $id = mam_db_insert('mark_appeals', [
        'report_id' => $reportId, 'student_id' => (int) $r['student_id'],
        'raised_by' => mam_report_actor_id($raisedBy), 'raised_role' => $raisedRole,
        'competency_code' => $competencyCode, 'reason' => $reason,
        'status' => 'open', 'opened_at' => date('Y-m-d H:i:s'),
    ]);
    mam_audit('appeal_open', 'mark_appeal', $id, ['report' => $reportId, 'role' => $raisedRole, 'competency' => $competencyCode]);
    return $id;
}

/**
 * Decide an appeal. `upheld` / `declined` / `withdrawn`, and every one of them
 * states its outcome — the v85 CHECK insists, because an upheld appeal that
 * closed with no note showed a family a mark overturned and nothing saying why.
 *
 * Upholding does NOT itself change the mark: it authorises a withdrawal and a
 * rebuild, which are separate audited acts by a person. An appeal decision that
 * silently rewrote a published band would be indistinguishable from the mark
 * having always been that.
 */
function mam_report_appeal_decide(int $appealId, string $status, ?int $decidedBy, string $outcomeNote): array {
    if (!in_array($status, ['upheld', 'declined', 'withdrawn'], true)) throw new InvalidArgumentException('bad_status');
    $outcomeNote = trim($outcomeNote);
    if ($outcomeNote === '') throw new InvalidArgumentException('outcome_note_required');

    $a = mam_db_row('SELECT * FROM mark_appeals WHERE id = ?', [$appealId]);
    if (!$a) throw new RuntimeException('appeal_not_found');
    if ($a['status'] !== 'open') throw new RuntimeException('already_decided');

    $now = date('Y-m-d H:i:s');
    mam_db_update('mark_appeals', [
        'status' => $status, 'outcome_note' => $outcomeNote,
        'decided_by' => mam_report_actor_id($decidedBy), 'closed_at' => $now,
    ], ['id' => $appealId]);
    mam_audit('appeal_decide', 'mark_appeal', $appealId, ['status' => $status, 'report' => $a['report_id'] !== null ? (int) $a['report_id'] : null]);
    return ['id' => $appealId, 'status' => $status, 'closed_at' => $now];
}

/** Reports for a student, for staff. Every status, newest first. */
function mam_report_list_for_student(int $studentId): array {
    $rows = mam_db_all('SELECT id FROM termly_reports WHERE student_id = ? ORDER BY id DESC', [$studentId]);
    $out = [];
    foreach ($rows as $r) { $rep = mam_report_get((int) $r['id']); if ($rep) $out[] = $rep; }
    return $out;
}

/** The reports awaiting a board, oldest first — the ratification queue. */
function mam_report_pending(int $limit = 100): array {
    $limit = max(1, min(500, $limit));
    $rows = mam_db_all(
        "SELECT tr.id, tr.student_id, tr.term_label, tr.status, tr.updated_at, u.name AS student_name
           FROM termly_reports tr JOIN users u ON u.id = tr.student_id
          WHERE tr.status IN ('draft','moderating')
          ORDER BY COALESCE(tr.updated_at, tr.created_at) ASC, tr.id ASC
          LIMIT " . $limit
    );
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['moderation'] = mam_report_moderation_state((int) $r['id']);
        $r['open_appeals'] = count(mam_report_open_appeals((int) $r['id']));
        $r['ratifiable'] = $r['moderation']['sufficient'] && $r['open_appeals'] === 0;
    }
    unset($r);
    return $rows;
}

/**
 * Notify the guardians (and the student) that a report has been published.
 * Best-effort by construction — see the caller.
 */
function mam_report_notify_ratified(int $reportId): void {
    $r = mam_db_row('SELECT student_id, term_label FROM termly_reports WHERE id = ?', [$reportId]);
    if (!$r) return;
    $studentId = (int) $r['student_id'];
    $title = 'Your termly report is ready';
    $body  = 'The examinations panel has ratified the ' . $r['term_label'] . ' report. '
           . 'It is on the Reports screen of the portal, with its grading legend on the same page.';

    if (function_exists('mam_notify')) {
        try { mam_notify($studentId, 'report.ratified', $title, $body, ['report' => $reportId]); } catch (\Throwable $e) {}
    }
    if (!function_exists('mam_parent_guardians_of')) return;
    foreach (mam_parent_guardians_of($studentId) as $g) {
        // A billing-only guardian is not told about a mark: the scope is the
        // boundary, and a notification that names a report is a disclosure.
        if (!mam_parent_scope_allows((int) $g['id'], $studentId, 'reports')) continue;
        if (function_exists('mam_notify')) {
            try { mam_notify((int) $g['id'], 'report.ratified', $title, $body, ['report' => $reportId, 'student' => $studentId]); } catch (\Throwable $e) {}
        }
    }
}
