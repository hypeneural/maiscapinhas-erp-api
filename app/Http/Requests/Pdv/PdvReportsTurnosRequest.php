<?php

declare(strict_types=1);

namespace App\Http\Requests\Pdv;

use Illuminate\Foundation\Http\FormRequest;

class PdvReportsTurnosRequest extends FormRequest
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
            'date' => ['required', 'date'],
            'sequencial' => ['nullable', 'integer', 'min:1'],
            'periodo' => ['nullable', 'string', 'in:MATUTINO,VESPERTINO,NOTURNO'],
            'fechado' => ['nullable', 'boolean'],
            'operador_id' => ['nullable', 'integer', 'min:1'],
            'responsavel_id' => ['nullable', 'integer', 'min:1'],
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
            'date.required' => 'O campo date e obrigatorio.',
            'date.date' => 'O campo date deve estar no formato de data valido (YYYY-MM-DD).',
            'sequencial.integer' => 'O campo sequencial deve ser numerico.',
            'sequencial.min' => 'O campo sequencial deve ser maior que zero.',
            'periodo.in' => 'O campo periodo deve ser MATUTINO, VESPERTINO ou NOTURNO.',
            'fechado.boolean' => 'O campo fechado deve ser true/false (ou 1/0).',
            'operador_id.integer' => 'O campo operador_id deve ser numerico.',
            'operador_id.min' => 'O campo operador_id deve ser maior que zero.',
            'responsavel_id.integer' => 'O campo responsavel_id deve ser numerico.',
            'responsavel_id.min' => 'O campo responsavel_id deve ser maior que zero.',
        ];
    }

    public function queryParameters(): array
    {
        // GET endpoint: force Scribe to extract params as query parameters.
        return [];
    }
}
