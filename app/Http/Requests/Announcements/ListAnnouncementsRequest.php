<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcements;

use App\Enums\AnnouncementScope;
use App\Enums\AnnouncementSeverity;
use App\Enums\AnnouncementStatus;
use App\Enums\AnnouncementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAnnouncementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in([...AnnouncementStatus::values(), 'all'])],
            'only_unacknowledged' => ['sometimes', 'boolean'],
            'only_unseen' => ['sometimes', 'boolean'],
            'severity' => ['sometimes', 'string', Rule::in(AnnouncementSeverity::values())],
            'type' => ['sometimes', 'string', Rule::in(AnnouncementType::values())],
            'scope' => ['sometimes', 'string', Rule::in(AnnouncementScope::values())],
            'store_id' => ['sometimes', 'integer', 'exists:stores,id'],
            'created_by' => ['sometimes', 'integer', 'exists:users,id'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'sort' => [
                'sometimes',
                'string',
                Rule::in([
                    'starts_at_desc',
                    'starts_at_asc',
                    'created_at_desc',
                    'created_at_asc',
                    'severity_desc',
                    'priority_desc',
                ])
            ],
        ];
    }
}
