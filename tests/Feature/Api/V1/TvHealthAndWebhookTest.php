<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Store;
use App\Models\TvAd;
use App\Models\TvLog;
use App\Models\TvScreen;
use App\Models\TvScreenSession;
use App\Models\TvScreenStatusEvent;
use App\Models\TvWebhook;
use App\Models\TvWebhookDelivery;
use App\Models\User;
use App\Services\Tv\TvWebhookDispatcher;
use App\Services\Wheel\AblyPublisher;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class TvHealthAndWebhookTest extends TestCase
{
    private User $adminUser;
    private Store $openStore;
    private Store $closedStore;
    private Store $neverStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareIsolatedSchema();

        config(['queue.default' => 'sync']);
        config(['tv.default_webhook_url' => null]);

        $ablyMock = Mockery::mock(AblyPublisher::class);
        $ablyMock->shouldReceive('publishToScreen')->andReturn(true);
        $this->app->instance(AblyPublisher::class, $ablyMock);

        $this->adminUser = User::create([
            'name' => 'Admin Monitor',
            'email' => 'monitor@staging.local',
            'password' => bcrypt('password'),
            'active' => true,
            'is_super_admin' => true,
        ]);

        // Freeze time at 2026-11-19 15:00 BRT (18:00 UTC) — Thursday afternoon
        Carbon::setTestNow(Carbon::parse('2026-11-19 18:00:00', 'UTC'));

        $this->openStore = Store::create([
            'name' => 'Loja 1 - Centro',
            'codigo' => 'LJ-01',
            'city' => 'Tijucas',
            'active' => true,
            'opening_hours' => [
                'tz' => 'America/Sao_Paulo',
                'weekly' => [
                    'thu' => [['start' => '09:00', 'end' => '21:00']],
                ],
            ],
        ]);

        $this->closedStore = Store::create([
            'name' => 'Loja 2 - Noturna Fechada',
            'codigo' => 'LJ-02',
            'city' => 'Itapema',
            'active' => true,
            'opening_hours' => [
                'tz' => 'America/Sao_Paulo',
                'weekly' => [
                    'thu' => [['start' => '06:00', 'end' => '12:00']],
                ],
            ],
        ]);

        $this->neverStore = Store::create([
            'name' => 'Estoque',
            'codigo' => 'EST-01',
            'city' => 'Tijucas',
            'active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function prepareIsolatedSchema(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::dropAllTables();

            $migrations = [
                'database/migrations/0001_01_01_000000_create_users_table.php',
                'database/migrations/0001_01_01_000001_create_cache_table.php',
                'database/migrations/2026_01_07_151616_create_personal_access_tokens_table.php',
                'database/migrations/2026_01_07_151618_create_permission_tables.php',
                'database/migrations/2026_01_07_151622_create_activity_log_table.php',
                'database/migrations/2026_01_07_151623_add_event_column_to_activity_log_table.php',
                'database/migrations/2026_01_07_151624_add_batch_uuid_column_to_activity_log_table.php',
                'database/migrations/2026_01_07_154610_create_stores_table.php',
                'database/migrations/2026_01_07_154612_create_store_users_table.php',
                'database/migrations/2026_01_07_154630_add_active_to_users_table.php',
                'database/migrations/2026_01_07_214300_add_extended_fields_to_users_table.php',
                'database/migrations/2026_01_07_214400_add_extended_fields_to_stores_table.php',
                'database/migrations/2026_01_07_220000_add_operational_fields_to_stores_table.php',
                'database/migrations/2026_01_09_210000_add_is_super_admin_to_users_table.php',
                'database/migrations/2026_03_03_100000_create_tv_screens_and_ads_tables.php',
                'database/migrations/2026_03_03_110000_create_tv_telemetry_and_logs_tables.php',
                'database/migrations/2026_03_03_120000_add_is_blackout_to_tv_screens.php',
                'database/migrations/2026_03_03_130000_create_tv_screen_sessions_table.php',
                'database/migrations/2026_03_03_140000_add_design_template_to_tv_screens_and_globals.php',
                'database/migrations/2026_11_19_180000_create_tv_health_events_and_webhooks_tables.php',
            ];

            foreach ($migrations as $path) {
                Artisan::call('migrate', ['--path' => $path, '--force' => true]);
            }
        } else {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
    }

    public function test_rich_ping_telemetry_playlist_version_and_preview_isolation(): void
    {
        $screen = TvScreen::create([
            'store_id' => $this->openStore->id,
            'name' => 'TV - Loja 1 - Centro',
            'slug' => 'loja-loja-1-centro',
            'orientation' => 'auto',
        ]);

        // 1. Preview ping MUST NOT modify screen status or create a session
        $previewRes = $this->postJson("/api/v1/tv/ping/{$screen->slug}", [
            'preview' => true,
            'width' => 1280,
            'height' => 720,
        ])->assertOk();

        $previewRes->assertJsonPath('ignored_preview', true);
        $this->assertFalse($screen->fresh()->is_online);
        $this->assertSame(0, TvScreenSession::count());

        // 2. Real Android TV Box WebView ping records full display + launch telemetry
        $ua = 'Mozilla/5.0 (Linux; Android 14.0; TV BOX -3 Build/QP1A.191105.004; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/101.0.4951.61 Mobile Safari/537.36';
        $pingRes = $this->withHeaders(['User-Agent' => $ua])->postJson("/api/v1/tv/ping/{$screen->slug}", [
            'width' => 541,
            'height' => 961,
            'screen_width' => 541,
            'screen_height' => 961,
            'dpr' => 2,
            'orientation_angle' => 0,
            'launch_mode' => 'android_webview',
            'launch_path' => '/tv/loja-loja-1-centro',
            'device_id' => 'tvbox-abc123',
            'fps' => 60,
            'uptime_seconds' => 30,
            'current_ad_title' => 'Banner Global',
        ])->assertOk();

        $pingRes->assertJsonPath('health_status', 'online')
            ->assertJsonPath('recommended_ping_seconds', 30);
        $version1 = $pingRes->json('playlist_version');
        $this->assertNotEmpty($version1);

        // 3. Adding an active ad MUST change playlist_version
        TvAd::create([
            'title' => 'Novo Vídeo Promo',
            'media_type' => 'video',
            'media_url' => 'https://api.staging.local/storage/tv-ads/promo.mp4',
            'duration_seconds' => 20,
            'is_global' => true,
            'status' => 'active',
        ]);

        $pingRes2 = $this->withHeaders(['User-Agent' => $ua])->postJson("/api/v1/tv/ping/{$screen->slug}", [
            'width' => 541,
            'height' => 961,
            'dpr' => 2,
            'device_id' => 'tvbox-abc123',
            'uptime_seconds' => 60,
        ])->assertOk();

        $this->assertNotSame($version1, $pingRes2->json('playlist_version'));
        $this->assertSame(1, TvScreenSession::whereNull('ended_at')->count());

        // 4. Verify public Pull GET /api/v1/tv/status (NO AUTH!)
        $statusRes = $this->getJson('/api/v1/tv/status')->assertOk();
        $statusRes->assertJsonPath('success', true)
            ->assertJsonPath('summary.online', 1)
            ->assertJsonPath('summary.never_connected', 2);

        $tv1 = collect($statusRes->json('data'))->firstWhere('slug', $screen->slug);
        $this->assertNotNull($tv1);
        $this->assertSame('online', $tv1['status']);
        $this->assertSame(541, $tv1['display']['viewport_width']);
        $this->assertSame(961, $tv1['display']['viewport_height']);
        $this->assertSame(1082, $tv1['display']['physical_width']);
        $this->assertSame(1922, $tv1['display']['physical_height']);
        $this->assertSame('portrait', $tv1['display']['orientation_detected']);
        $this->assertSame('9:16', $tv1['display']['aspect_ratio']);
        $this->assertSame('android_webview', $tv1['client']['launch_mode']);
        $this->assertTrue($tv1['client']['is_webview']);
        $this->assertStringContainsString('TV BOX -3', $tv1['client']['device_label']);
        $this->assertTrue($tv1['store_hours']['is_open_now']);

        // Security assertion: sensitive internal tokens MUST NEVER appear in public Pull response
        $rawBody = $statusRes->getContent();
        $this->assertStringNotContainsString($screen->access_token, (string) $rawBody);
        $this->assertStringNotContainsString($screen->screen_key, (string) $rawBody);
    }

    public function test_watchdog_detects_offline_closes_session_at_last_ping_and_dispatches_offline_and_online_webhooks(): void
    {
        Http::fake([
            'https://hooks.example.test/*' => Http::response(['ok' => true], 200),
        ]);

        $webhook = TvWebhook::create([
            'name' => 'Monitor n8n Principal',
            'url' => 'https://hooks.example.test/tv-events',
            'is_active' => true,
            'events' => ['tv.went_offline', 'tv.came_online'],
            'only_during_business_hours' => false,
            'cooldown_seconds' => 60,
        ]);

        $screen = TvScreen::create([
            'store_id' => $this->openStore->id,
            'name' => 'TV - Loja 1 - Centro',
            'slug' => 'loja-loja-1-centro',
            'orientation' => 'auto',
        ]);

        // Boot at 18:00 UTC (triggers tv.came_online first_boot)
        $this->postJson("/api/v1/tv/ping/{$screen->slug}", [
            'width' => 541,
            'height' => 961,
            'dpr' => 2,
            'current_ad_title' => 'Campanha Institucional',
            'uptime_seconds' => 10,
        ])->assertOk();

        // Continuous pings every 2 minutes from 18:02 to 18:10 UTC (10 minutes online continuously)
        foreach ([2, 4, 6, 8, 10] as $min) {
            Carbon::setTestNow(Carbon::parse(sprintf('2026-11-19 18:%02d:00', $min), 'UTC'));
            $this->postJson("/api/v1/tv/ping/{$screen->slug}", [
                'width' => 541,
                'height' => 961,
                'uptime_seconds' => 10 + ($min * 60),
            ])->assertOk();
        }

        // Advance 2 minutes (18:12 UTC -> 120s since ping -> DEGRADED, not offline yet!)
        Carbon::setTestNow(Carbon::parse('2026-11-19 18:12:00', 'UTC'));
        $this->artisan('tv:monitor-health')->assertExitCode(0);
        $this->assertSame('degraded', $screen->fresh()->health_status);
        $this->assertTrue($screen->fresh()->is_online);

        // Advance to 20:10 UTC (2 HOURS offline!) and run watchdog
        Carbon::setTestNow(Carbon::parse('2026-11-19 20:10:00', 'UTC'));
        $this->artisan('tv:monitor-health')->assertExitCode(0);

        $screen->refresh();
        $this->assertFalse($screen->is_online);
        $this->assertSame('offline', $screen->health_status);
        $this->assertSame('2026-11-19T18:10:00.000000Z', $screen->offline_since?->toISOString());

        // CRITICAL REGRESSION FIX CHECK: session MUST end at 18:10 (10m duration), NOT 20:10 (130m)!
        $session = TvScreenSession::first();
        $this->assertNotNull($session->ended_at);
        $this->assertSame('timeout_disconnected', $session->ended_reason);
        $this->assertSame('2026-11-19T18:10:00.000000Z', $session->ended_at->toISOString());
        $this->assertSame(10, $session->duration_minutes);

        // Verify tv.went_offline webhook was sent with critical severity (store is open 09:00-21:00 BRT)
        Http::assertSent(function ($request) {
            return $request->url() === 'https://hooks.example.test/tv-events'
                && $request->header('X-TV-Event')[0] === 'tv.went_offline'
                && $request['severity'] === 'critical'
                && $request['transition']['during_business_hours'] === true
                && $request['tv']['slug'] === 'loja-loja-1-centro';
        });

        // TV comes back online at 20:40 UTC (offline for 2h 30m = 9000s!)
        Carbon::setTestNow(Carbon::parse('2026-11-19 20:40:00', 'UTC'));
        $this->postJson("/api/v1/tv/ping/{$screen->slug}", [
            'width' => 541,
            'height' => 961,
            'uptime_seconds' => 5,
        ])->assertOk();

        $screen->refresh();
        $this->assertTrue($screen->is_online);
        $this->assertSame('online', $screen->health_status);
        $this->assertNull($screen->offline_since);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://hooks.example.test/tv-events'
                && $request->header('X-TV-Event')[0] === 'tv.came_online'
                && $request['transition']['reason'] === 'reconnected'
                && $request['transition']['downtime_seconds'] === 9000
                && $request['transition']['downtime_human'] === '2h 30m';
        });

        // Verify deliveries table logged every dispatch cleanly
        $this->assertGreaterThanOrEqual(3, TvWebhookDelivery::where('tv_webhook_id', $webhook->id)->where('status', 'delivered')->count());

        // Verify public detail endpoint GET /api/v1/tv/status/{slug} & GET /api/v1/tv/history
        $detailRes = $this->getJson("/api/v1/tv/status/{$screen->slug}")->assertOk();
        $detailRes->assertJsonPath('data.status', 'online')
            ->assertJsonCount(2, 'recent_sessions');
        $this->assertNotEmpty($detailRes->json('recent_updates'));

        $historyRes = $this->getJson('/api/v1/tv/history?category=status')->assertOk();
        $events = collect($historyRes->json('data'))->pluck('event')->all();
        $this->assertContains('tv.went_offline', $events);
        $this->assertContains('tv.came_online', $events);
    }

    public function test_business_hours_filter_graceful_disconnect_and_cooldown(): void
    {
        Http::fake([
            'https://hooks.example.test/*' => Http::response(['received' => true], 200),
        ]);

        // Webhook configured ONLY during business hours
        TvWebhook::create([
            'name' => 'Alerta Expediente',
            'url' => 'https://hooks.example.test/business-only',
            'is_active' => true,
            'events' => ['tv.went_offline', 'tv.came_online'],
            'only_during_business_hours' => true,
            'cooldown_seconds' => 300,
        ]);

        // closedStore is CLOSED at 15:00 BRT (closes 12:00)
        $closedScreen = TvScreen::create([
            'store_id' => $this->closedStore->id,
            'name' => 'TV - Loja 2',
            'slug' => 'loja-loja-2-fechada',
            'orientation' => 'auto',
        ]);

        $this->postJson("/api/v1/tv/ping/{$closedScreen->slug}", ['uptime_seconds' => 30])->assertOk();
        $this->postJson("/api/v1/tv/disconnect/{$closedScreen->slug}", ['reason' => 'pagehide'])->assertOk();

        // Screen MUST be marked offline immediately, with ended_reason = browser_closed
        $this->assertFalse($closedScreen->fresh()->is_online);
        $this->assertSame('offline', $closedScreen->fresh()->health_status);
        $this->assertSame('browser_closed', TvScreenSession::first()->ended_reason);

        // Because store is CLOSED, business-hours-only webhook MUST NOT have fired!
        Http::assertNothingSent();

        // Now test openStore with graceful disconnect twice inside cooldown (300s)
        $openScreen = TvScreen::create([
            'store_id' => $this->openStore->id,
            'name' => 'TV - Loja 1',
            'slug' => 'loja-loja-1-open',
            'orientation' => 'auto',
        ]);

        $this->postJson("/api/v1/tv/ping/{$openScreen->slug}", ['uptime_seconds' => 10])->assertOk();
        $this->postJson("/api/v1/tv/disconnect/{$openScreen->slug}", ['reason' => 'pagehide'])->assertOk();

        // Second rapid bounce 30s later should hit cooldown on tv.went_offline
        Carbon::setTestNow(Carbon::parse('2026-11-19 18:00:30', 'UTC'));
        $this->postJson("/api/v1/tv/ping/{$openScreen->slug}", ['uptime_seconds' => 5])->assertOk();
        $this->postJson("/api/v1/tv/disconnect/{$openScreen->slug}", ['reason' => 'pagehide'])->assertOk();

        $this->assertSame(1, TvWebhookDelivery::where('event_type', 'tv.went_offline')->where('status', 'delivered')->count());
        $this->assertSame(1, TvWebhookDelivery::where('event_type', 'tv.went_offline')->where('status', 'skipped_cooldown')->count());
    }

    public function test_admin_webhook_crud_test_endpoint_and_ssrf_guard(): void
    {
        Http::fake([
            'https://n8n.example.test/*' => Http::response(['status' => 'accepted'], 200),
        ]);

        Sanctum::actingAs($this->adminUser);

        // Create webhook
        $createRes = $this->postJson('/api/v1/admin/tv/webhooks', [
            'name' => 'n8n WhatsApp TVs',
            'url' => 'https://n8n.example.test/webhook/tv',
            'events' => ['tv.went_offline', 'tv.came_online'],
            'only_during_business_hours' => true,
            'cooldown_seconds' => 120,
        ])->assertStatus(201);

        $webhookId = $createRes->json('data.id');

        // List webhooks + public pull URLs metadata
        $this->getJson('/api/v1/admin/tv/webhooks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure(['meta' => ['supported_events', 'public_endpoints', 'thresholds']]);

        // Send manual test delivery
        $this->postJson("/api/v1/admin/tv/webhooks/{$webhookId}/test")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.http_status', 200);

        // Deliveries audit log
        $this->getJson('/api/v1/admin/tv/webhooks/deliveries')
            ->assertOk()
            ->assertJsonPath('data.0.event_type', 'tv.webhook_test')
            ->assertJsonPath('data.0.status', 'delivered');

        // Update & delete
        $this->putJson("/api/v1/admin/tv/webhooks/{$webhookId}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->deleteJson("/api/v1/admin/tv/webhooks/{$webhookId}")
            ->assertOk();

        // Verify SSRF guard blocks internal metadata/localhost URLs when running outside local/testing
        $dispatcher = app(TvWebhookDispatcher::class);
        $originalEnv = $this->app['env'];
        $this->app['env'] = 'production';
        config(['tv.webhook_allow_private_targets' => false]);
        try {
            $this->assertNotNull($dispatcher->validateTargetUrl('http://127.0.0.1:8000/secret'));
            $this->assertNotNull($dispatcher->validateTargetUrl('http://169.254.169.254/latest/meta-data/'));
            $this->assertNotNull($dispatcher->validateTargetUrl('http://localhost/admin'));
        } finally {
            $this->app['env'] = $originalEnv;
        }
    }

    public function test_repair_legacy_sessions_command_fixes_zombies_and_inflated_durations(): void
    {
        $screen = TvScreen::create([
            'store_id' => $this->openStore->id,
            'name' => 'TV - Legacy',
            'slug' => 'loja-legacy',
            'is_online' => true,
            'health_status' => 'online',
            'last_ping_at' => Carbon::parse('2026-11-10 15:00:00', 'UTC'),
        ]);

        // Zombie open session + inflated closed session
        $zombie = TvScreenSession::create([
            'store_id' => $this->openStore->id,
            'screen_id' => $screen->id,
            'screen_slug' => $screen->slug,
            'started_at' => Carbon::parse('2026-11-10 14:00:00', 'UTC'),
            'last_ping_at' => Carbon::parse('2026-11-10 15:00:00', 'UTC'),
            'ended_at' => null,
            'duration_minutes' => 60,
        ]);

        $inflated = TvScreenSession::create([
            'store_id' => $this->openStore->id,
            'screen_id' => $screen->id,
            'screen_slug' => $screen->slug,
            'started_at' => Carbon::parse('2026-11-01 12:00:00', 'UTC'),
            'last_ping_at' => Carbon::parse('2026-11-01 13:00:00', 'UTC'),
            'ended_at' => Carbon::parse('2026-11-05 12:00:00', 'UTC'),
            'duration_minutes' => 5760,
            'ended_reason' => 'timeout_disconnected',
        ]);

        // Dry-run does not modify anything
        $this->artisan('tv:repair-legacy-sessions')->assertExitCode(0);
        $this->assertNull($zombie->fresh()->ended_at);
        $this->assertSame(5760, $inflated->fresh()->duration_minutes);

        // Apply fixes both + marks stale screen offline
        $this->artisan('tv:repair-legacy-sessions --apply')->assertExitCode(0);
        $this->assertSame('2026-11-10T15:00:00.000000Z', $zombie->fresh()->ended_at?->toISOString());
        $this->assertSame(60, $zombie->fresh()->duration_minutes);
        $this->assertSame('2026-11-01T13:00:00.000000Z', $inflated->fresh()->ended_at?->toISOString());
        $this->assertSame(60, $inflated->fresh()->duration_minutes);
        $this->assertFalse($screen->fresh()->is_online);
        $this->assertSame('offline', $screen->fresh()->health_status);
    }
}
