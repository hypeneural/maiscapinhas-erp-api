<?php

declare(strict_types=1);

namespace App\Http\Requests\Pdv;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\Validation\Validator;

class PdvSyncIngestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $supportedSchemaVersions = config('pdv.supported_schema_versions', ['2.0']);
        if (!is_array($supportedSchemaVersions) || $supportedSchemaVersions === []) {
            $supportedSchemaVersions = ['2.0'];
        }

        return [
            'schema_version' => ['required', 'string', 'max:10', Rule::in($supportedSchemaVersions)],

            'agent' => ['sometimes', 'array'],
            'agent.version' => ['sometimes', 'string', 'max:20'],
            'agent.machine' => ['sometimes', 'string', 'max:120'],
            'agent.sent_at' => ['sometimes', 'date'],

            'store' => ['required', 'array'],
            'store.id_ponto_venda' => ['required', 'integer', 'min:1'],
            'store.nome' => ['sometimes', 'string', 'max:255'],
            'store.alias' => ['sometimes', 'string', 'max:100'],

            'window' => ['required', 'array'],
            'window.from' => ['required', 'date'],
            'window.to' => ['required', 'date', 'after_or_equal:window.from'],
            'window.minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],

            'turnos' => ['sometimes', 'array'],
            'turnos.*.id_turno' => ['sometimes', 'string', 'max:64'],
            'turnos.*.sequencial' => ['sometimes', 'integer', 'min:1'],
            'turnos.*.fechado' => ['sometimes', 'boolean'],
            'turnos.*.data_hora_inicio' => ['sometimes', 'date'],
            'turnos.*.data_hora_termino' => ['nullable', 'date'],

            'vendas' => ['sometimes', 'array'],
            'vendas.*.id_operacao' => ['sometimes', 'integer', 'min:1'],
            'vendas.*.data_hora' => ['sometimes', 'date'],
            'vendas.*.id_turno' => ['nullable', 'string', 'max:64'],
            'vendas.*.total' => ['sometimes', 'numeric'],
            'vendas.*.itens' => ['sometimes', 'array'],
            'vendas.*.itens.*.line_id' => ['sometimes', 'integer', 'min:1'],
            'vendas.*.itens.*.line_no' => ['sometimes', 'integer', 'min:1'],
            'vendas.*.pagamentos' => ['sometimes', 'array'],
            'vendas.*.pagamentos.*.line_id' => ['sometimes', 'integer', 'min:1'],
            'vendas.*.pagamentos.*.line_no' => ['sometimes', 'integer', 'min:1'],
            'resumo' => ['sometimes', 'array'],

            'ops' => ['sometimes', 'array'],
            'ops.count' => ['sometimes', 'integer', 'min:0'],
            'ops.ids' => ['sometimes', 'array'],
            'ops.ids.*' => ['integer', 'min:1'],

            'integrity' => ['required', 'array'],
            'integrity.sync_id' => ['required', 'string', 'min:8', 'max:128'],
            'integrity.warnings' => ['sometimes', 'array'],
            'integrity.warnings.*' => ['string', 'max:1000'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'error' => 'validation',
            'message' => 'Validation failed.',
            'details' => $validator->errors(),
        ], 422));
    }
}
