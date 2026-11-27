<?php
/**
 * MAM Academy — guardian↔staff threads, how the academy reaches a family, and
 * the make-up credit ledger (guardian redesign Messages screen + §3.5, schema v88).
 * ─────────────────────────────────────────────────────────────
 * Owns `guardian_threads`, `guardian_thread_participants`,
 * `guardian_thread_messages`, `guardian_channel_prefs`, `makeup_credits`.
 *
 * ── What this is NOT ────────────────────────────────────────────────────────
 * The `messages` table is the PUBLIC CONTACT FORM — name, email, phone, topic,
 * body — with no participants and no notion of a reply. Bending it into a thread
 * would put anonymous website enquiries in the same table as a safeguarding
 * conversation about a named child, and there is no migration that makes that a
 * good idea.
 *
 * ── The refusal this file exists to hold ────────────────────────────────────
 * **A student is never a participant in a guardian thread.** No guardian scope
 * reaches a teenager's messages with their instructor, and the cheapest way to
 * breach that would be to add a guardian to the student's own conversation. So
 * the two are different tables, the participant role CHECK admits only adult
 * roles, and `mam_thread_add_participant()` refuses a student outright rather
 * than relying on a caller to pass the right role.
 *
 * The reason is not squeamishness. A young person who knows their questions are
 * read at home stops asking the embarrassing ones, and the embarrassing ones are
 * where the teaching happens. What a family is owed instead is everything that
 * affects them, reliably — which is the next rule.
 *
 * ── What no preference can mute ─────────────────────────────────────────────
 * The design's Messages screen carries WhatsApp / Email / SMS toggles and, under
 * them, the sentence that makes them safe: **anything about safeguarding or money
 * reaches the guardian on every channel the academy can use, regardless of these
 * settings.** A family that has muted notifications has muted reminders about
 * practice, not a concern about their child and not a fee falling due.
 *
 * `mam_guardian_channels()` is the one implementation. The schema helps by having
 * nowhere to record a per-topic preference, so "mute safeguarding" is not a state
 * the database can hold.
 */
declare(strict_types=1);

/** Thread kinds. The two the preferences cannot suppress are named once, here. */
const MAM_THREAD_KINDS = ['general', 'academic', 'money', 'safeguarding', 'timetable'];
const MAM_THREAD_ALWAYS_REACH = ['safeguarding', 'money'];

/** The channels the academy can use, in the order the design lists them. */
const MAM_GUARDIAN_CHANNELS = ['whatsapp', 'email', 'sms'];

/* ─────────────────────────────────────────────────────────────────────────────
   Channel preferences
   ───────────────────────────────────────────────────────────────────────────── */

/** A guardian's stored preferences, with the shipped defaults when unset. */
function mam_guardian_prefs(int $userId): array {
    $r = mam_db_row('SELECT * FROM guardian_channel_prefs WHERE user_id = ?', [$userId]);
    return [
        'whatsapp' => $r ? (bool) $r['whatsapp'] : true,
        'email'    => $r ? (bool) $r['email']    : true,
        // SMS off by default: it costs the academy per message and reaches the
        // same phone WhatsApp already does, so it is opt-IN rather than opt-out.
        'sms'      => $r ? (bool) $r['sms']      : false,
        'set'      => (bool) $r,
    ];
}

/** Save a guardian's preferences. Only the three channels; nothing per-topic. */
function mam_guardian_prefs_set(int $userId, array $in): array {
    $row = [
        'user_id'    => $userId,
        'whatsapp'   => !empty($in['whatsapp']) ? 1 : 0,
        'email'      => !empty($in['email'])    ? 1 : 0,
        'sms'        => !empty($in['sms'])      ? 1 : 0,
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    mam_db_upsert('guardian_channel_prefs', $row, ['user_id'],
        ['whatsapp' => $row['whatsapp'], 'email' => $row['email'], 'sms' => $row['sms'], 'updated_at' => $row['updated_at']]);
    mam_audit('guardian_prefs', 'user', $userId, $row);
    return mam_guardian_prefs($userId);
}

/**
 * The channels to use for one guardian and one topic — the one place that rule
 * lives.
 *
 * For `safeguarding` and `money`, the preferences are IGNORED and every channel
 * the academy holds a usable address for is returned. Not "every channel left
 * on": a toggle a family flipped a year ago must not be the reason they did not
 * hear that their child's fee lapsed or that a concern was raised. The design's
 * own sentence on the screen says as much, and `override` is returned so the
 * caller can say so in the message rather than surprising anybody.
 *
 * A channel with no address is never returned, whatever the topic — there is no
 * point queueing an SMS to a guardian with no phone number, and reporting one as
 * "sent" would be worse than not trying.
 */
function mam_guardian_channels(int $userId, string $topic = 'general'): array {
    $u = mam_db_row('SELECT id, email, phone FROM users WHERE id = ?', [$userId]);
    if (!$u) return ['channels' => [], 'override' => false, 'reason' => 'no_such_user'];

    $has = [
        'email'    => !empty($u['email']),
        // WhatsApp and SMS both need a phone number on file.
        'whatsapp' => !empty($u['phone']),
        'sms'      => !empty($u['phone']),
    ];

    $always = in_array($topic, MAM_THREAD_ALWAYS_REACH, true);
    $prefs  = mam_guardian_prefs($userId);

    $out = [];
    foreach (MAM_GUARDIAN_CHANNELS as $c) {
        if (!$has[$c]) continue;
        if ($always || $prefs[$c]) $out[] = $c;
    }
    return [
        'channels' => $out,
        'override' => $always,
        'reason'   => $always
            ? 'Anything about safeguarding or money reaches you on every channel we can use, '
              . 'whatever your notification settings say.'
            : null,
        'topic'    => $topic,
    ];
}

/* ─────────────────────────────────────────────────────────────────────────────
   Threads
   ───────────────────────────────────────────────────────────────────────────── */

/** NULL, never 0 — these columns carry users(id) FKs (CLAUDE.md gotcha #3). */
function mam_thread_actor(?int $id): ?int { return $id !== null && $id > 0 ? $id : null; }

/** The role a user takes in a guardian thread. Never 'student'. */
function mam_thread_role_for(array $user): string {
    $has = fn(string $r) => function_exists('mam_user_has_role') ? mam_user_has_role($user, $r) : ($user['role'] ?? '') === $r;
    if ($has('admin'))      return 'admin';
    if ($has('instructor')) return 'instructor';
    if ($has('parent'))     return 'guardian';
    return 'office';
}

/**
 * Open a thread about a child.
 *
 * The opener is added as a participant, and so is every guardian whose scope
 * reaches staff messages — a household with two guardians must not have one of
 * them holding a conversation the other cannot see, because the commonest failure
 * of a school messaging system is that the reply went to the parent who was not
 * doing the school run.
 */
function mam_thread_open(int $studentId, string $subject, string $kind, int $openedBy, array $opts = []): int {
    $subject = trim($subject);
    if ($subject === '') throw new InvalidArgumentException('subject_required');
    if (!in_array($kind, MAM_THREAD_KINDS, true)) throw new InvalidArgumentException('bad_kind');
    if (!mam_db_row('SELECT id FROM users WHERE id = ?', [$studentId])) throw new RuntimeException('student_not_found');

    $opener = mam_db_row('SELECT * FROM users WHERE id = ?', [$openedBy]);
    if (!$opener) throw new RuntimeException('user_not_found');
    $openerRole = mam_thread_role_for($opener);
    if ($openerRole === 'guardian' && !mam_parent_can_view($openedBy, $studentId)) throw new RuntimeException('forbidden');

    $id = mam_db_insert('guardian_threads', [
        'student_id' => $studentId,
        'subject'    => $subject,
        'kind'       => $kind,
        'status'     => 'open',
        'opened_by'  => mam_thread_actor($openedBy),
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    mam_thread_add_participant($id, $openedBy);

    // Every guardian who is allowed to see staff messages about this child.
    if (function_exists('mam_parent_guardians_of')) {
        foreach (mam_parent_guardians_of($studentId) as $g) {
            if ((int) $g['id'] === $openedBy) continue;
            if (!mam_parent_scope_allows((int) $g['id'], $studentId, 'messages_staff')
                && !in_array($kind, MAM_THREAD_ALWAYS_REACH, true)) continue;
            try { mam_thread_add_participant($id, (int) $g['id']); } catch (\Throwable $e) { /* best effort */ }
        }
    }
    foreach ((array) ($opts['also'] ?? []) as $uid) {
        try { mam_thread_add_participant($id, (int) $uid); } catch (\Throwable $e) { /* best effort */ }
    }

    mam_audit('thread_open', 'guardian_thread', $id, ['student' => $studentId, 'kind' => $kind, 'by' => $openedBy]);
    return $id;
}

/**
 * Add someone to a thread. **Refuses a student**, whatever role a caller passes.
 *
 * The role is DERIVED from the account rather than taken as an argument, so there
 * is no signature through which "add this student as an office contact" is
 * expressible. A student account whose only role is `student` has no adult role to
 * derive, so it lands on 'office' — which is exactly the case this guard exists
 * for, and why the check is on the account and not on the label.
 */
function mam_thread_add_participant(int $threadId, int $userId): void {
    $t = mam_db_row('SELECT id, student_id FROM guardian_threads WHERE id = ?', [$threadId]);
    if (!$t) throw new RuntimeException('thread_not_found');
    $u = mam_db_row('SELECT * FROM users WHERE id = ?', [$userId]);
    if (!$u) throw new RuntimeException('user_not_found');

    // The refusal, stated twice: the child this thread is about, and any account
    // that is a student at all.
    if ($userId === (int) $t['student_id']) throw new RuntimeException('student_not_a_participant');
    $isStudent = function_exists('mam_user_has_role') ? mam_user_has_role($u, 'student') : ($u['role'] ?? '') === 'student';
    $role = mam_thread_role_for($u);
    if ($isStudent && $role === 'office') throw new RuntimeException('student_not_a_participant');

    if (mam_db_row('SELECT id FROM guardian_thread_participants WHERE thread_id = ? AND user_id = ?', [$threadId, $userId])) return;
    mam_db_insert('guardian_thread_participants', [
        'thread_id' => $threadId, 'user_id' => $userId, 'role' => $role, 'added_at' => date('Y-m-d H:i:s'),
    ]);
}

/** Is this user a participant? The only read gate on a thread. */
function mam_thread_can_read(int $threadId, int $userId): bool {
    return (bool) mam_db_row('SELECT 1 FROM guardian_thread_participants WHERE thread_id = ? AND user_id = ? LIMIT 1', [$threadId, $userId]);
}

/**
 * Post a message. Returns the message id.
 *
 * The author's NAME and ROLE are denormalised onto the row. `author_id` goes NULL
 * when a staff account is deleted, and a family's thread has to still make sense
 * afterwards — "a message from nobody" is worse than a name.
 */
function mam_thread_post(int $threadId, int $userId, string $body): int {
    $body = trim($body);
    if ($body === '') throw new InvalidArgumentException('body_required');
    $t = mam_db_row('SELECT * FROM guardian_threads WHERE id = ?', [$threadId]);
    if (!$t) throw new RuntimeException('thread_not_found');
    if ($t['status'] !== 'open') throw new RuntimeException('thread_closed');
    if (!mam_thread_can_read($threadId, $userId)) throw new RuntimeException('forbidden');
    $u = mam_db_row('SELECT * FROM users WHERE id = ?', [$userId]);
    if (!$u) throw new RuntimeException('user_not_found');

    $now = date('Y-m-d H:i:s');
    $id = mam_db_insert('guardian_thread_messages', [
        'thread_id'   => $threadId,
        'author_id'   => mam_thread_actor($userId),
        'author_name' => (string) ($u['name'] ?: 'Academy'),
        'author_role' => mam_thread_role_for($u),
        'body'        => $body,
        'created_at'  => $now,
    ]);
    mam_db_update('guardian_threads', ['last_message_at' => $now], ['id' => $threadId]);
    // Words about harm reach the safeguarding officer whatever the thread was about (safeguarding.php).
    if (function_exists('mam_lesson_safeguarding_hit') && function_exists('mam_sg_auto') && mam_lesson_safeguarding_hit($body)) {
        mam_sg_auto((int) $t['student_id'] ?: null, 'thread', $threadId, 'wellbeing', 'Words in a family message thread may be about harm.');
    }

    // Notify the other participants, on the channels the topic allows.
    foreach (mam_db_all('SELECT user_id, role FROM guardian_thread_participants WHERE thread_id = ? AND user_id <> ?', [$threadId, $userId]) as $p) {
        $ch = mam_guardian_channels((int) $p['user_id'], (string) $t['kind']);
        if (!$ch['channels']) continue;
        if (function_exists('mam_notify')) {
            try {
                mam_notify((int) $p['user_id'], 'thread.message', (string) $t['subject'],
                    mb_substr($body, 0, 240), ['thread' => $threadId, 'channels' => $ch['channels']]);
            } catch (\Throwable $e) { /* a notification must not fail a post */ }
        }
    }
    return $id;
}

/** Mark a thread read up to now, for one participant. */
function mam_thread_mark_read(int $threadId, int $userId): void {
    mam_db_update('guardian_thread_participants', ['last_read_at' => date('Y-m-d H:i:s')],
        ['thread_id' => $threadId, 'user_id' => $userId]);
}

/** Close a thread. Messages remain; the conversation stops. */
function mam_thread_close(int $threadId, ?int $actorId = null): void {
    $t = mam_db_row('SELECT status FROM guardian_threads WHERE id = ?', [$threadId]);
    if (!$t) throw new RuntimeException('thread_not_found');
    if ($t['status'] === 'closed') return;
    mam_db_update('guardian_threads', ['status' => 'closed', 'closed_at' => date('Y-m-d H:i:s')], ['id' => $threadId]);
    mam_audit('thread_close', 'guardian_thread', $threadId, ['by' => mam_thread_actor($actorId)]);
}

/**
 * The Messages screen for one guardian: their threads across all their children,
 * newest activity first, each with its latest message and an unread flag.
 */
function mam_thread_inbox(int $userId, int $limit = 40): array {
    $limit = max(1, min(200, $limit));
    $rows = mam_db_all(
        "SELECT t.id, t.subject, t.kind, t.status, t.student_id, t.last_message_at, t.created_at,
                p.last_read_at, u.name AS student_name
           FROM guardian_thread_participants p
           JOIN guardian_threads t ON t.id = p.thread_id
           JOIN users u ON u.id = t.student_id
          WHERE p.user_id = ?
          ORDER BY COALESCE(t.last_message_at, t.created_at) DESC, t.id DESC
          LIMIT " . $limit, [$userId]
    );
    $out = [];
    foreach ($rows as $t) {
        $last = mam_db_row(
            'SELECT author_name, author_role, body, created_at FROM guardian_thread_messages
              WHERE thread_id = ? ORDER BY created_at DESC, id DESC LIMIT 1', [(int) $t['id']]
        );
        $out[] = [
            'id'      => (int) $t['id'],
            'subject' => $t['subject'],
            'kind'    => $t['kind'],
            'status'  => $t['status'],
            'student_id'   => (int) $t['student_id'],
            'student_name' => $t['student_name'],
            'last'    => $last ? [
                'author' => $last['author_name'], 'role' => $last['author_role'],
                'body' => $last['body'], 'at' => $last['created_at'],
            ] : null,
            'unread'  => $last !== null && ($t['last_read_at'] === null || $last['created_at'] > $t['last_read_at']),
            'at'      => $t['last_message_at'] ?: $t['created_at'],
        ];
    }
    return $out;
}

/** One thread, with its messages, for a participant. */
function mam_thread_get(int $threadId, int $userId): ?array {
    if (!mam_thread_can_read($threadId, $userId)) throw new RuntimeException('forbidden');
    $t = mam_db_row(
        'SELECT t.*, u.name AS student_name FROM guardian_threads t
           JOIN users u ON u.id = t.student_id WHERE t.id = ?', [$threadId]
    );
    if (!$t) return null;
    return [
        'id' => (int) $t['id'], 'subject' => $t['subject'], 'kind' => $t['kind'],
        'status' => $t['status'], 'student_id' => (int) $t['student_id'], 'student_name' => $t['student_name'],
        'messages' => mam_db_all(
            'SELECT id, author_name, author_role, body, created_at FROM guardian_thread_messages
              WHERE thread_id = ? ORDER BY created_at, id', [$threadId]
        ),
        'participants' => mam_db_all(
            'SELECT p.role, u.name FROM guardian_thread_participants p
               JOIN users u ON u.id = p.user_id WHERE p.thread_id = ? ORDER BY p.role, u.name', [$threadId]
        ),
    ];
}

/* ─────────────────────────────────────────────────────────────────────────────
   Make-up credits
   ───────────────────────────────────────────────────────────────────────────── */

/** The published policy, as data, so the screen states it where a credit is spent. */
const MAM_MAKEUP_NOTICE_HOURS   = 24;
const MAM_MAKEUP_ALLOWANCE_YEAR = 5;
const MAM_MAKEUP_EXPIRY_DAYS    = 180;

/** The academy year a credit is counted in. */
function mam_makeup_year(): string {
    if (function_exists('mam_calendar_current_term')) {
        try {
            $t = mam_calendar_current_term();
            $y = $t['term']['year_label'] ?? null;
            if ($y) return (string) $y;
        } catch (\Throwable $e) { /* fall through */ }
    }
    // Academy years run Sept–Aug here, so a January credit belongs to the year
    // that started the previous September.
    $m = (int) date('n');
    $y = (int) date('Y');
    return $m >= 9 ? $y . '/' . ($y + 1) : ($y - 1) . '/' . $y;
}

/**
 * The policy, in the words the design puts on the screen where a credit is spent.
 * Returned rather than hardcoded in the client so the two cannot drift.
 */
function mam_makeup_policy(): array {
    return [
        'notice_hours'   => MAM_MAKEUP_NOTICE_HOURS,
        'allowance_year' => MAM_MAKEUP_ALLOWANCE_YEAR,
        'expiry_days'    => MAM_MAKEUP_EXPIRY_DAYS,
        'rules' => [
            'Cancel at least ' . MAM_MAKEUP_NOTICE_HOURS . ' hours ahead and the lesson becomes a credit.',
            MAM_MAKEUP_ALLOWANCE_YEAR . ' credits a year. They belong to the student and cannot be transferred.',
            'A credit expires ' . MAM_MAKEUP_EXPIRY_DAYS . ' days after it is earned.',
            'There is no cash refund in place of a credit.',
            'If the academy cancels, you always get a credit and it never counts against your ' . MAM_MAKEUP_ALLOWANCE_YEAR . '.',
            'Only your own cancellations count towards the ' . MAM_MAKEUP_ALLOWANCE_YEAR . '. Anything we grant is on top.',
        ],
    ];
}

/**
 * The credit position for a student: what is available, and how much of the
 * annual allowance is left.
 *
 * The allowance counts ONLY the rows where `counts_against_allowance` is 1. A
 * teacher cancellation and an academy closure are 0, so they never eat into it —
 * charging a family an allowance slot because the academy cancelled is the single
 * most resented thing a make-up policy can do, and the design says so twice.
 */
function mam_makeup_balance(int $studentId): array {
    mam_makeup_expire_due($studentId);
    $year = mam_makeup_year();
    $avail = (int) (mam_db_row("SELECT COUNT(*) AS n FROM makeup_credits
        WHERE student_id = ? AND status = 'available'", [$studentId])['n'] ?? 0);
    $usedAllowance = (int) (mam_db_row("SELECT COUNT(*) AS n FROM makeup_credits
        WHERE student_id = ? AND year_label = ? AND counts_against_allowance = 1
          AND status IN ('available','spent')", [$studentId, $year])['n'] ?? 0);
    $free = (int) (mam_db_row("SELECT COUNT(*) AS n FROM makeup_credits
        WHERE student_id = ? AND year_label = ? AND counts_against_allowance = 0
          AND status IN ('available','spent')", [$studentId, $year])['n'] ?? 0);

    return [
        'available'        => $avail,
        'allowance'        => MAM_MAKEUP_ALLOWANCE_YEAR,
        'allowance_used'   => $usedAllowance,
        'allowance_left'   => max(0, MAM_MAKEUP_ALLOWANCE_YEAR - $usedAllowance),
        // Disclosed separately so a family can see that the academy's own
        // cancellations were not charged to them.
        'academy_credits'  => $free,
        'year_label'       => $year,
        'policy'           => mam_makeup_policy(),
        'credits'          => mam_db_all(
            "SELECT id, reason, expires_on, granted_at, counts_against_allowance
               FROM makeup_credits WHERE student_id = ? AND status = 'available'
              ORDER BY expires_on", [$studentId]
        ),
    ];
}

/** Lapse any credit past its expiry. Derived from the date, swept on read, so a
 *  credit lapsing at midnight reads as expired on the next page load rather than
 *  the next cron run — the same rule as ID-card and vetting expiry. */
function mam_makeup_expire_due(?int $studentId = null): int {
    $today = function_exists('mam_calendar_today') ? mam_calendar_today() : date('Y-m-d');
    $sql = "UPDATE makeup_credits SET status = 'expired'
             WHERE status = 'available' AND expires_on < ?";
    $args = [$today];
    if ($studentId !== null) { $sql .= ' AND student_id = ?'; $args[] = $studentId; }
    $st = mam_db()->prepare($sql);
    $st->execute($args);
    return $st->rowCount();
}

/**
 * Grant a credit.
 *
 * `guardian_notice` requires MAM_MAKEUP_NOTICE_HOURS of notice and consumes an
 * allowance slot. `teacher_cancellation` and `academy_closure` ALWAYS credit and
 * NEVER consume one — that is forced here and again by the v88 CHECK, because it
 * is the rule most likely to be lost to a caller passing the default.
 */
function mam_makeup_grant(int $studentId, string $reason, array $opts = [], ?int $actorId = null): int {
    if (!in_array($reason, ['guardian_notice', 'teacher_cancellation', 'academy_closure', 'goodwill'], true)) {
        throw new InvalidArgumentException('bad_reason');
    }
    if (!mam_db_row('SELECT id FROM users WHERE id = ?', [$studentId])) throw new RuntimeException('student_not_found');

    /* Only a GUARDIAN cancellation spends the family's allowance. The allowance
     * limits how often a FAMILY may cancel; everything else on this list is the
     * academy's own doing — its instructor was unwell, its building shut, or it
     * decided to give something because the studio was too hot — and charging a
     * household an allowance slot for the academy's choice is the same mistake in
     * three different costumes. `goodwill` was the one that slipped through: it
     * was counting, so an academy trying to make something up to a family took
     * away their next cancellation to do it. */
    $counts = $reason === 'guardian_notice' ? 1 : 0;

    $noticeHours = isset($opts['notice_hours']) ? (float) $opts['notice_hours'] : null;
    if ($reason === 'guardian_notice') {
        if ($noticeHours === null && !empty($opts['session_at'])) {
            $noticeHours = (strtotime((string) $opts['session_at']) - time()) / 3600;
        }
        if ($noticeHours === null || $noticeHours < MAM_MAKEUP_NOTICE_HOURS) throw new RuntimeException('insufficient_notice');
    }

    if ($counts === 1) {
        $bal = mam_makeup_balance($studentId);
        if ($bal['allowance_left'] <= 0) throw new RuntimeException('allowance_exhausted');
    }

    $today = function_exists('mam_calendar_today') ? mam_calendar_today() : date('Y-m-d');
    $id = mam_db_insert('makeup_credits', [
        'student_id' => $studentId,
        'term_id'    => isset($opts['term_id']) && (int) $opts['term_id'] > 0 ? (int) $opts['term_id'] : null,
        'year_label' => mam_makeup_year(),
        'reason'     => $reason,
        'source_session_id' => isset($opts['session_id']) && (int) $opts['session_id'] > 0 ? (int) $opts['session_id'] : null,
        'notice_hours' => $noticeHours !== null ? round($noticeHours, 2) : null,
        'counts_against_allowance' => $counts,
        'status'     => 'available',
        'expires_on' => date('Y-m-d', strtotime($today . ' +' . MAM_MAKEUP_EXPIRY_DAYS . ' days')),
        'granted_by' => mam_thread_actor($actorId),
        'granted_at' => date('Y-m-d H:i:s'),
        'note'       => $opts['note'] ?? null,
    ]);
    mam_audit('makeup_grant', 'makeup_credit', $id, [
        'student' => $studentId, 'reason' => $reason, 'counts' => $counts, 'notice_hours' => $noticeHours,
    ]);
    return $id;
}

/**
 * Spend a credit on a session. Oldest-expiring first, so a family never loses a
 * credit they could have used — a LIFO queue silently expires the one nearest its
 * deadline while a later one sits available.
 */
function mam_makeup_spend(int $studentId, int $sessionId, ?int $actorId = null): int {
    mam_makeup_expire_due($studentId);
    $c = mam_db_row("SELECT id FROM makeup_credits WHERE student_id = ? AND status = 'available'
                      ORDER BY expires_on ASC, id ASC LIMIT 1", [$studentId]);
    if (!$c) throw new RuntimeException('no_credit');
    mam_db_update('makeup_credits', [
        'status' => 'spent', 'spent_session_id' => $sessionId > 0 ? $sessionId : null,
        'spent_at' => date('Y-m-d H:i:s'),
    ], ['id' => (int) $c['id']]);
    mam_audit('makeup_spend', 'makeup_credit', (int) $c['id'], ['student' => $studentId, 'session' => $sessionId, 'by' => mam_thread_actor($actorId)]);
    return (int) $c['id'];
}

/** The guardian's view of a child's credits — scope-gated on the timetable. */
function mam_makeup_for_guardian(int $parentId, int $studentId): array {
    if (!mam_parent_can_view($parentId, $studentId)) throw new RuntimeException('forbidden');
    if (!mam_parent_scope_allows($parentId, $studentId, 'timetable')) {
        return ['scope_denied' => true, 'policy' => mam_makeup_policy()];
    }
    return ['scope_denied' => false] + mam_makeup_balance($studentId);
}
