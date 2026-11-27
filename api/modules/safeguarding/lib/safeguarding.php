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

/**
 * What a child is told when their own words were held for the officer (ported
 * from NGG's coach_crisis_reply). Warm, never a telling-off, and it says an
 * adult was told ONLY when a concern was actually opened — a promise of help
 * that did not happen is worse than none.
 */
function mam_sg_help_text(bool $told): string {
    return 'It sounds like something hard may be happening. You are not in trouble for saying so. '
        . ($told ? 'A trusted adult at the academy has been told and will look out for you. ' : 'Please tell a teacher or an adult you trust today. ')
        . 'If you are in danger right now, call 112.';
}

/** Unopened concerns are re-sent after this many hours (NGG's concernHours, 2–72). Not switchable off. */
function mam_sg_escalate_hours(): int { return max(2, min(72, (int) mam_setting_num('sg_escalate_hours', 4))); }

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
    $ref = in_array($in['ref_type'] ?? '', ['post', 'comment', 'session', 'thread', 'lesson', 'user', 'incident', 'ticket', 'collection'], true) ? (string) $in['ref_type'] : null;
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
        /* One automatic concern per child per day (NGG's rule): a child posting
           three worrying things in an hour is ONE thing for the officer to act on,
           with every item listed on it, not three cases racing each other. */
        if ($aboutId) {
            $open = mam_db_row("SELECT id FROM safeguarding_concerns WHERE about_id = ? AND source = 'automatic' AND status <> 'closed' AND created_at >= ? ORDER BY id DESC",
                [$aboutId, gmdate('Y-m-d H:i:s', time() - 86400)]);
            if ($open) {
                mam_db_insert('safeguarding_notes', ['concern_id' => (int) $open['id'], 'author_id' => null,
                    'body' => 'Also held: ' . $refType . ' #' . $refId . ' — ' . $why, 'created_at' => gmdate('Y-m-d H:i:s')]);
                mam_db_update('safeguarding_concerns', ['urgency' => 'urgent'], ['id' => (int) $open['id']]);
                return (int) $open['id'];
            }
        }
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

/** One concern with its notes, and the thing it is about where that still exists. Every read by a person is audited. */
function mam_sg_get(int $id, ?int $readerId = null): ?array {
    if ($readerId) mam_audit('safeguarding_read', 'safeguarding_concern', $id, ['by' => $readerId]);
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


/**
 * Escalation (ported from NGG's concernEscalate, which NGG locks on): a
 * concern still OPEN and never looked at after mam_sg_escalate_hours() is
 * sent again — to the officer AND every super admin — once. There is no
 * switch for this: a concern that sits unread is exactly the failure the
 * queue exists to prevent, and a setting that turns that off would one day be
 * turned off.
 */
function mam_sg_escalate_sweep(): int {
    $cut = gmdate('Y-m-d H:i:s', time() - mam_sg_escalate_hours() * 3600);
    $n = 0;
    foreach (mam_db_all("SELECT id, category, urgency FROM safeguarding_concerns
                          WHERE status = 'open' AND acknowledged_at IS NULL AND escalated_at IS NULL AND created_at <= ? LIMIT 100", [$cut]) as $c) {
        mam_db_update('safeguarding_concerns', ['escalated_at' => gmdate('Y-m-d H:i:s')], ['id' => (int) $c['id']]);
        $to = mam_db_all("SELECT id, email FROM users WHERE role = 'admin' AND status = 'active' AND COALESCE(admin_tier, '') <> 'manager'");
        if ($dso = (function_exists('mam_vetting_dso') ? mam_vetting_dso() : null)) $to[] = $dso;
        $seen = [];
        foreach ($to as $u) {
            if (isset($seen[(int) $u['id']])) continue; $seen[(int) $u['id']] = true;
            $title = 'Not yet looked at: safeguarding concern #' . (int) $c['id'];
            try { mam_notify((int) $u['id'], 'safeguarding', $title, 'Raised more than ' . mam_sg_escalate_hours() . ' hours ago and nobody has opened it.', '/admin/console.php?v=safeguarding&concern=' . (int) $c['id']); } catch (Throwable $e) {}
            if (!empty($u['email']) && function_exists('mam_send')) { try { mam_send((string) $u['email'], $title, '<p>' . htmlspecialchars($title) . '. It was raised more than ' . mam_sg_escalate_hours() . ' hours ago and nobody has opened it.</p>', 'safeguarding'); } catch (Throwable $e) {} }
        }
        mam_audit('safeguarding_escalated', 'safeguarding_concern', (int) $c['id'], ['hours' => mam_sg_escalate_hours()]);
        $n++;
    }
    return $n;
}

/* ─── Incidents (ported from NGG's summer incident log, v103) ──────────── */

const MAM_INCIDENT_KINDS = [
    'first_aid'    => 'First aid',
    'health'       => 'Feeling unwell',
    'behaviour'    => 'Behaviour',
    'collection'   => 'Collection / pickup',
    'lost_item'    => 'Lost or broken item',
    'safeguarding' => 'Safeguarding',
    'other'        => 'Something else',
];

/** Who may record and read incidents for a student: an admin, or a teacher of theirs. */
function mam_incident_can(array $user, int $studentId): bool {
    if (mam_user_has_role($user, 'admin')) return true;
    return mam_user_has_role($user, 'instructor') && function_exists('mam_media_student_reader') && mam_media_student_reader($studentId, $user);
}

/**
 * Record an incident. $in: student_id, kind, happened_at?, place?, what (staff
 * only), action_taken?, family_note? (what a family is told — written for
 * them, because the staff note never leaves the building).
 *
 * A SAFEGUARDING incident is not an incident record at all for anybody but the
 * officer: it opens an urgent concern, is stored with its details restricted,
 * and the teacher can neither tell the family nor close it — the officer
 * decides who is told, because the wrong adult being told can put a child at
 * greater risk.
 */
function mam_incident_record(array $user, array $in): int {
    $sid = (int) ($in['student_id'] ?? 0);
    if ($sid <= 0 || !mam_incident_can($user, $sid)) throw new RuntimeException('forbidden');
    $kind = isset(MAM_INCIDENT_KINDS[$in['kind'] ?? '']) ? (string) $in['kind'] : 'other';
    $what = trim((string) ($in['what'] ?? ''));
    if (mb_strlen($what) < 5) throw new InvalidArgumentException('Say what happened.');
    $at = trim((string) ($in['happened_at'] ?? '')) ?: gmdate('Y-m-d H:i:s');
    $at = str_replace('T', ' ', $at);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $at)) throw new InvalidArgumentException('Give the time as a date and time.');
    $id = (int) mam_db_insert('incidents', ['student_id' => $sid, 'kind' => $kind, 'happened_at' => $at,
        'place' => mb_substr(trim((string) ($in['place'] ?? '')), 0, 120) ?: null,
        'what' => mb_substr($what, 0, 4000), 'action_taken' => mb_substr(trim((string) ($in['action_taken'] ?? '')), 0, 2000) ?: null,
        'family_note' => $kind === 'safeguarding' ? null : (mb_substr(trim((string) ($in['family_note'] ?? '')), 0, 1000) ?: null),
        'status' => 'open', 'recorded_by' => (int) $user['id'], 'created_at' => gmdate('Y-m-d H:i:s')]);
    if ($kind === 'safeguarding' || mam_lesson_safeguarding_hit($what)) {
        $cid = mam_sg_raise((int) $user['id'], ['category' => 'wellbeing', 'urgency' => 'urgent', 'about_id' => $sid,
            'ref_type' => 'incident', 'ref_id' => $id, 'body' => 'Recorded as an incident: ' . $what]);
        mam_db_update('incidents', ['concern_id' => $cid], ['id' => $id]);
    }
    mam_audit('incident_recorded', 'user', $sid, ['incident' => $id, 'kind' => $kind]);
    return $id;
}

/** Incidents for one student (or all open ones), with safeguarding details withheld from anybody but the officer. */
function mam_incident_list(array $viewer, ?int $studentId = null, string $filter = 'open'): array {
    $where = []; $args = [];
    if ($studentId) { $where[] = 'i.student_id = ?'; $args[] = $studentId; }
    if ($filter === 'open') $where[] = "i.status = 'open'";
    $rows = mam_db_all('SELECT i.*, s.name AS student_name, r.name AS recorded_by_name FROM incidents i
                          LEFT JOIN users s ON s.id = i.student_id LEFT JOIN users r ON r.id = i.recorded_by'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY i.happened_at DESC, i.id DESC LIMIT 200', $args);
    $officer = mam_sg_can_manage($viewer);
    $out = [];
    foreach ($rows as $r) {
        if (!mam_user_has_role($viewer, 'admin') && !mam_incident_can($viewer, (int) $r['student_id'])) continue;
        if ($r['kind'] === 'safeguarding' && !$officer) {
            $r['what'] = 'Restricted — with the safeguarding officer.'; $r['action_taken'] = null; $r['place'] = null;
            if ((int) $r['recorded_by'] !== (int) $viewer['id']) $r['student_name'] = 'Restricted';
        }
        $out[] = $r;
    }
    return $out;
}

/**
 * Tell the guardian. Records "told" ONLY when a message actually went, and
 * sends the FAMILY note, never the staff one. Refused for a safeguarding
 * incident (the officer decides).
 */
function mam_incident_tell(array $user, int $id): array {
    $i = mam_db_row('SELECT * FROM incidents WHERE id = ?', [$id]);
    if (!$i || !mam_incident_can($user, (int) $i['student_id'])) throw new RuntimeException('not_found');
    if ($i['kind'] === 'safeguarding') throw new RuntimeException('officer_decides');
    if (trim((string) $i['family_note']) === '') throw new InvalidArgumentException('Write the note the family will read first.');
    $sent = []; $stu = mam_db_row('SELECT name FROM users WHERE id = ?', [(int) $i['student_id']]);
    $subject = (MAM_INCIDENT_KINDS[$i['kind']] ?? 'An incident') . ' — ' . (string) ($stu['name'] ?? 'your child');
    foreach (mam_db_all('SELECT u.id, u.email FROM parent_links pl JOIN users u ON u.id = pl.parent_id WHERE pl.student_id = ?', [(int) $i['student_id']]) as $g) {
        try { mam_notify((int) $g['id'], 'incident', $subject, (string) $i['family_note'], null); $sent[] = 'portal'; } catch (Throwable $e) {}
        if (!empty($g['email']) && function_exists('mam_send')) {
            try { if (mam_send((string) $g['email'], $subject, '<p>' . nl2br(htmlspecialchars((string) $i['family_note'])) . '</p><p>Reply to this email or call the academy if you have questions.</p>', 'incident')) $sent[] = 'email'; } catch (Throwable $e) {}
        }
    }
    $sent = array_values(array_unique($sent));
    if (!$sent) return ['told' => false, 'message' => 'Nobody could be reached — no guardian is linked, or nothing could be sent. Call the family.'];
    mam_db_update('incidents', ['told_at' => gmdate('Y-m-d H:i:s'), 'told_by' => (int) $user['id'], 'told_channels' => implode(',', $sent)], ['id' => $id]);
    mam_audit('incident_told', 'user', (int) $i['student_id'], ['incident' => $id, 'channels' => $sent]);
    return ['told' => true, 'message' => 'The family has been told (' . implode(', ', $sent) . ').'];
}

function mam_incident_close(array $user, int $id, string $outcome): void {
    $i = mam_db_row('SELECT * FROM incidents WHERE id = ?', [$id]);
    if (!$i || !mam_incident_can($user, (int) $i['student_id'])) throw new RuntimeException('not_found');
    if ($i['kind'] === 'safeguarding' && !mam_sg_can_manage($user)) throw new RuntimeException('officer_decides');
    if (mb_strlen(trim($outcome)) < 3) throw new InvalidArgumentException('Say how it ended.');
    mam_db_update('incidents', ['status' => 'closed', 'outcome' => mb_substr(trim($outcome), 0, 1000), 'closed_by' => (int) $user['id'],
        'closed_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
    mam_audit('incident_closed', 'user', (int) $i['student_id'], ['incident' => $id]);
}

/* ─── Authorised collectors (ported from NGG's member_collectors, v104) ─────
 *
 * Who may take a child home, and a record of who did. NGG kept the list but
 * never looked at it when a child left; here the register asks.
 *
 *   · At most MAM_COLLECTORS_MAX people per child, each with a name and,
 *     ideally, a phone the desk can ring. Edited by staff (an admin or a
 *     teacher of the child); a family SEES the list and asks the academy to
 *     change it — a list a family member could edit from a phone is a list
 *     anyone holding that phone could edit.
 *   · Every sign-out is a row in `collections`, with one of four verdicts:
 *       authorised  — somebody on the list;
 *       not_listed  — the child HAS a list and this person is not on it. A
 *                     reason is required, and the officer is told at once;
 *       no_list     — the child has no list yet. Recorded, never alarmed: on
 *                     the day this ships every child is in this state, and a
 *                     control that opens forty concerns on its first afternoon
 *                     is a control somebody switches off. The register says
 *                     "no collectors on file" instead, until one is added;
 *       adult       — the student is 18 or over. Nothing to check.
 *   · Unknown age counts as a minor, as everywhere else here
 *     (`safeguarding_unknown_age_is_minor`).
 *   · Phone numbers of people who are not our users are personal data: they
 *     are shown to staff and to the child's own guardians, never exported,
 *     never printed on a card.
 */
const MAM_COLLECTORS_MAX = 8;

/** Is this student treated as a child for collection? Unknown age counts as a minor by default. */
function mam_collect_is_minor(int $studentId): bool {
    $m = function_exists('mam_vetting_student_is_minor') ? mam_vetting_student_is_minor($studentId) : null;
    if ($m !== null) return $m;
    return (int) mam_setting_num('safeguarding_unknown_age_is_minor', 1) === 1;
}

/** The list, in the order it was written. */
function mam_collectors_list(int $studentId): array {
    return mam_db_all('SELECT id, name, relation, phone FROM authorised_collectors WHERE student_id = ? ORDER BY id', [$studentId]);
}

/**
 * Replace the list. $list: [{name, relation?, phone?}, …]. Blank names are
 * skipped; the whole list is validated before anything is written, so a bad
 * phone on row six does not leave rows one to five half-saved.
 * Rows are matched by name so an unchanged person keeps their id — the
 * sign-out history points at it.
 */
function mam_collectors_save(array $user, int $studentId, array $list): array {
    if ($studentId <= 0 || !mam_incident_can($user, $studentId)) throw new RuntimeException('forbidden');
    $clean = [];
    foreach ($list as $c) {
        $name = trim((string) preg_replace('/\s+/', ' ', (string) ($c['name'] ?? '')));
        if ($name === '') continue;
        $phone = trim((string) ($c['phone'] ?? ''));
        if ($phone !== '' && !preg_match('/^[+0-9 ()-]{7,24}$/', $phone)) throw new InvalidArgumentException('Check the phone number for ' . $name . '.');
        $key = mb_strtolower($name);
        if (isset($clean[$key])) throw new InvalidArgumentException($name . ' is on the list twice.');
        $clean[$key] = ['name' => mb_substr($name, 0, 160), 'relation' => mb_substr(trim((string) ($c['relation'] ?? '')), 0, 60) ?: null, 'phone' => $phone ?: null];
    }
    if (count($clean) > MAM_COLLECTORS_MAX) throw new InvalidArgumentException('At most ' . MAM_COLLECTORS_MAX . ' people can be on the list.');
    $have = [];
    foreach (mam_collectors_list($studentId) as $r) $have[mb_strtolower((string) $r['name'])] = (int) $r['id'];
    $now = gmdate('Y-m-d H:i:s');
    foreach ($clean as $key => $c) {
        if (isset($have[$key])) { mam_db_update('authorised_collectors', ['relation' => $c['relation'], 'phone' => $c['phone']], ['id' => $have[$key]]); unset($have[$key]); }
        else mam_db_insert('authorised_collectors', $c + ['student_id' => $studentId, 'added_by' => (int) $user['id'], 'created_at' => $now]);
    }
    foreach ($have as $id) mam_db()->prepare('DELETE FROM authorised_collectors WHERE id = ?')->execute([$id]);
    mam_audit('collectors_saved', 'user', $studentId, ['count' => count($clean)]);
    return mam_collectors_list($studentId);
}

/**
 * Record who collected a child. Either $collectorId (somebody on the list) or
 * $otherName — and with a list on file, $reason, which goes to the officer.
 * Returns the stored row plus `concern_id` when the officer was told.
 */
function mam_collection_record(array $user, int $studentId, ?int $sessionId, ?int $collectorId, string $otherName = '', string $reason = ''): array {
    if ($studentId <= 0 || !mam_incident_can($user, $studentId)) throw new RuntimeException('forbidden');
    $list = mam_collectors_list($studentId);
    $name = ''; $verdict = '';
    if ($collectorId) {
        foreach ($list as $c) if ((int) $c['id'] === $collectorId) $name = (string) $c['name'];
        if ($name === '') throw new InvalidArgumentException('That person is not on this child\'s list.');
        $verdict = 'authorised';
    } else {
        $name = trim((string) preg_replace('/\s+/', ' ', $otherName));
        if (mb_strlen($name) < 2) throw new InvalidArgumentException('Say who collected them.');
        $verdict = !mam_collect_is_minor($studentId) ? 'adult' : ($list ? 'not_listed' : 'no_list');
        $reason = trim($reason);
        if ($verdict === 'not_listed' && mb_strlen($reason) < 5) {
            throw new InvalidArgumentException($name . ' is not on the list. Say how you checked it was all right — for example, which guardian you phoned.');
        }
    }
    if ($sessionId !== null && $sessionId > 0 && !mam_db_row('SELECT id FROM class_sessions WHERE id = ?', [$sessionId])) $sessionId = null;
    $id = (int) mam_db_insert('collections', ['student_id' => $studentId, 'session_id' => $sessionId ?: null,
        'collector_id' => $verdict === 'authorised' ? $collectorId : null, 'collector_name' => mb_substr($name, 0, 160),
        'verdict' => $verdict, 'reason' => $reason !== '' ? mb_substr($reason, 0, 500) : null,
        'recorded_by' => (int) $user['id'], 'collected_at' => gmdate('Y-m-d H:i:s')]);
    $cid = null;
    if ($verdict === 'not_listed') {
        $stu = mam_db_row('SELECT name FROM users WHERE id = ?', [$studentId]);
        $cid = mam_sg_raise((int) $user['id'], ['category' => 'wellbeing', 'urgency' => 'urgent', 'about_id' => $studentId,
            'ref_type' => 'collection', 'ref_id' => $id,
            'body' => (string) ($stu['name'] ?? 'A child') . ' was collected by ' . $name . ', who is not on their list of authorised collectors. '
                    . 'Recorded by the teacher with this reason: ' . $reason]);
        mam_db_update('collections', ['concern_id' => $cid], ['id' => $id]);
    }
    mam_audit('child_collected', 'user', $studentId, ['collection' => $id, 'verdict' => $verdict, 'session' => $sessionId]);
    return ['id' => $id, 'verdict' => $verdict, 'collector_name' => $name, 'concern_id' => $cid];
}

/** The latest collection per student for one session: student_id => row. */
function mam_collections_for_session(int $sessionId): array {
    $out = [];
    foreach (mam_db_all('SELECT * FROM collections WHERE session_id = ? ORDER BY id', [$sessionId]) as $r) $out[(int) $r['student_id']] = $r;
    return $out;
}

/** One child's recent sign-outs, newest first. */
function mam_collections_recent(int $studentId, int $limit = 10): array {
    return mam_db_all('SELECT c.*, u.name AS recorded_by_name FROM collections c LEFT JOIN users u ON u.id = c.recorded_by
                        WHERE c.student_id = ? ORDER BY c.collected_at DESC, c.id DESC LIMIT ' . max(1, min(50, $limit)), [$studentId]);
}
