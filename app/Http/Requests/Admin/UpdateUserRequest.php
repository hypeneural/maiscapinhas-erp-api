<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $userId = $this->route('user')?->id ?? $this->route('user');

        return [
            // Basic fields
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'password' => ['sometimes', 'string', Password::defaults()],
            'active' => ['sometimes', 'boolean'],
            'is_super_admin' => ['sometimes', 'boolean'],

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
                Rule::unique('users', 'cpf')->ignore($userId),
            ],
            'pix_key' => ['sometimes', 'nullable', 'string', 'max:255'],
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
        ];
    }
}
