<?php
/**
 * POST /api/parent/channels {whatsapp, email, sms} — how we reach you.
 *
 * The response carries the OVERRIDE sentence as data, so the screen states beside
 * the toggles that anything about safeguarding or money reaches the guardian
 * regardless of them. A toggle that silently does less than a family thinks is
 * worse than no toggle.
 */
declare(strict_types=1);
$u  = mam_require_role('parent');
$in = mam_json_input();
mam_ok([
    'channels' => mam_guardian_prefs_set((int) $u['id'], $in),
    'override' => mam_guardian_channels((int) $u['id'], 'safeguarding'),
]);
