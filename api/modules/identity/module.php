<?php
/**
 * Module manifest: identity.
 *
 * People and access: authentication, member records, guardians, consent, data-subject rights, the NDPA compliance layer and ID cards.
 *
 * `libs` lists this module's files in api/modules/identity/lib/, in load order.
 * `depends` names the modules this one calls. A call to a module not named
 * here fails test_module_boundaries.php, so new coupling is a deliberate
 * edit to this line. See api/modules/README.md.
 */
declare(strict_types=1);

return [
    'name'    => 'identity',
    'depends' => ['academics', 'comms', 'community', 'integrations', 'kernel', 'learning', 'live'],
    'libs'    => [
        'auth',
        'members',
        'parents',
        'consent',
        'datarights',
        'dataprotection',
        'cards',
    ],
];
