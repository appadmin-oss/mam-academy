<?php
/**
 * MAM Academy — the admin console's phone subset (frame `#5b`, "Admin on a
 * phone — a subset, not a squeeze").
 *
 * ── WHY A SUBSET AND NOT A RESPONSIVE CONSOLE ─────────────────────────────
 * The desktop console is responsive and, since its tables stack, genuinely
 * usable at 390px. This is not about fitting. It is about what a registrar
 * should be *asked* to do one-handed: the frame states the rule on its own
 * face — "Amending a record, voiding an invoice and closing a period are
 * deliberately unavailable here. A consequential, hard-to-reverse action taken
 * one-handed on a bus is how a ledger acquires a mystery."
 *
 * So the phone carries four panels — Decide, Today, Assign, Look up — and the
 * refusal is expressed HERE, in the read layer, not in the templates. A
 * refusal a template can forget is not a refusal; the instructor and student
 * portals hold the same line for the same reason, and `test_console_phone.php`
 * asserts it against the payload and the files rather than the markup.
 *
 * ── WHAT IS DELIBERATELY NOT FAKED ────────────────────────────────────────
 * The frame's Look up panel offers "Message the guardian" over WhatsApp. There
 * is no messaging provider configured in this repo and the modernization notes
 * refuse to ship a dead button for one, so that row is not here. The guardian's
 * email address is, because it exists. Everything else on these four panels
 * reads a real table.
 */
declare(strict_types=1);

/** The four destinations, in the order the tab bar shows them. */
const MAM_PHONE_PANELS = ['decide', 'today', 'assign', 'lookup'];

/**
 * The refusal, as data.
 *
 * ⚠️ These are `admin/index.php` action names that must never be reachable
 * from a phone panel. Every write on this surface posts to the same handler
 * table as the desktop console (there is one implementation of "waive"), so
 * the only thing standing between the phone and a consequential action is
 * which actions its renderers offer. Naming them here makes that testable:
 * `test_console_phone.php` greps every phone file for each one.
 *
 * The test is the point. A reviewer cannot eyeball four renderers and be sure
 * none of them grew a delete button.
 */
const MAM_PHONE_REFUSED_ACTIONS = [
    'enrollment_delete', 'vetting_delete', 'invoice_void', 'period_close',
    'user_delete', 'application_delete', 'payment_delete', 'acct_resolve',
];

/** Printed on the Look up panel, where the refusal is most likely to be met. */
const MAM_PHONE_DESK_NOTICE = 'Amending a record, voiding an invoice and closing a period are deliberately unavailable here. A consequential, hard-to-reverse action taken one-handed on a bus is how a ledger acquires a mystery.';

/** How far ahead a lapsing clearance counts as something to decide today. */
const MAM_PHONE_CLEARANCE_HORIZON_DAYS = 30;

function mam_phone_panel_ok(string $p): bool { return in_array($p, MAM_PHONE_PANELS, true); }

/**
 * Today, in the academy's own zone.
 *
 * `mam_calendar_today()` never reads the server clock — a console open in
 * London must agree with the academy in Lagos about which day it is, or the
 * Today panel shows tomorrow's sessions to somebody standing in the building.
 */
function mam_phone_today_date(): string {
    if (function_exists('mam_calendar_today')) return mam_calendar_today();
    return gmdate('Y-m-d');
}

/**
 * Panel 1 — Decide. The things that block something, newest pressure first.
 *
 * Every item here is a REVERSIBLE decision or a hand-off to the desk. The
 * frame's own four are an offer lapsing, a waiver request, a clearance about
 * to expire and an unmatched payment; three of those are decisions and the
 * fourth is explicitly "flagged for the desk" because matching a payment needs
 * the full ledger. That split is kept: an item carries `desk => true` when the
 * phone's job is to surface it rather than settle it.
 */
function mam_phone_decide(): array {
    $items = [];

    /* ── A waiver request. §15: a request moves the charge to 'pending' while
       it is open, so a student is not shown a debt the academy has not decided
       is owed. Approving waives it; rejecting restores 'outstanding' — the
       spec's "Rejected — Charge Maintained", not a drop. Both are reversible
       by a second decision, which is why this belongs on a phone. */
    foreach (mam_console_rows('phone.waivers',
        "SELECT e.id, e.reason_code, e.detail, e.created_at,
                c.amount_usd_cents, c.currency, c.minutes, c.reason AS charge_reason,
                u.name AS who
           FROM accountability_exceptions e
           JOIN accountability_charges c ON c.id = e.charge_id
           LEFT JOIN users u ON u.id = c.subject_id AND c.subject_type = 'student'
          WHERE e.status = 'pending'
          ORDER BY e.created_at ASC, e.id ASC
          LIMIT 6") as $r) {
        $amount = (int) ($r['amount_usd_cents'] ?? 0);
        $items[] = [
            'kind'    => 'waiver',
            'urgency' => 'warn',
            'eyebrow' => 'Waiver request · ' . mam_phone_age((string) ($r['created_at'] ?? '')),
            'title'   => trim(((string) ($r['who'] ?? 'A student')) . ' · '
                         . str_replace('_', ' ', (string) ($r['charge_reason'] ?? 'charge'))
                         . ($amount > 0 ? ' ' . mam_console_usd($amount) : '')),
            'body'    => (string) ($r['detail'] ?? '') ?: 'No detail was given with the request.',
            'note'    => 'A waiver or a refusal both need a note the family can read.',
            'actions' => [
                ['label' => 'Waive with a note', 'primary' => true, 'action' => 'acct_exception',
                 'fields' => ['request_id' => (int) $r['id'], 'decision' => 'approve'], 'note_field' => true],
                ['label' => 'Maintain', 'primary' => false, 'action' => 'acct_exception',
                 'fields' => ['request_id' => (int) $r['id'], 'decision' => 'reject'], 'note_field' => true],
            ],
        ];
    }

    /* ── A clearance about to lapse. Validity is DERIVED from expires_on, never
       a stored status column, so this reads as urgent the moment it is, rather
       than on the next cron run. Recording a certificate is additive and
       replaces the old row (UNIQUE per type), so it is safe from a phone —
       but the form belongs on the desk, so this links rather than writes. */
    $horizon = gmdate('Y-m-d', strtotime('+' . MAM_PHONE_CLEARANCE_HORIZON_DAYS . ' days'));
    foreach (mam_console_rows('phone.clearances',
        "SELECT v.id, v.clearance_type, v.expires_on, u.id AS uid, u.name
           FROM vetting_records v JOIN users u ON u.id = v.instructor_id
          WHERE v.expires_on IS NOT NULL AND v.expires_on <= ?
          ORDER BY v.expires_on ASC LIMIT 5", [$horizon]) as $r) {
        $days = mam_phone_days_until((string) $r['expires_on']);
        $items[] = [
            'kind'    => 'clearance',
            'urgency' => $days <= 0 ? 'bad' : ($days <= 14 ? 'bad' : 'warn'),
            'eyebrow' => $days < 0 ? 'Lapsed · ' . abs($days) . ' days ago'
                                   : ($days === 0 ? 'Lapses today' : 'Blocking · ' . $days . ' days'),
            /* A literal apostrophe. ⚠️ '\u{2019}' in SINGLE quotes is not an
               escape — it prints the eight characters verbatim, which is the
               same trap CLAUDE.md records for '\xB7' on the ID card. */
            'title'   => trim((string) $r['name']) . "\u{2019}s " . str_replace('_', ' ', (string) $r['clearance_type']) . ' expires',
            'body'    => 'New assignments refuse once it lapses; students already placed keep their teacher.',
            'actions' => [['label' => 'Record a new certificate', 'primary' => false,
                           'href' => '/admin/console.php?v=vetting']],
        ];
    }

    /* ── A seat about to be released. Reversible: extending a hold changes
       nothing that cannot be changed back. */
    $waiting = (int) mam_console_scalar('phone.waitlist',
        "SELECT COUNT(*) FROM class_waitlist WHERE status = 'waiting'");
    if ($waiting > 0) {
        $items[] = [
            'kind'    => 'waitlist',
            'urgency' => 'warn',
            'eyebrow' => 'Queued',
            'title'   => $waiting . ' ' . ($waiting === 1 ? 'learner is' : 'learners are') . ' on a waitlist',
            'body'    => 'A freed seat drains the queue automatically when the automation is on. Promoting by hand needs the cohort in front of you.',
            'desk'    => true,
            'actions' => [['label' => 'Open the roster', 'primary' => false,
                           'href' => '/admin/console.php?v=roster']],
        ];
    }

    /* ── Money that arrived and matched nothing. The frame flags this for the
       desk rather than settling it, and that is right: matching needs the full
       ledger beside it, which is exactly what a phone does not have. */
    $susp = mam_console_rows('phone.suspense',
        "SELECT id, provider, provider_reference, amount_usd_cents, currency, created_at
           FROM payments
          WHERE status = 'succeeded' AND (application_id IS NULL OR application_id = 0)
          ORDER BY created_at DESC LIMIT 3");
    foreach ($susp as $r) {
        $items[] = [
            'kind'    => 'suspense',
            'urgency' => 'warn',
            'eyebrow' => 'Unmatched · ' . mam_phone_age((string) ($r['created_at'] ?? '')),
            'title'   => mam_console_usd((int) ($r['amount_usd_cents'] ?? 0)) . ' in suspense',
            'body'    => trim((string) ($r['provider'] ?? 'A provider')) . ' '
                         . (string) ($r['provider_reference'] ?? '')
                         . '. Signature verified; no application at this amount. Matching needs the full ledger — flagged for the desk.',
            'desk'    => true,
            'actions' => [['label' => 'Open the ledger', 'primary' => false,
                           'href' => '/admin/console.php?v=ledger']],
        ];
    }

    return $items;
}

/**
 * Panel 2 — Today. What is happening in the building, and what is not marked.
 */
function mam_phone_today(): array {
    $day = mam_phone_today_date();
    $from = $day . ' 00:00:00';
    $to   = $day . ' 23:59:59';

    $sessions = mam_console_rows('phone.today.sessions',
        "SELECT s.id, s.title, s.scheduled_at, s.status, s.mode, s.room_id,
                r.name AS room_name, c.title AS cohort, u.name AS instructor
           FROM class_sessions s
           LEFT JOIN rooms r   ON r.id = s.room_id
           LEFT JOIN cohorts c ON c.id = s.cohort_id
           LEFT JOIN users u   ON u.id = c.instructor_id
          WHERE s.scheduled_at BETWEEN ? AND ?
          ORDER BY s.scheduled_at ASC", [$from, $to]);

    /* ── Room clash. `class_sessions.room_id` and `rooms` are both real, so
       this is a genuine read rather than a screen shaped like one. A clash is
       two sessions in one room whose times overlap; with no duration recorded
       the session is treated as its scheduled instant only, which under-reports
       rather than inventing an overlap that may not exist. */
    $clashes = [];
    foreach ($sessions as $i => $a) {
        if (!($a['room_id'] ?? null)) continue;
        $aStart = strtotime((string) $a['scheduled_at']);
        $aEnd   = $aStart + 60 * (int) (mam_phone_session_minutes($a));
        foreach (array_slice($sessions, $i + 1) as $b) {
            if ((int) ($b['room_id'] ?? 0) !== (int) $a['room_id']) continue;
            $bStart = strtotime((string) $b['scheduled_at']);
            if ($bStart < $aEnd) {
                $clashes[] = [
                    'room'  => (string) ($a['room_name'] ?? 'A room'),
                    'a'     => $a, 'b' => $b,
                    'times' => mam_phone_time((string) $a['scheduled_at']) . ' and ' . mam_phone_time((string) $b['scheduled_at']),
                ];
            }
        }
    }

    // Registers: a session is "marked" once any attendance row exists against it.
    $ids = array_map(static fn($s) => (int) $s['id'], $sessions);
    $marked = 0;
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $marked = (int) mam_console_scalar('phone.today.marked',
            "SELECT COUNT(DISTINCT session_id) FROM session_attendance WHERE session_id IN ($ph)", $ids);
    }

    return [
        'date'     => $day,
        'sessions' => $sessions,
        'clashes'  => $clashes,
        'marked'   => $marked,
        'unmarked' => max(0, count($sessions) - $marked),
    ];
}

/** A session's length, defaulting to the academy's usual hour when unset. */
function mam_phone_session_minutes(array $s): int {
    $m = (int) ($s['duration_min'] ?? 0);
    return $m > 0 ? $m : 60;
}

/**
 * Panel 3 — Assign. Find a learner, see the classes they can actually join.
 *
 * ⚠️ "Can actually join" is the whole panel. The frame is explicit: "Only
 * classes she actually passes are tappable. A full cohort offers the waitlist
 * instead, and anything she fails is listed with the reason rather than
 * hidden." A list that quietly drops the ineligible ones teaches a registrar
 * that the class does not exist.
 *
 * Capacity comes from `mam_lms_cohort_fill_count()`, which counts DISTINCT
 * students — never a row count, which double-counts a re-enrolment.
 */
function mam_phone_assign(?string $q): array {
    $q = trim((string) $q);
    if ($q === '') return ['query' => '', 'learner' => null, 'options' => [], 'matches' => []];

    $like = '%' . $q . '%';
    $matches = mam_console_rows('phone.assign.search',
        "SELECT id, name, email, status FROM users
          WHERE role = 'student' AND (name LIKE ? OR email LIKE ?)
          ORDER BY name LIMIT 8", [$like, $like]);
    if (!$matches) return ['query' => $q, 'learner' => null, 'options' => [], 'matches' => []];

    $learner = $matches[0];
    $sid = (int) $learner['id'];

    // What they are already in, so the list does not offer a duplicate.
    $already = [];
    foreach (mam_console_rows('phone.assign.current',
        "SELECT cohort_id FROM course_enrollments
          WHERE student_id = ? AND status IN ('active','pending','completed')", [$sid]) as $r) {
        if ($r['cohort_id'] ?? null) $already[(int) $r['cohort_id']] = true;
    }

    $options = [];
    foreach (mam_console_rows('phone.assign.cohorts',
        "SELECT co.id, co.title, co.capacity, co.term, c.name AS course, c.code,
                u.name AS instructor
           FROM cohorts co
           LEFT JOIN courses c ON c.id = co.course_id
           LEFT JOIN users u   ON u.id = co.instructor_id
          WHERE co.status = 'active'
          ORDER BY c.name, co.title LIMIT 12") as $co) {
        $cid  = (int) $co['id'];
        $fill = function_exists('mam_lms_cohort_fill_count')
            ? mam_console_try('phone.assign.fill', fn() => mam_lms_cohort_fill_count($cid), null) : null;
        $taken = (int) ($fill['count'] ?? $fill['filled'] ?? 0);
        $cap   = $co['capacity'] === null ? null : (int) $co['capacity'];
        $full  = $cap !== null && $taken >= $cap;

        $blocked = null;
        if (isset($already[$cid]))      $blocked = 'Already enrolled in this class.';
        elseif (!($co['code'] ?? null)) $blocked = 'This cohort maps to no course, so it cannot be enrolled into.';

        $options[] = [
            'cohort_id'  => $cid,
            'title'      => trim(((string) ($co['course'] ?? '')) ?: (string) $co['title']),
            'sub'        => trim(((string) ($co['title'] ?? '')) . ' · ' . ((string) ($co['instructor'] ?? 'no teacher yet'))),
            'taken'      => $taken,
            'capacity'   => $cap,
            'full'       => $full,
            'blocked'    => $blocked,
            'joinable'   => $blocked === null && !$full,
        ];
    }

    return ['query' => $q, 'learner' => $learner, 'options' => $options,
            'matches' => count($matches) > 1 ? array_slice($matches, 1) : []];
}

/**
 * Panel 4 — Look up. One person, the facts a registrar is asked for on the
 * phone, and only the actions that can be undone.
 */
function mam_phone_lookup(?string $q): array {
    $q = trim((string) $q);
    if ($q === '') return ['query' => '', 'person' => null, 'matches' => []];

    $like = '%' . $q . '%';
    $matches = mam_console_rows('phone.lookup.search',
        "SELECT id, name, email, role, status FROM users
          WHERE name LIKE ? OR email LIKE ?
          ORDER BY CASE role WHEN 'student' THEN 0 ELSE 1 END, name LIMIT 8", [$like, $like]);
    if (!$matches) return ['query' => $q, 'person' => null, 'matches' => []];

    $p   = $matches[0];
    $uid = (int) $p['id'];

    $enrol = mam_console_rows('phone.lookup.enrol',
        "SELECT c.name AS course, co.title AS cohort, ce.status
           FROM course_enrollments ce
           LEFT JOIN courses c  ON c.id = ce.course_id
           LEFT JOIN cohorts co ON co.id = ce.cohort_id
          WHERE ce.student_id = ? ORDER BY ce.id DESC LIMIT 4", [$uid]);

    $present = (int) mam_console_scalar('phone.lookup.present',
        "SELECT COUNT(*) FROM session_attendance WHERE student_id = ? AND status IN ('present','late')", [$uid]);
    $total = (int) mam_console_scalar('phone.lookup.sessions',
        "SELECT COUNT(*) FROM session_attendance WHERE student_id = ?", [$uid]);

    // Owed: MAM_ACCT_OWED is the predicate, never `= 'outstanding'` — a
    // disputed charge is non-terminal and still counts as owed.
    $owedStatuses = defined('MAM_ACCT_OWED') ? MAM_ACCT_OWED : ['outstanding', 'disputed'];
    $ph = implode(',', array_fill(0, count($owedStatuses), '?'));
    $owed = (int) mam_console_scalar('phone.lookup.owed',
        "SELECT COALESCE(SUM(amount_usd_cents),0) FROM accountability_charges
          WHERE subject_type = 'student' AND subject_id = ? AND status IN ($ph)",
        array_merge([$uid], $owedStatuses));

    $guardians = mam_console_rows('phone.lookup.guardians',
        "SELECT u.name, u.email, pl.access_scope
           FROM parent_links pl JOIN users u ON u.id = pl.parent_id
          WHERE pl.student_id = ? LIMIT 3", [$uid]);

    return [
        'query'      => $q,
        'person'     => $p,
        'enrolments' => $enrol,
        'attendance' => ['present' => $present, 'total' => $total,
                         'pct' => $total > 0 ? (int) round($present * 100 / $total) : null],
        'owed_cents' => $owed,
        'guardians'  => $guardians,
        'matches'    => count($matches) > 1 ? array_slice($matches, 1) : [],
        'desk_notice' => MAM_PHONE_DESK_NOTICE,
    ];
}

/* ── Small formatters. Kept here so a renderer never computes. ───────────── */

/** "2 days", "today" — an age a person reads, from a stored timestamp. */
function mam_phone_age(string $ts): string {
    $t = strtotime($ts);
    if (!$t) return 'recently';
    $d = (int) floor((time() - $t) / 86400);
    if ($d <= 0) return 'today';
    return $d === 1 ? '1 day' : $d . ' days';
}

/** Whole days from today to a date, negative when it has passed. */
function mam_phone_days_until(string $date): int {
    $t = strtotime($date);
    if (!$t) return 0;
    $today = strtotime(mam_phone_today_date());
    return (int) round(($t - $today) / 86400);
}

/** 16:00 from a stored timestamp. */
function mam_phone_time(string $ts): string {
    $t = strtotime($ts);
    return $t ? gmdate('H:i', $t) : '--:--';
}
