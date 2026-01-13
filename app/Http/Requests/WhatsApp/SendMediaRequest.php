<?php

declare(strict_types=1);

namespace App\Http\Requests\WhatsApp;

use Illuminate\Foundation\Http\FormRequest;

class SendMediaRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled in controller
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'number' => ['required', 'string', 'regex:/^\d+$/', 'max:20'],
            'mediatype' => ['required', 'string', 'in:image,video,document,audio'],
            'mimetype' => ['required', 'string', 'max:100'],
            'media' => ['required', 'url', 'max:2048'],
            'fileName' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'number.regex' => 'O número deve conter apenas dígitos (com DDI).',
            'mediatype.in' => 'O tipo de mídia deve ser: image, video, document ou audio.',
            'media.url' => 'A mídia deve ser uma URL válida.',
        ];
    }
}
