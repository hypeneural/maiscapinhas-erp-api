<?php

declare(strict_types=1);

namespace App\Services\Wheel;

use App\Enums\PlayerStatus;
use App\Enums\SessionStatus;
use App\Enums\SpinStatus;
use App\Models\WheelCampaign;
use App\Models\WheelEvent;
use App\Models\WheelInventory;
use App\Models\WheelPlayer;
use App\Models\WheelPrize;
use App\Models\WheelSegment;
use App\Models\WheelSession;
use App\Models\WheelSpin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SpinService - Core da lógica de giro da roleta.
 * 
 * Responsabilidades:
 * - Lock: apenas 1 giro por vez por sessão
 * - Idempotência: via client_nonce
 * - Sorteio: seleção ponderada de segmento
 * - Estoque: consumo atômico de inventário
 */
class SpinService
{
    // Lock timeout em segundos
    private const LOCK_TIMEOUT = 30;

    // Prefixo para locks
    private const LOCK_PREFIX = 'wheel_spin_lock:';

    /**
     * Solicita um giro para o player.
     * 
     * @param WheelSession $session Sessão ativa
     * @param WheelPlayer $player Jogador verificado
     * @param string|null $clientNonce Nonce para idempotência
     * @return SpinResult Resultado do giro
     * @throws SpinException Se não puder girar
     */
    public function requestSpin(
        WheelSession $session,
        WheelPlayer $player,
        ?string $clientNonce = null
    ): SpinResult {
        // 1. Verificar idempotência
        if ($clientNonce) {
            $existingSpin = WheelSpin::findByNonce($session->id, $clientNonce);
            if ($existingSpin) {
                Log::info('Spin idempotency hit', [
                    'session_id' => $session->id,
                    'nonce' => $clientNonce,
                    'spin_id' => $existingSpin->id,
                ]);
                return $this->buildResult($existingSpin);
            }
        }

        // 2. Validações
        $this->validateSpinRequest($session, $player);

        // 3. Adquirir lock
        $lockKey = self::LOCK_PREFIX . $session->id;
        $lock = Cache::lock($lockKey, self::LOCK_TIMEOUT);

        if (!$lock->get()) {
            throw new SpinException('Outro giro em andamento. Aguarde.', 'SPIN_LOCKED');
        }

        try {
            // 4. Verificar novamente após lock (double-check)
            if (WheelSpin::hasActiveSpinForSession($session->id)) {
                throw new SpinException('Já existe um giro em andamento.', 'SPIN_ACTIVE');
            }

            // 5. Executar giro em transação
            return DB::transaction(function () use ($session, $player, $clientNonce) {
                // Sortear segmento
                $segment = $this->selectSegment($session->campaign);

                if (!$segment) {
                    throw new SpinException('Nenhum prêmio disponível no momento.', 'NO_SEGMENTS');
                }

                // Consumir inventário (se aplicável)
                $inventoryConsumed = $this->consumeInventory($session->campaign, $segment->prize);

                // Gerar código do prêmio (se aplicável)
                $prizeCode = $segment->prize->requiresRedeem()
                    ? $segment->prize->generateCode()
                    : null;

                // Criar spin
                $spin = WheelSpin::create([
                    'spin_key' => WheelSpin::generateSpinKey(),
                    'session_id' => $session->id,
                    'player_id' => $player->id,
                    'campaign_id' => $session->campaign_id,
                    'screen_id' => $session->screen_id,
                    'status' => SpinStatus::PROCESSING,
                    'client_nonce' => $clientNonce,
                    'segment_id' => $segment->id,
                    'prize_id' => $segment->prize_id,
                    'prize_code' => $prizeCode,
                    'requested_at' => now(),
                ]);

                // Calcular ângulo final
                $segments = $session->campaign->activeSegments()->orderBy('sort_order')->get();
                $targetIndex = $segments->search(fn($s) => $s->id === $segment->id);
                $finalAngle = $spin->calculateFinalAngle($segments->toArray(), $targetIndex);

                $spin->final_angle = $finalAngle;
                $spin->save();

                // Atualizar statuses
                $player->status = PlayerStatus::SPINNING;
                $player->save();

                $session->status = SessionStatus::SPINNING;
                $session->save();

                // Logar evento
                WheelEvent::log(
                    WheelEvent::TYPE_SPIN_STARTED,
                    [
                        'spin_key' => $spin->spin_key,
                        'player_key' => $player->player_key,
                        'segment_id' => $segment->id,
                        'prize_id' => $segment->prize_id,
                    ],
                    $session->screen_id,
                    $session->campaign_id
                );

                return $this->buildResult($spin, $segment, $targetIndex);
            });

        } finally {
            $lock->release();
        }
    }

    /**
     * Confirma conclusão do giro (ACK da animação).
     */
    public function acknowledgeSpin(
        WheelSpin $spin,
        array $telemetry = []
    ): WheelSpin {
        if ($spin->status->isTerminal()) {
            return $spin;
        }

        $spin->status = SpinStatus::COMPLETED;
        $spin->completed_at = now();

        if (!empty($telemetry)) {
            $spin->updateTelemetry($telemetry);
        }

        $spin->save();

        // Atualizar player status
        $player = $spin->player;
        $player->status = $spin->prize->requiresRedeem()
            ? PlayerStatus::WON
            : PlayerStatus::LOST;
        $player->save();

        // Atualizar session status
        $session = $spin->session;
        $session->status = SessionStatus::COMPLETED;
        $session->save();

        // Logar evento
        WheelEvent::log(
            WheelEvent::TYPE_SPIN_COMPLETED,
            [
                'spin_key' => $spin->spin_key,
                'prize_key' => $spin->prize->prize_key,
                'prize_type' => $spin->prize->type->value,
                'animation_duration_ms' => $telemetry['animation_duration_ms'] ?? null,
            ],
            $spin->screen_id,
            $spin->campaign_id
        );

        // Log de prêmio ganho (se resgatável)
        if ($spin->prize->requiresRedeem()) {
            WheelEvent::logPrizeWon(
                $spin->campaign,
                $spin->prize,
                $spin->screen_id,
                ['spin_key' => $spin->spin_key, 'prize_code' => $spin->prize_code]
            );
        }

        return $spin;
    }

    /**
     * Valida se o giro pode ser iniciado.
     */
    private function validateSpinRequest(WheelSession $session, WheelPlayer $player): void
    {
        // Sessão deve estar ativa
        if (!in_array($session->status, [SessionStatus::WAITING, SessionStatus::ACTIVE])) {
            throw new SpinException(
                "Sessão não está ativa (status: {$session->status->value})",
                'SESSION_NOT_ACTIVE'
            );
        }

        // Sessão não pode estar expirada
        if ($session->isExpired()) {
            throw new SpinException('Sessão expirou.', 'SESSION_EXPIRED');
        }

        // Player deve estar verificado
        if (!$player->canSpin()) {
            throw new SpinException(
                "Jogador não pode girar (status: {$player->status->value})",
                'PLAYER_CANNOT_SPIN'
            );
        }

        // Player deve pertencer à sessão
        if ($player->session_id !== $session->id) {
            throw new SpinException('Jogador não pertence a esta sessão.', 'PLAYER_SESSION_MISMATCH');
        }

        // Campanha deve estar ativa
        $campaign = $session->campaign;
        if ($campaign->status->value !== 'active') {
            throw new SpinException('Campanha não está ativa.', 'CAMPAIGN_NOT_ACTIVE');
        }

        // Campanha deve estar no período
        if (!$campaign->isWithinPeriod()) {
            throw new SpinException('Campanha fora do período de validade.', 'CAMPAIGN_OUT_OF_PERIOD');
        }
    }

    /**
     * Seleciona um segmento baseado no peso de probabilidade.
     * Considera apenas segmentos com estoque disponível.
     */
    private function selectSegment(WheelCampaign $campaign): ?WheelSegment
    {
        // Carregar segmentos ativos com prize
        $segments = $campaign->activeSegments()
            ->with('prize')
            ->orderBy('sort_order')
            ->get();

        if ($segments->isEmpty()) {
            return null;
        }

        // Filtrar por estoque disponível
        $availableSegments = $segments->filter(function ($segment) use ($campaign) {
            // Prêmios que não consomem estoque sempre estão disponíveis
            if (!$segment->prize->consumesInventory()) {
                return true;
            }

            // Verificar estoque
            $inventory = WheelInventory::where('campaign_id', $campaign->id)
                ->where('prize_id', $segment->prize_id)
                ->first();

            if (!$inventory) {
                return true; // Sem limite = disponível
            }

            // Auto-reset diário se necessário
            $inventory->autoResetIfNeeded();

            return $inventory->hasStock();
        });

        if ($availableSegments->isEmpty()) {
            Log::warning('No segments with available stock', ['campaign_id' => $campaign->id]);

            // Fallback: retornar um segmento "nothing" ou "try_again" se existir
            $fallback = $segments->first(fn($s) => !$s->prize->consumesInventory());
            return $fallback;
        }

        // Sorteio ponderado
        $totalWeight = $availableSegments->sum('probability_weight');
        $random = mt_rand(1, $totalWeight);
        $cumulative = 0;

        foreach ($availableSegments as $segment) {
            $cumulative += $segment->probability_weight;
            if ($random <= $cumulative) {
                return $segment;
            }
        }

        // Fallback (não deveria chegar aqui)
        return $availableSegments->first();
    }

    /**
     * Consome uma unidade do estoque (atômico).
     */
    private function consumeInventory(WheelCampaign $campaign, WheelPrize $prize): bool
    {
        if (!$prize->consumesInventory()) {
            return true;
        }

        $inventory = WheelInventory::where('campaign_id', $campaign->id)
            ->where('prize_id', $prize->id)
            ->lockForUpdate()
            ->first();

        if (!$inventory) {
            return true; // Sem controle de estoque
        }

        if (!$inventory->hasStock()) {
            throw new SpinException('Estoque esgotado para este prêmio.', 'OUT_OF_STOCK');
        }

        $consumed = $inventory->consume();

        // Logar se estoque zerou
        if ($inventory->remaining === 0 || $inventory->daily_remaining === 0) {
            WheelEvent::log(
                WheelEvent::TYPE_INVENTORY_DEPLETED,
                [
                    'prize_key' => $prize->prize_key,
                    'remaining' => $inventory->remaining,
                    'daily_remaining' => $inventory->daily_remaining,
                ],
                null,
                $campaign->id
            );
        }

        return $consumed;
    }

    /**
     * Constrói o resultado do giro.
     */
    private function buildResult(
        WheelSpin $spin,
        ?WheelSegment $segment = null,
        ?int $targetIndex = null
    ): SpinResult {
        $segment = $segment ?? $spin->segment;

        return new SpinResult(
            spin: $spin,
            segment: $segment,
            targetIndex: $targetIndex,
            finalAngle: $spin->final_angle,
            prizeCode: $spin->prize_code,
        );
    }
}
