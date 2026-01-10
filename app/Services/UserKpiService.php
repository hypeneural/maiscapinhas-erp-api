<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class UserKpiService
{
    /**
     * Calculate all KPIs for users based on filters.
     */
    public function calculate(array $filters): array
    {
        $baseQuery = $this->buildBaseQuery($filters);

        return [
            'filters' => $this->normalizeFilters($filters),
            'totals' => $this->calculateTotals($baseQuery, $filters),
            'age' => $this->calculateAgeStats($baseQuery, $filters),
            'tenure' => $this->calculateTenureStats($baseQuery, $filters),
            'distribution' => $this->calculateDistribution($baseQuery, $filters),
        ];
    }

    /**
     * Normalize filters for response (convert types).
     */
    private function normalizeFilters(array $filters): array
    {
        $active = $filters['active'] ?? '1';

        return [
            'active' => $active === 'all' ? 'all' : (int) $active,
            'state' => $filters['state'] ?? null,
            'city' => $filters['city'] ?? null,
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
        ];
    }

    /**
     * Build base query with common filters.
     */
    private function buildBaseQuery(array $filters): \Illuminate\Database\Query\Builder
    {
        $query = DB::table('users');

        // Active filter
        $active = $filters['active'] ?? '1';
        if ($active !== 'all') {
            $query->where('active', (int) $active);
        }

        // State filter
        if (!empty($filters['state'])) {
            $query->where('state', strtoupper($filters['state']));
        }

        // City filter
        if (!empty($filters['city'])) {
            $query->where('city', $filters['city']);
        }

        // Date range filter (created_at)
        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query;
    }

    /**
     * Query 1: Calculate total counts.
     */
    private function calculateTotals(\Illuminate\Database\Query\Builder $baseQuery, array $filters): array
    {
        $result = (clone $baseQuery)
            ->selectRaw('
                COUNT(*) as users_total,
                SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) as active_total,
                SUM(CASE WHEN active = 0 THEN 1 ELSE 0 END) as inactive_total,
                SUM(CASE WHEN birth_date IS NOT NULL THEN 1 ELSE 0 END) as with_birth_date_total,
                SUM(CASE WHEN hire_date IS NOT NULL THEN 1 ELSE 0 END) as with_hire_date_total,
                SUM(CASE WHEN city IS NULL OR city = \'\' THEN 1 ELSE 0 END) as without_city_total
            ')
            ->first();

        return [
            'users_total' => (int) ($result->users_total ?? 0),
            'active_total' => (int) ($result->active_total ?? 0),
            'inactive_total' => (int) ($result->inactive_total ?? 0),
            'with_birth_date_total' => (int) ($result->with_birth_date_total ?? 0),
            'with_hire_date_total' => (int) ($result->with_hire_date_total ?? 0),
            'without_city_total' => (int) ($result->without_city_total ?? 0),
        ];
    }

    /**
     * Query 2: Calculate age statistics.
     */
    private function calculateAgeStats(\Illuminate\Database\Query\Builder $baseQuery, array $filters): array
    {
        $result = (clone $baseQuery)
            ->whereNotNull('birth_date')
            ->selectRaw('
                AVG(DATEDIFF(CURDATE(), birth_date) / 365.2425) as avg_age_years,
                MIN(birth_date) as oldest_birth_date,
                MAX(birth_date) as youngest_birth_date,
                COUNT(*) as age_population_total
            ')
            ->first();

        $population = (int) ($result->age_population_total ?? 0);

        if ($population === 0) {
            return [
                'avg_age_years' => null,
                'youngest_age_years' => null,
                'youngest_birth_date' => null,
                'oldest_age_years' => null,
                'oldest_birth_date' => null,
                'age_population_total' => 0,
            ];
        }

        $today = Carbon::now();
        $youngestBirthDate = $result->youngest_birth_date ? Carbon::parse($result->youngest_birth_date) : null;
        $oldestBirthDate = $result->oldest_birth_date ? Carbon::parse($result->oldest_birth_date) : null;

        return [
            'avg_age_years' => round((float) $result->avg_age_years, 2),
            'youngest_age_years' => $youngestBirthDate ? $today->diffInYears($youngestBirthDate) : null,
            'youngest_birth_date' => $youngestBirthDate?->format('Y-m-d'),
            'oldest_age_years' => $oldestBirthDate ? $today->diffInYears($oldestBirthDate) : null,
            'oldest_birth_date' => $oldestBirthDate?->format('Y-m-d'),
            'age_population_total' => $population,
        ];
    }

    /**
     * Query 3: Calculate tenure (time at company) statistics.
     */
    private function calculateTenureStats(\Illuminate\Database\Query\Builder $baseQuery, array $filters): array
    {
        $result = (clone $baseQuery)
            ->whereNotNull('hire_date')
            ->selectRaw('
                AVG(DATEDIFF(CURDATE(), hire_date)) as avg_tenure_days,
                MIN(hire_date) as longest_hire_date,
                MAX(hire_date) as newest_hire_date,
                COUNT(*) as tenure_population_total
            ')
            ->first();

        $population = (int) ($result->tenure_population_total ?? 0);

        if ($population === 0) {
            return [
                'avg_tenure_days' => null,
                'avg_tenure_months' => null,
                'longest_tenure_days' => null,
                'longest_hire_date' => null,
                'newest_tenure_days' => null,
                'newest_hire_date' => null,
                'tenure_population_total' => 0,
            ];
        }

        $today = Carbon::now();
        $longestHireDate = $result->longest_hire_date ? Carbon::parse($result->longest_hire_date) : null;
        $newestHireDate = $result->newest_hire_date ? Carbon::parse($result->newest_hire_date) : null;

        $avgTenureDays = (int) round((float) $result->avg_tenure_days);

        return [
            'avg_tenure_days' => $avgTenureDays,
            'avg_tenure_months' => round($avgTenureDays / 30.44, 1),
            'longest_tenure_days' => $longestHireDate ? $today->diffInDays($longestHireDate) : null,
            'longest_hire_date' => $longestHireDate?->format('Y-m-d'),
            'newest_tenure_days' => $newestHireDate ? $today->diffInDays($newestHireDate) : null,
            'newest_hire_date' => $newestHireDate?->format('Y-m-d'),
            'tenure_population_total' => $population,
        ];
    }

    /**
     * Query 4: Calculate distribution by city.
     */
    private function calculateDistribution(\Illuminate\Database\Query\Builder $baseQuery, array $filters): array
    {
        // Get total for percentage calculation
        $total = (clone $baseQuery)->count();

        if ($total === 0) {
            return [
                'cities_total_distinct' => 0,
                'top_city' => null,
                'by_city' => [],
            ];
        }

        // Group by city with normalized names
        $cities = (clone $baseQuery)
            ->selectRaw("COALESCE(NULLIF(city, ''), '(Sem cidade)') as city_name, COUNT(*) as qty")
            ->groupBy('city')
            ->orderByDesc('qty')
            ->get()
            ->map(function ($row) use ($total) {
                return [
                    'city' => $row->city_name,
                    'qty' => (int) $row->qty,
                    'pct' => round(($row->qty / $total) * 100, 2),
                ];
            })
            ->toArray();

        $topCity = !empty($cities) ? $cities[0] : null;

        return [
            'cities_total_distinct' => count($cities),
            'top_city' => $topCity,
            'by_city' => $cities,
        ];
    }
}
