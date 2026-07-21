<?php
/**
 * Admin form-builder endpoint (v46).
 *
 * GET  /api/admin/survey-forms              → {forms:[…]}
 * POST {action:'create',  title, description?, questions, kind?}       → {id}
 * POST {action:'update',  id, title?, description?, questions?, status?} → {ok}
 * POST {action:'delete',  id}                                           → {ok}
 * POST {action:'set_active', id}  — makes one form the active progress_report template
 * POST {action:'ai_generate', prompt, kind?} → {questions:[…]}
 */
declare(strict_types=1);

$u = mam_require_role('admin');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $kind = isset($_GET['kind']) ? (string)$_GET['kind'] : '';
    $where = $kind !== '' ? "WHERE kind = " . mam_db()->quote($kind) : '';
    $forms = mam_db_all(
        "SELECT s.id, s.title, s.description, s.kind, s.status, s.target_track,
                s.closes_at, s.created_at,
                u.name AS created_by_name,
                (SELECT COUNT(*) FROM survey_responses sr WHERE sr.survey_id = s.id) AS response_count
           FROM surveys s LEFT JOIN users u ON u.id = s.created_by
           $where
           ORDER BY s.kind, s.created_at DESC LIMIT 500"
    );
    foreach ($forms as &$f) {
        $f['questions'] = json_decode((string)(mam_db_row('SELECT questions_json FROM surveys WHERE id = ?', [(int)$f['id']])['questions_json'] ?? '[]'), true) ?: [];
    }
    unset($f);
    mam_ok(['forms' => $forms]);
}

$in     = mam_json_input();
$action = strtolower(trim((string)($in['action'] ?? '')));

/* ── AI generation ── */
if ($action === 'ai_generate') {
    $prompt = trim((string)($in['prompt'] ?? ''));
    if ($prompt === '') mam_fail('missing_field', 400, ['field' => 'prompt']);
    $kind = (string)($in['kind'] ?? 'progress_report');

    $systemPrompt = $kind === 'progress_report'
        ? 'You are a music education specialist designing post-session progress report forms. Return JSON only.'
        : 'You are an education survey designer. Return JSON only.';

    $fullPrompt = "Design a form for: {$prompt}\n\n"
        . "Return a JSON array of question objects. Each object must have:\n"
        . "  - \"label\": string (the question text)\n"
        . "  - \"type\": one of \"text\", \"textarea\", \"rating\", \"select\", \"checkbox\"\n"
        . "  - \"required\": boolean\n"
        . "  - \"options\": array of strings (only for select/checkbox types)\n"
        . "  - \"placeholder\": string (optional, for text/textarea)\n\n"
        . "Return ONLY the JSON array, no prose.";

    $result = mam_ai_complete('form_builder', $fullPrompt, [
        'system'     => $systemPrompt,
        'max_tokens' => 1200,
        'schema'     => ['type' => 'array'],
    ]);

    if (!$result['ok']) {
        mam_fail('ai_unavailable', 503, ['detail' => $result['error'] ?? 'AI provider not configured']);
    }

    // Parse JSON from AI response
    $text = trim((string)($result['text'] ?? ''));
    // Strip markdown code fences if present
    $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
    $text = preg_replace('/\s*```$/', '', $text);
    $questions = json_decode($text, true);
    if (!is_array($questions)) {
        mam_fail('ai_parse_error', 502, ['raw' => mb_substr($text, 0, 300)]);
    }
    mam_ok(['questions' => $questions, 'provider' => $result['provider'], 'model' => $result['model']]);
}

/* ── Create ── */
if ($action === 'create') {
    $title     = trim((string)($in['title'] ?? ''));
    $questions = (isset($in['questions']) && is_array($in['questions'])) ? $in['questions'] : [];
    $kind      = in_array($in['kind'] ?? '', ['survey', 'progress_report'], true) ? (string)$in['kind'] : 'survey';
    if ($title === '') mam_fail('missing_field', 400, ['field' => 'title']);

    $id = mam_db_insert('surveys', [
        'title'          => mb_substr($title, 0, 200),
        'description'    => isset($in['description']) ? mb_substr(trim((string)$in['description']), 0, 2000) : null,
        'questions_json' => json_encode($questions, JSON_UNESCAPED_UNICODE),
        'kind'           => $kind,
        'status'         => 'draft',
        'target_track'   => isset($in['target_track']) ? (string)$in['target_track'] : null,
        'created_by'     => (int)$u['id'],
    ]);
    mam_audit('survey_create', 'survey', $id, ['kind' => $kind, 'title' => $title]);
    mam_ok(['id' => $id]);
}

/* ── Update ── */
if ($action === 'update') {
    $id = (int)($in['id'] ?? 0);
    if ($id < 1) mam_fail('missing_field', 400, ['field' => 'id']);
    if (!mam_db_row('SELECT 1 FROM surveys WHERE id = ?', [$id])) mam_fail('not_found', 404);
    $upd = [];
    if (isset($in['title']))       $upd['title']          = mb_substr(trim((string)$in['title']), 0, 200);
    if (isset($in['description'])) $upd['description']    = mb_substr(trim((string)$in['description']), 0, 2000);
    if (isset($in['questions']) && is_array($in['questions'])) $upd['questions_json'] = json_encode($in['questions'], JSON_UNESCAPED_UNICODE);
    if (isset($in['status']) && in_array($in['status'], ['draft', 'active', 'closed'], true)) $upd['status'] = (string)$in['status'];
    if (isset($in['closes_at']))   $upd['closes_at']      = (string)$in['closes_at'] ?: null;
    if (!empty($upd)) mam_db_update('surveys', $upd, ['id' => $id]);
    mam_ok(['ok' => true]);
}

/* ── Set active progress_report template ── */
if ($action === 'set_active') {
    $id = (int)($in['id'] ?? 0);
    if ($id < 1) mam_fail('missing_field', 400, ['field' => 'id']);
    $row = mam_db_row("SELECT kind FROM surveys WHERE id = ?", [$id]);
    if (!$row) mam_fail('not_found', 404);
    // Deactivate any other progress_report templates first
    mam_db()->prepare("UPDATE surveys SET status='draft' WHERE kind='progress_report' AND status='active'")->execute();
    mam_db_update('surveys', ['status' => 'active'], ['id' => $id]);
    mam_ok(['ok' => true]);
}

/* ── Delete ── */
if ($action === 'delete') {
    $id = (int)($in['id'] ?? 0);
    if ($id < 1) mam_fail('missing_field', 400, ['field' => 'id']);
    mam_db()->prepare('DELETE FROM surveys WHERE id = ?')->execute([$id]);
    mam_audit('survey_delete', 'survey', $id, []);
    mam_ok(['ok' => true]);
}

mam_fail('unknown_action', 400);
