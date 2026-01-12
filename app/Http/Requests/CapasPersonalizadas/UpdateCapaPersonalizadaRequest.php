<?php

declare(strict_types=1);

namespace App\Http\Requests\CapasPersonalizadas;

use App\Enums\CapaPersonalizadaStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCapaPersonalizadaRequest extends FormRequest
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
            'product_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'obs' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'qty' => ['sometimes', 'integer', 'min:1'],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'integer', Rule::in(CapaPersonalizadaStatus::values())],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.exists' => 'O cliente selecionado não existe.',
            'customer_device_id.exists' => 'O dispositivo selecionado não existe.',
            'qty.min' => 'A quantidade mínima é 1.',
            'price.min' => 'O preço não pode ser negativo.',
            'status.in' => 'Status inválido.',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $capa = $this->route('capas_personalizada') ?? $this->route('capa');
            $customerId = $this->input('customer_id', $capa?->customer_id);
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
