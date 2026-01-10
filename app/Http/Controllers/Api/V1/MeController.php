<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Perfil do Usuário
 *
 * Endpoint para obter informações do usuário autenticado.
 */
class MeController extends Controller
{
    use ApiResponse;

    /**
     * Obter perfil do usuário atual
     *
     * Retorna os dados do usuário autenticado, incluindo as lojas
     * às quais ele tem acesso e seu papel (role) em cada uma.
     *
     * **Quem pode usar:** Qualquer usuário autenticado.
     *
     * **Informações retornadas:**
     * - Dados básicos do usuário (id, nome, email, status ativo)
     * - Dados de perfil (whatsapp, avatar_url, instagram)
     * - Datas para celebração (birth_date, hire_date)
     * - Lista de lojas com o papel do usuário em cada uma
     *
     * **Papéis possíveis:**
     * - `admin` - Administrador global (acesso total)
     * - `gerente` - Gerente da loja (gerencia vendedores e metas)
     * - `conferente` - Confere fechamentos de caixa
     * - `vendedor` - Vendedor (registra vendas e turnos)
     *
     * @response 200 scenario="Perfil com múltiplas lojas" {
     *   "data": {
     *     "user": {
     *       "id": 1,
     *       "name": "Admin Sistema",
     *       "email": "admin@maiscapinhas.com.br",
     *       "active": true,
     *       "whatsapp": "47999999999",
     *       "avatar_url": "https://example.com/avatar.jpg",
     *       "instagram": "@maiscapinhas",
     *       "birth_date": "1990-05-15",
     *       "hire_date": "2022-01-09",
     *       "created_at": "2026-01-01T00:00:00+00:00"
     *     },
     *     "stores": [
     *       { "id": 1, "name": "Mais Capinhas Tijucas", "city": "Tijucas", "role": "admin" },
     *       { "id": 2, "name": "Mais Capinhas Itapema", "city": "Itapema", "role": "admin" }
     *     ]
     *   },
     *   "meta": { "timestamp": "2026-01-07T12:00:00Z" }
     * }
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->success([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'active' => $user->active,
                'is_super_admin' => $user->is_super_admin ?? false,
                'whatsapp' => $user->whatsapp,
                'avatar_url' => $user->avatar_url,
                'instagram' => $user->instagram,
                'birth_date' => $user->birth_date?->format('Y-m-d'),
                'hire_date' => $user->hire_date?->format('Y-m-d'),
                'created_at' => $user->created_at->toIso8601String(),
            ],
            'stores' => $user->getStoresWithRoles(),
        ]);
    }
}
