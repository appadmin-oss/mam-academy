<?php
/**
 * GET  /api/admin/id-cards   — paginated list of all ID cards
 * POST /api/admin/id-cards   — admin actions: reissue, revoke, mark_printed
 */
declare(strict_types=1);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    $input  = mam_json_input();
    $action = (string)($input['action'] ?? '');
    $cardId = (int)($input['card_id'] ?? 0);
    $adminId = (int)($_SESSION['user_id'] ?? 0);

    if (!$cardId) mam_fail('invalid_params', 422);

    if ($action === 'revoke') {
        $reason = trim((string)($input['reason'] ?? 'admin_revoke'));
        if ($reason === '') $reason = 'admin_revoke';
        mam_card_revoke($cardId, $reason, $adminId);
        mam_ok(['ok' => true, 'action' => 'revoked']);
    }

    if ($action === 'reissue') {
        $newCard = mam_card_reissue($cardId, $adminId);
        $name    = mam_card_holder_name($newCard);
        // Re-send email for the new card.
        $email = null;
        if ($newCard['user_id'] !== null) {
            $u = mam_db_row('SELECT email FROM users WHERE id = ?', [(int)$newCard['user_id']]);
            if ($u) $email = (string)$u['email'];
        } elseif ($newCard['summer_app_id'] !== null) {
            $a = mam_db_row('SELECT email FROM summer_applications WHERE id = ?', [(int)$newCard['summer_app_id']]);
            if ($a) $email = (string)$a['email'];
        }
        if ($email) {
            mam_after_response(fn() => mam_card_send_email($newCard, $email, $name));
        }
        mam_ok(['ok' => true, 'action' => 'reissued', 'new_serial' => $newCard['serial']]);
    }

    if ($action === 'mark_printed') {
        mam_card_mark_printed($cardId);
        mam_ok(['ok' => true, 'action' => 'mark_printed']);
    }

    mam_fail('invalid_action', 422);
}

// GET — paginated list with holder name join.
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;
$status  = (string)($_GET['status'] ?? '');
$role    = (string)($_GET['role'] ?? '');
$q       = trim((string)($_GET['q'] ?? ''));

$where  = [];
$params = [];
if ($status !== '') { $where[] = 'ic.status = ?'; $params[] = $status; }
if ($role   !== '') { $where[] = 'ic.role = ?';   $params[] = $role; }
if ($q !== '') {
    $like     = '%' . $q . '%';
    $where[]  = '(ic.serial LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';
    $params   = array_merge($params, [$like, $like, $like]);
}

$wc = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int)(mam_db_row(
    "SELECT COUNT(*) AS n FROM id_cards ic LEFT JOIN users u ON u.id = ic.user_id $wc",
    $params
)['n'] ?? 0);

$rows = mam_db_all(
    "SELECT ic.id, ic.serial, ic.role, ic.status, ic.session_code,
            ic.issued_at, ic.printed_at, ic.revoked_at, ic.revoke_reason,
            ic.user_id, ic.summer_app_id, ic.version,
            u.name AS user_name, u.email AS user_email, u.avatar_url AS user_photo,
            sa.first_name AS sa_first, sa.last_name AS sa_last, sa.email AS sa_email
       FROM id_cards ic
       LEFT JOIN users u ON u.id = ic.user_id
       LEFT JOIN summer_applications sa ON sa.id = ic.summer_app_id
     $wc
     ORDER BY ic.id DESC
     LIMIT $perPage OFFSET $offset",
    $params
);

// Build display name for each row.
$cards = array_map(function (array $r): array {
    $name = $r['user_name'] ?: trim($r['sa_first'] . ' ' . $r['sa_last']);
    $email = $r['user_email'] ?: $r['sa_email'];
    return [
        'id'           => (int)$r['id'],
        'serial'       => (string)$r['serial'],
        'role'         => (string)$r['role'],
        'status'       => (string)$r['status'],
        'session_code' => (string)$r['session_code'],
        'issued_at'    => (string)$r['issued_at'],
        'printed_at'   => $r['printed_at'],
        'revoked_at'   => $r['revoked_at'],
        'revoke_reason'=> $r['revoke_reason'],
        'user_id'      => $r['user_id'] !== null ? (int)$r['user_id'] : null,
        'holder_name'  => $name ?: 'Unknown',
        'holder_email' => $email,
        'holder_photo' => $r['user_photo'],
    ];
}, $rows);

mam_ok([
    'cards'    => $cards,
    'total'    => $total,
    'page'     => $page,
    'per_page' => $perPage,
    'pages'    => max(1, (int)ceil($total / $perPage)),
]);
