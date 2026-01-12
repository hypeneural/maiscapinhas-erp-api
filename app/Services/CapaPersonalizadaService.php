<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CapaPersonalizadaStatus;
use App\Models\CapaPersonalizada;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CapaPersonalizadaService
{
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
     */
    public function updateStatus(
        CapaPersonalizada $capa,
        int $newStatusValue,
        User $changedBy
    ): CapaPersonalizada {
        $newStatus = CapaPersonalizadaStatus::from($newStatusValue);

        $capa->update([
            'status' => $newStatus->value,
            'updated_by_id' => $changedBy->id,
        ]);

        return $capa->fresh();
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

                    $this->updateStatus($capa, $newStatus->value, $changedBy);
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
     * Upload photo for a capa.
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
}
