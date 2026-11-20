<?php
/**
 * MAM Academy — the academic calendar as data (schema v83).
 * ─────────────────────────────────────────────────────────────
 * `academic-session.php` already answers "which term is it" by computing
 * TONI/MEDI/SUMMER/DOMI windows from constants plus a JSON settings override.
 * That is fine for a label on a page and useless for everything that hangs off a
 * term: a refund percentage, the withdrawal outcome, an official enrolment count.
 * Those need dated rows somebody can point at.
 *
 * Both research blueprints make this the dependency for refunds, scholarship
 * pro-ration and the "W" outcome, and they are right — before this file there was
 * **no refund function anywhere in the codebase**, so every refund was a manual
 * decision with nothing to check it against.
 *
 * ── How this coexists with the computed engine ──────────────────────────────
 * It does not replace it. `mam_calendar_current_term()` prefers a real row and
 * falls back to the computed window, so an install with an empty calendar behaves
 * exactly as before and one with authored terms gets enforceable deadlines. That
 * is the strangler pattern the research recommends over a cutover, and it means
 * this ships without touching `/apply`.
 *
 * ── Three invariants worth stating up front ─────────────────────────────────
 *  1. **A refund percentage is COMPUTED from a date, never stored per student.**
 *     Two students withdrawing on the same day must get the same answer, and the
 *     answer must be reproducible a year later during an audit.
 *  2. **The census is a SNAPSHOT and is append-only.** "Enrolment as of the census
 *     date" must not change when somebody withdraws in week nine. Recompute-on-read
 *     is the commonest way an enrolment figure quietly rewrites history.
 *  3. **Deadlines are adjudicated in the ACADEMY's timezone**, displayed in the
 *     reader's. A diaspora guardian in New York must not get a different refund
 *     from a Lagos guardian because their clocks disagree about which day it is.
 */
declare(strict_types=1);

/** The academy's canonical timezone for adjudicating a deadline. */
const MAM_CALENDAR_TZ = 'Africa/Lagos';

/**
 * The conventional pro-rata refund table, as percentages by teaching week.
 *
 * Taken from the university standard both blueprints cite: 100% before teaching
 * starts, tapering to 0% at roughly the 60% point of the term. It is the DEFAULT
 * used to seed a term's published schedule — once seeded, the rows are the truth,
 * because the academy may publish something different and a family is entitled to
 * the table that was published when they enrolled.
 *
 * Keyed by the week the withdrawal falls in (1-based). Week 0 = before the term
 * starts.
 */
const MAM_CALENDAR_REFUND_DEFAULT = [
    0 => 100,   // before teaching begins
    1 => 100,   // within add/drop
    2 => 80,
    3 => 60,
    4 => 50,
    5 => 40,
    6 => 25,
    7 => 10,
    8 => 0,     // past ~60% of a 12-week term
];

/** Statuses a term can hold. */
const MAM_CALENDAR_TERM_STATUSES = ['planned', 'active', 'closed'];

/* ── Authoring ───────────────────────────────────────────────────────────── */

/**
 * Create or update a term. Keyed on (year_label, code), so re-running with the
 * same pair edits rather than duplicating — an academy correcting a date should
 * not end up with two overlapping terms.
 *
 * Deadline ordering is enforced by CHECK constraints in the schema; this adds the
 * checks the schema cannot express (overlap with a SIBLING term), because two
 * terms covering the same day makes "which term is it" — and therefore which
 * refund table applies — ambiguous.
 */
function mam_calendar_term_save(array $in, ?int $actorId = null): int
{
    $year = trim((string) ($in['year_label'] ?? ''));
    $code = strtolower(trim((string) ($in['code'] ?? '')));
    if ($year === '' || $code === '') throw new InvalidArgumentException('year_and_code_required');

    $starts = trim((string) ($in['starts_on'] ?? ''));
    $ends   = trim((string) ($in['ends_on'] ?? ''));
    if ($starts === '' || $ends === '') throw new InvalidArgumentException('dates_required');
    if ($ends < $starts) throw new InvalidArgumentException('ends_before_starts');

    $status = strtolower(trim((string) ($in['status'] ?? 'planned')));
    if (!in_array($status, MAM_CALENDAR_TERM_STATUSES, true)) $status = 'planned';

    $existing = mam_db_row('SELECT id FROM academic_terms WHERE year_label = ? AND code = ?', [$year, $code]);
    $selfId   = $existing ? (int) $existing['id'] : 0;

    /* Overlap with any OTHER term. Two terms covering one day means two possible
       refund tables for the same withdrawal, and no way to say which is right. */
    $clash = mam_db_row(
        'SELECT id, label FROM academic_terms
          WHERE id <> ? AND starts_on <= ? AND ends_on >= ?
          LIMIT 1',
        [$selfId, $ends, $starts]
    );
    if ($clash) {
        throw new RuntimeException('overlaps_term:' . (string) ($clash['label'] ?? $clash['id']));
    }

    $row = [
        'year_label'          => $year,
        'code'                => $code,
        'term_no'             => max(1, (int) ($in['term_no'] ?? 1)),
        'label'               => trim((string) ($in['label'] ?? strtoupper($code) . ' Term')),
        'starts_on'           => $starts,
        'ends_on'             => $ends,
        'add_drop_deadline'   => trim((string) ($in['add_drop_deadline']   ?? '')) ?: null,
        'withdrawal_deadline' => trim((string) ($in['withdrawal_deadline'] ?? '')) ?: null,
        'census_date'         => trim((string) ($in['census_date']         ?? '')) ?: null,
        'teaching_weeks'      => max(1, (int) ($in['teaching_weeks'] ?? 12)),
        'timezone'            => trim((string) ($in['timezone'] ?? MAM_CALENDAR_TZ)) ?: MAM_CALENDAR_TZ,
        'status'              => $status,
        'notes'               => trim((string) ($in['notes'] ?? '')) ?: null,
    ];

    if ($selfId) {
        mam_db_update('academic_terms', $row, ['id' => $selfId]);
        $id = $selfId;
    } else {
        $id = mam_db_insert('academic_terms', $row);
    }
    mam_audit('calendar_term_save', 'academic_term', $id,
        ['year' => $year, 'code' => $code, 'starts' => $starts, 'ends' => $ends, 'actor' => $actorId]);

    // A term with no published refund table cannot answer the question the table
    // exists for, so seed the conventional one on creation.
    if (!$selfId) mam_calendar_seed_refund_schedule($id);
    return $id;
}

/**
 * Seed a term's published refund schedule from the conventional table.
 *
 * `$replace` wipes any existing rows first. Deliberately not the default: a term
 * whose table has been edited by the academy must not silently revert because
 * somebody re-saved the dates.
 */
function mam_calendar_seed_refund_schedule(int $termId, bool $replace = false): int
{
    if ($replace) {
        mam_db()->prepare('DELETE FROM term_refund_schedule WHERE term_id = ?')->execute([$termId]);
    } elseif (mam_db_row('SELECT id FROM term_refund_schedule WHERE term_id = ? LIMIT 1', [$termId])) {
        return 0;
    }
    $n = 0;
    $weeks = array_keys(MAM_CALENDAR_REFUND_DEFAULT);
    foreach ($weeks as $i => $w) {
        // The last band runs to the end of the term rather than stopping at its
        // own week, so a withdrawal in week 11 of a 12-week term still resolves.
        $next = $weeks[$i + 1] ?? null;
        $to   = $next === null ? 999 : ($next - 1);
        mam_db_insert('term_refund_schedule', [
            'term_id' => $termId, 'week_from' => $w, 'week_to' => $to,
            'percent' => (int) MAM_CALENDAR_REFUND_DEFAULT[$w],
        ]);
        $n++;
    }
    return $n;
}

/** The published schedule for a term, ordered. */
function mam_calendar_refund_schedule(int $termId): array
{
    return mam_db_all('SELECT week_from, week_to, percent FROM term_refund_schedule
                        WHERE term_id = ? ORDER BY week_from', [$termId]);
}

/* ── Reading ─────────────────────────────────────────────────────────────── */

/** One term by id, or null. */
function mam_calendar_term(int $termId): ?array
{
    $t = mam_db_row('SELECT * FROM academic_terms WHERE id = ?', [$termId]);
    return $t ? mam_calendar_decorate($t) : null;
}

/** Every authored term, newest first. */
function mam_calendar_terms(int $limit = 100): array
{
    $rows = mam_db_all('SELECT * FROM academic_terms ORDER BY starts_on DESC LIMIT ' . max(1, min(500, $limit)));
    return array_map('mam_calendar_decorate', $rows);
}

/**
 * The term covering a date — the authored row when one exists.
 *
 * Returns null when the calendar has nothing for that date. Callers that need a
 * label regardless should fall back to mam_session_current(); callers that need
 * a DEADLINE must treat null as "no calendar authored" and refuse to guess,
 * because inventing a refund date is worse than admitting there isn't one.
 */
function mam_calendar_term_on(?string $date = null): ?array
{
    $d = $date ?: mam_calendar_today();
    $t = mam_db_row('SELECT * FROM academic_terms WHERE starts_on <= ? AND ends_on >= ?
                      ORDER BY starts_on DESC LIMIT 1', [$d, $d]);
    return $t ? mam_calendar_decorate($t) : null;
}

/**
 * The operative term: an authored row if the calendar has one for today,
 * otherwise the computed window, tagged so a caller can tell which it got.
 */
function mam_calendar_current_term(): array
{
    $t = mam_calendar_term_on();
    if ($t) return ['source' => 'calendar'] + $t;
    $s = function_exists('mam_session_current') ? mam_session_current() : [];
    return [
        'source'     => 'computed',
        'id'         => null,
        'code'       => (string) ($s['code'] ?? ''),
        'label'      => (string) ($s['label'] ?? ''),
        'year_label' => (string) ($s['year'] ?? ''),
        'starts_on'  => (string) ($s['starts_on'] ?? ''),
        'ends_on'    => (string) ($s['ends_on'] ?? ''),
        // No deadlines exist in the computed path, and saying so is the point:
        // a caller must not read a missing deadline as "no deadline".
        'add_drop_deadline'   => null,
        'withdrawal_deadline' => null,
        'census_date'         => null,
        'calendar_authored'   => false,
    ];
}

/** Today, in the academy's timezone — never the server's. */
function mam_calendar_today(): string
{
    try { return (new DateTimeImmutable('now', new DateTimeZone(MAM_CALENDAR_TZ)))->format('Y-m-d'); }
    catch (Throwable $e) { return date('Y-m-d'); }
}

/** Derived fields every read of a term carries. */
function mam_calendar_decorate(array $t): array
{
    $today = mam_calendar_today();
    $t['id']             = (int) $t['id'];
    $t['teaching_weeks'] = (int) $t['teaching_weeks'];
    $t['is_current']     = ($t['starts_on'] <= $today && $t['ends_on'] >= $today);
    $t['week_no']        = mam_calendar_week_of($t, $today);
    $t['add_drop_open']  = $t['add_drop_deadline'] !== null && $today <= $t['add_drop_deadline'];
    /* Past the withdrawal deadline a withdrawal stops being a clean exit and
       becomes an outcome on the record — the "W". Reported as a flag rather than
       left for each caller to compare dates and get it subtly different. */
    $t['withdrawal_open'] = $t['withdrawal_deadline'] !== null && $today <= $t['withdrawal_deadline'];
    $t['census_taken']   = (bool) mam_db_row('SELECT id FROM term_census WHERE term_id = ?', [$t['id']]);
    $t['closures']       = mam_db_all('SELECT kind, label, from_on, to_on FROM term_closures
                                        WHERE term_id = ? ORDER BY from_on', [$t['id']]);
    return $t;
}

/**
 * Which teaching week a date falls in. 0 = before the term, null = after it.
 *
 * Counted in whole weeks from the start date, so week 1 is the first seven days.
 * Closures are deliberately NOT excluded: the refund table is published against
 * calendar weeks because that is what a family can verify against a start date,
 * and quietly subtracting a public holiday would make the published table wrong.
 */
function mam_calendar_week_of(array $term, ?string $date = null): ?int
{
    $d = $date ?: mam_calendar_today();
    if ($d < $term['starts_on']) return 0;
    if ($d > $term['ends_on'])   return null;
    $a = strtotime((string) $term['starts_on']);
    $b = strtotime($d);
    if ($a === false || $b === false) return null;
    return (int) floor(($b - $a) / (7 * 86400)) + 1;
}

/* ── The money question ──────────────────────────────────────────────────── */

/**
 * The refund percentage for a withdrawal on a date.
 *
 * Returns ['percent' => int, 'week' => int|null, 'basis' => string, 'term' => …]
 * or null when no term covers the date — in which case the caller must ask a
 * human rather than assume 0 or 100.
 *
 * The percentage comes from the term's PUBLISHED schedule, so the answer is the
 * one the family could read when they enrolled, and it is reproducible during an
 * audit a year later.
 */
function mam_calendar_refund_for(?string $date = null, ?int $termId = null): ?array
{
    $d    = $date ?: mam_calendar_today();
    $term = $termId ? mam_calendar_term($termId) : mam_calendar_term_on($d);
    if (!$term) return null;

    $week = mam_calendar_week_of($term, $d);
    if ($week === null) {
        // After the term ended there is nothing left to refund, and that is a
        // determinate answer rather than a missing one.
        return ['percent' => 0, 'week' => null, 'basis' => 'after_term_end', 'term' => $term];
    }

    $rows = mam_calendar_refund_schedule($term['id']);
    if (!$rows) {
        // A term with no published table cannot answer the question it exists to
        // answer. Refuse rather than fall back to a formula the family never saw.
        return ['percent' => null, 'week' => $week, 'basis' => 'no_published_schedule', 'term' => $term];
    }
    foreach ($rows as $r) {
        if ($week >= (int) $r['week_from'] && $week <= (int) $r['week_to']) {
            return ['percent' => (int) $r['percent'], 'week' => $week,
                    'basis' => 'published_schedule', 'term' => $term];
        }
    }
    return ['percent' => 0, 'week' => $week, 'basis' => 'beyond_schedule', 'term' => $term];
}

/**
 * What a withdrawal on this date means, in words and in outcomes.
 *
 * `outcome` is 'dropped' inside the add/drop window (it leaves no mark on the
 * record), 'withdrawn' up to the withdrawal deadline (the "W"), and 'graded'
 * after it — past that point the student is committed and takes whatever mark
 * the work earns. Getting this boundary wrong is how a student ends up with an
 * F for a course they left in week two.
 */
function mam_calendar_withdrawal_outcome(?string $date = null, ?int $termId = null): ?array
{
    $d    = $date ?: mam_calendar_today();
    $term = $termId ? mam_calendar_term($termId) : mam_calendar_term_on($d);
    if (!$term) return null;

    $refund = mam_calendar_refund_for($d, $term['id']);
    $week   = mam_calendar_week_of($term, $d);

    if ($term['add_drop_deadline'] !== null && $d <= $term['add_drop_deadline']) {
        $outcome = 'dropped';
        $note = 'Within the add/drop window — the course is removed and leaves no mark on the record.';
    } elseif ($term['withdrawal_deadline'] !== null && $d <= $term['withdrawal_deadline']) {
        $outcome = 'withdrawn';
        $note = 'Past add/drop but within the withdrawal window — recorded as a withdrawal (W), with no academic penalty.';
    } elseif ($term['withdrawal_deadline'] === null) {
        // No deadline authored: say so instead of picking one.
        $outcome = 'undetermined';
        $note = 'This term has no withdrawal deadline recorded, so the outcome must be decided by the academy.';
    } else {
        $outcome = 'graded';
        $note = 'Past the withdrawal deadline — the enrolment stands and is graded on the work submitted.';
    }
    return [
        'outcome'        => $outcome,
        'note'           => $note,
        'week'           => $week,
        'refund_percent' => $refund['percent'] ?? null,
        'refund_basis'   => $refund['basis'] ?? null,
        'term'           => $term,
    ];
}

/* ── Census ──────────────────────────────────────────────────────────────── */

/**
 * Take the census for a term: the official enrolment count, recorded ONCE.
 *
 * Append-only by unique index. A second call returns the existing snapshot rather
 * than overwriting it, because the whole purpose is that the figure quoted in a
 * report six months from now is the figure that was true on the day — not what
 * the live tables happen to say after a term of withdrawals.
 */
function mam_calendar_take_census(int $termId, ?int $actorId = null): array
{
    $term = mam_calendar_term($termId);
    if (!$term) throw new InvalidArgumentException('term_not_found');

    $existing = mam_db_row('SELECT * FROM term_census WHERE term_id = ?', [$termId]);
    if ($existing) return ['already' => true] + $existing;

    $enrolled = (int) (mam_db_row(
        "SELECT COUNT(DISTINCT ce.student_id) AS n FROM course_enrollments ce
          WHERE ce.status IN ('active','completed')")['n'] ?? 0);
    $cohorts = (int) (mam_db_row("SELECT COUNT(*) AS n FROM cohorts WHERE status = 'active'")['n'] ?? 0);

    $id = mam_db_insert('term_census', [
        'term_id'        => $termId,
        'taken_on'       => $term['census_date'] ?: mam_calendar_today(),
        'enrolled'       => $enrolled,
        'active_cohorts' => $cohorts,
        'detail_json'    => json_encode(['taken_at' => date('c'), 'actor' => $actorId]),
    ]);
    mam_audit('calendar_census', 'academic_term', $termId,
        ['enrolled' => $enrolled, 'cohorts' => $cohorts]);
    return ['already' => false, 'id' => $id, 'term_id' => $termId,
            'taken_on' => $term['census_date'] ?: mam_calendar_today(),
            'enrolled' => $enrolled, 'active_cohorts' => $cohorts];
}

/** The recorded census for a term, or null. Never recomputed. */
function mam_calendar_census(int $termId): ?array
{
    return mam_db_row('SELECT * FROM term_census WHERE term_id = ?', [$termId]) ?: null;
}

/* ── Closures ────────────────────────────────────────────────────────────── */

/** Add a holiday / break / exam window / make-up week to a term. */
function mam_calendar_closure_add(int $termId, string $kind, string $label, string $from, string $to): int
{
    if (!in_array($kind, ['holiday', 'break', 'exam', 'makeup'], true)) throw new InvalidArgumentException('bad_kind');
    if ($to < $from) throw new InvalidArgumentException('to_before_from');
    $term = mam_calendar_term($termId);
    if (!$term) throw new InvalidArgumentException('term_not_found');
    // A closure outside its own term is a data-entry slip that would quietly
    // never apply to anything.
    if ($from < $term['starts_on'] || $to > $term['ends_on']) throw new InvalidArgumentException('outside_term');
    $id = mam_db_insert('term_closures', ['term_id' => $termId, 'kind' => $kind,
        'label' => mb_substr($label, 0, 120), 'from_on' => $from, 'to_on' => $to]);
    mam_audit('calendar_closure', 'academic_term', $termId, ['kind' => $kind, 'from' => $from, 'to' => $to]);
    return $id;
}

/** Is this date inside a closure? Returns the closure, or null. */
function mam_calendar_closure_on(int $termId, ?string $date = null): ?array
{
    $d = $date ?: mam_calendar_today();
    return mam_db_row('SELECT kind, label, from_on, to_on FROM term_closures
                        WHERE term_id = ? AND from_on <= ? AND to_on >= ? LIMIT 1',
                        [$termId, $d, $d]) ?: null;
}

/** Delete a closure. */
function mam_calendar_closure_delete(int $id): bool
{
    $row = mam_db_row('SELECT term_id FROM term_closures WHERE id = ?', [$id]);
    if (!$row) return false;
    mam_db()->prepare('DELETE FROM term_closures WHERE id = ?')->execute([$id]);
    mam_audit('calendar_closure_delete', 'academic_term', (int) $row['term_id'], ['id' => $id]);
    return true;
}

/* ── Seeding a Nigerian session ──────────────────────────────────────────── */

/**
 * Author a whole session from the academy's own four-term shape, with deadlines
 * derived from each term's length.
 *
 * The derived positions follow the conventions both blueprints cite: add/drop at
 * the end of week 1, withdrawal at the 60% point (the same point the refund table
 * reaches 0), and the census a fortnight in, once the roster has settled.
 * They are a STARTING POINT an academy edits, not a policy — which is why they
 * are written into a seeded row the admin can see and change, rather than
 * computed silently at read time.
 */
function mam_calendar_seed_year(int $year, ?int $actorId = null): array
{
    $windows = function_exists('mam_session_windows')
        ? mam_session_windows($year, function_exists('mam_session_overrides') ? mam_session_overrides() : [])
        : [];
    if (!$windows) throw new RuntimeException('no_session_windows');

    $made = [];
    foreach ($windows as $i => $w) {
        $starts = (string) $w['starts_on'];
        $ends   = (string) $w['ends_on'];
        $weeks  = (int) ($w['weeks'] ?? 12);

        $addDrop = date('Y-m-d', strtotime($starts . ' +6 days'));
        // 60% of the teaching weeks, floored — the point the conventional refund
        // table reaches zero, so the two agree by construction rather than by
        // luck.
        $wdWeeks = max(1, (int) floor($weeks * 0.6));
        $withdraw = date('Y-m-d', strtotime($starts . ' +' . ($wdWeeks * 7 - 1) . ' days'));
        if ($withdraw > $ends) $withdraw = $ends;
        $census  = date('Y-m-d', strtotime($starts . ' +13 days'));
        if ($census > $ends) $census = $ends;
        if ($withdraw < $addDrop) $withdraw = $addDrop;

        /* A term already authored for this session is LEFT AS IT IS. Seeding
           again used to re-save it with the computed dates and status 'planned',
           silently undoing the dates the office had corrected against the
           published calendar — and reopening a closed term. Its refund table
           was safe; its deadlines were not. */
        $have = mam_db_row('SELECT id FROM academic_terms WHERE year_label = ? AND code = ?',
                           [(string) $year, strtolower((string) $w['code'])]);
        if ($have) {
            $made[] = ['id' => (int) $have['id'], 'code' => $w['code'], 'label' => $w['label'], 'existing' => true];
            continue;
        }
        try {
            $id = mam_calendar_term_save([
                'year_label'          => (string) $year,
                'code'                => (string) $w['code'],
                'term_no'             => $i + 1,
                'label'               => (string) $w['label'],
                'starts_on'           => $starts,
                'ends_on'             => $ends,
                'add_drop_deadline'   => $addDrop,
                'withdrawal_deadline' => $withdraw,
                'census_date'         => $census,
                'teaching_weeks'      => $weeks,
                'status'              => 'planned',
                'notes'               => 'Seeded from the computed session calendar; edit the dates to match the published calendar.',
            ], $actorId);
            $made[] = ['id' => $id, 'code' => $w['code'], 'label' => $w['label']];
        } catch (Throwable $e) {
            // An overlap with an already-authored term is expected on a re-seed;
            // report it rather than aborting the whole year.
            $made[] = ['id' => null, 'code' => $w['code'], 'label' => $w['label'], 'error' => $e->getMessage()];
        }
    }
    return $made;
}

/** Headline state for the admin screen. */
function mam_calendar_summary(): array
{
    $terms   = mam_calendar_terms(500);
    $current = mam_calendar_current_term();
    return [
        'terms'        => count($terms),
        'authored'     => (bool) $terms,
        'current'      => $current,
        'source'       => $current['source'],
        'missing_dates'=> count(array_filter($terms, static fn($t) =>
                            $t['add_drop_deadline'] === null || $t['withdrawal_deadline'] === null || $t['census_date'] === null)),
        'census_due'   => count(array_filter($terms, static fn($t) =>
                            $t['census_date'] !== null && !$t['census_taken'] && $t['census_date'] <= mam_calendar_today())),
    ];
}
