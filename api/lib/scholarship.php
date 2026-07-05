<?php
/**
 * MAM Scholars Initiative — scholarship application capture (flow-guide §2c).
 *
 * A parallel, OPT-IN track, deliberately separate from admissions: an essay-based
 * assessment with its own status. This lib only CAPTURES and reads applications —
 * it decides nothing. Awarding still routes through the admin `set-student-type`
 * action; a human committee decides. The advisory readiness signal reuses
 * mam_application_readiness() (validation.php) so requirements live in one place.
 *
 * Framing (binding copy elsewhere): investment & calling, never charity. The soft
 * requirements are shown transparently and are explicitly NOT pass/fail.
 */
declare(strict_types=1);

/** The transparent soft requirements — shown up front, never a gate. */
function mam_scholarship_requirements(): array {
    return [
        'Aged 16–26',
        'Some prior musical experience (not a complete beginner)',
        'Own — or have reliable access to — your instrument',
        'Available for weekly classes, including Saturdays',
        'Demonstrable financial need',
        'Alignment with MAM’s values & a heart to serve your community',
        'Committed to completing the programme',
    ];
}

function mam_scholarship_age_bands(): array { return ['under-16', '16-20', '21-26', '27-plus']; }
function mam_scholarship_experience(): array { return ['none', 'some', 'intermediate', 'advanced']; }

/** Nominal age for a band, so the shared readiness derivation can run. */
function mam_scholarship_band_age(string $band): ?int {
    return ['under-16' => 15, '16-20' => 18, '21-26' => 24, '27-plus' => 30][$band] ?? null;
}

/**
 * Sanitize + validate a scholarship submission. Returns
 * ['errors' => string[], 'data' => array] — the route turns a non-empty
 * `errors` into a 422. Never throws, so it is unit-testable without the
 * mam_fail() test stub firing.
 */
function mam_scholarship_validate(array $in): array {
    $errors = [];
    $d = [];
    $d['full_name'] = mam_str($in, 'full_name', 160);
    if (mb_strlen($d['full_name']) < 2) $errors[] = 'full_name';

    $email = mam_email(mam_str($in, 'email', 190));
    $d['email'] = $email ?: '';
    if (!$email) $errors[] = 'email';

    $ab = mam_str($in, 'age_band', 16);
    $d['age_band'] = mam_in($ab, mam_scholarship_age_bands()) ? $ab : null;
    $ex = mam_str($in, 'experience', 32);
    $d['experience'] = mam_in($ex, mam_scholarship_experience()) ? $ex : null;

    // Four essay sections — each required, bounded. (The "Commitment" section is
    // the explicit checkbox below, per the guide.)
    foreach (['financial_need', 'musical_journey', 'values_alignment', 'community_impact'] as $f) {
        $v = mam_str($in, $f, 2000);
        $d[$f] = $v !== '' ? $v : null;
        if (mb_strlen($v) < 10) $errors[] = $f; // a meaningful answer, not a gate on content
    }

    $d['commitment_ack'] = !empty($in['commitment_ack']) ? 1 : 0;
    if (!$d['commitment_ack']) $errors[] = 'commitment_ack';

    $d['application_reference'] = mam_str($in, 'application_reference', 64) ?: null;

    // Advisory only — reuse the single readiness derivation (age + experience).
    $rd = mam_application_readiness([
        'age'        => $d['age_band'] ? mam_scholarship_band_age($d['age_band']) : null,
        'experience' => $d['experience'],
    ]);
    $d['readiness_signal'] = $rd['signal'];

    return ['errors' => $errors, 'data' => $d];
}

/**
 * Persist a validated submission. Returns ['reference','status']. Audited.
 * `application_reference` is stored only if it matches a real application (soft
 * link; a scholarship application can also stand alone).
 */
function mam_scholarship_create(array $d, ?string $ip = null): array {
    // Self-contained reference (no dependency on bootstrap's mam_random_id, so
    // the lib is unit-testable): SCH- + 10 hex chars.
    $reference = 'SCH-' . strtoupper(bin2hex(random_bytes(5)));

    $appRef = null;
    if (!empty($d['application_reference'])) {
        $row = mam_db_row('SELECT reference FROM applications WHERE reference = ?', [$d['application_reference']]);
        if ($row) $appRef = (string)$row['reference'];
    }

    mam_db_insert('scholarship_applications', [
        'reference'             => $reference,
        'application_reference' => $appRef,
        'full_name'             => $d['full_name'],
        'email'                 => strtolower(trim($d['email'])),
        'age_band'              => $d['age_band'],
        'experience'            => $d['experience'],
        'financial_need'        => $d['financial_need'],
        'musical_journey'       => $d['musical_journey'],
        'values_alignment'      => $d['values_alignment'],
        'community_impact'      => $d['community_impact'],
        'commitment_ack'        => (int)$d['commitment_ack'],
        'readiness_signal'      => $d['readiness_signal'],
        'status'                => 'submitted',
        'ip'                    => $ip,
    ]);
    $id = (int)(mam_db_row('SELECT id FROM scholarship_applications WHERE reference = ?', [$reference])['id'] ?? 0);
    mam_audit('create', 'scholarship_application', $id, ['reference' => $reference, 'readiness' => $d['readiness_signal']]);

    return ['reference' => $reference, 'status' => 'submitted'];
}

/** Public status lookup — reference + matching email (mirrors application status). */
function mam_scholarship_status(string $reference, string $email): ?array {
    $reference = trim($reference);
    $email = strtolower(trim($email));
    if ($reference === '' || $email === '') return null;
    $row = mam_db_row('SELECT reference, status, created_at FROM scholarship_applications WHERE reference = ? AND email = ?', [$reference, $email]);
    if (!$row) return null;
    return ['reference' => (string)$row['reference'], 'status' => (string)$row['status'], 'submitted_at' => (string)$row['created_at']];
}
