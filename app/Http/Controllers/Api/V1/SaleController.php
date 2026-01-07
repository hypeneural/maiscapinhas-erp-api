<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreSaleRequest;
use App\Http\Requests\Sales\UpdateSaleRequest;
use App\Http\Resources\SaleResource;
use App\Http\Traits\ApiResponse;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Vendas
 *
 * Gerenciamento de vendas registradas no sistema MaisCapinhas ERP.
 *
 * As vendas são o **coração do sistema financeiro**, pois a partir delas
 * são calculados:
 * - **Bônus diário** - baseado no total de vendas do dia
 * - **Comissão mensal** - baseado no atingimento de meta
 * - **KPIs de desempenho** - taxa de conversão, ticket médio
 *
 * **Fontes de Vendas:**
 * | Origem | Descrição |
 * |--------|-----------|
 * | `pdv` | Importação automática do sistema de PDV |
 * | `manual` | Cadastro manual via esta API |
 * | `import` | Importação em lote (planilha CSV) |
 *
 * **Regras de Negócio Importantes:**
 * - Cada venda pertence a uma **loja** (`store_id`) e um **vendedor** (`seller_id`)
 * - A venda conta para a meta da **loja** onde foi realizada
 * - A comissão vai para o **vendedor**, mesmo que seja "volante"
 * - Alterações em vendas disparam recálculo automático de bônus/comissão
 *
 * **Impacto no Bônus:**
 * Quando uma venda é criada/alterada, o sistema automaticamente
 * recalcula o bônus diário do vendedor (via Job assíncrono).
 */
class SaleController extends Controller
{
    use ApiResponse;

    /**
     * Listar vendas
     *
     * Retorna a lista paginada de vendas das lojas às quais o
     * usuário tem acesso, com diversos filtros disponíveis.
     *
     * **Visibilidade:**
     * - Vendedor: vê vendas de todas as lojas onde trabalha
     * - Gerente: vê todas as vendas das lojas que gerencia
     * - Admin: vê todas as vendas do sistema
     *
     * **Filtros disponíveis:**
     * - `store_id` - Filtrar por loja específica
     * - `seller_id` - Filtrar por vendedor
     * - `from`/`to` - Período de datas
     *
     * **Ordenação:** Por data de venda, mais recentes primeiro.
     *
     * **Quem pode usar:** Qualquer usuário autenticado (vê apenas suas lojas).
     *
     * @queryParam store_id integer Filtrar por loja (deve ter acesso). Example: 1
     * @queryParam seller_id integer Filtrar por vendedor específico. Example: 6
     * @queryParam from string Data inicial (YYYY-MM-DD). Example: 2026-01-01
     * @queryParam to string Data final (YYYY-MM-DD). Example: 2026-01-31
     * @queryParam per_page integer Itens por página (1-100, default: 25). Example: 25
     *
     * @response 200 scenario="Lista de vendas" {
     *   "data": [
     *     {
     *       "id": 1,
     *       "store_id": 1,
     *       "seller_id": 6,
     *       "sold_at": "2026-01-07T10:30:00Z",
     *       "amount": 150.00,
     *       "source": "pdv",
     *       "store": { "id": 1, "name": "Mais Capinhas Tijucas" },
     *       "seller": { "id": 6, "name": "João Vendedor" }
     *     }
     *   ],
     *   "meta": { "current_page": 1, "per_page": 25, "total": 150, "last_page": 6 }
     * }
     *
     * @response 403 scenario="Loja sem acesso" {
     *   "message": "You do not have access to this store."
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['sometimes', 'integer', 'exists:stores,id'],
            'seller_id' => ['sometimes', 'integer', 'exists:users,id'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $userStoreIds = $user->storeUsers()->pluck('store_id')->toArray();

        $query = Sale::with(['store:id,name', 'seller:id,name'])
            ->whereIn('store_id', $userStoreIds);

        if ($request->filled('store_id')) {
            $storeId = (int) $request->input('store_id');
            if (!in_array($storeId, $userStoreIds)) {
                return $this->forbidden('You do not have access to this store.');
            }
            $query->where('store_id', $storeId);
        }

        if ($request->filled('seller_id')) {
            $query->where('seller_id', $request->input('seller_id'));
        }

        if ($request->filled('from')) {
            $query->where('sold_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('sold_at', '<=', $request->input('to') . ' 23:59:59');
        }

        $perPage = $request->input('per_page', 25);
        $paginator = $query->orderByDesc('sold_at')->paginate($perPage);

        return $this->paginated($paginator, SaleResource::class);
    }

    /**
     * Criar venda manual
     *
     * Registra uma nova venda no sistema de forma manual.
     *
     * **Quando usar:**
     * - Vendas feitas fora do PDV (dinheiro em espécie direto)
     * - Correções de vendas não registradas
     * - Vendas de quiosques sem sistema
     *
     * **Efeitos automáticos:**
     * Após criar a venda, o sistema automaticamente dispara:
     * 1. `RecalculateSellerDailyBonusJob` - recalcula bônus do dia
     * 2. `RecalculateSellerMonthlyCommissionJob` - recalcula comissão do mês
     *
     * **Regras de Negócio:**
     * - `store_id` deve ser uma loja onde o usuário tem acesso
     * - `seller_id` default é o usuário logado
     * - `source` default é "manual"
     * - `amount` deve ser positivo
     *
     * **Quem pode usar:** 
     * - Vendedores (podem registrar próprias vendas)
     * - Gerentes/Admins (podem registrar para qualquer vendedor)
     *
     * @bodyParam store_id integer required Loja onde a venda foi realizada. Example: 1
     * @bodyParam seller_id integer Vendedor que realizou (default: você). Example: 6
     * @bodyParam sold_at string required Data/hora da venda (ISO 8601). Example: 2026-01-07T15:30:00
     * @bodyParam amount number required Valor total da venda. Example: 175.50
     * @bodyParam source string Origem: pdv, manual, import (default: manual). Example: manual
     *
     * @response 201 scenario="Venda criada" {
     *   "data": {
     *     "id": 501,
     *     "store_id": 1,
     *     "seller_id": 6,
     *     "sold_at": "2026-01-07T15:30:00Z",
     *     "amount": 175.50,
     *     "source": "manual",
     *     "store": { "id": 1, "name": "Mais Capinhas Tijucas" },
     *     "seller": { "id": 6, "name": "João Vendedor" }
     *   }
     * }
     *
     * @response 403 scenario="Sem acesso à loja" {
     *   "message": "Você não tem acesso a esta loja."
     * }
     */
    public function store(StoreSaleRequest $request): JsonResponse
    {
        $user = $request->user();
        $storeId = (int) $request->input('store_id');

        if (!$user->hasAccessToStore($storeId)) {
            return $this->forbidden('Você não tem acesso a esta loja.');
        }

        $sale = Sale::create([
            'store_id' => $storeId,
            'seller_id' => $request->input('seller_id', $user->id),
            'sold_at' => $request->input('sold_at'),
            'amount' => $request->input('amount'),
            'source' => $request->input('source', 'manual'),
        ]);

        return $this->created(new SaleResource($sale->load(['store:id,name', 'seller:id,name'])));
    }

    /**
     * Ver detalhes da venda
     *
     * Retorna informações completas de uma venda específica.
     *
     * **Dados retornados:**
     * - Informações básicas: `id`, `store_id`, `seller_id`, `sold_at`, `amount`, `source`
     * - Loja: `store.id`, `store.name`
     * - Vendedor: `seller.id`, `seller.name`
     * - Timestamps: `created_at`, `updated_at`
     *
     * **Quem pode usar:** Usuários com acesso à loja da venda.
     *
     * @urlParam sale integer required ID da venda. Example: 1
     *
     * @response 200 scenario="Detalhes da venda" {
     *   "data": {
     *     "id": 1,
     *     "store_id": 1,
     *     "seller_id": 6,
     *     "sold_at": "2026-01-07T10:30:00Z",
     *     "amount": 150.00,
     *     "source": "pdv",
     *     "created_at": "2026-01-07T10:31:00Z",
     *     "store": { "id": 1, "name": "Mais Capinhas Tijucas" },
     *     "seller": { "id": 6, "name": "João Vendedor" }
     *   }
     * }
     */
    public function show(Request $request, Sale $sale): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasAccessToStore($sale->store_id)) {
            return $this->forbidden('Você não tem acesso a esta venda.');
        }

        return $this->success(new SaleResource($sale->load(['store:id,name', 'seller:id,name'])));
    }

    /**
     * Atualizar venda
     *
     * Edita dados de uma venda existente.
     *
     * **Importante:**
     * Alterações disparam recálculo automático de bônus e comissão
     * para o vendedor afetado.
     *
     * **Casos de uso:**
     * - Corrigir valor digitado errado
     * - Ajustar data de venda
     * - Corrigir fonte da venda
     *
     * **Regras de Negócio:**
     * - Não é possível alterar `store_id` ou `seller_id` após criação
     * - Apenas gerentes e admins podem editar vendas
     * - Histórico de alterações é registrado para auditoria
     *
     * **Quem pode usar:** Gerentes e Admins da loja.
     *
     * @urlParam sale integer required ID da venda. Example: 1
     * @bodyParam sold_at string Nova data/hora. Example: 2026-01-07T16:00:00
     * @bodyParam amount number Novo valor. Example: 180.00
     * @bodyParam source string Nova origem: pdv, manual, import. Example: pdv
     *
     * @response 200 scenario="Venda atualizada" {
     *   "data": {
     *     "id": 1,
     *     "amount": 180.00,
     *     "sold_at": "2026-01-07T16:00:00Z"
     *   }
     * }
     *
     * @response 403 scenario="Sem permissão" {
     *   "message": "Apenas gerentes e admins podem editar vendas."
     * }
     */
    public function update(UpdateSaleRequest $request, Sale $sale): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasAccessToStore($sale->store_id)) {
            return $this->forbidden('Você não tem acesso a esta venda.');
        }

        // Only gerente+ can edit sales
        $role = $user->roleInStore($sale->store_id);
        if (!in_array($role, ['admin', 'gerente'])) {
            return $this->forbidden('Apenas gerentes e admins podem editar vendas.');
        }

        $sale->update($request->only(['sold_at', 'amount', 'source']));

        return $this->success(new SaleResource($sale->load(['store:id,name', 'seller:id,name'])));
    }

    /**
     * Excluir venda
     *
     * Remove permanentemente uma venda do sistema.
     *
     * **Atenção:**
     * - Esta ação é **irreversível**
     * - O bônus/comissão do vendedor será recalculado automaticamente
     * - Recomendado documentar o motivo da exclusão
     *
     * **Casos de uso:**
     * - Venda cadastrada em duplicidade
     * - Venda cancelada pelo cliente
     * - Teste registrado acidentalmente em produção
     *
     * **Regras de Negócio:**
     * - Apenas administradores podem excluir vendas
     * - A exclusão é registrada no log de auditoria
     *
     * **Quem pode usar:** Apenas Admins.
     *
     * @urlParam sale integer required ID da venda. Example: 1
     *
     * @response 200 scenario="Venda excluída" {
     *   "data": { "message": "Venda excluída com sucesso." }
     * }
     *
     * @response 403 scenario="Sem permissão" {
     *   "message": "Apenas admins podem excluir vendas."
     * }
     */
    public function destroy(Request $request, Sale $sale): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasAccessToStore($sale->store_id)) {
            return $this->forbidden('Você não tem acesso a esta venda.');
        }

        // Only admin can delete
        $role = $user->roleInStore($sale->store_id);
        if ($role !== 'admin') {
            return $this->forbidden('Apenas admins podem excluir vendas.');
        }

        $sale->delete();

        return $this->success(['message' => 'Venda excluída com sucesso.']);
    }
}
