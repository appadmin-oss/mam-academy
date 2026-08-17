<?php
/**
 * The apply wizard is a real <form>.
 *
 * It was 51 inputs and 4 selects sitting outside any form at all, with no submit
 * listener — every control wired by click. That cost the browser's entire
 * contribution to the page: Enter did nothing (implicit submission requires a
 * form), autofill could not see a name/address block to group, password managers
 * would not offer to save, and native constraint validation never ran because
 * nothing ever fired a submit.
 *
 * This matters more than a tidiness point: the production export holds 48
 * application drafts against 26 submissions — 46% abandonment on a form people
 * had already started.
 */
declare(strict_types=1);

$apply = (string) file_get_contents(dirname(__DIR__, 2) . '/apply.php');
// Strip PHP and HTML comments before counting tags — the wrapper's own comment
// explains the change and contains the literal "<form", which a naive count
// reads as a second, nested form.
$applyCode = (string) preg_replace(['~<\?php\s*/\*.*?\*/\s*\?>~s', '~<!--.*?-->~s'], '', $apply);
$js    = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/apply.js');

t_group('apply wizard — it is a form');

t_ok(strpos($apply, '<form id="applyForm"') !== false, 'the wizard is wrapped in a form');
t_eq(substr_count($applyCode, '<form'), 1, 'exactly one form — a nested form is invalid and silently drops fields');
t_eq(substr_count($applyCode, '</form>'), 1, '…and it is closed exactly once');
t_ok((bool) preg_match('/<form id="applyForm"[^>]*novalidate/', $apply),
    'it carries novalidate');
// novalidate is load-bearing, not laziness. Steps 2-5 stay in the DOM while
// hidden, so a `required` field on a hidden step makes the form unsubmittable
// with "An invalid form control is not focusable" — an error the applicant can
// neither see nor fix. validateStep() gates each step instead.
// novalidate looks like laziness and is the opposite, so the reason is written
// into the file — a future reader who deletes it re-breaks the form invisibly.
t_ok(strpos($apply, 'focusable') !== false,
    '…and the reason novalidate is required is written down beside it');

t_group('apply wizard — Enter works');

// Implicit submission needs a submit button present. With every button
// type=button, pressing Enter in a form with more than one field does nothing
// at all — which is exactly what applicants experienced.
t_eq(substr_count($apply, 'class="btn-step btn-step-next" id="btnNext'), 4, 'there are four Continue buttons');
t_ok(!preg_match('/<button type="button" class="btn-step btn-step-next"/', $apply),
    'none of them is type=button any more');
t_eq(substr_count($apply, '<button type="submit" class="btn-step btn-step-next"'), 4,
    'all four are type=submit, so Enter has something to submit');

t_group('apply wizard — one listener, not four');

t_ok((bool) preg_match("/on\(\\\$\('#applyForm'\), 'submit'/", $js),
    'navigation listens on the form\'s submit event');
t_ok(strpos($js, 'e.preventDefault();') !== false,
    '…and prevents the default post, since the wizard owns navigation');
t_ok(!preg_match("/on\(\\\$\('#btnNext'\),\s*'click'/", $js),
    'the four per-button click listeners are gone (they would double-fire beside submit)');
t_ok(strpos($js, 'attemptNext();') !== false,
    'and the existing validate-then-advance path is unchanged — this adds the browser underneath it');

t_group('apply wizard — required describes what the wizard actually enforces');

// `required` marks exactly the fields validateStep() enforces unconditionally.
// Under novalidate it does not block submission — the wizard's own messages stay
// the ones people see. What it gives is a screen reader announcing "required", a
// machine-readable description of the form, and a backstop the day novalidate is
// reconsidered.
foreach (['firstName','lastName','email','phone','address','lga','state','country','dob'] as $id) {
    t_ok((bool) preg_match('/<input\b[^>]*id="' . $id . '"[^>]*\brequired\b/', $apply)
      || (bool) preg_match('/<input\b[^>]*\brequired\b[^>]*id="' . $id . '"/', $apply),
        "$id is marked required, matching validateStep()");
}

// The two kinds of field that must NOT be marked, because doing so would state
// a rule the application does not have.
t_ok(!preg_match('/<input\b[^>]*id="city"[^>]*\brequired\b/', $apply),
    'city is NOT required — the wizard never validates it');
foreach (['parentName','parentPhone'] as $id) {
    t_ok(!preg_match('/<input\b[^>]*id="' . $id . '"[^>]*\brequired\b/', $apply),
        "$id is NOT hardcoded required — it applies to minors only");
}

t_group('apply wizard — autofill was already right');

// A finding withdrawn, kept as a test so nobody "fixes" it back. 13 of the 18
// fields that CAN autofill carry a correct token; the other five are
// application-specific choices with no personal-data token to give them.
foreach ([['firstName','given-name'], ['lastName','family-name'], ['email','email'],
          ['phone','tel'], ['address','street-address'], ['state','address-level1'],
          ['country','country-name'], ['dob','bday']] as [$id, $token]) {
    t_ok(strpos($apply, 'autocomplete="' . $token . '"') !== false,
        "$id carries autocomplete=\"$token\"");
}

/* ────────────────────────────────────────────────────────────────────────────
 * Error paths + the headshot step. Every assertion below stands for something
 * measured in a real browser against the running site on 2026-08-17, not read
 * off the source: the wizard was driven to Review five times with a rejected
 * field, an aborted connection, an expired CSRF token, a real submit, and no
 * photo at all.
 * ──────────────────────────────────────────────────────────────────────────── */

$hs = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/headshot.js');
$css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/apply.css');

t_group('apply wizard — the phone field works without its CDN');

// MEASURED: with jsdelivr blocked, initIti() returned before binding anything,
// so data.phone stayed empty however carefully the applicant typed, and
// validateStep() failed the number it could not see. The wizard was
// unfinishable — a hard stop on the academy's only revenue form, triggered by
// an ad-blocker, a proxy, a CSP, or one bad minute of network.
t_ok((bool) preg_match('/function initIti\(inputId, key\) \{\s*var el = document\.getElementById\(inputId\);\s*if \(!el\) return null;/', $js),
    'initIti no longer gives up when intl-tel-input is absent');
t_ok((bool) preg_match("/if \(typeof window\.intlTelInput !== 'function'\) \{\s*on\(el, 'input', plain\);\s*on\(el, 'blur',  plain\);/", $js),
    '…it binds plain input/blur listeners instead, so the field still records what is typed');
t_ok(strpos($js, "var plain = function () { data[key] = el.value.trim(); clearError(inputId); saveDraft(); };") !== false,
    '…writing to the same data key the validator reads');

t_group('apply wizard — a failure always LOOKS like one');

// MEASURED: `role=null`, `background: rgba(0,0,0,0)`, `border: 0px`, unfocused —
// for BOTH a server field rejection and a dropped connection. Dressing the
// banner was the caller's job and only apiError() did it, so the two most
// likely real failures printed as ordinary body text.
t_ok(strpos($js, 'var NOTICE_TONES = {') !== false, 'one tone registry drives the banner');
t_ok(strpos($js, 'function setNotice(html, tone, takeFocus)') !== false, '…and one renderer applies it');
foreach (['error', 'warn', 'info'] as $tone) {
    t_ok((bool) preg_match('/\b' . $tone . ':\s*\{ role:/', $js), "the $tone tone is registered");
}
t_eq(substr_count($js, "notice.setAttribute('role'"), 1,
    'exactly one place sets the banner role — setNotice; no caller does it by hand');
// The two callers that used to skip the dressing.
t_ok((bool) preg_match("/setNotice\('Please fix the highlighted '/", $js),
    'server field errors go through setNotice');
t_ok((bool) preg_match("/setNotice\('We couldn\\\\'t reach our secure server just now/", $js),
    'the connection-dropped fallback goes through setNotice');
// Amber, not red: an unreachable server is not the applicant's mistake.
t_ok((bool) preg_match("/'warn', true\);\s*\}\s*function buildPayload/s", $js),
    '…as a WARNING, and it takes focus (nothing else on screen changed)');

// Focus. MEASURED: after a server rejection the applicant was carried back two
// steps and focus was left on <body>.
t_ok(strpos($js, 'if (!firstEl) firstEl = document.getElementById(spec.id);') !== false,
    'the first server-rejected field is remembered');
t_ok(strpos($js, 'Focus the field, not the banner') !== false,
    '…and focused after the step change, so a keyboard user lands on the thing to fix');

// A banner is about one attempt.
t_ok(strpos($js, 'function clearNotice()') !== false, 'there is a way to take the banner down');
t_ok((bool) preg_match('/saveDraft\(\);\s*\/\/ A retry that worked.*?clearNotice\(\);/s', $js),
    '…and a successful retry takes it down, so it cannot read as a failed payment');

t_group('apply wizard — contrast of the banner');

// Computed against the page background (#2A2119): text 9.8–10.7:1, borders
// 3.7–4.1:1. The shipped border alphas measured 1.6:1 (red) and 2.4:1 (amber) —
// below WCAG 1.4.11's 3:1, i.e. the outline that says "error" was itself too
// faint to see.
t_ok(strpos($js, "bd: 'rgba(244,63,94,0.9)'") !== false, 'the error border clears 3:1');
t_ok(strpos($js, "bd: 'rgba(232,150,31,0.72)'") !== false, 'the warning border clears 3:1');
t_ok(strpos($js, "bd: 'rgba(34,197,94,0.7)'") !== false, 'the info border clears 3:1');
t_ok(strpos($js, 'WCAG 1.4.11') !== false, '…and the threshold is written down, not left as a magic number');

t_group('headshot step — the required-photo message exists at all');

// MEASURED: pressing Continue with no photo produced a border pulse and nothing
// else. validateStep() wrote its message into `.hs-status`, an element that was
// not on the page — so the one hard-gated field on the step failed silently.
t_ok(strpos($apply, 'id="hsStatus"') !== false, 'the nudge has somewhere to be written');
t_ok((bool) preg_match('/<div class="hs-status" id="hsStatus" role="status" hidden>/', $apply),
    '…as a live region, so a screen reader hears why Continue did nothing');
t_ok(strpos($css, '.hs-status[hidden]{display:none}') !== false,
    'and it stays hidden until asked for (.hs-status sets display:flex, which outranks [hidden])');
t_ok(strpos($js, 'function setHsStatus(kind, html)') !== false, 'one writer owns that element');
t_ok(strpos($js, 'setHsStatus(null);') !== false, '…and a stored photo lowers it, so a met requirement stops shouting');
// The widget failing to load is the same class of dead end as the phone field.
t_ok(strpos($js, 'The photo uploader could not load.') !== false,
    'if the uploader itself does not load, the page says so instead of silently blocking');

t_group('headshot step — the upload reports what actually went wrong');

t_ok(strpos($hs, "j.error === 'rate_limited'") !== false,
    'a rate-limited upload says so (12 per 10 min is reachable by honest retrying)');
t_ok(strpos($hs, 'and try again. Your other answers are saved.') !== false,
    '…and says when to retry, and that nothing else was lost');
t_ok(strpos($hs, 'r.status === 413') !== false, 'an over-size body is named rather than reported as a generic failure');
t_ok(strpos($hs, 'r.status >= 500') !== false, '…as is a server-side fault');

t_group('apply wizard — reduced motion keeps the signal');

t_ok(strpos($hs, '@media (prefers-reduced-motion:reduce)') !== false,
    'the required-field pulse has a reduced-motion branch');
t_ok(strpos($hs, '.headshot-required-pulse .mam-hs{animation:none;border-color:#e53935;}') !== false,
    '…which holds the red border statically rather than removing the cue with the motion');
t_ok((bool) preg_match('/@media \(prefers-reduced-motion:reduce\)\{\s*\.headshot-required-pulse \.hs-dropzone\{animation:none\}/', $css),
    'and the stylesheet half does the same');

t_group('apply wizard — the payment step speaks English');

/* MEASURED on the payment step: with the Paystack SDK unreachable the applicant
   read the single word `init_failed`, and with our own endpoint down,
   `api_unavailable`. apiError(err.message) prints whatever the API layer threw,
   and for a MAM_API error `message` IS the server's machine-readable code — so
   the last screen before money spoke in tokens and offered no way forward. */
t_ok(strpos($js, 'var PAY_ERRORS = {') !== false, 'the payment step has a code → words map');
t_ok(strpos($js, 'function payErrorMessage(err)') !== false, '…behind one function');
t_ok(strpos($js, 'apiError(payErrorMessage(err));') !== false,
    'and the initialize failure path uses it instead of printing err.message');
t_ok(!preg_match("/apiError\(err && err\.message \? err\.message : 'Could not start payment/", $js),
    'the old "print the raw code" branch is gone');
// Every code the two payment routes can actually raise must have copy. Read off
// api/routes/payment-init.php and payment-verify.php, not invented.
foreach (['init_failed', 'paystack_init_failed', 'upstream_error', 'api_unavailable',
          'unsupported_provider', 'application_expired', 'application_not_found',
          'payment_not_found', 'order_not_found', 'zero_amount', 'script_load_failed'] as $code) {
    t_ok((bool) preg_match('/\b' . preg_quote($code, '/') . ':\s*\'/', $js),
        "the payment step has words for $code");
}
// An unknown code must never be printed AS the message — that is the whole bug.
t_ok(strpos($js, 'An unknown code must never be printed AS the message') !== false,
    'the unknown-code fallback says the honest thing and keeps the code as a reference');

t_group('apply wizard — a blocked payment SDK is named, not thrown raw');

// Ad-blockers and office networks block payment scripts as a matter of course,
// and a blocker that answers with an empty 200 loads "fine" and leaves no
// PaystackPop — the next line threw "Cannot read properties of undefined".
t_ok(strpos($js, "if (!window.PaystackPop || typeof window.PaystackPop.setup !== 'function') {") !== false,
    'a loaded-but-empty Paystack SDK is detected');
t_ok(strpos($js, "if (typeof window.FlutterwaveCheckout !== 'function') throw new Error('provider_unavailable');") !== false,
    '…and so is Flutterwave');
t_ok(strpos($js, 'var kobo = Math.round(Number(init.amount_ngn) * 100);') !== false,
    'a missing amount is caught here rather than reaching Paystack as NaN');
t_ok(strpos($js, 'if (!isFinite(kobo) || kobo <= 0)') !== false, '…and refused');
// Two identical function declarations; the later silently won.
t_eq(substr_count($js, 'function loadScript(src)'), 1, 'loadScript is declared exactly once');

t_group('apply wizard — "Payment received" is only said when it is true');

// The soft branch is a PROMISE that the webhook will reconcile. It may only be
// made where that holds: the provider took the money and OUR verify call failed
// transiently. A 404 from verify used to show the confetti success screen.
t_ok(strpos($js, 'var transient = !status || status === 0 || status >= 500 || status === 429') !== false,
    'the soft-success branch is limited to transient failures');
t_ok(strpos($js, 'Your payment went through, but we could not record it automatically.') !== false,
    'any other 4xx tells the truth and gives the applicant the reference to quote');
t_ok(strpos($js, 'you will not be charged again') !== false,
    '…and says they will not be charged twice, which is the thing they are afraid of');
