<?php

declare(strict_types=1);

namespace App\Http\Requests\Pedidos;

use App\Enums\PedidoStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePedidoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['sometimes', 'integer', 'exists:customers,id'],
            'customer_device_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:customer_devices,id',
            ],
            'selected_product' => ['sometimes', 'string', 'max:255'],
            'obs' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', 'integer', Rule::in(PedidoStatus::values())],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.exists' => 'O cliente selecionado não existe.',
            'customer_device_id.exists' => 'O dispositivo selecionado não existe.',
            'status.in' => 'Status inválido.',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $pedido = $this->route('pedido');
            $customerId = $this->input('customer_id', $pedido?->customer_id);
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
