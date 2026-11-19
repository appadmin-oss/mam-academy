<?php
/**
 * A family's side of fee-review.php (v100).
 *
 *   POST {action:'raise', invoice_id, kind:'question'|'amount'|'plan', message, amount?, instalments?}
 *   POST {action:'respond', query_id, accept: bool}     to the office's counter-offer
 *   POST {action:'code', invoice_id, code}              a discount code → {off}
 */
declare(strict_types=1);
$u = mam_require_role('parent');
$pid = (int) $u['id'];
$in = mam_json_input();
try {
    switch ((string) ($in['action'] ?? '')) {
        case 'raise':
            $id = mam_fee_query_raise($pid, (int) ($in['invoice_id'] ?? 0), $in);
            mam_ok(['id' => $id, 'message' => 'Sent. The office will answer here, and reminders about this invoice stop until they do.']);
        case 'respond':
            $q = mam_fee_query_respond($pid, (int) ($in['query_id'] ?? 0), !empty($in['accept']));
            mam_ok(['status' => $q['status'], 'message' => $q['status'] === 'accepted' ? 'Agreed. The invoice has been updated.' : 'Declined. The invoice stands as it was.']);
        case 'code':
            $inv = mam_fee_query_invoice($pid, (int) ($in['invoice_id'] ?? 0));
            $off = mam_fee_code_apply((int) $inv['id'], (string) ($in['code'] ?? ''), null, $pid);
            mam_ok(['off' => mam_fee_display($off, (string) $inv['currency']), 'message' => mam_fee_display($off, (string) $inv['currency']) . ' off. The invoice has been updated.']);
    }
    mam_fail('unknown_action', 400);
} catch (InvalidArgumentException $e) {
    mam_fail('validation', 422, ['message' => $e->getMessage()]);
} catch (RuntimeException $e) {
    $m = ['not_found' => 'That invoice was not found.', 'not_open' => 'That invoice is not open.', 'already_decided' => 'That has already been settled.'];
    mam_fail($e->getMessage(), $e->getMessage() === 'not_found' ? 404 : 409, ['message' => $m[$e->getMessage()] ?? 'That could not be done.']);
}
