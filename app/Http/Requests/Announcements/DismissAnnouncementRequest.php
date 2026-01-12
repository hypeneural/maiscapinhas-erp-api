<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcements;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class DismissAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('dismiss', $this->route('announcement'));
    }

    public function rules(): array
    {
        return [
            'store_id' => ['sometimes', 'nullable', 'integer', 'exists:stores,id'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $announcement = $this->route('announcement');

            // Can only dismiss non-require_ack announcements
            if ($announcement->require_ack) {
                $validator->errors()->add('announcement', 'Não é possível dispensar avisos que requerem confirmação.');
            }

            $storeId = $this->input('store_id');
            if ($storeId && !$this->user()->hasAccessToStore($storeId)) {
                $validator->errors()->add('store_id', 'Você não tem acesso a esta loja.');
            }
        });
    }
}
