<?php
/**
 * GET /api/session/current
 *
 * Public, unauthenticated. Returns the operative academic session resolved from
 * the calendar (see api/modules/academics/lib/academic-session.php), plus the session currently
 * being promoted and its promo-window state. The application form reads this so
 * the session is applied silently — it is never a form step nor user-editable.
 *
 *   { ok:true,
 *     code:'medi', label:'MEDI Term', year:2026,
 *     starts_on:'…', ends_on:'…', status:'active'|'upcoming'|'break'|'ended',
 *     source:'default'|'admin',
 *     next:{ code, label, year, starts_on, promo_lead_days, promo_starts_on, promoting } }
 */
declare(strict_types=1);

// Short browser cache: the calendar shifts by the day at most, and this is hit
// on form load. 5 minutes keeps it fresh while sparing the DB.
if (!headers_sent()) header('Cache-Control: public, max-age=300');

mam_ok(mam_session_current());
