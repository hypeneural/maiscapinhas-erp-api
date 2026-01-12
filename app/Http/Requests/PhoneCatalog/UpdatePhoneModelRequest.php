<?php

declare(strict_types=1);

namespace App\Http\Requests\PhoneCatalog;

use App\Enums\PhoneFormFactor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePhoneModelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'brand_id' => ['sometimes', 'integer', 'exists:phone_brands,id'],
            'marketing_name' => ['sometimes', 'string', 'max:255'],
            'release_year' => ['sometimes', 'nullable', 'integer', 'min:1990', 'max:2100'],
            'form_factor' => ['sometimes', 'string', Rule::enum(PhoneFormFactor::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'brand_id.exists' => 'A marca selecionada não existe.',
            'release_year.min' => 'O ano de lançamento deve ser no mínimo 1990.',
            'release_year.max' => 'O ano de lançamento é inválido.',
            'form_factor.enum' => 'Tipo de dispositivo inválido.',
        ];
    }
}
