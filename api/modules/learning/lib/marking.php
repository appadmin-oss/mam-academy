<?php
/**
 * Performance marking — the rules the instructor portal's Grade entry screen
 * is built on (design: `docs/design/2026-08-portal-redesign`, instructor
 * mobile frame, "Grade entry").
 *
 * ── WHY THIS FILE EXISTS ──────────────────────────────────────────────────
 * `rubric_criteria` already carries a label, a max and a weight. What it has
 * no room for is the two things this screen turns on: whether a criterion may
 * be marked while WATCHING, and what a given mark is called. Both are policy
 * rather than data, so they live in code where they can be reasoned about and
 * tested — the same choice `mastery.php` makes for its component registry and
 * `dataprotection.php` makes for its ROPA.
 *
 * ── THE AUDIO-ONLY RULE, WHICH IS THE POINT OF THE SCREEN ─────────────────
 * The design hides the video while the technical criteria are marked, and says
 * why on the face of it: "Seeing a performance measurably lifts the marks
 * given for it, so the technical criteria are heard, not watched."
 *
 * That is a real and well-replicated effect — visual information shifts
 * evaluations of musical performance, and it shifts them for reasons that have
 * nothing to do with pitch or timing. An instructor cannot un-see a nervous
 * child, an expensive instrument, or a familiar face. The control is not to
 * ask them to try: it is to withhold the channel until the criteria that
 * depend on it.
 *
 * So modality is a property of the CRITERION, not a preference of the marker,
 * and there is deliberately no setting that turns it off. `shape` (musical
 * shape / presentation / stagecraft) is the one criterion that cannot be
 * judged without seeing it, and it is marked last, after the technical marks
 * are already committed.
 *
 * ── WHAT IS DELIBERATELY NOT HERE ─────────────────────────────────────────
 * No automatic scoring of a child's playing, of any kind. The design's first
 * refusal, and the studio's own scoring is a self-attested practice result for
 * a different purpose. This file helps a human mark; it never marks.
 */
declare(strict_types=1);

/**
 * The marking criteria, in the order they are presented.
 *
 * `modality` is the control described above. `technical` criteria are marked
 * from audio alone; `presentation` is the criterion that requires sight and is
 * therefore taken last.
 */
const MAM_MARKING_CRITERIA = [
    'pitch' => ['label' => 'Pitch',  'modality' => 'audio', 'kind' => 'technical',
                'help'  => 'Accuracy of notes and intonation.'],
    'time'  => ['label' => 'Time',   'modality' => 'audio', 'kind' => 'technical',
                'help'  => 'Pulse, subdivision and recovery after a slip.'],
    'tone'  => ['label' => 'Tone',   'modality' => 'audio', 'kind' => 'technical',
                'help'  => 'Quality and control of sound across the range.'],
    'shape' => ['label' => 'Shape',  'modality' => 'video', 'kind' => 'presentation',
                'help'  => 'Phrasing, communication and presence. Marked last, with the video.'],
];

/**
 * Band thresholds as an exact FRACTION of the criterion's maximum — numerator
 * and denominator, not a decimal.
 *
 * ⚠️ MEASURED: written as 0.6667 the pass floor came out one mark too high —
 * `ceil(30 * 0.6667)` is 21, where two-thirds of 30 is 20. A rounded decimal
 * standing in for a fraction moves a band boundary, and a band boundary is the
 * difference between a pass and a fail for a real child. Kept as a fraction so
 * the arithmetic is the rule rather than an approximation of it.
 */
const MAM_MARKING_BANDS = [
    'distinction' => [9, 10],
    'merit'       => [8, 10],
    'pass'        => [2, 3],
];

/** How often a mark is independently second-marked. 1 in 8, per the design. */
const MAM_MARKING_SECOND_MARK_EVERY = 8;

/* The two sentences the Grade entry screen must print. Constants, not strings
   fetched with a row: a caller wanting the notice should not have to load a
   submission to get it, and a screen that renders an empty string because the
   lookup missed has silently dropped the explanation for why the video is
   gone — which is the only thing that makes the control legible. */
const MAM_MARKING_AUDIO_NOTICE = 'Video is hidden while you mark pitch, time and tone. Seeing a performance measurably lifts the marks given for it, so the technical criteria are heard, not watched.';
const MAM_MARKING_PUBLISH_NOTICE = 'Marks stay unpublished until the Examinations panel ratifies them. Any change after that is logged against your name.';

/**
 * May this criterion be marked while watching?
 *
 * ⚠️ The ONLY caller-visible answer to "should the player show video". There
 * is no setting and no override: an instructor who could turn this off would
 * be the instructor most likely to. An unknown criterion is treated as
 * technical (audio only) — the safe direction, because withholding the video
 * from something that did not need it costs nothing, while showing it for a
 * technical mark is the exact failure this exists to prevent.
 */
function mam_marking_video_allowed(string $criterion): bool {
    $c = mam_marking_criterion($criterion);
    return $c !== null && $c['modality'] === 'video';
}

/**
 * Resolve a criterion by key OR by rubric label.
 *
 * Rubric criteria are authored by instructors, so the label is what actually
 * arrives. Matched on a normalised substring, and ⚠️ the order matters for the
 * same reason `mastery.php` tests `theory` before `musical_accuracy`: a label
 * like "Tone and shape" contains both, and the FIRST registry key that matches
 * wins, so the registry is ordered most-specific-consequence first. Here that
 * means a label mentioning shape is presentation only when it mentions nothing
 * technical.
 */
function mam_marking_criterion(string $criterion): ?array {
    $k = strtolower(trim($criterion));
    if ($k === '') return null;
    if (isset(MAM_MARKING_CRITERIA[$k])) return ['key' => $k] + MAM_MARKING_CRITERIA[$k];

    // Technical first: a label naming any technical dimension is marked by ear,
    // whatever else it also says.
    foreach (['pitch', 'time', 'tone'] as $key) {
        if (str_contains($k, $key)) return ['key' => $key] + MAM_MARKING_CRITERIA[$key];
    }
    foreach (['intonation' => 'pitch', 'rhythm' => 'time', 'pulse' => 'time',
              'timing' => 'time', 'sound' => 'tone'] as $needle => $key) {
        if (str_contains($k, $needle)) return ['key' => $key] + MAM_MARKING_CRITERIA[$key];
    }
    foreach (['shape', 'phrasing', 'presentation', 'stagecraft', 'performance'] as $needle) {
        if (str_contains($k, $needle)) return ['key' => 'shape'] + MAM_MARKING_CRITERIA['shape'];
    }
    return null;
}

/**
 * The band table for a criterion out of `$max`, as the screen prints it.
 *
 * Derived from the share constants rather than written out, so a rubric using
 * a max other than 30 still gets a coherent table. At max = 30 this reproduces
 * the design's own figures exactly: Pass 20–23, Merit 24–26, Distinction 27–30.
 */
function mam_marking_bands(int $max): array {
    $max = max(1, $max);
    $share = fn(array $f) => (int) ceil($max * $f[0] / $f[1]);
    $dist  = $share(MAM_MARKING_BANDS['distinction']);
    $merit = $share(MAM_MARKING_BANDS['merit']);
    $pass  = $share(MAM_MARKING_BANDS['pass']);
    return [
        ['band' => 'distinction', 'label' => 'Distinction', 'low' => $dist,  'high' => $max],
        ['band' => 'merit',       'label' => 'Merit',       'low' => $merit, 'high' => $dist - 1],
        ['band' => 'pass',        'label' => 'Pass',        'low' => $pass,  'high' => $merit - 1],
    ];
}

/**
 * What a mark is called.
 *
 * ⚠️ `null` is "Not assessed", and it is NOT zero. The whole repo holds this
 * line — an unevidenced competency is a row with `level='insufficient'` and a
 * NULL pct, a new student's mastery score is null at level 0 — because scoring
 * an unassessed beginner 0 reports them as failing. A marker who has not heard
 * something must be able to say so.
 */
function mam_marking_band_of(?int $score, int $max): array {
    if ($score === null) {
        return ['band' => 'not_assessed', 'label' => 'Not assessed', 'assessed' => false];
    }
    $score = max(0, min($max, $score));
    foreach (mam_marking_bands($max) as $b) {
        if ($score >= $b['low']) return ['band' => $b['band'], 'label' => $b['label'], 'assessed' => true];
    }
    return ['band' => 'below', 'label' => 'Below pass', 'assessed' => true];
}

/**
 * Is this submission one of the 1-in-8 that a second marker also hears?
 *
 * DETERMINISTIC on the submission id, not random. Three reasons, all of which
 * bit somebody somewhere:
 *  · a marker must not be able to re-roll until it says no;
 *  · the same submission must give the same answer on every page load, or a
 *    refresh silently changes whether the mark needs agreeing;
 *  · a year later an audit has to be able to reproduce which marks were
 *    double-marked, from the ids alone.
 * The hash is only a spreader — it carries no secret and needs none.
 */
function mam_marking_needs_second_marker(int $submissionId, int $every = MAM_MARKING_SECOND_MARK_EVERY): bool {
    if ($every < 2) return true;
    return (hexdec(substr(md5('mam-second-mark:' . $submissionId), 0, 8)) % $every) === 0;
}

/**
 * The payload the Grade entry screen needs for one submission.
 *
 * `show_video` is computed per criterion here rather than in the browser: a
 * client-side rule is a rule an instructor can turn off with devtools, and the
 * point of the control is that it does not depend on the marker's willingness.
 */
function mam_marking_sheet(int $submissionId, array $criteria = []): array {
    $sub = mam_db_row(
        'SELECT s.id, s.student_id, s.artifact_url, s.artifact_kind, s.status, s.submitted_at,
                u.name AS student_name
           FROM submissions s LEFT JOIN users u ON u.id = s.student_id
          WHERE s.id = ?', [$submissionId]);
    if (!$sub) return [];

    if (!$criteria) {
        $criteria = [];
        foreach (MAM_MARKING_CRITERIA as $k => $c) $criteria[] = ['label' => $c['label'], 'max_points' => 30];
    }

    $out = [];
    foreach ($criteria as $c) {
        $resolved = mam_marking_criterion((string) ($c['label'] ?? ''));
        $max = (int) ($c['max_points'] ?? 30);
        $key = $resolved['key'] ?? null;
        $out[] = [
            'key'        => $key,
            'label'      => (string) ($c['label'] ?? ''),
            'max'        => $max,
            'kind'       => $resolved['kind'] ?? 'technical',
            'help'       => $resolved['help'] ?? '',
            'show_video' => $key !== null && mam_marking_video_allowed($key),
            'bands'      => mam_marking_bands($max),
        ];
    }

    // Technical criteria come first: the point of the sequence is that the
    // technical marks are committed before the video is ever shown.
    usort($out, fn($a, $b) => ($a['kind'] === 'presentation' ? 1 : 0) <=> ($b['kind'] === 'presentation' ? 1 : 0));

    return [
        'submission_id'      => (int) $sub['id'],
        'student_name'       => (string) ($sub['student_name'] ?? ''),
        'artifact_url'       => (string) ($sub['artifact_url'] ?? ''),
        'artifact_kind'      => (string) ($sub['artifact_kind'] ?? 'link'),
        'criteria'           => $out,
        'second_marker'      => mam_marking_needs_second_marker((int) $sub['id']),
        // Stated as data so the screen cannot forget to say it. Marks are not a
        // family's to see until the panel has ratified them — the same rule
        // `mam_report_ratify()` enforces on the report itself.
        'publication_notice' => MAM_MARKING_PUBLISH_NOTICE,
        'audio_only_notice'  => MAM_MARKING_AUDIO_NOTICE,
    ];
}

/**
 * Why a marking-sheet save must be refused, or null when it may be stored.
 * Every criterion on the sheet needs EITHER a whole-number mark within its
 * range OR an explicit `null` ("Not assessed", chosen on the band row). A
 * criterion the marker never touched is absent from the map, and saving that
 * used to accept the submission with no marks and drop it off the queue.
 * `null` stays "Not assessed", never 0 — the same line the mastery score holds.
 */
function mam_marking_rubric_refusal(array $rubric, array $sheetCriteria): ?string {
    if (!$sheetCriteria) return null;
    foreach ($sheetCriteria as $c) {
        $key = (string) ($c['key'] ?? '');
        if ($key === '') continue;
        if (!array_key_exists($key, $rubric)) return 'no_marks';
        $v = $rubric[$key];
        if ($v === null) continue;
        if (is_string($v) && $v === 'not_assessed') continue;
        if (!is_int($v) && !(is_float($v) && floor($v) == $v) && !(is_string($v) && ctype_digit($v))) return 'invalid_mark';
        $n = (int) $v; $max = (int) ($c['max'] ?? 30);
        if ($n < 0 || $n > $max) return 'invalid_mark';
    }
    return null;
}
