<?php
/**
 * Module manifest: community.
 *
 * Class spaces, the Recital Hall, repertoire, practice challenges, achievements, the studio leaderboard and practice logs.
 *
 * `libs` lists this module's files in api/modules/community/lib/, in load order.
 * `depends` names the modules this one calls. A call to a module not named
 * here fails test_module_boundaries.php, so new coupling is a deliberate
 * edit to this line. See api/modules/README.md.
 */
declare(strict_types=1);

return [
    'name'    => 'community',
    'depends' => ['academics', 'comms', 'identity', 'integrations', 'kernel', 'learning', 'live', 'safeguarding'],
    'libs'    => [
        'spaces',
        'recital',
        'repertoire',
        'challenges',
        'achievements',
        'studio',
        'practice',
    ],
];
