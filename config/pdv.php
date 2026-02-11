<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Webhook Authentication
    |--------------------------------------------------------------------------
    |
    | auth_mode:
    | - hmac   -> require X-PDV-Timestamp + X-PDV-Signature
    | - bearer -> require Authorization: Bearer <token>
    | - auto   -> prefer HMAC, fallback to bearer only when enabled
    |
    */
    'auth_mode' => env('PDV_AUTH_MODE', 'auto'),
    'hmac_secret' => env('PDV_HMAC_SECRET'),
    'allow_bearer_fallback' => (bool) env('PDV_ALLOW_BEARER_FALLBACK', false),
    'bearer_token' => env('PDV_BEARER_TOKEN'),
    'supported_schema_versions' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('PDV_SUPPORTED_SCHEMA_VERSIONS', '2.0'))
    ))),
    'json_schema_validation_enabled' => (bool) env('PDV_JSON_SCHEMA_VALIDATION_ENABLED', false),
    'json_schema_files' => [
        '2.0' => env('PDV_JSON_SCHEMA_FILE_2_0', base_path('docs/schema_v2.0.json')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Timestamp Policy
    |--------------------------------------------------------------------------
    |
    | strict   -> reject new syncs outside tolerance window
    | tolerant -> accept new syncs outside window and flag risk
    |
    */
    'timestamp_mode' => env('PDV_TIMESTAMP_MODE', 'tolerant'),
    'timestamp_tolerance_seconds' => (int) env('PDV_TIMESTAMP_TOLERANCE_SECONDS', 600),
    'naive_datetime_timezone' => env('PDV_NAIVE_DATETIME_TIMEZONE', 'America/Sao_Paulo'),
    'block_on_alias_mismatch' => (bool) env('PDV_BLOCK_ON_ALIAS_MISMATCH', false),

    /*
    |--------------------------------------------------------------------------
    | Ingestion Throughput
    |--------------------------------------------------------------------------
    */
    'rate_limit_per_minute' => (int) env('PDV_RATE_LIMIT_PER_MINUTE', 180),
    'queue_name' => env('PDV_QUEUE_NAME', 'pdv'),
    'store_lock_seconds' => (int) env('PDV_STORE_LOCK_SECONDS', 30),
    'worker_timeout_seconds' => (int) env('PDV_WORKER_TIMEOUT_SECONDS', 180),
    'queue_stale_threshold_minutes' => (int) env('PDV_QUEUE_STALE_THRESHOLD_MINUTES', 20),
    'job_tries' => max(1, (int) env('PDV_JOB_TRIES', 5)),
    'job_backoff_seconds' => array_values(array_filter(array_map(
        static fn (string $value): int => (int) trim($value),
        explode(',', (string) env('PDV_JOB_BACKOFF_SECONDS', '10,30,60,120'))
    ), static fn (int $value): bool => $value >= 0)),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    */
    'raw_retention_days' => (int) env('PDV_RAW_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Operational automation
    |--------------------------------------------------------------------------
    */
    'retry_failed_enabled' => (bool) env('PDV_RETRY_FAILED_ENABLED', false),
    'retry_failed_limit' => (int) env('PDV_RETRY_FAILED_LIMIT', 200),
    'retry_failed_max_attempts' => (int) env('PDV_RETRY_FAILED_MAX_ATTEMPTS', 8),
    'retry_failed_older_than_minutes' => (int) env('PDV_RETRY_FAILED_OLDER_THAN_MINUTES', 15),
];
