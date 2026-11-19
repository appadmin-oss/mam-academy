<?php
/**
 * MAM Academy — fee invoices, the four kinds of aid, and payment plans
 * (guardian redesign §3.4, schema v87).
 * ─────────────────────────────────────────────────────────────
 * Owns `fee_invoices`, `fee_invoice_lines`, `fee_aid_awards`,
 * `fee_invoice_credits`, `fee_payment_plans`, `fee_plan_instalments`,
 * `bursary_applications`.
 *
 * ── What this is NOT ────────────────────────────────────────────────────────
 * `invoicing.php` is the APPLICATION processing-fee lifecycle: one charge against
 * one applicant, with a reminder schedule and an expiry. It answers "has this
 * applicant paid to be considered". This answers "what does this family owe for
 * this child this term, and how was it worked out". Do not merge them.
 *
 * ── Money ───────────────────────────────────────────────────────────────────
 * Integer minor units + an explicit ISO currency + a rate frozen at issue. Never
 * a float in a column, and never a bare number whose meaning depends on a sibling
 * column — that ambiguity is what `payments.amount_usd_cents` was added in v61 to
 * end, after somebody summed naira into dollars and printed the total with a
 * naira sign.
 *
 * The invoice states what is OWED in its own currency. The naira figure a parent
 * is asked to authorise is a CONVERSION, computed from the frozen rate, and the
 * screen says it is an estimate until the payment clears — because the rate the
 * payment settles at is the payment row's own. The frozen rate is what lets a May
 * invoice reconcile in next year's audit.
 *
 * ⚠️ Totals are computed ONCE, in the invoice's own currency, from the lines —
 * never summed from per-line currency conversions. Rounding eleven lines
 * individually and adding them differs from converting the sum, and a one-naira
 * gap reads to a parent as a pricing error rather than as arithmetic.
 *
 * ── The four kinds of aid are four MECHANISMS ───────────────────────────────
 * Collapsing them into "discounts" changes what the academy's books say happened:
 *
 *   waiver      reduces the invoice LINE. Non-revenue — the academy never billed
 *               it, so it must never appear as a cash entry anywhere.
 *   bursary     needs-based GIFT AID: a credit against the invoice from the aid
 *               pool. The line stands at full value because the money is real.
 *   scholarship merit gift aid. Same mechanism, different criteria.
 *   sibling     a DISCOUNT, applied AFTER aid and capped at the published
 *               maximum.
 *
 * The order is fixed, and `mam_fee_recalc()` is the only implementation of it:
 *
 *     gross → less waivers → less aid credits → less sibling discount → total
 *
 * Sibling comes last because a percentage of a post-aid balance is smaller than a
 * percentage of the gross, and applying it first would hand the household a
 * discount on money the aid pool paid. Every stage is stored, so a family can be
 * shown the arithmetic and an auditor can take it apart — rather than one net
 * figure nobody can explain.
 *
 * ── Dunning, not disconnection ──────────────────────────────────────────────
 * Reminders at day 0, 3 and 7, then a 14-day grace window, then a conversation.
 * There is no function here that suspends anything, and there is no column for
 * it: the design's refusal is that an access hold never touches a child's
 * academic record, a safeguarding-relevant file, or a minor's report. The way to
 * keep a refusal is to leave the mechanism unbuilt.
 */
declare(strict_types=1);

/** Settings this file reads, with the shipped defaults the academy can change. */
const MAM_FEE_SIBLING_PCT_DEFAULT   = 10.0;   // per cent off the post-aid balance
const MAM_FEE_SIBLING_CAP_DEFAULT   = 60.0;   // in MAJOR units of the invoice currency
const MAM_FEE_DUE_DAYS_DEFAULT      = 14;
const MAM_FEE_GRACE_DAYS_DEFAULT    = 14;
/** The reminder ladder, in days after the due date. Design §3.4: "day 0, 3, 7". */
const MAM_FEE_DUNNING_DAYS = [0, 3, 7];

/**
 * The income bands a bursary is assessed on.
 *
 * A BAND, never a written case for hardship. The design is explicit and the
 * reasons are worth keeping: a written case rewards the family best able to write
 * one, asks a parent to describe their circumstances to their child's school, and
 * leaves an assessor free to be inconsistent between two households in the same
 * position. A band is a fact, and the table below is published, so two families
 * that declare the same thing get the same answer.
 *
 * The figures are monthly household income in NGN. `pct` is the indicative award,
 * which a committee may reduce but not silently exceed.
 */
const MAM_BURSARY_BANDS = [
    'under_150k'  => ['label' => 'Under ₦150,000 a month',        'pct' => 75.0],
    '150k_300k'   => ['label' => '₦150,000 – ₦300,000 a month',   'pct' => 50.0],
    '300k_600k'   => ['label' => '₦300,000 – ₦600,000 a month',   'pct' => 30.0],
    '600k_1m'     => ['label' => '₦600,000 – ₦1,000,000 a month', 'pct' => 15.0],
    'over_1m'     => ['label' => 'Over ₦1,000,000 a month',       'pct' => null],
    'prefer_not'  => ['label' => 'I would rather not say',        'pct' => null],
];

/* ─────────────────────────────────────────────────────────────────────────────
   Money conversion
   ───────────────────────────────────────────────────────────────────────────── */

/**
 * A decimal amount, as a STRING, into integer minor units.
 *
 * String arithmetic, not `(int) round($x * 100)`. The design says so and the
 * reason is arithmetic rather than taste. MEASURED on PHP 8.4: `8.29 * 100` is
 * 828.99999999999989 and `0.29 * 100` is 28.999999999999996, so a truncating
 * conversion loses a kobo on both; and rounding is only correct until a value
 * lands near a half — `2.675` rounds to 267 by the float route where the true
 * answer is 268. Here the decimal point is MOVED, which cannot be wrong.
 * (An earlier version of this note cited `8.07`, which is exact on this build:
 * the reasoning held but the example did not, and an example that does not
 * reproduce is worse than none.)
 *
 * `mam_money_to_minor()` in money.php takes a float and rounds, which is right
 * for its callers (they hold floats already, from a form or a legacy column).
 * This one exists for the path where the value arrives as text — a price list, a
 * JSON body, an admin form — and should be preferred there, because the float
 * never has to exist.
 *
 * Accepts '1,234.56', '1234.5', '.5', '-3.20', 1234 (int), 12.5 (float).
 */
function mam_fee_to_minor($amount, int $decimals = 2): int {
    $s = is_string($amount) ? trim($amount) : (string) $amount;
    $s = str_replace([',', ' ', "\u{00A0}"], '', $s);
    if ($s === '' || $s === '-' || $s === '+') return 0;
    if (!preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $s, $m)) {
        throw new InvalidArgumentException('bad_amount');
    }
    $sign  = $m[1] === '-' ? -1 : 1;
    $whole = $m[2] === '' ? '0' : $m[2];
    $frac  = $m[3] ?? '';
    // Round HALF UP on the first discarded digit, then move the point. Done on
    // the digits themselves so no float is ever constructed.
    if (strlen($frac) > $decimals) {
        $keep = substr($frac, 0, $decimals);
        $next = (int) $frac[$decimals];
        $minor = $whole . $keep;
        $minor = (string) (int) $minor;          // strip leading zeros
        if ($next >= 5) $minor = (string) ((int) $minor + 1);
    } else {
        $minor = $whole . str_pad($frac, $decimals, '0');
        $minor = (string) (int) $minor;
    }
    return $sign * (int) $minor;
}

/** Minor units back to a display string. Never used for arithmetic. */
function mam_fee_from_minor(int $minor, int $decimals = 2): string {
    $neg = $minor < 0;
    $s = str_pad((string) abs($minor), $decimals + 1, '0', STR_PAD_LEFT);
    $whole = substr($s, 0, -$decimals);
    $frac  = substr($s, -$decimals);
    return ($neg ? '-' : '') . number_format((float) ($whole . '.' . $frac), $decimals, '.', ',');
}

/** The symbol a currency prints with. Falls back to the ISO code. */
function mam_fee_symbol(string $currency): string {
    return ['USD' => '$', 'NGN' => '₦', 'GBP' => '£', 'EUR' => '€'][strtoupper($currency)] ?? (strtoupper($currency) . ' ');
}

/** A minor-unit amount formatted for a screen, with its symbol. */
function mam_fee_display(int $minor, string $currency): string {
    return mam_fee_symbol($currency) . mam_fee_from_minor($minor);
}

/**
 * A percentage OF a minor amount, in minor units, rounded half up.
 *
 * Integer arithmetic throughout: `(int) round($minor * $pct / 100)` in floats is
 * wrong at scale (a ₦4,748,000 line at 12.5% lands a kobo out), and a fee a
 * family can recompute on paper has to match to the coin.
 */
function mam_fee_pct_of(int $minor, float $pct): int {
    if ($pct <= 0 || $minor <= 0) return 0;
    // pct is expressed to three decimals at most (the column is DECIMAL(6,3)),
    // so scale by 1000 and do it in integers.
    $scaled = (int) round($pct * 1000);
    return intdiv($minor * $scaled + 50000, 100000);
}

/* ─────────────────────────────────────────────────────────────────────────────
   Invoices
   ───────────────────────────────────────────────────────────────────────────── */

/** The academy's invoicing currency. USD, matching the published price list. */
function mam_fee_currency(): string {
    $c = strtoupper(trim((string) mam_setting_or_default('fee_currency', 'USD')));
    return preg_match('/^[A-Z]{3}$/', $c) ? $c : 'USD';
}

/** A stable, human-quotable reference. Derived, so two runs give one answer. */
function mam_fee_ref(int $studentId, string $termLabel, int $seq): string {
    $t = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $termLabel) ?: 'TERM');
    return 'INV-' . substr($t, 0, 8) . '-' . str_pad((string) $studentId, 5, '0', STR_PAD_LEFT)
         . '-' . str_pad((string) $seq, 2, '0', STR_PAD_LEFT);
}

/** NULL, never 0 — `created_by` and friends carry a users(id) FK (gotcha #3). */
function mam_fee_actor(?int $id): ?int { return $id !== null && $id > 0 ? $id : null; }

/**
 * Open a DRAFT invoice for a child. The billing guardian is resolved when not
 * given: the linked guardian with the widest scope, because `billing` exists
 * precisely to name a payer.
 */
function mam_fee_invoice_open(int $studentId, ?int $guardianId = null, ?int $actorId = null, array $opts = []): int {
    if ($studentId <= 0) throw new InvalidArgumentException('bad_student');
    if (!mam_db_row('SELECT id FROM users WHERE id = ?', [$studentId])) throw new RuntimeException('student_not_found');

    $termId = null; $termLabel = '';
    if (function_exists('mam_calendar_current_term')) {
        try {
            $t = mam_calendar_current_term();
            if (!empty($t['term']) && ($t['source'] ?? '') === 'calendar') $termId = (int) $t['term']['id'];
            $termLabel = (string) ($t['term']['label'] ?? $t['label'] ?? '');
        } catch (\Throwable $e) { mam_log('warn', 'fee_term_lookup_failed', ['err' => $e->getMessage()]); }
    }
    if (isset($opts['term_label']) && $opts['term_label'] !== '') $termLabel = (string) $opts['term_label'];
    if ($termLabel === '') $termLabel = date('Y');

    if ($guardianId === null && function_exists('mam_parent_guardians_of')) {
        $rank = ['full' => 3, 'standard' => 2, 'billing' => 1];
        $best = 0;
        foreach (mam_parent_guardians_of($studentId) as $g) {
            $sc = mam_parent_scope((int) $g['id'], $studentId) ?? 'billing';
            if (($rank[$sc] ?? 0) > $best) { $best = $rank[$sc] ?? 0; $guardianId = (int) $g['id']; }
        }
    }

    $seq = 1 + (int) (mam_db_row('SELECT COUNT(*) AS n FROM fee_invoices WHERE student_id = ?', [$studentId])['n'] ?? 0);
    $ref = mam_fee_ref($studentId, $termLabel, $seq);
    // Derived refs can collide if an invoice was voided and reopened; walk on.
    while (mam_db_row('SELECT id FROM fee_invoices WHERE public_ref = ?', [$ref])) {
        $ref = mam_fee_ref($studentId, $termLabel, ++$seq);
    }

    $id = mam_db_insert('fee_invoices', [
        'public_ref'  => $ref,
        'student_id'  => $studentId,
        'guardian_id' => mam_fee_actor($guardianId),
        'term_id'     => $termId,
        'term_label'  => $termLabel,
        'currency'    => strtoupper((string) ($opts['currency'] ?? mam_fee_currency())),
        'status'      => 'draft',
        'note'        => $opts['note'] ?? null,
        'created_by'  => mam_fee_actor($actorId),
        'created_at'  => date('Y-m-d H:i:s'),
        // The CHECK insists the stages add up, so a zero invoice must be consistent.
        'gross_minor' => 0, 'waiver_minor' => 0, 'aid_minor' => 0,
        'sibling_minor' => 0, 'total_minor' => 0, 'paid_minor' => 0,
    ]);
    mam_audit('fee_invoice_open', 'fee_invoice', $id, ['student' => $studentId, 'ref' => $ref, 'term' => $termLabel]);
    return $id;
}

/** True while an invoice may still be edited. Paid and void are closed. */
function mam_fee_editable(array $inv): bool {
    return in_array($inv['status'], ['draft', 'issued', 'part_paid'], true);
}

/**
 * Add a line. `unit` is a DECIMAL STRING in the invoice currency ('300.00'), not
 * a float and not minor units — the caller usually has it as text from a price
 * list or a form, and converting through a float is where the kobo goes.
 */
function mam_fee_line_add(int $invoiceId, string $kind, string $label, $unit, int $qty = 1, array $opts = []): int {
    $inv = mam_db_row('SELECT * FROM fee_invoices WHERE id = ?', [$invoiceId]);
    if (!$inv) throw new RuntimeException('invoice_not_found');
    if (!mam_fee_editable($inv)) throw new RuntimeException('invoice_closed');
    if ($qty < 1) throw new InvalidArgumentException('bad_qty');
    $label = trim($label);
    if ($label === '') throw new InvalidArgumentException('label_required');

    $unitMinor = mam_fee_to_minor($unit);
    if ($unitMinor < 0) throw new InvalidArgumentException('negative_line');

    $id = mam_db_insert('fee_invoice_lines', [
        'invoice_id'    => $invoiceId,
        'kind'          => $kind,
        'label'         => $label,
        'enrollment_id' => mam_fee_actor($opts['enrollment_id'] ?? null),
        'cohort_id'     => mam_fee_actor($opts['cohort_id'] ?? null),
        'qty'           => $qty,
        'unit_minor'    => $unitMinor,
        'amount_minor'  => $unitMinor * $qty,
        'sort_order'    => (int) ($opts['sort_order'] ?? 0),
    ]);
    mam_fee_recalc($invoiceId);
    return $id;
}

/**
 * WAIVE part or all of a line.
 *
 * A waiver is not a payment and not a discount: it reduces what was billed. It
 * lives on the line beside the amount rather than netted into it, so the invoice
 * can show both what was charged and what was waived — which is what a family
 * needs to understand the bill and what an auditor needs to see that no cash
 * moved. Nothing here writes to `payments`, and nothing should.
 */
function mam_fee_line_waive(int $lineId, $amount, string $reason, ?int $actorId = null): void {
    $reason = trim($reason);
    if ($reason === '') throw new InvalidArgumentException('reason_required');
    $line = mam_db_row('SELECT * FROM fee_invoice_lines WHERE id = ?', [$lineId]);
    if (!$line) throw new RuntimeException('line_not_found');
    $inv = mam_db_row('SELECT * FROM fee_invoices WHERE id = ?', [(int) $line['invoice_id']]);
    if (!$inv || !mam_fee_editable($inv)) throw new RuntimeException('invoice_closed');

    $minor = $amount === true ? (int) $line['amount_minor'] : mam_fee_to_minor($amount);
    if ($minor < 0) throw new InvalidArgumentException('negative_waiver');
    if ($minor > (int) $line['amount_minor']) throw new RuntimeException('waiver_exceeds_line');

    mam_db_update('fee_invoice_lines', ['waiver_minor' => $minor, 'waiver_reason' => $minor > 0 ? $reason : null], ['id' => $lineId]);
    mam_fee_recalc((int) $line['invoice_id']);
    mam_audit('fee_waive', 'fee_invoice', (int) $line['invoice_id'], [
        'line' => $lineId, 'minor' => $minor, 'reason' => $reason, 'by' => mam_fee_actor($actorId),
    ]);
}

/**
 * Award aid. One award, one decision, recorded once — its application to any
 * particular invoice is separate, because an award spans a term.
 */
function mam_fee_aid_award(int $studentId, string $kind, array $in, ?int $actorId = null): int {
    if (!in_array($kind, ['bursary', 'scholarship', 'waiver', 'sibling'], true)) throw new InvalidArgumentException('bad_kind');
    if (!mam_db_row('SELECT id FROM users WHERE id = ?', [$studentId])) throw new RuntimeException('student_not_found');

    $pct = isset($in['pct']) && $in['pct'] !== null && $in['pct'] !== '' ? (float) $in['pct'] : null;
    $amt = isset($in['amount']) && $in['amount'] !== null && $in['amount'] !== '' ? mam_fee_to_minor($in['amount']) : null;
    if (($pct === null) === ($amt === null)) throw new InvalidArgumentException('pct_or_amount');
    if ($pct !== null && ($pct <= 0 || $pct > 100)) throw new InvalidArgumentException('bad_pct');
    if ($amt !== null && $amt <= 0) throw new InvalidArgumentException('bad_amount');

    $id = mam_db_insert('fee_aid_awards', [
        'student_id'   => $studentId,
        'kind'         => $kind,
        'pct'          => $pct,
        'amount_minor' => $amt,
        'currency'     => $amt !== null ? strtoupper((string) ($in['currency'] ?? mam_fee_currency())) : null,
        'term_id'      => mam_fee_actor($in['term_id'] ?? null),
        'term_label'   => $in['term_label'] ?? null,
        'basis'        => $in['basis'] ?? null,
        'status'       => 'active',
        'starts_on'    => $in['starts_on'] ?? null,
        'expires_on'   => $in['expires_on'] ?? null,
        'awarded_by'   => mam_fee_actor($actorId),
        'awarded_at'   => date('Y-m-d H:i:s'),
        'note'         => $in['note'] ?? null,
    ]);
    mam_audit('fee_aid_award', 'fee_aid_award', $id, ['student' => $studentId, 'kind' => $kind, 'pct' => $pct, 'minor' => $amt]);
    return $id;
}

/** Revoke an award. Keeps the row and the reason — an award given and taken back
 *  is an event a family may ask about. */
function mam_fee_aid_revoke(int $awardId, string $reason, ?int $actorId = null): void {
    $reason = trim($reason);
    if ($reason === '') throw new InvalidArgumentException('reason_required');
    $a = mam_db_row('SELECT * FROM fee_aid_awards WHERE id = ?', [$awardId]);
    if (!$a) throw new RuntimeException('award_not_found');
    if ($a['status'] === 'revoked') throw new RuntimeException('already_revoked');
    mam_db_update('fee_aid_awards', [
        'status' => 'revoked', 'revoked_at' => date('Y-m-d H:i:s'), 'revoked_reason' => $reason,
    ], ['id' => $awardId]);
    mam_audit('fee_aid_revoke', 'fee_aid_award', $awardId, ['reason' => $reason, 'by' => mam_fee_actor($actorId)]);
}

/** The student's live aid awards of a kind, newest first. */
function mam_fee_aid_for(int $studentId, ?string $kind = null): array {
    $today = function_exists('mam_calendar_today') ? mam_calendar_today() : date('Y-m-d');
    $sql = "SELECT * FROM fee_aid_awards
             WHERE student_id = ? AND status = 'active'
               AND (starts_on  IS NULL OR starts_on  <= ?)
               AND (expires_on IS NULL OR expires_on >= ?)";
    $args = [$studentId, $today, $today];
    if ($kind !== null) { $sql .= ' AND kind = ?'; $args[] = $kind; }
    return mam_db_all($sql . ' ORDER BY id DESC', $args);
}

/**
 * Apply a bursary or scholarship award to an invoice as a GIFT AID credit.
 *
 * Only these two. A waiver is on the line and is not money; a sibling discount is
 * computed on the post-aid balance by `mam_fee_recalc()`. Keeping the three
 * mechanisms apart is what makes "how much aid did the academy actually pay out
 * this term" a question with an answer.
 */
function mam_fee_credit_apply(int $invoiceId, int $awardId, ?int $actorId = null, $overrideAmount = null): int {
    $inv = mam_db_row('SELECT * FROM fee_invoices WHERE id = ?', [$invoiceId]);
    if (!$inv) throw new RuntimeException('invoice_not_found');
    if (!mam_fee_editable($inv)) throw new RuntimeException('invoice_closed');
    $a = mam_db_row('SELECT * FROM fee_aid_awards WHERE id = ?', [$awardId]);
    if (!$a) throw new RuntimeException('award_not_found');
    if ($a['status'] !== 'active') throw new RuntimeException('award_not_active');
    if (!in_array($a['kind'], ['bursary', 'scholarship'], true)) throw new RuntimeException('not_gift_aid');
    if ((int) $a['student_id'] !== (int) $inv['student_id']) throw new RuntimeException('award_other_student');
    if (mam_db_row('SELECT id FROM fee_invoice_credits WHERE invoice_id = ? AND award_id = ?', [$invoiceId, $awardId])) {
        throw new RuntimeException('already_applied');
    }

    // Aid applies to the CHARGEABLE amount — gross less waivers — because a
    // waived line was never billed and the pool must not pay for it.
    $chargeable = (int) $inv['gross_minor'] - (int) $inv['waiver_minor'];
    $minor = $overrideAmount !== null
        ? mam_fee_to_minor($overrideAmount)
        : ($a['pct'] !== null ? mam_fee_pct_of($chargeable, (float) $a['pct']) : (int) $a['amount_minor']);
    if ($minor <= 0) throw new RuntimeException('nothing_to_credit');

    // Never more than is chargeable, less what other aid already covers.
    $already = (int) (mam_db_row('SELECT COALESCE(SUM(amount_minor),0) AS s FROM fee_invoice_credits WHERE invoice_id = ?', [$invoiceId])['s'] ?? 0);
    $room = $chargeable - $already;
    if ($room <= 0) throw new RuntimeException('nothing_to_credit');
    if ($minor > $room) $minor = $room;

    $id = mam_db_insert('fee_invoice_credits', [
        'invoice_id'   => $invoiceId,
        'award_id'     => $awardId,
        'kind'         => $a['kind'],
        'amount_minor' => $minor,
        'currency'     => (string) $inv['currency'],
        'applied_by'   => mam_fee_actor($actorId),
        'applied_at'   => date('Y-m-d H:i:s'),
    ]);
    mam_fee_recalc($invoiceId);
    mam_audit('fee_credit', 'fee_invoice', $invoiceId, ['award' => $awardId, 'kind' => $a['kind'], 'minor' => $minor]);
    return $id;
}

/**
 * Recompute and store the invoice's stages. **The one implementation of the
 * order**, so the four mechanisms cannot drift apart:
 *
 *     gross → less waivers → less aid credits → less sibling discount → total
 *
 * The sibling discount is a percentage of what is left AFTER aid, capped at the
 * published maximum. Both the percentage and the cap are settings, and the cap is
 * applied to the computed amount rather than to the percentage — a cap on the
 * percentage would let a large invoice exceed the published maximum, which is the
 * thing the maximum exists to prevent.
 *
 * All of it in the invoice's own currency, in minor units. `total_usd_cents` is
 * derived once for cross-invoice reporting; BI reads that column and never sums
 * `total_minor` across currencies.
 */
function mam_fee_recalc(int $invoiceId): array {
    $inv = mam_db_row('SELECT * FROM fee_invoices WHERE id = ?', [$invoiceId]);
    if (!$inv) throw new RuntimeException('invoice_not_found');
    $cur = (string) $inv['currency'];

    // 1 · gross, and the waivers that reduce it.
    $agg = mam_db_row(
        'SELECT COALESCE(SUM(amount_minor),0) AS gross, COALESCE(SUM(waiver_minor),0) AS waived
           FROM fee_invoice_lines WHERE invoice_id = ?', [$invoiceId]
    );
    $gross  = (int) ($agg['gross'] ?? 0);
    $waiver = (int) ($agg['waived'] ?? 0);

    // 2 · gift aid actually applied to this invoice.
    $aid = (int) (mam_db_row('SELECT COALESCE(SUM(amount_minor),0) AS s FROM fee_invoice_credits WHERE invoice_id = ?', [$invoiceId])['s'] ?? 0);
    if ($aid > $gross - $waiver) $aid = max(0, $gross - $waiver);

    // 3 · the sibling discount, on the POST-AID balance, then capped.
    $sibling = 0;
    $postAid = $gross - $waiver - $aid;
    if ($postAid > 0) {
        $awards = mam_fee_aid_for((int) $inv['student_id'], 'sibling');
        if ($awards) {
            $a = $awards[0];
            $pct = $a['pct'] !== null ? (float) $a['pct'] : mam_setting_num('sibling_discount_pct', MAM_FEE_SIBLING_PCT_DEFAULT);
            $sibling = $a['amount_minor'] !== null ? (int) $a['amount_minor'] : mam_fee_pct_of($postAid, $pct);
            // The cap is a published MAXIMUM AMOUNT. Capping the percentage
            // instead would let a large invoice quietly exceed it.
            $capMinor = mam_fee_to_minor((string) mam_setting_num('sibling_discount_max', MAM_FEE_SIBLING_CAP_DEFAULT));
            if ($capMinor > 0 && $sibling > $capMinor) $sibling = $capMinor;
            if ($sibling > $postAid) $sibling = $postAid;
        }
    }

    $total = $gross - $waiver - $aid - $sibling;

    // 4 · the frozen rate, and the one cross-invoice summable figure.
    $rate = $inv['fx_rate_usd_ngn'] !== null ? (float) $inv['fx_rate_usd_ngn'] : null;
    $usdCents = null;
    if ($cur === 'USD') {
        $usdCents = $total;
    } elseif (function_exists('mam_money_usd_cents')) {
        // Through money.php so there is ONE conversion definition in the repo.
        $usdCents = mam_money_usd_cents((float) mam_fee_from_minor($total), $cur, $rate);
    }

    $paid = (int) (mam_db_row("SELECT COALESCE(SUM(amount_minor),0) AS s FROM fee_plan_instalments i
        JOIN fee_payment_plans p ON p.id = i.plan_id
        WHERE p.invoice_id = ? AND i.status = 'paid'", [$invoiceId])['s'] ?? 0);
    /* Payments the office recorded (v91, mam_fee_payment_record) are the
     * authority once an invoice has any: they can go DOWN as well as up, because
     * a payment recorded in error is voided and the balance must come back.
     * Without any, the older rule stands — paid never falls below what was stored. */
    $hasPayments = (bool) mam_db_row('SELECT 1 FROM fee_payments WHERE invoice_id = ? LIMIT 1', [$invoiceId]);
    if ($hasPayments) {
        $received = (int) (mam_db_row("SELECT COALESCE(SUM(amount_minor),0) AS s FROM fee_payments WHERE invoice_id = ? AND status = 'recorded'", [$invoiceId])['s'] ?? 0);
        if ($received > $paid) $paid = $received;
    } elseif ($paid < (int) $inv['paid_minor']) {
        $paid = (int) $inv['paid_minor'];
    }
    if ($paid > $total) $paid = $total;

    $status = (string) $inv['status'];
    if ($status !== 'draft' && $status !== 'void') {
        $status = $paid >= $total && $total > 0 ? 'paid' : ($paid > 0 ? 'part_paid' : 'issued');
    }

    mam_db_update('fee_invoices', [
        'gross_minor'   => $gross,
        'waiver_minor'  => $waiver,
        'aid_minor'     => $aid,
        'sibling_minor' => $sibling,
        'total_minor'   => $total,
        'paid_minor'    => $paid,
        'total_usd_cents' => $usdCents,
        'status'        => $status,
        'updated_at'    => date('Y-m-d H:i:s'),
    ], ['id' => $invoiceId]);

    return ['gross_minor' => $gross, 'waiver_minor' => $waiver, 'aid_minor' => $aid,
            'sibling_minor' => $sibling, 'total_minor' => $total, 'paid_minor' => $paid,
            'total_usd_cents' => $usdCents, 'status' => $status];
}

/**
 * Issue the invoice: freeze the rate, set the dates, make it visible to a family.
 *
 * The rate is frozen HERE and nowhere else. A conversion computed on every read
 * would make a family's May statement disagree with itself next year, and the
 * design asks for the rate on the face of the invoice precisely so it does not.
 */
function mam_fee_invoice_issue(int $invoiceId, ?int $actorId = null, ?string $dueOn = null): array {
    $inv = mam_db_row('SELECT * FROM fee_invoices WHERE id = ?', [$invoiceId]);
    if (!$inv) throw new RuntimeException('invoice_not_found');
    if ($inv['status'] === 'void') throw new RuntimeException('invoice_void');
    if ($inv['status'] !== 'draft') throw new RuntimeException('already_issued');
    if ((int) (mam_db_row('SELECT COUNT(*) AS n FROM fee_invoice_lines WHERE invoice_id = ?', [$invoiceId])['n'] ?? 0) === 0) {
        throw new RuntimeException('no_lines');
    }

    $today = function_exists('mam_calendar_today') ? mam_calendar_today() : date('Y-m-d');
    $dueDays   = (int) mam_setting_num('fee_due_days', MAM_FEE_DUE_DAYS_DEFAULT);
    $graceDays = (int) mam_setting_num('fee_grace_days', MAM_FEE_GRACE_DAYS_DEFAULT);
    $due   = $dueOn ?: date('Y-m-d', strtotime($today . ' +' . $dueDays . ' days'));
    $grace = date('Y-m-d', strtotime($due . ' +' . $graceDays . ' days'));

    $rate = null;
    if (function_exists('mam_usd_to_ngn_rate')) {
        try { $rate = mam_usd_to_ngn_rate(false); } catch (\Throwable $e) { $rate = null; }
    }

    mam_db_update('fee_invoices', [
        'status' => 'issued', 'issued_on' => $today, 'due_on' => $due, 'grace_until' => $grace,
        'fx_rate_usd_ngn' => $rate, 'updated_at' => date('Y-m-d H:i:s'),
    ], ['id' => $invoiceId]);
    $t = mam_fee_recalc($invoiceId);
    mam_audit('fee_invoice_issue', 'fee_invoice', $invoiceId, [
        'ref' => $inv['public_ref'], 'total_minor' => $t['total_minor'], 'currency' => $inv['currency'],
        'due_on' => $due, 'rate' => $rate, 'by' => mam_fee_actor($actorId),
    ]);
    return $t;
}

/** Void an invoice. Keeps the row and the reason — a bill withdrawn is an event. */
function mam_fee_invoice_void(int $invoiceId, string $reason, ?int $actorId = null): void {
    $reason = trim($reason);
    if ($reason === '') throw new InvalidArgumentException('reason_required');
    $inv = mam_db_row('SELECT * FROM fee_invoices WHERE id = ?', [$invoiceId]);
    if (!$inv) throw new RuntimeException('invoice_not_found');
    if ($inv['status'] === 'void') throw new RuntimeException('already_void');
    if ((int) $inv['paid_minor'] > 0) throw new RuntimeException('has_payments');
    mam_db_update('fee_invoices', [
        'status' => 'void', 'void_reason' => $reason, 'updated_at' => date('Y-m-d H:i:s'),
    ], ['id' => $invoiceId]);
    mam_audit('fee_invoice_void', 'fee_invoice', $invoiceId, ['reason' => $reason, 'by' => mam_fee_actor($actorId)]);
}

/**
 * One invoice, assembled the way the Fees screen reads it: the lines, the four
 * stages named separately, the frozen rate, the live estimate, and the refund
 * rule that applies today.
 */
function mam_fee_invoice_get(int $invoiceId): ?array {
    $inv = mam_db_row(
        'SELECT fi.*, u.name AS student_name, g.name AS guardian_name
           FROM fee_invoices fi
           JOIN users u ON u.id = fi.student_id
      LEFT JOIN users g ON g.id = fi.guardian_id
          WHERE fi.id = ?', [$invoiceId]
    );
    if (!$inv) return null;
    $cur = (string) $inv['currency'];

    $lines = [];
    foreach (mam_db_all('SELECT * FROM fee_invoice_lines WHERE invoice_id = ? ORDER BY sort_order, id', [$invoiceId]) as $l) {
        $lines[] = [
            'id' => (int) $l['id'], 'kind' => $l['kind'], 'label' => $l['label'],
            'qty' => (int) $l['qty'],
            'amount_minor' => (int) $l['amount_minor'],
            'amount' => mam_fee_display((int) $l['amount_minor'], $cur),
            'waiver_minor' => (int) $l['waiver_minor'],
            'waiver' => (int) $l['waiver_minor'] > 0 ? mam_fee_display((int) $l['waiver_minor'], $cur) : null,
            'waiver_reason' => $l['waiver_reason'],
            'net_minor' => (int) $l['amount_minor'] - (int) $l['waiver_minor'],
        ];
    }

    $credits = [];
    foreach (mam_db_all('SELECT * FROM fee_invoice_credits WHERE invoice_id = ? ORDER BY id', [$invoiceId]) as $c) {
        $credits[] = ['id' => (int) $c['id'], 'kind' => $c['kind'],
            'amount_minor' => (int) $c['amount_minor'],
            'amount' => mam_fee_display((int) $c['amount_minor'], $cur), 'note' => $c['note']];
    }

    $total = (int) $inv['total_minor'];
    $due   = $total - (int) $inv['paid_minor'];

    /* The charge estimate. The FROZEN rate is what reconciles; the LIVE rate is
     * what a parent is about to be charged, and the two are stated separately so
     * neither is mistaken for the other. */
    $frozen = $inv['fx_rate_usd_ngn'] !== null ? (float) $inv['fx_rate_usd_ngn'] : null;
    $live   = function_exists('mam_usd_to_ngn_rate') ? mam_usd_to_ngn_rate(false) : null;
    $charge = null;
    if ($cur === 'USD' && $live) {
        $ngn = mam_money_ngn_from_usd_cents($due, $live);
        $charge = [
            'currency' => 'NGN',
            'amount'   => '₦' . number_format($ngn),
            'rate'     => $live,
            'rate_label' => '₦' . number_format((int) round($live)) . ' to $1',
            'estimate' => true,
        ];
    }

    return [
        'id'          => (int) $inv['id'],
        'ref'         => $inv['public_ref'],
        'student_id'  => (int) $inv['student_id'],
        'student_name'=> $inv['student_name'],
        'guardian'    => $inv['guardian_name'],
        'term_label'  => $inv['term_label'],
        'status'      => $inv['status'],
        'currency'    => $cur,
        'issued_on'   => $inv['issued_on'],
        'due_on'      => $inv['due_on'],
        'grace_until' => $inv['grace_until'],
        'overdue_days'=> mam_fee_overdue_days($inv),
        'lines'       => $lines,
        'credits'     => $credits,
        // Each stage named, so the arithmetic on the screen can be followed.
        'gross'       => mam_fee_display((int) $inv['gross_minor'], $cur),
        'waiver'      => (int) $inv['waiver_minor'] > 0 ? mam_fee_display((int) $inv['waiver_minor'], $cur) : null,
        'aid'         => (int) $inv['aid_minor'] > 0 ? mam_fee_display((int) $inv['aid_minor'], $cur) : null,
        'sibling'     => (int) $inv['sibling_minor'] > 0 ? mam_fee_display((int) $inv['sibling_minor'], $cur) : null,
        'total'        => mam_fee_display($total, $cur),
        'paid'         => mam_fee_display((int) $inv['paid_minor'], $cur),
        'due'          => mam_fee_display($due, $cur),
        'due_minor'    => $due,
        'total_minor'  => $total,
        'frozen_rate'  => $frozen,
        'frozen_rate_label' => $frozen ? '₦' . number_format((int) round($frozen)) . ' to $1' : null,
        'charge'       => $charge,
        'plan'         => mam_fee_plan_for($invoiceId),
        'note'         => $inv['note'],
        'void_reason'  => $inv['void_reason'],
    ];
}

/** Days past due, or null when it is not overdue. Lagos time decides the date. */
function mam_fee_overdue_days(array $inv): ?int {
    if (empty($inv['due_on']) || in_array($inv['status'], ['paid', 'void', 'draft'], true)) return null;
    $today = function_exists('mam_calendar_today') ? mam_calendar_today() : date('Y-m-d');
    $d = (int) floor((strtotime($today) - strtotime((string) $inv['due_on'])) / 86400);
    return $d > 0 ? $d : null;
}

/* ─────────────────────────────────────────────────────────────────────────────
   Payment plans
   ───────────────────────────────────────────────────────────────────────────── */

/**
 * "Pay in 3 instalments instead."
 *
 * The plan does not change the total; it changes when each part falls due. So the
 * instalments must SUM to the balance exactly, and the rounding remainder goes on
 * the FIRST one — front-loaded rather than trailing, so a family never discovers a
 * larger final payment than the plan showed them, and never has a rounding
 * shortfall left owing after paying every instalment.
 */
function mam_fee_plan_create(int $invoiceId, int $instalments, ?int $requestedBy = null, ?int $approvedBy = null): array {
    if ($instalments < 2 || $instalments > 12) throw new InvalidArgumentException('bad_instalments');
    $inv = mam_db_row('SELECT * FROM fee_invoices WHERE id = ?', [$invoiceId]);
    if (!$inv) throw new RuntimeException('invoice_not_found');
    if ($inv['status'] === 'draft') throw new RuntimeException('not_issued');
    if (!mam_fee_editable($inv)) throw new RuntimeException('invoice_closed');
    if (mam_fee_plan_for($invoiceId)) throw new RuntimeException('plan_exists');

    $balance = (int) $inv['total_minor'] - (int) $inv['paid_minor'];
    if ($balance <= 0) throw new RuntimeException('nothing_owing');
    if ($balance < $instalments) throw new RuntimeException('too_small_to_split');

    $planId = mam_db_insert('fee_payment_plans', [
        'invoice_id'   => $invoiceId,
        'instalments'  => $instalments,
        'status'       => 'active',
        'requested_by' => mam_fee_actor($requestedBy),
        'approved_by'  => mam_fee_actor($approvedBy),
        'created_at'   => date('Y-m-d H:i:s'),
    ]);

    $each = intdiv($balance, $instalments);
    $rem  = $balance - ($each * $instalments);
    $from = (string) ($inv['due_on'] ?: (function_exists('mam_calendar_today') ? mam_calendar_today() : date('Y-m-d')));
    for ($i = 1; $i <= $instalments; $i++) {
        mam_db_insert('fee_plan_instalments', [
            'plan_id'      => $planId,
            'seq'          => $i,
            'due_on'       => date('Y-m-d', strtotime($from . ' +' . (($i - 1) * 30) . ' days')),
            'amount_minor' => $each + ($i === 1 ? $rem : 0),
            'status'       => 'pending',
        ]);
    }
    mam_audit('fee_plan_create', 'fee_invoice', $invoiceId, ['plan' => $planId, 'instalments' => $instalments, 'balance_minor' => $balance]);
    return mam_fee_plan_for($invoiceId) ?? [];
}

/** The live plan for an invoice, with its instalments. */
function mam_fee_plan_for(int $invoiceId): ?array {
    $p = mam_db_row("SELECT * FROM fee_payment_plans WHERE invoice_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1", [$invoiceId]);
    if (!$p) return null;
    $inv = mam_db_row('SELECT currency FROM fee_invoices WHERE id = ?', [$invoiceId]);
    $cur = (string) ($inv['currency'] ?? 'USD');
    $rows = [];
    foreach (mam_db_all('SELECT * FROM fee_plan_instalments WHERE plan_id = ? ORDER BY seq', [(int) $p['id']]) as $i) {
        $rows[] = ['seq' => (int) $i['seq'], 'due_on' => $i['due_on'], 'status' => $i['status'],
            'amount_minor' => (int) $i['amount_minor'], 'amount' => mam_fee_display((int) $i['amount_minor'], $cur)];
    }
    // A plan a guardian asked for and the office has not yet agreed is shown as
    // such: the schedule is a proposal until somebody approves it.
    return ['id' => (int) $p['id'], 'instalments' => (int) $p['instalments'],
            'status' => $p['status'], 'approved' => $p['approved_by'] !== null && (int) $p['approved_by'] > 0,
            'schedule' => $rows];
}

/** Cancel a plan. The debt does not go away; the schedule does. */
function mam_fee_plan_cancel(int $planId, string $reason, ?int $actorId = null): void {
    $reason = trim($reason);
    if ($reason === '') throw new InvalidArgumentException('reason_required');
    $p = mam_db_row('SELECT * FROM fee_payment_plans WHERE id = ?', [$planId]);
    if (!$p) throw new RuntimeException('plan_not_found');
    if ($p['status'] !== 'active') throw new RuntimeException('not_active');
    mam_db_update('fee_payment_plans', [
        'status' => 'cancelled', 'cancelled_at' => date('Y-m-d H:i:s'), 'cancel_reason' => $reason,
    ], ['id' => $planId]);
    mam_audit('fee_plan_cancel', 'fee_invoice', (int) $p['invoice_id'], ['plan' => $planId, 'reason' => $reason, 'by' => mam_fee_actor($actorId)]);
}

/* ─────────────────────────────────────────────────────────────────────────────
   The guardian's statement
   ───────────────────────────────────────────────────────────────────────────── */

/**
 * The Fees screen, for one guardian and one child.
 *
 * Scope-gated server-side (`invoices`), which every scope including `billing`
 * grants — a billing-only contact exists to see exactly this.
 *
 * Carries the refund rule that applies TODAY, from the academic calendar, because
 * the design puts "If you withdraw a child" on this screen and the calendar is
 * the only authority on a refund in this repo.
 */
function mam_fee_statement(int $parentId, int $studentId): array {
    if (!mam_parent_can_view($parentId, $studentId)) throw new RuntimeException('forbidden');
    if (!mam_parent_scope_allows($parentId, $studentId, 'invoices')) {
        return ['scope_denied' => true, 'invoices' => [], 'receipts' => []];
    }

    $out = [];
    foreach (mam_db_all(
        "SELECT id FROM fee_invoices WHERE student_id = ? AND status <> 'draft' AND status <> 'void'
          ORDER BY COALESCE(issued_on, created_at) DESC, id DESC", [$studentId]) as $r) {
        $inv = mam_fee_invoice_get((int) $r['id']);
        if ($inv) $out[] = $inv;
    }

    // With nothing owed the figure is still printed ("Due now ₦0.00"), so it takes
    // the family's own currency from their newest invoice, not the academy's
    // default — a naira family was told they owed "$0.00".
    $owedMinor = 0; $cur = $out[0]['currency'] ?? mam_fee_currency();
    foreach ($out as $i) {
        if (in_array($i['status'], ['issued', 'part_paid'], true)) { $owedMinor += $i['due_minor']; $cur = $i['currency']; }
    }

    /* "Nothing is withheld from a child over money." Stated as data so the screen
     * cannot forget it, and so the sentence lives beside the balance rather than
     * in a footer nobody reads. */
    $refund = null;
    if (function_exists('mam_calendar_refund_for')) {
        try { $refund = mam_calendar_refund_for(); } catch (\Throwable $e) { $refund = null; }
    }

    return [
        'scope_denied' => false,
        'currency'   => $cur,
        'owed_minor' => $owedMinor,
        'owed'       => mam_fee_display($owedMinor, $cur),
        'invoices'   => $out,
        'receipts'   => mam_fee_receipts($studentId),
        'refund_rule'=> $refund,
        'assurance'  => 'Nothing is withheld from a child over money — not their lessons, '
                      . 'not their report, and not their safeguarding record.',
        'dunning'    => ['reminder_days' => MAM_FEE_DUNNING_DAYS,
                         'grace_days' => (int) mam_setting_num('fee_grace_days', MAM_FEE_GRACE_DAYS_DEFAULT),
                         'next_step' => 'a conversation with the academy office'],
    ];
}

/**
 * Receipts for a child — the settled payments, newest first.
 *
 * Reads `amount_usd_cents` and NEVER `amount`: that column is the CHARGE
 * currency, and summing NGN into a USD total is the bug the v61 column exists to
 * prevent. A row with no usd_cents is shown with its own charged figure and
 * counted nowhere.
 */
function mam_fee_receipts(int $studentId, int $limit = 25): array {
    $limit = max(1, min(200, $limit));
    /* `applications` carries no email of its own — the address is on `customers`
     * via `applications.customer_id`, which is the join datarights.php uses too.
     * Matching on the address is deliberate and is the same compromise
     * academic-record.php makes for `mail_log`: a payment belongs to an
     * application, an application to a customer, and a customer to a person only
     * by the address they used. A student provisioned under a different address
     * from the one that paid will not see that receipt here, which is a real
     * limitation and better than guessing. */
    $rows = mam_db_all(
        "SELECT p.id, p.reference, p.amount, p.currency, p.amount_usd_cents, p.purpose,
                COALESCE(p.verified_at, p.created_at) AS at
           FROM payments p
           JOIN applications a ON a.id = p.application_id
           JOIN customers  c ON c.id = a.customer_id
           JOIN users      u ON LOWER(u.email) = LOWER(c.email)
          WHERE u.id = ? AND p.status = 'succeeded'
          ORDER BY at DESC LIMIT " . $limit, [$studentId]
    );
    $out = [];
    /* Payments the office recorded against a fee invoice (v91). In the invoice's
     * own currency, never converted — the receipt says what the family paid. */
    foreach (mam_db_all(
        "SELECT fp.id, fp.amount_minor, fp.currency, fp.method, fp.reference, fp.received_on, fi.public_ref
           FROM fee_payments fp JOIN fee_invoices fi ON fi.id = fp.invoice_id
          WHERE fi.student_id = ? AND fp.status = 'recorded'
          ORDER BY fp.received_on DESC, fp.id DESC LIMIT " . $limit, [$studentId]) as $f) {
        $out[] = [
            'id'        => 'fee-' . (int) $f['id'],
            'reference' => $f['reference'] ?: $f['public_ref'],
            'purpose'   => 'Fees · ' . $f['public_ref'],
            'at'        => $f['received_on'],
            'amount'    => mam_fee_display((int) $f['amount_minor'], (string) $f['currency']),
            'charged'   => null,
            'unpriced'  => false,
            'method'    => $f['method'],
        ];
    }
    foreach ($rows as $p) {
        $usd = $p['amount_usd_cents'] !== null ? (int) $p['amount_usd_cents'] : null;
        $out[] = [
            'id'        => (int) $p['id'],
            'reference' => $p['reference'],
            'purpose'   => $p['purpose'] ?: 'tuition',
            'at'        => $p['at'],
            // The honest figure first; the charged one beside it when they differ.
            'amount'    => $usd !== null ? '$' . number_format($usd / 100, 2)
                                         : mam_fee_symbol((string) $p['currency']) . number_format((float) $p['amount'], 2),
            'charged'   => strtoupper((string) $p['currency']) !== 'USD'
                             ? mam_fee_symbol((string) $p['currency']) . number_format((float) $p['amount'])
                             : null,
            'unpriced'  => $usd === null,
        ];
    }
    usort($out, static fn($x, $y) => strcmp((string) $y['at'], (string) $x['at']));
    return array_slice($out, 0, $limit);
}

/* ─────────────────────────────────────────────────────────────────────────────
   Bursary applications
   ───────────────────────────────────────────────────────────────────────────── */

/**
 * Apply for a bursary, on a declared income band.
 *
 * There is no hardship-statement parameter, and adding one would be a decision to
 * reverse the design's reasoning rather than an enhancement. `dependants_in_academy`
 * is collected because it changes the band's meaning, not to be scored.
 *
 * Nothing here touches an application or an admission record. Applying must never
 * affect an admission decision, and the way that stays true is that admissions
 * cannot see this table.
 */
function mam_bursary_apply(int $studentId, int $guardianId, string $incomeBand, array $in = []): int {
    if (!isset(MAM_BURSARY_BANDS[$incomeBand])) throw new InvalidArgumentException('bad_band');
    if (!mam_parent_can_view($guardianId, $studentId)) throw new RuntimeException('forbidden');
    if (mam_db_row("SELECT id FROM bursary_applications WHERE student_id = ? AND status IN ('submitted','assessing')", [$studentId])) {
        throw new RuntimeException('already_open');
    }

    $termId = null; $termLabel = null;
    if (function_exists('mam_calendar_current_term')) {
        try {
            $t = mam_calendar_current_term();
            if (!empty($t['term']) && ($t['source'] ?? '') === 'calendar') $termId = (int) $t['term']['id'];
            $termLabel = (string) ($t['term']['label'] ?? $t['label'] ?? '');
        } catch (\Throwable $e) { /* an unauthored calendar must not stop an application */ }
    }

    $id = mam_db_insert('bursary_applications', [
        'student_id'  => $studentId,
        'guardian_id' => mam_fee_actor($guardianId),
        'term_id'     => $termId,
        'term_label'  => $termLabel,
        'income_band' => $incomeBand,
        'household_size' => isset($in['household_size']) && (int) $in['household_size'] > 0 ? (int) $in['household_size'] : null,
        'dependants_in_academy' => max(1, (int) ($in['dependants_in_academy'] ?? 1)),
        'status'      => 'submitted',
        'created_at'  => date('Y-m-d H:i:s'),
    ]);
    mam_audit('bursary_apply', 'bursary_application', $id, ['student' => $studentId, 'band' => $incomeBand]);
    return $id;
}

/**
 * Decide a bursary application. `awarded` mints the aid award and links it, so
 * the decision and the entitlement cannot drift apart.
 *
 * `awarded_pct` may be BELOW the band's indicative figure — a committee weighing a
 * finite pool — but never above it, because the table is published and a family
 * that reads it is entitled to the answer it gives.
 */
function mam_bursary_decide(int $appId, string $status, ?int $actorId, string $outcomeNote, ?float $pct = null): array {
    if (!in_array($status, ['awarded', 'declined', 'withdrawn', 'assessing'], true)) throw new InvalidArgumentException('bad_status');
    $a = mam_db_row('SELECT * FROM bursary_applications WHERE id = ?', [$appId]);
    if (!$a) throw new RuntimeException('application_not_found');
    if (!in_array($a['status'], ['submitted', 'assessing'], true)) throw new RuntimeException('already_decided');

    if ($status === 'assessing') {
        mam_db_update('bursary_applications', ['status' => 'assessing'], ['id' => $appId]);
        return ['id' => $appId, 'status' => 'assessing'];
    }

    $outcomeNote = trim($outcomeNote);
    if ($outcomeNote === '') throw new InvalidArgumentException('outcome_note_required');

    $awardId = null;
    if ($status === 'awarded') {
        $band = MAM_BURSARY_BANDS[$a['income_band']] ?? null;
        $ceiling = $band['pct'] ?? null;
        if ($pct === null) $pct = $ceiling;
        if ($pct === null || $pct <= 0) throw new InvalidArgumentException('bad_pct');
        if ($ceiling !== null && $pct > $ceiling) throw new RuntimeException('above_published_band');
        $awardId = mam_fee_aid_award((int) $a['student_id'], 'bursary', [
            'pct' => $pct, 'term_id' => $a['term_id'], 'term_label' => $a['term_label'],
            'basis' => 'Income band: ' . ($band['label'] ?? $a['income_band']),
        ], $actorId);
    }

    mam_db_update('bursary_applications', [
        'status' => $status, 'awarded_pct' => $status === 'awarded' ? $pct : null,
        'award_id' => $awardId, 'decided_by' => mam_fee_actor($actorId),
        'decided_at' => date('Y-m-d H:i:s'), 'outcome_note' => $outcomeNote,
    ], ['id' => $appId]);
    mam_audit('bursary_decide', 'bursary_application', $appId, ['status' => $status, 'pct' => $pct, 'award' => $awardId]);
    return ['id' => $appId, 'status' => $status, 'award_id' => $awardId, 'awarded_pct' => $pct];
}

/** The bands, for the apply form. Labels and indicative percentages, published. */
function mam_bursary_bands(): array {
    $out = [];
    foreach (MAM_BURSARY_BANDS as $k => $b) {
        $out[] = ['code' => $k, 'label' => $b['label'], 'indicative_pct' => $b['pct']];
    }
    return $out;
}

/** A guardian's view of their own bursary applications for one child. */
function mam_bursary_for_guardian(int $parentId, int $studentId): array {
    if (!mam_parent_can_view($parentId, $studentId)) throw new RuntimeException('forbidden');
    $rows = mam_db_all(
        'SELECT id, income_band, status, awarded_pct, outcome_note, created_at, decided_at
           FROM bursary_applications WHERE student_id = ? ORDER BY id DESC', [$studentId]
    );
    foreach ($rows as &$r) {
        $r['band_label'] = MAM_BURSARY_BANDS[$r['income_band']]['label'] ?? $r['income_band'];
        // The assessor's private `note` is deliberately not selected above.
    }
    unset($r);
    return $rows;
}


/* ─────────────────────────────────────────────────────────────────────────────
   The office's side: payments received, plans agreed, reminders sent
   (schema v91; HANDOFF §4t). v87 built invoices, aid and plans and gave the
   office no way to act on any of them — no invoice could be raised, no bursary
   decided, nothing marked paid. These are what the console's actions call.
   ───────────────────────────────────────────────────────────────────────────── */

const MAM_FEE_PAY_METHODS = ['transfer' => 'Bank transfer', 'cash' => 'Cash', 'pos' => 'POS terminal', 'card' => 'Card', 'other' => 'Other'];

/**
 * Record money received against an issued invoice: one row per payment, in the
 * invoice's own currency. Refused on a draft (nothing is owed yet), a void
 * invoice, and for more than is still due — an overpayment is a conversation
 * and a refund, not a negative balance.
 */
function mam_fee_payment_record(int $invoiceId, $amount, string $method, ?int $actorId = null, array $opts = []): int {
    $inv = mam_db_row('SELECT * FROM fee_invoices WHERE id = ?', [$invoiceId]);
    if (!$inv) throw new RuntimeException('invoice_not_found');
    if ($inv['status'] === 'draft') throw new RuntimeException('not_issued');
    if ($inv['status'] === 'void') throw new RuntimeException('invoice_void');
    if (!isset(MAM_FEE_PAY_METHODS[$method])) throw new InvalidArgumentException('bad_method');
    $minor = mam_fee_to_minor($amount);
    if ($minor <= 0) throw new InvalidArgumentException('bad_amount');
    $due = (int) $inv['total_minor'] - (int) $inv['paid_minor'];
    if ($due <= 0) throw new RuntimeException('nothing_owing');
    if ($minor > $due) throw new RuntimeException('more_than_due');
    $on = trim((string) ($opts['received_on'] ?? ''));
    if ($on === '') $on = function_exists('mam_calendar_today') ? mam_calendar_today() : date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $on)) throw new InvalidArgumentException('bad_date');
    $ref = trim((string) ($opts['reference'] ?? ''));

    $id = mam_db_insert('fee_payments', [
        'invoice_id'   => $invoiceId,
        'amount_minor' => $minor,
        'currency'     => (string) $inv['currency'],
        'method'       => $method,
        'reference'    => $ref !== '' ? mb_substr($ref, 0, 120) : null,
        'received_on'  => $on,
        'note'         => trim((string) ($opts['note'] ?? '')) !== '' ? mb_substr(trim((string) $opts['note']), 0, 500) : null,
        'status'       => 'recorded',
        'recorded_by'  => mam_fee_actor($actorId),
        'created_at'   => date('Y-m-d H:i:s'),
    ]);
    $t = mam_fee_recalc($invoiceId);
    mam_fee_plan_allocate($invoiceId);
    mam_audit('fee_payment_record', 'fee_invoice', $invoiceId, [
        'payment' => $id, 'minor' => $minor, 'method' => $method, 'ref' => $ref, 'by' => mam_fee_actor($actorId),
    ]);
    return $id;
}

/** Void a payment recorded in error. The row stays, with the reason; the balance comes back. */
function mam_fee_payment_void(int $paymentId, string $reason, ?int $actorId = null): void {
    $reason = trim($reason);
    if ($reason === '') throw new InvalidArgumentException('reason_required');
    $p = mam_db_row('SELECT * FROM fee_payments WHERE id = ?', [$paymentId]);
    if (!$p) throw new RuntimeException('payment_not_found');
    if ($p['status'] === 'void') throw new RuntimeException('already_void');
    mam_db_update('fee_payments', ['status' => 'void', 'void_reason' => mb_substr($reason, 0, 500), 'voided_at' => date('Y-m-d H:i:s')], ['id' => $paymentId]);
    mam_fee_recalc((int) $p['invoice_id']);
    mam_fee_plan_allocate((int) $p['invoice_id']);
    mam_audit('fee_payment_void', 'fee_invoice', (int) $p['invoice_id'], ['payment' => $paymentId, 'reason' => $reason, 'by' => mam_fee_actor($actorId)]);
}

/** Every payment on an invoice, recorded and void, oldest first. */
function mam_fee_payments_for(int $invoiceId): array {
    return mam_db_all('SELECT fp.*, u.name AS recorded_by_name FROM fee_payments fp
        LEFT JOIN users u ON u.id = fp.recorded_by WHERE fp.invoice_id = ? ORDER BY fp.received_on, fp.id', [$invoiceId]);
}

/**
 * Mark a plan's instalments paid, in order, as far as the money received
 * covers them — and back to pending when a void takes it away. The plan never
 * holds money of its own; it is a schedule laid over `paid_minor`.
 */
function mam_fee_plan_allocate(int $invoiceId): void {
    $p = mam_db_row("SELECT id FROM fee_payment_plans WHERE invoice_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1", [$invoiceId]);
    if (!$p) return;
    if (!mam_db_row('SELECT 1 FROM fee_payments WHERE invoice_id = ? LIMIT 1', [$invoiceId])) return;
    $received = (int) (mam_db_row("SELECT COALESCE(SUM(amount_minor),0) AS s FROM fee_payments WHERE invoice_id = ? AND status = 'recorded'", [$invoiceId])['s'] ?? 0);
    // The plan was laid over the balance at the time, so money paid before it
    // existed is not this plan's: count only what came in on top of that.
    $planned = (int) (mam_db_row('SELECT COALESCE(SUM(amount_minor),0) AS s FROM fee_plan_instalments WHERE plan_id = ?', [(int) $p['id']])['s'] ?? 0);
    $inv = mam_db_row('SELECT total_minor FROM fee_invoices WHERE id = ?', [$invoiceId]);
    $before = max(0, (int) $inv['total_minor'] - $planned);
    $left = max(0, $received - $before);
    $allPaid = true; $newlyPaid = false;
    foreach (mam_db_all('SELECT id, amount_minor, status FROM fee_plan_instalments WHERE plan_id = ? ORDER BY seq', [(int) $p['id']]) as $i) {
        $covered = $left >= (int) $i['amount_minor'];
        if ($covered) $left -= (int) $i['amount_minor'];
        else $allPaid = false;
        $want = $covered ? 'paid' : 'pending';
        if ($i['status'] !== $want) {
            mam_db_update('fee_plan_instalments', ['status' => $want, 'paid_at' => $want === 'paid' ? date('Y-m-d H:i:s') : null], ['id' => (int) $i['id']]);
            if ($want === 'paid') $newlyPaid = true;
        }
    }
    // A cleared instalment starts the reminder ladder afresh for the next one.
    if ($newlyPaid) mam_db_update('fee_invoices', ['dunning_stage' => 0], ['id' => $invoiceId]);
    if ($allPaid) mam_db_update('fee_payment_plans', ['status' => 'completed'], ['id' => (int) $p['id']]);
}

/**
 * The office agrees a plan a guardian asked for. Until then the guardian sees it
 * as a request, and reminders keep to the invoice's own due date.
 */
function mam_fee_plan_approve(int $planId, ?int $actorId): void {
    $p = mam_db_row('SELECT * FROM fee_payment_plans WHERE id = ?', [$planId]);
    if (!$p) throw new RuntimeException('plan_not_found');
    if ($p['status'] !== 'active') throw new RuntimeException('not_active');
    if ($p['approved_by'] !== null && (int) $p['approved_by'] > 0) throw new RuntimeException('already_approved');
    $by = mam_fee_actor($actorId);
    if ($by === null) throw new InvalidArgumentException('approver_required');
    mam_db_update('fee_payment_plans', ['approved_by' => $by], ['id' => $planId]);
    // The dates that count are the instalments' now, so the reminders start again.
    mam_db_update('fee_invoices', ['dunning_stage' => 0], ['id' => (int) $p['invoice_id']]);
    mam_audit('fee_plan_approve', 'fee_invoice', (int) $p['invoice_id'], ['plan' => $planId, 'by' => $by]);
}

/**
 * Overdue reminders to the billing guardian, on the published ladder
 * (MAM_FEE_DUNNING_DAYS: the due date, then three and seven days after it).
 *
 * One email per invoice per run at most, and only ever FORWARD: an invoice
 * found already a week late gets the seven-day note once, not three notes at
 * once. After the last step the next step is a person — nothing here, or
 * anywhere, suspends a child's lessons, report or record over money. A plan the
 * office approved moves the date that counts to the next unpaid instalment.
 */
function mam_fee_dunning_sweep(int $limit = 100): int {
    if (function_exists('mam_automation_on') && !mam_automation_on('fee_reminders')) return 0;
    $today = function_exists('mam_calendar_today') ? mam_calendar_today() : date('Y-m-d');
    $appUrl = rtrim((string) mam_env('APP_URL', ''), '/');
    $sent = 0;
    $rows = mam_db_all(
        "SELECT fi.*, g.email AS guardian_email, g.name AS guardian_name, s.name AS student_name
           FROM fee_invoices fi
           JOIN users g ON g.id = fi.guardian_id
           JOIN users s ON s.id = fi.student_id
          WHERE fi.status IN ('issued','part_paid') AND fi.due_on IS NOT NULL AND fi.due_on <= ?
            AND fi.dunning_stage < ?
          ORDER BY fi.due_on, fi.id LIMIT " . max(1, $limit),
        [$today, count(MAM_FEE_DUNNING_DAYS)]
    );
    foreach ($rows as $inv) {
        if ((string) $inv['guardian_email'] === '') continue;
        $dueOn = (string) $inv['due_on'];
        $plan = mam_db_row("SELECT id FROM fee_payment_plans WHERE invoice_id = ? AND status = 'active' AND approved_by IS NOT NULL ORDER BY id DESC LIMIT 1", [(int) $inv['id']]);
        if ($plan) {
            $next = mam_db_row("SELECT due_on FROM fee_plan_instalments WHERE plan_id = ? AND status = 'pending' ORDER BY seq LIMIT 1", [(int) $plan['id']]);
            if (!$next) continue;
            $dueOn = (string) $next['due_on'];
        }
        $late = (int) floor((strtotime($today) - strtotime($dueOn)) / 86400);
        if ($late < 0) continue;
        $stage = 0;
        foreach (MAM_FEE_DUNNING_DAYS as $i => $d) if ($late >= $d) $stage = $i + 1;
        if ($stage <= (int) $inv['dunning_stage']) continue;
        // Claim the step first, so two overlapping runs cannot both send it.
        $won = mam_db()->prepare('UPDATE fee_invoices SET dunning_stage = ?, dunning_last = ? WHERE id = ? AND dunning_stage = ?');
        $won->execute([$stage, date('Y-m-d H:i:s'), (int) $inv['id'], (int) $inv['dunning_stage']]);
        if ($won->rowCount() < 1) continue;
        $due = mam_fee_display((int) $inv['total_minor'] - (int) $inv['paid_minor'], (string) $inv['currency']);
        $h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $first = trim(explode(' ', (string) $inv['guardian_name'])[0] ?? '') ?: 'there';
        $lead = $late === 0
            ? 'The fees for ' . $h($inv['student_name']) . ' (' . $h($inv['public_ref']) . ') fall due today.'
            : 'The fees for ' . $h($inv['student_name']) . ' (' . $h($inv['public_ref']) . ') were due on ' . $h($dueOn) . ' and ' . $h($due) . ' is still outstanding.';
        $last = $stage >= count(MAM_FEE_DUNNING_DAYS);
        $inner = "<h2 style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#1A120B'>"
            . ($late === 0 ? 'A reminder: fees due today' : 'Fees outstanding') . "</h2>"
            . "<p style='color:#4A3624;font-size:15px'>Hi " . $h($first) . " — {$lead}</p>"
            . "<p style='color:#4A3624;font-size:15px'>Amount due: <strong>" . $h($due) . "</strong>.</p>"
            . ($last ? "<p style='color:#4A3624;font-size:15px'>This is our last automatic reminder. If paying is difficult right now, reply to this email or call the academy office — a payment plan or a bursary may help, and nothing about " . $h($inv['student_name']) . "'s lessons, report or record is held back over fees.</p>"
                     : "<p style='color:#4A3624;font-size:15px'>If you have already paid, thank you — please ignore this. Nothing about " . $h($inv['student_name']) . "'s lessons, report or record is held back over fees.</p>")
            . ($appUrl !== '' ? mam_email_button($appUrl . '/portal/parent/', 'See the invoice') : '');
        $html = mam_email_layout([
            'header' => 'MAM ACADEMY',
            'preheader' => ($late === 0 ? 'Fees due today for ' : 'Fees outstanding for ') . (string) $inv['student_name'],
            'reason' => 'You received this because you are the billing contact for ' . (string) $inv['student_name'] . ' at MAM Academy.',
            'body' => $inner,
        ]);
        $ok = mam_send((string) $inv['guardian_email'], ($late === 0 ? 'Fees due today: ' : 'Fees outstanding: ') . (string) $inv['public_ref'], $html, 'fee_reminder', true);
        mam_audit('fee_reminder', 'fee_invoice', (int) $inv['id'], ['stage' => $stage, 'late_days' => $late, 'sent' => $ok]);
        if ($ok) $sent++;
    }
    return $sent;
}

/* ─────────────────────────────────────────────────────────────────────────────
   Paying a fee invoice online (schema v92)

   The guardian's Pay button. The charge is an ordinary `payments` row
   (purpose 'fee'), so the interactive verify and both webhooks settle it
   through mam_payment_settle() — the one race-safe claim every other charge
   uses — and settlement then records an ordinary `fee_payments` row, so the
   office sees an online payment exactly where it sees a bank transfer it
   typed in. Nothing here holds money of its own.

   Three things are easy to get wrong:
   - What is CREDITED is `fee_minor`, in the invoice's own currency, fixed when
     Pay was pressed. The naira charge is a conversion at today's rate; a
     dollar invoice must never be credited with whatever the naira came to.
   - The balance can shrink between Pay and settlement (the office records a
     cash payment in the meantime). The gateway has the money either way, so
     settlement credits what is still owed and records the rest as an
     overpayment for the office to refund — it never throws the money away
     and never drives a balance negative (`more_than_due` would refuse it).
   - The charge is compared against `amount_minor` (kobo, an integer), never
     `amount * 100`, which is a float and can land a hair above the kobo the
     gateway reports and fail a payment that was exact.
   ───────────────────────────────────────────────────────────────────────────── */

/**
 * What a fee charge costs at the gateway, which charges in naira.
 * NGN invoice: the balance itself, to the kobo. USD invoice: today's rate —
 * the same rate the statement's "You will be charged" sentence used.
 * @return array{kobo:int, usd_cents:?int, rate:?float}
 */
function mam_fee_online_quote(string $currency, int $feeMinor): array {
    $rate = function_exists('mam_usd_to_ngn_rate') ? (float) mam_usd_to_ngn_rate(false) : 0.0;
    if ($currency === 'NGN') {
        return ['kobo' => $feeMinor, 'usd_cents' => $rate > 0 ? (int) round($feeMinor / $rate) : null, 'rate' => $rate > 0 ? $rate : null];
    }
    if ($currency === 'USD') {
        if ($rate <= 0) throw new RuntimeException('no_exchange_rate');
        return ['kobo' => mam_money_ngn_from_usd_cents($feeMinor, $rate) * 100, 'usd_cents' => $feeMinor, 'rate' => $rate];
    }
    throw new RuntimeException('currency_not_payable_online');
}

/**
 * Open a gateway charge for (part of) an invoice. Does NOT talk to the gateway —
 * the route does — so this is testable and the refusals are the library's.
 *
 * @param ?int $feeMinor  how much of the balance, in the invoice currency; null = all of it
 * @return array the payments row, plus 'quote'
 */
function mam_fee_online_start(int $parentId, int $invoiceId, ?int $feeMinor, string $provider = 'paystack'): array {
    $inv = mam_db_row('SELECT * FROM fee_invoices WHERE id = ?', [$invoiceId]);
    if (!$inv) throw new RuntimeException('invoice_not_found');
    $sid = (int) $inv['student_id'];
    if (!mam_parent_can_view($parentId, $sid) || !mam_parent_scope_allows($parentId, $sid, 'payments')) {
        throw new RuntimeException('forbidden');
    }
    if (!in_array($inv['status'], ['issued', 'part_paid'], true)) throw new RuntimeException('not_payable');
    $due = (int) $inv['total_minor'] - (int) $inv['paid_minor'];
    if ($due <= 0) throw new RuntimeException('nothing_owing');
    $amt = $feeMinor ?? $due;
    if ($amt <= 0) throw new InvalidArgumentException('bad_amount');
    if ($amt > $due) throw new RuntimeException('more_than_due');

    $q = mam_fee_online_quote((string) $inv['currency'], $amt);
    if ($q['kobo'] < 100) throw new InvalidArgumentException('bad_amount'); // gateways refuse under ₦1

    // A fresh reference per press: a gateway refuses a reference it has seen,
    // and an abandoned attempt is a harmless pending row.
    $ref = 'FEE' . $invoiceId . '-' . strtoupper(bin2hex(random_bytes(5)));
    $now = date('Y-m-d H:i:s');
    $id = mam_db_insert('payments', [
        'application_id'   => null,
        'reference'        => $ref,
        'provider'         => $provider,
        'purpose'          => 'fee',
        'status'           => 'pending',
        'amount'           => number_format($q['kobo'] / 100, 2, '.', ''),
        'currency'         => 'NGN',
        'amount_minor'     => $q['kobo'],
        'amount_usd_cents' => $q['usd_cents'],
        'fx_rate_usd_ngn'  => $q['rate'],
        'fx_backfilled'    => 0,
        'fee_invoice_id'   => $invoiceId,
        'fee_minor'        => $amt,
        'fee_payer_id'     => $parentId,
        'created_at'       => $now,
        'updated_at'       => $now,
    ]);
    mam_audit('fee_online_start', 'fee_invoice', $invoiceId, ['payment' => $id, 'ref' => $ref, 'minor' => $amt, 'kobo' => $q['kobo'], 'by' => $parentId]);
    $row = mam_db_row('SELECT * FROM payments WHERE id = ?', [$id]);
    $row['quote'] = $q;
    return $row;
}

/** The fee_payments method an online charge is recorded under, from the gateway's channel. */
function mam_fee_online_method(?array $providerData): string {
    $ch = strtolower((string) ($providerData['channel'] ?? $providerData['payment_type'] ?? ''));
    if ($ch === 'card') return 'card';
    if (in_array($ch, ['bank', 'bank_transfer', 'banktransfer', 'account'], true)) return 'transfer';
    return 'other';
}

/**
 * Settlement for purpose 'fee'. Called by mam_payment_settle() AFTER its atomic
 * claim, so it runs once per charge; the reference check below makes it safe to
 * call again anyway. Never throws — the money has arrived whatever happens here.
 */
function mam_payment_settle_fee(array $payment, ?array $providerData = null): void {
    $ref = (string) $payment['reference'];
    try {
        $invId = (int) ($payment['fee_invoice_id'] ?? 0);
        $want  = (int) ($payment['fee_minor'] ?? 0);
        $inv   = $invId ? mam_db_row('SELECT * FROM fee_invoices WHERE id = ?', [$invId]) : null;
        if (!$inv || $want <= 0) {
            mam_log('error', 'fee_online_unmatched', ['ref' => $ref, 'invoice' => $invId]);
            mam_audit('fee_online_unmatched', 'payment', (int) ($payment['id'] ?? 0), ['ref' => $ref]);
            return;
        }
        if (mam_db_row('SELECT 1 FROM fee_payments WHERE invoice_id = ? AND reference = ?', [$invId, $ref])) return;

        $cur  = (string) $inv['currency'];
        $due  = in_array($inv['status'], ['issued', 'part_paid'], true) ? (int) $inv['total_minor'] - (int) $inv['paid_minor'] : 0;
        $take = max(0, min($want, $due));
        $over = $want - $take;
        $prov = ucfirst((string) ($payment['provider'] ?? 'online'));
        if ($take > 0) {
            mam_fee_payment_record($invId, mam_fee_from_minor($take), mam_fee_online_method($providerData), null, [
                'reference' => $ref,
                'note' => 'Paid online through ' . $prov
                    . ($over > 0 ? '. ' . mam_fee_display($over, $cur) . ' more than the balance was taken — refund it.' : '.'),
            ]);
        }
        if ($over > 0) {
            // The balance shrank between Pay and settlement (or the invoice was
            // voided). The family paid it; the office owes it back.
            mam_log('warn', 'fee_online_overpaid', ['ref' => $ref, 'invoice' => $invId, 'over_minor' => $over]);
            mam_audit('fee_online_overpaid', 'fee_invoice', $invId, ['ref' => $ref, 'over_minor' => $over, 'currency' => $cur]);
        }
        mam_fee_online_receipt($payment, $inv, $take, $over);
    } catch (\Throwable $e) {
        mam_log('error', 'fee_online_settle_failed', ['ref' => $ref, 'err' => $e->getMessage()]);
    }
}

/** A receipt to whoever pressed Pay. Best-effort; a mail outage does not undo a payment. */
function mam_fee_online_receipt(array $payment, array $inv, int $taken, int $over): void {
    $payer = mam_db_row('SELECT name, email FROM users WHERE id = ?', [(int) ($payment['fee_payer_id'] ?? 0)]);
    if (!$payer || !filter_var((string) $payer['email'], FILTER_VALIDATE_EMAIL)) return;
    $stu = mam_db_row('SELECT name FROM users WHERE id = ?', [(int) $inv['student_id']]);
    $cur = (string) $inv['currency'];
    $now = mam_db_row('SELECT total_minor, paid_minor FROM fee_invoices WHERE id = ?', [(int) $inv['id']]);
    $left = $now ? max(0, (int) $now['total_minor'] - (int) $now['paid_minor']) : 0;
    $h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
    $first = trim(explode(' ', (string) $payer['name'])[0] ?? '') ?: 'there';
    $appUrl = rtrim((string) mam_env('APP_URL', ''), '/');
    $inner = "<h2 style='font-family:Georgia,serif;margin:0 0 12px;font-size:22px;color:#1A120B'>Payment received</h2>"
        . "<p style='color:#4A3624;font-size:15px'>Hi " . $h($first) . " — thank you. We have received <strong>"
        . $h(mam_fee_display($taken + $over, $cur)) . "</strong> towards " . $h($stu['name'] ?? 'your child') . "'s fees ("
        . $h($inv['public_ref']) . ", " . $h($inv['term_label']) . ").</p>"
        . "<p style='color:#4A3624;font-size:15px'>Reference: <strong>" . $h($payment['reference']) . "</strong>. "
        . ($left > 0 ? 'Still to pay on this invoice: <strong>' . $h(mam_fee_display($left, $cur)) . '</strong>.' : 'This invoice is now paid in full.') . "</p>"
        . ($over > 0 ? "<p style='color:#4A3624;font-size:15px'>" . $h(mam_fee_display($over, $cur))
            . " of this was more than the balance by the time it cleared. The academy office will refund it to you.</p>" : '')
        . ($appUrl !== '' ? mam_email_button($appUrl . '/portal/parent/', 'See your statement') : '');
    $html = mam_email_layout([
        'header' => 'MAM ACADEMY',
        'preheader' => 'Payment received for ' . (string) ($stu['name'] ?? 'your child'),
        'reason' => 'You received this because you paid a fee invoice at MAM Academy.',
        'body' => $inner,
    ]);
    mam_send((string) $payer['email'], 'Payment received: ' . (string) $inv['public_ref'], $html, 'fee_receipt', true);
}
