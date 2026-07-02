<?php

return [
    'name' => 'Monitoring',
    'notifications' => [
        // De momento las alertas deben mostrarse solo dentro del sistema.
        'external_enabled' => (bool) env('MONITORING_EXTERNAL_NOTIFICATIONS_ENABLED', false),
    ],
    'scan' => [
        'default_batch_size' => max(1, (int) env('MONITORING_SCAN_DEFAULT_BATCH_SIZE', 500)),
    ],
];
