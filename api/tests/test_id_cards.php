<?php
/**
 * Tests for the ID card system: serial generation, HMAC sign/verify,
 * mint/revoke/reissue, and QR payload round-trip.
 */

t_group('ID cards — serial generation');

$serial1 = mam_card_serial_generate();
t_ok(preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $serial1) === 1, 'serial matches XXXX-XXXX-XXXX pattern');
t_ok(!str_contains($serial1, '0'), 'serial has no zero');
t_ok(!str_contains($serial1, 'O'), 'serial has no letter O');
t_ok(!str_contains($serial1, '1'), 'serial has no one');
t_ok(!str_contains($serial1, 'I'), 'serial has no letter I');
$serial2 = mam_card_serial_generate();
t_ok($serial1 !== $serial2, 'two serials are different (collision extremely unlikely)');

t_group('ID cards — HMAC sign and QR payload');

$sigA = mam_card_sign('ABCDEFGHIJKL', 42, null, 1);
t_ok(preg_match('/^[0-9a-f]{16}$/', $sigA) === 1, 'signature is 16 hex chars');
$sigB = mam_card_sign('ABCDEFGHIJKL', 42, null, 1);
t_ok($sigA === $sigB, 'same inputs → same signature (deterministic)');
$sigC = mam_card_sign('ABCDEFGHIJKL', 43, null, 1); // different userId
t_ok($sigA !== $sigC, 'different userId → different signature');
$sigD = mam_card_sign('ABCDEFGHIJKL', 42, null, 2); // different version
t_ok($sigA !== $sigD, 'different version → different signature');
$sigE = mam_card_sign('ABCDEFGHIJKL', null, 7, 1); // summer-only
t_ok($sigA !== $sigE, 'summer identity → different signature than user identity');

t_group('ID cards — mint');

// Seed a test user.
mam_db()->exec("DELETE FROM id_cards");
$testUserId = (int)mam_db()->query("INSERT INTO users (name, email, password_hash, role, created_at) VALUES ('Test Student','card_test@mam.test','x','student','2026-01-01') RETURNING id")->fetchColumn();
if (!$testUserId) {
    mam_db()->exec("INSERT INTO users (name, email, password_hash, role, created_at) VALUES ('Test Student','card_test@mam.test','x','student','2026-01-01')");
    $testUserId = (int)mam_db()->lastInsertId();
}

$card = mam_card_mint($testUserId, null, 'student', 'TEST-2026');
t_ok($card !== null,             'card minted successfully');
t_ok(!empty($card['serial']),    'card has serial');
t_ok($card['status'] === 'active',  'new card is active');
t_ok($card['role']   === 'student', 'role is student');
t_ok($card['session_code'] === 'TEST-2026', 'session_code stored');

// Idempotent: minting again returns the same card.
$card2 = mam_card_mint($testUserId, null, 'student', 'OTHER');
t_ok($card2['id'] === $card['id'], 'mint is idempotent — returns existing active card');

t_group('ID cards — QR payload and verify');

$payload = mam_card_qr_payload($card);
t_ok(str_starts_with($payload, 'MAMC1.'), 'payload starts with MAMC1.');
t_ok(preg_match('/^MAMC1\.[A-Z2-9]{12}\.[0-9a-f]{16}$/', $payload) === 1, 'payload format is valid');

$verified = mam_card_verify_payload($payload);
t_ok($verified !== null,            'valid payload verifies successfully');
t_ok($verified['id'] === $card['id'], 'verified payload returns correct card');

// Tampered signature → null.
$bad = substr($payload, 0, -4) . 'zzzz';
t_ok(mam_card_verify_payload($bad) === null, 'tampered signature → null');

// Unknown serial → null.
$fake = 'MAMC1.ZZZZZZZZZZZZ.0000000000000000';
t_ok(mam_card_verify_payload($fake) === null, 'unknown serial → null');

// Garbage input → null.
t_ok(mam_card_verify_payload('')           === null, 'empty string → null');
t_ok(mam_card_verify_payload('not-a-qr')  === null, 'arbitrary string → null');

// Case-insensitive: lowercase payload still verifies.
$lowerPayload = strtolower($payload);
$verifiedLower = mam_card_verify_payload($lowerPayload);
t_ok($verifiedLower !== null, 'lowercase payload still verifies');

t_group('ID cards — revoke');

mam_card_revoke((int)$card['id'], 'test_revoke');
$revokedCard = mam_db_row('SELECT status, revoke_reason FROM id_cards WHERE id = ?', [(int)$card['id']]);
t_ok($revokedCard['status']       === 'revoked',      'revoked card has status=revoked');
t_ok($revokedCard['revoke_reason'] === 'test_revoke', 'revoke reason stored');

// Revoked card: payload still parses but returns revoked status.
$verifiedRevoked = mam_card_verify_payload($payload);
t_ok($verifiedRevoked !== null,               'revoked card: payload still parses');
t_ok($verifiedRevoked['status'] === 'revoked', 'revoked card: status is revoked');

t_group('ID cards — reissue');

$newCard = mam_card_reissue((int)$card['id']);
t_ok($newCard !== null,                    'reissue returns new card');
t_ok($newCard['id']     !== $card['id'],   'new card has different id');
t_ok($newCard['serial'] !== $card['serial'], 'new card has different serial');
t_ok($newCard['status'] === 'active',      'new card is active');
t_ok((int)$newCard['user_id'] === $testUserId, 'new card linked to same user');

$newPayload = mam_card_qr_payload($newCard);
t_ok(mam_card_verify_payload($newPayload) !== null, 'new card payload verifies');
t_ok(mam_card_verify_payload($payload)   !== null, 'old (revoked) payload still parses');
$oldVerified = mam_card_verify_payload($payload);
t_ok($oldVerified['status'] === 'revoked', 'old payload now returns revoked card');

t_group('ID cards — summer app');

mam_db()->exec("DELETE FROM id_cards WHERE summer_app_id IS NOT NULL");
// Insert a summer application row.
mam_db()->exec("INSERT INTO summer_applications (reference, first_name, last_name, email, dob, policy_agreed, status, created_at)
  VALUES ('SUM-TEST-001','Alice','Smith','alice_summer@mam.test','2010-05-01',1,'enrolled','2026-01-01')");
$sumAppId = (int)mam_db()->lastInsertId();

$sumCard = mam_card_mint(null, $sumAppId, 'student', 'SUMMER-2026');
t_ok($sumCard !== null,                'summer card minted');
t_ok($sumCard['user_id'] === null,     'summer card has no user_id');
t_ok($sumCard['summer_app_id'] !== null, 'summer card has summer_app_id');

$sumPayload = mam_card_qr_payload($sumCard);
$sumVerified = mam_card_verify_payload($sumPayload);
t_ok($sumVerified !== null,            'summer card payload verifies');
t_ok($sumVerified['id'] === $sumCard['id'], 'summer card round-trip');

$holderName = mam_card_holder_name($sumCard);
t_ok($holderName === 'Alice Smith',    'holder name resolved from summer_application');

t_group('ID cards — PDF generation (no external deps)');

$pdf = mam_card_generate_pdf($sumCard, 'Alice Smith');
t_ok(str_starts_with($pdf, '%PDF-'),   'PDF starts with %PDF-');
t_ok(str_contains($pdf, 'endobj'),     'PDF has endobj marker');
t_ok(str_contains($pdf, '%%EOF'),      'PDF ends with %%EOF');
t_ok(str_contains($pdf, 'Alice Smith'), 'PDF contains holder name');
t_ok(str_contains($pdf, (string)$sumCard['serial']), 'PDF contains serial');

t_group('ID cards — recipient resolution');

mam_db()->exec("DELETE FROM id_cards");
$recipUserId = (int)(mam_db()->query("INSERT INTO users (name, email, password_hash, role, created_at) VALUES ('Recip Test','recip_test@mam.test','x','student','2026-01-01') RETURNING id")->fetchColumn() ?: 0);
if (!$recipUserId) { mam_db()->exec("INSERT INTO users (name, email, password_hash, role, created_at) VALUES ('Recip Test','recip_test@mam.test','x','student','2026-01-01')"); $recipUserId = (int)mam_db()->lastInsertId(); }
$recipCard = mam_card_mint($recipUserId, null, 'student', 'RECIP-2026');
$recip = mam_card_recipient($recipCard);
t_ok($recip['email'] === 'recip_test@mam.test', 'recipient email resolved from users');
t_ok($recip['name']  === 'Recip Test',          'recipient name resolved from users');

t_group('ID cards — edit photo');

$photoCard = mam_card_set_photo((int)$recipCard['id'], 'https://cdn.example.com/new-photo.jpg', false);
t_ok($photoCard['photo_url'] === 'https://cdn.example.com/new-photo.jpg', 'photo_url updated on card');
$userAfter = mam_db_row('SELECT avatar_url FROM users WHERE id = ?', [$recipUserId]);
t_ok(empty($userAfter['avatar_url']), 'avatar NOT synced when sync_avatar is false');

$photoCard2 = mam_card_set_photo((int)$recipCard['id'], 'https://cdn.example.com/synced.jpg', true);
$userAfter2 = mam_db_row('SELECT avatar_url FROM users WHERE id = ?', [$recipUserId]);
t_ok($userAfter2['avatar_url'] === 'https://cdn.example.com/synced.jpg', 'avatar synced when sync_avatar is true');

$cleared = mam_card_set_photo((int)$recipCard['id'], '', false);
t_ok($cleared['photo_url'] === null, 'empty url clears the card photo');

$threw = false;
try { mam_card_set_photo((int)$recipCard['id'], 'http://insecure.example.com/x.jpg', false); }
catch (RuntimeException $e) { $threw = ($e->getMessage() === 'invalid_photo_url'); }
t_ok($threw, 'non-https photo URL is rejected');

$threw2 = false;
try { mam_card_set_photo((int)$recipCard['id'], 'not-a-url', false); }
catch (RuntimeException $e) { $threw2 = ($e->getMessage() === 'invalid_photo_url'); }
t_ok($threw2, 'malformed photo URL is rejected');

t_group('ID cards — issue for user (approval delivery path)');

mam_db()->exec("DELETE FROM id_cards");
$issueUserId = (int)(mam_db()->query("INSERT INTO users (name, email, password_hash, role, created_at) VALUES ('Issue Test','issue_test@mam.test','x','student','2026-01-01') RETURNING id")->fetchColumn() ?: 0);
if (!$issueUserId) { mam_db()->exec("INSERT INTO users (name, email, password_hash, role, created_at) VALUES ('Issue Test','issue_test@mam.test','x','student','2026-01-01')"); $issueUserId = (int)mam_db()->lastInsertId(); }
$issued = mam_card_issue_for_user($issueUserId, 'student', 'CONTEMPORARY');
t_ok($issued !== null,                          'issue_for_user returns a card');
t_ok((int)$issued['user_id'] === $issueUserId,  'issued card linked to the user');
t_ok($issued['status'] === 'active',            'issued card is active');
$issuedAgain = mam_card_issue_for_user($issueUserId, 'student', 'OTHER');
t_ok($issuedAgain !== null && $issuedAgain['id'] === $issued['id'], 'issue_for_user is idempotent');

t_group('ID cards — the signing key is the resolver\'s, not a constant in the source');

/* ⚠️ MEASURED. `mam_card_sign()` used to derive its key from
   `mam_env('APP_KEY', 'dev-key')` rather than from `mam_app_key()`, which is
   the resolver every other signer here uses. On an install with no APP_KEY in
   api/.env — a state `mam_app_key()` exists to handle and logs as
   `app_key_autogenerated` before carrying on — that default meant every card
   was signed with the literal string 'dev-key', printed in this open-source
   repository. A card's serial is on its own face and in its Code-128 barcode,
   and `mam_card_verify_payload()` takes user_id and version from the ROW, so a
   forged payload needed nothing but sight of a real card. A signature computed
   offline from the public source alone matched this function exactly.

   The assertion below is the behavioural one: whatever the key resolves to
   here, the output must NOT be the value anybody could compute. */
$IC_public = (static function (): string {
    $k = hash_hmac('sha256', 'mam-idcard-v1', 'dev-key', true);
    return substr(hash_hmac('sha256', 'SIGNTEST01AB|u4242|1', $k), 0, 16);
})();

/* ⚠️ The env has to be CLEARED for this to mean anything. run.php sets a
   deterministic APP_KEY (line 30), so with the old code still in place the
   signature came out private and this assertion passed while the defect was
   present — checked by reintroducing it. Blanking the key reproduces the
   install the bug actually bit: `mam_env('APP_KEY', 'dev-key')` then returns
   the constant, while `mam_app_key()` is already resolved and keeps handing
   back the real key. So this now fails if the read ever goes back. */
/* UNSET, not set to ''. mam_env() is `$GLOBALS['__env'][$key] ?? $default`,
   so an empty string is still a value and the `'dev-key'` default never
   applies — setting it blank tested signing with '' and quietly proved
   nothing. Only removing the key reproduces an unconfigured install. */
$IC_saved = $GLOBALS['__env']['APP_KEY'] ?? null;
unset($GLOBALS['__env']['APP_KEY']);
$IC_sig = mam_card_sign('SIGNTEST01AB', 4242, null, 1);
if ($IC_saved === null) unset($GLOBALS['__env']['APP_KEY']); else $GLOBALS['__env']['APP_KEY'] = $IC_saved;

t_ok($IC_sig !== $IC_public,
    'with no APP_KEY configured, a card signature is still NOT the one anybody could compute from the source');
t_eq(mam_card_sign('SIGNTEST01AB', 4242, null, 1), $IC_sig,
    '…and it does not change with the environment — one resolved key signs every card');

/* And the shape, so the read cannot drift back. Comments are stripped first —
   the note above the fix names the old expression, and a test that cannot tell
   an explanation from an implementation forbids documenting the decision. */
$IC_src  = (string) file_get_contents(dirname(__DIR__) . '/lib/cards.php');
$IC_body = (string) preg_replace('~/\*.*?\*/~s', '', $IC_src);
$IC_body = (string) preg_replace('~^\s*//.*$~m', '', $IC_body);
t_ok(!str_contains($IC_body, "mam_env('APP_KEY'"),
    'cards.php reads no APP_KEY of its own — one resolver decides what signs a card');
t_ok(str_contains($IC_body, 'mam_app_key()'),
    '…it signs with mam_app_key(), which falls back to a PERSISTED random key, never a constant');

/* Sign and verify both route through mam_card_sign(), which is why the fix is
   symmetric — it cannot leave a card half-migrated. */
t_ok(str_contains($IC_body, 'mam_card_sign('),
    'mam_card_verify_payload() recomputes through the same signer it verifies against');

// Cleanup test data.
mam_db()->exec("DELETE FROM id_cards");
mam_db()->exec("DELETE FROM summer_applications WHERE reference = 'SUM-TEST-001'");
mam_db()->exec("DELETE FROM users WHERE email IN ('card_test@mam.test','recip_test@mam.test','issue_test@mam.test')");
