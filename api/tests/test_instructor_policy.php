<?php
/**
 * The MAM Academy Policy for Instructors — the document a candidate agrees to,
 * and now actually receives.
 *
 * `/instructor-apply` has always asked every candidate to tick "I have read and
 * agree to abide by the Academy's child-protection and code-of-conduct
 * policies". The academy never sent them one. A consent to a document the
 * person has not been given is not a consent, and these guards are about the
 * document reaching them and staying intact.
 */
declare(strict_types=1);
t_group('instructor policy (the document they agreed to)');

$points = mam_policy_instructor_points();

t_eq(count($points), 12, 'all twelve clauses are present');
$empty = array_values(array_filter($points, fn($p) => trim((string) $p) === ''));
t_eq($empty, [], 'no clause is blank');

/* ── The transcription, spot-checked against the PDF ─────────────────────── */

// The source is a font-subsetted PDF whose text extracts as a substitution
// cipher with the line breaks welded into the words — "Latenessis not
// permitted", "wearingappropriate shoes". A transcription that reintroduced one
// of those welds would email it to a candidate, so the three worst joins are
// pinned by name.
foreach (['Latenessis', 'wearingappropriate', 'rulesand', 'lessonplanning',
          'programpolicies', 'program_s', 'organization_s'] as $weld) {
    $hit = array_values(array_filter($points, fn($p) => stripos($p, $weld) !== false));
    t_eq($hit, [], 'no clause carries the PDF line-break weld "' . $weld . '"');
}
t_ok(strpos($points[0], 'Lateness is not permitted') !== false, 'clause 1 reads as sentences, not as the raw extraction');
t_ok(strpos($points[8], "program's overall goals") !== false, 'the apostrophes the font subset lost are restored');
t_ok(strpos($points[11], 'strictly prohibited') !== false, 'clause 12 — the relationships clause — is intact');

/* ⚠️ THE POLICY AND THE SOFTWARE DISAGREE, AND THE POLICY WINS HERE.
   Clause 1 says lateness "will attract deductions"; the software does not make
   them — `late_charge_instructor_enabled` ships off because Labour Act s.5(1)
   bars deductions from wages for fines absent an authorised labour officer's
   written consent (CLAUDE.md gotcha #11), and the Earnings screen states a
   recorded lateness at zero. This asserts the clause is reproduced VERBATIM
   anyway: it is the academy's document, and softening a policy somebody signs
   to match our implementation is the worse fault. The reconciliation is the
   academy's to make, in the document. */
t_ok(strpos($points[0], 'it will attract deductions') !== false,
    'clause 1 is verbatim, deductions and all — the text is not edited to match the code');

/* ── One registry, three surfaces ────────────────────────────────────────── */

$print = mam_policy_instructor_print_html('Tunde Adeyemi', 'FAC-7', 'September 19, 2026');
$email = mam_email_policy_instructor('Tunde Adeyemi', 'FAC-7', 'https://example.test/api/policy/FAC-7');

t_eq(substr_count($print, '<li>'), 12, 'the printable copy renders all twelve');
foreach ([$print, $email] as $i => $doc) {
    $where = $i === 0 ? 'printable copy' : 'email';
    $missing = array_values(array_filter($points, fn($p) => strpos($doc, htmlspecialchars($p)) === false));
    t_eq($missing, [], 'every clause reaches the ' . $where . ' — one registry, no drift');
    t_ok(strpos($doc, 'Tunde Adeyemi') !== false, 'the ' . $where . ' is stamped with the name they typed');
    t_ok(strpos($doc, 'FAC-7') !== false, 'and with the reference it belongs to');
    t_ok(strpos($doc, htmlspecialchars(MAM_POLICY_INSTRUCTOR_ACCEPT)) !== false,
        "the {$where} carries the PDF's own acceptance sentence");
}

// MEASURED: the CLASS policy rendered its signature `color:#fff` on a #FBF4E8
// panel — the one line naming who signed was invisible in every client. The
// instructor copy must not repeat it, and the class copy is fixed.
t_ok(strpos($email, "color:#fff;font-size:16px") === false,
    'the signature is not white-on-cream (the class policy shipped that for months)');
$cls = mam_email_policy('online', 'Ada Okonkwo', 'MAM-1');
t_ok(strpos($cls, "color:#fff;font-size:16px") === false,
    '…and the class policy no longer is either');

/* ── It is actually sent, and it is viewable ─────────────────────────────── */

$route = (string) @file_get_contents(MAM_API_ROOT . '/routes/faculty-apply.php');
t_ok(strpos($route, 'mam_email_policy_instructor(') !== false,
    'the faculty application sends the policy');
t_ok(strpos($route, "'faculty_policy'") !== false,
    '…under its own mail-log context, so a failure to deliver it is visible');
// Best-effort like every other message in that block: a policy that failed to
// send must never lose the application. It sits inside mam_after_response().
t_ok(strpos($route, 'mam_after_response(') !== false, 'and after the response, like the rest');

$pol = (string) @file_get_contents(MAM_API_ROOT . '/routes/policy.php');
t_ok(strpos($pol, "stripos(\$ref, 'FAC-')") !== false,
    'an instructor reference resolves through the SAME /api/policy/<ref> shape');
t_ok(strpos($pol, 'mam_policy_instructor_print_html(') !== false, '…to the printable copy');

/* ── No offence data, no check content — the vetting line holds here too ─── */

// `vetting_records` deliberately stores no check content (gotcha #10). A policy
// document is not a place to start collecting it either.
foreach (['certificate number', 'document number', 'conviction', 'offence'] as $banned) {
    t_ok(stripos(implode(' ', $points), $banned) === false,
        'the policy asks for no ' . $banned);
}
