<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Tv;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\TvAd;
use App\Models\TvAdImpression;
use App\Models\TvLog;
use App\Models\TvScreen;
use App\Services\Tv\TvHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TvRuntimeController extends Controller
{
    public function __construct(
        private TvHealthService $healthService
    ) {
    }
    /**
     * Obter o template de design padrão da rede.
     */
    public static function getDefaultDesignTemplate(): array
    {
        $global = DB::table('tv_global_settings')->where('key', 'default_design_template')->first();
        if ($global && $global->value) {
            $decoded = json_decode($global->value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [
            'clock' => [
                'enabled' => true,
                'position' => 'top-right',
                'format' => '24h',
                'show_seconds' => false,
                'show_date' => false,
                'style' => 'glass',
                'size' => 'md',
                'color' => '#ffffff',
            ],
            'store_badge' => [
                'enabled' => true,
                'position' => 'top-left',
                'custom_name' => null,
                'tagline' => 'Acessórios Premium para Celular',
                'style' => 'glass',
                'accent_color' => '#10b981',
                'pulse_dot' => true,
            ],
            'ticker_banner' => [
                'enabled' => false,
                'text' => 'Películas com garantia de troca! • Capinhas para todos os modelos de iPhone e Samsung',
                'tag_prefix' => '🔥 OFERTA',
                'font_family' => 'Inter',
                'font_size' => 16,
                'font_weight' => 'bold',
                'speed' => 'normal',
                'bg_color' => '#000000',
                'bg_opacity' => 0.85,
                'text_color' => '#ffffff',
                'padding_y' => 'md',
            ],
            'branding_logo' => [
                'enabled' => false,
                'logo_url' => null,
                'position' => 'bottom-left',
                'height_px' => 48,
                'opacity' => 0.9,
            ],
            'qr_code' => [
                'enabled' => false,
                'type' => 'whatsapp',
                'custom_url' => null,
                'title' => 'Chame no WhatsApp',
                'title_color' => '#ffffff',
                'fg_color' => '#000000',
                'bg_color' => '#ffffff',
                'position' => 'bottom-right',
                'size' => 'md',
                'style' => 'glass',
            ],
        ];
    }

    /**
     * Obter a playlist de anúncios ativa para a TV / Loja com template de design resolvido.
     */
    public function playlist(string $slugOrToken): JsonResponse
    {
        // 1. Tentar encontrar por slug ou screen_key ou access_token
        $screen = TvScreen::query()
            ->where('slug', $slugOrToken)
            ->orWhere('screen_key', $slugOrToken)
            ->orWhere('access_token', $slugOrToken)
            ->with('store:id,name')
            ->first();

        $storeId = $screen?->store_id;
        $storeName = $screen?->store?->name ?? 'Mais Capinhas';

        // Se for um ID numérico de loja direto (fallback de URL: /tv/1)
        if (!$screen && is_numeric($slugOrToken)) {
            $store = Store::find((int) $slugOrToken);
            if ($store) {
                $storeId = $store->id;
                $storeName = $store->name;
            }
        }

        // 2. Resolver o Design Template (Override da Loja ou Padrão Global)
        $defaultTemplate = self::getDefaultDesignTemplate();
        $designTemplate = array_replace_recursive($defaultTemplate, $screen?->design_template ?? []);

        // 3. Buscar anúncios ativos aplicáveis
        $ads = TvAd::query()
            ->currentlyActive()
            ->forStore($storeId)
            ->orderBy('order_priority', 'asc')
            ->orderBy('id', 'desc')
            ->get();

        // 4. Filtrar por dias da semana aplicáveis
        $filteredAds = $ads->filter(fn(TvAd $ad) => $ad->isApplicableToday());

        // 5. Formatar itens para a Engine da TV
        $playlist = $filteredAds->map(fn(TvAd $ad) => [
            'id' => $ad->id,
            'title' => $ad->title,
            'type' => $ad->media_type,
            'url' => $ad->media_url,
            'thumbnail_url' => $ad->thumbnail_url,
            'duration' => $ad->duration_seconds,
            'orientation' => $ad->target_orientation,
            'is_global' => $ad->is_global,
        ])->values();

        $playlistVersion = $this->healthService->computePlaylistVersion($storeId, $screen);

        return response()->json([
            'success' => true,
            'data' => [
                'screen' => [
                    'name' => $screen?->name ?? "TV - {$storeName}",
                    'store_id' => $storeId,
                    'store_name' => $storeName,
                    'orientation' => $screen?->orientation ?? 'auto',
                    'slug' => $screen?->slug ?? $slugOrToken,
                    'is_blackout' => (bool) ($screen?->is_blackout ?? false),
                ],
                'design_template' => $designTemplate,
                'realtime' => [
                    'provider' => 'ably',
                    'channel' => $screen ? "tv:screen:{$screen->screen_key}" : 'tv:screen:global',
                ],
                'playlist_version' => $playlistVersion,
                'recommended_ping_seconds' => (int) config('tv.heartbeat_interval_seconds', 30),
                'playlist_count' => $playlist->count(),
                'playlist' => $playlist,
                'server_time' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * Heartbeat da TV com telemetria avançada, detecção de transições e sessão atômica.
     */
    public function ping(Request $request, string $slugOrToken): JsonResponse
    {
        $screen = TvScreen::query()
            ->where('slug', $slugOrToken)
            ->orWhere('screen_key', $slugOrToken)
            ->orWhere('access_token', $slugOrToken)
            ->with('store')
            ->first();

        if ($screen) {
            $state = $this->healthService->recordPing($screen, $request->all(), $request);

            return response()->json(array_merge(['success' => true], $state));
        }

        return response()->json([
            'success' => true,
            'status' => 'online',
            'recommended_ping_seconds' => (int) config('tv.heartbeat_interval_seconds', 30),
            'server_time' => now()->toISOString(),
        ]);
    }

    /**
     * Desconexão graciosa via navigator.sendBeacon (pagehide / beforeunload).
     */
    public function disconnect(Request $request, string $slugOrToken): JsonResponse
    {
        $screen = TvScreen::query()
            ->where('slug', $slugOrToken)
            ->orWhere('screen_key', $slugOrToken)
            ->orWhere('access_token', $slugOrToken)
            ->with('store')
            ->first();

        if ($screen) {
            $payload = $request->all();
            if ($payload === [] && $request->getContent() !== '') {
                $decoded = json_decode((string) $request->getContent(), true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }

            $this->healthService->recordGracefulDisconnect($screen, $payload, $request);
        }

        return response()->json([
            'success' => true,
            'status' => 'offline',
            'server_time' => now()->toISOString(),
        ]);
    }

    /**
     * Registrar impressão de slide (execução concluída).
     */
    public function impression(Request $request, string $slugOrToken): JsonResponse
    {
        if ($request->boolean('preview') || filter_var($request->query('preview', false), FILTER_VALIDATE_BOOLEAN)) {
            return response()->json([
                'success' => true,
                'ignored_preview' => true,
                'message' => 'Preview impression ignored',
            ]);
        }

        $request->validate([
            'tv_ad_id' => 'required|integer|exists:tv_ads,id',
            'duration_played_seconds' => 'nullable|integer',
            'completed_naturally' => 'nullable|boolean',
            'media_type' => 'nullable|in:image,video',
        ]);

        $screen = TvScreen::query()
            ->where('slug', $slugOrToken)
            ->orWhere('screen_key', $slugOrToken)
            ->orWhere('access_token', $slugOrToken)
            ->first();

        $storeId = $screen?->store_id;

        TvAdImpression::create([
            'tv_ad_id' => (int) $request->tv_ad_id,
            'store_id' => $storeId,
            'screen_slug' => $screen?->slug ?? $slugOrToken,
            'duration_played_seconds' => (int) ($request->duration_played_seconds ?? 10),
            'completed_naturally' => filter_var($request->input('completed_naturally', true), FILTER_VALIDATE_BOOLEAN),
            'media_type' => $request->input('media_type', 'image'),
            'displayed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Impression logged',
        ]);
    }

    /**
     * Registrar erro de reprodução da TV Box.
     */
    public function logError(Request $request, string $slugOrToken): JsonResponse
    {
        if ($request->boolean('preview') || filter_var($request->query('preview', false), FILTER_VALIDATE_BOOLEAN)) {
            return response()->json(['success' => true, 'ignored_preview' => true]);
        }

        $screen = TvScreen::query()
            ->where('slug', $slugOrToken)
            ->orWhere('screen_key', $slugOrToken)
            ->first();

        $message = (string) $request->input('message', 'Erro no player de TV');
        $mediaUrl = $request->input('url') ? (string) $request->input('url') : null;

        TvLog::log('error', 'tv_player_error', $message, $screen?->slug ?? $slugOrToken, $screen?->store_id, [
            'url' => $mediaUrl,
            'type' => $request->input('type'),
            'user_agent' => $request->userAgent(),
            'ip' => $this->healthService->resolveClientIp($request),
        ]);

        $this->healthService->handlePlayerErrorLogged($screen, $message, $mediaUrl);

        return response()->json(['success' => true]);
    }
}
