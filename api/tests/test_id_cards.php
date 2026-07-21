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

// Cleanup test data.
mam_db()->exec("DELETE FROM id_cards");
mam_db()->exec("DELETE FROM summer_applications WHERE reference = 'SUM-TEST-001'");
mam_db()->exec("DELETE FROM users WHERE email = 'card_test@mam.test'");
