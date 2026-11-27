<?php
/**
 * Module manifest: learning.
 *
 * What a student works through and how it is assessed: lessons, exams, adaptive questions, pre-class work, marking, mastery, progress, termly reports, the academic record, accountability, growth and academic emails.
 *
 * `libs` lists this module's files in api/modules/learning/lib/, in load order.
 * `depends` names the modules this one calls. A call to a module not named
 * here fails test_module_boundaries.php, so new coupling is a deliberate
 * edit to this line. See api/modules/README.md.
 */
declare(strict_types=1);

return [
    'name'    => 'learning',
    'depends' => ['academics', 'admin', 'comms', 'community', 'finance', 'identity', 'integrations', 'kernel', 'live', 'safeguarding'],
    'libs'    => [
        'lessons',
        'lessons-learn',
        'lesson-exams',
        'adaptive',
        'preclass',
        'marking',
        'mastery',
        'progress',
        'progress-reports',
        'reports',
        'academic-record',
        'accountability',
        'growth',
        'academic-emails',
    ],
];
