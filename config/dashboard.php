<?php

declare(strict_types=1);

return [
    'slow_response_threshold_ms' => 3000,
    'critical_statuses' => ['down', 'degraded'],
    'kpi_window_hours' => 24,
    'table' => [
        'max_rows' => 200,
    ],
    'theme' => [
        'accent' => '#0ea5e9',
        'danger' => '#ef4444',
        'warning' => '#f59e0b',
        'success' => '#10b981',
        'panel' => 'rgba(15, 23, 42, 0.66)',
    ],
];
