<?php

namespace Database\Seeders;

use App\Enums\PrizeType;
use App\Models\Store;
use App\Models\WheelCampaign;
use App\Models\WheelInventory;
use App\Models\WheelPrize;
use App\Models\WheelPrizeRule;
use App\Models\WheelScreen;
use App\Models\WheelSegment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeder para popular dados de teste do módulo Wheel.
 * 
 * Execute: php artisan db:seed --class=WheelTestDataSeeder
 * 
 * Schema de produção (19/01/2026):
 * - wheel_screens: id, screen_key, store_id, name, secret_token_hash, status, device_info, last_seen_at
 * - wheel_prizes: id, prize_key, name, type, icon, description, redeem_instructions, code_prefix, active
 * - wheel_campaigns: id, campaign_key, name, status, starts_at, ends_at, terms_version, settings
 * - wheel_segments: id, campaign_id, prize_id, label, color, sort_order, probability_weight, active
 * - wheel_inventory: id, campaign_id, prize_id, quantity, remaining, daily_limit, daily_remaining, last_reset_at
 */
class WheelTestDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🎰 Populando dados de teste do módulo Wheel...');

        // 1. Usar lojas existentes
        $stores = Store::where('active', true)->limit(3)->get();
        $this->command->info("✅ {$stores->count()} lojas encontradas");

        if ($stores->isEmpty()) {
            $this->command->error('❌ Nenhuma loja encontrada. Crie lojas primeiro.');
            return;
        }

        // 2. Criar screens (ou usar existentes)
        $screens = $this->createScreens($stores);
        $this->command->info("✅ {$screens->count()} telas prontas");

        // 3. Criar prêmios (ou usar existentes)
        $prizes = $this->createPrizes();
        $this->command->info("✅ {$prizes->count()} prêmios prontos");

        // 4. Criar campanha
        $campaign = $this->createCampaign();
        $this->command->info("✅ Campanha '{$campaign->name}' criada");

        // 5. Criar segmentos
        $segments = $this->createSegments($campaign, $prizes);
        $this->command->info("✅ {$segments->count()} segmentos criados");

        // 6. Criar inventário
        $this->createInventory($campaign, $prizes);
        $this->command->info("✅ Inventário configurado");

        // 7. Criar regras de prêmios
        $this->createPrizeRules($campaign, $prizes);
        $this->command->info("✅ Regras de prêmios configuradas");

        // 8. Associar campanha às telas (M:N via wheel_screen_campaign)
        $this->associateCampaignToScreens($campaign, $screens);
        $this->command->info("✅ Campanha associada às {$screens->count()} telas");

        // 9. Criar players de teste
        $players = $this->createPlayers();
        $this->command->info("✅ {$players->count()} players de teste criados");

        $this->command->newLine();
        $this->command->info('🎉 Dados de teste criados com sucesso!');
        $this->command->table(
            ['Recurso', 'Quantidade'],
            [
                ['Lojas', $stores->count()],
                ['Telas (TVs)', $screens->count()],
                ['Prêmios', $prizes->count()],
                ['Campanhas', 1],
                ['Segmentos', $segments->count()],
                ['Players', $players->count()],
            ]
        );
    }

    private function createScreens($stores)
    {
        $screens = collect();

        foreach ($stores as $store) {
            // Verificar se já existe screen para esta loja
            $existing = WheelScreen::where('store_id', $store->id)->first();

            if ($existing) {
                $screens->push($existing);
                continue;
            }

            $screen = WheelScreen::create([
                'screen_key' => 'screen_' . Str::random(8),
                'name' => "TV {$store->name}",
                'store_id' => $store->id,
                'secret_token_hash' => hash('sha256', 'secret_' . $store->id . '_' . time()),
                'status' => 'active',
                'device_info' => json_encode([
                    'location' => 'Entrada principal',
                ]),
            ]);
            $screens->push($screen);
        }

        return $screens;
    }

    private function createPrizes()
    {
        $prizesData = [
            ['name' => 'Película Premium', 'type' => PrizeType::PRODUCT, 'icon' => '🎁', 'prefix' => 'PEL'],
            ['name' => 'Cupom 30% OFF', 'type' => PrizeType::COUPON, 'icon' => '💰', 'prefix' => 'C30'],
            ['name' => 'Cupom 20% OFF', 'type' => PrizeType::COUPON, 'icon' => '🏷️', 'prefix' => 'C20'],
            ['name' => 'Cupom 10% OFF', 'type' => PrizeType::COUPON, 'icon' => '🎟️', 'prefix' => 'C10'],
            ['name' => 'Chaveiro Exclusivo', 'type' => PrizeType::PRODUCT, 'icon' => '🔑', 'prefix' => 'CHV'],
            ['name' => 'Frete Grátis', 'type' => PrizeType::COUPON, 'icon' => '🚚', 'prefix' => 'FRT'],
            ['name' => 'Tente Novamente', 'type' => PrizeType::TRY_AGAIN, 'icon' => '🔄', 'prefix' => null],
            ['name' => 'Nada', 'type' => PrizeType::NOTHING, 'icon' => '❌', 'prefix' => null],
        ];

        $prizes = collect();

        foreach ($prizesData as $data) {
            // Verificar se já existe
            $existing = WheelPrize::where('name', $data['name'])->first();

            if ($existing) {
                $prizes->push($existing);
                continue;
            }

            $prize = WheelPrize::create([
                'prize_key' => 'prize_' . Str::random(8),
                'name' => $data['name'],
                'description' => 'Prêmio da roleta: ' . $data['name'],
                'type' => $data['type'],
                'icon' => $data['icon'],
                'redeem_instructions' => $data['prefix'] ? 'Apresente o código no caixa.' : null,
                'code_prefix' => $data['prefix'],
                'active' => true,
            ]);
            $prizes->push($prize);
        }

        return $prizes;
    }

    private function createCampaign()
    {
        // Verificar se já existe
        $existing = WheelCampaign::where('campaign_key', 'camp_verao2026')->first();
        if ($existing) {
            return $existing;
        }

        return WheelCampaign::create([
            'campaign_key' => 'camp_verao2026',
            'name' => 'Campanha Verão 2026',
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addMonths(2),
            'terms_version' => '1.0',
            'settings' => [
                'spin_duration_ms' => 8000,
                'per_phone_limit' => '1_per_day',
            ],
        ]);
    }

    private function createSegments($campaign, $prizes)
    {
        // Limpar segmentos existentes da campanha
        WheelSegment::where('campaign_id', $campaign->id)->delete();

        $config = [
            ['index' => 0, 'weight' => 3, 'color' => '#FFD700', 'label' => '🎁 Película'],
            ['index' => 1, 'weight' => 5, 'color' => '#FF6B6B', 'label' => '💰 30% OFF'],
            ['index' => 2, 'weight' => 10, 'color' => '#4ECDC4', 'label' => '🏷️ 20% OFF'],
            ['index' => 3, 'weight' => 15, 'color' => '#45B7D1', 'label' => '🎟️ 10% OFF'],
            ['index' => 4, 'weight' => 7, 'color' => '#96CEB4', 'label' => '🔑 Chaveiro'],
            ['index' => 5, 'weight' => 10, 'color' => '#FFEAA7', 'label' => '🚚 Frete'],
            ['index' => 6, 'weight' => 25, 'color' => '#A8A8A8', 'label' => '🔄 Tente'],
            ['index' => 7, 'weight' => 25, 'color' => '#666666', 'label' => '❌ Nada'],
        ];

        $segments = collect();

        foreach ($config as $order => $c) {
            $segment = WheelSegment::create([
                'campaign_id' => $campaign->id,
                'prize_id' => $prizes[$c['index']]->id,
                'label' => $c['label'],
                'color' => $c['color'],
                'sort_order' => $order,
                'probability_weight' => $c['weight'],
                'active' => true,
            ]);
            $segments->push($segment);
        }

        return $segments;
    }

    private function createInventory($campaign, $prizes)
    {
        // Schema: id, campaign_id, prize_id, total_limit, remaining, daily_limit, daily_remaining, reset_daily_at
        // Limpar inventário existente
        WheelInventory::where('campaign_id', $campaign->id)->delete();

        $config = [
            0 => ['qty' => 50, 'daily' => 10],   // Película
            1 => ['qty' => 100, 'daily' => 20],  // Cupom 30%
            2 => ['qty' => 200, 'daily' => 40],  // Cupom 20%
            3 => ['qty' => 500, 'daily' => 100], // Cupom 10%
            4 => ['qty' => 100, 'daily' => 20],  // Chaveiro
            5 => ['qty' => 150, 'daily' => 30],  // Frete
        ];

        foreach ($config as $index => $c) {
            WheelInventory::create([
                'campaign_id' => $campaign->id,
                'prize_id' => $prizes[$index]->id,
                'total_limit' => $c['qty'],
                'remaining' => $c['qty'],
                'daily_limit' => $c['daily'],
                'daily_remaining' => $c['daily'],
                'reset_daily_at' => now(),
            ]);
        }
    }

    private function createPrizeRules($campaign, $prizes)
    {
        // Limpar regras existentes
        WheelPrizeRule::where('campaign_id', $campaign->id)->delete();

        $rules = [
            0 => ['gap' => 15, 'cd' => 600, 'hour' => 3, 'day' => 10, 'pacing' => true],   // Película
            1 => ['gap' => 10, 'cd' => 300, 'hour' => 5, 'day' => 20, 'pacing' => true],   // Cupom 30%
            2 => ['gap' => 5, 'cd' => 120, 'hour' => 10, 'day' => 40, 'pacing' => false],  // Cupom 20%
            4 => ['gap' => 8, 'cd' => 300, 'hour' => 5, 'day' => 20, 'pacing' => true],    // Chaveiro
            5 => ['gap' => 5, 'cd' => 180, 'hour' => 8, 'day' => 30, 'pacing' => false],   // Frete
        ];

        foreach ($rules as $index => $r) {
            WheelPrizeRule::create([
                'campaign_id' => $campaign->id,
                'prize_id' => $prizes[$index]->id,
                'min_gap_spins' => $r['gap'],
                'cooldown_seconds' => $r['cd'],
                'max_per_hour' => $r['hour'],
                'max_per_day' => $r['day'],
                'cooldown_scope' => 'campaign',
                'pacing_enabled' => $r['pacing'],
                'pacing_buffer' => 1.25,
                'priority' => ($index + 1) * 10,
                'active' => true,
            ]);
        }
    }

    private function associateCampaignToScreens($campaign, $screens)
    {
        // Associar campanha às telas via tabela pivot wheel_screen_campaign
        foreach ($screens as $screen) {
            // Verifica se já existe associação
            if (!$screen->campaigns()->where('campaign_id', $campaign->id)->exists()) {
                $screen->campaigns()->attach($campaign->id, ['status' => 'active']);
            }
        }
    }

    private function createPlayers()
    {
        $playersData = [
            ['phone' => '+5548999990001', 'name' => 'João Silva'],
            ['phone' => '+5548999990002', 'name' => 'Maria Santos'],
            ['phone' => '+5548999990003', 'name' => 'Pedro Oliveira'],
            ['phone' => '+5548999990004', 'name' => 'Ana Costa'],
            ['phone' => '+5548999990005', 'name' => 'Carlos Souza'],
        ];

        $players = collect();

        foreach ($playersData as $data) {
            // Verificar se player já existe
            $existing = \App\Models\WheelPlayer::where('whatsapp_e164', $data['phone'])->first();

            if ($existing) {
                $players->push($existing);
                continue;
            }

            $player = \App\Models\WheelPlayer::create([
                'player_key' => 'player_' . Str::random(8),
                'whatsapp_e164' => $data['phone'],
                'phone_masked' => '****-' . substr($data['phone'], -4),
                'phone_hash' => hash('sha256', $data['phone']),
                'full_name' => $data['name'],
                'status' => 'verified',
                'whatsapp_confirmed_at' => now(),
                'last_seen_at' => now(),
            ]);
            $players->push($player);
        }

        return $players;
    }
}
