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
    | - none   -> disable webhook auth (for temporary diagnostics only)
    |
    */
    'auth_mode' => env('PDV_AUTH_MODE', 'auto'),
    'hmac_secret' => env('PDV_HMAC_SECRET'),
    'allow_bearer_fallback' => (bool) env('PDV_ALLOW_BEARER_FALLBACK', false),
    'bearer_token' => env('PDV_BEARER_TOKEN'),
    'allow_none_mode_in_production' => (bool) env('PDV_ALLOW_NONE_MODE_IN_PRODUCTION', false),
    'supported_schema_versions' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('PDV_SUPPORTED_SCHEMA_VERSIONS', '2.0,3.0'))
    ))),
    'json_schema_validation_enabled' => (bool) env('PDV_JSON_SCHEMA_VALIDATION_ENABLED', false),
    'json_schema_files' => [
        '2.0' => env('PDV_JSON_SCHEMA_FILE_2_0', base_path('docs/schema_v2.0.json')),
        '3.0' => env('PDV_JSON_SCHEMA_FILE_3_0', base_path('docs/schema_v3.0.json')),
    ],
    'log_channel' => env('PDV_LOG_CHANNEL', 'pdv'),
    'log_payload_on_validation_error' => (bool) env('PDV_LOG_PAYLOAD_ON_VALIDATION_ERROR', true),
    'log_payload_max_chars' => max(512, (int) env('PDV_LOG_PAYLOAD_MAX_CHARS', 6000)),

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
    'cron_queue_consumer_enabled' => (bool) env('PDV_CRON_QUEUE_CONSUMER_ENABLED', false),
    'cron_queue_consumer_max_time' => max(5, (int) env('PDV_CRON_QUEUE_CONSUMER_MAX_TIME', 50)),
    'cron_queue_consumer_sleep' => max(0, (int) env('PDV_CRON_QUEUE_CONSUMER_SLEEP', 1)),
    'cron_queue_consumer_memory' => max(64, (int) env('PDV_CRON_QUEUE_CONSUMER_MEMORY', 256)),
    'queue_consumer_heartbeat_cache_key' => env('PDV_QUEUE_CONSUMER_HEARTBEAT_CACHE_KEY', 'pdv:queue-consumer:heartbeat'),

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

    /*
    |--------------------------------------------------------------------------
    | Monitoring & Alerts (PR-21)
    |--------------------------------------------------------------------------
    */
    'monitor_enabled' => (bool) env('PDV_MONITOR_ENABLED', true),
    'monitor_max_queue_backlog' => max(0, (int) env('PDV_MONITOR_MAX_QUEUE_BACKLOG', 3)),
    'monitor_max_queued_syncs' => max(0, (int) env('PDV_MONITOR_MAX_QUEUED_SYNCS', 5)),
    'monitor_max_failed_jobs' => max(0, (int) env('PDV_MONITOR_MAX_FAILED_JOBS', 0)),
    'monitor_silent_store_threshold_minutes' => max(5, (int) env('PDV_MONITOR_SILENT_STORE_THRESHOLD_MINUTES', 120)),
    'monitor_max_stale_stores' => max(0, (int) env('PDV_MONITOR_MAX_STALE_STORES', 0)),
    'monitor_alert_cooldown_minutes' => max(1, (int) env('PDV_MONITOR_ALERT_COOLDOWN_MINUTES', 30)),
    'monitor_alert_webhook_url' => env('PDV_MONITOR_ALERT_WEBHOOK_URL'),
    'monitor_alert_slack_webhook_url' => env('PDV_MONITOR_ALERT_SLACK_WEBHOOK_URL'),
    'monitor_alert_emails' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('PDV_MONITOR_ALERT_EMAILS', ''))
    ))),
    'monitor_state_cache_key' => env('PDV_MONITOR_STATE_CACHE_KEY', 'pdv:ops-monitor:state'),
];
