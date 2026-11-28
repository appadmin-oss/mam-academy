<?php
/**
 * MAM Academy — consent & safeguarding center (Advanced Modernization §8.4).
 * ─────────────────────────────────────────────────────────────
 * One place for media, photo and data consent plus a safeguarding contact per
 * minor. Default-DENY: a student with no record has no consent. Every media /
 * showcase / public surface must gate on mam_consent_allows() so child-safety
 * and NDPR are systematic, not manual.
 */
declare(strict_types=1);

/**
 * Current consent state for a student. Each of media|photo|data is TRUE
 * (agreed), FALSE (declined) or NULL (not answered yet) — NULL is never "no"
 * on screen, and is still a refusal at the gate (mam_consent_allows()).
 * Answered-ness comes from the v112 *_decided_at stamps; before the migration
 * has run, a row a person wrote counts as answered.
 */
function mam_consent_get(int $studentId): array {
    $row = null;
    try { $row = mam_db_row("SELECT * FROM student_consents WHERE student_id = ?", [$studentId]); } catch (\Throwable $e) {}
    $out = [];
    foreach (['media', 'photo', 'data'] as $k) {
        if (!$row) { $out[$k] = null; continue; }
        $answered = array_key_exists($k . '_decided_at', $row)
            ? ($row[$k . '_decided_at'] !== null && $row[$k . '_decided_at'] !== '')
            : ($row['updated_by'] !== null);
        $out[$k] = $answered ? (bool) ($row[$k . '_consent'] ?? false) : null;
    }
    $out['safeguarding_contact'] = $row['safeguarding_contact'] ?? null;
    $out['updated_at'] = $row['updated_at'] ?? null;
    return $out;
}

/**
 * Record consent (upsert). $flags: media|photo|data — true, false, or ABSENT
 * (or null) to leave that one as it stands; plus safeguarding_contact. Only a
 * key that is given is stamped as answered, so recording one consent no
 * longer records the other two as "declined".
 */
function mam_consent_set(int $studentId, array $flags, ?int $by = null): void {
    $now = date('Y-m-d H:i:s');
    $data = ['updated_by' => $by, 'updated_at' => $now];
    foreach (['media', 'photo', 'data'] as $k) {
        if (!array_key_exists($k, $flags) || $flags[$k] === null) continue;
        $data[$k . '_consent'] = !empty($flags[$k]) ? 1 : 0;
        $data[$k . '_decided_at'] = $now;
    }
    if (array_key_exists('safeguarding_contact', $flags)) {
        $data['safeguarding_contact'] = mb_substr(trim((string) $flags['safeguarding_contact']), 0, 500) ?: null;
    }
    if (mam_db_row("SELECT student_id FROM student_consents WHERE student_id = ?", [$studentId])) {
        mam_db_update('student_consents', $data, ['student_id' => $studentId]);
    } else {
        $data['student_id'] = $studentId;
        mam_db_insert('student_consents', $data);
    }
    if (function_exists('mam_audit')) mam_audit('consent_set', 'user', $studentId, ['by' => $by,
        'media' => $data['media_consent'] ?? null, 'photo' => $data['photo_consent'] ?? null, 'data' => $data['data_consent'] ?? null]);
}

/**
 * The single enforcement gate. Returns true only if the named consent
 * ('media'|'photo'|'data') is explicitly granted. Default-deny, so any media
 * or showcase feature that calls this is safe-by-default for minors.
 */
function mam_consent_allows(int $studentId, string $type): bool {
    $c = mam_consent_get($studentId);
    return (bool) ($c[$type] ?? false);
}
