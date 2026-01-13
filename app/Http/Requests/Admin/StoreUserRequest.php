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
        // Authorization handled by controller
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            // Required fields
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
            ],
            'password' => ['required', 'string', Password::defaults()],

            // Optional basic fields
            'active' => ['sometimes', 'boolean'],
            'is_super_admin' => ['sometimes', 'boolean'],

            // Address fields
            'zip_code' => ['sometimes', 'nullable', 'string', 'max:8'],
            'street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'complement' => ['sometimes', 'nullable', 'string', 'max:255'],
            'neighborhood' => ['sometimes', 'nullable', 'string', 'max:100'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'state' => ['sometimes', 'nullable', 'string', 'size:2'],

            // Profile fields
            'birth_date' => ['sometimes', 'nullable', 'date', 'before:today'],
            'hire_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'whatsapp' => ['sometimes', 'nullable', 'string', 'max:20'],
            'instagram' => ['sometimes', 'nullable', 'string', 'max:50'],

            // Financial/Document fields
            'cpf' => [
                'sometimes',
                'nullable',
                'string',
                'max:14',
                Rule::unique('users', 'cpf'),
            ],
            'pix_key' => ['sometimes', 'nullable', 'string', 'max:255'],

            // Optional: assign to stores on creation
            'stores' => ['sometimes', 'array'],
            'stores.*.store_id' => ['required_with:stores', 'integer', 'exists:stores,id'],
            'stores.*.role' => ['required_with:stores', 'string', Rule::enum(StoreUserRole::class)],

            // Optional: assign global roles (Spatie)
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', Rule::in(['fabrica'])],
        ];
    }

    /**
     * Get custom messages for validation errors.
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Este email já está em uso.',
            'cpf.unique' => 'Este CPF já está cadastrado.',
            'birth_date.before' => 'A data de nascimento deve ser anterior a hoje.',
            'hire_date.before_or_equal' => 'A data de contratação não pode ser futura.',
            'stores.*.store_id.exists' => 'Uma das lojas informadas não existe.',
            'stores.*.role.enum' => 'Role inválida. Use: admin, gerente, conferente ou vendedor.',
        ];
    }
}
