<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcements;

use App\Enums\AnnouncementDisplayMode;
use App\Enums\AnnouncementScope;
use App\Enums\AnnouncementSeverity;
use App\Enums\AnnouncementStatus;
use App\Enums\AnnouncementTargetType;
use App\Enums\AnnouncementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('announcement'));
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:120'],
            'message' => ['sometimes', 'string'],
            'excerpt' => ['sometimes', 'nullable', 'string', 'max:200'],
            'type' => ['sometimes', 'string', Rule::in(AnnouncementType::values())],
            'severity' => ['sometimes', 'string', Rule::in(AnnouncementSeverity::values())],
            'display_mode' => ['sometimes', 'string', Rule::in(AnnouncementDisplayMode::values())],
            'icon' => ['sometimes', 'nullable', 'string', 'max:50'],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'image_alt' => ['sometimes', 'nullable', 'string', 'max:120'],
            'cta_label' => ['sometimes', 'nullable', 'string', 'max:80'],
            'cta_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'scope' => ['sometimes', 'string', Rule::in(AnnouncementScope::values())],
            'require_ack' => ['sometimes', 'boolean'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'repeat_every_minutes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'priority' => ['sometimes', 'integer', 'min:0'],
            'pinned_until' => ['sometimes', 'nullable', 'date'],
            'meta_json' => ['sometimes', 'nullable', 'array'],

            // Targets
            'targets' => ['sometimes', 'array'],
            'targets.*.target_type' => ['required_with:targets', 'string', Rule::in(AnnouncementTargetType::values())],
            'targets.*.target_id' => ['required_with:targets', 'string', 'max:64'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.max' => 'O título não pode ter mais de 120 caracteres.',
            'type.in' => 'Tipo inválido.',
            'severity.in' => 'Severidade inválida.',
            'display_mode.in' => 'Modo de exibição inválido.',
            'scope.in' => 'Escopo inválido.',
            'expires_at.after' => 'A data de expiração deve ser posterior à data de início.',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $announcement = $this->route('announcement');

            // Check if editable (draft/scheduled only for most changes)
            if (!$announcement->status->isEditable()) {
                // Allow only limited changes on active announcements
                $restrictedFields = ['scope', 'type', 'targets'];
                foreach ($restrictedFields as $field) {
                    if ($this->has($field)) {
                        $validator->errors()->add($field, 'Este campo não pode ser alterado em anúncios ativos.');
                    }
                }
            }

            // Advertência must have danger severity
            $type = $this->input('type', $announcement->type->value);
            $severity = $this->input('severity', $announcement->severity->value);
            if ($type === AnnouncementType::ADVERTENCIA->value && $severity !== AnnouncementSeverity::DANGER->value) {
                $validator->errors()->add('severity', 'Advertências devem ter severidade "danger".');
            }
        });
    }
}
