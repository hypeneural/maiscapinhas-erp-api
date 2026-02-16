<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Pdv\PdvSaleValidator;

class PdvSaleValidateController extends Controller
{
    /**
     * Valida uma única venda (Legacy/Stand-alone)
     */
    public function validateSingle(Request $request)
    {
        $data = $request->validate([
            'payload' => ['required'], // string JSON ou array
            'canal' => ['nullable', 'string'],
            'timezone' => ['nullable', 'string'],
            'tolerance.total' => ['nullable', 'numeric'],
            'tolerance.start_minus_minutes' => ['nullable', 'integer'],
            'tolerance.end_plus_minutes' => ['nullable', 'integer'],
        ]);

        $validator = new PdvSaleValidator();

        return response()->json(
            $validator->validateFromErpPayload($data)
        );
    }

    /**
     * Valida um lote de vendas (Batch)
     */
    public function validateBatch(Request $request)
    {
        // Validação da estrutura do Batch
        $data = $request->validate([
            'Lista' => ['required', 'array'],
            'Lista.*' => ['required', 'array'], // Cada item é um objeto de venda
            'timezone' => ['nullable', 'string'],
            'tolerance.total' => ['nullable', 'numeric'],
            'tolerance.start_minus_minutes' => ['nullable', 'integer'],
            'tolerance.end_plus_minutes' => ['nullable', 'integer'],
        ]);

        $validator = new PdvSaleValidator();
        $results = [];

        $globalOptions = [
            'timezone' => $data['timezone'] ?? null,
            'tolerance' => $data['tolerance'] ?? [],
        ];

        foreach ($data['Lista'] as $item) {
            // Preparar o payload individual como se fosse uma request unitária
            // O Validador espera ['payload' => ..., 'timezone' => ...]
            $singleInput = array_merge($globalOptions, [
                'payload' => $item
            ]);

            $res = $validator->validateFromErpPayload($singleInput);

            // Usar ID ou CodigoDaOperacao como chave para facilitar correlação
            $key = $item['Id'] ?? $item['CodigoDaOperacao'] ?? uniqid();

            $results[] = [
                'input_id' => $key,
                'validation' => $res
            ];
        }

        return response()->json([
            'batch_count' => count($results),
            'results' => $results
        ]);
    }
}
