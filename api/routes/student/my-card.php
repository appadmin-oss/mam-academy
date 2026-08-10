<?php
/**
 * GET /api/student/my-card
 *
 * Returns the logged-in student's active ID card, including the QR payload
 * they would encode on their physical card.
 */
declare(strict_types=1);

// Identity comes from the DB-backed cookie session (this app never populates
// PHP's native $_SESSION), and the route is already gated by mw_role('student').
$userId = (int)mam_require_role('student')['id'];

$card = mam_card_for_user($userId);
if (!$card) {
    mam_ok(['card' => null]);
}

mam_ok([
    'card' => [
        'id'           => (int)$card['id'],
        'serial'       => (string)$card['serial'],
        'role'         => (string)$card['role'],
        'session_code' => (string)$card['session_code'],
        'status'       => (string)$card['status'],
        'issued_at'    => (string)$card['issued_at'],
        'printed_at'   => $card['printed_at'],
        'qr_payload'   => mam_card_qr_payload($card),
    ],
]);
