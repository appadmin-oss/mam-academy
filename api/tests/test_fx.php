<?php
/** FX rate parsing — pure, no network. */
declare(strict_types=1);
t_group('fx parsing');

t_eq(mam_fx_parse_ngn('{"rates":{"NGN":1650.5,"USD":1}}'), 1650.5, 'open.er-api.com {rates} shape');
t_eq(mam_fx_parse_ngn('{"conversion_rates":{"NGN":1700}}'), 1700.0, 'exchangerate-API {conversion_rates} shape');
t_ok(mam_fx_parse_ngn('{"rates":{"USD":1,"GBP":0.8}}') === null, 'missing NGN → null');
t_ok(mam_fx_parse_ngn('not-json-at-all') === null, 'unparseable body → null');
t_ok(mam_fx_parse_ngn('{"rates":{"NGN":"oops"}}') === null, 'non-numeric NGN → null');

t_group('currency display-rate map');
$rates = mam_currency_rates(false); // read-only: must never touch the network
t_eq($rates['USD'], 1.0, 'USD is the exact base (1.0)');
t_ok(isset($rates['NGN'], $rates['GBP'], $rates['EUR']), 'map carries NGN, GBP and EUR');
t_ok($rates['NGN'] >= MAM_FX_MIN && $rates['NGN'] <= MAM_FX_MAX, 'NGN sits within sane bounds');
// The display NGN must equal the charge-time rate, or the homepage and the
// checkout would disagree — the whole point of one authoritative map.
t_eq($rates['NGN'], round(mam_usd_to_ngn_rate(false), 2), 'display NGN == charge-time USD→NGN');
t_ok(is_float($rates['GBP']) && $rates['GBP'] > 0, 'GBP is a positive display rate');
t_ok(is_float($rates['EUR']) && $rates['EUR'] > 0, 'EUR is a positive display rate');
