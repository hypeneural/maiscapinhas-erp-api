<?php

declare(strict_types=1);

namespace App\Http\Requests\Pdv;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Log;

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
            $supportedSchemaVersions = ['2.0', '3.0'];
        }

        return [
            'schema_version' => ['required', 'string', 'max:10', Rule::in($supportedSchemaVersions)],
            'event_type' => ['sometimes', 'string', 'max:30'],

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
            'turnos.*.duracao_minutos' => ['sometimes', 'integer', 'min:0'],
            'turnos.*.periodo' => ['sometimes', 'string', 'max:20'],
            'turnos.*.responsavel' => ['sometimes', 'nullable', 'array'],
            'turnos.*.responsavel.id_usuario' => ['nullable', 'integer', 'min:1'],
            'turnos.*.responsavel.nome' => ['nullable', 'string', 'max:200'],
            'turnos.*.qtd_vendas' => ['sometimes', 'integer', 'min:0'],
            'turnos.*.total_vendas' => ['sometimes', 'numeric'],
            'turnos.*.qtd_vendedores' => ['sometimes', 'integer', 'min:0'],

            'vendas' => ['sometimes', 'array'],
            'vendas.*.id_operacao' => ['sometimes', 'integer', 'min:1'],
            'vendas.*.canal' => ['sometimes', 'string', Rule::in(['HIPER_CAIXA', 'HIPER_LOJA'])],
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
            'snapshot_turnos' => ['sometimes', 'array'],
            'snapshot_turnos.*.id_turno' => ['sometimes', 'string', 'max:64'],
            'snapshot_turnos.*.sequencial' => ['sometimes', 'integer', 'min:1'],
            'snapshot_turnos.*.fechado' => ['sometimes', 'boolean'],
            'snapshot_turnos.*.data_hora_inicio' => ['sometimes', 'date'],
            'snapshot_turnos.*.data_hora_termino' => ['nullable', 'date'],
            'snapshot_turnos.*.duracao_minutos' => ['sometimes', 'integer', 'min:0'],
            'snapshot_turnos.*.periodo' => ['sometimes', 'string', 'max:20'],
            'snapshot_turnos.*.responsavel' => ['sometimes', 'nullable', 'array'],
            'snapshot_turnos.*.responsavel.id_usuario' => ['nullable', 'integer', 'min:1'],
            'snapshot_turnos.*.responsavel.nome' => ['nullable', 'string', 'max:200'],
            'snapshot_turnos.*.qtd_vendas' => ['sometimes', 'integer', 'min:0'],
            'snapshot_turnos.*.total_vendas' => ['sometimes', 'numeric'],
            'snapshot_turnos.*.qtd_vendedores' => ['sometimes', 'integer', 'min:0'],
            'snapshot_vendas' => ['sometimes', 'array'],
            'snapshot_vendas.*.id_operacao' => ['sometimes', 'integer', 'min:1'],
            'snapshot_vendas.*.canal' => ['sometimes', 'string', Rule::in(['HIPER_CAIXA', 'HIPER_LOJA'])],
            'snapshot_vendas.*.data_hora_inicio' => ['sometimes', 'date'],
            'snapshot_vendas.*.data_hora_termino' => ['nullable', 'date'],
            'snapshot_vendas.*.duracao_segundos' => ['sometimes', 'integer', 'min:0'],
            'snapshot_vendas.*.id_turno' => ['nullable', 'string', 'max:64'],
            'snapshot_vendas.*.turno_seq' => ['nullable', 'integer', 'min:0'],
            'snapshot_vendas.*.qtd_itens' => ['sometimes', 'integer', 'min:0'],
            'snapshot_vendas.*.total_itens' => ['sometimes', 'numeric'],
            'snapshot_vendas.*.vendedor' => ['sometimes', 'nullable', 'array'],
            'snapshot_vendas.*.vendedor.id_usuario' => ['nullable', 'integer', 'min:1'],
            'snapshot_vendas.*.vendedor.nome' => ['nullable', 'string', 'max:200'],

            'ops' => ['sometimes', 'array'],
            'ops.count' => ['sometimes', 'integer', 'min:0'],
            'ops.ids' => ['sometimes', 'array'],
            'ops.ids.*' => ['integer', 'min:1'],
            'ops.loja_count' => ['sometimes', 'integer', 'min:0'],
            'ops.loja_ids' => ['sometimes', 'array'],
            'ops.loja_ids.*' => ['integer', 'min:1'],

            'integrity' => ['required', 'array'],
            'integrity.sync_id' => ['required', 'string', 'min:8', 'max:128'],
            'integrity.warnings' => ['sometimes', 'array'],
            'integrity.warnings.*' => ['string', 'max:1000'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $rawPayload = $this->getContent();
        $payloadMaxChars = (int) config('pdv.log_payload_max_chars', 6000);
        $shouldLogPayload = (bool) config('pdv.log_payload_on_validation_error', true);
        $payloadExcerpt = $shouldLogPayload
            ? substr($rawPayload, 0, $payloadMaxChars)
            : null;

        Log::channel((string) config('pdv.log_channel', 'stack'))->warning('pdv.sync.request_validation_failed', [
            'request_id' => (string) $this->header('X-Request-Id', ''),
            'schema_header' => (string) $this->header('X-PDV-Schema-Version', ''),
            'remote_ip' => $this->ip(),
            'auth_header_present' => $this->header('Authorization') !== null,
            'payload_sha256' => hash('sha256', $rawPayload),
            'payload_bytes' => strlen($rawPayload),
            'payload_excerpt' => $payloadExcerpt,
            'payload_excerpt_truncated' => $shouldLogPayload ? strlen($rawPayload) > $payloadMaxChars : false,
            'sync_id' => (string) data_get($this->input(), 'integrity.sync_id', ''),
            'store_pdv_id' => data_get($this->input(), 'store.id_ponto_venda'),
            'schema_version' => (string) data_get($this->input(), 'schema_version', ''),
            'event_type' => (string) data_get($this->input(), 'event_type', ''),
            'errors' => $validator->errors()->toArray(),
        ]);

        throw new HttpResponseException(response()->json([
            'error' => 'validation',
            'message' => 'Validation failed.',
            'details' => $validator->errors(),
        ], 422));
    }
}
