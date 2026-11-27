<?php
/**
 * Module manifest: integrations.
 *
 * Outbound services: email, FX rates, WhatsApp (Kapso), Klangio, the AI gateway, Google Drive, the private media store (R2) and first-party uploads.
 *
 * `libs` lists this module's files in api/modules/integrations/lib/, in load order.
 * `depends` names the modules this one calls. A call to a module not named
 * here fails test_module_boundaries.php, so new coupling is a deliberate
 * edit to this line. See api/modules/README.md.
 */
declare(strict_types=1);

return [
    'name'    => 'integrations',
    'depends' => ['academics', 'comms', 'community', 'finance', 'identity', 'kernel', 'live', 'safeguarding'],
    'libs'    => [
        'mailer',
        'fx',
        'kapso',
        'klangio',
        'ai',
        'drive',
        'media-store',
        'uploads',
    ],
];
