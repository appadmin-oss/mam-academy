<?php
/**
 * POST /api/studio/feedback
 *
 * AI practice feedback (Studio S3) for a Rhythm/Pitch run. Body is NON-PII:
 * {tool, score, metrics:{}, weak:[]}. Any signed-in user (the scored tools are
 * student-gated). Off unless ai_provider is set; quota'd + audited in ai_runs.
 *   - AI off → {enabled:false, feedback:null}  (no model call)
 *   - AI on  → {enabled:true,  feedback:{text,provider}|null}
 */
declare(strict_types=1);

mam_require_user();

if (!mam_ai_enabled('studio_feedback')) {
    mam_ok(['enabled' => false, 'feedback' => null]);
}

$in = mam_json_input();
mam_ok(['enabled' => true, 'feedback' => mam_ai_studio_feedback([
    'tool'    => (string)($in['tool'] ?? ''),
    'score'   => (int)($in['score'] ?? 0),
    'metrics' => is_array($in['metrics'] ?? null) ? $in['metrics'] : [],
    'weak'    => is_array($in['weak'] ?? null) ? $in['weak'] : [],
])]);
