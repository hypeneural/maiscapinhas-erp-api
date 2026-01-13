<?php

declare(strict_types=1);

namespace App\Http\Requests\WhatsApp;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWhatsAppInstanceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $instance = $this->route('instance');
        $instanceId = $instance?->id ?? $instance;

        return [
            'name' => [
                'sometimes',
                'string',
                'max:60',
                'regex:/^[a-zA-Z0-9_\-]+$/',
                Rule::unique('whatsapp_instances')->where(
                    fn($q) => $q
                        ->where('provider', $this->input('provider', $instance->provider ?? 'evolution'))
                        ->where('base_url', rtrim($this->input('base_url', $instance->base_url ?? ''), '/'))
                        ->whereNull('deleted_at')
                )->ignore($instanceId),
            ],
            'provider' => ['sometimes', 'string', 'max:50'],
            'base_url' => ['sometimes', 'url', 'max:255'],
            'phone_e164' => ['nullable', 'string', 'max:20', 'regex:/^\d+$/'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'token' => ['nullable', 'string', 'max:500'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id', 'prohibited_with:user_id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id', 'prohibited_with:store_id'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
            'webhook_url' => ['nullable', 'url', 'max:255'],
            'webhook_events' => ['nullable', 'array'],
            'status' => ['nullable', Rule::in(['unknown', 'connected', 'disconnected', 'connecting'])],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('base_url')) {
            $this->merge([
                'base_url' => rtrim($this->input('base_url'), '/'),
            ]);
        }

        if ($this->has('name')) {
            $this->merge([
                'name' => trim($this->input('name')),
            ]);
        }
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'O nome deve conter apenas letras, números, underline e hífen.',
            'name.unique' => 'Já existe uma instância com este nome para o mesmo provedor e URL.',
            'store_id.prohibited_with' => 'Não é possível definir loja e usuário ao mesmo tempo.',
            'user_id.prohibited_with' => 'Não é possível definir usuário e loja ao mesmo tempo.',
            'phone_e164.regex' => 'O telefone deve conter apenas números.',
        ];
    }
}
