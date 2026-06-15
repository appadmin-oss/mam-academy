<?php
/**
 * Provider-agnostic AI gateway. Exercised entirely through the offline `mock`
 * adapter (no network, no keys) — proving the gateway, audit, schema handling,
 * PII redaction, and the first use case without any real provider. Real
 * adapters (Ollama/Groq/OpenRouter/…) share the same contract but can't be hit
 * in CI.
 */
declare(strict_types=1);
t_group('ai gateway (provider-agnostic, mock adapter)');

// ── Disabled by default: no provider → off, no external call, no audit row ──
mam_test_env(['AI_PROVIDER' => 'none']);
t_ok(mam_ai_enabled() === false, 'AI is disabled when ai_provider=none');
$before = (int)(mam_db_row('SELECT COUNT(*) AS n FROM ai_runs')['n'] ?? 0);
$off = mam_ai_complete('unit', 'hello');
t_ok($off['ok'] === false && $off['error'] === 'disabled', 'a disabled gateway returns ok=false/disabled (graceful)');
t_eq((int)(mam_db_row('SELECT COUNT(*) AS n FROM ai_runs')['n'] ?? 0), $before, 'a disabled call records nothing');

// ── Mock provider: completes + audits ──
mam_test_env(['AI_PROVIDER' => 'mock']);
t_ok(mam_ai_enabled() === true, 'AI enabled once a provider is set');
$r = mam_ai_complete('unit_text', 'Summarise the lesson');
t_ok($r['ok'] === true, 'mock completion succeeds');
t_eq($r['provider'], 'mock', 'result names the provider');
t_ok(strpos((string)$r['text'], '[mock]') === 0, 'mock echoes a deterministic answer');
$row = mam_db_row("SELECT * FROM ai_runs WHERE use_case = 'unit_text' ORDER BY id DESC LIMIT 1");
t_ok($row !== null, 'the run is recorded in ai_runs (audited)');
t_eq((int)$row['ok'], 1, 'audit row marks success');
t_eq($row['provider'], 'mock', 'audit row carries the provider');
t_ok(!empty($row['prompt_hash']) && strpos($row['prompt_hash'], 'Summarise') === false, 'audit stores a prompt HASH, not the raw prompt (privacy)');

// ── Schema path: structured JSON comes back parsed + schema-shaped ──
$schema = ['type' => 'object', 'properties' => ['level' => ['type' => 'string'], 'score' => ['type' => 'integer'], 'ready' => ['type' => 'boolean']], 'required' => ['level']];
$s = mam_ai_complete('unit_schema', 'Place this student', ['schema' => $schema]);
t_ok($s['ok'] === true && is_array($s['json']), 'schema request returns parsed JSON');
t_ok(array_key_exists('level', $s['json']) && array_key_exists('score', $s['json']) && array_key_exists('ready', $s['json']), 'JSON matches the requested schema keys');
t_ok($s['json']['score'] === 0 && $s['json']['ready'] === false, 'schema types are honoured (number→0, boolean→false)');

// ── Pure helpers ──
$red = mam_ai_redact('Reach Ada at ada@example.com or +234 802 123 4567 today');
t_ok(strpos($red, '[email]') !== false && strpos($red, 'ada@example.com') === false, 'redact() strips emails before egress');
t_ok(strpos($red, '[phone]') !== false, 'redact() strips phone numbers');
t_eq(mam_ai_extract_json("```json\n{\"a\":1,\"b\":\"x\"}\n```")['a'], 1, 'extract_json() recovers JSON from a fenced/prose blob');
t_ok(mam_ai_extract_json('not json at all') === null, 'extract_json() returns null when there is no JSON');

// ── readiness summary ──
$rd = mam_ai_readiness();
t_ok($rd['enabled'] === true && $rd['provider'] === 'mock', 'readiness reflects the configured provider');
mam_test_env(['AI_PROVIDER' => 'none']);
t_ok(mam_ai_readiness()['enabled'] === false && count(mam_ai_readiness()['issues']) >= 1, 'readiness flags a disabled gateway with an actionable issue');

// ── First use case: progress narrative (read-only) ──
mam_test_env(['AI_PROVIDER' => 'none']);
$sid = mam_db_insert('users', ['email' => 't_ai_stu@x.dev', 'password_hash' => 'x', 'role' => 'student', 'name' => 'Ada Obi', 'status' => 'active']);
$cid = (int)mam_db_row("SELECT id FROM courses WHERE code = 'piano'")['id'];
$eid = mam_db_insert('course_enrollments', ['student_id' => $sid, 'course_id' => $cid, 'status' => 'active']);
mam_db_insert('results', ['enrollment_id' => $eid, 'total_score' => 88, 'grade' => 'A', 'outcome' => 'pass']);
t_ok(mam_ai_progress_narrative($sid) === null, 'progress narrative is null when AI is off (caller falls back)');
mam_test_env(['AI_PROVIDER' => 'mock']);
$narr = mam_ai_progress_narrative($sid);
t_ok(is_array($narr) && !empty($narr['text']), 'progress narrative returns text when AI is enabled');
t_eq($narr['provider'], 'mock', 'narrative carries the provider used');

// ── L4 use case: practice coach (read-only, from the student's own logs) ──
mam_db_insert('practice_logs', ['student_id' => $sid, 'minutes' => 45, 'focus' => 'scales', 'notes' => 'C and G major', 'logged_on' => date('Y-m-d')]);
mam_db_insert('practice_logs', ['student_id' => $sid, 'minutes' => 30, 'focus' => 'repertoire', 'logged_on' => date('Y-m-d', strtotime('-2 days'))]);
mam_test_env(['AI_PROVIDER' => 'none']);
t_ok(mam_ai_practice_coach($sid) === null, 'practice coach is null when AI is off (caller falls back)');
mam_test_env(['AI_PROVIDER' => 'mock']);
$coach = mam_ai_practice_coach($sid);
t_ok(is_array($coach) && !empty($coach['text']), 'practice coach returns a plan when AI is enabled');
t_eq($coach['provider'], 'mock', 'coach carries the provider used');
$crow = mam_db_row("SELECT use_case FROM ai_runs WHERE use_case = 'practice_coach' ORDER BY id DESC LIMIT 1");
t_ok($crow !== null, 'the practice-coach run is audited in ai_runs');

// ── L4 / A4 use case: placement recommendation (schema-constrained, advisory) ──
$pcid = mam_db_insert('customers', ['email' => 't_ai_app@x.dev', 'first_name' => 'Bem', 'last_name' => 'T', 'age_range' => '16-20']);
$paid = mam_db_insert('applications', ['reference' => 'MAM-AI-PLACE-1', 'customer_id' => $pcid, 'pathway' => 'standard', 'mode' => 'online', 'discipline' => 'piano', 'experience' => '2 years self-taught', 'goals' => 'play in church', 'status' => 'submitted']);
mam_test_env(['AI_PROVIDER' => 'none']);
t_ok(mam_ai_placement_recommendation($paid) === null, 'placement recommendation is null when AI is off');
mam_test_env(['AI_PROVIDER' => 'mock']);
$rec = mam_ai_placement_recommendation($paid, 1);
t_ok(is_array($rec) && is_array($rec['json']), 'placement returns a parsed JSON recommendation when AI is on');
foreach (['recommended_pathway', 'recommended_level', 'confidence', 'rationale'] as $k) {
    t_ok(array_key_exists($k, $rec['json']), "placement JSON carries '$k'");
}
t_eq($rec['provider'], 'mock', 'placement carries the provider used');
t_ok(mam_ai_placement_recommendation(999999) === null, 'placement for a missing application is null (no crash)');
$prow = mam_db_row("SELECT reviewed_by FROM ai_runs WHERE use_case = 'placement' ORDER BY id DESC LIMIT 1");
t_ok($prow !== null && (int)$prow['reviewed_by'] === 1, 'the placement run is audited with the reviewer id');

// Reset so later test files start clean.
mam_test_env(['AI_PROVIDER' => 'none']);
