<?php
/**
 * GET /api/student/practice-coach
 *
 * A short, AI-generated practice plan for the signed-in student, built from
 * their own practice logs + finalized results via the provider-agnostic AI
 * gateway (api/modules/integrations/lib/ai.php). Lazily fetched by the portal:
 *   - AI off → {enabled:false, coach:null}   (portal shows nothing; no model call)
 *   - AI on  → {enabled:true,  coach:{text,provider,model}|null}
 * Read-only, quota'd, and audited in ai_runs. Never an error path for the portal.
 */
declare(strict_types=1);

$u = mam_require_role('student');

if (!mam_ai_enabled('practice_coach')) {
    mam_ok(['enabled' => false, 'coach' => null]);
}

mam_ok(['enabled' => true, 'coach' => mam_ai_practice_coach((int)$u['id'])]);
