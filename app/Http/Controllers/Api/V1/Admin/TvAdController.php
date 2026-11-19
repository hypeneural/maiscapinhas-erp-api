<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\TvAd;
use App\Models\TvAdImpression;
use App\Models\TvLog;
use App\Models\TvScreen;
use App\Models\TvScreenSession;
use App\Services\Wheel\AblyPublisher;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TvAdController extends Controller
{
    public function __construct(
        private ?AblyPublisher $ably = null
    ) {
    }

    /**
     * Listar anúncios com filtros e métricas de exibição.
     */
    public function index(Request $request): JsonResponse
    {
        $query = TvAd::query()
            ->with(['targetStores:id,name', 'creator:id,name'])
            ->withCount('storeTargets')
            ->orderBy('order_priority', 'asc')
            ->orderBy('id', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('media_type')) {
            $query->where('media_type', $request->media_type);
        }

        if ($request->filled('store_id')) {
            $storeId = (int) $request->store_id;
            $query->where(function ($q) use ($storeId) {
                $q->where('is_global', true)
                    ->orWhereHas('targetStores', fn($sq) => $sq->where('stores.id', $storeId));
            });
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where('title', 'like', "%{$search}%");
        }

        $ads = $query->paginate($request->integer('per_page', 50));

        // Carregar contagem de impressões por anúncio
        $adIds = collect($ads->items())->pluck('id')->toArray();
        $impressions = TvAdImpression::query()
            ->whereIn('tv_ad_id', $adIds)
            ->selectRaw('tv_ad_id, count(*) as total_impressions, sum(duration_played_seconds) as total_seconds')
            ->groupBy('tv_ad_id')
            ->get()
            ->keyBy('tv_ad_id');

        $formatted = collect($ads->items())->map(function (TvAd $ad) use ($impressions) {
            $stats = $impressions->get($ad->id);
            $arr = $ad->toArray();
            $arr['impressions_count'] = (int) ($stats->total_impressions ?? 0);
            $arr['total_airtime_minutes'] = round(((int) ($stats->total_seconds ?? 0)) / 60, 1);
            $arr['media_exists'] = $ad->media_path ? Storage::disk('public')->exists($ad->media_path) : true;
            return $arr;
        });

        return response()->json([
            'success' => true,
            'data' => $formatted,
            'meta' => [
                'current_page' => $ads->currentPage(),
                'last_page' => $ads->lastPage(),
                'per_page' => $ads->perPage(),
                'total' => $ads->total(),
            ],
        ]);
    }

    /**
     * Upload de partes/chunks para arquivos grandes (Vídeos de até 500MB+)
     */
    public function uploadChunk(Request $request): JsonResponse
    {
        $request->validate([
            'upload_id' => 'required|string|max:64',
            'chunk_index' => 'required|integer|min:0',
            'total_chunks' => 'required|integer|min:1',
            'filename' => 'required|string|max:255',
            'file' => 'required|file|max:10240',
        ]);

        $uploadId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $request->input('upload_id'));
        $chunkIndex = (int) $request->input('chunk_index');
        $totalChunks = (int) $request->input('total_chunks');
        $originalFilename = $request->input('filename');

        $tempDir = storage_path("app/temp/chunks/{$uploadId}");
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        // Salvar chunk individual
        $chunkFile = $request->file('file');
        $chunkFile->move($tempDir, "chunk_{$chunkIndex}");

        // Verificar se todos os chunks foram recebidos
        $allPresent = true;
        for ($i = 0; $i < $totalChunks; $i++) {
            if (!file_exists("{$tempDir}/chunk_{$i}")) {
                $allPresent = false;
                break;
            }
        }

        if (!$allPresent) {
            return response()->json([
                'success' => true,
                'completed' => false,
                'received_chunk' => $chunkIndex,
                'total_chunks' => $totalChunks,
            ]);
        }

        // Todos os chunks presentes! Unificar em arquivo final
        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        if (empty($ext)) {
            $ext = 'mp4';
        }

        $finalFilename = Str::random(24) . '.' . $ext;
        $targetDirectory = storage_path('app/public/tv-ads');
        if (!file_exists($targetDirectory)) {
            mkdir($targetDirectory, 0775, true);
        }

        $finalFilePath = "{$targetDirectory}/{$finalFilename}";
        $out = fopen($finalFilePath, 'wb');

        for ($i = 0; $i < $totalChunks; $i++) {
            $currentChunk = "{$tempDir}/chunk_{$i}";
            $in = fopen($currentChunk, 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            @unlink($currentChunk);
        }
        fclose($out);
        @rmdir($tempDir);

        $path = "tv-ads/{$finalFilename}";
        $mediaUrl = Storage::disk('public')->url($path);

        $mimeType = mime_content_type($finalFilePath);
        $isVideo = str_starts_with($mimeType, 'video/') || in_array($ext, ['mp4', 'webm', 'mov', 'mkv', 'avi']);
        $mediaType = $isVideo ? 'video' : 'image';

        return response()->json([
            'success' => true,
            'completed' => true,
            'media_type' => $mediaType,
            'media_url' => $mediaUrl,
            'media_path' => $path,
            'thumbnail_url' => $mediaType === 'image' ? $mediaUrl : null,
            'filename' => $finalFilename,
            'size' => filesize($finalFilePath),
            'mime_type' => $mimeType,
        ]);
    }

    /**
     * Cadastrar novo anúncio com upload direto ou mídia pré-processada por chunks.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'required|string|max:150',
            'file' => 'nullable|file|mimes:jpeg,png,jpg,webp,mp4,webm,mov|max:102400',
            'media_url' => 'nullable|string',
            'media_path' => 'nullable|string',
            'media_type' => 'nullable|in:image,video',
            'thumbnail_url' => 'nullable|string',
            'duration_seconds' => 'nullable|integer|min:3|max:600',
            'target_orientation' => 'nullable|in:all,horizontal,vertical',
            'is_global' => 'nullable|boolean',
            'status' => 'nullable|in:active,inactive,scheduled',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'days_of_week' => 'nullable|array',
            'store_ids' => 'nullable|array',
            'store_ids.*' => 'exists:stores,id',
            'order_priority' => 'nullable|integer',
        ]);

        try {
            $mediaType = 'image';
            $mediaUrl = null;
            $path = null;
            $thumbnailUrl = null;
            $fileSize = 0;

            if ($request->has('media_url') && $request->has('media_path')) {
                // Arquivo enviado via Chunked Upload
                $mediaUrl = $request->input('media_url');
                $path = $request->input('media_path');
                $mediaType = $request->input('media_type', 'video');
                $thumbnailUrl = $request->input('thumbnail_url', ($mediaType === 'image' ? $mediaUrl : null));
            } elseif ($request->hasFile('file')) {
                // Upload tradicional direto
                $file = $request->file('file');
                $fileSize = $file->getSize();
                $mime = $file->getMimeType();
                $isImage = str_starts_with($mime, 'image/');
                $isVideo = str_starts_with($mime, 'video/');
                $mediaType = $isVideo ? 'video' : 'image';

                $filename = Str::random(24) . '.' . $file->getClientOriginalExtension();
                $path = $file->storeAs('tv-ads', $filename, 'public');
                $mediaUrl = Storage::disk('public')->url($path);
                $thumbnailUrl = $isImage ? $mediaUrl : null;
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'O arquivo de mídia é obrigatório.',
                    'errors' => ['file' => ['Nenhum arquivo ou mídia foi fornecido.']],
                ], 422);
            }

            $duration = (int) ($request->duration_seconds ?? ($mediaType === 'video' ? 30 : 10));
            $isGlobal = filter_var($request->input('is_global', true), FILTER_VALIDATE_BOOLEAN);

            $ad = DB::transaction(function () use ($request, $mediaType, $mediaUrl, $path, $thumbnailUrl, $duration, $isGlobal) {
                $ad = TvAd::create([
                    'title' => $request->title,
                    'media_type' => $mediaType,
                    'media_url' => $mediaUrl,
                    'media_path' => $path,
                    'thumbnail_url' => $thumbnailUrl,
                    'duration_seconds' => $duration,
                    'target_orientation' => $request->input('target_orientation', 'all'),
                    'is_global' => $isGlobal,
                    'status' => $request->input('status', 'active'),
                    'starts_at' => $request->starts_at,
                    'ends_at' => $request->ends_at,
                    'days_of_week' => $request->days_of_week,
                    'order_priority' => (int) ($request->order_priority ?? 0),
                    'created_by_user_id' => $request->user()?->id,
                ]);

                if (!$isGlobal && !empty($request->store_ids)) {
                    $ad->targetStores()->sync($request->store_ids);
                }

                return $ad;
            });

            TvLog::log('info', 'ad_created', "Novo anúncio '{$ad->title}' publicado com sucesso", null, null, [
                'ad_id' => $ad->id,
                'media_type' => $mediaType,
                'duration_seconds' => $ad->duration_seconds,
                'file_size' => $fileSize,
                'user_id' => $request->user()?->id,
                'user_name' => $request->user()?->name ?? 'Administrador',
            ]);

            $this->notifyScreens();

            return response()->json([
                'success' => true,
                'message' => 'Anúncio publicado com sucesso!',
                'data' => $ad->load('targetStores:id,name'),
            ], 201);
        } catch (\Throwable $e) {
            TvLog::log('error', 'ad_upload_error', "Falha ao enviar arquivo do anúncio: {$e->getMessage()}", null, null, [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar envio do anúncio: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Atualizar anúncio existente.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $ad = TvAd::findOrFail($id);

        $request->validate([
            'title' => 'sometimes|required|string|max:150',
            'file' => 'nullable|file|mimes:jpeg,png,jpg,webp,mp4,webm,mov|max:102400',
            'media_url' => 'nullable|string',
            'media_path' => 'nullable|string',
            'media_type' => 'nullable|in:image,video',
            'thumbnail_url' => 'nullable|string',
            'duration_seconds' => 'nullable|integer|min:3|max:600',
            'target_orientation' => 'nullable|in:all,horizontal,vertical',
            'is_global' => 'nullable|boolean',
            'status' => 'nullable|in:active,inactive,scheduled,expired',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'days_of_week' => 'nullable|array',
            'store_ids' => 'nullable|array',
            'store_ids.*' => 'exists:stores,id',
            'order_priority' => 'nullable|integer',
        ]);

        DB::transaction(function () use ($request, $ad) {
            $dataToUpdate = [];

            if ($request->has('title')) {
                $dataToUpdate['title'] = $request->title;
            }
            if ($request->has('duration_seconds')) {
                $dataToUpdate['duration_seconds'] = (int) $request->duration_seconds;
            }
            if ($request->has('target_orientation')) {
                $dataToUpdate['target_orientation'] = $request->target_orientation;
            }
            if ($request->has('status')) {
                $dataToUpdate['status'] = $request->status;
            }
            if ($request->has('starts_at')) {
                $dataToUpdate['starts_at'] = $request->starts_at;
            }
            if ($request->has('ends_at')) {
                $dataToUpdate['ends_at'] = $request->ends_at;
            }
            if ($request->has('days_of_week')) {
                $dataToUpdate['days_of_week'] = $request->days_of_week;
            }
            if ($request->has('order_priority')) {
                $dataToUpdate['order_priority'] = (int) $request->order_priority;
            }

            if ($request->filled('media_url') && $request->filled('media_path')) {
                if ($ad->media_path && Storage::disk('public')->exists($ad->media_path)) {
                    Storage::disk('public')->delete($ad->media_path);
                }

                $mediaType = $request->input('media_type', 'video');
                $mediaUrl = $request->input('media_url');
                $dataToUpdate['media_type'] = $mediaType;
                $dataToUpdate['media_url'] = $mediaUrl;
                $dataToUpdate['media_path'] = $request->input('media_path');
                $dataToUpdate['thumbnail_url'] = $request->input('thumbnail_url', ($mediaType === 'image' ? $mediaUrl : null));
            } elseif ($request->hasFile('file')) {
                $file = $request->file('file');
                $mime = $file->getMimeType();
                $isImage = str_starts_with($mime, 'image/');
                $isVideo = str_starts_with($mime, 'video/');
                $mediaType = $isVideo ? 'video' : 'image';

                if ($ad->media_path && Storage::disk('public')->exists($ad->media_path)) {
                    Storage::disk('public')->delete($ad->media_path);
                }

                $filename = Str::random(24) . '.' . $file->getClientOriginalExtension();
                $path = $file->storeAs('tv-ads', $filename, 'public');
                $mediaUrl = Storage::disk('public')->url($path);

                $dataToUpdate['media_type'] = $mediaType;
                $dataToUpdate['media_url'] = $mediaUrl;
                $dataToUpdate['media_path'] = $path;
                $dataToUpdate['thumbnail_url'] = $isImage ? $mediaUrl : null;
            }

            if ($request->has('is_global')) {
                $isGlobal = filter_var($request->is_global, FILTER_VALIDATE_BOOLEAN);
                $dataToUpdate['is_global'] = $isGlobal;

                if ($isGlobal) {
                    $ad->targetStores()->detach();
                } elseif ($request->has('store_ids')) {
                    $ad->targetStores()->sync($request->store_ids);
                }
            } elseif ($request->has('store_ids') && !$ad->is_global) {
                $ad->targetStores()->sync($request->store_ids);
            }

            $ad->update($dataToUpdate);
        });

        TvLog::log('info', 'ad_updated', "Anúncio '{$ad->title}' atualizado (Tempo em tela: {$ad->duration_seconds}s)", null, null, [
            'ad_id' => $ad->id,
            'status' => $ad->status,
            'duration_seconds' => $ad->duration_seconds,
            'user_id' => $request->user()?->id,
            'user_name' => $request->user()?->name ?? 'Administrador',
        ]);

        $this->notifyScreens();

        return response()->json([
            'success' => true,
            'message' => 'Anúncio atualizado com sucesso!',
            'data' => $ad->fresh()->load('targetStores:id,name'),
        ]);
    }

    /**
     * Excluir anúncio.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $ad = TvAd::findOrFail($id);

        if ($ad->media_path && Storage::disk('public')->exists($ad->media_path)) {
            Storage::disk('public')->delete($ad->media_path);
        }

        $title = $ad->title;
        $ad->delete();

        TvLog::log('warning', 'ad_deleted', "Anúncio '{$title}' foi removido da grade", null, null, [
            'ad_id' => $id,
            'user_id' => $request->user()?->id,
            'user_name' => $request->user()?->name ?? 'Administrador',
        ]);

        $this->notifyScreens();

        return response()->json([
            'success' => true,
            'message' => 'Anúncio removido da grade com sucesso.',
        ]);
    }

    /**
     * Listar todas as telas/links das lojas físicas e telemetria atual.
     */
    public function screens(): JsonResponse
    {
        $stores = Store::query()
            ->orderBy('id', 'asc')
            ->get();

        $screens = [];
        foreach ($stores as $store) {
            $screen = TvScreen::firstOrCreate(
                ['store_id' => $store->id],
                [
                    'name' => 'TV - ' . $store->name,
                    'slug' => 'loja-' . Str::slug($store->name),
                    'orientation' => 'auto',
                    'is_online' => false,
                    'is_blackout' => false,
                ]
            );

            $isOnline = $screen->is_online && $screen->last_ping_at && $screen->last_ping_at->gt(now()->subMinutes(2));

            $screens[] = [
                'store_id' => $store->id,
                'store_name' => $store->name,
                'screen_id' => $screen->id,
                'slug' => $screen->slug,
                'screen_key' => $screen->screen_key,
                'access_token' => $screen->access_token,
                'orientation' => $screen->orientation,
                'is_online' => $isOnline,
                'is_blackout' => (bool) $screen->is_blackout,
                'current_ad_title' => $screen->current_ad_title,
                'current_ad_id' => $screen->current_ad_id,
                'fps' => $screen->fps,
                'uptime_seconds' => $screen->uptime_seconds,
                'last_ping_at' => $screen->last_ping_at?->toISOString(),
                'public_url' => config('app.frontend_url', config('app.url')) . '/tv/' . $screen->slug,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $screens,
        ]);
    }

    /**
     * Enviar comando de controle remoto para a TV.
     */
    public function sendCommand(Request $request, int $screenId): JsonResponse
    {
        $request->validate([
            'command' => 'required|string|in:reload,next,pause,play,clear_cache,turn_off,turn_on,toggle_power',
        ]);

        $screen = TvScreen::findOrFail($screenId);
        $command = $request->input('command');

        $humanCommandNames = [
            'turn_off' => 'Desligar Tela (Standby)',
            'turn_on' => 'Ligar Tela',
            'toggle_power' => 'Alternar Energia',
            'reload' => 'Recarregar Grade',
            'next' => 'Pular para Próximo Slide',
            'pause' => 'Pausar Reprodução',
            'play' => 'Retomar Reprodução',
            'clear_cache' => 'Limpar Cache de Armazenamento',
        ];

        if ($command === 'turn_off') {
            $screen->update(['is_blackout' => true]);

            $activeSession = TvScreenSession::where('screen_id', $screen->id)
                ->whereNull('ended_at')
                ->latest('id')
                ->first();
            if ($activeSession) {
                $activeSession->close('standby_turned_off');
            }
        } elseif ($command === 'turn_on') {
            $screen->update(['is_blackout' => false]);
        } elseif ($command === 'toggle_power') {
            $screen->update(['is_blackout' => !$screen->is_blackout]);
            $command = $screen->is_blackout ? 'turn_off' : 'turn_on';
        }

        $humanCommand = $humanCommandNames[$command] ?? $command;

        TvLog::log('info', 'tv_remote_command', "Comando remoto '{$humanCommand}' enviado para {$screen->name}", $screen->slug, $screen->store_id, [
            'command' => $command,
            'command_pt' => $humanCommand,
            'is_blackout' => (bool) $screen->is_blackout,
            'user_id' => $request->user()?->id,
            'user_name' => $request->user()?->name ?? 'Administrador',
        ]);

        try {
            if ($this->ably) {
                $this->ably->publishToScreen($screen->screen_key, 'player_command', [
                    'command' => $command,
                    'is_blackout' => (bool) $screen->is_blackout,
                    'timestamp' => now()->toISOString(),
                ]);
            }
        } catch (\Throwable $e) {
            // Fallback
        }

        $feedbackMessages = [
            'turn_off' => "A TV foi colocada em modo Standby (Tela Desligada) com sucesso.",
            'turn_on' => "A TV foi ligada e a transmissão dos anúncios foi retomada.",
            'reload' => "A grade de anúncios foi recarregada na TV com sucesso.",
            'next' => "A TV avançou para o próximo slide com sucesso.",
            'pause' => "A reprodução da TV foi pausada com sucesso.",
            'play' => "A reprodução da TV foi continuada com sucesso.",
            'clear_cache' => "O armazenamento temporário da TV foi limpo com sucesso.",
        ];

        $responseMessage = $feedbackMessages[$command] ?? "Comando '{$humanCommand}' executado com sucesso.";

        return response()->json([
            'success' => true,
            'message' => $responseMessage,
            'data' => [
                'screen_id' => $screen->id,
                'is_blackout' => (bool) $screen->is_blackout,
            ],
        ]);
    }

    /**
     * Estatísticas gerais e avançadas por Loja e Dia da Semana (Analytics Completo).
     */
    public function analytics(Request $request): JsonResponse
    {
        $storeId = $request->filled('store_id') && $request->store_id !== 'all' ? (int) $request->store_id : null;
        $period = $request->input('period', '7days');

        $now = now();
        $startDate = match ($period) {
            'today' => $now->copy()->startOfDay(),
            '30days' => $now->copy()->subDays(30)->startOfDay(),
            default => $now->copy()->subDays(7)->startOfDay(),
        };

        // 1. Consulta de Sessões de Uptime da TV
        $sessionsQuery = TvScreenSession::query()
            ->where('started_at', '>=', $startDate)
            ->with(['store:id,name', 'screen:id,name,slug'])
            ->orderBy('started_at', 'desc');

        if ($storeId) {
            $sessionsQuery->where('store_id', $storeId);
        }

        $sessions = $sessionsQuery->limit(30)->get();

        // 2. Resumo de Uptime
        $totalDurationMinutes = (int) TvScreenSession::query()
            ->where('started_at', '>=', $startDate)
            ->when($storeId, fn($q) => $q->where('store_id', $storeId))
            ->sum('duration_minutes');

        $totalHoursOnline = round($totalDurationMinutes / 60, 1);
        $totalSessionsCount = $sessionsQuery->count();

        $daysCount = match ($period) {
            'today' => 1,
            '30days' => 30,
            default => 7,
        };
        $avgHoursPerDay = round($totalHoursOnline / max(1, $daysCount), 1);

        // Score de Uptime considerando 12 horas diárias de funcionamento comercial
        $expectedHours = $daysCount * 12;
        $uptimeScore = $expectedHours > 0 ? min(100, round(($totalHoursOnline / $expectedHours) * 100, 1)) : 100;

        // 3. Performance por Anúncio na Loja Selecionada
        $adsStatsQuery = TvAdImpression::query()
            ->where('displayed_at', '>=', $startDate)
            ->when($storeId, fn($q) => $q->where('store_id', $storeId))
            ->selectRaw('tv_ad_id, count(*) as impressions_count, sum(duration_played_seconds) as total_seconds, max(displayed_at) as last_displayed_at')
            ->groupBy('tv_ad_id')
            ->with('ad:id,title,media_type,thumbnail_url,media_url,duration_seconds')
            ->orderBy('impressions_count', 'desc')
            ->get();

        $totalImpressionsInPeriod = $adsStatsQuery->sum('impressions_count');

        $adsPerformance = $adsStatsQuery->map(function ($item) use ($totalImpressionsInPeriod) {
            $airtimeMinutes = round(((int) $item->total_seconds) / 60, 1);
            $pct = $totalImpressionsInPeriod > 0
                ? round(($item->impressions_count / $totalImpressionsInPeriod) * 100, 1)
                : 0;

            return [
                'ad_id' => $item->tv_ad_id,
                'title' => $item->ad?->title ?? 'Anúncio Removido',
                'media_type' => $item->ad?->media_type ?? 'image',
                'thumbnail_url' => $item->ad?->thumbnail_url ?? $item->ad?->media_url,
                'impressions_count' => (int) $item->impressions_count,
                'airtime_minutes' => $airtimeMinutes,
                'percentage' => $pct,
                'last_displayed_at' => $item->last_displayed_at ? Carbon::parse($item->last_displayed_at)->toISOString() : null,
            ];
        });

        // 4. Distribuição por Dia da Semana (Segunda a Domingo)
        $weekdaysMap = [
            1 => 'Segunda',
            2 => 'Terça',
            3 => 'Quarta',
            4 => 'Quinta',
            5 => 'Sexta',
            6 => 'Sábado',
            0 => 'Domingo',
        ];

        $weekdayStats = TvAdImpression::query()
            ->where('displayed_at', '>=', $startDate)
            ->when($storeId, fn($q) => $q->where('store_id', $storeId))
            ->selectRaw('DAYOFWEEK(displayed_at) - 1 as weekday_num, count(*) as count, sum(duration_played_seconds) as total_seconds')
            ->groupBy('weekday_num')
            ->get()
            ->keyBy('weekday_num');

        $weekdayDistribution = [];
        foreach ([1, 2, 3, 4, 5, 6, 0] as $dayNum) {
            $stat = $weekdayStats->get($dayNum);
            $weekdayDistribution[] = [
                'day_num' => $dayNum,
                'day_name' => $weekdaysMap[$dayNum],
                'impressions' => (int) ($stat->count ?? 0),
                'airtime_minutes' => round(((int) ($stat->total_seconds ?? 0)) / 60, 1),
            ];
        }

        // 5. Distribuição por Faixa de Horário (00h às 23h)
        $hourlyStats = TvAdImpression::query()
            ->where('displayed_at', '>=', $startDate)
            ->when($storeId, fn($q) => $q->where('store_id', $storeId))
            ->selectRaw('HOUR(displayed_at) as hour_num, count(*) as count')
            ->groupBy('hour_num')
            ->get()
            ->keyBy('hour_num');

        $hourlyDistribution = [];
        for ($h = 0; $h < 24; $h++) {
            $stat = $hourlyStats->get($h);
            $hourlyDistribution[] = [
                'hour' => sprintf('%02dh', $h),
                'impressions' => (int) ($stat->count ?? 0),
            ];
        }

        // 6. Composição de Tipos de Mídia (Fotos vs Vídeos)
        $mediaTypeStats = TvAdImpression::query()
            ->where('displayed_at', '>=', $startDate)
            ->when($storeId, fn($q) => $q->where('store_id', $storeId))
            ->selectRaw('media_type, count(*) as count, sum(duration_played_seconds) as total_seconds')
            ->groupBy('media_type')
            ->get()
            ->keyBy('media_type');

        $mediaTypeDistribution = [
            [
                'name' => 'Fotos (Estáticas)',
                'media_type' => 'image',
                'count' => (int) ($mediaTypeStats->get('image')?->count ?? 0),
                'minutes' => round(((int) ($mediaTypeStats->get('image')?->total_seconds ?? 0)) / 60, 1),
                'fill' => '#3b82f6',
            ],
            [
                'name' => 'Vídeos (MP4)',
                'media_type' => 'video',
                'count' => (int) ($mediaTypeStats->get('video')?->count ?? 0),
                'minutes' => round(((int) ($mediaTypeStats->get('video')?->total_seconds ?? 0)) / 60, 1),
                'fill' => '#6366f1',
            ],
        ];

        // 7. Formatação das Sessões de Turno (Uptime)
        $reasonLabels = [
            'standby_turned_off' => 'Standby (Desligada pelo ERP)',
            'timeout_disconnected' => 'Desconectou / Sem Sinal da TV',
            'browser_closed' => 'Navegador Fechado',
        ];

        $formattedSessions = $sessions->map(function (TvScreenSession $s) use ($reasonLabels) {
            $isActiveNow = $s->ended_at === null && $s->last_ping_at->gt(now()->subMinutes(3));
            $reason = $isActiveNow
                ? 'Em Execução Agora'
                : ($reasonLabels[$s->ended_reason] ?? 'Desconectada');

            return [
                'id' => $s->id,
                'store_name' => $s->store?->name ?? 'Mais Capinhas',
                'screen_slug' => $s->screen_slug,
                'started_at' => $s->started_at->toISOString(),
                'ended_at' => $s->ended_at?->toISOString(),
                'last_ping_at' => $s->last_ping_at->toISOString(),
                'duration_minutes' => $s->duration_minutes,
                'duration_human' => floor($s->duration_minutes / 60) . 'h ' . ($s->duration_minutes % 60) . 'm',
                'is_active_now' => $isActiveNow,
                'ended_reason' => $s->ended_reason,
                'ended_reason_pt' => $reason,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $period,
                'store_id' => $storeId,
                'summary' => [
                    'total_impressions' => $totalImpressionsInPeriod,
                    'total_hours_online' => $totalHoursOnline,
                    'avg_hours_per_day' => $avgHoursPerDay,
                    'total_sessions_count' => $totalSessionsCount,
                    'uptime_score' => $uptimeScore,
                ],
                'sessions' => $formattedSessions,
                'weekday_distribution' => $weekdayDistribution,
                'hourly_distribution' => $hourlyDistribution,
                'media_type_distribution' => $mediaTypeDistribution,
                'ads_performance' => $adsPerformance,
            ],
        ]);
    }

    /**
     * Estatísticas gerais da rede de Digital Signage.
     */
    public function stats(): JsonResponse
    {
        $now = now();
        $today = $now->copy()->startOfDay();

        $totalActiveAds = TvAd::query()->currentlyActive()->count();
        $totalScreens = TvScreen::count();
        $onlineScreens = TvScreen::query()
            ->where('is_online', true)
            ->where('last_ping_at', '>', $now->subMinutes(2))
            ->count();

        $impressionsToday = TvAdImpression::where('displayed_at', '>=', $today)->count();
        $totalAirtimeTodayMinutes = round(
            ((int) TvAdImpression::where('displayed_at', '>=', $today)->sum('duration_played_seconds')) / 60,
            1
        );

        $recentLogs = TvLog::query()
            ->with('store:id,name')
            ->orderBy('id', 'desc')
            ->limit(15)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'active_ads_count' => $totalActiveAds,
                'total_screens' => $totalScreens,
                'online_screens' => $onlineScreens,
                'impressions_today' => $impressionsToday,
                'airtime_today_minutes' => $totalAirtimeTodayMinutes,
                'recent_logs' => $recentLogs,
            ],
        ]);
    }

    /**
     * Logs de diagnóstico e transmissão.
     */
    public function logs(Request $request): JsonResponse
    {
        $query = TvLog::query()
            ->with('store:id,name')
            ->orderBy('id', 'desc');

        if ($request->filled('level')) {
            $query->where('level', $request->level);
        }

        if ($request->filled('event')) {
            $query->where('event', $request->event);
        }

        if ($request->filled('screen_slug')) {
            $query->where('screen_slug', $request->screen_slug);
        }

        if ($request->filled('store_id') && $request->store_id !== 'all') {
            $query->where('store_id', (int) $request->store_id);
        }

        $logs = $query->paginate($request->integer('per_page', 50));

        return response()->json([
            'success' => true,
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * Obter configurações de design (Global + Overrides de Loja).
     */
    public function getDesignSettings(Request $request): JsonResponse
    {
        $defaultTemplate = \App\Http\Controllers\Api\V1\Tv\TvRuntimeController::getDefaultDesignTemplate();
        
        $stores = Store::orderBy('id', 'asc')->get(['id', 'name']);
        $screens = TvScreen::whereNotNull('design_template')->get()->keyBy('store_id');

        $storeOverrides = [];
        foreach ($stores as $store) {
            $screen = $screens->get($store->id);
            $storeOverrides[$store->id] = [
                'has_custom_template' => !empty($screen?->design_template),
                'template' => !empty($screen?->design_template)
                    ? array_replace_recursive($defaultTemplate, $screen->design_template)
                    : null,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'global' => $defaultTemplate,
                'store_overrides' => $storeOverrides,
            ],
        ]);
    }

    /**
     * Salvar template de design padrão global (Para toda a rede).
     */
    public function saveGlobalDesign(Request $request): JsonResponse
    {
        $template = $request->input('template', []);

        DB::table('tv_global_settings')->updateOrInsert(
            ['key' => 'default_design_template'],
            ['value' => json_encode($template), 'updated_at' => now()]
        );

        TvLog::log('info', 'tv_design_updated', 'Design padrão global das TVs atualizado com sucesso', null, null, [
            'user_id' => $request->user()?->id,
            'user_name' => $request->user()?->name ?? 'Administrador',
        ]);

        $this->notifyScreens();

        return response()->json([
            'success' => true,
            'message' => 'Design global das TVs salvo com sucesso!',
            'data' => $template,
        ]);
    }

    /**
     * Salvar template de design customizado para uma filial específica.
     */
    public function saveStoreDesign(Request $request, int $storeId): JsonResponse
    {
        $store = Store::findOrFail($storeId);
        $template = $request->input('template', []);

        $screen = TvScreen::firstOrCreate(
            ['store_id' => $store->id],
            [
                'name' => 'TV - ' . $store->name,
                'slug' => 'loja-' . Str::slug($store->name),
                'orientation' => 'auto',
                'is_online' => false,
                'is_blackout' => false,
            ]
        );

        $screen->update(['design_template' => $template]);

        TvLog::log('info', 'tv_design_updated', "Design exclusivo da loja '{$store->name}' atualizado com sucesso", $screen->slug, $store->id, [
            'user_id' => $request->user()?->id,
            'user_name' => $request->user()?->name ?? 'Administrador',
        ]);

        $this->notifyScreens();

        return response()->json([
            'success' => true,
            'message' => "Design exclusivo da loja '{$store->name}' salvo com sucesso!",
            'data' => $template,
        ]);
    }

    /**
     * Restaurar filial para usar o design global padrão da rede.
     */
    public function resetStoreDesign(Request $request, int $storeId): JsonResponse
    {
        $store = Store::findOrFail($storeId);
        $screen = TvScreen::where('store_id', $store->id)->first();

        if ($screen) {
            $screen->update(['design_template' => null]);
        }

        TvLog::log('info', 'tv_design_updated', "Design da loja '{$store->name}' restaurado para o padrão global", $screen?->slug, $store->id, [
            'user_id' => $request->user()?->id,
            'user_name' => $request->user()?->name ?? 'Administrador',
        ]);

        $this->notifyScreens();

        return response()->json([
            'success' => true,
            'message' => "A loja '{$store->name}' agora está utilizando o design padrão da rede.",
        ]);
    }

    /**
     * Upload de Logomarca para o Estúdio de Design.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:png,jpg,jpeg,webp,svg|max:10240',
        ]);

        $file = $request->file('file');
        $fileName = 'tv_logo_' . time() . '_' . Str::random(8) . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('tv/logos', $fileName, 'public');

        $baseUrl = config('app.url', 'https://api.maiscapinhas.com.br');
        $url = rtrim($baseUrl, '/') . '/storage/' . $path;

        TvLog::log('info', 'tv_logo_uploaded', "Nova logomarca enviada para o Estúdio de TV", null, null, [
            'file_name' => $fileName,
            'file_size' => $file->getSize(),
            'user_name' => $request->user()?->name ?? 'Administrador',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Logomarca enviada com sucesso!',
            'data' => [
                'url' => $url,
                'path' => $path,
            ],
        ]);
    }

    /**
     * Notificar todas as TVs via WebSocket.
     */
    private function notifyScreens(): void
    {
        try {
            if ($this->ably) {
                $this->ably->publishToScreen('global', 'ads_updated', [
                    'timestamp' => now()->toISOString(),
                    'reason' => 'ads_modified',
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning("Ably notify failed: " . $e->getMessage());
        }
    }
}
