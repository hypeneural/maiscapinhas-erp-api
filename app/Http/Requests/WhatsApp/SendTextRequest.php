<?php

declare(strict_types=1);

namespace App\Http\Requests\WhatsApp;

use Illuminate\Foundation\Http\FormRequest;

class SendTextRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled in controller
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'number' => ['required', 'string', 'regex:/^\d+$/', 'max:20'],
            'text' => ['required', 'string', 'max:4000'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'number.regex' => 'O número deve conter apenas dígitos (com DDI).',
            'number.required' => 'O número é obrigatório.',
            'text.required' => 'O texto da mensagem é obrigatório.',
            'text.max' => 'O texto não pode ultrapassar 4000 caracteres.',
        ];
    }
}
