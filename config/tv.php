<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | TV Digital Signage — Health Monitoring & Heartbeat Thresholds
    |--------------------------------------------------------------------------
    */
    'heartbeat_interval_seconds' => (int) env('TV_HEARTBEAT_INTERVAL_SECONDS', 30),

    // Healthy online window (up to 3 missed 30s pings)
    'online_threshold_seconds' => (int) env('TV_ONLINE_THRESHOLD_SECONDS', 90),

    // Grace window before declaring TV offline (6 missed 30s pings = 3 minutes)
    'offline_threshold_seconds' => (int) env('TV_OFFLINE_THRESHOLD_SECONDS', 180),

    // Window & count to classify an online TV as degraded due to playback errors
    'degraded_errors_window_minutes' => (int) env('TV_DEGRADED_ERRORS_WINDOW_MINUTES', 15),
    'degraded_errors_threshold' => (int) env('TV_DEGRADED_ERRORS_THRESHOLD', 3),

    // Number of TVs going offline within 2 minutes that suggests a central server/network incident
    'concurrent_offline_incident_threshold' => (int) env('TV_CONCURRENT_OFFLINE_INCIDENT_THRESHOLD', 3),

    // Default timezone for human-friendly local timestamps
    'timezone' => env('TV_TIMEZONE', 'America/Sao_Paulo'),

    /*
    |--------------------------------------------------------------------------
    | Public Status & History API Limits
    |--------------------------------------------------------------------------
    */
    'status_rate_limit_per_minute' => (int) env('TV_STATUS_RATE_LIMIT_PER_MINUTE', 120),
    'runtime_rate_limit_per_minute' => (int) env('TV_RUNTIME_RATE_LIMIT_PER_MINUTE', 180),
    'history_default_limit' => 20,
    'history_max_limit' => 100,

    /*
    |--------------------------------------------------------------------------
    | Outbound Push Webhooks
    |--------------------------------------------------------------------------
    */
    'default_webhook_url' => env('TV_WEBHOOK_URL'),
    'default_webhook_events' => ['tv.went_offline', 'tv.came_online'],
    'default_webhook_only_during_business_hours' => filter_var(
        env('TV_WEBHOOK_ONLY_BUSINESS_HOURS', false),
        FILTER_VALIDATE_BOOLEAN
    ),
    'webhook_timeout_seconds' => (int) env('TV_WEBHOOK_TIMEOUT_SECONDS', 8),
    'webhook_max_retries' => (int) env('TV_WEBHOOK_MAX_RETRIES', 3),
    'webhook_default_cooldown_seconds' => (int) env('TV_WEBHOOK_COOLDOWN_SECONDS', 60),
    'webhook_allow_private_targets' => filter_var(
        env('TV_WEBHOOK_ALLOW_PRIVATE_TARGETS', false),
        FILTER_VALIDATE_BOOLEAN
    ),

    /*
    |--------------------------------------------------------------------------
    | Supported Outbound Event Catalog
    |--------------------------------------------------------------------------
    */
    'supported_events' => [
        'tv.went_offline' => 'TV ficou offline',
        'tv.came_online' => 'TV voltou online',
        'tv.became_degraded' => 'TV entrou em estado instável/degradado',
        'tv.recovered_healthy' => 'TV recuperou estabilidade',
        'tv.standby_enabled' => 'TV colocada em Standby (Blackout)',
        'tv.standby_disabled' => 'TV religada (saiu de Standby)',
        'tv.player_error_spike' => 'Pico de erros de reprodução de mídia',
        'tv.device_changed' => 'Alteração de dispositivo ou resolução detectada',
        'tv.playlist_updated' => 'Grade de anúncios ou design atualizado',
        'tv.webhook_test' => 'Evento de teste do webhook',
    ],
];
