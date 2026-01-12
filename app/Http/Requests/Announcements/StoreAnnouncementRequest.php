<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcements;

use App\Enums\AnnouncementDisplayMode;
use App\Enums\AnnouncementScope;
use App\Enums\AnnouncementSeverity;
use App\Enums\AnnouncementTargetType;
use App\Enums\AnnouncementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', [\App\Models\Announcement::class, $this->input('scope')]);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string'],
            'excerpt' => ['sometimes', 'nullable', 'string', 'max:200'],
            'type' => ['required', 'string', Rule::in(AnnouncementType::values())],
            'severity' => ['required', 'string', Rule::in(AnnouncementSeverity::values())],
            'display_mode' => ['sometimes', 'string', Rule::in(AnnouncementDisplayMode::values())],
            'icon' => ['sometimes', 'nullable', 'string', 'max:50'],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'image_alt' => ['sometimes', 'nullable', 'string', 'max:120'],
            'cta_label' => ['sometimes', 'nullable', 'string', 'max:80'],
            'cta_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'scope' => ['required', 'string', Rule::in(AnnouncementScope::values())],
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
            'title.required' => 'O título é obrigatório.',
            'title.max' => 'O título não pode ter mais de 120 caracteres.',
            'message.required' => 'A mensagem é obrigatória.',
            'type.required' => 'O tipo é obrigatório.',
            'type.in' => 'Tipo inválido.',
            'severity.required' => 'A severidade é obrigatória.',
            'severity.in' => 'Severidade inválida.',
            'display_mode.in' => 'Modo de exibição inválido.',
            'scope.required' => 'O escopo é obrigatório.',
            'scope.in' => 'Escopo inválido.',
            'expires_at.after' => 'A data de expiração deve ser posterior à data de início.',
            'targets.*.target_type.required_with' => 'O tipo do alvo é obrigatório.',
            'targets.*.target_id.required_with' => 'O ID do alvo é obrigatório.',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $scope = $this->input('scope');
            $targets = $this->input('targets', []);
            $type = $this->input('type');
            $severity = $this->input('severity');

            // Non-global scopes require targets
            if ($scope && $scope !== AnnouncementScope::GLOBAL ->value && empty($targets)) {
                $validator->errors()->add('targets', 'Alvos são obrigatórios para este escopo.');
            }

            // Advertência must have danger severity
            if ($type === AnnouncementType::ADVERTENCIA->value && $severity !== AnnouncementSeverity::DANGER->value) {
                $validator->errors()->add('severity', 'Advertências devem ter severidade "danger".');
            }

            // repeat_every_minutes only for require_ack
            if ($this->input('repeat_every_minutes') && !$this->input('require_ack')) {
                $validator->errors()->add('repeat_every_minutes', 'Repetição só é permitida para avisos que requerem confirmação.');
            }
        });
    }

    protected function prepareForValidation()
    {
        // Auto-set severity to danger for advertências
        if ($this->input('type') === AnnouncementType::ADVERTENCIA->value && !$this->has('severity')) {
            $this->merge(['severity' => AnnouncementSeverity::DANGER->value]);
        }

        // Auto-set require_ack for modal display
        if ($this->input('display_mode') === AnnouncementDisplayMode::MODAL->value && !$this->has('require_ack')) {
            $this->merge(['require_ack' => true]);
        }

        // Default display_mode
        if (!$this->has('display_mode')) {
            $this->merge(['display_mode' => AnnouncementDisplayMode::BANNER->value]);
        }
    }
}
