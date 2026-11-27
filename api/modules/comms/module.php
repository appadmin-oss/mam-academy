<?php
/**
 * Module manifest: comms.
 *
 * Reaching people: the notification inbox, guardian threads, the support hub and lifecycle automations.
 *
 * `libs` lists this module's files in api/modules/comms/lib/, in load order.
 * `depends` names the modules this one calls. A call to a module not named
 * here fails test_module_boundaries.php, so new coupling is a deliberate
 * edit to this line. See api/modules/README.md.
 */
declare(strict_types=1);

return [
    'name'    => 'comms',
    'depends' => ['academics', 'identity', 'integrations', 'kernel', 'learning', 'safeguarding'],
    'libs'    => [
        'notifications',
        'guardian-comms',
        'support',
        'automations',
    ],
];
