<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\StoreUserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserBindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $storeId = $this->route('store')?->id ?? $this->route('store');
        $userId = $this->route('user')?->id ?? $this->route('user') ?? $this->input('user_id');

        return [
            'user_id' => [
                'required_without:role', // Required for POST (create binding)
                'integer',
                'exists:users,id',
                // Unique per store (only for POST)
                $this->isMethod('POST') ? Rule::unique('store_users')->where(function ($query) use ($storeId) {
                    return $query->where('store_id', $storeId);
                }) : null,
            ],
            'role' => [
                'required',
                'string',
                Rule::enum(StoreUserRole::class),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.unique' => 'Este usuário já está vinculado a esta loja.',
            'user_id.exists' => 'Usuário não encontrado.',
            'role.enum' => 'Role inválida. Use: admin, gerente, conferente ou vendedor.',
        ];
    }
}
