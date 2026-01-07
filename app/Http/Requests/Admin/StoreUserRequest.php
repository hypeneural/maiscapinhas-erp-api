<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\StoreUserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Authorization handled by policy
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
            ],
            'password' => ['required', 'string', Password::defaults()],
            'active' => ['sometimes', 'boolean'],

            // Optional: assign to stores on creation
            'stores' => ['sometimes', 'array'],
            'stores.*.store_id' => ['required_with:stores', 'integer', 'exists:stores,id'],
            'stores.*.role' => ['required_with:stores', 'string', Rule::enum(StoreUserRole::class)],
        ];
    }

    /**
     * Get custom messages for validation errors.
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Este email já está em uso.',
            'stores.*.store_id.exists' => 'Uma das lojas informadas não existe.',
            'stores.*.role.enum' => 'Role inválida. Use: admin, gerente, conferente ou vendedor.',
        ];
    }
}
