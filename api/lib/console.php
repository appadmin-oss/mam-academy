<?php
/**
 * MAM Academy — admin console read model (api/lib/console.php)
 *
 * One provider per console screen. Each returns a plain array the partial in
 * `partials/admin-console/` renders; nothing here emits markup and nothing here
 * writes. Writes stay where they already are: every action form in the console
 * posts to `admin/index.php`, which owns the capability table, the audit call
 * and the redirect. Duplicating those handlers here is how two admins come to
 * disagree about what "approve" means.
 *
 * ── Why the reads live in a lib rather than in the page ─────────────────────
 * `admin/index.php` learned the hard way (CLAUDE.md gotcha #9) that a PDO
 * exception inside a view is caught, logged, and renders as *nothing* — a bad
 * column silently empties a table and the screen still looks fine. So:
 *
 *   • every read goes through mam_console_rows()/_scalar(), which record the
 *     failure in a per-request register instead of swallowing it, and
 *   • mam_console_failures() lets the page render the design's explicit
 *     "Could not load" state with the request id on it.
 *
 * A screen that cannot read its data says so. That is the whole point.
 *
 * ── Which enrolment model ──────────────────────────────────────────────────
 * Both (gotcha #2). The design is written against the v2 schema, where there is
 * one `enrolments` table; v1 has two and they are not reconciled, so anywhere
 * the design says "one roster" this file dual-reads and says so in the note the
 * screen prints. Nothing here migrates data.
 */
declare(strict_types=1);

/* ─────────────────────────────────────────────────────────────────────────────
   Never-throw reads, with a register
   ───────────────────────────────────────────────────────────────────────── */

/** @var array<int,array{where:string,error:string}> */
$GLOBALS['__mam_console_fail'] = [];

function mam_console_fail(string $where, \Throwable $e): void {
    $GLOBALS['__mam_console_fail'][] = ['where' => $where, 'error' => $e->getMessage()];
    if (function_exists('mam_log')) mam_log('error', 'console_read_failed', ['where' => $where, 'error' => $e->getMessage()]);
}

/** Failures recorded so far this request. The page renders these, visibly. */
function mam_console_failures(): array { return $GLOBALS['__mam_console_fail'] ?? []; }

function mam_console_rows(string $where, string $sql, array $args = []): array {
    try { return mam_db_all($sql, $args); }
    catch (\Throwable $e) { mam_console_fail($where, $e); return []; }
}

function mam_console_row(string $where, string $sql, array $args = []): ?array {
    try { $r = mam_db_row($sql, $args); return $r ?: null; }
    catch (\Throwable $e) { mam_console_fail($where, $e); return null; }
}

/** @return int|float|string|null */
function mam_console_scalar(string $where, string $sql, array $args = [], $fallback = 0) {
    try {
        $st = mam_db()->prepare($sql);
        $st->execute($args);
        $v = $st->fetchColumn();
        return $v === false ? $fallback : $v;
    } catch (\Throwable $e) { mam_console_fail($where, $e); return $fallback; }
}

function mam_console_int(string $where, string $sql, array $args = []): int {
    return (int) mam_console_scalar($where, $sql, $args, 0);
}

/** A never-throw call into another lib. Same contract as the read helpers. */
function mam_console_try(string $where, callable $fn, $fallback = null) {
    try { return $fn(); }
    catch (\Throwable $e) { mam_console_fail($where, $e); return $fallback; }
}

/* ─────────────────────────────────────────────────────────────────────────────
   Formatting. USD is the accounting unit everywhere (see CLAUDE.md "Money");
   naira is a display conversion at the current warmed rate and is labelled as
   an estimate wherever a rate was not frozen on the row itself.
   ───────────────────────────────────────────────────────────────────────── */

function mam_console_usd(int $cents): string {
    return '$' . number_format($cents / 100, 2);
}

/** Compact money for a KPI tile: $14.6k / $1.2m. Never rounds a total to zero. */
function mam_console_usd_short(int $cents): string {
    $usd = $cents / 100;
    if ($usd >= 1000000) return '$' . rtrim(rtrim(number_format($usd / 1000000, 1), '0'), '.') . 'm';
    if ($usd >= 1000)    return '$' . rtrim(rtrim(number_format($usd / 1000, 1), '0'), '.') . 'k';
    // A whole number of dollars prints without cents. Compared loosely on
    // purpose: `$cents / 100` is a FLOAT even when it divides exactly, so a
    // strict comparison against floor() is false for every value and every
    // KPI tile grows two decimal places it does not want.
    return '$' . number_format($usd, $usd == floor($usd) ? 0 : 2);
}

/**
 * The same figure in naira, at a rate.
 *
 * The two arguments mean two different things and must not be conflated:
 *
 *   null  — this figure has no rate of its own (a catalogue price, a total the
 *           academy has not charged yet), so today's warmed rate is used and
 *           the caller labels the result an estimate.
 *   a rate — this ROW's frozen rate. A May payment must still reconcile next
 *           year, which is the reason `payments.fx_rate_usd_ngn` exists.
 *
 * A row whose stored rate is zero or negative is CORRUPT, not rate-less, so it
 * returns a dash rather than quietly substituting today's rate. Substituting
 * would print a plausible naira figure against a payment that was never taken
 * at that rate — the exact reconciliation bug the frozen column prevents.
 */
function mam_console_ngn(int $usdCents, ?float $rate = null): string {
    if ($rate !== null) {
        if ($rate <= 0 || !is_finite($rate)) return '—';
        return '₦' . number_format((int) round($usdCents / 100 * $rate));
    }
    $r = (float) mam_console_try('fx', fn() => mam_usd_to_ngn_rate(false), 0.0);
    if ($r <= 0) return '—';
    return '₦' . number_format((int) round($usdCents / 100 * $r));
}

/** "7d", "3h", "just now" — the age column the design puts on every queue. */
function mam_console_age(?string $ts): string {
    if (!$ts) return '—';
    $t = strtotime($ts);
    if ($t === false) return '—';
    $s = max(0, time() - $t);
    if ($s < 3600)  return max(1, (int) floor($s / 60)) . 'm';
    if ($s < 86400) return (int) floor($s / 3600) . 'h';
    return (int) floor($s / 86400) . 'd';
}

function mam_console_days_since(?string $ts): int {
    if (!$ts) return 0;
    $t = strtotime($ts);
    return $t === false ? 0 : (int) floor(max(0, time() - $t) / 86400);
}

function mam_console_date(?string $ts, string $fmt = 'j M'): string {
    if (!$ts) return '—';
    $t = strtotime($ts);
    return $t === false ? '—' : date($fmt, $t);
}

/** Initials for an avatar. Two letters, never more. */
function mam_console_initials(?string $name): string {
    $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
    $parts = array_values(array_filter($parts, fn($p) => $p !== ''));
    if (!$parts) return '—';
    $a = mb_strtoupper(mb_substr($parts[0], 0, 1));
    $b = count($parts) > 1 ? mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1)) : '';
    return $a . $b;
}

/** Percentage as an integer 0–100, safe against a zero denominator. */
function mam_console_pct(int $part, int $whole): int {
    return $whole > 0 ? (int) round($part * 100 / $whole) : 0;
}

/* ─────────────────────────────────────────────────────────────────────────────
   The rail
   ───────────────────────────────────────────────────────────────────────── */

/**
 * The console's information architecture: NINE areas, each holding its
 * sections. Thirty-one destinations, but never thirty-one on screen at once.
 *
 * ⚠️ THIS REPLACED A FLAT RAIL OF ALL 31, GROUPED FOUR WAYS. That was not a
 * style preference — the design handoff
 * (docs/design/2026-08-portal-redesign/README-admin-console.md §IA) names it
 * as the first redesign pass and says why it was abandoned: "the legacy 40-view
 * flat sidebar was unscannable; the first redesign pass made it 31 items
 * grouped four ways, which was still too long to scan on every navigation."
 * The rail is re-scanned on EVERY navigation, so its length is paid
 * repeatedly. Nine is the stated ceiling.
 *
 * Breadth moved to a section strip under the page title, where you only ever
 * see the siblings of where you already are. ⌘K is what makes demoting 22
 * destinations to a second level safe — layered navigation costs
 * discoverability and the palette is the compensation. It already existed.
 *
 * Four decisions the handoff asks to be preserved, all load-bearing:
 *  1. Reports is its OWN area, not a Governance section. Governance is
 *     quarterly work; Reports is daily, and mixing cadences buries the
 *     frequent thing.
 *  2. No area overview pages. A rail click lands on the area's first section
 *     the caller can actually see — an index page listing the tabs already
 *     visible below it is a click tax.
 *  3. The rail highlight follows the AREA, not the screen (see
 *     mam_console_area_of): opening Attendance keeps Teaching lit. A child
 *     screen must never orphan the rail.
 *  4. Six is the widest strip. Past about eight a strip needs its own
 *     overflow, and at that point the area should split instead.
 *
 * `cap` is the admin capability a section needs; a manager lacking it never
 * sees the entry. The capability table in admin/index.php is still the
 * enforcement — this only stops us advertising a dead end.
 */
function mam_console_nav(): array {
    return [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'fa-gauge', 'sections' => [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'cap' => null],
        ]],
        ['key' => 'admissions', 'label' => 'Admissions', 'icon' => 'fa-file-lines', 'sections' => [
            ['key' => 'admissions', 'label' => 'Applications',         'cap' => 'admissions'],
            ['key' => 'auditions',  'label' => 'Auditions',            'cap' => 'admissions'],
            ['key' => 'funnel',     'label' => 'Intake funnel',        'cap' => null],
            ['key' => 'bursaries',  'label' => 'Bursary applications', 'cap' => null],
        ]],
        ['key' => 'students', 'label' => 'Students', 'icon' => 'fa-user-group', 'sections' => [
            ['key' => 'students',  'label' => 'Students',            'cap' => null],
            ['key' => 'guardians', 'label' => 'Guardians & consent',  'cap' => 'enrollments'],
            ['key' => 'files',     'label' => 'Files & ID cards',     'cap' => 'media'],
        ]],
        ['key' => 'teaching', 'label' => 'Teaching', 'icon' => 'fa-chalkboard-user', 'sections' => [
            ['key' => 'cohorts',    'label' => 'Cohorts',            'cap' => null],
            ['key' => 'roster',     'label' => 'Enrol & staffing',   'cap' => 'enrollments'],
            ['key' => 'timetable',  'label' => 'Timetable & rooms',  'cap' => null],
            ['key' => 'attendance', 'label' => 'Attendance',         'cap' => null],
            ['key' => 'calendar',   'label' => 'Calendar',           'cap' => null],
            ['key' => 'events',     'label' => 'Recitals & events',  'cap' => null],
        ]],
        ['key' => 'curriculum', 'label' => 'Curriculum', 'icon' => 'fa-book-open', 'sections' => [
            ['key' => 'curriculum', 'label' => 'Lessons',             'cap' => 'curriculum'],
            ['key' => 'assessment', 'label' => 'Assessment & boards', 'cap' => null],
        ]],
        ['key' => 'finance', 'label' => 'Finance', 'icon' => 'fa-naira-sign', 'sections' => [
            ['key' => 'invoices',   'label' => 'Invoices & payments',  'cap' => null],
            ['key' => 'ledger',     'label' => 'Ledger & FX',          'cap' => null],
            ['key' => 'pricebooks', 'label' => 'Price books',          'cap' => null],
            ['key' => 'charges',    'label' => 'Charges & exceptions', 'cap' => 'enrollments'],
            ['key' => 'store',      'label' => 'Store & orders',       'cap' => null],
            ['key' => 'deductions', 'label' => 'Instructor deductions','cap' => null],
        ]],
        ['key' => 'people', 'label' => 'People', 'icon' => 'fa-user-gear', 'sections' => [
            ['key' => 'access',  'label' => 'People & access', 'cap' => 'users'],
            ['key' => 'vetting', 'label' => 'Staff & vetting',  'cap' => 'users'],
            /* Everybody's own: name, phone, password, signed-in devices. It
               used to be the legacy dashboard's ?v=account, retired with it —
               and the console's temporary-password gate sends people here, so
               it must exist and must need no capability. */
            ['key' => 'account', 'label' => 'My account',       'cap' => null],
        ]],
        ['key' => 'reports', 'label' => 'Reports', 'icon' => 'fa-chart-line', 'sections' => [
            ['key' => 'reports', 'label' => 'Reports', 'cap' => null],
        ]],
        ['key' => 'governance', 'label' => 'Governance', 'icon' => 'fa-scale-balanced', 'sections' => [
            ['key' => 'governance',     'label' => 'Audit trail',            'cap' => null],
            ['key' => 'dataprotection', 'label' => 'Data protection',        'cap' => 'users'],
            ['key' => 'comms',          'label' => 'Communications',         'cap' => null],
            ['key' => 'support',        'label' => 'Support',                'cap' => 'support'],
            ['key' => 'system',         'label' => 'System health',          'cap' => 'settings'],
            ['key' => 'settings',       'label' => 'Settings & vocabulary',  'cap' => 'settings'],
        ]],
    ];
}

/**
 * The area a screen belongs to — decision 3. Opening Attendance must keep
 * Teaching lit; a child screen that orphans the rail leaves an admin unable to
 * tell where they are.
 */
function mam_console_area_of(string $screen): ?array {
    foreach (mam_console_nav() as $area) {
        foreach ($area['sections'] as $s) if ($s['key'] === $screen) return $area;
    }
    return null;
}

/**
 * Where a rail click lands — decision 2, no area overview pages.
 *
 * The FIRST section this caller may actually see, not simply the first: a
 * manager without the `users` capability clicking People would otherwise land
 * on a screen that refuses them. Returns null when they may see none, and the
 * rail then omits the area entirely rather than offering a dead end.
 */
function mam_console_area_home(array $area, ?callable $canSee = null): ?string {
    foreach ($area['sections'] as $s) {
        if ($canSee === null || $canSee($s['cap'] ?? null)) return $s['key'];
    }
    return null;
}

/**
 * True when an area is a single destination and needs no section strip —
 * Dashboard and Reports. A strip holding one tab that is the page you are on
 * is furniture with no function.
 */
function mam_console_area_is_flat(array $area): bool {
    return count($area['sections']) < 2;
}

function mam_console_screens(): array {
    $out = [];
    foreach (mam_console_nav() as $area) foreach ($area['sections'] as $s) $out[] = $s['key'];
    return $out;
}

/**
 * The rail's badge counts. Each is a count of things a person must decide, not
 * a count of rows — a badge that never reaches zero is a badge people stop
 * seeing. Read in one pass so the rail costs one round of queries, not thirty.
 */
function mam_console_badges(): array {
    $b = [];
    $b['admissions'] = mam_console_int('badge.admissions',
        "SELECT COUNT(*) FROM applications WHERE status IN ('pending','under_review','submitted')");
    $b['attendance'] = mam_console_int('badge.attendance',
        "SELECT COUNT(*) FROM class_sessions cs
          WHERE cs.status = 'scheduled' AND cs.scheduled_at < ?
            AND NOT EXISTS (SELECT 1 FROM session_attendance sa WHERE sa.session_id = cs.id)",
        [gmdate('Y-m-d H:i:s')]);
    $b['charges'] = mam_console_int('badge.charges',
        "SELECT COUNT(*) FROM accountability_charges WHERE status IN ('waiver_requested','disputed')");
    $b['support'] = mam_console_int('badge.support',
        "SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','pending','in_progress')");
    $b['deductions'] = mam_console_int('badge.deductions',
        "SELECT COUNT(*) FROM instructor_fines WHERE status = 'pending'");
    $b['dataprotection'] = mam_console_int('badge.dp',
        "SELECT COUNT(*) FROM dp_requests WHERE closed_at IS NULL");
    $b['store'] = mam_console_int('badge.store',
        "SELECT COUNT(*) FROM store_orders WHERE status = 'pending'");
    $b['events'] = mam_console_int('badge.events',
        "SELECT COUNT(*) FROM recital_items WHERE status = 'submitted'");
    $b['guardians'] = mam_console_int('badge.guardians',
        "SELECT COUNT(*) FROM users u WHERE u.role = 'student' AND u.status = 'active'
           AND NOT EXISTS (SELECT 1 FROM parent_links pl WHERE pl.student_id = u.id)");
    $v = mam_console_try('badge.vetting', fn() => mam_vetting_summary(), []);
    $b['vetting'] = (int) (($v['gaps'] ?? 0) + ($v['expiring'] ?? 0));
    $b['bursaries'] = mam_console_int('badge.bursaries',
        "SELECT COUNT(*) FROM bursary_applications WHERE status IN ('submitted','assessing')");
    return array_filter($b, fn($n) => $n > 0);
}

/* ─────────────────────────────────────────────────────────────────────────────
   Shared context: the term, the money position, the academy's own name for now
   ───────────────────────────────────────────────────────────────────────── */

/**
 * The session line the topbar prints. Comes from the academic calendar, which
 * CLAUDE.md is explicit is the ONE authority on term dates — with its computed
 * fallback, so an install with an empty calendar still reads correctly.
 */
function mam_console_term(): array {
    $t = mam_console_try('term', fn() => mam_calendar_current_term(), []);
    if (!is_array($t)) $t = [];
    $label = trim((string) (($t['label'] ?? '') ?: ($t['code'] ?? '')));
    $year  = (string) ($t['year_label'] ?? '');
    $week  = $t['week'] ?? null;
    $weeks = $t['teaching_weeks'] ?? null;
    $line  = $label !== '' ? trim($label . ' ' . $year) : 'No term set';
    if ($week && $weeks) $line .= ' · week ' . (int) $week . ' of ' . (int) $weeks;
    elseif ($week)       $line .= ' · week ' . (int) $week;
    return [
        'term'   => $t,
        'label'  => $label,
        'year'   => $year,
        'week'   => $week ? (int) $week : null,
        'weeks'  => $weeks ? (int) $weeks : null,
        'line'   => $line,
        'source' => (string) ($t['source'] ?? 'computed'),
        'id'     => isset($t['id']) ? (int) $t['id'] : null,
    ];
}

/** Money owed, in USD cents, from the fee-invoice ledger. */
/**
 * Money owed, in USD cents. There is deliberately no 'overdue' STATUS —
 * overdue is derived from `due_on` on read, the same rule as ID-card and
 * clearance expiry, so an invoice that lapses at midnight reads as overdue on
 * the next page load rather than the next cron run.
 */
function mam_console_outstanding_cents(): int {
    return (int) mam_console_scalar('money.outstanding',
        "SELECT COALESCE(SUM(total_usd_cents), 0) FROM fee_invoices
          WHERE status IN ('issued','part_paid')", [], 0);
}

function mam_console_collected_cents(): int {
    return (int) mam_console_scalar('money.collected',
        "SELECT COALESCE(SUM(amount_usd_cents), 0) FROM payments WHERE " . MAM_CONSOLE_PAID, [], 0);
}

/**
 * A charge the gateway confirmed. `'succeeded'` is the one word
 * mam_payment_settle() writes, and the word the BI screen
 * (mam_bi_revenue_where) and the legacy admin's revenue tiles read. Every money
 * figure on this console used to ask for `'success'`, which nothing writes — so
 * Collected, the ledger's accounts and the currency breakdown all read zero
 * whatever had come in, beside a legacy screen showing the real revenue.
 */
const MAM_CONSOLE_PAID = "status = 'succeeded'";

/**
 * A charge with nothing to apply it to: an APPLICATION charge (tuition or a
 * booking fee) whose application is gone. Store orders and fee invoices carry
 * no application by design, so "application_id IS NULL" alone would put every
 * one of them in suspense the moment the spelling above was corrected.
 */
const MAM_CONSOLE_SUSPENSE = "status = 'succeeded' AND application_id IS NULL AND COALESCE(purpose, 'tuition') NOT IN ('store', 'fee')";

/* ─────────────────────────────────────────────────────────────────────────────
   1. Dashboard
   ───────────────────────────────────────────────────────────────────────── */

/**
 * The decision queue. Deduped to one row per obligation and ordered by what
 * blocks something soonest — the design's first claim about this screen. Each
 * item names the screen that resolves it, because a queue that only reports is
 * a second inbox.
 */
function mam_console_decisions(): array {
    $q = [];

    $stale = mam_console_int('dec.firstlook',
        "SELECT COUNT(*) FROM applications
          WHERE status IN ('pending','submitted') AND created_at < ?",
        [gmdate('Y-m-d H:i:s', time() - 5 * 86400)]);
    if ($stale > 0) $q[] = ['screen' => 'admissions', 'blocking' => true, 'age' => '5d+',
        'title' => $stale . ' application' . ($stale === 1 ? '' : 's') . ' past the five-day promise',
        'why' => 'Waiting for a first look. That is a staffing figure, not an applicant-quality figure.'];

    $suspense = mam_console_int('dec.suspense',
        "SELECT COUNT(*) FROM payments WHERE " . MAM_CONSOLE_SUSPENSE);
    if ($suspense > 0) $q[] = ['screen' => 'invoices', 'blocking' => true, 'age' => 'now',
        'title' => $suspense . ' payment' . ($suspense === 1 ? '' : 's') . ' with no matching application',
        'why' => 'Signature verified, nothing to apply it to. Sitting in suspense, not applied.'];

    $claims = mam_console_int('dec.feeclaims', "SELECT COUNT(*) FROM fee_transfer_claims WHERE status = 'pending'");
    if ($claims > 0) $q[] = ['screen' => 'invoices', 'blocking' => true, 'age' => '24h',
        'title' => $claims . ' bank transfer' . ($claims === 1 ? '' : 's') . ' reported by families, waiting to be checked',
        'why' => 'The bank instructions promise confirmation within 24 hours. Check each against the statement.'];
    $unap = mam_console_int('dec.feeunap', "SELECT COUNT(*) FROM fee_online_unapplied WHERE status = 'open'");
    if ($unap > 0) $q[] = ['screen' => 'invoices', 'blocking' => false, 'age' => 'now',
        'title' => $unap . ' online overpayment' . ($unap === 1 ? '' : 's') . ' to refund or move',
        'why' => 'Money a family paid after the balance was already settled. It is theirs until somebody deals with it.'];

    $vet = mam_console_try('dec.vetting', fn() => mam_vetting_attention(50), []) ?: [];
    foreach (array_slice($vet, 0, 3) as $v) {
        $q[] = ['screen' => 'vetting', 'blocking' => (($v['state'] ?? '') === 'gap'),
            'age' => isset($v['days']) ? (int) $v['days'] . 'd' : '—',
            'title' => trim((string) ($v['name'] ?? 'An instructor')) . ' — ' . (($v['state'] ?? '') === 'gap' ? 'clearance incomplete' : 'clearance lapsing'),
            'why' => (string) ($v['detail'] ?? 'Assignments to this instructor are refused once the gate is enforced.')];
    }

    $waivers = mam_console_int('dec.waivers',
        "SELECT COUNT(*) FROM accountability_charges WHERE status = 'waiver_requested'");
    if ($waivers > 0) $q[] = ['screen' => 'charges', 'blocking' => false, 'age' => '—',
        'title' => $waivers . ' waiver request' . ($waivers === 1 ? '' : 's') . ' awaiting a decision',
        'why' => 'A charge nobody explains is worse than no charge. Both outcomes need a note.'];

    $noGuardian = mam_console_int('dec.guardian',
        "SELECT COUNT(*) FROM users u WHERE u.role = 'student' AND u.status = 'active'
           AND NOT EXISTS (SELECT 1 FROM parent_links pl WHERE pl.student_id = u.id)");
    if ($noGuardian > 0) $q[] = ['screen' => 'guardians', 'blocking' => false, 'age' => '—',
        'title' => $noGuardian . ' learner' . ($noGuardian === 1 ? '' : 's') . ' with no guardian on file',
        'why' => 'They can attend — a data gap must not remove a child from class — but no report can be released.'];

    $unmarked = mam_console_int('dec.registers',
        "SELECT COUNT(*) FROM class_sessions cs
          WHERE cs.status = 'scheduled' AND cs.scheduled_at < ?
            AND NOT EXISTS (SELECT 1 FROM session_attendance sa WHERE sa.session_id = cs.id)",
        [gmdate('Y-m-d H:i:s', time() - 3600)]);
    if ($unmarked > 0) $q[] = ['screen' => 'attendance', 'blocking' => false, 'age' => '—',
        'title' => $unmarked . ' register' . ($unmarked === 1 ? '' : 's') . ' unmarked',
        'why' => 'A session that happened with no register cannot be reconciled against anything.'];

    $dp = mam_console_try('dec.dp', fn() => mam_dp_requests(true, 20), []) ?: [];
    foreach ($dp as $r) {
        $clock = mam_console_try('dec.dpclock', fn() => mam_dp_request_clock($r), []) ?: [];
        if (($clock['overdue'] ?? false) || (int) ($clock['days_left'] ?? 99) <= 7) {
            $q[] = ['screen' => 'dataprotection', 'blocking' => (bool) ($clock['overdue'] ?? false),
                'age' => isset($clock['days_left']) ? (int) $clock['days_left'] . 'd left' : '—',
                'title' => ucfirst((string) ($r['kind'] ?? 'subject')) . ' request · ' . (string) ($r['subject_email'] ?? 'unknown'),
                'why' => 'Thirty days is the academy\'s own commitment, not a statutory deadline.'];
        }
    }

    usort($q, fn($a, $b) => ($b['blocking'] ? 1 : 0) <=> ($a['blocking'] ? 1 : 0));
    return array_slice($q, 0, 8);
}

/** The admissions funnel, by status, as the dashboard and the board both show it. */
function mam_console_funnel_stages(): array {
    $counts = [];
    foreach (mam_console_rows('funnel.stages',
        "SELECT status, COUNT(*) n FROM applications GROUP BY status") as $r) {
        $counts[(string) $r['status']] = (int) $r['n'];
    }
    $stages = [
        ['key' => 'submitted',   'label' => 'Submitted',      'from' => ['pending', 'submitted']],
        ['key' => 'review',      'label' => 'Under review',   'from' => ['under_review', 'reviewing']],
        ['key' => 'interview',   'label' => 'Audition set',   'from' => ['interview_scheduled', 'audition_scheduled']],
        ['key' => 'offered',     'label' => 'Offer made',     'from' => ['offered', 'offer_made']],
        ['key' => 'accepted',    'label' => 'Place confirmed', 'from' => ['accepted', 'enrolled', 'paid']],
        ['key' => 'waitlisted',  'label' => 'Waitlist',       'from' => ['waitlisted']],
    ];
    $out = []; $max = 1;
    foreach ($stages as $s) {
        $n = 0; foreach ($s['from'] as $k) $n += $counts[$k] ?? 0;
        $out[] = ['key' => $s['key'], 'label' => $s['label'], 'n' => $n];
        $max = max($max, $n);
    }
    foreach ($out as &$o) $o['pct'] = mam_console_pct($o['n'], $max);
    return $out;
}

function mam_console_dashboard(): array {
    $term = mam_console_term();
    $termId = $term['id'];

    $census = $termId ? mam_console_try('dash.census', fn() => mam_calendar_census($termId), null) : null;
    $activeNow = mam_console_int('dash.active',
        "SELECT COUNT(DISTINCT student_id) FROM course_enrollments WHERE status = 'active'")
      + mam_console_int('dash.activelegacy',
        "SELECT COUNT(DISTINCT student_id) FROM enrollments e
          WHERE e.status = 'active' AND NOT EXISTS (
            SELECT 1 FROM course_enrollments ce WHERE ce.student_id = e.student_id AND ce.status = 'active')");

    $present = mam_console_int('dash.present',
        "SELECT COUNT(*) FROM session_attendance WHERE status IN ('present','late')");
    $marked = mam_console_int('dash.marked', "SELECT COUNT(*) FROM session_attendance");
    $unmarked = mam_console_int('dash.unmarked',
        "SELECT COUNT(*) FROM class_sessions cs
          WHERE cs.status = 'scheduled' AND cs.scheduled_at < ?
            AND NOT EXISTS (SELECT 1 FROM session_attendance sa WHERE sa.session_id = cs.id)",
        [gmdate('Y-m-d H:i:s')]);

    $overdue = mam_console_int('dash.overdue',
        "SELECT COUNT(*) FROM fee_invoices WHERE status IN ('issued','part_paid') AND due_on < ?",
        [date('Y-m-d')]);
    $openInv = mam_console_int('dash.openinv',
        "SELECT COUNT(*) FROM fee_invoices WHERE status IN ('issued','part_paid')");

    $today = date('Y-m-d');
    $todaySessions = mam_console_rows('dash.today',
        "SELECT cs.id, cs.title, cs.scheduled_at, cs.mode, cs.room, cs.status, c.title cohort
           FROM class_sessions cs LEFT JOIN cohorts c ON c.id = cs.cohort_id
          WHERE cs.scheduled_at >= ? AND cs.scheduled_at < ?
          ORDER BY cs.scheduled_at", [$today . ' 00:00:00', $today . ' 23:59:59']);

    $full = mam_console_rows('dash.full',
        "SELECT c.id, c.title, c.capacity,
                (SELECT COUNT(DISTINCT ce.student_id) FROM course_enrollments ce
                  WHERE ce.cohort_id = c.id AND ce.status IN ('active','pending')) fill,
                (SELECT COUNT(*) FROM class_waitlist w WHERE w.cohort_id = c.id AND w.status = 'waiting') waiting
           FROM cohorts c WHERE c.capacity IS NOT NULL AND c.status = 'active'");
    $atCapacity = array_values(array_filter($full, fn($r) => (int) $r['fill'] >= (int) $r['capacity']));

    return [
        'term' => $term,
        'kpis' => [
            ['label' => 'Enrolled', 'value' => (string) $activeNow, 'note' => $census
                ? 'Census locked ' . mam_console_date((string) ($census['taken_at'] ?? null)) . ' · ' . (int) ($census['headcount'] ?? 0) . ' at census'
                : 'No census taken for this term yet', 'screen' => 'students'],
            ['label' => 'Outstanding', 'value' => mam_console_usd_short(mam_console_outstanding_cents()),
                'tone' => $overdue > 0 ? 'bad' : '',
                'note' => $openInv . ' open invoice' . ($openInv === 1 ? '' : 's') . ' · ' . $overdue . ' overdue', 'screen' => 'invoices'],
            ['label' => 'Collected', 'value' => mam_console_usd_short(mam_console_collected_cents()),
                'note' => 'At the rate frozen on each payment', 'screen' => 'ledger'],
            ['label' => 'Attendance', 'value' => $marked > 0 ? mam_console_pct($present, $marked) . '%' : '—',
                'tone' => $unmarked > 0 ? 'warn' : '',
                'note' => $marked > 0 ? $unmarked . ' register' . ($unmarked === 1 ? '' : 's') . ' unmarked' : 'Nothing marked yet', 'screen' => 'attendance'],
        ],
        'decisions' => mam_console_decisions(),
        'funnel'    => mam_console_funnel_stages(),
        'today'     => $todaySessions,
        'at_capacity' => $atCapacity,
        'makeups'   => mam_console_int('dash.makeups',
            "SELECT COUNT(*) FROM makeup_credits WHERE status = 'spent' AND spent_at >= ?",
            [gmdate('Y-m-d H:i:s', time() - 7 * 86400)]),
        'health'    => mam_console_health(),
    ];
}

/** The health strip, shared by the dashboard and System health. */
function mam_console_health(): array {
    $stuck = mam_console_int('health.stuck', "SELECT COUNT(*) FROM mail_log WHERE status = 'failed'");
    $sent  = mam_console_int('health.sent',  "SELECT COUNT(*) FROM mail_log WHERE status = 'sent' AND created_at >= ?",
        [gmdate('Y-m-d H:i:s', time() - 86400)]);
    $badSig = mam_console_int('health.sig', "SELECT COUNT(*) FROM webhook_events WHERE signature_valid = 0");
    $rate  = mam_console_try('health.fx', fn() => mam_usd_to_ngn_rate(false), 0.0);
    $auditHead = mam_console_int('health.audit', "SELECT COALESCE(MAX(id), 0) FROM audit_log");
    /* ⚠️ The signing key's STABILITY, not its length. When APP_KEY is unset
       and storage/.app_key cannot be written, every process signs with a
       different key: every form on the site fails with csrf_invalid and every
       attendance QR and invitation link is rejected. It is silent and total,
       and it presents as "the forms are broken" — so it belongs on the health
       card, above the mail queue, rather than in a log nobody reads. */
    $keyOk = !function_exists('mam_app_key_persisted') || mam_app_key_persisted();

    return [
        'outbox'   => ['stuck' => $stuck, 'sent_24h' => $sent],
        'webhooks' => ['unverified' => $badSig],
        'fx'       => ['rate' => $rate ? (float) $rate : null],
        'audit'    => ['head' => $auditHead],
        'signing'  => ['stable' => $keyOk,
                       'detail' => $keyOk ? null
                           : 'APP_KEY is unset and api/storage/.app_key could not be written, so each request signs with a different key. Every form will fail and every signed link will be rejected. Set APP_KEY (32+ characters) in api/.env, or make api/storage writable.'],
        'nominal'  => ($stuck === 0 && $badSig === 0 && $keyOk),
    ];
}

/* ─────────────────────────────────────────────────────────────────────────────
   2. Admissions
   ───────────────────────────────────────────────────────────────────────── */

/**
 * The intake board. One table across every intake type, which is what the
 * design's "one table, four intake types" means — v1 keeps summer in its own
 * table, so this UNIONs it in rather than pretending the divergence isn't there.
 */
function mam_console_admissions(array $q = []): array {
    $stage = (string) ($q['stage'] ?? '');
    $type  = (string) ($q['type'] ?? '');
    $search = trim((string) ($q['q'] ?? ''));

    $where = []; $args = [];
    $stageMap = [
        'submitted'  => ['pending', 'submitted'],
        'review'     => ['under_review', 'reviewing'],
        'interview'  => ['interview_scheduled', 'audition_scheduled'],
        'offered'    => ['offered', 'offer_made'],
        'accepted'   => ['accepted', 'enrolled', 'paid'],
        'waitlisted' => ['waitlisted'],
    ];
    if (isset($stageMap[$stage])) {
        $in = implode(',', array_fill(0, count($stageMap[$stage]), '?'));
        $where[] = "a.status IN ($in)";
        foreach ($stageMap[$stage] as $s) $args[] = $s;
    }
    if ($type === 'scholarship') $where[] = "a.pathway = 'scholarship'";
    elseif ($type === 'audition') $where[] = "a.auditer_optin = 1";
    elseif ($type === 'programme') $where[] = "(a.pathway IS NULL OR a.pathway <> 'scholarship')";
    if ($search !== '') {
        $where[] = "(a.reference LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ?)";
        $like = '%' . $search . '%';
        array_push($args, $like, $like, $like, $like);
    }
    $sql = "SELECT a.id, a.reference, a.status, a.pathway, a.program, a.level, a.discipline,
                   a.created_at, a.total_usd, a.readiness_signal, a.assignee_id, a.session,
                   c.first_name, c.last_name, c.email, c.phone,
                   u.name AS assignee
              FROM applications a
              LEFT JOIN customers c ON c.id = a.customer_id
              LEFT JOIN users u ON u.id = a.assignee_id"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . " ORDER BY a.created_at ASC LIMIT 200";

    $rows = mam_console_rows('adm.rows', $sql, $args);

    $types = [
        'all'         => mam_console_int('adm.t.all', "SELECT COUNT(*) FROM applications"),
        'programme'   => mam_console_int('adm.t.prog', "SELECT COUNT(*) FROM applications WHERE pathway IS NULL OR pathway <> 'scholarship'"),
        'summer'      => mam_console_int('adm.t.sum', "SELECT COUNT(*) FROM summer_applications"),
        'scholarship' => mam_console_int('adm.t.sch', "SELECT COUNT(*) FROM applications WHERE pathway = 'scholarship'"),
        'audition'    => mam_console_int('adm.t.aud', "SELECT COUNT(*) FROM applications WHERE auditer_optin = 1"),
    ];

    $detail = null;
    $appId = (int) ($q['app'] ?? 0);
    if ($appId <= 0 && $rows) $appId = (int) $rows[0]['id'];
    if ($appId > 0) $detail = mam_console_application($appId);

    return [
        'rows'   => $rows,
        'stages' => mam_console_funnel_stages(),
        'types'  => $types,
        'stage'  => $stage,
        'type'   => $type,
        'q'      => $search,
        'stale'  => mam_console_int('adm.stale',
            "SELECT COUNT(*) FROM applications WHERE status IN ('pending','submitted') AND created_at < ?",
            [gmdate('Y-m-d H:i:s', time() - 5 * 86400)]),
        'detail' => $detail,
    ];
}

/** The applicant card: everything a first look needs, plus its own history. */
function mam_console_application(int $id): ?array {
    $a = mam_console_row('adm.detail',
        "SELECT a.*, c.first_name, c.last_name, c.email, c.phone, c.country, c.dob,
                u.name AS assignee
           FROM applications a
           LEFT JOIN customers c ON c.id = a.customer_id
           LEFT JOIN users u ON u.id = a.assignee_id
          WHERE a.id = ?", [$id]);
    if (!$a) return null;

    $a['events'] = mam_console_rows('adm.events',
        "SELECT e.from_status, e.to_status, e.reason, e.created_at, u.name actor
           FROM application_events e LEFT JOIN users u ON u.id = e.actor_id
          WHERE e.application_id = ? ORDER BY e.created_at DESC, e.id DESC LIMIT 20", [$id]);
    $a['notes'] = mam_console_rows('adm.notes',
        "SELECT n.*, u.name author FROM application_notes n
           LEFT JOIN users u ON u.id = n.author_id
          WHERE n.application_id = ? ORDER BY n.created_at DESC LIMIT 10", [$id]);
    $a['payments'] = mam_console_rows('adm.pay',
        "SELECT reference, provider, status, amount, currency, amount_usd_cents, created_at
           FROM payments WHERE application_id = ? ORDER BY created_at DESC", [$id]);
    $a['readiness'] = mam_console_try('adm.readiness',
        fn() => mam_application_readiness($a), null);
    return $a;
}

/* ─────────────────────────────────────────────────────────────────────────────
   3–4. Money: invoices, ledger
   ───────────────────────────────────────────────────────────────────────── */

function mam_console_invoices(array $q = []): array {
    $aging = ['current' => 0, 'd30' => 0, 'd60' => 0, 'd60p' => 0];
    foreach (mam_console_rows('inv.aging',
        "SELECT due_on, total_usd_cents, paid_minor, total_minor, status FROM fee_invoices
          WHERE status IN ('issued','part_paid')") as $r) {
        $cents = (int) $r['total_usd_cents'];
        $days = $r['due_on'] ? (int) floor((time() - (int) strtotime((string) $r['due_on'])) / 86400) : 0;
        if ($days <= 0)      $aging['current'] += $cents;
        elseif ($days <= 30) $aging['d30'] += $cents;
        elseif ($days <= 60) $aging['d60'] += $cents;
        else                 $aging['d60p'] += $cents;
    }

    $rows = mam_console_rows('inv.rows',
        "SELECT i.id, i.public_ref, i.status, i.currency, i.due_on, i.issued_on,
                i.total_minor, i.paid_minor, i.total_usd_cents, i.fx_rate_usd_ngn,
                i.aid_minor, i.waiver_minor, i.sibling_minor, i.dunning_stage,
                s.name student, g.name guardian
           FROM fee_invoices i
           LEFT JOIN users s ON s.id = i.student_id
           LEFT JOIN users g ON g.id = i.guardian_id
          ORDER BY (i.status IN ('issued','part_paid')) DESC, i.due_on ASC LIMIT 120");

    return [
        'aging' => $aging,
        'rows'  => $rows,
        'suspense' => mam_console_rows('inv.suspense',
            "SELECT reference, provider, provider_reference, amount, currency, amount_usd_cents, created_at
               FROM payments WHERE " . MAM_CONSOLE_SUSPENSE . "
              ORDER BY created_at DESC LIMIT 10"),
        'outstanding' => mam_console_outstanding_cents(),
        'collected'   => mam_console_collected_cents(),
        'grace_days'  => (int) mam_console_try('inv.grace', fn() => (int) mam_setting_num('fee_grace_days', 14), 14),
        // The office's side (HANDOFF §4t): who can be invoiced, the plans a
        // guardian asked for that nobody has agreed, and the invoice that is open.
        'students' => mam_console_rows('inv.students',
            "SELECT DISTINCT u.id, u.name, u.email FROM users u
               LEFT JOIN user_roles r ON r.user_id = u.id
              WHERE (u.role = 'student' OR r.role = 'student') AND COALESCE(u.status, 'active') <> 'deleted'
              ORDER BY u.name LIMIT 3000"),
        'plans_waiting' => mam_console_rows('inv.plans',
            "SELECT p.id, p.invoice_id, p.instalments, p.created_at, i.public_ref, s.name student
               FROM fee_payment_plans p JOIN fee_invoices i ON i.id = p.invoice_id
               LEFT JOIN users s ON s.id = i.student_id
              WHERE p.status = 'active' AND p.approved_by IS NULL ORDER BY p.created_at LIMIT 50"),
        // Transfers families reported, oldest first: the bank instructions promise
        // confirmation within 24 hours, so the one waiting longest is the one owed.
        'claims_waiting' => mam_console_rows('inv.claims',
            "SELECT c.id, c.invoice_id, c.amount_minor, c.currency, c.paid_on, c.created_at, i.public_ref, s.name student
               FROM fee_transfer_claims c JOIN fee_invoices i ON i.id = c.invoice_id
               LEFT JOIN users s ON s.id = i.student_id
              WHERE c.status = 'pending' ORDER BY c.created_at LIMIT 50"),
        'unapplied_open' => mam_console_rows('inv.unapplied',
            "SELECT u.id, u.invoice_id, u.over_minor, u.currency, u.reference, u.created_at, i.public_ref, s.name student
               FROM fee_online_unapplied u LEFT JOIN fee_invoices i ON i.id = u.invoice_id
               LEFT JOIN users s ON s.id = i.student_id
              WHERE u.status = 'open' ORDER BY u.created_at LIMIT 50"),
        'detail' => isset($q['id']) && (int) $q['id'] > 0 ? mam_console_try('inv.detail', fn() => mam_console_invoice_detail((int) $q['id']), null) : null,
        'methods' => MAM_FEE_PAY_METHODS,
    ];
}

/** One invoice, for the console: the family's own view plus what only the office sees. */
function mam_console_invoice_detail(int $id): ?array {
    $inv = mam_fee_invoice_get($id);
    if (!$inv) return null;
    $row = mam_db_row('SELECT student_id, guardian_id, status, total_minor, paid_minor FROM fee_invoices WHERE id = ?', [$id]);
    $inv['payments'] = mam_fee_payments_for($id);
    $inv['awards'] = mam_db_all("SELECT * FROM fee_aid_awards WHERE student_id = ? ORDER BY status = 'active' DESC, id DESC", [(int) $row['student_id']]);
    $inv['plan_row'] = mam_db_row("SELECT * FROM fee_payment_plans WHERE invoice_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1", [$id]);
    $inv['editable'] = mam_fee_editable(['status' => $row['status']]);
    $inv['has_guardian'] = $row['guardian_id'] !== null;
    /* Online charges against this invoice that have CLEARED, beside what each
     * one was credited. A charge can clear after the office has recorded the
     * same money by hand, or after a void; the family has paid either way, so
     * the difference is a refund the office owes and must be able to see here,
     * not only in the audit log. */
    $inv['online'] = [];
    foreach (mam_console_rows('inv.online',
        "SELECT p.reference, p.provider, p.fee_minor, p.amount_minor, p.verified_at,
                COALESCE((SELECT SUM(fp.amount_minor) FROM fee_payments fp
                           WHERE fp.invoice_id = p.fee_invoice_id AND fp.reference = p.reference
                             AND fp.status = 'recorded'), 0) AS credited
           FROM payments p
          WHERE p.fee_invoice_id = ? AND p.purpose = 'fee' AND " . MAM_CONSOLE_PAID . "
          ORDER BY p.id", [$id]) as $o) {
        $o['refund_minor'] = max(0, (int) $o['fee_minor'] - (int) $o['credited']);
        $o['unapplied'] = mam_console_try('inv.unap1', fn() => mam_db_row('SELECT * FROM fee_online_unapplied WHERE reference = ?', [$o['reference']]), null);
        $inv['online'][] = $o;
    }
    // Where an overpayment can be moved: the same child's other open invoices, same currency.
    $inv['open_siblings'] = mam_console_rows('inv.siblings',
        "SELECT id, public_ref, term_label, currency, total_minor - paid_minor AS due_minor FROM fee_invoices
          WHERE student_id = ? AND id <> ? AND status IN ('issued','part_paid') ORDER BY id", [(int) $row['student_id'], $id]);
    return $inv;
}

function mam_console_ledger(): array {
    $rows = mam_console_rows('led.rows',
        "SELECT p.id, p.reference, p.provider, p.provider_reference, p.status, p.purpose,
                p.amount, p.currency, p.amount_usd_cents, p.fx_rate_usd_ngn, p.created_at,
                p.verified_at, a.reference app_ref, c.first_name, c.last_name
           FROM payments p
           LEFT JOIN applications a ON a.id = p.application_id
           LEFT JOIN customers c ON c.id = a.customer_id
          ORDER BY p.created_at DESC LIMIT 60");

    $byCur = mam_console_rows('led.bycur',
        "SELECT currency, COUNT(*) n, COALESCE(SUM(amount_usd_cents),0) usd
           FROM payments WHERE " . MAM_CONSOLE_PAID . " GROUP BY currency ORDER BY usd DESC");

    $unpriced = mam_console_int('led.unpriced',
        "SELECT COUNT(*) FROM payments WHERE " . MAM_CONSOLE_PAID . " AND amount_usd_cents IS NULL");

    return [
        'rows' => $rows,
        'accounts' => [
            ['label' => 'Tuition & fees received', 'cents' => mam_console_int('led.tuition',
                "SELECT COALESCE(SUM(amount_usd_cents),0) FROM payments WHERE " . MAM_CONSOLE_PAID . " AND (purpose IS NULL OR purpose <> 'store')")],
            ['label' => 'Store & materials',  'cents' => mam_console_int('led.store',
                "SELECT COALESCE(SUM(amount_usd_cents),0) FROM payments WHERE " . MAM_CONSOLE_PAID . " AND purpose = 'store'")],
            ['label' => 'Student receivable', 'cents' => mam_console_outstanding_cents()],
            ['label' => 'Suspense · unmatched', 'cents' => mam_console_int('led.susp',
                "SELECT COALESCE(SUM(amount_usd_cents),0) FROM payments WHERE " . MAM_CONSOLE_SUSPENSE)],
            ['label' => 'Gift aid credited',  'cents' => -mam_console_int('led.aid',
                "SELECT COALESCE(SUM(amount_minor),0) FROM fee_invoice_credits")],
        ],
        'by_currency' => $byCur,
        'unpriced'    => $unpriced,
        'rate'        => mam_console_try('led.rate', fn() => mam_usd_to_ngn_rate(false), null),
        'terms'       => mam_console_try('led.terms', fn() => mam_calendar_terms(6), []) ?: [],
    ];
}

/* ─────────────────────────────────────────────────────────────────────────────
   5. Students
   ───────────────────────────────────────────────────────────────────────── */

/**
 * The members roster (HANDOFF §4w — NGG's list rules, ported).
 *
 * Every filter and the sort are ALLOW-LISTED by name; the caller never
 * contributes SQL text, and an unknown value falls back to the default rather
 * than erroring, so a stale bookmark still shows the roster. The total is the
 * true total for the filter, counted before paging, and "select all N
 * matching" asks mam_console_students_ids() for the same WHERE — a filter is
 * never applied to the drawn page.
 *
 * The band is shown and is NOT a sort key: sorting children by a composite
 * makes the table a ranking whatever the heading says (CLAUDE.md #12/#13).
 */
const MAM_ROSTER_PAGE = 50;
const MAM_ROSTER_SORTS = [
    'name'   => 'LOWER(u.name) ASC, u.id ASC',
    'newest' => 'u.created_at DESC, u.id DESC',
    'number' => "CASE WHEN COALESCE(u.enrollment_ref,'') = '' THEN 1 ELSE 0 END, u.enrollment_ref ASC, u.id ASC",
    'login'  => 'CASE WHEN u.last_login IS NULL THEN 1 ELSE 0 END, u.last_login DESC, u.id ASC',
];

function mam_console_students_where(array $q): array {
    $where = ["u.role = 'student'"]; $args = [];
    $stage = (string) ($q['stage'] ?? '');
    if (in_array($stage, ['active', 'roster', 'suspended'], true)) { $where[] = 'u.status = ?'; $args[] = $stage; }
    $type = (string) ($q['type'] ?? '');
    if ($type === 'scholarship') $where[] = "u.student_type = 'scholarship'";
    elseif ($type === 'paying') $where[] = "COALESCE(u.student_type, '') <> 'scholarship'";
    switch ((string) ($q['missing'] ?? '')) {
        case 'number':   $where[] = "COALESCE(u.enrollment_ref, '') = ''"; break;
        case 'photo':    $where[] = "COALESCE(u.avatar_url, '') = ''"; break;
        case 'guardian': $where[] = 'NOT EXISTS (SELECT 1 FROM parent_links pl WHERE pl.student_id = u.id)'; break;
        case 'learning': $where[] = "NOT EXISTS (SELECT 1 FROM course_enrollments ce WHERE ce.student_id = u.id AND ce.status = 'active')
                                    AND NOT EXISTS (SELECT 1 FROM enrollments e WHERE e.student_id = u.id AND e.status = 'active')"; break;
        case 'login':    $where[] = 'u.last_login IS NULL'; break;
    }
    if (($s = trim((string) ($q['q'] ?? ''))) !== '') {
        $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.enrollment_ref LIKE ? OR u.phone LIKE ?)';
        $like = '%' . $s . '%'; array_push($args, $like, $like, $like, $like);
    }
    return [implode(' AND ', $where), $args];
}

/** Every id matching the filter, up to the bulk cap — "select all N matching". */
function mam_console_students_ids(array $q, int $cap = 500): array {
    [$w, $args] = mam_console_students_where($q);
    $sort = MAM_ROSTER_SORTS[(string) ($q['sort'] ?? '')] ?? MAM_ROSTER_SORTS['name'];
    return array_map('intval', array_column(mam_console_rows('stu.ids', "SELECT u.id FROM users u WHERE $w ORDER BY $sort LIMIT " . (int) $cap, $args), 'id'));
}

function mam_console_students(array $q = []): array {
    [$w, $args] = mam_console_students_where($q);
    $sortKey = isset(MAM_ROSTER_SORTS[(string) ($q['sort'] ?? '')]) ? (string) $q['sort'] : 'name';
    $total = mam_console_int('stu.total', "SELECT COUNT(*) FROM users u WHERE $w", $args);
    $pages = max(1, (int) ceil($total / MAM_ROSTER_PAGE));
    $page  = min($pages, max(1, (int) ($q['page'] ?? 1)));

    $rows = mam_console_rows('stu.rows',
        "SELECT u.id, u.name, u.email, u.status, u.enrollment_ref, u.student_type, u.avatar_url, u.last_login, u.created_at,
                (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.student_id = u.id AND ce.status = 'active') courses,
                (SELECT COUNT(*) FROM enrollments e WHERE e.student_id = u.id AND e.status = 'active') classes,
                (SELECT COUNT(*) FROM parent_links pl WHERE pl.student_id = u.id) guardians,
                (SELECT COALESCE(SUM(i.total_usd_cents - CAST(i.paid_minor AS INTEGER)), 0) FROM fee_invoices i
                   WHERE i.student_id = u.id AND i.status IN ('issued','part_paid')) owed
           FROM users u WHERE $w
          ORDER BY " . MAM_ROSTER_SORTS[$sortKey] . ' LIMIT ' . MAM_ROSTER_PAGE . ' OFFSET ' . (($page - 1) * MAM_ROSTER_PAGE), $args);

    // Attendance for the whole page in ONE grouped query — it used to be two
    // queries per student, up to 400 for one screen.
    $ids = array_map('intval', array_column($rows, 'id'));
    $att = [];
    if ($ids) {
        $in = implode(',', $ids);   // integers, cast above
        foreach (mam_console_rows('stu.att', "SELECT student_id, COUNT(*) tot,
                SUM(CASE WHEN status IN ('present','late') THEN 1 ELSE 0 END) pres
                FROM session_attendance WHERE student_id IN ($in) GROUP BY student_id") as $a) {
            $att[(int) $a['student_id']] = $a;
        }
    }
    $onb = function_exists('mam_onboarding_batch') ? mam_console_try('stu.onb', fn() => mam_onboarding_batch($ids), []) : [];
    foreach ($rows as &$r) {
        $sid = (int) $r['id'];
        $r['onboarding'] = $onb[$sid] ?? null;
        $r['attendance'] = isset($att[$sid]) && (int) $att[$sid]['tot'] > 0 ? mam_console_pct((int) $att[$sid]['pres'], (int) $att[$sid]['tot']) : null;
        $m = mam_console_try('stu.mastery', fn() => mam_mastery_publishable(mam_mastery_score_cached($sid)), null);
        $r['band'] = ($m && ($m['band'] ?? null)) ? $m['band'] : null;
        $r['band_withheld'] = $m ? (bool) ($m['withheld'] ?? false) : true;
    }
    unset($r);

    $keep = array_intersect_key($q, array_flip(['stage', 'type', 'missing', 'q', 'sort']));
    return [
        'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'page_size' => MAM_ROSTER_PAGE,
        'filters' => $keep, 'sort' => $sortKey,
        'stage' => (string) ($q['stage'] ?? ''), 'q' => (string) ($q['q'] ?? ''),
        'stages' => [
            'active'    => mam_console_int('stu.s.a', "SELECT COUNT(*) FROM users WHERE role='student' AND status='active'"),
            'roster'    => mam_console_int('stu.s.r', "SELECT COUNT(*) FROM users WHERE role='student' AND status='roster'"),
            'suspended' => mam_console_int('stu.s.s', "SELECT COUNT(*) FROM users WHERE role='student' AND status='suspended'"),
        ],
        'bulk_actions' => function_exists('mam_member_bulk_actions') ? mam_member_bulk_actions() : [],
        'bulk_max' => defined('MAM_MEMBERS_BULK_MAX') ? MAM_MEMBERS_BULK_MAX : 500,
        'run' => isset($q['run']) ? mam_console_try('stu.run', fn() => mam_member_bulk_run_get((int) $q['run']), null) : null,
        'member' => isset($q['id']) && (int) $q['id'] > 0 ? mam_console_try('stu.member', fn() => mam_console_member((int) $q['id']), null) : null,
        'duplicates' => !empty($q['dupes']) ? mam_console_try('stu.dupes', fn() => mam_member_duplicates(), []) : null,
        'onboarding' => !empty($q['onboarding']) ? mam_console_try('stu.onbl', fn() => mam_onboarding_incomplete(100), []) : null,
    ];
}

/** One member's record panel: who, status and its history, notes, guardians, sessions. */
function mam_console_member(int $id): ?array {
    $u = mam_db_row('SELECT * FROM users WHERE id = ?', [$id]);
    if (!$u) return null;
    unset($u['password_hash']);
    return [
        'user' => $u,
        'history' => mam_member_status_history($id),
        'notes' => mam_member_notes($id),
        'sessions' => mam_console_int('mem.sess', 'SELECT COUNT(*) FROM sessions WHERE user_id = ?', [$id]),
        'guardians' => mam_console_rows('mem.guard', 'SELECT g.id, g.name, g.email, pl.access_scope FROM parent_links pl
            JOIN users g ON g.id = pl.parent_id WHERE pl.student_id = ? ORDER BY g.name', [$id]),
        'is_staff' => mam_member_is_staff($u),
        'onboarding' => function_exists('mam_onboarding_checklist') ? mam_console_try('mem.onb', fn() => mam_onboarding_checklist($id), null) : null,
    ];
}

/* ─────────────────────────────────────────────────────────────────────────────
   6. Cohorts & enrolments
   ───────────────────────────────────────────────────────────────────────── */

/**
 * Seats count DISTINCT students, never rows — the invariant the enrolment audit
 * pinned (CLAUDE.md #3). Capacity null means uncapped; zero would mean
 * permanently full, and the schema keeps that distinction deliberately.
 */
function mam_console_cohorts(): array {
    $rows = mam_console_rows('coh.rows',
        "SELECT c.id, c.title, c.term, c.status, c.capacity, c.mode, c.schedule_days, c.schedule_time,
                c.legacy_class_id, co.code course_code, co.name course_name, l.code level_code,
                p.code program_code, u.name instructor, c.instructor_id,
                (SELECT COUNT(DISTINCT ce.student_id) FROM course_enrollments ce
                  WHERE ce.cohort_id = c.id AND ce.status IN ('active','pending')) fill,
                (SELECT COUNT(*) FROM class_waitlist w WHERE w.cohort_id = c.id AND w.status = 'waiting') waiting,
                (SELECT COUNT(DISTINCT e.student_id) FROM enrollments e
                  WHERE e.class_id = c.legacy_class_id AND e.status = 'active') legacy_count
           FROM cohorts c
           LEFT JOIN courses co ON co.id = c.course_id
           LEFT JOIN levels l ON l.id = c.level_id
           LEFT JOIN programs p ON p.id = c.program_id
           LEFT JOIN users u ON u.id = c.instructor_id
          ORDER BY (c.status = 'active') DESC, c.title LIMIT 120");
    foreach ($rows as &$r) {
        $cap = $r['capacity'] === null ? null : (int) $r['capacity'];
        $r['uncapped'] = $cap === null;
        $r['pct'] = $cap ? min(100, mam_console_pct((int) $r['fill'], $cap)) : 0;
        $r['is_full'] = $cap !== null && (int) $r['fill'] >= $cap;
        $r['no_instructor'] = ((int) $r['instructor_id']) <= 0;
    }
    unset($r);
    return [
        'rows' => $rows,
        'legacy_only' => mam_console_int('coh.legacy',
            "SELECT COUNT(*) FROM classes cl WHERE NOT EXISTS (SELECT 1 FROM cohorts c WHERE c.legacy_class_id = cl.id)"),
    ];
}

/* ─────────────────────────────────────────────────────────────────────────────
   7. Enrol & staffing
   ───────────────────────────────────────────────────────────────────────── */

function mam_console_roster(array $q = []): array {
    $data = mam_console_cohorts();
    $sel = (int) ($q['cohort'] ?? 0);
    if ($sel <= 0 && $data['rows']) $sel = (int) $data['rows'][0]['id'];

    $cohort = null; $roster = []; $staff = []; $sessions = [];
    if ($sel > 0) {
        foreach ($data['rows'] as $r) if ((int) $r['id'] === $sel) { $cohort = $r; break; }
        $roster = mam_console_rows('ros.roster',
            "SELECT ce.id, ce.status, u.id student_id, u.name, u.email, u.avatar_url
               FROM course_enrollments ce JOIN users u ON u.id = ce.student_id
              WHERE ce.cohort_id = ? ORDER BY u.name", [$sel]);
        if ($cohort && (int) ($cohort['legacy_class_id'] ?? 0) > 0) {
            // Dual-read: a backfilled cohort's legacy roster is real students who
            // are not (yet) in course_enrollments. Showing 0 there would be a lie.
            $roster = array_merge($roster, mam_console_rows('ros.legacy',
                "SELECT e.id, e.status, u.id student_id, u.name, u.email, u.avatar_url
                   FROM enrollments e JOIN users u ON u.id = e.student_id
                  WHERE e.class_id = ? AND e.status = 'active'
                    AND NOT EXISTS (SELECT 1 FROM course_enrollments ce
                                     WHERE ce.cohort_id = ? AND ce.student_id = e.student_id)
                  ORDER BY u.name", [(int) $cohort['legacy_class_id'], $sel]));
        }
        $staff = mam_console_rows('ros.staff',
            "SELECT DISTINCT u.id, u.name, u.email FROM users u
              WHERE u.id = (SELECT instructor_id FROM cohorts WHERE id = ?)
                 OR u.id IN (SELECT instructor_id FROM course_enrollments WHERE cohort_id = ? AND instructor_id IS NOT NULL)",
            [$sel, $sel]);
        foreach ($staff as &$s) {
            $s['gate'] = mam_console_try('ros.gate', fn() => mam_vetting_may_teach_minors((int) $s['id']), null);
        }
        unset($s);
        $sessions = mam_console_rows('ros.sessions',
            "SELECT id, title, scheduled_at, status, room, mode FROM class_sessions
              WHERE cohort_id = ? ORDER BY scheduled_at DESC LIMIT 20", [$sel]);
    }

    return [
        'cohorts' => $data['rows'],
        'selected' => $sel,
        'cohort' => $cohort,
        'roster' => $roster,
        'staff' => $staff,
        'sessions' => $sessions,
        'vetting_mode' => mam_console_try('ros.mode', fn() => mam_vetting_mode(), 'warn'),
        'term' => mam_console_term(),
        'candidates' => mam_console_rows('ros.cand',
            "SELECT id, name, email FROM users WHERE role = 'student' AND status IN ('active','roster') ORDER BY name LIMIT 400"),
        'instructors' => mam_console_rows('ros.instr',
            "SELECT id, name, email FROM users WHERE role = 'instructor' AND status = 'active' ORDER BY name"),
    ];
}

/* ─────────────────────────────────────────────────────────────────────────────
   8. Timetable & rooms
   ───────────────────────────────────────────────────────────────────────── */

/**
 * A week of sessions, plus the room clashes in it. A clash is two sessions in
 * one room whose intervals overlap — computed here rather than trusted from a
 * flag, because nothing in v1 writes such a flag.
 */
function mam_console_timetable(array $q = []): array {
    $offset = (int) ($q['w'] ?? 0);
    $monday = strtotime('monday this week', time() + $offset * 7 * 86400);
    $from = date('Y-m-d', $monday);
    $to   = date('Y-m-d', $monday + 7 * 86400);

    $rows = mam_console_rows('tt.rows',
        "SELECT cs.id, cs.title, cs.scheduled_at, cs.duration_min, cs.status, cs.mode,
                cs.room, cs.room_id, r.name room_name, r.capacity room_cap,
                c.title cohort, u.name instructor,
                (SELECT COUNT(DISTINCT ce.student_id) FROM course_enrollments ce
                  WHERE ce.cohort_id = cs.cohort_id AND ce.status = 'active') enrolled
           FROM class_sessions cs
           LEFT JOIN cohorts c ON c.id = cs.cohort_id
           LEFT JOIN rooms r ON r.id = cs.room_id
           LEFT JOIN users u ON u.id = c.instructor_id
          WHERE cs.scheduled_at >= ? AND cs.scheduled_at < ?
          ORDER BY cs.scheduled_at", [$from . ' 00:00:00', $to . ' 00:00:00']);

    // Clash detection. Only sessions with a physical room can clash; an online
    // session shares no walls with anything.
    $clashes = [];
    $byRoom = [];
    foreach ($rows as $r) {
        $room = trim((string) ($r['room_name'] ?? $r['room'] ?? ''));
        if ($room === '' || ($r['mode'] ?? '') === 'online') continue;
        $byRoom[$room][] = $r;
    }
    foreach ($byRoom as $room => $list) {
        usort($list, fn($a, $b) => strcmp((string) $a['scheduled_at'], (string) $b['scheduled_at']));
        for ($i = 1; $i < count($list); $i++) {
            $prev = $list[$i - 1]; $cur = $list[$i];
            $prevEnd = strtotime((string) $prev['scheduled_at']) + max(30, (int) ($prev['duration_min'] ?: 60)) * 60;
            if (strtotime((string) $cur['scheduled_at']) < $prevEnd) {
                $clashes[] = ['room' => $room, 'a' => $prev, 'b' => $cur,
                    'overlap_min' => (int) round(($prevEnd - strtotime((string) $cur['scheduled_at'])) / 60)];
            }
        }
    }
    foreach ($clashes as $c) {
        foreach ($rows as &$r) if ((int) $r['id'] === (int) $c['a']['id'] || (int) $r['id'] === (int) $c['b']['id']) $r['clash'] = true;
        unset($r);
    }

    $rooms = mam_console_rows('tt.rooms', "SELECT id, name, capacity, location, active FROM rooms ORDER BY name");
    foreach ($rooms as &$rm) {
        $used = 0;
        foreach ($rows as $r) if ((int) ($r['room_id'] ?? 0) === (int) $rm['id']) $used += max(30, (int) ($r['duration_min'] ?: 60));
        // Against a nominal 8-hour teaching day, six days a week.
        $rm['used_min'] = $used;
        $rm['util'] = mam_console_pct($used, 8 * 60 * 6);
    }
    unset($rm);

    return ['from' => $from, 'to' => $to, 'offset' => $offset, 'rows' => $rows,
        'clashes' => $clashes, 'rooms' => $rooms, 'term' => mam_console_term()];
}

/* ─────────────────────────────────────────────────────────────────────────────
   9. Curriculum
   ───────────────────────────────────────────────────────────────────────── */

function mam_console_curriculum(array $q = []): array {
    $programs = mam_console_rows('cur.prog',
        "SELECT p.id, p.code, p.name, p.weeks, p.active,
                (SELECT COUNT(*) FROM program_courses pc WHERE pc.program_id = p.id) courses
           FROM programs p ORDER BY p.sort_order, p.name");
    $prog = (int) ($q['program'] ?? 0);
    if ($prog <= 0 && $programs) $prog = (int) $programs[0]['id'];

    $courses = mam_console_rows('cur.courses',
        "SELECT c.id, c.code, c.name, c.kind, c.active, pc.level_code,
                (SELECT COUNT(*) FROM modules m WHERE m.course_id = c.id) modules
           FROM program_courses pc JOIN courses c ON c.id = pc.course_id
          WHERE pc.program_id = ? ORDER BY pc.sort_order, c.code", [$prog]);

    $course = (int) ($q['course'] ?? 0);
    if ($course <= 0 && $courses) $course = (int) $courses[0]['id'];

    // Resources attach to a class or a cohort, never to a module, so a per-module
    // resource count is not a thing v1 can answer — don't invent a column for it.
    // A module is a LESSON since v97 (api/lib/lessons.php): its state, how many
    // questions it has and how many students have started it, in one query.
    $modules = $course > 0 ? mam_console_rows('cur.modules',
        "SELECT m.id, m.week, m.title, m.kind, m.status, m.version, l.code level_code,
                (SELECT COUNT(*) FROM module_checks mc WHERE mc.module_id = m.id) questions,
                (SELECT COUNT(*) FROM module_progress mp WHERE mp.module_id = m.id) started,
                (SELECT COUNT(*) FROM module_progress mp WHERE mp.module_id = m.id AND mp.status = 'completed') done
           FROM modules m LEFT JOIN levels l ON l.id = m.level_id
          WHERE m.course_id = ? ORDER BY l.sort_order, m.week, m.id", [$course]) : [];

    $weeks = (int) (mam_console_scalar('cur.weeks', "SELECT weeks FROM programs WHERE id = ?", [$prog], 0) ?: 0);
    $have = array_map(fn($m) => (int) $m['week'], $modules);
    $gaps = [];
    for ($w = 1; $w <= max(0, $weeks); $w++) if (!in_array($w, $have, true)) $gaps[] = $w;

    // Coverage: which of the mastery components does anything in this course
    // actually evidence? A competency carrying weight that nothing evidences is
    // a curriculum gap, and every student's band will read "not enough evidence".
    $components = mam_console_try('cur.components', fn() => MAM_MASTERY_COMPONENTS, []) ?: [];
    $criteria = mam_console_rows('cur.criteria',
        "SELECT DISTINCT rc.label FROM rubric_criteria rc
           JOIN rubrics r ON r.id = rc.rubric_id
           JOIN cohorts c ON c.id = r.cohort_id
          WHERE c.course_id = ?", [$course]);
    $evidenced = [];
    foreach ($criteria as $c) {
        $comp = mam_console_try('cur.map', fn() => mam_mastery_component_for_criterion((string) $c['label']), null);
        if ($comp) $evidenced[$comp] = true;
    }
    $coverage = [];
    foreach ($components as $key => $meta) {
        $coverage[] = ['key' => $key, 'label' => (string) ($meta['label'] ?? $key),
            'weight' => (float) ($meta['weight'] ?? 0), 'evidenced' => isset($evidenced[$key])];
    }

    // The lesson open in the editor: ?lesson=N, or ?lesson=new for a blank one
    // on the course in view, at the first week that has no lesson yet.
    $editing = null;
    $lq = (string) ($q['lesson'] ?? '');
    if ($lq === 'new') {
        $next = 1;
        while (in_array($next, $have, true)) $next++;
        $editing = ['id' => null, 'course_id' => $course, 'level_id' => null, 'week' => $next, 'kind' => 'module',
            'title' => '', 'summary' => '', 'body' => '', 'objectives' => [], 'duration_mins' => null, 'pass_mark' => 60,
            'status' => 'draft', 'version' => 1, 'checks' => [], 'materials' => [], 'started' => 0];
    } elseif ((int) $lq > 0) {
        $editing = mam_console_try('cur.lesson', fn() => mam_lesson_get((int) $lq, true, true), null);
        if ($editing) $editing['started'] = (int) mam_console_scalar('cur.lstarted', 'SELECT COUNT(*) FROM module_progress WHERE module_id = ?', [(int) $lq], 0);
    }

    return ['programs' => $programs, 'program' => $prog, 'courses' => $courses,
        'course' => $course, 'modules' => $modules, 'gaps' => $gaps, 'weeks' => $weeks,
        'coverage' => $coverage, 'editing' => $editing,
        'all_courses' => mam_console_rows('cur.allcourses',
            "SELECT c.id, c.code, c.name, c.kind, c.active,
                    (SELECT COUNT(*) FROM program_courses pc WHERE pc.course_id = c.id AND pc.program_id = ?) linked
               FROM courses c ORDER BY c.sort_order, c.name", [$prog]),
        'report' => mam_console_try('cur.report', fn() => mam_lesson_course_report(), []),
        'waiting' => (int) mam_console_scalar('cur.waiting', 'SELECT COUNT(*) FROM module_progress WHERE pending = 1', [], 0),
        'levels' => mam_console_rows('cur.levels', "SELECT id, code, name FROM levels ORDER BY sort_order")];
}

/* ─────────────────────────────────────────────────────────────────────────────
   10. Assessment & boards
   ───────────────────────────────────────────────────────────────────────── */

function mam_console_assessment(): array {
    $rubrics = mam_console_rows('asm.rubrics',
        "SELECT r.id, r.title, r.active, r.created_at, c.title cohort,
                (SELECT COUNT(*) FROM rubric_criteria rc WHERE rc.rubric_id = r.id) criteria,
                (SELECT COUNT(*) FROM submission_reviews sr WHERE sr.rubric_id = r.id) marks
           FROM rubrics r LEFT JOIN cohorts c ON c.id = r.cohort_id
          ORDER BY r.active DESC, r.created_at DESC LIMIT 40");

    $reports = mam_console_rows('asm.reports',
        "SELECT status, COUNT(*) n FROM termly_reports GROUP BY status");
    $byStatus = [];
    foreach ($reports as $r) $byStatus[(string) $r['status']] = (int) $r['n'];

    $appeals = mam_console_rows('asm.appeals',
        "SELECT a.id, a.competency_code, a.reason, a.status, a.opened_at, a.closed_at,
                u.name student, a.report_id
           FROM mark_appeals a LEFT JOIN users u ON u.id = a.student_id
          ORDER BY (a.closed_at IS NULL) DESC, a.opened_at DESC LIMIT 20");

    // A moderation records whether the second marker AGREED, not a second
    // percentage — so the figure to show is the disagreement rate, not a mean
    // absolute gap. Averaging two disagreeing markers is exactly what
    // reports.php refuses to do, and a "gap" column here would imply it does.
    $mod = mam_console_row('asm.mod',
        "SELECT COUNT(*) n, SUM(CASE WHEN agreed = 0 THEN 1 ELSE 0 END) disagreed
           FROM report_moderations");

    return [
        'rubrics' => $rubrics,
        'reports' => $byStatus,
        'appeals' => $appeals,
        'open_appeals' => mam_console_int('asm.open', "SELECT COUNT(*) FROM mark_appeals WHERE closed_at IS NULL"),
        'moderation' => ['n' => (int) ($mod['n'] ?? 0), 'disagreed' => (int) ($mod['disagreed'] ?? 0)],
        'min_share' => defined('MAM_REPORT_MODERATION_SHARE') ? MAM_REPORT_MODERATION_SHARE : null,
        'min_sample' => defined('MAM_REPORT_MODERATION_MIN') ? MAM_REPORT_MODERATION_MIN : null,
        'drafts' => mam_console_rows('asm.drafts',
            "SELECT r.id, r.term_label, r.status, r.band_low, r.band_high, r.coverage_evidenced,
                    r.coverage_total, u.name student, c.title cohort
               FROM termly_reports r LEFT JOIN users u ON u.id = r.student_id
               LEFT JOIN cohorts c ON c.id = r.cohort_id
              WHERE r.status <> 'ratified' ORDER BY r.created_at DESC LIMIT 30"),
    ];
}

/* ─────────────────────────────────────────────────────────────────────────────
   11. Bursaries & awards
   ───────────────────────────────────────────────────────────────────────── */

function mam_console_bursaries(): array {
    $rows = mam_console_rows('bur.rows',
        "SELECT b.id, b.income_band, b.status, b.awarded_pct, b.household_size,
                b.dependants_in_academy, b.term_label, b.created_at, b.decided_at, b.outcome_note,
                s.name student, s.id student_id, g.name guardian
           FROM bursary_applications b
           LEFT JOIN users s ON s.id = b.student_id
           LEFT JOIN users g ON g.id = b.guardian_id
          ORDER BY (b.status IN ('submitted','assessing')) DESC, b.created_at DESC LIMIT 80");

    $awards = mam_console_rows('bur.awards',
        "SELECT kind, COUNT(*) n, COALESCE(SUM(amount_minor), 0) minor, currency
           FROM fee_aid_awards GROUP BY kind, currency");

    $bands = mam_console_try('bur.bands', fn() => mam_bursary_bands(), null);
    if (!is_array($bands)) $bands = [];

    return [
        'rows' => $rows,
        'awards' => $awards,
        'bands' => $bands,
        'pending' => mam_console_int('bur.pending', "SELECT COUNT(*) FROM bursary_applications WHERE status IN ('submitted','assessing')"),
        'scholars' => mam_console_int('bur.schol', "SELECT COUNT(*) FROM users WHERE student_type = 'scholarship'"),
        'sibling_pct' => mam_console_try('bur.sib', fn() => mam_setting_num('sibling_discount_pct', 0), 0.0),
    ];
}

/* ─────────────────────────────────────────────────────────────────────────────
   12. Intake funnel
   ───────────────────────────────────────────────────────────────────────── */

function mam_console_funnel(): array {
    $steps = mam_console_rows('fun.steps',
        "SELECT step, COUNT(DISTINCT sid) reached FROM funnel_events
          WHERE step IS NOT NULL GROUP BY step ORDER BY step");
    $starts = mam_console_int('fun.starts', "SELECT COUNT(DISTINCT sid) FROM funnel_events");
    $submitted = mam_console_int('fun.sub', "SELECT COUNT(*) FROM applications");
    // Half-finished applications. The draft carries its own status, so a draft
    // that never became an application is the one that is not 'submitted' — this
    // is the largest pool in the funnel and nothing reaches it today.
    $drafts = mam_console_int('fun.drafts',
        "SELECT COUNT(*) FROM application_drafts WHERE status <> 'submitted'");

    $prev = null; $rows = [];
    foreach ($steps as $s) {
        $n = (int) $s['reached'];
        $rows[] = ['step' => (int) $s['step'], 'reached' => $n,
            'dropped' => $prev === null ? 0 : max(0, $prev - $n),
            'pct' => $starts > 0 ? mam_console_pct($n, $starts) : 0];
        $prev = $n;
    }

    $byPathway = mam_console_rows('fun.path',
        "SELECT pathway, COUNT(DISTINCT sid) n FROM funnel_events
          WHERE pathway IS NOT NULL AND pathway <> '' GROUP BY pathway ORDER BY n DESC");

    return ['starts' => $starts, 'submitted' => $submitted, 'steps' => $rows,
        'drafts' => $drafts, 'by_pathway' => $byPathway,
        'conversion' => $starts > 0 ? mam_console_pct($submitted, $starts) : null];
}

/* ─────────────────────────────────────────────────────────────────────────────
   13. Attendance
   ───────────────────────────────────────────────────────────────────────── */

function mam_console_attendance(array $q = []): array {
    $day = (string) ($q['d'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) $day = date('Y-m-d');

    $sessions = mam_console_rows('att.sessions',
        "SELECT cs.id, cs.title, cs.scheduled_at, cs.duration_min, cs.status, cs.mode, cs.room,
                c.title cohort, u.name instructor,
                (SELECT COUNT(DISTINCT ce.student_id) FROM course_enrollments ce
                  WHERE ce.cohort_id = cs.cohort_id AND ce.status = 'active') enrolled,
                (SELECT COUNT(*) FROM session_attendance sa WHERE sa.session_id = cs.id) marks,
                (SELECT COUNT(*) FROM session_attendance sa WHERE sa.session_id = cs.id AND sa.status IN ('present','late')) present,
                (SELECT MIN(sa.created_at) FROM session_attendance sa WHERE sa.session_id = cs.id) marked_at
           FROM class_sessions cs
           LEFT JOIN cohorts c ON c.id = cs.cohort_id
           LEFT JOIN users u ON u.id = c.instructor_id
          WHERE cs.scheduled_at >= ? AND cs.scheduled_at < ?
          ORDER BY cs.scheduled_at", [$day . ' 00:00:00', $day . ' 23:59:59']);

    // Escalation: a first absence sends nothing (people get ill); the second
    // opens a conversation. The level is never shown to the learner.
    $absent = mam_console_rows('att.absent',
        "SELECT sa.student_id, u.name, COUNT(*) n,
                GROUP_CONCAT(cs.title) titles
           FROM session_attendance sa
           JOIN class_sessions cs ON cs.id = sa.session_id
           JOIN users u ON u.id = sa.student_id
          WHERE sa.status = 'absent' AND cs.scheduled_at >= ?
          GROUP BY sa.student_id, u.name HAVING COUNT(*) >= 2
          ORDER BY n DESC LIMIT 20", [gmdate('Y-m-d', time() - 90 * 86400)]);
    foreach ($absent as &$a) {
        $n = (int) $a['n'];
        $a['level'] = $n >= 6 ? 'L3 · review' : ($n >= 4 ? 'L2 · + staff' : 'L1 · parent');
    }
    unset($a);

    $enrolled = 0; $present = 0; $marked = 0;
    foreach ($sessions as $s) { $enrolled += (int) $s['enrolled']; $present += (int) $s['present']; if ((int) $s['marks'] > 0) $marked++; }

    $late = 0;
    foreach ($sessions as $s) {
        if ((int) $s['marks'] === 0 && strtotime((string) $s['scheduled_at']) < time() - 3600) $late++;
    }

    return ['day' => $day, 'sessions' => $sessions, 'absent' => $absent,
        'marked' => $marked, 'total' => count($sessions), 'present' => $present,
        'enrolled' => $enrolled, 'late' => $late,
        // The check-in boundaries for the next session today. mam_checkin_window()
        // is the ONE source for §9's 30-minute opening and 15-minute readiness
        // threshold — never restate either as a literal here or in the browser.
        'window' => (function () use ($sessions) {
            foreach ($sessions as $s) {
                if (strtotime((string) $s['scheduled_at']) >= time() - 3600) {
                    return mam_console_try('att.window', fn() => mam_checkin_window(
                        (string) $s['scheduled_at'], (int) ($s['duration_min'] ?: 60)), null);
                }
            }
            return null;
        })(),
        // The platform log, per participant. The register is what the instructor
        // saw and the Meet log is what the platform recorded; where they
        // disagree the REGISTER wins and the difference is kept, because it is
        // evidence rather than an error to overwrite (§11).
        'reconciliation' => mam_console_rows('att.recon',
            "SELECT r.id, r.session_id, r.provider, r.display_name, r.join_at, r.leave_at,
                    r.minutes, r.source, cs.title
               FROM meeting_reconciliation r LEFT JOIN class_sessions cs ON cs.id = r.session_id
              ORDER BY r.id DESC LIMIT 12")];
}

/* ─────────────────────────────────────────────────────────────────────────────
   14. Guardians & consent
   ───────────────────────────────────────────────────────────────────────── */

function mam_console_guardians(): array {
    $links = mam_console_rows('gd.links',
        "SELECT pl.id, pl.relationship, pl.access_scope, pl.created_at,
                p.id parent_id, p.name parent, p.email parent_email,
                s.id student_id, s.name student
           FROM parent_links pl
           JOIN users p ON p.id = pl.parent_id
           JOIN users s ON s.id = pl.student_id
          ORDER BY s.name, p.name LIMIT 300");

    $consents = mam_console_row('gd.consents',
        "SELECT COUNT(*) total,
                SUM(CASE WHEN photo_consent = 1 THEN 1 ELSE 0 END) photo,
                SUM(CASE WHEN media_consent = 1 THEN 1 ELSE 0 END) media,
                SUM(CASE WHEN data_consent = 1 THEN 1 ELSE 0 END) data
           FROM student_consents");

    $students = mam_console_int('gd.students', "SELECT COUNT(*) FROM users WHERE role='student' AND status='active'");
    $noGuardian = mam_console_rows('gd.none',
        "SELECT u.id, u.name, u.email, u.created_at FROM users u
          WHERE u.role = 'student' AND u.status = 'active'
            AND NOT EXISTS (SELECT 1 FROM parent_links pl WHERE pl.student_id = u.id)
          ORDER BY u.name LIMIT 50");

    $scopes = [];
    foreach (mam_console_rows('gd.scopes',
        "SELECT COALESCE(access_scope,'standard') s, COUNT(*) n FROM parent_links GROUP BY 1") as $r) {
        $scopes[(string) $r['s']] = (int) $r['n'];
    }

    return ['links' => $links, 'consents' => $consents ?: [], 'students' => $students,
        'no_guardian' => $noGuardian, 'scopes' => $scopes,
        'never' => defined('MAM_PARENT_NEVER') ? MAM_PARENT_NEVER : []];
}

/* ─────────────────────────────────────────────────────────────────────────────
   15. Academic calendar
   ───────────────────────────────────────────────────────────────────────── */

function mam_console_calendar(): array {
    $terms = mam_console_try('cal.terms', fn() => mam_calendar_terms(24), []) ?: [];
    foreach ($terms as &$t) {
        $t = mam_console_try('cal.dec', fn() => mam_calendar_decorate($t), $t) ?: $t;
        $t['closures'] = mam_console_rows('cal.clos',
            "SELECT id, kind, label, from_on, to_on FROM term_closures WHERE term_id = ? ORDER BY from_on",
            [(int) $t['id']]);
        $t['refunds'] = mam_console_try('cal.ref', fn() => mam_calendar_refund_schedule((int) $t['id']), []) ?: [];
        $t['census'] = mam_console_try('cal.cen', fn() => mam_calendar_census((int) $t['id']), null);
    }
    unset($t);
    return ['terms' => $terms, 'current' => mam_console_term(),
        'tz' => defined('MAM_CALENDAR_TZ') ? MAM_CALENDAR_TZ : 'Africa/Lagos',
        'today' => mam_console_try('cal.today', fn() => mam_calendar_today(), date('Y-m-d'))];
}

/* ─────────────────────────────────────────────────────────────────────────────
   16. Recitals & events
   ───────────────────────────────────────────────────────────────────────── */

function mam_console_events(array $q = []): array {
    $recitals = mam_console_rows('ev.recitals',
        "SELECT r.id, r.slug, r.title, r.venue, r.starts_at, r.status, r.interval_min,
                (SELECT COUNT(*) FROM recital_items i WHERE i.recital_id = r.id) items,
                (SELECT COUNT(*) FROM recital_items i WHERE i.recital_id = r.id AND i.status = 'submitted') pending,
                (SELECT COALESCE(SUM(i.duration_min),0) FROM recital_items i WHERE i.recital_id = r.id AND i.status = 'accepted') minutes
           FROM recitals r ORDER BY r.starts_at DESC LIMIT 20");
    $sel = (int) ($q['recital'] ?? 0);
    if ($sel <= 0 && $recitals) $sel = (int) $recitals[0]['id'];

    $items = $sel > 0 ? mam_console_rows('ev.items',
        "SELECT i.id, i.title, i.composer, i.duration_min, i.status, i.section, i.sort_order,
                u.name performer, i.student_id,
                (SELECT photo_consent FROM student_consents sc WHERE sc.student_id = i.student_id) photo_consent
           FROM recital_items i LEFT JOIN users u ON u.id = i.student_id
          WHERE i.recital_id = ? ORDER BY (i.status = 'accepted') DESC, i.sort_order, i.id", [$sel]) : [];

    $recital = null;
    foreach ($recitals as $r) if ((int) $r['id'] === $sel) { $recital = $r; break; }

    return ['recitals' => $recitals, 'selected' => $sel, 'recital' => $recital, 'items' => $items];
}

/* ─────────────────────────────────────────────────────────────────────────────
   17. Files & ID cards
   ───────────────────────────────────────────────────────────────────────── */

function mam_console_files(): array {
    $resources = mam_console_rows('fl.res',
        "SELECT r.id, r.title, r.type, r.url, r.created_at, c.title cohort, cl.title class
           FROM class_resources r
           LEFT JOIN cohorts c ON c.id = r.cohort_id
           LEFT JOIN classes cl ON cl.id = r.class_id
          ORDER BY r.created_at DESC LIMIT 60");

    $cards = mam_console_rows('fl.cards',
        "SELECT c.id, c.serial, c.status, c.role, c.session_code, c.issued_at, c.printed_at,
                c.expires_at, c.photo_url, u.name
           FROM id_cards c LEFT JOIN users u ON u.id = c.user_id
          ORDER BY c.issued_at DESC LIMIT 60");
    // Derived state, never the status column — between two cron runs a lapsed
    // card still reads 'active' (CLAUDE.md, card expiry).
    $now = gmdate('Y-m-d H:i:s');
    $ready = 0; $lapsed = 0;
    foreach ($cards as &$c) {
        $c['lapsed'] = !empty($c['expires_at']) && (string) $c['expires_at'] < $now;
        if ($c['lapsed']) $lapsed++;
        elseif ((string) $c['status'] === 'active' && empty($c['printed_at'])) $ready++;
    }
    unset($c);

    $avatars = mam_console_int('fl.avatars', "SELECT COUNT(*) FROM users WHERE avatar_url IS NOT NULL AND avatar_url <> ''");

    return ['resources' => $resources, 'cards' => $cards, 'ready' => $ready,
        'lapsed' => $lapsed, 'avatars' => $avatars,
        'retention' => mam_console_try('fl.ret', fn() => mam_dp_retention(), []) ?: []];
}

/* ─────────────────────────────────────────────────────────────────────────────
   18. Store & orders
   ───────────────────────────────────────────────────────────────────────── */

function mam_console_store(): array {
    $orders = mam_console_rows('st.orders',
        "SELECT id, reference, customer_name, customer_email, total_usd, status,
                items_json, delivered_at, created_at
           FROM store_orders ORDER BY created_at DESC LIMIT 80");
    $items = mam_console_rows('st.items',
        "SELECT id, sku, name, price_usd, currency, stock, availability, goods_type, active
           FROM store_items ORDER BY name LIMIT 120");
    $oos = array_values(array_filter($items, fn($i) => (int) ($i['active'] ?? 0) === 1 && $i['stock'] !== null && (int) $i['stock'] <= 0));

    $sum = mam_console_row('st.sum',
        "SELECT COUNT(*) n, COALESCE(SUM(total_usd),0) usd FROM store_orders WHERE status IN ('paid','delivered')");

    return ['orders' => $orders, 'items' => $items, 'out_of_stock' => $oos,
        'pending' => mam_console_int('st.pend', "SELECT COUNT(*) FROM store_orders WHERE status = 'pending'"),
        'awaiting' => mam_console_int('st.await', "SELECT COUNT(*) FROM store_orders WHERE status = 'paid'"),
        'revenue_usd' => (float) ($sum['usd'] ?? 0), 'orders_n' => (int) ($sum['n'] ?? 0)];
}

/* ─────────────────────────────────────────────────────────────────────────────
   19. Charges & exceptions  ·  20. Instructor deductions
   ───────────────────────────────────────────────────────────────────────── */

function mam_console_charges(): array {
    $rows = mam_console_rows('ch.rows',
        "SELECT c.id, c.subject_type, c.subject_id, c.reason, c.minutes, c.amount_usd_cents,
                c.currency, c.status, c.note, c.created_at, c.resolution_note,
                u.name subject_name, cs.title session_title
           FROM accountability_charges c
           LEFT JOIN users u ON u.id = c.subject_id
           LEFT JOIN class_sessions cs ON cs.id = c.session_id
          ORDER BY (c.status IN ('waiver_requested','disputed')) DESC, c.created_at DESC LIMIT 80");
    return [
        'rows' => $rows,
        'student_gate' => (int) mam_console_try('ch.g1', fn() => (int) mam_setting_num('late_charge_enabled', 0), 0),
        'instructor_gate' => (int) mam_console_try('ch.g2', fn() => (int) mam_setting_num('late_charge_instructor_enabled', 0), 0),
        'pending' => mam_console_int('ch.pend', "SELECT COUNT(*) FROM accountability_charges WHERE status IN ('waiver_requested','disputed')"),
        'owed_cents' => mam_console_int('ch.owed',
            "SELECT COALESCE(SUM(amount_usd_cents),0) FROM accountability_charges WHERE status IN ('outstanding','disputed')"),
        'exceptions' => mam_console_rows('ch.exc',
            "SELECT e.id, e.charge_id, e.reason_code, e.detail, e.status, e.decision_note, e.created_at
               FROM accountability_exceptions e ORDER BY e.created_at DESC LIMIT 20"),
    ];
}

function mam_console_deductions(): array {
    $rows = mam_console_rows('de.rows',
        "SELECT f.id, f.reason, f.status, f.amount_kobo, f.amount_usd_cents, f.currency,
                f.created_at, f.resolved_at, f.resolution_note,
                u.name instructor, u.email, cs.title session_title, cs.scheduled_at
           FROM instructor_fines f
           LEFT JOIN users u ON u.id = f.instructor_id
           LEFT JOIN class_sessions cs ON cs.id = f.session_id
          ORDER BY (f.status = 'pending') DESC, f.created_at DESC LIMIT 80");
    $by = [];
    foreach (mam_console_rows('de.by', "SELECT status, COUNT(*) n FROM instructor_fines GROUP BY status") as $r) {
        $by[(string) $r['status']] = (int) $r['n'];
    }
    return ['rows' => $rows, 'by_status' => $by,
        'gate' => (int) mam_console_try('de.gate', fn() => (int) mam_setting_num('late_charge_instructor_enabled', 0), 0),
        'flagged_cents' => mam_console_int('de.val',
            "SELECT COALESCE(SUM(amount_usd_cents),0) FROM instructor_fines WHERE status = 'pending'")];
}

/* ─────────────────────────────────────────────────────────────────────────────
   21. Price books
   ───────────────────────────────────────────────────────────────────────── */

function mam_console_pricebooks(): array {
    $keys = ['tuition_usd', 'registration_fee_usd', 'certification_fee_usd', 'booking_fee_usd',
        'summer_fee_usd', 'materials_fee_usd', 'certificate_reissue_usd'];
    $items = [];
    foreach ($keys as $k) {
        $v = mam_console_try('pb.' . $k, fn() => mam_setting_or_default($k, ''), '');
        if ($v === '' || $v === null) continue;
        $items[] = ['key' => $k, 'usd' => (float) $v];
    }
    $prog = mam_console_rows('pb.prog',
        "SELECT p.code, p.name, p.weeks, p.certification FROM programs p WHERE p.active = 1 ORDER BY p.sort_order");
    $rate = mam_console_try('pb.rate', fn() => mam_usd_to_ngn_rate(false), null);
    $charged = mam_console_int('pb.charged',
        "SELECT COUNT(*) FROM fee_invoices WHERE status IN ('issued','part_paid')");
    return ['items' => $items, 'programs' => $prog, 'rate' => $rate, 'live_invoices' => $charged,
        'currencies' => mam_console_try('pb.cur', fn() => mam_fx_currencies(), []) ?: []];
}

/* ─────────────────────────────────────────────────────────────────────────────
   22. People & access
   ───────────────────────────────────────────────────────────────────────── */

function mam_console_access(): array {
    $rows = mam_console_rows('ac.rows',
        "SELECT u.id, u.name, u.email, u.role, u.status, u.admin_tier, u.last_login, u.created_at,
                (SELECT COUNT(*) FROM sessions s WHERE s.user_id = u.id AND s.expires_at > ?) sessions
           FROM users u WHERE u.role IN ('admin','instructor') OR u.status = 'system'
          ORDER BY (u.role = 'admin') DESC, u.name LIMIT 200", [gmdate('Y-m-d H:i:s')]);
    foreach ($rows as &$r) {
        $r['extra_roles'] = array_column(mam_console_rows('ac.roles',
            "SELECT role FROM user_roles WHERE user_id = ?", [(int) $r['id']]), 'role');
        if ((string) $r['role'] === 'instructor') {
            $r['gate'] = mam_console_try('ac.gate', fn() => mam_vetting_may_teach_minors((int) $r['id']), null);
        }
    }
    unset($r);
    return ['rows' => $rows,
        'scopes' => ['billing' => 'Invoices and receipts. No marks, no attendance.',
            'standard' => 'Adds ratified reports, attendance, timetable.',
            'full' => 'Adds consents and the ability to act for the child.'],
        'never' => defined('MAM_PARENT_NEVER') ? MAM_PARENT_NEVER : [],
        'break_glass' => mam_console_row('ac.bg',
            "SELECT id, email, status, created_at FROM users WHERE email = 'break-glass@mam.invalid'")];
}

/* ─────────────────────────────────────────────────────────────────────────────
   23. Governance & audit
   ───────────────────────────────────────────────────────────────────────── */

function mam_console_governance(): array {
    return [
        'recent' => mam_console_rows('gv.recent',
            "SELECT id, actor, action, entity, entity_id, meta, ip, created_at
               FROM audit_log ORDER BY id DESC LIMIT 40"),
        'total' => mam_console_int('gv.total', "SELECT COUNT(*) FROM audit_log"),
        'head' => mam_console_int('gv.head', "SELECT COALESCE(MAX(id),0) FROM audit_log"),
        'dp_open' => mam_console_try('gv.dp', fn() => mam_dp_requests(true, 20), []) ?: [],
        'vetting' => mam_console_try('gv.vet', fn() => mam_vetting_summary(), []) ?: [],
        'retention_mode' => mam_console_try('gv.ret', fn() => mam_dp_retention_mode(), 'report'),
        'tier' => mam_console_try('gv.tier', fn() => mam_dp_tier(6), []) ?: [],
        'break_glass' => mam_console_int('gv.bg',
            "SELECT COUNT(*) FROM audit_log WHERE actor LIKE '%break-glass%' OR actor = ?",
            [(string) mam_env('ADMIN_USER', '__none__')]),
    ];
}

/* ─────────────────────────────────────────────────────────────────────────────
   24. Reports
   ───────────────────────────────────────────────────────────────────────── */

function mam_console_reports(): array {
    $terms = mam_console_try('rp.terms', fn() => mam_calendar_terms(8), []) ?: [];
    $enrolByTerm = [];
    foreach ($terms as $t) {
        $c = mam_console_try('rp.cen', fn() => mam_calendar_census((int) $t['id']), null);
        $enrolByTerm[] = ['label' => trim((string) ($t['label'] ?? $t['code'] ?? '')) . ' ' . (string) ($t['year_label'] ?? ''),
            'n' => $c ? (int) ($c['headcount'] ?? 0) : null];
    }

    $offers = mam_console_int('rp.offers', "SELECT COUNT(*) FROM applications WHERE status IN ('offered','offer_made','accepted','enrolled','paid')");
    $taken  = mam_console_int('rp.taken', "SELECT COUNT(*) FROM applications WHERE status IN ('accepted','enrolled','paid')");

    // "First look" is the first status transition off the submitted state. The
    // table records from_status/to_status, so that is what identifies it — there
    // is no event-name column to match on.
    $firstLook = mam_console_rows('rp.first',
        "SELECT a.created_at, MIN(e.created_at) looked
           FROM applications a JOIN application_events e ON e.application_id = a.id
          WHERE e.from_status IN ('pending','submitted') AND e.to_status <> e.from_status
          GROUP BY a.id, a.created_at LIMIT 400");
    $days = [];
    foreach ($firstLook as $r) {
        $a = strtotime((string) $r['created_at']); $b = strtotime((string) $r['looked']);
        if ($a && $b && $b >= $a) $days[] = ($b - $a) / 86400;
    }
    sort($days);
    $median = $days ? $days[(int) floor((count($days) - 1) / 2)] : null;

    $billed = mam_console_int('rp.billed', "SELECT COALESCE(SUM(total_usd_cents),0) FROM fee_invoices WHERE status <> 'void'");
    $collected = mam_console_collected_cents();

    $byCohort = mam_console_rows('rp.coh',
        "SELECT c.id, c.title,
                (SELECT COUNT(DISTINCT ce.student_id) FROM course_enrollments ce WHERE ce.cohort_id = c.id AND ce.status='active') students,
                (SELECT COUNT(*) FROM session_attendance sa JOIN class_sessions cs ON cs.id = sa.session_id WHERE cs.cohort_id = c.id) marks,
                (SELECT COUNT(*) FROM session_attendance sa JOIN class_sessions cs ON cs.id = sa.session_id
                  WHERE cs.cohort_id = c.id AND sa.status IN ('present','late')) present
           FROM cohorts c WHERE c.status = 'active' ORDER BY c.title LIMIT 30");
    foreach ($byCohort as &$c) $c['rate'] = (int) $c['marks'] > 0 ? mam_console_pct((int) $c['present'], (int) $c['marks']) : null;
    unset($c);

    $aid = mam_console_rows('rp.aid',
        "SELECT kind, COALESCE(SUM(amount_minor),0) minor, COUNT(*) n FROM fee_aid_awards GROUP BY kind");

    return [
        'kpis' => [
            ['label' => 'Offer → confirmed', 'value' => $offers > 0 ? mam_console_pct($taken, $offers) . '%' : '—',
                'note' => $taken . ' of ' . $offers . ' offers taken up'],
            ['label' => 'Median first look', 'value' => $median !== null ? round($median, 1) . 'd' : '—',
                'note' => 'The academy\'s promise is five days'],
            ['label' => 'Collected / billed', 'value' => $billed > 0 ? mam_console_pct($collected, $billed) . '%' : '—',
                'note' => mam_console_usd_short($collected) . ' of ' . mam_console_usd_short($billed)],
            ['label' => 'Active learners', 'value' => (string) mam_console_int('rp.act',
                "SELECT COUNT(DISTINCT student_id) FROM course_enrollments WHERE status = 'active'"),
                'note' => 'Course enrolments, active today'],
        ],
        'enrol_by_term' => $enrolByTerm,
        'funnel' => mam_console_funnel_stages(),
        'by_cohort' => $byCohort,
        'aid' => $aid,
        'billed' => $billed,
        'collected' => $collected,
        'instructor_notice' => defined('MAM_ANALYTICS_INSTRUCTOR_NOTICE') ? MAM_ANALYTICS_INSTRUCTOR_NOTICE : '',
    ];
}

/* ─────────────────────────────────────────────────────────────────────────────
   25. Communications
   ───────────────────────────────────────────────────────────────────────── */

function mam_console_comms(): array {
    $by = [];
    foreach (mam_console_rows('cm.by', "SELECT status, COUNT(*) n FROM mail_log GROUP BY status") as $r) {
        $by[(string) $r['status']] = (int) $r['n'];
    }
    $contexts = mam_console_rows('cm.ctx',
        "SELECT context, COUNT(*) n,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) failed
           FROM mail_log WHERE created_at >= ? GROUP BY context ORDER BY n DESC LIMIT 30",
        [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
    return [
        'by_status' => $by,
        'contexts' => $contexts,
        'recent' => mam_console_rows('cm.recent',
            "SELECT id, to_email, subject, context, status, error, attempts, created_at, sent_at
               FROM mail_log ORDER BY id DESC LIMIT 30"),
        'announcements' => mam_console_rows('cm.ann',
            "SELECT a.id, a.title, a.body, a.created_at, c.title cohort, cl.title class
               FROM announcements a
               LEFT JOIN cohorts c ON c.id = a.cohort_id
               LEFT JOIN classes cl ON cl.id = a.class_id
              ORDER BY a.created_at DESC LIMIT 20"),
        'threads' => mam_console_int('cm.threads', "SELECT COUNT(*) FROM guardian_threads"),
        'sent_24h' => mam_console_int('cm.24', "SELECT COUNT(*) FROM mail_log WHERE status='sent' AND created_at >= ?",
            [gmdate('Y-m-d H:i:s', time() - 86400)]),
    ];
}

/* ─────────────────────────────────────────────────────────────────────────────
   26. Settings & vocabulary
   ───────────────────────────────────────────────────────────────────────── */

/**
 * My account — the signed-in admin's own row and their live sessions. A
 * break-glass operator (HTTP Basic Auth, no user row) gets `me = null`: there
 * is no account to edit, and the screen says so rather than drawing a form
 * whose save would be refused.
 */
function mam_console_account(): array {
    $me = mam_current_user();
    if (!$me) return ['me' => null, 'sessions' => []];
    return [
        'me'       => ['name' => (string) ($me['name'] ?? ''), 'email' => (string) ($me['email'] ?? ''),
                       'phone' => (string) ($me['phone'] ?? ''), 'role' => (string) ($me['role'] ?? ''),
                       'tier' => (string) ($me['admin_tier'] ?? ''),
                       'must_change' => (int) ($me['must_change_password'] ?? 0) === 1],
        'sessions' => mam_console_try('account.sessions', fn() => mam_sessions_list((int) $me['id']), []),
    ];
}

function mam_console_settings(): array {
    $terms = mam_console_try('se.terms', fn() => mam_calendar_terms(8), []) ?: [];
    $autos = mam_console_try('se.autos', fn() => mam_automations_registry(), []) ?: [];
    $state = [];
    foreach (mam_console_rows('se.astate', "SELECT akey, enabled, last_run_at, run_count FROM automations") as $r) {
        $state[(string) $r['akey']] = $r;
    }
    return [
        'terms' => $terms,
        'automations' => $autos,
        'automation_state' => $state,
        'vetting_mode' => mam_console_try('se.vet', fn() => mam_vetting_mode(), 'warn'),
        'retention_mode' => mam_console_try('se.ret', fn() => mam_dp_retention_mode(), 'report'),
        'statuses' => mam_console_status_vocabulary(),
        'tenant' => [
            'base_currency' => 'USD',
            'reporting_currency' => (string) mam_console_try('se.cur', fn() => mam_setting_or_default('display_currency', 'NGN'), 'NGN'),
            'timezone' => defined('MAM_CALENDAR_TZ') ? MAM_CALENDAR_TZ : 'Africa/Lagos',
            'rate' => mam_console_try('se.rate', fn() => mam_usd_to_ngn_rate(false), null),
        ],
    ];
}

/**
 * The status vocabulary: the stored value, what a family is shown, and what
 * happens next. The design's rule is that a status with no sentence cannot
 * exist, so this is a registry rather than a table — the sentences ship with
 * the code that produces the statuses.
 */
function mam_console_status_vocabulary(): array {
    return [
        ['stored' => 'pending', 'shown' => 'Received',
         'next' => 'We have your application. You will hear from us within ten working days.', 'action' => false],
        ['stored' => 'under_review', 'shown' => 'Under review',
         'next' => 'We are reading your application. You will hear within ten working days.', 'action' => false],
        ['stored' => 'interview_scheduled', 'shown' => 'Audition scheduled',
         'next' => 'Your slot is confirmed. Bring two contrasting pieces.', 'action' => true],
        ['stored' => 'offered', 'shown' => 'Offer — conditional',
         'next' => 'Accept to hold the place. The deposit comes off your first session.', 'action' => true],
        ['stored' => 'accepted', 'shown' => 'Place confirmed',
         'next' => 'You are enrolled. Your timetable appears in the portal before the session starts.', 'action' => false],
        ['stored' => 'waitlisted', 'shown' => 'Waitlist',
         'next' => 'You are in line for a seat. We will call the moment one frees.', 'action' => false],
        ['stored' => 'rejected', 'shown' => 'Not this intake',
         'next' => 'This decision cannot be saved without a reason the family can read.', 'action' => false],
    ];
}

/* ─────────────────────────────────────────────────────────────────────────────
   27. Staff & vetting
   ───────────────────────────────────────────────────────────────────────── */

function mam_console_vetting(): array {
    $instructors = mam_console_rows('vt.instr',
        "SELECT id, name, email, status FROM users WHERE role = 'instructor' ORDER BY name LIMIT 200");
    foreach ($instructors as &$i) {
        $i['records'] = mam_console_try('vt.for', fn() => mam_vetting_for((int) $i['id']), []) ?: [];
        $i['gate'] = mam_console_try('vt.gate', fn() => mam_vetting_may_teach_minors((int) $i['id']), null);
    }
    unset($i);
    return [
        'instructors' => $instructors,
        'required' => defined('MAM_VETTING_REQUIRED') ? MAM_VETTING_REQUIRED : [],
        'alternatives' => defined('MAM_VETTING_ALTERNATIVES') ? MAM_VETTING_ALTERNATIVES : [],
        'mode' => mam_console_try('vt.mode', fn() => mam_vetting_mode(), 'warn'),
        'summary' => mam_console_try('vt.sum', fn() => mam_vetting_summary(), []) ?: [],
        'dso' => mam_console_try('vt.dso', fn() => mam_vetting_dso(), null),
        'attention' => mam_console_try('vt.att', fn() => mam_vetting_attention(50), []) ?: [],
    ];
}

/* ─────────────────────────────────────────────────────────────────────────────
   28. Data protection
   ───────────────────────────────────────────────────────────────────────── */

function mam_console_dataprotection(): array {
    $requests = mam_console_try('dp.req', fn() => mam_dp_requests(false, 60), []) ?: [];
    foreach ($requests as &$r) $r['clock'] = mam_console_try('dp.clock', fn() => mam_dp_request_clock($r), []) ?: [];
    unset($r);
    $breaches = mam_console_rows('dp.breach',
        "SELECT * FROM dp_breaches ORDER BY discovered_at DESC LIMIT 20");
    foreach ($breaches as &$b) $b['clock'] = mam_console_try('dp.bclock', fn() => mam_dp_breach_clock($b), []) ?: [];
    unset($b);
    return [
        'requests' => $requests,
        'open' => count(array_filter($requests, fn($r) => empty($r['closed_at']))),
        'breaches' => $breaches,
        'purposes' => mam_console_try('dp.purp', fn() => mam_dp_purposes(), []) ?: [],
        'retention' => mam_console_try('dp.ret', fn() => mam_dp_retention(), []) ?: [],
        'mode' => mam_console_try('dp.mode', fn() => mam_dp_retention_mode(), 'report'),
        /* The application log FILES. Always the dry-run form: a read provider
           that could delete would make opening a screen a destructive act. */
        'logs' => mam_console_try('dp.logs', fn() => mam_dp_prune_app_logs(true), []) ?: [],
        'log_keep_days' => mam_console_try('dp.logdays', fn() => mam_dp_log_retention_days(), 90),
        'processors' => mam_console_try('dp.proc', fn() => mam_dp_processor_registry(), []) ?: [],
        'tier' => mam_console_try('dp.tier', fn() => mam_dp_tier(6), []) ?: [],
        'deadlines' => defined('MAM_DP_DEADLINES') ? MAM_DP_DEADLINES : [],
        'notes' => mam_console_rows('dp.notes', "SELECT * FROM dp_register_notes ORDER BY kind, ref_key"),
        'assessments' => mam_console_rows('dp.dpia', "SELECT * FROM dp_assessments ORDER BY created_at DESC LIMIT 20"),
    ];
}

/* ─────────────────────────────────────────────────────────────────────────────
   29. Support
   ───────────────────────────────────────────────────────────────────────── */

function mam_console_support(): array {
    $rows = mam_console_rows('sp.rows',
        "SELECT t.id, t.reference, t.subject, t.category, t.status, t.priority,
                t.created_at, t.updated_at, t.resolved_at,
                u.name student, u.email, a.name assignee,
                (SELECT COUNT(*) FROM ticket_messages m WHERE m.ticket_id = t.id) replies
           FROM support_tickets t
           LEFT JOIN users u ON u.id = t.student_id
           LEFT JOIN users a ON a.id = t.assigned_to
          ORDER BY (t.status IN ('open','pending','in_progress')) DESC, t.created_at DESC LIMIT 80");
    // The first reply is the first message from somebody other than the person
    // who raised the ticket. author_id is what the table carries; there is no
    // role column on the message.
    $first = mam_console_rows('sp.first',
        "SELECT t.created_at, MIN(m.created_at) replied FROM support_tickets t
           JOIN ticket_messages m ON m.ticket_id = t.id
          WHERE m.author_id IS NULL OR m.author_id <> t.student_id
          GROUP BY t.id, t.created_at LIMIT 400");
    $mins = [];
    foreach ($first as $r) {
        $a = strtotime((string) $r['created_at']); $b = strtotime((string) $r['replied']);
        if ($a && $b && $b >= $a) $mins[] = ($b - $a) / 60;
    }
    sort($mins);
    return ['rows' => $rows,
        'open' => mam_console_int('sp.open', "SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','pending','in_progress')"),
        'closed_week' => mam_console_int('sp.week', "SELECT COUNT(*) FROM support_tickets WHERE resolved_at >= ?",
            [gmdate('Y-m-d H:i:s', time() - 7 * 86400)]),
        'median_first_reply_min' => $mins ? (int) round($mins[(int) floor((count($mins) - 1) / 2)]) : null,
        'messages' => mam_console_rows('sp.msgs',
            "SELECT id, name, email, topic, status, created_at FROM messages ORDER BY created_at DESC LIMIT 20")];
}

/* ─────────────────────────────────────────────────────────────────────────────
   30. System health
   ───────────────────────────────────────────────────────────────────────── */

function mam_console_system(): array {
    $registry = mam_console_try('sy.reg', fn() => mam_automations_registry(), []) ?: [];
    $state = [];
    foreach (mam_console_rows('sy.state', "SELECT akey, enabled, last_run_at, run_count FROM automations") as $r) {
        $state[(string) $r['akey']] = $r;
    }
    $on = 0;
    foreach ($registry as $k => $_) if ((int) ($state[$k]['enabled'] ?? 0) === 1) $on++;

    return [
        'registry' => $registry,
        'state' => $state,
        'on' => $on,
        'total' => count($registry),
        /* Whether a backup is still happening. This screen already carries the
           signing-key banner, and the two belong together: a restore needs the
           archive AND the key that signed what is in it. */
        'backup' => mam_console_try('sy.backup', fn() => mam_backup_status(), []) ?: [],
        'backups' => mam_console_try('sy.backups', fn() => mam_backup_list(6), []) ?: [],
        'events' => mam_console_rows('sy.events',
            "SELECT id, provider, event_type, status, signature_valid, error, received_at
               FROM webhook_events ORDER BY id DESC LIMIT 30"),
        'failed_mail' => mam_console_rows('sy.mail',
            "SELECT id, to_email, subject, context, error, attempts, created_at
               FROM mail_log WHERE status = 'failed' ORDER BY id DESC LIMIT 20"),
        'jobs' => mam_console_rows('sy.jobs',
            "SELECT id, kind, status, attempts, last_error, run_after, created_at
               FROM jobs ORDER BY id DESC LIMIT 20"),
        'schema' => ['version' => mam_console_int('sy.schema', "SELECT COALESCE(MAX(version),0) FROM schema_version"),
            'target' => defined('MAM_SCHEMA_TARGET') ? MAM_SCHEMA_TARGET : null],
        'api' => ['version' => defined('MAM_API_VERSION') ? MAM_API_VERSION : null],
        'driver' => mam_console_try('sy.driver', fn() => mam_db()->getAttribute(PDO::ATTR_DRIVER_NAME), 'unknown'),
        'health' => mam_console_health(),
    ];
}

/* ─────────────────────────────────────────────────────────────────────────────
   31. Auditions
   ───────────────────────────────────────────────────────────────────────── */

function mam_console_auditions(): array {
    $results = mam_console_rows('au.results',
        "SELECT r.id, r.dimensions, r.recommended_pathway, r.notes, r.audited_at,
                u.name student, u.id student_id, a.name auditor
           FROM auditer_results r
           LEFT JOIN users u ON u.id = r.student_id
           LEFT JOIN users a ON a.id = r.audited_by
          ORDER BY r.audited_at DESC LIMIT 40");
    $booked = mam_console_rows('au.booked',
        "SELECT a.id, a.reference, a.discipline, a.level, a.status, a.created_at,
                c.first_name, c.last_name, c.email
           FROM applications a LEFT JOIN customers c ON c.id = a.customer_id
          WHERE a.auditer_optin = 1 AND a.status IN ('interview_scheduled','audition_scheduled','under_review','pending')
          ORDER BY a.created_at LIMIT 40");
    return ['results' => $results, 'booked' => $booked,
        'placement_on' => (bool) mam_console_try('au.auto', fn() => mam_automation_on('auditer_placement'), false)];
}
