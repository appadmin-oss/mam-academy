<?php
/**
 * Lesson exams, AI marking and the study helper (schema v98, ported from NGG's
 * learn-exam.php, curriculum_ai_mark and the study helper).
 *
 * EXAMS. A sitting is timed BY THE SERVER: the deadline is stored when it
 * starts, answers are saved as the student goes, and a submit after the
 * deadline plus MAM_EXAM_GRACE_SEC marks what was saved — a closed laptop does
 * not buy more time, and a dropped connection does not lose the work. Each
 * sitting's question order and option order are its own shuffle. The student
 * pledges the work is their own before anything is shown.
 *   • A LESSON EXAM is the lesson's questions plus up to MAM_EXAM_SPACED
 *     questions from lessons finished earlier — spaced recall, the point of it.
 *   • The COURSE TRIAL opens at tier 5 (Master): 10 machine-marked and 2 written
 *     questions from the whole course, 30 minutes, a pass at 85% gives Grand
 *     Master; one sitting a week.
 *   • A result can be appealed ONCE, within MAM_EXAM_APPEAL_DAYS, to a teacher.
 *   NGG's "viva" (follow-up questions generated about a written answer) is not
 *   ported: it needs a model in the loop of every sitting, and a weak viva is
 *   held for a teacher anyway.
 *
 * AI MARKING (`lesson_ai_marking`, OFF by default, and only with an AI provider
 * configured). A short answer, task or reflection may be marked by the model
 * against the teacher's guide. Three rules, all NGG's:
 *   • below MAM_AI_MARK_MIN_CONFIDENCE it marks nothing — the answer waits for a
 *     teacher, which is what happens with no provider at all;
 *   • an answer that reads as a child in danger is HELD for a person, never
 *     marked, and the safeguarding officer is told;
 *   • an `evidence` answer (a recording of a child playing) is never sent to a
 *     model: MAM's refusal of automatic scoring of a child's playing holds.
 *   A teacher can change any mark the assistant gave.
 *
 * STUDY HELPER (`lesson_helper_enabled` + a provider): a tutor for one lesson,
 * MAM_HELPER_DAILY questions a day, refused while an exam is open, told never
 * to answer the lesson's own questions for the student, and screened for the
 * same safeguarding words before anything reaches a model.
 */
declare(strict_types=1);

const MAM_EXAM_GRACE_SEC     = 90;
const MAM_EXAM_SPACED        = 3;
const MAM_EXAM_SEC_PER_ITEM  = 90;
const MAM_EXAM_MIN_SEC       = 300;
const MAM_EXAM_APPEAL_DAYS   = 7;
const MAM_TRIAL_AUTO         = 10;
const MAM_TRIAL_WRITTEN      = 2;
const MAM_TRIAL_SEC          = 1800;
const MAM_TRIAL_PASS         = 85;
const MAM_TRIAL_COOLDOWN_DAYS = 7;
const MAM_AI_MARK_MIN_CONFIDENCE = 0.6;
const MAM_HELPER_DAILY       = 20;
/** A band names a result in words. The order matters: the first one reached wins. */
const MAM_EXAM_BANDS = [[95, 'Outstanding'], [85, 'Distinction'], [70, 'Merit'], [60, 'Pass']];

function mam_exam_band(float $pct, int $passMark = 60): string {
    foreach (MAM_EXAM_BANDS as [$floor, $name]) {
        if ($name === 'Pass') return $pct >= $passMark ? 'Pass' : 'Not yet';
        if ($pct >= $floor) return $name;
    }
    return 'Not yet';
}

/* ─── Safeguarding screen, shared by AI marking and the helper ─────────── */

/**
 * Words that mean a person must read this now — about harm to themselves, or
 * harm done to them. Deliberately broad: a false alarm costs the officer a
 * minute, a miss costs a child. Never used to mark anything down.
 */
function mam_lesson_safeguarding_hit(string $text): bool {
    return (bool) preg_match('/\b(kill (my|him|her)self|suicid\w*|self[- ]?harm|cut(ting)? myself|want to die|end my life|'
        . 'hurt(s|ing)? me|hit(s|ting)? me|touch(es|ed|ing)? me|abus\w+|beat(s|ing)? me|scared to go home|not safe at home|rape\w*)\b/i', $text);
}

/** Tell the safeguarding officer (and record it) that something was held. */
function mam_lesson_safeguarding_raise(int $studentId, string $where, int $refId): void {
    mam_audit('lesson_safeguarding_hold', 'user', $studentId, ['where' => $where, 'ref' => $refId]);
    // Into the officer's queue, like every other held word (safeguarding.php) — not only a notification.
    if (function_exists('mam_sg_auto')) { mam_sg_auto($studentId, 'lesson', $refId, 'wellbeing', 'Words held from ' . $where . '.'); return; }
    $dso = function_exists('mam_vetting_dso') ? mam_vetting_dso() : null;
    if ($dso && function_exists('mam_notify')) {
        try { mam_notify((int) $dso['id'], 'safeguarding', 'A student\'s words were held for you to read',
            'Held from ' . $where . ' (student #' . $studentId . '). Nothing was marked or sent to an assistant.', null); }
        catch (Throwable $t) {}
    }
}

/* ─── AI marking ────────────────────────────────────────────────────────── */

function mam_lesson_ai_marking_on(): bool {
    return (int) mam_setting_num('lesson_ai_marking', 0) === 1 && function_exists('mam_ai_enabled') && mam_ai_enabled('lesson_mark');
}

/**
 * Mark one written answer with the model, or decline. Returns:
 *   ['awarded' => float, 'by' => 'ai', 'comment' => string]   marked;
 *   ['awarded' => null, 'hold' => true]                        held for a person (safeguarding);
 *   null                                                       left for a teacher (off, unsure, failed, or evidence).
 */
function mam_lesson_ai_mark(array $check, string $answer, int $studentId, int $refId = 0): ?array {
    if (mam_lesson_safeguarding_hit($answer)) {
        mam_lesson_safeguarding_raise($studentId, 'a lesson answer', $refId);
        return ['awarded' => null, 'hold' => true];
    }
    if (!in_array($check['kind'] ?? '', ['short', 'task', 'reflection'], true)) return null;   // never evidence of playing
    if (!mam_lesson_ai_marking_on()) return null;
    $points = (int) ($check['points'] ?? 1);
    $prompt = "Question: " . ($check['prompt'] ?? '') . "\n"
        . "What a good answer looks like (the teacher's guide): " . (($check['rubric'] ?? '') ?: 'not given') . "\n"
        . "Maximum points: $points\n"
        . "The student's answer:\n<<<\n" . mb_substr($answer, 0, 4000) . "\n>>>\n"
        . "Mark it against the guide. Reply as JSON: {\"awarded\": number 0..$points, \"confidence\": number 0..1, \"comment\": one kind sentence to the student}.";
    $r = mam_ai_complete('lesson_mark', $prompt, [
        'system' => 'You mark short written answers for a music academy whose students are often children. Be fair and kind. '
            . 'Mark only against the guide given. If unsure, give a LOW confidence — a teacher will mark it instead.',
        'schema' => ['type' => 'object', 'properties' => ['awarded' => ['type' => 'number'], 'confidence' => ['type' => 'number'], 'comment' => ['type' => 'string']]],
        'temperature' => 0.1, 'max_tokens' => 300, 'user_id' => $studentId,
    ]);
    $j = $r['ok'] ? ($r['json'] ?? null) : null;
    if (!is_array($j) || !isset($j['awarded'], $j['confidence'])) return null;
    if ((float) $j['confidence'] < MAM_AI_MARK_MIN_CONFIDENCE) return null;
    return ['awarded' => (float) max(0, min($points, (float) $j['awarded'])), 'by' => 'ai',
        'comment' => mb_substr(trim((string) ($j['comment'] ?? '')), 0, 600) ?: null];
}

/* ─── Exams ─────────────────────────────────────────────────────────────── */

/** The student's open sitting, if any. An expired one is closed first, marking what was saved. */
function mam_exam_open_sitting(int $studentId): ?array {
    $s = mam_db_row("SELECT * FROM lesson_sittings WHERE student_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1", [$studentId]);
    if (!$s) return null;
    if (time() > strtotime($s['deadline'] . ' UTC') + MAM_EXAM_GRACE_SEC) {
        mam_exam_finish((int) $s['id'], null);
        return null;
    }
    return $s;
}

/**
 * Build a sitting's items: each a question id and, for multiple choice, the
 * order its options are shown in. The question order is shuffled too. Stored
 * on the sitting, so a refresh shows the same exam.
 */
function mam_exam_build_items(array $checkIds): array {
    shuffle($checkIds);
    $items = [];
    foreach ($checkIds as $cid) {
        $c = mam_db_row('SELECT kind, options FROM module_checks WHERE id = ?', [$cid]);
        if (!$c) continue;
        $perm = [];
        if ($c['kind'] === 'mcq') { $perm = array_keys(mam_lesson_json_list($c['options'])); shuffle($perm); }
        $items[] = ['check_id' => (int) $cid, 'perm' => $perm];
    }
    return $items;
}

function mam_exam_insert(int $studentId, int $courseId, ?int $moduleId, string $kind, array $items, int $limitSec): int {
    $now = time();
    return (int) mam_db_insert('lesson_sittings', ['student_id' => $studentId, 'course_id' => $courseId, 'module_id' => $moduleId,
        'kind' => $kind, 'items' => json_encode($items), 'answers' => '{}', 'status' => 'open', 'time_limit_sec' => $limitSec,
        'pledged_at' => gmdate('Y-m-d H:i:s', $now), 'started_at' => gmdate('Y-m-d H:i:s', $now),
        'deadline' => gmdate('Y-m-d H:i:s', $now + $limitSec), 'updated_at' => gmdate('Y-m-d H:i:s', $now)]);
}

/**
 * Start a lesson exam. The student must be able to open the lesson and must
 * pledge the work is their own. One sitting open at a time: asking again
 * returns the open one rather than a second clock. Throws RuntimeException
 * ('not_yours'|'no_questions'|'pledge_required').
 */
function mam_exam_start_session(int $studentId, int $lessonId, bool $pledged): int {
    if (!$pledged) throw new RuntimeException('pledge_required');
    $open = mam_exam_open_sitting($studentId);
    if ($open) return (int) $open['id'];
    $l = mam_lesson_get($lessonId, true);
    if (!$l || !mam_lesson_student_can_open($studentId, $l)) throw new RuntimeException('not_yours');
    $ids = array_column($l['checks'], 'id');
    if (!$ids) throw new RuntimeException('no_questions');
    // Spaced recall: machine-marked questions from lessons this student already finished on the course.
    $spaced = mam_db_all("SELECT mc.id FROM module_checks mc JOIN modules m ON m.id = mc.module_id
                            JOIN module_progress p ON p.module_id = m.id AND p.student_id = ? AND p.status = 'completed'
                           WHERE m.course_id = ? AND m.id <> ? AND m.status = 'published' AND mc.kind IN ('mcq','order')",
        [$studentId, $l['course_id'], $lessonId]);
    $spaced = array_column($spaced, 'id'); shuffle($spaced);
    $spaced = function_exists('mam_adaptive_pick') ? mam_adaptive_pick($studentId, (int) $l['course_id'], $spaced, MAM_EXAM_SPACED) : array_slice($spaced, 0, MAM_EXAM_SPACED);
    $ids = array_merge($ids, $spaced);
    $limit = max(MAM_EXAM_MIN_SEC, count($ids) * MAM_EXAM_SEC_PER_ITEM);
    $id = mam_exam_insert($studentId, $l['course_id'], $lessonId, 'session', mam_exam_build_items($ids), $limit);
    mam_audit('lesson_exam_start', 'lesson_sitting', $id, ['kind' => 'session', 'lesson' => $lessonId]);
    return $id;
}

/**
 * Start the course trial: open at tier 5, once a week. Throws RuntimeException
 * ('pledge_required'|'not_open'|'cooldown'|'not_enough_questions').
 */
function mam_exam_start_trial(int $studentId, int $courseId, bool $pledged): int {
    if (!$pledged) throw new RuntimeException('pledge_required');
    $open = mam_exam_open_sitting($studentId);
    if ($open) return (int) $open['id'];
    if (!mam_lesson_tier($studentId, $courseId)['trial_open']) throw new RuntimeException('not_open');
    $last = mam_db_row("SELECT MAX(submitted_at) t FROM lesson_sittings WHERE student_id = ? AND course_id = ? AND kind = 'trial' AND submitted_at IS NOT NULL", [$studentId, $courseId]);
    if ($last && $last['t'] && strtotime($last['t'] . ' UTC') > time() - MAM_TRIAL_COOLDOWN_DAYS * 86400) throw new RuntimeException('cooldown');
    $pool = fn(string $kinds) => array_column(mam_db_all(
        "SELECT mc.id FROM module_checks mc JOIN modules m ON m.id = mc.module_id
          WHERE m.course_id = ? AND m.status = 'published' AND mc.kind IN ($kinds)", [$courseId]), 'id');
    $auto = $pool("'mcq','order'"); $written = $pool("'short','task','reflection'");
    shuffle($written);
    // Machine-marked questions are chosen near this student's level (adaptive.php), with a spread either side.
    $auto = function_exists('mam_adaptive_pick') ? mam_adaptive_pick($studentId, $courseId, $auto, MAM_TRIAL_AUTO) : array_slice($auto, 0, MAM_TRIAL_AUTO);
    $ids = array_merge($auto, array_slice($written, 0, MAM_TRIAL_WRITTEN));
    if (count($ids) < 4) throw new RuntimeException('not_enough_questions');
    $id = mam_exam_insert($studentId, $courseId, null, 'trial', mam_exam_build_items($ids), MAM_TRIAL_SEC);
    mam_audit('lesson_exam_start', 'lesson_sitting', $id, ['kind' => 'trial', 'course' => $courseId]);
    return $id;
}

/** A sitting as its student sees it: no answers, options in this sitting's order, time left by the server's clock. */
function mam_exam_view(int $studentId, int $sittingId): array {
    $s = mam_db_row('SELECT * FROM lesson_sittings WHERE id = ? AND student_id = ?', [$sittingId, $studentId]);
    if (!$s) throw new RuntimeException('not_found');
    if ($s['status'] === 'open' && time() > strtotime($s['deadline'] . ' UTC') + MAM_EXAM_GRACE_SEC) {
        mam_exam_finish($sittingId, null);
        $s = mam_db_row('SELECT * FROM lesson_sittings WHERE id = ?', [$sittingId]);
    }
    $items = json_decode((string) $s['items'], true) ?: [];
    $answers = json_decode((string) ($s['answers'] ?? '{}'), true) ?: [];
    $out = ['id' => (int) $s['id'], 'kind' => $s['kind'], 'status' => $s['status'], 'course_id' => (int) $s['course_id'],
        'module_id' => $s['module_id'] !== null ? (int) $s['module_id'] : null,
        'seconds_left' => max(0, strtotime($s['deadline'] . ' UTC') - time()), 'time_limit_sec' => (int) $s['time_limit_sec'],
        'items' => [], 'result' => null];
    foreach ($items as $n => $it) {
        $c = mam_db_row('SELECT id, kind, prompt, options, points FROM module_checks WHERE id = ?', [(int) $it['check_id']]);
        if (!$c) continue;
        $opts = mam_lesson_json_list($c['options']);
        $row = ['check_id' => (int) $c['id'], 'kind' => $c['kind'], 'prompt' => $c['prompt'], 'points' => (int) $c['points'],
            'saved' => $answers[(string) $c['id']] ?? null];
        if ($c['kind'] === 'mcq') $row['options'] = array_map(fn($i) => ['i' => (int) $i, 'text' => (string) ($opts[$i] ?? '')], $it['perm'] ?: array_keys($opts));
        elseif ($c['kind'] === 'order') $row['options'] = mam_lesson_order_shuffle((int) $c['id'] + (int) $s['id'] * 7919, $opts);
        $out['items'][] = $row;
    }
    if ($s['status'] !== 'open') {
        $marks = json_decode((string) ($s['marks'] ?? '{}'), true) ?: [];
        $out['result'] = ['pct' => $s['pct'] !== null ? round((float) $s['pct']) : null, 'band' => $s['band'],
            'pending' => (int) $s['pending'] === 1, 'submitted_at' => $s['submitted_at'],
            'appeal' => ['open' => $s['appealed_at'] === null && $s['status'] === 'marked'
                    && strtotime((string) $s['submitted_at'] . ' UTC') > time() - MAM_EXAM_APPEAL_DAYS * 86400,
                'text' => $s['appeal_text'], 'outcome' => $s['appeal_outcome']],
            'marks' => array_map(fn($m) => ['awarded' => $m['awarded'] ?? null, 'by' => $m['by'] ?? null,
                'comment' => $m['comment'] ?? null, 'hold' => !empty($m['hold'])], $marks)];
    }
    return $out;
}

/** Save answers while the clock runs. After the deadline plus grace nothing more is accepted. */
function mam_exam_save(int $studentId, int $sittingId, array $answers): void {
    $s = mam_db_row('SELECT * FROM lesson_sittings WHERE id = ? AND student_id = ?', [$sittingId, $studentId]);
    if (!$s || $s['status'] !== 'open') throw new RuntimeException('closed');
    if (time() > strtotime($s['deadline'] . ' UTC') + MAM_EXAM_GRACE_SEC) throw new RuntimeException('time_up');
    $ids = array_column(json_decode((string) $s['items'], true) ?: [], 'check_id');
    $keep = [];
    foreach ($ids as $cid) {
        $v = $answers[(string) $cid] ?? ($answers[$cid] ?? null);
        if ($v === null) continue;
        $keep[(string) $cid] = is_array($v) ? array_map('intval', array_values($v)) : mb_substr((string) $v, 0, 8000);
    }
    $old = json_decode((string) ($s['answers'] ?? '{}'), true) ?: [];
    mam_db_update('lesson_sittings', ['answers' => json_encode($keep + $old, JSON_UNESCAPED_UNICODE), 'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $sittingId]);
}

/** Submit: save what came with it (if still in time), then mark. Returns the student's view. */
function mam_exam_submit(int $studentId, int $sittingId, array $answers): array {
    $s = mam_db_row('SELECT * FROM lesson_sittings WHERE id = ? AND student_id = ?', [$sittingId, $studentId]);
    if (!$s) throw new RuntimeException('not_found');
    if ($s['status'] !== 'open') return mam_exam_view($studentId, $sittingId);
    try { mam_exam_save($studentId, $sittingId, $answers); } catch (RuntimeException $e) { /* late: mark what was saved */ }
    mam_exam_finish($sittingId, null);
    return mam_exam_view($studentId, $sittingId);
}

/**
 * Mark a sitting from its saved answers: machine-marked questions at once,
 * written ones by the model where allowed, the rest left for a teacher. An
 * unanswered question scores nothing; it is not left pending.
 */
function mam_exam_finish(int $sittingId, ?int $by): void {
    $s = mam_db_row('SELECT * FROM lesson_sittings WHERE id = ?', [$sittingId]);
    if (!$s || $s['status'] !== 'open') return;
    $answers = json_decode((string) ($s['answers'] ?? '{}'), true) ?: [];
    $marks = [];
    $checks = [];
    foreach (json_decode((string) $s['items'], true) ?: [] as $it) {
        $c = mam_db_row('SELECT * FROM module_checks WHERE id = ?', [(int) $it['check_id']]);
        if (!$c) continue;
        $checks[] = ['id' => (int) $c['id'], 'points' => (int) $c['points']];
        $cid = (string) $c['id']; $a = $answers[$cid] ?? null; $pts = (int) $c['points'];
        if ($a === null || $a === '' || $a === []) { $marks[$cid] = ['awarded' => 0.0]; continue; }
        if ($c['kind'] === 'mcq') $marks[$cid] = ['awarded' => (string) (int) $a === (string) $c['answer'] ? (float) $pts : 0.0];
        elseif ($c['kind'] === 'order') $marks[$cid] = ['awarded' => mam_lesson_order_points((array) $a, count(mam_lesson_json_list($c['options'])), $pts)];
        else $marks[$cid] = mam_lesson_ai_mark($c, is_array($a) ? implode(' ', $a) : (string) $a, (int) $s['student_id'], $sittingId) ?? ['awarded' => null];
    }
    mam_exam_store_marks($sittingId, $checks, $marks, $by, 'submitted_at');
}

/** Totals, band and status from a marks map; awards tiers when the sitting is fully marked. */
function mam_exam_store_marks(int $sittingId, array $checks, array $marks, ?int $by, ?string $stamp = null): void {
    $s = mam_db_row('SELECT * FROM lesson_sittings WHERE id = ?', [$sittingId]);
    [$score, $max, $pending, $pct] = mam_lesson_totals($checks, $marks);
    $passMark = $s['kind'] === 'trial' ? MAM_TRIAL_PASS : 60;
    $row = ['marks' => json_encode($marks, JSON_UNESCAPED_UNICODE), 'score' => $score, 'max_score' => $max,
        'pct' => $pending ? null : $pct, 'band' => $pending ? null : mam_exam_band($pct, $passMark),
        'pending' => $pending ? 1 : 0, 'status' => $pending ? 'submitted' : 'marked', 'updated_at' => gmdate('Y-m-d H:i:s')];
    if ($by) $row['marked_by'] = $by;
    if ($stamp) $row[$stamp] = gmdate('Y-m-d H:i:s');
    mam_db_update('lesson_sittings', $row, ['id' => $sittingId]);
    // Machine-marked answers move the ability estimate once, when the sitting is first marked.
    if (!$pending && $s['status'] === 'open' && function_exists('mam_adaptive_learn')) {
        try { mam_adaptive_learn((int) $s['student_id'], (int) $s['course_id'], $checks, $marks); } catch (Throwable $t) {}
    }
    if (!$pending && function_exists('mam_lesson_award')) {
        try { mam_lesson_award((int) $s['student_id'], (int) $s['course_id']); } catch (Throwable $t) {}
    }
}

/** A student's sittings on their courses, newest first, for the Learn screen. */
function mam_exam_history(int $studentId, int $limit = 20): array {
    return mam_db_all("SELECT s.id, s.kind, s.status, s.pct, s.band, s.pending, s.submitted_at, s.course_id, c.name AS course_name, m.title AS lesson_title
                         FROM lesson_sittings s JOIN courses c ON c.id = s.course_id LEFT JOIN modules m ON m.id = s.module_id
                        WHERE s.student_id = ? ORDER BY s.id DESC LIMIT " . max(1, min(100, $limit)), [$studentId]);
}

/** Appeal a marked result: once, within MAM_EXAM_APPEAL_DAYS, with a reason. */
function mam_exam_appeal(int $studentId, int $sittingId, string $text): void {
    $s = mam_db_row('SELECT * FROM lesson_sittings WHERE id = ? AND student_id = ?', [$sittingId, $studentId]);
    if (!$s) throw new RuntimeException('not_found');
    if ($s['status'] !== 'marked') throw new RuntimeException('not_marked');
    if ($s['appealed_at'] !== null) throw new RuntimeException('already_appealed');
    if (strtotime((string) $s['submitted_at'] . ' UTC') < time() - MAM_EXAM_APPEAL_DAYS * 86400) throw new RuntimeException('too_late');
    $text = mb_substr(trim($text), 0, 2000);
    if (mb_strlen($text) < 10) throw new InvalidArgumentException('Say what you think was marked wrongly, and why.');
    mam_db_update('lesson_sittings', ['appeal_text' => $text, 'appealed_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $sittingId]);
    mam_audit('lesson_exam_appeal', 'lesson_sitting', $sittingId, []);
}

/**
 * Exam work waiting for a teacher: written answers to mark, and appeals to
 * answer — only for students they teach on that course (all, for an admin).
 */
function mam_exam_teacher_queue(?int $teacherId): array {
    $sql = "SELECT s.*, u.name AS student_name, c.name AS course_name, m.title AS lesson_title
              FROM lesson_sittings s JOIN users u ON u.id = s.student_id JOIN courses c ON c.id = s.course_id
              LEFT JOIN modules m ON m.id = s.module_id
             WHERE (s.pending = 1 OR (s.appealed_at IS NOT NULL AND s.appeal_outcome IS NULL))";
    $args = [];
    if ($teacherId !== null) {
        $sql .= " AND EXISTS (SELECT 1 FROM course_enrollments ce LEFT JOIN cohorts co ON co.id = ce.cohort_id
                   WHERE ce.student_id = s.student_id AND ce.course_id = s.course_id AND ce.status IN ('active','completed')
                     AND (ce.instructor_id = ? OR co.instructor_id = ?))";
        $args = [$teacherId, $teacherId];
    }
    $out = [];
    foreach (mam_db_all($sql . ' ORDER BY s.id LIMIT 100', $args) as $s) {
        $answers = json_decode((string) ($s['answers'] ?? '{}'), true) ?: [];
        $marks = json_decode((string) ($s['marks'] ?? '{}'), true) ?: [];
        $items = [];
        foreach (json_decode((string) $s['items'], true) ?: [] as $it) {
            $c = mam_db_row('SELECT * FROM module_checks WHERE id = ?', [(int) $it['check_id']]);
            if (!$c || in_array($c['kind'], MAM_LESSON_AUTO_KINDS, true)) continue;
            $cid = (string) $c['id'];
            $items[] = ['check_id' => (int) $c['id'], 'kind' => $c['kind'], 'prompt' => $c['prompt'], 'rubric' => (string) ($c['rubric'] ?? ''),
                'points' => (int) $c['points'], 'answer' => $answers[$cid] ?? null, 'awarded' => $marks[$cid]['awarded'] ?? null,
                'by' => $marks[$cid]['by'] ?? null, 'hold' => !empty($marks[$cid]['hold'])];
        }
        $out[] = ['sitting_id' => (int) $s['id'], 'kind' => $s['kind'], 'student_id' => (int) $s['student_id'], 'student_name' => $s['student_name'],
            'course_name' => $s['course_name'], 'lesson_title' => $s['lesson_title'], 'pending' => (int) $s['pending'] === 1,
            'appeal' => $s['appealed_at'] !== null && $s['appeal_outcome'] === null ? (string) $s['appeal_text'] : null, 'items' => $items];
    }
    return $out;
}

/**
 * A teacher marks written answers on a sitting and/or answers its appeal. Any
 * written mark may be set — including one the assistant gave. Answering an
 * appeal needs a sentence, upheld or not.
 */
function mam_exam_teacher_mark(int $teacherId, int $sittingId, array $awards, array $comments = [], ?string $appealOutcome = null): void {
    $s = mam_db_row('SELECT * FROM lesson_sittings WHERE id = ?', [$sittingId]);
    if (!$s || $s['status'] === 'open') throw new RuntimeException('not_found');
    $marks = json_decode((string) ($s['marks'] ?? '{}'), true) ?: [];
    $checks = [];
    foreach (json_decode((string) $s['items'], true) ?: [] as $it) {
        $c = mam_db_row('SELECT * FROM module_checks WHERE id = ?', [(int) $it['check_id']]);
        if (!$c) continue;
        $checks[] = ['id' => (int) $c['id'], 'points' => (int) $c['points']];
        $cid = (string) $c['id'];
        if (in_array($c['kind'], MAM_LESSON_AUTO_KINDS, true)) continue;
        $v = $awards[$cid] ?? ($awards[$c['id']] ?? null);
        if ($v === null || $v === '') continue;
        $marks[$cid] = ['awarded' => (float) max(0, min((int) $c['points'], (float) $v)), 'by' => 'teacher',
            'comment' => mb_substr(trim((string) ($comments[$cid] ?? '')), 0, 600) ?: null];
    }
    if ($appealOutcome !== null && $s['appealed_at'] !== null && $s['appeal_outcome'] === null) {
        $o = mb_substr(trim($appealOutcome), 0, 2000);
        if ($o === '') throw new InvalidArgumentException('Answer the appeal in a sentence, upheld or not.');
        mam_db_update('lesson_sittings', ['appeal_outcome' => $o, 'appeal_by' => $teacherId ?: null], ['id' => $sittingId]);
    }
    mam_exam_store_marks($sittingId, $checks, $marks, $teacherId);
    mam_audit('lesson_exam_mark', 'lesson_sitting', $sittingId, ['appeal_answered' => $appealOutcome !== null]);
    if (function_exists('mam_notify')) {
        try { mam_notify((int) $s['student_id'], 'lesson.exam', 'Your exam has been marked', null, '/portal/student/#learn'); } catch (Throwable $t) {}
    }
}

/* ─── The study helper ──────────────────────────────────────────────────── */

function mam_lesson_helper_on(): bool {
    return (int) mam_setting_num('lesson_helper_enabled', 0) === 1 && function_exists('mam_ai_enabled') && mam_ai_enabled('lesson_helper');
}

/** How many helper questions this student has asked today (rate_limits bucket, the same store the AI quota uses). */
function mam_lesson_helper_used(int $studentId): int {
    $r = mam_db_row('SELECT count FROM rate_limits WHERE bucket = ?', ['lesson_helper:' . $studentId . ':' . gmdate('Y-m-d')]);
    return $r ? (int) $r['count'] : 0;
}

function mam_lesson_helper_count(int $studentId): void {
    $bucket = 'lesson_helper:' . $studentId . ':' . gmdate('Y-m-d');
    $reset = strtotime('tomorrow UTC') ?: time() + 86400;
    $sql = mam_db_driver() === 'mysql'
        ? 'INSERT INTO rate_limits (bucket, count, reset_at) VALUES (?,1,?) ON DUPLICATE KEY UPDATE count = count + 1'
        : 'INSERT INTO rate_limits (bucket, count, reset_at) VALUES (?,1,?) ON CONFLICT(bucket) DO UPDATE SET count = count + 1';
    mam_db()->prepare($sql)->execute([$bucket, $reset]);
}

/**
 * Ask the study helper about one lesson. Returns ['answer' => text, 'left' => n]
 * or throws RuntimeException('helper_off'|'not_yours'|'exam_open'|'daily_limit').
 * A question carrying safeguarding words is never sent anywhere: the student is
 * pointed to a person, and the safeguarding officer is told.
 */
function mam_lesson_helper_ask(int $studentId, int $lessonId, string $question): array {
    if (!mam_lesson_helper_on()) throw new RuntimeException('helper_off');
    $l = mam_lesson_get($lessonId, false);
    if (!$l || !mam_lesson_student_can_open($studentId, $l)) throw new RuntimeException('not_yours');
    if (mam_exam_open_sitting($studentId)) throw new RuntimeException('exam_open');
    if (mam_lesson_helper_used($studentId) >= MAM_HELPER_DAILY) throw new RuntimeException('daily_limit');
    $question = mb_substr(trim($question), 0, 1000);
    if ($question === '') throw new InvalidArgumentException('Ask a question first.');
    if (mam_lesson_safeguarding_hit($question)) {
        mam_lesson_safeguarding_raise($studentId, 'the study helper', $lessonId);
        return ['answer' => 'That sounds important, and it is something a person should hear, not a study helper. '
            . 'Please tell your teacher, a parent or another adult you trust today. The academy\'s safeguarding lead has been told you may need to talk.',
            'left' => MAM_HELPER_DAILY - mam_lesson_helper_used($studentId), 'held' => true];
    }
    mam_lesson_helper_count($studentId);
    $context = 'Lesson: ' . $l['title'] . "\n" . ($l['summary'] ? 'In one line: ' . $l['summary'] . "\n" : '')
        . ($l['objectives'] ? 'By the end the student can: ' . implode('; ', $l['objectives']) . "\n" : '')
        . 'The lesson text: ' . mb_substr($l['body'], 0, 6000) . "\n"
        . 'The lesson\'s own questions (do NOT answer these for the student): '
        . implode(' | ', array_map(fn($c) => $c['prompt'], $l['checks']));
    $r = mam_ai_complete('lesson_helper', $context . "\n\nThe student asks: " . $question, [
        'system' => 'You are a patient study helper at a music academy; the student may be a child. Explain using the lesson. '
            . 'Never give the answer to one of the lesson\'s own questions — help them work it out with a hint or a smaller step. '
            . 'Keep answers short, warm and plain. If asked about anything unsafe or not about learning, say kindly that you can only help with the lesson.',
        'temperature' => 0.4, 'max_tokens' => 500, 'user_id' => $studentId,
    ]);
    if (empty($r['ok']) || trim((string) ($r['text'] ?? '')) === '') throw new RuntimeException('helper_failed');
    return ['answer' => mb_substr(trim((string) $r['text']), 0, 3000), 'left' => max(0, MAM_HELPER_DAILY - mam_lesson_helper_used($studentId)), 'held' => false];
}
