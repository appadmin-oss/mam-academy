<?php
/**
 * Module manifest: admissions.
 *
 * Getting in: student, scholarship and faculty applications, Auditer placement, completing an enrolment, the funnel and referrals.
 *
 * `libs` lists this module's files in api/modules/admissions/lib/, in load order.
 * `depends` names the modules this one calls. A call to a module not named
 * here fails test_module_boundaries.php, so new coupling is a deliberate
 * edit to this line. See api/modules/README.md.
 */
declare(strict_types=1);

return [
    'name'    => 'admissions',
    'depends' => ['academics', 'comms', 'finance', 'identity', 'integrations', 'kernel', 'safeguarding'],
    'libs'    => [
        'admissions',
        'scholarship',
        'faculty',
        'auditer',
        'enrol-complete',
        'decisions',
        'funnel',
        'referrals',
    ],
];
