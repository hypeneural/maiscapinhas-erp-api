<?php

declare(strict_types=1);

namespace App\Http\Requests\Pdv;

use Illuminate\Foundation\Http\FormRequest;

class PdvSyncAdminIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', 'max:20'],
            'event_type' => ['sometimes', 'string', 'max:30'],
            'sync_id' => ['sometimes', 'string', 'max:128'],
            'schema_version' => ['sometimes', 'string', 'max:10'],
            'request_id' => ['sometimes', 'string', 'max:64'],
            'risk_flag' => ['sometimes', 'string', 'max:80'],
            'store_pdv_id' => ['sometimes', 'integer', 'min:1'],
            'store_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function queryParameters(): array
    {
        // GET endpoint: force Scribe to extract params as query parameters.
        return [];
    }
}
