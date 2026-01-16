<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PhoneCatalog\StorePhoneModelRequest;
use App\Http\Requests\PhoneCatalog\UpdatePhoneModelRequest;
use App\Http\Resources\PhoneModelResource;
use App\Models\PhoneModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Catálogo - Modelos de Telefone
 *
 * Gestão de modelos de telefone do catálogo.
 * Modelos são vinculados a marcas e usados para associar dispositivos de clientes.
 *
 * **Leitura:** Todos os usuários autenticados.
 * **Escrita:** Apenas administradores.
 */
class PhoneModelController extends Controller
{
    /**
     * Listar modelos
     *
     * Retorna lista paginada de modelos com dados da marca.
     *
     * **Quem pode usar:** Todos os usuários autenticados.
     *
     * @queryParam search string Buscar por nome. Example: iPhone
     * @queryParam brand_id integer Filtrar por marca. Example: 1
     * @queryParam form_factor string Filtrar por formato. Example: smartphone
     * @queryParam release_year integer Filtrar por ano de lançamento. Example: 2024
     * @queryParam sort string Campo para ordenação. Example: marketing_name
     * @queryParam direction string Direção: `asc` ou `desc`. Example: asc
     * @queryParam per_page integer Itens por página (máx 100). Example: 50
     *
     * @response 200 scenario="Lista de modelos" {
     *   "data": [
     *     {"id": 1, "marketing_name": "iPhone 15", "brand": {"id": 1, "brand_name": "Apple"}}
     *   ],
     *   "meta": {"current_page": 1, "total": 100}
     * }
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PhoneModel::query()->with('brand');

        if ($request->filled('search')) {
            $query->search($request->input('search'));
        }

        if ($request->filled('brand_id')) {
            $query->byBrand((int) $request->input('brand_id'));
        }

        if ($request->filled('form_factor')) {
            $query->byFormFactor($request->input('form_factor'));
        }

        if ($request->filled('release_year')) {
            $query->byReleaseYear((int) $request->input('release_year'));
        }

        // Sorting
        $sortField = $request->input('sort', 'marketing_name');
        $sortDirection = $request->input('direction', 'asc');
        $query->orderBy($sortField, $sortDirection);

        // Pagination
        $perPage = min($request->input('per_page', 50), 100);

        return PhoneModelResource::collection($query->paginate($perPage));
    }

    /**
     * Criar modelo
     *
     * Cria um novo modelo de telefone vinculado a uma marca.
     *
     * **Quem pode usar:** Apenas administradores.
     *
     * @bodyParam brand_id integer required ID da marca. Example: 1
     * @bodyParam marketing_name string required Nome comercial. Example: Galaxy S24
     * @bodyParam model_number string Número do modelo. Example: SM-S921B
     * @bodyParam form_factor string Formato do dispositivo. Example: smartphone
     * @bodyParam release_year integer Ano de lançamento. Example: 2024
     *
     * @response 201 scenario="Modelo criado" {
     *   "message": "Modelo criado com sucesso.",
     *   "data": {"id": 1, "marketing_name": "Galaxy S24", "brand": {"brand_name": "Samsung"}}
     * }
     *
     * @response 403 scenario="Sem permissão" {"message": "Apenas administradores podem gerenciar modelos."}
     */
    public function store(StorePhoneModelRequest $request): JsonResponse
    {

        $model = PhoneModel::create($request->validated());

        return response()->json([
            'message' => 'Modelo criado com sucesso.',
            'data' => new PhoneModelResource($model->load('brand')),
        ], 201);
    }

    /**
     * Detalhes do modelo
     *
     * Retorna detalhes de um modelo com dados da marca.
     *
     * **Quem pode usar:** Todos os usuários autenticados.
     *
     * @urlParam phoneModel integer required ID do modelo. Example: 1
     *
     * @response 200 scenario="Detalhes do modelo" {
     *   "data": {"id": 1, "marketing_name": "iPhone 15", "brand": {"id": 1, "brand_name": "Apple"}}
     * }
     */
    public function show(PhoneModel $phoneModel): JsonResponse
    {
        return response()->json([
            'data' => new PhoneModelResource($phoneModel->load('brand')),
        ]);
    }

    /**
     * Atualizar modelo
     *
     * Atualiza dados de um modelo existente.
     *
     * **Quem pode usar:** Apenas administradores.
     *
     * @urlParam phoneModel integer required ID do modelo. Example: 1
     * @bodyParam marketing_name string Nome comercial. Example: iPhone 15 Pro
     * @bodyParam model_number string Número do modelo. Example: A3101
     *
     * @response 200 scenario="Modelo atualizado" {
     *   "message": "Modelo atualizado com sucesso.",
     *   "data": {"id": 1, "marketing_name": "iPhone 15 Pro"}
     * }
     */
    public function update(UpdatePhoneModelRequest $request, PhoneModel $phoneModel): JsonResponse
    {

        $phoneModel->update($request->validated());

        return response()->json([
            'message' => 'Modelo atualizado com sucesso.',
            'data' => new PhoneModelResource($phoneModel->fresh()->load('brand')),
        ]);
    }

    /**
     * Excluir modelo
     *
     * Remove um modelo do catálogo.
     *
     * **Quem pode usar:** Apenas administradores.
     *
     * **Regras de negócio:**
     * - Modelos com dispositivos vinculados não podem ser excluídos
     *
     * @urlParam phoneModel integer required ID do modelo. Example: 1
     *
     * @response 200 scenario="Modelo excluído" {"message": "Modelo excluído com sucesso."}
     * @response 422 scenario="Tem dispositivos" {"message": "Não é possível excluir modelo com dispositivos vinculados."}
     */
    public function destroy(PhoneModel $phoneModel): JsonResponse
    {

        // Check if model has devices
        if ($phoneModel->devices()->exists()) {
            return response()->json([
                'message' => 'Não é possível excluir modelo com dispositivos vinculados.',
            ], 422);
        }

        $phoneModel->delete();

        return response()->json([
            'message' => 'Modelo excluído com sucesso.',
        ]);
    }

}

