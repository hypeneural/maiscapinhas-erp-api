<?php

declare(strict_types=1);

namespace App\Http\Requests\Pdv;

use Illuminate\Foundation\Http\FormRequest;

class PdvSyncDebugShowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'include_raw' => ['sometimes', 'boolean'],
            'include_decoded' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        if ($this->has('include_raw')) {
            $merged['include_raw'] = $this->normalizeBooleanInput($this->input('include_raw'));
        }

        if ($this->has('include_decoded')) {
            $merged['include_decoded'] = $this->normalizeBooleanInput($this->input('include_decoded'));
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
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
