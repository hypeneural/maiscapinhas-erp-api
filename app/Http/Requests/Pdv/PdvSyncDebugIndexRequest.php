<?php

declare(strict_types=1);

namespace App\Http\Requests\Pdv;

use Illuminate\Foundation\Http\FormRequest;

class PdvSyncDebugIndexRequest extends FormRequest
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
            'store_id_filial' => ['sometimes', 'integer', 'min:1'],
            'payload_contains' => ['sometimes', 'string', 'max:255'],
            'has_error' => ['sometimes', 'boolean'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'sort' => ['sometimes', 'in:asc,desc'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (!$this->has('has_error')) {
            return;
        }

        $this->merge([
            'has_error' => $this->normalizeBooleanInput($this->input('has_error')),
        ]);
    }

    private function normalizeBooleanInput(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            if ($value === 1) {
                return true;
            }
            if ($value === 0) {
                return false;
            }
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            $normalized = trim($normalized, "\"'");
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return $value;
    }

    public function queryParameters(): array
    {
        return [];
    }
}
