<?php

declare(strict_types=1);

namespace App\Http\Requests\Rules;

use Illuminate\Foundation\Http\FormRequest;

class StoreBonusRuleRequest extends FormRequest
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
            'config_json.*.min_sales' => ['required', 'numeric', 'min:0'],
            'config_json.*.bonus' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'config_json.required' => 'A configuração de faixas de bônus é obrigatória.',
            'config_json.*.min_sales.required' => 'O valor mínimo de vendas é obrigatório para cada faixa.',
            'config_json.*.bonus.required' => 'O valor do bônus é obrigatório para cada faixa.',
        ];
    }
}
