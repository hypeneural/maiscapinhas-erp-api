<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Wheel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wheel\StorePrizeRequest;
use App\Http\Requests\Wheel\UpdatePrizeRequest;
use App\Http\Resources\Wheel\PrizeResource;
use App\Models\WheelPrize;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Wheel - Prizes
 *
 * API para gerenciamento de Prêmios do módulo Roleta.
 */
class PrizeController extends Controller
{
    /**
     * Listar prêmios.
     */
    public function index(Request $request): JsonResponse
    {
        $query = WheelPrize::query()
            ->withCount('segments')
            ->when($request->filled('type'), fn($q) => $q->where('type', $request->type))
            ->when($request->has('active'), fn($q) => $q->where('active', $request->boolean('active')))
            ->when($request->filled('search'), fn($q) => $q->where(function ($sub) use ($request) {
                $sub->where('name', 'like', "%{$request->search}%")
                    ->orWhere('prize_key', 'like', "%{$request->search}%");
            }))
            ->orderBy('type')
            ->orderBy('name');

        $prizes = $request->boolean('all')
            ? $query->get()
            : $query->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => PrizeResource::collection($prizes),
            'meta' => $prizes instanceof \Illuminate\Pagination\LengthAwarePaginator ? [
                'total' => $prizes->total(),
                'per_page' => $prizes->perPage(),
            ] : null,
        ]);
    }

    /**
     * Criar prêmio.
     */
    public function store(StorePrizeRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Gerar prize_key se não fornecido
        if (empty($data['prize_key'])) {
            $data['prize_key'] = WheelPrize::generatePrizeKey($data['name']);
        }

        $prize = WheelPrize::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Prêmio criado com sucesso.',
            'data' => new PrizeResource($prize),
        ], 201);
    }

    /**
     * Exibir prêmio.
     */
    public function show(string $prizeKey): JsonResponse
    {
        $prize = WheelPrize::where('prize_key', $prizeKey)
            ->withCount('segments')
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => new PrizeResource($prize),
        ]);
    }

    /**
     * Atualizar prêmio.
     */
    public function update(UpdatePrizeRequest $request, string $prizeKey): JsonResponse
    {
        $prize = WheelPrize::where('prize_key', $prizeKey)->firstOrFail();

        $prize->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Prêmio atualizado com sucesso.',
            'data' => new PrizeResource($prize->fresh()),
        ]);
    }

    /**
     * Excluir prêmio.
     */
    public function destroy(string $prizeKey): JsonResponse
    {
        $prize = WheelPrize::where('prize_key', $prizeKey)
            ->withCount('segments')
            ->firstOrFail();

        // Não permitir excluir se estiver sendo usado
        if ($prize->segments_count > 0) {
            return response()->json([
                'success' => false,
                'message' => "Este prêmio está sendo usado em {$prize->segments_count} segmento(s). Remova-os primeiro.",
            ], 422);
        }

        $prize->delete();

        return response()->json([
            'success' => true,
            'message' => 'Prêmio excluído com sucesso.',
        ]);
    }

    /**
     * Ativar/Desativar prêmio.
     */
    public function toggle(string $prizeKey): JsonResponse
    {
        $prize = WheelPrize::where('prize_key', $prizeKey)->firstOrFail();

        $prize->toggle();

        $action = $prize->active ? 'ativado' : 'desativado';

        return response()->json([
            'success' => true,
            'message' => "Prêmio {$action} com sucesso.",
            'data' => new PrizeResource($prize),
        ]);
    }
}
