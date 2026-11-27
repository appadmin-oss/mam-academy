<?php
/**
 * Module manifest: portals.
 *
 * Read providers for the student and instructor portal screens.
 *
 * `libs` lists this module's files in api/modules/portals/lib/, in load order.
 * `depends` names the modules this one calls. A call to a module not named
 * here fails test_module_boundaries.php, so new coupling is a deliberate
 * edit to this line. See api/modules/README.md.
 */
declare(strict_types=1);

return [
    'name'    => 'portals',
    'depends' => ['academics', 'community', 'finance', 'identity', 'kernel', 'learning', 'live', 'safeguarding'],
    'libs'    => [
        'instructor-portal',
        'student-portal',
    ],
];
