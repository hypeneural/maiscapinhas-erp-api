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

    protected function prepareForValidation(): void
    {
        $input = $this->all();

        // 1. Normalize Store (PascalCase -> snake_case)
        if (isset($input['store']) && is_array($input['store'])) {
            $s = $input['store'];
            // Map known V5 keys to V4 expected keys
            if (!isset($s['id_ponto_venda']) && isset($s['IdFilial']))
                $s['id_ponto_venda'] = $s['IdFilial'];
            if (!isset($s['id_ponto_venda']) && isset($s['Id']))
                $s['id_ponto_venda'] = $s['Id']; // Fallback
            if (!isset($s['nome']) && isset($s['Nome']))
                $s['nome'] = $s['Nome'];
            if (!isset($s['alias']) && isset($s['Apelido']))
                $s['alias'] = $s['Apelido'];
            if (!isset($s['cnpj']) && isset($s['CnpjDaLoja']))
                $s['cnpj'] = $s['CnpjDaLoja'];
            if (!isset($s['id_filial']) && isset($s['IdFilial']))
                $s['id_filial'] = $s['IdFilial'];
            $input['store'] = $s;
        }

        // 2. Normalize Turnos (PascalCase -> snake_case)
        $normalizeTurno = function ($originalList) {
            if (!is_array($originalList))
                return $originalList;
            return array_map(function ($t) {
                if (!is_array($t))
                    return $t;
                // Direct mappings
                if (isset($t['Canal']))
                    $t['canal'] = $t['Canal'];
                if (isset($t['IdTurno']))
                    $t['id_turno'] = $t['IdTurno'];
                if (isset($t['Sequencial']))
                    $t['sequencial'] = $t['Sequencial'];
                if (isset($t['Fechado']))
                    $t['fechado'] = $t['Fechado'];
                if (isset($t['DataHoraInicio']))
                    $t['data_hora_inicio'] = $t['DataHoraInicio'];
                if (isset($t['DataHoraTermino']))
                    $t['data_hora_termino'] = $t['DataHoraTermino'];
                if (isset($t['DuracaoMinutos']))
                    $t['duracao_minutos'] = $t['DuracaoMinutos'];
                if (isset($t['Periodo']))
                    $t['periodo'] = $t['Periodo'];

                if (isset($t['Responsavel']) && is_array($t['Responsavel'])) {
                    $r = $t['Responsavel'];
                    if (isset($r['Id']))
                        $t['responsavel']['id_usuario'] = $r['Id'];
                    if (isset($r['Nome']))
                        $t['responsavel']['nome'] = $r['Nome'];
                    if (isset($r['Login']))
                        $t['responsavel']['login'] = $r['Login'];
                }
                if (isset($t['Operador']) && is_array($t['Operador'])) {
                    $o = $t['Operador'];
                    if (isset($o['Login']))
                        $t['operador']['login'] = $o['Login'];
                }

                if (isset($t['QtdVendas']))
                    $t['qtd_vendas'] = $t['QtdVendas'];
                if (isset($t['TotalVendas']))
                    $t['total_vendas'] = $t['TotalVendas'];
                if (isset($t['QtdVendedores']))
                    $t['qtd_vendedores'] = $t['QtdVendedores'];

                return $t;
            }, $originalList);
        };

        if (isset($input['turnos']) && is_array($input['turnos'])) {
            $input['turnos'] = $normalizeTurno($input['turnos']);
        }
        // V5 Support
        if (isset($input['turnos_abertos']) && is_array($input['turnos_abertos'])) {
            $input['turnos_abertos'] = $normalizeTurno($input['turnos_abertos']);
        }
        if (isset($input['turnos_fechados']) && is_array($input['turnos_fechados'])) {
            $input['turnos_fechados'] = $normalizeTurno($input['turnos_fechados']);
        }

        // 3. Normalize Vendas (PascalCase -> snake_case)
        if (isset($input['vendas']) && is_array($input['vendas'])) {
            $input['vendas'] = array_map(function ($v) {
                if (!is_array($v))
                    return $v;

                if (isset($v['SaleId']))
                    $v['id_operacao'] = $v['SaleId'];
                if (isset($v['Canal']))
                    $v['canal'] = $v['Canal'];
                if (isset($v['DateTime']))
                    $v['data_hora'] = $v['DateTime'];
                if (isset($v['TurnoId']))
                    $v['id_turno'] = $v['TurnoId'];

                // Total can be string "219.90" or float TotalAmount
                if (isset($v['Total']))
                    $v['total'] = $v['Total'];
                elseif (isset($v['TotalAmount']))
                    $v['total'] = $v['TotalAmount'];

                // Itens
                if (isset($v['Itens']) && is_array($v['Itens'])) {
                    $v['itens'] = array_map(function ($startItem) {
                        if (isset($startItem['LineId']))
                            $startItem['line_id'] = $startItem['LineId'];
                        if (isset($startItem['LineNo']))
                            $startItem['line_no'] = $startItem['LineNo'];
                        if (isset($startItem['Vendedor']['Login']))
                            $startItem['vendedor']['login'] = $startItem['Vendedor']['Login'];
                        return $startItem;
                    }, $v['Itens']);
                }

                // Pagamentos
                if (isset($v['Pagamentos']) && is_array($v['Pagamentos'])) {
                    $v['pagamentos'] = array_map(function ($startPay) {
                        if (isset($startPay['LineId']))
                            $startPay['line_id'] = $startPay['LineId'];
                        // line_no not always present in V5? check sample. Sample has LineId, but no LineNo in pagamentos?
                        // Validation rules say 'pagamentos.*.line_no' is SOMETIMES.
                        return $startPay;
                    }, $v['Pagamentos']);
                }

                return $v;
            }, $input['vendas']);
        }

        $this->replace($input);
    }

    public function rules(): array
    {
        $supportedSchemaVersions = config('pdv.supported_schema_versions', ['3.0', '3.1', '4.0', '5.0']);
        if (!is_array($supportedSchemaVersions) || $supportedSchemaVersions === []) {
            $supportedSchemaVersions = ['3.0', '3.1', '4.0', '5.0'];
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
            'store.cnpj' => ['sometimes', 'nullable', 'string', 'max:18'],
            'store.id_filial' => ['sometimes', 'nullable', 'integer', 'min:1'],

            'window' => ['required', 'array'],
            'window.from' => ['required', 'date'],
            'window.to' => ['required', 'date', 'after_or_equal:window.from'],
            'window.minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],

            'turnos' => ['sometimes', 'array'],
            'turnos.*.canal' => ['sometimes', 'string', Rule::in(['HIPER_CAIXA', 'HIPER_LOJA'])],
            'turnos.*.id_turno' => ['sometimes', 'string', 'max:64'],
            'turnos.*.sequencial' => ['sometimes', 'integer', 'min:1'],
            'turnos.*.fechado' => ['sometimes', 'boolean'],
            'turnos.*.data_hora_inicio' => ['sometimes', 'date'],
            'turnos.*.data_hora_termino' => ['nullable', 'date'],
            'turnos.*.duracao_minutos' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'turnos.*.periodo' => ['sometimes', 'string', 'max:20'],
            'turnos.*.responsavel' => ['sometimes', 'nullable', 'array'],
            'turnos.*.responsavel.id_usuario' => ['nullable', 'integer', 'min:1'],
            'turnos.*.responsavel.nome' => ['nullable', 'string', 'max:200'],
            'turnos.*.operador.login' => ['nullable', 'string', 'max:100'],
            'turnos.*.responsavel.login' => ['nullable', 'string', 'max:100'],
            'turnos.*.qtd_vendas' => ['sometimes', 'integer', 'min:0'],
            'turnos.*.total_vendas' => ['sometimes', 'numeric'],
            'turnos.*.qtd_vendedores' => ['sometimes', 'integer', 'min:0'],

            'turnos_abertos' => ['sometimes', 'array'],
            'turnos_abertos.*.canal' => ['sometimes', 'string', Rule::in(['HIPER_CAIXA', 'HIPER_LOJA'])],
            'turnos_abertos.*.id_turno' => ['sometimes', 'string', 'max:64'],
            'turnos_abertos.*.sequencial' => ['sometimes', 'integer', 'min:1'],
            'turnos_abertos.*.fechado' => ['sometimes', 'boolean'],
            'turnos_abertos.*.data_hora_inicio' => ['sometimes', 'date'],
            'turnos_abertos.*.data_hora_termino' => ['nullable', 'date'],
            'turnos_abertos.*.duracao_minutos' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'turnos_abertos.*.periodo' => ['sometimes', 'string', 'max:20'],
            'turnos_abertos.*.responsavel' => ['sometimes', 'nullable', 'array'],
            'turnos_abertos.*.responsavel.id_usuario' => ['nullable', 'integer', 'min:1'],
            'turnos_abertos.*.responsavel.nome' => ['nullable', 'string', 'max:200'],
            'turnos_abertos.*.operador.login' => ['nullable', 'string', 'max:100'],
            'turnos_abertos.*.responsavel.login' => ['nullable', 'string', 'max:100'],
            'turnos_abertos.*.qtd_vendas' => ['sometimes', 'integer', 'min:0'],
            'turnos_abertos.*.total_vendas' => ['sometimes', 'numeric'],
            'turnos_abertos.*.qtd_vendedores' => ['sometimes', 'integer', 'min:0'],

            'turnos_fechados' => ['sometimes', 'array'],
            'turnos_fechados.*.canal' => ['sometimes', 'string', Rule::in(['HIPER_CAIXA', 'HIPER_LOJA'])],
            'turnos_fechados.*.id_turno' => ['sometimes', 'string', 'max:64'],
            'turnos_fechados.*.sequencial' => ['sometimes', 'integer', 'min:1'],
            'turnos_fechados.*.fechado' => ['sometimes', 'boolean'],
            'turnos_fechados.*.data_hora_inicio' => ['sometimes', 'date'],
            'turnos_fechados.*.data_hora_termino' => ['nullable', 'date'],
            'turnos_fechados.*.duracao_minutos' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'turnos_fechados.*.periodo' => ['sometimes', 'string', 'max:20'],
            'turnos_fechados.*.responsavel' => ['sometimes', 'nullable', 'array'],
            'turnos_fechados.*.responsavel.id_usuario' => ['nullable', 'integer', 'min:1'],
            'turnos_fechados.*.responsavel.nome' => ['nullable', 'string', 'max:200'],
            'turnos_fechados.*.operador.login' => ['nullable', 'string', 'max:100'],
            'turnos_fechados.*.responsavel.login' => ['nullable', 'string', 'max:100'],
            'turnos_fechados.*.qtd_vendas' => ['sometimes', 'integer', 'min:0'],
            'turnos_fechados.*.total_vendas' => ['sometimes', 'numeric'],
            'turnos_fechados.*.qtd_vendedores' => ['sometimes', 'integer', 'min:0'],

            'vendas' => ['sometimes', 'array'],
            'vendas.*.id_operacao' => ['sometimes', 'integer', 'min:1'],
            'vendas.*.canal' => ['sometimes', 'string', Rule::in(['HIPER_CAIXA', 'HIPER_LOJA'])],
            'vendas.*.data_hora' => ['sometimes', 'date'],
            'vendas.*.id_turno' => ['nullable', 'string', 'max:64'],
            'vendas.*.total' => ['sometimes', 'numeric'],
            'vendas.*.itens' => ['sometimes', 'array'],
            'vendas.*.itens.*.line_id' => ['sometimes', 'integer', 'min:1'],
            'vendas.*.itens.*.line_no' => ['sometimes', 'integer', 'min:1'],
            'vendas.*.itens.*.vendedor.login' => ['nullable', 'string', 'max:100'],
            'vendas.*.pagamentos' => ['sometimes', 'array'],
            'vendas.*.pagamentos.*.line_id' => ['sometimes', 'integer', 'min:1'],
            'vendas.*.pagamentos.*.line_no' => ['sometimes', 'integer', 'min:1'],
            'resumo' => ['sometimes', 'array'],
            'snapshot_turnos' => ['sometimes', 'array'],
            'snapshot_turnos.*.canal' => ['sometimes', 'string', Rule::in(['HIPER_CAIXA', 'HIPER_LOJA'])],
            'snapshot_turnos.*.id_turno' => ['sometimes', 'string', 'max:64'],
            'snapshot_turnos.*.sequencial' => ['sometimes', 'integer', 'min:1'],
            'snapshot_turnos.*.fechado' => ['sometimes', 'boolean'],
            'snapshot_turnos.*.data_hora_inicio' => ['sometimes', 'date'],
            'snapshot_turnos.*.data_hora_termino' => ['nullable', 'date'],
            'snapshot_turnos.*.duracao_minutos' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'snapshot_turnos.*.periodo' => ['sometimes', 'string', 'max:20'],
            'snapshot_turnos.*.responsavel' => ['sometimes', 'nullable', 'array'],
            'snapshot_turnos.*.responsavel.id_usuario' => ['nullable', 'integer', 'min:1'],
            'snapshot_turnos.*.responsavel.nome' => ['nullable', 'string', 'max:200'],
            'snapshot_turnos.*.operador.login' => ['nullable', 'string', 'max:100'],
            'snapshot_turnos.*.responsavel.login' => ['nullable', 'string', 'max:100'],
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
            'snapshot_vendas.*.vendedor.login' => ['nullable', 'string', 'max:100'],

            'resumo.by_vendor' => ['sometimes', 'array'],
            'resumo.by_vendor.*.login' => ['nullable', 'string', 'max:100'],

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
