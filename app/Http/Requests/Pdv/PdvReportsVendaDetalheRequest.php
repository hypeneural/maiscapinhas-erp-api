<?php

declare(strict_types=1);

namespace App\Http\Requests\Pdv;

use Illuminate\Foundation\Http\FormRequest;

class PdvReportsVendaDetalheRequest extends FormRequest
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
            'canal' => ['required', 'string', 'in:HIPER_CAIXA,HIPER_LOJA'],
            'id_operacao' => ['required', 'integer', 'min:1'],
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
            'canal.required' => 'O campo canal e obrigatorio.',
            'canal.in' => 'O campo canal deve ser HIPER_CAIXA ou HIPER_LOJA.',
            'id_operacao.required' => 'O campo id_operacao e obrigatorio.',
            'id_operacao.integer' => 'O campo id_operacao deve ser numerico.',
            'id_operacao.min' => 'O campo id_operacao deve ser maior que zero.',
        ];
    }

    public function queryParameters(): array
    {
        // GET endpoint: force Scribe to extract params as query parameters.
        return [];
    }
}

