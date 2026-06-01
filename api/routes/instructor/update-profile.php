<?php
/**
 * POST /api/instructor/update-profile
 *
 * Lets an instructor maintain their own public profile + contact details.
 *   { name, phone?, bio?, specialties?, years_experience? }
 * Upserts instructor_profiles and keeps users.name/phone in sync.
 */
declare(strict_types=1);

$u = mam_require_role('instructor', 'admin');

$in    = mam_json_input();
$name  = mam_str($in, 'name', 120);
$phone = mam_str($in, 'phone', 24);
$bio   = mam_str($in, 'bio', 1000);
$spec  = mam_str($in, 'specialties', 300);
$years = isset($in['years_experience']) && $in['years_experience'] !== ''
    ? max(0, min(80, (int)$in['years_experience'])) : null;

if ($name === '') mam_fail('validation', 422, ['fields' => ['name']]);

mam_db_update('users', [
    'name'  => $name,
    'phone' => $phone ?: null,
    'updated_at' => date('Y-m-d H:i:s'),
], ['id' => (int)$u['id']]);

$fields = [
    'bio'              => $bio ?: null,
    'specialties'      => $spec ?: null,
    'years_experience' => $years,
    'updated_at'       => date('Y-m-d H:i:s'),
];
$exists = mam_db_row('SELECT user_id FROM instructor_profiles WHERE user_id = ?', [(int)$u['id']]);
if ($exists) {
    mam_db_update('instructor_profiles', $fields, ['user_id' => (int)$u['id']]);
} else {
    $fields['user_id'] = (int)$u['id'];
    mam_db_insert('instructor_profiles', $fields);
}

mam_audit('update', 'instructor_profile', (int)$u['id'], ['fields' => array_keys($fields)]);
mam_ok(['updated' => true]);
