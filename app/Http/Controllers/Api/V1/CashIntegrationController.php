<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @group Fechamento de Caixa
 *
 * Endpoints para integração de dados reais do PDV.
 */
class CashIntegrationController extends Controller
{
    use ApiResponse;

    /**
     * Obter dados de fechamento do PDV
     *
     * Retorna os totais e detalhamento de pagamentos registrados no PDV
     * para um determinado Turno/Loja/Data.
     *
     * @queryParam store_id integer required ID da loja. Example: 1
     * @queryParam date string required Data do turno (YYYY-MM-DD). Example: 2026-01-07
     * @queryParam shift_code string required Código do turno (M, T, N). Example: M
     *
     * @response 200 {
     *   "data": {
     *     "system_total": 1500.50,
     *     "payments": [
     *       { "label": "Dinheiro", "value": 500.00 },
     *       { "label": "Cartão Crédito", "value": 1000.50 }
     *     ],
     *     "turnos_found": 1,
     *     "details": [
     *       { "id": 123, "periodo": "MATUTINO", "total": 1500.50 }
     *     ]
     *   }
     * }
     */
    public function getClosureData(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'date' => ['required', 'date'],
            'shift_code' => ['required', 'string', 'in:M,T,N,1,2,3'],
        ]);

        $storeId = (int) $request->input('store_id');
        $date = $request->input('date');
        $shiftCode = $request->input('shift_code');

        // 1. Map Shift Code to PDV Periods
        $periods = $this->mapShiftCodeToPeriod($shiftCode);

        // 2. Find matching Pdv Turnos
        // Using DATE(data_hora_inicio) to match the date part
        $turnos = DB::table('pdv_turnos')
            ->where('store_id', $storeId)
            ->whereDate('data_hora_inicio', $date)
            ->whereIn('periodo', $periods)
            ->get(['id', 'total_sistema', 'qtd_vendas', 'periodo', 'data_hora_inicio']);

        if ($turnos->isEmpty()) {
            return $this->success([
                'system_total' => 0.00,
                'payments' => [],
                'turnos_found' => 0,
                'details' => [],
            ]);
        }

        $turnoIds = $turnos->pluck('id')->toArray();

        // 3. Aggregate Payments
        $payments = DB::table('pdv_turno_pagamentos')
            ->select('meio_pagamento', DB::raw('SUM(total) as total'))
            ->groupBy('meio_pagamento')
            ->join('pdv_turnos', function ($join) {
                $join->on('pdv_turno_pagamentos.store_pdv_id', '=', 'pdv_turnos.store_pdv_id')
                    ->on('pdv_turno_pagamentos.id_turno', '=', 'pdv_turnos.id_turno')
                    ->on('pdv_turno_pagamentos.canal', '=', 'pdv_turnos.canal');
            })
            ->whereIn('pdv_turnos.id', $turnoIds)
            ->get();

        // 4. Calculate Totals
        $systemTotal = $turnos->sum('total_sistema');

        return $this->success([
            'system_total' => (float) $systemTotal,
            'payments' => $payments->map(fn($p) => [
                'label' => $p->meio_pagamento,
                'value' => (float) $p->total,
            ]),
            'turnos_found' => $turnos->count(),
            'details' => $turnos->map(fn($t) => [
                'id' => $t->id,
                'periodo' => $t->periodo,
                'total' => (float) $t->total_sistema,
            ]),
        ]);
    }

    /**
     * Maps ERP shift code to PDV periods
     */
    private function mapShiftCodeToPeriod(string $code): array
    {
        return match (strtoupper($code)) {
            'M', '1' => ['MATUTINO', '1', 'Turno 1'],
            'T', '2' => ['VESPERTINO', '2', 'Turno 2'],
            'N', '3' => ['NOTURNO', '3', 'Turno 3'],
            default => [$code] // Fallback attempt
        };
    }
}
