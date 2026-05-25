<?php
declare(strict_types=1);

$in = mam_json_input();
$data = [
    'title'         => mam_str($in, 'title', 120),
    'discipline'    => mam_str($in, 'discipline', 32),
    'pathway'       => mam_str($in, 'pathway', 32) ?: null,
    'instructor_id' => isset($in['instructor_id']) ? (int)$in['instructor_id'] : null,
    'mode'          => mam_str($in, 'mode', 16) ?: null,
    'schedule'      => mam_str($in, 'schedule', 200) ?: null,
    'capacity'      => isset($in['capacity']) ? max(1, (int)$in['capacity']) : 12,
    'starts_on'     => mam_str($in, 'starts_on', 20) ?: null,
];
if ($data['title'] === '') mam_fail('validation', 422, ['fields' => ['title']]);

$id = mam_db_insert('classes', $data);
mam_audit('create', 'class', $id, ['title' => $data['title']]);
mam_ok(['class_id' => $id]);
