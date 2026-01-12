<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcements;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class AckAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('ack', $this->route('announcement'));
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
            $storeId = $this->input('store_id');

            if ($storeId && !$this->user()->hasAccessToStore($storeId)) {
                $validator->errors()->add('store_id', 'Você não tem acesso a esta loja.');
            }
        });
    }
}
