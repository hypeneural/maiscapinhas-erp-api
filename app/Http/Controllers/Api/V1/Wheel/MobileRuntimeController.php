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
use App\Models\WheelSessionPlayer;
use App\Models\WheelSpin;
use App\Services\Wheel\AblyPublisher;
use App\Services\Wheel\SpinException;
use App\Services\Wheel\SpinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * MobileRuntimeController
 * 
 * Endpoints runtime para Mobile (celular do cliente na loja).
 * Refatorado para usar WheelPlayer (pessoa) + WheelSessionPlayer (participação).
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
     * Busca/cria Player pelo WhatsApp e cria SessionPlayer para esta sessão.
     */
    public function join(Request $request, string $sessionKey): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|min:10|max:20',
            'name' => 'nullable|string|max:100',
            'terms_accepted' => 'required|boolean',
            'device_fingerprint' => 'nullable|string|max:100',
        ]);

        if (!$request->boolean('terms_accepted')) {
            return response()->json([
                'success' => false,
                'message' => 'É necessário aceitar os termos.',
                'code' => 'TERMS_NOT_ACCEPTED',
            ], 400);
        }

        $session = WheelSession::where('session_key', $sessionKey)
            ->with('campaign', 'screen.store')
            ->first();

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Sessão não encontrada.',
                'code' => 'SESSION_NOT_FOUND',
            ], 404);
        }

        if ($session->isExpired()) {
            return response()->json([
                'success' => false,
                'message' => 'QR Code expirado.',
                'code' => 'SESSION_EXPIRED',
            ], 410);
        }

        if (!$session->canJoin()) {
            return response()->json([
                'success' => false,
                'message' => 'Sessão não aceita novos participantes.',
                'code' => 'QUEUE_FULL',
            ], 422);
        }

        $phone = $request->input('phone');
        $name = $request->input('name');
        $campaign = $session->campaign;

        // Verificar limite por telefone/campanha
        $player = WheelPlayer::findOrCreateByPhone($phone, $name);

        if ($campaign->getSetting('per_phone_limit') === '1_per_campaign') {
            if ($player->hasParticipatedInCampaign($campaign->id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Você já participou desta campanha.',
                    'code' => 'PHONE_LIMIT_REACHED',
                ], 429);
            }
        }

        // Verificar se já está nesta sessão
        $existingSessionPlayer = WheelSessionPlayer::where('session_id', $session->id)
            ->where('player_id', $player->id)
            ->active()
            ->first();

        if ($existingSessionPlayer) {
            // Retornar participação existente
            $token = $existingSessionPlayer->generateAccessToken();

            return response()->json([
                'success' => true,
                'data' => [
                    'session_player' => $existingSessionPlayer->toMobileArray(),
                    'player' => $player->toPublicArray(),
                    'access_token' => $token,
                    'realtime' => [
                        'auth_url' => '/api/v1/wheel/realtime/auth',
                        'channel' => "wheel:player:{$player->player_key}",
                    ],
                ],
                'message' => 'Bem-vindo de volta!',
            ]);
        }

        // Próxima posição na fila
        $queuePosition = WheelSessionPlayer::where('session_id', $session->id)
            ->active()
            ->max('queue_position') ?? -1;
        $queuePosition += 1;

        // Criar participação nesta sessão
        $sessionPlayer = WheelSessionPlayer::create([
            'session_id' => $session->id,
            'player_id' => $player->id,
            'status' => PlayerStatus::PENDING,
            'queue_position' => $queuePosition,
            'terms_version' => $campaign->terms_version,
            'terms_accepted_at' => now(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device_info' => [
                'fingerprint' => $request->input('device_fingerprint'),
            ],
        ]);

        // Gerar token de acesso
        $accessToken = $sessionPlayer->generateAccessToken();

        // Logar evento
        WheelEvent::log('player_joined', [
            'player_key' => $player->player_key,
            'session_player_key' => $sessionPlayer->session_player_key,
            'queue_position' => $queuePosition,
        ], $session->screen_id, $campaign->id);

        // Atualizar sessão para ativa se primeiro player
        if ($session->status === SessionStatus::WAITING) {
            $session->status = SessionStatus::ACTIVE;
            $session->save();
        }

        // Publicar para TV
        $this->ably->publishToScreen($session->screen->screen_key, 'player_connected', [
            'player_key' => $player->player_key,
            'phone_masked' => $player->getPhoneMasked(),
            'name' => $player->full_name,
            'queue_position' => $queuePosition,
            'status' => $sessionPlayer->status->value,
        ]);

        // Publicar fila atualizada
        $this->publishQueueUpdate($session);

        return response()->json([
            'success' => true,
            'data' => [
                'session_player' => $sessionPlayer->toMobileArray(),
                'player' => $player->toPublicArray(),
                'access_token' => $accessToken,
                'session' => [
                    'session_key' => $session->session_key,
                    'store_name' => $session->screen->store->name ?? 'Loja',
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
     * POST /sessions/{sessionKey}/request-code
     * 
     * Solicitar código de verificação via WhatsApp.
     */
    public function requestCode(Request $request, string $sessionKey): JsonResponse
    {
        $sessionPlayer = $this->getAuthenticatedSessionPlayer($request);
        if ($sessionPlayer instanceof JsonResponse) {
            return $sessionPlayer;
        }

        $player = $sessionPlayer->player;

        if ($player->whatsapp_confirmed_at) {
            // Já verificado, atualizar status da participação
            $sessionPlayer->status = PlayerStatus::VERIFIED;
            $sessionPlayer->save();

            return response()->json([
                'success' => true,
                'message' => 'Telefone já verificado.',
                'data' => ['already_verified' => true],
            ]);
        }

        // Verificar challenge existente
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
        $sessionPlayer->status = PlayerStatus::VERIFYING;
        $sessionPlayer->save();

        // TODO: Integrar com Evolution API
        $challenge->markSent(['simulated' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Código enviado para seu WhatsApp.',
            'data' => [
                'expires_in_seconds' => 300,
                'code' => app()->environment('local') ? $challenge->code : null,
            ],
        ]);
    }

    /**
     * POST /sessions/{sessionKey}/verify
     * 
     * Verificar telefone via código WhatsApp.
     */
    public function verify(Request $request, string $sessionKey): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|min:4|max:6',
        ]);

        $sessionPlayer = $this->getAuthenticatedSessionPlayer($request);
        if ($sessionPlayer instanceof JsonResponse) {
            return $sessionPlayer;
        }

        $player = $sessionPlayer->player;

        // Buscar challenge ativo
        $challenge = WheelPhoneChallenge::findActiveForPlayer($player->id);

        if (!$challenge) {
            return response()->json([
                'success' => false,
                'message' => 'Nenhum código de verificação pendente.',
                'code' => 'NO_CHALLENGE',
            ], 400);
        }

        // Verificar código
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

        // Marcar player como verificado (globalmente)
        $player->markWhatsAppConfirmed();

        // Atualizar participação
        $sessionPlayer->status = PlayerStatus::VERIFIED;
        $sessionPlayer->save();

        // Logar
        WheelEvent::log('player_verified', [
            'player_key' => $player->player_key,
        ], $sessionPlayer->session->screen_id, $sessionPlayer->session->campaign_id);

        // Publicar para TV
        $this->ably->publishToScreen(
            $sessionPlayer->session->screen->screen_key,
            'player_verified',
            [
                'player_key' => $player->player_key,
                'phone_masked' => $player->getPhoneMasked(),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Telefone verificado com sucesso!',
            'data' => [
                'session_player' => $sessionPlayer->fresh()->toMobileArray(),
                'can_spin' => $sessionPlayer->fresh()->canSpin(),
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

        $sessionPlayer = $this->getAuthenticatedSessionPlayer($request);
        if ($sessionPlayer instanceof JsonResponse) {
            return $sessionPlayer;
        }

        $session = $sessionPlayer->session;
        $player = $sessionPlayer->player;

        try {
            $result = $this->spinService->requestSpinForSessionPlayer(
                $sessionPlayer,
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

        $spin = WheelSpin::where('spin_key', $spinKey)->with('sessionPlayer.player', 'prize')->first();

        if (!$spin) {
            return response()->json([
                'success' => false,
                'message' => 'Spin não encontrado.',
            ], 404);
        }

        // Verificar autenticação
        $sessionPlayer = $this->getAuthenticatedSessionPlayer($request);
        if ($sessionPlayer instanceof JsonResponse) {
            return $sessionPlayer;
        }

        if ($spin->session_player_id !== $sessionPlayer->id) {
            return response()->json([
                'success' => false,
                'message' => 'Spin não pertence a este jogador.',
            ], 403);
        }

        // Atualizar com telemetria
        $telemetry = $request->only(['animation_duration_ms', 'fps', 'latency_ms']);
        $spin = $this->spinService->acknowledgeSpin($spin, $telemetry);

        $result = [
            'spin_key' => $spin->spin_key,
            'is_winner' => $spin->prize->requiresRedeem(),
            'prize' => [
                'prize_key' => $spin->prize->prize_key,
                'name' => $spin->prize->name,
                'type' => $spin->prize->type->value,
                'icon' => $spin->prize->icon ?? $spin->prize->type->icon(),
                'code' => $spin->prize_code,
                'redeem_instructions' => $spin->prize->redeem_instructions,
            ],
            'spins_remaining' => $sessionPlayer->fresh()->getSpinsAvailable(),
        ];

        // Publicar resultado
        $this->ably->publishToScreen(
            $spin->screen->screen_key,
            'spin_result',
            $result
        );

        $this->ably->publishToPlayer(
            $sessionPlayer->player->player_key,
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
        $sessionPlayer = $this->getAuthenticatedSessionPlayer($request);
        if ($sessionPlayer instanceof JsonResponse) {
            return $sessionPlayer;
        }

        $session = $sessionPlayer->session->load('screen.store', 'campaign');
        $player = $sessionPlayer->player;
        $lastSpin = $sessionPlayer->spins()->with('prize')->latest()->first();

        return response()->json([
            'success' => true,
            'data' => [
                'state' => $sessionPlayer->getState(),
                'server_time' => now()->toISOString(),
                'player' => $player->toPublicArray(),
                'session_player' => $sessionPlayer->toMobileArray(),
                'session' => [
                    'session_key' => $session->session_key,
                    'status' => $session->status->value,
                    'expires_at' => $session->expires_at?->toISOString(),
                ],
                'screen' => [
                    'screen_key' => $session->screen->screen_key,
                    'store_name' => $session->screen->store->name ?? 'Loja',
                ],
                'queue' => [
                    'position' => $sessionPlayer->queue_position,
                    'total' => WheelSessionPlayer::where('session_id', $session->id)->active()->count(),
                    'eta_seconds' => $sessionPlayer->queue_position * 15, // ~15s por jogador
                ],
                'current_spin' => $lastSpin && !$lastSpin->isCompleted() ? [
                    'spin_key' => $lastSpin->spin_key,
                    'status' => $lastSpin->status->value,
                    'started_at' => $lastSpin->started_at?->toISOString(),
                ] : null,
                'last_result' => $lastSpin && $lastSpin->isCompleted() ? [
                    'spin_key' => $lastSpin->spin_key,
                    'prize' => [
                        'name' => $lastSpin->prize->name,
                        'type' => $lastSpin->prize->type->value,
                        'code' => $lastSpin->prize_code,
                    ],
                ] : null,
                'ui_hints' => [
                    'focus_mode_min_ms' => 2500,
                    'spin_duration_ms' => $session->campaign->getSetting('spin_duration_ms', 8000),
                ],
            ],
        ]);
    }

    /**
     * POST /mobile/address
     * 
     * Atualizar endereço do player via CEP (ViaCEP).
     */
    public function updateAddress(Request $request): JsonResponse
    {
        $request->validate([
            'cep' => 'required|string|size:8',
            'number' => 'nullable|string|max:20',
            'complement' => 'nullable|string|max:100',
        ]);

        $sessionPlayer = $this->getAuthenticatedSessionPlayer($request);
        if ($sessionPlayer instanceof JsonResponse) {
            return $sessionPlayer;
        }

        $player = $sessionPlayer->player;
        $cep = preg_replace('/\D/', '', $request->input('cep'));

        // Buscar no ViaCEP
        try {
            $response = Http::timeout(5)->get("https://viacep.com.br/ws/{$cep}/json/");

            if ($response->successful()) {
                $data = $response->json();

                if (isset($data['erro'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'CEP não encontrado.',
                        'code' => 'CEP_NOT_FOUND',
                    ], 404);
                }

                // Atualizar player
                $player->updateAddressFromViaCep($data);
                $player->number = $request->input('number');
                $player->complement = $request->input('complement');
                $player->save();

                return response()->json([
                    'success' => true,
                    'message' => 'Endereço atualizado com sucesso.',
                    'data' => [
                        'cep' => $player->cep,
                        'street' => $player->street,
                        'number' => $player->number,
                        'complement' => $player->complement,
                        'neighborhood' => $player->neighborhood,
                        'city' => $player->city,
                        'state' => $player->state,
                    ],
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao consultar CEP. Tente novamente.',
                'code' => 'VIACEP_ERROR',
            ], 500);
        }

        return response()->json([
            'success' => false,
            'message' => 'Erro ao consultar CEP.',
        ], 500);
    }

    /**
     * Autentica SessionPlayer via Bearer token.
     */
    private function getAuthenticatedSessionPlayer(Request $request): WheelSessionPlayer|JsonResponse
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Token não fornecido.',
            ], 401);
        }

        // Buscar session_player ativo
        $sessionPlayers = WheelSessionPlayer::active()
            ->with('player', 'session.screen', 'session.campaign')
            ->get();

        foreach ($sessionPlayers as $sp) {
            if ($sp->verifyAccessToken($token)) {
                return $sp;
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Token inválido ou sessão expirada.',
        ], 401);
    }

    private function publishQueueUpdate(WheelSession $session): void
    {
        $queue = WheelSessionPlayer::where('session_id', $session->id)
            ->active()
            ->with('player')
            ->orderBy('queue_position')
            ->get()
            ->map(fn($sp) => [
                'player_key' => $sp->player->player_key,
                'phone_masked' => $sp->player->getPhoneMasked(),
                'status' => $sp->status->value,
                'queue_position' => $sp->queue_position,
            ])->toArray();

        $this->ably->publishToScreen($session->screen->screen_key, 'queue_updated', [
            'queue' => $queue,
            'count' => count($queue),
            'current_player' => $queue[0] ?? null,
        ]);
    }
}
