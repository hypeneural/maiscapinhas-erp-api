<?php

declare(strict_types=1);

namespace App\Http\Requests\CapasPersonalizadas;

use App\Enums\CapaPersonalizadaStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCapaPersonalizadaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'store_id' => ['sometimes', 'integer', 'exists:stores,id'],
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'customer_device_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:customer_devices,id',
            ],
            'selected_product' => ['required', 'string', 'max:255'],
            'product_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'obs' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'qty' => ['sometimes', 'integer', 'min:1'],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'payed' => ['sometimes', 'boolean'],
            'payday' => ['sometimes', 'nullable', 'date', 'required_if:payed,true,1'],
            'received_by_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:users,id',
                'required_if:payed,true,1',
            ],
            'status' => ['sometimes', 'integer', Rule::in(CapaPersonalizadaStatus::values())],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.required' => 'O cliente é obrigatório.',
            'customer_id.exists' => 'O cliente selecionado não existe.',
            'customer_device_id.exists' => 'O dispositivo selecionado não existe.',
            'selected_product.required' => 'O produto é obrigatório.',
            'qty.min' => 'A quantidade mínima é 1.',
            'price.min' => 'O preço não pode ser negativo.',
            'payday.required_if' => 'A data de pagamento é obrigatória quando marcado como pago.',
            'received_by_id.required_if' => 'O recebedor é obrigatório quando marcado como pago.',
            'received_by_id.exists' => 'O recebedor selecionado não existe.',
            'status.in' => 'Status inválido.',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $customerId = $this->input('customer_id');
            $deviceId = $this->input('customer_device_id');

            if ($deviceId && $customerId) {
                $deviceBelongsToCustomer = \App\Models\CustomerDevice::where('id', $deviceId)
                    ->where('customer_id', $customerId)
                    ->exists();

                if (!$deviceBelongsToCustomer) {
                    $validator->errors()->add(
                        'customer_device_id',
                        'O dispositivo não pertence ao cliente selecionado.'
                    );
                }
            }
        });
    }
}
