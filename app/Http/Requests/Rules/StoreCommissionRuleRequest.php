<?php

declare(strict_types=1);

namespace App\Http\Requests\Rules;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommissionRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy handles authorization
    }

    public function rules(): array
    {
        return [
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'effective_from' => ['required', 'date'],
            'config_json' => ['required', 'array', 'min:1'],
            'config_json.*.min_attainment' => ['required', 'numeric', 'min:0'],
            'config_json.*.rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'config_json.required' => 'A configuração de faixas de comissão é obrigatória.',
            'config_json.*.min_attainment.required' => 'O percentual mínimo de atingimento é obrigatório para cada faixa.',
            'config_json.*.rate.required' => 'A taxa de comissão é obrigatória para cada faixa.',
        ];
    }
}
