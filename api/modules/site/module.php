<?php
/**
 * Module manifest: site.
 *
 * The facts the public pages print (contact details, addresses, programme facts).
 *
 * `libs` lists this module's files in api/modules/site/lib/, in load order.
 * `depends` names the modules this one calls. A call to a module not named
 * here fails test_module_boundaries.php, so new coupling is a deliberate
 * edit to this line. See api/modules/README.md.
 */
declare(strict_types=1);

return [
    'name'    => 'site',
    'depends' => ['academics', 'integrations', 'kernel'],
    'libs'    => [
        'site',
    ],
];
