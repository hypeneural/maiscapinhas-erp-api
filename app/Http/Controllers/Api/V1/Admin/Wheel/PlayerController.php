<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Wheel;

use App\Http\Controllers\Controller;
use App\Http\Resources\Wheel\PlayerResource;
use App\Models\WheelEvent;
use App\Models\WheelPlayer;
use App\Models\WheelSessionPlayer;
use App\Models\WheelSpin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Wheel - Players (Admin)
 *
 * API para gerenciamento de Jogadores do módulo Roleta.
 */
class PlayerController extends Controller
{
    /**
     * Listar jogadores com filtros avançados.
     * 
     * Suporta filtros: search, city, state, store_id, campaign_id, 
     * has_address, verified_only, date_from, date_to
     */
    public function index(Request $request): JsonResponse
    {
        $query = WheelPlayer::query()
            ->withCount([
                'sessionPlayers',
                'sessionPlayers as spins_count' => function ($q) {
                    $q->whereHas('spins');
                }
            ])
            ->with([
                'sessionPlayers' => function ($q) {
                    $q->latest()->limit(1)->with('session.screen.store');
                }
            ]);

        // Busca geral (nome, telefone mascarado, player_key)
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('phone_masked', 'like', "%{$search}%")
                    ->orWhere('player_key', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%");
            });
        }

        // Filtro por cidade
        if ($request->filled('city')) {
            $query->where('city', 'like', "%{$request->city}%");
        }

        // Filtro por estado
        if ($request->filled('state')) {
            $query->where('state', $request->state);
        }

        // Filtro por CEP
        if ($request->filled('cep')) {
            $query->where('cep', 'like', "{$request->cep}%");
        }

        // Filtro por loja (via session_players -> session -> screen -> store)
        if ($request->filled('store_id')) {
            $storeId = $request->input('store_id');
            $query->whereHas('sessionPlayers.session.screen', function ($q) use ($storeId) {
                $q->where('store_id', $storeId);
            });
        }

        // Filtro por campanha
        if ($request->filled('campaign_id')) {
            $campaignId = $request->input('campaign_id');
            $query->whereHas('sessionPlayers.session', function ($q) use ($campaignId) {
                $q->where('campaign_id', $campaignId);
            });
        }

        // Filtro: apenas com endereço
        if ($request->boolean('has_address')) {
            $query->whereNotNull('cep')
                ->whereNotNull('city');
        }

        // Filtro: apenas verificados
        if ($request->boolean('verified_only')) {
            $query->whereNotNull('whatsapp_confirmed_at');
        }

        // Filtro: apenas com giros
        if ($request->boolean('has_spins')) {
            $query->whereHas('sessionPlayers.spins');
        }

        // Filtro: período de cadastro
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Ordenação
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');
        $allowedSorts = ['created_at', 'full_name', 'city', 'state', 'last_seen_at'];

        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDir);
        }

        // Paginação
        $perPage = min($request->input('per_page', 20), 100);
        $players = $query->paginate($perPage);

        // Estatísticas
        $stats = [
            'total' => WheelPlayer::count(),
            'verified' => WheelPlayer::whereNotNull('whatsapp_confirmed_at')->count(),
            'with_address' => WheelPlayer::whereNotNull('cep')->count(),
            'cities' => WheelPlayer::whereNotNull('city')
                ->distinct()
                ->count('city'),
        ];

        return response()->json([
            'success' => true,
            'data' => PlayerResource::collection($players),
            'meta' => [
                'current_page' => $players->currentPage(),
                'per_page' => $players->perPage(),
                'total' => $players->total(),
                'last_page' => $players->lastPage(),
            ],
            'stats' => $stats,
            'filters_applied' => array_filter($request->only([
                'search',
                'city',
                'state',
                'cep',
                'store_id',
                'campaign_id',
                'has_address',
                'verified_only',
                'has_spins',
                'date_from',
                'date_to'
            ])),
        ]);
    }

    /**
     * Exibir detalhes de um jogador.
     */
    public function show(string $playerKey): JsonResponse
    {
        $player = WheelPlayer::where('player_key', $playerKey)
            ->with([
                'sessionPlayers' => function ($q) {
                    $q->with(['session.campaign', 'session.screen.store', 'spins.prize'])
                        ->orderByDesc('created_at');
                },
            ])
            ->firstOrFail();

        // Estatísticas do jogador
        $stats = [
            'total_sessions' => $player->sessionPlayers->count(),
            'total_spins' => $player->sessionPlayers->sum(fn($sp) => $sp->spins->count()),
            'prizes_won' => $player->sessionPlayers->sum(
                fn($sp) =>
                $sp->spins->filter(fn($s) => $s->prize?->requiresRedeem())->count()
            ),
            'stores_visited' => $player->sessionPlayers
                ->map(fn($sp) => $sp->session?->screen?->store_id)
                ->filter()
                ->unique()
                ->count(),
            'campaigns_participated' => $player->sessionPlayers
                ->map(fn($sp) => $sp->session?->campaign_id)
                ->filter()
                ->unique()
                ->count(),
        ];

        // Timeline de participações
        $timeline = $player->sessionPlayers->map(fn($sp) => [
            'session_player_key' => $sp->session_player_key,
            'session_key' => $sp->session?->session_key,
            'campaign' => $sp->session?->campaign?->name,
            'store' => $sp->session?->screen?->store?->name,
            'status' => $sp->status->value,
            'spins' => $sp->spins->map(fn($s) => [
                'spin_key' => $s->spin_key,
                'prize' => $s->prize?->name,
                'code' => $s->prize_code,
                'created_at' => $s->created_at?->toISOString(),
            ]),
            'joined_at' => $sp->joined_at?->toISOString(),
            'left_at' => $sp->left_at?->toISOString(),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'player' => new PlayerResource($player),
                'stats' => $stats,
                'timeline' => $timeline,
            ],
        ]);
    }

    /**
     * Atualizar dados de um jogador (admin).
     */
    public function update(Request $request, string $playerKey): JsonResponse
    {
        $request->validate([
            'full_name' => 'nullable|string|max:100',
            'cep' => 'nullable|string|max:9',
            'street' => 'nullable|string|max:200',
            'number' => 'nullable|string|max:20',
            'complement' => 'nullable|string|max:100',
            'neighborhood' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:2',
        ]);

        $player = WheelPlayer::where('player_key', $playerKey)->firstOrFail();

        $player->fill($request->only([
            'full_name',
            'cep',
            'street',
            'number',
            'complement',
            'neighborhood',
            'city',
            'state'
        ]));
        $player->save();

        WheelEvent::log('player_updated', [
            'player_key' => $player->player_key,
            'updated_fields' => array_keys($request->all()),
            'updated_by' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Jogador atualizado com sucesso.',
            'data' => new PlayerResource($player->fresh()),
        ]);
    }

    /**
     * Logs de um jogador específico.
     */
    public function logs(Request $request, string $playerKey): JsonResponse
    {
        $player = WheelPlayer::where('player_key', $playerKey)->firstOrFail();

        // Buscar session_player_ids do jogador
        $sessionPlayerIds = WheelSessionPlayer::where('player_id', $player->id)
            ->pluck('id');

        // Buscar spins do jogador
        $spinIds = WheelSpin::whereIn('session_player_id', $sessionPlayerIds)
            ->orWhere('player_id', $player->id)
            ->pluck('id');

        // Buscar eventos relacionados
        $query = WheelEvent::query()
            ->where(function ($q) use ($player, $spinIds) {
                // Eventos com player_key no payload
                $q->where('payload->player_key', $player->player_key)
                    // Ou eventos de spin
                    ->orWhereIn('payload->spin_id', $spinIds->toArray());
            })
            ->orderByDesc('created_at');

        // Filtro por tipo
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Filtro por período
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $events = $query->paginate($request->input('per_page', 50));

        return response()->json([
            'success' => true,
            'data' => $events->map(fn($e) => [
                'id' => $e->id,
                'type' => $e->type,
                'payload' => $e->payload,
                'screen_id' => $e->screen_id,
                'campaign_id' => $e->campaign_id,
                'created_at' => $e->created_at->toISOString(),
            ]),
            'meta' => [
                'current_page' => $events->currentPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ],
        ]);
    }

    /**
     * Histórico de giros de um jogador.
     */
    public function spins(Request $request, string $playerKey): JsonResponse
    {
        $player = WheelPlayer::where('player_key', $playerKey)->firstOrFail();

        $query = WheelSpin::query()
            ->whereHas('sessionPlayer', function ($q) use ($player) {
                $q->where('player_id', $player->id);
            })
            ->orWhere('player_id', $player->id) // Compatibilidade
            ->with(['prize', 'session.campaign', 'session.screen.store'])
            ->orderByDesc('created_at');

        // Filtro por campanha
        if ($request->filled('campaign_id')) {
            $query->where('campaign_id', $request->campaign_id);
        }

        // Filtro por prêmio
        if ($request->filled('prize_id')) {
            $query->where('prize_id', $request->prize_id);
        }

        // Filtro: apenas ganhadores
        if ($request->boolean('winners_only')) {
            $query->whereHas('prize', fn($q) => $q->whereNotIn('type', ['nothing', 'try_again']));
        }

        $spins = $query->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $spins->map(fn($s) => [
                'spin_key' => $s->spin_key,
                'campaign' => $s->session?->campaign?->name,
                'store' => $s->session?->screen?->store?->name,
                'prize' => [
                    'name' => $s->prize?->name,
                    'type' => $s->prize?->type?->value,
                    'icon' => $s->prize?->icon,
                ],
                'prize_code' => $s->prize_code,
                'status' => $s->status->value,
                'created_at' => $s->created_at->toISOString(),
            ]),
            'meta' => [
                'current_page' => $spins->currentPage(),
                'per_page' => $spins->perPage(),
                'total' => $spins->total(),
            ],
        ]);
    }

    /**
     * Estatísticas de jogadores por cidade.
     */
    public function statsByCity(Request $request): JsonResponse
    {
        $stats = WheelPlayer::query()
            ->whereNotNull('city')
            ->selectRaw('city, state, COUNT(*) as players_count')
            ->groupBy('city', 'state')
            ->orderByDesc('players_count')
            ->limit($request->input('limit', 20))
            ->get();

        return response()->json([
            'success' => true,
            'data' => $stats->map(fn($row) => [
                'city' => $row->city,
                'state' => $row->state,
                'players_count' => $row->players_count,
            ]),
        ]);
    }

    /**
     * Estatísticas de jogadores por loja.
     */
    public function statsByStore(Request $request): JsonResponse
    {
        $stats = WheelSessionPlayer::query()
            ->with('session.screen.store')
            ->selectRaw('
                (SELECT store_id FROM wheel_screens 
                 JOIN wheel_sessions ON wheel_sessions.screen_id = wheel_screens.id 
                 WHERE wheel_sessions.id = wheel_session_players.session_id) as store_id,
                COUNT(DISTINCT player_id) as unique_players,
                COUNT(*) as total_participations
            ')
            ->groupBy('store_id')
            ->get();

        // Enriquecer com dados da loja
        $enriched = $stats->map(function ($row) {
            $store = \App\Models\Store::find($row->store_id);
            return [
                'store_id' => $row->store_id,
                'store_name' => $store?->name ?? 'Desconhecida',
                'unique_players' => $row->unique_players,
                'total_participations' => $row->total_participations,
            ];
        })->sortByDesc('unique_players')->values();

        return response()->json([
            'success' => true,
            'data' => $enriched,
        ]);
    }

    /**
     * Exportar jogadores (CSV).
     */
    public function export(Request $request): JsonResponse
    {
        // Por enquanto, retorna dados para frontend exportar
        // Futuramente, gerar CSV no backend

        $query = WheelPlayer::query()
            ->with('sessionPlayers.session.screen.store');

        // Aplicar mesmos filtros do index
        if ($request->filled('city')) {
            $query->where('city', $request->city);
        }
        if ($request->filled('state')) {
            $query->where('state', $request->state);
        }
        if ($request->filled('store_id')) {
            $storeId = $request->input('store_id');
            $query->whereHas('sessionPlayers.session.screen', function ($q) use ($storeId) {
                $q->where('store_id', $storeId);
            });
        }

        $players = $query->limit(1000)->get();

        $exportData = $players->map(fn($p) => [
            'player_key' => $p->player_key,
            'name' => $p->full_name,
            'phone_masked' => $p->phone_masked,
            'verified' => $p->whatsapp_confirmed_at ? 'Sim' : 'Não',
            'city' => $p->city,
            'state' => $p->state,
            'cep' => $p->cep,
            'sessions_count' => $p->sessionPlayers->count(),
            'created_at' => $p->created_at->format('Y-m-d H:i:s'),
            'last_seen' => $p->last_seen_at?->format('Y-m-d H:i:s'),
        ]);

        return response()->json([
            'success' => true,
            'data' => $exportData,
            'count' => $exportData->count(),
        ]);
    }
}
