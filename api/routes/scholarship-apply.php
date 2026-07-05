<?php
/**
 * POST /api/scholarship/apply
 *
 * MAM Scholars Initiative — capture an opt-in scholarship application (essay
 * assessment + commitment). Public, CSRF-protected, rate-limited, honeypot-
 * guarded. Decides nothing: it stores the submission for a human committee.
 */
declare(strict_types=1);

$in = mam_json_input();
mam_honeypot($in);

$v = mam_scholarship_validate($in);
if (!empty($v['errors'])) mam_fail('validation', 422, ['fields' => $v['errors']]);

$res = mam_scholarship_create($v['data'], mam_client_ip());

mam_ok([
    'submitted' => true,
    'reference' => $res['reference'],
    'status'    => $res['status'],
]);
