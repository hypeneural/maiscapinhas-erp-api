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
            'bio_enabled' => ['sometimes', 'boolean'],

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

            // Opening hours (deep validation)
            'opening_hours' => ['sometimes', 'nullable', 'array'],
            'opening_hours.tz' => ['required_with:opening_hours', 'string', 'timezone:all'],
            'opening_hours.weekly' => ['required_with:opening_hours', 'array'],
            'opening_hours.weekly.mon' => ['sometimes', 'array'],
            'opening_hours.weekly.tue' => ['sometimes', 'array'],
            'opening_hours.weekly.wed' => ['sometimes', 'array'],
            'opening_hours.weekly.thu' => ['sometimes', 'array'],
            'opening_hours.weekly.fri' => ['sometimes', 'array'],
            'opening_hours.weekly.sat' => ['sometimes', 'array'],
            'opening_hours.weekly.sun' => ['sometimes', 'array'],
            'opening_hours.weekly.*.*.start' => ['required', 'date_format:H:i'],
            'opening_hours.weekly.*.*.end' => ['required', 'date_format:H:i'],
            'opening_hours.exceptions' => ['sometimes', 'array'],
            'opening_hours.exceptions.*.date' => ['required', 'date_format:Y-m-d'],
            'opening_hours.exceptions.*.closed' => ['sometimes', 'boolean'],
            'opening_hours.exceptions.*.reason' => ['sometimes', 'nullable', 'string', 'max:100'],

            // Business info
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
            'opening_hours.tz.required_with' => 'O timezone é obrigatório quando opening_hours é informado.',
            'opening_hours.tz.timezone' => 'O timezone informado é inválido.',
            'opening_hours.weekly.required_with' => 'Os horários semanais são obrigatórios quando opening_hours é informado.',
            'opening_hours.weekly.*.*.start.required' => 'O horário de início é obrigatório.',
            'opening_hours.weekly.*.*.start.date_format' => 'O horário de início deve estar no formato HH:MM.',
            'opening_hours.weekly.*.*.end.required' => 'O horário de término é obrigatório.',
            'opening_hours.weekly.*.*.end.date_format' => 'O horário de término deve estar no formato HH:MM.',
            'opening_hours.exceptions.*.date.required' => 'A data da exceção é obrigatória.',
            'opening_hours.exceptions.*.date.date_format' => 'A data da exceção deve estar no formato YYYY-MM-DD.',
        ];
    }
}

