<?php
/**
 * POST /api/student/id-card — a student claims their own ID card.
 *
 * Issues the card (and its email) when the student is enrolled and has none;
 * returns the existing card otherwise. A screen view never creates a record,
 * which is why this is a POST beside the read-only `?screen=card`.
 */
declare(strict_types=1);

$u = mam_require_role('student');
try {
    $card = mam_card_claim_for_student((int) $u['id']);
} catch (RuntimeException $e) {
    mam_fail($e->getMessage() === 'not_enrolled' ? 'not_enrolled' : 'card_unavailable', $e->getMessage() === 'not_enrolled' ? 409 : 500);
}
if (!$card) mam_fail('card_unavailable', 500);
mam_audit('id_card_claim', 'user', (int) $u['id'], ['serial' => (string) $card['serial']]);
mam_ok(mam_card_portal_view((int) $u['id']));
