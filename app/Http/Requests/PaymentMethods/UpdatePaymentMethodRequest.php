<?php

declare(strict_types=1);

namespace App\Http\Requests\PaymentMethods;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $paymentMethodId = $this->route('payment_method')?->id;

        return [
            'name' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('payment_methods', 'name')->ignore($paymentMethodId),
            ],
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
                Rule::unique('payment_methods', 'slug')->ignore($paymentMethodId),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Esta forma de pagamento já está cadastrada.',
            'slug.unique' => 'Este slug já está em uso.',
            'sort_order.min' => 'A ordem deve ser um número positivo.',
        ];
    }
}
