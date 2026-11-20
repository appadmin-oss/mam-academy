<?php
/**
 * MAM Academy — instructor vetting and the safeguarding gate.
 * ─────────────────────────────────────────────────────────────
 * The academy teaches children, one-to-one, sometimes online. That single fact
 * makes staff vetting the highest-consequence control in the system, and until
 * now nothing recorded it and nothing checked it.
 *
 * ── What Nigerian law actually requires ─────────────────────────────────────
 * There is NO nationwide statutory DBS equivalent, so this is assembled from the
 * instruments that do bind:
 *
 *  • **Child Rights Act 2003 s.2(2)** requires every institution responsible for
 *    the care of children to conform to standards set by the appropriate
 *    authorities "particularly in the areas of safety, health, welfare, number
 *    and SUITABILITY OF THEIR STAFF and competent supervision". Suitability is
 *    the hook: an academy that cannot say why an adult is suitable to be alone
 *    with a child has not met it. s.1 makes the child's best interest the primary
 *    consideration; s.11 prohibits abuse while in the care of a school authority;
 *    s.277 fixes a child as under 18.
 *
 *  • **Lagos State Safeguarding and Child Protection Policy (2016)** makes a
 *    Child Protection Policy mandatory for every school and child-centred
 *    institution in the State, and its Safer Recruitment section mandates: two
 *    verified written character references, a signed commitment to the Child
 *    Protection Policy as a condition of employment, an affidavit of good
 *    behaviour regarding interaction with children, a recruitment-panel member
 *    trained in child protection, and a background search against the Lagos
 *    State Sex Offenders Register. It also requires a **Designated Child
 *    Protection Officer** who keeps records and reports quarterly to the Ministry
 *    of Youth and Social Development.
 *
 *  • Available checks in practice: the Nigeria Police Character Certificate (via
 *    POSSAP), the Lagos Sex Offenders Register, NAPTIP's national database under
 *    the VAPP Act 2015, TRCN teacher registration, and identity verification by
 *    NIN or passport. For UK-resident instructors, an Enhanced DBS with the
 *    Children's Barred List is the stronger equivalent.
 *
 * ── What this file deliberately does NOT store ──────────────────────────────
 * Status, type, issuing body, reference number, dates, and a pointer to where
 * the evidence is held offline. **Never the content of a check, and never
 * offence data.** Under the NDPA this is sensitive personal data about the
 * instructor (criminal-record data survives as sensitive via NDPR 2019
 * reg. 1.3(xxv) and the NDPC's s.30(2) power even though NDPA s.65 omits it), so
 * the less of it exists in a shared-hosting database the better. A reference
 * number and a filing location are enough to prove diligence; a scan of a police
 * certificate in a web-accessible database is a liability, not a control.
 *
 * ── Why the gate has three modes and defaults to `warn` ─────────────────────
 * The research's threshold is "no uncleared instructor can be scheduled 1:1 with
 * a minor", and `enforce` is the target state. But switching a live academy
 * straight to `enforce` with zero vetting records on file would refuse every
 * assignment on the next click — safeguarding theatre that ends with somebody
 * disabling the whole feature to get through the day. So the gate ships in
 * `warn`: every blocked-in-future assignment is recorded and surfaced loudly,
 * the admin loads the records they already hold offline, and then turns on
 * `enforce` deliberately. `off` exists only for a non-teaching install.
 *
 * The mode is a setting, but WHICH CHECKS ARE REQUIRED is not — see
 * MAM_VETTING_REQUIRED. An academy that could quietly shorten the required set
 * would have a gate that proves nothing.
 */
declare(strict_types=1);

/**
 * Clearance types. `label` is what an admin picks from; `sensitive` marks the
 * ones whose mere existence is sensitive personal data and therefore never
 * exposed outside the safeguarding screens.
 */
const MAM_VETTING_TYPES = [
    'police_character'     => ['label' => 'Nigeria Police Character Certificate (POSSAP)', 'sensitive' => true,  'months' => 36],
    'sex_offender_lagos'   => ['label' => 'Lagos State Sex Offenders Register search',     'sensitive' => true,  'months' => 12],
    'naptip_check'         => ['label' => 'NAPTIP sex offenders database search',          'sensitive' => true,  'months' => 12],
    'dbs_enhanced'         => ['label' => 'UK Enhanced DBS + Children\'s Barred List',     'sensitive' => true,  'months' => 36],
    'reference_1'          => ['label' => 'Verified written character reference (1 of 2)', 'sensitive' => false, 'months' => null],
    'reference_2'          => ['label' => 'Verified written character reference (2 of 2)', 'sensitive' => false, 'months' => null],
    'policy_commitment'    => ['label' => 'Signed Child Protection Policy commitment',     'sensitive' => false, 'months' => null],
    'affidavit_good'       => ['label' => 'Affidavit of good behaviour (children)',        'sensitive' => false, 'months' => null],
    'safeguarding_training'=> ['label' => 'Safeguarding / child-protection training',      'sensitive' => false, 'months' => 24],
    'identity'             => ['label' => 'Identity verified (NIN or passport)',           'sensitive' => false, 'months' => null],
    'trcn'                 => ['label' => 'TRCN teacher registration',                     'sensitive' => false, 'months' => 36],
];

/**
 * The set that must be present and unexpired before an instructor may be
 * assigned to teach a minor.
 *
 * Taken from the Lagos Safer Recruitment mandate, not invented: two references,
 * the signed policy commitment, the affidavit, a Sex Offenders Register search,
 * plus identity and safeguarding training, which CRA s.2(2) "suitability" cannot
 * seriously be met without.
 *
 * A CONSTANT, not a setting. An academy that could shorten this list would have
 * a gate that certifies nothing — and the first thing anyone does under time
 * pressure is shorten the list.
 */
const MAM_VETTING_REQUIRED = [
    'reference_1', 'reference_2', 'policy_commitment', 'affidavit_good',
    'identity', 'safeguarding_training', 'sex_offender_lagos',
];

/**
 * Either of these satisfies the criminal-record limb, so a UK-resident
 * instructor with an Enhanced DBS is not asked for a Lagos register search they
 * cannot obtain — and vice versa. Keyed by the required type they substitute for.
 */
const MAM_VETTING_ALTERNATIVES = [
    'sex_offender_lagos' => ['naptip_check', 'dbs_enhanced', 'police_character'],
];

/** Gate modes, weakest first. */
const MAM_VETTING_MODES = ['off', 'warn', 'enforce'];

/** How many days ahead an expiring clearance starts warning. */
const MAM_VETTING_EXPIRY_WARN_DAYS = 60;

/** The configured gate mode. Defaults to `warn` — see the file header. */
function mam_vetting_mode(): string {
    $m = strtolower(trim((string) mam_setting_or_default('safeguarding_gate_mode', 'warn')));
    return in_array($m, MAM_VETTING_MODES, true) ? $m : 'warn';
}

/** Is a type name one we know? */
function mam_vetting_type_ok(string $type): bool {
    return isset(MAM_VETTING_TYPES[$type]);
}

/**
 * Record (or re-record) a clearance.
 *
 * Upserts on (instructor, type): re-running a check REPLACES the record rather
 * than accumulating rows, because the question the gate asks is "is there a
 * current one", and a history of superseded certificate numbers is exactly the
 * sensitive data this file exists not to hoard. The audit log keeps the trail of
 * who recorded what when, which is the part that matters for proving diligence.
 *
 * $expiresOn may be null for a type with no natural expiry (a reference does not
 * lapse); for the rest, the default period in MAM_VETTING_TYPES is applied when
 * no explicit date is given.
 */
function mam_vetting_record(
    int $instructorId,
    string $type,
    ?int $verifiedBy,
    ?string $issuedOn = null,
    ?string $expiresOn = null,
    ?string $issuingBody = null,
    ?string $reference = null,
    ?string $evidenceHeld = null
): int {
    if ($instructorId <= 0) throw new InvalidArgumentException('bad_instructor');
    if (!mam_vetting_type_ok($type)) throw new InvalidArgumentException('bad_type');

    $issuedOn = $issuedOn ?: date('Y-m-d');
    if ($expiresOn === null) {
        $months = MAM_VETTING_TYPES[$type]['months'];
        if ($months !== null) {
            /* Clamp to the end of the target month: strtotime('+1 month') on
               the 31st lands in the month AFTER next (the same trap the
               ID-card expiry sweep documents).

               ⚠️ This comment was here BEFORE the clamp was. The line under it
               called strtotime() directly, which is precisely the behaviour
               the comment says is being avoided — MEASURED, a clearance issued
               2026-08-31 expired 2027-03-03 instead of 2027-02-28, three extra
               days of validity on a child-safeguarding check. A comment that
               states the opposite of its code is worse than none, because a
               reviewer reads it and moves on. `mam_add_months()` is now the one
               implementation, shared with cards.php. */
            $expiresOn = mam_add_months($issuedOn, (int) $months);
        }
    }
    // An expiry before the issue date is a data-entry slip, not a lapsed check;
    // accepting it would silently make the instructor permanently un-clearable.
    if ($expiresOn !== null && $expiresOn < $issuedOn) throw new InvalidArgumentException('expiry_before_issue');

    $row = [
        'instructor_id' => $instructorId,
        'clearance_type'=> $type,
        'issuing_body'  => $issuingBody !== null ? mb_substr($issuingBody, 0, 120) : null,
        'reference_no'  => $reference   !== null ? mb_substr($reference, 0, 80)   : null,
        'issued_on'     => $issuedOn,
        'expires_on'    => $expiresOn,
        'verified_by'   => $verifiedBy ?: null,   // null, never 0 — users(id) FK
        'verified_at'   => date('Y-m-d H:i:s'),
        'evidence_held' => $evidenceHeld !== null ? mb_substr($evidenceHeld, 0, 200) : null,
    ];
    $existing = mam_db_row('SELECT id FROM vetting_records WHERE instructor_id = ? AND clearance_type = ?',
        [$instructorId, $type]);
    if ($existing) {
        mam_db_update('vetting_records', $row, ['id' => (int) $existing['id']]);
        $id = (int) $existing['id'];
    } else {
        $id = mam_db_insert('vetting_records', $row);
    }
    /* The audit entry names the TYPE and the dates and nothing else. It must not
       carry the reference number: the audit log is read by more people and kept
       longer than the safeguarding screen. */
    mam_audit('vetting_record', 'user', $instructorId,
        ['type' => $type, 'issued' => $issuedOn, 'expires' => $expiresOn, 'replaced' => (bool) $existing]);
    return $id;
}

/** Remove a clearance record (a mis-entry). Audited; the evidence stays offline. */
function mam_vetting_delete(int $recordId, ?int $actorId = null): bool {
    $row = mam_db_row('SELECT instructor_id, clearance_type FROM vetting_records WHERE id = ?', [$recordId]);
    if (!$row) return false;
    mam_db()->prepare('DELETE FROM vetting_records WHERE id = ?')->execute([$recordId]);
    mam_audit('vetting_delete', 'user', (int) $row['instructor_id'],
        ['type' => $row['clearance_type'], 'actor' => $actorId]);
    return true;
}

/** Every clearance on file for an instructor, with derived state. */
function mam_vetting_for(int $instructorId): array {
    $rows = mam_db_all('SELECT * FROM vetting_records WHERE instructor_id = ? ORDER BY clearance_type', [$instructorId]);
    $today = date('Y-m-d');
    $warnBy = date('Y-m-d', strtotime('+' . MAM_VETTING_EXPIRY_WARN_DAYS . ' days'));
    foreach ($rows as &$r) {
        $exp = $r['expires_on'] ?: null;
        /* Derived, never a stored status column. A clearance that lapses at
           midnight must read as expired on the next page load, not on the next
           cron run — the same rule the ID-card expiry sweep follows. */
        $r['state'] = $exp === null ? 'valid'
                    : ($exp < $today ? 'expired'
                    : ($exp <= $warnBy ? 'expiring' : 'valid'));
        $r['label'] = MAM_VETTING_TYPES[$r['clearance_type']]['label'] ?? $r['clearance_type'];
        $r['days_left'] = $exp === null ? null
            : (int) floor((strtotime($exp) - strtotime($today)) / 86400);
    }
    unset($r);
    return $rows;
}

/**
 * May this instructor be assigned to teach a MINOR?
 *
 * Returns:
 *   ok        bool    — every required clearance present and unexpired
 *   mode      string  — the configured gate mode
 *   blocking  bool    — whether `ok === false` should actually stop the caller
 *   missing   array   — required types with no record at all
 *   expired   array   — required types whose record has lapsed
 *   expiring  array   — valid but inside the warning window (never blocking)
 *   satisfied array   — the required types that are met, and by what
 */
function mam_vetting_may_teach_minors(int $instructorId): array {
    $mode = mam_vetting_mode();
    $have = [];
    foreach (mam_vetting_for($instructorId) as $r) {
        $have[(string) $r['clearance_type']] = $r;
    }

    $missing = []; $expired = []; $expiring = []; $satisfied = [];
    foreach (MAM_VETTING_REQUIRED as $need) {
        // A required type may be met by itself or by a listed equivalent, so an
        // instructor is never asked for a check their jurisdiction cannot issue.
        $candidates = array_merge([$need], MAM_VETTING_ALTERNATIVES[$need] ?? []);
        $met = null;
        foreach ($candidates as $c) {
            if (isset($have[$c]) && $have[$c]['state'] !== 'expired') { $met = $have[$c]; break; }
        }
        if ($met !== null) {
            $satisfied[$need] = ['by' => $met['clearance_type'], 'state' => $met['state'],
                                 'expires_on' => $met['expires_on']];
            if ($met['state'] === 'expiring') $expiring[] = $need;
            continue;
        }
        // Distinguish "never obtained" from "lapsed": they need different actions
        // (get one vs renew one) and an admin chasing the wrong one wastes days.
        $anyLapsed = false;
        foreach ($candidates as $c) { if (isset($have[$c])) { $anyLapsed = true; break; } }
        if ($anyLapsed) $expired[] = $need; else $missing[] = $need;
    }

    $ok = !$missing && !$expired;
    return [
        'ok'        => $ok,
        'mode'      => $mode,
        'blocking'  => !$ok && $mode === 'enforce',
        'missing'   => $missing,
        'expired'   => $expired,
        'expiring'  => $expiring,
        'satisfied' => $satisfied,
        'required'  => MAM_VETTING_REQUIRED,
    ];
}

/** A one-line human summary of a gate result, for a toast or an audit note. */
function mam_vetting_explain(array $gate): string {
    if ($gate['ok']) {
        return $gate['expiring']
            ? 'Cleared to teach minors, but ' . count($gate['expiring']) . ' clearance(s) expire soon.'
            : 'Cleared to teach minors.';
    }
    $bits = [];
    $name = static function (string $t): string { return MAM_VETTING_TYPES[$t]['label'] ?? $t; };
    if ($gate['missing']) $bits[] = 'not on file: ' . implode(', ', array_map($name, $gate['missing']));
    if ($gate['expired']) $bits[] = 'expired: ' . implode(', ', array_map($name, $gate['expired']));
    return 'Not cleared to teach minors — ' . implode('; ', $bits) . '.';
}

/**
 * Is this student a minor?
 *
 * Returns true / false / null (unknown). DOB lives on `customers`, reached
 * through the application, because `users` has no birth date; `program_track`
 * = KTAMS is the academy's own under-16 marker and is trusted when a DOB is
 * absent.
 */
function mam_vetting_student_is_minor(int $studentId): ?bool {
    $row = mam_db_row(
        "SELECT c.dob AS dob, a.program_track AS track
           FROM users u
           LEFT JOIN customers c ON LOWER(c.email) = LOWER(u.email)
           LEFT JOIN applications a ON a.customer_id = c.id
          WHERE u.id = ?
          ORDER BY a.id DESC",
        [$studentId]
    );
    if (!$row) return null;
    if (!empty($row['dob']) && function_exists('mam_age_from_dob')) {
        $age = mam_age_from_dob((string) $row['dob']);
        if ($age !== null) return $age < 18;
    }
    if (strtoupper((string) ($row['track'] ?? '')) === 'KTAMS') return true;
    return null;
}

/**
 * Does this cohort contain a minor?
 *
 * `null` (age unknown) counts as a minor when `safeguarding_unknown_age_is_minor`
 * is on, which it is by default. An academy that teaches children and cannot
 * tell whether a particular student is one should err toward protection — and
 * because the gate ships in `warn`, erring that way costs a visible notice, not
 * a blocked lesson.
 */
function mam_vetting_cohort_has_minor(int $cohortId): bool {
    $assume = (int) mam_setting_num('safeguarding_unknown_age_is_minor', 1) === 1;
    $rows = mam_db_all(
        "SELECT DISTINCT student_id FROM course_enrollments
          WHERE cohort_id = ? AND status IN ('active','pending','completed')", [$cohortId]);
    foreach ($rows as $r) {
        $m = mam_vetting_student_is_minor((int) $r['student_id']);
        if ($m === true) return true;
        if ($m === null && $assume) return true;
    }
    return false;
}

/**
 * THE GATE. Call before assigning $instructorId to $cohortId.
 *
 * Returns ['allow' => bool, 'gate' => array|null, 'reason' => string].
 * `allow` is false ONLY in `enforce` mode with a real deficiency — in `warn` the
 * caller proceeds and is expected to surface `reason`. Every non-clear outcome is
 * audited either way, so the record of who was assigned while uncleared exists
 * from the day this ships rather than from the day enforcement is switched on.
 */
function mam_vetting_check_assignment(int $instructorId, ?int $cohortId, bool $minorKnown = false): array {
    if (mam_vetting_mode() === 'off' || $instructorId <= 0) {
        return ['allow' => true, 'gate' => null, 'reason' => ''];
    }
    $touchesMinor = $minorKnown || ($cohortId !== null && mam_vetting_cohort_has_minor($cohortId));
    if (!$touchesMinor) return ['allow' => true, 'gate' => null, 'reason' => ''];

    $gate = mam_vetting_may_teach_minors($instructorId);
    if ($gate['ok']) return ['allow' => true, 'gate' => $gate, 'reason' => ''];

    $reason = mam_vetting_explain($gate);
    mam_audit('vetting_gate', 'user', $instructorId, [
        'cohort'   => $cohortId,
        'mode'     => $gate['mode'],
        'blocked'  => $gate['blocking'],
        'missing'  => $gate['missing'],
        'expired'  => $gate['expired'],
    ]);
    return ['allow' => !$gate['blocking'], 'gate' => $gate, 'reason' => $reason];
}

/**
 * Every instructor with a vetting problem — the safeguarding officer's worklist.
 * Instructors with NO records at all are included: an empty file is the most
 * serious state, and a list that only showed lapsed checks would hide it.
 */
function mam_vetting_attention(int $limit = 200): array {
    $rows = mam_db_all(
        "SELECT id, name, email FROM users
          WHERE role = 'instructor' AND status = 'active'
          ORDER BY name LIMIT " . max(1, min(500, $limit)));
    $out = [];
    foreach ($rows as $u) {
        $gate = mam_vetting_may_teach_minors((int) $u['id']);
        if ($gate['ok'] && !$gate['expiring']) continue;
        $out[] = [
            'instructor_id' => (int) $u['id'],
            'name'          => $u['name'] ?: $u['email'],
            'email'         => $u['email'],
            'ok'            => $gate['ok'],
            'missing'       => $gate['missing'],
            'expired'       => $gate['expired'],
            'expiring'      => $gate['expiring'],
            'summary'       => mam_vetting_explain($gate),
            // No records at all sorts first — it is the worst state, not the
            // quietest one.
            'severity'      => (!$gate['satisfied'] ? 3 : ($gate['expired'] ? 2 : ($gate['missing'] ? 2 : 1))),
        ];
    }
    usort($out, static fn($a, $b) => [$b['severity'], $a['name']] <=> [$a['severity'], $b['name']]);
    return $out;
}

/**
 * The Designated Safeguarding Officer, whom the Lagos policy requires by name.
 * Returns null when unset — and that is itself a finding the admin screen shows,
 * because "we have a policy" without a named officer is the commonest way the
 * requirement is missed.
 */
function mam_vetting_dso(): ?array {
    $id = (int) mam_setting_num('safeguarding_officer_user_id', 0);
    if ($id <= 0) return null;
    $u = mam_db_row('SELECT id, name, email, role FROM users WHERE id = ?', [$id]);
    return $u ?: null;
}

/** Headline counts for the admin safeguarding panel. */
function mam_vetting_summary(): array {
    $instructors = (int) (mam_db_row("SELECT COUNT(*) AS n FROM users WHERE role = 'instructor' AND status = 'active'")['n'] ?? 0);
    $attention   = mam_vetting_attention(500);
    $clearedFull = 0;
    foreach (mam_db_all("SELECT id FROM users WHERE role = 'instructor' AND status = 'active'") as $u) {
        if (mam_vetting_may_teach_minors((int) $u['id'])['ok']) $clearedFull++;
    }
    return [
        'instructors'   => $instructors,
        'cleared'       => $clearedFull,
        'needs_action'  => count(array_filter($attention, static fn($a) => !$a['ok'])),
        'expiring_soon' => count(array_filter($attention, static fn($a) => $a['ok'] && $a['expiring'])),
        'mode'          => mam_vetting_mode(),
        'dso'           => mam_vetting_dso(),
        'required'      => MAM_VETTING_REQUIRED,
    ];
}

/* ─── Only cleared adults see children's records (NGG port, NEXT-AGENT §4) ──
 *
 * The gate above runs at ASSIGNMENT. That leaves two holes once the academy
 * switches to `enforce`: an instructor assigned before enforcement, and one
 * whose clearance lapsed afterwards, still opens the register, the child's
 * page, their incidents and collectors, their growth and their videos. NGG
 * refuses an uncleared mentor any child record; so does this, at every door,
 * through these two predicates.
 *
 *   · Only `enforce` refuses. In `warn` and `off` nothing changes — the gate
 *     ships in warn for the reason the file header gives, and a door that
 *     suddenly shut on a live academy would be switched off within the day.
 *   · An adult student is never gated, and unknown age counts as a minor
 *     (`safeguarding_unknown_age_is_minor`), as everywhere else here.
 *   · Admins are not instructors here: the doors call this for the instructor
 *     branch only.
 *   · Each refusal is audited (`vetting_record_refused`), so the officer can
 *     see who tried to open what while uncleared.
 */
function mam_vetting_gate_cached(int $instructorId): array {
    static $memo = [];
    if (!empty($GLOBALS['__mam_vetting_nomemo'])) $memo = [];
    return $memo[$instructorId] ??= mam_vetting_may_teach_minors($instructorId);
}

/** May this instructor open this student's records? False only in `enforce`, for a minor, while uncleared. */
function mam_vetting_may_see_child(int $instructorId, int $studentId): bool {
    if ($instructorId <= 0 || mam_vetting_mode() !== 'enforce') return true;
    $minor = mam_vetting_student_is_minor($studentId);
    if ($minor === false) return true;
    if ($minor === null && (int) mam_setting_num('safeguarding_unknown_age_is_minor', 1) !== 1) return true;
    if (mam_vetting_gate_cached($instructorId)['ok']) return true;
    mam_audit('vetting_record_refused', 'user', $instructorId, ['student' => $studentId]);
    return false;
}

/** May this instructor open a class's register or space? False only in `enforce`, for a class with a minor, while uncleared. */
function mam_vetting_may_see_cohort(int $instructorId, int $cohortId): bool {
    if ($instructorId <= 0 || $cohortId <= 0 || mam_vetting_mode() !== 'enforce') return true;
    if (!mam_vetting_cohort_has_minor($cohortId)) return true;
    if (mam_vetting_gate_cached($instructorId)['ok']) return true;
    mam_audit('vetting_record_refused', 'cohort', $cohortId, ['instructor' => $instructorId]);
    return false;
}
