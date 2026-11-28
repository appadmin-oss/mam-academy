<?php
/**
 * Admissions decisions reach the family (admissions fixes 3 and 7, 2026-11-28).
 * ─────────────────────────────────────────────────────────────
 * An offer, a waiting-list place and a refusal used to change a status column
 * and stop: nothing was sent, and /status told families to "watch your email"
 * for messages that never came. A refusal could be saved with no reason at all,
 * although the console's own card said one was required.
 *
 * This file is the one place a decision is TAKEN — the transition through the
 * state machine, the reason a refusal must carry, and the email that tells the
 * family — for both intakes:
 *
 *   mam_admissions_decide()  year-round applications (applications)
 *   mam_summer_set_status()  summer applications (summer_applications) — the ONE
 *                            implementation the console form and the JSON route
 *                            both call, so the two can no longer disagree about
 *                            the state machine or about what "enrolled" does.
 *
 * Mail goes through mam_send(), so every message is in mail_log.
 *
 * Who is written to: a CHILD's correspondence (under 18, NDPA s.31) goes to the
 * guardian the application named, greeting the guardian; an adult's to the
 * applicant. A learner with no email of their own is never written to directly.
 */
declare(strict_types=1);

/** The decisions a person may take on a year-round application from the console.
 *  'paid' is a payment fact (recorded by settlement or a bank confirmation) and
 *  'enrolled' an outcome (Complete the enrolment), so neither is a decision. */
const MAM_ADMISSIONS_DECISIONS = ['submitted', 'in_review', 'accepted', 'waitlisted', 'cancelled'];

/** The statuses that tell the family something, and so send a message. */
const MAM_ADMISSIONS_DECISIONS_MAILED = ['accepted', 'waitlisted', 'cancelled'];

/** Absolute URL on the academy site. */
function mam_admissions_url(string $path): string {
    return rtrim((string) mam_env('APP_URL', 'https://academy.mamcentre.org'), '/') . $path;
}

/** A programme's name, as a family reads it. */
function mam_admissions_programme_label(?string $pathway): string {
    return [
        'standard' => 'the Standard programme', 'certified-musician' => 'the Certified Musician programme',
        'grand-musician' => 'the Grand Musician programme', 'crash' => 'the Crash Programme',
        'meta-order' => 'The MetaMusic Order',
    ][strtolower(trim((string) $pathway))] ?? 'MAM Academy';
}

/** First word of a name, or $fallback. */
function mam_admissions_first_word(?string $name, string $fallback = 'there'): string {
    $w = trim(explode(' ', trim((string) $name))[0] ?? '');
    return $w !== '' ? $w : $fallback;
}

/**
 * Who an application's correspondence goes to, and whom it greets.
 *
 * @return array{email:?string, greet:string, guardian:bool, learner:string}
 *   email is null when there is nobody to write to (a child with no address of
 *   their own and no guardian address — which validation no longer admits).
 */
function mam_application_contact(array $app): array {
    $cust = !empty($app['customer_id'])
        ? (mam_db_row('SELECT first_name, last_name, email, dob FROM customers WHERE id = ?', [(int) $app['customer_id']]) ?: [])
        : [];
    // A held application's customer row is the OTHER person who holds the
    // address; the learner is the one the application was made for.
    $hold = function_exists('mam_application_identity_held') ? mam_application_identity_held($app) : null;
    $learnerFirst = (string) ($hold['submitted']['first_name'] ?? $cust['first_name'] ?? '');
    $dob = (string) ($hold['submitted']['dob'] ?? $cust['dob'] ?? '');
    $age = $dob !== '' ? mam_age_from_dob($dob) : null;
    $minor = $age !== null ? $age < MAM_ADULT_AGE : ((string) ($app['program_track'] ?? '') === 'KTAMS');
    $own = strtolower(trim((string) ($cust['email'] ?? '')));
    $placeholder = mam_email_is_placeholder($own);
    $pe = strtolower(trim((string) ($app['parent_email'] ?? '')));
    $learner = mam_admissions_first_word($learnerFirst, 'your child');
    if (($minor || $placeholder) && $pe !== '' && filter_var($pe, FILTER_VALIDATE_EMAIL)) {
        return ['email' => $pe, 'greet' => mam_admissions_first_word((string) ($app['parent_name'] ?? '')),
                'guardian' => true, 'learner' => $learner];
    }
    return ['email' => ($own !== '' && !$placeholder) ? $own : null,
            'greet' => mam_admissions_first_word($learnerFirst), 'guardian' => false,
            'learner' => mam_admissions_first_word($learnerFirst, 'you')];
}

/**
 * The message for a decision on a year-round application. Returns
 * ['subject','html'] — pure, so it can be tested without a mailer.
 */
function mam_admissions_decision_message(array $app, string $status, ?string $reason, array $contact): array {
    $h = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
    $ref = (string) ($app['reference'] ?? '');
    $prog = mam_admissions_programme_label($app['pathway'] ?? null);
    $g = $contact['guardian'];
    $who = $g ? $contact['learner'] : 'you';
    $whose = $g ? $contact['learner'] . '’s' : 'your';
    $statusUrl = mam_admissions_url('/status?ref=' . rawurlencode($ref));
    $p = static fn($t) => "<p style='margin:0 0 12px;font-size:15px;line-height:1.7;color:#4A3624'>$t</p>";
    $body = "<h2 style='font-family:Georgia,serif;margin:0 0 14px;font-size:22px;color:#1A120B'>Dear " . $h($contact['greet']) . ',</h2>';
    switch ($status) {
        case 'accepted':
            $subject = 'An offer from MAM Academy (' . $ref . ')';
            $body .= $p('We are delighted to offer ' . $h($who) . ' a place on ' . $h($prog) . '.')
                . $p('To hold the place, pay the tuition. ' . ($g ? 'Their' : 'Your') . ' timetable and first lesson date follow once it is paid.')
                . mam_email_button(mam_admissions_url('/tuition?ref=' . rawurlencode($ref)), 'Pay tuition to hold the place');
            break;
        case 'waitlisted':
            $subject = 'You are on the MAM Academy waiting list (' . $ref . ')';
            $body .= $p(ucfirst($h($who)) . ($g ? ' is' : ' are') . ' on our waiting list for ' . $h($prog) . '.')
                . $p('There is nothing you need to do. We will write the moment a place frees, and the place is offered in the order the waiting list was joined.');
            break;
        case 'cancelled':
        default:
            $subject = 'Your MAM Academy application (' . $ref . ')';
            $body .= $p('Thank you for applying. We are sorry: we cannot offer ' . $h($who) . ' a place on ' . $h($prog) . ' this intake.');
            if ($reason !== null && trim($reason) !== '') {
                $body .= "<p style='margin:0 0 6px;font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#9A6B1E;font-weight:700'>Why</p>"
                    . "<p style='margin:0 0 14px;padding:14px 16px;border-left:3px solid #B8721B;background:#FBF4E8;font-size:15px;line-height:1.7;color:#1A120B;white-space:pre-wrap'>" . $h(trim($reason)) . '</p>';
            }
            $body .= $p('You are welcome to apply again for a later intake. If you have a question about this decision, reply to this email.');
    }
    $body .= $p("<a href='" . $h($statusUrl) . "' style='color:#B8721B;font-weight:600'>See " . $h($whose) . ' application status</a> — reference <strong>' . $h($ref) . '</strong>.');
    $html = mam_email_layout(['header' => 'MAM ACADEMY',
        'preheader' => strip_tags($subject),
        'reason' => 'You received this because ' . ($g ? 'you applied to MAM Academy for ' . $contact['learner'] : 'you applied to MAM Academy') . '.',
        'body' => $body]);
    return ['subject' => $subject, 'html' => $html];
}

/** Email the family about a decision. True when the mailer accepted it. */
function mam_admissions_decision_email(int $appId, string $status, ?string $reason = null): bool {
    if (!in_array($status, MAM_ADMISSIONS_DECISIONS_MAILED, true)) return false;
    $app = mam_db_row('SELECT * FROM applications WHERE id = ?', [$appId]);
    if (!$app) return false;
    $c = mam_application_contact($app);
    if ($c['email'] === null) { mam_log('warn', 'decision_no_recipient', ['app' => $appId, 'status' => $status]); return false; }
    $m = mam_admissions_decision_message($app, $status, $reason, $c);
    return mam_send($c['email'], $m['subject'], $m['html'], 'application_decision_' . $status, true);
}

/**
 * Take a decision on a year-round application: the transition (enforced and
 * written to application_events by mam_admissions_transition), the reason a
 * refusal must carry, and the email to the family.
 *
 * Throws RuntimeException: not_a_decision ('paid'/'enrolled'), illegal_transition,
 * reason_required (a refusal with no reason), application_not_found.
 * Returns the transition result plus `emailed`.
 */
function mam_admissions_decide(int $appId, string $to, ?int $by = null, ?string $reason = null): array {
    $reason = trim((string) $reason);
    if (in_array($to, ['paid', 'enrolled'], true)) throw new RuntimeException('not_a_decision');
    if (!in_array($to, MAM_ADMISSIONS_DECISIONS, true)) throw new RuntimeException('illegal_transition');
    // A refusal needs its own reason, in words the family will read.
    if ($to === 'cancelled' && $reason === '') throw new RuntimeException('reason_required');
    $res = mam_admissions_transition($appId, $to, $by, $reason !== '' ? $reason : null);
    $res['emailed'] = false;
    if ($res['changed'] && in_array($to, MAM_ADMISSIONS_DECISIONS_MAILED, true)) {
        $res['emailed'] = mam_admissions_decision_email($appId, $to, $reason !== '' ? $reason : null);
        mam_audit('application_decision_emailed', 'application', $appId, ['status' => $to, 'sent' => $res['emailed']]);
    }
    return $res;
}

/** The sentence the office reads when a decision was refused. */
function mam_admissions_decide_refusal(string $code): string {
    return [
        'not_a_decision'        => 'Paid and Enrolled are not decisions: a payment is recorded when the money arrives (Invoices & payments), and a student is enrolled with “Complete the enrolment” below, which places them in a class.',
        'illegal_transition'    => 'That status change isn’t allowed from the current stage.',
        'reason_required'       => 'A refusal needs a reason the family can read. Write it in “Reason the family can read”, then save.',
        'application_not_found' => 'Application not found.',
    ][$code] ?? 'The decision was not saved.';
}

/* ── Summer ──────────────────────────────────────────────────────────────── */

/** The summer statuses that send a message to the family. */
const MAM_SUMMER_STATUSES_MAILED = ['confirmed', 'waitlisted', 'rejected', 'enrolled'];

/** Who a summer application's correspondence goes to (a child's: the guardian). */
function mam_summer_contact(array $s): array {
    $age = (string) ($s['dob'] ?? '') !== '' ? mam_age_from_dob((string) $s['dob']) : null;
    if ($age === null && isset($s['age_at_submission'])) $age = (int) $s['age_at_submission'];
    $learner = mam_admissions_first_word((string) ($s['first_name'] ?? ''), 'your child');
    $ge = strtolower(trim((string) ($s['guardian_email'] ?? '')));
    if ($age !== null && $age < MAM_ADULT_AGE && $ge !== '' && filter_var($ge, FILTER_VALIDATE_EMAIL)) {
        return ['email' => $ge, 'greet' => mam_admissions_first_word((string) ($s['guardian_name'] ?? '')), 'guardian' => true, 'learner' => $learner];
    }
    $own = strtolower(trim((string) ($s['email'] ?? '')));
    return ['email' => $own !== '' ? $own : null, 'greet' => $learner === 'your child' ? 'there' : $learner, 'guardian' => false, 'learner' => 'you'];
}

/** The message for a summer decision. Pure: returns ['subject','html']. */
function mam_summer_decision_message(array $s, string $status, ?string $reason, array $c): array {
    $h = static fn($x) => htmlspecialchars((string) $x, ENT_QUOTES);
    $ref = (string) ($s['reference'] ?? '');
    $prog = (string) mam_setting('summer_prog_name', 'MAM Summer Music Camp');
    $g = $c['guardian'];
    $who = $g ? $c['learner'] : 'you';
    $p = static fn($t) => "<p style='margin:0 0 12px;font-size:15px;line-height:1.7;color:#4A3624'>$t</p>";
    $body = "<h2 style='font-family:Georgia,serif;margin:0 0 14px;font-size:22px;color:#1A120B'>Dear " . $h($c['greet']) . ',</h2>';
    switch ($status) {
        case 'confirmed':
            $subject = 'A place at the ' . $prog . ' (' . $ref . ')';
            $body .= $p('We are delighted to confirm a place for ' . $h($who) . ' at the ' . $h($prog) . '.')
                . $p('We will write again with the schedule, what to bring and how to sign in.');
            break;
        case 'waitlisted':
            $subject = 'The ' . $prog . ' waiting list (' . $ref . ')';
            $body .= $p(ucfirst($h($who)) . ($g ? ' is' : ' are') . ' on the waiting list for the ' . $h($prog) . '. There is nothing you need to do: we will write as soon as a place opens.');
            break;
        case 'enrolled':
            $subject = 'Enrolled: the ' . $prog . ' (' . $ref . ')';
            $body .= $p(ucfirst($h($who)) . ($g ? ' is' : ' are') . ' enrolled at the ' . $h($prog) . '.')
                . $p('If an account was made, a separate email carries the link to set its password. Links are never sent with a password in them.');
            break;
        case 'rejected':
        default:
            $subject = 'Your ' . $prog . ' application (' . $ref . ')';
            $body .= $p('Thank you for applying. We are sorry: we cannot offer ' . $h($who) . ' a place at the ' . $h($prog) . ' this season.');
            if ($reason !== null && trim($reason) !== '') {
                $body .= "<p style='margin:0 0 6px;font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#9A6B1E;font-weight:700'>Why</p>"
                    . "<p style='margin:0 0 14px;padding:14px 16px;border-left:3px solid #B8721B;background:#FBF4E8;font-size:15px;line-height:1.7;color:#1A120B;white-space:pre-wrap'>" . $h(trim($reason)) . '</p>';
            }
            $body .= $p('If you have a question about this decision, reply to this email.');
    }
    $body .= $p("<a href='" . $h(mam_admissions_url('/status?ref=' . rawurlencode($ref))) . "' style='color:#B8721B;font-weight:600'>See the application status</a> — reference <strong>" . $h($ref) . '</strong>.');
    return ['subject' => $subject, 'html' => mam_email_layout(['header' => 'MAM ACADEMY', 'preheader' => $subject,
        'reason' => 'You received this because of an application to the ' . $prog . '.', 'body' => $body])];
}

/** Email a summer family about a decision. True when the mailer accepted it. */
function mam_summer_decision_email(int $id, string $status, ?string $reason = null): bool {
    if (!in_array($status, MAM_SUMMER_STATUSES_MAILED, true)) return false;
    $s = mam_db_row('SELECT * FROM summer_applications WHERE id = ?', [$id]);
    if (!$s) return false;
    $c = mam_summer_contact($s);
    if ($c['email'] === null) return false;
    $m = mam_summer_decision_message($s, $status, $reason, $c);
    return mam_send($c['email'], $m['subject'], $m['html'], 'summer_decision_' . $status, true);
}

/**
 * Move a summer application to $to — the ONE implementation (the console form
 * `summer_set_status` and POST /api/admin/summer/status both call it).
 *
 *   • the state machine (mam_summer_can_transition) is enforced; re-asserting
 *     the current status is a no-op;
 *   • a refusal ('rejected') needs a reason, which is stored and emailed;
 *   • 'enrolled' really enrols (mam_summer_enrol) BEFORE the status moves, and an
 *     application held for identity (the address belongs to someone else — a
 *     sibling's place) is NOT enrolled and keeps its status: the admin is told;
 *   • the student's and guardian's invitations, the ID card (id_card_issue
 *     automation) and the family's email follow.
 *
 * $opts: identity_confirmed (bool) — the office checked it is the same person.
 * Throws RuntimeException: not_found, illegal_transition, reason_required.
 * Returns ['ok','changed','from','to','held','message','enrolment','emailed'].
 */
function mam_summer_set_status(int $id, string $to, ?int $by = null, ?string $reason = null, array $opts = []): array {
    $row = mam_db_row('SELECT id, reference, status FROM summer_applications WHERE id = ?', [$id]);
    if (!$row) throw new RuntimeException('not_found');
    $from = (string) $row['status'];
    $reason = trim((string) $reason);
    $out = ['ok' => true, 'changed' => false, 'from' => $from, 'to' => $to, 'held' => false,
            'message' => '', 'enrolment' => null, 'emailed' => false];
    if ($from === $to) { $out['message'] = 'No change.'; return $out; }
    if (!mam_summer_can_transition($from, $to)) throw new RuntimeException('illegal_transition');
    if ($to === 'rejected' && $reason === '') throw new RuntimeException('reason_required');

    if ($to === 'enrolled') {
        $enr = mam_summer_enrol($id, $by, ['identity_confirmed' => !empty($opts['identity_confirmed'])]);
        $out['enrolment'] = $enr;
        if (($enr['reason'] ?? '') === 'identity_held') {
            $out['ok'] = false; $out['held'] = true;
            $out['message'] = mam_summer_identity_hold_text((array) ($enr['hold'] ?? []));
            mam_audit('summer_enrol_held', 'summer_application', $id, ['reason' => $enr['hold']['reason'] ?? null]);
            return $out;
        }
    }

    $upd = ['status' => $to, 'updated_at' => date('Y-m-d H:i:s')];
    if ($reason !== '') $upd['decision_reason'] = mb_substr($reason, 0, 2000);
    mam_db_update('summer_applications', $upd, ['id' => $id]);
    mam_audit('summer_status', 'summer_application', $id, ['from' => $from, 'to' => $to, 'reason' => $reason !== '' ? $reason : null, 'by' => $by]);
    $out['changed'] = true;

    if ($to === 'enrolled' && $out['enrolment']) {
        $e = $out['enrolment'];
        // Links to choose a password, never a password; the guardian's own.
        if (!empty($e['invite'])) mam_auth_send_invite((int) $e['user_id'], 'student', 'summer_student_invite');
        if (!empty($e['guardian_invite']) && !empty($e['guardian_id'])) mam_auth_send_invite((int) $e['guardian_id'], 'parent', 'summer_guardian_invite');
        if (function_exists('mam_card_mint_for_summer_app') && (!function_exists('mam_automation_on') || mam_automation_on('id_card_issue'))) {
            try { mam_card_mint_for_summer_app($id); } catch (Throwable $ex) { mam_log('warn', 'summer_card_failed', ['id' => $id, 'err' => $ex->getMessage()]); }
        }
        $out['message'] = ($e['enrollment_id'] ?? null)
            ? 'Enrolled in the summer class.'
            : 'Marked enrolled, but no class was made: ' . ($e['reason'] === 'course_not_found' ? 'their instrument is not one of the courses — place them from Teaching → Enrol & staffing.' : 'no instrument on the application.');
    }
    if (in_array($to, MAM_SUMMER_STATUSES_MAILED, true)) {
        $out['emailed'] = mam_summer_decision_email($id, $to, $reason !== '' ? $reason : null);
    }
    if ($out['message'] === '') $out['message'] = 'Status updated' . ($out['emailed'] ? ' and the family emailed.' : '.');
    return $out;
}

/** The sentence the office reads for a summer hold. */
function mam_summer_identity_hold_text(array $hold): string {
    $sub = trim(($hold['submitted']['first_name'] ?? '') . ' ' . ($hold['submitted']['last_name'] ?? ''));
    $ex = trim((string) ($hold['existing_name'] ?? ''));
    return sprintf('Not enrolled: this summer application is for %s, but its email already belongs to %s. '
        . 'Enrolling it would put this child into that account. If it really is the same person, tick the confirmation and enrol again; '
        . 'if it is a different person (a brother or sister on a parent\'s email), they need their own email first.',
        $sub !== '' ? $sub : 'someone', $ex !== '' ? $ex : 'another person');
}

/** The sentence for a refused summer move. */
function mam_summer_refusal(string $code): string {
    return [
        'not_found'          => 'That summer application no longer exists.',
        'illegal_transition' => 'That status change isn’t allowed from the current stage.',
        'reason_required'    => 'A refusal needs a reason the family can read.',
    ][$code] ?? 'Could not update the status.';
}
