<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wheel;

use App\Http\Controllers\Controller;
use App\Models\WheelPlayer;
use App\Models\WheelScreen;
use App\Services\Wheel\AblyPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * RealtimeAuthController
 * 
 * Endpoint para autenticação Ably (gera tokenRequest).
 */
class RealtimeAuthController extends Controller
{
    public function __construct(
        private AblyPublisher $ably
    ) {
    }

    /**
     * POST /realtime/auth
     * 
     * Gera token Ably para TV ou Mobile.
     */
    public function auth(Request $request): JsonResponse
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Token não fornecido.',
            ], 401);
        }

        // Tentar autenticar como Screen
        $screens = WheelScreen::all();
        foreach ($screens as $screen) {
            if (Hash::check($token, $screen->secret_token_hash)) {
                try {
                    $ablyToken = $this->ably->createScreenToken($screen->screen_key);

                    return response()->json([
                        'success' => true,
                        'data' => $ablyToken,
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Erro ao gerar token Ably.',
                    ], 500);
                }
            }
        }

        // Tentar autenticar como Player
        $players = WheelPlayer::active()->get();
        foreach ($players as $player) {
            if ($player->verifyAccessToken($token)) {
                try {
                    $ablyToken = $this->ably->createPlayerToken($player->player_key);

                    return response()->json([
                        'success' => true,
                        'data' => $ablyToken,
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Erro ao gerar token Ably.',
                    ], 500);
                }
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Token inválido.',
        ], 401);
    }
}
