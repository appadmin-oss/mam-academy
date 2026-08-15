<?php
/**
 * MAM Academy — the Recital Hall.
 * ─────────────────────────────────────────────────────────────
 * A recital is an EVENT with a curated running order, not a list of names.
 * This is the destination the rest of the academy was missing: repertoire a
 * student has polished, takes they have recorded, the cohort they belong to —
 * all of it now leads somewhere a family can sit down and watch.
 *
 * THE LIFECYCLE, and why each state exists
 *   draft      — being written. Invisible to everyone but staff.
 *   open       — the call for submissions. Students may propose pieces from
 *                their repertoire; staff accept or decline.
 *   programmed — the running order is LOCKED and the programme is public.
 *                Proposals close. This is the state the printed programme is
 *                generated from, so it must stop moving: a family reading a
 *                running order that silently changes underneath them is worse
 *                than no running order at all.
 *   completed  — it happened. Accepted items become 'performed', which is what
 *                promotes the piece in the student's repertoire.
 *   cancelled  — it did not. Deliberately terminal and distinct from deletion,
 *                because "the recital was called off" is information a family
 *                needs, and a deleted row cannot tell them.
 *
 * ITEM STATUSES are separate from the recital's, so a piece can be proposed,
 * declined, or withdrawn without destroying the record that it was offered.
 * A student who was turned down for the spring recital should still be able to
 * see that they put themselves forward.
 */
declare(strict_types=1);

const MAM_RECITAL_STATUSES = ['draft', 'open', 'programmed', 'completed', 'cancelled'];
const MAM_RECITAL_ITEM_STATUSES = ['proposed', 'accepted', 'declined', 'withdrawn', 'performed'];

/** Legal recital transitions. A programme cannot go back to a draft. */
function mam_recital_transitions(): array {
    return [
        'draft'      => ['open', 'programmed', 'cancelled'],
        // A programme can be re-opened for submissions if staff need more
        // pieces, but only BEFORE it completes.
        'open'       => ['programmed', 'draft', 'cancelled'],
        'programmed' => ['completed', 'open', 'cancelled'],
        'completed'  => [],
        'cancelled'  => [],
    ];
}

function mam_recital_can_transition(string $from, string $to): bool {
    return in_array($to, mam_recital_transitions()[$from] ?? [], true);
}

/** A URL-safe slug, uniquified against existing recitals. */
function mam_recital_slug(string $title, ?int $ignoreId = null): string {
    $base = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $title) ?? '', '-'));
    if ($base === '') $base = 'recital';
    $base = substr($base, 0, 120);
    $slug = $base;
    for ($i = 2; $i < 200; $i++) {
        $row = mam_db_row('SELECT id FROM recitals WHERE slug = ?', [$slug]);
        if (!$row || ($ignoreId !== null && (int) $row['id'] === $ignoreId)) return $slug;
        $slug = $base . '-' . $i;
    }
    return $base . '-' . bin2hex(random_bytes(3));
}

function mam_recital_create(array $f, ?int $by = null): int {
    $title = trim((string) ($f['title'] ?? ''));
    if ($title === '') throw new InvalidArgumentException('title_required');
    $id = mam_db_insert('recitals', [
        'slug'                 => mam_recital_slug($title),
        'title'                => mb_substr($title, 0, 200),
        'subtitle'             => isset($f['subtitle']) ? mb_substr(trim((string) $f['subtitle']), 0, 200) : null,
        'venue'                => isset($f['venue']) ? mb_substr(trim((string) $f['venue']), 0, 200) : null,
        'starts_at'            => $f['starts_at']     ?? null,
        'doors_open_at'        => $f['doors_open_at'] ?? null,
        'submissions_close_at' => $f['submissions_close_at'] ?? null,
        'interval_min'         => isset($f['interval_min']) ? max(0, (int) $f['interval_min']) : 15,
        'description'          => $f['description']    ?? null,
        'programme_note'       => $f['programme_note'] ?? null,
        'status'               => 'draft',
        'created_by'           => $by,
    ]);
    if (function_exists('mam_audit')) mam_audit('recital_create', 'recital', $id, ['title' => $title]);
    return $id;
}

function mam_recital(int $id): ?array {
    return mam_db_row('SELECT * FROM recitals WHERE id = ?', [$id]) ?: null;
}

function mam_recital_by_slug(string $slug): ?array {
    return mam_db_row('SELECT * FROM recitals WHERE slug = ?', [trim($slug)]) ?: null;
}

/** Update editable fields. Retitling re-slugs only while still a draft — a
 *  public programme's URL has been shared by then and must not rot. */
function mam_recital_update(int $id, array $f, ?int $by = null): bool {
    $r = mam_recital($id);
    if (!$r) throw new RuntimeException('not_found');
    $set = [];
    foreach (['title', 'subtitle', 'venue', 'starts_at', 'doors_open_at', 'submissions_close_at', 'description', 'programme_note'] as $k) {
        if (array_key_exists($k, $f)) $set[$k] = $f[$k] === '' ? null : $f[$k];
    }
    if (array_key_exists('interval_min', $f))   $set['interval_min']   = max(0, (int) $f['interval_min']);
    if (array_key_exists('interval_after', $f)) $set['interval_after'] = $f['interval_after'] === null || $f['interval_after'] === '' ? null : (int) $f['interval_after'];
    if (!$set) return false;
    if (isset($set['title'])) {
        $set['title'] = mb_substr(trim((string) $set['title']), 0, 200);
        if ($set['title'] === '') throw new InvalidArgumentException('title_required');
        if ((string) $r['status'] === 'draft') $set['slug'] = mam_recital_slug($set['title'], $id);
    }
    $set['updated_at'] = date('Y-m-d H:i:s');
    mam_db_update('recitals', $set, ['id' => $id]);
    if (function_exists('mam_audit')) mam_audit('recital_update', 'recital', $id, ['fields' => array_keys($set), 'by' => $by]);
    return true;
}

/**
 * Move a recital through its lifecycle. Completing it marks every accepted
 * item 'performed' and promotes the student's repertoire entry — the single
 * moment where playing a piece in public becomes a fact in their record.
 */
function mam_recital_set_status(int $id, string $to, ?int $by = null): bool {
    $r = mam_recital($id);
    if (!$r) throw new RuntimeException('not_found');
    $from = (string) $r['status'];
    if ($from === $to) return false;
    if (!in_array($to, MAM_RECITAL_STATUSES, true)) throw new InvalidArgumentException('bad_status');
    if (!mam_recital_can_transition($from, $to)) throw new RuntimeException('illegal_transition');

    mam_db_update('recitals', ['status' => $to, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);

    if ($to === 'completed') {
        foreach (mam_db_all("SELECT id, student_id, repertoire_id FROM recital_items WHERE recital_id = ? AND status = 'accepted'", [$id]) as $it) {
            mam_db_update('recital_items', ['status' => 'performed'], ['id' => (int) $it['id']]);
            // Performing in public is what earns 'performed' in the repertoire.
            // Best-effort: a missing repertoire link must never block closing
            // out a recital that has already happened.
            if ($it['repertoire_id'] !== null && function_exists('mam_repertoire_update')) {
                try { mam_repertoire_update((int) $it['repertoire_id'], (int) $it['student_id'], ['status' => 'performed']); }
                catch (\Throwable $e) { if (function_exists('mam_log')) mam_log('warn', 'recital_promote_failed', ['item' => $it['id']]); }
            }
        }
    }
    if ($to === 'programmed') mam_recital_notify_programmed($id);
    if ($to === 'cancelled')  mam_recital_notify_cancelled($id);

    if (function_exists('mam_audit')) mam_audit('recital_status', 'recital', $id, ['from' => $from, 'to' => $to, 'by' => $by]);
    return true;
}

/** Is this recital currently taking submissions? */
function mam_recital_accepting(array $r, ?string $now = null): bool {
    if ((string) $r['status'] !== 'open') return false;
    $close = $r['submissions_close_at'] ?? null;
    if ($close === null || $close === '') return true;
    return ($now ?: date('Y-m-d H:i:s')) < (string) $close;
}

/** Recitals a student may currently propose a piece for. */
function mam_recital_open_calls(?string $now = null): array {
    $out = [];
    foreach (mam_db_all("SELECT * FROM recitals WHERE status = 'open' ORDER BY starts_at ASC, id ASC") as $r) {
        if (mam_recital_accepting($r, $now)) $out[] = $r;
    }
    return $out;
}

/**
 * Propose a piece. A student may put a given repertoire entry forward once per
 * recital — re-proposing the same piece is idempotent rather than an error,
 * because the honest reading of a double submit is "I want to play this", not
 * "I want to play this twice".
 */
function mam_recital_propose(int $recitalId, int $studentId, array $f): int {
    $r = mam_recital($recitalId);
    if (!$r) throw new RuntimeException('not_found');
    if (!mam_recital_accepting($r)) throw new RuntimeException('submissions_closed');

    $repId = isset($f['repertoire_id']) && $f['repertoire_id'] !== null ? (int) $f['repertoire_id'] : null;
    $title = trim((string) ($f['title'] ?? ''));
    $composer = isset($f['composer']) ? trim((string) $f['composer']) : null;

    if ($repId !== null) {
        // The piece must be the student's own. Proposing from someone else's
        // repertoire would put their work on the programme under another name.
        $rep = mam_db_row('SELECT id, student_id, title, composer FROM repertoire WHERE id = ?', [$repId]);
        if (!$rep || (int) $rep['student_id'] !== $studentId) throw new RuntimeException('not_your_piece');
        if ($title === '')    $title = (string) $rep['title'];
        if ($composer === null || $composer === '') $composer = $rep['composer'] !== null ? (string) $rep['composer'] : null;

        $dupe = mam_db_row("SELECT id FROM recital_items WHERE recital_id = ? AND student_id = ? AND repertoire_id = ? AND status IN ('proposed','accepted')", [$recitalId, $studentId, $repId]);
        if ($dupe) return (int) $dupe['id'];
    }
    if ($title === '') throw new InvalidArgumentException('title_required');

    $id = mam_db_insert('recital_items', [
        'recital_id'    => $recitalId,
        'student_id'    => $studentId,
        'repertoire_id' => $repId,
        'submission_id' => isset($f['submission_id']) && $f['submission_id'] ? (int) $f['submission_id'] : null,
        'title'         => mb_substr($title, 0, 200),
        'composer'      => $composer !== null && $composer !== '' ? mb_substr($composer, 0, 160) : null,
        'arranger'      => isset($f['arranger']) && $f['arranger'] !== '' ? mb_substr(trim((string) $f['arranger']), 0, 160) : null,
        'movement'      => isset($f['movement']) && $f['movement'] !== '' ? mb_substr(trim((string) $f['movement']), 0, 160) : null,
        'instrument'    => isset($f['instrument']) && $f['instrument'] !== '' ? mb_substr(trim((string) $f['instrument']), 0, 80) : null,
        // A duration is what makes a running order possible at all. Clamped so
        // one optimistic entry cannot push the interval past midnight.
        'duration_min'  => max(1, min(60, (int) ($f['duration_min'] ?? 4))),
        'note'          => isset($f['note']) && $f['note'] !== '' ? mb_substr(trim((string) $f['note']), 0, 1000) : null,
        'status'        => 'proposed',
        'sort_order'    => 0,
    ]);
    if (function_exists('mam_audit')) mam_audit('recital_propose', 'recital_item', $id, ['recital' => $recitalId, 'student' => $studentId]);
    return $id;
}

/** A student withdraws their own proposal. */
function mam_recital_withdraw(int $itemId, int $studentId): bool {
    $it = mam_db_row('SELECT * FROM recital_items WHERE id = ?', [$itemId]);
    if (!$it) throw new RuntimeException('not_found');
    if ((int) $it['student_id'] !== $studentId) throw new RuntimeException('forbidden');
    if (in_array((string) $it['status'], ['performed', 'withdrawn'], true)) return false;
    mam_db_update('recital_items', ['status' => 'withdrawn'], ['id' => $itemId]);
    if (function_exists('mam_audit')) mam_audit('recital_withdraw', 'recital_item', $itemId, ['student' => $studentId]);
    return true;
}

/**
 * Staff decision on a proposal. Accepting places the item at the end of its
 * section, so a curator's first job is only ever "yes or no" — ordering is a
 * separate, deliberate act (mam_recital_reorder).
 */
function mam_recital_decide(int $itemId, string $decision, ?int $by = null, ?string $note = null): bool {
    if (!in_array($decision, ['accepted', 'declined'], true)) throw new InvalidArgumentException('bad_decision');
    $it = mam_db_row('SELECT * FROM recital_items WHERE id = ?', [$itemId]);
    if (!$it) throw new RuntimeException('not_found');
    if ((string) $it['status'] === 'performed') throw new RuntimeException('already_performed');

    $set = ['status' => $decision, 'decided_by' => $by, 'decided_at' => date('Y-m-d H:i:s')];
    if ($note !== null && $note !== '') $set['note'] = mb_substr($note, 0, 1000);
    if ($decision === 'accepted' && (int) $it['sort_order'] === 0) {
        $max = mam_db_row("SELECT COALESCE(MAX(sort_order),0) AS m FROM recital_items WHERE recital_id = ? AND section = ? AND status = 'accepted'", [(int) $it['recital_id'], (string) $it['section']]);
        $set['sort_order'] = ((int) ($max['m'] ?? 0)) + 10;
    }
    mam_db_update('recital_items', $set, ['id' => $itemId]);
    mam_recital_notify_decision((int) $itemId, $decision);
    if (function_exists('mam_audit')) mam_audit('recital_decide', 'recital_item', $itemId, ['decision' => $decision, 'by' => $by]);
    return true;
}

/**
 * Set the running order. Positions are rewritten in tens, which leaves room to
 * drop a piece between two others later without renumbering the whole half.
 * @param array $order [[item_id, section], …] in the order they will be played
 */
function mam_recital_reorder(int $recitalId, array $order, ?int $by = null): int {
    $n = 0; $pos = [];
    foreach ($order as $row) {
        $itemId  = (int) ($row['id'] ?? $row[0] ?? 0);
        $section = (string) ($row['section'] ?? $row[1] ?? 'main');
        if ($itemId < 1) continue;
        if (!in_array($section, ['opening', 'main', 'finale'], true)) $section = 'main';
        $it = mam_db_row('SELECT id FROM recital_items WHERE id = ? AND recital_id = ?', [$itemId, $recitalId]);
        if (!$it) continue;   // silently ignore an item from another recital
        $pos[$section] = ($pos[$section] ?? 0) + 10;
        mam_db_update('recital_items', ['section' => $section, 'sort_order' => $pos[$section]], ['id' => $itemId]);
        $n++;
    }
    if ($n && function_exists('mam_audit')) mam_audit('recital_reorder', 'recital', $recitalId, ['items' => $n, 'by' => $by]);
    return $n;
}

/** Every item on a recital, newest proposals last. */
function mam_recital_items(int $recitalId, ?array $statuses = null): array {
    $sql = "SELECT ri.*, u.name AS student_name, u.avatar_url,
                   s.artifact_url, s.artifact_kind
              FROM recital_items ri
              JOIN users u ON u.id = ri.student_id
         LEFT JOIN submissions s ON s.id = ri.submission_id
             WHERE ri.recital_id = ?";
    $args = [$recitalId];
    if ($statuses) {
        $sql .= ' AND ri.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
        $args = array_merge($args, $statuses);
    }
    // Section first, then position, then id — the id tiebreak keeps the order
    // deterministic when two items share a position (which reorder avoids, but
    // a hand-edited row can still produce).
    $sql .= " ORDER BY CASE ri.section WHEN 'opening' THEN 0 WHEN 'main' THEN 1 ELSE 2 END, ri.sort_order ASC, ri.id ASC";
    return mam_db_all($sql, $args);
}

/**
 * The programme: the accepted running order with a CLOCK TIME against every
 * item, computed from the start time, each duration, and the interval.
 *
 * This is the thing that makes it a programme rather than a list. A parent
 * reading it wants to know when their child plays, and an academy that can
 * answer that to the minute looks like an institution rather than a class.
 *
 * @return array{recital:array,sections:array,total_min:int,ends_at:?string}
 */
function mam_recital_programme_full(int $recitalId): array {
    $r = mam_recital($recitalId);
    if (!$r) throw new RuntimeException('not_found');
    $items = mam_recital_items($recitalId, ['accepted', 'performed']);

    $startTs = null;
    if (!empty($r['starts_at'])) {
        $t = strtotime((string) $r['starts_at']);
        if ($t !== false) $startTs = $t;
    }
    $intervalAfter = $r['interval_after'] !== null ? (int) $r['interval_after'] : null;
    $intervalMin   = max(0, (int) ($r['interval_min'] ?? 15));

    $cursor = $startTs;
    $total  = 0;
    $rows   = [];
    $idx    = 0;
    foreach ($items as $it) {
        $idx++;
        $dur = max(1, (int) $it['duration_min']);
        $rows[] = [
            'id'            => (int) $it['id'],
            'position'      => $idx,
            'section'       => (string) $it['section'],
            'student_id'    => (int) $it['student_id'],
            'student_name'  => (string) $it['student_name'],
            'avatar_url'    => $it['avatar_url'],
            'title'         => (string) $it['title'],
            'composer'      => $it['composer'],
            'arranger'      => $it['arranger'],
            'movement'      => $it['movement'],
            'instrument'    => $it['instrument'],
            'duration_min'  => $dur,
            'status'        => (string) $it['status'],
            'note'          => $it['note'],
            'recording_url' => $it['artifact_kind'] === 'audio' ? $it['artifact_url'] : null,
            'at'            => $cursor !== null ? date('H:i', $cursor) : null,
        ];
        if ($cursor !== null) $cursor += $dur * 60;
        $total += $dur;

        // The interval sits AFTER the nth piece, so it can be placed by
        // position without the curator having to think in minutes.
        if ($intervalAfter !== null && $idx === $intervalAfter && $intervalMin > 0) {
            $rows[] = [
                'id' => null, 'position' => null, 'section' => 'interval', 'interval' => true,
                'title' => 'Interval', 'duration_min' => $intervalMin,
                'at' => $cursor !== null ? date('H:i', $cursor) : null,
            ];
            if ($cursor !== null) $cursor += $intervalMin * 60;
            $total += $intervalMin;
        }
    }

    // Group for rendering, preserving the computed order.
    $sections = [];
    foreach ($rows as $row) {
        $k = $row['section'];
        $sections[$k][] = $row;
    }

    return [
        'recital'   => $r,
        'items'     => $rows,
        'sections'  => $sections,
        'total_min' => $total,
        'ends_at'   => ($startTs !== null) ? date('H:i', $startTs + $total * 60) : null,
        'performer_count' => count(array_unique(array_column(array_filter($rows, fn($x) => !empty($x['student_id'])), 'student_id'))),
    ];
}

/** Recitals visible to the public: programmed or completed, newest first. */
function mam_recital_public_list(int $limit = 20): array {
    $lim = max(1, min(100, $limit));
    return mam_db_all("SELECT * FROM recitals WHERE status IN ('programmed','completed') ORDER BY COALESCE(starts_at, created_at) DESC LIMIT $lim");
}

/** Everything a student has ever put forward, across all recitals. */
function mam_recital_for_student(int $studentId): array {
    return mam_db_all(
        "SELECT ri.*, r.title AS recital_title, r.slug, r.status AS recital_status, r.starts_at, r.venue
           FROM recital_items ri JOIN recitals r ON r.id = ri.recital_id
          WHERE ri.student_id = ?
          ORDER BY COALESCE(r.starts_at, r.created_at) DESC, ri.id DESC",
        [$studentId]
    );
}

/** Admin listing with a proposal count, so the queue is visible at a glance. */
function mam_recital_admin_list(int $limit = 100): array {
    $lim = max(1, min(200, $limit));
    return mam_db_all(
        "SELECT r.*,
                (SELECT COUNT(*) FROM recital_items i WHERE i.recital_id = r.id AND i.status = 'proposed') AS proposed_count,
                (SELECT COUNT(*) FROM recital_items i WHERE i.recital_id = r.id AND i.status IN ('accepted','performed')) AS programmed_count
           FROM recitals r ORDER BY COALESCE(r.starts_at, r.created_at) DESC LIMIT $lim"
    );
}

/* ── notifications ─────────────────────────────────────────────────────────
   Every one of these is best-effort. A recital must never fail to be
   published because a mailbox was unreachable. */

function mam_recital_notify_decision(int $itemId, string $decision): void {
    if (!function_exists('mam_notify')) return;
    try {
        $it = mam_db_row('SELECT ri.*, r.title AS recital_title, r.slug FROM recital_items ri JOIN recitals r ON r.id = ri.recital_id WHERE ri.id = ?', [$itemId]);
        if (!$it) return;
        if ($decision === 'accepted') {
            mam_notify((int) $it['student_id'], 'recital.accepted',
                'You are on the programme for ' . $it['recital_title'],
                '"' . $it['title'] . '" has been accepted. Keep polishing — we will publish the running order soon.',
                '/recital/' . $it['slug']);
        } else {
            // Declining is the moment to be a teacher rather than a gatekeeper.
            mam_notify((int) $it['student_id'], 'recital.declined',
                'About your ' . $it['recital_title'] . ' submission',
                '"' . $it['title'] . '" is not on this programme. Your teacher can suggest what to bring to the next one — putting yourself forward is the hard part, and you did it.',
                '/portal/student/#recitals');
        }
    } catch (\Throwable $e) { /* never block a decision on a notification */ }
}

/** Tell performers and their guardians that the programme is live. */
function mam_recital_notify_programmed(int $recitalId): void {
    if (!function_exists('mam_notify')) return;
    try {
        $r = mam_recital($recitalId);
        if (!$r) return;
        $when = !empty($r['starts_at']) ? date('j M Y, H:i', strtotime((string) $r['starts_at'])) : 'a date to be confirmed';
        $seen = [];
        foreach (mam_recital_items($recitalId, ['accepted']) as $it) {
            $sid = (int) $it['student_id'];
            if (isset($seen[$sid])) continue;
            $seen[$sid] = true;
            mam_notify($sid, 'recital.programmed', 'The programme for ' . $r['title'] . ' is out',
                'You are performing on ' . $when . '. Share the programme with your family.', '/recital/' . $r['slug']);
            // Guardians get their own copy — the person who has to arrange the
            // lift is not usually the person holding the student login.
            if (function_exists('mam_parent_guardians_of')) {
                foreach (mam_parent_guardians_of($sid) as $g) {
                    mam_notify((int) $g['id'], 'recital.programmed', $r['title'] . ' — programme published',
                        $it['student_name'] . ' is performing on ' . $when . '.', '/recital/' . $r['slug']);
                }
            }
        }
    } catch (\Throwable $e) { /* publishing must not depend on the inbox */ }
}

function mam_recital_notify_cancelled(int $recitalId): void {
    if (!function_exists('mam_notify')) return;
    try {
        $r = mam_recital($recitalId);
        if (!$r) return;
        $seen = [];
        foreach (mam_recital_items($recitalId, ['accepted', 'proposed']) as $it) {
            $sid = (int) $it['student_id'];
            if (isset($seen[$sid])) continue;
            $seen[$sid] = true;
            mam_notify($sid, 'recital.cancelled', $r['title'] . ' has been cancelled',
                'We are sorry — this recital will not go ahead. Your teacher will be in touch about the next one.',
                '/portal/student/#recitals');
        }
    } catch (\Throwable $e) { /* a cancellation still stands if mail fails */ }
}

/* ── public shaping ────────────────────────────────────────────────────────
   Everything a logged-out visitor can see passes through these two functions.
   Treat them as the privacy boundary they are: a concert programme is meant to
   be forwarded to grandparents, so it goes out with no session behind it. */

/** The public face of a recital — no internal ids, no creator, no counts. */
function mam_recital_public_card(array $r): array {
    return [
        'slug'           => (string) $r['slug'],
        'title'          => (string) $r['title'],
        'subtitle'       => $r['subtitle'],
        'venue'          => $r['venue'],
        'starts_at'      => $r['starts_at'],
        'doors_open_at'  => $r['doors_open_at'],
        'status'         => (string) $r['status'],
        'description'    => $r['description'],
        'programme_note' => $r['programme_note'],
        'url'            => '/recital/' . $r['slug'],
    ];
}

/**
 * How a performer is named in public.
 *
 * A printed concert programme names its performers — that is the honour, and
 * withholding it would make the programme meaningless. But this academy
 * teaches children and already runs a consent register (§8.4), and the
 * leaderboard already settled the house style for a name in a public list:
 * first name plus last initial. So MEDIA consent is what earns a full name on
 * a page anyone can read; without it the student still appears, still gets
 * their piece printed, and is simply named the way the leaderboard names them.
 *
 * Never a silent omission: a child dropped from the programme because a form
 * was not signed would be the cruellest possible reading of a consent flag.
 */
function mam_recital_public_name(int $studentId, string $name): string {
    $full = function_exists('mam_consent_allows') ? mam_consent_allows($studentId, 'media') : true;
    if ($full) return $name;
    if (function_exists('mam_leaderboard_display_name')) return mam_leaderboard_display_name($name);
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    return ($parts[0] ?? 'A student') . (count($parts) > 1 ? ' ' . mb_strtoupper(mb_substr((string) end($parts), 0, 1)) . '.' : '');
}

/**
 * One line of the public programme.
 *
 * The recording is deliberately NOT included. Takes are access-gated
 * (/api/media/take checks the caller), and publishing the URL on a page with
 * no session would hand out a recording of a child to anyone with the link —
 * exactly the policy that endpoint exists to prevent. Recordings appear in the
 * portals, where there is someone to check.
 */
function mam_recital_public_item(array $it): array {
    if (!empty($it['interval'])) {
        return ['interval' => true, 'title' => 'Interval', 'duration_min' => (int) $it['duration_min'], 'at' => $it['at']];
    }
    return [
        'position'     => $it['position'],
        'section'      => $it['section'],
        'performer'    => mam_recital_public_name((int) $it['student_id'], (string) $it['student_name']),
        'title'        => (string) $it['title'],
        'composer'     => $it['composer'],
        'arranger'     => $it['arranger'],
        'movement'     => $it['movement'],
        'instrument'   => $it['instrument'],
        'duration_min' => (int) $it['duration_min'],
        'at'           => $it['at'],
    ];
}

/* ── The recordings gallery ────────────────────────────────────────────────
   Listening back after the night.

   A RECITAL RECORDING IS NOT A PRACTICE TAKE, and the difference decides the
   access policy. A take is private work: /api/media/take lets the student,
   their own teacher, their own guardian and an admin hear it, and nobody else.
   Applying that rule to a recital gallery would give each parent a gallery
   containing exactly one child — which is not a gallery, it is a filing
   cabinet. The point of listening back is hearing the EVENING.

   So a published recital recording has its own, deliberately different gate:

     1. The recital must be COMPLETED. Nothing is listenable before the night;
        a programme is a promise, not an archive.
     2. The performer must have MEDIA consent. This is the same flag that earns
        a full name on the public programme, and it is the one that families
        actually understand as "you may record and share my child".
     3. The listener must be SIGNED IN to the academy. Not public — a recording
        of a child never goes on the open web, no matter how completed the
        recital is or how freely the family consented. The audience is the
        community that was in the room, or could have been.

   Staff attach the recording explicitly; nothing is published by accident. */

/** Audio submissions belonging to a student — what staff can attach. */
function mam_recital_available_recordings(int $studentId, int $limit = 30): array {
    $lim = max(1, min(100, $limit));
    return mam_db_all(
        "SELECT s.id, s.artifact_url, s.submitted_at, a.title AS assignment_title
           FROM submissions s
      LEFT JOIN assignments a ON a.id = s.assignment_id
          WHERE s.student_id = ? AND s.artifact_kind = 'audio' AND s.artifact_url IS NOT NULL
          ORDER BY s.id DESC LIMIT $lim",
        [$studentId]
    );
}

/**
 * Attach (or clear, with null) the recording published against a recital item.
 * The submission must belong to the SAME student as the item — publishing one
 * child's playing under another child's name on a programme is the worst
 * mistake this feature could make, so it is checked rather than trusted.
 */
function mam_recital_attach_recording(int $itemId, ?int $submissionId, ?int $by = null): bool {
    $it = mam_db_row('SELECT id, student_id, recital_id FROM recital_items WHERE id = ?', [$itemId]);
    if (!$it) throw new RuntimeException('not_found');

    if ($submissionId !== null) {
        $sub = mam_db_row('SELECT id, student_id, artifact_kind, artifact_url FROM submissions WHERE id = ?', [$submissionId]);
        if (!$sub) throw new RuntimeException('recording_not_found');
        if ((int) $sub['student_id'] !== (int) $it['student_id']) throw new RuntimeException('not_their_recording');
        if ((string) $sub['artifact_kind'] !== 'audio' || empty($sub['artifact_url'])) throw new RuntimeException('not_audio');
    }

    mam_db_update('recital_items', ['submission_id' => $submissionId], ['id' => $itemId]);
    if (function_exists('mam_audit')) {
        mam_audit($submissionId === null ? 'recital_recording_cleared' : 'recital_recording_attached',
            'recital_item', $itemId, ['submission' => $submissionId, 'by' => $by]);
    }
    return true;
}

/**
 * Is this recital item's recording publishable at all? (Rules 1 and 2 above —
 * the listener check is rule 3 and lives in mam_recital_can_hear.)
 */
function mam_recital_recording_published(int $itemId): bool {
    $row = mam_db_row(
        'SELECT ri.student_id, ri.submission_id, r.status
           FROM recital_items ri JOIN recitals r ON r.id = ri.recital_id
          WHERE ri.id = ?',
        [$itemId]
    );
    if (!$row) return false;
    if ($row['submission_id'] === null) return false;
    if ((string) $row['status'] !== 'completed') return false;
    if (function_exists('mam_consent_allows') && !mam_consent_allows((int) $row['student_id'], 'media')) return false;
    return true;
}

/**
 * May this signed-in user hear this item's recording?
 *
 * Being signed in is the whole audience test on top of the publication rules:
 * the academy community, never the open web. A performer, their guardian and
 * their teacher additionally keep access through the private-take route even
 * when the gallery rules do not apply — that is deliberate, and lives in
 * /api/media/take rather than here.
 */
function mam_recital_can_hear(?array $user, int $itemId): bool {
    if (!$user || (int) ($user['id'] ?? 0) < 1) return false;
    return mam_recital_recording_published($itemId);
}

/**
 * The gallery for a completed recital: every item whose recording may be
 * published, in running order.
 *
 * An item with no recording, or whose performer withheld media consent, is
 * simply absent — the gallery says nothing about why, because "no recording of
 * this child" is not a fact that needs explaining to a room full of other
 * families.
 */
function mam_recital_gallery(int $recitalId): array {
    $r = mam_recital($recitalId);
    if (!$r || (string) $r['status'] !== 'completed') return [];
    $out = [];
    foreach (mam_recital_items($recitalId, ['performed', 'accepted']) as $it) {
        if (!mam_recital_recording_published((int) $it['id'])) continue;
        $out[] = [
            'item_id'    => (int) $it['id'],
            'title'      => (string) $it['title'],
            'composer'   => $it['composer'],
            'movement'   => $it['movement'],
            'instrument' => $it['instrument'],
            // The gallery is signed-in only, but it is still a shared page, so
            // it follows the same naming rule as the printed programme.
            'performer'  => mam_recital_public_name((int) $it['student_id'], (string) $it['student_name']),
            'audio_url'  => '/api/media/recital-take?item=' . (int) $it['id'],
        ];
    }
    return $out;
}

/**
 * A guardian's view of one child's recital life: what is coming, and what they
 * have already played.
 *
 * `slot_at` is the clock time that child steps out — computed from the same
 * running order the printed programme uses, so a parent planning the evening
 * reads exactly what the programme says rather than a second, drifting answer.
 */
function mam_recital_for_guardian(int $studentId): array {
    $upcoming = []; $past = [];
    foreach (mam_recital_for_student($studentId) as $it) {
        $status  = (string) $it['recital_status'];
        $itemSt  = (string) $it['status'];
        // A guardian is shown what is real: a piece on a published programme,
        // or one already performed. Proposals under consideration are the
        // student's own business until a teacher has decided.
        if (!in_array($itemSt, ['accepted', 'performed'], true)) continue;
        if (!in_array($status, ['programmed', 'completed'], true)) continue;

        $slot = null;
        try {
            foreach (mam_recital_programme_full((int) $it['recital_id'])['items'] as $row) {
                if (!empty($row['id']) && (int) $row['id'] === (int) $it['id']) { $slot = $row['at']; break; }
            }
        } catch (\Throwable $e) { /* a missing programme must not hide the row */ }

        $entry = [
            'item_id'       => (int) $it['id'],
            'recital_title' => (string) $it['recital_title'],
            'slug'          => (string) $it['slug'],
            'venue'         => $it['venue'],
            'starts_at'     => $it['starts_at'],
            'title'         => (string) $it['title'],
            'composer'      => $it['composer'],
            'instrument'    => $it['instrument'],
            'slot_at'       => $slot,
            'programme_url' => '/recital/' . $it['slug'],
            'audio_url'     => mam_recital_recording_published((int) $it['id']) ? '/api/media/recital-take?item=' . (int) $it['id'] : null,
        ];
        if ($status === 'completed') $past[] = $entry; else $upcoming[] = $entry;
    }
    return ['upcoming' => $upcoming, 'past' => $past];
}
