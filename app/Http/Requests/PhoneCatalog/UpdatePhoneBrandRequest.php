<?php

declare(strict_types=1);

namespace App\Http\Requests\PhoneCatalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePhoneBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $brandId = $this->route('phone_brand')?->id ?? $this->route('phone_brand');

        return [
            'brand_name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('phone_brands', 'brand_name')->ignore($brandId),
            ],
            'brand_slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                Rule::unique('phone_brands', 'brand_slug')->ignore($brandId),
            ],
            'parent_company' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'brand_name.unique' => 'Esta marca já está cadastrada.',
            'brand_slug.unique' => 'Este slug já está em uso.',
        ];
    }
}
