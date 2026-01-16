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
