<?php
/**
 * MAM Academy — ID card face renderer (shared markup).
 *
 * The SINGLE place card markup is authored. Used by:
 *   • card.php         — the student's own printable card at /card/{serial}
 *   • card-studio.php  — the admin batch studio
 * Paired with assets/css/id-card.css, which owns all the geometry.
 *
 * Kept as pure functions with no DB access so either page can call it with a
 * plain array, and so a caller can render a preview for a card that does not
 * exist yet. Every value is escaped here — callers pass raw strings.
 *
 * Why a partial rather than duplicating the markup: the studio proofs what gets
 * printed. If the studio and the student page drift, an admin signs off on a
 * sheet that isn't what the student receives.
 */
declare(strict_types=1);

if (!function_exists('mam_card_face_fields')) {
    /**
     * Normalise an id_cards row (+ holder name) into the fields the face needs.
     * Accepts the loose shapes both callers already have rather than forcing
     * each to pre-build a DTO.
     *
     * @param array $c  id_cards row plus optional 'holder_name', 'photo_url'
     * @return array{name:string,given:string,surname:string,initials:string,role:string,
     *               display_id:string,exp:string,qr:string,photo:string,revoked:bool}
     */
    function mam_card_face_fields(array $c): array
    {
        $name = trim((string)($c['holder_name'] ?? ''));

        // Split for the two-line treatment: given name(s) small, surname large.
        // A single-word name becomes the surname line, so it renders large and
        // alone rather than small with an empty line beneath it.
        $all     = $name !== '' ? preg_split('/\s+/', $name) : [];
        $parts   = $all;
        $surname = '';
        $given   = '';
        if (count($parts) > 1) {
            $surname = (string)array_pop($parts);
            $given   = implode(' ', $parts);
        } elseif ($parts) {
            $surname = (string)$parts[0];
        }

        // Initials from the first letter of the first and last name parts, so a
        // photoless card still reads as that person rather than as a blank disc.
        $initials = '';
        if ($all) {
            $initials = mb_strtoupper(mb_substr((string)$all[0], 0, 1));
            if (count($all) > 1) $initials .= mb_strtoupper(mb_substr((string)$all[count($all) - 1], 0, 1));
        }

        // The REAL expiry (id_cards.expires_at, stamped at mint). mam_card_exp_display
        // is preferred when cards.php is loaded; the derived fallback keeps this
        // partial usable standalone (the studio and tests both call it directly).
        if (isset($c['exp_display'])) {
            $exp = (string)$c['exp_display'];
        } elseif (function_exists('mam_card_exp_display')) {
            $exp = mam_card_exp_display($c);
        } else {
            $expiresAt = (string)($c['expires_at'] ?? '');
            $issuedYY  = substr((string)($c['issued_at'] ?? ''), 2, 2);
            $exp = $expiresAt !== ''
                ? 'EXP.' . date('m/y', (int)strtotime($expiresAt))
                : ($issuedYY !== '' ? 'EXP.12/' . $issuedYY : '');
        }

        // Expired is as void as revoked: a lapsed card must not read as valid at a
        // door. Computed here so every surface (student page, studio, print) agrees.
        $expired = false;
        if (function_exists('mam_card_is_expired')) {
            $expired = mam_card_is_expired($c);
        } elseif (($c['expires_at'] ?? '') !== '') {
            $expired = strtotime((string)$c['expires_at']) < time();
        }

        // Only same-origin, https, or data: images may reach the <img src>. An
        // external http:// photo would also break the html2canvas export (tainted
        // canvas), so the guard doubles as an export guard.
        $photo = (string)($c['photo_url'] ?? '');
        if ($photo !== ''
            && !str_starts_with($photo, '/')
            && !str_starts_with($photo, 'https://')
            && !str_starts_with($photo, 'data:image/')) {
            $photo = '';
        }

        return [
            'name'       => $name,
            'given'      => $given,
            'surname'    => $surname,
            'initials'   => $initials !== '' ? $initials : '—',
            'role'       => mb_strtoupper((string)($c['role'] ?? 'student')),
            'display_id' => (string)($c['display_id'] ?? ''),
            'exp'        => $exp,
            'qr'         => (string)($c['qr_payload'] ?? ''),
            'photo'      => $photo,
            'revoked'    => (string)($c['status'] ?? '') === 'revoked',
            'expired'    => $expired,
            'void'       => (string)($c['status'] ?? '') === 'revoked' || $expired,
        ];
    }
}

if (!function_exists('mam_card_face_width')) {
    /**
     * Whitelist a CSS width before it lands in a `style` attribute.
     *
     * Escaping already stops an attribute break-out, but this value is
     * interpolated into CSS, where escaping is not a defence: a permitted string
     * could still smuggle another declaration. Only the shapes the two callers
     * actually use are allowed — a bare length, or a min()/max()/clamp() of
     * lengths (card.php passes `min(340px, 86vw)` so the face stays fluid while
     * --mam-card-w remains an absolute length for its calc() type scale).
     * Anything else falls back to the stylesheet default.
     */
    function mam_card_face_width(string $w): string
    {
        $w = trim($w);
        if ($w === '') return '';
        // A single length: 300px / 54mm / 12rem / 80%
        if (preg_match('/^\d+(\.\d+)?(px|mm|cm|in|pt|rem|em|vw|vh|%)$/', $w)) return $w;
        // min()/max()/clamp() over a comma-separated list of such lengths.
        if (preg_match('/^(min|max|clamp)\(\s*[0-9.]+(px|mm|cm|in|pt|rem|em|vw|vh|%)(\s*,\s*[0-9.]+(px|mm|cm|in|pt|rem|em|vw|vh|%)){1,2}\s*\)$/', $w)) return $w;
        return '';
    }
}

if (!function_exists('mam_card_face_html')) {
    /**
     * The FRONT face. `$opts`:
     *   qr_id   — element id the QR renderer targets (must be unique per card
     *             on a page that shows many; the studio passes a per-card id).
     *   logo    — absolute/relative logo URL, or '' for the MAM wordmark block.
     *   raised  — screen shadow. The exporter renders flat so no shadow bakes
     *             into the bleed.
     *   width   — CSS width for --mam-card-w (e.g. '300px').
     */
    function mam_card_face_html(array $c, array $opts = []): string
    {
        $f = mam_card_face_fields($c);
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE);

        $qrId  = (string)($opts['qr_id'] ?? 'qrcode');
        $logo  = (string)($opts['logo'] ?? '');
        $width = mam_card_face_width((string)($opts['width'] ?? ''));
        if ($logo !== '' && !str_starts_with($logo, '/') && !str_starts_with($logo, 'https://')) $logo = '';

        $classes = 'mam-card';
        if (!empty($opts['raised'])) $classes .= ' mam-card--raised';
        if (!empty($f['void']))      $classes .= ' mam-card--revoked';
        if (!empty($f['expired']) && empty($f['revoked'])) $classes .= ' mam-card--expired';
        $style = $width !== '' ? ' style="--mam-card-w:' . $h($width) . '"' : '';

        $out  = '<div class="' . $classes . '"' . $style . ' data-card-face="front">';
        $out .= '<div class="mam-card__white"></div>';

        $out .= '<div class="mam-card__brand">';
        $out .= $logo !== ''
            ? '<img class="mam-card__logo" src="' . $h($logo) . '" alt="MAM Academy">'
            : '<div class="mam-card__logo-fallback" aria-hidden="true">MAM</div>';
        $out .= '<div class="mam-card__brand-name">MAM Academy</div>';
        $out .= '<div class="mam-card__brand-sub">Mystery - Art - Music</div>';
        $out .= '</div>';

        $out .= '<div class="mam-card__photo">';
        $out .= $f['photo'] !== ''
            ? '<img src="' . $h($f['photo']) . '" alt="' . $h($f['name']) . '">'
            : '<div class="mam-card__initials">' . $h($f['initials']) . '</div>';
        $out .= '</div>';

        $out .= '<div class="mam-card__lower">';
        $out .= '<div class="mam-card__given">' . $h(mb_strtoupper($f['given'])) . '</div>';
        $out .= '<div class="mam-card__surname">' . $h(mb_strtoupper($f['surname'])) . '</div>';
        $out .= '<div class="mam-card__role">' . $h($f['role']) . '</div>';
        // The QR box is filled client-side by assets/js/vendor/qrcode.min.js; the
        // payload rides on data-qr so one initialiser can walk every card.
        $out .= '<div class="mam-card__qr"><div id="' . $h($qrId) . '" data-qr="' . $h($f['qr']) . '"></div></div>';
        $out .= '<div class="mam-card__id">' . $h($f['display_id']) . '</div>';
        $out .= '</div>';

        if ($f['exp'] !== '') {
            $out .= '<div class="mam-card__exp" aria-label="Expiry ' . $h($f['exp']) . '">' . $h($f['exp']) . '</div>';
        }

        return $out . '</div>';
    }
}

if (!function_exists('mam_card_barcode_128')) {
    /**
     * Code 128 (subset B) bar pattern for $data.
     *
     * Returns a string of digits: each is a run length, alternating bar, space,
     * bar, space… starting with a bar. That is the form Code 128 is defined in,
     * and it renders as plain flex columns — no font, no canvas, no SVG — so
     * html2canvas rasterises the barcode exactly as the browser paints it. (A
     * barcode FONT would silently fall back and print as letters; an SVG would hit
     * the same html2canvas gap that forced the background plates.)
     *
     * Returns '' for anything outside printable ASCII rather than emitting a
     * barcode that scans as the wrong string.
     */
    function mam_card_barcode_128(string $data): string
    {
        // The 107 Code 128 patterns, indexed by code value.
        static $P = [
            '212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
            '221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
            '221231','213212','223112','312131','311222','321122','321221','312212','322112','322211',
            '212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
            '231113','231311','112133','112331','132131','113123','113321','133121','313121','211331',
            '231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
            '314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
            '112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
            '111242','121142','121241','114212','124112','124211','411212','421112','421211','212141',
            '214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
            '114131','311141','411131','211412','211214','211232','233111',
        ];
        if ($data === '') return '';
        $len = strlen($data);
        $sum = 104;                      // START B
        $out = $P[104];
        for ($i = 0; $i < $len; $i++) {
            $o = ord($data[$i]);
            if ($o < 32 || $o > 126) return '';   // subset B only
            $v = $o - 32;
            $out .= $P[$v];
            $sum += $v * ($i + 1);
        }
        $out .= $P[$sum % 103];          // checksum
        $out .= '2331112';               // STOP (includes the final bar)
        return $out;
    }
}

if (!function_exists('mam_card_barcode_html')) {
    /** Render a Code 128 pattern as flex columns, one element per run, sized
     *  against --mam-card-w so the barcode scales with the card. */
    function mam_card_barcode_html(string $data, string $label = ''): string
    {
        $pat = mam_card_barcode_128($data);
        if ($pat === '') return '';
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE);
        $out = '<div class="mam-card__barcode" role="img" aria-label="Barcode ' . $h($label !== '' ? $label : $data) . '">';
        for ($i = 0, $n = strlen($pat); $i < $n; $i++) {
            $run = (int)$pat[$i];
            $bar = ($i % 2 === 0);       // the pattern alternates bar, space, bar, …
            $w = 'calc(var(--mam-card-w) * ' . sprintf('%.5f', 0.00135 * $run) . ')';
            $out .= '<i class="' . ($bar ? '' : 'sp') . '" style="width:' . $w . '"></i>';
        }
        return $out . '</div>';
    }
}

if (!function_exists('mam_card_back_html')) {
    /**
     * The BACK face, following the designer's comp: yellow textured plate, the MAM
     * lockup, the authorised signature and its caption, the "if found" contact
     * block, and a Code 128 barcode of the card serial.
     *
     * Printing duplex needs a real back; without one the studio can only produce
     * single-sided sheets. No second QR — the front already carries the scannable
     * code and a second one invites a mis-scan. The barcode encodes the SERIAL,
     * not the signed attendance payload, so a desk scanner can look a card up
     * without that scan counting as an attendance mark.
     *
     * `$opts`: logo, signature, tel, email, url, width, raised.
     */
    function mam_card_back_html(array $c, array $opts = []): string
    {
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE);

        $serial = (string)($c['serial'] ?? '');
        $width  = mam_card_face_width((string)($opts['width'] ?? ''));
        $style  = $width !== '' ? ' style="--mam-card-w:' . $h($width) . '"' : '';

        $logo = (string)($opts['logo'] ?? '/assets/img/idcard/logo.png');
        if ($logo !== '' && !str_starts_with($logo, '/') && !str_starts_with($logo, 'https://')) $logo = '';
        $sig = (string)($opts['signature'] ?? '/assets/img/idcard/signature.png');
        if ($sig !== '' && !str_starts_with($sig, '/') && !str_starts_with($sig, 'https://')) $sig = '';

        $tel   = (string)($opts['tel']   ?? '');
        $email = (string)($opts['email'] ?? '');
        $url   = (string)($opts['url']   ?? '');

        $classes = 'mam-card mam-card--back';
        if (!empty($opts['raised'])) $classes .= ' mam-card--raised';

        $out = '<div class="' . $classes . '"' . $style . ' data-card-face="back">';
        $out .= $logo !== ''
            ? '<img class="mam-card__back-logo" src="' . $h($logo) . '" alt="MAM Academy">'
            : '<div class="mam-card__back-logo-fallback" aria-hidden="true">MAM</div>';

        if ($sig !== '') $out .= '<img class="mam-card__sig" src="' . $h($sig) . '" alt="">';
        $out .= '<div class="mam-card__sig-line">Authorised Signature</div>';

        $out .= '<div class="mam-card__found">';
        if ($tel !== '') {
            $out .= '<span class="mam-card__found-lead">If found</span> '
                  . '<span class="mam-card__found-tel">' . $h($tel) . '</span>';
        }
        if ($email !== '') $out .= '<span class="mam-card__found-mail">' . $h($email) . '</span>';
        if ($url !== '') {
            // The host is emphasised the way the comp does it, without letting the
            // caller inject markup: split on the first dot after the scheme.
            $bare = (string)preg_replace('#^https?://#', '', $url);
            $dot  = strpos($bare, '.');
            $out .= '<span class="mam-card__found-url">';
            $out .= $dot !== false
                ? '<b>https://' . $h(substr($bare, 0, $dot)) . '</b>' . $h(substr($bare, $dot))
                : '<b>' . $h($url) . '</b>';
            $out .= '</span>';
        }
        $out .= '</div>';

        if ($serial !== '') $out .= mam_card_barcode_html($serial, 'serial ' . $serial);

        return $out . '</div>';
    }
}
