<?php
/**
 * Class spaces (schema v99): a feed per class where students hand in work —
 * mostly videos of themselves playing — and teachers mark it, comment on a
 * moment in it, and keep the room safe. Ported in spirit from NGG's community,
 * with the three things NGG's lacked: a space belongs to a CLASS, a class can be
 * PRIVATE, and a submission is MARKED.
 *
 * Who is in a space:
 *   · a `class` space is everyone in its cohort (an active or completed
 *     course enrolment) plus its teachers, plus anybody added by hand;
 *   · a `private` space is only the people added by hand (and the cohort's
 *     teacher, who must be able to see the class they teach);
 *   · `space_members.status = 'removed'` takes somebody out of either, so a
 *     class space can still lose one disruptive member;
 *   · a guardian reads their child's spaces and writes nothing — not a post,
 *     not a comment, not a reaction. Their child's marks they see; nobody
 *     else's.
 *
 * The rules a refactor must not lose:
 *   · NO private messages, of any kind, between anybody. Everything said here
 *     is said in front of the class's teacher. That is the whole point of a
 *     class feed for children, and the reason there is no table for it.
 *   · A mark is private. Classmates see that a submission was reviewed, never
 *     the score or the feedback; the student, their guardians and the teachers
 *     do.
 *   · A video of a minor whose family has not given MEDIA consent is shown to
 *     the teacher only, whatever the task says — default-deny, as consent.php
 *     requires of every media surface.
 *   · Words are screened before anybody else sees them (mam_space_screen):
 *     words about harm hold the item AND open an urgent safeguarding concern;
 *     a phone number, an address, a link or "add me on…" holds it, because
 *     moving a child off a watched platform is the first step of grooming;
 *     unkind words hold it. Held means the author sees it waiting and a
 *     teacher decides.
 *   · With `review_media` on (the default) a student's video waits for a
 *     teacher before the class sees it. A teacher's own held item is decided by
 *     an admin, never by themselves.
 *   · Reports: "unsafe" holds the item at once and opens a concern; any three
 *     people reporting the same item hold it.
 *   · The future public community is a separate surface. Nothing here is ever
 *     shown to somebody who is not in the space, so turning that on cannot
 *     leak a class's videos.
 */
declare(strict_types=1);

const MAM_SPACE_BODY_MAX = 2000;
const MAM_SPACE_COMMENT_MAX = 800;
const MAM_SPACE_REPORTS_TO_HOLD = 3;
const MAM_SPACE_REACTIONS = ['clap', 'heart', 'star'];

/* ─── Screening words ───────────────────────────────────────────────────── */

/**
 * Screen words before anybody else sees them. Returns
 * ['hold' => null|reason, 'safeguarding' => bool]. Order matters: harm is
 * checked first and cannot be switched off.
 */
function mam_space_screen(string $text): array {
    $t = ' ' . mb_strtolower($text) . ' ';
    if ($t === '  ') return ['hold' => null, 'safeguarding' => false];
    if (function_exists('mam_lesson_safeguarding_hit') && mam_lesson_safeguarding_hit($text)) {
        return ['hold' => 'Held for the safeguarding officer to read.', 'safeguarding' => true];
    }
    $digits = preg_replace('/[^0-9]/', '', preg_replace('/\b(19|20)\d{2}\b/', '', $text));   // a year is not a phone number
    if (strlen((string) $digits) >= 9 && preg_match('/(\+?\d[\d\s().-]{8,}\d)/', $text)) return ['hold' => 'It looks like a phone number.', 'safeguarding' => false];
    if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $text)) return ['hold' => 'It looks like an email address.', 'safeguarding' => false];
    if (preg_match('#(https?://|www\.|\b[a-z0-9-]+\.(com|ng|net|org|io|me|ly|gg|tv)\b)#i', $text)) return ['hold' => 'It has a link in it.', 'safeguarding' => false];
    if (preg_match('/\b(whats\s?app|snap(chat)?|insta(gram)?|tik\s?tok|telegram|discord|facebook|messenger|dm me|text me|call me|my number|add me|follow me|meet (me|up)|my address|where do you live|send (me )?(a )?(pic|photo|picture))\b/i', $t)) {
        return ['hold' => 'It asks to talk somewhere else.', 'safeguarding' => false];
    }
    if (preg_match('/\b(stupid|idiot|dumb|ugly|loser|shut up|hate you|you suck|trash|useless|fat|moron|retard\w*)\b/i', $t)) {
        return ['hold' => 'Some of the words may be unkind.', 'safeguarding' => false];
    }
    return ['hold' => null, 'safeguarding' => false];
}

/* ─── Spaces ────────────────────────────────────────────────────────────── */

function mam_space_get(int $id): ?array {
    return mam_db_row("SELECT * FROM class_spaces WHERE id = ?", [$id]);
}

/** Create a space. A cohort has at most one; asking again returns it. */
function mam_space_create(string $title, string $visibility, ?int $by, ?int $cohortId = null, string $about = ''): int {
    $title = trim($title);
    if ($title === '' || mb_strlen($title) > 160) throw new InvalidArgumentException('Give the space a name of up to 160 characters.');
    if (!in_array($visibility, ['class', 'private'], true)) throw new InvalidArgumentException('A space is open to its class or private.');
    if ($cohortId) {
        if ($have = mam_db_row("SELECT id FROM class_spaces WHERE cohort_id = ?", [$cohortId])) return (int) $have['id'];
        if (!mam_db_row("SELECT id FROM cohorts WHERE id = ?", [$cohortId])) throw new InvalidArgumentException('That class does not exist.');
    } elseif ($visibility === 'class') {
        throw new InvalidArgumentException('A space with no class has nobody in it until people are added — make it private.');
    }
    $id = (int) mam_db_insert('class_spaces', ['cohort_id' => $cohortId ?: null, 'title' => $title, 'about' => mb_substr(trim($about), 0, 1000) ?: null,
        'visibility' => $visibility, 'created_by' => $by, 'created_at' => gmdate('Y-m-d H:i:s')]);
    mam_audit('space_created', 'class_space', $id, ['cohort' => $cohortId, 'visibility' => $visibility]);
    return $id;
}

/** The space for a cohort, made on first use with the cohort's own name. */
function mam_space_for_cohort(int $cohortId, ?int $by = null): int {
    if ($have = mam_db_row("SELECT id FROM class_spaces WHERE cohort_id = ?", [$cohortId])) return (int) $have['id'];
    $c = mam_db_row("SELECT title FROM cohorts WHERE id = ?", [$cohortId]);
    if (!$c) throw new InvalidArgumentException('That class does not exist.');
    return mam_space_create((string) ($c['title'] ?: 'Class ' . $cohortId), 'class', $by, $cohortId);
}

/** Change a space's settings. Teachers of it and admins only (checked by the caller via mam_space_role). */
function mam_space_update(int $id, array $in, int $by): void {
    $s = mam_space_get($id);
    if (!$s) throw new RuntimeException('not_found');
    $upd = ['updated_at' => gmdate('Y-m-d H:i:s')];
    if (isset($in['title'])) { $t = trim((string) $in['title']); if ($t === '') throw new InvalidArgumentException('A space needs a name.'); $upd['title'] = mb_substr($t, 0, 160); }
    if (array_key_exists('about', $in)) $upd['about'] = mb_substr(trim((string) $in['about']), 0, 1000) ?: null;
    if (isset($in['visibility'])) {
        $v = (string) $in['visibility'];
        if (!in_array($v, ['class', 'private'], true)) throw new InvalidArgumentException('A space is open to its class or private.');
        if ($v === 'class' && !$s['cohort_id']) throw new InvalidArgumentException('Only a space that belongs to a class can be open to it.');
        $upd['visibility'] = $v;
    }
    foreach (['review_media', 'students_comment'] as $k) if (isset($in[$k])) $upd[$k] = !empty($in[$k]) ? 1 : 0;
    if (isset($in['status']) && in_array($in['status'], ['open', 'archived'], true)) $upd['status'] = $in['status'];
    mam_db_update('class_spaces', $upd, ['id' => $id]);
    mam_audit('space_updated', 'class_space', $id, array_diff_key($upd, ['updated_at' => 1]) + ['by' => $by]);
}

/* ─── Who is in a space ─────────────────────────────────────────────────── */

/** Is this person a teacher of the space's cohort? */
function mam_space_teaches_cohort(int $cohortId, int $uid): bool {
    return mam_teaches($uid, $cohortId); // teaching.php — lead, assistant, or cover on its day
}

/** A student's role in a space from membership alone (never via a guardian). */
function mam_space_member_role(array $s, int $uid): ?string {
    $m = mam_db_row("SELECT role, status FROM space_members WHERE space_id = ? AND user_id = ?", [(int) $s['id'], $uid]);
    if ($m && $m['status'] === 'removed') return null;
    if ($m) return (string) $m['role'];
    $cid = (int) ($s['cohort_id'] ?? 0);
    if ($cid <= 0) return null;
    if (mam_space_teaches_cohort($cid, $uid)) {
        // An uncleared teacher of a class with children is not its teacher under `enforce` (vetting.php).
        return (!function_exists('mam_vetting_may_see_cohort') || mam_vetting_may_see_cohort($uid, $cid)) ? 'teacher' : null;
    }
    if ($s['visibility'] === 'class' && mam_db_row("SELECT 1 FROM course_enrollments WHERE cohort_id = ? AND student_id = ? AND status IN ('active','completed')", [$cid, $uid])) {
        return 'student';
    }
    return null;
}

/**
 * This person's role in a space: admin | teacher | student | guardian, or null.
 * A guardian is returned only where their scope reaches class spaces, and
 * carries the children it is through, because a guardian sees their own
 * child's marks and nobody else's.
 */
function mam_space_role(array $s, ?array $user): ?string {
    if (!$user) return null;
    $uid = (int) $user['id'];
    if (mam_user_has_role($user, 'admin')) return 'admin';
    $r = mam_space_member_role($s, $uid);
    if ($r !== null) return $r;
    if (mam_space_guardian_children($s, $uid)) return 'guardian';
    return null;
}

/** The children through whom a guardian reads this space. */
function mam_space_guardian_children(array $s, int $parentId): array {
    $kids = [];
    foreach (mam_db_all("SELECT student_id FROM parent_links WHERE parent_id = ?", [$parentId]) as $l) {
        $kid = (int) $l['student_id'];
        if (mam_space_member_role($s, $kid) === 'student' && mam_parent_scope_allows($parentId, $kid, 'class_space')) $kids[] = $kid;
    }
    return $kids;
}

function mam_space_is_staff(?string $role): bool { return $role === 'teacher' || $role === 'admin'; }

/**
 * Make sure each live class this person is in has its space, so a class's
 * space exists from the first time anybody in it looks — nobody has to
 * remember to open one. Cheap: one query, and an insert only for a class
 * that has none.
 */
function mam_space_ensure_for_user(array $user): void {
    $uid = (int) $user['id'];
    [$teach, $targs] = mam_teaching_cohort_sql('co.id', $uid);
    $rows = mam_db_all(
        "SELECT DISTINCT co.id FROM cohorts co
           LEFT JOIN class_spaces s ON s.cohort_id = co.id
          WHERE s.id IS NULL AND COALESCE(co.status, 'active') = 'active'
            AND ($teach
                 OR EXISTS (SELECT 1 FROM course_enrollments ce WHERE ce.cohort_id = co.id AND ce.status IN ('active','completed')
                             AND ce.student_id = ?))
          LIMIT 20", array_merge($targs, [$uid]));
    foreach ($rows as $r) {
        try { mam_space_for_cohort((int) $r['id']); } catch (Throwable $e) { /* a race with another request made it first */ }
    }
}

/** Every space this person can open, with their role in each. */
function mam_space_list_for(array $user): array {
    $uid = (int) $user['id'];
    $isAdmin = mam_user_has_role($user, 'admin');
    $ids = [];
    if ($isAdmin) {
        foreach (mam_db_all("SELECT id FROM class_spaces ORDER BY status, title") as $r) $ids[] = (int) $r['id'];
    } else {
        $people = [$uid];
        foreach (mam_db_all("SELECT student_id FROM parent_links WHERE parent_id = ?", [$uid]) as $l) $people[] = (int) $l['student_id'];
        [$teach, $targs] = mam_teaching_cohort_sql('s.cohort_id', $uid);
        $in = implode(',', array_fill(0, count($people), '?'));
        $rows = mam_db_all(
            "SELECT DISTINCT s.id FROM class_spaces s
               LEFT JOIN space_members m ON m.space_id = s.id AND m.user_id IN ($in) AND m.status = 'active'
               LEFT JOIN cohorts co ON co.id = s.cohort_id
               LEFT JOIN course_enrollments ce ON ce.cohort_id = s.cohort_id AND ce.student_id IN ($in) AND ce.status IN ('active','completed')
              WHERE m.id IS NOT NULL OR $teach OR (s.visibility = 'class' AND ce.id IS NOT NULL)",
            array_merge($people, $people, $targs));
        foreach ($rows as $r) $ids[] = (int) $r['id'];
    }
    $out = [];
    foreach (array_unique($ids) as $id) {
        $s = mam_space_get($id);
        $role = $s ? mam_space_role($s, $user) : null;
        if (!$s || !$role) continue;
        if ($s['status'] === 'archived' && !mam_space_is_staff($role)) continue;
        $out[] = mam_space_summary($s, $role, $uid);
    }
    usort($out, static fn($a, $b) => [$a['status'] === 'archived', $a['title']] <=> [$b['status'] === 'archived', $b['title']]);
    return $out;
}

function mam_space_summary(array $s, string $role, int $uid): array {
    $sid = (int) $s['id'];
    $open = mam_db_all("SELECT id, title, due_at FROM space_tasks WHERE space_id = ? AND status = 'open' ORDER BY COALESCE(due_at, '9999') LIMIT 3", [$sid]);
    $held = mam_space_is_staff($role)
        ? (int) (mam_db_row("SELECT (SELECT COUNT(*) FROM space_posts WHERE space_id = ? AND status = 'held')
                                 + (SELECT COUNT(*) FROM space_comments c JOIN space_posts p ON p.id = c.post_id WHERE p.space_id = ? AND c.status = 'held') AS n", [$sid, $sid])['n'] ?? 0)
        : 0;
    $toMark = mam_space_is_staff($role)
        ? (int) (mam_db_row("SELECT COUNT(*) AS n FROM space_posts WHERE space_id = ? AND kind = 'submission' AND status <> 'removed' AND reviewed_at IS NULL", [$sid])['n'] ?? 0)
        : 0;
    return ['id' => $sid, 'title' => (string) $s['title'], 'about' => (string) ($s['about'] ?? ''), 'visibility' => (string) $s['visibility'],
            'status' => (string) $s['status'], 'role' => $role, 'cohort_id' => $s['cohort_id'] ? (int) $s['cohort_id'] : null,
            'review_media' => (int) $s['review_media'] === 1, 'students_comment' => (int) $s['students_comment'] === 1,
            'members' => mam_space_member_count($s), 'tasks' => $open, 'held' => $held, 'to_mark' => $toMark];
}

/** Everyone in a space, for the members list (names only — no contact details, ever). */
function mam_space_members(array $s): array {
    $sid = (int) $s['id']; $cid = (int) ($s['cohort_id'] ?? 0);
    $people = [];
    if ($cid > 0) {
        // The class's current staff (teaching.php), not every teacher ever named on an enrolment.
        foreach (mam_cohort_staff($cid) as $r) {
            if (!mam_teaches((int) $r['instructor_id'], $cid)) continue; // a cover only on their day
            $people[(int) $r['instructor_id']] = ['id' => (int) $r['instructor_id'], 'name' => (string) $r['instructor_name'], 'role' => 'teacher', 'how' => 'class'];
        }
        if ($lead = mam_cohort_lead_id($cid)) {
            $people[$lead] ??= ['id' => $lead, 'name' => (string) (mam_db_row('SELECT name FROM users WHERE id = ?', [$lead])['name'] ?? ''), 'role' => 'teacher', 'how' => 'class'];
        }
        if ($s['visibility'] === 'class') {
            foreach (mam_db_all("SELECT DISTINCT u.id, u.name FROM course_enrollments ce JOIN users u ON u.id = ce.student_id
                                  WHERE ce.cohort_id = ? AND ce.status IN ('active','completed')", [$cid]) as $r) {
                $people[(int) $r['id']] ??= ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'role' => 'student', 'how' => 'class'];
            }
        }
    }
    foreach (mam_db_all("SELECT m.user_id, m.role, m.status, u.name FROM space_members m JOIN users u ON u.id = m.user_id WHERE m.space_id = ?", [$sid]) as $r) {
        if ($r['status'] === 'removed') { unset($people[(int) $r['user_id']]); continue; }
        $people[(int) $r['user_id']] = ['id' => (int) $r['user_id'], 'name' => (string) $r['name'], 'role' => (string) $r['role'], 'how' => 'added'];
    }
    $out = array_values($people);
    usort($out, static fn($a, $b) => [$a['role'] !== 'teacher', $a['name']] <=> [$b['role'] !== 'teacher', $b['name']]);
    return $out;
}

function mam_space_member_count(array $s): int { return count(mam_space_members($s)); }

/**
 * Add people. A teacher may add only students they teach (so a private space
 * cannot become a way for an adult to gather children they have no class with)
 * and other instructors; an admin may add anyone. Returns [added, refused].
 */
function mam_space_members_add(array $s, array $userIds, string $role, array $actor): array {
    $actorRole = mam_space_role($s, $actor);
    if (!mam_space_is_staff($actorRole)) throw new RuntimeException('forbidden');
    if (!in_array($role, ['student', 'teacher'], true)) $role = 'student';
    $added = 0; $refused = [];
    foreach (array_slice(array_unique(array_map('intval', $userIds)), 0, 200) as $uid) {
        $u = mam_db_row("SELECT id, name, role, status FROM users WHERE id = ?", [$uid]);
        if (!$u || $u['status'] !== 'active') { $refused[] = ['id' => $uid, 'why' => 'Not an active account.']; continue; }
        if ($role === 'teacher' && !mam_user_has_role($u, 'instructor') && !mam_user_has_role($u, 'admin')) { $refused[] = ['id' => $uid, 'why' => 'Only staff can be added as a teacher.']; continue; }
        if ($role === 'student' && !mam_user_has_role($u, 'student')) { $refused[] = ['id' => $uid, 'why' => 'Not a student.']; continue; }
        if ($actorRole === 'teacher' && $role === 'student' && !mam_media_student_reader($uid, $actor)) {
            $refused[] = ['id' => $uid, 'why' => 'You can add only students you teach.']; continue;
        }
        $have = mam_db_row("SELECT id FROM space_members WHERE space_id = ? AND user_id = ?", [(int) $s['id'], $uid]);
        if ($have) mam_db_update('space_members', ['status' => 'active', 'role' => $role, 'added_by' => (int) $actor['id']], ['id' => (int) $have['id']]);
        else mam_db_insert('space_members', ['space_id' => (int) $s['id'], 'user_id' => $uid, 'role' => $role, 'status' => 'active',
            'added_by' => (int) $actor['id'], 'created_at' => gmdate('Y-m-d H:i:s')]);
        $added++;
    }
    mam_audit('space_members_added', 'class_space', (int) $s['id'], ['added' => $added, 'refused' => count($refused), 'role' => $role, 'by' => (int) $actor['id']]);
    return ['added' => $added, 'refused' => $refused];
}

/** Take somebody out of a space (even a class space). Their work stays, for the record. */
function mam_space_member_remove(array $s, int $uid, array $actor): void {
    if (!mam_space_is_staff(mam_space_role($s, $actor))) throw new RuntimeException('forbidden');
    if ($uid === (int) $actor['id']) throw new InvalidArgumentException('You cannot remove yourself.');
    $have = mam_db_row("SELECT id FROM space_members WHERE space_id = ? AND user_id = ?", [(int) $s['id'], $uid]);
    if ($have) mam_db_update('space_members', ['status' => 'removed', 'added_by' => (int) $actor['id']], ['id' => (int) $have['id']]);
    else mam_db_insert('space_members', ['space_id' => (int) $s['id'], 'user_id' => $uid, 'role' => 'student', 'status' => 'removed',
        'added_by' => (int) $actor['id'], 'created_at' => gmdate('Y-m-d H:i:s')]);
    mam_audit('space_member_removed', 'class_space', (int) $s['id'], ['user' => $uid, 'by' => (int) $actor['id']]);
}

/* ─── Tasks ─────────────────────────────────────────────────────────────── */

function mam_space_task_save(array $s, array $in, array $actor): int {
    if (!mam_space_is_staff(mam_space_role($s, $actor))) throw new RuntimeException('forbidden');
    $title = trim((string) ($in['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 160) throw new InvalidArgumentException('Give the task a title of up to 160 characters.');
    $due = trim((string) ($in['due_at'] ?? ''));
    if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', str_replace('T', ' ', $due))) throw new InvalidArgumentException('Give the due date as a date.');
    $row = ['title' => $title, 'brief' => mb_substr(trim((string) ($in['brief'] ?? '')), 0, 2000) ?: null,
        'wants' => in_array($in['wants'] ?? '', ['video', 'audio', 'any'], true) ? $in['wants'] : 'video',
        'share' => ($in['share'] ?? '') === 'teacher' ? 'teacher' : 'class',
        'points' => max(1, min(100, (int) ($in['points'] ?? 10))),
        'due_at' => $due !== '' ? str_replace('T', ' ', $due) : null];
    $id = (int) ($in['id'] ?? 0);
    if ($id > 0) {
        $t = mam_db_row("SELECT * FROM space_tasks WHERE id = ? AND space_id = ?", [$id, (int) $s['id']]);
        if (!$t) throw new RuntimeException('not_found');
        if (isset($in['status']) && in_array($in['status'], ['open', 'closed'], true)) $row['status'] = $in['status'];
        mam_db_update('space_tasks', $row, ['id' => $id]);
    } else {
        $id = (int) mam_db_insert('space_tasks', $row + ['space_id' => (int) $s['id'], 'status' => 'open', 'created_by' => (int) $actor['id'], 'created_at' => gmdate('Y-m-d H:i:s')]);
        mam_space_notify_class($s, 'New task: ' . $title, $row['due_at'] ? 'Due ' . substr((string) $row['due_at'], 0, 10) . '.' : null, (int) $actor['id']);
    }
    mam_audit('space_task_saved', 'space_task', $id, ['space' => (int) $s['id']]);
    return $id;
}

/** The tasks in a space, each with this viewer's own submission state where they are a student. */
function mam_space_tasks(array $s, array $user, string $role): array {
    $rows = mam_db_all("SELECT * FROM space_tasks WHERE space_id = ? ORDER BY CASE WHEN status = 'open' THEN 0 ELSE 1 END, COALESCE(due_at, '9999'), id DESC", [(int) $s['id']]);
    $uid = (int) $user['id']; $now = gmdate('Y-m-d H:i:s');
    foreach ($rows as &$t) {
        $t['id'] = (int) $t['id']; $t['points'] = (int) $t['points'];
        $t['overdue'] = $t['status'] === 'open' && $t['due_at'] && $t['due_at'] < $now;
        if ($role === 'student') {
            $mine = mam_db_row("SELECT id, status, reviewed_at, score, max_score FROM space_posts WHERE task_id = ? AND author_id = ? AND status <> 'removed' ORDER BY id DESC", [$t['id'], $uid]);
            $t['mine'] = $mine ? ['post_id' => (int) $mine['id'], 'status' => $mine['status'], 'reviewed' => $mine['reviewed_at'] !== null,
                'score' => $mine['score'] !== null ? (int) $mine['score'] : null, 'max' => $mine['max_score'] !== null ? (int) $mine['max_score'] : null] : null;
        } elseif (mam_space_is_staff($role)) {
            $t['handed_in'] = (int) (mam_db_row("SELECT COUNT(DISTINCT author_id) AS n FROM space_posts WHERE task_id = ? AND status <> 'removed'", [$t['id']])['n'] ?? 0);
            $t['to_mark'] = (int) (mam_db_row("SELECT COUNT(*) AS n FROM space_posts WHERE task_id = ? AND status <> 'removed' AND reviewed_at IS NULL", [$t['id']])['n'] ?? 0);
        }
    }
    return $rows;
}

/* ─── Posts ─────────────────────────────────────────────────────────────── */

/**
 * Post to a space. $in: kind (post|submission|notice), body, media_id?, task_id?.
 * Returns ['id', 'status', 'audience', 'note'] — the note says, in a sentence,
 * why a post is waiting or who will see it.
 */
function mam_space_post(array $s, array $user, array $in): array {
    $role = mam_space_role($s, $user);
    if (!$role || $role === 'guardian') throw new RuntimeException('forbidden');
    if ($s['status'] !== 'open') throw new InvalidArgumentException('This space is archived.');
    $uid = (int) $user['id'];
    $staff = mam_space_is_staff($role);
    $kind = in_array($in['kind'] ?? '', ['post', 'submission', 'notice'], true) ? (string) $in['kind'] : 'post';
    if ($kind === 'notice' && !$staff) $kind = 'post';
    if ($kind === 'submission' && $staff) throw new InvalidArgumentException('Teachers set tasks; students hand work in.');
    $body = trim((string) ($in['body'] ?? ''));
    if (mb_strlen($body) > MAM_SPACE_BODY_MAX) throw new InvalidArgumentException('Keep a post under ' . MAM_SPACE_BODY_MAX . ' characters.');
    $mediaId = (int) ($in['media_id'] ?? 0);
    $media = null;
    if ($mediaId > 0) {
        $media = mam_db_row("SELECT * FROM media_objects WHERE id = ? AND owner_id = ? AND status = 'uploaded' AND purpose = 'community'", [$mediaId, $uid]);
        if (!$media) throw new InvalidArgumentException('That file has not finished uploading.');
        if ($media['scope_type'] && !($media['scope_type'] === 'space' && (int) $media['scope_id'] === (int) $s['id'])) throw new InvalidArgumentException('That file is already used somewhere else.');
    }
    if ($body === '' && !$media) throw new InvalidArgumentException('Write something or add a file.');

    $task = null; $audience = 'class'; $notes = [];
    if ($kind === 'submission') {
        $task = mam_db_row("SELECT * FROM space_tasks WHERE id = ? AND space_id = ?", [(int) ($in['task_id'] ?? 0), (int) $s['id']]);
        if (!$task) throw new InvalidArgumentException('Choose the task this is for.');
        if ($task['status'] !== 'open') throw new InvalidArgumentException('That task is closed.');
        if (!$media) throw new InvalidArgumentException('Add your ' . ($task['wants'] === 'audio' ? 'recording' : ($task['wants'] === 'any' ? 'file' : 'video')) . '.');
        if ($task['wants'] === 'video' && $media['kind'] !== 'video') throw new InvalidArgumentException('This task asks for a video.');
        if ($task['wants'] === 'audio' && !in_array($media['kind'], ['audio', 'video'], true)) throw new InvalidArgumentException('This task asks for a recording.');
        $audience = $task['share'] === 'teacher' ? 'teacher' : 'class';
        if ($audience === 'teacher') $notes[] = 'Only your teacher will see it.';
    }
    if (!$staff && $media && in_array($media['kind'], ['video', 'image'], true) && mam_space_needs_media_consent($uid)) {
        $audience = 'teacher';
        $notes[] = 'Only your teacher will see your ' . $media['kind'] . ', because your family has not yet agreed to class videos and photos.';
    }

    $status = 'visible'; $holdReason = null;
    $scr = mam_space_screen($body);
    if ($scr['hold'] !== null) { $status = 'held'; $holdReason = $scr['hold']; }
    elseif (!$staff && $media && (int) $s['review_media'] === 1 && in_array($media['kind'], ['video', 'image', 'audio'], true)) {
        $status = 'held'; $holdReason = 'review';
    }

    // A resubmission before it is marked replaces the earlier one; after marking it is a new attempt.
    $existing = $task ? mam_db_row("SELECT id FROM space_posts WHERE task_id = ? AND author_id = ? AND status <> 'removed' AND reviewed_at IS NULL ORDER BY id DESC", [(int) $task['id'], $uid]) : null;
    $row = ['body' => $body !== '' ? $body : null, 'media_id' => $media ? $mediaId : null, 'audience' => $audience,
            'status' => $status, 'hold_reason' => $holdReason, 'max_score' => $task ? (int) $task['points'] : null];
    if ($existing) {
        $id = (int) $existing['id'];
        $old = mam_db_row("SELECT media_id FROM space_posts WHERE id = ?", [$id]);
        mam_db_update('space_posts', $row + ['edited_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
        if ($old && $old['media_id'] && (int) $old['media_id'] !== $mediaId) mam_media_delete((int) $old['media_id']);
        $notes[] = 'This replaces what you handed in before.';
    } else {
        $id = (int) mam_db_insert('space_posts', $row + ['space_id' => (int) $s['id'], 'author_id' => $uid, 'kind' => $kind,
            'task_id' => $task ? (int) $task['id'] : null, 'created_at' => gmdate('Y-m-d H:i:s')]);
    }
    if ($media) mam_media_attach($mediaId, $uid, 'space', (int) $s['id']);
    if ($scr['safeguarding']) mam_sg_auto($uid, 'post', $id, 'wellbeing', 'Words in a class post were held for you to read.');
    if ($scr['safeguarding']) $notes[] = mam_sg_help_text(true);
    elseif ($status === 'held') $notes[] = $holdReason === 'review' ? 'A teacher will check it before the class sees it.' : 'It is waiting for a teacher. ' . $holdReason;
    if ($kind === 'notice' && $status === 'visible') mam_space_notify_class($s, 'Notice in ' . $s['title'], mb_substr($body, 0, 140), $uid);
    if ($kind === 'submission') mam_space_notify_teachers($s, 'Work handed in: ' . ($task['title'] ?? ''), (string) ($user['name'] ?? 'A student'), $id);
    mam_audit('space_post', 'space_post', $id, ['space' => (int) $s['id'], 'kind' => $kind, 'status' => $status]);
    return ['id' => $id, 'status' => $status, 'audience' => $audience, 'note' => implode(' ', $notes)];
}

/** Does this student need media consent they do not have? Adults (age known) do not. */
function mam_space_needs_media_consent(int $uid): bool {
    $minor = function_exists('mam_vetting_student_is_minor') ? mam_vetting_student_is_minor($uid) : null;
    if ($minor === false) return false;
    return !(function_exists('mam_consent_allows') && mam_consent_allows($uid, 'media'));
}

/**
 * Can this viewer see this post at all? Visible class posts: anybody in the
 * space. Teacher-only posts and held or removed posts: the author, the
 * space's staff, and a guardian of the author.
 */
function mam_space_post_visible(array $p, string $role, int $uid, array $kids): bool {
    if (mam_space_is_staff($role)) return true;
    $author = (int) ($p['author_id'] ?? 0);
    $own = $author === $uid || in_array($author, $kids, true);
    if ($p['status'] !== 'visible') return $own && $p['status'] === 'held';
    if ($p['audience'] === 'teacher') return $own;
    return true;
}

/**
 * The feed: newest first, paged by id. Marks travel only to those who may see
 * them; a classmate sees "reviewed" and nothing more.
 */
function mam_space_feed(array $s, array $user, int $before = 0, int $limit = 20, int $taskId = 0): array {
    $role = mam_space_role($s, $user);
    if (!$role) throw new RuntimeException('forbidden');
    $uid = (int) $user['id'];
    $kids = $role === 'guardian' ? mam_space_guardian_children($s, $uid) : [];
    $limit = max(1, min(50, $limit));
    $where = 'p.space_id = ?'; $args = [(int) $s['id']];
    if ($before > 0) { $where .= ' AND p.id < ?'; $args[] = $before; }
    if ($taskId > 0) { $where .= ' AND p.task_id = ?'; $args[] = $taskId; }
    if (!mam_space_is_staff($role)) $where .= " AND p.status <> 'removed'";
    $rows = mam_db_all("SELECT p.*, u.name AS author_name, u.role AS author_role, t.title AS task_title
                          FROM space_posts p LEFT JOIN users u ON u.id = p.author_id LEFT JOIN space_tasks t ON t.id = p.task_id
                         WHERE $where ORDER BY p.id DESC LIMIT " . ($limit * 3), $args);
    $out = [];
    foreach ($rows as $p) {
        if (!mam_space_post_visible($p, $role, $uid, $kids)) continue;
        $out[] = mam_space_post_view($p, $role, $uid, $kids);
        if (count($out) >= $limit) break;
    }
    return ['posts' => $out, 'more' => count($rows) >= $limit * 3 || count($out) >= $limit,
            'next' => $out ? (int) end($out)['id'] : 0];
}

function mam_space_post_view(array $p, string $role, int $uid, array $kids): array {
    $pid = (int) $p['id'];
    $author = (int) ($p['author_id'] ?? 0);
    $mayMark = mam_space_is_staff($role) || $author === $uid || in_array($author, $kids, true);
    $media = $p['media_id'] ? mam_media_public_view(mam_db_row("SELECT * FROM media_objects WHERE id = ?", [(int) $p['media_id']])) : null;
    $reactions = [];
    foreach (mam_db_all("SELECT kind, COUNT(*) AS n, SUM(CASE WHEN user_id = ? THEN 1 ELSE 0 END) AS mine FROM space_reactions WHERE post_id = ? GROUP BY kind", [$uid, $pid]) as $r) {
        $reactions[(string) $r['kind']] = ['n' => (int) $r['n'], 'mine' => (int) $r['mine'] > 0];
    }
    $comments = [];
    foreach (mam_db_all("SELECT c.*, u.name AS author_name, u.role AS author_role FROM space_comments c LEFT JOIN users u ON u.id = c.author_id
                          WHERE c.post_id = ? ORDER BY c.id", [$pid]) as $c) {
        $ownC = (int) $c['author_id'] === $uid;
        if ($c['status'] === 'removed' && !mam_space_is_staff($role)) continue;
        if ($c['status'] === 'held' && !mam_space_is_staff($role) && !$ownC) continue;
        $comments[] = ['id' => (int) $c['id'], 'body' => (string) $c['body'], 'at_sec' => $c['at_sec'] !== null ? (float) $c['at_sec'] : null,
            'author' => (string) ($c['author_name'] ?? 'Somebody'), 'author_is_staff' => in_array($c['author_role'], ['instructor', 'admin'], true),
            'mine' => $ownC, 'status' => (string) $c['status'], 'hold_reason' => mam_space_is_staff($role) || $ownC ? $c['hold_reason'] : null,
            'created_at' => (string) $c['created_at']];
    }
    return ['id' => $pid, 'kind' => (string) $p['kind'], 'body' => (string) ($p['body'] ?? ''),
        'author' => (string) ($p['author_name'] ?? 'Somebody'), 'author_id' => $author,
        'author_is_staff' => in_array($p['author_role'] ?? '', ['instructor', 'admin'], true),
        'mine' => $author === $uid, 'task_id' => $p['task_id'] ? (int) $p['task_id'] : null, 'task_title' => $p['task_title'],
        'audience' => (string) $p['audience'], 'status' => (string) $p['status'],
        'hold_reason' => $p['status'] !== 'visible' && $mayMark ? (string) ($p['hold_reason'] ?? '') : null,
        'media' => $media, 'reactions' => $reactions, 'comments' => $comments,
        'reviewed' => $p['reviewed_at'] !== null,
        'score' => $mayMark && $p['score'] !== null ? (int) $p['score'] : null,
        'max_score' => $mayMark && $p['max_score'] !== null ? (int) $p['max_score'] : null,
        'feedback' => $mayMark ? $p['feedback'] : null,
        'created_at' => (string) $p['created_at'], 'edited_at' => $p['edited_at']];
}

/** Read one post (the media proxy uses it to decide who may play a file). */
function mam_space_post_can_view_media(int $mediaId, array $user): bool {
    $p = mam_db_row("SELECT * FROM space_posts WHERE media_id = ? ORDER BY id DESC", [$mediaId]);
    if (!$p) return false;
    $s = mam_space_get((int) $p['space_id']);
    $role = $s ? mam_space_role($s, $user) : null;
    if (!$role) return false;
    $kids = $role === 'guardian' ? mam_space_guardian_children($s, (int) $user['id']) : [];
    return mam_space_post_visible($p, $role, (int) $user['id'], $kids);
}

/** An author may delete their own post; staff may remove anybody's (it stays, hidden, for the record). */
function mam_space_post_remove(int $postId, array $user, string $note = ''): void {
    $p = mam_db_row("SELECT * FROM space_posts WHERE id = ?", [$postId]);
    $s = $p ? mam_space_get((int) $p['space_id']) : null;
    if (!$p || !$s) throw new RuntimeException('not_found');
    $role = mam_space_role($s, $user);
    $own = (int) $p['author_id'] === (int) $user['id'];
    if (!$own && !mam_space_is_staff($role)) throw new RuntimeException('forbidden');
    if ($own && $p['reviewed_at'] !== null && !mam_space_is_staff($role)) throw new InvalidArgumentException('Marked work stays on the record. Ask your teacher if it needs taking down.');
    mam_db_update('space_posts', ['status' => 'removed', 'hold_reason' => $own ? 'Deleted by its author.' : (mb_substr(trim($note), 0, 200) ?: 'Removed by a teacher.')], ['id' => $postId]);
    mam_audit('space_post_removed', 'space_post', $postId, ['by' => (int) $user['id'], 'own' => $own]);
}

/* ─── Comments, reactions, reports ──────────────────────────────────────── */

function mam_space_comment(int $postId, array $user, string $body, ?float $atSec = null): array {
    $p = mam_db_row("SELECT * FROM space_posts WHERE id = ?", [$postId]);
    $s = $p ? mam_space_get((int) $p['space_id']) : null;
    if (!$p || !$s) throw new RuntimeException('not_found');
    $role = mam_space_role($s, $user);
    if (!$role || $role === 'guardian') throw new RuntimeException('forbidden');
    $uid = (int) $user['id'];
    if (!mam_space_post_visible($p, $role, $uid, [])) throw new RuntimeException('not_found');
    if ($s['status'] !== 'open') throw new InvalidArgumentException('This space is archived.');
    $staff = mam_space_is_staff($role);
    if (!$staff && (int) $s['students_comment'] !== 1) throw new InvalidArgumentException('Comments in this space are for teachers.');
    if (!$staff && $p['status'] !== 'visible') throw new InvalidArgumentException('You can comment once a teacher has checked it.');
    $body = trim($body);
    if ($body === '') throw new InvalidArgumentException('Write a comment.');
    if (mb_strlen($body) > MAM_SPACE_COMMENT_MAX) throw new InvalidArgumentException('Keep a comment under ' . MAM_SPACE_COMMENT_MAX . ' characters.');
    $scr = mam_space_screen($body);
    $status = $scr['hold'] !== null ? 'held' : 'visible';
    $id = (int) mam_db_insert('space_comments', ['post_id' => $postId, 'author_id' => $uid, 'body' => $body,
        'at_sec' => $atSec !== null && $atSec >= 0 ? round($atSec, 2) : null, 'status' => $status, 'hold_reason' => $scr['hold'],
        'created_at' => gmdate('Y-m-d H:i:s')]);
    if ($scr['safeguarding']) mam_sg_auto($uid, 'comment', $id, 'wellbeing', 'Words in a class comment were held for you to read.');
    if ($status === 'visible' && (int) $p['author_id'] !== $uid && $p['author_id']) {
        try { mam_notify((int) $p['author_id'], 'space', ($staff ? 'Your teacher' : 'A classmate') . ' commented on your post', mb_substr($body, 0, 140), null); } catch (Throwable $e) {}
    }
    return ['id' => $id, 'status' => $status, 'note' => $scr['safeguarding'] ? mam_sg_help_text(true) : ($status === 'held' ? 'It is waiting for a teacher. ' . $scr['hold'] : '')];
}

function mam_space_react(int $postId, array $user, string $kind, bool $on): void {
    if (!in_array($kind, MAM_SPACE_REACTIONS, true)) throw new InvalidArgumentException('Unknown reaction.');
    $p = mam_db_row("SELECT * FROM space_posts WHERE id = ?", [$postId]);
    $s = $p ? mam_space_get((int) $p['space_id']) : null;
    if (!$p || !$s) throw new RuntimeException('not_found');
    $role = mam_space_role($s, $user);
    if (!$role || $role === 'guardian' || !mam_space_post_visible($p, $role, (int) $user['id'], []) || $p['status'] !== 'visible') throw new RuntimeException('forbidden');
    if ($on) {
        if (!mam_db_row("SELECT 1 FROM space_reactions WHERE post_id = ? AND user_id = ? AND kind = ?", [$postId, (int) $user['id'], $kind])) {
            mam_db_insert('space_reactions', ['post_id' => $postId, 'user_id' => (int) $user['id'], 'kind' => $kind, 'created_at' => gmdate('Y-m-d H:i:s')]);
        }
    } else {
        mam_db()->prepare("DELETE FROM space_reactions WHERE post_id = ? AND user_id = ? AND kind = ?")->execute([$postId, (int) $user['id'], $kind]);
    }
}

/**
 * Report a post or comment. "unsafe" holds it at once and opens a
 * safeguarding concern; otherwise three different people reporting the same
 * thing hold it. A guardian may report too — they can see the feed.
 */
function mam_space_report(array $user, string $type, int $id, string $reason, string $note = ''): array {
    if (!in_array($reason, ['unkind', 'unsafe', 'private_info', 'spam', 'other'], true)) $reason = 'other';
    $table = $type === 'comment' ? 'space_comments' : 'space_posts';
    $row = mam_db_row("SELECT * FROM $table WHERE id = ?", [$id]);
    if (!$row) throw new RuntimeException('not_found');
    $p = $type === 'comment' ? mam_db_row("SELECT * FROM space_posts WHERE id = ?", [(int) $row['post_id']]) : $row;
    $s = $p ? mam_space_get((int) $p['space_id']) : null;
    $role = $s ? mam_space_role($s, $user) : null;
    if (!$role) throw new RuntimeException('not_found');
    $col = $type === 'comment' ? 'comment_id' : 'post_id';
    if (mam_db_row("SELECT 1 FROM space_reports WHERE $col = ? AND reporter_id = ? AND status = 'open'", [$id, (int) $user['id']])) {
        return ['held' => $row['status'] === 'held', 'note' => 'You have already reported this. A teacher will look.'];
    }
    mam_db_insert('space_reports', [$col => $id, 'reporter_id' => (int) $user['id'], 'reason' => $reason,
        'note' => mb_substr(trim($note), 0, 1000) ?: null, 'status' => 'open', 'created_at' => gmdate('Y-m-d H:i:s')]);
    $count = (int) (mam_db_row("SELECT COUNT(DISTINCT reporter_id) AS n FROM space_reports WHERE $col = ? AND status = 'open'", [$id])['n'] ?? 0);
    $hold = $reason === 'unsafe' || $count >= MAM_SPACE_REPORTS_TO_HOLD;
    if ($hold && $row['status'] === 'visible') {
        mam_db_update($table, ['status' => 'held', 'hold_reason' => $reason === 'unsafe' ? 'Reported as unsafe.' : 'Reported by ' . $count . ' people.'], ['id' => $id]);
    }
    if ($reason === 'unsafe') {
        mam_sg_raise((int) $user['id'], ['category' => 'content', 'urgency' => 'urgent', 'about_id' => (int) ($row['author_id'] ?? 0),
            'ref_type' => $type === 'comment' ? 'comment' : 'post', 'ref_id' => $id,
            'body' => 'Reported as unsafe in a class space.' . (trim($note) !== '' ? ' They said: ' . mb_substr(trim($note), 0, 1000) : '')]);
    }
    mam_space_notify_teachers($s, 'Something was reported in ' . $s['title'], 'Open the space to look.', (int) $p['id']);
    mam_audit('space_reported', $type === 'comment' ? 'space_comment' : 'space_post', $id, ['reason' => $reason, 'held' => $hold]);
    return ['held' => $hold, 'note' => $hold ? 'Thank you. It is hidden until a teacher looks at it.' : 'Thank you. A teacher will look at it.'];
}

/* ─── Teachers: decide, mark ─────────────────────────────────────────────── */

/**
 * Approve or remove a held post or comment, or close its reports. A teacher
 * never decides their own held item — an admin does — so the check on adults
 * is not one the adult can wave through.
 */
function mam_space_moderate(array $user, string $type, int $id, string $decision, string $note = ''): void {
    if (!in_array($decision, ['approve', 'remove'], true)) throw new InvalidArgumentException('Approve or remove.');
    $table = $type === 'comment' ? 'space_comments' : 'space_posts';
    $row = mam_db_row("SELECT * FROM $table WHERE id = ?", [$id]);
    if (!$row) throw new RuntimeException('not_found');
    $p = $type === 'comment' ? mam_db_row("SELECT * FROM space_posts WHERE id = ?", [(int) $row['post_id']]) : $row;
    $s = mam_space_get((int) $p['space_id']);
    $role = mam_space_role($s, $user);
    if (!mam_space_is_staff($role)) throw new RuntimeException('forbidden');
    if ($role !== 'admin' && (int) $row['author_id'] === (int) $user['id']) throw new InvalidArgumentException('An admin decides your own held posts.');
    if ($decision === 'approve' && $row['status'] === 'held' && mam_db_row("SELECT 1 FROM safeguarding_concerns WHERE ref_type = ? AND ref_id = ? AND status <> 'closed'", [$type === 'comment' ? 'comment' : 'post', $id]) && $role !== 'admin' && !mam_sg_can_manage($user)) {
        throw new InvalidArgumentException('The safeguarding officer is looking at this one. They will decide.');
    }
    $new = $decision === 'approve' ? 'visible' : 'removed';
    mam_db_update($table, ['status' => $new, 'hold_reason' => $decision === 'remove' ? (mb_substr(trim($note), 0, 200) ?: 'Removed by a teacher.') : null], ['id' => $id]);
    $col = $type === 'comment' ? 'comment_id' : 'post_id';
    mam_db()->prepare("UPDATE space_reports SET status = 'closed', outcome = ?, handled_by = ?, handled_at = ? WHERE $col = ? AND status = 'open'")
        ->execute([$decision === 'approve' ? 'kept' : 'removed', (int) $user['id'], gmdate('Y-m-d H:i:s'), $id]);
    if ($row['author_id'] && (int) $row['author_id'] !== (int) $user['id']) {
        try { mam_notify((int) $row['author_id'], 'space', $decision === 'approve' ? 'Your post is up' : 'Your post was taken down',
            $decision === 'approve' ? 'Your class can see it now.' : (trim($note) !== '' ? mb_substr(trim($note), 0, 200) : 'Ask your teacher why.'), null); } catch (Throwable $e) {}
    }
    mam_audit('space_moderated', $type === 'comment' ? 'space_comment' : 'space_post', $id, ['decision' => $decision, 'by' => (int) $user['id']]);
}

/** Mark a submission: a score out of the task's points, and words. Teachers of the space only. */
function mam_space_review(int $postId, array $user, int $score, string $feedback): void {
    $p = mam_db_row("SELECT * FROM space_posts WHERE id = ?", [$postId]);
    $s = $p ? mam_space_get((int) $p['space_id']) : null;
    if (!$p || !$s) throw new RuntimeException('not_found');
    if (!mam_space_is_staff(mam_space_role($s, $user))) throw new RuntimeException('forbidden');
    if ($p['kind'] !== 'submission') throw new InvalidArgumentException('Only handed-in work is marked.');
    if ($p['status'] === 'removed') throw new InvalidArgumentException('That was taken down.');
    $max = (int) ($p['max_score'] ?: 10);
    if ($score < 0 || $score > $max) throw new InvalidArgumentException('Give a mark from 0 to ' . $max . '.');
    $feedback = trim($feedback);
    if (mb_strlen($feedback) < 3) throw new InvalidArgumentException('Say something about the work — a mark alone teaches nothing.');
    mam_db_update('space_posts', ['score' => $score, 'feedback' => mb_substr($feedback, 0, 3000), 'reviewed_by' => (int) $user['id'],
        'reviewed_at' => gmdate('Y-m-d H:i:s')], ['id' => $postId]);
    if ($p['author_id']) {
        try { mam_notify((int) $p['author_id'], 'space', 'Your work was marked', $score . ' out of ' . $max . '. Open it to read what your teacher said.', null); } catch (Throwable $e) {}
    }
    mam_audit('space_reviewed', 'space_post', $postId, ['score' => $score, 'max' => $max, 'by' => (int) $user['id']]);
}

/** What a teacher must decide now, across every space they teach (or all, for an admin). */
function mam_space_queue(array $user): array {
    $spaces = array_filter(mam_space_list_for($user), static fn($sp) => mam_space_is_staff($sp['role']));
    $ids = array_column($spaces, 'id');
    if (!$ids) return ['held' => [], 'reports' => [], 'to_mark' => []];
    $in = implode(',', array_fill(0, count($ids), '?'));
    // ⚠️ Every column ALIASED: a UNION's ORDER BY on SQLite must name result columns (CLAUDE.md gotcha #2).
    $held = mam_db_all("SELECT 'post' AS type, p.id AS id, p.space_id AS space_id, s.title AS space_title, p.body AS body, p.hold_reason AS hold_reason,
                               p.media_id AS media_id, p.author_id AS author_id, u.name AS author, p.created_at AS created_at
                          FROM space_posts p JOIN class_spaces s ON s.id = p.space_id LEFT JOIN users u ON u.id = p.author_id
                         WHERE p.space_id IN ($in) AND p.status = 'held'
                         UNION ALL
                        SELECT 'comment' AS type, c.id AS id, p.space_id AS space_id, s.title AS space_title, c.body AS body, c.hold_reason AS hold_reason,
                               NULL AS media_id, c.author_id AS author_id, u.name AS author, c.created_at AS created_at
                          FROM space_comments c JOIN space_posts p ON p.id = c.post_id JOIN class_spaces s ON s.id = p.space_id LEFT JOIN users u ON u.id = c.author_id
                         WHERE p.space_id IN ($in) AND c.status = 'held'
                         ORDER BY created_at, id", array_merge($ids, $ids));
    foreach ($held as &$h) {
        $h['media'] = $h['media_id'] ? mam_media_public_view(mam_db_row("SELECT * FROM media_objects WHERE id = ?", [(int) $h['media_id']])) : null;
        $h['own'] = (int) $h['author_id'] === (int) $user['id'];
    }
    $toMark = mam_db_all("SELECT p.id, p.space_id, s.title AS space_title, t.title AS task_title, u.name AS author, p.created_at
                            FROM space_posts p JOIN class_spaces s ON s.id = p.space_id LEFT JOIN space_tasks t ON t.id = p.task_id LEFT JOIN users u ON u.id = p.author_id
                           WHERE p.space_id IN ($in) AND p.kind = 'submission' AND p.status <> 'removed' AND p.reviewed_at IS NULL
                           ORDER BY p.created_at LIMIT 100", $ids);
    return ['held' => $held, 'to_mark' => $toMark];
}

/* ─── Space marks in the rest of the system (classes audit §3.10) ────────── */

/**
 * Handed-in space work waiting for a mark, from the classes this instructor
 * teaches (mam_teaches, via cohort_staff) — the instructor Marking screen's
 * `spaces` list. Marking from there posts the same `review` action to
 * /api/spaces, so it is the same mam_space_review() and the same record.
 */
function mam_space_marking_queue_for_instructor(int $instructorId, int $limit = 50): array {
    [$teach, $targs] = mam_teaching_cohort_sql('s.cohort_id', $instructorId);
    $rows = mam_db_all(
        "SELECT p.id, p.space_id, s.title AS space_title, t.title AS task_title, u.name AS student_name,
                COALESCE(p.max_score, t.points, 10) AS max_score, p.created_at AS submitted_at
           FROM space_posts p
           JOIN class_spaces s ON s.id = p.space_id
           LEFT JOIN space_tasks t ON t.id = p.task_id
           LEFT JOIN users u ON u.id = p.author_id
          WHERE $teach AND s.status <> 'archived'
            AND p.kind = 'submission' AND p.status <> 'removed' AND p.reviewed_at IS NULL
          ORDER BY p.created_at, p.id LIMIT " . max(1, min(200, $limit)), $targs);
    foreach ($rows as &$r) { $r['id'] = (int) $r['id']; $r['space_id'] = (int) $r['space_id']; $r['max_score'] = (int) $r['max_score']; }
    unset($r);
    return $rows;
}

/**
 * A student's OWN marked space work, newest first: task, mark out of what,
 * what the teacher said, when. Marks are private (rules above), so this takes
 * the student and nobody else's id; a guardian reaches it only through
 * mam_parent_child_detail(), under the 'reports' grant.
 */
function mam_space_marked_work(int $studentId, int $limit = 20): array {
    $rows = mam_db_all(
        "SELECT p.id, s.title AS space_title, COALESCE(t.title, 'Handed-in work') AS task_title,
                p.score, COALESCE(p.max_score, t.points, 10) AS max_score, p.feedback, p.reviewed_at AS marked_at
           FROM space_posts p
           JOIN class_spaces s ON s.id = p.space_id
           LEFT JOIN space_tasks t ON t.id = p.task_id
          WHERE p.author_id = ? AND p.kind = 'submission' AND p.status <> 'removed'
            AND p.reviewed_at IS NOT NULL AND p.score IS NOT NULL
          ORDER BY p.reviewed_at DESC, p.id DESC LIMIT " . max(1, min(100, $limit)), [$studentId]);
    foreach ($rows as &$r) { $r['id'] = (int) $r['id']; $r['score'] = (int) $r['score']; $r['max_score'] = (int) $r['max_score']; }
    unset($r);
    return $rows;
}

/* ─── Telling people ────────────────────────────────────────────────────── */

function mam_space_notify_class(array $s, string $title, ?string $body, int $exceptId): void {
    foreach (mam_space_members($s) as $m) {
        if ($m['id'] === $exceptId) continue;
        try { mam_notify($m['id'], 'space', $title, $body, null); } catch (Throwable $e) {}
    }
}

function mam_space_notify_teachers(array $s, string $title, ?string $body, int $postId): void {
    foreach (mam_space_members($s) as $m) {
        if ($m['role'] !== 'teacher') continue;
        try { mam_notify($m['id'], 'space', $title, $body, null); } catch (Throwable $e) {}
    }
}
