<?php

declare(strict_types=1);

namespace App\Http\Requests\Pedidos;

use App\Enums\PedidoStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkStatusPedidoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'exists:pedidos,id'],
            'status' => ['required', 'integer', Rule::in(PedidoStatus::values())],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Os IDs dos pedidos são obrigatórios.',
            'ids.array' => 'Os IDs devem ser uma lista.',
            'ids.min' => 'Pelo menos um pedido deve ser selecionado.',
            'ids.*.exists' => 'Um dos pedidos selecionados não existe.',
            'status.required' => 'O status é obrigatório.',
            'status.in' => 'Status inválido.',
        ];
    }
}
