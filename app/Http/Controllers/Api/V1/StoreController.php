<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Lojas
 *
 * Endpoints para consultar lojas às quais o usuário tem acesso.
 * O usuário só pode visualizar lojas onde está vinculado via `store_users`.
 */
class StoreController extends Controller
{
    use ApiResponse;

    /**
     * Listar lojas do usuário
     *
     * Retorna todas as lojas ativas às quais o usuário autenticado tem acesso.
     * Cada loja inclui o papel (role) do usuário naquela loja.
     *
     * **Quem pode usar:** Qualquer usuário autenticado.
     *
     * **Regras:**
     * - Apenas lojas ativas (`active = true`) são retornadas
     * - Apenas lojas onde o usuário está vinculado são listadas
     *
     * @response 200 scenario="Lista de lojas" {
     *   "data": [
     *     { "id": 1, "name": "Mais Capinhas Tijucas", "city": "Tijucas", "role": "admin" },
     *     { "id": 2, "name": "Mais Capinhas Itapema", "city": "Itapema", "role": "gerente" }
     *   ],
     *   "meta": { "timestamp": "2026-01-07T12:00:00Z" }
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $stores = Store::whereIn('id', $user->storeUsers()->pluck('store_id'))
            ->where('active', true)
            ->get()
            ->map(fn(Store $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'city' => $store->city,
                'role' => $user->roleInStore($store->id),
            ]);

        return $this->success($stores);
    }

    /**
     * Obter detalhes de uma loja
     *
     * Retorna os detalhes de uma loja específica, se o usuário tiver acesso.
     *
     * **Quem pode usar:** Usuários com vínculo na loja.
     *
     * **Erros possíveis:**
     * - `403` - Usuário não tem acesso a esta loja
     * - `404` - Loja não encontrada
     *
     * @urlParam store integer required ID da loja. Example: 1
     *
     * @response 200 scenario="Loja encontrada" {
     *   "data": {
     *     "id": 1,
     *     "name": "Mais Capinhas Tijucas",
     *     "city": "Tijucas",
     *     "active": true,
     *     "role": "admin",
     *     "created_at": "2026-01-01T00:00:00+00:00"
     *   },
     *   "meta": { "timestamp": "2026-01-07T12:00:00Z" }
     * }
     *
     * @response 403 scenario="Sem acesso" {
     *   "error": {
     *     "code": 403,
     *     "message": "You do not have access to this store."
     *   }
     * }
     */
    public function show(Request $request, Store $store): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasAccessToStore($store->id)) {
            return $this->forbidden('You do not have access to this store.');
        }

        return $this->success([
            'id' => $store->id,
            'name' => $store->name,
            'codigo' => $store->codigo,
            'city' => $store->city,
            'active' => $store->active,
            'troco_padrao' => (float) $store->troco_padrao,
            'role' => $user->roleInStore($store->id),
            'created_at' => $store->created_at->toIso8601String(),
        ]);
    }

    /**
     * Listar vendedores de uma loja
     *
     * Retorna todos os vendedores ativos vinculados a uma loja,
     * com estatísticas do mês atual.
     *
     * **Quem pode usar:** Gerentes e Admins da loja.
     *
     * @urlParam store integer required ID da loja. Example: 1
     * @queryParam month string Mês para estatísticas (YYYY-MM). Example: 2026-01
     *
     * @response 200 scenario="Lista de vendedores" {
     *   "data": [
     *     {
     *       "id": 5,
     *       "name": "João Silva",
     *       "avatar_url": "https://...",
     *       "role": "vendedor",
     *       "total_sold_mtd": 45000.00,
     *       "goal": 50000.00,
     *       "achievement_rate": 90.00,
     *       "ranking_position": 2
     *     }
     *   ]
     * }
     */
    public function sellers(Request $request, Store $store): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasAccessToStore($store->id)) {
            return $this->forbidden('You do not have access to this store.');
        }

        $request->validate([
            'month' => ['sometimes', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $month = $request->input('month', now()->format('Y-m'));
        $startOfMonth = \Carbon\Carbon::parse($month . '-01')->startOfMonth();
        $endOfMonth = \Carbon\Carbon::parse($month . '-01')->endOfMonth();

        // Get sellers from store_users
        $storeUsers = $store->storeUsers()
            ->with('user')
            ->whereHas('user', fn($q) => $q->where('active', true))
            ->get();

        // Get sales totals
        $salesTotals = \App\Models\Sale::where('store_id', $store->id)
            ->whereBetween('sold_at', [$startOfMonth, $endOfMonth->endOfDay()])
            ->groupBy('seller_id')
            ->selectRaw('seller_id, SUM(amount) as total_sold')
            ->pluck('total_sold', 'seller_id');

        // Get goals
        $goal = \App\Models\StoreMonthlyGoal::forStore($store->id)
            ->forMonth($month)
            ->first();

        $splits = $goal?->splits()->get()->keyBy('user_id') ?? collect();

        // Build ranking by sales
        $sellersData = $storeUsers->map(function ($su) use ($salesTotals, $splits) {
            $userId = $su->user_id;
            $totalSold = $salesTotals->get($userId, 0);
            $goalAmount = $splits->get($userId)?->goal_amount ?? 0;
            $achievement = $goalAmount > 0 ? round(($totalSold / $goalAmount) * 100, 2) : 0;

            return [
                'id' => $userId,
                'name' => $su->user?->name,
                'avatar_url' => $su->user?->avatar_url,
                'role' => $su->role,
                'total_sold_mtd' => (float) $totalSold,
                'goal' => (float) $goalAmount,
                'achievement_rate' => $achievement,
            ];
        })->sortByDesc('total_sold_mtd')->values();

        // Add ranking position
        $rankPosition = 0;
        $sellers = $sellersData->map(function ($seller) use (&$rankPosition) {
            $rankPosition++;
            $seller['ranking_position'] = $rankPosition;
            return $seller;
        });

        return $this->success($sellers);
    }

    /**
     * Atualizar foto da loja (fachada)
     *
     * Permite atualizar a foto de fachada da loja.
     *
     * **Quem pode usar:**
     * - Admins (qualquer loja)
     * - Gerentes (apenas sua loja)
     *
     * **Validações:**
     * - Tipos: jpg, jpeg, png, webp
     * - Tamanho máximo: 5MB
     * - Dimensões mínimas: 800x600px
     *
     * @urlParam store integer required ID da loja. Example: 1
     * @bodyParam photo file required Arquivo de imagem da fachada. Example: fachada.jpg
     * @bodyParam remove boolean Remover foto atual. Example: false
     *
     * @response 200 scenario="Foto atualizada" {
     *   "data": {
     *     "store_id": 1,
     *     "photo_url": "https://api.maiscapinhas.com.br/storage/stores/1/photo.jpg"
     *   },
     *   "meta": { "request_id": "uuid", "timestamp": "2026-01-07T12:00:00Z" }
     * }
     *
     * @response 403 scenario="Sem permissão" {
     *   "error": { "code": 403, "message": "Você não tem permissão para atualizar esta foto." }
     * }
     */
    public function updatePhoto(Request $request, Store $store): JsonResponse
    {
        $user = $request->user();

        // Authorization: admin or gerente of this store
        $isAdmin = $user->storeUsers()
            ->where('role', \App\Enums\StoreUserRole::ADMIN->value)
            ->exists();

        $isGerenteOfStore = $user->storeUsers()
            ->where('store_id', $store->id)
            ->where('role', \App\Enums\StoreUserRole::GERENTE->value)
            ->exists();

        if (!$isAdmin && !$isGerenteOfStore) {
            return $this->forbidden('Você não tem permissão para atualizar esta foto.');
        }

        // Handle remove
        if ($request->boolean('remove')) {
            if ($store->photo_url) {
                $oldPath = $this->extractPathFromUrl($store->photo_url);
                if ($oldPath) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($oldPath);
                }

                $store->update(['photo_url' => null]);
            }

            return $this->success([
                'store_id' => $store->id,
                'photo_url' => null,
            ]);
        }

        // Validate file
        $request->validate([
            'photo' => [
                'required',
                \Illuminate\Validation\Rules\File::image()
                    ->types(['jpg', 'jpeg', 'png', 'webp'])
                    ->max(5 * 1024) // 5MB
                    ->dimensions(
                        \Illuminate\Validation\Rules\Dimensions::create()
                            ->minWidth(800)
                            ->minHeight(600)
                    ),
            ],
        ]);

        // Delete old photo if exists
        if ($store->photo_url) {
            $oldPath = $this->extractPathFromUrl($store->photo_url);
            if ($oldPath) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($oldPath);
            }
        }

        // Store new photo
        $file = $request->file('photo');
        $extension = $file->getClientOriginalExtension();
        $filename = 'photo.' . $extension;
        $path = $file->storeAs("stores/{$store->id}", $filename, 'public');

        $photoUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($path);

        $store->update(['photo_url' => $photoUrl]);

        return $this->success([
            'store_id' => $store->id,
            'photo_url' => $photoUrl,
        ]);
    }

    /**
     * Extract storage path from full URL.
     */
    private function extractPathFromUrl(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        $storagePath = parse_url($url, PHP_URL_PATH);
        if ($storagePath && str_contains($storagePath, '/storage/')) {
            return str_replace('/storage/', '', $storagePath);
        }

        return null;
    }
}


