<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PhoneCatalog\StorePhoneBrandRequest;
use App\Http\Requests\PhoneCatalog\UpdatePhoneBrandRequest;
use App\Http\Resources\PhoneBrandResource;
use App\Models\PhoneBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Catálogo - Marcas de Telefone
 *
 * Gestão de marcas de telefone do catálogo.
 * Marcas são usadas para categorizar modelos de telefone.
 *
 * **Leitura:** Todos os usuários autenticados.
 * **Escrita:** Apenas administradores.
 */
class PhoneBrandController extends Controller
{
    /**
     * Listar marcas
     *
     * Retorna lista paginada de marcas com contagem de modelos.
     *
     * **Quem pode usar:** Todos os usuários autenticados.
     *
     * @queryParam search string Buscar por nome. Example: Apple
     * @queryParam slug string Filtrar por slug. Example: apple
     * @queryParam sort string Campo para ordenação. Example: brand_name
     * @queryParam direction string Direção: `asc` ou `desc`. Example: asc
     * @queryParam per_page integer Itens por página (máx 100). Example: 50
     *
     * @response 200 scenario="Lista de marcas" {
     *   "data": [
     *     {"id": 1, "brand_name": "Apple", "brand_slug": "apple", "models_count": 15},
     *     {"id": 2, "brand_name": "Samsung", "brand_slug": "samsung", "models_count": 25}
     *   ],
     *   "meta": {"current_page": 1, "total": 10}
     * }
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PhoneBrand::query()->withCount('models');

        if ($request->filled('search')) {
            $query->search($request->input('search'));
        }

        if ($request->filled('slug')) {
            $query->where('brand_slug', $request->input('slug'));
        }

        // Sorting
        $sortField = $request->input('sort', 'brand_name');
        $sortDirection = $request->input('direction', 'asc');
        $query->orderBy($sortField, $sortDirection);

        // Pagination
        $perPage = min($request->input('per_page', 50), 100);

        return PhoneBrandResource::collection($query->paginate($perPage));
    }

    /**
     * Criar marca
     *
     * Cria uma nova marca de telefone.
     *
     * **Quem pode usar:** Apenas administradores.
     *
     * @bodyParam brand_name string required Nome da marca. Example: Motorola
     * @bodyParam brand_slug string Slug (gerado automaticamente se não informado). Example: motorola
     *
     * @response 201 scenario="Marca criada" {
     *   "message": "Marca criada com sucesso.",
     *   "data": {"id": 3, "brand_name": "Motorola", "brand_slug": "motorola"}
     * }
     *
     * @response 403 scenario="Sem permissão" {"message": "Apenas administradores podem gerenciar marcas."}
     */
    public function store(StorePhoneBrandRequest $request): JsonResponse
    {

        $brand = PhoneBrand::create($request->validated());

        return response()->json([
            'message' => 'Marca criada com sucesso.',
            'data' => new PhoneBrandResource($brand),
        ], 201);
    }

    /**
     * Detalhes da marca
     *
     * Retorna detalhes de uma marca com contagem de modelos.
     *
     * **Quem pode usar:** Todos os usuários autenticados.
     *
     * @urlParam phoneBrand integer required ID da marca. Example: 1
     *
     * @response 200 scenario="Detalhes da marca" {
     *   "data": {"id": 1, "brand_name": "Apple", "brand_slug": "apple", "models_count": 15}
     * }
     */
    public function show(PhoneBrand $phoneBrand): JsonResponse
    {
        return response()->json([
            'data' => new PhoneBrandResource($phoneBrand->loadCount('models')),
        ]);
    }

    /**
     * Atualizar marca
     *
     * Atualiza dados de uma marca existente.
     *
     * **Quem pode usar:** Apenas administradores.
     *
     * @urlParam phoneBrand integer required ID da marca. Example: 1
     * @bodyParam brand_name string Nome da marca. Example: Apple Inc.
     *
     * @response 200 scenario="Marca atualizada" {
     *   "message": "Marca atualizada com sucesso.",
     *   "data": {"id": 1, "brand_name": "Apple Inc."}
     * }
     */
    public function update(UpdatePhoneBrandRequest $request, PhoneBrand $phoneBrand): JsonResponse
    {

        $phoneBrand->update($request->validated());

        return response()->json([
            'message' => 'Marca atualizada com sucesso.',
            'data' => new PhoneBrandResource($phoneBrand->fresh()),
        ]);
    }

    /**
     * Excluir marca
     *
     * Remove uma marca do catálogo.
     *
     * **Quem pode usar:** Apenas administradores.
     *
     * **Regras de negócio:**
     * - Marcas com modelos vinculados não podem ser excluídas
     *
     * @urlParam phoneBrand integer required ID da marca. Example: 1
     *
     * @response 200 scenario="Marca excluída" {"message": "Marca excluída com sucesso."}
     * @response 422 scenario="Tem modelos" {"message": "Não é possível excluir marca com modelos vinculados."}
     */
    public function destroy(PhoneBrand $phoneBrand): JsonResponse
    {

        // Check if brand has models
        if ($phoneBrand->models()->exists()) {
            return response()->json([
                'message' => 'Não é possível excluir marca com modelos vinculados.',
            ], 422);
        }

        $phoneBrand->delete();

        return response()->json([
            'message' => 'Marca excluída com sucesso.',
        ]);
    }

}

