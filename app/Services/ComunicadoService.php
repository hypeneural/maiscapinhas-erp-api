<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Comunicado;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ComunicadoService
{
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return Comunicado::query()
            ->byStatus($filters['status'] ?? null)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function create(array $data): Comunicado
    {
        return Comunicado::create($data);
    }

    public function update(Comunicado $item, array $data): Comunicado
    {
        $item->update($data);
        return $item->fresh();
    }

    public function delete(Comunicado $item): bool
    {
        return $item->delete();
    }

    public function updateStatus(Comunicado $item, int $status): Comunicado
    {
        $item->update(['status' => $status]);
        return $item->fresh();
    }
}