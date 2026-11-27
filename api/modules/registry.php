<?php
/**
 * The modules, in load order. A module's manifest is api/modules/<name>/module.php.
 *
 * Libraries load in this order, module by module. Order matters only where a
 * file runs code at load time; it does not limit which module may call which
 * (that is each manifest's `depends`, checked by test_module_boundaries.php).
 */
declare(strict_types=1);

return [
    'kernel',
    'integrations',
    'site',
    'identity',
    'safeguarding',
    'finance',
    'academics',
    'admissions',
    'learning',
    'live',
    'community',
    'comms',
    'portals',
    'admin',
    'v2',
];
