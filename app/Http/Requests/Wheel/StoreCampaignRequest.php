<?php

declare(strict_types=1);

namespace App\Http\Requests\Wheel;

use App\Enums\CampaignStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'campaign_key' => [
                'nullable',
                'string',
                'max:50',
                'regex:/^[a-z0-9_]+$/',
                'unique:wheel_campaigns,campaign_key',
            ],
            'name' => 'required|string|max:150',
            'status' => [
                'sometimes',
                Rule::in([CampaignStatus::DRAFT->value]), // Novas campanhas só podem ser draft
            ],
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'terms_version' => 'nullable|string|max:20',
            'settings' => 'nullable|array',
            'settings.qr_ttl_seconds' => 'nullable|integer|min:30|max:600',
            'settings.spin_duration_ms' => 'nullable|integer|min:3000|max:15000',
            'settings.min_rotations' => 'nullable|integer|min:3|max:10',
            'settings.max_rotations' => 'nullable|integer|min:5|max:15',
            'settings.max_queue_size' => 'nullable|integer|min:1|max:50',
            'settings.per_phone_limit' => 'nullable|string|in:1_per_campaign,1_per_day,unlimited',
        ];
    }

    public function messages(): array
    {
        return [
            'campaign_key.regex' => 'A chave da campanha deve conter apenas letras minúsculas, números e underscores.',
            'campaign_key.unique' => 'Esta chave já está em uso.',
            'name.required' => 'O nome é obrigatório.',
            'ends_at.after' => 'A data de término deve ser posterior à data de início.',
        ];
    }
}
