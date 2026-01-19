<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wheel;

use App\Enums\ScreenStatus;
use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Models\WheelCampaign;
use App\Models\WheelEvent;
use App\Models\WheelScreen;
use App\Models\WheelSession;
use App\Services\Wheel\AblyPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * ScreenRuntimeController
 * 
 * Endpoints runtime para TVs/Totens (não precisa de autenticação admin).
 * Usa autenticação via screen_key + secret_token.
 */
class ScreenRuntimeController extends Controller
{
    public function __construct(
        private AblyPublisher $ably
    ) {
    }

    /**
     * POST /screens/{screenKey}/auth
     * 
     * Autenticação da TV. Retorna token + configuração Ably.
     */
    public function auth(Request $request, string $screenKey): JsonResponse
    {
        $request->validate([
            'secret_token' => 'required|string',
        ]);

        $screen = WheelScreen::where('screen_key', $screenKey)->first();

        if (!$screen) {
            return response()->json([
                'success' => false,
                'message' => 'Screen não encontrada.',
            ], 404);
        }

        // Verificar token
        if (!Hash::check($request->secret_token, $screen->secret_token_hash)) {
            WheelEvent::log('screen_auth_failed', [
                'screen_key' => $screenKey,
                'ip' => $request->ip(),
            ], $screen->id);

            return response()->json([
                'success' => false,
                'message' => 'Token inválido.',
            ], 401);
        }

        // Verificar status
        if ($screen->status === ScreenStatus::INACTIVE) {
            return response()->json([
                'success' => false,
                'message' => 'Screen está inativa.',
            ], 403);
        }

        // Atualizar last_seen
        $screen->touch();
        $screen->last_seen_at = now();
        $screen->device_info = array_merge($screen->device_info ?? [], [
            'user_agent' => $request->userAgent(),
            'ip' => $request->ip(),
            'connected_at' => now()->toISOString(),
        ]);
        $screen->save();

        // Obter campanha ativa
        $activeCampaign = $screen->activeCampaigns()->first();

        // Gerar token Ably
        $ablyToken = null;
        try {
            $ablyToken = $this->ably->createScreenToken($screenKey);
        } catch (\Exception $e) {
            // Log error but continue
        }

        // Logar conexão
        WheelEvent::log('screen_connected', [
            'screen_key' => $screenKey,
            'ip' => $request->ip(),
            'campaign_key' => $activeCampaign?->campaign_key,
        ], $screen->id, $activeCampaign?->id);

        return response()->json([
            'success' => true,
            'data' => [
                'screen' => [
                    'id' => $screen->id,
                    'screen_key' => $screen->screen_key,
                    'name' => $screen->name,
                    'status' => $screen->status->value,
                    'store_id' => $screen->store_id,
                ],
                'campaign' => $activeCampaign ? [
                    'id' => $activeCampaign->id,
                    'campaign_key' => $activeCampaign->campaign_key,
                    'name' => $activeCampaign->name,
                    'settings' => array_merge(
                        WheelCampaign::DEFAULT_SETTINGS,
                        $activeCampaign->settings ?? []
                    ),
                    'starts_at' => $activeCampaign->starts_at?->toISOString(),
                    'ends_at' => $activeCampaign->ends_at?->toISOString(),
                ] : null,
                'realtime_provider' => 'ably',
                'realtime' => [
                    'auth_url' => '/api/v1/wheel/realtime/auth',
                    'channels' => [
                        'screen' => "wheel:screen:{$screenKey}",
                    ],
                    'client_id' => "screen:{$screenKey}",
                    'token' => $ablyToken,
                ],
                'server_time' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * POST /screens/{screenKey}/sessions
     * 
     * Criar nova sessão (gera QR Code).
     */
    public function createSession(Request $request, string $screenKey): JsonResponse
    {
        $screen = $this->getAuthenticatedScreen($request, $screenKey);
        if ($screen instanceof JsonResponse) {
            return $screen;
        }

        // Expirar sessões antigas
        WheelSession::where('screen_id', $screen->id)
            ->active()
            ->where('expires_at', '<', now())
            ->update(['status' => SessionStatus::EXPIRED]);

        // Verificar se já existe sessão ativa
        $existingSession = WheelSession::where('screen_id', $screen->id)
            ->active()
            ->notExpired()
            ->first();

        if ($existingSession) {
            return response()->json([
                'success' => true,
                'data' => $this->formatSession($existingSession),
                'message' => 'Sessão existente retornada.',
            ]);
        }

        // Obter campanha ativa
        $campaign = $screen->activeCampaigns()->first();

        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Nenhuma campanha ativa vinculada a esta TV.',
            ], 400);
        }

        if (!$campaign->isWithinPeriod()) {
            return response()->json([
                'success' => false,
                'message' => 'Campanha fora do período de validade.',
            ], 400);
        }

        // Criar nova sessão
        $session = WheelSession::createForScreen($screen, $campaign);

        // Gerar QR Code data
        $baseUrl = config('app.frontend_url', config('app.url'));
        $session->generateQrCodeData($baseUrl);

        // Logar
        WheelEvent::log('session_created', [
            'session_key' => $session->session_key,
            'expires_at' => $session->expires_at->toISOString(),
        ], $screen->id, $campaign->id);

        // Publicar via Ably
        $this->ably->publishToScreen($screenKey, 'session_updated', [
            'session' => $this->formatSession($session),
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->formatSession($session),
        ], 201);
    }

    /**
     * GET /screens/{screenKey}/state
     * 
     * Estado atual da screen (sessão, fila, jogador).
     */
    public function state(Request $request, string $screenKey): JsonResponse
    {
        $screen = $this->getAuthenticatedScreen($request, $screenKey);
        if ($screen instanceof JsonResponse) {
            return $screen;
        }

        // Sessão ativa
        $session = WheelSession::where('screen_id', $screen->id)
            ->active()
            ->notExpired()
            ->with(['players' => fn($q) => $q->active()->orderBy('queue_position')])
            ->first();

        // Campanha
        $campaign = $screen->activeCampaigns()->first();

        // Formatar fila
        $queue = $session?->players?->map(fn($p) => [
            'player_key' => $p->player_key,
            'phone_masked' => $p->phone_masked,
            'status' => $p->status->value,
            'queue_position' => $p->queue_position,
            'verified' => $p->phone_verified,
        ])->toArray() ?? [];

        return response()->json([
            'success' => true,
            'data' => [
                'screen' => [
                    'screen_key' => $screen->screen_key,
                    'name' => $screen->name,
                    'status' => $screen->status->value,
                ],
                'campaign' => $campaign ? [
                    'campaign_key' => $campaign->campaign_key,
                    'name' => $campaign->name,
                    'settings' => $campaign->settings,
                ] : null,
                'session' => $session ? $this->formatSession($session) : null,
                'queue' => $queue,
                'queue_count' => count($queue),
                'current_player' => $session?->currentPlayer ? [
                    'player_key' => $session->currentPlayer->player_key,
                    'phone_masked' => $session->currentPlayer->phone_masked,
                    'status' => $session->currentPlayer->status->value,
                ] : null,
                'server_time' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * POST /screens/{screenKey}/heartbeat
     * 
     * Heartbeat da TV (atualiza last_seen).
     */
    public function heartbeat(Request $request, string $screenKey): JsonResponse
    {
        $screen = $this->getAuthenticatedScreen($request, $screenKey);
        if ($screen instanceof JsonResponse) {
            return $screen;
        }

        $screen->last_seen_at = now();

        if ($request->has('device_info')) {
            $screen->device_info = array_merge(
                $screen->device_info ?? [],
                $request->input('device_info', [])
            );
        }

        $screen->save();

        return response()->json([
            'success' => true,
            'data' => [
                'ack' => true,
                'server_time' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * DELETE /sessions/{sessionKey}
     * 
     * Expirar/cancelar sessão.
     */
    public function expireSession(Request $request, string $sessionKey): JsonResponse
    {
        $session = WheelSession::where('session_key', $sessionKey)->first();

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Sessão não encontrada.',
            ], 404);
        }

        // Verificar autenticação da screen
        $screen = $this->getAuthenticatedScreen($request, $session->screen->screen_key);
        if ($screen instanceof JsonResponse) {
            return $screen;
        }

        if ($session->status->isTerminal()) {
            return response()->json([
                'success' => false,
                'message' => 'Sessão já finalizada.',
            ], 400);
        }

        $session->status = SessionStatus::CANCELLED;
        $session->save();

        // Notificar players via Ably
        foreach ($session->activePlayers as $player) {
            $this->ably->publishToPlayer($player->player_key, 'session_expired', [
                'reason' => 'cancelled',
            ]);
        }

        // Logar
        WheelEvent::log('session_cancelled', [
            'session_key' => $sessionKey,
        ], $session->screen_id, $session->campaign_id);

        return response()->json([
            'success' => true,
            'message' => 'Sessão cancelada.',
        ]);
    }

    /**
     * Verifica autenticação da screen via header.
     */
    private function getAuthenticatedScreen(Request $request, string $screenKey): WheelScreen|JsonResponse
    {
        $screen = WheelScreen::where('screen_key', $screenKey)->first();

        if (!$screen) {
            return response()->json([
                'success' => false,
                'message' => 'Screen não encontrada.',
            ], 404);
        }

        // Verificar token no header
        $token = $request->bearerToken();

        if (!$token || !Hash::check($token, $screen->secret_token_hash)) {
            return response()->json([
                'success' => false,
                'message' => 'Token inválido.',
            ], 401);
        }

        if ($screen->status === ScreenStatus::INACTIVE) {
            return response()->json([
                'success' => false,
                'message' => 'Screen inativa.',
            ], 403);
        }

        return $screen;
    }

    private function formatSession(WheelSession $session): array
    {
        return [
            'session_key' => $session->session_key,
            'status' => $session->status->value,
            'qr_code_data' => $session->qr_code_data,
            'expires_at' => $session->expires_at->toISOString(),
            'expires_in_seconds' => max(0, now()->diffInSeconds($session->expires_at, false)),
            'campaign_key' => $session->campaign->campaign_key,
        ];
    }
}
