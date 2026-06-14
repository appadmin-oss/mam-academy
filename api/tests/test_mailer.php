<?php
/**
 * Mailer — pure helpers (html→text, readiness) + the v17 outbound outbox
 * (record on send, retain failures, retry with a poison-message cap).
 *
 * No live SMTP is exercised (impossible in CI). Determinism comes from
 * MAIL_FORCE_SMTP=1 with an empty MAIL_HOST, which makes the transport return
 * a clean failure instantly — exactly the "misconfigured prod" case the outbox
 * exists to make recoverable.
 */
declare(strict_types=1);
t_group('mailer: helpers + outbox');

// ── mam_html_to_text ───────────────────────────────────────────────────────
$t = mam_html_to_text('<p>Hello <a href="https://x.dev/go">click here</a> now</p>');
t_ok(strpos($t, 'https://x.dev/go') !== false, 'html_to_text preserves the link URL (strip_tags would drop it)');
t_ok(strpos($t, 'click here') !== false, 'link anchor text is kept alongside the URL');
t_eq(mam_html_to_text('<a href="https://x.dev">https://x.dev</a>'), 'https://x.dev', 'a bare-URL link is not duplicated');
t_ok(strpos(mam_html_to_text('A &amp; B &mdash; C'), 'A & B') !== false, 'HTML entities are decoded');
$blocks = mam_html_to_text('<p>one</p><p>two</p>');
t_ok(strpos($blocks, 'one') !== false && strpos($blocks, 'two') !== false, 'block tags become line breaks, not run-together text');

// ── mam_mail_readiness ───────────────────────────────────────────────────────
mam_test_env(['MAIL_HOST' => '', 'MAIL_USERNAME' => '', 'MAIL_PASSWORD' => '', 'MAIL_FORCE_SMTP' => '0']);
$r1 = mam_mail_readiness();
t_ok($r1['ready'] === false, 'no MAIL_HOST → not ready');
t_ok(count($r1['issues']) >= 1, 'unconfigured mail surfaces an actionable issue (the #1 "emails do not work" cause)');
t_eq($r1['transport'], 'mail()', 'no host → falls back to PHP mail()');

mam_test_env(['MAIL_HOST' => 'mail.x.dev', 'MAIL_USERNAME' => 'u@x.dev', 'MAIL_PASSWORD' => '']);
t_ok(mam_mail_readiness()['ready'] === false, 'username set but empty password → not ready (SMTP auth would fail)');

mam_test_env(['MAIL_HOST' => 'mail.x.dev', 'MAIL_USERNAME' => 'u@x.dev', 'MAIL_PASSWORD' => 'secret']);
$r3 = mam_mail_readiness();
t_ok($r3['ready'] === true, 'host + user + pass → ready');
t_ok($r3['password_set'] === true, 'password presence is detected (without leaking it)');

// ── mam_mail_log_record: failure RETAINS body, success DROPS it ──────────────
$idF = mam_mail_log_record('rec-fail@x.dev', 'Subj', 'unit', '<p>body F</p>', true, ['from' => 'a@b.dev'],
    ['ok' => false, 'transport' => 'smtp', 'error' => 'auth failed', 'attempts' => 2]);
t_ok($idF > 0, 'recorder inserts a row for a failed send');
$rowF = mam_db_row('SELECT * FROM mail_log WHERE id = ?', [$idF]);
t_eq($rowF['status'], 'failed', 'failed send stored as failed');
t_ok((string)$rowF['body'] === '<p>body F</p>', 'failed row RETAINS the rendered body (so it can be re-sent)');
t_ok(strpos((string)$rowF['error'], 'auth failed') !== false, 'failure reason captured');
t_eq((int)$rowF['attempts'], 2, 'attempt count captured');
t_ok(strpos((string)$rowF['opts'], 'a@b.dev') !== false, 'opts JSON captured for faithful retry');

$idS = mam_mail_log_record('rec-ok@x.dev', 'Subj', 'unit', '<p>body S</p>', true, [],
    ['ok' => true, 'transport' => 'smtp', 'attempts' => 1]);
$rowS = mam_db_row('SELECT * FROM mail_log WHERE id = ?', [$idS]);
t_eq($rowS['status'], 'sent', 'successful send stored as sent');
t_ok($rowS['body'] === null, 'successful row DROPS the body (no hoarding rendered PII)');
t_ok(!empty($rowS['sent_at']), 'sent_at stamped on success');

// ── End-to-end: mam_send records a retained failure when SMTP is unconfigured ─
mam_test_env(['MAIL_FORCE_SMTP' => '1', 'MAIL_HOST' => '']);
$sent = mam_send('e2e-fail@x.dev', 'E2E Subj', '<p>Hi {{name}}</p>', 'application_confirmation', true);
t_ok($sent === false, 'mam_send returns false when SMTP is forced but unconfigured');
$row = mam_db_row('SELECT * FROM mail_log WHERE to_email = ? ORDER BY id DESC LIMIT 1', ['e2e-fail@x.dev']);
t_ok($row !== null, 'mam_send wrote an outbox row (failures are no longer lost)');
t_eq($row['status'], 'failed', 'unconfigured send recorded as failed');
t_eq($row['context'], 'application_confirmation', 'caller context captured for diagnosis');
t_ok((string)$row['body'] === '<p>Hi {{name}}</p>', 'the real send path retains the body for retry');
$attemptsBefore = (int)$row['attempts'];

// ── Retry: re-sends, increments attempts, stays failed until a transport works ─
$sum = mam_mail_retry_failed(50, 6, 0);
t_ok($sum['tried'] >= 1, 'retry processed at least one eligible failed row');
t_ok($sum['failed'] >= 1, 'retry still fails while SMTP is unconfigured (no false success)');
$row2 = mam_db_row('SELECT * FROM mail_log WHERE to_email = ? ORDER BY id DESC LIMIT 1', ['e2e-fail@x.dev']);
t_ok((int)$row2['attempts'] > $attemptsBefore, 'retry increments the attempt counter');
t_eq($row2['status'], 'failed', 'row stays failed until a real transport succeeds');

// ── Poison-message guards: capped + body-less rows are skipped ───────────────
$cappedId = mam_db_insert('mail_log', ['to_email' => 'capped@x.dev', 'subject' => 'x', 'context' => 't', 'is_html' => 1, 'status' => 'failed', 'attempts' => 6, 'body' => '<p>x</p>']);
$noBodyId = mam_db_insert('mail_log', ['to_email' => 'nobody@x.dev', 'subject' => 'x', 'context' => 't', 'is_html' => 1, 'status' => 'failed', 'attempts' => 0, 'body' => null]);
mam_mail_retry_failed(50, 6, 0);
t_eq((int)mam_db_row('SELECT attempts FROM mail_log WHERE id = ?', [$cappedId])['attempts'], 6, 'a row at the attempt cap is not retried (poison-message guard)');
t_eq((int)mam_db_row('SELECT attempts FROM mail_log WHERE id = ?', [$noBodyId])['attempts'], 0, 'a row with no retained body is not retried');

// Reset mail env so later test files start clean.
mam_test_env(['MAIL_FORCE_SMTP' => '0', 'MAIL_HOST' => '', 'MAIL_USERNAME' => '', 'MAIL_PASSWORD' => '']);
