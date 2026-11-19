<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\TvWebhook;
use App\Models\TvWebhookDelivery;
use App\Services\Tv\TvHealthService;
use App\Services\Tv\TvWebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TvWebhookAdminController extends Controller
{
    public function __construct(
        private TvWebhookDispatcher $dispatcher,
        private TvHealthService $healthService
    ) {
    }

    /**
     * Listar webhooks configurados, catálogo de eventos suportados e URLs dos endpoints públicos Pull.
     */
    public function index(): JsonResponse
    {
        $webhooks = TvWebhook::query()
            ->with('creator:id,name')
            ->withCount('deliveries')
            ->orderByDesc('id')
            ->get();

        $apiBase = rtrim((string) config('app.url'), '/');

        return response()->json([
            'success' => true,
            'data' => $webhooks,
            'meta' => [
                'supported_events' => config('tv.supported_events', []),
                'default_env_webhook_configured' => !empty(config('tv.default_webhook_url')),
                'public_endpoints' => [
                    'fleet_status' => "{$apiBase}/api/v1/tv/status",
                    'screen_status_template' => "{$apiBase}/api/v1/tv/status/{slug}",
                    'history_feed' => "{$apiBase}/api/v1/tv/history",
                ],
                'thresholds' => [
                    'heartbeat_interval_seconds' => (int) config('tv.heartbeat_interval_seconds', 30),
                    'online_threshold_seconds' => (int) config('tv.online_threshold_seconds', 90),
                    'offline_threshold_seconds' => (int) config('tv.offline_threshold_seconds', 180),
                ],
            ],
        ]);
    }

    /**
     * Cadastrar novo webhook de saída (Push).
     */
    public function store(Request $request): JsonResponse
    {
        $validEventKeys = array_merge(['*'], array_keys((array) config('tv.supported_events', [])));

        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'url' => 'required|url|max:500',
            'is_active' => 'nullable|boolean',
            'events' => 'nullable|array|min:1',
            'events.*' => 'string|in:' . implode(',', $validEventKeys),
            'store_ids' => 'nullable|array',
            'store_ids.*' => 'integer|exists:stores,id',
            'only_during_business_hours' => 'nullable|boolean',
            'cooldown_seconds' => 'nullable|integer|min:0|max:86400',
            'custom_headers' => 'nullable|array',
        ]);

        if ($urlError = $this->dispatcher->validateTargetUrl($validated['url'])) {
            return response()->json([
                'success' => false,
                'message' => $urlError,
                'errors' => ['url' => [$urlError]],
            ], 422);
        }

        $webhook = TvWebhook::create([
            'name' => trim($validated['name']),
            'url' => trim($validated['url']),
            'is_active' => $validated['is_active'] ?? true,
            'events' => $validated['events'] ?? config('tv.default_webhook_events', ['tv.went_offline', 'tv.came_online']),
            'store_ids' => !empty($validated['store_ids']) ? array_values(array_unique(array_map('intval', $validated['store_ids']))) : null,
            'only_during_business_hours' => (bool) ($validated['only_during_business_hours'] ?? false),
            'cooldown_seconds' => (int) ($validated['cooldown_seconds'] ?? config('tv.webhook_default_cooldown_seconds', 60)),
            'custom_headers' => $validated['custom_headers'] ?? null,
            'created_by_user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Alerta cadastrado com sucesso!',
            'data' => $webhook->load('creator:id,name'),
        ], 201);
    }

    /**
     * Atualizar webhook existente.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $webhook = TvWebhook::findOrFail($id);
        $validEventKeys = array_merge(['*'], array_keys((array) config('tv.supported_events', [])));

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'url' => 'sometimes|required|url|max:500',
            'is_active' => 'sometimes|boolean',
            'events' => 'sometimes|array|min:1',
            'events.*' => 'string|in:' . implode(',', $validEventKeys),
            'store_ids' => 'nullable|array',
            'store_ids.*' => 'integer|exists:stores,id',
            'only_during_business_hours' => 'sometimes|boolean',
            'cooldown_seconds' => 'sometimes|integer|min:0|max:86400',
            'custom_headers' => 'nullable|array',
        ]);

        if (isset($validated['url']) && ($urlError = $this->dispatcher->validateTargetUrl($validated['url']))) {
            return response()->json([
                'success' => false,
                'message' => $urlError,
                'errors' => ['url' => [$urlError]],
            ], 422);
        }

        if (array_key_exists('store_ids', $validated)) {
            $validated['store_ids'] = !empty($validated['store_ids'])
                ? array_values(array_unique(array_map('intval', $validated['store_ids'])))
                : null;
        }

        $webhook->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Alerta atualizado com sucesso!',
            'data' => $webhook->fresh()->load('creator:id,name'),
        ]);
    }

    /**
     * Excluir webhook.
     */
    public function destroy(int $id): JsonResponse
    {
        $webhook = TvWebhook::findOrFail($id);
        $webhook->delete();

        return response()->json([
            'success' => true,
            'message' => 'Alerta removido com sucesso.',
        ]);
    }

    /**
     * Enviar evento de teste síncrono para o webhook e retornar o resultado HTTP imediato.
     */
    public function test(int $id): JsonResponse
    {
        $webhook = TvWebhook::findOrFail($id);

        if ($urlError = $this->dispatcher->validateTargetUrl($webhook->url)) {
            return response()->json([
                'success' => false,
                'message' => $urlError,
            ], 422);
        }

        $payload = $this->healthService->buildTestWebhookPayload();
        $delivery = $this->dispatcher->sendTest($webhook, $payload);

        return response()->json([
            'success' => $delivery->status === 'delivered',
            'message' => $delivery->status === 'delivered'
                ? "Envio de teste realizado com sucesso (código {$delivery->http_status} em {$delivery->duration_ms} ms)."
                : "Falha ao enviar o teste: " . ($delivery->error_message ?: 'Erro desconhecido'),
            'data' => $delivery,
        ], $delivery->status === 'delivered' ? 200 : 502);
    }

    /**
     * Listar últimas entregas de webhooks (auditoria de disparos).
     */
    public function deliveries(Request $request): JsonResponse
    {
        $query = TvWebhookDelivery::query()
            ->with(['webhook:id,name', 'store:id,name'])
            ->orderByDesc('id');

        if ($request->filled('tv_webhook_id')) {
            $query->where('tv_webhook_id', (int) $request->input('tv_webhook_id'));
        }
        if ($request->filled('event_type')) {
            $query->where('event_type', (string) $request->input('event_type'));
        }
        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }
        if ($request->filled('screen_slug')) {
            $query->where('screen_slug', (string) $request->input('screen_slug'));
        }

        $perPage = min(100, max(1, $request->integer('per_page', 30)));
        $paginated = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }
}
