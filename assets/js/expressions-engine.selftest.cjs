/* Node self-test for the "One song, three expressions" arrangement engine.
   Run: node assets/js/expressions-engine.selftest.cjs   (exit 0 = pass; CI runs it) */
'use strict';
var E = require('./expressions-engine.js');
var fails = 0;
function ok(cond, msg) { if (!cond) { console.error('  ✗ ' + msg); fails++; } else { console.log('  ✓ ' + msg); } }
function eq(a, b, msg) { ok(JSON.stringify(a) === JSON.stringify(b), msg + ' (got ' + JSON.stringify(a) + ', want ' + JSON.stringify(b) + ')'); }
function song(id) { return E.SONGS.filter(function (s) { return s.id === id; })[0]; }
function transpose(s, k) { var c = JSON.parse(JSON.stringify(s)); c.notes.forEach(function (n) { if (n.midi != null) n.midi += k; }); return c; }

console.log('expressions engine self-test');

// ── Key detection ──
eq(E.detectKey(song('ode')).tonic + ':' + E.detectKey(song('ode')).mode, '0:major', 'Ode to Joy is in C major');
eq(E.detectKey(song('grace')).tonic + ':' + E.detectKey(song('grace')).mode, '7:major', 'Amazing Grace is in G major');
eq(E.detectKey(song('twinkle')).tonic, 0, 'Twinkle is in C');
eq(E.detectKey(transpose(song('ode'), 7)).tonic, 7, 'Ode to Joy transposed up a fifth is found in G');
var minorTune = { tempo: 90, timeSig: [4, 4], notes: [69, 72, 71, 69, 64, 65, 64, 62, 60, 62, 64, 65, 64, 62, 64, 69].map(function (m, i) { return { midi: m, beats: i === 15 ? 2 : (i % 4 === 3 ? 1 : 1) }; }) };
eq(E.detectKey(minorTune).mode, 'minor', 'a tune that centres on A and ends on it reads as minor');

// ── Cadences ──
var authentic = 0;
E.SONGS.forEach(function (s) {
  var c = E.classical(s), last = c.chords[c.chords.length - 1], before = c.chords[c.chords.length - 2];
  ok(last.rn === 'I', s.title + ': classical ends on I');
  ok(before.rn === 'V' || before.rn === 'IV', s.title + ': classical approaches the close from V, or IV where the tune rules V out (' + before.rn + (before.figure || '') + ')');
  if (before.rn === 'V') authentic++;
  var p = c.analysis.phrases;
  p.forEach(function (ph) {
    var atEnd = c.chords.filter(function (x) { return x.bar === ph.endBar; }).pop();
    ok(atEnd && (atEnd.rn === 'I' || atEnd.rn === 'V'), s.title + ': phrase ending bar ' + (ph.endBar + 1) + ' closes on I or V');
  });
  var k = E.contemporary(s), kl = k.chords[k.chords.length - 1];
  ok(kl.symbol.indexOf(E.pcName(k.key.tonic, k.key.flat)) === 0, s.title + ': contemporary ends on the tonic (' + kl.symbol + ')');
});

ok(authentic >= 3, 'most songs close with an authentic V–I cadence (' + authentic + ' of ' + E.SONGS.length + ')');

// ── Classical voice-leading ──
E.SONGS.forEach(function (s) {
  var v = E.classical(s).voices, par = 0, crossed = 0, ltDoubled = 0, lt = (E.detectKey(s).tonic + 11) % 12;
  for (var i = 1; i < v.length; i++) if (E.parallel(v[i - 1].S, v[i].S, v[i - 1].B, v[i].B)) par++;
  v.forEach(function (x) {
    if (!(x.S > x.A && x.A >= x.T && x.T > x.B)) crossed++;
    if ([x.S, x.A, x.T, x.B].filter(function (m) { return m % 12 === lt; }).length > 1) ltDoubled++;
  });
  eq(par, 0, s.title + ': no parallel fifths or octaves between soprano and bass');
  eq(crossed, 0, s.title + ': voices stay in order (S > A ≥ T > B)');
  eq(ltDoubled, 0, s.title + ': the leading tone is never doubled');
});
// Every voice of every chord is a tone of that chord, in any key (a C-only test hid a bug once).
[song('grace'), transpose(song('ode'), 2), transpose(song('twinkle'), -3)].forEach(function (s) {
  var c = E.classical(s), tonic = c.key.tonic, wrong = 0;
  c.voices.forEach(function (v, i) {
    var ch = c.chords[i], third = ch.q === 'maj' ? 4 : 3, sev = ch.seventh ? [10] : [];
    var pcs = [0, third, 7].concat(sev).map(function (x) { return (x + ch.root + tonic) % 12; });
    [v.A, v.T, v.B].forEach(function (m) { if (pcs.indexOf(m % 12) < 0) wrong++; });
  });
  eq(wrong, 0, (s.title || 'transposed') + ' in ' + c.key.name + ': alto, tenor and bass are all chord tones');
});

// ── Contemporary chord symbols ──
var SYM = /^[A-G](♯|♭)?(maj9|maj7|6\/9|m9|m7|13|9sus4|9|°7)$/;
E.SONGS.forEach(function (s) {
  var bad = E.contemporary(s).chords.map(function (c) { return c.symbol; }).filter(function (x) { return !SYM.test(x); });
  eq(bad, [], s.title + ': every chord symbol is well formed');
});

// ── The MAM 6-line staff: 6 lines, 5 spaces, doh on the bottom line ──
eq(E.SIX_LINE, ['d', 'r', 'm', 'f', 's', 'l', 't', "d'", "r'", "m'", "f'"], 'eleven positions, bottom to top');
var doh = 55; // G3
var want = { 0: 0, 2: 1, 4: 2, 5: 3, 7: 4, 9: 5, 11: 6, 12: 7, 14: 8, 16: 9, 17: 10 };
Object.keys(want).forEach(function (semi) { eq(E.sixLinePosition(doh + Number(semi), doh).pos, want[semi], 'degree +' + semi + ' sits at position ' + want[semi]); });
eq([0, 2, 4, 6, 8, 10].map(E.isLine), [true, true, true, true, true, true], 'd m s t r\' f\' are the six lines');
eq([1, 3, 5, 7, 9].map(E.isLine), [false, false, false, false, false], 'r f l d\' m\' are the five spaces');
eq(E.solfaName(-2), 'l,', 'below doh: low la with a comma');
eq(E.sixLinePosition(doh + 1, doh), { pos: 0, sharp: true }, 'a chromatic note is written on the degree below, raised');

// ── African arrangement ──
E.SONGS.forEach(function (s) {
  var a = E.african(s), per = a.pulsesPerBeat;
  ok(a.staff.every(function (n) { return Number.isInteger(n.pulse) && Number.isInteger(n.len) && n.len >= 1; }), s.title + ': every note sits on the pulse grid');
  var src = s.notes.filter(function (n) { return n.midi != null; }).map(function (n) { return n.midi; });
  var lead = a.staff.filter(function (n) { return n.part !== 'chorus'; }).map(function (n) { return n.midi; });
  eq(lead.slice(0, src.length), src, s.title + ': the African version keeps every note of the tune (it re-feels, never rewrites)');
  eq(a.bell.length, a.cycles * 7, s.title + ': the 7-stroke bell sounds in every 12-pulse cycle');
  eq(a.bell.slice(0, 7), E.BELL, s.title + ': bell = standard pattern x.x.xx.x.x.x');
  ok(a.staff.some(function (n) { return n.part === 'response'; }) || a.analysis.phrases.length < 2, s.title + ': phrases alternate call and response');
});

// ── Rhythm → written note values ──
function toks(evs, o) { var out = []; E.notate(evs, o).forEach(function (m) { m.tokens.forEach(function (t) { out.push((t.rest ? 'r' : '') + t.base + (t.dots ? '.' : '') + (t.tieNext ? '~' : '')); }); }); return out; }
eq(toks([{ start: 0, len: 4, midi: 60 }], { barTicks: 12, compound: true, endTick: 12 }), ['q.~', '8', 'rq', 'rh.'], '12/8: four pulses = dotted quarter tied to an eighth; the rest completes the beat, then a dotted-half rest');
eq(toks([{ start: 10, len: 4, midi: 60 }], { barTicks: 12, compound: true, endTick: 24 }), ['rh.', 'rq.', 'r8', 'q~', 'q', 'r8', 'rq.', 'rh.'], '12/8: a note across the bar line is split and tied');
var beams = E.notate([0, 1, 2, 3, 4, 5].map(function (p) { return { start: p, len: 1, midi: 60 }; }), { barTicks: 12, compound: true, endTick: 12 })[0].tokens.filter(function (t) { return !t.rest; }).map(function (t) { return t.beam; });
ok(beams[0] === beams[2] && beams[3] === beams[5] && beams[0] !== beams[3], '12/8: eighths beam in groups of three (dotted-quarter beats)');
eq(toks([{ start: 2, len: 3, midi: 60 }], { barTicks: 8, endTick: 8 }), ['rq', 'q~', '8', 'r8', 'rq'], '4/4: a dotted quarter may not cross the middle of the bar, and rests never syncopate');
eq(toks([{ start: 0, len: 6, midi: 60 }], { barTicks: 8, endTick: 8 }), ['h.', 'rq'], '4/4: a dotted half on the downbeat');
eq(toks([{ start: 1, len: 2, midi: 60 }], { barTicks: 8, endTick: 8 }), ['r8', 'q', 'r8', 'rh'], '4/4: a syncopated quarter inside the half bar');
eq(E.notate([{ start: 4, len: 2, midi: 62 }], { barTicks: 6, anacrusis: 2, endTick: 6 })[0].start, 4, 'an anacrusis bar starts at the pickup, without leading rests');

// ── Spelling for the five-line staves ──
var G = { tonic: 7, mode: 'major' }, F = { tonic: 5, mode: 'major' }, C = { tonic: 0, mode: 'major' };
eq([E.spell(66, G).letter, E.spell(66, G).acc, E.spell(66, G).keyAcc], [3, 1, 1], 'F♯ in G major is F with the key\'s sharp');
eq([E.spell(65, G).letter, E.spell(65, G).acc], [3, 0], 'F natural in G major needs a natural sign');
eq([E.spell(70, F).letter, E.spell(70, F).acc], [6, -1], 'B♭ in F major');
eq([E.spell(61, C).letter, E.spell(61, C).acc], [0, 1], 'C♯ in C major is a raised C');
eq(E.keySignature({ tonic: 2, mode: 'major' }).count, 2, 'D major has two sharps');
eq([E.spell(64, C).step, E.spell(43, C).step, E.spell(71, C).step], [30, 18, 34], 'staff steps: E4 is the bottom treble line, G2 the bottom bass line, B4 the middle line');
eq(E.keySignature({ tonic: 9, mode: 'minor' }).count, 0, 'A minor has none');

if (fails) { console.error('\n' + fails + ' failure(s)'); process.exit(1); }
console.log('\nall passed');
