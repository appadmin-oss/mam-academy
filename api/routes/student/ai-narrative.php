<?php
/**
 * GET /api/student/ai-narrative
 *
 * A short, AI-generated progress note for the signed-in student, built from
 * their finalized results via the provider-agnostic AI gateway (api/modules/integrations/lib/ai.php).
 * Lazily fetched by the portal:
 *   - AI off            → {enabled:false, narrative:null}  (portal shows nothing,
 *                          and no model call is made)
 *   - AI on             → {enabled:true,  narrative:{text,provider,model}|null}
 * Read-only, quota'd, and audited in ai_runs. Never an error path for the portal.
 */
declare(strict_types=1);

$u = mam_require_role('student');

if (!mam_ai_enabled('progress_narrative')) {
    mam_ok(['enabled' => false, 'narrative' => null]);
}

mam_ok(['enabled' => true, 'narrative' => mam_ai_progress_narrative((int)$u['id'])]);
