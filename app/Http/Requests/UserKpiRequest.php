<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UserKpiRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Must be authenticated (handled by Sanctum middleware)
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'active' => ['sometimes', 'string', 'in:0,1,all'],
            'state' => ['sometimes', 'nullable', 'string', 'size:2'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }

    /**
     * Get custom messages for validation errors.
     */
    public function messages(): array
    {
        return [
            'active.in' => 'O parâmetro active deve ser 0, 1 ou all.',
            'state.size' => 'O estado deve ter exatamente 2 caracteres (ex: SC, SP).',
            'date_to.after_or_equal' => 'A data final deve ser igual ou posterior à data inicial.',
        ];
    }

    /**
     * Get validated filter values with defaults.
     */
    public function getFilters(): array
    {
        return [
            'active' => $this->input('active', '1'),
            'state' => $this->input('state'),
            'city' => $this->input('city'),
            'date_from' => $this->input('date_from'),
            'date_to' => $this->input('date_to'),
        ];
    }
}
