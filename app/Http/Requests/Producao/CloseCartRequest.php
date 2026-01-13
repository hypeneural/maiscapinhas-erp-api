<?php

declare(strict_types=1);

namespace App\Http\Requests\Producao;

use Illuminate\Foundation\Http\FormRequest;

class CloseCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only global admins can close cart
        $user = $this->user();
        return $user && $user->isGlobalAdmin();
    }

    public function rules(): array
    {
        return [
            'observation' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'observation.max' => 'A observação deve ter no máximo 2000 caracteres.',
        ];
    }
}
