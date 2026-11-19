<?php
/**
 * GET /api/admin/fee-evidence?id=N — the slip a guardian attached to a reported
 * transfer, for the office checking it. Fees capability only; refused if the
 * file on disk is no longer the file that was sent. Served as an attachment
 * with nosniff, never rendered inline as a document.
 */
declare(strict_types=1);
$r = mam_fee_claim_evidence((int) ($_GET['id'] ?? 0));
if (empty($r['ok'])) mam_fail((string) $r['error'], $r['error'] === 'not_found' ? 404 : 409);
mam_audit('fee_claim_evidence_viewed', 'fee_invoice', (int) $r['invoice_id'], ['claim' => (int) ($_GET['id'] ?? 0)]);
$ext = MAM_FEE_EVIDENCE_TYPES[$r['type']] ?? 'bin';
header_remove('Content-Type');
header('Content-Type: ' . $r['type']);
header('Content-Length: ' . (string) filesize($r['path']));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="transfer-slip-' . (int) ($_GET['id'] ?? 0) . '.' . $ext . '"');
readfile($r['path']);
if (!defined('MAM_RESPONSE_SENT')) define('MAM_RESPONSE_SENT', true);
