<?php
/**
 * Lessons, phase 2 — what surrounds a lesson (schema v98, ported from NGG's
 * learn.php, lesson-feedback.php, learn-mastery.php and the curriculum's year
 * placement and deadlines). The questions and marking are lessons.php; exams,
 * AI marking and the study helper are lesson-exams.php.
 *
 *   • PRIVATE NOTES and "saved for later" — the student's own; no teacher,
 *     guardian or report ever reads them (the same refusal as practice logs).
 *   • LESSON FEEDBACK — one tap: got it / mostly / lost. The console shows which
 *     lessons are not landing; it never shows which CHILD said so.
 *   • MATERIALS A TEACHER SENDS — pending until the curriculum office approves;
 *     a refusal needs a reason, because "rejected" alone teaches nothing.
 *   • PLACEMENT — which level of a course a student works at for LESSONS. It is
 *     its own table, deliberately not the enrolment's level: moving a child up a
 *     level of lessons must not move them between classes, change a capacity
 *     count or re-price anything. With no placement the enrolment's level holds.
 *     Advancing is by MASTERY, not the calendar: finishing MAM_LESSON_ADVANCE_PCT
 *     of a level's lessons opens the next. Lower levels left behind are catch-up
 *     — self-paced, never overdue.
 *   • TIERS AND CERTIFICATES — per course, from lessons completed and exams
 *     passed. Each tier's certificate is issued once and is publicly verifiable
 *     like every other certificate here. NGG's top tier is called "God"; for a
 *     children's academy it is "Grand Master".
 *   • DEADLINES AND FINES — a lesson at the student's own level is due a number
 *     of weeks after they started; reminders go to the student and their
 *     guardians; a fine is charged ONCE per lesson, through the accountability
 *     ledger, and only when the academy has switched fines on. The switch
 *     (`lesson_fines_enabled`) ships OFF: a money penalty on a child is the
 *     academy's decision under its own legal gate (CLAUDE.md gotcha #11), and
 *     changing the amount needs the fees capability as well as curriculum.
 */
declare(strict_types=1);

const MAM_LESSON_ADVANCE_PCT = 80;
const MAM_LESSON_FEELS = ['got' => 'Got it', 'mostly' => 'Mostly', 'lost' => 'Lost'];
const MAM_LESSON_TIERS = [
    1 => 'Starter', 2 => 'Apprentice', 3 => 'Practitioner', 4 => 'Expert', 5 => 'Master', 6 => 'Grand Master',
];

/* ─── Private notes, saved for later, last opened ──────────────────────── */

function mam_lesson_note_row(int $studentId, int $lessonId): ?array {
    return mam_db_row('SELECT * FROM lesson_notes WHERE module_id = ? AND student_id = ?', [$lessonId, $studentId]);
}

/** Ensure a notes row exists; a double tap is harmless (UNIQUE). */
function mam_lesson_note_ensure(int $studentId, int $lessonId): array {
    $r = mam_lesson_note_row($studentId, $lessonId);
    if ($r) return $r;
    try { mam_db_insert('lesson_notes', ['module_id' => $lessonId, 'student_id' => $studentId]); } catch (Throwable $t) {}
    return mam_lesson_note_row($studentId, $lessonId) ?? [];
}

/** Opening a lesson: when, and how many times. */
function mam_lesson_touch(int $studentId, int $lessonId): void {
    $r = mam_lesson_note_ensure($studentId, $lessonId);
    if ($r) mam_db_update('lesson_notes', ['opened_at' => gmdate('Y-m-d H:i:s'), 'opens' => (int) $r['opens'] + 1], ['id' => (int) $r['id']]);
}

function mam_lesson_note_save(int $studentId, int $lessonId, string $note): void {
    $r = mam_lesson_note_ensure($studentId, $lessonId);
    $note = mb_substr(trim($note), 0, 8000);
    mam_db_update('lesson_notes', ['note' => $note !== '' ? $note : null, 'note_at' => $note !== '' ? gmdate('Y-m-d H:i:s') : null], ['id' => (int) $r['id']]);
}

function mam_lesson_save_for_later(int $studentId, int $lessonId, bool $saved): void {
    $r = mam_lesson_note_ensure($studentId, $lessonId);
    mam_db_update('lesson_notes', ['saved' => $saved ? 1 : 0, 'saved_at' => $saved ? gmdate('Y-m-d H:i:s') : null], ['id' => (int) $r['id']]);
}

/* ─── How a lesson landed ──────────────────────────────────────────────── */

function mam_lesson_feedback_set(int $studentId, int $lessonId, string $feel, string $body = ''): void {
    if (!isset(MAM_LESSON_FEELS[$feel])) throw new InvalidArgumentException('Choose got it, mostly or lost.');
    $body = mb_substr(trim($body), 0, 1000);
    $r = mam_db_row('SELECT id FROM lesson_feedback WHERE module_id = ? AND student_id = ?', [$lessonId, $studentId]);
    if ($r) mam_db_update('lesson_feedback', ['feel' => $feel, 'body' => $body !== '' ? $body : null, 'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => (int) $r['id']]);
    else mam_db_insert('lesson_feedback', ['module_id' => $lessonId, 'student_id' => $studentId, 'feel' => $feel, 'body' => $body !== '' ? $body : null]);
}

function mam_lesson_feedback_mine(int $studentId, int $lessonId): ?array {
    $r = mam_db_row('SELECT feel, body FROM lesson_feedback WHERE module_id = ? AND student_id = ?', [$lessonId, $studentId]);
    return $r ? ['feel' => (string) $r['feel'], 'body' => (string) ($r['body'] ?? '')] : null;
}

/**
 * Lessons that are not landing, for the console: counts only, and the comments
 * WITHOUT names — the board is about the lesson, and a child who said "lost"
 * must not become the subject of the meeting. A lesson is flagged when at least
 * five students answered and a third or more said "lost".
 */
function mam_lesson_feedback_board(int $limit = 30): array {
    $rows = mam_db_all(
        "SELECT f.module_id, m.title, m.week, c.name AS course_name,
                SUM(CASE WHEN f.feel = 'got' THEN 1 ELSE 0 END) got,
                SUM(CASE WHEN f.feel = 'mostly' THEN 1 ELSE 0 END) mostly,
                SUM(CASE WHEN f.feel = 'lost' THEN 1 ELSE 0 END) lost,
                COUNT(*) n
           FROM lesson_feedback f JOIN modules m ON m.id = f.module_id JOIN courses c ON c.id = m.course_id
          GROUP BY f.module_id, m.title, m.week, c.name");
    foreach ($rows as &$r) {
        foreach (['got', 'mostly', 'lost', 'n'] as $k) $r[$k] = (int) $r[$k];
        $r['lost_share'] = $r['n'] > 0 ? round($r['lost'] / $r['n'] * 100) : 0;
        $r['flagged'] = $r['n'] >= 5 && $r['lost'] * 3 >= $r['n'];
        $r['comments'] = array_column(mam_db_all(
            "SELECT body FROM lesson_feedback WHERE module_id = ? AND body IS NOT NULL ORDER BY COALESCE(updated_at, created_at) DESC LIMIT 3",
            [(int) $r['module_id']]), 'body');
    }
    unset($r);
    usort($rows, fn($a, $b) => [$b['flagged'], $b['lost_share'], $b['n']] <=> [$a['flagged'], $a['lost_share'], $a['n']]);
    return array_slice($rows, 0, $limit);
}

/* ─── Materials a teacher sends ────────────────────────────────────────── */

/**
 * A teacher proposes a material for a lesson they teach on. It is PENDING —
 * students see only approved materials (mam_lesson_get filters) — until the
 * curriculum office approves it. Returns the new id.
 */
function mam_lesson_material_submit(int $teacherId, int $lessonId, array $in): int {
    $m = mam_db_row('SELECT id FROM modules WHERE id = ?', [$lessonId]);
    if (!$m) throw new RuntimeException('not_found');
    $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 200);
    $url = trim((string) ($in['url'] ?? ''));
    if ($title === '') throw new InvalidArgumentException('Give the material a title.');
    if (!mam_lesson_url_ok($url)) throw new InvalidArgumentException('The link must start with https:// (or be a file stored here).');
    $kind = in_array($in['kind'] ?? 'link', MAM_LESSON_MATERIAL_KINDS, true) ? (string) $in['kind'] : 'link';
    $pos = (int) (mam_db_row('SELECT COALESCE(MAX(position), 0) + 1 p FROM module_materials WHERE module_id = ?', [$lessonId])['p'] ?? 1);
    $id = (int) mam_db_insert('module_materials', ['module_id' => $lessonId, 'kind' => $kind, 'title' => $title, 'url' => $url,
        'guide' => mb_substr(trim((string) ($in['guide'] ?? '')), 0, 300) ?: null, 'position' => $pos,
        'status' => 'pending', 'submitted_by' => $teacherId]);
    mam_audit('lesson_material_submit', 'module', $lessonId, ['material' => $id]);
    return $id;
}

function mam_lesson_materials_pending(): array {
    return mam_db_all(
        "SELECT mm.*, m.title AS lesson_title, m.week, c.name AS course_name, u.name AS submitted_by_name
           FROM module_materials mm JOIN modules m ON m.id = mm.module_id JOIN courses c ON c.id = m.course_id
           LEFT JOIN users u ON u.id = mm.submitted_by
          WHERE mm.status = 'pending' ORDER BY mm.created_at, mm.id LIMIT 200");
}

/** Approve or reject a sent material. A rejection without a reason is refused. */
function mam_lesson_material_review(int $reviewerId, int $materialId, bool $approve, string $note = ''): void {
    $mm = mam_db_row('SELECT * FROM module_materials WHERE id = ?', [$materialId]);
    if (!$mm || $mm['status'] !== 'pending') throw new RuntimeException('not_pending');
    $note = mb_substr(trim($note), 0, 500);
    if (!$approve && $note === '') throw new InvalidArgumentException('Say why it is not suitable — the teacher who sent it needs to know.');
    mam_db_update('module_materials', ['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $reviewerId ?: null,
        'reviewed_at' => gmdate('Y-m-d H:i:s'), 'review_note' => $note !== '' ? $note : null], ['id' => $materialId]);
    mam_audit($approve ? 'lesson_material_approve' : 'lesson_material_reject', 'module', (int) $mm['module_id'], ['material' => $materialId]);
    if ($mm['submitted_by'] && function_exists('mam_notify')) {
        try {
            mam_notify((int) $mm['submitted_by'], 'lesson.material',
                $approve ? 'Your material is live: “' . $mm['title'] . '”' : 'Your material was not added: “' . $mm['title'] . '”',
                $approve ? null : $note, null);
        } catch (Throwable $t) {}
    }
}

/* ─── Placement: which level of a course a student works at ────────────── */

/** Levels in teaching order, id → position. */
function mam_lesson_level_order(): array {
    static $memo = null;
    if ($memo !== null) return $memo;
    $memo = [];
    foreach (mam_db_all('SELECT id FROM levels ORDER BY sort_order, id') as $i => $r) $memo[(int) $r['id']] = $i;
    return $memo;
}

/** The level a student works at for lessons on a course: their placement, else their enrolment's level. */
function mam_lesson_placed_level(int $studentId, int $courseId, ?int $enrolLevelId): ?int {
    $p = mam_db_row('SELECT level_id FROM lesson_placements WHERE student_id = ? AND course_id = ?', [$studentId, $courseId]);
    return $p ? (int) $p['level_id'] : $enrolLevelId;
}

/**
 * Which published lessons of a course a student has, and how each reaches them:
 *   ''       — a lesson with no level (every level has it);
 *   current  — at their placed level;
 *   catchup  — a level below it, left behind: self-paced, never overdue;
 *   early    — a level above, opened by finishing MAM_LESSON_ADVANCE_PCT of the
 *              one before: self-paced, never overdue.
 * A lesson not in the map is not open to them yet. Pure over three reads.
 */
function mam_lesson_stage_map(int $studentId, int $courseId, ?int $placedLevelId): array {
    $order = mam_lesson_level_order();
    $lessons = mam_db_all("SELECT id, level_id FROM modules WHERE course_id = ? AND status = 'published'", [$courseId]);
    $done = [];
    foreach (mam_db_all("SELECT p.module_id FROM module_progress p JOIN modules m ON m.id = p.module_id
                          WHERE p.student_id = ? AND m.course_id = ? AND p.status = 'completed'", [$studentId, $courseId]) as $r) {
        $done[(int) $r['module_id']] = true;
    }
    $byLevel = [];
    foreach ($lessons as $l) $byLevel[$l['level_id'] === null ? 'none' : (int) $l['level_id']][] = (int) $l['id'];
    $map = [];
    foreach ($byLevel['none'] ?? [] as $id) $map[$id] = '';
    if ($placedLevelId === null || !isset($order[$placedLevelId])) return $map;
    $placedPos = $order[$placedLevelId];
    $levelsByPos = array_flip($order);
    foreach ($byLevel as $lv => $ids) {
        if ($lv === 'none' || !isset($order[$lv])) continue;
        if ($order[$lv] === $placedPos) foreach ($ids as $id) $map[$id] = 'current';
        elseif ($order[$lv] < $placedPos) foreach ($ids as $id) $map[$id] = 'catchup';
    }
    // Opened early by mastery: each level above opens when the one below it is
    // MAM_LESSON_ADVANCE_PCT done — a level with no lessons opens nothing.
    $pos = $placedPos;
    while (isset($levelsByPos[$pos + 1])) {
        $below = $byLevel[$levelsByPos[$pos]] ?? [];
        if (!$below) break;
        $doneHere = count(array_filter($below, fn($id) => isset($done[$id])));
        if ($doneHere * 100 < MAM_LESSON_ADVANCE_PCT * count($below)) break;
        $pos++;
        foreach ($byLevel[$levelsByPos[$pos]] ?? [] as $id) $map[$id] = 'early';
    }
    return $map;
}

/** Does a stage carry a deadline? Only a lesson at the student's own level (or one with no level). */
function mam_lesson_stage_due(string $stage): bool {
    return $stage === '' || $stage === 'current';
}

/**
 * A teacher (or the office) places a student at a level of a course for
 * lessons. Moving UP issues a promotion certificate. Audited with the reason.
 * Returns ['from' => level_id|null, 'to' => level_id].
 */
function mam_lesson_place(int $staffId, int $studentId, int $courseId, int $levelId, string $reason = ''): array {
    $order = mam_lesson_level_order();
    if (!isset($order[$levelId])) throw new InvalidArgumentException('bad_level');
    $enrol = mam_db_row("SELECT level_id FROM course_enrollments WHERE student_id = ? AND course_id = ? AND status IN ('active','completed') ORDER BY id DESC LIMIT 1", [$studentId, $courseId]);
    if (!$enrol) throw new RuntimeException('not_enrolled');
    $from = mam_lesson_placed_level($studentId, $courseId, $enrol['level_id'] !== null ? (int) $enrol['level_id'] : null);
    if ($from === $levelId) return ['from' => $from, 'to' => $levelId];
    $row = ['level_id' => $levelId, 'placed_by' => $staffId ?: null, 'reason' => mb_substr(trim($reason), 0, 300) ?: null, 'placed_at' => gmdate('Y-m-d H:i:s')];
    $have = mam_db_row('SELECT id FROM lesson_placements WHERE student_id = ? AND course_id = ?', [$studentId, $courseId]);
    if ($have) mam_db_update('lesson_placements', $row, ['id' => (int) $have['id']]);
    else mam_db_insert('lesson_placements', $row + ['student_id' => $studentId, 'course_id' => $courseId]);
    mam_audit('lesson_place', 'user', $studentId, ['course' => $courseId, 'from' => $from, 'to' => $levelId, 'reason' => $row['reason']]);
    if ($from !== null && isset($order[$from]) && $order[$levelId] > $order[$from]) {
        $course = (string) (mam_db_row('SELECT name FROM courses WHERE id = ?', [$courseId])['name'] ?? '');
        $level  = (string) (mam_db_row('SELECT name FROM levels WHERE id = ?', [$levelId])['name'] ?? '');
        mam_lesson_certificate($studentId, 'promotion', $course . ': moved up to ' . $level, $staffId);
    }
    return ['from' => $from, 'to' => $levelId];
}

/** The students a teacher teaches on a course, with where each is placed and how far through they are. */
function mam_lesson_placement_list(?int $teacherId, int $courseId): array {
    $sql = "SELECT DISTINCT u.id, u.name, ce.level_id FROM course_enrollments ce JOIN users u ON u.id = ce.student_id
              LEFT JOIN cohorts co ON co.id = ce.cohort_id
             WHERE ce.course_id = ? AND ce.status IN ('active','completed')";
    $args = [$courseId];
    if ($teacherId !== null) { [$tq, $targs] = mam_teaching_enrolment_sql('ce', $teacherId); $sql .= ' AND ' . $tq; $args = array_merge($args, $targs); }
    $out = [];
    foreach (mam_db_all($sql . ' ORDER BY u.name', $args) as $r) {
        $placed = mam_lesson_placed_level((int) $r['id'], $courseId, $r['level_id'] !== null ? (int) $r['level_id'] : null);
        $out[] = ['student_id' => (int) $r['id'], 'name' => (string) $r['name'], 'placed_level_id' => $placed,
            'tier' => mam_lesson_tier((int) $r['id'], $courseId)];
    }
    return $out;
}

/* ─── Tiers and certificates ───────────────────────────────────────────── */

/**
 * Where a student stands on a course, NGG's six tiers:
 *   1 Starter        a lesson begun
 *   2 Apprentice     25% of their lessons completed
 *   3 Practitioner   50%, and 2 lesson exams passed
 *   4 Expert         75%, and an exam average of 70 or more
 *   5 Master         every lesson, and an exam average of 50 or more
 *   6 Grand Master   a Master who passed the course trial at 85% or more
 * "Their lessons" are the ones they have (stage '', current, catch-up) — a level
 * opened early counts when finished, never against them while not.
 * Personal only: nothing here compares one child with another.
 */
function mam_lesson_tier(int $studentId, int $courseId): array {
    $enrol = mam_db_row("SELECT level_id FROM course_enrollments WHERE student_id = ? AND course_id = ? AND status IN ('active','completed') ORDER BY id DESC LIMIT 1", [$studentId, $courseId]);
    $placed = mam_lesson_placed_level($studentId, $courseId, $enrol && $enrol['level_id'] !== null ? (int) $enrol['level_id'] : null);
    $map = mam_lesson_stage_map($studentId, $courseId, $placed);
    $own = array_keys(array_filter($map, fn($s) => $s !== 'early'));
    $prog = [];
    if ($map) {
        $ph = implode(',', array_fill(0, count($map), '?'));
        foreach (mam_db_all("SELECT module_id, status FROM module_progress WHERE student_id = ? AND module_id IN ($ph)", array_merge([$studentId], array_keys($map))) as $p) {
            $prog[(int) $p['module_id']] = (string) $p['status'];
        }
    }
    $began = (bool) $prog;
    $doneOwn = count(array_filter($own, fn($id) => ($prog[$id] ?? '') === 'completed'));
    $pct = $own ? (int) floor($doneOwn * 100 / count($own)) : 0;
    $sit = mam_db_all("SELECT pct FROM lesson_sittings WHERE student_id = ? AND course_id = ? AND kind = 'session' AND status = 'marked' AND pct IS NOT NULL", [$studentId, $courseId]);
    $passed = count(array_filter($sit, fn($r) => (float) $r['pct'] >= 60));
    $avg = $sit ? array_sum(array_map(fn($r) => (float) $r['pct'], $sit)) / count($sit) : null;
    $trial = mam_db_row("SELECT MAX(pct) p FROM lesson_sittings WHERE student_id = ? AND course_id = ? AND kind = 'trial' AND status = 'marked'", [$studentId, $courseId]);
    $trialBest = $trial && $trial['p'] !== null ? (float) $trial['p'] : null;

    $tier = 0;
    if ($began) $tier = 1;
    if ($tier >= 1 && $pct >= 25) $tier = 2;
    if ($tier >= 2 && $pct >= 50 && $passed >= 2) $tier = 3;
    if ($tier >= 3 && $pct >= 75 && $avg !== null && $avg >= 70) $tier = 4;
    if ($tier >= 4 && $own && $doneOwn === count($own) && $avg !== null && $avg >= 50) $tier = 5;
    if ($tier >= 5 && $trialBest !== null && $trialBest >= 85) $tier = 6;

    $next = [
        0 => 'Open your first lesson.',
        1 => 'Finish a quarter of your lessons.',
        2 => 'Finish half of your lessons and pass two lesson exams.',
        3 => 'Finish three quarters of your lessons with an exam average of 70.',
        4 => 'Finish every lesson with an exam average of 50 or more.',
        5 => 'Pass the course trial with 85% or more.',
        6 => 'You have reached the top tier on this course.',
    ][$tier];
    return ['tier' => $tier, 'name' => MAM_LESSON_TIERS[$tier] ?? 'Not started', 'pct' => $pct, 'done' => $doneOwn, 'total' => count($own),
        'exams_passed' => $passed, 'exam_avg' => $avg !== null ? (int) round($avg) : null, 'trial_best' => $trialBest !== null ? (int) round($trialBest) : null,
        'next' => $next, 'trial_open' => $tier >= 5];
}

/** Issue a certificate once (same student, kind and title), with a public reference. Returns the reference. */
function mam_lesson_certificate(int $studentId, string $kind, string $title, ?int $by = null): string {
    $have = mam_db_row('SELECT reference FROM certificates WHERE student_id = ? AND kind = ? AND title = ?', [$studentId, $kind, $title]);
    if ($have) return (string) $have['reference'];
    $ref = 'CERT-' . gmdate('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
    mam_db_insert('certificates', ['reference' => $ref, 'student_id' => $studentId, 'kind' => $kind, 'title' => mb_substr($title, 0, 200), 'issued_by' => $by]);
    mam_audit('lesson_certificate', 'certificate', $studentId, ['ref' => $ref, 'kind' => $kind, 'title' => $title]);
    if (function_exists('mam_notify')) {
        try { mam_notify($studentId, 'lesson.certificate', 'New certificate: ' . $title, 'Reference ' . $ref . ' — anyone can check it on the certificate page.', '/verify?ref=' . rawurlencode($ref)); }
        catch (Throwable $t) {}
    }
    return $ref;
}

/**
 * Issue every tier certificate a student has reached on a course and not yet
 * received (Apprentice and up — "Starter" is opening a lesson, not an
 * achievement). Called after anything that can raise a tier: a completed
 * lesson, a marked answer, a tick, a marked exam. Returns the new references.
 */
function mam_lesson_award(int $studentId, int $courseId): array {
    $t = mam_lesson_tier($studentId, $courseId);
    if ($t['tier'] < 2) return [];
    $course = (string) (mam_db_row('SELECT name FROM courses WHERE id = ?', [$courseId])['name'] ?? 'Course');
    $refs = [];
    for ($n = 2; $n <= $t['tier']; $n++) {
        $title = $course . ': ' . MAM_LESSON_TIERS[$n];
        if (!mam_db_row("SELECT 1 FROM certificates WHERE student_id = ? AND kind = 'course_tier' AND title = ?", [$studentId, $title])) {
            $refs[] = mam_lesson_certificate($studentId, 'course_tier', $title, null);
        }
    }
    return $refs;
}

/** A student's lesson certificates, newest first. */
function mam_lesson_certificates(int $studentId): array {
    return mam_db_all("SELECT reference, kind, title, issued_at FROM certificates WHERE student_id = ? AND kind IN ('course_tier','promotion') ORDER BY issued_at DESC, id DESC", [$studentId]);
}

/* ─── Deadlines, reminders and fines ───────────────────────────────────── */

/**
 * The schedule, read from settings with NGG's defaults. `term_start` falls back
 * to the academic calendar's current term; with neither, NOTHING is due — a
 * deadline computed from no start date would be a date nobody chose.
 */
function mam_lesson_schedule(): array {
    $start = trim((string) mam_setting('lesson_term_start', ''));
    if ($start === '' && function_exists('mam_calendar_current_term')) {
        try { $start = (string) (mam_calendar_current_term()['starts_on'] ?? ''); } catch (Throwable $t) { $start = ''; }
    }
    return [
        'term_start'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ? $start : '',
        'remind_before' => max(0, (int) mam_setting_num('lesson_remind_before_days', 2)),
        'remind_every'  => max(1, (int) mam_setting_num('lesson_remind_every_days', 2)),
        'fine_after'    => max(1, (int) mam_setting_num('lesson_fine_after_days', 7)),
        'fines_enabled' => (int) mam_setting_num('lesson_fines_enabled', 0) === 1,
        'fine_usd'      => max(0.0, (float) mam_setting_num('lesson_fine_usd', 1)),
    ];
}

/** When a lesson is due for a student: the later of term start and their enrolment, plus the lesson's week. */
/**
 * When a student's clock starts on a course: the later of when they enrolled
 * and when they were last PLACED. A child moved up a level today must not be
 * overdue on lessons of that level from before they were moved.
 */
function mam_lesson_clock_start(int $studentId, int $courseId, ?string $enrolledAt): ?string {
    $p = mam_db_row('SELECT placed_at FROM lesson_placements WHERE student_id = ? AND course_id = ?', [$studentId, $courseId]);
    $placed = $p['placed_at'] ?? null;
    if (!$placed) return $enrolledAt;
    return (!$enrolledAt || $placed > $enrolledAt) ? $placed : $enrolledAt;
}

function mam_lesson_due_at(array $sched, ?string $enrolledAt, int $week): ?string {
    if ($sched['term_start'] === '') return null;
    $start = $sched['term_start'];
    if ($enrolledAt && substr($enrolledAt, 0, 10) > $start) $start = substr($enrolledAt, 0, 10);
    return gmdate('Y-m-d', strtotime($start . ' UTC') + max(1, $week) * 7 * 86400);
}

/**
 * The daily deadline run (api/tools/run-jobs.php). For every active enrolment,
 * every lesson at the student's own level that is not completed:
 *   • from `remind_before` days before it is due, a reminder every
 *     `remind_every` days — ONE message per student per period, listing what is
 *     due, to the student and to each linked guardian;
 *   • `fine_after` days past due, ONE fine per lesson ever, through the
 *     accountability ledger — only with `lesson_fines_enabled` on.
 * Catch-up and opened-early lessons are self-paced and never due. Idempotent:
 * lesson_deadline_log's UNIQUE (lesson, student, kind, period) is the claim, so
 * two runs on one day send one reminder and charge one fine.
 * Returns ['reminded' => students, 'fined' => charges].
 */
function mam_lesson_due_run(?string $today = null, ?int $onlyStudent = null): array {
    $sched = mam_lesson_schedule();
    $out = ['reminded' => 0, 'fined' => 0, 'skipped' => $sched['term_start'] === '' ? 'no term start' : null];
    if (function_exists('mam_automation_on') && !mam_automation_on('lesson_deadlines')) { $out['skipped'] = 'automation off'; return $out; }
    if ($sched['term_start'] === '') return $out;
    if (function_exists('mam_automation_touch')) mam_automation_touch('lesson_deadlines');
    $today = $today ?? gmdate('Y-m-d');
    $dayNo = intdiv(strtotime($today . ' UTC'), 86400);
    $period = 'p' . intdiv($dayNo, $sched['remind_every']);
    $enrols = mam_db_all("SELECT ce.student_id, ce.course_id, ce.level_id, COALESCE(ce.approved_at, ce.created_at) enrolled_at, u.email, u.name
                            FROM course_enrollments ce JOIN users u ON u.id = ce.student_id
                           WHERE ce.status = 'active' AND u.status = 'active'" . ($onlyStudent ? ' AND ce.student_id = ' . (int) $onlyStudent : ''));
    $remind = [];
    foreach ($enrols as $e) {
        $sid = (int) $e['student_id']; $cid = (int) $e['course_id'];
        $placed = mam_lesson_placed_level($sid, $cid, $e['level_id'] !== null ? (int) $e['level_id'] : null);
        $map = mam_lesson_stage_map($sid, $cid, $placed);
        $dueIds = array_keys(array_filter($map, 'mam_lesson_stage_due'));
        if (!$dueIds) continue;
        $ph = implode(',', array_fill(0, count($dueIds), '?'));
        $lessons = mam_db_all("SELECT m.id, m.week, m.title, p.status FROM modules m
                                 LEFT JOIN module_progress p ON p.module_id = m.id AND p.student_id = ?
                                WHERE m.id IN ($ph)", array_merge([$sid], $dueIds));
        foreach ($lessons as $l) {
            if (($l['status'] ?? '') === 'completed') continue;
            $due = mam_lesson_due_at($sched, mam_lesson_clock_start($sid, $cid, $e['enrolled_at']), (int) $l['week']);
            if ($due === null) continue;
            $daysToDue = intdiv(strtotime($due . ' UTC') - strtotime($today . ' UTC'), 86400);
            if ($daysToDue <= $sched['remind_before']) $remind[$sid][] = ['lesson' => $l, 'due' => $due, 'late' => $daysToDue < 0];
            if ($sched['fines_enabled'] && $daysToDue <= -$sched['fine_after']) {
                if (mam_lesson_fine($sid, (int) $l['id'], (string) $l['title'], $sched['fine_usd'])) $out['fined']++;
            }
        }
    }
    foreach ($remind as $sid => $items) {
        $fresh = [];
        foreach ($items as $it) {
            try { mam_db_insert('lesson_deadline_log', ['module_id' => (int) $it['lesson']['id'], 'student_id' => $sid, 'kind' => 'reminder', 'period' => $period]); $fresh[] = $it; }
            catch (Throwable $t) { /* already reminded this period */ }
        }
        if (!$fresh) continue;
        mam_lesson_remind($sid, $fresh);
        $out['reminded']++;
    }
    return $out;
}

/** One reminder to a student and their guardians, listing what is due or late. */
function mam_lesson_remind(int $studentId, array $items): void {
    $stu = mam_db_row('SELECT name, email FROM users WHERE id = ?', [$studentId]);
    if (!$stu) return;
    $lines = array_map(fn($it) => '• Week ' . (int) $it['lesson']['week'] . ': ' . $it['lesson']['title']
        . ($it['late'] ? ' — was due ' . $it['due'] : ' — due ' . $it['due']), $items);
    $body = '<p>' . htmlspecialchars('These lessons are due, or overdue:') . '</p><p>' . nl2br(htmlspecialchars(implode("\n", $lines)))
        . '</p><p>' . htmlspecialchars('Open them from Learn in the student portal.') . '</p>';
    if (function_exists('mam_notify')) {
        try { mam_notify($studentId, 'lesson.due', count($items) === 1 ? 'A lesson is due' : count($items) . ' lessons are due', implode("\n", $lines), '/portal/student/#learn'); }
        catch (Throwable $t) {}
    }
    if (function_exists('mam_send') && $stu['email']) {
        try { mam_send((string) $stu['email'], 'MAM Academy — lessons due', $body, 'lesson_due'); } catch (Throwable $t) {}
    }
    foreach (mam_db_all("SELECT u.email FROM parent_links pl JOIN users u ON u.id = pl.parent_id WHERE pl.student_id = ? AND u.status = 'active'", [$studentId]) as $g) {
        if (!$g['email'] || !function_exists('mam_send')) continue;
        try { mam_send((string) $g['email'], 'MAM Academy — ' . $stu['name'] . "'s lessons", $body, 'lesson_due_guardian'); } catch (Throwable $t) {}
    }
}

/**
 * Charge the overdue fine for one lesson, once ever. A ledger row in
 * accountability_charges (reason `lesson_overdue`), so it is owed, waivable and
 * disputable exactly like every other charge the student sees. Returns true
 * when a charge was made now.
 */
function mam_lesson_fine(int $studentId, int $lessonId, string $title, float $usd): bool {
    if ($usd <= 0) return false;
    try { $logId = (int) mam_db_insert('lesson_deadline_log', ['module_id' => $lessonId, 'student_id' => $studentId, 'kind' => 'fine', 'period' => 'once']); }
    catch (Throwable $t) { return false; }                               // already fined for this lesson
    $cents = function_exists('mam_money_to_minor') ? mam_money_to_minor($usd) : (int) round($usd * 100);
    $rate = function_exists('mam_usd_to_ngn_rate') ? mam_usd_to_ngn_rate(false) : null;
    $cid = (int) mam_db_insert('accountability_charges', ['subject_type' => 'student', 'subject_id' => $studentId, 'session_id' => null,
        'reason' => 'lesson_overdue', 'minutes' => 0, 'amount_usd_cents' => $cents, 'currency' => 'USD', 'fx_rate_usd_ngn' => $rate, 'status' => 'outstanding']);
    mam_db_update('lesson_deadline_log', ['charge_id' => $cid], ['id' => $logId]);
    mam_audit('lesson_fine', 'user', $studentId, ['lesson' => $lessonId, 'usd_cents' => $cents, 'charge' => $cid]);
    if (function_exists('mam_notify')) {
        try { mam_notify($studentId, 'accountability', 'Overdue lesson charge', '“' . $title . '” is overdue, so a $' . number_format($usd, 2)
            . ' charge was recorded. You can ask for it to be waived from Fees.', null); } catch (Throwable $t) {}
    }
    return true;
}
