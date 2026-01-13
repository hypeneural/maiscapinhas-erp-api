<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\WhatsApp\CheckNumbersRequest;
use App\Http\Requests\WhatsApp\SendMediaRequest;
use App\Http\Requests\WhatsApp\SendTextRequest;
use App\Http\Traits\ApiResponse;
use App\Models\WhatsAppInstance;
use App\Services\WhatsApp\EvolutionClientFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * @group WhatsApp - Mensagens
 *
 * Endpoints para envio de mensagens e verificação de números via WhatsApp.
 *
 * Estes endpoints permitem que o sistema envie mensagens através
 * das instâncias WhatsApp configuradas.
 *
 * **Autorização:**
 * - Super admin: acesso total a qualquer instância
 * - Usuários: acesso a instâncias do seu escopo (user/store/global)
 *
 * **Resolução de Instância:**
 * Se não informar instance_id, o sistema resolve automaticamente:
 * 1. Instância do usuário (se existir)
 * 2. Instância da loja (se usuário tiver vínculo)
 * 3. Instância global favorita
 */
class WhatsAppMessageController extends Controller
{
    use ApiResponse;

    public function __construct(
        private EvolutionClientFactory $clientFactory,
    ) {
    }

    /**
     * Enviar mensagem de texto
     *
     * Envia uma mensagem de texto via WhatsApp.
     *
     * @urlParam instance integer required ID da instância WhatsApp. Example: 1
     * @bodyParam number string required Número do destinatário (com DDI, só dígitos). Example: 5548999999999
     * @bodyParam text string required Texto da mensagem (max 4000 chars). Example: Olá! Seu pedido está pronto.
     *
     * @response 200 scenario="Mensagem enviada" {
     *   "data": {
     *     "ok": true,
     *     "message_id": "3EB0C767D097B3C5C12345",
     *     "provider_data": { "key": { "id": "..." } }
     *   }
     * }
     *
     * @response 422 scenario="Instância sem API Key" {
     *   "message": "Instância sem API Key configurada."
     * }
     *
     * @response 403 scenario="Instância inativa" {
     *   "message": "Instância não está ativa."
     * }
     *
     * @response 502 scenario="Erro no provedor" {
     *   "message": "Erro ao enviar mensagem.",
     *   "errors": { "provider_status": 500 }
     * }
     */
    public function sendText(SendTextRequest $request, WhatsAppInstance $instance): JsonResponse
    {
        $authError = $this->authorizeInstance($request, $instance);
        if ($authError) {
            return $authError;
        }

        if (!$instance->is_active) {
            return $this->forbidden('Instância não está ativa.');
        }

        try {
            $client = $this->clientFactory->make($instance);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $result = $client->sendText(
            $request->input('number'),
            $request->input('text'),
        );

        if (!$result['ok']) {
            return $this->error('Erro ao enviar mensagem.', 502, [
                'provider_status' => $result['status'],
                'provider_data' => $result['data'],
            ]);
        }

        return $this->success([
            'ok' => true,
            'message_id' => $result['data']['key']['id'] ?? null,
            'provider_data' => $result['data'],
        ]);
    }

    /**
     * Enviar mensagem com mídia
     *
     * Envia uma mensagem com mídia (imagem, vídeo, documento, áudio) via WhatsApp.
     *
     * @urlParam instance integer required ID da instância WhatsApp. Example: 1
     * @bodyParam number string required Número do destinatário (com DDI). Example: 5548999999999
     * @bodyParam mediatype string required Tipo: image, video, document, audio. Example: image
     * @bodyParam mimetype string required MIME type do arquivo. Example: image/jpeg
     * @bodyParam media string required URL pública do arquivo. Example: https://example.com/image.jpg
     * @bodyParam fileName string Nome do arquivo (para documents). Example: documento.pdf
     * @bodyParam caption string Legenda da mídia. Example: Aqui está seu comprovante!
     *
     * @response 200 scenario="Mídia enviada" {
     *   "data": { "ok": true, "message_id": "3EB0C767D097B3C5C12345" }
     * }
     */
    public function sendMedia(SendMediaRequest $request, WhatsAppInstance $instance): JsonResponse
    {
        $authError = $this->authorizeInstance($request, $instance);
        if ($authError) {
            return $authError;
        }

        if (!$instance->is_active) {
            return $this->forbidden('Instância não está ativa.');
        }

        try {
            $client = $this->clientFactory->make($instance);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $payload = [
            'number' => $request->input('number'),
            'mediatype' => $request->input('mediatype'),
            'mimetype' => $request->input('mimetype'),
            'media' => $request->input('media'),
        ];

        if ($request->filled('fileName')) {
            $payload['fileName'] = $request->input('fileName');
        }
        if ($request->filled('caption')) {
            $payload['caption'] = $request->input('caption');
        }

        $result = $client->sendMedia($payload);

        if (!$result['ok']) {
            return $this->error('Erro ao enviar mídia.', 502, [
                'provider_status' => $result['status'],
                'provider_data' => $result['data'],
            ]);
        }

        return $this->success([
            'ok' => true,
            'message_id' => $result['data']['key']['id'] ?? null,
            'provider_data' => $result['data'],
        ]);
    }

    /**
     * Verificar números WhatsApp
     *
     * Verifica se uma lista de números possui WhatsApp.
     *
     * @urlParam instance integer required ID da instância WhatsApp. Example: 1
     * @bodyParam numbers array required Lista de números (max 200). Example: ["5548999999999", "5548888888888"]
     *
     * @response 200 scenario="Verificação realizada" {
     *   "data": {
     *     "ok": true,
     *     "results": [
     *       { "number": "5548999999999", "exists": true, "jid": "5548999999999@s.whatsapp.net" },
     *       { "number": "5548888888888", "exists": false, "jid": null }
     *     ]
     *   }
     * }
     */
    public function checkNumbers(CheckNumbersRequest $request, WhatsAppInstance $instance): JsonResponse
    {
        $authError = $this->authorizeInstance($request, $instance);
        if ($authError) {
            return $authError;
        }

        if (!$instance->is_active) {
            return $this->forbidden('Instância não está ativa.');
        }

        try {
            $client = $this->clientFactory->make($instance);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $result = $client->checkWhatsAppNumbers($request->input('numbers'));

        if (!$result['ok']) {
            return $this->error('Erro ao verificar números.', 502, [
                'provider_status' => $result['status'],
                'provider_data' => $result['data'],
            ]);
        }

        return $this->success([
            'ok' => true,
            'results' => $result['data'],
        ]);
    }

    /**
     * Authorize user access to the instance.
     */
    private function authorizeInstance(Request $request, WhatsAppInstance $instance): ?JsonResponse
    {
        $user = $request->user();

        // Super admin can access all
        if ($user->isSuperAdmin()) {
            return null;
        }

        // Global instance: any authenticated user can use
        if ($instance->scope === 'global') {
            return null;
        }

        // User-scoped instance: only owner
        if ($instance->user_id && $instance->user_id !== $user->id) {
            return $this->forbidden('Você não tem acesso a esta instância.');
        }

        // Store-scoped instance: user must have access to store
        if ($instance->store_id && !$user->hasAccessToStore($instance->store_id)) {
            return $this->forbidden('Você não tem acesso a esta instância.');
        }

        return null;
    }
}
