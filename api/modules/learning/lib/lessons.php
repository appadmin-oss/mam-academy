<?php
/**
 * Lessons — the syllabus as something a student works through, and a record of
 * how far each one got. Ported from NGG's curriculum (api/_lib/domain/
 * curriculum.php in the staff-desk codebase), onto the rows MAM already had.
 *
 * A LESSON is a `modules` row: one course · level · week. Before v97 that row
 * was a title and a paragraph, and nothing recorded a student against it —
 * per-student progress lived only on cohort `lessons`, which nothing links to
 * the syllabus. Now a lesson has:
 *   • a life: draft → published → retired (only `published` reaches a student);
 *   • questions (module_checks) — multiple choice and put-in-order are marked by
 *     the machine; short answers, tasks, reflections and evidence are marked by a
 *     teacher, never by the machine;
 *   • materials (module_materials) — links and files, each with a line saying
 *     what to do with it;
 *   • one progress row per student (module_progress).
 *
 * The rules carried over from NGG, each of which exists because its absence was
 * a bug there:
 *   1. A lesson cannot be PUBLISHED without questions, nor with only machine-
 *      marked ones. "Clicked through" is not "learned", and a lesson a child can
 *      finish by guessing a multiple choice is the same thing.
 *   2. The right answers are hidden until the student passes or has tried
 *      MAM_LESSON_REVEAL_AFTER times. NGG returned them after the FIRST try, and
 *      the second attempt was copying.
 *   3. Completion is sticky: a later, worse attempt never un-completes a lesson.
 *   4. The version bumps only when a QUESTION changes and somebody has already
 *      answered it — fixing a typo in the body must not mark every score stale.
 *      A progress row from an older version reads as `stale`.
 *   5. A lesson anybody has started is RETIRED, never deleted: the progress rows
 *      are the record of what a child did.
 *   6. An instructor may tick a lesson done (it happened in the room). An untick
 *      only removes a tick-made row with no attempts: a lesson done online is the
 *      student's record, not the teacher's.
 *
 * What is deliberately NOT here (NGG has it): fines for an overdue lesson —
 * money penalties on children sit behind legal gates in this academy (CLAUDE.md
 * gotcha #11) and are the owner's decision, not a port; AI marking — a written
 * answer goes to a person, which is what NGG does when no provider is set;
 * exams, mastery tiers, placement years and the study helper, which are later
 * phases (docs/HANDOFF.md §4z).
 *
 * MAM's own refusal holds throughout: nothing here scores a child's PLAYING. An
 * `evidence` question takes a link to a recording and a person marks it.
 */
declare(strict_types=1);

const MAM_LESSON_STATUSES      = ['draft', 'published', 'retired'];
const MAM_LESSON_KINDS         = ['module', 'revision', 'exam'];
const MAM_LESSON_CHECK_KINDS   = ['mcq', 'order', 'short', 'task', 'reflection', 'evidence'];
/** Marked by the machine. Everything else waits for a person. */
const MAM_LESSON_AUTO_KINDS    = ['mcq', 'order'];
const MAM_LESSON_MATERIAL_KINDS = ['link', 'pdf', 'score', 'slides', 'doc', 'video', 'audio', 'image'];
const MAM_LESSON_REVEAL_AFTER  = 3;
/** An answer given at least this many days after the lesson was opened counts as recall. */
const MAM_LESSON_RECALL_DAYS   = 2;
const MAM_LESSON_MAX_CHECKS    = 30;
const MAM_LESSON_MAX_MATERIALS = 20;
const MAM_LESSON_BULK_MAX      = 500;

function mam_lesson_now(): string { return gmdate('Y-m-d H:i:s'); }

function mam_lesson_json_list($v): array {
    if (is_array($v)) return array_values($v);
    if (!is_string($v) || $v === '') return [];
    $d = json_decode($v, true);
    return is_array($d) ? array_values($d) : [];
}

/** A link a lesson may hold: https, or one of this site's own /api/media/ files. */
function mam_lesson_url_ok(string $u): bool {
    $u = trim($u);
    if ($u === '' || strlen($u) > 1000) return false;
    if (preg_match('#^/api/media/[a-z0-9_-]+\?#i', $u)) return true;
    return (bool) filter_var($u, FILTER_VALIDATE_URL) && stripos($u, 'https://') === 0;
}

/* ─── Reading a lesson ─────────────────────────────────────────────────── */

/**
 * One lesson with its questions and materials. $withAnswers is the line between
 * a staff view and a student's: a student's copy carries no answer, no rubric
 * and no "why this is tempting", and its order steps are shuffled — stably, by
 * the question's id, and never into the correct order.
 */
function mam_lesson_get(int $id, bool $withAnswers = false, bool $allMaterials = false): ?array {
    $m = mam_db_row(
        "SELECT m.*, c.code AS course_code, c.name AS course_name, l.code AS level_code, l.name AS level_name
           FROM modules m JOIN courses c ON c.id = m.course_id LEFT JOIN levels l ON l.id = m.level_id
          WHERE m.id = ?", [$id]);
    if (!$m) return null;
    $checks = mam_db_all('SELECT * FROM module_checks WHERE module_id = ? ORDER BY position, id', [$id]);
    $mats = mam_db_all('SELECT * FROM module_materials WHERE module_id = ?' . ($allMaterials ? '' : " AND status = 'approved'")
        . ' ORDER BY position, id', [$id]);
    $out = [
        'id' => (int) $m['id'], 'course_id' => (int) $m['course_id'], 'course_code' => (string) $m['course_code'],
        'course_name' => (string) $m['course_name'], 'level_id' => $m['level_id'] !== null ? (int) $m['level_id'] : null,
        'level_code' => $m['level_code'], 'level_name' => $m['level_name'], 'week' => (int) $m['week'],
        'kind' => (string) $m['kind'], 'title' => (string) $m['title'], 'summary' => (string) ($m['summary'] ?? ''),
        'body' => (string) ($m['content'] ?? ''), 'objectives' => mam_lesson_json_list($m['objectives'] ?? null),
        'duration_mins' => $m['duration_mins'] !== null ? (int) $m['duration_mins'] : null,
        'pass_mark' => (int) ($m['pass_mark'] ?? 60), 'status' => (string) ($m['status'] ?? 'published'),
        'version' => (int) ($m['version'] ?? 1), 'published_at' => $m['published_at'] ?? null,
        'checks' => [], 'materials' => [],
    ];
    foreach ($checks as $c) {
        $opts = mam_lesson_json_list($c['options']);
        $row = ['id' => (int) $c['id'], 'kind' => (string) $c['kind'], 'prompt' => (string) $c['prompt'],
                'options' => $opts, 'points' => (int) $c['points'], 'position' => (int) $c['position']];
        if ($withAnswers) {
            $row['difficulty'] = (int) ($c['difficulty'] ?? 3);
            $row['answer'] = $c['answer'];
            $row['whys']   = mam_lesson_json_list($c['whys']);
            $row['rubric'] = (string) ($c['rubric'] ?? '');
        } elseif ($c['kind'] === 'order' && count($opts) > 1) {
            $row['options'] = mam_lesson_order_shuffle((int) $c['id'], $opts);
        }
        $out['checks'][] = $row;
    }
    foreach ($mats as $mt) {
        $out['materials'][] = ['id' => (int) $mt['id'], 'kind' => (string) $mt['kind'], 'title' => (string) $mt['title'],
            'url' => (string) $mt['url'], 'guide' => (string) ($mt['guide'] ?? ''), 'status' => (string) $mt['status']];
    }
    return $out;
}

/**
 * A put-in-order question's steps as a student is shown them: each step keeps
 * its ORIGINAL index (that is what the student sends back), the order is a
 * stable shuffle seeded by the question id — a refresh must not reshuffle — and
 * it is never the correct order, which would hand over the answer.
 */
function mam_lesson_order_shuffle(int $checkId, array $opts): array {
    $items = [];
    foreach ($opts as $i => $t) $items[] = ['i' => $i, 'text' => (string) $t];
    usort($items, fn($a, $b) => strcmp(md5($checkId . ':' . $a['i']), md5($checkId . ':' . $b['i'])));
    $inOrder = true;
    foreach ($items as $k => $it) if ($it['i'] !== $k) { $inOrder = false; break; }
    if ($inOrder) $items = array_merge(array_slice($items, 1), [$items[0]]);   // rotate: never the answer
    return $items;
}

/* ─── Writing a lesson ─────────────────────────────────────────────────── */

/**
 * Validate a lesson as the console's editor posts it. Returns [clean, errors];
 * errors is a list of sentences, empty when the lesson may be saved. Nothing is
 * written here — validation happens before ANY write, so a refused save leaves
 * the lesson exactly as it was.
 *
 * $in: course_id, level_id?, week, kind?, title, summary?, body?, objectives[]?,
 *      duration_mins?, pass_mark?, status?,
 *      checks[]: {id?, kind, prompt, options[]?, answer?, whys[]?, rubric?, points?},
 *      materials[]: {id?, kind?, title, url, guide?}
 */
function mam_lesson_validate(array $in, ?array $existing = null): array {
    $e = [];
    $v = [];
    $v['course_id'] = (int) ($in['course_id'] ?? ($existing['course_id'] ?? 0));
    if ($v['course_id'] <= 0 || !mam_db_row('SELECT 1 FROM courses WHERE id = ?', [$v['course_id']])) $e[] = 'Choose the course this lesson belongs to.';
    $lv = $in['level_id'] ?? ($existing['level_id'] ?? null);
    $v['level_id'] = ($lv === '' || $lv === null || (int) $lv <= 0) ? null : (int) $lv;
    if ($v['level_id'] !== null && !mam_db_row('SELECT 1 FROM levels WHERE id = ?', [$v['level_id']])) $e[] = 'That level does not exist.';
    $v['week'] = (int) ($in['week'] ?? 0);
    if ($v['week'] < 1 || $v['week'] > 104) $e[] = 'The week must be between 1 and 104.';
    $k = (string) ($in['kind'] ?? ($existing['kind'] ?? 'module'));
    $v['kind'] = in_array($k, MAM_LESSON_KINDS, true) ? $k : 'module';      // absent was stored as '' before
    $v['title'] = trim((string) ($in['title'] ?? ''));
    if ($v['title'] === '' || mb_strlen($v['title']) > 160) $e[] = 'Give the lesson a title (up to 160 characters).';
    $v['summary'] = mb_substr(trim((string) ($in['summary'] ?? '')), 0, 600);
    $v['body'] = mb_substr((string) ($in['body'] ?? ''), 0, 60000);
    $obj = is_array($in['objectives'] ?? null) ? $in['objectives'] : preg_split('/\R/', (string) ($in['objectives'] ?? ''));
    $v['objectives'] = array_values(array_slice(array_filter(array_map(fn($o) => mb_substr(trim((string) $o), 0, 240), $obj), 'strlen'), 0, 12));
    $dm = $in['duration_mins'] ?? null;
    $v['duration_mins'] = ($dm === null || $dm === '') ? null : max(1, min(600, (int) $dm));
    $v['pass_mark'] = max(0, min(100, (int) ($in['pass_mark'] ?? 60)));
    $v['status'] = in_array($in['status'] ?? '', MAM_LESSON_STATUSES, true) ? (string) $in['status'] : ($existing['status'] ?? 'draft');

    $checks = is_array($in['checks'] ?? null) ? array_values($in['checks']) : [];
    if (count($checks) > MAM_LESSON_MAX_CHECKS) $e[] = 'A lesson holds at most ' . MAM_LESSON_MAX_CHECKS . ' questions.';
    $v['checks'] = [];
    foreach (array_slice($checks, 0, MAM_LESSON_MAX_CHECKS) as $i => $c) {
        $n = $i + 1;
        $kind = (string) ($c['kind'] ?? '');
        if (!in_array($kind, MAM_LESSON_CHECK_KINDS, true)) { $e[] = "Question $n: choose what kind of question it is."; continue; }
        $prompt = trim((string) ($c['prompt'] ?? ''));
        if ($prompt === '') { $e[] = "Question $n: write the question."; continue; }
        $opts = array_values(array_filter(array_map(fn($o) => mb_substr(trim((string) $o), 0, 300),
            is_array($c['options'] ?? null) ? $c['options'] : preg_split('/\R/', (string) ($c['options'] ?? ''))), 'strlen'));
        $points = (int) ($c['points'] ?? 1);
        if ($points < 0 || $points > 100) { $e[] = "Question $n: points must be between 0 and 100."; continue; }
        $answer = null; $whys = [];
        if ($kind === 'mcq') {
            if (count($opts) < 2 || count($opts) > 8) { $e[] = "Question $n: a multiple choice needs 2 to 8 options."; continue; }
            $a = $c['answer'] ?? null;
            if ($a === null || $a === '' || !ctype_digit((string) $a) || (int) $a >= count($opts)) { $e[] = "Question $n: mark which option is right."; continue; }
            $answer = (string) (int) $a;
            $w = is_array($c['whys'] ?? null) ? $c['whys'] : preg_split('/\R/', (string) ($c['whys'] ?? ''));
            $whys = array_map(fn($x) => mb_substr(trim((string) $x), 0, 300), array_slice(array_pad(array_values($w), count($opts), ''), 0, count($opts)));
        } elseif ($kind === 'order') {
            // The options ARE the right order, as written. The student sees them shuffled.
            if (count($opts) < 2 || count($opts) > 10) { $e[] = "Question $n: put 2 to 10 steps in the right order."; continue; }
        } else {
            $opts = [];
        }
        $v['checks'][] = ['id' => (int) ($c['id'] ?? 0) ?: null, 'kind' => $kind, 'prompt' => mb_substr($prompt, 0, 4000),
            'options' => $opts, 'answer' => $answer, 'whys' => $whys,
            'rubric' => mb_substr(trim((string) ($c['rubric'] ?? '')), 0, 2000), 'points' => $points, 'position' => $n,
            // How hard the teacher thinks it is, 1–5; the adaptive layer (adaptive.php) starts from this and learns.
            'difficulty' => max(1, min(5, (int) ($c['difficulty'] ?? 3)))];
    }

    $mats = is_array($in['materials'] ?? null) ? array_values($in['materials']) : [];
    if (count($mats) > MAM_LESSON_MAX_MATERIALS) $e[] = 'A lesson holds at most ' . MAM_LESSON_MAX_MATERIALS . ' materials.';
    $v['materials'] = [];
    foreach (array_slice($mats, 0, MAM_LESSON_MAX_MATERIALS) as $i => $m) {
        $n = $i + 1;
        $title = trim((string) ($m['title'] ?? '')); $url = trim((string) ($m['url'] ?? ''));
        if ($title === '' && $url === '') continue;                       // an empty row on the form
        if ($title === '') { $e[] = "Material $n: give it a title."; continue; }
        if (!mam_lesson_url_ok($url)) { $e[] = "Material $n: the link must start with https:// (or be a file stored here)."; continue; }
        $kind = in_array($m['kind'] ?? 'link', MAM_LESSON_MATERIAL_KINDS, true) ? (string) $m['kind'] : 'link';
        $v['materials'][] = ['id' => (int) ($m['id'] ?? 0) ?: null, 'kind' => $kind, 'title' => mb_substr($title, 0, 200),
            'url' => $url, 'guide' => mb_substr(trim((string) ($m['guide'] ?? '')), 0, 300), 'position' => $n];
    }

    if (!$e && $v['status'] === 'published') {
        $why = mam_lesson_publish_refusal($v['checks'], $existing);
        if ($why) $e[] = $why;
    }
    return [$v, $e];
}

/**
 * Why this lesson may not be published, or null. NGG's rule, applied where it
 * can be true: a lesson moving TO published, or one that already has
 * questions. A syllabus week that was published before lessons existed (every
 * row from before v97) keeps its state when edited — refusing every edit to it
 * until somebody wrote questions would make the old syllabus uneditable. Such a
 * week is "syllabus only": no questions, so a teacher's tick is its record.
 */
function mam_lesson_publish_refusal(array $checks, ?array $existing): ?string {
    $wasPublished = $existing && ($existing['status'] ?? '') === 'published';
    $hadChecks = $existing && (int) (mam_db_row('SELECT COUNT(*) n FROM module_checks WHERE module_id = ?', [(int) $existing['id']])['n'] ?? 0) > 0;
    if ($wasPublished && !$hadChecks && !$checks) return null;
    if (!$checks) return 'A lesson cannot be published without questions — add at least one, so finishing it means something.';
    $kinds = array_unique(array_column($checks, 'kind'));
    if (!array_diff($kinds, MAM_LESSON_AUTO_KINDS)) {
        return 'Add at least one question a teacher marks (short answer, task, reflection or evidence): a lesson finished by guessing a multiple choice has not been learned.';
    }
    return null;
}

/** The fields of the checks that decide a score — a change to any of these is a change to what was asked. */
function mam_lesson_checks_signature(array $checks): string {
    $sig = array_map(fn($c) => [$c['kind'], trim((string) $c['prompt']), array_values($c['options'] ?? []),
        (string) ($c['answer'] ?? ''), (int) $c['points']], $checks);
    return hash('sha256', json_encode($sig, JSON_UNESCAPED_UNICODE));
}

/**
 * Create or update a lesson. Returns ['id' => …, 'version' => …] or throws
 * InvalidArgumentException carrying the errors joined into one sentence.
 * One week of one course at one level is one lesson: a second lesson for the
 * same slot is refused, as the import and the clone already treat it.
 */
function mam_lesson_save(?int $id, array $in, ?int $by = null): array {
    $existing = $id ? mam_db_row('SELECT * FROM modules WHERE id = ?', [$id]) : null;
    if ($id && !$existing) throw new InvalidArgumentException('That lesson no longer exists.');
    [$v, $errors] = mam_lesson_validate($in, $existing);
    if (!$errors) {
        $clash = mam_lms_find_module_week($v['course_id'], $v['level_id'], $v['week']);
        if ($clash && $clash !== $id) $errors[] = 'There is already a lesson for that course, level and week — edit that one instead.';
    }
    if ($errors) throw new InvalidArgumentException(implode(' ', $errors));

    $db = mam_db();
    $db->beginTransaction();
    try {
        $now = mam_lesson_now();
        $version = $existing ? (int) ($existing['version'] ?? 1) : 1;
        if ($existing) {
            $old = mam_db_all('SELECT kind, prompt, options, answer, points FROM module_checks WHERE module_id = ? ORDER BY position, id', [$id]);
            $old = array_map(fn($c) => ['kind' => $c['kind'], 'prompt' => $c['prompt'], 'options' => mam_lesson_json_list($c['options']),
                'answer' => $c['answer'], 'points' => (int) $c['points']], $old);
            $answered = (int) (mam_db_row('SELECT COUNT(*) n FROM module_progress WHERE module_id = ? AND attempts > 0', [$id])['n'] ?? 0);
            if ($answered > 0 && mam_lesson_checks_signature($old) !== mam_lesson_checks_signature($v['checks'])) $version++;
        }
        $row = ['course_id' => $v['course_id'], 'level_id' => $v['level_id'], 'week' => $v['week'], 'kind' => $v['kind'],
            'title' => $v['title'], 'content' => $v['body'] !== '' ? $v['body'] : null, 'summary' => $v['summary'] ?: null,
            'objectives' => $v['objectives'] ? json_encode($v['objectives'], JSON_UNESCAPED_UNICODE) : null,
            'duration_mins' => $v['duration_mins'], 'pass_mark' => $v['pass_mark'], 'status' => $v['status'],
            'version' => $version, 'updated_at' => $now, 'updated_by' => $by];
        if ($v['status'] === 'published' && (!$existing || empty($existing['published_at']))) $row['published_at'] = $now;
        if ($existing) mam_db_update('modules', $row, ['id' => $id]);
        else $id = (int) mam_db_insert('modules', $row);

        // Questions: keep the ids the editor sends back, so a student's stored
        // answer still points at the question it answered.
        $keep = [];
        foreach ($v['checks'] as $c) {
            $data = ['module_id' => $id, 'kind' => $c['kind'], 'prompt' => $c['prompt'],
                'options' => $c['options'] ? json_encode($c['options'], JSON_UNESCAPED_UNICODE) : null,
                'answer' => $c['answer'], 'whys' => array_filter($c['whys'], 'strlen') ? json_encode($c['whys'], JSON_UNESCAPED_UNICODE) : null,
                'rubric' => $c['rubric'] !== '' ? $c['rubric'] : null, 'points' => $c['points'], 'position' => $c['position'],
                'difficulty' => $c['difficulty'] ?? 3];
            if ($c['id'] && mam_db_row('SELECT 1 FROM module_checks WHERE id = ? AND module_id = ?', [$c['id'], $id])) {
                mam_db_update('module_checks', $data, ['id' => $c['id']]); $keep[] = $c['id'];
            } else {
                $keep[] = (int) mam_db_insert('module_checks', $data);
            }
        }
        $ph = $keep ? implode(',', array_fill(0, count($keep), '?')) : '0';
        $db->prepare("DELETE FROM module_checks WHERE module_id = ? AND id NOT IN ($ph)")->execute(array_merge([$id], $keep));

        $keepM = [];
        foreach ($v['materials'] as $m) {
            $data = ['module_id' => $id, 'kind' => $m['kind'], 'title' => $m['title'], 'url' => $m['url'],
                'guide' => $m['guide'] !== '' ? $m['guide'] : null, 'position' => $m['position']];
            if ($m['id'] && mam_db_row('SELECT 1 FROM module_materials WHERE id = ? AND module_id = ?', [$m['id'], $id])) {
                mam_db_update('module_materials', $data, ['id' => $m['id']]); $keepM[] = $m['id'];
            } else {
                $keepM[] = (int) mam_db_insert('module_materials', $data + ['status' => 'approved']);
            }
        }
        $ph = $keepM ? implode(',', array_fill(0, count($keepM), '?')) : '0';
        // A material a teacher sent is reviewed on its own queue (lessons-learn.php); the editor never removes one it did not show.
        $db->prepare("DELETE FROM module_materials WHERE module_id = ? AND status = 'approved' AND id NOT IN ($ph)")->execute(array_merge([$id], $keepM));
        $db->commit();
    } catch (Throwable $t) {
        if ($db->inTransaction()) $db->rollBack();
        throw $t;
    }
    mam_audit($existing ? 'lesson_update' : 'lesson_create', 'module', $id,
        ['status' => $v['status'], 'version' => $version, 'checks' => count($v['checks'])]);
    return ['id' => $id, 'version' => $version];
}

/**
 * Publish, unpublish (back to draft) or retire lessons in bulk. One verdict per
 * lesson, so a run where some are refused says which and why. Publishing goes
 * through the same rule as a save. Capped at MAM_LESSON_BULK_MAX.
 */
function mam_lesson_set_status(array $ids, string $status, ?int $by = null): array {
    if (!in_array($status, MAM_LESSON_STATUSES, true)) throw new InvalidArgumentException('bad_status');
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (count($ids) > MAM_LESSON_BULK_MAX) throw new InvalidArgumentException('too_many');
    $out = ['applied' => 0, 'unchanged' => 0, 'refused' => []];
    foreach ($ids as $id) {
        $m = mam_db_row('SELECT * FROM modules WHERE id = ?', [$id]);
        if (!$m) { $out['refused'][$id] = 'not found'; continue; }
        if (($m['status'] ?? '') === $status) { $out['unchanged']++; continue; }
        if ($status === 'published') {
            $checks = mam_db_all('SELECT kind FROM module_checks WHERE module_id = ?', [$id]);
            $why = mam_lesson_publish_refusal($checks, null);
            if ($why) { $out['refused'][$id] = $why; continue; }
        }
        $row = ['status' => $status, 'updated_at' => mam_lesson_now(), 'updated_by' => $by];
        if ($status === 'published' && empty($m['published_at'])) $row['published_at'] = mam_lesson_now();
        mam_db_update('modules', $row, ['id' => $id]);
        $out['applied']++;
    }
    mam_audit('lesson_status', 'module', null, ['status' => $status, 'applied' => $out['applied'],
        'unchanged' => $out['unchanged'], 'refused' => count($out['refused'])]);
    return $out;
}

/** True when anybody has a progress row on this lesson — it may then only be retired. */
function mam_lesson_has_progress(int $id): bool {
    return mam_db_row('SELECT 1 FROM module_progress WHERE module_id = ? LIMIT 1', [$id]) !== null
        // An exam sat on it is a result somebody was given (lesson-exams.php, v98).
        || mam_db_row('SELECT 1 FROM lesson_sittings WHERE module_id = ? LIMIT 1', [$id]) !== null;
}

/* ─── Which lessons a student has ──────────────────────────────────────── */

/**
 * The (course, level) pairs a student is studying: their active or completed
 * course enrolments. A level-less enrolment takes the level-less syllabus,
 * matching how mam_lms_course_outline() reads it. A student who exists only in
 * a LEGACY class has no course here; the class's cohort brings them across with
 * the console's "converge" action (CLAUDE.md gotcha #2) — guessing a course
 * from a class's free-text discipline would put a guitarist on piano lessons,
 * which is the NGG bug its own docblock records.
 */
function mam_lesson_student_courses(int $studentId): array {
    return mam_db_all(
        "SELECT DISTINCT ce.course_id, ce.level_id, c.code AS course_code, c.name AS course_name, l.name AS level_name
           FROM course_enrollments ce JOIN courses c ON c.id = ce.course_id LEFT JOIN levels l ON l.id = ce.level_id
          WHERE ce.student_id = ? AND ce.status IN ('active','completed')
          ORDER BY c.name", [$studentId]);
}

/** May this student see this lesson? Published, and on a course they study at that level. */
function mam_lesson_student_can_open(int $studentId, array $lesson): bool {
    if (($lesson['status'] ?? '') !== 'published') return false;
    foreach (mam_lesson_student_courses($studentId) as $c) {
        if ((int) $c['course_id'] !== (int) $lesson['course_id']) continue;
        $lv = $c['level_id'] !== null ? (int) $c['level_id'] : null;
        if ($lv === ($lesson['level_id'] ?? null)) return true;
        // Placement (lessons-learn.php): the student's lesson level, the levels
        // below it as catch-up, and the next ones opened early by mastery.
        if (function_exists('mam_lesson_stage_map')) {
            $map = mam_lesson_stage_map($studentId, (int) $c['course_id'], mam_lesson_placed_level($studentId, (int) $c['course_id'], $lv));
            if (isset($map[(int) $lesson['id']])) return true;
        }
    }
    return false;
}

/** A progress row's state as a student or teacher reads it. `stale` = answered an older version of the questions. */
function mam_lesson_progress_view(?array $p, int $lessonVersion, int $passMark): array {
    if (!$p) return ['state' => 'not_started', 'best_pct' => null, 'attempts' => 0, 'pending' => false, 'stale' => false, 'completed_at' => null];
    $state = (string) $p['status'];
    return ['state' => $state, 'best_pct' => $p['best_pct'] !== null ? round((float) $p['best_pct']) : null,
        'attempts' => (int) $p['attempts'], 'pending' => (int) $p['pending'] === 1,
        'stale' => $state !== 'completed' && (int) $p['attempts'] > 0 && (int) $p['module_version'] !== $lessonVersion,
        'ticked' => $p['confirmed_by'] !== null && (int) $p['attempts'] === 0,
        'completed_at' => $p['completed_at'], 'passed_mark' => $passMark];
}

/**
 * A student's lessons, course by course, with where they stand on each. One
 * query for the lessons and one for the progress — never one per lesson.
 */
function mam_lesson_student_board(int $studentId): array {
    $courses = mam_lesson_student_courses($studentId);
    if (!$courses) return [];
    $prog = [];
    foreach (mam_db_all('SELECT * FROM module_progress WHERE student_id = ?', [$studentId]) as $p) $prog[(int) $p['module_id']] = $p;
    $counts = [];
    foreach (mam_db_all("SELECT module_id, COUNT(*) n FROM module_checks GROUP BY module_id") as $r) $counts[(int) $r['module_id']] = (int) $r['n'];
    $sched = function_exists('mam_lesson_schedule') ? mam_lesson_schedule() : null;
    $today = gmdate('Y-m-d');
    $out = [];
    foreach ($courses as $c) {
        $cid = (int) $c['course_id'];
        $lv = $c['level_id'] !== null ? (int) $c['level_id'] : null;
        /* Placement decides which lessons are the student's, and how each one
           counts: `current` (their level, due on the schedule), `catchup` (a
           level below, self-paced, never overdue) and `early` (a level above,
           opened by finishing MAM_LESSON_ADVANCE_PCT of the one below). */
        $placed = function_exists('mam_lesson_placed_level') ? mam_lesson_placed_level($studentId, $cid, $lv) : $lv;
        $map = function_exists('mam_lesson_stage_map') ? mam_lesson_stage_map($studentId, $cid, $placed) : [];
        if (!$map) {                                                       // no levels on the course: the old rule
            foreach (($lv === null
                ? mam_db_all("SELECT id FROM modules WHERE course_id = ? AND level_id IS NULL AND status = 'published'", [$cid])
                : mam_db_all("SELECT id FROM modules WHERE course_id = ? AND level_id = ? AND status = 'published'", [$cid, $lv])) as $r) $map[(int) $r['id']] = '';
        }
        $rows = [];
        if ($map) {
            $ph = implode(',', array_fill(0, count($map), '?'));
            $rows = mam_db_all("SELECT m.*, lv.name AS level_name, lv.sort_order AS level_sort FROM modules m LEFT JOIN levels lv ON lv.id = m.level_id
                                 WHERE m.id IN ($ph) ORDER BY COALESCE(lv.sort_order, 0), lv.id, m.week", array_keys($map));
        }
        $enrolledAt = (string) (mam_db_row("SELECT COALESCE(approved_at, created_at) t FROM course_enrollments WHERE student_id = ? AND course_id = ?
                                              AND status IN ('active','completed') ORDER BY id DESC LIMIT 1", [$studentId, $cid])['t'] ?? '');
        $lessons = []; $done = 0; $own = 0;
        foreach ($rows as $m) {
            $stage = $map[(int) $m['id']] ?? '';
            $view = mam_lesson_progress_view($prog[(int) $m['id']] ?? null, (int) ($m['version'] ?? 1), (int) ($m['pass_mark'] ?? 60));
            $counts_own = $stage !== 'early';
            if ($counts_own) { $own++; if ($view['state'] === 'completed') $done++; }
            $due = null; $late = false;
            if ($sched && $stage !== 'catchup' && $stage !== 'early' && $view['state'] !== 'completed') {
                $due = mam_lesson_due_at($sched, mam_lesson_clock_start($studentId, $cid, $enrolledAt ?: null), (int) $m['week']);
                $late = $due !== null && $due < $today;
            }
            $lessons[] = ['id' => (int) $m['id'], 'week' => (int) $m['week'], 'kind' => (string) $m['kind'], 'title' => (string) $m['title'],
                'summary' => (string) ($m['summary'] ?? ''), 'questions' => $counts[(int) $m['id']] ?? 0,
                'syllabus_only' => ($counts[(int) $m['id']] ?? 0) === 0, 'stage' => $stage,
                'level_name' => $m['level_name'], 'due' => $due, 'late' => $late] + $view;
        }
        $tier = function_exists('mam_lesson_tier') ? mam_lesson_tier($studentId, $cid) : null;
        $placedName = $placed !== null ? (string) (mam_db_row('SELECT name FROM levels WHERE id = ?', [$placed])['name'] ?? '') : '';
        $out[] = ['course_id' => $cid, 'placed_level_id' => $placed, 'course_code' => $c['course_code'], 'course_name' => $c['course_name'],
            'level_name' => $placedName !== '' ? $placedName : $c['level_name'],
            'done' => $done, 'total' => $own, 'lessons' => $lessons, 'tier' => $tier];
    }
    return $out;
}

/* ─── A student working through a lesson ───────────────────────────────── */

/**
 * Open a lesson: start it (once — a double tap is harmless) and return the
 * student's copy, their progress and what they may be told about their last
 * attempt. Throws RuntimeException('not_found'|'not_yours').
 */
function mam_lesson_open(int $studentId, int $lessonId): array {
    $l = mam_lesson_get($lessonId, false);
    if (!$l) throw new RuntimeException('not_found');
    if (!mam_lesson_student_can_open($studentId, $l)) throw new RuntimeException('not_yours');
    if (!mam_db_row('SELECT 1 FROM module_progress WHERE module_id = ? AND student_id = ?', [$lessonId, $studentId])) {
        try {
            mam_db_insert('module_progress', ['module_id' => $lessonId, 'student_id' => $studentId, 'status' => 'started',
                'module_version' => $l['version'], 'started_at' => mam_lesson_now(), 'updated_at' => mam_lesson_now()]);
        } catch (Throwable $t) { /* the UNIQUE index caught a second tap: already started */ }
    }
    $p = mam_db_row('SELECT * FROM module_progress WHERE module_id = ? AND student_id = ?', [$lessonId, $studentId]);
    $l['progress'] = mam_lesson_progress_view($p, $l['version'], $l['pass_mark']);
    $l['review'] = $p ? mam_lesson_review($l['id'], $p) : null;
    $l['syllabus_only'] = !$l['checks'];
    if (function_exists('mam_lesson_touch')) {
        mam_lesson_touch($studentId, $lessonId);
        $n = mam_lesson_note_row($studentId, $lessonId);
        $l['note'] = (string) ($n['note'] ?? '');
        $l['saved'] = (int) ($n['saved'] ?? 0) === 1;
        $l['feedback_mine'] = mam_lesson_feedback_mine($studentId, $lessonId);
        $l['feels'] = MAM_LESSON_FEELS;
    }
    if (function_exists('mam_lesson_helper_on')) {
        $l['helper'] = mam_lesson_helper_on() ? ['left' => max(0, MAM_HELPER_DAILY - mam_lesson_helper_used($studentId))] : null;
        $l['exam_open'] = (bool) mam_exam_open_sitting($studentId);
    }
    return $l;
}

/** May the right answers be shown yet? After a pass, or after MAM_LESSON_REVEAL_AFTER tries. */
function mam_lesson_may_reveal(array $p): bool {
    return ($p['status'] ?? '') === 'completed' || (int) ($p['attempts'] ?? 0) >= MAM_LESSON_REVEAL_AFTER;
}

/**
 * What a student is told about their last attempt: per question, right or wrong
 * (or waiting for a teacher), the "why that was tempting" line for a wrong
 * choice, the teacher's comment — and the right answer ONLY once they may be
 * shown it.
 */
function mam_lesson_review(int $lessonId, array $p): ?array {
    if ((int) $p['attempts'] === 0 && $p['marks'] === null) return null;
    $marks = json_decode((string) ($p['marks'] ?? ''), true) ?: [];
    $answers = json_decode((string) ($p['answers'] ?? ''), true) ?: [];
    $reveal = mam_lesson_may_reveal($p);
    $items = [];
    foreach (mam_db_all('SELECT * FROM module_checks WHERE module_id = ? ORDER BY position, id', [$lessonId]) as $c) {
        $cid = (string) $c['id'];
        $mk = $marks[$cid] ?? null;
        $item = ['check_id' => (int) $c['id'], 'points' => (int) $c['points'],
            'awarded' => $mk['awarded'] ?? null, 'pending' => ($mk['awarded'] ?? null) === null,
            'right' => isset($mk['awarded']) && $mk['awarded'] !== null ? ((float) $mk['awarded'] >= (int) $c['points']) : null,
            'slip' => $mk['slip'] ?? null, 'comment' => $mk['comment'] ?? null, 'your_answer' => $answers[$cid] ?? null];
        if ($reveal) {
            if ($c['kind'] === 'mcq') $item['answer'] = (int) $c['answer'];
            if ($c['kind'] === 'order') $item['answer_order'] = mam_lesson_json_list($c['options']);
        }
        $items[] = $item;
    }
    return ['attempts' => (int) $p['attempts'], 'revealed' => $reveal, 'reveal_after' => MAM_LESSON_REVEAL_AFTER,
        'feedback' => (string) ($p['feedback'] ?? ''), 'items' => $items];
}

/**
 * Points for a put-in-order answer: the share of steps already in the right
 * relative order (the longest increasing run of original indices), and full
 * points ONLY for the exact order. $given is the original indices in the order
 * the student placed them.
 */
function mam_lesson_order_points(array $given, int $n, int $points): float {
    $given = array_values(array_map('intval', $given));
    if (count($given) !== $n || count(array_unique($given)) !== $n || min($given) < 0 || max($given) >= $n) return 0.0;
    if ($given === range(0, $n - 1)) return (float) $points;
    $tails = [];
    foreach ($given as $x) {
        $lo = 0; $hi = count($tails);
        while ($lo < $hi) { $mid = intdiv($lo + $hi, 2); if ($tails[$mid] < $x) $lo = $mid + 1; else $hi = $mid; }
        $tails[$lo] = $x;
    }
    $share = round($points * count($tails) / $n, 2);
    return min($share, max(0, $points - 0.01));                          // never full without the exact order
}

/** Totals over a marks map: [score, max, pending, pct]. A lesson worth no points scores 100%. */
function mam_lesson_totals(array $checks, array $marks): array {
    $score = 0.0; $max = 0.0; $pending = false;
    foreach ($checks as $c) {
        $max += (int) $c['points'];
        $a = $marks[(string) $c['id']]['awarded'] ?? null;
        if ($a === null) { $pending = true; continue; }
        $score += (float) $a;
    }
    $pct = $max > 0 ? round($score / $max * 100, 2) : 100.0;
    return [$score, $max, $pending, $pct];
}

/**
 * Submit answers to a lesson's questions. $answers maps question id → the
 * answer: an option index for multiple choice, a list of original step indices
 * for put-in-order, text for a written question, a link for evidence.
 * Every question needs an answer, and a link must be a real one, or nothing is
 * stored. Returns the review the student is shown. Throws RuntimeException
 * ('not_found'|'not_yours'|'no_questions') or InvalidArgumentException(sentence).
 */
function mam_lesson_submit(int $studentId, int $lessonId, array $answers): array {
    $l = mam_lesson_get($lessonId, true);
    if (!$l) throw new RuntimeException('not_found');
    if (!mam_lesson_student_can_open($studentId, $l)) throw new RuntimeException('not_yours');
    if (!$l['checks']) throw new RuntimeException('no_questions');

    $clean = []; $marks = []; $missing = [];
    foreach ($l['checks'] as $i => $c) {
        $cid = (string) $c['id']; $raw = $answers[$cid] ?? ($answers[$c['id']] ?? null);
        switch ($c['kind']) {
            case 'mcq':
                if ($raw === null || $raw === '' || !ctype_digit((string) $raw) || (int) $raw >= count($c['options'])) { $missing[] = $i + 1; break; }
                $pick = (int) $raw; $clean[$cid] = $pick;
                $ok = $pick === (int) $c['answer'];
                $marks[$cid] = ['awarded' => $ok ? (float) $c['points'] : 0.0, 'slip' => $ok ? null : (($c['whys'][$pick] ?? '') ?: null)];
                break;
            case 'order':
                $seq = is_array($raw) ? $raw : (is_string($raw) ? array_filter(explode(',', $raw), 'strlen') : []);
                if (count($seq) !== count($c['options'])) { $missing[] = $i + 1; break; }
                $clean[$cid] = array_map('intval', array_values($seq));
                $marks[$cid] = ['awarded' => mam_lesson_order_points($clean[$cid], count($c['options']), (int) $c['points'])];
                break;
            case 'evidence':
                $u = trim((string) $raw);
                if (!mam_lesson_url_ok($u)) { $missing[] = $i + 1; break; }
                $clean[$cid] = $u; $marks[$cid] = ['awarded' => null];
                break;
            default:                                                     // short, task, reflection: a person marks it
                $t = trim((string) $raw);
                if ($t === '') { $missing[] = $i + 1; break; }
                $clean[$cid] = mb_substr($t, 0, 8000); $marks[$cid] = ['awarded' => null];
        }
    }
    /* The assistant may mark a written answer (lesson-exams.php) — off unless
       the academy switched it on, never an evidence link, and held for a
       person when the words read as a child at risk. Only once every answer is
       in: an incomplete submission sends nothing anywhere. */
    if (!$missing && function_exists('mam_lesson_ai_mark')) {
        foreach ($l['checks'] as $c) {
            $cid = (string) $c['id'];
            if (!in_array($c['kind'], ['short', 'task', 'reflection'], true) || !isset($clean[$cid])) continue;
            $ai = mam_lesson_ai_mark($c, (string) $clean[$cid], $studentId, $lessonId);
            if ($ai !== null) $marks[$cid] = $ai;
        }
    }
    if ($missing) throw new InvalidArgumentException('Answer every question before you send it (question ' . implode(', ', $missing)
        . '). A recording or photo is sent as a link starting https://.');

    [$score, $max, $pending, $pct] = mam_lesson_totals($l['checks'], $marks);
    $now = mam_lesson_now();
    $p = mam_db_row('SELECT * FROM module_progress WHERE module_id = ? AND student_id = ?', [$lessonId, $studentId]);
    $passed = !$pending && $pct >= $l['pass_mark'];
    $wasDone = $p && $p['status'] === 'completed';
    $status = ($passed || $wasDone) ? 'completed' : ($pending ? 'submitted' : 'attempted');
    $best = $p && $p['best_pct'] !== null ? (float) $p['best_pct'] : null;
    if (!$pending) $best = max($best ?? 0.0, $pct);
    $row = ['status' => $status, 'module_version' => $l['version'], 'score' => $score, 'max_score' => $max, 'best_pct' => $best,
        'attempts' => ($p ? (int) $p['attempts'] : 0) + 1, 'answers' => json_encode($clean, JSON_UNESCAPED_UNICODE),
        'marks' => json_encode($marks, JSON_UNESCAPED_UNICODE), 'pending' => $pending ? 1 : 0, 'updated_at' => $now];
    if ($status === 'completed' && !($p['completed_at'] ?? null)) $row['completed_at'] = $now;
    $startedAt = $p['started_at'] ?? $now;
    if (strtotime($now . ' UTC') - strtotime($startedAt . ' UTC') >= MAM_LESSON_RECALL_DAYS * 86400) $row['reviewed_at'] = $now;
    if ($p) mam_db_update('module_progress', $row, ['id' => (int) $p['id']]);
    else mam_db_insert('module_progress', $row + ['module_id' => $lessonId, 'student_id' => $studentId, 'started_at' => $now]);

    /* The first attempt only feeds the ability estimate (adaptive.php): a second
       try after the answers are shown measures memory of the answer, not ability. */
    if ((int) $row['attempts'] === 1 && function_exists('mam_adaptive_learn')) {
        try { mam_adaptive_learn($studentId, (int) $l['course_id'], $l['checks'], $marks); } catch (Throwable $t) { mam_log('warn', 'adaptive_learn_failed', ['error' => $t->getMessage()]); }
    }
    $p = mam_db_row('SELECT * FROM module_progress WHERE module_id = ? AND student_id = ?', [$lessonId, $studentId]);
    if ($status === 'completed' && function_exists('mam_lesson_award')) {
        try { mam_lesson_award($studentId, (int) $l['course_id']); } catch (Throwable $t) { mam_log('warn', 'lesson_award_failed', ['error' => $t->getMessage()]); }
    }
    return ['progress' => mam_lesson_progress_view($p, $l['version'], $l['pass_mark']), 'review' => mam_lesson_review($lessonId, $p),
        'passed' => $passed, 'pct' => $pct, 'pending' => $pending];
}

/* ─── The teacher's side ───────────────────────────────────────────────── */

/**
 * Does this instructor teach this student on this course? Through the
 * enrolment's own instructor, or the cohort the enrolment sits in. Admins pass
 * the route's capability check instead and never reach this.
 */
function mam_lesson_instructor_teaches(int $instructorId, int $studentId, int $courseId): bool {
    return mam_db_row(
        "SELECT 1 FROM course_enrollments ce LEFT JOIN cohorts co ON co.id = ce.cohort_id
          WHERE ce.student_id = ? AND ce.course_id = ? AND ce.status IN ('active','completed')
            AND (ce.instructor_id = ? OR co.instructor_id = ?) LIMIT 1",
        [$studentId, $courseId, $instructorId, $instructorId]) !== null;
}

/**
 * Written answers waiting for a teacher, oldest first. $instructorId null = all
 * (an admin's view). The question text and the student's answers come with each
 * row, so marking needs no second request.
 */
function mam_lesson_marking_queue(?int $instructorId, int $limit = 100): array {
    $sql = "SELECT p.*, m.title, m.week, m.course_id, m.pass_mark, m.version AS lesson_version, c.name AS course_name, u.name AS student_name
              FROM module_progress p JOIN modules m ON m.id = p.module_id JOIN courses c ON c.id = m.course_id
              JOIN users u ON u.id = p.student_id
             WHERE (p.pending = 1 OR p.marks LIKE '%\"by\":\"ai\"%')";
    $args = [];
    if ($instructorId !== null) {
        $sql .= " AND EXISTS (SELECT 1 FROM course_enrollments ce LEFT JOIN cohorts co ON co.id = ce.cohort_id
                   WHERE ce.student_id = p.student_id AND ce.course_id = m.course_id AND ce.status IN ('active','completed')
                     AND (ce.instructor_id = ? OR co.instructor_id = ?))";
        $args = [$instructorId, $instructorId];
    }
    $sql .= ' ORDER BY p.pending DESC, p.updated_at, p.id LIMIT ' . max(1, min(500, $limit));
    $rows = mam_db_all($sql, $args);
    $out = [];
    foreach ($rows as $r) {
        $answers = json_decode((string) $r['answers'], true) ?: [];
        $marks = json_decode((string) $r['marks'], true) ?: [];
        $items = [];
        foreach (mam_db_all('SELECT * FROM module_checks WHERE module_id = ? ORDER BY position, id', [(int) $r['module_id']]) as $c) {
            $cid = (string) $c['id'];
            if (in_array($c['kind'], MAM_LESSON_AUTO_KINDS, true)) continue;
            $by = $marks[$cid]['by'] ?? null;
            if (($marks[$cid]['awarded'] ?? null) !== null && $by !== 'ai') continue;
            $items[] = ['check_id' => (int) $c['id'], 'kind' => $c['kind'], 'prompt' => $c['prompt'], 'rubric' => (string) ($c['rubric'] ?? ''),
                'points' => (int) $c['points'], 'answer' => $answers[$cid] ?? null,
                'ai_awarded' => $by === 'ai' ? $marks[$cid]['awarded'] : null, 'held' => !empty($marks[$cid]['hold'])];
        }
        if (!$items) continue;
        $out[] = ['progress_id' => (int) $r['id'], 'lesson_id' => (int) $r['module_id'], 'title' => $r['title'], 'week' => (int) $r['week'],
            'course_name' => $r['course_name'], 'student_id' => (int) $r['student_id'], 'student_name' => $r['student_name'],
            'submitted_at' => $r['updated_at'], 'items' => $items];
    }
    return $out;
}

/**
 * A teacher marks the written answers on one attempt. $awards maps question id →
 * points (clamped to that question's worth); $comments maps question id → a line
 * for the student; $feedback is one note for the whole attempt. Only questions
 * still waiting are marked here — a machine-marked one is not a teacher's to
 * change. When nothing is left waiting, the lesson completes or not on the pass
 * mark, and the student is told. Throws RuntimeException('not_found'|'nothing_pending').
 */
function mam_lesson_mark(int $markerId, int $progressId, array $awards, array $comments = [], string $feedback = ''): array {
    $p = mam_db_row('SELECT * FROM module_progress WHERE id = ?', [$progressId]);
    if (!$p) throw new RuntimeException('not_found');
    $aiMarked = strpos((string) $p['marks'], '"by":"ai"') !== false;
    if ((int) $p['pending'] !== 1 && !$aiMarked) throw new RuntimeException('nothing_pending');
    $l = mam_lesson_get((int) $p['module_id'], true);
    if (!$l) throw new RuntimeException('not_found');
    $marks = json_decode((string) $p['marks'], true) ?: [];
    $changed = 0;
    foreach ($l['checks'] as $c) {
        $cid = (string) $c['id'];
        // A machine-marked question is not a teacher's to change; a mark the
        // ASSISTANT gave always is.
        if (in_array($c['kind'], MAM_LESSON_AUTO_KINDS, true)) continue;
        if (($marks[$cid]['awarded'] ?? null) !== null && ($marks[$cid]['by'] ?? null) !== 'ai') continue;
        if (!array_key_exists($cid, $awards) && !array_key_exists($c['id'], $awards)) continue;
        $v = $awards[$cid] ?? $awards[$c['id']];
        if ($v === '' || $v === null) continue;
        $marks[$cid] = ['awarded' => (float) max(0, min((int) $c['points'], (float) $v)), 'by' => 'teacher',
            'comment' => mb_substr(trim((string) ($comments[$cid] ?? ($comments[$c['id']] ?? ''))), 0, 1000) ?: null];
        $changed++;
    }
    if ($changed === 0) throw new InvalidArgumentException('Give a mark for at least one answer.');
    [$score, $max, $pending, $pct] = mam_lesson_totals($l['checks'], $marks);
    $wasDone = $p['status'] === 'completed';
    $status = (!$pending && $pct >= $l['pass_mark']) || $wasDone ? 'completed' : ($pending ? 'submitted' : 'attempted');
    $row = ['marks' => json_encode($marks, JSON_UNESCAPED_UNICODE), 'score' => $score, 'max_score' => $max,
        'pending' => $pending ? 1 : 0, 'status' => $status, 'marked_by' => $markerId ?: null, 'updated_at' => mam_lesson_now()];
    if ($feedback !== '') $row['feedback'] = mb_substr(trim($feedback), 0, 2000);
    if (!$pending) $row['best_pct'] = max((float) ($p['best_pct'] ?? 0), $pct);
    if ($status === 'completed' && empty($p['completed_at'])) $row['completed_at'] = mam_lesson_now();
    mam_db_update('module_progress', $row, ['id' => $progressId]);
    mam_audit('lesson_mark', 'module_progress', $progressId, ['marked' => $changed, 'pending' => $pending, 'status' => $status]);
    if ($status === 'completed' && function_exists('mam_lesson_award')) {
        try { mam_lesson_award((int) $p['student_id'], (int) $l['course_id']); } catch (Throwable $t) { mam_log('warn', 'lesson_award_failed', ['error' => $t->getMessage()]); }
    }
    if (!$pending && function_exists('mam_notify')) {
        try {
            mam_notify((int) $p['student_id'], 'lesson.marked',
                $status === 'completed' ? 'Lesson marked: you passed “' . $l['title'] . '”' : 'Lesson marked: “' . $l['title'] . '”',
                $status === 'completed' ? null : 'Have another go — the lesson stays open.', '/portal/student/#learn');
        } catch (Throwable $t) { mam_log('warn', 'lesson_mark_notify_failed', ['error' => $t->getMessage()]); }
    }
    return ['status' => $status, 'pending' => $pending, 'pct' => $pct];
}

/**
 * A teacher ticks a lesson done for a student — it happened in the room — or
 * takes the tick back. A tick never overwrites what the student did online: an
 * untick removes only a row the tick made and nobody answered on, and a lesson
 * already completed online stays completed. Returns 'ticked'|'unticked'|'unchanged'.
 * Throws RuntimeException('not_found'|'done_online').
 */
function mam_lesson_confirm(int $staffId, int $lessonId, int $studentId, bool $done): string {
    if (!mam_db_row('SELECT 1 FROM modules WHERE id = ?', [$lessonId])) throw new RuntimeException('not_found');
    $p = mam_db_row('SELECT * FROM module_progress WHERE module_id = ? AND student_id = ?', [$lessonId, $studentId]);
    $now = mam_lesson_now();
    if ($done) {
        if ($p && $p['status'] === 'completed') return 'unchanged';
        $ver = (int) (mam_db_row('SELECT version FROM modules WHERE id = ?', [$lessonId])['version'] ?? 1);
        if ($p) mam_db_update('module_progress', ['status' => 'completed', 'completed_at' => $now, 'confirmed_by' => $staffId ?: null, 'updated_at' => $now], ['id' => (int) $p['id']]);
        else mam_db_insert('module_progress', ['module_id' => $lessonId, 'student_id' => $studentId, 'status' => 'completed', 'module_version' => $ver,
            'confirmed_by' => $staffId ?: null, 'started_at' => $now, 'completed_at' => $now, 'updated_at' => $now]);
        mam_audit('lesson_tick', 'module', $lessonId, ['student' => $studentId]);
        if (function_exists('mam_lesson_award')) {
            $cid = (int) (mam_db_row('SELECT course_id FROM modules WHERE id = ?', [$lessonId])['course_id'] ?? 0);
            try { mam_lesson_award($studentId, $cid); } catch (Throwable $t) { mam_log('warn', 'lesson_award_failed', ['error' => $t->getMessage()]); }
        }
        return 'ticked';
    }
    if (!$p) return 'unchanged';
    if ((int) $p['attempts'] > 0 || $p['confirmed_by'] === null) throw new RuntimeException('done_online');
    mam_db()->prepare('DELETE FROM module_progress WHERE id = ?')->execute([(int) $p['id']]);
    mam_audit('lesson_untick', 'module', $lessonId, ['student' => $studentId]);
    return 'unticked';
}

/**
 * A class's lessons against its students, for the teacher: one row per student,
 * one cell per published lesson. Two queries whatever the size of the class.
 */
function mam_lesson_class_board(int $courseId, ?int $levelId, array $studentIds): array {
    $lessons = $levelId === null
        ? mam_db_all("SELECT id, week, title, version, pass_mark FROM modules WHERE course_id = ? AND level_id IS NULL AND status = 'published' ORDER BY week", [$courseId])
        : mam_db_all("SELECT id, week, title, version, pass_mark FROM modules WHERE course_id = ? AND level_id = ? AND status = 'published' ORDER BY week", [$courseId, $levelId]);
    $studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds))));
    $grid = [];
    if ($lessons && $studentIds) {
        $lp = implode(',', array_fill(0, count($lessons), '?')); $sp = implode(',', array_fill(0, count($studentIds), '?'));
        foreach (mam_db_all("SELECT * FROM module_progress WHERE module_id IN ($lp) AND student_id IN ($sp)",
            array_merge(array_map(fn($l) => (int) $l['id'], $lessons), $studentIds)) as $p) {
            $grid[(int) $p['student_id']][(int) $p['module_id']] = $p;
        }
    }
    $rows = [];
    foreach ($studentIds as $sid) {
        $cells = []; $done = 0;
        foreach ($lessons as $l) {
            $v = mam_lesson_progress_view($grid[$sid][(int) $l['id']] ?? null, (int) $l['version'], (int) $l['pass_mark']);
            if ($v['state'] === 'completed') $done++;
            $cells[(int) $l['id']] = $v;
        }
        $rows[$sid] = ['done' => $done, 'cells' => $cells];
    }
    return ['lessons' => $lessons, 'students' => $rows];
}

/**
 * Lessons by course for the console: how many are drafts, published, retired,
 * how many students are on the course, and how far through they are. Grouped
 * queries only — the console reads this on every visit.
 */
function mam_lesson_course_report(): array {
    $courses = mam_db_all('SELECT id, code, name, active FROM courses ORDER BY sort_order, name');
    $byStatus = [];
    foreach (mam_db_all('SELECT course_id, status, COUNT(*) n FROM modules GROUP BY course_id, status') as $r) $byStatus[(int) $r['course_id']][(string) $r['status']] = (int) $r['n'];
    $students = [];
    foreach (mam_db_all("SELECT course_id, COUNT(DISTINCT student_id) n FROM course_enrollments WHERE status IN ('active','completed') GROUP BY course_id") as $r) $students[(int) $r['course_id']] = (int) $r['n'];
    $done = []; $pending = [];
    foreach (mam_db_all("SELECT m.course_id, SUM(CASE WHEN p.status = 'completed' THEN 1 ELSE 0 END) d, SUM(p.pending) w
                           FROM module_progress p JOIN modules m ON m.id = p.module_id GROUP BY m.course_id") as $r) {
        $done[(int) $r['course_id']] = (int) $r['d']; $pending[(int) $r['course_id']] = (int) $r['w'];
    }
    $out = [];
    foreach ($courses as $c) {
        $cid = (int) $c['id']; $s = $byStatus[$cid] ?? [];
        $pub = $s['published'] ?? 0; $stu = $students[$cid] ?? 0;
        $out[] = ['id' => $cid, 'code' => $c['code'], 'name' => $c['name'], 'active' => (int) $c['active'] === 1,
            'published' => $pub, 'draft' => $s['draft'] ?? 0, 'retired' => $s['retired'] ?? 0, 'students' => $stu,
            'completions' => $done[$cid] ?? 0, 'waiting' => $pending[$cid] ?? 0,
            // Completions over everything there is to complete, as a share. Null when there is nothing to divide by — not 0%.
            'completion_pct' => ($pub > 0 && $stu > 0) ? (int) round(100 * min(1, ($done[$cid] ?? 0) / ($pub * $stu))) : null];
    }
    return $out;
}
