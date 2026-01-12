<?php

declare(strict_types=1);

namespace App\Http\Requests\PhoneCatalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePhoneBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'brand_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('phone_brands', 'brand_name'),
            ],
            'brand_slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                Rule::unique('phone_brands', 'brand_slug'),
            ],
            'parent_company' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'brand_name.required' => 'O nome da marca é obrigatório.',
            'brand_name.unique' => 'Esta marca já está cadastrada.',
            'brand_slug.unique' => 'Este slug já está em uso.',
        ];
    }
}
