<?php
/**
 * POST /api/ai/chat   —  the "Ask MAM" assistant (provider-agnostic gateway)
 *
 * Body JSON: { context: 'site'|'student'|'admin'|'practice', messages: [{role,content}] }
 *   → { reply }
 *
 * One endpoint, four personas. The context picks a server-side system prompt
 * (authoritative — the browser can't change MAM's facts) and the access rule:
 *   site     → public (rate-limited)
 *   student  → logged-in users      practice → logged-in users
 *   admin    → admins only
 * CSRF + a per-IP rate limit are applied by the router. Stays a clean JSON error
 * when the provider is unset or hiccups — never a 500.
 */
declare(strict_types=1);

if (!mam_ai_configured()) {
    mam_fail('ai_unconfigured', 503, ['message' => 'The assistant isn\'t enabled on this server yet.']);
}

$in = mam_json_input();
$context = preg_replace('/[^a-z]/', '', strtolower((string)($in['context'] ?? 'site')));
if (!in_array($context, ['site', 'student', 'admin', 'practice'], true)) $context = 'site';

// Access control per persona.
if ($context === 'admin') { mam_require_admin(); }
elseif ($context === 'student' || $context === 'practice') { mam_require_user(); }

$messages = is_array($in['messages'] ?? null) ? $in['messages'] : [];
if (!$messages) mam_fail('no_message', 400, ['message' => 'Ask a question to get started.']);

// Shared, grounded facts — kept general so the model never invents prices/dates.
$base = "MAM Academy is a Creative Intelligence & Performance music academy, online and onsite in Lagos, Nigeria. "
      . "Programmes: Certified Musician, Grand Musician, the Crash Programme (6 weeks) and the MetaMusic Order (12 weeks). "
      . "Entry is through the MAM Auditer assessment. It offers Trinity-equivalent certification and teaches the MAM Method / Rhythm Intelligence. "
      . "People apply at /apply and can reach the academy at academy@mamcentre.org. "
      . "Never invent specific prices, dates, schedules or policies — for those, point people to the relevant page on the site or to contact the academy.";

$personas = [
    'site'     => "You are the friendly MAM Academy website assistant for visitors and prospective students. Answer questions about the programmes, the MAM Auditer, applying, online vs onsite study, and general music questions. Keep replies concise (2–5 sentences), warm and accurate. If something is account-specific or you're unsure, suggest the relevant page or contacting the academy.",
    'student'  => "You are a supportive music tutor for an enrolled MAM Academy student. Help with music theory, technique, practice planning and understanding their assignments. Be encouraging and concise. You do not have access to their private records — if asked about grades, payments or schedules, point them to the portal.",
    'practice' => "You are a practice coach inside MAM Academy's Practice Studio. Give short, actionable practice tips, suggest what to work on, and explain musical concepts simply and encouragingly.",
    'admin'    => "You assist MAM Academy staff. Help draft announcements, emails and summaries in a professional, warm tone, and answer operational questions. Be concise and offer to revise.",
];
$system = $base . "\n\n" . ($personas[$context] ?? $personas['site']);

try {
    $reply = mam_ai_chat($messages, $system, ['max_tokens' => 700]);
} catch (Throwable $e) {
    mam_log('error', 'ai_chat_failed', ['err' => $e->getMessage(), 'provider' => mam_ai_provider(), 'context' => $context]);
    mam_fail('ai_failed', 502, ['message' => 'The assistant is having a moment — please try again.']);
}

mam_log('info', 'ai_chat', ['context' => $context, 'provider' => mam_ai_provider(), 'turns' => count($messages)]);
mam_ok(['reply' => $reply]);
