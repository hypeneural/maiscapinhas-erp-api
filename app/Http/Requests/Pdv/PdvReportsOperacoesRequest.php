<?php

declare(strict_types=1);

namespace App\Http\Requests\Pdv;

use Illuminate\Foundation\Http\FormRequest;

class PdvReportsOperacoesRequest extends FormRequest
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
            'store_alias' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'tipo_operacao' => ['nullable', 'string', 'in:venda,fechamento_caixa'],
            'status' => ['nullable', 'string', 'max:20'],
            'vendedor_id' => ['nullable', 'integer', 'min:1'],
            'canal' => ['nullable', 'string', 'in:HIPER_CAIXA,HIPER_LOJA'],
            'turno_seq' => ['nullable', 'integer', 'min:1'],
            'id_finalizador' => ['nullable', 'integer', 'min:1'],
            'meio_pagamento' => ['nullable', 'string', 'max:120'],
            'min_total' => ['nullable', 'numeric', 'min:0'],
            'max_total' => ['nullable', 'numeric', 'min:0', 'gte:min_total'],
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
            'store_alias.max' => 'O campo store_alias excede o tamanho maximo permitido.',
            'from.date' => 'O campo from deve ser uma data valida.',
            'to.date' => 'O campo to deve ser uma data valida.',
            'to.after_or_equal' => 'O campo to deve ser maior ou igual ao campo from.',
            'tipo_operacao.in' => 'O campo tipo_operacao deve ser venda ou fechamento_caixa.',
            'status.max' => 'O campo status excede o tamanho maximo permitido.',
            'vendedor_id.integer' => 'O campo vendedor_id deve ser numerico.',
            'vendedor_id.min' => 'O campo vendedor_id deve ser maior que zero.',
            'canal.in' => 'O campo canal deve ser HIPER_CAIXA ou HIPER_LOJA.',
            'turno_seq.integer' => 'O campo turno_seq deve ser numerico.',
            'turno_seq.min' => 'O campo turno_seq deve ser maior que zero.',
            'id_finalizador.integer' => 'O campo id_finalizador deve ser numerico.',
            'id_finalizador.min' => 'O campo id_finalizador deve ser maior que zero.',
            'meio_pagamento.max' => 'O campo meio_pagamento excede o tamanho maximo permitido.',
            'min_total.numeric' => 'O campo min_total deve ser numerico.',
            'min_total.min' => 'O campo min_total deve ser maior ou igual a zero.',
            'max_total.numeric' => 'O campo max_total deve ser numerico.',
            'max_total.min' => 'O campo max_total deve ser maior ou igual a zero.',
            'max_total.gte' => 'O campo max_total deve ser maior ou igual ao campo min_total.',
            'per_page.integer' => 'O campo per_page deve ser numerico.',
            'per_page.min' => 'O campo per_page deve ser maior que zero.',
            'per_page.max' => 'O campo per_page nao pode ser maior que 100.',
            'sort.in' => 'O campo sort deve ser asc ou desc.',
        ];
    }

    public function queryParameters(): array
    {
        return [];
    }
}
