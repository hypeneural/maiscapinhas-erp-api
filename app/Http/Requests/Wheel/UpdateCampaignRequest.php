<?php

declare(strict_types=1);

namespace App\Http\Requests\Wheel;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:150',
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
            'ends_at.after' => 'A data de término deve ser posterior à data de início.',
        ];
    }
}
