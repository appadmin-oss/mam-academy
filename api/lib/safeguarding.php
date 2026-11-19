<?php
/**
 * Safeguarding concerns (schema v99): one place anybody can tell the academy
 * that something is wrong, and one queue the Designated Safeguarding Officer
 * works from.
 *
 * Before this, the guardian thread refused a safeguarding message with
 * `use_safeguarding_route` and there was no such route. A concern raised in the
 * wrong place reaches the wrong person, or nobody: an instructor's inbox, a
 * class feed, a support ticket. So:
 *
 *   · Anybody signed in may raise one — a student, a guardian, a teacher —
 *     about themselves, another person, a post, a comment or a live class.
 *   · The officer is told AT ONCE (in-app and by email). If no officer is
 *     named, every super admin is told, because a concern that waits for an
 *     appointment is a concern nobody read.
 *   · Automatic concerns come from the screens that hold words (a class post,
 *     a comment, a lesson answer) and are marked `automatic`, so the officer
 *     can tell a machine's guess from a person's report.
 *   · The person who raised it sees that it was received, never what the
 *     officer decided — the outcome is about somebody else.
 *   · Concerns are never deleted. They close with an outcome, and every step
 *     is a note with a name and a time on it.
 *
 * Access: the named officer, and admins holding `safeguarding`.
 */
declare(strict_types=1);

const MAM_SG_CATEGORIES = [
    'wellbeing'    => 'Somebody\'s safety or wellbeing',
    'conduct'      => 'How an adult behaved',
    'contact'      => 'Somebody asked for private contact',
    'content'      => 'Something posted or sent',
    'online_class' => 'Something in an online class',
    'other'        => 'Something else',
];

/** What the person who raised a concern is told, whatever happens next. */
const MAM_SG_RECEIVED = 'Thank you. The academy\'s safeguarding officer has been told and will look at this today. '
    . 'If anybody is in danger right now, call 112.';

/** May this person work the safeguarding queue? */
function mam_sg_can_manage(?array $user): bool {
    if (!$user) return false;
    $dso = function_exists('mam_vetting_dso') ? mam_vetting_dso() : null;
    if ($dso && (int) $dso['id'] === (int) $user['id']) return true;
    return mam_user_has_role($user, 'admin') && mam_admin_can($user, 'safeguarding');
}

/**
 * Raise a concern. $in: category, body, urgency?, about_id?, ref_type?, ref_id?.
 * Returns the new id. Throws InvalidArgumentException with a sentence.
 */
function mam_sg_raise(?int $by, array $in, string $source = 'person'): int {
    $cat = (string) ($in['category'] ?? 'other');
    if (!isset(MAM_SG_CATEGORIES[$cat])) $cat = 'other';
    $body = trim((string) ($in['body'] ?? ''));
    if ($source === 'person' && mb_strlen($body) < 10) throw new InvalidArgumentException('Say a little about what happened, so the officer knows where to start.');
    $urg = ($in['urgency'] ?? '') === 'urgent' ? 'urgent' : 'normal';
    $ref = in_array($in['ref_type'] ?? '', ['post', 'comment', 'session', 'thread', 'lesson', 'user'], true) ? (string) $in['ref_type'] : null;
    $about = (int) ($in['about_id'] ?? 0);
    if ($about > 0 && !mam_db_row('SELECT 1 FROM users WHERE id = ?', [$about])) $about = 0;
    $id = (int) mam_db_insert('safeguarding_concerns', [
        'raised_by' => $by, 'about_id' => $about ?: null, 'source' => $source === 'automatic' ? 'automatic' : 'person',
        'ref_type' => $ref, 'ref_id' => $ref ? (int) ($in['ref_id'] ?? 0) : null,
        'category' => $cat, 'urgency' => $urg, 'body' => mb_substr($body, 0, 5000), 'status' => 'open',
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);
    mam_audit('safeguarding_raised', 'safeguarding_concern', $id, ['source' => $source, 'category' => $cat, 'urgency' => $urg]);
    mam_sg_tell_officer($id, $cat, $urg, $source);
    return $id;
}

/** A concern a screen raised by itself. Never throws — a screen must not fail because the queue did. */
function mam_sg_auto(?int $aboutId, string $refType, int $refId, string $category, string $why): ?int {
    try {
        return mam_sg_raise(null, ['about_id' => $aboutId, 'ref_type' => $refType, 'ref_id' => $refId,
            'category' => $category, 'urgency' => 'urgent', 'body' => $why], 'automatic');
    } catch (Throwable $e) {
        mam_log('error', 'safeguarding_auto_failed', ['err' => $e->getMessage()]);
        return null;
    }
}

/** The officer is told now; with no officer named, every super admin is. */
function mam_sg_tell_officer(int $id, string $cat, string $urg, string $source): void {
    $to = [];
    $dso = function_exists('mam_vetting_dso') ? mam_vetting_dso() : null;
    if ($dso) $to[] = $dso;
    else $to = mam_db_all("SELECT id, name, email FROM users WHERE role = 'admin' AND status = 'active' AND COALESCE(admin_tier, '') <> 'manager'");
    $title = ($urg === 'urgent' ? 'Urgent: ' : '') . 'A safeguarding concern was raised';
    $line = ($source === 'automatic' ? 'Held automatically' : 'Raised by a person') . ' · ' . (MAM_SG_CATEGORIES[$cat] ?? $cat) . '. Open the safeguarding queue to read it.';
    foreach ($to as $u) {
        try { mam_notify((int) $u['id'], 'safeguarding', $title, $line, '/admin/console.php?v=safeguarding&concern=' . $id); } catch (Throwable $e) {}
        if (!empty($u['email']) && function_exists('mam_send')) {
            // The email names nobody and quotes nothing: an inbox is a weaker place than the queue.
            try { mam_send((string) $u['email'], $title, '<p>' . htmlspecialchars($line) . '</p><p>Concern #' . $id . '.</p>', 'safeguarding'); }
            catch (Throwable $e) {}
        }
    }
}

/** The queue: open first, urgent first, oldest first. */
function mam_sg_list(string $filter = 'open', int $limit = 200): array {
    $where = $filter === 'closed' ? "c.status = 'closed'" : ($filter === 'all' ? '1=1' : "c.status <> 'closed'");
    return mam_db_all(
        "SELECT c.*, rb.name AS raised_by_name, ab.name AS about_name, asg.name AS assigned_name
           FROM safeguarding_concerns c
           LEFT JOIN users rb ON rb.id = c.raised_by
           LEFT JOIN users ab ON ab.id = c.about_id
           LEFT JOIN users asg ON asg.id = c.assigned_to
          WHERE $where
          ORDER BY CASE WHEN c.status = 'closed' THEN 1 ELSE 0 END, CASE WHEN c.urgency = 'urgent' THEN 0 ELSE 1 END, c.id ASC
          LIMIT " . max(1, min(500, $limit)));
}

/** One concern with its notes, and the thing it is about where that still exists. */
function mam_sg_get(int $id): ?array {
    $c = mam_db_row("SELECT c.*, rb.name AS raised_by_name, rb.role AS raised_by_role, ab.name AS about_name
                       FROM safeguarding_concerns c
                       LEFT JOIN users rb ON rb.id = c.raised_by
                       LEFT JOIN users ab ON ab.id = c.about_id
                      WHERE c.id = ?", [$id]);
    if (!$c) return null;
    $c['notes'] = mam_db_all("SELECT n.*, u.name AS author_name FROM safeguarding_notes n LEFT JOIN users u ON u.id = n.author_id
                               WHERE n.concern_id = ? ORDER BY n.id", [$id]);
    $c['subject'] = null;
    if ($c['ref_type'] === 'post' && $c['ref_id']) {
        $c['subject'] = mam_db_row("SELECT p.id, p.body, p.status, p.media_id, p.space_id, s.title AS space_title, u.name AS author_name
                                      FROM space_posts p JOIN class_spaces s ON s.id = p.space_id LEFT JOIN users u ON u.id = p.author_id
                                     WHERE p.id = ?", [(int) $c['ref_id']]);
    } elseif ($c['ref_type'] === 'comment' && $c['ref_id']) {
        $c['subject'] = mam_db_row("SELECT sc.id, sc.body, sc.status, sc.post_id, u.name AS author_name
                                      FROM space_comments sc LEFT JOIN users u ON u.id = sc.author_id WHERE sc.id = ?", [(int) $c['ref_id']]);
    }
    return $c;
}

/**
 * Work a concern: move it on, write a note, take it, close it with an outcome.
 * Closing needs an outcome, because "closed" with nothing written is how a
 * concern that was never looked at looks exactly like one that was.
 */
function mam_sg_update(int $id, int $by, array $in): void {
    $c = mam_db_row("SELECT * FROM safeguarding_concerns WHERE id = ?", [$id]);
    if (!$c) throw new RuntimeException('not_found');
    $status = (string) ($in['status'] ?? $c['status']);
    if (!in_array($status, ['open', 'assessing', 'referred', 'closed'], true)) throw new InvalidArgumentException('That is not a status a concern can have.');
    $note = trim((string) ($in['note'] ?? ''));
    $outcome = trim((string) ($in['outcome'] ?? ''));
    if ($status === 'closed' && $c['status'] !== 'closed' && mb_strlen($outcome) < 10) {
        throw new InvalidArgumentException('Write what was decided before closing it.');
    }
    $upd = ['status' => $status];
    if (!empty($in['take'])) $upd['assigned_to'] = $by;
    if ($c['acknowledged_at'] === null) $upd['acknowledged_at'] = gmdate('Y-m-d H:i:s');
    if ($status === 'closed' && $c['status'] !== 'closed') { $upd['closed_at'] = gmdate('Y-m-d H:i:s'); $upd['outcome'] = mb_substr($outcome, 0, 4000); }
    if ($status !== 'closed' && $c['status'] === 'closed') { $upd['closed_at'] = null; }
    mam_db_update('safeguarding_concerns', $upd, ['id' => $id]);
    $line = $note;
    if ($status !== $c['status']) $line = trim('Moved to ' . $status . '. ' . $note . ($status === 'closed' ? ' Outcome: ' . $outcome : ''));
    if ($line !== '') mam_db_insert('safeguarding_notes', ['concern_id' => $id, 'author_id' => $by, 'body' => mb_substr($line, 0, 5000), 'created_at' => gmdate('Y-m-d H:i:s')]);
    mam_audit('safeguarding_updated', 'safeguarding_concern', $id, ['status' => $status, 'took' => !empty($in['take'])]);
}

/** How many need somebody now — the console badge. */
function mam_sg_open_count(): int {
    return (int) (mam_db_row("SELECT COUNT(*) AS n FROM safeguarding_concerns WHERE status = 'open'")['n'] ?? 0);
}
