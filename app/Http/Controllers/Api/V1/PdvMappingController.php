<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PdvStoreMappingResource;
use App\Http\Resources\PdvUserMappingResource;
use App\Http\Resources\PdvUserSuggestionResource;
use App\Http\Traits\ApiResponse;
use App\Models\PdvStoreMapping;
use App\Models\PdvUserMapping;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @group Integração PDV - Mapeamentos
 *
 * Endpoints para gerenciar o vínculo entre entidades do PDV e do ERP.
 */
class PdvMappingController extends Controller
{
    use ApiResponse;

    // --- Store Mappings ---

    /**
     * Listar Mapeamentos de Lojas
     *
     * Retorna lista de vínculos entre IDs de Loja do PDV e Lojas do ERP.
     */
    public function indexStores(): JsonResponse
    {
        $mappings = PdvStoreMapping::with('store')->get();
        return $this->success(PdvStoreMappingResource::collection($mappings));
    }

    /**
     * Criar/Atualizar Mapeamento de Loja
     *
     * Vincula um ID externo de loja (PDV) a uma loja interna.
     * Verifica CNPJ para alertar inconsistências.
     */
    public function storeStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pdv_store_id' => 'required|integer',
            'store_id' => 'required|integer|exists:stores,id',
            'cnpj' => 'nullable|string',
            'alias' => 'nullable|string',
        ]);

        $store = Store::find($validated['store_id']);
        $warning = null;

        // Simple CNPJ validation/warning logic
        if (!empty($validated['cnpj']) && !empty($store->cnpj)) {
            $pdvCnpj = preg_replace('/[^0-9]/', '', $validated['cnpj']);
            $erpCnpj = preg_replace('/[^0-9]/', '', $store->cnpj);

            if ($pdvCnpj !== $erpCnpj) {
                $warning = "CNPJ mismatch: PDV ($pdvCnpj) vs ERP ($erpCnpj). Mapping saved with override.";
                Log::warning("PdvMapping: Store CNPJ mismatch", [
                    'pdv_store_id' => $validated['pdv_store_id'],
                    'store_id' => $store->id,
                    'pdv_cnpj' => $pdvCnpj,
                    'erp_cnpj' => $erpCnpj
                ]);
            }
        }

        $mapping = PdvStoreMapping::updateOrCreate(
            ['pdv_store_id' => $validated['pdv_store_id']],
            [
                'store_id' => $validated['store_id'],
                'alias' => $validated['alias'] ?? $store->name,
                'cnpj' => $validated['cnpj'] ?? null,
                'active' => true,
            ]
        );

        return $this->success([
            'data' => new PdvStoreMappingResource($mapping->load('store')),
            'message' => 'Store mapping saved successfully.',
            'warning' => $warning,
        ]);
    }

    // --- User Mappings ---

    /**
     * Listar Mapeamentos de Usuários
     *
     * Retorna lista de vínculos entre Vendedores do PDV e Usuários do ERP.
     */
    public function indexUsers(Request $request): JsonResponse
    {
        $query = PdvUserMapping::with(['user', 'storeMapping']);

        if ($request->has('store_id')) {
            // Filter by INTERNAL store ID via the relationship
            $storeId = $request->input('store_id');
            $query->whereHas('storeMapping', function ($q) use ($storeId) {
                $q->where('store_id', $storeId);
            });
        }

        if ($request->has('pdv_store_id')) {
            $query->where('store_pdv_id', $request->input('pdv_store_id'));
        }

        $mappings = $query->paginate($request->input('per_page', 50));

        return $this->success(PdvUserMappingResource::collection($mappings));
    }

    /**
     * Sugestões de Mapeamento (Inbox)
     *
     * Lista vendedores que venderam nos últimos 30 dias mas não estão mapeados.
     */
    public function suggestUsers(Request $request): JsonResponse
    {
        // 1. Find unmapped sellers active in the last 30 days
        $unmapped = DB::table('pdv_venda_itens as vi')
            ->select([
                'vi.store_pdv_id',
                'vi.vendedor_pdv_id',
                DB::raw('MAX(vi.updated_at) as last_seen_at'),
                DB::raw('COUNT(*) as sales_count')
            ])
            ->leftJoin('pdv_user_mappings as m', function ($join) {
                $join->on('m.store_pdv_id', '=', 'vi.store_pdv_id')
                    ->on('m.pdv_user_id', '=', 'vi.vendedor_pdv_id');
            })
            ->whereNull('m.id') // Only unmapped
            ->where('vi.updated_at', '>=', now()->subDays(30))
            ->groupBy('vi.store_pdv_id', 'vi.vendedor_pdv_id')
            ->orderByDesc('last_seen_at')
            ->limit(50)
            ->get();

        // 2. Try to find name and suggest match
        // We need to look up a name. Ideally `pdv_venda_itens` doesn't have names, but `pdv_usuarios` might?
        // Or maybe we captured it in `pdv_user_mappings` previously? No.
        // Let's assume we don't have the name easily unless we query `pdv_usuarios` if it exists (it does).

        $suggestions = $unmapped->map(function ($item) {
            $suggestion = null;
            $matchConfidence = 0;

            // Try to find name in pdv_usuarios (if synced)
            $pdvUser = DB::table('pdv_usuarios')
                ->where('id', $item->vendedor_pdv_id) // Assuming ID matches? Or compound key?
                // The schema for pdv_usuarios usually has 'id' as PK or similar.
                // Let's safe check. If it fails, we skip suggestion.
                ->first();

            if ($pdvUser && isset($pdvUser->nome)) {
                $item->pdv_user_name = $pdvUser->nome;

                // Fuzzy match against active ERP users
                // Simple implementation: exact match or "like"
                $match = User::where('name', 'LIKE', $pdvUser->nome)
                    ->orWhere('name', 'LIKE', "%{$pdvUser->nome}%")
                    ->first();

                if ($match) {
                    $suggestion = $match;
                    $matchConfidence = 80; // Arbitrary high confidence
                }
            }

            $item->suggested_user = $suggestion;
            $item->match_confidence = $matchConfidence;

            return $item;
        });

        return $this->success(PdvUserSuggestionResource::collection($suggestions));
    }

    /**
     * Criar/Atualizar Mapeamento de Usuário
     */
    public function storeUser(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_pdv_id' => 'required|integer',
            'pdv_user_id' => 'required|integer',
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $mapping = $this->upsertUserMapping(
            (int) $validated['store_pdv_id'],
            (int) $validated['pdv_user_id'],
            (int) $validated['user_id']
        );

        return $this->success(new PdvUserMappingResource($mapping));
    }

    /**
     * Bulk Assign Users
     */
    public function bulkStoreUsers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mappings' => 'required|array',
            'mappings.*.store_pdv_id' => 'required|integer',
            'mappings.*.pdv_user_id' => 'required|integer',
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $results = [];
        $userId = (int) $validated['user_id'];

        DB::beginTransaction();
        try {
            foreach ($validated['mappings'] as $map) {
                $results[] = $this->upsertUserMapping(
                    (int) $map['store_pdv_id'],
                    (int) $map['pdv_user_id'],
                    $userId
                );
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to process bulk mapping: ' . $e->getMessage());
        }

        return $this->success([
            'count' => count($results),
            'message' => 'Bulk mapping completed successfully.'
        ]);
    }

    /**
     * Remover Mapeamento
     */
    public function destroyUser(int $id): JsonResponse
    {
        $mapping = PdvUserMapping::findOrFail($id);
        $mapping->delete();

        return $this->success([
            'message' => 'Mapping removed successfully.'
        ]);
    }

    // --- Helpers ---

    private function upsertUserMapping(int $storePdvId, int $pdvUserId, int $userId): PdvUserMapping
    {
        // Try to fetch PDV user name for reference
        $pdvUserName = null;
        try {
            $pdvUser = DB::table('pdv_usuarios')->where('id', $pdvUserId)->first();
            $pdvUserName = $pdvUser->nome ?? null;
        } catch (\Throwable $t) {
            // Ignore if table doesn't exist or other error
        }

        return PdvUserMapping::updateOrCreate(
            [
                'store_pdv_id' => $storePdvId,
                'pdv_user_id' => $pdvUserId,
            ],
            [
                'user_id' => $userId,
                'pdv_user_name' => $pdvUserName,
                'active' => true,
                'source' => 'manual_api',
                'confidence' => 100,
            ]
        );
    }
}
