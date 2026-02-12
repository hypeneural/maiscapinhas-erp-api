<?php

declare(strict_types=1);

namespace App\Http\Requests\Pdv;

use Illuminate\Foundation\Http\FormRequest;

class PdvReportsVendasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'store_pdv_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'vendedor_id' => ['nullable', 'integer', 'min:1'],
            'canal' => ['nullable', 'string', 'in:HIPER_CAIXA,HIPER_LOJA'],
            'id_turno' => ['nullable', 'string', 'max:64'],
            'id_finalizador' => ['nullable', 'integer', 'min:1'],
            'meio_pagamento' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }

    public function messages(): array
    {
        return [
            'store_id.integer' => 'O campo store_id deve ser numerico.',
            'store_id.exists' => 'A loja informada em store_id nao foi encontrada.',
            'store_pdv_id.integer' => 'O campo store_pdv_id deve ser numerico.',
            'store_pdv_id.min' => 'O campo store_pdv_id deve ser maior que zero.',
            'from.date' => 'O campo from deve ser uma data valida.',
            'to.date' => 'O campo to deve ser uma data valida.',
            'to.after_or_equal' => 'O campo to deve ser maior ou igual ao campo from.',
            'vendedor_id.integer' => 'O campo vendedor_id deve ser numerico.',
            'vendedor_id.min' => 'O campo vendedor_id deve ser maior que zero.',
            'canal.in' => 'O campo canal deve ser HIPER_CAIXA ou HIPER_LOJA.',
            'id_turno.max' => 'O campo id_turno excede o tamanho maximo permitido.',
            'id_finalizador.integer' => 'O campo id_finalizador deve ser numerico.',
            'id_finalizador.min' => 'O campo id_finalizador deve ser maior que zero.',
            'meio_pagamento.max' => 'O campo meio_pagamento excede o tamanho maximo permitido.',
            'per_page.integer' => 'O campo per_page deve ser numerico.',
            'per_page.min' => 'O campo per_page deve ser maior que zero.',
            'per_page.max' => 'O campo per_page nao pode ser maior que 100.',
            'sort.in' => 'O campo sort deve ser asc ou desc.',
        ];
    }

    public function queryParameters(): array
    {
        // GET endpoint: force Scribe to extract params as query parameters.
        return [];
    }
}
