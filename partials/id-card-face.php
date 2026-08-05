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

        $issuedYY = substr((string)($c['issued_at'] ?? ''), 2, 2);
        $exp = (string)($c['exp_display'] ?? ($issuedYY !== '' ? 'EXP.12/' . $issuedYY : ''));

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
        if ($f['revoked'])           $classes .= ' mam-card--revoked';
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

if (!function_exists('mam_card_back_html')) {
    /**
     * The BACK face — carrier for the terms, the serial and a signature strip.
     * Printing duplex needs a real back; without one the studio can only produce
     * single-sided sheets. Deliberately text-only: no photo, no QR (the front
     * already carries the scannable code, and a second QR invites a mis-scan).
     */
    function mam_card_back_html(array $c, array $opts = []): string
    {
        $f = mam_card_face_fields($c);
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE);

        $serial  = (string)($c['serial'] ?? '');
        $session = (string)($c['session_code'] ?? '');
        $support = (string)($opts['support_email'] ?? '');
        $width   = mam_card_face_width((string)($opts['width'] ?? ''));
        $style   = $width !== '' ? ' style="--mam-card-w:' . $h($width) . '"' : '';

        $classes = 'mam-card mam-card--back';
        if (!empty($opts['raised'])) $classes .= ' mam-card--raised';

        $out  = '<div class="' . $classes . '"' . $style . ' data-card-face="back">';
        $out .= '<div class="mam-card__back-brand">MAM Academy</div>';
        $out .= '<div class="mam-card__back-rule"></div>';
        $out .= '<ul class="mam-card__back-terms">';
        $out .= '<li>This card identifies the holder as a registered member of MAM Academy and remains its property.</li>';
        $out .= '<li>Carry it to every session — the QR code on the front records your attendance.</li>';
        $out .= '<li>Not transferable. Report a lost or damaged card so it can be voided and reissued.</li>';
        $out .= '</ul>';
        $out .= '<div class="mam-card__back-foot">';
        $out .= '<div class="mam-card__back-meta">';
        if ($f['display_id'] !== '') $out .= '<div><b>' . $h($f['display_id']) . '</b></div>';
        if ($serial !== '')          $out .= '<div>Serial ' . $h($serial) . '</div>';
        if ($session !== '')         $out .= '<div>' . $h($session) . '</div>';
        if ($support !== '')         $out .= '<div>' . $h($support) . '</div>';
        $out .= '</div>';
        $out .= '<div class="mam-card__back-sign">Signature</div>';
        $out .= '</div>';

        return $out . '</div>';
    }
}
