<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CapaPersonalizadaStatus;
use App\Models\CapaPersonalizada;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppNotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CapaPersonalizadaService
{
    public function __construct(
        private readonly WhatsAppNotificationService $whatsAppService,
    ) {
    }

    /**
     * Create a new capa personalizada.
     */
    public function createCapa(array $data, User $user): CapaPersonalizada
    {
        $data['user_id'] = $data['user_id'] ?? $user->id;
        $data['created_by_id'] = $user->id;
        $data['status'] = $data['status'] ?? CapaPersonalizadaStatus::ENCOMENDA_SOLICITADA->value;

        return CapaPersonalizada::create($data);
    }

    /**
     * Update capa status.
     *
     * @return array{capa: CapaPersonalizada, whatsapp_notification: ?array}
     */
    public function updateStatus(
        CapaPersonalizada $capa,
        int $newStatusValue,
        User $changedBy,
        bool $notifyWhatsApp = false
    ): array {
        $newStatus = CapaPersonalizadaStatus::from($newStatusValue);

        $capa->update([
            'status' => $newStatus->value,
            'updated_by_id' => $changedBy->id,
        ]);

        $capa = $capa->fresh();

        // Send WhatsApp notification if requested and status is "Disponível na Loja"
        $whatsappNotification = null;
        if ($notifyWhatsApp && $newStatus === CapaPersonalizadaStatus::DISPONIVEL_LOJA) {
            // Load customer relationship if not loaded
            $capa->loadMissing(['customer', 'store']);
            $whatsappNotification = $this->whatsAppService->sendCapaAvailableNotification($capa);
        }

        return [
            'capa' => $capa,
            'whatsapp_notification' => $whatsappNotification,
        ];
    }

    /**
     * Bulk update status for multiple capas.
     */
    public function bulkUpdateStatus(
        array $capaIds,
        int $newStatusValue,
        User $changedBy
    ): array {
        $results = [
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        $newStatus = CapaPersonalizadaStatus::from($newStatusValue);

        DB::transaction(function () use ($capaIds, $newStatus, $changedBy, &$results) {
            foreach ($capaIds as $id) {
                try {
                    $capa = CapaPersonalizada::find($id);

                    if (!$capa) {
                        $results['errors'][] = "Capa {$id} não encontrada.";
                        continue;
                    }

                    if ($capa->status->value === $newStatus->value) {
                        $results['skipped']++;
                        continue;
                    }

                    $this->updateStatus($capa, $newStatus->value, $changedBy, false);
                    $results['updated']++;
                } catch (\Exception $e) {
                    $results['errors'][] = "Erro na capa {$id}: " . $e->getMessage();
                }
            }
        });

        return $results;
    }

    /**
     * Send capas to production in bulk.
     */
    public function sendToProduction(
        array $capaIds,
        string $sendedAt,
        User $changedBy
    ): array {
        $results = [
            'updated' => 0,
            'errors' => [],
        ];

        DB::transaction(function () use ($capaIds, $sendedAt, $changedBy, &$results) {
            foreach ($capaIds as $id) {
                try {
                    $capa = CapaPersonalizada::find($id);

                    if (!$capa) {
                        $results['errors'][] = "Capa {$id} não encontrada.";
                        continue;
                    }

                    $capa->update([
                        'sended_to_production_at' => $sendedAt,
                        'status' => CapaPersonalizadaStatus::ENVIADO_PRODUCAO->value,
                        'updated_by_id' => $changedBy->id,
                    ]);

                    $results['updated']++;
                } catch (\Exception $e) {
                    $results['errors'][] = "Erro na capa {$id}: " . $e->getMessage();
                }
            }
        });

        return $results;
    }

    /**
     * Register payment for a capa.
     */
    public function registerPayment(
        CapaPersonalizada $capa,
        bool $payed,
        ?string $payday,
        ?int $receivedById,
        User $changedBy
    ): CapaPersonalizada {
        $capa->update([
            'payed' => $payed,
            'payday' => $payed ? $payday : null,
            'received_by_id' => $payed ? $receivedById : null,
            'updated_by_id' => $changedBy->id,
        ]);

        return $capa->fresh();
    }

    /**
     * Upload photo for a capa (authenticated).
     */
    public function uploadPhoto(CapaPersonalizada $capa, UploadedFile $file): array
    {
        // Delete old photo if exists
        if ($capa->photo_path) {
            Storage::disk('public')->delete($capa->photo_path);
        }

        // Store new photo
        $path = $file->store('capas-personalizadas', 'public');

        $capa->update(['photo_path' => $path]);

        return [
            'photo_path' => $path,
            'photo_url' => asset('storage/' . $path),
            'size' => $file->getSize(),
            'mime' => $file->getMimeType(),
        ];
    }

    /**
     * Delete photo from a capa.
     */
    public function deletePhoto(CapaPersonalizada $capa): bool
    {
        if (!$capa->photo_path) {
            return false;
        }

        Storage::disk('public')->delete($capa->photo_path);
        $capa->update(['photo_path' => null]);

        return true;
    }

    // ========================================
    // Public Upload Token Methods
    // ========================================

    /**
     * Generate a temporary upload token for a capa.
     * Token expires in 5 minutes.
     */
    public function generateUploadToken(CapaPersonalizada $capa): array
    {
        $token = Str::random(64);
        $expiresAt = now()->addMinutes(5);

        $capa->update([
            'upload_token' => $token,
            'upload_token_expires_at' => $expiresAt,
        ]);

        return [
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
            'upload_url' => url("/api/v1/capas-personalizadas/{$capa->id}/upload-publico"),
        ];
    }

    /**
     * Upload photo via public endpoint with token validation.
     *
     * @throws \Exception
     */
    public function uploadPhotoPublic(CapaPersonalizada $capa, UploadedFile $file, string $token): array
    {
        // Validate token
        if (!$capa->hasValidUploadToken($token)) {
            throw new \Exception('Token inválido ou expirado.', 401);
        }

        // Check if already has photo
        if ($capa->photo_path) {
            throw new \Exception('Esta capa já possui uma foto.', 409);
        }

        // Store new photo
        $path = $file->store('capas-personalizadas', 'public');

        $capa->update(['photo_path' => $path]);

        // Clear token after successful upload
        $capa->clearUploadToken();

        return [
            'photo_path' => $path,
            'photo_url' => asset('storage/' . $path),
            'size' => $file->getSize(),
            'mime' => $file->getMimeType(),
        ];
    }
}

