<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wheel;

use App\Enums\PlayerStatus;
use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Models\WheelEvent;
use App\Models\WheelPhoneChallenge;
use App\Models\WheelPlayer;
use App\Models\WheelSession;
use App\Models\WheelSpin;
use App\Services\Wheel\AblyPublisher;
use App\Services\Wheel\SpinException;
use App\Services\Wheel\SpinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * MobileRuntimeController
 * 
 * Endpoints runtime para Mobile (celular do cliente na loja).
 */
class MobileRuntimeController extends Controller
{
    public function __construct(
        private SpinService $spinService,
        private AblyPublisher $ably
    ) {
    }

    /**
     * POST /sessions/{sessionKey}/join
     * 
     * Entrar na fila via QR Code.
     */
    public function join(Request $request, string $sessionKey): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|min:10|max:20',
        ]);

        $session = WheelSession::where('session_key', $sessionKey)
            ->with('campaign')
            ->first();

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Sessão não encontrada.',
                'code' => 'SESSION_NOT_FOUND',
            ], 404);
        }

        // Verificar se pode entrar
        if ($session->isExpired()) {
            return response()->json([
                'success' => false,
                'message' => 'QR Code expirado.',
                'code' => 'SESSION_EXPIRED',
            ], 400);
        }

        if (!$session->canJoin()) {
            return response()->json([
                'success' => false,
                'message' => 'Sessão não aceita novos participantes.',
                'code' => 'SESSION_FULL',
            ], 400);
        }

        $phone = $request->input('phone');
        $campaign = $session->campaign;

        // Verificar limite por telefone
        $phoneLimit = $campaign->getSetting('per_phone_limit', '1_per_campaign');

        if ($phoneLimit === '1_per_campaign') {
            if (WheelPlayer::hasParticipatedInCampaign($phone, $campaign->id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Você já participou desta campanha.',
                    'code' => 'PHONE_LIMIT_REACHED',
                ], 400);
            }
        }

        // Verificar se já está na sessão
        $existingPlayer = WheelPlayer::where('session_id', $session->id)
            ->byPhone($phone)
            ->active()
            ->first();

        if ($existingPlayer) {
            // Retornar player existente
            $token = $existingPlayer->generateAccessToken();

            return response()->json([
                'success' => true,
                'data' => [
                    'player' => $this->formatPlayer($existingPlayer),
                    'access_token' => $token,
                    'realtime' => [
                        'auth_url' => '/api/v1/wheel/realtime/auth',
                        'channel' => "wheel:player:{$existingPlayer->player_key}",
                    ],
                ],
                'message' => 'Bem-vindo de volta!',
            ]);
        }

        // Criar player
        $player = WheelPlayer::createForSession(
            $session,
            $phone,
            $request->ip(),
            $request->userAgent()
        );

        // Gerar token de acesso
        $accessToken = $player->generateAccessToken();

        // Logar evento
        WheelEvent::log('player_joined', [
            'player_key' => $player->player_key,
            'queue_position' => $player->queue_position,
        ], $session->screen_id, $session->campaign_id);

        // Atualizar sessão para ativa se primeiro player
        if ($session->status === SessionStatus::WAITING) {
            $session->status = SessionStatus::ACTIVE;
            $session->save();
        }

        // Publicar para TV
        $this->ably->publishToScreen($session->screen->screen_key, 'player_connected', [
            'player_key' => $player->player_key,
            'phone_masked' => $player->phone_masked,
            'queue_position' => $player->queue_position,
            'status' => $player->status->value,
        ]);

        // Publicar fila atualizada
        $this->publishQueueUpdate($session);

        return response()->json([
            'success' => true,
            'data' => [
                'player' => $this->formatPlayer($player),
                'access_token' => $accessToken,
                'session' => [
                    'session_key' => $session->session_key,
                    'campaign_name' => $campaign->name,
                ],
                'realtime' => [
                    'auth_url' => '/api/v1/wheel/realtime/auth',
                    'channel' => "wheel:player:{$player->player_key}",
                ],
                'requires_verification' => (bool) $campaign->getSetting('require_phone_verification', true),
            ],
        ], 201);
    }

    /**
     * POST /sessions/{sessionKey}/verify
     * 
     * Verificar telefone via código WhatsApp.
     */
    public function verify(Request $request, string $sessionKey): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $player = $this->getAuthenticatedPlayer($request);
        if ($player instanceof JsonResponse) {
            return $player;
        }

        // Buscar challenge ativo
        $challenge = WheelPhoneChallenge::findActiveForPlayer($player->id);

        if (!$challenge) {
            return response()->json([
                'success' => false,
                'message' => 'Nenhum código de verificação pendente.',
                'code' => 'NO_CHALLENGE',
            ], 400);
        }

        // Tentar verificar
        $verified = $challenge->verify($request->input('code'));

        if (!$verified) {
            $attemptsLeft = $challenge->max_attempts - $challenge->attempts;

            return response()->json([
                'success' => false,
                'message' => "Código incorreto. {$attemptsLeft} tentativa(s) restante(s).",
                'code' => 'INVALID_CODE',
                'attempts_left' => $attemptsLeft,
            ], 400);
        }

        // Logar
        WheelEvent::log('player_verified', [
            'player_key' => $player->player_key,
        ], $player->session->screen_id, $player->session->campaign_id);

        // Publicar para TV
        $this->ably->publishToScreen(
            $player->session->screen->screen_key,
            'player_verified',
            [
                'player_key' => $player->player_key,
                'phone_masked' => $player->phone_masked,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Telefone verificado com sucesso!',
            'data' => [
                'player' => $this->formatPlayer($player->fresh()),
                'can_spin' => $player->fresh()->canSpin(),
            ],
        ]);
    }

    /**
     * POST /sessions/{sessionKey}/request-code
     * 
     * Solicitar código de verificação via WhatsApp.
     */
    public function requestCode(Request $request, string $sessionKey): JsonResponse
    {
        $player = $this->getAuthenticatedPlayer($request);
        if ($player instanceof JsonResponse) {
            return $player;
        }

        if ($player->phone_verified) {
            return response()->json([
                'success' => false,
                'message' => 'Telefone já verificado.',
            ], 400);
        }

        // Verificar se já tem challenge ativo
        $existingChallenge = WheelPhoneChallenge::findActiveForPlayer($player->id);

        if ($existingChallenge && !$existingChallenge->isExpired()) {
            return response()->json([
                'success' => true,
                'message' => 'Código já enviado. Verifique seu WhatsApp.',
                'data' => [
                    'expires_in_seconds' => max(0, now()->diffInSeconds($existingChallenge->expires_at, false)),
                ],
            ]);
        }

        // Criar novo challenge
        $challenge = WheelPhoneChallenge::createForPlayer($player);

        // Atualizar status
        $player->status = PlayerStatus::VERIFYING;
        $player->save();

        // TODO: Integrar com Evolution API para enviar via WhatsApp
        // Por enquanto, apenas simula o envio
        $challenge->markSent(['simulated' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Código enviado para seu WhatsApp.',
            'data' => [
                'expires_in_seconds' => 300,
                // Em DEV, retornar o código para facilitar testes
                'code' => app()->environment('local') ? $challenge->code : null,
            ],
        ]);
    }

    /**
     * POST /mobile/spins
     * 
     * Solicitar giro (com idempotência via client_nonce).
     */
    public function spin(Request $request): JsonResponse
    {
        $request->validate([
            'client_nonce' => 'nullable|string|max:100',
        ]);

        $player = $this->getAuthenticatedPlayer($request);
        if ($player instanceof JsonResponse) {
            return $player;
        }

        $session = $player->session;

        try {
            $result = $this->spinService->requestSpin(
                $session,
                $player,
                $request->input('client_nonce')
            );

            // Publicar spin_started para TV (COM target)
            $this->ably->publishToScreen(
                $session->screen->screen_key,
                'spin_started',
                $result->forScreen()
            );

            // Publicar spin_started para Mobile (SEM target)
            $this->ably->publishToPlayer(
                $player->player_key,
                'spin_started',
                $result->forMobile()
            );

            return response()->json([
                'success' => true,
                'data' => $result->forMobile(),
            ]);

        } catch (SpinException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => $e->code,
            ], 400);
        }
    }

    /**
     * POST /spins/{spinKey}/ack
     * 
     * ACK da animação (telemetria).
     */
    public function ackSpin(Request $request, string $spinKey): JsonResponse
    {
        $request->validate([
            'animation_duration_ms' => 'nullable|integer',
            'fps' => 'nullable|numeric',
            'latency_ms' => 'nullable|integer',
        ]);

        $spin = WheelSpin::where('spin_key', $spinKey)->first();

        if (!$spin) {
            return response()->json([
                'success' => false,
                'message' => 'Spin não encontrado.',
            ], 404);
        }

        // Verificar autenticação
        $player = $this->getAuthenticatedPlayer($request);
        if ($player instanceof JsonResponse) {
            return $player;
        }

        if ($spin->player_id !== $player->id) {
            return response()->json([
                'success' => false,
                'message' => 'Spin não pertence a este jogador.',
            ], 403);
        }

        // Atualizar com telemetria e completar
        $telemetry = $request->only(['animation_duration_ms', 'fps', 'latency_ms']);
        $spin = $this->spinService->acknowledgeSpin($spin, $telemetry);

        // Obter resultado
        $result = [
            'spin_key' => $spin->spin_key,
            'won' => $spin->prize->requiresRedeem(),
            'prize' => [
                'prize_key' => $spin->prize->prize_key,
                'name' => $spin->prize->name,
                'type' => $spin->prize->type->value,
                'icon' => $spin->prize->icon ?? $spin->prize->type->icon(),
            ],
            'prize_code' => $spin->prize_code,
        ];

        // Publicar resultado
        $this->ably->publishToScreen(
            $spin->screen->screen_key,
            'spin_result',
            $result
        );

        $this->ably->publishToPlayer(
            $player->player_key,
            'spin_result',
            $result
        );

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * GET /mobile/state
     * 
     * Estado do player.
     */
    public function state(Request $request): JsonResponse
    {
        $player = $this->getAuthenticatedPlayer($request);
        if ($player instanceof JsonResponse) {
            return $player;
        }

        $session = $player->session;
        $lastSpin = $player->spins()->latest()->first();

        return response()->json([
            'success' => true,
            'data' => [
                'player' => $this->formatPlayer($player),
                'session' => [
                    'session_key' => $session->session_key,
                    'status' => $session->status->value,
                    'campaign_name' => $session->campaign->name,
                ],
                'last_spin' => $lastSpin ? [
                    'spin_key' => $lastSpin->spin_key,
                    'status' => $lastSpin->status->value,
                    'prize_code' => $lastSpin->prize_code,
                ] : null,
                'server_time' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * Autentica player via Bearer token.
     */
    private function getAuthenticatedPlayer(Request $request): WheelPlayer|JsonResponse
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Token não fornecido.',
            ], 401);
        }

        // Buscar player ativo com este token
        $players = WheelPlayer::active()->get();

        foreach ($players as $player) {
            if ($player->verifyAccessToken($token)) {
                return $player;
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Token inválido.',
        ], 401);
    }

    private function formatPlayer(WheelPlayer $player): array
    {
        return [
            'player_key' => $player->player_key,
            'phone_masked' => $player->phone_masked,
            'status' => $player->status->value,
            'queue_position' => $player->queue_position,
            'phone_verified' => $player->phone_verified,
            'can_spin' => $player->canSpin(),
        ];
    }

    private function publishQueueUpdate(WheelSession $session): void
    {
        $queue = $session->activePlayers()->get()->map(fn($p) => [
            'player_key' => $p->player_key,
            'phone_masked' => $p->phone_masked,
            'status' => $p->status->value,
            'queue_position' => $p->queue_position,
        ])->toArray();

        $this->ably->publishToScreen($session->screen->screen_key, 'queue_updated', [
            'queue' => $queue,
            'count' => count($queue),
        ]);
    }
}
