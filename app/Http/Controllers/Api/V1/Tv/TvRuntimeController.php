<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Tv;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\TvAd;
use App\Models\TvAdImpression;
use App\Models\TvLog;
use App\Models\TvScreen;
use App\Models\TvScreenSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TvRuntimeController extends Controller
{
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
                'playlist_count' => $playlist->count(),
                'playlist' => $playlist,
                'server_time' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * Heartbeat da TV com telemetria avançada e gerenciamento de sessões de uptime.
     */
    public function ping(Request $request, string $slugOrToken): JsonResponse
    {
        $screen = TvScreen::query()
            ->where('slug', $slugOrToken)
            ->orWhere('screen_key', $slugOrToken)
            ->orWhere('access_token', $slugOrToken)
            ->with('store:id,name')
            ->first();

        if ($screen) {
            $currentAdTitle = $request->input('current_ad_title');
            $currentAdId = $request->input('current_ad_id');
            $fps = $request->input('fps');
            $uptimeSeconds = (int) $request->input('uptime_seconds', 0);
            $now = now();

            $screen->update([
                'is_online' => true,
                'current_ad_title' => $currentAdTitle,
                'current_ad_id' => $currentAdId ? (int) $currentAdId : null,
                'fps' => $fps ? (int) $fps : null,
                'uptime_seconds' => $uptimeSeconds,
                'last_ping_at' => $now,
                'device_info' => array_merge($screen->device_info ?? [], [
                    'user_agent' => $request->userAgent(),
                    'ip' => $request->ip(),
                    'screen_width' => $request->input('width'),
                    'screen_height' => $request->input('height'),
                    'offline_cached' => $request->boolean('offline_cached'),
                    'pinged_at' => $now->toISOString(),
                ]),
            ]);

            // Gerenciamento de Sessão de Funcionamento (Uptime) se a TV não estiver em Standby
            if (!$screen->is_blackout) {
                $activeSession = TvScreenSession::where('screen_id', $screen->id)
                    ->whereNull('ended_at')
                    ->latest('id')
                    ->first();

                if ($activeSession) {
                    if ($activeSession->last_ping_at->lt($now->copy()->subMinutes(5))) {
                        $activeSession->close('timeout_disconnected');
                        $activeSession = TvScreenSession::create([
                            'store_id' => $screen->store_id,
                            'screen_id' => $screen->id,
                            'screen_slug' => $screen->slug,
                            'started_at' => $now,
                            'last_ping_at' => $now,
                            'duration_minutes' => 0,
                            'device_info' => ['user_agent' => $request->userAgent(), 'ip' => $request->ip()],
                        ]);
                    } else {
                        $duration = max(1, (int) $activeSession->started_at->diffInMinutes($now));
                        $activeSession->update([
                            'last_ping_at' => $now,
                            'duration_minutes' => $duration,
                        ]);
                    }
                } else {
                    TvScreenSession::create([
                        'store_id' => $screen->store_id,
                        'screen_id' => $screen->id,
                        'screen_slug' => $screen->slug,
                        'started_at' => $now,
                        'last_ping_at' => $now,
                        'duration_minutes' => 0,
                        'device_info' => ['user_agent' => $request->userAgent(), 'ip' => $request->ip()],
                    ]);

                    TvLog::log('info', 'tv_turned_on', "TV da loja '{$screen->store?->name}' conectou e iniciou transmissão", $screen->slug, $screen->store_id, [
                        'ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                    ]);
                }
            }
        }

        return response()->json([
            'success' => true,
            'status' => 'online',
            'server_time' => now()->toISOString(),
        ]);
    }

    /**
     * Registrar impressão de slide (execução concluída).
     */
    public function impression(Request $request, string $slugOrToken): JsonResponse
    {
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
        $screen = TvScreen::query()
            ->where('slug', $slugOrToken)
            ->orWhere('screen_key', $slugOrToken)
            ->first();

        TvLog::log('error', 'tv_player_error', $request->input('message', 'Erro no player de TV'), $screen?->slug ?? $slugOrToken, $screen?->store_id, [
            'url' => $request->input('url'),
            'type' => $request->input('type'),
            'user_agent' => $request->userAgent(),
            'ip' => $request->ip(),
        ]);

        return response()->json(['success' => true]);
    }
}
