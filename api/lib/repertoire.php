<?php
/**
 * MAM Academy — repertoire → recital programme (Advanced Modernization §1.7).
 * ─────────────────────────────────────────────────────────────
 * A living list of pieces a student is learning / has polished / has performed.
 * The polished + performed pieces assemble into a recital programme — a
 * long-term progress narrative a parent can see and a student can be proud of.
 */
declare(strict_types=1);

function mam_repertoire_statuses(): array { return ['learning', 'polished', 'performed']; }

/** A student's full repertoire, ordered. */
function mam_repertoire_list(int $studentId): array {
    return mam_db_all("SELECT * FROM repertoire WHERE student_id = ? ORDER BY sort_order, id", [$studentId]);
}

/** The recital programme: polished + performed pieces, in order. */
function mam_repertoire_programme(int $studentId): array {
    return mam_db_all(
        "SELECT * FROM repertoire WHERE student_id = ? AND status IN ('polished','performed') ORDER BY sort_order, id",
        [$studentId]
    );
}

/** Add a piece. Returns the new id. */
function mam_repertoire_add(int $studentId, string $title, ?string $composer = null, string $status = 'learning', ?string $notes = null): int {
    $title = trim($title);
    if ($title === '') throw new InvalidArgumentException('title_required');
    if (!in_array($status, mam_repertoire_statuses(), true)) $status = 'learning';
    return mam_db_insert('repertoire', [
        'student_id' => $studentId,
        'title'      => mb_substr($title, 0, 200),
        'composer'   => ($composer !== null && trim($composer) !== '') ? mb_substr(trim($composer), 0, 160) : null,
        'status'     => $status,
        'notes'      => ($notes !== null && trim($notes) !== '') ? mb_substr(trim($notes), 0, 1000) : null,
    ]);
}

/** Update a piece the student owns (title/composer/status/notes). */
function mam_repertoire_update(int $id, int $studentId, array $fields): bool {
    $row = mam_db_row("SELECT student_id FROM repertoire WHERE id = ?", [$id]);
    if (!$row) return false;
    if ((int) $row['student_id'] !== $studentId) throw new RuntimeException('forbidden');
    $upd = ['updated_at' => date('Y-m-d H:i:s')];
    if (isset($fields['title'])) {
        $t = trim((string) $fields['title']);
        if ($t === '') throw new InvalidArgumentException('title_required');
        $upd['title'] = mb_substr($t, 0, 200);
    }
    if (array_key_exists('composer', $fields)) $upd['composer'] = ($fields['composer'] !== null && trim((string) $fields['composer']) !== '') ? mb_substr(trim((string) $fields['composer']), 0, 160) : null;
    if (isset($fields['status']) && in_array($fields['status'], mam_repertoire_statuses(), true)) $upd['status'] = $fields['status'];
    if (array_key_exists('notes', $fields)) $upd['notes'] = ($fields['notes'] !== null && trim((string) $fields['notes']) !== '') ? mb_substr(trim((string) $fields['notes']), 0, 1000) : null;
    mam_db_update('repertoire', $upd, ['id' => $id]);
    return true;
}

/** Delete a piece the student owns. */
function mam_repertoire_delete(int $id, int $studentId): bool {
    $row = mam_db_row("SELECT student_id FROM repertoire WHERE id = ?", [$id]);
    if (!$row) return false;
    if ((int) $row['student_id'] !== $studentId) throw new RuntimeException('forbidden');
    mam_db()->prepare("DELETE FROM repertoire WHERE id = ?")->execute([$id]);
    return true;
}
