<?php

namespace Database\Seeders;

use App\Enums\PrizeType;
use App\Enums\PlayerStatus;
use App\Enums\SessionStatus;
use App\Enums\SpinStatus;
use App\Models\Store;
use App\Models\WheelCampaign;
use App\Models\WheelEvent;
use App\Models\WheelInventory;
use App\Models\WheelPlayer;
use App\Models\WheelPrize;
use App\Models\WheelPrizeRule;
use App\Models\WheelPrizeState;
use App\Models\WheelScreen;
use App\Models\WheelSegment;
use App\Models\WheelSession;
use App\Models\WheelSessionPlayer;
use App\Models\WheelSpin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * Seeder para popular dados COMPLETOS do módulo Wheel com gameplay simulado.
 * 
 * Execute: php artisan db:seed --class=WheelGameplaySeeder
 * 
 * Cria:
 * - 30+ players com nomes brasileiros
 * - Múltiplas sessions por screen
 * - Session players associando players às sessions
 * - Spins com distribuição baseada em probabilidade
 * - Consumo de inventário
 * - Prize states atualizados
 */
class WheelGameplaySeeder extends Seeder
{
    private array $brazilianNames = [
        'João Silva',
        'Maria Santos',
        'Pedro Oliveira',
        'Ana Costa',
        'Carlos Souza',
        'Juliana Ferreira',
        'Lucas Pereira',
        'Mariana Rodrigues',
        'Gabriel Almeida',
        'Beatriz Lima',
        'Rafael Martins',
        'Isabela Gomes',
        'Thiago Ribeiro',
        'Larissa Carvalho',
        'Felipe Nascimento',
        'Camila Dias',
        'Bruno Monteiro',
        'Amanda Barbosa',
        'Diego Fernandes',
        'Patricia Correia',
        'Vinícius Araújo',
        'Letícia Nunes',
        'Rodrigo Cardoso',
        'Fernanda Moura',
        'André Teixeira',
        'Carolina Pinto',
        'Marcelo Castro',
        'Renata Medeiros',
        'Gustavo Freitas',
        'Daniela Vieira',
    ];

    private array $ddds = ['11', '21', '31', '41', '48', '51', '61', '71', '81', '85'];

    private int $spinSequence = 0;

    public function run(): void
    {
        $this->command->info('🎰 Populando dados completos do módulo Wheel com gameplay...');
        $this->command->newLine();

        // 1. Verificar pré-requisitos
        $stores = Store::where('active', true)->limit(3)->get();
        if ($stores->isEmpty()) {
            $this->command->error('❌ Nenhuma loja encontrada. Execute WheelTestDataSeeder primeiro.');
            return;
        }

        // 2. Buscar dados existentes (criados pelo WheelTestDataSeeder)
        $screens = WheelScreen::whereIn('store_id', $stores->pluck('id'))->get();
        $campaign = WheelCampaign::where('status', 'active')->first();
        $prizes = WheelPrize::all();
        $segments = WheelSegment::where('campaign_id', $campaign?->id)->get();

        if ($screens->isEmpty() || !$campaign || $prizes->isEmpty() || $segments->isEmpty()) {
            $this->command->error('❌ Dados base não encontrados. Execute WheelTestDataSeeder primeiro.');
            $this->command->info('   php artisan db:seed --class=WheelTestDataSeeder');
            return;
        }

        $this->command->info("📊 Base encontrada:");
        $this->command->info("   - {$screens->count()} screens");
        $this->command->info("   - Campanha: {$campaign->name}");
        $this->command->info("   - {$prizes->count()} prêmios");
        $this->command->info("   - {$segments->count()} segmentos");
        $this->command->newLine();

        // 3. Criar players
        $this->command->info('👥 Criando players...');
        $players = $this->createPlayers();
        $this->command->info("   ✅ {$players->count()} players criados/encontrados");

        // 4. Criar sessions históricas e ativas
        $this->command->info('📱 Criando sessions...');
        $allSessions = collect();
        foreach ($screens as $screen) {
            $sessions = $this->createSessionsForScreen($screen, $campaign);
            $allSessions = $allSessions->merge($sessions);
        }
        $this->command->info("   ✅ {$allSessions->count()} sessions criadas");

        // 5. Simular gameplay (session_players + spins)
        $this->command->info('🎮 Simulando gameplay...');
        $stats = $this->simulateGameplay($allSessions, $players, $segments, $campaign);
        $this->command->info("   ✅ {$stats['session_players']} participações");
        $this->command->info("   ✅ {$stats['spins']} spins");
        $this->command->info("   ✅ {$stats['wins']} prêmios ganhos");
        $this->command->info("   ✅ {$stats['redeemed']} resgates");

        // 6. Atualizar inventário
        $this->command->info('📦 Verificando inventário...');
        $this->displayInventory($campaign);

        // 7. Resumo final
        $this->command->newLine();
        $this->command->info('🎉 Dados de gameplay criados com sucesso!');
        $this->displaySummary($players, $allSessions, $stats);
    }

    private function createPlayers(): \Illuminate\Support\Collection
    {
        $players = collect();

        foreach ($this->brazilianNames as $index => $name) {
            $ddd = $this->ddds[$index % count($this->ddds)];
            $phone = "+55{$ddd}9" . str_pad((string) rand(10000000, 99999999), 8, '0', STR_PAD_LEFT);

            $existing = WheelPlayer::where('phone_hash', WheelPlayer::hashPhone($phone))->first();

            if ($existing) {
                $players->push($existing);
                continue;
            }

            $player = WheelPlayer::create([
                'player_key' => 'player_' . Str::random(12),
                'whatsapp_e164' => $phone,
                'phone_masked' => WheelPlayer::maskPhone($phone),
                'phone_hash' => WheelPlayer::hashPhone($phone),
                'full_name' => $name,
                'whatsapp_confirmed_at' => now()->subDays(rand(1, 30)),
                'phone_verified' => true,
                'last_seen_at' => now()->subHours(rand(1, 168)),
                // Endereço aleatório
                'city' => $this->getRandomCity(),
                'state' => $this->getRandomState(),
                'cep' => $this->getRandomCEP(),
            ]);

            $players->push($player);
        }

        return $players;
    }

    private function createSessionsForScreen(WheelScreen $screen, WheelCampaign $campaign): \Illuminate\Support\Collection
    {
        $sessions = collect();
        $now = now();

        // Criar sessions históricas (últimos 7 dias)
        for ($day = 6; $day >= 0; $day--) {
            $date = $now->copy()->subDays($day);
            $sessionsThisDay = rand(3, 6);

            for ($i = 0; $i < $sessionsThisDay; $i++) {
                $startTime = $date->copy()->setTime(rand(9, 20), rand(0, 59));

                $status = $day === 0 && $i === $sessionsThisDay - 1
                    ? SessionStatus::WAITING
                    : (rand(1, 10) > 2 ? SessionStatus::COMPLETED : SessionStatus::EXPIRED);

                $session = WheelSession::create([
                    'session_key' => 'sess_' . Str::random(12),
                    'screen_id' => $screen->id,
                    'campaign_id' => $campaign->id,
                    'status' => $status,
                    'qr_code_data' => "https://app.maiscapinhas.com.br/wheel/join/sess_" . Str::random(12),
                    'expires_at' => $startTime->copy()->addMinutes(rand(5, 15)),
                    'metadata' => ['created_by' => 'seeder'],
                    'created_at' => $startTime,
                    'updated_at' => $startTime->copy()->addMinutes(rand(3, 20)),
                ]);

                $sessions->push($session);
            }
        }

        return $sessions;
    }

    private function simulateGameplay(
        \Illuminate\Support\Collection $sessions,
        \Illuminate\Support\Collection $players,
        \Illuminate\Support\Collection $segments,
        WheelCampaign $campaign
    ): array {
        $stats = [
            'session_players' => 0,
            'spins' => 0,
            'wins' => 0,
            'redeemed' => 0,
        ];

        $playerIndex = 0;
        $segmentWeights = $segments->mapWithKeys(fn($s) => [$s->id => $s->probability_weight])->toArray();
        $totalWeight = array_sum($segmentWeights);

        foreach ($sessions as $session) {
            // Skip sessions waiting/cancelled
            if ($session->status === SessionStatus::WAITING || $session->status === SessionStatus::CANCELLED) {
                continue;
            }

            // 1-4 players por session
            $playersInSession = rand(1, 4);
            $sessionPlayers = [];

            for ($i = 0; $i < $playersInSession; $i++) {
                $player = $players[$playerIndex % $players->count()];
                $playerIndex++;

                // Verificar se já existe session_player
                $existingSessionPlayer = WheelSessionPlayer::where('session_id', $session->id)
                    ->where('player_id', $player->id)
                    ->first();

                if ($existingSessionPlayer) {
                    $sessionPlayers[] = $existingSessionPlayer;
                    continue;
                }

                $sessionPlayer = WheelSessionPlayer::create([
                    'session_player_key' => 'sp_' . Str::random(12),
                    'session_id' => $session->id,
                    'player_id' => $player->id,
                    // PlayerStatus enum values: pending, verifying, verified, spinning, won, lost, left, timeout
                    'status' => $session->status === SessionStatus::COMPLETED
                        ? PlayerStatus::WON
                        : PlayerStatus::TIMEOUT,
                    'queue_position' => 0,
                    'access_token_hash' => bcrypt(Str::random(32)),
                    'device_info' => ['platform' => 'mobile', 'browser' => 'chrome'],
                    'terms_version' => '1.0',
                    'terms_accepted_at' => $session->created_at,
                    'ip_address' => $this->getRandomIP(),
                    'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X)',
                    'joined_at' => $session->created_at->copy()->addSeconds(rand(5, 30)),
                    'left_at' => $session->status === SessionStatus::COMPLETED
                        ? $session->updated_at
                        : null,
                    'created_at' => $session->created_at,
                    'updated_at' => $session->updated_at,
                ]);

                $sessionPlayers[] = $sessionPlayer;
                $stats['session_players']++;

                // Criar evento player_joined para analytics
                WheelEvent::create([
                    'event_id' => (string) Str::uuid(),
                    'type' => WheelEvent::TYPE_PLAYER_JOINED,
                    'screen_id' => $session->screen_id,
                    'campaign_id' => $campaign->id,
                    'payload' => [
                        'player_key' => $player->player_key,
                        'phone_hash' => $player->phone_hash,
                    ],
                    'created_at' => $sessionPlayer->joined_at,
                ]);
            }

            // Criar spins para cada session_player que completou
            foreach ($sessionPlayers as $sessionPlayer) {
                if ($session->status !== SessionStatus::COMPLETED) {
                    continue;
                }

                // 1 spin por player (para campanhas com limite)
                $this->spinSequence++;

                // Selecionar segmento baseado em peso
                $selectedSegment = $this->selectSegmentByWeight($segments, $segmentWeights, $totalWeight);
                $prize = WheelPrize::find($selectedSegment->prize_id);

                // Verificar se é prêmio real (não nothing/try_again)
                $isWin = $prize && in_array($prize->type, [PrizeType::PRODUCT, PrizeType::COUPON]);

                // Verificar inventário
                if ($isWin) {
                    $inventory = WheelInventory::where('campaign_id', $campaign->id)
                        ->where('prize_id', $prize->id)
                        ->first();

                    if ($inventory && $inventory->remaining <= 0) {
                        // Estoque zerado, selecionar "nada" ou "tente novamente"
                        $fallbackSegment = $segments->first(
                            fn($s) =>
                            $s->prize && in_array($s->prize->type, [PrizeType::NOTHING, PrizeType::TRY_AGAIN])
                        );
                        if ($fallbackSegment) {
                            $selectedSegment = $fallbackSegment;
                            $prize = WheelPrize::find($selectedSegment->prize_id);
                            $isWin = false;
                        }
                    }
                }

                // Calcular ângulo final
                $segmentCount = $segments->count();
                $segmentAngle = 360 / max($segmentCount, 1);
                $segmentIndex = $segments->search(fn($s) => $s->id === $selectedSegment->id);
                $rotations = rand(5, 8);
                $finalAngle = ($rotations * 360) + ($segmentIndex * $segmentAngle) + ($segmentAngle / 2) + rand(-10, 10);

                $prizeCode = null;
                if ($isWin && $prize->code_prefix) {
                    $prizeCode = $prize->code_prefix . '-' . strtoupper(Str::random(6));
                }

                $spinCreatedAt = $sessionPlayer->joined_at->copy()->addSeconds(rand(10, 60));
                $spinCompletedAt = $spinCreatedAt->copy()->addSeconds(rand(8, 12));

                $spin = WheelSpin::create([
                    'spin_key' => 'spin_' . Str::random(12),
                    'session_id' => $session->id,
                    'player_id' => $sessionPlayer->player_id,
                    'session_player_id' => $sessionPlayer->id,
                    'campaign_id' => $campaign->id,
                    'screen_id' => $session->screen_id,
                    'status' => SpinStatus::COMPLETED,
                    'client_nonce' => Str::uuid()->toString(),
                    'segment_id' => $selectedSegment->id,
                    'prize_id' => $prize?->id,
                    'prize_code' => $prizeCode,
                    'final_angle' => round($finalAngle, 2),
                    'requested_at' => $spinCreatedAt,
                    'started_at' => $spinCreatedAt->copy()->addMilliseconds(rand(100, 500)),
                    'completed_at' => $spinCompletedAt,
                    'animation_duration_ms' => rand(7500, 9000),
                    'telemetry' => [
                        'fps_avg' => rand(55, 60),
                        'latency_ms' => rand(50, 200),
                    ],
                    'redeemed' => $isWin && rand(1, 100) <= 65, // 65% resgatam
                    'redeemed_at' => null,
                    'redeemed_by' => null,
                    'created_at' => $spinCreatedAt,
                    'updated_at' => $spinCompletedAt,
                ]);

                if ($spin->redeemed) {
                    $spin->redeemed_at = $spinCompletedAt->copy()->addMinutes(rand(5, 60));
                    $spin->redeemed_by = 'caixa_' . rand(1, 5);
                    $spin->save();
                    $stats['redeemed']++;
                }

                $stats['spins']++;

                // Criar eventos para analytics
                WheelEvent::create([
                    'event_id' => (string) Str::uuid(),
                    'type' => WheelEvent::TYPE_SPIN_COMPLETED,
                    'screen_id' => $session->screen_id,
                    'campaign_id' => $campaign->id,
                    'payload' => [
                        'spin_key' => $spin->spin_key,
                        'player_key' => $sessionPlayer->player->player_key,
                        'segment_label' => $selectedSegment->label,
                        'prize_key' => $prize?->prize_key,
                    ],
                    'created_at' => $spinCompletedAt,
                ]);

                if ($isWin) {
                    $stats['wins']++;

                    // Criar evento prize_won para analytics
                    WheelEvent::create([
                        'event_id' => (string) Str::uuid(),
                        'type' => WheelEvent::TYPE_PRIZE_WON,
                        'screen_id' => $session->screen_id,
                        'campaign_id' => $campaign->id,
                        'payload' => [
                            'prize_key' => $prize->prize_key,
                            'prize_name' => $prize->name,
                            'prize_type' => $prize->type->value,
                            'prize_code' => $prizeCode,
                            'player_key' => $sessionPlayer->player->player_key,
                        ],
                        'created_at' => $spinCompletedAt,
                    ]);

                    // Consumir inventário
                    $inventory = WheelInventory::where('campaign_id', $campaign->id)
                        ->where('prize_id', $prize->id)
                        ->first();

                    if ($inventory) {
                        $inventory->remaining = max(0, $inventory->remaining - 1);
                        if ($inventory->daily_remaining !== null) {
                            $inventory->daily_remaining = max(0, $inventory->daily_remaining - 1);
                        }
                        $inventory->save();
                    }

                    // Atualizar prize state
                    $prizeState = WheelPrizeState::getOrCreate($campaign->id, $prize->id, null);
                    $prizeState->recordAward($this->spinSequence);
                }

                // Atualizar status do session_player
                $sessionPlayer->status = $isWin ? PlayerStatus::WON : PlayerStatus::LOST;
                $sessionPlayer->save();
            }
        }

        return $stats;
    }

    private function selectSegmentByWeight(
        \Illuminate\Support\Collection $segments,
        array $weights,
        int $totalWeight
    ): WheelSegment {
        $random = rand(1, $totalWeight);
        $cumulative = 0;

        foreach ($segments as $segment) {
            $cumulative += $weights[$segment->id] ?? 0;
            if ($random <= $cumulative) {
                return $segment;
            }
        }

        return $segments->last();
    }

    private function displayInventory(WheelCampaign $campaign): void
    {
        $inventory = WheelInventory::where('campaign_id', $campaign->id)
            ->with('prize')
            ->get();

        $tableData = $inventory->map(fn($i) => [
            $i->prize?->name ?? 'N/A',
            $i->total_limit ?? '∞',
            $i->remaining ?? '∞',
            $i->daily_limit ?? '∞',
            $i->daily_remaining ?? '∞',
        ])->toArray();

        $this->command->table(
            ['Prêmio', 'Total', 'Restante', 'Diário', 'Diário Rest.'],
            $tableData
        );
    }

    private function displaySummary(
        \Illuminate\Support\Collection $players,
        \Illuminate\Support\Collection $sessions,
        array $stats
    ): void {
        $this->command->table(
            ['Recurso', 'Quantidade'],
            [
                ['Players', $players->count()],
                ['Sessions', $sessions->count()],
                ['Participações', $stats['session_players']],
                ['Spins', $stats['spins']],
                ['Prêmios Ganhos', $stats['wins']],
                ['Resgates', $stats['redeemed']],
                [
                    'Taxa de Resgate',
                    $stats['wins'] > 0
                    ? round(($stats['redeemed'] / $stats['wins']) * 100, 1) . '%'
                    : '0%'
                ],
            ]
        );
    }

    private function getRandomCity(): string
    {
        $cities = [
            'São Paulo',
            'Rio de Janeiro',
            'Belo Horizonte',
            'Curitiba',
            'Porto Alegre',
            'Florianópolis',
            'Salvador',
            'Recife',
            'Fortaleza',
            'Brasília',
            'Manaus',
            'Goiânia',
        ];
        return $cities[array_rand($cities)];
    }

    private function getRandomState(): string
    {
        $states = ['SP', 'RJ', 'MG', 'PR', 'RS', 'SC', 'BA', 'PE', 'CE', 'DF', 'AM', 'GO'];
        return $states[array_rand($states)];
    }

    private function getRandomCEP(): string
    {
        return str_pad((string) rand(10000, 99999), 5, '0', STR_PAD_LEFT) . '-' .
            str_pad((string) rand(0, 999), 3, '0', STR_PAD_LEFT);
    }

    private function getRandomIP(): string
    {
        return rand(100, 200) . '.' . rand(0, 255) . '.' . rand(0, 255) . '.' . rand(1, 254);
    }
}
