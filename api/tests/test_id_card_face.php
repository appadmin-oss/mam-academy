<?php
/**
 * ID card face partial — the shared renderer behind /card/{serial} and
 * /card-studio (partials/id-card-face.php).
 *
 * Worth testing because BOTH surfaces render through it: a regression here shows
 * up on the student's card and on a print run of two hundred at the same time.
 * The photo guard is also a security boundary (it decides what reaches an <img
 * src>) and an export boundary (a cross-origin photo taints the html2canvas
 * canvas and silently kills the PDF).
 */
declare(strict_types=1);
t_group('id card face partial');

// Not a lib, so the harness doesn't autoload it — pull it in directly.
require_once dirname(MAM_API_ROOT) . '/partials/id-card-face.php';

/* ── Name splitting: given name(s) small, surname large ──────────────────── */
$two = mam_card_face_fields(['holder_name' => 'Adaeze Okonkwo']);
t_eq($two['given'], 'Adaeze', 'two-part name: given is the first part');
t_eq($two['surname'], 'Okonkwo', 'two-part name: surname is the last part');

$three = mam_card_face_fields(['holder_name' => 'Nehita C. Oriakhi']);
t_eq($three['given'], 'Nehita C.', 'three-part name: middle stays on the given line');
t_eq($three['surname'], 'Oriakhi', 'three-part name: surname is the last part');

// A single word must render LARGE and alone, not small with a blank line under it.
$one = mam_card_face_fields(['holder_name' => 'Prince']);
t_eq($one['surname'], 'Prince', 'single-word name becomes the surname line');
t_eq($one['given'], '', 'single-word name leaves the given line empty');

$messy = mam_card_face_fields(['holder_name' => '  Ada   Lovelace  ']);
t_eq($messy['given'], 'Ada', 'runs of whitespace collapse');
t_eq($messy['surname'], 'Lovelace', 'surrounding whitespace is trimmed');

$empty = mam_card_face_fields(['holder_name' => '']);
t_eq($empty['surname'], '', 'an empty name yields empty parts');
t_eq($empty['initials'], '—', 'an empty name falls back to a dash, not a blank disc');

/* ── Initials for the photoless placeholder ──────────────────────────────── */
t_eq($two['initials'], 'AO', 'initials take the first letter of the first and last parts');
t_eq($three['initials'], 'NO', 'initials skip the middle name');
t_eq($one['initials'], 'P', 'a single-word name yields one initial');

/* ── Photo guard: what may reach an <img src> ────────────────────────────── */
t_eq(mam_card_face_fields(['photo_url' => 'https://cdn.example.com/a.jpg'])['photo'],
    'https://cdn.example.com/a.jpg', 'an https photo is allowed');
t_eq(mam_card_face_fields(['photo_url' => '/uploads/a.jpg'])['photo'],
    '/uploads/a.jpg', 'a same-origin path is allowed');
t_eq(mam_card_face_fields(['photo_url' => 'data:image/png;base64,AAAA'])['photo'],
    'data:image/png;base64,AAAA', 'a data:image URI is allowed');
// http:// is refused: mixed content on an https page, AND it taints the export canvas.
t_eq(mam_card_face_fields(['photo_url' => 'http://insecure.example.com/a.jpg'])['photo'],
    '', 'a plain http photo is refused');
t_eq(mam_card_face_fields(['photo_url' => 'javascript:alert(1)'])['photo'],
    '', 'a javascript: photo URL is refused');
t_eq(mam_card_face_fields(['photo_url' => 'data:text/html,<script>'])['photo'],
    '', 'a non-image data URI is refused');

/* ── Expiry derives from the issue year ──────────────────────────────────── */
t_eq(mam_card_face_fields(['issued_at' => '2026-08-05 10:00:00'])['exp'], 'EXP.12/26', 'expiry is derived from the issue year');
t_eq(mam_card_face_fields(['exp_display' => 'EXP.06/30'])['exp'], 'EXP.06/30', 'an explicit expiry overrides the derived one');
t_eq(mam_card_face_fields([])['exp'], '', 'no issue date yields no expiry');

/* ── Front face markup ───────────────────────────────────────────────────── */
$card = [
    'holder_name' => 'Adaeze Okonkwo',
    'role'        => 'student',
    'display_id'  => 'MAM/026/0001',
    'qr_payload'  => 'MAMC1.ABC123.deadbeef',
    'issued_at'   => '2026-01-02 00:00:00',
    'serial'      => 'ABC1-2DEF-3GHI',
    'status'      => 'active',
];
$html = mam_card_face_html($card, ['qr_id' => 'qr-7']);
t_ok(strpos($html, 'class="mam-card"') !== false, 'front face carries the shared .mam-card class');
t_ok(strpos($html, 'mam-card__white') !== false, 'front face includes the white sweep element');
t_ok(strpos($html, 'id="qr-7"') !== false, 'the QR element id is caller-supplied (unique per card in the studio)');
t_ok(strpos($html, 'data-qr="MAMC1.ABC123.deadbeef"') !== false, 'the QR payload rides on data-qr for the shared painter');
t_ok(strpos($html, 'OKONKWO') !== false, 'the surname is upper-cased onto the face');
t_ok(strpos($html, 'STUDENT') !== false, 'the role is upper-cased onto the face');
t_ok(strpos($html, 'MAM/026/0001') !== false, 'the display id is on the face');
t_ok(strpos($html, 'mam-card--revoked') === false, 'an active card carries no void overlay');
// No photo on this fixture, so the initials placeholder must appear instead.
t_ok(strpos($html, 'mam-card__initials') !== false, 'a photoless card falls back to initials');

$revoked = mam_card_face_html(['holder_name' => 'X Y', 'status' => 'revoked']);
t_ok(strpos($revoked, 'mam-card--revoked') !== false, 'a revoked card gets the void overlay class');

/* ── Escaping: every value is escaped inside the partial ─────────────────── */
$evil = mam_card_face_html([
    'holder_name' => 'Bobby "><script>alert(1)</script>',
    'display_id'  => '<img src=x onerror=alert(2)>',
    'qr_payload'  => '"><script>alert(3)</script>',
]);
t_ok(strpos($evil, '<script>') === false, 'a script tag in the holder name cannot break out');
// The escaped text still CONTAINS the substring "onerror=" — harmlessly, as text.
// What matters is that no element is formed, i.e. no raw `<img` survives.
t_ok(strpos($evil, '<img') === false, 'an injected <img> in the display id is neutralised');
t_ok(strpos($evil, '&lt;img src=x onerror=alert(2)&gt;') !== false, 'it is escaped to text, not dropped');
t_ok(strpos($evil, '&lt;script&gt;') !== false, 'the dangerous markup is escaped, not dropped');
// Attribute break-out: no bare `"` may survive. (The name splitter puts "Bobby"
// on the given line and the rest on the surname line, so they are NOT contiguous
// in the output — assert on the escaped surname itself.)
t_ok(strpos($evil, '&quot;&gt;&lt;script&gt;') !== false, 'the quote that would close an attribute is escaped');
t_ok(strpos($evil, 'data-qr="&quot;&gt;&lt;script&gt;') !== false, 'the QR payload is escaped inside its attribute');

// A hostile logo is refused outright.
$optEvil = mam_card_face_html(['holder_name' => 'A B'], ['logo' => 'javascript:alert(1)', 'width' => '1px" onload="alert(1)']);
t_ok(strpos($optEvil, 'javascript:') === false, 'a javascript: logo URL is refused');
// The width lands in a `style` attribute, where escaping alone is not a defence
// (a permitted string could smuggle another CSS declaration), so it is whitelisted.
t_ok(strpos($optEvil, 'onload') === false, 'a crafted width is dropped entirely, not merely escaped');
t_ok(strpos($optEvil, 'style=') === false, 'a rejected width emits no style attribute at all');

/* ── Back face — follows the designer's comp ─────────────────────────────── */
$back = mam_card_back_html($card, [
    'tel'   => '+234-704-444-6517',
    'email' => 'mailmamcentre@gmail.com',
    'url'   => 'https://academy.mamcentre.org',
]);
t_ok(strpos($back, 'mam-card--back') !== false, 'the back face carries its own modifier class');
t_ok(strpos($back, 'mam-card__back-logo') !== false, 'the back face shows the MAM lockup');
t_ok(strpos($back, 'mam-card__sig') !== false, 'the back face shows the authorised signature');
t_ok(strpos($back, 'Authorised Signature') !== false, 'the signature is captioned');
t_ok(strpos($back, '+234-704-444-6517') !== false, 'the "if found" phone is printed');
t_ok(strpos($back, 'mailmamcentre@gmail.com') !== false, 'the contact email is printed');
t_ok(strpos($back, '<b>https://academy</b>.mamcentre.org') !== false, 'the host is emphasised like the comp');
// The front's QR must NOT be duplicated on the back — two codes invite a mis-scan.
t_ok(strpos($back, 'data-qr') === false, 'the back face carries no second QR code');
t_ok(strpos($back, 'mam-card__barcode') !== false, 'the back face carries a barcode');

// A hostile URL must not inject markup through the host-emphasis split.
$evilUrl = mam_card_back_html(['serial' => 'X'], ['url' => 'https://a<script>alert(1)</script>.b']);
t_ok(strpos($evilUrl, '<script>') === false, 'a script tag in the URL cannot break out');

/* ── Code 128 barcode of the serial ──────────────────────────────────────── */
// Round-trip: decode the emitted pattern with an independent reverse lookup, so
// the encoder is verified against the spec rather than against itself. A barcode
// that encodes the wrong string is worse than no barcode — it scans as a
// DIFFERENT card.
$P128 = ['212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
'221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
'221231','213212','223112','312131','311222','321122','321221','312212','322112','322211',
'212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
'231113','231311','112133','112331','132131','113123','113321','133121','313121','211331',
'231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
'314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
'112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
'111242','121142','121241','114212','124112','124211','411212','421112','421211','212141',
'214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
'114131','311141','411131','211412','211214','211232','233111'];
$rev128 = array_flip($P128);
$decode128 = static function (string $pat) use ($rev128): string {
    if (substr($pat, -7) !== '2331112') return '!stop';
    $body = substr($pat, 0, -7);
    if (strlen($body) % 6) return '!len';
    $vals = [];
    for ($i = 0; $i < strlen($body); $i += 6) {
        $sym = substr($body, $i, 6);
        if (!isset($rev128[$sym])) return '!sym';
        $vals[] = $rev128[$sym];
    }
    if (array_shift($vals) !== 104) return '!startB';
    $check = array_pop($vals);
    $sum = 104;
    foreach ($vals as $k => $v) $sum += $v * ($k + 1);
    if ($sum % 103 !== $check) return '!checksum';
    $s = '';
    foreach ($vals as $v) $s .= chr($v + 32);
    return $s;
};
foreach (['RGZT-4Q96-74YV', 'MAM/026/0229', 'A', '0', 'Hello World 123', '~!@#'] as $payload) {
    t_eq($decode128(mam_card_barcode_128($payload)), $payload, "Code 128 round-trips '$payload'");
}
t_eq(mam_card_barcode_128(''), '', 'an empty payload yields no barcode');
// Outside printable ASCII there is no subset-B encoding; emitting one anyway
// would produce a barcode that scans as something else.
t_eq(mam_card_barcode_128("cafÃ©"), '', 'a non-ASCII payload is refused, not mis-encoded');
t_eq(mam_card_barcode_128("tab	here"), '', 'a control character is refused');
// 11 modules per symbol (start + data + check) plus the 13-module stop.
$pat = mam_card_barcode_128('AB');
t_eq(strlen($pat), 4 * 6 + 7, 'pattern length is 6 digits per symbol plus the stop');
t_eq(array_sum(str_split($pat)), 4 * 11 + 13, 'module count is 11 per symbol plus 13 for the stop');
t_eq(mam_card_barcode_html('', 'x'), '', 'no barcode markup when the payload is unencodable');

/* ── Width whitelist (the value is interpolated into CSS) ────────────────── */
t_eq(mam_card_face_width('300px'), '300px', 'a bare length is allowed');
t_eq(mam_card_face_width('54mm'), '54mm', 'a millimetre length is allowed (print sizing)');
t_eq(mam_card_face_width('min(340px, 86vw)'), 'min(340px, 86vw)', 'min() of lengths is allowed — card.php uses it to stay fluid');
t_eq(mam_card_face_width('clamp(200px, 50vw, 400px)'), 'clamp(200px, 50vw, 400px)', 'clamp() of lengths is allowed');
t_eq(mam_card_face_width('red;background:url(x)'), '', 'a smuggled CSS declaration is rejected');
t_eq(mam_card_face_width('expression(alert(1))'), '', 'a legacy CSS expression is rejected');
t_eq(mam_card_face_width('1px" onload="alert(1)'), '', 'an attribute break-out attempt is rejected');
t_eq(mam_card_face_width('calc(100% - 10px)'), '', 'calc() is not on the whitelist (no caller needs it)');
