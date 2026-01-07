<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'active' => $this->active,

            // Profile data
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'hire_date' => $this->hire_date?->format('Y-m-d'),
            'whatsapp' => $this->whatsapp,
            'avatar_url' => $this->avatar_url,
            'instagram' => $this->instagram,
            'cpf' => $this->when($this->shouldShowSensitiveData($request), $this->cpf),
            'pix_key' => $this->when($this->shouldShowSensitiveData($request), $this->pix_key),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'stores' => $this->whenLoaded('storeUsers', function () {
                return $this->storeUsers->map(fn($su) => [
                    'store_id' => $su->store_id,
                    'store_name' => $su->store?->name,
                    'role' => $su->role,
                ]);
            }),
        ];
    }

    /**
     * Determina se deve mostrar dados sensíveis (CPF, PIX).
     * Apenas para o próprio usuário ou admins.
     */
    private function shouldShowSensitiveData(Request $request): bool
    {
        $authUser = $request->user();
        if (!$authUser) {
            return false;
        }

        // Próprio usuário pode ver
        if ($authUser->id === $this->id) {
            return true;
        }

        // Admin pode ver
        return $authUser->storeUsers()->where('role', 'admin')->exists();
    }
}
