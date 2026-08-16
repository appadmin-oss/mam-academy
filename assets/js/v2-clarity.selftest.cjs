/* =============================================================================
   v2-clarity.js — executed self-test (CI runs this; `node --check` only parses)
   =============================================================================
   The renderers are where escaping, colour-independence and the empty state
   either hold or quietly do not. Each assertion below corresponds to a way a
   reader gets misled, not to a line of code.
   ========================================================================== */
'use strict';
const C = require('./v2-clarity.js');

let pass = 0, fail = 0;
function ok(cond, what) { if (cond) { pass++; } else { fail++; console.error('  ✗ ' + what); } }
function eq(a, b, what) { ok(a === b, what + '\n      got: ' + JSON.stringify(a) + '\n     want: ' + JSON.stringify(b)); }
function group(t) { console.log('▸ ' + t); }

/* ── escaping ─────────────────────────────────────────────────────────────── */
group('escaping — status wording is tenant-editable, so it is untrusted');

eq(C.esc('<script>alert(1)</script>'), '&lt;script&gt;alert(1)&lt;/script&gt;', 'tags are escaped');
eq(C.esc('a & b'), 'a &amp; b', 'ampersands are escaped');
eq(C.esc('"x" \'y\''), '&quot;x&quot; &#39;y&#39;', 'both quote kinds are escaped (attribute context)');
eq(C.esc(null), '', 'null renders empty, not "null"');
eq(C.esc(0), '0', 'but zero renders as zero');

const hostile = C.chip({ label: '<img src=x onerror=alert(1)>', tone: 'neutral' });
ok(hostile.indexOf('<img') === -1, 'a hostile label cannot inject an element');
ok(hostile.indexOf('&lt;img') !== -1, '…it is shown as text instead');

group('URLs — an action_url is tenant-editable too');
eq(C.safeUrl('javascript:alert(1)'), '#', 'javascript: is neutralised');
eq(C.safeUrl('  JaVaScRiPt:alert(1)'), '#', '…whatever the casing or padding');
eq(C.safeUrl('data:text/html,<script>'), '#', 'data: is neutralised');
eq(C.safeUrl('//evil.test/x'), '//evil.test/x', 'a protocol-relative URL is allowed (it is https in practice)');
eq(C.safeUrl('/portal/payments'), '/portal/payments', 'a root-relative path is kept');
eq(C.safeUrl('https://mam.test/x'), 'https://mam.test/x', 'an https URL is kept');
eq(C.safeUrl('/\\evil.test'), '#', 'a backslash-smuggled host is refused');
eq(C.safeUrl(''), '', 'an empty URL stays empty so no button is rendered');

/* ── colour independence ──────────────────────────────────────────────────── */
group('nothing is signalled by colour alone');

Object.keys(C.TONES).forEach(function (t) {
  const html = C.chip({ label: 'X', tone: t });
  const info = C.TONES[t];
  ok(html.indexOf(info.glyph) !== -1, t + ': carries a glyph as well as a colour');
  ok(html.indexOf('aria-label="' + info.word + ': X"') !== -1,
     t + ': the severity is in the accessible name, not only in the hue');
});
ok(C.chip({ label: 'X', tone: 'nonsense' }).indexOf('data-tone="nonsense"') !== -1,
   'an unknown tone still renders (a portal must not blank out over a typo)');
eq(C.tone('nonsense').glyph, C.TONES.neutral.glyph, '…falling back to neutral');
ok(C.chip({}).indexOf('Not set') !== -1, 'a chip with no status says so rather than rendering empty');

/* ── the explain panel ────────────────────────────────────────────────────── */
group('explain — "nothing to do" is an answer, not a blank');

const withNext = C.explain({ label: 'Due', meaning: 'This is owed.', next_step: 'Pay before Friday.', tone: 'attention' });
ok(withNext.indexOf('What to do next') !== -1, 'the next step is labelled');
ok(withNext.indexOf('Pay before Friday.') !== -1, '…and shown');

const noNext = C.explain({ label: 'Paid', meaning: 'Settled in full.', next_step: null, tone: 'positive' });
ok(noNext.indexOf('Nothing is needed from you') !== -1,
   'a null next step is SAID, because a blank there reads as missing information');
ok(noNext.indexOf('mam-clarity__next--none') !== -1, '…and is styled as the quiet variant');
ok(C.explain({ label: 'Paid', tone: 'positive' }, { showNoAction: false }).indexOf('Nothing is needed') === -1,
   'a caller with its own layout can suppress it');

const withReason = C.explain({ label: 'Cancelled', meaning: 'This class is not going ahead.', tone: 'warning' },
                             { reason: 'Instructor unwell' });
ok(withReason.indexOf('Instructor unwell') !== -1, 'the recorded reason is surfaced to the reader');
ok(withReason.indexOf('<strong>Why:</strong>') !== -1, '…and introduced, not dropped in bare');
ok(C.explain({ label: 'X', tone: 'neutral' }, { reason: '<b>x</b>' }).indexOf('<b>x</b>') === -1,
   'a reason is escaped like anything else');

/* ── due dates ────────────────────────────────────────────────────────────── */
group('due dates read the way a person would say them');

eq(C.dueText(0), 'Due today', 'today');
eq(C.dueText(1), 'Due tomorrow', 'tomorrow');
eq(C.dueText(3), 'Due in 3 days', 'this week');
eq(C.dueText(14), 'Due in 2 weeks', 'beyond a week, in weeks');
eq(C.dueText(7), 'Due in 7 days', 'exactly a week stays in days (clearer than "1 week")');
// Overdue must never render as "in -2 days", and must never collapse into
// "Due today" — the difference is whether someone has already missed it.
eq(C.dueText(-1), 'Overdue by 1 day', 'one day late is singular');
eq(C.dueText(-2), 'Overdue by 2 days', 'and later is plural and positive-signed');
eq(C.dueText(null), '', 'an undated item says nothing rather than guessing');
eq(C.dueText(undefined), '', '…including when the field is absent');

group('the headline');
eq(C.summaryText({ total: 0 }), 'You are all caught up', 'nothing to do is phrased as relief, not as zero');
eq(C.summaryText({ total: 1 }), 'You have 1 thing to do', 'one is singular');
eq(C.summaryText({ total: 3 }), 'You have 3 things to do', 'many is plural');
eq(C.summaryText({ total: 3, overdue: 1 }), 'You have 3 things to do, 1 overdue', 'overdue is called out');
eq(C.summaryText(), 'You are all caught up', 'a missing summary does not render "undefined"');

/* ── the list ─────────────────────────────────────────────────────────────── */
group('the to-do list');

const empty = C.todoList([], { total: 0 });
ok(empty.indexOf('mam-todo__empty') !== -1, 'the empty state is a real render, not a fallthrough');
ok(empty.indexOf('Nothing needs your attention') !== -1, '…and says so in words');
ok(empty.indexOf('<ul') === -1, '…with no empty list left behind for a screen reader to announce');

const items = [
  { title: 'Read the policy', is_blocking: true, dedupe_key: 'p:1' },
  { title: 'Pay your tuition', detail: 'Place held until 30 September.', action_url: '/portal/payments',
    action_label: 'Pay ₦120,000', is_overdue: true, due_in_days: -3, dedupe_key: 'i:1' }
];
const list = C.todoList(items, { total: 2, overdue: 1, blocking: 1 });
ok(list.indexOf('data-blocking="1"') !== -1, 'a blocking item is marked for styling');
ok(list.indexOf('data-overdue="1"') !== -1, 'an overdue one too');
ok(list.indexOf('Overdue by 3 days') !== -1, '…and says how late it is');
ok(list.indexOf('Pay ₦120,000') !== -1, 'the button carries the real action, not "Open"');
ok(list.indexOf('aria-label="Pay ₦120,000 — Pay your tuition"') !== -1,
   'the link names its task — six links all called "Pay" tell a screen-reader user nothing');
ok(list.indexOf('aria-live="polite"') !== -1, 'resolving an item in place is announced, not silent');
ok(list.indexOf('href="/portal/payments"') !== -1, 'a safe URL survives');
ok(C.todoList([{ title: 'x', action_url: 'javascript:alert(1)', action_label: 'Go' }]).indexOf('javascript:') === -1,
   'a hostile action_url never reaches href');
ok(C.todoList([{ title: 'No link here' }]).indexOf('mam-todo__cta') === -1,
   'an item with no URL renders no dead button');

/* ── time ─────────────────────────────────────────────────────────────────── */
group('time — the number must be right before the styling matters');

const w = C.when({ text: '10 Sep 2026, 16:00', zone: 'Africa/Lagos', also: '11:00 your time' });
ok(w.indexOf('16:00') !== -1, "the class's own clock leads");
ok(w.indexOf('Africa/Lagos') !== -1, '…named, so it is unambiguous');
ok(w.indexOf('11:00 your time') !== -1, "…and the viewer's time follows");
ok(C.when({ text: '16:00', zone: 'Africa/Lagos', also: null }).indexOf('your time') === -1,
   'a viewer in the same zone gets no redundant second time');
eq(C.when(null), '', 'a missing time renders nothing rather than "undefined"');

/* ── preferences ──────────────────────────────────────────────────────────── */
group('preferences reach the document');

function fakeDoc() {
  const attrs = {}, style = {};
  return {
    documentElement: {
      setAttribute: (k, v) => { attrs[k] = v; },
      removeAttribute: (k) => { delete attrs[k]; },
      style: { setProperty: (k, v) => { style[k] = v; } },
      _attrs: attrs, _style: style
    }
  };
}
let d = fakeDoc();
C.applyPreferences({ text_scale_pct: 150, dyslexia_font: true, reduce_motion: true, locale: 'yo' }, d);
eq(d.documentElement._style['--mam-scale'], '1.5', 'text scale becomes a CSS custom property');
eq(d.documentElement._attrs['data-mam-dyslexia'], '1', 'the dyslexia face is switched on');
eq(d.documentElement._attrs['data-mam-motion'], 'reduce',
   'the STORED motion preference applies even where the OS setting is absent (a managed device)');
eq(d.documentElement._attrs['lang'], 'yo', 'the language is set on <html>, so screen readers switch voice');

// A value outside the range the database enforces could only come from a stale
// client, and honouring it would leave the interface unusable with no way back.
d = fakeDoc();
eq(C.applyPreferences({ text_scale_pct: 900 }, d).scale, 100, 'an out-of-range scale falls back to 100%');
d = fakeDoc();
eq(C.applyPreferences({ text_scale_pct: 10 }, d).scale, 100, '…at the low end too');
d = fakeDoc();
eq(C.applyPreferences({ text_scale_pct: 250 }, d).scale, 250, 'but 250% — the documented maximum — is honoured');
d = fakeDoc();
C.applyPreferences({ dyslexia_font: false, reduce_motion: false }, d);
ok(!('data-mam-dyslexia' in d.documentElement._attrs), 'turning a preference off removes the attribute');
ok(!('data-mam-motion' in d.documentElement._attrs), '…rather than leaving it stuck on');
eq(Object.keys(C.applyPreferences({}, null)).length, 0, 'no document is a no-op, not a crash');

console.log(fail === 0
  ? '\n✅ v2-clarity: ' + pass + ' passed, 0 failed'
  : '\n❌ v2-clarity: ' + pass + ' passed, ' + fail + ' failed');
process.exit(fail === 0 ? 0 : 1);
