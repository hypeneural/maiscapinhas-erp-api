<?php

declare(strict_types=1);

namespace App\Http\Requests\Wheel;

use App\Enums\ScreenStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateScreenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'store_id' => 'sometimes|exists:stores,id',
            'name' => 'sometimes|string|max:100',
            'status' => [
                'sometimes',
                Rule::in(ScreenStatus::values()),
            ],
            'device_info' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'store_id.exists' => 'Loja não encontrada.',
        ];
    }
}
