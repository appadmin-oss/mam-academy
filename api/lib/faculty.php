<?php
/**
 * Faculty (instructor) applications — approval-gated, single-use invites.
 *
 * Flow:
 *   1. Someone expresses interest in teaching (contact form) → a REQUEST row
 *      in `faculty_invites` (status 'requested'). Nothing is emailed yet.
 *   2. An admin APPROVES the request (or invites someone directly) → the row
 *      gains a single-use nonce + a 7-day expiry, and the application-form
 *      link is emailed (status 'invited').
 *   3. The candidate opens /instructor-apply?token=… — the token is
 *      HMAC-signed over (invite id + nonce) so it can't be guessed or
 *      tampered with, and the DB row is the single source of truth for
 *      "already used" / "expired".
 *   4. Submitting the form stores the application, CONSUMES the invite
 *      (status 'used' — the link is never reusable), and directs the
 *      candidate to book their faculty interview appointment.
 */
declare(strict_types=1);

const MAM_FACULTY_INVITE_DAYS = 7;

function mam_faculty_b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function mam_faculty_unb64(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/'), true); }

/** Where a submitted candidate books their interview. */
function mam_faculty_book_url(): string {
    return rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/') . '/book';
}

/** The application-form URL for an invite row (must be approved/invited). */
function mam_faculty_apply_url(array $invite): string {
    $raw = (int)$invite['id'] . '|' . (string)$invite['nonce'];
    $sig = hash_hmac('sha256', $raw, mam_app_key());
    $token = mam_faculty_b64($raw . '|' . $sig);
    return rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/') . '/instructor-apply?token=' . urlencode($token);
}

/**
 * Record teaching interest (status 'requested'; nothing emailed). One open
 * request per email — repeat interest refreshes the existing row.
 * Returns the invite row id.
 */
function mam_faculty_request(string $email, ?string $name = null, string $source = 'contact'): int {
    $email = strtolower(trim($email));
    $open = mam_db_row("SELECT id FROM faculty_invites WHERE email = ? AND status IN ('requested','invited')", [$email]);
    if ($open) return (int)$open['id'];
    $id = mam_db_insert('faculty_invites', ['email' => $email, 'name' => $name ?: null, 'source' => $source, 'status' => 'requested']);
    mam_audit('faculty_request', 'faculty_invite', $id, ['email' => $email, 'source' => $source]);
    return $id;
}

/**
 * Approve a request (or mint a direct invite): assigns the single-use nonce +
 * 7-day expiry and returns the fresh row (email the link with
 * mam_faculty_apply_url). Re-approving an 'invited' row re-issues a NEW nonce
 * (the old link stops working) and resets the clock.
 */
function mam_faculty_approve(int $inviteId, ?int $by = null): ?array {
    $inv = mam_db_row('SELECT * FROM faculty_invites WHERE id = ?', [$inviteId]);
    if (!$inv || $inv['status'] === 'used') return null;
    mam_db_update('faculty_invites', [
        'status'     => 'invited',
        'nonce'      => bin2hex(random_bytes(16)),
        'expires_at' => date('Y-m-d H:i:s', time() + MAM_FACULTY_INVITE_DAYS * 86400),
        'invited_by' => $by,
        'invited_at' => date('Y-m-d H:i:s'),
    ], ['id' => $inviteId]);
    mam_audit('faculty_approve', 'faculty_invite', $inviteId, ['by' => $by]);
    return mam_db_row('SELECT * FROM faculty_invites WHERE id = ?', [$inviteId]);
}

/** Dismiss a request (no email, link never issued). */
function mam_faculty_dismiss(int $inviteId, ?int $by = null): void {
    mam_db_update('faculty_invites', ['status' => 'dismissed'], ['id' => $inviteId]);
    mam_audit('faculty_dismiss', 'faculty_invite', $inviteId, ['by' => $by]);
}

/**
 * Verify a form token → the live invite row, or a string reason:
 * 'invalid' (bad signature/shape), 'used' (single-use — already submitted),
 * 'expired'. The DB row decides state; the HMAC only proves authenticity.
 * @return array|string
 */
function mam_faculty_verify_token(?string $token) {
    if (!$token) return 'invalid';
    $parts = explode('|', mam_faculty_unb64($token));
    if (count($parts) !== 3) return 'invalid';
    [$id, $nonce, $sig] = $parts;
    if (!hash_equals(hash_hmac('sha256', $id . '|' . $nonce, mam_app_key()), $sig)) return 'invalid';
    $inv = mam_db_row('SELECT * FROM faculty_invites WHERE id = ?', [(int)$id]);
    if (!$inv || (string)$inv['nonce'] !== $nonce || $inv['status'] === 'dismissed' || $inv['status'] === 'requested') return 'invalid';
    if ($inv['status'] === 'used') return 'used';
    if (!$inv['expires_at'] || strtotime((string)$inv['expires_at']) < time()) return 'expired';
    return $inv;
}

/** Consume an invite after a successful submission — the link is done forever. */
function mam_faculty_consume(int $inviteId): void {
    mam_db_update('faculty_invites', ['status' => 'used', 'used_at' => date('Y-m-d H:i:s')], ['id' => $inviteId]);
    mam_audit('faculty_invite_used', 'faculty_invite', $inviteId, []);
}

/** Pending requests awaiting admin approval. */
function mam_faculty_pending_requests(int $limit = 100): array {
    $limit = max(1, min(200, $limit));
    return mam_db_all("SELECT * FROM faculty_invites WHERE status = 'requested' ORDER BY id DESC LIMIT " . $limit);
}

/** Compose + send the invite email for an approved invite row. */
function mam_faculty_send_invite(array $invite): bool {
    $link  = mam_faculty_apply_url($invite);
    $first = htmlspecialchars(trim(explode(' ', trim((string)($invite['name'] ?? '')))[0] ?: 'there'));
    return mam_send((string)$invite['email'], 'Your MAM Academy instructor application form',
        mam_email_layout(['header' => 'MAM ACADEMY', 'preheader' => 'Complete your instructor application — details + headshot.',
            'reason' => 'You received this because MAM Academy approved your request to apply as an instructor.',
            'body' => "<h2 style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px'>You're invited to apply, $first</h2>"
                . "<p style='margin:0 0 10px'>Complete our instructor application — your teaching disciplines, experience, a short bio, links/CV and a professional headshot — so the faculty panel can review you properly.</p>"
                . "<p style='margin:0'>This link is personal, works <strong>once</strong>, and expires in " . MAM_FACULTY_INVITE_DAYS . " days.</p>"
                . mam_email_button($link, 'Complete your application')]),
        'faculty_invite', true);
}

/**
 * Validate + normalise an instructor application. Never throws.
 * @return array{errors: string[], data: array<string,mixed>}
 */
function mam_faculty_validate(array $in): array {
    $errors = [];
    $name = trim((string)($in['full_name'] ?? ''));
    $phone = trim((string)($in['phone'] ?? ''));
    $disciplines = trim((string)($in['disciplines'] ?? ''));
    $years = isset($in['experience_years']) ? max(0, min(60, (int)$in['experience_years'])) : null;
    $bio = trim((string)($in['bio'] ?? ''));
    $links = trim((string)($in['links'] ?? ''));
    $cv = trim((string)($in['cv_url'] ?? ''));
    $shot = trim((string)($in['headshot_url'] ?? ''));

    if ($name === '' || mb_strlen($name) > 160) $errors[] = 'full_name';
    if ($disciplines === '' || mb_strlen($disciplines) > 300) $errors[] = 'disciplines';
    if (mb_strlen($bio) < 40 || mb_strlen($bio) > 4000) $errors[] = 'bio';
    if ($phone !== '' && mb_strlen($phone) > 40) $errors[] = 'phone';
    if (mb_strlen($links) > 1000) $errors[] = 'links';
    // URL fields must be https and sane, when provided.
    foreach ([['cv_url', $cv], ['headshot_url', $shot]] as [$k, $v]) {
        if ($v !== '' && !preg_match('#^https://[^\s"\'<>]{4,500}$#i', $v)) $errors[] = $k;
    }

    return ['errors' => $errors, 'data' => [
        'full_name' => $name, 'phone' => $phone ?: null, 'disciplines' => $disciplines,
        'experience_years' => $years, 'bio' => $bio, 'links' => $links ?: null,
        'cv_url' => $cv ?: null, 'headshot_url' => $shot ?: null,
    ]];
}

/** Store a validated application (one live application per email — resubmits update it). */
function mam_faculty_application_create(string $email, array $data, ?string $ip = null): int {
    $email = strtolower(trim($email));
    $existing = mam_db_row("SELECT id FROM instructor_applications WHERE email = ? AND status IN ('submitted','reviewing')", [$email]);
    if ($existing) {
        mam_db_update('instructor_applications', $data + ['ip' => $ip], ['id' => (int)$existing['id']]);
        mam_audit('faculty_apply_update', 'instructor_application', (int)$existing['id'], ['email' => $email]);
        return (int)$existing['id'];
    }
    $id = mam_db_insert('instructor_applications', $data + ['email' => $email, 'status' => 'submitted', 'ip' => $ip]);
    mam_audit('faculty_apply', 'instructor_application', $id, ['email' => $email]);
    return $id;
}

/* ═══════════════════════════════════════════════════════════════════════════
 * v90 — the application as an ASSESSMENT
 *
 * v36 asked for a name, a free-text list of disciplines and a bio. That is an
 * expression of interest. What the academy actually has to decide is whether
 * this person may teach a NAMED COURSE at a NAMED LEVEL to children, and the
 * two things that decision needs — a structured teaching matrix and the
 * safer-recruitment groundwork — were both missing.
 *
 * The matrix is stated against the same (course, level) tuple as
 * `instructor_assignments`, so approving a candidate copies rows instead of
 * re-keying a sentence.
 * ═══════════════════════════════════════════════════════════════════════════ */

/** Teaching modes a candidate can offer. Matches `class_sessions.mode` (v81). */
const MAM_FACULTY_MODES = ['onsite', 'online', 'hybrid'];

/** Advisory triage bands. Never shown to the candidate — see mam_faculty_readiness(). */
const MAM_FACULTY_READINESS = ['ready', 'developing', 'review'];

/**
 * Whether someone may apply WITHOUT an invitation.
 *
 * The v36 flow was invite-only: a request queued, an admin approved, and a
 * single-use HMAC link was emailed. That is the right shape for head-hunting
 * and the wrong shape for a published "teach at MAM" page, which is what an
 * academy recruiting at scale needs.
 *
 * Both now coexist. An invite still pre-verifies the address and is still
 * consumed exactly once; an open application is rate-limited, honeypotted and
 * CSRF-gated like any other public form. Turning this OFF restores v36
 * behaviour exactly, which is why it is a setting and not a deletion.
 */
function mam_faculty_open_applications(): bool {
    return (string) mam_setting_or_default('faculty_open_applications', '1') === '1';
}

/**
 * What the form offers: the live catalogue, never a hardcoded list.
 *
 * The courses and levels a candidate picks from are the ones the academy
 * actually teaches. Hardcoding them here would mean a course added in the
 * admin screens is one nobody can apply to teach.
 */
function mam_faculty_catalogue(): array {
    $courses = mam_db_all("SELECT id, code, name, kind FROM courses WHERE active = 1 ORDER BY sort_order, name");
    $levels  = mam_db_all("SELECT id, code, name, trinity_grades FROM levels ORDER BY sort_order");
    $progs   = mam_db_all("SELECT code, name FROM programs WHERE active = 1 ORDER BY sort_order, name");
    return ['courses' => $courses, 'levels' => $levels, 'programmes' => $progs, 'modes' => MAM_FACULTY_MODES];
}

/**
 * Resolve a submitted teaching matrix against the live catalogue.
 *
 * An unknown course or level code is DROPPED, never guessed — the same refusal
 * `mam_enrol_resolve()` makes for an instrument that is not a course. A
 * candidate who claims something the academy does not teach has said something
 * the panel should read in their own words, not something this should invent a
 * row for.
 *
 * Deduplicated on (course, level): the UNIQUE index on
 * `instructor_assignments` has the same shape, and a NULL level is distinct
 * from a levelled row in SQLite, so the dedupe is done here rather than left
 * to the index.
 */
function mam_faculty_normalise_subjects(array $in): array {
    $cat = mam_faculty_catalogue();
    $courseByCode = []; foreach ($cat['courses'] as $c) $courseByCode[$c['code']] = $c;
    $levelByCode  = []; foreach ($cat['levels']  as $l) $levelByCode[$l['code']]  = $l;

    $out = [];
    foreach ($in as $row) {
        if (!is_array($row)) continue;
        $cc = trim((string)($row['course'] ?? $row['course_code'] ?? ''));
        if ($cc === '' || !isset($courseByCode[$cc])) continue;          // unknown course → dropped
        $lc = trim((string)($row['level'] ?? $row['level_code'] ?? ''));
        $level = ($lc !== '' && isset($levelByCode[$lc])) ? $levelByCode[$lc] : null;
        $years = isset($row['years']) && $row['years'] !== '' ? max(0, min(60, (int)$row['years'])) : null;

        $key = $courseByCode[$cc]['id'] . ':' . ($level ? $level['id'] : '0');
        if (isset($out[$key])) continue;                                  // dedupe (course, level)
        $out[$key] = [
            'course_id'   => (int)$courseByCode[$cc]['id'],
            'course_code' => $cc,
            'course_name' => (string)$courseByCode[$cc]['name'],
            'level_id'    => $level ? (int)$level['id'] : null,
            'level_code'  => $level ? (string)$level['code'] : null,
            'level_name'  => $level ? (string)$level['name'] : null,
            'years'       => $years,
        ];
    }
    return array_values($out);
}

/**
 * Referees — CONTACTS ONLY.
 *
 * `MAM_VETTING_REQUIRED` names reference_1 and reference_2, and this is the
 * moment to collect who to ask. It is NOT the moment to record an outcome:
 * `vetting_records` holds no check content by design (a certificate scan in a
 * shared-hosting database is a liability, not a control), and an applicant's
 * own claim about themselves is never a clearance. A verifier takes the
 * reference up and records the result there, attributed to them.
 */
function mam_faculty_normalise_referees(array $in): array {
    $out = [];
    foreach ($in as $row) {
        if (!is_array($row)) continue;
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') continue;
        $email = strtolower(trim((string)($row['email'] ?? '')));
        $out[] = [
            'name'         => mb_substr($name, 0, 160),
            'relationship' => mb_substr(trim((string)($row['relationship'] ?? '')), 0, 120) ?: null,
            'organisation' => mb_substr(trim((string)($row['organisation'] ?? '')), 0, 190) ?: null,
            'email'        => ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) ? mb_substr($email, 0, 190) : null,
            'phone'        => mb_substr(trim((string)($row['phone'] ?? '')), 0, 40) ?: null,
        ];
        if (count($out) >= 4) break;
    }
    return $out;
}

/**
 * Advisory triage for the faculty panel. STAFF-SIDE ONLY.
 *
 * This mirrors `mam_application_readiness()` in intent — it routes, it never
 * rejects — with one deliberate difference: the student's readiness note is
 * shown to the applicant warmly, and this one is NOT shown to the candidate at
 * all. Telling someone "your application scores as weak" the moment they
 * submit is neither useful nor kind, and a band produced by counting fields is
 * not a hiring judgement. The same reasoning the repo already applies to
 * intervention flags (staff-side only) and to the instructor comparison table
 * (formative, never a ranking).
 *
 * It never gates submission. Nothing reads it but the panel's own queue.
 */
function mam_faculty_readiness(array $data, array $subjects, array $referees): string {
    $years = (int)($data['experience_years'] ?? 0);
    $hasQuals = trim((string)($data['qualifications'] ?? '')) !== '';
    $refs = count($referees);
    $levelled = 0;
    foreach ($subjects as $s) if (!empty($s['level_id'])) $levelled++;

    // "review" means a human should look sooner, not that the candidate is weak:
    // an application with no subjects or no referees cannot be progressed at all.
    if (!$subjects || $refs < 2) return 'review';
    if ($years >= 3 && $hasQuals && $levelled > 0 && $refs >= 2) return 'ready';
    return 'developing';
}

/**
 * Validate the full application. Backward compatible with the v36 shape:
 * `disciplines` free text is still accepted and still stored, because
 * `admin/index.php` renders that column. When a structured matrix is supplied
 * it WINS and `disciplines` is derived from it, so the two can never disagree.
 */
function mam_faculty_validate_full(array $in): array {
    $base = mam_faculty_validate($in);
    $errors = $base['errors'];
    $data = $base['data'];

    $subjects = mam_faculty_normalise_subjects(is_array($in['subjects'] ?? null) ? $in['subjects'] : []);
    $referees = mam_faculty_normalise_referees(is_array($in['referees'] ?? null) ? $in['referees'] : []);

    // A structured matrix is authoritative; keep the legacy column in step.
    if ($subjects) {
        $labels = [];
        foreach ($subjects as $s) {
            $labels[] = $s['level_name'] ? $s['course_name'] . ' (' . $s['level_name'] . ')' : $s['course_name'];
        }
        $data['disciplines'] = mb_substr(implode(', ', $labels), 0, 300);
        $errors = array_values(array_diff($errors, ['disciplines']));
    }

    $modes = [];
    foreach ((array)($in['teaching_modes'] ?? []) as $m) {
        $m = strtolower(trim((string)$m));
        if (in_array($m, MAM_FACULTY_MODES, true) && !in_array($m, $modes, true)) $modes[] = $m;
    }

    $cat = mam_faculty_catalogue();
    $progCodes = array_column($cat['programmes'], 'code');
    $progs = [];
    foreach ((array)($in['programmes'] ?? []) as $p) {
        $p = strtolower(trim((string)$p));
        if (in_array($p, $progCodes, true) && !in_array($p, $progs, true)) $progs[] = $p;
    }

    $hours = isset($in['weekly_hours']) && $in['weekly_hours'] !== '' ? max(0, min(80, (int)$in['weekly_hours'])) : null;
    $start = trim((string)($in['earliest_start'] ?? ''));
    if ($start !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) { $errors[] = 'earliest_start'; $start = ''; }

    $philosophy = trim((string)($in['teaching_philosophy'] ?? ''));
    if (mb_strlen($philosophy) > 4000) $errors[] = 'teaching_philosophy';
    $quals = trim((string)($in['qualifications'] ?? ''));
    if (mb_strlen($quals) > 2000) $errors[] = 'qualifications';
    $location = trim((string)($in['location'] ?? ''));
    if (mb_strlen($location) > 190) $errors[] = 'location';

    // The two commitments are the `policy_commitment` limb of the vetting set.
    // Both are REQUIRED: an academy teaching children one-to-one cannot accept
    // an application from somebody unwilling to state them.
    $safeAck = !empty($in['safeguarding_ack']);
    $polAck  = !empty($in['policy_ack']);
    if (!$safeAck) $errors[] = 'safeguarding_ack';
    if (!$polAck)  $errors[] = 'policy_ack';

    if (!$subjects) $errors[] = 'subjects';
    if (count($referees) < 2) $errors[] = 'referees';

    $data += [
        'programmes'          => $progs ? json_encode($progs) : null,
        'teaching_modes'      => $modes ? json_encode($modes) : null,
        'location'            => $location ?: null,
        'qualifications'      => $quals ?: null,
        'teaching_philosophy' => $philosophy ?: null,
        'availability'        => mam_faculty_availability_json($in['availability'] ?? null),
        'weekly_hours'        => $hours,
        'earliest_start'      => $start ?: null,
        'safeguarding_ack'    => $safeAck ? 1 : 0,
        'policy_ack'          => $polAck ? 1 : 0,
    ];

    return [
        'errors'   => array_values(array_unique($errors)),
        'data'     => $data,
        'subjects' => $subjects,
        'referees' => $referees,
    ];
}

/** Availability is a free-shaped grid from the client; store it as vetted JSON or nothing. */
function mam_faculty_availability_json($in): ?string {
    if (!is_array($in) || !$in) return null;
    $slots = [];
    $days = ['mon','tue','wed','thu','fri','sat','sun'];
    $parts = ['morning','afternoon','evening'];
    foreach ($in as $slot) {
        $slot = strtolower(trim((string)$slot));
        if (!str_contains($slot, ':')) continue;
        [$d, $p] = explode(':', $slot, 2);
        if (in_array($d, $days, true) && in_array($p, $parts, true) && !in_array($slot, $slots, true)) $slots[] = $slot;
    }
    return $slots ? json_encode($slots) : null;
}

/**
 * Store a full application: the row, its teaching matrix and its referees.
 *
 * Resubmission REPLACES the matrix and the referees rather than adding to
 * them. `mam_faculty_application_create()` already updates one live
 * application per email, so appending would leave a candidate who corrected a
 * level claiming both.
 */
function mam_faculty_application_save(string $email, array $v, ?string $ip = null): int {
    $data = $v['data'];
    $data['readiness_signal'] = mam_faculty_readiness($data, $v['subjects'], $v['referees']);
    $data['submitted_at'] = gmdate('Y-m-d H:i:s');

    $id = mam_faculty_application_create($email, $data, $ip);

    mam_db()->prepare('DELETE FROM instructor_application_subjects WHERE application_id = ?')->execute([$id]);
    foreach ($v['subjects'] as $s) {
        mam_db_insert('instructor_application_subjects', [
            'application_id' => $id,
            'course_id'      => $s['course_id'],
            'level_id'       => $s['level_id'],
            'years'          => $s['years'],
        ]);
    }

    mam_db()->prepare('DELETE FROM instructor_application_referees WHERE application_id = ?')->execute([$id]);
    foreach ($v['referees'] as $r) {
        mam_db_insert('instructor_application_referees', $r + ['application_id' => $id]);
    }

    return $id;
}

/** Read one application back with its matrix and referees, for the panel. */
function mam_faculty_application_detail(int $id): ?array {
    $app = mam_db_row('SELECT * FROM instructor_applications WHERE id = ?', [$id]);
    if (!$app) return null;
    $app['subjects'] = mam_db_all(
        "SELECT s.id, s.years, c.code AS course_code, c.name AS course_name,
                l.code AS level_code, l.name AS level_name
           FROM instructor_application_subjects s
           JOIN courses c ON c.id = s.course_id
           LEFT JOIN levels l ON l.id = s.level_id
          WHERE s.application_id = ?
          ORDER BY c.sort_order, c.name, l.sort_order", [$id]);
    $app['referees'] = mam_db_all(
        'SELECT id, name, relationship, organisation, email, phone
           FROM instructor_application_referees WHERE application_id = ? ORDER BY id', [$id]);
    return $app;
}
