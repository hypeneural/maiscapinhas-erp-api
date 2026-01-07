<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'seller_id' => ['sometimes', 'integer', 'exists:users,id'],
            'sold_at' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'source' => ['sometimes', 'string', 'in:pdv,manual,import'],
        ];
    }

    public function messages(): array
    {
        return [
            'store_id.required' => 'A loja é obrigatória.',
            'store_id.exists' => 'Loja não encontrada.',
            'sold_at.required' => 'A data da venda é obrigatória.',
            'amount.required' => 'O valor é obrigatório.',
            'amount.min' => 'O valor deve ser maior que zero.',
        ];
    }
}
