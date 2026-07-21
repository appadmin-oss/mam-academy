<?php
/**
 * GET  /api/student/surveys         → active surveys (with responded flag)
 * POST /api/student/surveys         → submit a survey response
 */
declare(strict_types=1);
$u = mam_require_role('student');
$track = (string)($_GET['track'] ?? 'MAM');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $surveys = mam_surveys_active($track);
    foreach ($surveys as &$s) {
        $s['questions']  = json_decode((string)$s['questions_json'], true) ?: [];
        $s['responded']  = mam_survey_responded((int)$s['id'], (int)$u['id']);
        unset($s['questions_json']);
    }
    unset($s);
    mam_ok(['surveys' => $surveys]);
    exit;
}

$in        = mam_json_input();
$surveyId  = (int)($in['survey_id'] ?? 0);
$answers   = (isset($in['answers']) && is_array($in['answers'])) ? $in['answers'] : [];
if ($surveyId < 1)    mam_fail('missing_field', 400, ['field' => 'survey_id']);
if (empty($answers))  mam_fail('empty_answers', 422, ['field' => 'answers']);

try {
    $id = mam_survey_respond($surveyId, (int)$u['id'], $answers);
    mam_notify((int)$u['id'], 'survey_submitted', 'Survey submitted', 'Thank you for your response!');
    mam_ok(['id' => $id]);
} catch (\RuntimeException $e) {
    $code = $e->getMessage() === 'already_responded' ? 409 : 404;
    mam_fail($e->getMessage(), $code);
}
