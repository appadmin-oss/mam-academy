<?php
/**
 * Instructor + guardian portal forms.
 *
 * Measured in a browser on 2026-08-17 by signing into each portal with real
 * teaching data seeded (four students on a class and a cohort, one scheduled
 * session) and walking every tab that renders a control.
 *
 * Two findings, one of them the same root cause as the apply wizard's dead
 * phone field: a third-party CDN script was load-bearing for a core function,
 * behind a fallback that was not one.
 */
declare(strict_types=1);

$inst   = (string) file_get_contents(dirname(__DIR__, 2) . '/portal/instructor/index.html');
$parent = (string) file_get_contents(dirname(__DIR__, 2) . '/portal/parent/index.html');
$libs   = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/libs.js');
$pjs    = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/portal.js');

t_group('portal toasts — an error is never written only to the console');

/* MEASURED: an instructor recorded a mark, the server answered 500, and NOTHING
 * appeared. The catch ran (the button re-enabled), MAM_DIALOG.toast existed —
 * and its no-SweetAlert2 branch was `console.log('[toast]', message)`.
 *
 * That mattered out of proportion to its size: toast IS the instructor
 * portal's entire error vocabulary ("Could not record: …", "Could not save
 * attendance.", "Could not update the mark."). On any network that blocks the
 * CDN — ad-blocker, corporate proxy, tightened CSP — every one of those
 * failures was silent, and the instructor watched the form reset and concluded
 * the mark had saved. `alert` and `confirm` degraded to native dialogs, which
 * are at least visible; only `toast` degraded to somewhere nobody looks.
 */
t_ok(strpos($libs, "console.log('[toast]', message)") === false,
    'the no-library branch no longer writes the error to the console and stops');
t_ok(strpos($libs, 'function fallbackToast(message, icon)') !== false,
    'there is a self-hosted toast for when SweetAlert2 does not arrive');
t_ok(strpos($libs, 'if (!swal) { fallbackToast(message, icon); return; }') !== false,
    '…and toast() uses it');
// It must not be able to fail the same way the thing it replaces did.
t_ok(strpos($libs, 'var TOAST_CSS_ID') !== false, 'its styles are injected, not fetched');
// Bound the check to the function body itself rather than a byte window —
// makeDialog() follows it and legitimately mentions the CDN-backed library.
$fbStart = strpos($libs, 'function fallbackToast(message, icon)');
$fbEnd   = strpos($libs, 'function makeDialog()', $fbStart ?: 0);
$fbBody  = ($fbStart !== false && $fbEnd !== false) ? substr($libs, $fbStart, $fbEnd - $fbStart) : '';
t_ok($fbBody !== '' && !preg_match('/loadJs|loadCss|CDN\.|fetch\(|<script/', $fbBody),
    '…and it loads nothing external');
// A failure interrupts; a confirmation is announced politely.
t_ok(strpos($libs, "t.setAttribute('role', bad ? 'alert' : 'status');") !== false,
    'an error toast is role=alert, a success is role=status');
t_ok(strpos($libs, 'txt.textContent = String(message == null') !== false,
    'the message is set as TEXT — callers pass server-derived strings');
t_ok(strpos($libs, 'setTimeout(close, bad ? 9000 : 4000);') !== false,
    'an error lingers longer than a confirmation, because a missed error is the whole problem');
t_ok(strpos($libs, "x.setAttribute('aria-label', 'Dismiss')") !== false, '…and can always be dismissed by hand');
t_ok(strpos($libs, '@media (prefers-reduced-motion:reduce){.mam-toast{animation:none}}') !== false,
    'it respects reduced motion');
// Borders carry the tone, so they clear WCAG 1.4.11. Computed against each
// toast's own background: err 4.77:1, warn 7.17:1, ok 7.38:1, base 3.42:1.
t_ok(strpos($libs, 'border:1px solid #856520') !== false,
    'the neutral border is #856520 — #7A5C1E measured 2.97:1, just under 3:1');

t_group('portal errors — one translator, not seventeen pasted codes');

/* MEASURED: "Could not record: server_error" — the server's machine code
 * pasted into the sentence. 17 call sites did this. */
t_ok(strpos($pjs, 'errText: function (err, fallback)') !== false, 'the portal kit translates API codes');
t_ok(strpos($pjs, 'fail: function (err, fallback)') !== false, '…and has one way to report a failure');
t_ok(strpos($pjs, "else { try { window.alert(msg); } catch (e) {} }") !== false,
    'P.fail falls all the way back to alert, so a missing dialog cannot hide an error');
foreach (['forbidden', 'validation', 'not_found', 'server_error', 'csrf_invalid', 'rate_limited', 'illegal_transition'] as $code) {
    t_ok((bool) preg_match('/\b' . $code . ':\s*\'/', $pjs), "$code has words");
}
// A dropped connection surfaces as fetch's own sentence, not a server code.
t_ok(strpos($pjs, "code === 'Failed to fetch'") !== false,
    'a network failure gets the network answer, not a reference number');
t_ok(strpos($pjs, "var isCode = /^[a-z][a-z0-9_]{2,39}\$/.test(String(code));") !== false,
    'only an actual code is offered as a reference — a browser sentence is not one');
t_ok(substr_count($inst, 'err.body && err.body.error') <= 2,
    'at most two instructor sites still read the raw code, and both map it to words themselves');

t_group('instructor portal — every control has a name');

/* MEASURED: 51 visible controls across the tabs that render any; 20 unnamed.
 * The portal's idiom is `<div class="field"><label>Score</label><input …></div>`
 * — a label that neither wraps the control nor carries `for`, so it is a
 * caption, not a label. It looks right on screen and associates with nothing.
 * 31 sites in this file. Re-measured after the fix: 0 unnamed. */
t_ok(substr_count($inst, 'aria-label=') >= 30, 'the portal names its controls');
t_ok(!preg_match("/<label>Score<\/label><input name=\"score\"(?![^>]*aria-label)/", $inst),
    'the gradebook score input is named');
// The gradebook repeats the same four controls per student card, so the column
// caption is ambiguous the moment there is more than one student. Recording a
// mark against the wrong child is the failure this screen must prevent.
foreach (['Score for', 'Component for', 'Week for', 'Score out of, for', 'Record mark for'] as $frag) {
    t_ok(strpos($inst, 'aria-label="' . $frag . " ' + who + '\"") !== false,
        "the gradebook names the student in \"$frag …\"");
}
t_ok(strpos($inst, "var who = P.escapeHtml(e.student_name || 'this student');") !== false,
    '…escaped once per card, with a fallback for a nameless record');
// Two filters had no text to take a name from at all.
t_ok(strpos($inst, 'aria-label="Choose a cohort"') !== false, 'the sessions cohort picker is named');
t_ok(strpos($inst, 'aria-label="Filter gradebook by cohort"') !== false,
    'the gradebook filter is named (its <label> held only an icon)');

t_group('instructor portal — nothing fails silently');

t_ok(!preg_match("/\.catch\(function \(\) \{\}\);/", $inst),
    'no catch swallows a failure without saying anything');
t_ok(strpos($inst, 'Could not delete that note — it is still there.') !== false,
    'a failed note delete says the note is still there (it looked identical to success)');
t_ok(strpos($inst, 'Could not load the notes on this take') !== false,
    'a failed notes load says so, rather than reading as "no notes"');

t_group('instructor portal — a failed boot resolves');

// MEASURED: with the API unreachable the main area said "Could not load your
// dashboard" while the sidebar's user card still said "Loading…" — a page that
// claims to be both finished and still working, with no way to retry.
t_ok(strpos($inst, "if (e.message === 'unauthenticated') return;") !== false,
    'the redirect case exits early rather than painting an error over it');
t_ok(strpos($inst, "card.innerHTML = '<div class=\"portal-avatar\">!</div>") !== false,
    'the user card resolves instead of saying "Loading…" forever');
t_ok(strpos($inst, "content.removeAttribute('aria-busy');") !== false, '…and aria-busy is cleared');
t_ok(strpos($inst, "id=\"dashRetry\"") !== false, 'and there is a Try again button');

t_group('guardian portal — measured clean, left alone');

// 4 visible controls, all named; both failure paths already say what happened
// ("Could not load your children right now.", "Could not load this child right
// now."). Recorded so nobody "fixes" a portal that was already correct.
t_ok(strpos($parent, 'Could not load your children right now.') !== false,
    'a failed children list says so');
t_ok(strpos($parent, 'Could not load this child right now.') !== false,
    'a failed child page says so');
t_eq(preg_match_all("/<label>[^<]{1,40}<\/label>\s*(?:\\\\?')?\s*\+?\s*(?:\\\\?')?\s*<(?:input|select|textarea)\b/", $parent),
    0, 'the guardian portal has no caption-masquerading-as-label');
