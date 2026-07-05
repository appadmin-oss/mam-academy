<?php
/**
 * MAM Scholars Initiative (§2c) — capture + validation + status. The flow only
 * records an application for a human committee; it never decides or gates.
 */
declare(strict_types=1);
t_group('scholarship application');

function _schol_valid_input(array $over = []): array {
    return array_merge([
        'full_name'        => 'Ada Obi',
        'email'            => 'Ada.Schol@Example.com',
        'age_band'         => '16-20',
        'experience'       => 'some',
        'financial_need'   => 'My family cannot cover tuition this year.',
        'musical_journey'  => 'I have sung in my church choir for three years.',
        'values_alignment' => 'I served my community by teaching kids to sing.',
        'community_impact' => 'I will start a free neighbourhood music circle.',
        'commitment_ack'   => true,
    ], $over);
}

// ── Validation gates on the essentials, but is never a content pass/fail ──
$v = mam_scholarship_validate(_schol_valid_input());
t_ok(empty($v['errors']), 'a complete submission has no validation errors');
t_eq($v['data']['email'], 'ada.schol@example.com', 'email is captured + normalised to lowercase');

$v = mam_scholarship_validate(_schol_valid_input(['commitment_ack' => false]));
t_ok(in_array('commitment_ack', $v['errors'], true), 'the explicit commitment checkbox is required');

$v = mam_scholarship_validate(_schol_valid_input(['email' => 'not-an-email']));
t_ok(in_array('email', $v['errors'], true), 'a bad email is rejected');

$v = mam_scholarship_validate(_schol_valid_input(['financial_need' => 'no']));
t_ok(in_array('financial_need', $v['errors'], true), 'an empty/too-short essay section is flagged');

// Optional selects are allow-listed (garbage → null, not an error).
$v = mam_scholarship_validate(_schol_valid_input(['age_band' => 'garbage', 'experience' => 'garbage']));
t_ok(empty($v['errors']) && $v['data']['age_band'] === null && $v['data']['experience'] === null, 'unknown age/experience tokens drop to null, not an error');

// ── Readiness signal is advisory + reuses the shared derivation ──
$v = mam_scholarship_validate(_schol_valid_input(['experience' => 'none']));
t_eq($v['data']['readiness_signal'], 'foundation', 'a complete beginner → foundation (advisory)');
$v = mam_scholarship_validate(_schol_valid_input(['age_band' => '27-plus']));
t_eq($v['data']['readiness_signal'], 'review', 'age outside 16–26 → review (advisory, never a rejection)');

// ── Create persists + audits, and status lookup is email-gated ──
$v = mam_scholarship_validate(_schol_valid_input());
$res = mam_scholarship_create($v['data'], '203.0.113.9');
t_ok(strpos($res['reference'], 'SCH') === 0, 'a scholarship reference is minted (SCH-…)');
t_eq($res['status'], 'submitted', 'a new application starts submitted');
$row = mam_db_row('SELECT email, commitment_ack, status FROM scholarship_applications WHERE reference = ?', [$res['reference']]);
t_eq($row['email'], 'ada.schol@example.com', 'email is stored normalised (lowercased)');
t_eq((int)$row['commitment_ack'], 1, 'the commitment acknowledgement is persisted');

$st = mam_scholarship_status($res['reference'], 'ada.schol@example.com');
t_ok($st && $st['status'] === 'submitted', 'status lookup works with the matching email');
t_ok(mam_scholarship_status($res['reference'], 'someone.else@x.dev') === null, 'a mismatched email reveals nothing');
t_ok(mam_scholarship_status('SCH-NONE', 'ada.schol@example.com') === null, 'an unknown reference reveals nothing');

// A soft link to a real application is stored only when it exists.
$v = mam_scholarship_validate(_schol_valid_input(['application_reference' => 'MAM-DOES-NOT-EXIST']));
$res2 = mam_scholarship_create($v['data']);
t_ok(mam_db_row('SELECT application_reference FROM scholarship_applications WHERE reference = ?', [$res2['reference']])['application_reference'] === null, 'a non-existent application_reference is dropped (soft link)');
