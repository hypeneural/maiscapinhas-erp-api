<?php

declare(strict_types=1);

namespace App\Http\Requests\Wheel;

use App\Enums\ScreenStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreScreenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'screen_key' => [
                'nullable',
                'string',
                'max:50',
                'regex:/^[a-z0-9\-]+$/',
                'unique:wheel_screens,screen_key',
            ],
            'store_id' => 'required|exists:stores,id',
            'name' => 'required|string|max:100',
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
            'screen_key.regex' => 'A chave da TV deve conter apenas letras minúsculas, números e hífens.',
            'screen_key.unique' => 'Esta chave já está em uso.',
            'store_id.required' => 'A loja é obrigatória.',
            'store_id.exists' => 'Loja não encontrada.',
            'name.required' => 'O nome é obrigatório.',
        ];
    }
}
