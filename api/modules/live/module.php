<?php
/**
 * Module manifest: live.
 *
 * Running a class: instructor clock-in, meeting links, live online classes (JaaS), attendance and check-in.
 *
 * `libs` lists this module's files in api/modules/live/lib/, in load order.
 * `depends` names the modules this one calls. A call to a module not named
 * here fails test_module_boundaries.php, so new coupling is a deliberate
 * edit to this line. See api/modules/README.md.
 */
declare(strict_types=1);

return [
    'name'    => 'live',
    'depends' => ['academics', 'comms', 'community', 'identity', 'kernel', 'learning', 'safeguarding'],
    'libs'    => [
        'meetings',
        'meeting-links',
        'live-classes',
        'attendance',
        'checkin',
    ],
];
