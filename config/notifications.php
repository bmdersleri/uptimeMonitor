<?php

declare(strict_types=1);

return [
    'subject_prefix' => '[Uptime]',
    'events' => [
        'incident_opened' => 'DOWN',
        'incident_recovered' => 'RECOVERED',
        'broken_link_summary' => 'BROKEN LINKS',
        'daily_report' => 'DAILY REPORT',
        'weekly_report' => 'WEEKLY REPORT',
    ],
    'broken_links' => [
        'min_occurrence_before_notify' => 2,
        'max_examples' => 8,
    ],
    'retry' => [
        'max_attempts' => 5,
        'base_delay_minutes' => 5,
    ],
];
