/* node specs/fee-model.test.js — check values from the design handoff. */
const { quote, format } = require('./fee-model');
const eq = (a, b, m) => { if (a !== b) { console.error('FAIL', m, a, '!==', b); process.exitCode = 1; } else console.log('ok  ', m, '=', a); };
eq(format(quote({ programme: 'certified' }).total), '$240', 'Certified, Pre-Certificate, in person');
eq(format(quote({ programme: 'grand' }).total), '$313', 'Grand, Pre-Certificate');
eq(format(-quote({ programme: 'grand' }).lines.at(-1).v), '$47', 'Grand saving');
eq(format(quote({ programme: 'standard', extras: 2, mode: 'online' }).total), '$288', 'Standard + 2 courses, online');
eq(format(quote({ programme: 'crash', mode: 'online' }).total), '$70', 'Crash (no surcharge)');
eq(format(quote({ programme: 'certified', level: 3 }).total, 'NGN'), '₦450,000', 'Certified Diploma in NGN');
