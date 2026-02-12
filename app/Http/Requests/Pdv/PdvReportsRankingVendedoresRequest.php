<?php

declare(strict_types=1);

namespace App\Http\Requests\Pdv;

use Illuminate\Foundation\Http\FormRequest;

class PdvReportsRankingVendedoresRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mode' => ['nullable', 'string', 'in:daily,weekly,monthly'],
            'reference_date' => ['nullable', 'date'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'store_pdv_id' => ['nullable', 'integer', 'min:1'],
            'canal' => ['nullable', 'string', 'in:HIPER_CAIXA,HIPER_LOJA'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function queryParameters(): array
    {
        // GET endpoint: force Scribe to extract params as query parameters.
        return [];
    }
}
