<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Store;
use App\Models\TvAd;
use App\Models\TvAdImpression;
use App\Models\TvLog;
use App\Models\TvScreen;
use App\Models\TvScreenSession;
use App\Models\User;
use App\Services\Wheel\AblyPublisher;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * End-to-end feature & regression test suite for the recovered TV Digital Signage module.
 *
 * Isolates all external services (AblyPublisher mocked) and uses synthetic local data only.
 */
class TvSignageTest extends TestCase
{
    /** @var array<int, string> */
    private array $cleanupPaths = [];

    private User $adminUser;
    private Store $storeAlpha;
    private Store $storeBeta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareIsolatedSchema();
        $this->registerSqliteDateFunctionsIfNeeded();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Always mock AblyPublisher so tests NEVER touch external real-time channels or real TVs.
        $ablyMock = Mockery::mock(AblyPublisher::class);
        $ablyMock->shouldReceive('publishToScreen')->andReturn(true);
        $this->app->instance(AblyPublisher::class, $ablyMock);

        Storage::fake('public');

        $this->adminUser = User::create([
            'name' => 'Admin TV Teste',
            'email' => 'admin.tv@staging.local',
            'password' => bcrypt('password'),
            'active' => true,
            'is_super_admin' => true,
        ]);

        $this->storeAlpha = Store::create([
            'name' => 'Loja 1 - Centro Teste',
            'city' => 'Tijucas',
            'active' => true,
        ]);

        $this->storeBeta = Store::create([
            'name' => 'Loja 2 - Shopping Teste',
            'city' => 'Itapema',
            'active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanupPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }
        $this->cleanupPaths = [];
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function prepareIsolatedSchema(): void
    {
        // On SQLite :memory:, run core ERP tables + all 5 recovered TV migrations directly,
        // avoiding a pre-existing MySQL-only dropColumn migration on unrelated wheel_players table.
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
                'database/migrations/2026_01_16_110003_create_user_roles_table.php',
                'database/migrations/2026_01_16_110004_create_user_permissions_table.php',
                'database/migrations/2026_01_16_110005_create_store_permissions_table.php',
                'database/migrations/2026_01_16_120000_extend_spatie_permission_tables.php',
                'database/migrations/2026_01_16_120001_create_user_store_roles_table.php',
                'database/migrations/2026_01_16_132619_create_modules_table.php',
                'database/migrations/2026_03_03_100000_create_tv_screens_and_ads_tables.php',
                'database/migrations/2026_03_03_110000_create_tv_telemetry_and_logs_tables.php',
                'database/migrations/2026_03_03_120000_add_is_blackout_to_tv_screens.php',
                'database/migrations/2026_03_03_130000_create_tv_screen_sessions_table.php',
                'database/migrations/2026_03_03_140000_add_design_template_to_tv_screens_and_globals.php',
            ];

            foreach ($migrations as $path) {
                Artisan::call('migrate', ['--path' => $path, '--force' => true]);
            }
        } else {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
    }

    private function registerSqliteDateFunctionsIfNeeded(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $pdo = DB::connection()->getPdo();
        if (method_exists($pdo, 'sqliteCreateFunction')) {
            // MariaDB/MySQL DAYOFWEEK(): 1 = Sunday .. 7 = Saturday
            $pdo->sqliteCreateFunction('DAYOFWEEK', static function (?string $dt): ?int {
                if (!$dt) {
                    return null;
                }
                return ((int) date('w', strtotime($dt))) + 1;
            }, 1);

            $pdo->sqliteCreateFunction('HOUR', static function (?string $dt): ?int {
                if (!$dt) {
                    return null;
                }
                return (int) date('G', strtotime($dt));
            }, 1);
        }
    }

    // =========================================================================
    // 1. Authorization & Shared Super-Admin Regression
    // =========================================================================

    public function test_unauthenticated_requests_to_admin_tv_endpoints_are_rejected_with_401(): void
    {
        $this->getJson('/api/v1/admin/tv/ads')->assertUnauthorized();
        $this->postJson('/api/v1/admin/tv/ads', [])->assertUnauthorized();
        $this->getJson('/api/v1/admin/tv/screens')->assertUnauthorized();
        $this->getJson('/api/v1/admin/tv/stats')->assertUnauthorized();
        $this->getJson('/api/v1/admin/tv/analytics')->assertUnauthorized();
        $this->getJson('/api/v1/admin/tv/design')->assertUnauthorized();
        $this->getJson('/api/v1/admin/tv/logs')->assertUnauthorized();
    }

    public function test_me_endpoint_grants_screen_tv_ads_and_all_active_stores_to_super_admin(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/api/v1/me')->assertOk();

        $response->assertJsonPath('data.user.is_super_admin', true);
        $permissions = $response->json('data.permissions');
        $this->assertIsArray($permissions);
        $this->assertContains('screen.tv.ads', $permissions);
        $this->assertCount(2, $response->json('data.stores'));

        // Verify Spatie super_admin role also satisfies User::isSuperAdmin() and Gate::before()
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $roleSuperAdmin = User::create([
            'name' => 'Role Super Admin',
            'email' => 'role.super@staging.local',
            'password' => bcrypt('password'),
            'is_super_admin' => false,
            'active' => true,
        ]);
        $roleSuperAdmin->assignRole('super_admin');

        $this->assertTrue($roleSuperAdmin->isSuperAdmin());
        $this->assertTrue($roleSuperAdmin->isAdminInStore($this->storeAlpha->id));
        $this->assertTrue(Gate::forUser($roleSuperAdmin)->allows('any-ability-name'));
    }

    // =========================================================================
    // 2. Ad CRUD: Image Upload, Video Upload, Chunked Upload, Edit, Delete
    // =========================================================================

    public function test_admin_can_create_image_ad_and_list_it(): void
    {
        Sanctum::actingAs($this->adminUser);

        $file = UploadedFile::fake()->image('banner-pelicula.jpg', 1920, 1080);

        $response = $this->post('/api/v1/admin/tv/ads', [
            'title' => 'Campanha Película 3D',
            'file' => $file,
            'duration_seconds' => 12,
            'target_orientation' => 'horizontal',
            'is_global' => true,
            'status' => 'active',
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Campanha Película 3D')
            ->assertJsonPath('data.media_type', 'image')
            ->assertJsonPath('data.duration_seconds', 12)
            ->assertJsonPath('data.is_global', true);

        $mediaPath = $response->json('data.media_path');
        Storage::disk('public')->assertExists($mediaPath);

        $this->assertDatabaseHas('tv_logs', [
            'event' => 'ad_created',
            'level' => 'info',
        ]);

        $this->getJson('/api/v1/admin/tv/ads')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Campanha Película 3D')
            ->assertJsonPath('data.0.media_exists', true);
    }

    public function test_admin_can_create_video_ad_directly(): void
    {
        Sanctum::actingAs($this->adminUser);

        $video = UploadedFile::fake()->create('promo.mp4', 512, 'video/mp4');

        $response = $this->post('/api/v1/admin/tv/ads', [
            'title' => 'Vídeo Institucional',
            'file' => $video,
            'duration_seconds' => 25,
            'is_global' => true,
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.media_type', 'video')
            ->assertJsonPath('data.duration_seconds', 25);

        Storage::disk('public')->assertExists($response->json('data.media_path'));
    }

    public function test_admin_can_upload_video_in_chunks_and_register_ad(): void
    {
        Sanctum::actingAs($this->adminUser);

        $uploadId = 'up_test_' . uniqid();
        $chunk0Content = str_repeat('A', 2048);
        $chunk1Content = str_repeat('B', 2048);

        $chunk0 = UploadedFile::fake()->createWithContent('chunk0.mp4', $chunk0Content);
        $chunk1 = UploadedFile::fake()->createWithContent('chunk1.mp4', $chunk1Content);

        // Part 1 of 2
        $res0 = $this->post('/api/v1/admin/tv/ads/upload-chunk', [
            'upload_id' => $uploadId,
            'chunk_index' => 0,
            'total_chunks' => 2,
            'filename' => 'campanha-natal.mp4',
            'file' => $chunk0,
        ], ['Accept' => 'application/json']);

        $res0->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('completed', false)
            ->assertJsonPath('received_chunk', 0);

        // Part 2 of 2 (triggers assembly)
        $res1 = $this->post('/api/v1/admin/tv/ads/upload-chunk', [
            'upload_id' => $uploadId,
            'chunk_index' => 1,
            'total_chunks' => 2,
            'filename' => 'campanha-natal.mp4',
            'file' => $chunk1,
        ], ['Accept' => 'application/json']);

        $res1->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('completed', true)
            ->assertJsonPath('media_type', 'video')
            ->assertJsonPath('size', 4096);

        $assembledPath = storage_path('app/public/' . $res1->json('media_path'));
        $tempDir = storage_path("app/temp/chunks/{$uploadId}");
        $this->cleanupPaths[] = $assembledPath;
        $this->cleanupPaths[] = $tempDir;
        $this->assertFileExists($assembledPath);
        $this->assertSame($chunk0Content . $chunk1Content, file_get_contents($assembledPath));
        $this->assertFileDoesNotExist("{$tempDir}/chunk_0");
        $this->assertFileDoesNotExist("{$tempDir}/chunk_1");

        // Create ad referencing chunked upload result
        $createRes = $this->postJson('/api/v1/admin/tv/ads', [
            'title' => 'Campanha Chunked',
            'media_url' => $res1->json('media_url'),
            'media_path' => $res1->json('media_path'),
            'media_type' => $res1->json('media_type'),
            'duration_seconds' => 30,
            'is_global' => true,
        ]);

        $createRes->assertCreated()
            ->assertJsonPath('data.title', 'Campanha Chunked')
            ->assertJsonPath('data.media_type', 'video');
    }

    public function test_store_ad_returns_422_when_neither_file_nor_media_url_provided(): void
    {
        Sanctum::actingAs($this->adminUser);

        $this->postJson('/api/v1/admin/tv/ads', [
            'title' => 'Anúncio Sem Mídia',
        ])->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_admin_can_update_and_delete_ad(): void
    {
        Sanctum::actingAs($this->adminUser);

        $ad = TvAd::create([
            'title' => 'Original Title',
            'media_type' => 'image',
            'media_url' => 'http://127.0.0.1:8000/storage/tv-ads/orig.jpg',
            'media_path' => 'tv-ads/orig.jpg',
            'duration_seconds' => 10,
            'is_global' => true,
            'status' => 'active',
        ]);
        Storage::disk('public')->put('tv-ads/orig.jpg', 'fake-image');

        $updateRes = $this->putJson("/api/v1/admin/tv/ads/{$ad->id}", [
            'title' => 'Updated Title',
            'duration_seconds' => 20,
            'status' => 'inactive',
            'is_global' => false,
            'store_ids' => [$this->storeAlpha->id],
        ]);

        $updateRes->assertOk()
            ->assertJsonPath('data.title', 'Updated Title')
            ->assertJsonPath('data.duration_seconds', 20)
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.is_global', false)
            ->assertJsonCount(1, 'data.target_stores');

        $this->deleteJson("/api/v1/admin/tv/ads/{$ad->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('tv_ads', ['id' => $ad->id]);
        Storage::disk('public')->assertMissing('tv-ads/orig.jpg');
    }

    // =========================================================================
    // 3. Store Distribution, Store Isolation, Empty & Inactive Playlist Filters
    // =========================================================================

    public function test_playlist_respects_global_targeting_store_isolation_status_and_schedule(): void
    {
        $screenAlpha = TvScreen::create([
            'store_id' => $this->storeAlpha->id,
            'name' => 'TV Centro',
            'slug' => 'loja-centro-teste',
        ]);
        $screenBeta = TvScreen::create([
            'store_id' => $this->storeBeta->id,
            'name' => 'TV Shopping',
            'slug' => 'loja-shopping-teste',
        ]);

        // Empty playlist initially
        $this->getJson("/api/v1/tv/playlist/{$screenAlpha->slug}")
            ->assertOk()
            ->assertJsonPath('data.playlist_count', 0)
            ->assertJsonPath('data.playlist', []);

        // 1. Global active ad
        $globalAd = TvAd::create([
            'title' => 'Global Banner',
            'media_type' => 'image',
            'media_url' => 'http://127.0.0.1:8000/storage/tv-ads/g.jpg',
            'duration_seconds' => 10,
            'is_global' => true,
            'status' => 'active',
            'order_priority' => 1,
        ]);

        // 2. Store Alpha ONLY ad
        $alphaOnlyAd = TvAd::create([
            'title' => 'Oferta Exclusiva Centro',
            'media_type' => 'image',
            'media_url' => 'http://127.0.0.1:8000/storage/tv-ads/a.jpg',
            'duration_seconds' => 15,
            'is_global' => false,
            'status' => 'active',
            'order_priority' => 2,
        ]);
        $alphaOnlyAd->targetStores()->sync([$this->storeAlpha->id]);

        // 3. Inactive ad (must NOT appear)
        TvAd::create([
            'title' => 'Anúncio Pausado',
            'media_type' => 'image',
            'media_url' => 'http://127.0.0.1:8000/storage/tv-ads/p.jpg',
            'duration_seconds' => 10,
            'is_global' => true,
            'status' => 'inactive',
        ]);

        // 4. Future scheduled ad (must NOT appear)
        TvAd::create([
            'title' => 'Anúncio Futuro',
            'media_type' => 'image',
            'media_url' => 'http://127.0.0.1:8000/storage/tv-ads/f.jpg',
            'duration_seconds' => 10,
            'is_global' => true,
            'status' => 'active',
            'starts_at' => now()->addDays(2),
        ]);

        // 5. Expired ad (must NOT appear)
        TvAd::create([
            'title' => 'Anúncio Expirado',
            'media_type' => 'image',
            'media_url' => 'http://127.0.0.1:8000/storage/tv-ads/e.jpg',
            'duration_seconds' => 10,
            'is_global' => true,
            'status' => 'active',
            'ends_at' => now()->subHour(),
        ]);

        // 6. Wrong day of week ad (must NOT appear)
        $otherDay = (now()->dayOfWeek + 1) % 7;
        TvAd::create([
            'title' => 'Anúncio Outro Dia',
            'media_type' => 'image',
            'media_url' => 'http://127.0.0.1:8000/storage/tv-ads/d.jpg',
            'duration_seconds' => 10,
            'is_global' => true,
            'status' => 'active',
            'days_of_week' => [$otherDay],
        ]);

        // Store Alpha sees Global + Alpha Only (2 ads)
        $resAlpha = $this->getJson("/api/v1/tv/playlist/{$screenAlpha->slug}")->assertOk();
        $resAlpha->assertJsonPath('data.playlist_count', 2);
        $alphaIds = collect($resAlpha->json('data.playlist'))->pluck('id')->all();
        $this->assertSame([$globalAd->id, $alphaOnlyAd->id], $alphaIds);

        // Store Beta sees ONLY Global (1 ad) — strict store isolation!
        $resBeta = $this->getJson("/api/v1/tv/playlist/{$screenBeta->slug}")->assertOk();
        $resBeta->assertJsonPath('data.playlist_count', 1);
        $betaIds = collect($resBeta->json('data.playlist'))->pluck('id')->all();
        $this->assertSame([$globalAd->id], $betaIds);

        // Lookup by screen_key, access_token, and numeric store_id fallback all work
        $this->getJson("/api/v1/tv/playlist/{$screenAlpha->screen_key}")
            ->assertOk()
            ->assertJsonPath('data.screen.slug', $screenAlpha->slug);

        $this->getJson("/api/v1/tv/playlist/{$screenAlpha->access_token}")
            ->assertOk()
            ->assertJsonPath('data.screen.store_id', $this->storeAlpha->id);

        $this->getJson("/api/v1/tv/playlist/{$this->storeAlpha->id}")
            ->assertOk()
            ->assertJsonPath('data.screen.store_id', $this->storeAlpha->id)
            ->assertJsonPath('data.playlist_count', 2);
    }

    // =========================================================================
    // 4. Heartbeat, Sessions, Timeout Recovery, Impressions, Errors, Stats & Analytics
    // =========================================================================

    public function test_heartbeat_sessions_impressions_errors_stats_and_analytics_flow(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 10:00:00'));

        $screen = TvScreen::create([
            'store_id' => $this->storeAlpha->id,
            'name' => 'TV Centro',
            'slug' => 'loja-centro-teste',
        ]);

        $ad = TvAd::create([
            'title' => 'Banner Oferta',
            'media_type' => 'image',
            'media_url' => 'http://127.0.0.1:8000/storage/tv-ads/o.jpg',
            'duration_seconds' => 10,
            'is_global' => true,
            'status' => 'active',
        ]);

        // First ping opens session #1
        $this->postJson("/api/v1/tv/ping/{$screen->slug}", [
            'width' => 1920,
            'height' => 1080,
            'current_ad_id' => $ad->id,
            'current_ad_title' => $ad->title,
            'fps' => 60,
            'uptime_seconds' => 120,
            'offline_cached' => false,
        ])->assertOk()->assertJsonPath('status', 'online');

        $screen->refresh();
        $this->assertTrue($screen->is_online);
        $this->assertSame(60, $screen->fps);
        $this->assertSame('Banner Oferta', $screen->current_ad_title);
        $this->assertSame(1, TvScreenSession::count());

        // Ping 2 minutes later extends session #1
        Carbon::setTestNow(Carbon::parse('2026-11-19 10:02:00'));
        $this->postJson("/api/v1/tv/ping/{$screen->slug}", ['uptime_seconds' => 240])->assertOk();
        $this->assertSame(1, TvScreenSession::count());
        $this->assertSame(2, TvScreenSession::first()->duration_minutes);

        // Ping 8 minutes later (> 5min gap) closes session #1 as timeout_disconnected and opens session #2
        Carbon::setTestNow(Carbon::parse('2026-11-19 10:10:00'));
        $this->postJson("/api/v1/tv/ping/{$screen->slug}", ['uptime_seconds' => 30])->assertOk();
        $this->assertSame(2, TvScreenSession::count());
        $closedSession = TvScreenSession::orderBy('id')->first();
        $this->assertSame('timeout_disconnected', $closedSession->ended_reason);
        $this->assertNotNull($closedSession->ended_at);

        // Log impression
        $this->postJson("/api/v1/tv/impression/{$screen->slug}", [
            'tv_ad_id' => $ad->id,
            'duration_played_seconds' => 10,
            'completed_naturally' => true,
            'media_type' => 'image',
        ])->assertOk()->assertJsonPath('success', true);

        // Invalid impression returns 422
        $this->postJson("/api/v1/tv/impression/{$screen->slug}", [
            'tv_ad_id' => 999999,
        ])->assertStatus(422);

        // Log player error
        $this->postJson("/api/v1/tv/error/{$screen->slug}", [
            'message' => 'Erro ao reproduzir vídeo simulado',
            'url' => 'http://127.0.0.1:8000/storage/tv-ads/broken.mp4',
            'type' => 'video',
        ])->assertOk()->assertJsonPath('success', true);

        // Admin telemetry endpoints
        Sanctum::actingAs($this->adminUser);

        $this->getJson('/api/v1/admin/tv/stats')
            ->assertOk()
            ->assertJsonPath('data.active_ads_count', 1)
            ->assertJsonPath('data.total_screens', 1)
            ->assertJsonPath('data.online_screens', 1)
            ->assertJsonPath('data.impressions_today', 1);

        $this->getJson('/api/v1/admin/tv/analytics?store_id=' . $this->storeAlpha->id . '&period=7days')
            ->assertOk()
            ->assertJsonPath('data.summary.total_impressions', 1)
            ->assertJsonCount(7, 'data.weekday_distribution')
            ->assertJsonCount(24, 'data.hourly_distribution')
            ->assertJsonPath('data.ads_performance.0.ad_id', $ad->id);

        $this->getJson('/api/v1/admin/tv/logs?level=error')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.event', 'tv_player_error');
    }

    // =========================================================================
    // 5. Design Studio (Global, Per-Store Override, Reset, Logo Upload)
    // =========================================================================

    public function test_global_and_store_design_templates_and_logo_upload(): void
    {
        Sanctum::actingAs($this->adminUser);

        // Default global template returned
        $this->getJson('/api/v1/admin/tv/design')
            ->assertOk()
            ->assertJsonPath('data.global.clock.enabled', true)
            ->assertJsonPath("data.store_overrides.{$this->storeAlpha->id}.has_custom_template", false);

        // Save global design
        $this->postJson('/api/v1/admin/tv/design/global', [
            'template' => [
                'ticker_banner' => [
                    'enabled' => true,
                    'text' => 'PROMO GLOBAL TESTE',
                ],
            ],
        ])->assertOk()->assertJsonPath('success', true);

        // Save store-specific override for Alpha only
        $this->postJson("/api/v1/admin/tv/design/stores/{$this->storeAlpha->id}", [
            'template' => [
                'store_badge' => [
                    'custom_name' => 'MC Centro VIP',
                ],
            ],
        ])->assertOk()->assertJsonPath('success', true);

        $alphaScreen = TvScreen::where('store_id', $this->storeAlpha->id)->firstOrFail();

        // Alpha playlist receives merged global + store override
        $this->getJson("/api/v1/tv/playlist/{$alphaScreen->slug}")
            ->assertOk()
            ->assertJsonPath('data.design_template.ticker_banner.text', 'PROMO GLOBAL TESTE')
            ->assertJsonPath('data.design_template.store_badge.custom_name', 'MC Centro VIP');

        // Beta playlist receives global without Alpha override
        $this->getJson("/api/v1/tv/playlist/{$this->storeBeta->id}")
            ->assertOk()
            ->assertJsonPath('data.design_template.ticker_banner.text', 'PROMO GLOBAL TESTE')
            ->assertJsonPath('data.design_template.store_badge.custom_name', null);

        // Reset Alpha back to global
        $this->deleteJson("/api/v1/admin/tv/design/stores/{$this->storeAlpha->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->getJson("/api/v1/tv/playlist/{$alphaScreen->slug}")
            ->assertOk()
            ->assertJsonPath('data.design_template.store_badge.custom_name', null);

        // Upload logo
        $logo = UploadedFile::fake()->image('brand-logo.png', 300, 100);
        $logoRes = $this->post('/api/v1/admin/tv/design/upload-logo', [
            'file' => $logo,
        ], ['Accept' => 'application/json']);

        $logoRes->assertOk()
            ->assertJsonPath('success', true);
        Storage::disk('public')->assertExists($logoRes->json('data.path'));
    }

    // =========================================================================
    // 6. Blackout & Remote Commands in Simulated Environment
    // =========================================================================

    public function test_blackout_and_remote_commands_with_mocked_ably(): void
    {
        Sanctum::actingAs($this->adminUser);

        // Screens endpoint auto-creates TvScreen records per store
        $screensRes = $this->getJson('/api/v1/admin/tv/screens')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $screenId = $screensRes->json('data.0.screen_id');
        $slug = $screensRes->json('data.0.slug');

        // Start active session via ping
        $this->postJson("/api/v1/tv/ping/{$slug}", ['uptime_seconds' => 60])->assertOk();
        $this->assertSame(1, TvScreenSession::whereNull('ended_at')->count());

        // Turn off (blackout) closes active session as standby_turned_off
        $this->postJson("/api/v1/admin/tv/screens/{$screenId}/command", ['command' => 'turn_off'])
            ->assertOk()
            ->assertJsonPath('data.is_blackout', true);

        $this->assertSame(0, TvScreenSession::whereNull('ended_at')->count());
        $this->assertSame('standby_turned_off', TvScreenSession::first()->ended_reason);

        // Playlist reflects is_blackout = true
        $this->getJson("/api/v1/tv/playlist/{$slug}")
            ->assertOk()
            ->assertJsonPath('data.screen.is_blackout', true);

        // Turn on restores blackout = false
        $this->postJson("/api/v1/admin/tv/screens/{$screenId}/command", ['command' => 'turn_on'])
            ->assertOk()
            ->assertJsonPath('data.is_blackout', false);

        // Other simulated remote commands succeed and log audit trail
        foreach (['reload', 'next', 'pause', 'play', 'clear_cache', 'toggle_power'] as $cmd) {
            $this->postJson("/api/v1/admin/tv/screens/{$screenId}/command", ['command' => $cmd])
                ->assertOk()
                ->assertJsonPath('success', true);
        }

        // Invalid command returns 422
        $this->postJson("/api/v1/admin/tv/screens/{$screenId}/command", ['command' => 'format_disk'])
            ->assertStatus(422);

        $this->assertSame(8, TvLog::where('event', 'tv_remote_command')->count());
    }
}
