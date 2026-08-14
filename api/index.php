<?php
/**
 * MAM Academy API
 * ─────────────────────────────────────────────────────────────
 * Single entry point. The bootstrap loads .env, libraries, CORS
 * and structured logging. The router below declares every route
 * with its HTTP method, handler file, and middleware chain.
 *
 * Routes resolve from the `route` query parameter set by the
 * project root .htaccess rewrite (/api/foo → /api/index.php?route=foo).
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/router.php';

/** Helper: include a route file as the request handler. The router calls the
 *  returned closure with the captured path params, so route files (invoice,
 *  policy, certificate, …) can read them as $params['name']. */
$f = function (string $path): callable {
    return function ($params = []) use ($path) { require __DIR__ . '/routes/' . $path; };
};

$r = new MamRouter();

/* ─── Public ─── */
$r->get('/health',  $f('health.php'),  [mw_rate_limit('health', 60, 60)]);
$r->get('/csrf',    $f('csrf-token.php'));
$r->get('/diag',    $f('diag.php'),     [mw_rate_limit('diag', 30, 60)]);
// First-run installer (self-disables once any user exists). Token-guarded via
// SETUP_TOKEN env when set. No CSRF: it's a one-time bootstrap before any login.
$r->get ('/setup', $f('setup.php'), [mw_rate_limit('setup', 30, 600)]);
$r->post('/setup', $f('setup.php'), [mw_rate_limit('setup', 10, 600)]);
// Booking-fee status — public (no PII surfaced). /book polls this to decide
// whether to show the $5 CTA or the unlocked Google appointment embed.
$r->get('/booking/status',  $f('booking-status.php'),  [mw_rate_limit('booking_status', 60, 60)]);
$r->get('/booking/pricing', $f('booking-pricing.php'), [mw_rate_limit('booking_pricing', 60, 60)]);
// Public, admin-driven pricing/config — lets the front-end show live programme
// prices & fees without a redeploy (server stays authoritative at charge time).
$r->get('/config/public',   $f('config-public.php'),   [mw_rate_limit('config_public', 120, 60)]);
$r->get('/promo/active',     $f('promo-active.php'),    [mw_rate_limit('promo_active', 120, 60)]);

// Academic session resolved from the calendar — applied silently by the form.
$r->get('/session/current', $f('session-current.php'), [mw_rate_limit('session_current', 120, 60)]);

// "Find Your Path" advisor — AI-driven recommendation (AI picks the programme +
// writes the rationale). Public + rate-limited; off unless ai_provider set, in
// which case the client falls back to its deterministic preview.
$r->get('/advisor/recommend', $f('advisor-recommend.php'), [mw_rate_limit('advisor_reco', 30, 600)]);

// Public academic catalogue (programmes, levels, courses) — drives the apply
// flow and the student "add a course" picker.
$r->get('/courses', $f('courses.php'), [mw_rate_limit('courses', 120, 60)]);
// Course week-by-week outline (programme plan + authored modules).
$r->get('/course/outline', $f('course-outline.php'), [mw_rate_limit('course_outline', 120, 60)]);

// Web-viewable invoice + signed policy. Replace the .html attachments that
// most mail clients render as source code with proper standalone pages
// (applicants click the link in the email and view in their browser, where
// "Save as PDF" + Print just work).
$r->get('/invoice/:ref',         $f('invoice.php'),         [mw_rate_limit('invoice', 30, 60)]);
$r->get('/tuition-invoice/:ref', $f('tuition-invoice.php'), [mw_rate_limit('invoice', 30, 60)]);
// Auto-generated OG/social card (flier-style PNG). Disk-cached per unique
// title/subtitle, so the rate limit only meets cold renders.
$r->get('/og', $f('og-image.php'), [mw_rate_limit('og', 60, 300)]);
$r->get('/policy/:ref',  $f('policy.php'),  [mw_rate_limit('policy',  30, 60)]);
// Web-viewable, printable course/programme certificate (issued on a pass).
$r->get('/certificate/:ref', $f('certificate.php'), [mw_rate_limit('certificate', 30, 60)]);

/* ─── Store / Library (public catalogue) ─── */
$r->get ('/store/items',       $f('store-list.php'),     [mw_rate_limit('store_list', 60, 60)]);
$r->get ('/store/item/:slug',  $f('store-detail.php'),   [mw_rate_limit('store_detail', 60, 60)]);
$r->post('/store/checkout',    $f('store-checkout.php'), [mw_csrf(), mw_rate_limit('store_checkout', 10, 60)]);

/* ─── Public forms (CSRF + rate-limited) ─── */
$r->post('/application', $f('application.php'), [mw_csrf(), mw_rate_limit('apply',       10, 600)]);
// MAM Scholars Initiative — opt-in scholarship assessment + status (§2c).
$r->post('/scholarship/apply',  $f('scholarship-apply.php'),  [mw_csrf(), mw_rate_limit('scholarship_apply', 6, 600)]);
$r->get ('/scholarship/status', $f('scholarship-status.php'), [mw_rate_limit('scholarship_status', 30, 600)]);
// Public applicant status lookup (reference + matching email; rate-limited).
$r->get ('/application/status', $f('application/status.php'), [mw_rate_limit('app_status', 30, 600)]);
// Summer Programme — public application form + status lookup.
// First-party headshot storage. Public because applicants are not signed in when
// they register; CSRF + a tight rate limit + GD re-encoding are the controls.
$r->post('/upload/headshot', $f('upload-headshot.php'), [mw_csrf(), mw_rate_limit('upload_headshot', 12, 600)]);
$r->get ('/media/headshot',  $f('media-headshot.php'),  [mw_rate_limit('media_headshot', 400, 600)]);
$r->post('/summer/apply',  $f('summer/apply.php'),  [mw_csrf(), mw_rate_limit('summer_apply', 8, 600)]);
$r->get ('/summer/status', $f('summer/status.php'), [mw_rate_limit('summer_status', 30, 600)]);
// Gated tuition lookup for the /tuition payment page (reference + matching email).
$r->get ('/tuition/status', $f('tuition-status.php'), [mw_rate_limit('tuition_status', 30, 600)]);
// Server-side resumable application drafts + magic-link (A1).
$r->get ('/application/draft',       $f('application/draft.php'),       [mw_rate_limit('app_draft',  240, 600)]);
$r->post('/application/draft',       $f('application/draft.php'),       [mw_csrf(), mw_rate_limit('app_draft', 240, 600)]);
$r->post('/application/resume-link', $f('application/resume-link.php'), [mw_csrf(), mw_rate_limit('app_resume', 6, 600)]);
// First-party funnel beacon (A3). No CSRF — it's an append-only analytics
// signal sent via navigator.sendBeacon; bounded by the rate limit + a strict
// event allowlist, and Do-Not-Track is honoured inside the handler.
$r->post('/track', $f('track.php'), [mw_rate_limit('track', 300, 600)]);
$r->post('/newsletter',  $f('newsletter.php'),  [mw_csrf(), mw_rate_limit('newsletter',  5,  600)]);
// Unsubscribe is reached from email links / mail-client one-click — no CSRF
// cookie available, so it relies on a signed token instead. Both verbs map
// to the same handler (GET = human click page, POST = RFC 8058 one-click).
$r->get ('/newsletter/unsubscribe', $f('newsletter-unsubscribe.php'), [mw_rate_limit('unsubscribe', 60, 600)]);
$r->post('/newsletter/unsubscribe', $f('newsletter-unsubscribe.php'), [mw_rate_limit('unsubscribe', 60, 600)]);
$r->post('/contact',     $f('contact.php'),     [mw_csrf(), mw_rate_limit('contact',     5,  600)]);
// Instructor application form (emailed, tokenized invite — api/lib/faculty.php).
$r->get ('/faculty-apply', $f('faculty-apply.php'), [mw_rate_limit('faculty_check', 30, 600)]);
$r->post('/faculty-apply', $f('faculty-apply.php'), [mw_csrf(), mw_rate_limit('faculty_apply', 6, 600)]);

/* ─── Payments ─── */
$r->group('/payment', function (MamRouter $r) use ($f) {
    $r->post('/initialize', $f('payment-init.php'),   [mw_csrf(), mw_rate_limit('payment_init',   20, 600)]);
    $r->post('/verify',     $f('payment-verify.php'), [mw_csrf(), mw_rate_limit('payment_verify', 30, 600)]);
    // Webhooks rely on HMAC signatures, not CSRF cookies. Rate-limited so an
    // unauthenticated flood can't fill webhook_events with raw payloads (the
    // handlers also reject an invalid signature before persisting).
    $r->post('/webhook/paystack',    $f('webhook-paystack.php'),    [mw_rate_limit('wh_paystack', 120, 60)]);
    $r->post('/webhook/flutterwave', $f('webhook-flutterwave.php'), [mw_rate_limit('wh_flutterwave', 120, 60)]);
});

/* ─── Auth ─── */
$r->group('/auth', function (MamRouter $r) use ($f) {
    $r->get ('/me',                $f('auth/me.php'));
    $r->post('/login',             $f('auth/login.php'),            [mw_csrf(), mw_rate_limit('login',          8,  600)]);
    $r->post('/logout',            $f('auth/logout.php'),           [mw_csrf()]);
    $r->post('/change-password',   $f('auth/change-password.php'),  [mw_csrf(), mw_user()]);
    $r->post('/request-reset',     $f('auth/request-reset.php'),    [mw_csrf(), mw_rate_limit('reset_request',  5,  600)]);
    $r->post('/perform-reset',     $f('auth/perform-reset.php'),    [mw_csrf(), mw_rate_limit('reset_perform', 10, 600)]);
    // Session management (P5): see + revoke your active sessions.
    $r->get ('/sessions',          $f('auth/sessions.php'),         [mw_user()]);
    $r->post('/revoke-session',    $f('auth/revoke-session.php'),   [mw_csrf(), mw_user()]);
    $r->post('/revoke-others',     $f('auth/revoke-others.php'),    [mw_csrf(), mw_user()]);
});

/* ─── Attendance QR self-check-in (student scans the instructor's session QR; #9/#10) ─── */
$r->post('/attendance/checkin', $f('attendance-checkin.php'), [mw_csrf(), mw_role('student'), mw_rate_limit('attendance_checkin', 30, 600)]);
// Cohort-model twin: self-check-in against a class_session → session_attendance (P2).
$r->post('/attendance/session-checkin', $f('attendance-session-checkin.php'), [mw_csrf(), mw_role('student'), mw_rate_limit('attendance_checkin', 30, 600)]);

/* ─── Drive file proxy: access-gated fetch of a Drive-backed resource (#5) ─── */
$r->get('/drive/file', $f('drive-file.php'), [mw_rate_limit('drive_file', 120, 600)]);

/* ─── Public progress report (token-gated, no auth required) ─── */
$r->get('/report',         $f('report.php'),         [mw_rate_limit('progress_report', 60, 600)]);
// Learner progress lookup by enrollment ref (public, no login required).
// Rate-limited tightly (5 per min per IP) to deter enumeration.
$r->get('/learner-lookup', $f('learner-lookup.php'), [mw_rate_limit('learner_lookup', 5, 60)]);

/* ─── Studio AI practice feedback (any signed-in user; S3) ─── */
$r->post('/studio/feedback', $f('studio/feedback.php'), [mw_csrf(), mw_user(), mw_rate_limit('studio_feedback', 60, 600)]);

/* ─── Studio leaderboard (opt-in; S2) ─── */
$r->post('/studio/score',       $f('studio/score.php'),       [mw_csrf(), mw_role('student'), mw_rate_limit('studio_score', 60, 600)]);
$r->get ('/studio/leaderboard', $f('studio/leaderboard.php'), [mw_user(), mw_rate_limit('studio_board', 120, 600)]);
// Shareable score card (public SVG; inputs clamped + escaped).
$r->get ('/studio/card',        $f('studio/card.php'),        [mw_rate_limit('studio_card', 120, 600)]);

/* ─── Notification centre (any signed-in user; LMS L3) ─── */
$r->get ('/notifications',      $f('notifications/list.php'), [mw_user(), mw_rate_limit('notif_list', 240, 600)]);
$r->post('/notifications/read', $f('notifications/read.php'), [mw_csrf(), mw_user(), mw_rate_limit('notif_read', 240, 600)]);

/* ─── Student portal ─── */
$r->group('/student', function (MamRouter $r) use ($f) {
    $r->get ('/dashboard',           $f('student/dashboard.php'),           [mw_role('student')]);
    $r->get ('/courses',             $f('student/courses.php'),             [mw_role('student')]);
    // Internal wallet (#12): read-only balance/history + store-only redemption.
    $r->get ('/wallet',              $f('student/wallet.php'),              [mw_role('student')]);
    $r->post('/store-redeem',        $f('student/store-redeem.php'),        [mw_csrf(), mw_role('student'), mw_rate_limit('store_redeem', 20, 600)]);
    $r->post('/add-course',          $f('student/add-course.php'),          [mw_csrf(), mw_role('student'), mw_rate_limit('add_course', 30, 600)]);
    $r->post('/update-profile',      $f('student/update-profile.php'),      [mw_csrf(), mw_role('student')]);
    $r->post('/avatar',              $f('student/avatar.php'),              [mw_csrf(), mw_role('student'), mw_rate_limit('student_avatar', 20, 600)]);
    $r->post('/practice-log',        $f('student/practice-log.php'),        [mw_csrf(), mw_role('student')]);
    $r->post('/assignment-complete', $f('student/assignment-complete.php'), [mw_csrf(), mw_role('student')]);
    // Per-component gradebook breakdown for one of the student's own courses.
    $r->get ('/assessments',         $f('student/assessments.php'),         [mw_role('student')]);
    // AI progress note (provider-agnostic gateway; off unless ai_provider set).
    $r->get ('/ai-narrative',        $f('student/ai-narrative.php'),        [mw_role('student'), mw_rate_limit('ai_narrative', 20, 600)]);
    // AI practice coach (L4) — a weekly plan from the student's own logs/results.
    $r->get ('/practice-coach',      $f('student/practice-coach.php'),      [mw_role('student'), mw_rate_limit('practice_coach', 20, 600)]);
    // Cohort session attendance (LMS L2 dual-read view).
    $r->get ('/session-attendance',  $f('student/session-attendance.php'),  [mw_role('student')]);
    // Submissions against assignments (LMS L2).
    $r->post('/submit',              $f('student/submit.php'),              [mw_csrf(), mw_role('student'), mw_rate_limit('submit', 60, 600)]);
    $r->get ('/submissions',         $f('student/submissions.php'),         [mw_role('student')]);
    // Full programme curriculum for the Curriculum tab.
    $r->get ('/curriculum',          $f('student/curriculum.php'),          [mw_role('student'), mw_rate_limit('student_curriculum', 30, 60)]);
    // Upcoming class sessions from LMS L2 cohorts.
    $r->get ('/upcoming-sessions',   $f('student/upcoming-sessions.php'),   [mw_role('student')]);
    // Support hub: feedback, surveys, tickets.
    $r->get ('/feedback',            $f('student/feedback.php'),            [mw_role('student')]);
    $r->post('/feedback',            $f('student/feedback.php'),            [mw_csrf(), mw_role('student'), mw_rate_limit('feedback', 20, 600)]);
    $r->get ('/surveys',             $f('student/surveys.php'),             [mw_role('student')]);
    $r->post('/surveys',             $f('student/surveys.php'),             [mw_csrf(), mw_role('student'), mw_rate_limit('survey_respond', 30, 600)]);
    $r->get ('/tickets',             $f('student/tickets.php'),             [mw_role('student')]);
    $r->post('/tickets',             $f('student/tickets.php'),             [mw_csrf(), mw_role('student'), mw_rate_limit('ticket_create', 10, 600)]);
    // Own progress report — same data as the public report but authenticated.
    // Also handles enrollment-ref generation and token management.
    $r->get ('/progress',            $f('student/progress.php'),            [mw_role('student')]);
    $r->post('/progress',            $f('student/progress.php'),            [mw_csrf(), mw_role('student'), mw_rate_limit('student_progress', 20, 600)]);
    // Per-session instructor-submitted progress reports for the student.
    $r->get ('/session-reports',     $f('student/session-reports.php'),     [mw_role('student')]);
    // Waitlist: join or leave a cohort queue when class is at capacity.
    $r->get ('/waitlist',            $f('student/waitlist.php'),            [mw_role('student')]);
    $r->post('/waitlist',            $f('student/waitlist.php'),            [mw_csrf(), mw_role('student'), mw_rate_limit('student_waitlist', 20, 600)]);
    // ID card: student views their own card serial and QR payload.
    $r->get ('/my-card',             $f('student/my-card.php'),             [mw_role('student')]);

    // Paid rescheduling (LMS 2.0 Part C) — server-priced request + wallet pay.
    $r->get ('/reschedule',          $f('student/reschedule.php'),          [mw_role('student')]);
    $r->post('/reschedule',          $f('student/reschedule.php'),          [mw_csrf(), mw_role('student'), mw_rate_limit('reschedule', 30, 600)]);
});

/* ─── Instructor portal ─── */
$r->group('/instructor', function (MamRouter $r) use ($f) {
    $r->get ('/dashboard',        $f('instructor/dashboard.php'),       [mw_role('instructor', 'admin')]);
    $r->get ('/student',          $f('instructor/student.php'),         [mw_role('instructor', 'admin')]);
    $r->post('/mark-attendance',  $f('instructor/mark-attendance.php'), [mw_csrf(), mw_role('instructor', 'admin')]);
    // Per-session QR check-in token for a class the instructor teaches (#9/#10).
    $r->get ('/attendance-qr',    $f('instructor/attendance-qr.php'),   [mw_role('instructor', 'admin')]);
    $r->post('/update-profile',   $f('instructor/update-profile.php'),  [mw_csrf(), mw_role('instructor', 'admin')]);
    $r->post('/announce',         $f('instructor/announce.php'),        [mw_csrf(), mw_role('instructor', 'admin')]);
    $r->post('/resource',         $f('instructor/resource.php'),        [mw_csrf(), mw_role('instructor', 'admin')]);
    // File upload to Drive via the Apps Script gateway (#5) → stored as a resource.
    $r->post('/drive-upload',     $f('instructor/drive-upload.php'),    [mw_csrf(), mw_role('instructor', 'admin'), mw_rate_limit('drive_upload', 40, 600)]);
    $r->post('/assignment',        $f('instructor/assignment.php'),        [mw_csrf(), mw_role('instructor', 'admin')]);
    $r->post('/assignment-delete', $f('instructor/assignment-delete.php'), [mw_csrf(), mw_role('instructor', 'admin')]);
    $r->post('/assignment-update', $f('instructor/assignment-update.php'), [mw_csrf(), mw_role('instructor', 'admin')]);
    // LMS gradebook — weekly weighted assessments → auto-computed results.
    $r->get ('/gradebook',        $f('instructor/gradebook.php'),       [mw_role('instructor', 'admin')]);
    $r->get ('/assessments',      $f('instructor/assessments.php'),     [mw_role('instructor', 'admin')]);
    $r->get ('/earnings',         $f('instructor/earnings.php'),        [mw_role('instructor', 'admin')]);
    $r->get ('/curriculum',       $f('instructor/curriculum.php'),      [mw_role('instructor', 'admin')]);
    $r->post('/curriculum',       $f('instructor/curriculum.php'),      [mw_csrf(), mw_role('instructor', 'admin'), mw_rate_limit('curriculum_propose', 40, 600)]);
    $r->post('/assess',           $f('instructor/assess.php'),          [mw_csrf(), mw_role('instructor', 'admin'), mw_rate_limit('assess', 120, 600)]);
    // LMS L2 — cohort scheduling + session attendance.
    $r->get ('/sessions',           $f('instructor/sessions.php'),           [mw_role('instructor', 'admin')]);
    $r->post('/sessions',           $f('instructor/sessions.php'),           [mw_csrf(), mw_role('instructor', 'admin')]);
    $r->get ('/session-attendance', $f('instructor/session-attendance.php'), [mw_role('instructor', 'admin')]);
    $r->post('/session-attendance', $f('instructor/session-attendance.php'), [mw_csrf(), mw_role('instructor', 'admin'), mw_rate_limit('sess_att', 240, 600)]);
    // Paid rescheduling (LMS 2.0 Part C) — approve/decline queue for owned cohorts.
    $r->get ('/reschedule',         $f('instructor/reschedule.php'),         [mw_role('instructor', 'admin')]);
    $r->post('/reschedule',         $f('instructor/reschedule.php'),         [mw_csrf(), mw_role('instructor', 'admin'), mw_rate_limit('reschedule_decide', 60, 600)]);
    // Per-session QR check-in token for a cohort session (P2 twin of attendance-qr).
    $r->get ('/session-attendance-qr', $f('instructor/session-attendance-qr.php'), [mw_role('instructor', 'admin')]);
    // ID card scan: instructor scans a student's card QR to mark attendance.
    $r->post('/card-scan', $f('instructor/card-scan.php'), [mw_csrf(), mw_role('instructor', 'admin'), mw_rate_limit('card_scan', 240, 600)]);
    $r->post('/lesson-record',         $f('instructor/lesson-record.php'),          [mw_csrf(), mw_role('instructor', 'admin'), mw_rate_limit('lesson_record', 60, 600)]);
    // Post-session progress report (v46): per-student form → email + 2-hr deadline fine.
    $r->get ('/progress-report',       $f('instructor/progress-report.php'),        [mw_role('instructor', 'admin')]);
    $r->post('/progress-report',       $f('instructor/progress-report.php'),        [mw_csrf(), mw_role('instructor', 'admin'), mw_rate_limit('progress_report', 120, 600)]);
    // Submissions review + feedback (LMS L2) + AI grading assist (L4).
    $r->get ('/submissions',        $f('instructor/submissions.php'),     [mw_role('instructor', 'admin')]);
    $r->post('/feedback',           $f('instructor/feedback.php'),        [mw_csrf(), mw_role('instructor', 'admin')]);
    $r->post('/feedback-draft',     $f('instructor/feedback-draft.php'),  [mw_csrf(), mw_role('instructor', 'admin'), mw_rate_limit('ai_draft', 30, 600)]);
});

/* ─── Admin (JSON endpoints; the HTML dashboard is at /admin/) ─── */
$r->group('/admin', function (MamRouter $r) use ($f) {
    // RBAC: sensitive control is super-only; operational endpoints allow managers.
    $r->get ('/list-users',      $f('admin/list-users.php'),     [mw_admin_cap('users')]);
    $r->post('/create-user',     $f('admin/create-user.php'),    [mw_csrf(), mw_admin_cap('users')]);
    $r->post('/reset-password',  $f('admin/reset-password.php'), [mw_csrf(), mw_admin_cap('users')]);
    $r->post('/create-class',    $f('admin/create-class.php'),   [mw_csrf(), mw_admin_cap('enrollments')]);
    $r->post('/enroll-student',  $f('admin/enroll-student.php'), [mw_csrf(), mw_admin_cap('enrollments')]);
    $r->post('/mail-test',       $f('admin/mail-test.php'),      [mw_csrf(), mw_admin_cap('mail'), mw_rate_limit('mail_test', 10, 600)]);
    // Outbound-mail outbox: viewing is operational; re-sending is super-only.
    $r->get ('/mail-log',        $f('admin/mail-log.php'),       [mw_admin_cap('view')]);
    $r->post('/mail-retry',      $f('admin/mail-retry.php'),     [mw_csrf(), mw_admin_cap('mail'), mw_rate_limit('mail_retry', 30, 600)]);
    $r->post('/migrate',         $f('admin/migrate.php'),        [mw_csrf(), mw_admin_cap('migrate'), mw_rate_limit('migrate', 5, 300)]);
    // Form builder + AI generation (v46): survey & progress-report templates.
    $r->get ('/survey-forms', $f('admin/survey-forms.php'), [mw_admin_cap('content')]);
    $r->post('/survey-forms', $f('admin/survey-forms.php'), [mw_csrf(), mw_admin_cap('content'), mw_rate_limit('survey_forms', 30, 600)]);
    // Store catalogue admin CRUD (one file dispatches by method).
    $r->get ('/store/items',     $f('admin/store-items.php'),    [mw_admin_cap('view')]);
    $r->post('/store/items',     $f('admin/store-items.php'),    [mw_csrf(), mw_admin_cap('store_catalog')]);
    // LMS course-registration approvals + instructor assignment (operational).
    $r->get ('/course-enrollments', $f('admin/course-enrollments.php'), [mw_admin_cap('enrollments')]);
    $r->post('/course-enrollments', $f('admin/course-enrollments.php'), [mw_csrf(), mw_admin_cap('enrollments')]);
    // Standard curriculum authoring + JSON/CSV import & export (one file by method).
    $r->get ('/curriculum',         $f('admin/curriculum.php'),         [mw_admin_cap('curriculum')]);
    $r->post('/curriculum',         $f('admin/curriculum.php'),         [mw_csrf(), mw_admin_cap('curriculum'), mw_rate_limit('curriculum', 120, 600)]);
    // Assign admin tiers (super-only).
    $r->post('/set-admin-tier',  $f('admin/set-admin-tier.php'), [mw_csrf(), mw_admin_cap('rbac')]);
    $r->post('/set-student-type', $f('admin/set-student-type.php'), [mw_csrf(), mw_admin_cap('users')]);
    $r->post('/wallet-credit',    $f('admin/wallet-credit.php'),    [mw_csrf(), mw_admin_cap('users')]);
    // AI placement assist (L4 / A4) — advisory pathway/level suggestion for an
    // application. Operational (admissions); rate-limited; off unless ai_provider set.
    $r->get ('/placement',       $f('admin/placement.php'),      [mw_admin_cap('admissions'), mw_rate_limit('ai_placement', 30, 600)]);
    $r->get ('/ai-triage',       $f('admin/ai-triage.php'),      [mw_admin_cap('admissions'), mw_rate_limit('ai_triage', 30, 600)]);
    // Media & documents library — aggregated class resources + portal photos.
    $r->get ('/media',           $f('admin/media.php'),          [mw_admin_cap('media')]);
    // Support hub — feedback, surveys, tickets (operational; super for full access).
    $r->get ('/support',         $f('admin/support.php'),        [mw_admin_cap('view')]);
    $r->post('/support',         $f('admin/support.php'),        [mw_csrf(), mw_admin_cap('support'), mw_rate_limit('admin_support', 60, 600)]);
    // Progress report token management (admin generates learner links).
    $r->get ('/progress-report', $f('admin/progress-report.php'), [mw_admin_cap('view')]);
    $r->post('/progress-report', $f('admin/progress-report.php'), [mw_csrf(), mw_admin_cap('enrollments'), mw_rate_limit('progress_token', 30, 600)]);
    // Instructor fine management.
    $r->get ('/fines', $f('admin/fines.php'), [mw_admin_cap('view')]);
    $r->post('/fines', $f('admin/fines.php'), [mw_csrf(), mw_admin_cap('enrollments'), mw_rate_limit('admin_fines', 60, 600)]);
    // Enterprise scheduling: academy-wide calendar + cohort waitlist management.
    $r->get ('/schedule', $f('admin/schedule.php'),  [mw_admin_cap('view')]);
    $r->get ('/waitlist', $f('admin/waitlist.php'),  [mw_admin_cap('enrollments')]);
    $r->post('/waitlist', $f('admin/waitlist.php'),  [mw_csrf(), mw_admin_cap('enrollments')]);
    // On-demand self-heal: re-runs criticalRepairs + required-column verification; returns status report.
    $r->get ('/heal',     $f('admin/heal.php'),      [mw_admin_cap('settings'), mw_rate_limit('admin_heal', 10, 300)]);
    // Summer programme admin: list applications + update status.
    $r->get ('/summer',         $f('admin/summer.php'), [mw_admin_cap('view')]);
    $r->post('/summer/status',  $f('admin/summer.php'), [mw_csrf(), mw_admin_cap('view')]);
    // Headshot upload for staff fixing a card photo. Same handler as the public
    // route, but admin-gated — so it carries a limit fit for working through a
    // batch of students rather than the tight cap an anonymous form needs.
    $r->post('/upload-headshot', $f('upload-headshot.php'), [mw_csrf(), mw_admin_cap('enrollments'), mw_rate_limit('admin_upload_headshot', 120, 600)]);
    // ID cards: list all cards + admin actions (reissue, revoke, mark printed).
    $r->get ('/id-cards',       $f('admin/id-cards.php'),      [mw_admin_cap('enrollments')]);
    $r->post('/id-cards',       $f('admin/id-cards.php'),      [mw_csrf(), mw_admin_cap('enrollments')]);
    $r->post('/session-remind', $f('admin/session-remind.php'), [mw_csrf(), mw_admin_cap('enrollments')]);
});

/* ─── Dispatch ─── */
$route  = '/' . trim((string)($_GET['route'] ?? ''), '/');
// Versioned prefix: /api/v1/... is an alias of the current (v1) surface, so
// integrations can pin a major. An unknown major fails loudly instead of
// silently hitting the wrong contract.
if (preg_match('#^/v(\d+)(/.*)?$#', $route, $vm)) {
    if ((int)$vm[1] !== (int)explode('.', MAM_API_VERSION)[0]) {
        mam_fail('unsupported_api_version', 404, ['supported' => 'v' . explode('.', MAM_API_VERSION)[0], 'requested' => 'v' . $vm[1]]);
    }
    $route = ($vm[2] ?? '') !== '' ? $vm[2] : '/';
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$r->dispatch($method, $route);
