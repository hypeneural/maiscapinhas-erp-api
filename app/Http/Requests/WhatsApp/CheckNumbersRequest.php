<?php

declare(strict_types=1);

namespace App\Http\Requests\WhatsApp;

use Illuminate\Foundation\Http\FormRequest;

class CheckNumbersRequest extends FormRequest
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
            'numbers' => ['required', 'array', 'min:1', 'max:200'],
            'numbers.*' => ['string', 'regex:/^\d+$/', 'max:20'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'numbers.required' => 'A lista de números é obrigatória.',
            'numbers.min' => 'Informe pelo menos 1 número.',
            'numbers.max' => 'O limite é de 200 números por requisição.',
            'numbers.*.regex' => 'Cada número deve conter apenas dígitos (com DDI).',
        ];
    }
}
