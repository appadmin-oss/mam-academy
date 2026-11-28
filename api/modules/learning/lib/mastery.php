<?php
/**
 * MAM Academy — the mastery scale and the MAM Mastery Score™ (PDF §23-24).
 * ─────────────────────────────────────────────────────────────
 * §23 defines a six-level competency scale and §24 a standardised composite
 * score. Both were previously implicit: `lesson_progress.mastery_pct` carried a
 * percentage with no vocabulary attached, and nothing anywhere combined the
 * evidence into one number.
 *
 * Three design decisions this file commits to, all of them from §24:
 *
 *  1. **Attendance and punctuality are NOT components.** §24 is explicit that
 *     they must stay separately visible "so that a student's musical ability is
 *     not confused with disciplinary performance". They are returned alongside
 *     the score, never inside it.
 *
 *  2. **A component with no evidence is EXCLUDED, not scored zero.** A student
 *     in week two has not been assessed on repertoire; scoring that as 0 would
 *     report a beginner as failing. Weights are renormalised across the
 *     components that actually have evidence, and `coverage` discloses how much
 *     of the full weight that was.
 *
 *     ⚠️ **Exclusion is right; renormalising SILENTLY is not.** The OECD/JRC
 *     Handbook on Constructing Composite Indicators is explicit that
 *     renormalising weights changes what the index means, so a score built on
 *     3 of 11 components is not comparable to one built on 11 — and under linear
 *     aggregation a high component can mask a missing one, because compensability
 *     is constant. Disclosing `coverage` beside the number was not enough on its
 *     own: a reader sees "73.4" and anchors on it.
 *
 *     So the composite is now WITHHELD entirely below MAM_MASTERY_MIN_COVERAGE
 *     (an "insufficient evidence" state, not a number with a caveat), reported
 *     as a BAND rather than a false-precise decimal, and carries the arithmetic
 *     bounds the unscored weight actually permits. Subscores remain available
 *     throughout, because per-component evidence is real even when the composite
 *     is not.
 *
 *  3. **Rubric criteria are mapped to components by their LABEL**, because §21
 *     lets administrators author rubrics per instrument and there is no fixed
 *     criterion vocabulary to key on. An unrecognised label falls into
 *     `technical_competence` (the §20 example's largest bucket) rather than
 *     being discarded — evidence is never silently dropped.
 */
declare(strict_types=1);

/**
 * The minimum share of total weight that must carry evidence before a composite
 * is reported at all. Below it the student gets "insufficient evidence" — level
 * 0, §23's own honest answer — and their subscores.
 *
 * This is a VALUE JUDGEMENT, stated as one, exactly like the weights below. It
 * is not derived from the data and no threshold could be: the OECD/JRC Handbook's
 * point is that the choice is the analyst's and must be declared. 40 means a
 * composite needs roughly four of the eleven components — enough that the number
 * describes a musician rather than a fragment of one.
 *
 * A constant, not a setting, for the same reason the weights are: an academy that
 * quietly lowered it would make two students' scores incomparable with nothing on
 * screen to say so.
 */
const MAM_MASTERY_MIN_COVERAGE = 40.0;

/**
 * Coverage at or above this is a complete-enough picture to report without a
 * provisional marker. Between MIN and this, the score shows with the marker.
 */
const MAM_MASTERY_FULL_COVERAGE = 75.0;

/** Reported band width. A composite from an 11-part weighted mean of subjective
 *  rubric marks does not support one-decimal precision; ten-point bands do. */
const MAM_MASTERY_BAND = 10;

/** §23's six-level competency scale. Index IS the level. */
const MAM_MASTERY_LEVELS = [
    0 => ['key' => 'not_assessed', 'label' => 'Not assessed', 'blurb' => 'Insufficient evidence.'],
    1 => ['key' => 'emerging',     'label' => 'Emerging',     'blurb' => 'Requires significant support.'],
    2 => ['key' => 'developing',   'label' => 'Developing',   'blurb' => 'Can perform with assistance.'],
    3 => ['key' => 'proficient',   'label' => 'Proficient',   'blurb' => 'Can perform independently to the expected standard.'],
    4 => ['key' => 'advanced',     'label' => 'Advanced',     'blurb' => 'Performs beyond the expected standard.'],
    5 => ['key' => 'mastery',      'label' => 'Mastery',      'blurb' => 'Demonstrates exceptional command and consistency.'],
];

/**
 * §24's components and their weights (summing to 100). The weights express the
 * academy's judgement about what a musician's overall standard is made of; they
 * are constants rather than settings because changing them silently would make
 * two students' scores incomparable across time.
 */
const MAM_MASTERY_COMPONENTS = [
    'preparation'          => ['label' => 'Preparation',          'weight' => 10],
    'teachback'            => ['label' => 'Teach-back',           'weight' => 10],
    'technical_competence' => ['label' => 'Technical competence', 'weight' => 15],
    'musical_accuracy'     => ['label' => 'Musical accuracy',     'weight' => 10],
    'rhythm_timing'        => ['label' => 'Rhythm & timing',      'weight' => 10],
    'theory'               => ['label' => 'Theory',               'weight' => 8],
    'expression'           => ['label' => 'Expression',           'weight' => 8],
    'repertoire'           => ['label' => 'Repertoire',           'weight' => 8],
    'creativity'           => ['label' => 'Creativity',           'weight' => 6],
    'performance'          => ['label' => 'Performance',          'weight' => 10],
    'consistency'          => ['label' => 'Consistency',          'weight' => 5],
];

/**
 * Map a rubric criterion label to a §24 component. Ordered longest-signal
 * first so 'note accuracy' does not match on 'note' before 'accuracy' is
 * considered. Unmatched → technical_competence (see the file header).
 */
function mam_mastery_component_for_criterion(string $label): string {
    $l = strtolower($label);
    $rules = [
        'rhythm_timing'        => ['rhythm', 'timing', 'tempo', 'groove', 'pulse', 'time keeping', 'coordination'],
        // Theory is tested BEFORE accuracy: §20's own example criterion is
        // "Score/Notation Accuracy", which is about reading the page, not about
        // playing the right notes. Checking 'accuracy' first would swallow it.
        'theory'               => ['theory', 'notation', 'score', 'harmony', 'chord knowledge', 'aural', 'ear training'],
        'musical_accuracy'     => ['accuracy', 'accurate', 'pitch', 'intonation', 'note', 'sight read', 'sight-read'],
        'expression'           => ['expression', 'expressive', 'dynamic', 'articulation', 'phrasing', 'musicality', 'interpretation', 'tone'],
        'creativity'           => ['creativ', 'improvis', 'compos', 'arrang'],
        'performance'          => ['performance', 'confidence', 'presentation', 'stage', 'ensemble', 'memory', 'recall'],
        'repertoire'           => ['repertoire'],
        'preparation'          => ['preparation', 'practice'],
        'technical_competence' => ['technique', 'technical', 'posture', 'position', 'finger', 'grip', 'stick', 'picking', 'embouchure', 'breath', 'pedal'],
    ];
    foreach ($rules as $component => $needles) {
        foreach ($needles as $n) if (strpos($l, $n) !== false) return $component;
    }
    return 'technical_competence';
}

/** §23 level for a 0–100 percentage. Never returns 0 — that is "no evidence". */
function mam_mastery_level_from_pct(float $pct): int {
    if ($pct >= 90) return 5;
    if ($pct >= 75) return 4;
    if ($pct >= 60) return 3;
    if ($pct >= 40) return 2;
    return 1;
}

/** The level descriptor, safe for any int. */
function mam_mastery_level(int $level): array {
    return MAM_MASTERY_LEVELS[$level] ?? MAM_MASTERY_LEVELS[0];
}

/**
 * Per-component evidence for a student, as `component => [pct, n, source]`.
 * Only components with at least one piece of evidence appear. Split out from
 * the score so a portal can show the breakdown without recomputing.
 */
function mam_mastery_components(int $studentId): array {
    $out = [];
    $put = function (string $key, float $earned, float $max, int $n, string $source) use (&$out) {
        if ($max <= 0 || $n <= 0) return;
        if (!isset($out[$key])) $out[$key] = ['earned' => 0.0, 'max' => 0.0, 'n' => 0, 'sources' => []];
        $out[$key]['earned'] += $earned;
        $out[$key]['max']    += $max;
        $out[$key]['n']      += $n;
        if (!in_array($source, $out[$key]['sources'], true)) $out[$key]['sources'][] = $source;
    };

    // ── Preparation: how far the student's modules got before class.
    $prep = mam_db_row(
        'SELECT COUNT(*) AS n, COALESCE(SUM(percent),0) AS earned FROM lesson_progress WHERE student_id = ?',
        [$studentId]
    );
    if ((int) ($prep['n'] ?? 0) > 0) $put('preparation', (float) $prep['earned'], (int) $prep['n'] * 100.0, (int) $prep['n'], 'lesson_progress');

    // ── Teach-back: the mean of §19's six dimensions across reviewed teach-backs.
    $tbs = mam_db_all(
        "SELECT understanding, accuracy, vocabulary, explanation, application, confidence
           FROM teachbacks WHERE student_id = ? AND status = 'reviewed'",
        [$studentId]
    );
    $tbEarned = 0.0; $tbMax = 0.0; $tbN = 0;
    foreach ($tbs as $t) {
        foreach (['understanding', 'accuracy', 'vocabulary', 'explanation', 'application', 'confidence'] as $dim) {
            if ($t[$dim] === null || $t[$dim] === '') continue;
            $tbEarned += (float) $t[$dim];
            // Each dimension is scored 0–5 (MAM_TEACHBACK_DIMENSIONS; the writer,
            // mam_preclass_teachback_review(), clamps to that range). This used
            // to divide by 100, so a perfect teach-back counted as 5%.
            $tbMax    += 5.0;
        }
        $tbN++;
    }
    $put('teachback', $tbEarned, $tbMax, $tbN, 'teachbacks');

    // ── Rubric-scored submissions, bucketed by criterion label (§20-21).
    $reviews = mam_db_all(
        "SELECT sr.scores_json, sr.rubric_id
           FROM submission_reviews sr
           JOIN submissions s ON s.id = sr.submission_id
          WHERE s.student_id = ? AND sr.rubric_id IS NOT NULL AND sr.scores_json IS NOT NULL",
        [$studentId]
    );
    if ($reviews) {
        $rubricIds = array_values(array_unique(array_map(fn($r) => (int) $r['rubric_id'], $reviews)));
        $ph = implode(',', array_fill(0, count($rubricIds), '?'));
        $crit = [];
        foreach (mam_db_all("SELECT id, rubric_id, label, max_points FROM rubric_criteria WHERE rubric_id IN ($ph)", $rubricIds) as $c) {
            $crit[(int) $c['id']] = $c;
        }
        foreach ($reviews as $r) {
            $scores = json_decode((string) $r['scores_json'], true);
            if (!is_array($scores)) continue;
            foreach ($scores as $critId => $points) {
                $c = $crit[(int) $critId] ?? null;
                if (!$c || !is_numeric($points)) continue;
                $max = max(1.0, (float) $c['max_points']);
                $put(mam_mastery_component_for_criterion((string) $c['label']),
                     min((float) $points, $max), $max, 1, 'rubric');
            }
        }
    }

    // ── Finalised course results feed technical competence: they are the
    //    academy's own summative judgement and outrank any single submission.
    $res = mam_db_row(
        "SELECT COUNT(*) AS n, COALESCE(SUM(r.total_score),0) AS earned
           FROM results r JOIN course_enrollments ce ON ce.id = r.enrollment_id
          WHERE ce.student_id = ? AND r.total_score IS NOT NULL",
        [$studentId]
    );
    if ((int) ($res['n'] ?? 0) > 0) $put('technical_competence', (float) $res['earned'], (int) $res['n'] * 100.0, (int) $res['n'], 'results');

    // ── Repertoire: pieces carried to performance standard vs pieces taken on.
    $rep = mam_db_all("SELECT status, COUNT(*) AS c FROM repertoire WHERE student_id = ? GROUP BY status", [$studentId]);
    $repTotal = 0; $repDone = 0;
    foreach ($rep as $r) {
        $repTotal += (int) $r['c'];
        if (in_array((string) $r['status'], ['mastered', 'performed', 'polished'], true)) $repDone += (int) $r['c'];
    }
    if ($repTotal > 0) $put('repertoire', $repDone * 100.0, $repTotal * 100.0, $repTotal, 'repertoire');

    // ── Performance: pieces actually performed at a recital are the strongest
    //    evidence the academy holds that a student can do it in front of people.
    $perf = mam_db_row(
        "SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'performed' THEN 1 ELSE 0 END) AS done
           FROM recital_items WHERE student_id = ? AND status IN ('accepted','performed','withdrawn')",
        [$studentId]
    );
    if ((int) ($perf['total'] ?? 0) > 0) {
        $put('performance', (int) ($perf['done'] ?? 0) * 100.0, (int) $perf['total'] * 100.0, (int) $perf['total'], 'recitals');
    }

    // ── Consistency: modules reached without needing a re-attempt or a
    //    carry-forward. This is the §24 component about steadiness, not speed.
    $con = mam_db_row(
        "SELECT COUNT(*) AS n,
                SUM(CASE WHEN attempts <= 1 AND status <> 'carried_forward' AND status <> 'remedial' THEN 1 ELSE 0 END) AS clean
           FROM lesson_progress WHERE student_id = ?",
        [$studentId]
    );
    if ((int) ($con['n'] ?? 0) > 0) $put('consistency', (int) ($con['clean'] ?? 0) * 100.0, (int) $con['n'] * 100.0, (int) $con['n'], 'lesson_progress');

    $final = [];
    foreach (MAM_MASTERY_COMPONENTS as $key => $meta) {
        if (!isset($out[$key])) continue;
        $c = $out[$key];
        $final[$key] = [
            'key'     => $key,
            'label'   => $meta['label'],
            'weight'  => $meta['weight'],
            'pct'     => round($c['earned'] / $c['max'] * 100, 1),
            'n'       => $c['n'],
            'sources' => $c['sources'],
        ];
    }
    return $final;
}

/**
 * The MAM Mastery Score™ (§24) for a student: a 0–100 composite plus its §23
 * level, the per-component breakdown, and the evidence coverage the score rests
 * on. Attendance and punctuality are returned SEPARATELY and deliberately are
 * not folded in (§24).
 *
 * With no evidence at all the score is null and the level is 0 — the honest
 * answer, and the one §23 level 0 exists to express. A score is flagged
 * `provisional` below half coverage so a thin record is never displayed with
 * the same authority as a complete one.
 */
function mam_mastery_score(int $studentId): array {
    $components = mam_mastery_components($studentId);
    $totalWeight = 0; $sum = 0.0;
    foreach ($components as $c) { $totalWeight += $c['weight']; $sum += $c['pct'] * $c['weight']; }
    $fullWeight = array_sum(array_column(MAM_MASTERY_COMPONENTS, 'weight'));
    $coverage   = $fullWeight > 0 ? round($totalWeight / $fullWeight * 100, 1) : 0.0;

    /* The renormalised weighted mean over evidenced components. Kept, because
       excluding an unassessed component is correct. What changes is whether we
       are willing to REPORT it, and how. */
    $raw = $totalWeight > 0 ? round($sum / $totalWeight, 1) : null;

    /* Below the coverage floor there is no composite — not a composite with a
       warning. "Insufficient evidence" is a different statement from a number,
       and it is the true one. §23's level 0 exists to say exactly this. */
    $sufficient = $raw !== null && $coverage >= MAM_MASTERY_MIN_COVERAGE;
    $score = $sufficient ? $raw : null;
    $level = $score === null ? 0 : mam_mastery_level_from_pct($score);
    $meta  = mam_mastery_level($level);

    /* What the unscored weight could still do to this number.
       Not a confidence interval — there is no sampling model here and inventing
       one would be worse than saying nothing. These are the ARITHMETIC bounds:
       the floor is this student's score if every unevidenced component came in at
       zero, the ceiling if every one came in at full marks. At complete coverage
       they collapse onto the score. At 30% coverage a "80" is anything from 24 to
       94, which is the honest reason a thin score cannot be compared with a
       complete one — and it is checkable arithmetic a parent can follow. */
    $bounds = null;
    if ($raw !== null) {
        $earnedShare = $raw * $coverage / 100;          // points actually evidenced
        $bounds = [
            'min' => round($earnedShare, 1),
            'max' => round($earnedShare + (100 - $coverage), 1),
        ];
    }

    /* A band, not a decimal. Ten points wide, floored — 73.4 reports as "70–79".
       An 11-component weighted mean of subjective rubric marks cannot support one
       decimal place, and printing one invites a parent to read a 2-point gap
       between two children as a real difference. */
    $band = null;
    if ($score !== null) {
        $lo = (int) (floor($score / MAM_MASTERY_BAND) * MAM_MASTERY_BAND);
        $band = ['low' => $lo, 'high' => min(100, $lo + MAM_MASTERY_BAND - 1),
                 'label' => $lo . '–' . min(100, $lo + MAM_MASTERY_BAND - 1)];
    }

    // Attendance + punctuality — reported beside the score, never inside it.
    $att = mam_db_row(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN status IN ('present','late','excused','left_early') THEN 1 ELSE 0 END) AS attended,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) AS lates
           FROM session_attendance WHERE student_id = ?",
        [$studentId]
    );
    $attTotal = (int) ($att['total'] ?? 0);

    return [
        'student_id'  => $studentId,
        'score'       => $score,
        'level'       => $level,
        'level_key'   => $meta['key'],
        'level_label' => $meta['label'],
        'level_blurb' => $meta['blurb'],
        /* The headline is what a parent or child actually reads, so it must never
           imply a precision or a completeness the evidence does not carry. */
        'headline'    => $score === null
            ? ($raw === null
                ? 'MAM Mastery Score: not yet assessed'
                // Withheld, and the reason said plainly rather than left to be
                // inferred from a coverage figure elsewhere on the page.
                : 'MAM Mastery Score: not enough evidence yet ('
                  . (int) round($coverage) . '% of the assessment covered)')
            : 'MAM Mastery Score: ' . $band['label'] . ' — ' . strtoupper($meta['label'])
              . ($coverage < MAM_MASTERY_FULL_COVERAGE ? ' (provisional)' : ''),
        'coverage_pct' => $coverage,
        /* The raw renormalised mean, retained for internal continuity and audit.
           ⚠️ Do NOT surface `raw_score` to a student, guardian or league table —
           it is the number the coverage floor exists to withhold. Use `score`
           (null when insufficient) and `band`. */
        'raw_score'    => $raw,
        'sufficient'   => $sufficient,
        'min_coverage' => MAM_MASTERY_MIN_COVERAGE,
        'band'         => $band,
        'bounds'       => $bounds,
        'provisional'  => $score !== null && $coverage < MAM_MASTERY_FULL_COVERAGE,
        'components'   => array_values($components),
        'missing'      => array_values(array_diff(array_keys(MAM_MASTERY_COMPONENTS), array_keys($components))),
        // Separate by design (§24).
        'attendance'  => [
            'sessions'      => $attTotal,
            'attendance_pct'=> $attTotal > 0 ? round((int) ($att['attended'] ?? 0) / $attTotal * 100, 1) : null,
            'lates'         => (int) ($att['lates'] ?? 0),
            'punctuality_pct' => $attTotal > 0 ? round((1 - (int) ($att['lates'] ?? 0) / $attTotal) * 100, 1) : null,
        ],
    ];
}

/**
 * Memoised `mam_mastery_score()` — for a caller that reads one student's score
 * MORE THAN ONCE in a single request. The engine is eight queries deep
 * (lesson_progress twice, teachbacks, submission_reviews, results, repertoire,
 * and session_attendance twice), so a second read is eight wasted round trips.
 *
 * ⚠️ **Only use this where the repeat is real, and never after a write.** The
 * memo is request-scoped and does not observe evidence recorded after the first
 * call; a route that grades work and then reports a score must call
 * `mam_mastery_forget()` in between. The guardian children list deliberately
 * does NOT use it: it computes each child exactly once, so there is nothing to
 * save and the staleness would be pure downside. Its per-child cost is inherent
 * — the design puts a band in every child's tile — and the fix for that, if it
 * ever bites, is a summary table maintained on write, not a cache on read.
 */
function mam_mastery_score_cached(int $studentId): array {
    // Held in a reference a second function can reach, not a bare `static`:
    // `mam_mastery_forget()` has to be able to drop it, and a static declared
    // inside this body is invisible from anywhere else.
    if (!isset($GLOBALS['__mam_mastery_memo'])) $GLOBALS['__mam_mastery_memo'] = [];
    if (!array_key_exists($studentId, $GLOBALS['__mam_mastery_memo'])) {
        $GLOBALS['__mam_mastery_memo'][$studentId] = mam_mastery_score($studentId);
    }
    return $GLOBALS['__mam_mastery_memo'][$studentId];
}

/** Drop the memo — one student, or all of them. Call after writing evidence. */
function mam_mastery_forget(?int $studentId = null): void {
    if ($studentId === null) { $GLOBALS['__mam_mastery_memo'] = []; return; }
    unset($GLOBALS['__mam_mastery_memo'][$studentId]);
}

/**
 * The mastery result as it may be shown to a STUDENT or a GUARDIAN — the one
 * predicate for that, so the two surfaces cannot disagree about what is
 * publishable.
 *
 * Two things are removed, and both were reachable:
 *
 *  1. **`raw_score`.** The header above says plainly not to surface it, and
 *     `GET /api/student/mastery` was returning this whole array verbatim, so the
 *     precise composite the coverage floor exists to withhold was in the JSON
 *     the student's own browser received. Saying "do not surface" in a comment is
 *     not a control; removing the key is.
 *
 *  2. **`bounds`, but only when the score is WITHHELD.** Beside a reported band
 *     the bounds are a deliberate disclosure — the arithmetic a parent can
 *     follow, as the comment on them explains — and the band is already public.
 *     Beside a withheld score they are the opposite: `bounds.min` is
 *     `raw × coverage ÷ 100`, so `raw = bounds.min × 100 ÷ coverage_pct` recovers
 *     the withheld figure exactly from two keys sitting next to each other in the
 *     same payload. That is a number the academy would be PRESENTING, in a
 *     payload whose own `sufficient: false` says it has decided not to.
 *
 * ⚠️ **This does not make `raw_score` unrecoverable, and no honest version of it
 * could.** `components` carries a percentage and a weight per evidenced
 * component, the full weight table is a public constant, and the composite is
 * their weighted mean — so anyone who wants the figure can compute it from what
 * is published. Subscores are published ON PURPOSE (the file header: "Subscores
 * remain available throughout, because per-component evidence is real even when
 * the composite is not", and the design's guardian report draws a bar per
 * criterion), so the alternative is withholding real evidence to obstruct
 * arithmetic.
 *
 * What the coverage floor governs is therefore what the academy STATES as a
 * comparable headline figure — the thing a parent anchors on and compares
 * between two children — not the information content of the payload. Claiming
 * otherwise would be theatre, and the previous attempt at this made exactly that
 * claim ("an explicit key allowlist, so raw_score cannot leak") while shipping
 * both the mean itself and two keys that invert to it.
 *
 * Everything else stays: per-component percentages ARE guardian-facing, and
 * attendance/punctuality are §24's required companions to the score.
 */
function mam_mastery_publishable(array $m): array {
    unset($m['raw_score']);
    if (empty($m['sufficient'])) {
        $m['bounds'] = null;
        $m['withheld_reason'] = ($m['coverage_pct'] ?? 0) > 0
            ? 'below_coverage_floor'
            : 'not_assessed';
    }
    return $m;
}
