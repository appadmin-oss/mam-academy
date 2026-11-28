<?php
/**
 * UX quick wins (2026-11-28 UX research). One group per item; each pins the
 * behaviour the research found broken so it cannot quietly come back.
 */
declare(strict_types=1);

$UX_root = dirname(MAM_API_ROOT);
/** Source with comments stripped, so an assertion never matches a comment. */
function ux_code(string $path): string {
    $src = (string) file_get_contents($path);
    if (str_ends_with($path, '.php')) {
        $out = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
            $out .= is_array($t) ? $t[1] : $t;
        }
        return $out;
    }
    return (string) preg_replace('#/\*.*?\*/#s', '', $src);
}

t_group('ux 7 — a marking sheet with an unmarked criterion is refused');

$UX_crit = [['key' => 'pitch', 'max' => 30], ['key' => 'time', 'max' => 30], ['key' => 'tone', 'max' => 30], ['key' => 'shape', 'max' => 30]];
t_eq(mam_marking_rubric_refusal([], $UX_crit), 'no_marks', 'an empty marking (nothing chosen) is refused');
t_eq(mam_marking_rubric_refusal(['pitch' => 22], $UX_crit), 'no_marks', 'three criteria left untouched are refused');
t_eq(mam_marking_rubric_refusal(['pitch' => 22, 'time' => null, 'tone' => null, 'shape' => null], $UX_crit), null,
    'one mark plus an explicit "Not assessed" for the rest may be saved');
t_eq(mam_marking_rubric_refusal(['pitch' => null, 'time' => null, 'tone' => null, 'shape' => null], $UX_crit), null,
    'every criterion explicitly "Not assessed" is a choice, and may be saved');
t_eq(mam_marking_rubric_refusal(['pitch' => 31, 'time' => 1, 'tone' => 1, 'shape' => 1], $UX_crit), 'invalid_mark', 'a mark above the maximum is refused');
t_eq(mam_marking_rubric_refusal(['pitch' => 'ten', 'time' => 1, 'tone' => 1, 'shape' => 1], $UX_crit), 'invalid_mark', 'a non-number mark is refused');

$UX_fb = ux_code(MAM_API_ROOT . '/routes/instructor/feedback.php');
$UX_refuseAt = strpos($UX_fb, 'mam_marking_rubric_refusal(');
$UX_writeAt  = strpos($UX_fb, 'mam_lms_give_feedback(');
t_ok($UX_refuseAt !== false && $UX_writeAt !== false && $UX_refuseAt < $UX_writeAt,
    'the route refuses BEFORE it writes, so the submission stays on the marking queue');

// Behaviour against the real tables: a refused save leaves the submission's status alone.
$UX_t = (int) mam_db_insert('users', ['name' => 'UX Teacher', 'email' => 'ux.teacher@test.local', 'role' => 'instructor', 'password_hash' => 'x', 'status' => 'active']);
$UX_s = (int) mam_db_insert('users', ['name' => 'UX Pupil', 'email' => 'ux.pupil@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$UX_a = (int) mam_db_insert('assignments', ['title' => 'UX piece', 'student_id' => $UX_s, 'author_id' => $UX_t]);
$UX_sub = (int) mam_db_insert('submissions', ['assignment_id' => $UX_a, 'student_id' => $UX_s, 'status' => 'submitted']);
$UX_sheet = mam_marking_sheet($UX_sub);
t_eq(mam_marking_rubric_refusal([], $UX_sheet['criteria'] ?? []), 'no_marks', 'the real sheet for a submission refuses an empty marking');
t_eq((string) (mam_db_row('SELECT status FROM submissions WHERE id = ?', [$UX_sub])['status'] ?? ''), 'submitted', 'the refused submission is still waiting to be marked');

$UX_ij = ux_code($UX_root . '/assets/js/portal-instructor.js');
t_ok(str_contains($UX_ij, 'data-crit-go') && str_contains($UX_ij, "id=\"gradeErr\" role=\"alert\""),
    'every criterion can be opened and marked, and a refusal is announced inline');

t_group('ux 8 — a termly report needs its comment, and "sent" is the server\'s word');

$UX_rep = ux_code(MAM_API_ROOT . '/routes/instructor/report.php');
t_ok((bool) preg_match("/if \(\\\$comment === ''\) mam_fail\('comment_required', 422/", $UX_rep), 'an empty comment is refused with comment_required');
t_ok(strpos($UX_rep, "comment_required") < strpos($UX_rep, 'mam_report_build('), 'the refusal comes before any draft is built');
t_ok(str_contains($UX_rep, "'sent' => \$sent"), 'the route says whether the panel now holds the report');
t_ok(str_contains($UX_ij, "r && r.sent ? 'Sent to the Examinations panel.'") && !str_contains($UX_ij, "}, 'Sent to the Examinations panel.');"),
    'the screen says "Sent" only when the server reports it sent');
t_ok(str_contains($UX_ij, 'id="reportErr" role="alert"'), 'a missing comment is announced beside the box');

t_group('ux 17 — a future date of birth is named as such');

$UX_tomorrow = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
t_eq(mam_dob_problem($UX_tomorrow), 'future', 'tomorrow is "future", not "invalid"');
t_eq(mam_dob_message('future'), "Date of birth can't be in the future.", 'with the plain sentence');
t_eq(mam_dob_problem('2015-02-30'), 'invalid', 'an impossible date is invalid');
t_eq(mam_dob_problem('2015-03-24'), null, 'a real past date is fine');
t_eq(mam_dob_problem((new DateTimeImmutable('-1 year'))->format('Y-m-d')), 'too_young', 'a one-year-old is too young for the default floor');
$UX_got = null;
try { mam_validate_application(['dob' => $UX_tomorrow]); } catch (Throwable $e) { $UX_got = $e->getMessage(); }
t_ok(is_string($UX_got) && str_starts_with($UX_got, 'mam_fail:validation'), 'the application validator refuses a future date of birth');
$UX_val = ux_code(MAM_API_ROOT . '/modules/kernel/lib/validation.php');
t_ok(str_contains($UX_val, "'messages' => \$messages"), 'the validator sends the sentence with the refused field');
t_ok(str_contains($UX_val, "mam_fail('invalid_dob', 422, ['message' => mam_dob_message(\$dobProblem)"), 'the summer form gets the same sentence (a future date no longer reads as an age)');
$UX_af = ux_code($UX_root . '/assets/js/apply-flow.js');
$UX_su = ux_code($UX_root . '/assets/js/summer.js');
t_ok(str_contains($UX_af, "Date of birth can\\'t be in the future.") && str_contains($UX_su, "Date of birth can\\'t be in the future."),
    'both forms say it before submitting');

t_group('ux 6 — card failure on /apply falls back honestly, once');

$UX_cp = ux_code(MAM_API_ROOT . '/routes/config-public.php');
t_ok(str_contains($UX_cp, "'payment_methods'") && str_contains($UX_cp, 'mam_paystack_configured()'),
    'the public config says whether card can be taken right now');
$UX_cfg = ux_code($UX_root . '/assets/js/config.js');
t_ok(str_contains($UX_cfg, 'j.payment_methods'), 'the browser config takes the server\'s word on payment methods');
t_ok(str_contains($UX_af, "s.f.pay = 'transfer'") && str_contains($UX_af, "'Your application is saved as ' + s.ref + '. ' + (srvMsg"),
    'a refused card step shows the reference and the server\'s message, and switches to bank transfer');
t_ok(str_contains($UX_af, 'idem: s.idem') && str_contains($UX_af, 'if (d.idem) s.idem = String(d.idem)'),
    'the idempotency key survives a reload, so a retry reuses the application');
$UX_app = ux_code(MAM_API_ROOT . '/routes/application.php');
t_ok(strpos($UX_app, 'WHERE idempotency_key = ?') < strpos($UX_app, 'mam_validate_application('), 'the server returns the prior application before validating again');

t_group('ux 15 — settings are read-only to an admin who cannot save them');

$UX_set = ux_code($UX_root . '/partials/admin-console/settings.php');
t_ok(str_contains($UX_set, "mam_admin_can(\$cfgUser, 'settings')"), 'the screen asks whether this admin holds the settings capability');
t_ok(str_contains($UX_set, "<fieldset class=\"ac-plainset\"<?= \$canEditSettings ? '' : ' disabled' ?>>"), 'without it every field is disabled');
t_ok((bool) preg_match('/<\?php if \(\$canEditSettings\): \?>\s*<div class="ac-row ac-mt">\s*<button type="submit"/', $UX_set), 'and Save is not offered');
t_ok(str_contains($UX_set, 'You can read these settings but not change them'), 'the reason is said in words');
t_eq(mam_admin_capabilities()['settings'] ?? null, 'super', 'settings stays super-only');

t_group('ux 14 — one name per admissions status, and the chips add up');

$UX_stg = mam_console_funnel_stages();
$UX_vocab = array_column(mam_console_status_vocabulary(), 'shown', 'stored');
foreach ($UX_vocab as $UX_k => $UX_shown) {
    $UX_chip = array_values(array_filter($UX_stg, fn($c) => $c['key'] === $UX_k))[0] ?? null;
    t_ok($UX_chip !== null && $UX_chip['label'] === $UX_shown, "status '$UX_k' has a chip labelled as everywhere else ('$UX_shown')");
}
$UX_total = (int) (mam_db_row('SELECT COUNT(*) n FROM applications')['n'] ?? 0);
t_eq(array_sum(array_column($UX_stg, 'n')), $UX_total, 'the chips add up to the number of applications');
$UX_adm = ux_code($UX_root . '/partials/admin-console/admissions.php');
t_ok(str_contains($UX_adm, "ac_status((string) \$r['status'], mam_console_status_label((string) \$r['status']))"), 'the list pills use the same label map');
t_ok(str_contains($UX_adm, 'Now: <?= ac_h(mam_console_status_label('), 'and so does the detail\'s "Now:"');

t_group('ux 19 — a consent nobody answered reads "Not answered yet", not "Declined"');

$UX_ck = (int) mam_db_insert('users', ['name' => 'UX Consent Kid', 'email' => 'ux.ck@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
$UX_cp2 = (int) mam_db_insert('users', ['name' => 'UX Consent Mum', 'email' => 'ux.cm@test.local', 'role' => 'parent', 'password_hash' => 'x', 'status' => 'active']);
t_eq(mam_consent_get($UX_ck)['photo'], null, 'no record: photo is not answered');
mam_consent_set($UX_ck, ['photo' => true], $UX_cp2);
$UX_c = mam_consent_get($UX_ck);
t_eq([$UX_c['photo'], $UX_c['media'], $UX_c['data']], [true, null, null], 'answering one leaves the other two unanswered, not declined');
mam_consent_set($UX_ck, ['media' => false], $UX_cp2);
$UX_c = mam_consent_get($UX_ck);
t_eq([$UX_c['photo'], $UX_c['media'], $UX_c['data']], [true, false, null], 'a "no" is recorded as a no, and the earlier yes stands');
t_ok(!mam_consent_allows($UX_ck, 'data') && !mam_consent_allows($UX_ck, 'media') && mam_consent_allows($UX_ck, 'photo'),
    'the gate still refuses anything not agreed (default-deny unchanged)');
// A row written by nobody (the pre-v112 shape of "never asked") reads as unanswered.
$UX_ck2 = (int) mam_db_insert('users', ['name' => 'UX Consent Kid 2', 'email' => 'ux.ck2@test.local', 'role' => 'student', 'password_hash' => 'x', 'status' => 'active']);
mam_db_insert('student_consents', ['student_id' => $UX_ck2, 'media_consent' => 0, 'photo_consent' => 0, 'data_consent' => 0]);
t_eq(mam_consent_get($UX_ck2)['media'], null, 'a row nobody wrote is unanswered, not declined');
$UX_g = ux_code($UX_root . '/assets/js/portal-guardian.js');
t_ok(str_contains($UX_g, 'Not answered yet.') && !str_contains($UX_g, "['media', 'photo', 'data'].forEach(function (k) { payload[k] = !!con[k]; });"),
    'the guardian screen says "Not answered yet" and sends only the consent pressed');

t_group('ux 21 — an invoice reference on /tuition is sent to the portal');

$UX_ts = ux_code(MAM_API_ROOT . '/routes/tuition-status.php');
t_ok(str_contains($UX_ts, "'kind' => 'fee_invoice'") && strpos($UX_ts, "'kind' => 'fee_invoice'") < strpos($UX_ts, 'mam_tuition_status('),
    'the lookup recognises an INV- reference before searching applications');
$UX_tj = ux_code($UX_root . '/assets/js/tuition.js');
t_ok(str_contains($UX_tj, "/^INV-/i.test(ref)") && str_contains($UX_tj, "a.href = '/portal/parent/'"),
    'the page says where invoices are paid, with a link, instead of "we couldn\'t find an application"');

t_group('ux 22 — guardian messages are an in-page form, not prompt boxes');

t_ok(!str_contains($UX_g, 'window.prompt('), 'no window.prompt() is left in the guardian portal');
t_ok(str_contains($UX_g, 'id="cmpChild"') && str_contains($UX_g, 'id="cmpSubject"') && str_contains($UX_g, 'id="cmpBody"'),
    'the form asks which child, a subject and the message, each with a label');
t_ok(str_contains($UX_g, 'id="cmpBodyErr" role="alert"') && str_contains($UX_g, "setAttribute('aria-invalid', 'true')"),
    'an empty field is refused inline and marked invalid');
t_ok(str_contains($UX_g, "[data-compose-cancel]"), 'Cancel is wired into the click delegation');

t_group('ux 16 — a likely email typo must be answered before Continue');

$UX_af = ux_code($UX_root . '/assets/js/apply-flow.js');
t_ok(str_contains($UX_af, "if (sg) e[mk] = 'Did you mean '"), 'validation refuses the contact step while a suggestion is unanswered');
t_ok(str_contains($UX_af, 'data-keepmail="') && str_contains($UX_af, 'No, keep what I typed'), 'the applicant can keep what they typed, with one press');
t_ok(str_contains($UX_af, "if ((s.mailOk || {})[k] === em) return '';"), 'once kept, the address is not questioned again');

t_group('ux 9 — session times carry their offset; portals show Lagos time, labelled');

t_eq(mam_session_iso('2026-11-28 16:30:00'), '2026-11-28T16:30:00+01:00', 'a stored session time leaves with +01:00');
t_eq(mam_session_iso('2026-11-28T16:30'), '2026-11-28T16:30:00+01:00', 'the T form too');
$UX_pl = mam_session_iso_payload(['next' => ['scheduled_at' => '2026-11-28 16:30:00', 'created_at' => '2026-11-28 10:00:00'], 'x' => [['session_at' => '2026-12-01 09:00:00']]]);
t_eq($UX_pl['next']['scheduled_at'], '2026-11-28T16:30:00+01:00', 'a nested session time is converted');
t_eq($UX_pl['next']['created_at'], '2026-11-28 10:00:00', 'a UTC timestamp is left alone');
t_eq($UX_pl['x'][0]['session_at'], '2026-12-01T09:00:00+01:00', 'lists are walked');
foreach (['student/portal.php', 'instructor/portal.php', 'parent/child.php', 'parent/dashboard.php'] as $UX_r) {
    $UX_src = ux_code(MAM_API_ROOT . '/routes/' . $UX_r);
    t_ok(str_contains($UX_src, 'mam_ok(mam_session_iso_payload($p))') && !preg_match('/(?<!\$p\)\)|=> )\bmam_ok\(/', str_replace('fn(array $p) => mam_ok(mam_session_iso_payload($p))', '', $UX_src)),
        "$UX_r sends every response through the offset walker");
}
$UX_pj = ux_code($UX_root . '/assets/js/portal.js');
t_ok(str_contains($UX_pj, "var LAGOS = 'Africa/Lagos'") && str_contains($UX_pj, "t + ' WAT'") && str_contains($UX_pj, 'timeLagos: timeLagos'),
    'the shared kit formats in Africa/Lagos and labels it WAT');
foreach (['portal-student.js', 'portal-instructor.js', 'portal-guardian.js'] as $UX_f) {
    $UX_src = ux_code($UX_root . '/assets/js/' . $UX_f);
    t_ok(str_contains($UX_src, 'P.timeLagos') || str_contains($UX_src, 'P.dateTimeLagos'), "$UX_f uses the shared Lagos formatter");
    t_ok(!preg_match('/toLocale(Time|Date)?String\(\[\]|toLocale(Time|Date)?String\(undefined, \{ (weekday|day|hour)/', $UX_src), "$UX_f has no browser-zone date/time formatting left");
}

t_group('ux 24 — the logo is a small raster; the film waits on a phone');

$UX_logo = $UX_root . '/assets/img/mam-academy-logo-96.png';
t_ok(is_file($UX_logo) && filesize($UX_logo) < 15 * 1024, 'the header logo is a compact PNG under 15 KB [' . (is_file($UX_logo) ? filesize($UX_logo) : 0) . ' bytes]');
t_ok(is_file($UX_root . '/mam-academy-logo.svg'), 'the SVG stays (favicon, structured data)');
foreach (['partials/site/header-home.php', 'partials/site/header-inner.php', 'partials/site/footer.php'] as $UX_f) {
    t_ok(!str_contains((string) file_get_contents($UX_root . '/' . $UX_f), 'img src="/mam-academy-logo.svg"'), "$UX_f no longer draws the 149 KB SVG");
}
$UX_site = ux_code($UX_root . '/assets/js/site.js');
t_ok(str_contains($UX_site, "matchMedia('(max-width: 768px)')") && str_contains($UX_site, 'if (src && !reduce && !saveData && !small) { build(); run(); }'),
    'the hero film does not autoplay on a phone or under Save-Data');

t_group('ux 25 — words, not codes');

$UX_pj = ux_code($UX_root . '/assets/js/portal.js');
t_ok(str_contains($UX_pj, "toni: 'First term (Jan–Apr)'") && str_contains($UX_pj, 'termName: termName'), 'term codes are named in words');
t_ok(str_contains($UX_g, 'P.termName(rep.term_label)') && str_contains($UX_g, 'P.termName(inv.term_label)'), 'the guardian report and invoice use the words');
t_eq(mam_card_exp_words(['expires_at' => '2027-11-30 00:00:00']), 'Nov 2027', 'the ID card expiry reads "Nov 2027" on screen');
t_ok(str_contains(ux_code($UX_root . '/assets/js/portal-student.js'), "'Valid until ' + esc(c.expires_words"), 'the student card says "Valid until Nov 2027"');
$UX_af = ux_code($UX_root . '/assets/js/apply-flow.js');
t_ok(str_contains($UX_af, "return 'Thank you' + (g ? ', ' + esc(g) : '')"), 'a parent applying for a child is thanked, not the child');
$UX_ty = (string) file_get_contents($UX_root . '/thank-you.php');
t_ok(!str_contains($UX_ty, 'Reference: pending'), 'the legacy thank-you no longer says "Reference: pending"');
t_ok(str_contains(ux_code($UX_root . '/assets/js/portal-instructor.js'), "function fmtClock(v) { return P.timeLagos ? P.timeLagos(v, { label: false })"),
    'the instructor home uses one 12-hour clock');

t_group('ux 11 — "Enrol this student" confirms money and class, and never defaults an adult into KTAMS');

$UX_ec = ux_code(MAM_API_ROOT . '/modules/admissions/lib/enrol-complete.php');
t_ok(!str_contains($UX_ec, 'Course Registrations'), 'the follow-up names a screen that exists');
t_ok(str_contains($UX_ec, "\$track = \$appAge < 16 ? 'KTAMS'"), 'the default programme follows the applicant\'s age');
t_eq(mam_enrol_programme_code('standard', 'MAM'), 'standard', 'an adult on Standard defaults to Standard');
$UX_ad = ux_code($UX_root . '/partials/admin-console/admissions.php');
t_ok(str_contains($UX_ad, "<option value=\"\"<?= \$pick('programme') === '' ? ' selected' : '' ?>>No programme"), 'nothing is preselected when the application does not decide the programme');
t_ok(str_contains($UX_ad, "'No payment recorded'") && str_contains($UX_ad, 'name="confirm_enrol" value="1" required'), 'the payment status is shown and must be confirmed');
$UX_idx = ux_code($UX_root . '/admin/index.php');
$UX_eAt = strpos($UX_idx, "\$action === 'enrol_complete'");
t_ok($UX_eAt !== false && strpos($UX_idx, "empty(\$_POST['confirm_enrol'])", $UX_eAt) < strpos($UX_idx, 'mam_enrol_complete(', $UX_eAt),
    'the handler refuses an unconfirmed enrolment before doing anything');
$UX_adC = (int) mam_db_insert('customers', ['first_name' => 'Grown', 'last_name' => 'Up', 'email' => 'ux.adult@test.local', 'dob' => '1990-05-01']);
$UX_adA = (int) mam_db_insert('applications', ['reference' => 'MAM-UXADULT', 'customer_id' => $UX_adC, 'status' => 'submitted', 'pathway' => 'standard', 'program' => 'performance', 'program_track' => 'KTAMS', 'total_usd' => 5]);
$UX_rz = mam_enrol_resolve($UX_adA);
t_ok(($UX_rz['programme']['code'] ?? '') !== 'ktams', 'an adult (by date of birth) is not defaulted into KTAMS even with a stale KTAMS track [' . ($UX_rz['programme']['code'] ?? 'none') . ']');
$UX_kC = (int) mam_db_insert('customers', ['first_name' => 'Small', 'last_name' => 'One', 'email' => 'ux.small@test.local', 'dob' => (new DateTimeImmutable('-9 years'))->format('Y-m-d')]);
$UX_kA = (int) mam_db_insert('applications', ['reference' => 'MAM-UXCHILD', 'customer_id' => $UX_kC, 'status' => 'submitted', 'pathway' => 'standard', 'program' => 'performance', 'total_usd' => 5]);
t_eq(mam_enrol_resolve($UX_kA)['programme']['code'] ?? null, 'ktams', 'a nine-year-old is placed in KTAMS by age');
t_ok(str_contains((string) file_get_contents($UX_root . '/assets/css/portal.css'), '.login-form button[type=submit]{background:var(--btn-primary-bg,#F2A93B)'),
    'the sign-in button has a fill even where the page sets no portal theme');
