<?php
/**
 * The academic-session engine (api/modules/academics/lib/academic-session.php).
 *
 * This decides which term every application is stamped with, and drives the
 * "current / upcoming session" copy across the public site. It is pure date
 * arithmetic with no test coverage until now — the cheapest thing in the repo
 * to verify and the most embarrassing to get wrong, since a term boundary that
 * is off by a week mislabels every application submitted that week.
 *
 * The expected 2026 dates below were derived INDEPENDENTLY from the schedule
 * documented at the top of the lib (first Monday on/after Jan 15; 12/12/8/12
 * teaching weeks; a 1-week break after all but the last), not read back out of
 * the implementation. If a change to the engine breaks these, the question is
 * whether the schedule changed — not whether to update the numbers.
 */
declare(strict_types=1);

t_group('academic session — first Monday anchor');

$mon = fn(string $d) => mam_first_monday_on_or_after(new DateTimeImmutable($d))->format('Y-m-d');
t_eq($mon('2026-01-15'), '2026-01-19', 'Thu 15 Jan 2026 → Mon 19 Jan');
t_eq($mon('2025-01-15'), '2025-01-20', 'Wed 15 Jan 2025 → Mon 20 Jan');
t_eq($mon('2027-01-15'), '2027-01-18', 'Fri 15 Jan 2027 → Mon 18 Jan');
// A Monday must return ITSELF — "on or after", not "after". 15 Jan 2024 was a
// Monday; an off-by-one here would push every term of that year a week late.
t_eq($mon('2024-01-15'), '2024-01-15', 'a date that is already Monday returns itself');
t_eq($mon('2026-01-18'), '2026-01-19', 'Sunday rolls forward one day, not eight');
t_eq($mon('2026-01-20'), '2026-01-26', 'Tuesday rolls forward to the following Monday');

t_group('academic session — the 2026 windows');

$w = mam_session_windows(2026, []);
t_eq(count($w), 4, 'a year has four terms');
t_eq(implode(',', array_column($w, 'code')), 'toni,medi,summer,domi', 'terms run in calendar order');

$by = [];
foreach ($w as $x) $by[$x['code']] = $x;

t_eq($by['toni']['starts_on'],   '2026-01-19', 'TONI starts the first Monday on/after 15 Jan');
t_eq($by['toni']['ends_on'],     '2026-04-12', 'TONI runs 12 teaching weeks');
t_eq($by['medi']['starts_on'],   '2026-04-20', 'MEDI starts after a one-week break');
t_eq($by['medi']['ends_on'],     '2026-07-12', 'MEDI runs 12 teaching weeks');
t_eq($by['summer']['starts_on'], '2026-07-20', 'Summer starts after a one-week break');
t_eq($by['summer']['ends_on'],   '2026-09-13', 'Summer is the short term — 8 weeks');
t_eq($by['domi']['starts_on'],   '2026-09-21', 'DOMI starts after a one-week break');
t_eq($by['domi']['ends_on'],     '2026-12-13', 'DOMI runs 12 teaching weeks and closes the year');

// ends_on is the LAST TEACHING DAY (inclusive); _end is the day after. Getting
// these confused is how a term silently gains or loses a day.
t_eq($by['toni']['_end']->format('Y-m-d'), '2026-04-13', '_end is exclusive — the day after the last teaching day');
t_eq((new DateTimeImmutable($by['toni']['ends_on']))->diff($by['toni']['_start'])->days, 83, 'TONI spans 12 weeks minus a day, inclusive');
foreach ($w as $x) t_eq($x['source'], 'default', 'a computed window is marked source=default');

// Every gap between terms is exactly the declared break — no drift accumulates
// across the year from repeated week arithmetic.
t_eq((int) $by['toni']['_end']->diff($by['medi']['_start'])->days, 7, 'exactly one week between TONI and MEDI');
t_eq((int) $by['medi']['_end']->diff($by['summer']['_start'])->days, 7, 'exactly one week between MEDI and Summer');
t_eq((int) $by['summer']['_end']->diff($by['domi']['_start'])->days, 7, 'exactly one week between Summer and DOMI');

t_group('academic session — status classification');

$at = fn(string $d) => mam_session_current(new DateTimeImmutable($d), []);

$r = $at('2026-02-10');
t_eq($r['code'], 'toni', 'a mid-February date is in TONI');
t_eq($r['status'], 'active', 'and classes are running');

t_eq($at('2026-01-19')['status'], 'active', 'the first teaching day is already active');
t_eq($at('2026-04-12')['status'], 'active', 'the last teaching day is still active');
// One day past the last teaching day is a BREAK, not still-active. The
// exclusive _end is exactly what makes this land right.
$brk = $at('2026-04-13');
t_eq($brk['status'], 'break', 'the day after TONI ends is a break');
t_eq($brk['code'], 'medi', 'and the break resolves to the term about to start');

// Early January precedes the year's first term. It must read as "upcoming"
// rather than as a break — there is no term behind it in that year.
$up = $at('2026-01-05');
t_eq($up['code'], 'toni', 'early January points at TONI');
t_ok(in_array($up['status'], ['upcoming', 'break'], true), 'early January is pre-season, not active');

// The Dec/Jan gap is the case the three-year span exists for: after DOMI
// closes, the operative session is next year's TONI, not an "ended" year.
$gap = $at('2026-12-20');
t_eq($gap['code'], 'toni', 'after DOMI closes we look ahead to next TONI');
t_eq($gap['year'], 2027, 'and specifically to NEXT year — this is the wrap the 3-year span exists for');
t_ok($gap['status'] !== 'ended', 'the year end is never reported as "ended" to a visitor');

// 'ended' is reachable only when a caller hands over a single year's windows.
t_eq(mam_session_pick(mam_session_windows(2026, []), new DateTimeImmutable('2027-06-01'))['status'], 'ended', 'past a bounded window set, status is ended');

t_group('academic session — admin overrides');

$ov = ['toni' => ['2026' => ['starts_on' => '2026-02-02', 'ends_on' => '2026-04-24']]];
$o  = mam_session_windows(2026, $ov);
t_eq($o[0]['starts_on'], '2026-02-02', 'an override moves the start');
t_eq($o[0]['ends_on'],   '2026-04-24', 'ends_on in the override is the INCLUSIVE last teaching day');
t_eq($o[0]['_end']->format('Y-m-d'), '2026-04-25', 'and is stored internally as exclusive');
t_eq($o[0]['source'], 'admin', 'an overridden window is marked source=admin');
// An override to one term must cascade: later terms follow the moved end.
t_eq($o[1]['starts_on'], '2026-05-02', 'MEDI shifts to one week after the overridden TONI end');
t_eq($o[2]['source'], 'default', 'a term with no override of its own stays source=default');

// Integer year keys must work as well as string ones — JSON gives strings,
// a PHP array literal gives ints, and both reach this function.
$oInt = mam_session_windows(2026, ['toni' => [2026 => ['starts_on' => '2026-02-02']]]);
t_eq($oInt[0]['starts_on'], '2026-02-02', 'an integer year key resolves the same as a string one');

// A partial override sets only what it names.
$part = mam_session_windows(2026, ['medi' => ['2026' => ['ends_on' => '2026-08-01']]]);
t_eq($part[1]['starts_on'], '2026-04-20', 'a partial override leaves the unnamed bound computed');
t_eq($part[1]['ends_on'],   '2026-08-01', 'and applies the one it names');

// An override for a DIFFERENT year must not leak into this one.
t_eq(mam_session_windows(2026, ['toni' => ['2025' => ['starts_on' => '2025-03-03']]])[0]['starts_on'], '2026-01-19', 'an override for another year is ignored');

t_group('academic session — promotion window');

// While a term runs we promote the NEXT one; on a break we promote the term
// that is about to start (the one already resolved).
$act = $at('2026-02-10');
t_eq($act['next']['code'], 'medi', 'during TONI we promote MEDI');
t_eq($act['next']['starts_on'], '2026-04-20', 'with MEDI\'s real start date');

$onBreak = $at('2026-04-15');
t_eq($onBreak['code'], 'medi', 'a break resolves to the upcoming term');
t_eq($onBreak['next']['code'], 'medi', 'and promotes that same term rather than skipping ahead');

// promo_starts_on is exactly promo_lead_days before the start, whatever the
// setting is configured to — asserted as a relationship, not a literal, so the
// test does not break when an admin changes the lead time.
$lead = (int) $act['next']['promo_lead_days'];
t_ok($lead > 0, 'a promotion lead time is configured');
t_eq(
    $act['next']['promo_starts_on'],
    (new DateTimeImmutable($act['next']['starts_on']))->modify("-$lead days")->format('Y-m-d'),
    'promotion opens exactly promo_lead_days before the term starts'
);

// The flag flips on the boundary day, not after it.
$promoOpen = new DateTimeImmutable($act['next']['promo_starts_on']);
t_ok(mam_session_current($promoOpen, [])['next']['promoting'] === true, 'promotion is live on the day it opens');
t_ok(mam_session_current($promoOpen->modify('-1 day'), [])['next']['promoting'] === false, 'and not the day before');

t_group('academic session — overrides via the settings store');

// mam_session_overrides() is the settings-backed path the app actually uses.
mam_setting_set('academic_session_overrides', json_encode(['toni' => ['2026' => ['starts_on' => '2026-03-09']]]));
t_eq(mam_session_overrides()['toni']['2026']['starts_on'], '2026-03-09', 'overrides decode from the JSON setting');
t_eq(mam_session_current(new DateTimeImmutable('2026-03-10'))['starts_on'], '2026-03-09', 'and mam_session_current picks them up with no explicit argument');
// Malformed JSON must degrade to "no overrides", never throw — a bad paste in
// the admin form cannot be allowed to 500 the public apply page.
mam_setting_set('academic_session_overrides', '{not json');
t_eq(mam_session_overrides(), [], 'malformed override JSON degrades to no overrides');
mam_setting_set('academic_session_overrides', '');
t_eq(mam_session_overrides(), [], 'an empty setting means no overrides');
