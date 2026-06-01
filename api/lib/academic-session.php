<?php
/**
 * Academic-session engine
 * ─────────────────────────────────────────────────────────────
 * Resolves the operative academic session (TONI · MEDI · Summer · DOMI) from
 * the calendar, so the application form never has to ask for it and the rest of
 * the site can show "current / upcoming session" consistently.
 *
 * Default term schedule, per academic year:
 *   TONI    — first Monday on/after Jan 15 · 12 teaching weeks · then 1 wk break
 *   MEDI    — right after the TONI break    · 12 teaching weeks · then 1 wk break
 *   SUMMER  — right after the MEDI break     ·  8 teaching weeks · then 1 wk break
 *   DOMI    — right after the Summer break    · 12 teaching weeks
 *
 * Admin overrides (Phase 3) may supply explicit dates via the
 * 'academic_session_overrides' setting — a JSON map keyed by session code then
 * year, e.g.  { "toni": { "2026": { "starts_on":"2026-01-19","ends_on":"2026-04-10" } } }
 * When a session has no override its dates compute deterministically here.
 *
 * Nothing in this file touches the DB schema; overrides ride on the existing
 * key/value settings store, so the engine ships without a migration.
 */
declare(strict_types=1);

/** Ordered term definitions. 'break' = weeks of break that follow the term. */
const MAM_SESSION_DEFS = [
    ['code' => 'toni',   'label' => 'TONI Term',   'weeks' => 12, 'break' => 1],
    ['code' => 'medi',   'label' => 'MEDI Term',   'weeks' => 12, 'break' => 1],
    ['code' => 'summer', 'label' => 'Summer Term', 'weeks' => 8,  'break' => 1],
    ['code' => 'domi',   'label' => 'DOMI Term',   'weeks' => 12, 'break' => 0],
];

/** First Monday on or after the given date (returns the date itself if Monday). */
function mam_first_monday_on_or_after(DateTimeImmutable $d): DateTimeImmutable {
    $dow = (int)$d->format('N');           // 1 = Mon … 7 = Sun
    return $dow === 1 ? $d : $d->modify('+' . (8 - $dow) . ' days');
}

/** Admin date overrides from settings; [] when none configured. */
function mam_session_overrides(): array {
    $raw = mam_setting('academic_session_overrides', null);
    if (is_string($raw) && $raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) return $j;
    }
    return is_array($raw) ? $raw : [];
}

/**
 * Build the four session windows for one academic year, applying any overrides.
 * Each window carries DateTimeImmutable `_start` (inclusive) and `_end`
 * (exclusive — the day after the last teaching day) for internal comparison;
 * those are stripped before a window is returned to a caller.
 *
 * @return array<int,array<string,mixed>>
 */
function mam_session_windows(int $year, array $overrides = []): array {
    $cursor = mam_first_monday_on_or_after(new DateTimeImmutable("$year-01-15"));
    $out = [];
    foreach (MAM_SESSION_DEFS as $def) {
        $start  = $cursor;
        $end    = $start->modify('+' . $def['weeks'] . ' weeks'); // exclusive
        $source = 'default';

        $ov = $overrides[$def['code']][$year] ?? ($overrides[$def['code']][(string)$year] ?? null);
        if (is_array($ov)) {
            if (!empty($ov['starts_on'])) { $start = new DateTimeImmutable((string)$ov['starts_on']); $source = 'admin'; }
            if (!empty($ov['ends_on']))   { $end   = (new DateTimeImmutable((string)$ov['ends_on']))->modify('+1 day'); $source = 'admin'; }
        }

        $out[] = [
            'code'      => $def['code'],
            'label'     => $def['label'],
            'year'      => $year,
            'starts_on' => $start->format('Y-m-d'),
            'ends_on'   => $end->modify('-1 day')->format('Y-m-d'), // inclusive last teaching day
            'source'    => $source,
            '_start'    => $start,
            '_end'      => $end,
        ];
        // Next term begins after this term's break.
        $cursor = $end->modify('+' . $def['break'] . ' weeks');
    }
    return $out;
}

/**
 * Resolve the operative session for `$now`.
 * Returns: code, label, year, starts_on, ends_on, status, source, and a `next`
 * block describing the session being promoted (with promo-window state).
 *
 *   status: 'active'  — classes are running
 *           'upcoming'— before the year's first term starts
 *           'break'   — between two terms
 *           'ended'   — past the year's last term (rare; caller extends years)
 */
function mam_session_current(?DateTimeInterface $now = null, ?array $overrides = null): array {
    $now       = $now ? DateTimeImmutable::createFromInterface($now) : new DateTimeImmutable('today');
    $overrides = $overrides ?? mam_session_overrides();
    $year      = (int)$now->format('Y');

    // Span three years (prev · current · next) so that whatever the date — the
    // Dec/Jan gap, mid-term, or the active DOMI looking ahead to next TONI — the
    // operative term AND the session being promoted are always in view.
    $windows = array_merge(
        mam_session_windows($year - 1, $overrides),
        mam_session_windows($year,     $overrides),
        mam_session_windows($year + 1, $overrides)
    );

    return mam_session_pick($windows, $now);
}

/** Walk windows (ordered by start) and classify `$now` against them. */
function mam_session_pick(array $windows, DateTimeImmutable $now): array {
    $n = count($windows);
    for ($i = 0; $i < $n; $i++) {
        $w = $windows[$i];
        if ($now >= $w['_start'] && $now < $w['_end']) {
            return mam_session_shape($w, 'active', $windows[$i + 1] ?? null, $now);
        }
        if ($now < $w['_start']) {
            // We've reached a term that hasn't started, having passed any earlier
            // ones — so we're either pre-season (i = 0) or mid-break.
            return mam_session_shape($w, $i === 0 ? 'upcoming' : 'break', $windows[$i + 1] ?? null, $now);
        }
    }
    return mam_session_shape($windows[$n - 1], 'ended', null, $now);
}

/** Normalise a window into the public shape, attaching promo state. */
function mam_session_shape(array $w, string $status, ?array $next, DateTimeImmutable $now): array {
    $res = [
        'code'      => $w['code'],
        'label'     => $w['label'],
        'year'      => $w['year'],
        'starts_on' => $w['starts_on'],
        'ends_on'   => $w['ends_on'],
        'status'    => $status,
        'source'    => $w['source'] ?? 'default',
    ];

    // The session being promoted is the next one to START: if a term is running
    // we promote whatever follows it; otherwise we promote the resolved term.
    $promo = $status === 'active' ? $next : $w;
    if ($promo) {
        $lead       = (int)mam_setting_num('session_promo_lead_days', 14);
        $startDt    = $promo['_start'] ?? new DateTimeImmutable($promo['starts_on']);
        $promoStart = $startDt->modify("-$lead days");
        $res['next'] = [
            'code'            => $promo['code'],
            'label'           => $promo['label'],
            'year'            => $promo['year'],
            'starts_on'       => $promo['starts_on'],
            'promo_lead_days' => $lead,
            'promo_starts_on' => $promoStart->format('Y-m-d'),
            'promoting'       => $now >= $promoStart,
        ];
    }
    return $res;
}
