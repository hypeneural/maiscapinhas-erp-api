<?php

declare(strict_types=1);

namespace App\Http\Requests\CapasPersonalizadas;

use Illuminate\Foundation\Http\FormRequest;

class UploadPublicoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Public endpoint - no authentication required.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'token' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [
            'photo.required' => 'A foto é obrigatória.',
            'photo.image' => 'O arquivo deve ser uma imagem.',
            'photo.mimes' => 'A imagem deve ser do tipo: jpg, jpeg, png ou webp.',
            'photo.max' => 'A imagem não pode ter mais de 10MB.',
            'token.required' => 'O token é obrigatório.',
            'token.string' => 'O token deve ser uma string.',
            'token.max' => 'O token não pode ter mais de 64 caracteres.',
        ];
    }
}
