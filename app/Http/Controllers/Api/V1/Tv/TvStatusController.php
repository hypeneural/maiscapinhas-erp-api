<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Tv;

use App\Http\Controllers\Controller;
use App\Services\Tv\TvHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints públicos (Pull, somente leitura, sem autenticação) para consultar
 * o status atual das TVs, resoluções, horário comercial e histórico de atualizações.
 */
class TvStatusController extends Controller
{
    public function __construct(
        private TvHealthService $healthService
    ) {
    }

    /**
     * GET /api/v1/tv/status
     * Status atual de todas as TVs + resumo da frota + últimas atualizações.
     */
    public function index(Request $request): JsonResponse
    {
        $payload = $this->healthService->buildFleetStatus([
            'status' => $request->query('status'),
            'store_id' => $request->query('store_id'),
            'only_monitored' => $request->boolean('only_monitored', false),
            'only_open_stores' => $request->boolean('only_open_stores', false),
            'include_history' => $request->has('include_history') ? $request->boolean('include_history') : true,
            'history_limit' => $request->integer('history_limit', (int) config('tv.history_default_limit', 20)),
            'events' => $request->query('events', 'all'),
        ]);

        return response()->json($payload);
    }

    /**
     * GET /api/v1/tv/status/{slug}
     * Detalhe completo de 1 TV específica (por slug ou store_id) + sessões + histórico.
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $payload = $this->healthService->buildSingleScreenStatus($slug, [
            'history_limit' => $request->integer('history_limit', 30),
            'sessions_limit' => $request->integer('sessions_limit', 15),
            'events' => $request->query('events', 'all'),
        ]);

        if ($payload === null) {
            return response()->json([
                'success' => false,
                'message' => "TV '{$slug}' não encontrada.",
            ], 404);
        }

        return response()->json($payload);
    }

    /**
     * GET /api/v1/tv/history
     * Feed global cronológico das últimas atualizações, quedas, reconexões, comandos e erros.
     */
    public function history(Request $request): JsonResponse
    {
        $payload = $this->healthService->buildHistoryFeed([
            'slug' => $request->query('slug'),
            'store_id' => $request->query('store_id'),
            'category' => $request->query('category', 'all'),
            'event' => $request->query('event'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'limit' => $request->integer('limit', 50),
        ]);

        return response()->json($payload);
    }
}
