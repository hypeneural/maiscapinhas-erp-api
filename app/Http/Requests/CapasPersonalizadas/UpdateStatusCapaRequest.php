<?php

declare(strict_types=1);

namespace App\Http\Requests\CapasPersonalizadas;

use App\Enums\CapaPersonalizadaStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStatusCapaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'integer', Rule::in(CapaPersonalizadaStatus::values())],
            'notify_whatsapp' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'O status é obrigatório.',
            'status.in' => 'Status inválido.',
            'notify_whatsapp.boolean' => 'O campo notify_whatsapp deve ser verdadeiro ou falso.',
        ];
    }
}
