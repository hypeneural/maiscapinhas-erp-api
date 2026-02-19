<?php

declare(strict_types=1);

namespace App\Http\Requests\Pdv;

use App\Http\Requests\Pdv\Concerns\ValidatesStoreIdentifier;
use Illuminate\Foundation\Http\FormRequest;

class PdvReportsVendasRequest extends FormRequest
{
    use ValidatesStoreIdentifier;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'store_id' => $this->storeIdRules(),
            'store_pdv_id' => ['nullable', 'integer', 'min:1'],
            'store_alias' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'vendedor_id' => ['nullable', 'integer', 'min:1'],
            'canal' => ['nullable', 'string', 'in:HIPER_CAIXA,HIPER_LOJA'],
            'id_turno' => ['nullable', 'string', 'max:64'],
            'turno_seq' => ['nullable', 'integer', 'min:1'],
            'id_finalizador' => ['nullable', 'integer', 'min:1'],
            'meio_pagamento' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string', 'in:asc,desc'],
            'min_total' => ['nullable', 'numeric', 'min:0'],
            'max_total' => ['nullable', 'numeric', 'min:0', 'gte:min_total'],
        ];
    }

    public function messages(): array
    {
        return [
            'store_pdv_id.integer' => 'O campo store_pdv_id deve ser numerico.',
            'store_pdv_id.min' => 'O campo store_pdv_id deve ser maior que zero.',
            'store_alias.max' => 'O campo store_alias excede o tamanho maximo permitido.',
            'from.date' => 'O campo from deve ser uma data valida.',
            'to.date' => 'O campo to deve ser uma data valida.',
            'to.after_or_equal' => 'O campo to deve ser maior ou igual ao campo from.',
            'vendedor_id.integer' => 'O campo vendedor_id deve ser numerico.',
            'vendedor_id.min' => 'O campo vendedor_id deve ser maior que zero.',
            'canal.in' => 'O campo canal deve ser HIPER_CAIXA ou HIPER_LOJA.',
            'id_turno.max' => 'O campo id_turno excede o tamanho maximo permitido.',
            'turno_seq.integer' => 'O campo turno_seq deve ser numerico.',
            'turno_seq.min' => 'O campo turno_seq deve ser maior que zero.',
            'id_finalizador.integer' => 'O campo id_finalizador deve ser numerico.',
            'id_finalizador.min' => 'O campo id_finalizador deve ser maior que zero.',
            'meio_pagamento.max' => 'O campo meio_pagamento excede o tamanho maximo permitido.',
            'per_page.integer' => 'O campo per_page deve ser numerico.',
            'per_page.min' => 'O campo per_page deve ser maior que zero.',
            'per_page.max' => 'O campo per_page nao pode ser maior que 100.',
            'sort.in' => 'O campo sort deve ser asc ou desc.',
            'min_total.numeric' => 'O campo min_total deve ser numerico.',
            'min_total.min' => 'O campo min_total deve ser maior ou igual a zero.',
            'max_total.numeric' => 'O campo max_total deve ser numerico.',
            'max_total.min' => 'O campo max_total deve ser maior ou igual a zero.',
            'max_total.gte' => 'O campo max_total deve ser maior ou igual ao campo min_total.',
        ];
    }

    public function queryParameters(): array
    {
        // GET endpoint: force Scribe to extract params as query parameters.
        return [];
    }
}
