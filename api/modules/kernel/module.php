<?php
/**
 * Module manifest: kernel.
 *
 * Request plumbing every other module stands on: logging, JSON responses, validation, the database and its migrator, settings, sessions, CSRF, rate limits, idempotency, the audit log, the job queue, inline edit and backup checks.
 *
 * `libs` lists this module's files in api/modules/kernel/lib/, in load order.
 * `depends` names the modules this one calls. A call to a module not named
 * here fails test_module_boundaries.php, so new coupling is a deliberate
 * edit to this line. See api/modules/README.md.
 */
declare(strict_types=1);

return [
    'name'    => 'kernel',
    'depends' => ['academics', 'admissions', 'finance', 'identity', 'integrations', 'learning'],
    'libs'    => [
        'log',
        'response',
        'validation',
        'db',
        'settings',
        'session',
        'csrf',
        'rate-limit',
        'idempotency',
        'audit',
        'jobs',
        'inline-edit',
        'backup',
    ],
];
