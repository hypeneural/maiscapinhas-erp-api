<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Pdv\PdvSaleValidator;

class PdvSaleValidateController extends Controller
{
    public function __invoke(Request $request)
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
}
