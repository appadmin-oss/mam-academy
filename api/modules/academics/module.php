<?php
/**
 * Module manifest: academics.
 *
 * The academic structure: sessions and the calendar, courses and cohorts (LMS), the legacy class carry-over, curriculum seed, scheduling, the slot finder, rescheduling and make-ups.
 *
 * `libs` lists this module's files in api/modules/academics/lib/, in load order.
 * `depends` names the modules this one calls. A call to a module not named
 * here fails test_module_boundaries.php, so new coupling is a deliberate
 * edit to this line. See api/modules/README.md.
 */
declare(strict_types=1);

return [
    'name'    => 'academics',
    'depends' => ['comms', 'finance', 'identity', 'integrations', 'kernel', 'learning', 'live', 'safeguarding'],
    'libs'    => [
        'session-time',
        'academic-session',
        'calendar',
        'calendar-feeds',
        'lms',
        'lms2',
        'legacy-carry',
        'curriculum-seed',
        'cohort-admin',
        'scheduling',
        'slot-finder',
        'reschedule',
        'makeup',
    ],
];
