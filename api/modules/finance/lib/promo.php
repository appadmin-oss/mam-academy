<?php
/**
 * Email promotional banner helpers.
 *
 * mam_email_active_banner() returns a ready-to-insert <tr>…</tr> HTML snippet
 * for the currently live banner (if any), or an empty string. The snippet is
 * designed to drop directly into the mam_email_layout() inner table.
 *
 * Selection rules:
 *   - active = 1
 *   - starts_at IS NULL or starts_at <= today
 *   - ends_at   IS NULL or ends_at   >= today
 *   - ties broken by starts_at DESC, then id DESC (most recently started wins)
 */
declare(strict_types=1);

/**
 * The single live promo row (active + within its date window), or null.
 * Shared by the email banner, the public banner, and the discount engine.
 * Defensive if the table is missing. (Not statically cached: it's a single
 * indexed lookup, and caching would break correctness if a promo is created
 * or expires within a process — and test isolation.)
 */
function mam_promo_active(): ?array {
    $today = date('Y-m-d');
    try {
        return mam_db_row(
            "SELECT * FROM promo_banners
              WHERE active = 1
                AND (starts_at IS NULL OR starts_at <= ?)
                AND (ends_at   IS NULL OR ends_at   >= ?)
              ORDER BY starts_at DESC, id DESC LIMIT 1",
            [$today, $today]
        ) ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** The live promo IF it's opted into the public site (public = 1), else null. */
function mam_promo_active_public(): ?array {
    $p = mam_promo_active();
    return ($p && (int)($p['public'] ?? 0) === 1) ? $p : null;
}

/**
 * The discount percent (0–90) a live PUBLIC promo grants for a given context.
 * $context is 'tuition' or 'store'; applies_to 'all' (or empty) matches both.
 * Returns 0 when there's no live public promo, no discount, or it doesn't apply
 * to this context — so callers can multiply unconditionally.
 */
function mam_promo_discount_percent(string $context): int {
    $p = mam_promo_active_public();
    if (!$p) return 0;
    $pct = (int)($p['discount_percent'] ?? 0);
    if ($pct <= 0) return 0;
    $applies = trim((string)($p['applies_to'] ?? 'all'));
    if ($applies === '' || $applies === 'all' || $applies === $context) {
        return max(0, min(90, $pct));
    }
    return 0;
}

/**
 * Public-safe JSON payload for the live public promo (for GET /api/promo/active
 * and the site banner), or null. Never leaks internal-only columns.
 */
function mam_promo_public_payload(): ?array {
    $p = mam_promo_active_public();
    if (!$p) return null;
    $ctaUrl = trim((string)($p['cta_url'] ?? ''));
    if ($ctaUrl !== '' && !preg_match('#^https?://|^/#i', $ctaUrl)) $ctaUrl = '';
    return [
        'title'            => (string)($p['title'] ?? ''),
        'body'             => (string)($p['body'] ?? ''),
        'discount_percent' => (int)($p['discount_percent'] ?? 0),
        'applies_to'       => trim((string)($p['applies_to'] ?? 'all')) ?: 'all',
        'cta_label'        => (string)($p['cta_label'] ?? ''),
        'cta_url'          => $ctaUrl,
        'bg_color'         => preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($p['bg_color'] ?? '')) ? (string)$p['bg_color'] : '#FEF3C7',
        // Countdown target: end of the promo's last valid day (local server date).
        'ends_at'          => ($p['ends_at'] ?? null) ? ((string)$p['ends_at'] . 'T23:59:59') : null,
    ];
}

function mam_email_active_banner(): string {
    $today = date('Y-m-d');
    $sql = "SELECT * FROM promo_banners
            WHERE active = 1
              AND (starts_at IS NULL OR starts_at <= ?)
              AND (ends_at   IS NULL OR ends_at   >= ?)
            ORDER BY starts_at DESC, id DESC
            LIMIT 1";
    try {
        $row = mam_db_row($sql, [$today, $today]);
    } catch (Throwable $e) {
        // Table may not exist yet on a freshly deployed instance; fail silently.
        return '';
    }
    if (!$row) return '';

    $bg = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($row['bg_color'] ?? ''))
        ? (string)$row['bg_color'] : '#FEF3C7';
    $body     = htmlspecialchars((string)($row['body']      ?? ''), ENT_QUOTES);
    $ctaLabel = trim((string)($row['cta_label'] ?? ''));
    $ctaUrl   = trim((string)($row['cta_url']   ?? ''));

    $ctaHtml = '';
    if ($ctaLabel !== '' && $ctaUrl !== '') {
        $scheme  = strtolower(substr($ctaUrl, 0, 8));
        $safeUrl = ($scheme === 'https://' || substr($scheme, 0, 7) === 'http://')
            ? htmlspecialchars($ctaUrl, ENT_QUOTES) : '#';
        $ctaHtml =
            '<p style="margin:10px 0 0">'
          . '<a href="' . $safeUrl . '" '
          .   'style="display:inline-block;padding:8px 18px;background:#C17B2A;color:#141413;'
          .          'border-radius:6px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\','
          .          'Helvetica,Arial,sans-serif;font-size:13px;font-weight:600;text-decoration:none">'
          . htmlspecialchars($ctaLabel, ENT_QUOTES) . ' &rarr;'
          . '</a></p>';
    }

    return '<tr><td style="padding:20px 44px 0 44px">'
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
         .   'style="background:' . $bg . ';border-radius:8px;overflow:hidden">'
         . '<tr><td style="padding:14px 18px;border-left:4px solid #C17B2A">'
         .   '<p style="margin:0 0 5px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\','
         .      'Helvetica,Arial,sans-serif;font-size:10px;font-weight:800;letter-spacing:.15em;'
         .      'text-transform:uppercase;color:#C17B2A">From MAM Academy</p>'
         .   '<p style="margin:0;font-family:Georgia,\'Times New Roman\',serif;font-size:14px;'
         .      'color:#141413;line-height:1.55">' . $body . '</p>'
         . $ctaHtml
         . '</td></tr>'
         . '</table>'
         . '</td></tr>';
}
