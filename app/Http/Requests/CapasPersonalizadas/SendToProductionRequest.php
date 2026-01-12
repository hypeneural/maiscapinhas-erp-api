<?php

declare(strict_types=1);

namespace App\Http\Requests\CapasPersonalizadas;

use Illuminate\Foundation\Http\FormRequest;

class SendToProductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'exists:capas_personalizadas,id'],
            'sended_to_production_at' => ['required', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Os IDs das capas são obrigatórios.',
            'ids.array' => 'Os IDs devem ser uma lista.',
            'ids.min' => 'Pelo menos uma capa deve ser selecionada.',
            'ids.*.exists' => 'Uma das capas selecionadas não existe.',
            'sended_to_production_at.required' => 'A data de envio para produção é obrigatória.',
            'sended_to_production_at.date' => 'A data de envio para produção é inválida.',
        ];
    }
}
