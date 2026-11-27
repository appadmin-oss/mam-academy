<?php
/**
 * Module manifest: admin.
 *
 * The admin console read model, its phone subset, upper-admin intelligence, BI and analytics.
 *
 * `libs` lists this module's files in api/modules/admin/lib/, in load order.
 * `depends` names the modules this one calls. A call to a module not named
 * here fails test_module_boundaries.php, so new coupling is a deliberate
 * edit to this line. See api/modules/README.md.
 */
declare(strict_types=1);

return [
    'name'    => 'admin',
    'depends' => ['academics', 'admissions', 'comms', 'finance', 'identity', 'integrations', 'kernel', 'learning', 'live', 'safeguarding'],
    'libs'    => [
        'console',
        'console-phone',
        'upper-admin',
        'bi',
        'analytics',
        'analytics2',
    ],
];
