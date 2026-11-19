<?php

declare(strict_types=1);

namespace App\Services\Tv;

use App\Models\Store;
use App\Models\TvAd;
use App\Models\TvAdImpression;
use App\Models\TvLog;
use App\Models\TvScreen;
use App\Models\TvScreenSession;
use App\Models\TvScreenStatusEvent;
use App\Services\OpeningHoursService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TvHealthService
{
    public function __construct(
        private OpeningHoursService $openingHoursService,
        private TvWebhookDispatcher $webhookDispatcher
    ) {
    }

    /**
     * Resolve client IP safely behind reverse proxies (Cloudflare / Nginx / Plesk).
     */
    public function resolveClientIp(Request $request): string
    {
        $cfIp = trim((string) $request->header('CF-Connecting-IP', ''));
        if ($cfIp !== '' && filter_var($cfIp, FILTER_VALIDATE_IP)) {
            return $cfIp;
        }

        $forwardedFor = trim((string) $request->header('X-Forwarded-For', ''));
        if ($forwardedFor !== '') {
            $first = trim(explode(',', $forwardedFor)[0] ?? '');
            if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }

        $realIp = trim((string) $request->header('X-Real-IP', ''));
        if ($realIp !== '' && filter_var($realIp, FILTER_VALIDATE_IP)) {
            return $realIp;
        }

        return (string) ($request->ip() ?: '0.0.0.0');
    }

    /**
     * Compute a compact deterministic version hash for the screen's active playlist & design.
     */
    public function computePlaylistVersion(?int $storeId, ?TvScreen $screen): string
    {
        $globalDesignUpdated = DB::table('tv_global_settings')
            ->where('key', 'default_design_template')
            ->value('updated_at');

        $ads = TvAd::query()
            ->currentlyActive()
            ->forStore($storeId)
            ->orderBy('order_priority', 'asc')
            ->orderBy('id', 'desc')
            ->get(['id', 'media_url', 'media_type', 'duration_seconds', 'target_orientation', 'order_priority', 'status', 'days_of_week', 'updated_at'])
            ->filter(fn (TvAd $ad) => $ad->isApplicableToday())
            ->map(fn (TvAd $ad) => [
                'id' => $ad->id,
                'url' => $ad->media_url,
                'dur' => $ad->duration_seconds,
                'prio' => $ad->order_priority,
                'ori' => $ad->target_orientation,
                'upd' => $ad->updated_at?->timestamp,
            ])
            ->values()
            ->all();

        $fingerprint = [
            'blackout' => (bool) ($screen?->is_blackout ?? false),
            'orientation' => $screen?->orientation ?? 'auto',
            'store_design' => $screen?->design_template ?? null,
            'global_design_updated' => $globalDesignUpdated ? (string) $globalDesignUpdated : null,
            'ads' => $ads,
        ];

        return substr(sha1((string) json_encode($fingerprint)), 0, 16);
    }

    /**
     * Process an incoming TV heartbeat atomically (lockForUpdate, single active session, transition detection).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function recordPing(TvScreen $screen, array $input, Request $request): array
    {
        $isPreview = filter_var($input['preview'] ?? $request->query('preview', false), FILTER_VALIDATE_BOOLEAN);
        $playlistVersion = $this->computePlaylistVersion($screen->store_id, $screen);

        if ($isPreview) {
            return [
                'ignored_preview' => true,
                'status' => 'preview',
                'health_status' => $screen->health_status ?: 'never_connected',
                'is_blackout' => (bool) $screen->is_blackout,
                'playlist_version' => $playlistVersion,
                'recommended_ping_seconds' => (int) config('tv.heartbeat_interval_seconds', 30),
                'server_time' => now()->toISOString(),
            ];
        }

        $now = now();
        $ip = $this->resolveClientIp($request);
        $userAgent = (string) ($request->userAgent() ?? '');
        $parsedUa = $this->parseUserAgent($userAgent);
        $offlineThreshold = (int) config('tv.offline_threshold_seconds', 180);
        $degradedWindowMin = (int) config('tv.degraded_errors_window_minutes', 15);
        $degradedErrorThreshold = (int) config('tv.degraded_errors_threshold', 3);

        $pendingWebhooks = [];

        $resultState = DB::transaction(function () use (
            $screen,
            $input,
            $now,
            $ip,
            $userAgent,
            $parsedUa,
            $playlistVersion,
            $offlineThreshold,
            $degradedWindowMin,
            $degradedErrorThreshold,
            &$pendingWebhooks
        ): array {
            /** @var TvScreen $locked */
            $locked = TvScreen::query()
                ->with('store')
                ->whereKey($screen->id)
                ->lockForUpdate()
                ->firstOrFail();

            $previousStatus = $this->inferEffectiveStatus($locked, $now);
            $previousOfflineSince = $locked->offline_since ?? $locked->last_ping_at;
            $previousDeviceInfo = is_array($locked->device_info) ? $locked->device_info : [];
            $previousDeviceId = $locked->last_device_id ?: ($previousDeviceInfo['device_id'] ?? null);
            $previousViewportW = isset($previousDeviceInfo['viewport_width'])
                ? (int) $previousDeviceInfo['viewport_width']
                : (isset($previousDeviceInfo['screen_width']) ? (int) $previousDeviceInfo['screen_width'] : null);
            $previousViewportH = isset($previousDeviceInfo['viewport_height'])
                ? (int) $previousDeviceInfo['viewport_height']
                : (isset($previousDeviceInfo['screen_height']) ? (int) $previousDeviceInfo['screen_height'] : null);

            $viewportW = isset($input['width']) && is_numeric($input['width']) ? (int) $input['width'] : $previousViewportW;
            $viewportH = isset($input['height']) && is_numeric($input['height']) ? (int) $input['height'] : $previousViewportH;
            $dpr = isset($input['dpr']) && is_numeric($input['dpr']) && (float) $input['dpr'] > 0
                ? round((float) $input['dpr'], 2)
                : (isset($previousDeviceInfo['device_pixel_ratio']) ? (float) $previousDeviceInfo['device_pixel_ratio'] : null);

            $displayScreenW = isset($input['screen_width']) && is_numeric($input['screen_width'])
                ? (int) $input['screen_width']
                : ($previousDeviceInfo['display_screen_width'] ?? null);
            $displayScreenH = isset($input['screen_height']) && is_numeric($input['screen_height'])
                ? (int) $input['screen_height']
                : ($previousDeviceInfo['display_screen_height'] ?? null);

            $physicalW = ($viewportW && $dpr) ? (int) round($viewportW * $dpr) : ($displayScreenW ?: $viewportW);
            $physicalH = ($viewportH && $dpr) ? (int) round($viewportH * $dpr) : ($displayScreenH ?: $viewportH);
            $orientationDetected = ($viewportW && $viewportH)
                ? ($viewportH > $viewportW ? 'portrait' : 'landscape')
                : ($previousDeviceInfo['orientation_detected'] ?? null);
            $aspectRatio = $this->computeAspectRatio($viewportW, $viewportH);

            $launchMode = isset($input['launch_mode']) && is_string($input['launch_mode']) && $input['launch_mode'] !== ''
                ? Str::limit($input['launch_mode'], 40, '')
                : ($parsedUa['is_webview'] ? 'android_webview' : ($previousDeviceInfo['launch_mode'] ?? 'browser_tab'));

            $deviceId = isset($input['device_id']) && is_string($input['device_id']) && trim($input['device_id']) !== ''
                ? Str::limit(trim($input['device_id']), 64, '')
                : $previousDeviceId;

            $offlineCached = filter_var($input['offline_cached'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $networkOnline = array_key_exists('network_online', $input)
                ? filter_var($input['network_online'], FILTER_VALIDATE_BOOLEAN)
                : true;

            $recentErrorsCount = TvLog::query()
                ->where('screen_slug', $locked->slug)
                ->where('event', 'tv_player_error')
                ->where('created_at', '>=', $now->copy()->subMinutes($degradedWindowMin))
                ->count();

            $newHealthStatus = 'online';
            if ($locked->is_blackout) {
                $newHealthStatus = 'standby';
            } elseif ($offlineCached || !$networkOnline || $recentErrorsCount >= $degradedErrorThreshold) {
                $newHealthStatus = 'degraded';
            }

            $deviceInfo = array_merge($previousDeviceInfo, [
                'user_agent' => $userAgent,
                'ip' => $ip,
                // Keep legacy keys for backward compatibility
                'screen_width' => $viewportW,
                'screen_height' => $viewportH,
                // Enriched display telemetry
                'viewport_width' => $viewportW,
                'viewport_height' => $viewportH,
                'display_screen_width' => $displayScreenW,
                'display_screen_height' => $displayScreenH,
                'device_pixel_ratio' => $dpr,
                'physical_width' => $physicalW,
                'physical_height' => $physicalH,
                'orientation_detected' => $orientationDetected,
                'orientation_angle' => isset($input['orientation_angle']) && is_numeric($input['orientation_angle']) ? (int) $input['orientation_angle'] : ($previousDeviceInfo['orientation_angle'] ?? null),
                'orientation_type' => isset($input['orientation_type']) ? Str::limit((string) $input['orientation_type'], 32, '') : ($previousDeviceInfo['orientation_type'] ?? null),
                'aspect_ratio' => $aspectRatio,
                // Client & launch telemetry
                'launch_mode' => $launchMode,
                'launch_path' => isset($input['launch_path']) ? Str::limit((string) $input['launch_path'], 180, '') : ($previousDeviceInfo['launch_path'] ?? '/tv/' . $locked->slug),
                'referrer' => array_key_exists('referrer', $input) ? ($input['referrer'] ? Str::limit((string) $input['referrer'], 180, '') : null) : ($previousDeviceInfo['referrer'] ?? null),
                'is_webview' => $parsedUa['is_webview'],
                'is_pwa' => $launchMode === 'pwa_standalone',
                'device_id' => $deviceId,
                'device_label' => $parsedUa['device_label'],
                'os' => $parsedUa['os'],
                'browser' => $parsedUa['browser'],
                'app_version' => isset($input['app_version']) ? Str::limit((string) $input['app_version'], 40, '') : ($previousDeviceInfo['app_version'] ?? null),
                'player_state' => isset($input['player_state']) ? Str::limit((string) $input['player_state'], 32, '') : ($locked->is_blackout ? 'standby' : 'playing'),
                'visibility_state' => isset($input['visibility_state']) ? Str::limit((string) $input['visibility_state'], 24, '') : ($previousDeviceInfo['visibility_state'] ?? 'visible'),
                'network_online' => $networkOnline,
                'offline_cached' => $offlineCached,
                'playlist_count' => isset($input['playlist_count']) && is_numeric($input['playlist_count']) ? (int) $input['playlist_count'] : ($previousDeviceInfo['playlist_count'] ?? null),
                'pinged_at' => $now->toISOString(),
            ]);

            $cameOnlineFromOffline = in_array($previousStatus, ['offline', 'never_connected'], true)
                && in_array($newHealthStatus, ['online', 'degraded'], true);

            $statusChanged = $previousStatus !== $newHealthStatus;

            $updateData = [
                'is_online' => true,
                'health_status' => $newHealthStatus,
                'current_ad_title' => $input['current_ad_title'] ?? $locked->current_ad_title,
                'current_ad_id' => isset($input['current_ad_id']) && is_numeric($input['current_ad_id'])
                    ? (int) $input['current_ad_id']
                    : null,
                'fps' => isset($input['fps']) && is_numeric($input['fps']) ? (int) $input['fps'] : $locked->fps,
                'uptime_seconds' => isset($input['uptime_seconds']) && is_numeric($input['uptime_seconds'])
                    ? max(0, (int) $input['uptime_seconds'])
                    : (int) $locked->uptime_seconds,
                'last_ping_at' => $now,
                'last_device_id' => $deviceId,
                'playlist_version' => isset($input['playlist_version']) && is_string($input['playlist_version'])
                    ? Str::limit($input['playlist_version'], 64, '')
                    : $playlistVersion,
                'device_info' => $deviceInfo,
            ];

            if ($statusChanged) {
                $updateData['status_changed_at'] = $now;
            }
            if ($cameOnlineFromOffline || $locked->online_since === null) {
                if (!$locked->is_blackout) {
                    $updateData['online_since'] = $now;
                    $updateData['offline_since'] = null;
                }
            }

            $locked->update($updateData);

            // Atomic single-active-session management (eliminates zombie duplicates!)
            if (!$locked->is_blackout) {
                $openSessions = TvScreenSession::query()
                    ->where('screen_id', $locked->id)
                    ->whereNull('ended_at')
                    ->orderByDesc('id')
                    ->get();

                $newestSession = $openSessions->first();

                // Close any extra duplicate zombie open sessions at their own last_ping_at
                foreach ($openSessions->slice(1) as $zombie) {
                    $zombie->close('timeout_disconnected', $zombie->last_ping_at ?? $zombie->started_at);
                }

                if ($newestSession) {
                    if ($newestSession->last_ping_at && $newestSession->last_ping_at->lt($now->copy()->subSeconds($offlineThreshold))) {
                        $newestSession->close('timeout_disconnected', $newestSession->last_ping_at);
                        TvScreenSession::create([
                            'store_id' => $locked->store_id,
                            'screen_id' => $locked->id,
                            'screen_slug' => $locked->slug,
                            'started_at' => $now,
                            'last_ping_at' => $now,
                            'duration_minutes' => 0,
                            'device_info' => [
                                'user_agent' => $userAgent,
                                'ip' => $ip,
                                'device_id' => $deviceId,
                                'viewport' => ($viewportW && $viewportH) ? "{$viewportW}x{$viewportH}" : null,
                                'launch_mode' => $launchMode,
                            ],
                        ]);
                    } else {
                        $duration = $newestSession->started_at
                            ? max(0, (int) $newestSession->started_at->diffInMinutes($now))
                            : 0;
                        $newestSession->update([
                            'last_ping_at' => $now,
                            'duration_minutes' => $duration,
                        ]);
                    }
                } else {
                    TvScreenSession::create([
                        'store_id' => $locked->store_id,
                        'screen_id' => $locked->id,
                        'screen_slug' => $locked->slug,
                        'started_at' => $now,
                        'last_ping_at' => $now,
                        'duration_minutes' => 0,
                        'device_info' => [
                            'user_agent' => $userAgent,
                            'ip' => $ip,
                            'device_id' => $deviceId,
                            'viewport' => ($viewportW && $viewportH) ? "{$viewportW}x{$viewportH}" : null,
                            'launch_mode' => $launchMode,
                        ],
                    ]);
                }
            }

            $storeHours = $this->calculateStoreHours($locked->store);
            $duringBusinessHours = (bool) ($storeHours['is_open_now'] ?? false);
            $storeName = $locked->store?->name ?? $locked->name;

            // Emit deterministic transition events & queue webhooks
            if ($cameOnlineFromOffline) {
                $downtimeSeconds = ($previousStatus === 'offline' && $previousOfflineSince)
                    ? max(0, (int) $previousOfflineSince->diffInSeconds($now))
                    : null;
                $reason = $previousStatus === 'never_connected' ? 'first_boot' : 'reconnected';

                TvScreenStatusEvent::create([
                    'screen_id' => $locked->id,
                    'store_id' => $locked->store_id,
                    'screen_slug' => $locked->slug,
                    'event_type' => 'tv.came_online',
                    'previous_status' => $previousStatus,
                    'new_status' => $newHealthStatus,
                    'reason' => $reason,
                    'duration_previous_seconds' => $downtimeSeconds,
                    'during_business_hours' => $duringBusinessHours,
                    'metadata' => [
                        'viewport' => ($viewportW && $viewportH) ? "{$viewportW}x{$viewportH}" : null,
                        'physical' => ($physicalW && $physicalH) ? "{$physicalW}x{$physicalH}" : null,
                        'launch_mode' => $launchMode,
                        'device_id' => $deviceId,
                        'device_label' => $parsedUa['device_label'],
                    ],
                    'occurred_at' => $now,
                    'created_at' => $now,
                ]);

                $downtimeSuffix = $downtimeSeconds !== null
                    ? ' (ficou offline por ' . $this->formatDurationHuman($downtimeSeconds) . ')'
                    : '';

                TvLog::log(
                    'info',
                    'tv_turned_on',
                    "TV da loja '{$storeName}' conectou e iniciou transmissão{$downtimeSuffix}",
                    $locked->slug,
                    $locked->store_id,
                    [
                        'ip' => $ip,
                        'device_id' => $deviceId,
                        'device_label' => $parsedUa['device_label'],
                        'viewport' => ($viewportW && $viewportH) ? "{$viewportW}x{$viewportH}" : null,
                        'launch_mode' => $launchMode,
                        'downtime_seconds' => $downtimeSeconds,
                    ]
                );

                if ($locked->monitoring_enabled) {
                    $pendingWebhooks[] = [
                        'event' => 'tv.came_online',
                        'screen_id' => $locked->id,
                        'previous_status' => $previousStatus,
                        'new_status' => $newHealthStatus,
                        'reason' => $reason,
                        'duration_previous_seconds' => $downtimeSeconds,
                        'downtime_seconds' => $downtimeSeconds,
                        'occurred_at' => $now,
                        'during_business_hours' => $duringBusinessHours,
                    ];
                }
            } elseif ($previousStatus === 'online' && $newHealthStatus === 'degraded') {
                $reason = $offlineCached ? 'offline_cached_mode' : (!$networkOnline ? 'network_offline_flag' : 'media_errors_spike');
                TvScreenStatusEvent::create([
                    'screen_id' => $locked->id,
                    'store_id' => $locked->store_id,
                    'screen_slug' => $locked->slug,
                    'event_type' => 'tv.became_degraded',
                    'previous_status' => 'online',
                    'new_status' => 'degraded',
                    'reason' => $reason,
                    'duration_previous_seconds' => $locked->status_changed_at ? (int) $locked->status_changed_at->diffInSeconds($now) : null,
                    'during_business_hours' => $duringBusinessHours,
                    'metadata' => ['recent_errors_count' => $recentErrorsCount, 'offline_cached' => $offlineCached],
                    'occurred_at' => $now,
                    'created_at' => $now,
                ]);

                if ($locked->monitoring_enabled) {
                    $pendingWebhooks[] = [
                        'event' => 'tv.became_degraded',
                        'screen_id' => $locked->id,
                        'previous_status' => 'online',
                        'new_status' => 'degraded',
                        'reason' => $reason,
                        'duration_previous_seconds' => null,
                        'downtime_seconds' => null,
                        'occurred_at' => $now,
                        'during_business_hours' => $duringBusinessHours,
                    ];
                }
            } elseif ($previousStatus === 'degraded' && $newHealthStatus === 'online') {
                TvScreenStatusEvent::create([
                    'screen_id' => $locked->id,
                    'store_id' => $locked->store_id,
                    'screen_slug' => $locked->slug,
                    'event_type' => 'tv.recovered_healthy',
                    'previous_status' => 'degraded',
                    'new_status' => 'online',
                    'reason' => 'healthy_ping_resumed',
                    'duration_previous_seconds' => $locked->status_changed_at ? (int) $locked->status_changed_at->diffInSeconds($now) : null,
                    'during_business_hours' => $duringBusinessHours,
                    'metadata' => [],
                    'occurred_at' => $now,
                    'created_at' => $now,
                ]);

                if ($locked->monitoring_enabled) {
                    $pendingWebhooks[] = [
                        'event' => 'tv.recovered_healthy',
                        'screen_id' => $locked->id,
                        'previous_status' => 'degraded',
                        'new_status' => 'online',
                        'reason' => 'healthy_ping_resumed',
                        'duration_previous_seconds' => null,
                        'downtime_seconds' => null,
                        'occurred_at' => $now,
                        'during_business_hours' => $duringBusinessHours,
                    ];
                }
            }

            // Detect device or resolution change on an already-known screen
            $deviceChanged = $previousDeviceId !== null && $deviceId !== null && $previousDeviceId !== $deviceId;
            $resolutionChanged = $previousViewportW !== null && $previousViewportH !== null
                && $viewportW !== null && $viewportH !== null
                && (abs($previousViewportW - $viewportW) > 10 || abs($previousViewportH - $viewportH) > 10);

            if ($deviceChanged || $resolutionChanged) {
                $changeReason = $deviceChanged ? 'device_id_changed' : 'resolution_changed';
                TvScreenStatusEvent::create([
                    'screen_id' => $locked->id,
                    'store_id' => $locked->store_id,
                    'screen_slug' => $locked->slug,
                    'event_type' => 'tv.device_changed',
                    'previous_status' => $previousStatus,
                    'new_status' => $newHealthStatus,
                    'reason' => $changeReason,
                    'duration_previous_seconds' => null,
                    'during_business_hours' => $duringBusinessHours,
                    'metadata' => [
                        'previous_device_id' => $previousDeviceId,
                        'new_device_id' => $deviceId,
                        'previous_viewport' => ($previousViewportW && $previousViewportH) ? "{$previousViewportW}x{$previousViewportH}" : null,
                        'new_viewport' => ($viewportW && $viewportH) ? "{$viewportW}x{$viewportH}" : null,
                        'launch_mode' => $launchMode,
                    ],
                    'occurred_at' => $now,
                    'created_at' => $now,
                ]);

                if ($locked->monitoring_enabled) {
                    $pendingWebhooks[] = [
                        'event' => 'tv.device_changed',
                        'screen_id' => $locked->id,
                        'previous_status' => $previousStatus,
                        'new_status' => $newHealthStatus,
                        'reason' => $changeReason,
                        'duration_previous_seconds' => null,
                        'downtime_seconds' => null,
                        'occurred_at' => $now,
                        'during_business_hours' => $duringBusinessHours,
                    ];
                }
            }

            return [
                'status' => $newHealthStatus === 'standby' ? 'standby' : 'online',
                'health_status' => $newHealthStatus,
                'is_blackout' => (bool) $locked->is_blackout,
                'playlist_version' => $playlistVersion,
                'recommended_ping_seconds' => (int) config('tv.heartbeat_interval_seconds', 30),
                'server_time' => $now->toISOString(),
            ];
        });

        // Dispatch outbound webhooks AFTER transaction commits so consumers never see uncommitted DB state
        foreach ($pendingWebhooks as $item) {
            $this->dispatchTransitionWebhook($item);
        }

        return $resultState;
    }

    /**
     * Handle graceful disconnect beacon (pagehide / beforeunload) for immediate offline detection.
     *
     * @param  array<string, mixed>  $input
     */
    public function recordGracefulDisconnect(TvScreen $screen, array $input, Request $request): void
    {
        if (filter_var($input['preview'] ?? $request->query('preview', false), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $now = now();
        $reason = Str::limit((string) ($input['reason'] ?? 'browser_closed'), 60, '');
        $pendingWebhook = null;

        DB::transaction(function () use ($screen, $now, $reason, &$pendingWebhook): void {
            $locked = TvScreen::query()
                ->with('store')
                ->whereKey($screen->id)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                return;
            }

            $previousStatus = $this->inferEffectiveStatus($locked, $now);
            if ($previousStatus === 'offline' || $previousStatus === 'never_connected') {
                return;
            }

            $onlineDuration = $locked->online_since
                ? max(0, (int) $locked->online_since->diffInSeconds($now))
                : ($locked->uptime_seconds ?: null);

            $locked->update([
                'is_online' => false,
                'health_status' => 'offline',
                'status_changed_at' => $now,
                'offline_since' => $now,
                'online_since' => null,
            ]);

            $openSessions = TvScreenSession::query()
                ->where('screen_id', $locked->id)
                ->whereNull('ended_at')
                ->get();

            foreach ($openSessions as $session) {
                $session->close('browser_closed', $now);
            }

            $storeHours = $this->calculateStoreHours($locked->store);
            $duringBusinessHours = (bool) ($storeHours['is_open_now'] ?? false);
            $storeName = $locked->store?->name ?? $locked->name;

            TvScreenStatusEvent::create([
                'screen_id' => $locked->id,
                'store_id' => $locked->store_id,
                'screen_slug' => $locked->slug,
                'event_type' => 'tv.went_offline',
                'previous_status' => $previousStatus,
                'new_status' => 'offline',
                'reason' => $reason,
                'duration_previous_seconds' => $onlineDuration,
                'during_business_hours' => $duringBusinessHours,
                'metadata' => ['graceful' => true],
                'occurred_at' => $now,
                'created_at' => $now,
            ]);

            TvLog::log(
                'warning',
                'tv_went_offline',
                "TV da loja '{$storeName}' encerrou o player ({$reason})",
                $locked->slug,
                $locked->store_id,
                [
                    'reason' => $reason,
                    'during_business_hours' => $duringBusinessHours,
                    'online_duration_seconds' => $onlineDuration,
                ]
            );

            if ($locked->monitoring_enabled) {
                $pendingWebhook = [
                    'event' => 'tv.went_offline',
                    'screen_id' => $locked->id,
                    'previous_status' => $previousStatus,
                    'new_status' => 'offline',
                    'reason' => $reason,
                    'duration_previous_seconds' => $onlineDuration,
                    'downtime_seconds' => null,
                    'occurred_at' => $now,
                    'during_business_hours' => $duringBusinessHours,
                ];
            }
        });

        if ($pendingWebhook) {
            $this->dispatchTransitionWebhook($pendingWebhook);
        }
    }

    /**
     * Reconcile stale screens (pure DB — zero Redis dependency).
     * Executed every minute by `tv:monitor-health` AND lazily before status reads.
     *
     * @return array{checked: int, transitioned_offline: int, transitioned_degraded: int, sessions_closed: int, webhooks_queued: int}
     */
    public function reconcileStaleScreens(?int $onlyScreenId = null, bool $dispatchWebhooks = true): array
    {
        $now = now();
        $onlineThreshold = (int) config('tv.online_threshold_seconds', 90);
        $offlineThreshold = (int) config('tv.offline_threshold_seconds', 180);

        $query = TvScreen::query()
            ->with('store')
            ->whereNotNull('last_ping_at')
            ->where(function ($q) use ($now, $onlineThreshold) {
                $q->where('is_online', true)
                    ->orWhereIn('health_status', ['online', 'degraded', 'standby'])
                    ->orWhere('last_ping_at', '<', $now->copy()->subSeconds($onlineThreshold));
            });

        if ($onlyScreenId !== null) {
            $query->whereKey($onlyScreenId);
        }

        $candidates = $query->get();
        $stats = [
            'checked' => $candidates->count(),
            'transitioned_offline' => 0,
            'transitioned_degraded' => 0,
            'sessions_closed' => 0,
            'webhooks_queued' => 0,
        ];

        $pendingWebhooks = [];

        foreach ($candidates as $candidate) {
            $lastPing = $candidate->last_ping_at;
            if (!$lastPing) {
                continue;
            }

            $ageSeconds = (int) $lastPing->diffInSeconds($now, false);

            if ($ageSeconds > $offlineThreshold) {
                DB::transaction(function () use ($candidate, $now, $offlineThreshold, &$stats, &$pendingWebhooks): void {
                    $locked = TvScreen::query()
                        ->with('store')
                        ->whereKey($candidate->id)
                        ->lockForUpdate()
                        ->first();

                    if (!$locked || !$locked->last_ping_at) {
                        return;
                    }

                    $freshAge = (int) $locked->last_ping_at->diffInSeconds($now, false);
                    if ($freshAge <= $offlineThreshold) {
                        return;
                    }

                    // Always close any leftover open sessions at their own last_ping_at
                    $openSessions = TvScreenSession::query()
                        ->where('screen_id', $locked->id)
                        ->whereNull('ended_at')
                        ->get();

                    foreach ($openSessions as $session) {
                        $session->close('timeout_disconnected', $session->last_ping_at ?? $locked->last_ping_at);
                        $stats['sessions_closed']++;
                    }

                    $alreadyMarkedOffline = !$locked->is_online && $locked->health_status === 'offline';
                    if ($alreadyMarkedOffline) {
                        return;
                    }

                    $previousStatus = $locked->health_status ?: ($locked->is_online ? 'online' : 'offline');
                    $effectiveOfflineAt = $locked->last_ping_at->copy();
                    $onlineDuration = $locked->online_since
                        ? max(0, (int) $locked->online_since->diffInSeconds($effectiveOfflineAt))
                        : ($locked->uptime_seconds ?: null);

                    $locked->update([
                        'is_online' => false,
                        'health_status' => 'offline',
                        'status_changed_at' => $effectiveOfflineAt,
                        'offline_since' => $effectiveOfflineAt,
                        'online_since' => null,
                    ]);

                    $stats['transitioned_offline']++;

                    $storeHours = $this->calculateStoreHours($locked->store);
                    $duringBusinessHours = (bool) ($storeHours['is_open_now'] ?? false);
                    $storeName = $locked->store?->name ?? $locked->name;

                    TvScreenStatusEvent::create([
                        'screen_id' => $locked->id,
                        'store_id' => $locked->store_id,
                        'screen_slug' => $locked->slug,
                        'event_type' => 'tv.went_offline',
                        'previous_status' => $previousStatus,
                        'new_status' => 'offline',
                        'reason' => 'heartbeat_timeout',
                        'duration_previous_seconds' => $onlineDuration,
                        'during_business_hours' => $duringBusinessHours,
                        'metadata' => [
                            'last_ping_at' => $effectiveOfflineAt->toISOString(),
                            'detected_at' => $now->toISOString(),
                            'age_seconds' => $freshAge,
                        ],
                        'occurred_at' => $effectiveOfflineAt,
                        'created_at' => $now,
                    ]);

                    TvLog::log(
                        'warning',
                        'tv_went_offline',
                        "TV da loja '{$storeName}' ficou offline (sem sinal há " . $this->formatDurationHuman($freshAge) . ')',
                        $locked->slug,
                        $locked->store_id,
                        [
                            'reason' => 'heartbeat_timeout',
                            'last_ping_at' => $effectiveOfflineAt->toISOString(),
                            'during_business_hours' => $duringBusinessHours,
                            'online_duration_seconds' => $onlineDuration,
                        ]
                    );

                    if ($locked->monitoring_enabled) {
                        $pendingWebhooks[] = [
                            'event' => 'tv.went_offline',
                            'screen_id' => $locked->id,
                            'previous_status' => $previousStatus,
                            'new_status' => 'offline',
                            'reason' => 'heartbeat_timeout',
                            'duration_previous_seconds' => $onlineDuration,
                            'downtime_seconds' => null,
                            'occurred_at' => $effectiveOfflineAt,
                            'during_business_hours' => $duringBusinessHours,
                        ];
                    }
                });
            } elseif ($ageSeconds > $onlineThreshold && !$candidate->is_blackout && $candidate->health_status === 'online') {
                DB::transaction(function () use ($candidate, $now, $onlineThreshold, $offlineThreshold, &$stats, &$pendingWebhooks): void {
                    $locked = TvScreen::query()
                        ->with('store')
                        ->whereKey($candidate->id)
                        ->lockForUpdate()
                        ->first();

                    if (!$locked || !$locked->last_ping_at || $locked->health_status !== 'online' || $locked->is_blackout) {
                        return;
                    }

                    $freshAge = (int) $locked->last_ping_at->diffInSeconds($now, false);
                    if ($freshAge <= $onlineThreshold || $freshAge > $offlineThreshold) {
                        return;
                    }

                    $locked->update([
                        'health_status' => 'degraded',
                        'status_changed_at' => $now,
                    ]);

                    $stats['transitioned_degraded']++;

                    $storeHours = $this->calculateStoreHours($locked->store);
                    $duringBusinessHours = (bool) ($storeHours['is_open_now'] ?? false);

                    TvScreenStatusEvent::create([
                        'screen_id' => $locked->id,
                        'store_id' => $locked->store_id,
                        'screen_slug' => $locked->slug,
                        'event_type' => 'tv.became_degraded',
                        'previous_status' => 'online',
                        'new_status' => 'degraded',
                        'reason' => 'heartbeat_delayed',
                        'duration_previous_seconds' => null,
                        'during_business_hours' => $duringBusinessHours,
                        'metadata' => ['seconds_since_last_ping' => $freshAge],
                        'occurred_at' => $now,
                        'created_at' => $now,
                    ]);

                    if ($locked->monitoring_enabled) {
                        $pendingWebhooks[] = [
                            'event' => 'tv.became_degraded',
                            'screen_id' => $locked->id,
                            'previous_status' => 'online',
                            'new_status' => 'degraded',
                            'reason' => 'heartbeat_delayed',
                            'duration_previous_seconds' => null,
                            'downtime_seconds' => null,
                            'occurred_at' => $now,
                            'during_business_hours' => $duringBusinessHours,
                        ];
                    }
                });
            }
        }

        if ($dispatchWebhooks) {
            foreach ($pendingWebhooks as $item) {
                $deliveries = $this->dispatchTransitionWebhook($item);
                $stats['webhooks_queued'] += count($deliveries);
            }
        }

        return $stats;
    }

    /**
     * Record a Standby/Blackout transition initiated from the Admin panel.
     */
    public function recordStandbyTransition(TvScreen $screen, bool $isBlackout, ?string $actorName = null): void
    {
        $screen->loadMissing('store');
        $now = now();
        $previousStatus = $screen->health_status ?: ($screen->is_online ? 'online' : 'offline');
        $newStatus = $isBlackout ? 'standby' : ($screen->is_online ? 'online' : 'offline');
        $eventType = $isBlackout ? 'tv.standby_enabled' : 'tv.standby_disabled';

        $screen->update([
            'health_status' => $newStatus,
            'status_changed_at' => $now,
        ]);

        $storeHours = $this->calculateStoreHours($screen->store);
        $duringBusinessHours = (bool) ($storeHours['is_open_now'] ?? false);

        TvScreenStatusEvent::create([
            'screen_id' => $screen->id,
            'store_id' => $screen->store_id,
            'screen_slug' => $screen->slug,
            'event_type' => $eventType,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'reason' => 'admin_command',
            'duration_previous_seconds' => null,
            'during_business_hours' => $duringBusinessHours,
            'metadata' => ['actor' => $actorName ?? 'Administrador'],
            'occurred_at' => $now,
            'created_at' => $now,
        ]);

        if ($screen->monitoring_enabled) {
            $this->dispatchTransitionWebhook([
                'event' => $eventType,
                'screen_id' => $screen->id,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'reason' => 'admin_command',
                'duration_previous_seconds' => null,
                'downtime_seconds' => null,
                'occurred_at' => $now,
                'during_business_hours' => $duringBusinessHours,
            ]);
        }
    }

    /**
     * Check if playback errors spiked on a screen and emit an optional webhook alert.
     */
    public function handlePlayerErrorLogged(?TvScreen $screen, string $message, ?string $mediaUrl = null): void
    {
        if (!$screen || !$screen->monitoring_enabled) {
            return;
        }

        $windowMin = (int) config('tv.degraded_errors_window_minutes', 15);
        $threshold = (int) config('tv.degraded_errors_threshold', 3);
        $now = now();

        $recentCount = TvLog::query()
            ->where('screen_slug', $screen->slug)
            ->where('event', 'tv_player_error')
            ->where('created_at', '>=', $now->copy()->subMinutes($windowMin))
            ->count();

        if ($recentCount < $threshold) {
            return;
        }

        $screen->loadMissing('store');
        $storeHours = $this->calculateStoreHours($screen->store);
        $duringBusinessHours = (bool) ($storeHours['is_open_now'] ?? false);

        $this->dispatchTransitionWebhook([
            'event' => 'tv.player_error_spike',
            'screen_id' => $screen->id,
            'previous_status' => $screen->health_status ?: 'online',
            'new_status' => 'degraded',
            'reason' => 'media_errors_spike',
            'duration_previous_seconds' => null,
            'downtime_seconds' => null,
            'occurred_at' => $now,
            'during_business_hours' => $duringBusinessHours,
            'extra' => [
                'errors_in_window' => $recentCount,
                'window_minutes' => $windowMin,
                'last_error_message' => $message,
                'media_url' => $mediaUrl,
            ],
        ]);
    }

    /**
     * Notify webhooks subscribed to `tv.playlist_updated` when ads or design change.
     *
     * @param  array<string, mixed>  $details
     */
    public function notifyPlaylistUpdated(string $action, string $message, ?int $storeId = null, array $details = []): void
    {
        $now = now();
        $tz = (string) config('tv.timezone', 'America/Sao_Paulo');

        $payload = [
            'event' => 'tv.playlist_updated',
            'event_label' => 'Grade de anúncios ou design atualizado',
            'severity' => 'info',
            'occurred_at' => $now->toISOString(),
            'occurred_at_local' => $now->copy()->setTimezone($tz)->toIso8601String(),
            'summary_text' => "📺 Atualização TV Signage: {$message}",
            'details' => array_merge(['action' => $action, 'store_id' => $storeId], $details),
            'fleet_context' => $this->buildFleetContextSummary(),
        ];

        $this->webhookDispatcher->dispatchEvent(
            eventType: 'tv.playlist_updated',
            payload: $payload,
            storeId: $storeId,
            screenSlug: null,
            duringBusinessHours: true
        );
    }

    /**
     * Build the full public Pull payload for GET /api/v1/tv/status.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function buildFleetStatus(array $filters = []): array
    {
        // Lazy reconciliation guarantees real-time accuracy even between cron runs
        $this->reconcileStaleScreens();

        $now = now();
        $tz = (string) config('tv.timezone', 'America/Sao_Paulo');
        $stores = Store::query()->orderBy('id', 'asc')->get();
        $screensByStore = TvScreen::query()->get()->keyBy('store_id');

        $ensuredScreens = collect();
        foreach ($stores as $store) {
            $screen = $screensByStore->get($store->id);
            if (!$screen) {
                $screen = TvScreen::firstOrCreate(
                    ['store_id' => $store->id],
                    [
                        'name' => 'TV - ' . $store->name,
                        'slug' => 'loja-' . Str::slug($store->name),
                        'orientation' => 'auto',
                        'is_online' => false,
                        'is_blackout' => false,
                        'health_status' => 'never_connected',
                        'monitoring_enabled' => true,
                    ]
                );
            }
            $screen->setRelation('store', $store);
            $ensuredScreens->push($screen);
        }

        // Batch-load telemetry aggregates (zero N+1!)
        $aggregates = $this->loadBatchTelemetryAggregates($ensuredScreens, $now);

        $allDtos = $ensuredScreens->map(
            fn (TvScreen $s) => $this->formatScreenStatusDto($s, $now, $aggregates)
        );

        // Compute summary across full fleet before applying response filters
        $monitoredDtos = $allDtos->where('monitoring_enabled', true);
        $summary = [
            'total_screens' => $allDtos->count(),
            'monitored_screens' => $monitoredDtos->count(),
            'online' => $allDtos->where('status', 'online')->count(),
            'degraded' => $allDtos->where('status', 'degraded')->count(),
            'standby' => $allDtos->where('status', 'standby')->count(),
            'offline' => $allDtos->where('status', 'offline')->count(),
            'offline_during_business_hours' => $allDtos->filter(
                fn (array $d) => $d['status'] === 'offline' && ($d['store_hours']['is_open_now'] ?? false)
            )->count(),
            'never_connected' => $allDtos->where('status', 'never_connected')->count(),
            'fleet_uptime_24h_pct' => $monitoredDtos->count() > 0
                ? round((float) $monitoredDtos->avg(fn (array $d) => $d['timing']['uptime_24h_pct']), 1)
                : 0.0,
        ];

        // Apply query filters
        $filtered = $allDtos;

        if (!empty($filters['only_monitored'])) {
            $filtered = $filtered->filter(fn (array $d) => $d['monitoring_enabled'] && $d['status'] !== 'never_connected');
        }
        if (!empty($filters['only_open_stores'])) {
            $filtered = $filtered->filter(fn (array $d) => (bool) ($d['store_hours']['is_open_now'] ?? false));
        }
        if (!empty($filters['store_id'])) {
            $targetStoreId = (int) $filters['store_id'];
            $filtered = $filtered->filter(fn (array $d) => $d['store_id'] === $targetStoreId);
        }
        if (!empty($filters['status'])) {
            $allowed = array_filter(array_map('trim', explode(',', strtolower((string) $filters['status']))));
            if ($allowed !== []) {
                $filtered = $filtered->filter(fn (array $d) => in_array($d['status'], $allowed, true));
            }
        }

        $response = [
            'success' => true,
            'generated_at' => $now->toISOString(),
            'generated_at_local' => $now->copy()->setTimezone($tz)->toIso8601String(),
            'thresholds' => [
                'heartbeat_interval_seconds' => (int) config('tv.heartbeat_interval_seconds', 30),
                'online_max_age_seconds' => (int) config('tv.online_threshold_seconds', 90),
                'offline_after_seconds' => (int) config('tv.offline_threshold_seconds', 180),
            ],
            'summary' => $summary,
            'data' => $filtered->values()->all(),
        ];

        $includeHistory = array_key_exists('include_history', $filters) ? (bool) $filters['include_history'] : true;
        if ($includeHistory) {
            $historyLimit = min(
                (int) config('tv.history_max_limit', 100),
                max(1, (int) ($filters['history_limit'] ?? config('tv.history_default_limit', 20)))
            );
            $response['recent_updates'] = $this->buildHistoryFeed([
                'store_id' => $filters['store_id'] ?? null,
                'category' => $filters['events'] ?? 'all',
                'limit' => $historyLimit,
            ])['data'];
        }

        return $response;
    }

    /**
     * Build detailed status + sessions + update history for a single TV (GET /api/v1/tv/status/{slug}).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>|null
     */
    public function buildSingleScreenStatus(string $slugOrId, array $options = []): ?array
    {
        $screen = TvScreen::query()
            ->with('store')
            ->where('slug', $slugOrId)
            ->when(is_numeric($slugOrId), fn ($q) => $q->orWhere('store_id', (int) $slugOrId))
            ->first();

        if (!$screen) {
            return null;
        }

        $this->reconcileStaleScreens($screen->id);
        $screen->refresh()->load('store');

        $now = now();
        $tz = (string) config('tv.timezone', 'America/Sao_Paulo');
        $aggregates = $this->loadBatchTelemetryAggregates(collect([$screen]), $now);
        $dto = $this->formatScreenStatusDto($screen, $now, $aggregates);

        $sessionsLimit = min(50, max(1, (int) ($options['sessions_limit'] ?? 15)));
        $historyLimit = min((int) config('tv.history_max_limit', 100), max(1, (int) ($options['history_limit'] ?? 30)));

        $reasonLabels = [
            'standby_turned_off' => 'Standby (Desligada pelo ERP)',
            'timeout_disconnected' => 'Desconectou / Sem Sinal',
            'browser_closed' => 'Player Fechado',
        ];

        $recentSessions = TvScreenSession::query()
            ->where('screen_id', $screen->id)
            ->orderByDesc('id')
            ->limit($sessionsLimit)
            ->get()
            ->map(function (TvScreenSession $s) use ($reasonLabels, $tz) {
                $isActive = $s->ended_at === null;
                $durMin = (int) $s->duration_minutes;

                return [
                    'id' => $s->id,
                    'started_at' => $s->started_at?->toISOString(),
                    'started_at_local' => $s->started_at?->copy()->setTimezone($tz)->toIso8601String(),
                    'ended_at' => $s->ended_at?->toISOString(),
                    'ended_at_local' => $s->ended_at?->copy()->setTimezone($tz)->toIso8601String(),
                    'last_ping_at' => $s->last_ping_at?->toISOString(),
                    'duration_minutes' => $durMin,
                    'duration_human' => floor($durMin / 60) . 'h ' . ($durMin % 60) . 'm',
                    'is_active' => $isActive,
                    'ended_reason' => $s->ended_reason,
                    'ended_reason_label' => $isActive ? 'Em Execução Agora' : ($reasonLabels[$s->ended_reason] ?? 'Encerrada'),
                ];
            })
            ->values()
            ->all();

        $history = $this->buildHistoryFeed([
            'slug' => $screen->slug,
            'store_id' => $screen->store_id,
            'include_global_content' => true,
            'category' => $options['events'] ?? 'all',
            'limit' => $historyLimit,
        ]);

        return [
            'success' => true,
            'generated_at' => $now->toISOString(),
            'generated_at_local' => $now->copy()->setTimezone($tz)->toIso8601String(),
            'data' => $dto,
            'recent_sessions' => $recentSessions,
            'recent_updates' => $history['data'],
        ];
    }

    /**
     * Build unified, chronological update/transition history feed (GET /api/v1/tv/history).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function buildHistoryFeed(array $filters = []): array
    {
        $now = now();
        $tz = (string) config('tv.timezone', 'America/Sao_Paulo');
        $limit = min(
            (int) config('tv.history_max_limit', 100),
            max(1, (int) ($filters['limit'] ?? 50))
        );

        $category = strtolower((string) ($filters['category'] ?? 'all'));
        $slug = !empty($filters['slug']) ? trim((string) $filters['slug']) : null;
        $storeId = !empty($filters['store_id']) ? (int) $filters['store_id'] : null;
        $includeGlobalContent = (bool) ($filters['include_global_content'] ?? false);
        $eventFilter = !empty($filters['event'])
            ? array_filter(array_map('trim', explode(',', (string) $filters['event'])))
            : [];
        $from = !empty($filters['from']) ? Carbon::parse((string) $filters['from']) : null;
        $to = !empty($filters['to']) ? Carbon::parse((string) $filters['to']) : null;

        $items = collect();

        // 1. Status transition events (tv_screen_status_events)
        if ($category === 'all' || $category === 'status') {
            $evQuery = TvScreenStatusEvent::query()
                ->with('store:id,name')
                ->orderByDesc('occurred_at')
                ->orderByDesc('id');

            if ($slug !== null) {
                $evQuery->where('screen_slug', $slug);
            } elseif ($storeId !== null) {
                $evQuery->where('store_id', $storeId);
            }
            if ($eventFilter !== []) {
                $evQuery->whereIn('event_type', $eventFilter);
            }
            if ($from) {
                $evQuery->where('occurred_at', '>=', $from);
            }
            if ($to) {
                $evQuery->where('occurred_at', '<=', $to);
            }

            foreach ($evQuery->limit($limit)->get() as $ev) {
                $storeName = $ev->store?->name ?? $ev->screen_slug;
                $items->push([
                    'id' => 'evt_' . $ev->id,
                    'category' => 'status',
                    'event' => $ev->event_type,
                    'level' => $this->levelForEvent($ev->event_type),
                    'screen_slug' => $ev->screen_slug,
                    'store_id' => $ev->store_id,
                    'store_name' => $storeName,
                    'message' => $this->buildStatusEventMessage($ev, $storeName),
                    'occurred_at' => $ev->occurred_at?->toISOString(),
                    'occurred_at_local' => $ev->occurred_at?->copy()->setTimezone($tz)->toIso8601String(),
                    'details' => [
                        'previous_status' => $ev->previous_status,
                        'new_status' => $ev->new_status,
                        'reason' => $ev->reason,
                        'duration_previous_seconds' => $ev->duration_previous_seconds,
                        'duration_previous_human' => $ev->duration_previous_seconds !== null
                            ? $this->formatDurationHuman((int) $ev->duration_previous_seconds)
                            : null,
                        'during_business_hours' => (bool) $ev->during_business_hours,
                        'metadata' => $ev->metadata,
                    ],
                ]);
            }
        }

        // 2. Content, Remote Command, and Player Error logs (tv_logs — sanitized!)
        if (in_array($category, ['all', 'content', 'commands', 'errors'], true)) {
            $logQuery = TvLog::query()
                ->with('store:id,name')
                ->whereNotIn('event', ['tv_turned_on', 'tv_went_offline']) // already represented in tv_screen_status_events
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            $allowedLogEvents = match ($category) {
                'content' => ['ad_created', 'ad_updated', 'ad_deleted', 'tv_design_updated', 'tv_logo_uploaded'],
                'commands' => ['tv_remote_command'],
                'errors' => ['tv_player_error', 'ad_upload_error'],
                default => null,
            };

            if ($allowedLogEvents !== null) {
                $logQuery->whereIn('event', $allowedLogEvents);
            }
            if ($eventFilter !== []) {
                $logQuery->whereIn('event', $eventFilter);
            }

            if ($slug !== null || $storeId !== null) {
                $logQuery->where(function ($q) use ($slug, $storeId, $includeGlobalContent) {
                    if ($slug !== null) {
                        $q->where('screen_slug', $slug);
                    }
                    if ($storeId !== null) {
                        $q->orWhere('store_id', $storeId);
                    }
                    if ($includeGlobalContent) {
                        $q->orWhere(function ($gq) {
                            $gq->whereNull('screen_slug')
                                ->whereNull('store_id')
                                ->whereIn('event', ['ad_created', 'ad_updated', 'ad_deleted', 'tv_design_updated']);
                        });
                    }
                });
            }

            if ($from) {
                $logQuery->where('created_at', '>=', $from);
            }
            if ($to) {
                $logQuery->where('created_at', '<=', $to);
            }

            foreach ($logQuery->limit($limit)->get() as $log) {
                $logCategory = match ($log->event) {
                    'tv_remote_command' => 'commands',
                    'tv_player_error', 'ad_upload_error' => 'errors',
                    default => 'content',
                };

                // Sanitize context (never leak internal stack traces or raw user emails)
                $safeDetails = $this->sanitizeLogContext($log->context);

                $items->push([
                    'id' => 'log_' . $log->id,
                    'category' => $logCategory,
                    'event' => $log->event,
                    'level' => $log->level,
                    'screen_slug' => $log->screen_slug,
                    'store_id' => $log->store_id,
                    'store_name' => $log->store?->name ?? ($log->store_id ? "Loja #{$log->store_id}" : 'Todas as Lojas'),
                    'message' => $log->message,
                    'occurred_at' => $log->created_at?->toISOString(),
                    'occurred_at_local' => $log->created_at?->copy()->setTimezone($tz)->toIso8601String(),
                    'details' => $safeDetails,
                ]);
            }
        }

        $sorted = $items
            ->sortByDesc('occurred_at')
            ->take($limit)
            ->values()
            ->all();

        return [
            'success' => true,
            'generated_at' => $now->toISOString(),
            'generated_at_local' => $now->copy()->setTimezone($tz)->toIso8601String(),
            'count' => count($sorted),
            'data' => $sorted,
        ];
    }

    /**
     * Build sanitized screen status DTO (shared by public Pull API & enriched Admin /screens).
     *
     * @param  array<string, mixed>  $aggregates
     * @return array<string, mixed>
     */
    public function formatScreenStatusDto(TvScreen $screen, Carbon $now, array $aggregates): array
    {
        $tz = (string) config('tv.timezone', 'America/Sao_Paulo');
        $status = $this->inferEffectiveStatus($screen, $now);
        $isOnline = in_array($status, ['online', 'degraded'], true);
        $deviceInfo = is_array($screen->device_info) ? $screen->device_info : [];
        $parsedUa = $this->parseUserAgent((string) ($deviceInfo['user_agent'] ?? ''));

        $storeHours = $this->calculateStoreHours($screen->store);
        $isOpenNow = (bool) ($storeHours['is_open_now'] ?? false);

        $lastPing = $screen->last_ping_at;
        $secondsSincePing = $lastPing ? max(0, (int) $lastPing->diffInSeconds($now, false)) : null;

        $offlineSince = $status === 'offline' ? ($screen->offline_since ?? $lastPing) : null;
        $offlineForSeconds = $offlineSince ? max(0, (int) $offlineSince->diffInSeconds($now, false)) : 0;

        $onlineSince = $isOnline ? ($screen->online_since ?? $lastPing) : null;
        $onlineForSeconds = $onlineSince ? max(0, (int) $onlineSince->diffInSeconds($now, false)) : 0;

        $viewportW = isset($deviceInfo['viewport_width'])
            ? (int) $deviceInfo['viewport_width']
            : (isset($deviceInfo['screen_width']) ? (int) $deviceInfo['screen_width'] : null);
        $viewportH = isset($deviceInfo['viewport_height'])
            ? (int) $deviceInfo['viewport_height']
            : (isset($deviceInfo['screen_height']) ? (int) $deviceInfo['screen_height'] : null);
        $dpr = isset($deviceInfo['device_pixel_ratio']) ? (float) $deviceInfo['device_pixel_ratio'] : null;

        // Infer DPR=2 physical resolution for standard 541x961 WebView TV boxes when DPR wasn't reported yet
        $physicalW = isset($deviceInfo['physical_width'])
            ? (int) $deviceInfo['physical_width']
            : (($viewportW && $dpr) ? (int) round($viewportW * $dpr) : null);
        $physicalH = isset($deviceInfo['physical_height'])
            ? (int) $deviceInfo['physical_height']
            : (($viewportH && $dpr) ? (int) round($viewportH * $dpr) : null);

        $orientationDetected = $deviceInfo['orientation_detected']
            ?? (($viewportW && $viewportH) ? ($viewportH > $viewportW ? 'portrait' : 'landscape') : null);
        $aspectRatio = $deviceInfo['aspect_ratio'] ?? $this->computeAspectRatio($viewportW, $viewportH);

        $errors1h = (int) ($aggregates['errors_1h'][$screen->slug] ?? 0);
        $errors24h = (int) ($aggregates['errors_24h'][$screen->slug] ?? 0);
        $lastErr = $aggregates['last_error'][$screen->slug] ?? null;
        $impToday = (int) ($aggregates['imp_today'][$screen->slug] ?? 0);
        $imp24h = (int) ($aggregates['imp_24h'][$screen->slug] ?? 0);
        $uptime24hPct = (float) ($aggregates['uptime_24h_pct'][$screen->id] ?? 0.0);
        $uptime7dPct = (float) ($aggregates['uptime_7d_pct'][$screen->id] ?? 0.0);

        $severity = match ($status) {
            'offline' => $isOpenNow ? 'critical' : 'warning',
            'degraded' => 'warning',
            'standby', 'never_connected' => 'info',
            default => 'ok',
        };

        $statusLabel = match ($status) {
            'online' => 'Online',
            'degraded' => 'Instável / Degradada',
            'standby' => 'Standby (Desligada pelo ERP)',
            'offline' => 'Offline',
            default => 'Nunca conectou',
        };

        $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return [
            'screen_id' => $screen->id,
            'store_id' => $screen->store_id,
            'store_code' => $screen->store?->codigo,
            'store_name' => $screen->store?->name ?? $screen->name,
            'store_city' => $screen->store?->city,
            'slug' => $screen->slug,
            'public_player_url' => "{$frontendUrl}/tv/{$screen->slug}",
            'monitoring_enabled' => (bool) $screen->monitoring_enabled,
            'status' => $status,
            'status_label' => $statusLabel,
            'severity' => $severity,
            'is_online' => $isOnline,
            'is_blackout' => (bool) $screen->is_blackout,
            'store_hours' => [
                'is_open_now' => $isOpenNow,
                'status' => $storeHours['status'] ?? 'unknown',
                'status_label' => $storeHours['status_label'] ?? 'Horário não informado',
                'today_hours_label' => $storeHours['today_hours_label'] ?? null,
                'opens_at' => $storeHours['opens_at'] ?? null,
                'closes_at' => $storeHours['closes_at'] ?? null,
                'timezone' => $storeHours['timezone'] ?? $tz,
            ],
            'timing' => [
                'last_ping_at' => $lastPing?->toISOString(),
                'last_ping_local' => $lastPing?->copy()->setTimezone($tz)->toIso8601String(),
                'seconds_since_last_ping' => $secondsSincePing,
                'online_since' => $onlineSince?->toISOString(),
                'online_for_seconds' => $onlineForSeconds,
                'online_for_human' => $onlineForSeconds > 0 ? $this->formatDurationHuman($onlineForSeconds) : null,
                'offline_since' => $offlineSince?->toISOString(),
                'offline_for_seconds' => $offlineForSeconds,
                'offline_for_human' => $offlineForSeconds > 0 ? $this->formatDurationHuman($offlineForSeconds) : null,
                'uptime_seconds' => (int) ($screen->uptime_seconds ?? 0),
                'uptime_24h_pct' => $uptime24hPct,
                'uptime_7d_pct' => $uptime7dPct,
                'last_status_change_at' => $screen->status_changed_at?->toISOString(),
            ],
            'display' => [
                'viewport_width' => $viewportW,
                'viewport_height' => $viewportH,
                'physical_width' => $physicalW,
                'physical_height' => $physicalH,
                'display_screen_width' => isset($deviceInfo['display_screen_width']) ? (int) $deviceInfo['display_screen_width'] : null,
                'display_screen_height' => isset($deviceInfo['display_screen_height']) ? (int) $deviceInfo['display_screen_height'] : null,
                'device_pixel_ratio' => $dpr,
                'orientation_detected' => $orientationDetected,
                'orientation_angle' => $deviceInfo['orientation_angle'] ?? null,
                'orientation_config' => $screen->orientation ?? 'auto',
                'aspect_ratio' => $aspectRatio,
            ],
            'client' => [
                'launch_mode' => $deviceInfo['launch_mode'] ?? ($parsedUa['is_webview'] ? 'android_webview' : ($lastPing ? 'browser_tab' : null)),
                'launch_path' => $deviceInfo['launch_path'] ?? ($lastPing ? '/tv/' . $screen->slug : null),
                'referrer' => $deviceInfo['referrer'] ?? null,
                'is_webview' => (bool) ($deviceInfo['is_webview'] ?? $parsedUa['is_webview']),
                'is_pwa' => (bool) ($deviceInfo['is_pwa'] ?? false),
                'device_id' => $screen->last_device_id ?? ($deviceInfo['device_id'] ?? null),
                'device_label' => $deviceInfo['device_label'] ?? $parsedUa['device_label'],
                'os' => $deviceInfo['os'] ?? $parsedUa['os'],
                'browser' => $deviceInfo['browser'] ?? $parsedUa['browser'],
                'player_state' => $deviceInfo['player_state'] ?? ($screen->is_blackout ? 'standby' : ($isOnline ? 'playing' : 'offline')),
                'visibility_state' => $deviceInfo['visibility_state'] ?? null,
                'network_online' => array_key_exists('network_online', $deviceInfo) ? (bool) $deviceInfo['network_online'] : null,
                'app_version' => $deviceInfo['app_version'] ?? null,
                'playlist_version' => $screen->playlist_version,
            ],
            'playback' => [
                'current_ad_id' => $screen->current_ad_id,
                'current_ad_title' => $screen->current_ad_title,
                'playlist_count' => isset($deviceInfo['playlist_count']) ? (int) $deviceInfo['playlist_count'] : null,
                'fps' => $screen->fps,
                'offline_cached' => (bool) ($deviceInfo['offline_cached'] ?? false),
                'impressions_today' => $impToday,
                'impressions_24h' => $imp24h,
                'errors_last_1h' => $errors1h,
                'errors_last_24h' => $errors24h,
                'last_error_at' => $lastErr['created_at'] ?? null,
                'last_error_message' => $lastErr['message'] ?? null,
            ],
        ];
    }

    /**
     * Build a test webhook payload using the first active TV (or synthetic sample if none exists).
     *
     * @return array<string, mixed>
     */
    public function buildTestWebhookPayload(): array
    {
        $now = now();
        $tz = (string) config('tv.timezone', 'America/Sao_Paulo');
        $sampleScreen = TvScreen::with('store')->orderByDesc('last_ping_at')->first();
        $tvBlock = null;

        if ($sampleScreen) {
            $aggregates = $this->loadBatchTelemetryAggregates(collect([$sampleScreen]), $now);
            $tvBlock = $this->formatCompactWebhookTvBlock($this->formatScreenStatusDto($sampleScreen, $now, $aggregates));
        }

        return [
            'event' => 'tv.webhook_test',
            'event_label' => 'Evento de teste do webhook',
            'severity' => 'info',
            'occurred_at' => $now->toISOString(),
            'occurred_at_local' => $now->copy()->setTimezone($tz)->toIso8601String(),
            'detected_at' => $now->toISOString(),
            'summary_text' => '🧪 Teste de Webhook Mais Capinhas TV Signage recebido com sucesso!',
            'transition' => [
                'previous_status' => 'online',
                'new_status' => 'online',
                'reason' => 'manual_test',
                'reason_label' => 'Disparo manual de teste',
                'duration_previous_seconds' => null,
                'duration_previous_human' => null,
                'downtime_seconds' => null,
                'downtime_human' => null,
                'during_business_hours' => true,
            ],
            'tv' => $tvBlock,
            'fleet_context' => $this->buildFleetContextSummary(),
        ];
    }

    /**
     * Parse common Android TV Box WebView & desktop browser User-Agents cleanly.
     *
     * @return array{device_label: ?string, os: ?string, browser: ?string, is_webview: bool}
     */
    public function parseUserAgent(?string $ua): array
    {
        $ua = trim((string) $ua);
        if ($ua === '') {
            return ['device_label' => null, 'os' => null, 'browser' => null, 'is_webview' => false];
        }

        $isWebView = (bool) preg_match('/;\s*wv\)/i', $ua);

        $os = null;
        $model = null;
        if (preg_match('/Android\s+([0-9.]+)(?:;\s*([^;)]+))?/i', $ua, $m)) {
            $os = 'Android ' . $m[1];
            if (!empty($m[2])) {
                $model = trim(preg_replace('/\s+Build\/.*$/i', '', $m[2]) ?? '');
            }
        } elseif (str_contains($ua, 'Windows NT')) {
            $os = 'Windows';
        } elseif (str_contains($ua, 'Macintosh') || str_contains($ua, 'Mac OS X')) {
            $os = 'macOS';
        } elseif (str_contains($ua, 'Tizen')) {
            $os = 'Samsung Tizen';
        } elseif (str_contains($ua, 'Web0S') || str_contains($ua, 'webOS')) {
            $os = 'LG webOS';
        } elseif (str_contains($ua, 'Linux')) {
            $os = 'Linux';
        }

        $browser = null;
        if (preg_match('/Chrome\/(\d+)/i', $ua, $bm)) {
            $browser = ($isWebView ? 'WebView Chrome ' : 'Chrome ') . $bm[1];
        } elseif (preg_match('/Firefox\/(\d+)/i', $ua, $bm)) {
            $browser = 'Firefox ' . $bm[1];
        } elseif (preg_match('/Safari\/(\d+)/i', $ua)) {
            $browser = 'Safari';
        }

        $parts = array_values(array_filter([$os, $model, $browser]));
        $label = $parts !== [] ? implode(' · ', $parts) : Str::limit($ua, 60);

        return [
            'device_label' => $label,
            'os' => $os,
            'browser' => $browser,
            'is_webview' => $isWebView,
        ];
    }

    public function formatDurationHuman(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remSec = $seconds % 60;

        if ($days > 0) {
            return "{$days}d {$hours}h {$minutes}m";
        }
        if ($hours > 0) {
            return sprintf('%dh %02dm', $hours, $minutes);
        }

        return sprintf('%dm %02ds', $minutes, $remSec);
    }

    public function computeAspectRatio(?int $width, ?int $height): ?string
    {
        if (!$width || !$height || $width <= 0 || $height <= 0) {
            return null;
        }

        $ratio = $width / $height;
        $known = [
            '9:16' => 9 / 16,
            '16:9' => 16 / 9,
            '3:4' => 3 / 4,
            '4:3' => 4 / 3,
            '1:1' => 1.0,
            '9:20' => 9 / 20,
            '21:9' => 21 / 9,
        ];

        foreach ($known as $label => $target) {
            if (abs($ratio - $target) <= 0.03) {
                return $label;
            }
        }

        $gcd = $this->gcd($width, $height);

        return ($width / $gcd) . ':' . ($height / $gcd);
    }

    /**
     * Compute effective real-time status for a TvScreen without mutating DB.
     */
    public function inferEffectiveStatus(TvScreen $screen, Carbon $now): string
    {
        if (!$screen->last_ping_at) {
            return $screen->is_blackout ? 'standby' : 'never_connected';
        }

        $onlineThreshold = (int) config('tv.online_threshold_seconds', 90);
        $offlineThreshold = (int) config('tv.offline_threshold_seconds', 180);
        $age = (int) $screen->last_ping_at->diffInSeconds($now, false);

        if ($age > $offlineThreshold) {
            return 'offline';
        }

        if ($screen->is_blackout) {
            return 'standby';
        }

        if (!$screen->is_online && $screen->health_status === 'offline') {
            return 'offline';
        }

        if ($age > $onlineThreshold || $screen->health_status === 'degraded') {
            return 'degraded';
        }

        return 'online';
    }

    /**
     * @param  array<string, mixed>  $transition
     * @return array<int, \App\Models\TvWebhookDelivery>
     */
    private function dispatchTransitionWebhook(array $transition): array
    {
        $screen = TvScreen::with('store')->find($transition['screen_id']);
        if (!$screen) {
            return [];
        }

        $now = now();
        $tz = (string) config('tv.timezone', 'America/Sao_Paulo');
        /** @var Carbon $occurredAt */
        $occurredAt = $transition['occurred_at'] instanceof Carbon ? $transition['occurred_at'] : $now;
        $event = (string) $transition['event'];
        $duringBusinessHours = (bool) ($transition['during_business_hours'] ?? false);

        $aggregates = $this->loadBatchTelemetryAggregates(collect([$screen]), $now);
        $fullDto = $this->formatScreenStatusDto($screen, $now, $aggregates);
        $fleetContext = $this->buildFleetContextSummary();

        $eventLabels = config('tv.supported_events', []);
        $eventLabel = is_array($eventLabels) ? ($eventLabels[$event] ?? $event) : $event;

        $reasonLabels = [
            'heartbeat_timeout' => 'Sem sinal de heartbeat (> ' . (int) round(config('tv.offline_threshold_seconds', 180) / 60) . ' min)',
            'browser_closed' => 'Player fechado / desconexão graciosa',
            'pagehide' => 'Player fechado / navegação interrompida',
            'first_boot' => 'Primeira conexão registrada',
            'reconnected' => 'TV reconectada após queda',
            'heartbeat_delayed' => 'Atraso no recebimento de heartbeat',
            'offline_cached_mode' => 'Reprodução em cache offline',
            'media_errors_spike' => 'Pico de erros de reprodução de mídia',
            'healthy_ping_resumed' => 'Sinal saudável normalizado',
            'admin_command' => 'Comando manual via ERP',
            'device_id_changed' => 'Novo dispositivo detectado no mesmo slug',
            'resolution_changed' => 'Alteração de resolução detectada',
        ];
        $reason = (string) ($transition['reason'] ?? '');
        $reasonLabel = $reasonLabels[$reason] ?? $reason;

        $severity = match ($event) {
            'tv.went_offline' => $duringBusinessHours ? 'critical' : 'info',
            'tv.became_degraded', 'tv.player_error_spike', 'tv.device_changed' => 'warning',
            'tv.came_online', 'tv.recovered_healthy' => 'ok',
            default => 'info',
        };

        $durPrev = $transition['duration_previous_seconds'] ?? null;
        $downtime = $transition['downtime_seconds'] ?? null;
        $storeName = $fullDto['store_name'];
        $citySuffix = !empty($fullDto['store_city']) ? " ({$fullDto['store_city']})" : '';
        $localTime = $occurredAt->copy()->setTimezone($tz)->format('H:i:s');

        $summaryText = match ($event) {
            'tv.went_offline' => sprintf(
                '%s TV OFFLINE%s: %s%s sem sinal desde %s%s.%s',
                $duringBusinessHours ? '🔴' : '🌙',
                $duringBusinessHours ? ' (Expediente)' : ' (Fora do expediente)',
                $storeName,
                $citySuffix,
                $localTime,
                $durPrev !== null ? ' (ficou online por ' . $this->formatDurationHuman((int) $durPrev) . ')' : '',
                $fullDto['playback']['current_ad_title'] ? ' Último anúncio: ' . $fullDto['playback']['current_ad_title'] . '.' : ''
            ),
            'tv.came_online' => sprintf(
                '🟢 TV ONLINE: %s%s voltou a transmitir às %s%s.',
                $storeName,
                $citySuffix,
                $localTime,
                $downtime !== null ? ' (ficou offline por ' . $this->formatDurationHuman((int) $downtime) . ')' : ' (primeira conexão)'
            ),
            'tv.became_degraded' => "🟡 TV INSTÁVEL: {$storeName}{$citySuffix} em estado degradado ({$reasonLabel}).",
            'tv.recovered_healthy' => "🟢 TV ESTABILIZADA: {$storeName}{$citySuffix} voltou ao estado saudável.",
            'tv.standby_enabled' => "⚫ TV STANDBY: {$storeName}{$citySuffix} colocada em Standby pelo ERP.",
            'tv.standby_disabled' => "☀️ TV LIGADA: {$storeName}{$citySuffix} saiu do modo Standby.",
            'tv.player_error_spike' => "⚠️ ERRO DE MÍDIA TV: {$storeName}{$citySuffix} registrou pico de falhas de reprodução.",
            'tv.device_changed' => "🔄 DISPOSITIVO ALTERADO: {$storeName}{$citySuffix} ({$reasonLabel}).",
            default => "📺 Evento {$event}: {$storeName}{$citySuffix}.",
        };

        $payload = [
            'event' => $event,
            'event_label' => $eventLabel,
            'severity' => $severity,
            'occurred_at' => $occurredAt->toISOString(),
            'occurred_at_local' => $occurredAt->copy()->setTimezone($tz)->toIso8601String(),
            'detected_at' => $now->toISOString(),
            'summary_text' => $summaryText,
            'transition' => [
                'previous_status' => $transition['previous_status'],
                'new_status' => $transition['new_status'],
                'reason' => $reason,
                'reason_label' => $reasonLabel,
                'duration_previous_seconds' => $durPrev,
                'duration_previous_human' => $durPrev !== null ? $this->formatDurationHuman((int) $durPrev) : null,
                'downtime_seconds' => $downtime,
                'downtime_human' => $downtime !== null ? $this->formatDurationHuman((int) $downtime) : null,
                'during_business_hours' => $duringBusinessHours,
            ],
            'tv' => $this->formatCompactWebhookTvBlock($fullDto),
            'fleet_context' => $fleetContext,
        ];

        if (!empty($transition['extra'])) {
            $payload['details'] = $transition['extra'];
        }

        return $this->webhookDispatcher->dispatchEvent(
            eventType: $event,
            payload: $payload,
            storeId: $screen->store_id,
            screenSlug: $screen->slug,
            duringBusinessHours: $duringBusinessHours
        );
    }

    /**
     * @param  array<string, mixed>  $fullDto
     * @return array<string, mixed>
     */
    private function formatCompactWebhookTvBlock(array $fullDto): array
    {
        $apiBase = rtrim((string) config('app.url'), '/');
        $vpW = $fullDto['display']['viewport_width'];
        $vpH = $fullDto['display']['viewport_height'];
        $phW = $fullDto['display']['physical_width'];
        $phH = $fullDto['display']['physical_height'];

        return [
            'screen_id' => $fullDto['screen_id'],
            'store_id' => $fullDto['store_id'],
            'store_code' => $fullDto['store_code'],
            'store_name' => $fullDto['store_name'],
            'store_city' => $fullDto['store_city'],
            'slug' => $fullDto['slug'],
            'status' => $fullDto['status'],
            'is_online' => $fullDto['is_online'],
            'is_blackout' => $fullDto['is_blackout'],
            'last_ping_at' => $fullDto['timing']['last_ping_at'],
            'last_ping_local' => $fullDto['timing']['last_ping_local'],
            'offline_since' => $fullDto['timing']['offline_since'],
            'online_since' => $fullDto['timing']['online_since'],
            'current_ad_id' => $fullDto['playback']['current_ad_id'],
            'current_ad_title' => $fullDto['playback']['current_ad_title'],
            'display' => [
                'viewport' => ($vpW && $vpH) ? "{$vpW}x{$vpH}" : null,
                'physical' => ($phW && $phH) ? "{$phW}x{$phH}" : null,
                'orientation' => $fullDto['display']['orientation_detected'],
                'aspect_ratio' => $fullDto['display']['aspect_ratio'],
            ],
            'client' => [
                'launch_mode' => $fullDto['client']['launch_mode'],
                'device_label' => $fullDto['client']['device_label'],
                'device_id' => $fullDto['client']['device_id'],
            ],
            'store_hours' => [
                'is_open_now' => $fullDto['store_hours']['is_open_now'],
                'status_label' => $fullDto['store_hours']['status_label'],
                'today_hours_label' => $fullDto['store_hours']['today_hours_label'],
            ],
            'errors_last_1h' => $fullDto['playback']['errors_last_1h'],
            'uptime_24h_pct' => $fullDto['timing']['uptime_24h_pct'],
            'status_url' => "{$apiBase}/api/v1/tv/status/{$fullDto['slug']}",
            'player_url' => $fullDto['public_player_url'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFleetContextSummary(): array
    {
        $now = now();
        $screens = TvScreen::query()->get();
        $counts = [
            'online' => 0,
            'degraded' => 0,
            'standby' => 0,
            'offline' => 0,
            'never_connected' => 0,
            'monitored_screens' => 0,
        ];

        foreach ($screens as $s) {
            if ($s->monitoring_enabled) {
                $counts['monitored_screens']++;
            }
            $st = $this->inferEffectiveStatus($s, $now);
            if (isset($counts[$st])) {
                $counts[$st]++;
            }
        }

        $concurrentOffline = TvScreenStatusEvent::query()
            ->where('event_type', 'tv.went_offline')
            ->where('created_at', '>=', $now->copy()->subMinutes(2))
            ->distinct('screen_id')
            ->count('screen_id');

        $incidentThreshold = (int) config('tv.concurrent_offline_incident_threshold', 3);

        return array_merge($counts, [
            'concurrent_offline_last_2m' => $concurrentOffline,
            'possible_server_incident' => $concurrentOffline >= $incidentThreshold,
        ]);
    }

    /**
     * Batch-load impressions, playback errors, and 24h/7d uptime percentages across all screens (SQLite & MariaDB safe).
     *
     * @param  Collection<int, TvScreen>  $screens
     * @return array<string, mixed>
     */
    private function loadBatchTelemetryAggregates(Collection $screens, Carbon $now): array
    {
        $slugs = $screens->pluck('slug')->filter()->values()->all();
        $screenIds = $screens->pluck('id')->filter()->values()->all();

        $since1h = $now->copy()->subHour();
        $since24h = $now->copy()->subHours(24);
        $since7d = $now->copy()->subDays(7);
        $todayStart = $now->copy()->setTimezone((string) config('tv.timezone', 'America/Sao_Paulo'))->startOfDay()->setTimezone($now->getTimezone());

        $errors1h = [];
        $errors24h = [];
        $lastError = [];
        $impToday = [];
        $imp24h = [];
        $uptime24hPct = [];
        $uptime7dPct = [];

        if ($slugs !== []) {
            $errors1h = TvLog::query()
                ->whereIn('screen_slug', $slugs)
                ->where('event', 'tv_player_error')
                ->where('created_at', '>=', $since1h)
                ->selectRaw('screen_slug, COUNT(*) as cnt')
                ->groupBy('screen_slug')
                ->pluck('cnt', 'screen_slug')
                ->map(fn ($v) => (int) $v)
                ->all();

            $errors24h = TvLog::query()
                ->whereIn('screen_slug', $slugs)
                ->where('event', 'tv_player_error')
                ->where('created_at', '>=', $since24h)
                ->selectRaw('screen_slug, COUNT(*) as cnt')
                ->groupBy('screen_slug')
                ->pluck('cnt', 'screen_slug')
                ->map(fn ($v) => (int) $v)
                ->all();

            $recentErrors = TvLog::query()
                ->whereIn('screen_slug', $slugs)
                ->where('event', 'tv_player_error')
                ->orderByDesc('id')
                ->limit(max(50, count($slugs) * 5))
                ->get(['screen_slug', 'message', 'created_at']);

            foreach ($recentErrors as $err) {
                if (!isset($lastError[$err->screen_slug])) {
                    $lastError[$err->screen_slug] = [
                        'message' => $err->message,
                        'created_at' => $err->created_at?->toISOString(),
                    ];
                }
            }

            $impToday = TvAdImpression::query()
                ->whereIn('screen_slug', $slugs)
                ->where('displayed_at', '>=', $todayStart)
                ->selectRaw('screen_slug, COUNT(*) as cnt')
                ->groupBy('screen_slug')
                ->pluck('cnt', 'screen_slug')
                ->map(fn ($v) => (int) $v)
                ->all();

            $imp24h = TvAdImpression::query()
                ->whereIn('screen_slug', $slugs)
                ->where('displayed_at', '>=', $since24h)
                ->selectRaw('screen_slug, COUNT(*) as cnt')
                ->groupBy('screen_slug')
                ->pluck('cnt', 'screen_slug')
                ->map(fn ($v) => (int) $v)
                ->all();
        }

        if ($screenIds !== []) {
            $sessions7d = TvScreenSession::query()
                ->whereIn('screen_id', $screenIds)
                ->where('last_ping_at', '>=', $since7d)
                ->get(['screen_id', 'started_at', 'last_ping_at', 'ended_at']);

            $byScreen = $sessions7d->groupBy('screen_id');
            foreach ($screenIds as $sid) {
                $list = $byScreen->get($sid, collect());
                $secs24h = $this->sumClampedSessionSeconds($list, $since24h, $now);
                $secs7d = $this->sumClampedSessionSeconds($list, $since7d, $now);

                // Express uptime % against expected 12h/day business window (capped at 100%)
                $uptime24hPct[$sid] = min(100.0, round(($secs24h / (12 * 3600)) * 100, 1));
                $uptime7dPct[$sid] = min(100.0, round(($secs7d / (7 * 12 * 3600)) * 100, 1));
            }
        }

        return [
            'errors_1h' => $errors1h,
            'errors_24h' => $errors24h,
            'last_error' => $lastError,
            'imp_today' => $impToday,
            'imp_24h' => $imp24h,
            'uptime_24h_pct' => $uptime24hPct,
            'uptime_7d_pct' => $uptime7dPct,
        ];
    }

    /**
     * Sum accurate non-overlapping session seconds clamped to [$windowStart, $windowEnd].
     *
     * @param  Collection<int, TvScreenSession>  $sessions
     */
    private function sumClampedSessionSeconds(Collection $sessions, Carbon $windowStart, Carbon $windowEnd): int
    {
        $intervals = [];
        foreach ($sessions as $s) {
            if (!$s->started_at) {
                continue;
            }
            $start = $s->started_at->gt($windowStart) ? $s->started_at->timestamp : $windowStart->timestamp;
            // Always cap session end at last_ping_at (immune to historical close() bug!)
            $rawEnd = $s->last_ping_at ?? $s->ended_at ?? $s->started_at;
            $end = $rawEnd->lt($windowEnd) ? $rawEnd->timestamp : $windowEnd->timestamp;
            if ($end > $start) {
                $intervals[] = [$start, $end];
            }
        }

        if ($intervals === []) {
            return 0;
        }

        usort($intervals, fn (array $a, array $b) => $a[0] <=> $b[0]);
        $merged = [$intervals[0]];
        foreach (array_slice($intervals, 1) as $iv) {
            $lastIdx = count($merged) - 1;
            if ($iv[0] <= $merged[$lastIdx][1]) {
                $merged[$lastIdx][1] = max($merged[$lastIdx][1], $iv[1]);
            } else {
                $merged[] = $iv;
            }
        }

        $total = 0;
        foreach ($merged as [$s, $e]) {
            $total += ($e - $s);
        }

        return $total;
    }

    /**
     * @return array<string, mixed>
     */
    private function calculateStoreHours(?Store $store): array
    {
        return $this->openingHoursService->calculate($store?->opening_hours);
    }

    private function levelForEvent(string $eventType): string
    {
        return match ($eventType) {
            'tv.went_offline', 'tv.became_degraded', 'tv.player_error_spike' => 'warning',
            default => 'info',
        };
    }

    private function buildStatusEventMessage(TvScreenStatusEvent $ev, string $storeName): string
    {
        $dur = $ev->duration_previous_seconds !== null
            ? $this->formatDurationHuman((int) $ev->duration_previous_seconds)
            : null;

        return match ($ev->event_type) {
            'tv.went_offline' => "TV {$storeName} ficou offline"
                . ($ev->during_business_hours ? ' durante expediente comercial' : ' fora do expediente')
                . ($dur ? " (esteve online por {$dur})" : ''),
            'tv.came_online' => "TV {$storeName} voltou online"
                . ($dur ? " (ficou offline por {$dur})" : ' (primeira conexão)'),
            'tv.became_degraded' => "TV {$storeName} entrou em estado instável ({$ev->reason})",
            'tv.recovered_healthy' => "TV {$storeName} recuperou operação saudável",
            'tv.standby_enabled' => "TV {$storeName} colocada em Standby pelo ERP",
            'tv.standby_disabled' => "TV {$storeName} religada pelo ERP",
            'tv.device_changed' => "Alteração de dispositivo/resolução detectada em {$storeName}",
            default => "Evento {$ev->event_type} em {$storeName}",
        };
    }

    /**
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>
     */
    private function sanitizeLogContext(?array $context): array
    {
        if (!is_array($context)) {
            return [];
        }

        $allowed = [
            'ad_id', 'media_type', 'duration_seconds', 'status',
            'command', 'command_pt', 'is_blackout',
            'url', 'type', 'file_name', 'downtime_seconds',
            'viewport', 'launch_mode', 'device_label',
        ];

        $out = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $context)) {
                $out[$key] = $context[$key];
            }
        }

        return $out;
    }

    private function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            $t = $b;
            $b = $a % $b;
            $a = $t;
        }

        return max(1, $a);
    }
}
