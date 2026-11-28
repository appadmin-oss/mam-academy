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

/** The programme's name, everywhere (it was "NGV Scholarship" on the site and in mail). */
const MAM_SCHOLARS_NAME = 'MAM Scholars Initiative';

/**
 * Tell the applicant their application arrived, and the office that there is one
 * to read (admissions fix 4). Neither used to happen: the submission was stored,
 * nothing was sent, and nothing in the console listed it. Both through mam_send()
 * (mail_log). Best-effort: a failed message never loses the application.
 * Returns ['applicant' => bool, 'office' => bool].
 */
function mam_scholarship_notify(string $reference): array {
    $out = ['applicant' => false, 'office' => false];
    $row = mam_db_row('SELECT * FROM scholarship_applications WHERE reference = ?', [$reference]);
    if (!$row) return $out;
    $h = static fn($x) => htmlspecialchars((string) $x, ENT_QUOTES);
    $base = rtrim((string) mam_env('APP_URL', 'https://academy.mamcentre.org'), '/');
    $first = trim(explode(' ', trim((string) $row['full_name']))[0] ?? '') ?: 'there';
    $p = static fn($t) => "<p style='margin:0 0 12px;font-size:15px;line-height:1.7;color:#4A3624'>$t</p>";
    $out['applicant'] = mam_send((string) $row['email'], 'Your ' . MAM_SCHOLARS_NAME . ' application (' . $row['reference'] . ')',
        mam_email_layout(['header' => 'MAM ACADEMY', 'preheader' => 'We have your ' . MAM_SCHOLARS_NAME . ' application.',
            'reason' => 'You received this because you applied to the ' . MAM_SCHOLARS_NAME . '.',
            'body' => "<h2 style='font-family:Georgia,serif;margin:0 0 14px;font-size:22px;color:#1A120B'>Thank you, " . $h($first) . '.</h2>'
                . $p('We have your application to the ' . MAM_SCHOLARS_NAME . '. Your reference is <strong>' . $h($row['reference']) . '</strong>.')
                . $p('A committee reads every application in full. The requirements we published are guidance, not a pass-or-fail test, and your MAM Auditer assessment informs the decision but never makes it. We will write to you with the outcome.')
                . $p("You can check where it stands at any time: <a href='" . $h($base . '/status?ref=' . rawurlencode((string) $row['reference'])) . "' style='color:#B8721B;font-weight:600'>your application status</a>, with this reference and this email address.")]),
        'scholarship_confirmation', true);
    $notify = (string) mam_env('NOTIFY_EMAIL', '');
    if ($notify !== '') {
        $out['office'] = mam_send($notify, 'New ' . MAM_SCHOLARS_NAME . ' application — ' . $row['reference'],
            mam_email_admin_notice('New ' . MAM_SCHOLARS_NAME . ' application', [
                'Reference' => (string) $row['reference'], 'Name' => (string) $row['full_name'], 'Email' => (string) $row['email'],
                'Age band' => (string) ($row['age_band'] ?? '—'), 'Experience' => (string) ($row['experience'] ?? '—'),
                'Programme application' => (string) ($row['application_reference'] ?? '') ?: 'None linked',
                'Readiness (advisory)' => (string) ($row['readiness_signal'] ?? '—'),
            ], ['lede' => (string) $row['full_name'] . ' applied to the ' . MAM_SCHOLARS_NAME . '. The essays are on the console.',
                'cta' => ['url' => $base . '/admin/console.php?v=admissions&type=scholarship&app=' . (int) $row['id'], 'label' => 'Read the application']]),
            'scholarship_admin_notice', true, ['reply_to' => (string) $row['email']]);
    }
    return $out;
}

/** The scholarship status as the /status page shows any application: a stage,
 *  its label and the next step (admissions fix 4 — /status refused SCH- refs). */
function mam_scholarship_status_view(string $reference, string $email): ?array {
    $st = mam_scholarship_status($reference, $email);
    if (!$st) return null;
    $row = mam_db_row('SELECT full_name FROM scholarship_applications WHERE reference = ?', [$st['reference']]);
    $map = [
        'submitted' => ['received', 'Application received', 'The committee reads every application in full. We will write to you with the outcome.'],
        'reviewing' => ['reviewing', 'With the committee', 'The committee is reading your application. We will write to you with the outcome.'],
        'awarded'   => ['accepted', 'Awarded', 'Congratulations — watch your email for what happens next.'],
        'declined'  => ['closed', 'Not awarded this time', 'Thank you for applying. Your email from the committee explains the decision.'],
    ];
    [$stage, $label, $next] = $map[$st['status']] ?? $map['submitted'];
    return [
        'reference' => $st['reference'], 'first_name' => trim(explode(' ', trim((string) ($row['full_name'] ?? '')))[0] ?? ''),
        'programme' => MAM_SCHOLARS_NAME, 'status' => $st['status'], 'stage' => $stage, 'stage_label' => $label,
        'next_step' => $next, 'submitted_at' => $st['submitted_at'], 'is_scholarship' => true,
    ];
}

/** Public status lookup — reference + matching email (mirrors application status). */
function mam_scholarship_status(string $reference, string $email): ?array {
    $reference = trim($reference);
    $email = strtolower(trim($email));
    if ($reference === '' || $email === '') return null;
    $row = mam_db_row('SELECT reference, status, created_at FROM scholarship_applications WHERE UPPER(reference) = UPPER(?) AND email = ?', [$reference, $email]);
    if (!$row) return null;
    return ['reference' => (string)$row['reference'], 'status' => (string)$row['status'], 'submitted_at' => (string)$row['created_at']];
}
