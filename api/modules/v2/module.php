<?php
/**
 * Module manifest: v2.
 *
 * The schema-v2 ETL and read model. Dormant: only the read model is loaded, and it is off unless V2_READ_DB/V2_READ_DSN is set.
 *
 * `libs` lists this module's files in api/modules/v2/lib/, in load order.
 * `depends` names the modules this one calls. A call to a module not named
 * here fails test_module_boundaries.php, so new coupling is a deliberate
 * edit to this line. See api/modules/README.md.
 */
declare(strict_types=1);

return [
    'name'    => 'v2',
    'depends' => ['kernel'],
    'libs'    => [
        'readmodel',
    ],
];
