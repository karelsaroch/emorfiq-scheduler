<?php

declare(strict_types=1);

return [
    'mutex' => [
        // database | redis | flock (flock jen pro jeden server)
        'store' => env('SCHEDULER_MUTEX_STORE', 'database'),
        'prefix' => env('SCHEDULER_MUTEX_PREFIX', env('APP_NAME', 'app') . ':'),

        'database' => [
            // Doporučeno samostatné spojení (autocommit, mimo aplikační transakce).
            'connection' => env('SCHEDULER_MUTEX_DB_CONNECTION'),
            'table' => 'scheduler_locks',
        ],
        'redis' => [
            'connection' => env('SCHEDULER_MUTEX_REDIS_CONNECTION', 'default'),
        ],
        'flock' => [
            'directory' => env('SCHEDULER_MUTEX_FLOCK_DIR', sys_get_temp_dir() . '/emorfiq-locks'),
        ],
    ],
];
