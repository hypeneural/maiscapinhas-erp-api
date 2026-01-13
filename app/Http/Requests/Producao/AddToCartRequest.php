<?php

declare(strict_types=1);

namespace App\Http\Requests\Producao;

use Illuminate\Foundation\Http\FormRequest;

class AddToCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only admins can add to cart
        $user = $this->user();
        return $user && ($user->hasRole('admin') || $user->hasRole('super_admin'));
    }

    public function rules(): array
    {
        return [
            'capa_ids' => ['required', 'array', 'min:1', 'max:100'],
            'capa_ids.*' => ['required', 'integer', 'exists:capas_personalizadas,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'capa_ids.required' => 'É necessário informar ao menos uma capa.',
            'capa_ids.array' => 'O campo capa_ids deve ser um array.',
            'capa_ids.min' => 'É necessário informar ao menos uma capa.',
            'capa_ids.max' => 'Limite máximo de 100 capas por vez.',
            'capa_ids.*.exists' => 'Uma ou mais capas informadas não existem.',
        ];
    }
}
