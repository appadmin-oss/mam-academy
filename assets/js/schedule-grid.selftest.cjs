/**
 * Headless verification of the schedule grid's DATE MATH (§4.3).
 *
 *   node assets/js/schedule-grid.selftest.cjs
 *
 * This is the half that decides WHERE a session lands and WHAT time a drop
 * means. Getting it wrong moves a real class: a week that starts on Sunday
 * shifts every card one column, and a naive `new Date('2026-08-15 09:00:00')`
 * either throws or silently applies the viewer's UTC offset, turning a 09:00
 * Lagos class into an 08:00 London one on the way to the server.
 *
 * The DOM half (dragstart/dragover/drop) needs a browser and is verified there.
 */
'use strict';
const path = require('path');
const S = require(path.join(__dirname, 'schedule-grid.js'));
const I = S._internals;
if (!I) { console.error('FATAL: _internals not exposed'); process.exit(1); }
const { parseNaive, fmtNaive, fmtDate, addDays, weekStart, dayIndex, slotDateTime, minutesOfDay, overlaps, layout, hourLabel } = I;

let pass = 0, fail = 0;
function ck(cond, msg) { if (cond) { pass++; console.log('  ✓ ' + msg); } else { fail++; console.log('  ✗ FAIL: ' + msg); } }

console.log('▸ parseNaive — strict, zone-free');
ck(fmtNaive(parseNaive('2026-08-15 09:30:00')) === '2026-08-15 09:30:00', 'a full datetime round-trips unchanged');
ck(fmtNaive(parseNaive('2026-08-15 09:30')) === '2026-08-15 09:30:00', 'seconds default to 00');
ck(fmtNaive(parseNaive('2026-08-15')) === '2026-08-15 00:00:00', 'a bare date is midnight');
ck(fmtNaive(parseNaive('2026-08-15T09:30:00')) === '2026-08-15 09:30:00', 'the T separator is accepted (datetime-local inputs emit it)');
// The whole point: no zone shifting, ever. 09:00 in must be 09:00 out
// regardless of where the browser thinks it is.
ck(parseNaive('2026-08-15 09:00:00').hh === 9, 'the hour survives parsing verbatim — no UTC conversion');
ck(parseNaive('') === null, 'an empty string is null, not today');
ck(parseNaive(null) === null, 'null input is null');
ck(parseNaive('not a date') === null, 'garbage is null');
ck(parseNaive('2026-13-01') === null, 'month 13 is rejected');
ck(parseNaive('2026-02-30') === null, 'a date the calendar does not have is rejected');
ck(parseNaive('2026-08-15 24:00') === null, 'hour 24 is rejected');
ck(parseNaive('2026-08-15 09:60') === null, 'minute 60 is rejected');
ck(parseNaive('2026-02-29') === null, '29 Feb in a non-leap year is rejected');
ck(fmtNaive(parseNaive('2024-02-29')) === '2024-02-29 00:00:00', 'but 29 Feb in a leap year is fine');

console.log('▸ addDays — real calendar arithmetic');
ck(fmtDate(addDays(parseNaive('2026-08-15'), 1)) === '2026-08-16', 'plus one day');
ck(fmtDate(addDays(parseNaive('2026-08-31'), 1)) === '2026-09-01', 'rolls the month');
ck(fmtDate(addDays(parseNaive('2026-12-31'), 1)) === '2027-01-01', 'rolls the year');
ck(fmtDate(addDays(parseNaive('2026-01-01'), -1)) === '2025-12-31', 'rolls backwards across the year');
ck(fmtDate(addDays(parseNaive('2024-02-28'), 1)) === '2024-02-29', 'knows about leap days');
ck(addDays(parseNaive('2026-08-15 09:30:00'), 1).hh === 9, 'the time of day rides along unchanged');

console.log('▸ weekStart — ISO weeks start MONDAY');
// 2026-08-15 is a Saturday; its ISO week began Monday 2026-08-10.
ck(fmtDate(weekStart('2026-08-15')) === '2026-08-10', 'Saturday resolves back to its Monday');
ck(fmtDate(weekStart('2026-08-10')) === '2026-08-10', 'a Monday is its own week start');
// The trap: Sunday belongs to the week that STARTED, not the one about to.
// Treating Sunday as day 0 shifts every card a column and moves a class.
ck(fmtDate(weekStart('2026-08-16')) === '2026-08-10', 'Sunday belongs to the week just ending, not the next one');
ck(fmtDate(weekStart('2026-08-17')) === '2026-08-17', 'the following Monday starts a new week');
ck(fmtDate(weekStart('2026-01-01')) === '2025-12-29', 'a week start can fall in the previous year');
ck(weekStart('rubbish') === null, 'an unparseable anchor is null');

console.log('▸ dayIndex — column placement');
const ws = weekStart('2026-08-15');
ck(dayIndex(ws, '2026-08-10 09:00') === 0, 'Monday is column 0');
ck(dayIndex(ws, '2026-08-15 09:00') === 5, 'Saturday is column 5');
ck(dayIndex(ws, '2026-08-16 23:59') === 6, 'Sunday is column 6, still inside the week');
ck(dayIndex(ws, '2026-08-17 00:00') === -1, 'the next Monday is outside');
ck(dayIndex(ws, '2026-08-09 23:59') === -1, 'the previous Sunday is outside');
ck(dayIndex(ws, 'nonsense') === -1, 'an unparseable date is outside');

console.log('▸ slotDateTime — what a drop means');
ck(slotDateTime(ws, 0, 9, 0) === '2026-08-10 09:00:00', 'Monday 9am');
ck(slotDateTime(ws, 6, 20, 0) === '2026-08-16 20:00:00', 'Sunday 8pm');
ck(slotDateTime(ws, 3, 0, 0) === '2026-08-13 00:00:00', 'midnight is a valid slot');
ck(slotDateTime(ws, 2, 14, 30) === '2026-08-12 14:30:00', 'a minute offset is honoured');
ck(slotDateTime(ws, -1, 9, 0) === null, 'a negative day is refused');
ck(slotDateTime(ws, 7, 9, 0) === null, 'day 7 is off the grid');
ck(slotDateTime(ws, 0, 24, 0) === null, 'hour 24 is refused');
ck(slotDateTime(null, 0, 9, 0) === null, 'no week start means no slot');

console.log('▸ minutesOfDay + overlaps');
ck(minutesOfDay('2026-08-15 09:30') === 570, '09:30 is 570 minutes in');
ck(minutesOfDay('2026-08-15 00:00') === 0, 'midnight is 0');
ck(minutesOfDay('bad') === null, 'unparseable is null');
ck(overlaps('2026-08-15 09:00', 60, '2026-08-15 09:30', 60) === true, 'a half-hour stagger overlaps');
ck(overlaps('2026-08-15 09:00', 60, '2026-08-15 10:00', 60) === false, 'back-to-back does NOT overlap');
ck(overlaps('2026-08-15 09:00', 90, '2026-08-15 10:00', 60) === true, 'a 90-minute class runs into the next hour');
ck(overlaps('2026-08-15 09:00', 60, '2026-08-16 09:00', 60) === false, 'the same time on a different day is not a clash');

console.log('▸ layout — bucketing, and never losing a session');
const sessions = [
  { id: 1, title: 'Mon 9',       scheduled_at: '2026-08-10 09:00:00' },
  { id: 2, title: 'Mon 9 again', scheduled_at: '2026-08-10 09:45:00' },
  { id: 3, title: 'Sat 6pm',     scheduled_at: '2026-08-15 18:00:00' },
  { id: 4, title: 'Unscheduled', scheduled_at: null },
  { id: 5, title: 'Next week',   scheduled_at: '2026-08-20 09:00:00' },
  { id: 6, title: 'Dawn class',  scheduled_at: '2026-08-11 06:00:00' },
  { id: 7, title: 'Late class',  scheduled_at: '2026-08-12 23:00:00' }
];
const L = layout(sessions, ws, 8, 20);
ck(L.cells['0:9'].length === 2, 'two sessions in the same hour share a cell');
ck(L.cells['5:18'][0].id === 3, 'Saturday 6pm lands in column 5, hour 18');
ck(L.outside.some(s => s.id === 4), 'an unscheduled session is listed, not dropped');
ck(L.outside.some(s => s.id === 5), 'a session in another week is listed, not dropped');
ck(L.outside.length === 2, 'and nothing else is exiled');
// Out-of-band sessions clamp into the visible grid rather than vanishing —
// silently hiding a 6am class is how one gets forgotten about entirely.
ck(L.cells['1:8'] && L.cells['1:8'][0].id === 6, 'an early class clamps to the first visible hour');
ck(L.cells['2:20'] && L.cells['2:20'][0].id === 7, 'a late class clamps to the last visible hour');
const total = Object.keys(L.cells).reduce((n, k) => n + L.cells[k].length, 0) + L.outside.length;
ck(total === sessions.length, 'every session is accounted for exactly once');
ck(layout(null, ws, 8, 20).outside.length === 0, 'a null session list is empty, not a crash');

console.log('▸ hourLabel');
ck(hourLabel(0) === '12am', 'midnight reads 12am, not 0am');
ck(hourLabel(12) === '12pm', 'noon reads 12pm, not 0pm');
ck(hourLabel(9) === '9am' && hourLabel(21) === '9pm', 'morning and evening hours');

console.log('\n' + (fail ? '❌ ' + fail + ' FAILED' : '✅ ALL ' + pass + ' PASSED'));
process.exit(fail ? 1 : 0);
