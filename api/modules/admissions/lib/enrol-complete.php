<?php
/**
 * Completing an enrolment — one action, end to end.
 * ─────────────────────────────────────────────────────────────
 * Marking an application "enrolled" used to create a portal account and an ID
 * card and then stop. No course, no instructor, no class. On the real data that
 * left **5 applications marked accepted/enrolled against 1 course enrolment** —
 * four people the academy considered enrolled who were in nothing. The status
 * was a label, not an outcome, and there was no screen anywhere that finished
 * the job.
 *
 * This file finishes it: account → course enrolment → cohort → instructor, in
 * one call, from what the application already knows.
 *
 * ── Why it resolves-then-confirms instead of firing silently ──
 * The obvious version enrols automatically the moment an admin clicks
 * "enrolled". It would be wrong here, and the live data says so:
 *
 *   • `discipline` holds `violin` and `clarinet`, which are not courses. A
 *     silent path enrols those applicants in nothing, or worse, in whatever a
 *     fuzzy match returned.
 *   • `level` is NULL on 15 of 26 applications.
 *   • `pathway` mixes levels (`cert`, `pre-cert`) with programmes (`crash`,
 *     `grand-musician`, `standard`), so it cannot be read as either alone.
 *
 * So `mam_enrol_resolve()` works out everything it can and says plainly what it
 * could not, and `mam_enrol_complete()` performs the whole chain once someone
 * has seen it. Where the data is unambiguous — 24 of 26 applications — the
 * admin confirms a filled-in form. Where it is not, they are asked about
 * exactly the one field in question rather than the whole record.
 *
 * ── Why it never swallows a failure ──
 * The old path wrapped provisioning in a catch that only wrote a log line, so
 * the admin saw "Application updated" whether or not anything happened. One of
 * the five is marked `enrolled` with no portal account at all — that is what a
 * swallowed failure looks like six weeks later. Every step here records its
 * outcome and the caller is expected to show it.
 */
declare(strict_types=1);

/**
 * Programme codes, mapped from the two application fields that carry programme
 * intent. `program_track` wins when it says KTAMS, because that is the one
 * unambiguous signal in the data; otherwise `pathway` decides.
 *
 * The values that are really LEVELS (`cert`, `pre-cert`) are deliberately absent
 * — they resolve to no programme rather than to a wrong one.
 */
function mam_enrol_programme_code(?string $pathway, ?string $track): ?string
{
    if (strtoupper(trim((string) $track)) === 'KTAMS') return 'ktams';
    return [
        'standard'           => 'standard',
        'crash'              => 'crash',
        'certified-musician' => 'certified',
        'grand-musician'     => 'grand',
    ][strtolower(trim((string) $pathway))] ?? null;
}

/* ── identity hold (schema v109) ─────────────────────────────────────────────
   Identity is still keyed on email in v1 (customers.email is UNIQUE), so an
   application whose address already belongs to someone else used to REWRITE
   that person — a parent applying for a child turned the parent's record into
   the child's, and enrolment then put the child's course in the parent's
   account. Until the identity module gives every person their own record
   (docs/restructure-plan-2026-11-28.md §2.1), such an application is HELD: the
   existing person is left untouched, the submitted identity is kept on the
   application, and nothing provisions or enrols from it until somebody
   confirms it really is the same person. */

/** Lower-case, letters only, single spaces — for comparing names typed twice. */
function mam_identity_norm_name(?string $first, ?string $last): string {
    $s = mb_strtolower(trim((string) $first . ' ' . (string) $last));
    $s = preg_replace('/[^\p{L}\s]+/u', '', $s) ?? $s;
    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

/**
 * Why this submission is NOT the person already holding the address, or null
 * when nothing says so. Conservative: a name that differs, or two dates of
 * birth that differ. A missing value never counts as a difference.
 */
function mam_identity_conflict(array $existing, array $submitted): ?string {
    $a = mam_identity_norm_name($existing['first_name'] ?? null, $existing['last_name'] ?? null);
    $b = mam_identity_norm_name($submitted['first_name'] ?? null, $submitted['last_name'] ?? null);
    if ($a !== '' && $b !== '' && $a !== $b) return 'name_differs';
    $da = trim((string) ($existing['dob'] ?? '')); $db = trim((string) ($submitted['dob'] ?? ''));
    if ($da !== '' && $db !== '' && substr($da, 0, 10) !== substr($db, 0, 10)) return 'dob_differs';
    return null;
}

/** The hold on an application (decoded), or null when there is none. */
function mam_application_identity_held($app): ?array {
    if (is_int($app)) $app = mam_db_row('SELECT id, identity_hold FROM applications WHERE id = ?', [$app]) ?: [];
    $raw = (string) ($app['identity_hold'] ?? '');
    if ($raw === '') return null;
    $h = json_decode($raw, true);
    return is_array($h) ? $h : ['reason' => 'held'];
}

/** Release a hold once a person has confirmed the identity. Audited. */
function mam_application_identity_release(int $appId, ?int $actorId): void {
    mam_db_update('applications', ['identity_hold' => null, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $appId]);
    if (function_exists('mam_audit')) mam_audit('application_identity_confirmed', 'application', $appId, ['by' => $actorId]);
}

/** A sentence for the office: who applied, and who already holds the address. */
function mam_application_identity_hold_text(array $hold, ?array $cust): string {
    $sub = trim(($hold['submitted']['first_name'] ?? '') . ' ' . ($hold['submitted']['last_name'] ?? ''));
    $sdob = (string) ($hold['submitted']['dob'] ?? '');
    $ex = $cust ? trim(($cust['first_name'] ?? '') . ' ' . ($cust['last_name'] ?? '')) : '';
    return sprintf(
        'This application was made for %s%s, but its email already belongs to %s. Their record was left unchanged. '
        . 'Enrol only if this is the same person; if it is a different person (for example a child applying with a parent\'s email), '
        . 'they need their own email before they can be enrolled — do not enrol them into this account.',
        $sub !== '' ? $sub : 'someone', $sdob !== '' ? ' (born ' . $sdob . ')' : '', $ex !== '' ? $ex : 'another person');
}

/**
 * Work out what enrolling this applicant would mean, without doing it.
 *
 * Returns:
 *   ready      bool    — everything needed is resolved
 *   course     ['code','name','id'] | null
 *   level      ['code','name','id'] | null
 *   programme  ['code','name','id'] | null
 *   student    ['id','email','name','exists'] — the portal account, if any
 *   cohort     the cohort this would land in, once known
 *   instructor the instructor that cohort carries, if any
 *   blockers   [['field','why','options']] — what a human must answer
 *   notes      [string] — resolved-but-worth-saying
 *
 * `$overrides` lets the confirming screen change any of course/level/programme/
 * instructor before committing. That is the "unless a change is required" half:
 * the form is pre-filled, not locked.
 */
function mam_enrol_resolve(int $appId, array $overrides = []): array
{
    $app = mam_db_row('SELECT * FROM applications WHERE id = ?', [$appId]);
    if (!$app) throw new InvalidArgumentException('application_not_found');

    $out = ['application' => $app, 'ready' => false, 'course' => null, 'level' => null,
            'programme' => null, 'student' => null, 'cohort' => null, 'instructor' => null,
            'blockers' => [], 'notes' => []];

    /* ── course ──────────────────────────────────────────────────────────── */
    $courseCode = (string) ($overrides['course'] ?? $app['discipline'] ?? '');
    if ($courseCode !== '') {
        $c = mam_db_row('SELECT id, code, name FROM courses WHERE code = ? AND active = 1', [$courseCode]);
        if ($c) $out['course'] = ['id' => (int) $c['id'], 'code' => $c['code'], 'name' => $c['name']];
    }
    if (!$out['course']) {
        // The applicant named an instrument the academy does not teach as a
        // course — violin and clarinet both appear in the live data. Offering
        // the catalogue is the honest response; guessing a near-match would
        // enrol somebody in the wrong subject.
        $out['blockers'][] = [
            'field'   => 'course',
            'why'     => $courseCode === ''
                ? 'The application does not say which instrument or subject.'
                : sprintf('The application asks for "%s", which is not one of the courses on offer.', $courseCode),
            'options' => mam_db_all('SELECT code, name FROM courses WHERE active = 1 ORDER BY kind DESC, name'),
        ];
    }

    /* ── level ───────────────────────────────────────────────────────────── */
    $levelCode = array_key_exists('level', $overrides) ? (string) $overrides['level'] : (string) ($app['level'] ?? '');
    if ($levelCode !== '') {
        $l = mam_db_row('SELECT id, code, name FROM levels WHERE code = ?', [$levelCode]);
        if ($l) $out['level'] = ['id' => (int) $l['id'], 'code' => $l['code'], 'name' => $l['name']];
    }
    // A missing level is NOT a blocker. It is legal to enrol without one, the
    // curriculum has an unlevelled path, and 15 of 26 applications have none —
    // making it mandatory here would block more than half the queue over a
    // field the applicant was never asked for.
    if (!$out['level']) $out['notes'][] = 'No level recorded — enrolling without one. You can set it later.';

    /* ── programme ───────────────────────────────────────────────────────── */
    $progCode = array_key_exists('programme', $overrides)
        ? (string) $overrides['programme']
        : (string) mam_enrol_programme_code($app['pathway'] ?? null, $app['program_track'] ?? null);
    if ($progCode !== '') {
        $p = mam_db_row('SELECT id, code, name FROM programs WHERE code = ?', [$progCode]);
        if ($p) $out['programme'] = ['id' => (int) $p['id'], 'code' => $p['code'], 'name' => $p['name']];
    }
    if (!$out['programme']) {
        $out['notes'][] = sprintf('Programme not certain from "%s" — enrolling without one.',
            (string) ($app['pathway'] ?? '—'));
    }

    /* ── the person ──────────────────────────────────────────────────────── */
    $cust = $app['customer_id'] ? mam_db_row('SELECT * FROM customers WHERE id = ?', [(int) $app['customer_id']]) : null;
    if (!$cust) {
        $out['blockers'][] = ['field' => 'customer', 'why' => 'This application has no applicant record attached.', 'options' => []];
    } else {
        // Somebody who exercised their right to erasure must not be enrolled.
        // Their applications survive so the funnel counts reconcile, and two of
        // the four in the live backlog are theirs — so without this guard the
        // "clear the backlog" button would rebuild a person the academy was
        // asked to forget, and email them a password.
        if (str_ends_with(strtolower(trim((string) $cust['email'])), '.invalid')) {
            $out['blockers'][] = ['field' => 'erased', 'options' => [],
                'why' => 'This applicant asked to be erased. Their application is kept so the '
                       . 'numbers still add up, but they cannot be enrolled. If they have come '
                       . 'back, take a fresh application.'];
            $out['ready'] = false;
            return $out;
        }
        $hold = mam_application_identity_held($app);
        if ($hold !== null && empty($overrides['identity_confirmed'])) {
            $out['blockers'][] = ['field' => 'identity', 'options' => [],
                'why' => mam_application_identity_hold_text($hold, $cust)];
        }
        $u = mam_db_row('SELECT id, email, name FROM users WHERE LOWER(email) = LOWER(?)', [$cust['email']]);
        $out['student'] = [
            'id'     => $u ? (int) $u['id'] : null,
            'email'  => (string) $cust['email'],
            'name'   => trim(((string) $cust['first_name']) . ' ' . ((string) $cust['last_name'])),
            'exists' => (bool) $u,
        ];
        if (!$u) $out['notes'][] = 'A portal account will be created and the sign-in details emailed.';
    }

    /* ── where they would land ───────────────────────────────────────────── */
    // Resolved read-only, so the admin sees the actual class and teacher before
    // committing rather than discovering them afterwards.
    if ($out['course'] && function_exists('mam_lms_ensure_cohort')) {
        try {
            $cohortId = mam_lms_ensure_cohort($out['course']['id'], $out['level']['id'] ?? null, $out['programme']['id'] ?? null);
            $co = mam_db_row('SELECT * FROM cohorts WHERE id = ?', [$cohortId]);
            if ($co) {
                $seats = function_exists('mam_lms_cohort_fill_count') ? (int) mam_lms_cohort_fill_count($cohortId) : 0;
                $cap   = $co['capacity'] === null ? null : (int) $co['capacity'];
                $out['cohort'] = ['id' => $cohortId, 'title' => (string) ($co['title'] ?? ''),
                                  'capacity' => $cap, 'filled' => $seats,
                                  'full' => $cap !== null && $seats >= $cap];
                $instrId = (int) ($overrides['instructor'] ?? $co['instructor_id'] ?? 0)
                    ?: (int) (mam_lms_default_instructor($out['course']['id'], $out['level']['id'] ?? null) ?? 0);
                if ($instrId) {
                    $iu = mam_db_row('SELECT id, name, email FROM users WHERE id = ?', [$instrId]);
                    if ($iu) $out['instructor'] = ['id' => (int) $iu['id'], 'name' => (string) $iu['name'], 'email' => (string) $iu['email']];
                }
                if (!$out['instructor']) {
                    // Not a blocker: an unassigned student is visible and
                    // fixable, whereas refusing to enrol them leaves the
                    // application stuck and the person waiting.
                    $out['notes'][] = 'No instructor is assigned to this class yet — assign one after enrolling.';
                }
                if ($out['cohort']['full']) {
                    $out['notes'][] = 'This class is full — the student will be added to the waiting list in position order.';
                }
            }
        } catch (Throwable $e) {
            $out['notes'][] = 'Could not work out the class in advance: ' . $e->getMessage();
        }
    }

    $out['ready'] = !$out['blockers'];
    return $out;
}

/**
 * Do it: account, course enrolment, cohort, instructor — in that order.
 *
 * Returns a step-by-step record of what happened. Nothing is swallowed; a step
 * that fails is reported with its reason and the caller shows it. `$overrides`
 * carries whatever the confirming screen changed.
 */
function mam_enrol_complete(int $appId, array $overrides = [], ?int $actorId = null): array
{
    $plan = mam_enrol_resolve($appId, $overrides);
    if (!$plan['ready']) {
        return ['ok' => false, 'steps' => [], 'plan' => $plan,
                'error' => 'Not enough information to enrol — answer the questions below first.'];
    }

    $steps = [];
    $app   = $plan['application'];

    /* 1 — the portal account */
    $studentId = $plan['student']['id'] ?? null;
    if (!$studentId) {
        try {
            $prov = mam_ensure_student_user((int) $app['customer_id']);
            $studentId = (int) ($prov['user']['id'] ?? 0);
            /* This step used to say "sign-in details emailed" while nothing was
               sent: the provisioner made a password and nobody received it. The
               invitation is sent now, and the step says whether it went. */
            $invited = ($studentId && !empty($prov['created'])) ? mam_auth_send_invite($studentId, 'student', 'portal_welcome') : null;
            $steps[] = ['step' => 'account', 'ok' => (bool) $studentId,
                        'detail' => !$studentId ? 'Account was not created.'
                            : (empty($prov['created']) ? 'Already had a portal account.'
                            : (!empty($invited['sent']) ? 'Portal account created; an invitation to set a password was emailed.'
                                        : 'Portal account created, but the invitation could not be sent — send one from the member record.'))];
            if (!$studentId) return ['ok' => false, 'steps' => $steps, 'plan' => $plan,
                                     'error' => 'The portal account could not be created, so nothing else was attempted.'];
        } catch (Throwable $e) {
            // The old code caught this and wrote a log line, so the admin saw
            // "Application updated" and the student got nothing. Never again.
            $steps[] = ['step' => 'account', 'ok' => false, 'detail' => $e->getMessage()];
            return ['ok' => false, 'steps' => $steps, 'plan' => $plan,
                    'error' => 'The portal account could not be created: ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['step' => 'account', 'ok' => true, 'detail' => 'Already had a portal account.'];
    }

    /* 1b — the guardian the application named, with their own account. */
    if (function_exists('mam_application_link_guardian') && trim((string) ($app['parent_email'] ?? '')) !== '') {
        $g = mam_application_link_guardian($app, (int) $studentId, $actorId);
        $steps[] = ['step' => 'guardian', 'ok' => $g['linked'] || $g['reason'] === 'same_as_student',
            'detail' => $g['linked'] ? ('Guardian linked' . ($g['invited'] ? ' and invited to set a password.' : '.'))
                : ($g['reason'] === 'same_as_student' ? 'The guardian gave the student\'s own email, so no separate guardian account was made — link one from the guardian screen.'
                : 'The guardian could not be linked (' . $g['reason'] . ').')];
    }

    /* 2 — the course enrolment, which resolves the cohort and instructor */
    try {
        $r = mam_lms_add_course(
            $studentId,
            $plan['course']['code'],
            $plan['level']['code'] ?? null,
            $plan['programme']['code'] ?? null,
            isset($overrides['instructor']) ? (int) $overrides['instructor'] : null
        );
        // mam_lms_add_course returns id === null on the waitlisted branch — a
        // caller that reads it as an int gets 0 and thinks it failed.
        $enrolId = (int) ($r['id'] ?? 0);
        $status  = (string) ($r['status'] ?? '');
        if ($status === 'waitlisted') {
            $steps[] = ['step' => 'course', 'ok' => true, 'waitlisted' => true,
                        'detail' => sprintf('The class is full, so %s is on the waiting list at position %d. They move up automatically when a place frees.',
                            $plan['student']['name'], (int) ($r['position'] ?? 0))];
        } else {
            $steps[] = ['step' => 'course', 'ok' => true, 'enrolment_id' => $enrolId,
                        'detail' => sprintf('Enrolled in %s%s.', $plan['course']['name'],
                            $plan['level'] ? ' (' . $plan['level']['name'] . ')' : '')];
        }
        $cohortId = (int) ($r['cohort_id'] ?? 0);
    } catch (Throwable $e) {
        $why = $e->getMessage() === 'already_enrolled'
            ? 'They are already enrolled in this course.'
            : $e->getMessage();
        $steps[] = ['step' => 'course', 'ok' => $e->getMessage() === 'already_enrolled', 'detail' => $why];
        if ($e->getMessage() !== 'already_enrolled') {
            return ['ok' => false, 'steps' => $steps, 'plan' => $plan,
                    'error' => 'The course enrolment failed: ' . $why];
        }
        $cohortId = 0;
    }

    /* 3 — the instructor, ACTUALLY assigned, then the class named */
    if ($cohortId) {
        $co = mam_db_row('SELECT * FROM cohorts WHERE id = ?', [$cohortId]);

        // The plan screen names an instructor, so completion must assign one —
        // an earlier version reported the name and wrote nothing, which is the
        // same class of lie as the status that said "enrolled" and enrolled
        // nobody. Order matters and is the academy's own: instructor first,
        // then the class they teach is what the student is allocated to.
        $instr = (int) ($overrides['instructor'] ?? 0)
              ?: (int) ($co['instructor_id'] ?? 0)
              ?: (int) (mam_lms_default_instructor((int) $plan['course']['id'], $plan['level']['id'] ?? null) ?? 0);

        /* Safeguarding gate — see api/modules/safeguarding/lib/vetting.php. This path runs from an
           admin click AND from payment settlement, so it is the one an uncleared
           instructor is most likely to slip through unattended.

           An unclear result never fails the enrolment: the student is enrolled
           either way, and refusing the whole placement because a certificate has
           lapsed would leave a paid applicant in nothing at all. In `enforce` the
           instructor is simply not attached, and the step says why — which is the
           correct outcome, because "enrolled, awaiting a cleared teacher" is a
           state the academy can act on. */
        $vet = function_exists('mam_vetting_check_assignment')
            ? mam_vetting_check_assignment($instr, $cohortId)
            : ['allow' => true, 'reason' => ''];
        if ($instr && !$vet['allow']) {
            $steps[] = ['step' => 'instructor', 'ok' => false, 'safeguarding' => true,
                        'detail' => 'Instructor NOT assigned — ' . $vet['reason']
                                  . ' Record their clearances under Staff & Vetting, then assign.'];
            $instr = 0;
        } elseif ($instr && $vet['reason'] !== '') {
            // warn mode: assigned, but the gap is reported rather than buried.
            $steps[] = ['step' => 'safeguarding', 'ok' => false,
                        'detail' => 'Assigned, but ' . lcfirst($vet['reason'])];
        }

        if ($instr) {
            // The cohort carries the teacher for everyone in it; the enrolment
            // carries it too, because mam_lms_instructor_cohorts() keys off
            // EITHER, and setting only one leaves the roster invisible to the
            // instructor who is supposed to teach it.
            if (!(int) ($co['instructor_id'] ?? 0)) {
                mam_db_update('cohorts', ['instructor_id' => $instr], ['id' => $cohortId]);
            }
            if ($studentId) {
                // mam_db_update() only builds `col = ?` conditions, so the
                // "don't clobber an existing assignment" guard needs raw SQL.
                mam_db()->prepare('UPDATE course_enrollments SET instructor_id = ?
                                    WHERE student_id = ? AND cohort_id = ? AND instructor_id IS NULL')
                        ->execute([$instr, $studentId, $cohortId]);
            }
            $iname = (string) (mam_db_row('SELECT name FROM users WHERE id = ?', [$instr])['name'] ?? $instr);
            $steps[] = ['step' => 'instructor', 'ok' => true, 'instructor_id' => $instr,
                        'detail' => 'Instructor assigned: ' . $iname];
        } else {
            // Not a failure: the student is enrolled and visible. But it is the
            // one thing left undone, so it is said plainly rather than implied.
            $steps[] = ['step' => 'instructor', 'ok' => false,
                        'detail' => 'No instructor available for this course yet — assign one from Course Registrations.'];
        }

        $co = mam_db_row('SELECT * FROM cohorts WHERE id = ?', [$cohortId]);
        $steps[] = ['step' => 'class', 'ok' => true, 'cohort_id' => $cohortId,
                    'detail' => 'Class: ' . (string) ($co['title'] ?? ('#' . $cohortId))];
    }

    /* 4 — move the application on, through the audited state machine */
    if (($app['status'] ?? '') !== 'enrolled') {
        try {
            mam_admissions_transition($appId, 'enrolled', $actorId, 'Enrolment completed');
            $steps[] = ['step' => 'application', 'ok' => true, 'detail' => 'Application marked enrolled.'];
        } catch (Throwable $e) {
            // The person IS enrolled at this point; the label lagging behind is
            // worth reporting but must not read as a failed enrolment.
            $steps[] = ['step' => 'application', 'ok' => false,
                        'detail' => 'Enrolled, but the application status could not be updated: ' . $e->getMessage()];
        }
    }

    if (function_exists('mam_audit')) {
        mam_audit('enrol_complete', 'application', $appId, [
            'student_id' => $studentId, 'course' => $plan['course']['code'],
            'level' => $plan['level']['code'] ?? null, 'cohort_id' => $cohortId ?: null,
        ]);
    }

    // The office confirmed this was the same person (identity_confirmed), and
    // the enrolment went through: the hold has done its job.
    if (!empty($overrides['identity_confirmed']) && mam_application_identity_held($app) !== null) {
        mam_application_identity_release((int) $app['id'], $actorId);
    }
    return ['ok' => true, 'steps' => $steps, 'plan' => $plan, 'student_id' => $studentId,
            'error' => null];
}

/**
 * Applications the academy considers accepted or enrolled but that are in no
 * course — the backlog this whole file exists to clear. Four of five on the
 * live data.
 */
function mam_enrol_incomplete(int $limit = 50): array
{
    return mam_db_all(
        "SELECT a.id, a.reference, a.status, a.discipline, a.level, a.pathway, a.program_track,
                c.first_name, c.last_name, c.email
           FROM applications a
           LEFT JOIN customers c ON c.id = a.customer_id
          WHERE a.status IN ('accepted', 'paid', 'enrolled')
            AND NOT EXISTS (
                SELECT 1 FROM users u
                  JOIN course_enrollments ce ON ce.student_id = u.id
                 WHERE LOWER(u.email) = LOWER(c.email)
                   AND ce.status IN ('active', 'pending', 'completed'))
          ORDER BY a.created_at DESC
          LIMIT " . max(1, min(200, $limit)));
}

/**
 * Applications damaged by the registration-photo bug (fixed 2026-11).
 *
 * mam_validate_application() used to `return` as soon as it accepted a
 * first-party headshot (/api/media/headshot?k=…), so every field validated
 * after the photo was never set and was stored as NULL: date of birth, age
 * band, programme track, guardian, readiness, the policy agreement and its
 * signature, and the registration flag (so the $5 booking fee was left out of
 * the total). Those values were never stored anywhere, so they cannot be
 * rebuilt from the database — the family has to be asked again.
 *
 * The fingerprint is the photo URL plus the two fields every valid submission
 * sets and this bug always left NULL. A healthy application can't match it:
 * program_track is always 'MAM' or 'KTAMS' and the policy has to be agreed.
 * This is the admin's way to find them, since the host has no shell to run SQL.
 */
function mam_application_damaged(int $limit = 200): array
{
    return mam_db_all(
        "SELECT a.id, a.reference, a.status, a.total_usd, a.created_at,
                c.first_name, c.last_name, c.email, c.phone,
                -- A RETURNING family's other applications share this customer row,
                -- and the same bug wrote NULL over the age, country and city stored
                -- on it. Counted so the screen can say so, not only of this one.
                (SELECT COUNT(*) FROM applications o WHERE o.customer_id = a.customer_id AND o.id <> a.id) AS other_apps
           FROM applications a
           LEFT JOIN customers c ON c.id = a.customer_id
          WHERE a.headshot_url LIKE '/api/media/headshot%'
            AND a.program_track IS NULL
            AND a.policy_agreed IS NULL
          ORDER BY a.created_at DESC
          LIMIT " . max(1, min(500, $limit)));
}
