<?php
/** FX rate parsing — pure, no network. */
declare(strict_types=1);
t_group('fx parsing');

t_eq(mam_fx_parse_ngn('{"rates":{"NGN":1650.5,"USD":1}}'), 1650.5, 'open.er-api.com {rates} shape');
t_eq(mam_fx_parse_ngn('{"conversion_rates":{"NGN":1700}}'), 1700.0, 'exchangerate-API {conversion_rates} shape');
t_ok(mam_fx_parse_ngn('{"rates":{"USD":1,"GBP":0.8}}') === null, 'missing NGN → null');
t_ok(mam_fx_parse_ngn('not-json-at-all') === null, 'unparseable body → null');
t_ok(mam_fx_parse_ngn('{"rates":{"NGN":"oops"}}') === null, 'non-numeric NGN → null');
