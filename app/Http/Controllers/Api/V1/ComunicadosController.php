<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Comunicado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Comunicados
 *
 * API para gerenciamento de comunicados.
 */
class ComunicadosController extends Controller
{
    /**
     * Listar comunicados.
     */
    public function index(Request $request): JsonResponse
    {
        $items = Comunicado::query()
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 15));

        return response()->json($items);
    }

    /**
     * Criar comunicado.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $item = Comunicado::create($validated);

        return response()->json([
            'message' => 'Comunicado criado com sucesso.',
            'data' => $item,
        ], 201);
    }

    /**
     * Exibir comunicado.
     */
    public function show(Comunicado $comunicado): JsonResponse
    {
        return response()->json(['data' => $comunicado]);
    }

    /**
     * Atualizar comunicado.
     */
    public function update(Request $request, Comunicado $comunicado): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'integer'],
        ]);

        $comunicado->update($validated);

        return response()->json([
            'message' => 'Comunicado atualizado.',
            'data' => $comunicado,
        ]);
    }

    /**
     * Excluir comunicado.
     */
    public function destroy(Comunicado $comunicado): JsonResponse
    {
        $comunicado->delete();

        return response()->json([
            'message' => 'Comunicado excluído.',
        ]);
    }
}