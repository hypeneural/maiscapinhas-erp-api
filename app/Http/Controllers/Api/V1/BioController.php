<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BioStoreResource;
use App\Http\Traits\ApiResponse;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Bio
 *
 * Endpoints públicos para exibição na Bio do Instagram.
 * Estes endpoints não requerem autenticação.
 */
class BioController extends Controller
{
    use ApiResponse;

    /**
     * Listar lojas habilitadas para Bio
     *
     * Retorna todas as lojas ativas com bio_enabled = true.
     * Este endpoint é público e não requer autenticação.
     *
     * @queryParam city string Filtrar por cidade. Example: Tijucas
     *
     * @response 200 {
     *   "data": [
     *     {
     *       "id": 1,
     *       "name": "Mais Capinhas Tijucas",
     *       "city": "Tijucas",
     *       "photo_url": "/storage/stores/1/photo.jpg",
     *       "hours_human": {
     *         "is_open_now": true,
     *         "status_label": "Aberto agora • Fecha às 20:30"
     *       }
     *     }
     *   ],
     *   "meta": {
     *     "total": 1
     *   }
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $query = Store::query()
            ->where('active', true)
            ->where('bio_enabled', true);

        // Optional city filter
        if ($request->filled('city')) {
            $query->where('city', 'like', '%' . $request->input('city') . '%');
        }

        $stores = $query->orderBy('name')->get();

        return response()->json([
            'data' => BioStoreResource::collection($stores),
            'meta' => [
                'total' => $stores->count(),
            ],
        ]);
    }

    /**
     * Obter detalhes de uma loja para Bio
     *
     * Retorna os detalhes de uma loja específica habilitada para Bio.
     * Retorna 404 se a loja não existir ou não estiver habilitada para Bio.
     *
     * @urlParam store integer required ID da loja. Example: 1
     *
     * @response 200 {
     *   "data": {
     *     "id": 1,
     *     "name": "Mais Capinhas Tijucas",
     *     "city": "Tijucas",
     *     "hours_human": {
     *       "timezone": "America/Sao_Paulo",
     *       "is_open_now": true,
     *       "status": "open",
     *       "status_label": "Aberto agora • Fecha às 20:30",
     *       "today_hours_label": "Hoje: 08:30–20:30",
     *       "opens_at": null,
     *       "closes_at": "20:30",
     *       "next_change_at": "2026-01-10T20:30:00-03:00",
     *       "weekly_label": "Seg–Sáb 08:30–20:30 | Dom Fechado"
     *     }
     *   }
     * }
     *
     * @response 404 {
     *   "error": {
     *     "code": 404,
     *     "message": "Loja não encontrada ou não habilitada para Bio."
     *   }
     * }
     */
    public function show(int $store): JsonResponse
    {
        $storeModel = Store::query()
            ->where('id', $store)
            ->where('active', true)
            ->where('bio_enabled', true)
            ->first();

        if (!$storeModel) {
            return $this->error(
                'Loja não encontrada ou não habilitada para Bio.',
                404
            );
        }

        return $this->success(new BioStoreResource($storeModel));
    }
}
