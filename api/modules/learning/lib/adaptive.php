<?php
/**
 * Adaptive assessment (schema v102): an ability estimate per student per
 * course, a difficulty per question, and exams that choose questions near
 * the student's level.
 *
 * The model is the simplest that is honest: a one-parameter logistic (Rasch)
 * model fitted online, Elo-style. A student has an ability θ and a question a
 * difficulty b on the same logit scale; the chance of a right answer is
 * 1 / (1 + e^-(θ − b)). After each machine-marked answer both move a little
 * towards what happened. A teacher's 1–5 difficulty is the starting b
 * ((d − 3) × 0.8), so a new question is placed sensibly before anybody has
 * answered it, and it is then learnt from answers.
 *
 * What it is for, and what it is NOT:
 *   · It chooses questions — spaced recall and the course trial pick from the
 *     ones near the student's level, with a spread either side, so a strong
 *     student is not bored and a struggling one is not buried.
 *   · It SUGGESTS a level to the teacher (move up, catch up), never moves a
 *     student by itself: a placement is a teacher's decision
 *     (mam_lesson_place) and stays one.
 *   · Only machine-marked answers (multiple choice, put in order) count, and
 *     only a FIRST attempt — a second try after the answers are shown measures
 *     memory of the answer. Nothing a person wrote or played is scored here.
 *   · It is never shown to a student as a number; θ is a measuring tool, not
 *     a grade. A teacher sees "about right / ready for more / needs support"
 *     with how many answers it rests on.
 */
declare(strict_types=1);

const MAM_ADAPT_K_STUDENT = 0.4;       // how far one answer moves a student
const MAM_ADAPT_K_ITEM    = 0.05;      // and a question (slower: many students answer it)
const MAM_ADAPT_MIN_N     = 15;        // answers before a suggestion is offered
const MAM_ADAPT_UP        = 1.0;       // θ above the level's questions by this → ready for more
const MAM_ADAPT_DOWN      = -1.0;      // below by this → needs support

function mam_adaptive_p(float $theta, float $b): float { return 1.0 / (1.0 + exp(-($theta - $b))); }

/** A question's difficulty: learnt where answered, else from the teacher's 1–5. */
function mam_adaptive_b(int $checkId): float {
    $c = mam_db_row('SELECT difficulty FROM module_checks WHERE id = ?', [$checkId]);
    $cal = mam_db_row('SELECT b FROM check_calibration WHERE check_id = ?', [$checkId]);
    if ($cal) return (float) $cal['b'];
    return ((int) ($c['difficulty'] ?? 3) - 3) * 0.8;
}

function mam_adaptive_theta(int $studentId, int $courseId): array {
    $r = mam_db_row('SELECT theta, answered FROM student_ability WHERE student_id = ? AND course_id = ?', [$studentId, $courseId]);
    return ['theta' => $r ? (float) $r['theta'] : 0.0, 'n' => $r ? (int) $r['answered'] : 0];
}

/**
 * Learn from a set of marked answers. $checks: rows with id, kind, points;
 * $marks: check id → ['awarded' => …]. Right means full points.
 */
function mam_adaptive_learn(int $studentId, int $courseId, array $checks, array $marks): void {
    $ab = mam_adaptive_theta($studentId, $courseId);
    $theta = $ab['theta']; $n = $ab['n']; $moved = 0;
    foreach ($checks as $c) {
        if (!in_array($c['kind'] ?? '', ['mcq', 'order'], true)) continue;
        $a = $marks[(string) $c['id']]['awarded'] ?? null;
        if ($a === null) continue;
        $right = (float) $a >= (float) $c['points'] ? 1.0 : 0.0;
        $b = mam_adaptive_b((int) $c['id']);
        $p = mam_adaptive_p($theta, $b);
        // Early answers move a student further than late ones, so a new student settles quickly.
        $k = MAM_ADAPT_K_STUDENT * max(0.35, 1.0 / sqrt(1 + $n / 10));
        $theta = max(-4.0, min(4.0, $theta + $k * ($right - $p)));
        $nb = max(-4.0, min(4.0, $b - MAM_ADAPT_K_ITEM * ($right - $p)));
        if (mam_db_row('SELECT 1 FROM check_calibration WHERE check_id = ?', [(int) $c['id']])) {
            mam_db()->prepare('UPDATE check_calibration SET b = ?, answered = answered + 1, correct = correct + ? WHERE check_id = ?')->execute([$nb, (int) $right, (int) $c['id']]);
        } else {
            mam_db_insert('check_calibration', ['check_id' => (int) $c['id'], 'b' => $nb, 'answered' => 1, 'correct' => (int) $right]);
        }
        $n++; $moved++;
    }
    if (!$moved) return;
    $row = ['theta' => round($theta, 4), 'answered' => $n, 'updated_at' => gmdate('Y-m-d H:i:s')];
    if (mam_db_row('SELECT 1 FROM student_ability WHERE student_id = ? AND course_id = ?', [$studentId, $courseId])) {
        mam_db_update('student_ability', $row, ['student_id' => $studentId, 'course_id' => $courseId]);
    } else {
        mam_db_insert('student_ability', $row + ['student_id' => $studentId, 'course_id' => $courseId]);
    }
}

/**
 * Choose $n questions from $pool for this student: most of them where the
 * chance of a right answer is near 70% (hard enough to learn from, easy
 * enough not to discourage), the rest a spread either side — and never the
 * same order twice.
 */
function mam_adaptive_pick(int $studentId, int $courseId, array $pool, int $n): array {
    $pool = array_values(array_unique(array_map('intval', $pool)));
    if (count($pool) <= $n) { shuffle($pool); return $pool; }
    $theta = mam_adaptive_theta($studentId, $courseId)['theta'];
    $scored = [];
    foreach ($pool as $id) {
        $p = mam_adaptive_p($theta, mam_adaptive_b($id));
        $scored[] = ['id' => $id, 'gap' => abs($p - 0.7) + mt_rand(0, 100) / 1000];
    }
    usort($scored, static fn($a, $b) => $a['gap'] <=> $b['gap']);
    $near = (int) ceil($n * 0.7);
    $out = array_column(array_slice($scored, 0, $near), 'id');
    $rest = array_column(array_slice($scored, $near), 'id');
    shuffle($rest);
    $out = array_merge($out, array_slice($rest, 0, $n - count($out)));
    shuffle($out);
    return $out;
}

/**
 * What the estimate suggests to a TEACHER for one student on one course:
 * ready_for_more | about_right | needs_support | not_enough — compared with
 * the questions at the level the student is placed at.
 */
function mam_adaptive_suggestion(int $studentId, int $courseId): array {
    $ab = mam_adaptive_theta($studentId, $courseId);
    if ($ab['n'] < MAM_ADAPT_MIN_N) {
        return ['key' => 'not_enough', 'words' => 'Not enough answered yet to say (' . $ab['n'] . ' of ' . MAM_ADAPT_MIN_N . ').', 'answered' => $ab['n']];
    }
    $enrol = mam_db_row("SELECT level_id FROM course_enrollments WHERE student_id = ? AND course_id = ? AND status IN ('active','completed') ORDER BY id DESC", [$studentId, $courseId]);
    $level = function_exists('mam_lesson_placed_level') ? mam_lesson_placed_level($studentId, $courseId, $enrol ? (int) $enrol['level_id'] : null) : null;
    $ids = array_column(mam_db_all("SELECT mc.id FROM module_checks mc JOIN modules m ON m.id = mc.module_id
                                     WHERE m.course_id = ? AND (m.level_id = ? OR ? IS NULL) AND mc.kind IN ('mcq','order')", [$courseId, $level, $level]), 'id');
    $bs = array_map(static fn($id) => mam_adaptive_b((int) $id), $ids);
    $levelB = $bs ? array_sum($bs) / count($bs) : 0.0;
    $gap = $ab['theta'] - $levelB;
    if ($gap >= MAM_ADAPT_UP) return ['key' => 'ready_for_more', 'words' => 'Getting the questions at this level right much more often than not — consider the next level.', 'answered' => $ab['n']];
    if ($gap <= MAM_ADAPT_DOWN) return ['key' => 'needs_support', 'words' => 'Finding this level\'s questions hard — catch-up lessons or a word in class may help.', 'answered' => $ab['n']];
    return ['key' => 'about_right', 'words' => 'About right for this level.', 'answered' => $ab['n']];
}
