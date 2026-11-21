<?php
/**
 * The facts the redesigned public pages print and must not type (2026-11 site,
 * partials/site/): the intake being promoted and every fee. A fee printed on the
 * homepage, the Programmes table and the estimator comes from the same functions
 * that charge an application (validation.php), so the page and the charge cannot
 * disagree — the handoff's own fee-model.js is kept only as a test oracle
 * (docs/design/2026-11-site/specs/, and test_site_redesign.php checks the two agree).
 *
 * The names keep the `site_` prefix the templates use; they live here rather than
 * in the template shell so the suite can call them (the harness never loads
 * bootstrap.php, and the shell does).
 */
declare(strict_types=1);

/**
 * The intake the site is promoting: the next session to START, from the
 * academic calendar (authored terms first, computed windows otherwise) — never
 * a typed month, which is how the old homepage came to advertise September
 * after September had passed.
 */
function site_intake(): string {
    try {
        $s = mam_session_current();
        $n = $s['next'] ?? $s;
        $name = trim(preg_replace('/\s+term$/i', '', (string) ($n['label'] ?? '')));
        $label = trim($name . ' ' . (string) ($n['year'] ?? ''));
    } catch (Throwable $e) {
        $label = '';
    }
    return $label !== '' ? $label : 'Next';
}

/**
 * Every figure the fee estimator, the comparison table and the level cards print,
 * from the functions that charge them (validation.php). Whole dollars, as the
 * server rounds them.
 */
function site_fees(): array {
    $bases = mam_level_base_prices();
    $f = [
        'levels' => [
            ['id' => 'precert',    'name' => 'Pre-Certificate', 'grades' => '1–2', 'base' => (float) ($bases['precert'] ?? 120)],
            ['id' => 'cert',       'name' => 'Certificate',     'grades' => '3–4', 'base' => (float) ($bases['cert'] ?? 130)],
            ['id' => 'prediploma', 'name' => 'Pre-Diploma',     'grades' => '5–6', 'base' => (float) ($bases['prediploma'] ?? 160)],
            ['id' => 'diploma',    'name' => 'Diploma',         'grades' => '7–8', 'base' => (float) ($bases['diploma'] ?? 180)],
        ],
        'compulsory'   => (float) mam_compulsory_fee(),
        'grandPct'     => (float) mam_grand_discount_pct(),
        'onlinePct'    => (float) mam_online_surcharge_pct(),
        'crash'        => (float) mam_setting_num('price_crash', 70),
        'meta'         => (float) mam_setting_num('price_meta_order', 60),
        'registration' => (float) mam_registration_fee(),
        'from'         => mam_programme_from_prices(),
    ];
    try {
        $r = mam_currency_rates(false);
        $f['rates'] = ['USD' => 1.0, 'NGN' => (float) ($r['NGN'] ?? 0), 'GBP' => (float) ($r['GBP'] ?? 0)];
    } catch (Throwable $e) {
        $f['rates'] = ['USD' => 1.0];
    }
    return $f;
}

/** "$240" — whole dollars, the way the server rounds every price. */
function site_usd(float $v): string { return '$' . number_format(round($v), 0); }

/**
 * One printed fee, by name: level0..level3 (a level's base), from:<programme>,
 * crash, meta, reg, online ("20%"). Every figure a page prints goes through here.
 */
function site_fee(string $k): string {
    $f = site_fees();
    if (preg_match('/^level([0-3])$/', $k, $m)) return site_usd($f['levels'][(int) $m[1]]['base']);
    if (str_starts_with($k, 'from:')) return site_usd((float) ($f['from'][substr($k, 5)] ?? 0));
    return match ($k) {
        'crash'  => site_usd($f['crash']),
        'meta'   => site_usd($f['meta']),
        'reg'    => site_usd($f['registration']),
        'online' => rtrim(rtrim(number_format($f['onlinePct'], 1), '0'), '.') . '%',
        'grand'  => rtrim(rtrim(number_format($f['grandPct'], 1), '0'), '.') . '%',
        'compulsory' => site_usd($f['compulsory']),
        default  => throw new InvalidArgumentException("unknown fee $k"),
    };
}
