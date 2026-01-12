<?php

declare(strict_types=1);

namespace App\Http\Requests\CapasPersonalizadas;

use App\Enums\CapaPersonalizadaStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkStatusCapaRequest extends FormRequest
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
            'status' => ['required', 'integer', Rule::in(CapaPersonalizadaStatus::values())],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Os IDs das capas são obrigatórios.',
            'ids.array' => 'Os IDs devem ser uma lista.',
            'ids.min' => 'Pelo menos uma capa deve ser selecionada.',
            'ids.*.exists' => 'Uma das capas selecionadas não existe.',
            'status.required' => 'O status é obrigatório.',
            'status.in' => 'Status inválido.',
        ];
    }
}
