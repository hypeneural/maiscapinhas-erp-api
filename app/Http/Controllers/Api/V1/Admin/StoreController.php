<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStoreRequest;
use App\Http\Requests\Admin\UpdateStoreRequest;
use App\Http\Resources\StoreResource;
use App\Http\Traits\ApiResponse;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Administração - Lojas
 *
 * Gerenciamento das lojas (pontos de venda) do sistema MaisCapinhas ERP.
 *
 * Cada loja representa uma unidade física de venda com:
 * - Equipe própria (vendedores, conferentes, gerentes)
 * - Metas mensais individuais
 * - Turnos de caixa e fechamentos
 * - Regras de bônus/comissão (podem ser específicas ou herdar as globais)
 *
 * **Hierarquia de Dados:**
 * ```
 * Loja
 * ├── Usuários (via store_users com roles)
 * ├── Vendas (registradas por vendedores)
 * ├── Turnos de Caixa (por data/turno/vendedor)
 * ├── Metas Mensais (com splits por vendedor)
 * └── Regras de Bônus/Comissão (opcionais, específicas)
 * ```
 *
 * **Regras de Negócio:**
 * - Uma loja desativada não aparece na lista de lojas do usuário comum
 * - Dados históricos são mantidos para auditoria
 * - Lojas são identificadas por ID numérico (não há código de loja)
 *
 * **Auditoria:** Todas as operações são registradas via Spatie ActivityLog.
 */
class StoreController extends Controller
{
    use ApiResponse;

    /**
     * Listar todas as lojas
     *
     * Retorna uma lista completa de todas as lojas cadastradas no sistema,
     * incluindo lojas ativas e inativas (para administração).
     *
     * Este endpoint difere do `/stores` público que mostra apenas
     * as lojas às quais o usuário tem acesso.
     *
     * **Métricas retornadas:**
     * - `users_count` - quantidade de usuários vinculados
     *
     * **Filtros disponíveis:**
     * - `search` - busca por nome ou cidade
     * - `active` - filtrar por status
     *
     * **Quem pode usar:** Apenas administradores.
     *
     * @queryParam search string Busca por nome ou cidade da loja. Example: tijucas
     * @queryParam active boolean Filtrar por status ativo/inativo. Example: true
     * @queryParam per_page integer Itens por página (1-100, default: 25). Example: 25
     *
     * @response 200 scenario="Lista de lojas" {
     *   "data": [
     *     {
     *       "id": 1,
     *       "name": "Mais Capinhas Tijucas",
     *       "city": "Tijucas",
     *       "active": true,
     *       "users_count": 5,
     *       "created_at": "2026-01-01T00:00:00Z"
     *     },
     *     {
     *       "id": 2,
     *       "name": "Mais Capinhas Itapema",
     *       "city": "Itapema",
     *       "active": true,
     *       "users_count": 3
     *     }
     *   ],
     *   "meta": { "current_page": 1, "per_page": 25, "total": 3, "last_page": 1 }
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $query = Store::withCount('storeUsers');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%");
            });
        }

        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        $stores = $query->orderBy('name')->paginate($request->input('per_page', 25));

        return $this->paginated($stores, StoreResource::class);
    }

    /**
     * Criar nova loja
     *
     * Cadastra uma nova loja (ponto de venda) no sistema.
     *
     * Após criar a loja, você deve:
     * 1. Vincular usuários à loja (via endpoint de vínculos)
     * 2. Configurar metas mensais (via `/goals/monthly`)
     * 3. Opcionalmente criar regras específicas de bônus/comissão
     *
     * **Quem pode usar:** Apenas administradores.
     *
     * @bodyParam name string required Nome identificador da loja. Example: Mais Capinhas Shopping Center
     * @bodyParam city string required Cidade onde a loja está localizada. Example: Florianópolis
     * @bodyParam active boolean Se a loja está ativa (default: true). Example: true
     *
     * @response 201 scenario="Loja criada" {
     *   "data": {
     *     "id": 4,
     *     "name": "Mais Capinhas Shopping Center",
     *     "city": "Florianópolis",
     *     "active": true,
     *     "created_at": "2026-01-07T17:00:00Z"
     *   }
     * }
     */
    public function store(StoreStoreRequest $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $store = Store::create([
            'name' => $request->input('name'),
            'city' => $request->input('city'),
            'active' => $request->input('active', true),
        ]);

        return $this->created(new StoreResource($store));
    }

    /**
     * Ver detalhes da loja
     *
     * Retorna informações completas de uma loja, incluindo
     * a lista de todos os usuários vinculados e suas roles.
     *
     * **Dados retornados:**
     * - Informações básicas: `id`, `name`, `city`, `active`
     * - Lista de usuários com: `user_id`, `user_name`, `user_email`, `role`
     *
     * **Quem pode usar:** Apenas administradores.
     *
     * @urlParam store integer required ID da loja. Example: 1
     *
     * @response 200 scenario="Detalhes da loja" {
     *   "data": {
     *     "id": 1,
     *     "name": "Mais Capinhas Tijucas",
     *     "city": "Tijucas",
     *     "active": true,
     *     "created_at": "2026-01-01T00:00:00Z",
     *     "users": [
     *       { "user_id": 2, "user_name": "Carlos Gerente", "user_email": "carlos@test.com", "role": "gerente" },
     *       { "user_id": 6, "user_name": "João Vendedor", "user_email": "joao@test.com", "role": "vendedor" }
     *     ]
     *   }
     * }
     */
    public function show(Request $request, Store $store): JsonResponse
    {
        $this->authorizeAdmin($request);

        return $this->success(new StoreResource($store->load('storeUsers.user')));
    }

    /**
     * Atualizar loja
     *
     * Atualiza informações de uma loja existente.
     * Apenas os campos informados serão alterados.
     *
     * **Quem pode usar:** Apenas administradores.
     *
     * @urlParam store integer required ID da loja. Example: 1
     * @bodyParam name string Novo nome da loja. Example: Mais Capinhas Tijucas - Centro
     * @bodyParam city string Nova cidade. Example: Tijucas
     * @bodyParam active boolean Ativar (true) ou desativar (false). Example: true
     *
     * @response 200 scenario="Loja atualizada" {
     *   "data": {
     *     "id": 1,
     *     "name": "Mais Capinhas Tijucas - Centro",
     *     "city": "Tijucas",
     *     "active": true
     *   }
     * }
     */
    public function update(UpdateStoreRequest $request, Store $store): JsonResponse
    {
        $this->authorizeAdmin($request);

        $store->update($request->only(['name', 'city', 'active']));

        return $this->success(new StoreResource($store));
    }

    /**
     * Desativar loja
     *
     * Define a loja como inativa. Lojas desativadas não aparecem
     * na listagem de lojas dos usuários comuns, mas todos os dados
     * históricos são mantidos para auditoria.
     *
     * **Importante:**
     * - Vendas, turnos e fechamentos passados são mantidos
     * - Metas e regras específicas são preservadas
     * - A loja pode ser reativada via PUT
     *
     * **Quem pode usar:** Apenas administradores.
     *
     * @urlParam store integer required ID da loja. Example: 1
     *
     * @response 200 scenario="Loja desativada" {
     *   "data": { "message": "Loja desativada com sucesso." }
     * }
     */
    public function destroy(Request $request, Store $store): JsonResponse
    {
        $this->authorizeAdmin($request);

        $store->update(['active' => false]);

        return $this->success(['message' => 'Loja desativada com sucesso.']);
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            return;
        }

        $isAdmin = $user->storeUsers()->where('role', 'admin')->exists();

        if (!$isAdmin) {
            abort(403, 'Apenas administradores podem acessar este recurso.');
        }
    }
}
