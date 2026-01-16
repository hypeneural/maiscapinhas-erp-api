<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentMethods\StorePaymentMethodRequest;
use App\Http\Requests\PaymentMethods\UpdatePaymentMethodRequest;
use App\Http\Resources\PaymentMethodResource;
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Formas de Pagamento
 *
 * Gestão de formas de pagamento do sistema.
 * Formas de pagamento disponíveis: Dinheiro, Pix, Débito, Crédito, etc.
 *
 * **Leitura:** Todos os usuários autenticados.
 * **Escrita:** Apenas Super Admin e Admin Global.
 */
class PaymentMethodController extends Controller
{
    /**
     * Listar formas de pagamento
     *
     * Retorna lista paginada de formas de pagamento com filtros.
     *
     * **Quem pode usar:** Todos os usuários autenticados.
     *
     * @queryParam search string Buscar por nome ou slug. Example: pix
     * @queryParam is_active boolean Filtrar por status ativo. Example: true
     * @queryParam sort string Campo para ordenação. Example: sort_order
     * @queryParam direction string Direção: `asc` ou `desc`. Example: asc
     * @queryParam per_page integer Itens por página (máx 100). Example: 50
     *
     * @response 200 scenario="Lista de formas de pagamento" {
     *   "data": [
     *     {"id": 1, "name": "Dinheiro", "slug": "dinheiro", "is_active": true, "sort_order": 1},
     *     {"id": 2, "name": "Pix", "slug": "pix", "is_active": true, "sort_order": 2}
     *   ],
     *   "meta": {"current_page": 1, "total": 4}
     * }
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PaymentMethod::query();

        if ($request->filled('search')) {
            $query->search($request->input('search'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Sorting
        $allowedSortFields = ['id', 'name', 'slug', 'sort_order', 'created_at', 'is_active'];
        $sortField = $request->input('sort', 'sort_order');
        $sortField = in_array($sortField, $allowedSortFields) ? $sortField : 'sort_order';
        $sortDirection = $request->input('direction', 'asc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortField, $sortDirection);

        // Secondary sort by name if primary is sort_order
        if ($sortField === 'sort_order') {
            $query->orderBy('name', 'asc');
        }

        // Pagination
        $perPage = min($request->input('per_page', 50), 100);

        return PaymentMethodResource::collection($query->paginate($perPage));
    }

    /**
     * Criar forma de pagamento
     *
     * Cria uma nova forma de pagamento.
     *
     * **Quem pode usar:** Apenas Super Admin e Admin Global.
     *
     * @bodyParam name string required Nome da forma de pagamento. Example: Boleto
     * @bodyParam slug string Slug (gerado automaticamente se não informado). Example: boleto
     * @bodyParam description string Descrição opcional. Example: Pagamento via boleto bancário
     * @bodyParam is_active boolean Status ativo. Example: true
     * @bodyParam sort_order integer Ordem de exibição. Example: 5
     *
     * @response 201 scenario="Forma de pagamento criada" {
     *   "message": "Forma de pagamento criada com sucesso.",
     *   "data": {"id": 5, "name": "Boleto", "slug": "boleto", "is_active": true}
     * }
     *
     * @response 403 scenario="Sem permissão" {"message": "Apenas administradores podem gerenciar formas de pagamento."}
     */
    public function store(StorePaymentMethodRequest $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $paymentMethod = PaymentMethod::create($request->validated());

        return response()->json([
            'message' => 'Forma de pagamento criada com sucesso.',
            'data' => new PaymentMethodResource($paymentMethod),
        ], 201);
    }

    /**
     * Detalhes da forma de pagamento
     *
     * Retorna detalhes de uma forma de pagamento específica.
     *
     * **Quem pode usar:** Todos os usuários autenticados.
     *
     * @urlParam payment_method integer required ID da forma de pagamento. Example: 1
     *
     * @response 200 scenario="Detalhes da forma de pagamento" {
     *   "data": {"id": 1, "name": "Dinheiro", "slug": "dinheiro", "is_active": true, "sort_order": 1}
     * }
     */
    public function show(PaymentMethod $paymentMethod): JsonResponse
    {
        return response()->json([
            'data' => new PaymentMethodResource($paymentMethod),
        ]);
    }

    /**
     * Atualizar forma de pagamento
     *
     * Atualiza dados de uma forma de pagamento existente.
     *
     * **Quem pode usar:** Apenas Super Admin e Admin Global.
     *
     * @urlParam payment_method integer required ID da forma de pagamento. Example: 1
     * @bodyParam name string Nome da forma de pagamento. Example: Dinheiro
     * @bodyParam is_active boolean Status ativo. Example: false
     * @bodyParam sort_order integer Ordem de exibição. Example: 1
     *
     * @response 200 scenario="Forma de pagamento atualizada" {
     *   "message": "Forma de pagamento atualizada com sucesso.",
     *   "data": {"id": 1, "name": "Dinheiro", "is_active": false}
     * }
     */
    public function update(UpdatePaymentMethodRequest $request, PaymentMethod $paymentMethod): JsonResponse
    {
        $this->authorizeAdmin($request);

        $paymentMethod->update($request->validated());

        return response()->json([
            'message' => 'Forma de pagamento atualizada com sucesso.',
            'data' => new PaymentMethodResource($paymentMethod->fresh()),
        ]);
    }

    /**
     * Excluir forma de pagamento
     *
     * Remove uma forma de pagamento do sistema.
     *
     * **Quem pode usar:** Apenas Super Admin e Admin Global.
     *
     * @urlParam payment_method integer required ID da forma de pagamento. Example: 1
     *
     * @response 200 scenario="Forma de pagamento excluída" {"message": "Forma de pagamento excluída com sucesso."}
     */
    public function destroy(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        $this->authorizeAdmin($request);

        $paymentMethod->delete();

        return response()->json([
            'message' => 'Forma de pagamento excluída com sucesso.',
        ]);
    }

    /**
     * Verifica se o usuário é administrador.
     */
    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        if (!$user->isSuperAdmin() && !$user->isGlobalAdmin()) {
            abort(403, 'Apenas administradores podem gerenciar formas de pagamento.');
        }
    }
}
