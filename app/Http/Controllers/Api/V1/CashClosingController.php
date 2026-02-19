<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\CashClosing;
use App\Models\CashShift;
use App\Services\CashClosingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * @group Fechamento de Caixa
 *
 * Endpoints para gerenciar o fluxo de fechamento de caixa.
 *
 * **Workflow de Estados:**
 * ```
 * draft → submitted → approved
 *                   → rejected → draft → ...
 * ```
 *
 * - `draft` - Rascunho (editável)
 * - `submitted` - Enviado para conferência
 * - `approved` - Aprovado (imutável)
 * - `rejected` - Rejeitado (volta para draft para correção)
 */
class CashClosingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private CashClosingService $cashClosingService
    ) {
    }

    /**
     * Enviar fechamento para conferência
     *
     * Envia um fechamento de caixa para ser conferido/aprovado.
     * O fechamento deve estar em status `draft` ou `rejected` para ser enviado.
     *
     * **Quem pode usar:** Vendedor responsável pelo turno ou níveis superiores.
     *
     * **Regras de negócio:**
     * - Todas as divergências (diff_value ≠ 0) devem ter justificativa preenchida
     * - O status deve ser `draft` ou `rejected` para poder enviar
     *
     * **Erros possíveis:**
     * - `409` - Status inválido (já enviado ou aprovado)
     * - `422` - Divergências sem justificativa
     * - `403` - Sem acesso à loja
     * - `404` - Fechamento não encontrado
     *
     * @urlParam shift integer required ID do turno. Example: 1
     *
     * @response 200 scenario="Enviado com sucesso" {
     *   "data": {
     *     "id": 1,
     *     "status": "submitted",
     *     "version": 1,
     *     "lines": [],
     *     "cash_shift": { "id": 1, "date": "2026-01-07", "shift_code": "M" }
     *   },
     *   "meta": { "timestamp": "2026-01-07T12:00:00Z" }
     * }
     *
     * @response 422 scenario="Divergência sem justificativa" {
     *   "message": "All divergences must be justified before submitting.",
     *   "errors": { "lines": ["Linha 'Dinheiro' possui divergência sem justificativa."] }
     * }
     *
     * @response 409 scenario="Status inválido" {
     *   "error": { "code": 409, "message": "Cannot submit closing with status 'approved'." }
     * }
     */
    public function submit(Request $request, CashShift $shift): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasAccessToStore($shift->store_id)) {
            return $this->forbidden('You do not have access to this store.');
        }

        $closing = $shift->cashClosing;

        if (!$closing) {
            return $this->notFound('No closing found for this shift.');
        }

        try {
            $closing = $this->cashClosingService->submit($closing, $user);
            return $this->success($closing->load(['lines', 'cashShift.store', 'cashShift.seller']));
        } catch (ValidationException $e) {
            throw $e;
        } catch (ConflictHttpException $e) {
            return $this->conflict($e->getMessage());
        }
    }

    /**
     * Aprovar fechamento de caixa
     *
     * Aprova um fechamento que foi enviado para conferência.
     * Esta ação é **irreversível** - fechamentos aprovados não podem ser editados.
     *
     * **Quem pode usar:** Conferentes, Gerentes ou Admins da loja.
     *
     * **Regras de negócio:**
     * - O fechamento deve estar em status `submitted`
     * - Após aprovação, os dados são considerados oficiais para cálculo de bônus/comissão
     *
     * **Erros possíveis:**
     * - `409` - Status inválido (não está em submitted)
     * - `403` - Sem permissão para aprovar
     * - `404` - Fechamento não encontrado
     *
     * @urlParam shift integer required ID do turno. Example: 1
     *
     * @response 200 scenario="Aprovado com sucesso" {
     *   "data": {
     *     "id": 1,
     *     "status": "approved",
     *     "closed_by": 3,
     *     "closed_at": "2026-01-07T12:00:00+00:00"
     *   },
     *   "meta": { "timestamp": "2026-01-07T12:00:00Z" }
     * }
     *
     * @response 409 scenario="Não pode aprovar" {
     *   "error": { "code": 409, "message": "Cannot approve closing with status 'draft'." }
     * }
     */
    public function approve(Request $request, CashShift $shift): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasAccessToStore($shift->store_id)) {
            return $this->forbidden('You do not have access to this store.');
        }

        if (!$user->canApproveInStore($shift->store_id)) {
            return $this->forbidden('You do not have permission to approve closings in this store.');
        }

        $closing = $shift->cashClosing;

        if (!$closing) {
            return $this->notFound('No closing found for this shift.');
        }

        try {
            $closing = $this->cashClosingService->approve($closing, $user);
            return $this->success($closing->load(['lines', 'cashShift.store', 'cashShift.seller', 'closedByUser']));
        } catch (ConflictHttpException $e) {
            return $this->conflict($e->getMessage());
        }
    }

    /**
     * Rejeitar fechamento de caixa
     *
     * Rejeita um fechamento enviado para conferência, retornando-o ao status `draft`
     * para que o vendedor possa corrigir os problemas apontados.
     *
     * **Quem pode usar:** Conferentes, Gerentes ou Admins da loja.
     *
     * **Regras de negócio:**
     * - O fechamento deve estar em status `submitted`
     * - É obrigatório informar o motivo da rejeição (mín. 10 caracteres)
     * - O vendedor será notificado e poderá reenviar após correções
     *
     * **Erros possíveis:**
     * - `409` - Status inválido (não está em submitted)
     * - `403` - Sem permissão para rejeitar
     * - `422` - Motivo não informado ou muito curto
     *
     * @urlParam shift integer required ID do turno. Example: 1
     * @bodyParam reason string required Motivo da rejeição (mín. 10, máx. 500 caracteres). Example: Valores de cartão não conferem com relatório do PDV.
     *
     * @response 200 scenario="Rejeitado com sucesso" {
     *   "data": {
     *     "id": 1,
     *     "status": "rejected"
     *   },
     *   "meta": { "timestamp": "2026-01-07T12:00:00Z" }
     * }
     *
     * @response 422 scenario="Motivo não informado" {
     *   "message": "The reason field is required.",
     *   "errors": { "reason": ["The reason field is required."] }
     * }
     */
    public function reject(Request $request, CashShift $shift): JsonResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $user = $request->user();

        if (!$user->hasAccessToStore($shift->store_id)) {
            return $this->forbidden('You do not have access to this store.');
        }

        if (!$user->canApproveInStore($shift->store_id)) {
            return $this->forbidden('You do not have permission to reject closings in this store.');
        }

        $closing = $shift->cashClosing;

        if (!$closing) {
            return $this->notFound('No closing found for this shift.');
        }

        try {
            $closing = $this->cashClosingService->reject($closing, $user, $request->input('reason'));
            return $this->success($closing->load(['lines', 'cashShift.store', 'cashShift.seller']));
        } catch (ConflictHttpException $e) {
            return $this->conflict($e->getMessage());
        }
    }

    /**
     * Criar fechamento de caixa
     *
     * Cria um novo fechamento de caixa para um turno existente,
     * incluindo os valores por meio de pagamento.
     *
     * **Quem pode usar:** Qualquer usuário com acesso à loja.
     *
     * **Regras de negócio:**
     * - O turno não pode ter um fechamento existente
     * - A justificativa é opcional e se aplica ao turno inteiro (não por linha)
     * - O campo `justified` indica se a divergência foi justificada (usado para cálculo de bônus)
     *
     * @urlParam shift integer required ID do turno. Example: 1
     * @bodyParam lines array required Lista de linhas (meios de pagamento). Example: [{"label": "Dinheiro", "system_value": 1000, "real_value": 950}]
     * @bodyParam lines.*.label string required Nome do meio de pagamento. Example: Dinheiro
     * @bodyParam lines.*.system_value number required Valor informado no sistema. Example: 1000.00
     * @bodyParam lines.*.real_value number required Valor real contado. Example: 950.00
     * @bodyParam justification_text string Justificativa geral para divergências (opcional). Example: Troco dado incorretamente
     * @bodyParam justified boolean Se a divergência está justificada (usado para bônus). Example: true
     *
     * @response 201 scenario="Fechamento criado" {
     *   "data": {
     *     "id": 1,
     *     "status": "draft",
     *     "version": 1,
     *     "justification_text": "Troco dado incorretamente",
     *     "justified": true,
     *     "lines": [
     *       { "id": 1, "label": "Dinheiro", "system_value": 1000.00, "real_value": 950.00, "diff_value": -50.00 }
     *     ]
     *   },
     *   "meta": { "timestamp": "2026-01-07T12:00:00Z" }
     * }
     *
     * @response 409 scenario="Fechamento já existe" {
     *   "error": { "code": 409, "message": "Closing already exists for this shift." }
     * }
     */
    public function store(Request $request, CashShift $shift): JsonResponse
    {
        $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.label' => ['required', 'string'],
            'lines.*.system_value' => ['required', 'numeric', 'min:0'],
            'lines.*.real_value' => ['required', 'numeric', 'min:0'],
            'justification_text' => ['nullable', 'string', 'max:1000'],
            'justified' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();

        if (!$user->hasAccessToStore($shift->store_id)) {
            return $this->forbidden('You do not have access to this store.');
        }

        // Check if closing already exists
        if ($shift->cashClosing) {
            return $this->conflict('Closing already exists for this shift. Use PUT to update.');
        }

        $closing = $this->cashClosingService->createWithLines(
            $shift,
            $request->input('lines'),
            $request->input('justification_text'),
            $request->boolean('justified', false)
        );

        return $this->created($closing->load(['cashShift.store', 'cashShift.seller']));
    }

    /**
     * Atualizar fechamento de caixa
     *
     * Atualiza um fechamento de caixa existente (apenas em status draft ou rejected).
     *
     * **Quem pode usar:** Qualquer usuário com acesso à loja.
     *
     * **Regras de negócio:**
     * - O fechamento deve estar em status `draft` ou `rejected`
     * - A justificativa é opcional e se aplica ao turno inteiro
     * - Todas as linhas são substituídas pelos novos valores
     *
     * @urlParam shift integer required ID do turno. Example: 1
     * @bodyParam lines array required Lista de linhas (meios de pagamento). Example: [{"label": "Dinheiro", "system_value": 1000, "real_value": 1000}]
     * @bodyParam justification_text string Justificativa geral para divergências (opcional). Example: null
     * @bodyParam justified boolean Se a divergência está justificada. Example: false
     *
     * @response 200 scenario="Fechamento atualizado" {
     *   "data": {
     *     "id": 1,
     *     "status": "draft",
     *     "version": 2,
     *     "justification_text": null,
     *     "justified": false,
     *     "lines": [...]
     *   }
     * }
     *
     * @response 409 scenario="Não pode atualizar" {
     *   "error": { "code": 409, "message": "Cannot update closing with status 'approved'." }
     * }
     */
    public function update(Request $request, CashShift $shift): JsonResponse
    {
        $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.label' => ['required', 'string'],
            'lines.*.system_value' => ['required', 'numeric', 'min:0'],
            'lines.*.real_value' => ['required', 'numeric', 'min:0'],
            'justification_text' => ['nullable', 'string', 'max:1000'],
            'justified' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();

        if (!$user->hasAccessToStore($shift->store_id)) {
            return $this->forbidden('You do not have access to this store.');
        }

        $closing = $shift->cashClosing;

        if (!$closing) {
            return $this->notFound('No closing found for this shift. Use POST to create.');
        }

        try {
            $closing = $this->cashClosingService->updateWithLines(
                $closing,
                $request->input('lines'),
                $request->input('justification_text'),
                $request->boolean('justified', false)
            );

            return $this->success($closing->load(['cashShift.store', 'cashShift.seller']));
        } catch (ConflictHttpException $e) {
            return $this->conflict($e->getMessage());
        }
    }

    /**
     * Obter detalhes do fechamento
     *
     * Retorna os detalhes completos de um fechamento de caixa,
     * incluindo todas as linhas e a justificativa geral.
     *
     * **Quem pode usar:** Qualquer usuário com acesso à loja.
     *
     * @urlParam shift integer required ID do turno. Example: 1
     *
     * @response 200 scenario="Detalhes do fechamento" {
     *   "data": {
     *     "id": 1,
     *     "status": "approved",
     *     "version": 2,
     *     "justification_text": "Troco incorreto no início do turno",
     *     "justified": true,
     *     "closed_at": "2026-01-07T12:00:00+00:00",
     *     "lines": [
     *       { "id": 1, "label": "Dinheiro", "system_value": 500.00, "real_value": 495.00, "diff_value": -5.00 }
     *     ],
     *     "cash_shift": { "id": 1, "date": "2026-01-07", "shift_code": "M" },
     *     "closed_by_user": { "id": 3, "name": "Ana Conferente" }
     *   },
     *   "meta": { "timestamp": "2026-01-07T12:00:00Z" }
     * }
     */
    public function show(Request $request, CashShift $shift): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasAccessToStore($shift->store_id)) {
            return $this->forbidden('You do not have access to this store.');
        }

        $closing = $shift->cashClosing;

        if (!$closing) {
            return $this->notFound('No closing found for this shift.');
        }

        return $this->success($closing->load(['lines', 'cashShift.store', 'cashShift.seller', 'closedByUser', 'activities.causer']));
    }
}
