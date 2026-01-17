<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Comemorações
 *
 * Endpoints para exibir aniversariantes de nascimento e de empresa.
 */
class CelebrationController extends Controller
{
    use ApiResponse;

    /**
     * Listar comemorações (tabela dinâmica)
     *
     * Lista todos os usuários com datas de aniversário e contratação,
     * com filtros avançados, ordenação e paginação para tabela admin.
     *
     * @queryParam store_id int Filtrar por loja. Example: 1
     * @queryParam type string Tipo: `birthday`, `work_anniversary`. Example: birthday
     * @queryParam month int Filtrar por mês (1-12). Example: 1
     * @queryParam status string `today`, `upcoming`, `past`, `this_week`, `this_month`. Example: upcoming
     * @queryParam keyword string Busca por nome. Example: João
     * @queryParam sort string Campo: `name`, `date`, `days_until`, `store_name`, `years`. Default: days_until. Example: name
     * @queryParam direction string `asc` ou `desc`. Default: asc. Example: asc
     * @queryParam per_page int Itens por página (máx 100). Default: 25. Example: 25
     *
     * @response 200 {
     *   "data": [...],
     *   "meta": {"current_page": 1, "per_page": 25, "total": 50},
     *   "filters": {"types": [...], "stores": [...], "statuses": [...]}
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $storeId = $request->input('store_id');
        $type = $request->input('type');
        $month = $request->input('month');
        $status = $request->input('status');
        $keyword = $request->input('keyword');
        $sortField = $request->input('sort', 'days_until');
        $sortDirection = $request->input('direction', 'asc') === 'desc' ? 'desc' : 'asc';
        $perPage = min((int) $request->input('per_page', 25), 100);

        $today = Carbon::today();
        $celebrations = [];

        // Query users
        $query = User::query()->active()->with('storeUsers.store');

        if ($storeId) {
            $query->whereHas('storeUsers', fn($q) => $q->where('store_id', $storeId));
        }

        if ($keyword) {
            $query->where('name', 'like', "%{$keyword}%");
        }

        $users = $query->get();

        foreach ($users as $user) {
            $store = $user->storeUsers->first()?->store;
            $storeName = $store?->name ?? 'Sem Loja';
            $storeIdValue = $store?->id;

            // Birthday
            if ((!$type || $type === 'birthday') && $user->birth_date) {
                $nextBirthday = Carbon::create($today->year, $user->birth_date->month, $user->birth_date->day);
                if ($nextBirthday->lt($today)) {
                    $nextBirthday->addYear();
                }
                $daysUntil = (int) $today->diffInDays($nextBirthday, false);

                $celebrations[] = [
                    'id' => $user->id . '_birthday',
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'avatar_url' => $user->avatar_url,
                    'store_id' => $storeIdValue,
                    'store_name' => $storeName,
                    'type' => 'birthday',
                    'type_label' => 'Aniversário',
                    'original_date' => $user->birth_date->toDateString(),
                    'next_date' => $nextBirthday->toDateString(),
                    'day' => $user->birth_date->day,
                    'month' => $user->birth_date->month,
                    'days_until' => $daysUntil,
                    'is_today' => $daysUntil === 0,
                    'is_this_week' => $daysUntil >= 0 && $daysUntil <= 7,
                    'is_this_month' => $nextBirthday->month === $today->month,
                    'status' => $this->getStatus($daysUntil),
                    'status_label' => $this->getStatusLabel($daysUntil),
                    'years' => null,
                ];
            }

            // Work anniversary
            if ((!$type || $type === 'work_anniversary') && $user->hire_date) {
                $nextAnniversary = Carbon::create($today->year, $user->hire_date->month, $user->hire_date->day);
                if ($nextAnniversary->lt($today)) {
                    $nextAnniversary->addYear();
                }
                $daysUntil = (int) $today->diffInDays($nextAnniversary, false);
                $years = $nextAnniversary->year - $user->hire_date->year;

                // Skip if not yet 1 year
                if ($years < 1)
                    continue;

                $celebrations[] = [
                    'id' => $user->id . '_work',
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'avatar_url' => $user->avatar_url,
                    'store_id' => $storeIdValue,
                    'store_name' => $storeName,
                    'type' => 'work_anniversary',
                    'type_label' => 'Aniversário de Empresa',
                    'original_date' => $user->hire_date->toDateString(),
                    'next_date' => $nextAnniversary->toDateString(),
                    'day' => $user->hire_date->day,
                    'month' => $user->hire_date->month,
                    'days_until' => $daysUntil,
                    'is_today' => $daysUntil === 0,
                    'is_this_week' => $daysUntil >= 0 && $daysUntil <= 7,
                    'is_this_month' => $nextAnniversary->month === $today->month,
                    'status' => $this->getStatus($daysUntil),
                    'status_label' => $this->getStatusLabel($daysUntil),
                    'years' => $years,
                    'years_label' => $years === 1 ? '1 ano' : "{$years} anos",
                ];
            }
        }

        // Filter by month
        if ($month) {
            $celebrations = array_filter($celebrations, fn($c) => $c['month'] === (int) $month);
        }

        // Filter by status
        if ($status) {
            $celebrations = array_filter($celebrations, fn($c) => $c['status'] === $status);
        }

        // Sort
        $sortMap = [
            'name' => 'user_name',
            'date' => 'next_date',
            'days_until' => 'days_until',
            'store_name' => 'store_name',
            'years' => 'years',
        ];
        $sortKey = $sortMap[$sortField] ?? 'days_until';

        usort($celebrations, function ($a, $b) use ($sortKey, $sortDirection) {
            $cmp = $a[$sortKey] <=> $b[$sortKey];
            return $sortDirection === 'desc' ? -$cmp : $cmp;
        });

        // Pagination
        $total = count($celebrations);
        $page = max(1, (int) $request->input('page', 1));
        $offset = ($page - 1) * $perPage;
        $paginatedData = array_slice($celebrations, $offset, $perPage);

        // Collect filter options
        $stores = User::query()
            ->active()
            ->with('storeUsers.store')
            ->get()
            ->flatMap(fn($u) => $u->storeUsers->pluck('store'))
            ->unique('id')
            ->map(fn($s) => ['id' => $s?->id, 'name' => $s?->name])
            ->filter(fn($s) => $s['id'])
            ->values();

        return response()->json([
            'data' => array_values($paginatedData),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil($total / $perPage),
            ],
            'filters' => [
                'types' => [
                    ['value' => 'birthday', 'label' => 'Aniversário'],
                    ['value' => 'work_anniversary', 'label' => 'Aniversário de Empresa'],
                ],
                'statuses' => [
                    ['value' => 'today', 'label' => 'Hoje'],
                    ['value' => 'this_week', 'label' => 'Esta Semana'],
                    ['value' => 'this_month', 'label' => 'Este Mês'],
                    ['value' => 'upcoming', 'label' => 'Próximos'],
                ],
                'stores' => $stores,
                'months' => collect(range(1, 12))->map(fn($m) => [
                    'value' => $m,
                    'label' => Carbon::create(null, $m)->locale('pt_BR')->monthName,
                ]),
            ],
            'summary' => [
                'total' => $total,
                'today' => count(array_filter($celebrations, fn($c) => $c['is_today'])),
                'this_week' => count(array_filter($celebrations, fn($c) => $c['is_this_week'])),
                'birthdays' => count(array_filter($celebrations, fn($c) => $c['type'] === 'birthday')),
                'work_anniversaries' => count(array_filter($celebrations, fn($c) => $c['type'] === 'work_anniversary')),
            ],
        ]);
    }

    private function getStatus(int $daysUntil): string
    {
        if ($daysUntil === 0)
            return 'today';
        if ($daysUntil > 0 && $daysUntil <= 7)
            return 'this_week';
        if ($daysUntil > 7 && $daysUntil <= 30)
            return 'this_month';
        return 'upcoming';
    }

    private function getStatusLabel(int $daysUntil): string
    {
        if ($daysUntil === 0)
            return 'Hoje';
        if ($daysUntil === 1)
            return 'Amanhã';
        if ($daysUntil > 0 && $daysUntil <= 7)
            return "Em {$daysUntil} dias";
        if ($daysUntil > 7 && $daysUntil <= 30)
            return "Em {$daysUntil} dias";
        return Carbon::today()->addDays($daysUntil)->locale('pt_BR')->format('d M');
    }

    /**
     * Comemorações do mês
     *
     * Lista todos os aniversariantes (nascimento e empresa) do mês especificado.
     *
     * @queryParam month int Mês (1-12). Default: mês atual. Example: 1
     * @queryParam year int Ano. Default: ano atual. Example: 2026
     * @queryParam store_id int Filtrar por loja. Example: 1
     * @queryParam type string Tipo: `birthday`, `work_anniversary`, ou ambos. Example: birthday
     *
     * @response 200 {
     *   "data": {
     *     "month": 1,
     *     "year": 2026,
     *     "celebrations": [...],
     *     "summary": {"total": 15, "birthdays": 10, "work_anniversaries": 5}
     *   }
     * }
     */
    public function month(Request $request): JsonResponse
    {
        $month = (int) $request->input('month', now()->month);
        $year = (int) $request->input('year', now()->year);
        $storeId = $request->input('store_id');
        $type = $request->input('type');

        $celebrations = [];
        $today = Carbon::today();
        $targetDate = Carbon::create($year, $month, 1);

        // Query active users
        $query = User::query()->active()->with('storeUsers.store');

        if ($storeId) {
            $query->whereHas('storeUsers', fn($q) => $q->where('store_id', $storeId));
        }

        $users = $query->get();

        foreach ($users as $user) {
            $store = $user->storeUsers->first()?->store;
            $storeName = $store?->name ?? 'Sem Loja';
            $storeIdValue = $store?->id;

            // Birthday
            if ((!$type || $type === 'birthday') && $user->birth_date) {
                $birthDate = $user->birth_date;
                if ($birthDate->month === $month) {
                    $celebrationDate = Carbon::create($year, $month, $birthDate->day);
                    $daysUntil = $today->diffInDays($celebrationDate, false);

                    $celebrations[] = [
                        'id' => $user->id . '_birthday',
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                        'avatar_url' => $user->avatar_url,
                        'store_id' => $storeIdValue,
                        'store_name' => $storeName,
                        'type' => 'birthday',
                        'date' => $celebrationDate->toDateString(),
                        'day_of_month' => $birthDate->day,
                        'days_until' => max(0, (int) $daysUntil),
                        'is_today' => $daysUntil === 0,
                        'is_past' => $daysUntil < 0,
                        'years' => null,
                    ];
                }
            }

            // Work anniversary
            if ((!$type || $type === 'work_anniversary') && $user->hire_date) {
                $hireDate = $user->hire_date;
                if ($hireDate->month === $month && $hireDate->year < $year) {
                    $anniversaryDate = Carbon::create($year, $month, $hireDate->day);
                    $daysUntil = $today->diffInDays($anniversaryDate, false);
                    $years = $year - $hireDate->year;

                    $celebrations[] = [
                        'id' => $user->id . '_work',
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                        'avatar_url' => $user->avatar_url,
                        'store_id' => $storeIdValue,
                        'store_name' => $storeName,
                        'type' => 'work_anniversary',
                        'date' => $anniversaryDate->toDateString(),
                        'day_of_month' => $hireDate->day,
                        'days_until' => max(0, (int) $daysUntil),
                        'is_today' => $daysUntil === 0,
                        'is_past' => $daysUntil < 0,
                        'years' => $years,
                    ];
                }
            }
        }

        // Sort by day of month
        usort($celebrations, fn($a, $b) => $a['day_of_month'] <=> $b['day_of_month']);

        // Summary
        $birthdays = array_filter($celebrations, fn($c) => $c['type'] === 'birthday');
        $workAnniversaries = array_filter($celebrations, fn($c) => $c['type'] === 'work_anniversary');
        $todayCelebrations = array_filter($celebrations, fn($c) => $c['is_today']);
        $upcomingThisWeek = array_filter($celebrations, fn($c) => !$c['is_past'] && $c['days_until'] <= 7);

        return $this->success([
            'month' => $month,
            'year' => $year,
            'celebrations' => array_values($celebrations),
            'summary' => [
                'total' => count($celebrations),
                'birthdays' => count($birthdays),
                'work_anniversaries' => count($workAnniversaries),
                'today' => count($todayCelebrations),
                'upcoming_this_week' => count($upcomingThisWeek),
            ],
        ]);
    }

    /**
     * Próximas comemorações
     *
     * Lista as próximas comemorações para widget do dashboard.
     *
     * @queryParam limit int Quantidade. Default: 5. Example: 5
     * @queryParam days int Próximos X dias. Default: 7. Example: 7
     * @queryParam store_id int Filtrar por loja. Example: 1
     *
     * @response 200 {
     *   "data": [
     *     {"user_name": "Maria", "type": "birthday", "days_until": 2}
     *   ]
     * }
     */
    public function upcoming(Request $request): JsonResponse
    {
        $limit = (int) $request->input('limit', 5);
        $days = (int) $request->input('days', 7);
        $storeId = $request->input('store_id');

        $today = Carbon::today();
        $endDate = $today->copy()->addDays($days);
        $upcoming = [];

        $query = User::query()->active()->with('storeUsers.store');

        if ($storeId) {
            $query->whereHas('storeUsers', fn($q) => $q->where('store_id', $storeId));
        }

        $users = $query->get();

        foreach ($users as $user) {
            $store = $user->storeUsers->first()?->store;

            // Birthday
            if ($user->birth_date) {
                $nextBirthday = Carbon::create($today->year, $user->birth_date->month, $user->birth_date->day);
                if ($nextBirthday->lt($today)) {
                    $nextBirthday->addYear();
                }

                if ($nextBirthday->between($today, $endDate)) {
                    $upcoming[] = [
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                        'avatar_url' => $user->avatar_url,
                        'store_name' => $store?->name ?? 'Sem Loja',
                        'type' => 'birthday',
                        'date' => $nextBirthday->toDateString(),
                        'days_until' => (int) $today->diffInDays($nextBirthday),
                    ];
                }
            }

            // Work anniversary
            if ($user->hire_date && $user->hire_date->year < $today->year) {
                $nextAnniversary = Carbon::create($today->year, $user->hire_date->month, $user->hire_date->day);
                if ($nextAnniversary->lt($today)) {
                    $nextAnniversary->addYear();
                }

                if ($nextAnniversary->between($today, $endDate)) {
                    $upcoming[] = [
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                        'avatar_url' => $user->avatar_url,
                        'store_name' => $store?->name ?? 'Sem Loja',
                        'type' => 'work_anniversary',
                        'date' => $nextAnniversary->toDateString(),
                        'days_until' => (int) $today->diffInDays($nextAnniversary),
                        'years' => $nextAnniversary->year - $user->hire_date->year,
                    ];
                }
            }
        }

        // Sort by days_until and limit
        usort($upcoming, fn($a, $b) => $a['days_until'] <=> $b['days_until']);
        $upcoming = array_slice($upcoming, 0, $limit);

        return $this->success($upcoming);
    }

    /**
     * Comemorações de hoje
     *
     * Lista quem está comemorando hoje com mensagem personalizada.
     *
     * @response 200 {
     *   "data": [
     *     {"user_name": "João", "type": "work_anniversary", "years": 3, "message": "João completou 3 anos!"}
     *   ]
     * }
     */
    public function today(Request $request): JsonResponse
    {
        $today = Carbon::today();
        $celebrations = [];

        $users = User::query()
            ->active()
            ->with('storeUsers.store')
            ->get();

        foreach ($users as $user) {
            $store = $user->storeUsers->first()?->store;

            // Birthday today
            if (
                $user->birth_date &&
                $user->birth_date->month === $today->month &&
                $user->birth_date->day === $today->day
            ) {
                $age = $today->year - $user->birth_date->year;
                $celebrations[] = [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'avatar_url' => $user->avatar_url,
                    'store_name' => $store?->name ?? 'Sem Loja',
                    'type' => 'birthday',
                    'years' => null,
                    'message' => "🎂 Hoje é aniversário de {$user->name}!",
                ];
            }

            // Work anniversary today
            if (
                $user->hire_date &&
                $user->hire_date->month === $today->month &&
                $user->hire_date->day === $today->day &&
                $user->hire_date->year < $today->year
            ) {
                $years = $today->year - $user->hire_date->year;
                $yearsText = $years === 1 ? '1 ano' : "{$years} anos";
                $celebrations[] = [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'avatar_url' => $user->avatar_url,
                    'store_name' => $store?->name ?? 'Sem Loja',
                    'type' => 'work_anniversary',
                    'years' => $years,
                    'message' => "🎉 {$user->name} completou {$yearsText} na Mais Capinhas!",
                ];
            }
        }

        return $this->success($celebrations);
    }
}
