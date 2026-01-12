<?php

declare(strict_types=1);

namespace App\Http\Requests\CapasPersonalizadas;

use Illuminate\Foundation\Http\FormRequest;

class PaymentCapaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payed' => ['required', 'boolean'],
            'payday' => ['required_if:payed,true,1', 'nullable', 'date'],
            'received_by_id' => ['required_if:payed,true,1', 'nullable', 'integer', 'exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'payed.required' => 'O campo pago é obrigatório.',
            'payday.required_if' => 'A data de pagamento é obrigatória quando marcado como pago.',
            'payday.date' => 'A data de pagamento é inválida.',
            'received_by_id.required_if' => 'O recebedor é obrigatório quando marcado como pago.',
            'received_by_id.exists' => 'O recebedor selecionado não existe.',
        ];
    }
}
