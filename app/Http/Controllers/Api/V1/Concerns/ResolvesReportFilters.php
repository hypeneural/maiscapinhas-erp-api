<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

trait ResolvesReportFilters
{
    /**
     * Resolve store filter accepting internal ID or stores.guid UUID.
     */
    protected function resolveStoreIdFilter(mixed $storeIdInput): ?int
    {
        if ($storeIdInput === null) {
            return null;
        }

        if (is_int($storeIdInput)) {
            $exists = DB::table('stores')->where('id', $storeIdInput)->exists();
            if (!$exists) {
                throw ValidationException::withMessages([
                    'store_id' => ['A loja informada em store_id nao foi encontrada.'],
                ]);
            }

            return $storeIdInput;
        }

        $normalized = trim((string) $storeIdInput);
        if ($normalized === '') {
            return null;
        }

        if (ctype_digit($normalized)) {
            $storeId = (int) $normalized;
            $exists = DB::table('stores')->where('id', $storeId)->exists();
            if (!$exists) {
                throw ValidationException::withMessages([
                    'store_id' => ['A loja informada em store_id nao foi encontrada.'],
                ]);
            }

            return $storeId;
        }

        if (!Str::isUuid($normalized)) {
            throw ValidationException::withMessages([
                'store_id' => ['O campo store_id deve ser numerico ou UUID valido.'],
            ]);
        }

        $storeId = DB::table('stores')
            ->whereRaw('LOWER(guid) = ?', [strtolower($normalized)])
            ->value('id');

        if ($storeId === null) {
            throw ValidationException::withMessages([
                'store_id' => ['A loja informada em store_id nao foi encontrada.'],
            ]);
        }

        return (int) $storeId;
    }

    /**
     * Resolve report window with flexible filters.
     *
     * Precedence:
     * 1) from/to
     * 2) date
     * 3) period preset
     * 4) month
     * 5) current month
     *
     * @param array<string,mixed> $validated
     * @return array{
     *   mode:string,
     *   period_label:string,
     *   month:string,
     *   timezone:string,
     *   from_local:CarbonImmutable,
     *   to_local:CarbonImmutable,
     *   from_utc:CarbonImmutable,
     *   to_utc:CarbonImmutable
     * }
     */
    protected function resolveReportWindow(array $validated, string $timezone = 'America/Sao_Paulo'): array
    {
        $nowLocal = CarbonImmutable::now($timezone);

        $month = isset($validated['month']) ? trim((string) $validated['month']) : '';
        $date = isset($validated['date']) ? trim((string) $validated['date']) : '';
        $period = isset($validated['period']) ? strtolower(trim((string) $validated['period'])) : '';
        $fromInput = isset($validated['from']) ? trim((string) $validated['from']) : '';
        $toInput = isset($validated['to']) ? trim((string) $validated['to']) : '';

        $mode = 'month';
        $periodLabel = '';

        if ($fromInput !== '' || $toInput !== '') {
            $fromLocal = $fromInput !== ''
                ? CarbonImmutable::parse($fromInput, $timezone)->startOfDay()
                : $nowLocal->subDays(30)->startOfDay();
            $toLocal = $toInput !== ''
                ? CarbonImmutable::parse($toInput, $timezone)->endOfDay()
                : $nowLocal->endOfDay();
            $mode = 'custom';
            $periodLabel = $fromLocal->toDateString() . ' to ' . $toLocal->toDateString();
        } elseif ($date !== '') {
            $fromLocal = CarbonImmutable::parse($date, $timezone)->startOfDay();
            $toLocal = CarbonImmutable::parse($date, $timezone)->endOfDay();
            $mode = 'day';
            $periodLabel = $fromLocal->toDateString();
        } elseif ($period !== '') {
            switch ($period) {
                case 'today':
                    $fromLocal = $nowLocal->startOfDay();
                    $toLocal = $nowLocal->endOfDay();
                    break;
                case 'yesterday':
                    $fromLocal = $nowLocal->subDay()->startOfDay();
                    $toLocal = $nowLocal->subDay()->endOfDay();
                    break;
                case 'last_7_days':
                    $fromLocal = $nowLocal->subDays(6)->startOfDay();
                    $toLocal = $nowLocal->endOfDay();
                    break;
                case 'last_30_days':
                    $fromLocal = $nowLocal->subDays(29)->startOfDay();
                    $toLocal = $nowLocal->endOfDay();
                    break;
                case 'this_month':
                    $fromLocal = $nowLocal->startOfMonth()->startOfDay();
                    $toLocal = $nowLocal->endOfDay();
                    break;
                case 'last_month':
                    $lastMonth = $nowLocal->subMonthNoOverflow();
                    $fromLocal = $lastMonth->startOfMonth()->startOfDay();
                    $toLocal = $lastMonth->endOfMonth()->endOfDay();
                    break;
                default:
                    throw ValidationException::withMessages([
                        'period' => ['Periodo invalido. Use today, yesterday, last_7_days, last_30_days, this_month ou last_month.'],
                    ]);
            }
            $mode = 'period';
            $periodLabel = $period;
        } elseif ($month !== '') {
            $fromLocal = CarbonImmutable::parse($month . '-01', $timezone)->startOfMonth()->startOfDay();
            $toLocal = CarbonImmutable::parse($month . '-01', $timezone)->endOfMonth()->endOfDay();
            $mode = 'month';
            $periodLabel = $month;
        } else {
            $fromLocal = $nowLocal->startOfMonth()->startOfDay();
            $toLocal = $nowLocal->endOfMonth()->endOfDay();
            $mode = 'month';
            $periodLabel = $nowLocal->format('Y-m');
        }

        if ($toLocal->lt($fromLocal)) {
            throw ValidationException::withMessages([
                'to' => ['O campo to deve ser maior ou igual ao campo from.'],
            ]);
        }

        return [
            'mode' => $mode,
            'period_label' => $periodLabel,
            'month' => $fromLocal->format('Y-m'),
            'timezone' => $timezone,
            'from_local' => $fromLocal,
            'to_local' => $toLocal,
            'from_utc' => $fromLocal->setTimezone('UTC'),
            'to_utc' => $toLocal->setTimezone('UTC'),
        ];
    }
}
