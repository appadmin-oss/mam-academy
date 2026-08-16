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
