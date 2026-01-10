<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Required fields
            'name' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],

            // Optional basic fields
            'active' => ['sometimes', 'boolean'],
            'codigo' => ['sometimes', 'nullable', 'string', 'max:20'],

            // Address fields
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'neighborhood' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:2'],
            'zip_code' => ['sometimes', 'nullable', 'string', 'max:10'],

            // Geolocation
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],

            // Contact
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'whatsapp' => ['sometimes', 'nullable', 'string', 'max:20'],
            'instagram' => ['sometimes', 'nullable', 'string', 'max:50'],

            // Business info
            'opening_hours' => ['sometimes', 'nullable', 'array'],
            'cnpj' => ['sometimes', 'nullable', 'string', 'max:18'],
            'troco_padrao' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'O nome da loja é obrigatório.',
            'city.required' => 'A cidade é obrigatória.',
            'state.max' => 'O estado deve ter no máximo 2 caracteres (UF).',
            'latitude.between' => 'Latitude deve estar entre -90 e 90.',
            'longitude.between' => 'Longitude deve estar entre -180 e 180.',
            'troco_padrao.min' => 'O troco padrão não pode ser negativo.',
        ];
    }
}
