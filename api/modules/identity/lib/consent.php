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

/** Current consent state for a student (defaults all-false). */
function mam_consent_get(int $studentId): array {
    $row = null;
    try { $row = mam_db_row("SELECT * FROM student_consents WHERE student_id = ?", [$studentId]); } catch (\Throwable $e) {}
    return [
        'media'                => (bool) ($row['media_consent'] ?? false),
        'photo'                => (bool) ($row['photo_consent'] ?? false),
        'data'                 => (bool) ($row['data_consent'] ?? false),
        'safeguarding_contact' => $row['safeguarding_contact'] ?? null,
        'updated_at'           => $row['updated_at'] ?? null,
    ];
}

/** Set consent (upsert). $flags: media|photo|data (bool), safeguarding_contact. */
function mam_consent_set(int $studentId, array $flags, ?int $by = null): void {
    $data = [
        'media_consent'        => !empty($flags['media']) ? 1 : 0,
        'photo_consent'        => !empty($flags['photo']) ? 1 : 0,
        'data_consent'         => !empty($flags['data'])  ? 1 : 0,
        'safeguarding_contact' => array_key_exists('safeguarding_contact', $flags)
            ? (mb_substr(trim((string) $flags['safeguarding_contact']), 0, 500) ?: null) : null,
        'updated_by'           => $by,
        'updated_at'           => date('Y-m-d H:i:s'),
    ];
    if (mam_db_row("SELECT student_id FROM student_consents WHERE student_id = ?", [$studentId])) {
        mam_db_update('student_consents', $data, ['student_id' => $studentId]);
    } else {
        $data['student_id'] = $studentId;
        mam_db_insert('student_consents', $data);
    }
    if (function_exists('mam_audit')) mam_audit('consent_set', 'user', $studentId, ['by' => $by, 'media' => $data['media_consent'], 'photo' => $data['photo_consent'], 'data' => $data['data_consent']]);
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
