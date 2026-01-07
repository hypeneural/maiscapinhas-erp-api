<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\Clients\PeopleAnalyticsClient;
use App\Enums\KpiSource;
use App\Models\PeopleKpiShift;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PeopleAnalyticsSyncService
{
    public function __construct(
        private PeopleAnalyticsClient $client
    ) {
    }

    /**
     * Sync KPIs for a store and date from FastAPI.
     */
    public function syncKpis(int $storeId, Carbon $date): array
    {
        $store = Store::find($storeId);

        if (!$store) {
            throw new \InvalidArgumentException("Store with ID {$storeId} not found.");
        }

        // Get store code (use ID padded to 3 digits)
        $storeCode = str_pad((string) $storeId, 3, '0', STR_PAD_LEFT);

        $kpisData = $this->client->getKpis($storeCode, $date->format('Y-m-d'));

        if ($kpisData === null) {
            Log::warning('No KPI data returned from People Analytics', [
                'store_id' => $storeId,
                'date' => $date->format('Y-m-d'),
            ]);
            return [];
        }

        return $this->persistKpis($storeId, $date, $kpisData);
    }

    /**
     * Persist KPIs to database.
     */
    private function persistKpis(int $storeId, Carbon $date, array $kpisData): array
    {
        $results = [];

        DB::transaction(function () use ($storeId, $date, $kpisData, &$results) {
            foreach ($kpisData as $shiftData) {
                $shiftCode = $shiftData['shift'] ?? $shiftData['shift_code'] ?? null;

                if (!$shiftCode) {
                    continue;
                }

                $kpi = PeopleKpiShift::updateOrCreate(
                    [
                        'store_id' => $storeId,
                        'date' => $date->format('Y-m-d'),
                        'shift_code' => strtoupper($shiftCode),
                    ],
                    [
                        'in_count' => $shiftData['in_count'] ?? 0,
                        'out_count' => $shiftData['out_count'] ?? 0,
                        'staff_in' => $shiftData['staff_in'] ?? 0,
                        'staff_out' => $shiftData['staff_out'] ?? 0,
                        'source' => KpiSource::FASTAPI,
                        'raw_json' => $shiftData,
                    ]
                );

                $results[] = $kpi;
            }
        });

        Log::info('People Analytics KPIs synced', [
            'store_id' => $storeId,
            'date' => $date->format('Y-m-d'),
            'count' => count($results),
        ]);

        return $results;
    }

    /**
     * Get KPIs from database for a store and date.
     */
    public function getStoredKpis(int $storeId, Carbon $date): array
    {
        $shifts = PeopleKpiShift::forStore($storeId)
            ->forDate($date)
            ->orderBy('shift_code')
            ->get();

        $totals = PeopleKpiShift::getDayTotals($storeId, $date);

        return [
            'shifts' => $shifts,
            'totals' => $totals,
        ];
    }

    /**
     * Manually insert KPIs.
     */
    public function insertManualKpi(int $storeId, Carbon $date, string $shiftCode, array $data): PeopleKpiShift
    {
        return PeopleKpiShift::updateOrCreate(
            [
                'store_id' => $storeId,
                'date' => $date->format('Y-m-d'),
                'shift_code' => strtoupper($shiftCode),
            ],
            [
                'in_count' => $data['in_count'] ?? 0,
                'out_count' => $data['out_count'] ?? 0,
                'staff_in' => $data['staff_in'] ?? 0,
                'staff_out' => $data['staff_out'] ?? 0,
                'source' => KpiSource::MANUAL,
                'raw_json' => $data,
            ]
        );
    }
}
