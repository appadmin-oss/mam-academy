<?php
/**
 * Module manifest: safeguarding.
 *
 * Staff vetting, the safeguarding gate, concerns and incidents.
 *
 * `libs` lists this module's files in api/modules/safeguarding/lib/, in load order.
 * `depends` names the modules this one calls. A call to a module not named
 * here fails test_module_boundaries.php, so new coupling is a deliberate
 * edit to this line. See api/modules/README.md.
 */
declare(strict_types=1);

return [
    'name'    => 'safeguarding',
    'depends' => ['comms', 'identity', 'integrations', 'kernel', 'learning'],
    'libs'    => [
        'vetting',
        'safeguarding',
    ],
];
