<?php
/**
 * Invoice review, family queries and discount codes (schema v100).
 *
 * Three things a family and the office both needed and neither had:
 *
 * 1. REVIEW BEFORE SENDING. A draft went straight to a family the moment
 *    anybody pressed Issue. Now a draft is checked (mam_fee_review_checks:
 *    no lines, a zero total, no guardian or no email to send it to, a second
 *    open invoice for the same child and term) and signed off by a person —
 *    a DIFFERENT person from whoever drafted it, unless the academy has only
 *    one office admin and says so (`fee_review_two_person` = 0). Any change
 *    to a draft's lines after sign-off clears it: a review covers the
 *    invoice that was reviewed, not one edited since.
 *
 * 2. A FAMILY CAN QUERY AN INVOICE. A question, a different amount, or a
 *    plan. The office answers, accepts, counters or declines — always with
 *    words the family reads. Accepting a different amount is recorded as a
 *    WAIVER with the query's number as its reason (so the invoice still shows
 *    what was charged and what was let go); accepting a plan opens an
 *    approved plan. A counter-offer goes back to the family, who accept or
 *    decline it. While a query is open, reminders about that invoice stop —
 *    chasing somebody for a bill they are asking about is not fair.
 *
 * 3. DISCOUNT CODES, applied WITH A REASON. A code is a percentage or an
 *    amount, with an optional cap on uses, a window and one use per family.
 *    Applying one waives across the invoice's lines (largest first), each
 *    waiver carrying "Code X" as its reason. It is a reduction of what was
 *    billed, never a payment, which is what fees.php already says a waiver
 *    is. A family can enter a code themselves on an unpaid invoice; the
 *    office can apply one to a draft.
 *
 * Interest and late charges are deliberately NOT here. The office can still
 * add a `late` line by hand; nothing adds one by itself.
 */
declare(strict_types=1);

const MAM_FEE_QUERY_KINDS = ['question' => 'A question', 'amount' => 'A different amount', 'plan' => 'Paying in instalments'];

/* ─── 1. Review ─────────────────────────────────────────────────────────── */

/** Is review required before a draft is issued? On unless the academy turns it off. */
function mam_fee_review_required(): bool { return (int) mam_setting_num('fee_review_required', 1) === 1; }

/**
 * What stands between this draft and a family. `block` stops a sign-off;
 * `warn` is something the reviewer should look at and may accept.
 */
function mam_fee_review_checks(int $invoiceId): array {
    $inv = mam_db_row('SELECT * FROM fee_invoices WHERE id = ?', [$invoiceId]);
    if (!$inv) return [['level' => 'block', 'text' => 'That invoice no longer exists.']];
    $out = [];
    $lines = (int) (mam_db_row('SELECT COUNT(*) AS n FROM fee_invoice_lines WHERE invoice_id = ?', [$invoiceId])['n'] ?? 0);
    if ($lines === 0) $out[] = ['level' => 'block', 'text' => 'There are no lines on it.'];
    elseif ((int) $inv['total_minor'] === 0) $out[] = ['level' => 'warn', 'text' => 'It comes to nothing after waivers and aid. Is it needed?'];
    $g = $inv['guardian_id'] ? mam_db_row('SELECT name, email FROM users WHERE id = ?', [(int) $inv['guardian_id']]) : null;
    if (!$g) $out[] = ['level' => 'block', 'text' => 'Nobody is named to pay it. Link a guardian to the student first.'];
    elseif (trim((string) $g['email']) === '') $out[] = ['level' => 'warn', 'text' => 'The guardian has no email address, so they will only see it in their portal.'];
    $dup = mam_db_row("SELECT public_ref FROM fee_invoices WHERE student_id = ? AND term_label = ? AND id <> ? AND status NOT IN ('void') LIMIT 1",
        [(int) $inv['student_id'], (string) $inv['term_label'], $invoiceId]);
    if ($dup) $out[] = ['level' => 'warn', 'text' => 'There is already invoice ' . $dup['public_ref'] . ' for this student and ' . $inv['term_label'] . '.'];
    foreach (mam_db_all('SELECT label, amount_minor FROM fee_invoice_lines WHERE invoice_id = ?', [$invoiceId]) as $l) {
        if ((int) $l['amount_minor'] === 0) $out[] = ['level' => 'warn', 'text' => '“' . $l['label'] . '” is a line of nothing.'];
    }
    return $out;
}

/** Sign a draft off. Throws a code fees' error table can say in words. */
function mam_fee_invoice_review(int $invoiceId, int $actorId, string $note = ''): void {
    $inv = mam_db_row('SELECT * FROM fee_invoices WHERE id = ?', [$invoiceId]);
    if (!$inv) throw new RuntimeException('invoice_not_found');
    if ($inv['status'] !== 'draft') throw new RuntimeException('already_issued');
    foreach (mam_fee_review_checks($invoiceId) as $c) if ($c['level'] === 'block') throw new RuntimeException('review_blocked');
    if ((int) mam_setting_num('fee_review_two_person', 1) === 1 && $inv['created_by'] !== null && (int) $inv['created_by'] === $actorId) {
        throw new RuntimeException('review_own_draft');
    }
    mam_db_update('fee_invoices', ['reviewed_by' => $actorId, 'reviewed_at' => gmdate('Y-m-d H:i:s'),
        'review_note' => mb_substr(trim($note), 0, 500) ?: null, 'review_sig' => mam_fee_review_sig($invoiceId)], ['id' => $invoiceId]);
    mam_audit('fee_invoice_reviewed', 'fee_invoice', $invoiceId, ['by' => $actorId]);
}

/**
 * What the review covered: every line's kind, label, quantity, price and
 * waiver. If this changes after sign-off the review no longer covers the
 * invoice and it is read as unreviewed — without a hook in every writer.
 */
function mam_fee_review_sig(int $invoiceId): string {
    $rows = mam_db_all('SELECT kind, label, qty, unit_minor, waiver_minor FROM fee_invoice_lines WHERE invoice_id = ? ORDER BY id', [$invoiceId]);
    $c = mam_db_row('SELECT COALESCE(SUM(amount_minor),0) AS s FROM fee_invoice_credits WHERE invoice_id = ?', [$invoiceId]);
    return hash('sha256', json_encode([$rows, (int) ($c['s'] ?? 0)]));
}

/** Does the sign-off still cover this draft? */
function mam_fee_is_reviewed(array $inv): bool {
    return !empty($inv['reviewed_at']) && hash_equals((string) ($inv['review_sig'] ?? ''), mam_fee_review_sig((int) $inv['id']));
}

/** Issue, through the review gate. The one way the office sends an invoice. */
function mam_fee_issue_reviewed(int $invoiceId, int $actorId, ?string $dueOn = null): array {
    $inv = mam_db_row('SELECT * FROM fee_invoices WHERE id = ?', [$invoiceId]);
    if (!$inv) throw new RuntimeException('invoice_not_found');
    if (mam_fee_review_required() && $inv['status'] === 'draft' && !mam_fee_is_reviewed($inv)) throw new RuntimeException('not_reviewed');
    return mam_fee_invoice_issue($invoiceId, $actorId, $dueOn);
}

/* ─── 2. Family queries ─────────────────────────────────────────────────── */

/** The invoice a guardian may query: theirs to pay, issued and not settled. */
function mam_fee_query_invoice(int $parentId, int $invoiceId): array {
    $inv = mam_db_row('SELECT * FROM fee_invoices WHERE id = ?', [$invoiceId]);
    if (!$inv || ((int) $inv['guardian_id'] !== $parentId && !mam_parent_scope_allows($parentId, (int) $inv['student_id'], 'invoices'))) throw new RuntimeException('not_found');
    if (!in_array($inv['status'], ['issued', 'part_paid'], true)) throw new RuntimeException('not_open');
    return $inv;
}

/**
 * A family queries an invoice. $in: kind, message, amount? (what they propose
 * to pay in total, decimal), instalments? (2..6).
 */
function mam_fee_query_raise(int $parentId, int $invoiceId, array $in): int {
    $inv = mam_fee_query_invoice($parentId, $invoiceId);
    $kind = isset(MAM_FEE_QUERY_KINDS[$in['kind'] ?? '']) ? (string) $in['kind'] : 'question';
    $msg = trim((string) ($in['message'] ?? ''));
    if (mb_strlen($msg) < 5) throw new InvalidArgumentException('Say what you would like the office to look at.');
    if (mam_db_row("SELECT 1 FROM fee_invoice_queries WHERE invoice_id = ? AND status IN ('open','countered')", [$invoiceId])) {
        throw new InvalidArgumentException('There is already a question open on this invoice. The office will answer it first.');
    }
    $prop = null; $inst = null;
    if ($kind === 'amount') {
        $prop = mam_fee_to_minor((string) ($in['amount'] ?? ''));
        $balance = (int) $inv['total_minor'];
        if ($prop <= 0 || $prop >= $balance) throw new InvalidArgumentException('Propose a total below ' . mam_fee_display($balance, (string) $inv['currency']) . '.');
        if ($prop < (int) $inv['paid_minor']) throw new InvalidArgumentException('That is less than has already been paid.');
    } elseif ($kind === 'plan') {
        $inst = (int) ($in['instalments'] ?? 0);
        if ($inst < 2 || $inst > 6) throw new InvalidArgumentException('Choose 2 to 6 instalments.');
        if (mam_fee_plan_for($invoiceId)) throw new InvalidArgumentException('This invoice already has a payment plan.');
    }
    $id = (int) mam_db_insert('fee_invoice_queries', ['invoice_id' => $invoiceId, 'raised_by' => $parentId, 'kind' => $kind,
        'message' => mb_substr($msg, 0, 3000), 'proposed_minor' => $prop, 'proposed_instalments' => $inst, 'status' => 'open',
        'created_at' => gmdate('Y-m-d H:i:s')]);
    mam_audit('fee_query_raised', 'fee_invoice', $invoiceId, ['query' => $id, 'kind' => $kind, 'proposed_minor' => $prop, 'instalments' => $inst]);
    foreach (mam_db_all("SELECT id FROM users WHERE role = 'admin' AND status = 'active' LIMIT 20") as $a) {
        try { mam_notify((int) $a['id'], 'fees', 'A family has queried invoice ' . $inv['public_ref'], MAM_FEE_QUERY_KINDS[$kind], '/admin/console.php?v=invoices&invoice=' . $invoiceId); } catch (Throwable $e) {}
    }
    return $id;
}

/**
 * The office answers. $decision: answer (a question only) | accept | counter | decline.
 * A counter carries counter_amount and/or counter_instalments. Every decision
 * carries words the family reads.
 */
function mam_fee_query_decide(int $queryId, int $actorId, string $decision, string $reply, array $counter = []): array {
    $q = mam_db_row('SELECT * FROM fee_invoice_queries WHERE id = ?', [$queryId]);
    if (!$q) throw new RuntimeException('not_found');
    if ($q['status'] !== 'open') throw new RuntimeException('already_decided');
    $reply = trim($reply);
    if (mb_strlen($reply) < 5) throw new RuntimeException('reply_required');
    $inv = mam_db_row('SELECT * FROM fee_invoices WHERE id = ?', [(int) $q['invoice_id']]);
    $upd = ['office_reply' => mb_substr($reply, 0, 3000), 'decided_by' => $actorId, 'decided_at' => gmdate('Y-m-d H:i:s')];
    switch ($decision) {
        case 'answer':
            if ($q['kind'] !== 'question') throw new RuntimeException('bad_decision');
            $upd['status'] = 'answered';
            break;
        case 'accept':
            if ($q['kind'] === 'question') throw new RuntimeException('bad_decision');
            mam_fee_query_apply($q, $inv, $q['proposed_minor'] !== null ? (int) $q['proposed_minor'] : null,
                $q['proposed_instalments'] !== null ? (int) $q['proposed_instalments'] : null, $actorId, $q['raised_by'] ? (int) $q['raised_by'] : null);
            $upd['status'] = 'accepted';
            break;
        case 'counter':
            if ($q['kind'] === 'question') throw new RuntimeException('bad_decision');
            $cm = isset($counter['amount']) && trim((string) $counter['amount']) !== '' ? mam_fee_to_minor((string) $counter['amount']) : null;
            $ci = isset($counter['instalments']) && (int) $counter['instalments'] > 0 ? (int) $counter['instalments'] : null;
            if ($q['kind'] === 'amount' && ($cm === null || $cm <= 0 || $cm >= (int) $inv['total_minor'])) throw new RuntimeException('bad_counter');
            if ($q['kind'] === 'plan' && ($ci === null || $ci < 2 || $ci > 6)) throw new RuntimeException('bad_counter');
            $upd += ['status' => 'countered', 'counter_minor' => $cm, 'counter_instalments' => $ci];
            break;
        case 'decline':
            $upd['status'] = 'declined';
            break;
        default:
            throw new RuntimeException('bad_decision');
    }
    mam_db_update('fee_invoice_queries', $upd, ['id' => $queryId]);
    mam_audit('fee_query_decided', 'fee_invoice', (int) $q['invoice_id'], ['query' => $queryId, 'decision' => $upd['status'], 'by' => $actorId]);
    if ($q['raised_by']) {
        $titles = ['answered' => 'The office answered your question', 'accepted' => 'The office agreed', 'countered' => 'The office has made an offer', 'declined' => 'The office could not agree'];
        try { mam_notify((int) $q['raised_by'], 'fees', $titles[$upd['status']] . ' · ' . $inv['public_ref'], mb_substr($reply, 0, 200), null); } catch (Throwable $e) {}
    }
    return mam_db_row('SELECT * FROM fee_invoice_queries WHERE id = ?', [$queryId]);
}

/** The family accepts or declines the office's counter-offer. */
function mam_fee_query_respond(int $parentId, int $queryId, bool $accept): array {
    $q = mam_db_row('SELECT * FROM fee_invoice_queries WHERE id = ?', [$queryId]);
    if (!$q || (int) $q['raised_by'] !== $parentId) throw new RuntimeException('not_found');
    if ($q['status'] !== 'countered') throw new RuntimeException('already_decided');
    $inv = mam_db_row('SELECT * FROM fee_invoices WHERE id = ?', [(int) $q['invoice_id']]);
    if ($accept) mam_fee_query_apply($q, $inv, $q['counter_minor'] !== null ? (int) $q['counter_minor'] : null,
        $q['counter_instalments'] !== null ? (int) $q['counter_instalments'] : null, (int) $q['decided_by'] ?: null, $parentId);
    mam_db_update('fee_invoice_queries', ['status' => $accept ? 'accepted' : 'declined', 'family_response_at' => gmdate('Y-m-d H:i:s')], ['id' => $queryId]);
    mam_audit('fee_query_response', 'fee_invoice', (int) $q['invoice_id'], ['query' => $queryId, 'accepted' => $accept]);
    return mam_db_row('SELECT * FROM fee_invoice_queries WHERE id = ?', [$queryId]);
}

/**
 * Make what was agreed true on the invoice. A lower total is a waiver across
 * the lines, reason "Agreed with the family (query #N)"; instalments are an
 * approved plan.
 */
function mam_fee_query_apply(array $q, array $inv, ?int $totalMinor, ?int $instalments, ?int $officeId, ?int $familyId): void {
    if ($totalMinor !== null) {
        $cut = (int) $inv['total_minor'] - $totalMinor;
        if ($cut > 0) mam_fee_waive_across((int) $inv['id'], $cut, 'Agreed with the family (query #' . (int) $q['id'] . ')', $officeId);
    }
    if ($instalments !== null) mam_fee_plan_create((int) $inv['id'], $instalments, $familyId, $officeId ?: null);
}

/** Queries on an invoice, newest first. */
function mam_fee_queries_for(int $invoiceId): array {
    return mam_db_all('SELECT q.*, u.name AS raised_by_name, d.name AS decided_by_name FROM fee_invoice_queries q
                         LEFT JOIN users u ON u.id = q.raised_by LEFT JOIN users d ON d.id = q.decided_by
                        WHERE q.invoice_id = ? ORDER BY q.id DESC', [$invoiceId]);
}

/** Is a query open on this invoice? Reminders stop while one is. */
function mam_fee_query_open(int $invoiceId): bool {
    return (bool) mam_db_row("SELECT 1 FROM fee_invoice_queries WHERE invoice_id = ? AND status IN ('open','countered')", [$invoiceId]);
}

/* ─── 3. Discount codes ─────────────────────────────────────────────────── */

function mam_fee_code_norm(string $c): string { return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $c)); }

/** Create or change a code. $in: code, label, pct | amount+currency, max_uses?, once_per_family?, starts_on?, ends_on?, active? */
function mam_fee_code_save(array $in, int $actorId): int {
    $code = mam_fee_code_norm((string) ($in['code'] ?? ''));
    if (strlen($code) < 4 || strlen($code) > 24) throw new InvalidArgumentException('A code is 4 to 24 letters and digits.');
    $label = trim((string) ($in['label'] ?? ''));
    if ($label === '') throw new InvalidArgumentException('Say what the code is for — it becomes the reason on every invoice it reduces.');
    $pct = trim((string) ($in['pct'] ?? '')); $amt = trim((string) ($in['amount'] ?? ''));
    if (($pct === '') === ($amt === '')) throw new InvalidArgumentException('Give a percentage or an amount, not both.');
    if ($pct !== '' && ((float) $pct <= 0 || (float) $pct > 100)) throw new InvalidArgumentException('A percentage from 1 to 100.');
    $cur = strtoupper(trim((string) ($in['currency'] ?? mam_fee_currency())));
    if ($amt !== '' && mam_fee_to_minor($amt) <= 0) throw new InvalidArgumentException('An amount above zero.');
    foreach (['starts_on', 'ends_on'] as $k) if (!empty($in[$k]) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $in[$k])) throw new InvalidArgumentException('Give dates as dates.');
    $row = ['code' => $code, 'label' => mb_substr($label, 0, 120), 'pct' => $pct !== '' ? round((float) $pct, 2) : null,
        'amount_minor' => $amt !== '' ? mam_fee_to_minor($amt) : null, 'currency' => $amt !== '' ? $cur : null,
        'max_uses' => (int) ($in['max_uses'] ?? 0) > 0 ? (int) $in['max_uses'] : null,
        'once_per_family' => !isset($in['once_per_family']) || !empty($in['once_per_family']) ? 1 : 0,
        'starts_on' => ($in['starts_on'] ?? '') ?: null, 'ends_on' => ($in['ends_on'] ?? '') ?: null,
        'active' => !isset($in['active']) || !empty($in['active']) ? 1 : 0];
    $have = mam_db_row('SELECT id FROM fee_discount_codes WHERE code = ?', [$code]);
    if ($have) { mam_db_update('fee_discount_codes', $row, ['id' => (int) $have['id']]); $id = (int) $have['id']; }
    else $id = (int) mam_db_insert('fee_discount_codes', $row + ['used' => 0, 'created_by' => $actorId, 'created_at' => gmdate('Y-m-d H:i:s')]);
    mam_audit('fee_code_saved', 'fee_discount_code', $id, ['code' => $code, 'by' => $actorId]);
    return $id;
}

/**
 * Apply a code to an invoice. Returns the amount taken off (minor units).
 * Refusals are sentences, because a family typing a code needs to know
 * whether it was the code, the date or the invoice.
 */
function mam_fee_code_apply(int $invoiceId, string $rawCode, ?int $actorId, ?int $familyId = null): int {
    $inv = mam_db_row('SELECT * FROM fee_invoices WHERE id = ?', [$invoiceId]);
    if (!$inv || !mam_fee_editable($inv)) throw new InvalidArgumentException('Codes can be used only on an invoice that is not yet paid.');
    $c = mam_db_row('SELECT * FROM fee_discount_codes WHERE code = ?', [mam_fee_code_norm($rawCode)]);
    $today = function_exists('mam_calendar_today') ? mam_calendar_today() : date('Y-m-d');
    if (!$c || (int) $c['active'] !== 1) throw new InvalidArgumentException('That code was not recognised.');
    if (($c['starts_on'] && $today < $c['starts_on']) || ($c['ends_on'] && $today > $c['ends_on'])) throw new InvalidArgumentException('That code is not valid today.');
    if ($c['max_uses'] !== null && (int) $c['used'] >= (int) $c['max_uses']) throw new InvalidArgumentException('That code has been used up.');
    if (mam_db_row('SELECT 1 FROM fee_discount_redemptions WHERE code_id = ? AND invoice_id = ?', [(int) $c['id'], $invoiceId])) {
        throw new InvalidArgumentException('That code is already on this invoice.');
    }
    if ((int) $c['once_per_family'] === 1 && $inv['guardian_id'] && mam_db_row(
        'SELECT 1 FROM fee_discount_redemptions r JOIN fee_invoices i ON i.id = r.invoice_id WHERE r.code_id = ? AND i.guardian_id = ?',
        [(int) $c['id'], (int) $inv['guardian_id']])) {
        throw new InvalidArgumentException('Your family has already used that code.');
    }
    if ($c['amount_minor'] !== null && strtoupper((string) $c['currency']) !== strtoupper((string) $inv['currency'])) {
        throw new InvalidArgumentException('That code is for invoices in ' . $c['currency'] . '.');
    }
    $owing = (int) $inv['total_minor'] - (int) $inv['paid_minor'];
    $off = $c['pct'] !== null ? mam_fee_pct_of((int) $inv['total_minor'], (float) $c['pct']) : (int) $c['amount_minor'];
    $off = min($off, $owing);
    if ($off <= 0) throw new InvalidArgumentException('There is nothing left on this invoice for a code to reduce.');
    mam_fee_waive_across($invoiceId, $off, 'Code ' . $c['code'] . ' — ' . $c['label'], $actorId);
    mam_db_insert('fee_discount_redemptions', ['code_id' => (int) $c['id'], 'invoice_id' => $invoiceId, 'amount_minor' => $off,
        'applied_by' => $actorId ?? $familyId, 'created_at' => gmdate('Y-m-d H:i:s')]);
    mam_db()->prepare('UPDATE fee_discount_codes SET used = used + 1 WHERE id = ?')->execute([(int) $c['id']]);
    mam_audit('fee_code_applied', 'fee_invoice', $invoiceId, ['code' => $c['code'], 'minor' => $off, 'by' => $actorId ?? $familyId]);
    return $off;
}

/**
 * Take an amount off an invoice as waivers, largest line first, each
 * carrying the reason. A line keeps any waiver it already had (added to,
 * with both reasons), so nothing earlier is overwritten.
 */
function mam_fee_waive_across(int $invoiceId, int $minor, string $reason, ?int $actorId): void {
    $left = $minor;
    foreach (mam_db_all('SELECT * FROM fee_invoice_lines WHERE invoice_id = ? ORDER BY (amount_minor - waiver_minor) DESC, id', [$invoiceId]) as $l) {
        if ($left <= 0) break;
        $room = (int) $l['amount_minor'] - (int) $l['waiver_minor'];
        if ($room <= 0) continue;
        $take = min($room, $left);
        $newReason = $l['waiver_reason'] ? mb_substr($l['waiver_reason'] . '; ' . $reason, 0, 500) : $reason;
        mam_fee_line_waive((int) $l['id'], mam_fee_from_minor((int) $l['waiver_minor'] + $take), $newReason, $actorId);
        $left -= $take;
    }
    if ($left > 0) throw new RuntimeException('waiver_exceeds_line');
}

function mam_fee_codes(): array {
    return mam_db_all('SELECT * FROM fee_discount_codes ORDER BY active DESC, created_at DESC LIMIT 200');
}
