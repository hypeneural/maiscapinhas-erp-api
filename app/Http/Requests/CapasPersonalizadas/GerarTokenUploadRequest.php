<?php

declare(strict_types=1);

namespace App\Http\Requests\CapasPersonalizadas;

use Illuminate\Foundation\Http\FormRequest;

class GerarTokenUploadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // User must be authenticated (handled by middleware)
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [];
    }
}
