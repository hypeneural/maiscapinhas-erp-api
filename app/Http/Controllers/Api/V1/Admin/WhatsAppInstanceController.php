<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\WhatsApp\StoreWhatsAppInstanceRequest;
use App\Http\Requests\WhatsApp\UpdateWhatsAppInstanceRequest;
use App\Http\Resources\WhatsAppInstanceResource;
use App\Http\Traits\ApiResponse;
use App\Models\WhatsAppInstance;
use App\Services\WhatsApp\EvolutionClientFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * @group Administração - WhatsApp
 *
 * Gerenciamento de instâncias WhatsApp (Evolution API).
 *
 * Este módulo permite criar, editar, listar e gerenciar instâncias do WhatsApp
 * conectadas via Evolution API. Apenas super admins têm acesso.
 *
 * **Modelo de Escopos:**
 * - Global: `store_id = null` e `user_id = null` (para notificações gerais)
 * - Por Loja: `store_id` preenchido (instância específica da loja)
 * - Por Usuário: `user_id` preenchido (instância específica do usuário)
 *
 * **Secrets:**
 * - `api_key` e `token` são criptografados em repouso
 * - Nunca são retornados nas responses (apenas masked values)
 */
class WhatsAppInstanceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private EvolutionClientFactory $clientFactory,
    ) {
    }

    /**
     * Listar instâncias WhatsApp
     *
     * Retorna uma lista paginada de todas as instâncias WhatsApp cadastradas.
     *
     * @queryParam search string Busca por nome ou URL. Example: loja_01
     * @queryParam scope string Filtrar por escopo: global, store, user. Example: global
     * @queryParam store_id integer Filtrar por loja. Example: 1
     * @queryParam user_id integer Filtrar por usuário. Example: 5
     * @queryParam status string Filtrar por status: connected, disconnected, unknown, connecting. Example: connected
     * @queryParam is_active boolean Filtrar por ativo/inativo. Example: true
     * @queryParam provider string Filtrar por provedor. Example: evolution
     * @queryParam per_page integer Itens por página (1-100, default: 25). Example: 25
     *
     * @response 200 scenario="Lista de instâncias" {
     *   "data": [
     *     {
     *       "id": 1,
     *       "name": "loja_01",
     *       "scope": "global",
     *       "provider": "evolution",
     *       "base_url": "https://evolution.example.com",
     *       "status": "connected",
     *       "is_default": true,
     *       "is_active": true,
     *       "has_api_key": true,
     *       "api_key_masked": "********1234"
     *     }
     *   ],
     *   "meta": { "pagination": { "total": 1, "current_page": 1 } }
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $query = WhatsAppInstance::with(['store:id,name', 'user:id,name']);

        // Search
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('base_url', 'like', "%{$search}%")
                    ->orWhere('phone_e164', 'like', "%{$search}%");
            });
        }

        // Filter by scope
        if ($request->filled('scope')) {
            match ($request->input('scope')) {
                'global' => $query->global(),
                'store' => $query->whereNotNull('store_id'),
                'user' => $query->whereNotNull('user_id'),
                default => null,
            };
        }

        // Filter by store_id
        if ($request->filled('store_id')) {
            $query->where('store_id', $request->integer('store_id'));
        }

        // Filter by user_id
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filter by is_active
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Filter by provider
        if ($request->filled('provider')) {
            $query->where('provider', $request->input('provider'));
        }

        // Include trashed if requested
        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        $instances = $query
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return $this->paginated($instances, WhatsAppInstanceResource::class);
    }

    /**
     * Criar instância WhatsApp
     *
     * Cadastra uma nova instância WhatsApp no sistema.
     *
     * @bodyParam name string required Nome único da instância (letras, números, _ e -). Example: loja_01
     * @bodyParam base_url string required URL do servidor Evolution. Example: https://evolution.example.com
     * @bodyParam api_key string API Key do Evolution. Example: your-api-key-here
     * @bodyParam provider string Provedor (default: evolution). Example: evolution
     * @bodyParam store_id integer ID da loja (para escopo loja). Example: 1
     * @bodyParam user_id integer ID do usuário (para escopo usuário). Example: 5
     * @bodyParam is_default boolean Marcar como favorita no escopo. Example: true
     * @bodyParam is_active boolean Ativa para uso. Example: true
     *
     * @response 201 scenario="Instância criada" {
     *   "data": {
     *     "id": 1,
     *     "name": "loja_01",
     *     "scope": "global",
     *     "status": "unknown",
     *     "has_api_key": true,
     *     "api_key_masked": "********here"
     *   }
     * }
     */
    public function store(StoreWhatsAppInstanceRequest $request): JsonResponse
    {
        $instance = DB::transaction(function () use ($request) {
            $data = $request->validated();

            // If setting as default, unset others in same scope
            if ($request->boolean('is_default')) {
                $this->unsetDefaultsInScope(
                    $request->input('store_id'),
                    $request->input('user_id'),
                );
            }

            return WhatsAppInstance::create($data);
        });

        return $this->created(new WhatsAppInstanceResource($instance->load(['store', 'user'])));
    }

    /**
     * Ver detalhes da instância
     *
     * Retorna informações completas de uma instância WhatsApp.
     *
     * @urlParam instance integer required ID da instância. Example: 1
     *
     * @response 200 scenario="Detalhes" {
     *   "data": {
     *     "id": 1,
     *     "name": "loja_01",
     *     "scope": "global",
     *     "provider": "evolution",
     *     "base_url": "https://evolution.example.com",
     *     "status": "connected",
     *     "last_state": { "instance": { "state": "open" } }
     *   }
     * }
     */
    public function show(WhatsAppInstance $instance): JsonResponse
    {
        return $this->success(new WhatsAppInstanceResource($instance->load(['store', 'user'])));
    }

    /**
     * Atualizar instância
     *
     * Atualiza dados de uma instância WhatsApp.
     * Secrets só são atualizados se enviados no payload.
     *
     * @urlParam instance integer required ID da instância. Example: 1
     * @bodyParam name string Nome da instância. Example: loja_02
     * @bodyParam api_key string Nova API Key (deixe vazio para manter). Example: new-api-key
     *
     * @response 200 scenario="Atualizada" {
     *   "data": { "id": 1, "name": "loja_02" }
     * }
     */
    public function update(UpdateWhatsAppInstanceRequest $request, WhatsAppInstance $instance): JsonResponse
    {
        DB::transaction(function () use ($request, $instance) {
            $data = $request->validated();

            // Only update secrets if explicitly provided and not empty
            if (!$request->filled('api_key')) {
                unset($data['api_key']);
            }
            if (!$request->filled('token')) {
                unset($data['token']);
            }

            // If setting as default, unset others in same scope
            if ($request->boolean('is_default') && !$instance->is_default) {
                $this->unsetDefaultsInScope(
                    $data['store_id'] ?? $instance->store_id,
                    $data['user_id'] ?? $instance->user_id,
                );
            }

            $instance->update($data);
        });

        return $this->success(new WhatsAppInstanceResource($instance->fresh()->load(['store', 'user'])));
    }

    /**
     * Excluir instância (soft delete)
     *
     * Remove uma instância WhatsApp (soft delete).
     *
     * @urlParam instance integer required ID da instância. Example: 1
     *
     * @response 200 scenario="Excluída" {
     *   "data": { "message": "Instância excluída com sucesso." }
     * }
     */
    public function destroy(WhatsAppInstance $instance): JsonResponse
    {
        $instance->delete();

        return $this->success(['message' => 'Instância excluída com sucesso.']);
    }

    /**
     * Definir como favorita
     *
     * Marca a instância como favorita no seu escopo.
     * Remove a flag das outras instâncias do mesmo escopo.
     *
     * @urlParam instance integer required ID da instância. Example: 1
     *
     * @response 200 scenario="Definida como favorita" {
     *   "data": { "message": "Instância definida como favorita.", "instance": { "id": 1, "is_default": true } }
     * }
     */
    public function setDefault(WhatsAppInstance $instance): JsonResponse
    {
        DB::transaction(function () use ($instance) {
            $this->unsetDefaultsInScope($instance->store_id, $instance->user_id);
            $instance->update(['is_default' => true]);
        });

        return $this->success([
            'message' => 'Instância definida como favorita.',
            'instance' => new WhatsAppInstanceResource($instance->fresh()),
        ]);
    }

    /**
     * Limpar API Key
     *
     * Remove a API Key da instância.
     *
     * @urlParam instance integer required ID da instância. Example: 1
     *
     * @response 200 scenario="API Key removida" {
     *   "data": { "message": "API Key removida.", "has_api_key": false }
     * }
     */
    public function clearApiKey(WhatsAppInstance $instance): JsonResponse
    {
        $instance->api_key = null;
        $instance->save();

        return $this->success([
            'message' => 'API Key removida.',
            'has_api_key' => false,
        ]);
    }

    /**
     * Limpar Token
     *
     * Remove o Token da instância.
     *
     * @urlParam instance integer required ID da instância. Example: 1
     *
     * @response 200 scenario="Token removido" {
     *   "data": { "message": "Token removido.", "has_token": false }
     * }
     */
    public function clearToken(WhatsAppInstance $instance): JsonResponse
    {
        $instance->token = null;
        $instance->save();

        return $this->success([
            'message' => 'Token removido.',
            'has_token' => false,
        ]);
    }

    /**
     * Verificar estado da conexão
     *
     * Consulta o estado atual da instância na Evolution API
     * e atualiza o status local.
     *
     * @urlParam instance integer required ID da instância. Example: 1
     *
     * @response 200 scenario="Estado atualizado" {
     *   "data": {
     *     "status": "connected",
     *     "last_state": { "instance": { "state": "open" } },
     *     "last_state_checked_at": "2026-01-13T17:00:00Z"
     *   }
     * }
     *
     * @response 422 scenario="Sem API Key" {
     *   "message": "Instância sem API Key configurada."
     * }
     *
     * @response 502 scenario="Erro no provedor" {
     *   "message": "Erro ao consultar Evolution API.",
     *   "details": { "status": 500, "data": null }
     * }
     */
    public function state(WhatsAppInstance $instance): JsonResponse
    {
        try {
            $client = $this->clientFactory->make($instance);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $result = $client->connectionState();

        if (!$result['ok']) {
            return $this->error('Erro ao consultar Evolution API.', 502, [
                'provider_status' => $result['status'],
                'provider_data' => $result['data'],
            ]);
        }

        // Map Evolution state to internal status
        $evolutionState = $result['data']['instance']['state'] ?? 'unknown';
        $status = WhatsAppInstance::mapEvolutionState($evolutionState);

        // Update instance
        $instance->update([
            'status' => $status,
            'last_state' => $result['data'],
            'last_state_checked_at' => now(),
        ]);

        return $this->success([
            'status' => $status,
            'evolution_state' => $evolutionState,
            'last_state' => $result['data'],
            'last_state_checked_at' => $instance->last_state_checked_at->toIso8601String(),
        ]);
    }

    /**
     * Conectar instância (obter QR Code)
     *
     * Solicita conexão da instância na Evolution API.
     * Retorna o código para gerar QR Code no frontend.
     *
     * **Renderização do QR:**
     * Use o campo `code` com uma biblioteca de QR code no frontend.
     * O campo `pairingCode` é o código alfanumérico alternativo.
     *
     * @urlParam instance integer required ID da instância. Example: 1
     *
     * @response 200 scenario="QR Code gerado" {
     *   "data": {
     *     "type": "qr_text",
     *     "code": "2@y8eK+bjtEjUWy9/FOM...",
     *     "pairingCode": "WZYEH1YY",
     *     "expires_in": 60
     *   }
     * }
     */
    public function connect(WhatsAppInstance $instance): JsonResponse
    {
        try {
            $client = $this->clientFactory->make($instance);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $result = $client->connect();

        if (!$result['ok']) {
            return $this->error('Erro ao conectar na Evolution API.', 502, [
                'provider_status' => $result['status'],
                'provider_data' => $result['data'],
            ]);
        }

        // Update status to connecting
        $instance->update([
            'status' => 'connecting',
            'last_state' => $result['data'],
            'last_state_checked_at' => now(),
        ]);

        $data = $result['data'];

        return $this->success([
            'type' => 'qr_text',
            'code' => $data['code'] ?? $data['base64'] ?? null,
            'pairingCode' => $data['pairingCode'] ?? null,
            'expires_in' => 60,
        ], 200, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Testar conexão
     *
     * Executa um teste rápido de conexão com a Evolution API.
     *
     * @urlParam instance integer required ID da instância. Example: 1
     *
     * @response 200 scenario="Teste OK" {
     *   "data": { "ok": true, "status": "connected" }
     * }
     */
    public function test(WhatsAppInstance $instance): JsonResponse
    {
        try {
            $client = $this->clientFactory->make($instance);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $result = $client->connectionState();

        if (!$result['ok']) {
            return $this->success([
                'ok' => false,
                'error' => 'Falha ao comunicar com Evolution API.',
                'provider_status' => $result['status'],
            ]);
        }

        $evolutionState = $result['data']['instance']['state'] ?? 'unknown';

        return $this->success([
            'ok' => true,
            'status' => WhatsAppInstance::mapEvolutionState($evolutionState),
            'evolution_state' => $evolutionState,
        ]);
    }

    /**
     * Unset is_default for all instances in the same scope.
     */
    private function unsetDefaultsInScope(?int $storeId, ?int $userId): void
    {
        $query = WhatsAppInstance::where('is_default', true);

        if ($userId) {
            $query->where('user_id', $userId);
        } elseif ($storeId) {
            $query->where('store_id', $storeId)->whereNull('user_id');
        } else {
            $query->whereNull('store_id')->whereNull('user_id');
        }

        $query->update(['is_default' => false]);
    }
}
