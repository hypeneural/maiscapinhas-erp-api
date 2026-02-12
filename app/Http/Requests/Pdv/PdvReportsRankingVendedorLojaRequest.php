<?php

declare(strict_types=1);

namespace App\Http\Requests\Pdv;

use Illuminate\Foundation\Http\FormRequest;

class PdvReportsRankingVendedorLojaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'store_pdv_id' => ['nullable', 'integer', 'min:1'],
            'vendedor_id' => ['nullable', 'integer', 'min:1'],
            'canal' => ['nullable', 'string', 'in:HIPER_CAIXA,HIPER_LOJA'],
            'sort_by' => ['nullable', 'string', 'in:total_vendido,qtd_vendas,total_itens'],
            'sort' => ['nullable', 'string', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function queryParameters(): array
    {
        // GET endpoint: force Scribe to extract params as query parameters.
        return [];
    }
}
