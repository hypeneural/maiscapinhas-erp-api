<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\StoreUserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserBindingRequest;
use App\Http\Traits\ApiResponse;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Administração - Vínculos Loja-Usuário
 *
 * Gerenciamento dos vínculos entre lojas e usuários (tabela `store_users`).
 *
 * Este módulo controla **qual usuário trabalha em qual loja** e com
 * **qual nível de permissão (role)**.
 *
 * **Sistema de Roles:**
 * | Role | Pode fazer |
 * |------|------------|
 * | `admin` | Tudo: gerenciar usuários, lojas, regras, confirmar comissões |
 * | `gerente` | Gerenciar equipe da loja, aprovar fechamentos, ver dashboards |
 * | `conferente` | Aprovar/rejeitar fechamentos de caixa |
 * | `vendedor` | Registrar vendas, submeter fechamento do próprio turno |
 *
 * **Regras de Negócio:**
 * - Um usuário pode ter roles diferentes em lojas diferentes
 * - A role `admin` em qualquer loja dá acesso administrativo global
 * - Vendedor "volante" pode trabalhar temporariamente em outra loja
 * - Não é possível remover seu próprio vínculo (proteção)
 *
 * **Vínculo × Comissão:**
 * - Vendas são sempre registradas na loja onde ocorreram (`store_id` da venda)
 * - Comissão vai para o vendedor que realizou a venda (`seller_id`)
 * - Meta é calculada pela loja + split configurado
 */
class StoreUserController extends Controller
{
    use ApiResponse;

    /**
     * Listar usuários de uma loja
     *
     * Retorna todos os usuários vinculados a uma loja específica,
     * com suas respectivas roles e status.
     *
     * **Dados retornados por vínculo:**
     * - `user_id` - ID do usuário
     * - `user_name` - Nome do usuário
     * - `user_email` - Email do usuário
     * - `user_active` - Se o usuário está ativo
     * - `role` - Role do usuário nesta loja
     * - `created_at` - Quando o vínculo foi criado
     *
     * **Quem pode usar:** Apenas administradores.
     *
     * @urlParam store integer required ID da loja. Example: 1
     *
     * @response 200 scenario="Usuários da loja" {
     *   "data": [
     *     {
     *       "user_id": 2,
     *       "user_name": "Carlos Gerente",
     *       "user_email": "carlos.gerente@maiscapinhas.com.br",
     *       "user_active": true,
     *       "role": "gerente",
     *       "created_at": "2026-01-01T00:00:00Z"
     *     },
     *     {
     *       "user_id": 6,
     *       "user_name": "João Vendedor",
     *       "user_email": "joao.vendedor@maiscapinhas.com.br",
     *       "user_active": true,
     *       "role": "vendedor",
     *       "created_at": "2026-01-01T00:00:00Z"
     *     }
     *   ]
     * }
     */
    public function index(Request $request, Store $store): JsonResponse
    {
        $this->authorizeAdmin($request);

        $bindings = StoreUser::where('store_id', $store->id)
            ->with('user:id,name,email,active')
            ->get()
            ->map(fn($su) => [
                'user_id' => $su->user_id,
                'user_name' => $su->user->name,
                'user_email' => $su->user->email,
                'user_active' => $su->user->active,
                'role' => $su->role,
                'created_at' => $su->created_at?->toIso8601String(),
            ]);

        return $this->success($bindings);
    }

    /**
     * Vincular usuário a loja
     *
     * Cria um novo vínculo entre um usuário existente e uma loja,
     * definindo qual role esse usuário terá nessa loja.
     *
     * **Casos de uso:**
     * - Adicionar novo vendedor à equipe de uma loja
     * - Promover funcionário a gerente em determinada loja
     * - Configurar vendedor "volante" para trabalhar temporariamente
     *
     * **Regras de Negócio:**
     * - Um usuário só pode ter um vínculo por loja (unique: store_id + user_id)
     * - Para alterar a role, use PUT
     * - O usuário deve existir previamente (criar via POST /admin/users)
     *
     * **Quem pode usar:** Apenas administradores.
     *
     * @urlParam store integer required ID da loja. Example: 1
     * @bodyParam user_id integer required ID do usuário a vincular. Example: 8
     * @bodyParam role string required Role: admin, gerente, conferente ou vendedor. Example: vendedor
     *
     * @response 201 scenario="Vínculo criado" {
     *   "data": {
     *     "user_id": 8,
     *     "store_id": 1,
     *     "role": "vendedor"
     *   }
     * }
     *
     * @response 422 scenario="Usuário já vinculado" {
     *   "message": "The given data was invalid.",
     *   "errors": { "user_id": ["Este usuário já está vinculado a esta loja."] }
     * }
     */
    public function store(StoreUserBindingRequest $request, Store $store): JsonResponse
    {
        $this->authorizeAdmin($request);

        $binding = StoreUser::create([
            'store_id' => $store->id,
            'user_id' => $request->input('user_id'),
            'role' => $request->input('role'),
        ]);

        return $this->created([
            'user_id' => $binding->user_id,
            'store_id' => $binding->store_id,
            'role' => $binding->role,
        ]);
    }

    /**
     * Atualizar role do usuário na loja
     *
     * Altera a role de um usuário em uma loja específica.
     *
     * **Casos de uso:**
     * - Promover vendedor a conferente
     * - Rebaixar gerente a conferente
     * - Dar acesso admin a um gerente
     *
     * **Quem pode usar:** Apenas administradores.
     *
     * @urlParam store integer required ID da loja. Example: 1
     * @urlParam user integer required ID do usuário no vínculo. Example: 6
     * @bodyParam role string required Nova role: admin, gerente, conferente ou vendedor. Example: gerente
     *
     * @response 200 scenario="Role atualizada" {
     *   "data": {
     *     "user_id": 6,
     *     "store_id": 1,
     *     "role": "gerente"
     *   }
     * }
     *
     * @response 404 scenario="Vínculo não existe" {
     *   "message": "Usuário não está vinculado a esta loja."
     * }
     */
    public function update(StoreUserBindingRequest $request, Store $store, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);

        $binding = StoreUser::where('store_id', $store->id)
            ->where('user_id', $user->id)
            ->first();

        if (!$binding) {
            return $this->notFound('Usuário não está vinculado a esta loja.');
        }

        $binding->update(['role' => $request->input('role')]);

        return $this->success([
            'user_id' => $binding->user_id,
            'store_id' => $binding->store_id,
            'role' => $binding->role,
        ]);
    }

    /**
     * Remover vínculo
     *
     * Remove completamente o vínculo de um usuário com uma loja.
     *
     * **Atenção:**
     * - O usuário perde todo acesso a esta loja
     * - Dados históricos (vendas, fechamentos) são mantidos
     * - Para desativar temporariamente, considere alterar a role
     *
     * **Regras de Negócio:**
     * - Não é possível remover seu próprio vínculo (proteção)
     * - Se o usuário só tinha vínculo com esta loja, ficará "órfão"
     *
     * **Quem pode usar:** Apenas administradores.
     *
     * @urlParam store integer required ID da loja. Example: 1
     * @urlParam user integer required ID do usuário. Example: 8
     *
     * @response 200 scenario="Vínculo removido" {
     *   "data": { "message": "Vínculo removido com sucesso." }
     * }
     *
     * @response 403 scenario="Tentativa de auto-remoção" {
     *   "message": "Você não pode remover seu próprio vínculo."
     * }
     *
     * @response 404 scenario="Vínculo não encontrado" {
     *   "message": "Vínculo não encontrado."
     * }
     */
    public function destroy(Request $request, Store $store, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);

        // Prevent admin from removing themselves
        if ($request->user()->id === $user->id) {
            return $this->forbidden('Você não pode remover seu próprio vínculo.');
        }

        $deleted = StoreUser::where('store_id', $store->id)
            ->where('user_id', $user->id)
            ->delete();

        if (!$deleted) {
            return $this->notFound('Vínculo não encontrado.');
        }

        return $this->success(['message' => 'Vínculo removido com sucesso.']);
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
