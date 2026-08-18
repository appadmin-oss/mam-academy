<?php
/**
 * ETL — transform (v1 rows → v2 rows). Pure functions, no database.
 * ─────────────────────────────────────────────────────────────
 * Every function here takes v1 arrays and returns v2 arrays. Nothing connects,
 * nothing writes. That is what lets the whole mapping be tested against the
 * real snapshot's shapes without a database anywhere near it, and it is why a
 * transform bug is a failing assertion rather than a half-loaded database.
 *
 * Identity resolution lives next door in identity.php; this file assumes it has
 * already run and takes the resolved people as input.
 */
declare(strict_types=1);

require_once __DIR__ . '/identity.php';

/**
 * MAM's base currency. Decided from the data, not from a preference: every
 * payment in v1 is NGN and v59 predates any USD column, so a USD base would
 * force the ETL to invent an FX rate per historical transaction and store it as
 * frozen fact. Under NGN, `currency = base_currency` for every existing row and
 * the money CHECK is satisfied honestly with nothing invented.
 */
const MAM_V2_BASE_CURRENCY = 'NGN';

/**
 * Decimal string → integer minor units, by STRING arithmetic.
 *
 * v1 stores money as a DECIMAL that arrives from the export as the string
 * "7500.00". The obvious `(int) round($v * 100)` is wrong often enough to
 * matter: 8.07 * 100 is 806.9999999999999 in binary floating point, and money
 * that is one kobo short reconciles against nothing. Splitting on the decimal
 * point and padding is exact for every input.
 *
 * `$exponent` comes from the currency, never assumed to be 2 — JPY has none.
 */
function mam_v2_minor(string|int|float|null $amount, int $exponent = 2): ?int
{
    if ($amount === null || $amount === '') return null;
    $s = trim((string) $amount);
    if (!preg_match('/^(-?)(\d+)(?:\.(\d*))?$/', $s, $m)) return null;
    [$whole, $frac] = [$m[2], $m[3] ?? ''];
    if ($exponent === 0) {
        // Anything after the point is not representable; refuse rather than
        // silently truncating somebody's money.
        if (rtrim($frac, '0') !== '') return null;
        return (int) ($m[1] . $whole);
    }
    if (strlen($frac) > $exponent && rtrim(substr($frac, $exponent), '0') !== '') return null;
    $frac = str_pad(substr($frac, 0, $exponent), $exponent, '0', STR_PAD_RIGHT);
    return (int) ($m[1] . $whole . $frac);
}

/**
 * v1 payment status → v2.
 *
 * `pending_bank_transfer` maps to its own v2 state rather than collapsing into
 * `pending`. It is 11 of the 15 rows in the real data and it is the only one
 * that needs a human: a card payment pending the gateway needs nobody.
 */
function mam_v2_map_payment_status(?string $v1): string
{
    return [
        'pending_bank_transfer' => 'awaiting_transfer',
        'pending'               => 'pending',
        'initialized'           => 'pending',
        'succeeded'             => 'succeeded',
        'success'               => 'succeeded',
        'paid'                  => 'succeeded',
        'failed'                => 'failed',
        'cancelled'             => 'failed',
        'refunded'              => 'refunded',
        'disputed'              => 'disputed',
    ][strtolower(trim((string) $v1))] ?? 'pending';
}

/**
 * v1 application status → v2.
 *
 * `enrolled` is deliberately NOT a v2 application status. In v2 the application
 * ends at `accepted` and the enrolment row carries what happened next; keeping
 * "enrolled" on the application would put the same fact in two places that can
 * then disagree — which is the v1 pattern this rebuild exists to end.
 */
function mam_v2_map_application_status(?string $v1): string
{
    return [
        'draft'      => 'draft',
        'submitted'  => 'submitted',
        'reviewing'  => 'under_review',
        'in_review'  => 'under_review',
        'waitlisted' => 'waitlisted',
        'accepted'   => 'accepted',
        'enrolled'   => 'accepted',
        'paid'       => 'accepted',
        'rejected'   => 'rejected',
        'declined'   => 'rejected',
        'cancelled'  => 'withdrawn',
        'withdrawn'  => 'withdrawn',
    ][strtolower(trim((string) $v1))] ?? 'submitted';
}

/** An index from v1 customer/user id to the resolved person's array offset. */
function mam_v2_person_index(array $people): array
{
    $ix = ['customers' => [], 'users' => []];
    foreach ($people as $i => $p) {
        if ($p['customer_id'] !== null) $ix['customers'][(int) $p['customer_id']] = $i;
        if ($p['user_id'] !== null)     $ix['users'][(int) $p['user_id']]         = $i;
    }
    return $ix;
}

/**
 * v1 payments → v2 payments.
 *
 * Returns ['rows' => [...], 'report' => [...], 'rejected' => [...]]. A row that
 * cannot be transformed is REJECTED with a reason rather than being written
 * with a guessed value — a payment whose amount could not be parsed exactly is
 * the last thing that should be quietly approximated.
 */
function mam_v2_transform_payments(array $payments, array $people, int $tenantId = 1): array
{
    $ix = mam_v2_person_index($people);
    $rows = $rejected = [];
    $report = ['in' => count($payments), 'out' => 0, 'by_status' => [], 'unowned' => 0,
               'total_minor' => 0, 'currency' => MAM_V2_BASE_CURRENCY];

    foreach ($payments as $p) {
        $currency = strtoupper(trim((string) ($p['currency'] ?? MAM_V2_BASE_CURRENCY)));
        $minor    = mam_v2_minor($p['amount'] ?? null);
        if ($minor === null) {
            $rejected[] = ['id' => $p['id'] ?? null, 'why' => 'amount not exactly representable: '
                . var_export($p['amount'] ?? null, true)];
            continue;
        }

        // Under an NGN base every v1 payment is same-currency, so the base
        // figure is the amount itself and there is no rate to freeze. The
        // moment a non-NGN payment appears this must carry a real observed
        // rate — so it is rejected rather than defaulted, which would write a
        // 1:1 conversion and call it fact.
        if ($currency !== MAM_V2_BASE_CURRENCY) {
            $rejected[] = ['id' => $p['id'] ?? null,
                'why' => "currency $currency is not the base and no historical rate exists"];
            continue;
        }

        $status = mam_v2_map_payment_status($p['status'] ?? null);
        $owner  = null;
        // v1 payments hang off an application, which hangs off a customer.
        // Resolved here rather than at load time so an unowned payment is
        // visible in the report instead of becoming a NULL somebody notices later.
        if (!empty($p['_customer_id']) && isset($ix['customers'][(int) $p['_customer_id']])) {
            $owner = $ix['customers'][(int) $p['_customer_id']];
        } else {
            $report['unowned']++;
        }

        $rows[] = [
            'tenant_id'         => $tenantId,
            'person_ix'         => $owner,
            'provider'          => (string) ($p['provider'] ?? 'manual'),
            'provider_ref'      => $p['provider_reference'] ?? null,
            'status'            => $status,
            'currency'          => $currency,
            'amount_minor'      => $minor,
            'base_currency'     => MAM_V2_BASE_CURRENCY,
            'base_amount_minor' => $minor,   // same currency: not a conversion
            'fx_rate'           => null,     // nothing to freeze, so nothing invented
            'fx_source'         => null,
            'received_at'       => $p['verified_at'] ?? null,
            // v1's OWN reference, kept apart from the gateway's. This is the
            // string on the customer's receipt and in the confirmation email —
            // the one they quote on the phone — and it was being dropped: the
            // map carried `provider_reference`, which is NULL on all 15 real
            // payments because a pending bank transfer never reached a gateway.
            // A reference map that cannot resolve the reference people actually
            // hold is not doing the job it exists for.
            'legacy_ref'        => $p['reference'] ?? null,
            'failure_reason'    => $status === 'failed' ? 'Recorded as failed in the previous system' : null,
            'legacy'            => ['payments', (string) ($p['id'] ?? '')],
        ];
        $report['out']++;
        $report['by_status'][$status] = ($report['by_status'][$status] ?? 0) + 1;
        $report['total_minor'] += $minor;
    }
    return ['rows' => $rows, 'report' => $report, 'rejected' => $rejected];
}

/**
 * v1 `applications.source` → v2 `source_channel`.
 *
 * The two are not the same field. v1 stored the full LANDING URL —
 * `https://academy.mamcentre.org/apply?pathway=certified-musician` — while
 * `source_channel VARCHAR(48)` means an attribution channel: `website`,
 * `referral`, `instagram`. Copying one into the other was a MySQL truncation
 * error (SQLite does not enforce widths, so it only surfaced on a real engine)
 * and, worse, it would have made attribution meaningless: 23 applications would
 * share one "channel" and two more would differ only by query string.
 *
 * So the channel is DERIVED — the host for a URL, the value itself for a short
 * token — and the full URL is preserved in the payload as `source_url`, because
 * the landing page is real information and truncating it away is a loss nobody
 * would notice until they tried to attribute a term's intake.
 */
function mam_v2_source_channel(?string $v1): ?string
{
    $s = trim((string) $v1);
    if ($s === '') return null;
    if (preg_match('#^https?://#i', $s)) {
        $host = parse_url($s, PHP_URL_HOST);
        return $host ? substr((string) $host, 0, 48) : 'web';
    }
    // A short token is already a channel. Longer free text is not, and a
    // truncated one would be a channel nobody chose — so it becomes 'other'
    // and the original is kept in the payload.
    return strlen($s) <= 48 ? $s : 'other';
}

/** v1 applications → v2 applications. */
function mam_v2_transform_applications(array $apps, array $people, int $tenantId = 1): array
{
    $ix = mam_v2_person_index($people);
    $rows = $rejected = [];
    $report = ['in' => count($apps), 'out' => 0, 'by_status' => [], 'unowned' => 0, 'erased_owner' => 0];

    foreach ($apps as $a) {
        $cid = isset($a['customer_id']) ? (int) $a['customer_id'] : 0;
        if (!$cid || !isset($ix['customers'][$cid])) {
            // An application with no resolvable applicant cannot be loaded:
            // membership_id and the funnel both depend on it.
            $rejected[] = ['id' => $a['id'] ?? null, 'why' => 'no resolvable applicant'];
            $report['unowned']++;
            continue;
        }
        $i = $ix['customers'][$cid];
        // An erased person keeps their applications — the counts must reconcile
        // and the funnel history is the academy's own record — but the payload
        // is dropped, because it holds the free-text answers that identify them.
        $erased = !empty($people[$i]['erased']);
        if ($erased) $report['erased_owner']++;

        $status = mam_v2_map_application_status($a['status'] ?? null);
        $rows[] = [
            'tenant_id'        => $tenantId,
            'person_ix'        => $i,
            'type'             => 'programme',
            'status'           => $status,
            'reference'        => $a['reference'] ?? null,
            'submitted_at'     => $a['created_at'] ?? null,
            'decision_reason'  => $status === 'rejected'
                ? (string) ($a['decision_reason'] ?? 'Reason not recorded in the previous system')
                : null,
            'readiness_signal' => $a['readiness_signal'] ?? null,
            'source_channel'   => mam_v2_source_channel($a['source'] ?? null),
            'payload'          => $erased ? null : (array_intersect_key((array) $a, array_flip(
                ['pathway', 'mode', 'program', 'discipline', 'session', 'experience', 'goals', 'level']))
                // The landing URL the channel was derived from, kept whole.
                + (trim((string) ($a['source'] ?? '')) !== '' ? ['source_url' => (string) $a['source']] : [])),
            'legacy'           => ['applications', (string) ($a['id'] ?? '')],
        ];
        $report['out']++;
        $report['by_status'][$status] = ($report['by_status'][$status] ?? 0) + 1;
    }
    return ['rows' => $rows, 'report' => $report, 'rejected' => $rejected];
}

/**
 * Every transformed row carries where it came from, so `legacy_reference_map`
 * can be written and an old reference stays resolvable after cutover — the
 * references live in emails, receipts and people's memories.
 */
function mam_v2_legacy_rows(array $transformed, int $tenantId = 1): array
{
    $out = [];
    foreach ($transformed as $r) {
        if (empty($r['legacy'])) continue;
        [$table, $id] = $r['legacy'];
        $out[] = ['tenant_id' => $tenantId, 'legacy_table' => $table, 'legacy_id' => $id,
                  'legacy_ref' => $r['reference'] ?? null];
    }
    return $out;
}
