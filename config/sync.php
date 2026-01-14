<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Source Database Connection
    |--------------------------------------------------------------------------
    | Connection name untuk database source (MariaDB/MySQL)
    */
    'source_connection' => env('SYNC_SOURCE_CONNECTION', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Target Database Connection
    |--------------------------------------------------------------------------
    | Connection name untuk database target (PostgreSQL)
    */
    'target_connection' => env('SYNC_TARGET_CONNECTION', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | Audit Log Table
    |--------------------------------------------------------------------------
    | Nama table untuk menyimpan audit log perubahan data
    */
    'audit_table' => env('SYNC_AUDIT_TABLE', 'sync_audit_log'),

    /*
    |--------------------------------------------------------------------------
    | Batch Size
    |--------------------------------------------------------------------------
    | Jumlah record yang di-sync per batch
    */
    'batch_size' => env('SYNC_BATCH_SIZE', 100),

    /*
    |--------------------------------------------------------------------------
    | Sync Interval
    |--------------------------------------------------------------------------
    | Interval check untuk perubahan data (dalam detik)
    */
    'sync_interval' => env('SYNC_INTERVAL', 5),

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    */
    'retry' => [
        'max_attempts' => env('SYNC_RETRY_MAX', 3),
        'delay_ms' => env('SYNC_RETRY_DELAY', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cleanup Configuration
    |--------------------------------------------------------------------------
    */
    'cleanup' => [
        'enabled' => env('SYNC_CLEANUP_ENABLED', true),
        'keep_days' => env('SYNC_CLEANUP_KEEP_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Filters
    |--------------------------------------------------------------------------
    | Include/exclude tables dari sync
    */
    'tables' => [
        'include' => env('SYNC_INCLUDE_TABLES', '*'), // '*' = all tables
        'exclude' => array_filter(explode(',', env('SYNC_EXCLUDE_TABLES', 'migrations,failed_jobs,password_resets,personal_access_tokens,sync_audit_log,sync_error_log'))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Tuning
    |--------------------------------------------------------------------------
    */
    'performance' => [
        'use_queue' => env('SYNC_USE_QUEUE', false),  // Disabled queue, sync langsung
        'queue_name' => env('SYNC_QUEUE_NAME', 'database-sync'),
        'memory_limit' => env('SYNC_MEMORY_LIMIT', '256M'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring
    |--------------------------------------------------------------------------
    */
    'monitoring' => [
        'enabled' => env('SYNC_MONITORING_ENABLED', true),
        'log_channel' => env('SYNC_LOG_CHANNEL', 'daily'),
    ],
];
