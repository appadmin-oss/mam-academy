<?php
/**
 * MAM Academy ID Cards — mint, verify, revoke, email issuance.
 *
 * QR payload format: MAMC1.{12-char-serial-no-hyphens}.{16-char-hex-HMAC}
 * Serial display:    XXXX-XXXX-XXXX (uppercase alphanumeric, no ambiguous chars)
 * Charset:           ABCDEFGHJKLMNPQRSTUVWXYZ23456789 (32 chars, no 0/O/1/I)
 *
 * Security model: the QR payload is static but revocation is immediate — a
 * card_id deleted or status='revoked' in the DB stops all attendance marks.
 * Replay of a valid scan is idempotent (returns result='already').
 */
declare(strict_types=1);

const MAM_CARD_CHARSET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

/** Generate a new unique 12-char serial with no ambiguous characters. */
function mam_card_serial_generate(): string {
    $bytes = random_bytes(12);
    $raw   = '';
    for ($i = 0; $i < 12; $i++) {
        $raw .= MAM_CARD_CHARSET[ord($bytes[$i]) % 32];
    }
    return substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4);
}

/**
 * Derive a 16-char hex HMAC signature for a card.
 * Identity is 'u{userId}' for portal users or 's{summerAppId}' for summer-only.
 */
function mam_card_sign(string $serialRaw, ?int $userId, ?int $summerAppId, int $version): string {
    $key      = hash_hmac('sha256', 'mam-idcard-v1', (string)mam_env('APP_KEY', 'dev-key'), true);
    $identity = ($userId !== null) ? 'u' . $userId : 's' . $summerAppId;
    return substr(hash_hmac('sha256', $serialRaw . '|' . $identity . '|' . $version, $key), 0, 16);
}

/**
 * Build the QR payload string for a card row.
 * Returns e.g. MAMC1.K7Q29DXFM3TR.a1b2c3d4e5f6a7b8
 */
function mam_card_qr_payload(array $card): string {
    $serialRaw = str_replace('-', '', strtoupper((string)$card['serial']));
    $sig       = mam_card_sign(
        $serialRaw,
        $card['user_id']      !== null ? (int)$card['user_id']      : null,
        $card['summer_app_id'] !== null ? (int)$card['summer_app_id'] : null,
        (int)$card['version']
    );
    return 'MAMC1.' . $serialRaw . '.' . $sig;
}

/**
 * Verify a raw QR scan string.
 * Returns the card row (status may be 'active' or 'revoked') or null if
 * the payload is malformed or the HMAC does not match (forged/unknown).
 * Callers MUST check card['status'] === 'active' before marking attendance.
 */
function mam_card_verify_payload(string $raw): ?array {
    $raw = trim(strtoupper($raw));
    if (!preg_match('/^MAMC1\.([A-Z2-9]{12})\.([0-9A-F]{16})$/', $raw, $m)) return null;
    $serialRaw = $m[1];
    $givenSig  = strtolower($m[2]);
    $serial    = substr($serialRaw, 0, 4) . '-' . substr($serialRaw, 4, 4) . '-' . substr($serialRaw, 8, 4);
    $card = mam_db_row('SELECT * FROM id_cards WHERE serial = ?', [$serial]);
    if (!$card) return null;
    $expected = mam_card_sign(
        $serialRaw,
        $card['user_id']       !== null ? (int)$card['user_id']       : null,
        $card['summer_app_id'] !== null ? (int)$card['summer_app_id'] : null,
        (int)$card['version']
    );
    if (!hash_equals($expected, $givenSig)) return null;
    return $card;
}

/**
 * Create a new ID card for a user or summer applicant.
 * Returns the card row. If an active card already exists for this identity,
 * it is returned without creating a duplicate.
 */
function mam_card_mint(?int $userId, ?int $summerAppId, string $role, string $sessionCode = '', ?int $createdBy = null): array {
    if ($userId === null && $summerAppId === null) {
        throw new RuntimeException('id_card: user_id or summer_app_id required');
    }

    // Idempotent: return existing active card for this identity.
    if ($userId !== null) {
        $existing = mam_db_row('SELECT * FROM id_cards WHERE user_id = ? AND status = ?', [$userId, 'active']);
        if ($existing) return $existing;
    } elseif ($summerAppId !== null) {
        $existing = mam_db_row('SELECT * FROM id_cards WHERE summer_app_id = ? AND status = ?', [$summerAppId, 'active']);
        if ($existing) return $existing;
    }

    // Resolve photo from user avatar or summer applicant headshot.
    $photoUrl = null;
    if ($userId !== null) {
        $u = mam_db_row('SELECT avatar_url FROM users WHERE id = ?', [$userId]);
        if ($u && !empty($u['avatar_url'])) $photoUrl = (string)$u['avatar_url'];
    }
    if ($photoUrl === null && $summerAppId !== null) {
        $a = mam_db_row('SELECT headshot_url FROM summer_applications WHERE id = ?', [$summerAppId]);
        if ($a && !empty($a['headshot_url'])) $photoUrl = (string)$a['headshot_url'];
    }

    // Generate a unique serial (retry up to 10 times on collision — ~1 in 10^18 chance).
    $serial = null;
    for ($i = 0; $i < 10; $i++) {
        $candidate = mam_card_serial_generate();
        if (!mam_db_row('SELECT id FROM id_cards WHERE serial = ?', [$candidate])) {
            $serial = $candidate;
            break;
        }
    }
    if ($serial === null) throw new RuntimeException('id_card: serial generation failed');

    $now = date('Y-m-d H:i:s');
    mam_db_insert('id_cards', [
        'user_id'       => $userId,
        'summer_app_id' => $summerAppId,
        'role'          => $role,
        'serial'        => $serial,
        'version'       => 1,
        'status'        => 'active',
        'session_code'  => $sessionCode,
        'photo_url'     => $photoUrl,
        'issued_at'     => $now,
        'created_by'    => $createdBy,
        'created_at'    => $now,
    ]);
    $id = (int)mam_db()->lastInsertId();
    $card = mam_db_row('SELECT * FROM id_cards WHERE id = ?', [$id]);
    if (!$card) throw new RuntimeException('id_card: insert failed');
    return $card;
}

/** Get the active card for a portal user, or null if none exists. */
function mam_card_for_user(int $userId): ?array {
    return mam_db_row('SELECT * FROM id_cards WHERE user_id = ? AND status = ? ORDER BY id DESC LIMIT 1', [$userId, 'active']) ?: null;
}

/** Get the active card for a summer application, or null if none exists. */
function mam_card_for_summer_app(int $summerAppId): ?array {
    return mam_db_row('SELECT * FROM id_cards WHERE summer_app_id = ? AND status = ? ORDER BY id DESC LIMIT 1', [$summerAppId, 'active']) ?: null;
}

/** Revoke a card (marks status='revoked', records reason and timestamp). */
function mam_card_revoke(int $cardId, string $reason, ?int $revokedBy = null): void {
    $now = date('Y-m-d H:i:s');
    mam_db_update('id_cards', [
        'status'        => 'revoked',
        'revoked_at'    => $now,
        'revoke_reason' => $reason,
    ], ['id' => $cardId]);
    mam_audit('revoke', 'id_card', $cardId, ['reason' => $reason, 'by' => $revokedBy]);
}

/**
 * Revoke an existing card and mint a replacement for the same identity.
 * Returns the new card row.
 */
function mam_card_reissue(int $oldCardId, ?int $reissuedBy = null): array {
    $old = mam_db_row('SELECT * FROM id_cards WHERE id = ?', [$oldCardId]);
    if (!$old) throw new RuntimeException('id_card: card not found');

    mam_card_revoke($oldCardId, 'reissued', $reissuedBy);

    $newCard = mam_card_mint(
        $old['user_id']       !== null ? (int)$old['user_id']       : null,
        $old['summer_app_id'] !== null ? (int)$old['summer_app_id'] : null,
        (string)$old['role'],
        (string)$old['session_code'],
        $reissuedBy
    );
    mam_audit('reissue', 'id_card', $oldCardId, ['new_card_id' => (int)$newCard['id'], 'by' => $reissuedBy]);
    return $newCard;
}

/** Mark a card as printed (physical card handed to student). */
function mam_card_mark_printed(int $cardId): void {
    mam_db_update('id_cards', ['printed_at' => date('Y-m-d H:i:s')], ['id' => $cardId]);
}

/**
 * Resolve the display name for a card holder.
 * Looks up users or summer_applications depending on what's set.
 */
function mam_card_holder_name(array $card): string {
    if ($card['user_id'] !== null) {
        $u = mam_db_row('SELECT name FROM users WHERE id = ?', [(int)$card['user_id']]);
        if ($u && !empty($u['name'])) return (string)$u['name'];
    }
    if ($card['summer_app_id'] !== null) {
        $a = mam_db_row('SELECT first_name, last_name FROM summer_applications WHERE id = ?', [(int)$card['summer_app_id']]);
        if ($a) return trim((string)$a['first_name'] . ' ' . (string)$a['last_name']);
    }
    return 'Unknown';
}

/**
 * Issue a card for a regular course enrollment activation.
 * Called from mam_lms_transition() when status → 'active'.
 * Returns the card (new or existing) or null if enrollment not found.
 */
function mam_card_mint_for_enrollment(int $enrollmentId): ?array {
    $enr = mam_db_row(
        "SELECT ce.student_id, co.title AS cohort_title, co.id AS cohort_id
           FROM course_enrollments ce
           LEFT JOIN cohorts co ON co.id = ce.cohort_id
          WHERE ce.id = ?",
        [$enrollmentId]
    );
    if (!$enr) return null;
    $userId = (int)$enr['student_id'];
    $sessionCode = (string)($enr['cohort_title'] ?? '');
    try {
        $card = mam_card_mint($userId, null, 'student', $sessionCode);
        mam_after_response(function () use ($card, $userId) {
            $u = mam_db_row('SELECT name, email FROM users WHERE id = ?', [$userId]);
            if ($u && !empty($u['email'])) {
                mam_card_send_email($card, (string)$u['email'], (string)$u['name']);
            }
        });
        return $card;
    } catch (Throwable $e) {
        mam_log('warn', 'id_card_mint_failed', ['enrollment_id' => $enrollmentId, 'err' => $e->getMessage()]);
        return null;
    }
}

/**
 * Issue a card for a summer application when it transitions to 'enrolled'.
 * If the applicant also has a portal account (matching email), the card
 * links to both user_id and summer_app_id.
 */
function mam_card_mint_for_summer_app(int $summerAppId): ?array {
    $app = mam_db_row('SELECT * FROM summer_applications WHERE id = ?', [$summerAppId]);
    if (!$app) return null;

    // Check if a portal account exists with the same email.
    $user   = mam_db_row('SELECT id FROM users WHERE email = ?', [(string)$app['email']]);
    $userId = $user ? (int)$user['id'] : null;

    $year        = !empty($app['session_year']) ? (string)$app['session_year'] : date('Y');
    $sessionCode = 'SUMMER-' . $year;
    $holderName  = trim((string)$app['first_name'] . ' ' . (string)$app['last_name']);
    $email       = (string)$app['email'];

    try {
        $card = mam_card_mint($userId, $summerAppId, 'student', $sessionCode);
        // Link user_id if discovered and card was just minted without it.
        if ($userId !== null && $card['user_id'] === null) {
            mam_db_update('id_cards', ['user_id' => $userId], ['id' => (int)$card['id']]);
            $card['user_id'] = $userId;
        }
        mam_after_response(function () use ($card, $email, $holderName) {
            mam_card_send_email($card, $email, $holderName);
        });
        return $card;
    } catch (Throwable $e) {
        mam_log('warn', 'id_card_mint_summer_failed', ['summer_app_id' => $summerAppId, 'err' => $e->getMessage()]);
        return null;
    }
}

/**
 * Compute the human-readable display ID shown on the card face.
 * Format: MAM/0{yy}/{id:04d}  e.g. MAM/026/0229 for card #229 issued in 2026.
 */
function mam_card_display_id(array $card): string {
    $yy = substr((string)$card['issued_at'], 2, 2); // e.g. "26" from "2026-..."
    $id = str_pad((string)(int)$card['id'], 4, '0', STR_PAD_LEFT);
    return 'MAM/0' . $yy . '/' . $id;
}

/**
 * Generate a GD PNG for the card email attachment.
 * Portrait card matching the branded design: white upper half, orange lower half,
 * circular photo at the boundary. Returns null if GD is unavailable.
 */
function mam_card_generate_png(array $card, string $name): ?string {
    if (!function_exists('imagecreatetruecolor')) return null;
    $w = 540; $h = 720; // 3:4 portrait
    $img = imagecreatetruecolor($w, $h);
    if ($img === false) return null;

    $cWhite  = imagecolorallocate($img, 255, 255, 255);
    $cOrange = imagecolorallocate($img, 240, 123, 26);
    $cDark   = imagecolorallocate($img, 26,  18,  11);
    $cGrey   = imagecolorallocate($img, 200, 190, 178);
    $cMutedO = imagecolorallocate($img, 255, 220, 180); // muted on orange

    // White background
    imagefill($img, 0, 0, $cWhite);

    // Orange lower section with V-notch for photo
    $midX   = (int)($w / 2);
    $topY   = (int)($h * 0.50); // orange top edge (straight)
    $notchY = (int)($h * 0.44); // notch dip (photo center)
    $notchR = (int)($w * 0.18); // half-width of V notch

    $pts = [
        0,                        $topY,
        $midX - $notchR * 2,      $topY,
        $midX - $notchR,          $notchY + (int)($h * 0.02),
        $midX,                    $notchY,
        $midX + $notchR,          $notchY + (int)($h * 0.02),
        $midX + $notchR * 2,      $topY,
        $w,                       $topY,
        $w,                       $h,
        0,                        $h,
    ];
    imagefilledpolygon($img, $pts, $cOrange);

    // MAM ACADEMY text at top
    $mamText = 'MAM ACADEMY';
    $textX = (int)(($w - strlen($mamText) * imagefontwidth(5)) / 2);
    imagestring($img, 5, max(20, $textX), 28, $mamText, $cDark);
    $subText = 'Mystery  \xB7  Art  \xB7  Music';
    imagestring($img, 2, (int)(($w - strlen($subText) * imagefontwidth(2)) / 2), 54, $subText, $cGrey);

    // Photo circle (if available)
    $photoUrl = !empty($card['photo_url']) ? (string)$card['photo_url'] : null;
    $photoCX = $midX;
    $photoCY = $notchY;
    $photoR  = (int)($w * 0.14);
    if ($photoUrl !== null) {
        $data = @file_get_contents($photoUrl);
        if ($data) {
            $src = @imagecreatefromstring($data);
            if ($src) {
                $sw = imagesx($src); $sh = imagesy($src);
                $sz = (int)($photoR * 2);
                $tmp = imagecreatetruecolor($sz, $sz);
                imagecopyresampled($tmp, $src, 0, 0, 0, 0, $sz, $sz, $sw, $sh);
                // Apply circular clip using pixel scan
                $cTrans = imagecolorallocate($tmp, 255, 255, 255);
                for ($py = 0; $py < $sz; $py++) {
                    for ($px = 0; $px < $sz; $px++) {
                        $dx = $px - $photoR; $dy = $py - $photoR;
                        if (($dx * $dx + $dy * $dy) > ($photoR * $photoR)) {
                            imagesetpixel($tmp, $px, $py, $cTrans);
                        }
                    }
                }
                imagecopy($img, $tmp, $photoCX - $photoR, $photoCY - $photoR, 0, 0, $sz, $sz);
                imagedestroy($tmp);
                imagedestroy($src);
            }
        }
    }
    // Photo white ring
    imageellipse($img, $photoCX, $photoCY, $photoR * 2 + 6, $photoR * 2 + 6, $cWhite);
    imageellipse($img, $photoCX, $photoCY, $photoR * 2 + 4, $photoR * 2 + 4, $cWhite);

    // Name in orange area
    $nameParts = explode(' ', $name, 2);
    $first = strtoupper($nameParts[0] ?? $name);
    $last  = strtoupper($nameParts[1] ?? '');
    imagestring($img, 4, 60, (int)($h * 0.55), $first, $cWhite);
    imagestring($img, 5, 60, (int)($h * 0.61), $last,  $cWhite);
    imagestring($img, 3, 60, (int)($h * 0.68), 'S T U D E N T', $cMutedO);

    // Display ID at bottom
    $displayId = mam_card_display_id($card);
    imagestring($img, 3, 60, (int)($h * 0.87), $displayId, $cMutedO);

    ob_start();
    imagepng($img);
    $bytes = ob_get_clean();
    imagedestroy($img);
    return ($bytes !== false && $bytes !== '') ? $bytes : null;
}

/**
 * Generate a minimal PDF for the card email attachment.
 * Uses only Helvetica-Bold (built-in PDF Type1 font, no embedding needed).
 */
function mam_card_generate_pdf(array $card, string $name): string {
    $serial  = (string)$card['serial'];
    $role    = ucfirst((string)$card['role']);
    $year    = substr((string)$card['issued_at'], 0, 4);
    $appUrl  = rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/');
    $cardUrl = $appUrl . '/card/' . $serial;

    $esc = static function (string $s): string {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    };

    // A6 landscape: 420 × 297 pt
    $stream  = "BT\n";
    $stream .= "/F1 22 Tf\n50 247 Td\n(" . $esc('MAM ACADEMY') . ") Tj\n";
    $stream .= "0 -36 Td\n/F1 15 Tf\n(" . $esc($name) . ") Tj\n";
    $stream .= "0 -24 Td\n/F2 11 Tf\n(" . $esc($role) . ") Tj\n";
    $stream .= "0 -32 Td\n/F1 17 Tf\n(" . $esc($serial) . ") Tj\n";
    $stream .= "0 -28 Td\n/F2 9 Tf\n(MAM Academy  " . $esc($year) . ") Tj\n";
    $stream .= "0 -18 Td\n(" . $esc($cardUrl) . ") Tj\n";
    $stream .= "ET\n";
    $streamLen = strlen($stream);

    $objs = [
        1 => "<< /Type /Catalog /Pages 2 0 R >>",
        2 => "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        3 => "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 420 297]"
           . " /Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >>",
        4 => "<< /Length {$streamLen} >>\nstream\n{$stream}\nendstream",
        5 => "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold"
           . " /Encoding /WinAnsiEncoding >>",
        6 => "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica"
           . " /Encoding /WinAnsiEncoding >>",
    ];

    $pdf    = "%PDF-1.4\n";
    $xrefs  = [];
    $n      = count($objs);
    foreach ($objs as $i => $obj) {
        $xrefs[$i] = strlen($pdf);
        $pdf .= "{$i} 0 obj\n{$obj}\nendobj\n";
    }
    $xrefOff = strlen($pdf);
    $pdf .= "xref\n0 " . ($n + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= $n; $i++) {
        $pdf .= str_pad((string)$xrefs[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size " . ($n + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n{$xrefOff}\n%%EOF\n";
    return $pdf;
}

/**
 * Send the ID card issuance email with PDF (always) and PNG (if GD available).
 */
function mam_card_send_email(array $card, string $email, string $name): void {
    if (!function_exists('mam_email_layout')) return; // test harness guard

    $serial  = (string)$card['serial'];
    $appUrl  = rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/');
    $cardUrl = $appUrl . '/card/' . $serial;
    $safeUrl = htmlspecialchars($cardUrl, ENT_QUOTES);
    $safeName = htmlspecialchars($name, ENT_QUOTES);
    $safeSerial = htmlspecialchars($serial, ENT_QUOTES);

    $body = mam_email_layout(
        "Your MAM Academy ID card is ready",
        "<p style='margin:0 0 16px'>Hi {$safeName},</p>"
        . "<p style='margin:0 0 16px'>Your MAM Academy ID card has been issued. "
        . "Your card serial number is:</p>"
        . "<div style='text-align:center;padding:20px 0;font-size:26px;"
        . "letter-spacing:5px;font-family:monospace;font-weight:bold;"
        . "color:#1A120B;background:#F9F6F0;border-radius:8px;margin:0 0 20px'>"
        . $safeSerial . "</div>"
        . "<p style='margin:0 0 16px'>Show your ID card at each session — "
        . "your instructor will scan the QR code to mark your attendance.</p>"
        . mam_email_button($cardUrl, 'View &amp; Print Your Card')
        . "<p style='margin:24px 0 0;font-size:13px;color:#7A6A56'>"
        . "Your card is attached to this email as a PDF and image file. "
        . "You can print it or show it on your phone.</p>"
    );

    $attachments = [];
    $png = mam_card_generate_png($card, $name);
    if ($png !== null) {
        $attachments[] = ['content' => $png, 'name' => 'MAM-ID-Card.png', 'mime' => 'image/png'];
    }
    $attachments[] = [
        'content' => mam_card_generate_pdf($card, $name),
        'name'    => 'MAM-ID-Card.pdf',
        'mime'    => 'application/pdf',
    ];

    mam_send($email, 'Your MAM Academy ID Card — ' . $serial, $body, 'id_card_issue', true, [
        'attachments' => $attachments,
    ]);
    mam_automation_touch('id_card_issue');
}
