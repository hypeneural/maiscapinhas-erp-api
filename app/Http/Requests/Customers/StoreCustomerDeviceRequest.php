<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone_model_id' => ['required', 'integer', 'exists:phone_models,id'],
            'nickname' => ['sometimes', 'nullable', 'string', 'max:60'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone_model_id.required' => 'O modelo do aparelho é obrigatório.',
            'phone_model_id.exists' => 'O modelo selecionado não existe.',
        ];
    }
}
