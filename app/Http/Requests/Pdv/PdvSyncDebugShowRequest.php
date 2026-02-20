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

    public function queryParameters(): array
    {
        return [];
    }
}

