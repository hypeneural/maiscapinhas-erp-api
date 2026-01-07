<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Http\Traits\ApiResponse;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * @group Administração - Usuários
 *
 * Gerenciamento centralizado de usuários do sistema MaisCapinhas ERP.
 *
 * Este módulo permite criar, editar, listar e desativar usuários,
 * além de vincular usuários a lojas com roles específicas.
 *
 * **Modelo de Permissões:**
 * - Cada usuário pode estar vinculado a múltiplas lojas
 * - Em cada loja, o usuário tem uma role: `admin`, `gerente`, `conferente` ou `vendedor`
 * - Usuários desativados perdem acesso a todos os endpoints protegidos
 *
 * **Regras de Negócio:**
 * - Email deve ser único no sistema
 * - Não é possível desativar o próprio usuário
 * - Ao desativar, todos os tokens de acesso são revogados
 *
 * **Auditoria:** Todas as operações são registradas via Spatie ActivityLog.
 */
class UserController extends Controller
{
    use ApiResponse;

    /**
     * Listar todos os usuários
     *
     * Retorna uma lista paginada de todos os usuários cadastrados no sistema,
     * incluindo suas lojas vinculadas e respectivas roles.
     *
     * Este endpoint é útil para:
     * - Visão geral da equipe
     * - Buscar usuários por nome/email
     * - Filtrar por status ativo/inativo
     * - Ver quais usuários estão em determinada loja
     *
     * **Quem pode usar:** Apenas administradores.
     *
     * **Dados retornados por usuário:**
     * - `id`, `name`, `email`, `active` - dados básicos
     * - `stores[]` - lista de lojas com `store_id`, `store_name`, `role`
     *
     * @queryParam search string Busca parcial por nome ou email (case insensitive). Example: joao
     * @queryParam active boolean Filtrar por status: true (ativos) ou false (inativos). Example: true
     * @queryParam store_id integer Filtrar apenas usuários vinculados a esta loja. Example: 1
     * @queryParam per_page integer Quantidade por página (1-100, default: 25). Example: 25
     *
     * @response 200 scenario="Lista de usuários" {
     *   "data": [
     *     {
     *       "id": 1,
     *       "name": "Admin Master",
     *       "email": "admin@maiscapinhas.com.br",
     *       "active": true,
     *       "created_at": "2026-01-01T00:00:00Z",
     *       "stores": [
     *         { "store_id": 1, "store_name": "Mais Capinhas Tijucas", "role": "admin" },
     *         { "store_id": 2, "store_name": "Mais Capinhas Itapema", "role": "admin" }
     *       ]
     *     }
     *   ],
     *   "meta": { "current_page": 1, "per_page": 25, "total": 10, "last_page": 1 }
     * }
     *
     * @response 403 scenario="Sem permissão" {
     *   "message": "Apenas administradores podem acessar este recurso."
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $query = User::with(['storeUsers.store:id,name']);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        if ($request->filled('store_id')) {
            $query->whereHas('storeUsers', function ($q) use ($request) {
                $q->where('store_id', $request->input('store_id'));
            });
        }

        $users = $query->orderBy('name')->paginate($request->input('per_page', 25));

        return $this->paginated($users, UserResource::class);
    }

    /**
     * Criar novo usuário
     *
     * Cadastra um novo usuário no sistema, opcionalmente já vinculando-o
     * a uma ou mais lojas com roles específicas.
     *
     * Este endpoint é utilizado para:
     * - Onboarding de novos funcionários
     * - Criar usuários administrativos
     * - Configurar equipe de vendas
     *
     * **Fluxo recomendado:**
     * 1. Criar usuário com dados básicos + senha temporária
     * 2. Vincular às lojas onde irá trabalhar
     * 3. Compartilhar credenciais com o novo usuário
     *
     * **Regras de Negócio:**
     * - Email deve ser único (validação 422 se duplicado)
     * - Senha deve ter mínimo 8 caracteres
     * - Vínculos são opcionais na criação (podem ser adicionados depois)
     * - Se informar vínculos, `store_id` deve existir e `role` deve ser válida
     *
     * **Quem pode usar:** Apenas administradores.
     *
     * @bodyParam name string required Nome completo do usuário. Example: João Silva Santos
     * @bodyParam email string required Email único para login. Example: joao.silva@maiscapinhas.com.br
     * @bodyParam password string required Senha de acesso (mínimo 8 caracteres). Example: senha123456
     * @bodyParam active boolean Define se usuário está ativo (default: true). Example: true
     * @bodyParam stores array Lista de lojas para vincular na criação.
     * @bodyParam stores.*.store_id integer required ID da loja existente. Example: 1
     * @bodyParam stores.*.role string required Role: admin, gerente, conferente ou vendedor. Example: vendedor
     *
     * @response 201 scenario="Usuário criado com vínculos" {
     *   "data": {
     *     "id": 11,
     *     "name": "João Silva Santos",
     *     "email": "joao.silva@maiscapinhas.com.br",
     *     "active": true,
     *     "created_at": "2026-01-07T17:00:00Z",
     *     "stores": [
     *       { "store_id": 1, "store_name": "Mais Capinhas Tijucas", "role": "vendedor" }
     *     ]
     *   }
     * }
     *
     * @response 422 scenario="Email duplicado" {
     *   "message": "The given data was invalid.",
     *   "errors": { "email": ["Este email já está em uso."] }
     * }
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $user = DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'password' => Hash::make($request->input('password')),
                'active' => $request->input('active', true),
            ]);

            // Attach to stores if provided
            if ($request->has('stores')) {
                foreach ($request->input('stores') as $storeData) {
                    StoreUser::create([
                        'store_id' => $storeData['store_id'],
                        'user_id' => $user->id,
                        'role' => $storeData['role'],
                    ]);
                }
            }

            return $user;
        });

        return $this->created(new UserResource($user->load('storeUsers.store')));
    }

    /**
     * Ver detalhes do usuário
     *
     * Retorna informações completas de um usuário específico,
     * incluindo todas as lojas vinculadas e suas respectivas roles.
     *
     * Útil para:
     * - Verificar acessos do usuário
     * - Editar informações
     * - Auditar permissões
     *
     * **Quem pode usar:** Apenas administradores.
     *
     * **Dados retornados:**
     * - `id`, `name`, `email`, `active`, `created_at`, `updated_at`
     * - `stores[]` - array de lojas com role em cada uma
     *
     * @urlParam user integer required ID do usuário. Example: 6
     *
     * @response 200 scenario="Detalhes do usuário" {
     *   "data": {
     *     "id": 6,
     *     "name": "João Vendedor",
     *     "email": "joao.vendedor@maiscapinhas.com.br",
     *     "active": true,
     *     "created_at": "2026-01-01T00:00:00Z",
     *     "updated_at": "2026-01-07T12:00:00Z",
     *     "stores": [
     *       { "store_id": 1, "store_name": "Mais Capinhas Tijucas", "role": "vendedor" }
     *     ]
     *   }
     * }
     *
     * @response 404 scenario="Usuário não encontrado" {
     *   "message": "No query results for model [App\\Models\\User] 999"
     * }
     */
    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);

        return $this->success(new UserResource($user->load('storeUsers.store')));
    }

    /**
     * Atualizar usuário
     *
     * Atualiza dados cadastrais de um usuário existente.
     * Apenas os campos informados serão alterados.
     *
     * **Casos de uso:**
     * - Corrigir nome ou email
     * - Alterar senha
     * - Ativar/desativar usuário
     *
     * **Regras de Negócio:**
     * - Email deve continuar único (validação considera o próprio usuário)
     * - Para gerenciar vínculos com lojas, use os endpoints de vínculos
     *
     * **Quem pode usar:** Apenas administradores.
     *
     * @urlParam user integer required ID do usuário a atualizar. Example: 6
     * @bodyParam name string Novo nome completo. Example: João da Silva Atualizado
     * @bodyParam email string Novo email (deve ser único). Example: joao.novo@maiscapinhas.com.br
     * @bodyParam password string Nova senha (mínimo 8 caracteres). Example: novasenha123
     * @bodyParam active boolean Ativar (true) ou desativar (false). Example: true
     *
     * @response 200 scenario="Usuário atualizado" {
     *   "data": {
     *     "id": 6,
     *     "name": "João da Silva Atualizado",
     *     "email": "joao.vendedor@maiscapinhas.com.br",
     *     "active": true
     *   }
     * }
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->only(['name', 'email', 'active']);

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->input('password'));
        }

        $user->update($data);

        return $this->success(new UserResource($user->load('storeUsers.store')));
    }

    /**
     * Desativar usuário
     *
     * Define o usuário como inativo (`active = false`) e revoga
     * todos os tokens de acesso, forçando logout imediato.
     *
     * **Importante:**
     * - Não exclui o usuário do banco (soft disable)
     * - Dados históricos são mantidos para auditoria
     * - Usuário pode ser reativado posteriormente via PUT
     *
     * **Regras de Negócio:**
     * - Não é possível desativar seu próprio usuário (proteção)
     * - Usuários desativados não conseguem fazer login
     * - Tokens existentes são invalidados imediatamente
     *
     * **Quem pode usar:** Apenas administradores.
     *
     * @urlParam user integer required ID do usuário a desativar. Example: 6
     *
     * @response 200 scenario="Usuário desativado" {
     *   "data": { "message": "Usuário desativado com sucesso." }
     * }
     *
     * @response 403 scenario="Tentativa de auto-desativação" {
     *   "message": "Você não pode desativar seu próprio usuário."
     * }
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);

        // Prevent self-deletion
        if ($user->id === $request->user()->id) {
            return $this->forbidden('Você não pode desativar seu próprio usuário.');
        }

        $user->update(['active' => false]);

        // Revoke all tokens
        $user->tokens()->delete();

        return $this->success(['message' => 'Usuário desativado com sucesso.']);
    }

    /**
     * Verify user is admin.
     */
    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        $isAdmin = $user->storeUsers()->where('role', 'admin')->exists();

        if (!$isAdmin) {
            abort(403, 'Apenas administradores podem acessar este recurso.');
        }
    }
}
