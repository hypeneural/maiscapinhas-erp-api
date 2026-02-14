<?php

namespace Tests\Feature\Api\V1;

use App\Models\Store;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PdvMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // Set env vars before booting app to force SQLite
        putenv('DB_CONNECTION=sqlite');
        // Use :memory: database
        putenv('DB_DATABASE=:memory:');

        parent::setUp();

        // Setup basic data
        $this->store = Store::factory()->create();
        $this->user = User::factory()->create();
        $this->user->stores()->attach($this->store->id, ['role' => 'admin', 'active' => true]);

        // Mock PDV Data
        $this->seedPdvData();
    }

    private function seedPdvData()
    {
        // Ensure tables exist? RefreshDatabase handles migrations.
        // If migrations fail on SQLite, test fails. 
        // Assuming migrations work.

        $storePdvId = 999;

        DB::table('pdv_user_mappings')->insert([
            'store_pdv_id' => $storePdvId,
            'pdv_user_id' => 101, // PDV ID
            'user_id' => $this->user->id, // Internal ID
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleId = DB::table('pdv_vendas')->insertGetId([
            'store_id' => $this->store->id,
            'store_pdv_id' => $storePdvId,
            'id_operacao' => 12345,
            'canal' => 'LJ',
            'data_hora' => Carbon::today(),
            'total' => 100.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pdv_venda_itens')->insert([
            'store_pdv_id' => $storePdvId,
            'id_operacao' => 12345,
            'canal' => 'LJ',
            'line_no' => 1,
            'id_produto' => 55,
            'qtd' => 1,
            'total' => 100.00,
            'vendedor_pdv_id' => 101,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pdv_turnos')->insert([
            'store_id' => $this->store->id,
            'store_pdv_id' => $storePdvId,
            'id_turno' => 888,
            'sequencial' => 1,
            'operador_pdv_id' => 101,
            'data_hora_inicio' => Carbon::now()->subHours(2),
            'fechado' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_dashboard_vendedor_returns_pdv_data()
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/dashboard/vendedor?store_id={$this->store->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.my_sales.total', 100.00)
            ->assertJsonPath('data.my_shifts.0.status', 'open');
    }

    public function test_dashboard_admin_returns_pdv_data()
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/dashboard/admin?month=" . Carbon::today()->format('Y-m'));

        $response->assertStatus(200)
            ->assertJsonPath('data.total_sales.total', 100.00)
            ->assertJsonPath('data.top_sellers.0.name', $this->user->name);
    }

    public function test_ranking_endpoint_returns_pdv_data()
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/reports/ranking?period=" . Carbon::today()->format('Y-m') . "&store_id=" . $this->store->id);

        $response->assertStatus(200)
            ->assertJsonPath('ranking.0.seller.name', $this->user->name)
            ->assertJsonPath('ranking.0.total_sold', 100.00);
    }

    public function test_store_performance_endpoint_returns_pdv_data()
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/reports/store-performance?store_id={$this->store->id}&month=" . Carbon::today()->format('Y-m'));

        $response->assertStatus(200)
            ->assertJsonPath('sales.current_amount', 100.00);
    }

    public function test_gamification_service_returns_pdv_data()
    {
        $service = app(\App\Domains\Reports\Services\SellerGamificationService::class);
        $gamification = $service->getBonusGamification($this->store->id, $this->user->id, Carbon::today());

        $this->assertEquals(100.00, $gamification['current_amount']);
    }
}
