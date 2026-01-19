<?php

declare(strict_types=1);

namespace App\Http\Requests\Wheel;

use App\Enums\PrizeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePrizeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:100',
            'type' => [
                'sometimes',
                Rule::in(PrizeType::values()),
            ],
            'icon' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:1000',
            'redeem_instructions' => 'nullable|string|max:2000',
            'code_prefix' => 'nullable|string|max:20',
            'active' => 'boolean',
        ];
    }
}
