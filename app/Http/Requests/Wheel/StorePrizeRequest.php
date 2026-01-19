<?php

declare(strict_types=1);

namespace App\Http\Requests\Wheel;

use App\Enums\PrizeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePrizeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'prize_key' => [
                'nullable',
                'string',
                'max:50',
                'regex:/^[a-z0-9_]+$/',
                'unique:wheel_prizes,prize_key',
            ],
            'name' => 'required|string|max:100',
            'type' => [
                'required',
                Rule::in(PrizeType::values()),
            ],
            'icon' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:1000',
            'redeem_instructions' => 'nullable|string|max:2000',
            'code_prefix' => 'nullable|string|max:20',
            'active' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'prize_key.regex' => 'A chave do prêmio deve conter apenas letras minúsculas, números e underscores.',
            'prize_key.unique' => 'Esta chave já está em uso.',
            'name.required' => 'O nome é obrigatório.',
            'type.required' => 'O tipo é obrigatório.',
            'type.in' => 'Tipo inválido. Use: product, coupon, nothing ou try_again.',
        ];
    }
}
